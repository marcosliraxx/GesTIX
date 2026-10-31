#!/usr/bin/env python3
# Caminho: agent/arcoll_agent.py
"""Arcoll Network Agent 7.1 - companion local para o PWA.

Uso:
  python arcoll_agent.py --origin https://seu-dominio.example/app
  ARCOLL_ORIGIN=https://seu-dominio.example python arcoll_agent.py

O agente deve ser executado no dispositivo que possui a interface de rede a ser
inventariada. O PWA não consegue instalar ou iniciar este processo sozinho.
O serviço HTTP aceita conexões apenas deste computador (127.0.0.1).
"""
import argparse, ctypes, ipaddress, json, os, platform, re, shutil, socket, subprocess, sys, threading, time, urllib.request, urllib.error, uuid
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

VERSION = "3.3.3"
LISTEN_HOST = "127.0.0.1"
PORT = int(os.environ.get("ARCOLL_PORT", "8787"))
WIN = platform.system().lower() == "windows"
CREATE_FLAGS = 0x08000000 if WIN else 0
CACHE = {"t": 0.0, "devices": []}
PROGRESS = {"active": False, "done": 0, "total": 254, "phase": "idle", "count": 0, "found": []}
LOCK = threading.RLock()
SCAN_THREAD = None
SYSTEM_PREVIOUS = None
AGENT_TOKEN = os.environ.get("ARCOLL_AGENT_TOKEN", "").strip()

parser = argparse.ArgumentParser(description="Arcoll local network agent")
parser.add_argument("--origin", action="append", default=[], help="origem HTTPS autorizada do PWA (pode repetir)")
parser.add_argument("--port", type=int, default=PORT, help="porta local do agente")
args = parser.parse_args()
PORT = max(1024, min(65535, args.port))
ALLOWED_ORIGINS = [x.rstrip("/") for x in (args.origin or [os.environ.get("ARCOLL_ORIGIN", "")]) if x]


def run(cmd, timeout=6):
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, errors="ignore", timeout=timeout, creationflags=CREATE_FLAGS)
        return (p.stdout or "") + (p.stderr or "")
    except Exception:
        return ""


def local_ip():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(("10.255.255.255", 1))
        return s.getsockname()[0]
    except Exception:
        return "127.0.0.1"
    finally:
        s.close()


def gateway():
    if WIN:
        for line in run(["route", "print", "-4", "0.0.0.0"]).splitlines():
            p = line.split()
            if len(p) >= 3 and p[0] == "0.0.0.0" and p[1] == "0.0.0.0":
                return p[2]
    else:
        m = re.search(r"default via (\S+)", run(["ip", "route", "show", "default"]))
        if m:
            return m.group(1)
    return local_ip().rsplit(".", 1)[0] + ".1"


def ping(host, timeout_ms=900):
    if WIN:
        cmd = ["ping", "-n", "1", "-w", str(timeout_ms), host]
    else:
        cmd = ["ping", "-c", "1", "-W", str(max(1, int(timeout_ms / 1000))), host]
    out = run(cmd, timeout_ms / 1000 + 3)
    ok = "TTL=" in out.upper() if WIN else ("1 received" in out or "1 packets received" in out)
    if not ok:
        return None
    m = re.search(r"(?:time|tempo)[=<]\s*([\d.,]+)\s*ms", out, re.I)
    return float(m.group(1).replace(",", ".")) if m else 1.0


def arp_table():
    """Vizinhos IPv4 observados pelo sistema; ignora grupos e endereços inválidos."""
    result = {}
    outputs = [run(["arp", "-a"])]
    if not WIN:
        outputs.append(run(["ip", "neigh", "show"]))
    pattern = re.compile(r"(?P<ip>\d{1,3}(?:\.\d{1,3}){3})\s+.*?(?P<mac>[0-9a-fA-F]{2}(?:[:-][0-9a-fA-F]{2}){5})(?![0-9a-fA-F])")
    # Formato Windows: IP seguido diretamente pelo MAC, sem campos intermediários.
    for output in outputs:
        for line in output.splitlines():
            match = pattern.search(line)
            if not match:
                continue
            try:
                ip = ipaddress.ip_address(match.group("ip"))
                if not isinstance(ip, ipaddress.IPv4Address) or not ip.is_private or ip.is_multicast or ip.is_unspecified:
                    continue
            except ValueError:
                continue
            mac = valid_mac(match.group("mac"))
            if mac:
                result[str(ip)] = mac
    return result


def neighbor_states():
    """Estados de vizinhança fornecidos pelo Windows (sem sweep de IPs)."""
    if not WIN:
        return {}
    cmd = ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
           "Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue | "
           "Select-Object IPAddress,LinkLayerAddress,State | ConvertTo-Json -Compress"]
    output = run(cmd, 6).strip()
    try:
        rows = json.loads(output)
        if isinstance(rows, dict): rows = [rows]
        result = {}
        for row in rows if isinstance(rows, list) else []:
            ip = str(row.get("IPAddress", ""))
            mac = valid_mac(row.get("LinkLayerAddress"))
            if mac and ipaddress.ip_address(ip).is_private:
                result[ip] = {"mac": mac, "state": str(row.get("State", "")).lower()}
        return result
    except (ValueError, TypeError, AttributeError):
        return {}


def valid_mac(mac):
    raw = re.sub(r"[^0-9a-fA-F]", "", str(mac or "")).upper()
    if len(raw) != 12 or raw in ("000000000000", "FFFFFFFFFFFF"):
        return None
    if int(raw[:2], 16) & 1:  # Multicast ou broadcast nao e endereco de host.
        return None
    return ":".join(raw[i:i+2] for i in range(0, 12, 2))


def local_adapter_mac(ip):
    """Consulta o adaptador do endereco local; nao atribui MAC de gateway a host remoto."""
    if not WIN:
        return None
    try:
        command = ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
                   "$x=Get-NetIPAddress -AddressFamily IPv4 -IPAddress '" + ip + "' -ErrorAction SilentlyContinue | Select-Object -First 1; if($x){(Get-NetAdapter -InterfaceIndex $x.InterfaceIndex -ErrorAction SilentlyContinue).MacAddress}"]
        return valid_mac(run(command, 5).strip())
    except Exception:
        return None


def reverse_name(ip):
    try:
        return socket.gethostbyaddr(ip)[0].split(".")[0]
    except Exception:
        return None


SERVICE_PORTS = {8787:"Arcoll Agent",21:"FTP",22:"SSH",23:"Telnet",53:"DNS",80:"HTTP",139:"NetBIOS",443:"HTTPS",445:"SMB",515:"LPD",554:"RTSP",631:"IPP",1900:"UPnP",2869:"UPnP/HTTP",3389:"RDP",5357:"WSD",8008:"Chromecast",8009:"Chromecast",8080:"HTTP-alt",8443:"HTTPS-alt",9100:"JetDirect",62078:"Apple-mux"}

def device_kind(name, ip, gw, me, ports=()):
    if ip == gw: return "router"
    if ip == me: return "computer"
    text = (name or "").lower(); opened=set(ports)
    groups = {
        "printer": ("print", "printer", "impressora"), "phone": ("iphone", "android", "galaxy", "pixel", "phone"),
        "tablet": ("ipad", "tablet"), "tv": ("smarttv", "smart-tv", "webos", "bravia", "roku", "tizen", "tv"),
        "camera": ("cam", "nvr", "camera"), "nas": ("nas", "synology", "qnap"),
        "speaker": ("echo", "homepod", "speaker", "sonos"), "laptop": ("laptop", "notebook", "macbook"),
        "server": ("server", "servidor"), "iot": ("sensor", "tuya", "esp-", "smartplug", "thermostat"),
        "game": ("xbox", "playstation", "nintendo"), "watch": ("watch", "wearable"),
        "computer": ("desktop", "workstation", "pc-")}
    for kind, words in groups.items():
        if any(word in text for word in words): return kind
    if opened & {515,631,9100}: return "printer"
    if opened & {554}: return "camera"
    if opened & {8008,8009}: return "tv"
    if opened & {445,3389,22,8787}: return "computer"
    if opened & {80,443,8080,8443} and opened & {53,1900}: return "router"
    return "device"


def local_network(ip):
    """Detecta rede IPv4 conectada, preferindo a máscara da rota do sistema."""
    if WIN:
        out=run(["ipconfig"])
        masks=re.findall(r"(?:Máscara de sub-rede|Subnet Mask)[ .:]*([0-9.]+)",out,re.I)
        for mask in masks:
            try:
                net=ipaddress.IPv4Network(f"{ip}/{mask}",strict=False)
                if ipaddress.ip_address(ip) in net: return net
            except Exception: pass
    else:
        out=run(["ip","-o","-f","inet","addr","show"])
        for addr in re.findall(r"inet (\d+\.\d+\.\d+\.\d+/\d+)",out):
            try:
                net=ipaddress.ip_interface(addr).network
                if str(ipaddress.ip_interface(addr).ip)==ip:return net
            except Exception: pass
    return ipaddress.IPv4Network(ip.rsplit(".",1)[0]+".0/24",strict=False)


def probe_services(ip):
    found=[]
    for port in SERVICE_PORTS:
        s=socket.socket(); s.settimeout(.22)
        try:
            if s.connect_ex((ip,port))==0: found.append(port)
        except Exception: pass
        finally: s.close()
    return found


def netbios_name(ip):
    if not WIN: return None
    out=run(["nbtstat","-A",ip],3)
    for line in out.splitlines():
        m=re.match(r"\s*([^\s<]+)\s+<00>\s+UNIQUE",line,re.I)
        if m and m.group(1)!="__MSBROWSE__": return m.group(1).strip()
    return None


def build_device(ip, mac, name, gw, me, latency=None, source="agente", ports=()):
    private_mac = bool(mac) and (int(mac.split(":")[0], 16) & 2) != 0
    return {"name": name or ("Gateway" if ip == gw else "Este computador" if ip == me else "Dispositivo " + ip.rsplit(".", 1)[-1]),
            "ip": ip, "mac": mac, "type": device_kind(name, ip, gw, me, ports), "status": "online",
            "latency_ms": latency, "source": source, "link_type": "unknown", "open_ports": [{"port": p, "service": SERVICE_PORTS.get(p, "")} for p in ports],
            "detail": "MAC privado/aleatório típico de celular" if private_mac else "",
            "topology": {"gateway_ip": gw if gw != me else None,
                         "role": "gateway" if ip == gw else "agent" if ip == me else "endpoint",
                         "uplink_status": "not_verified",
                         "evidence": "local_default_route" if ip == gw else "arp_neighbor",
                         "note": "O ARP confirma vizinhanca IP/MAC, nao a porta fisica nem switches intermediarios."},
            "last_seen": int(time.time() * 1000)}



def system_inventory():
    """Coleta somente métricas do próprio host, com bibliotecas padrão."""
    global SYSTEM_PREVIOUS
    mem_total=mem_available=None; uptime=None; rx=tx=0
    if WIN:
        class MEMORYSTATUSEX(ctypes.Structure):
            _fields_=[("dwLength",ctypes.c_ulong),("dwMemoryLoad",ctypes.c_ulong),("ullTotalPhys",ctypes.c_ulonglong),("ullAvailPhys",ctypes.c_ulonglong),("ullTotalPageFile",ctypes.c_ulonglong),("ullAvailPageFile",ctypes.c_ulonglong),("ullTotalVirtual",ctypes.c_ulonglong),("ullAvailVirtual",ctypes.c_ulonglong),("ullAvailExtendedVirtual",ctypes.c_ulonglong)]
        m=MEMORYSTATUSEX(); m.dwLength=ctypes.sizeof(m)
        try:
            if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m)):
                mem_total=int(m.ullTotalPhys); mem_available=int(m.ullAvailPhys)
        except Exception: pass
        try: uptime=int(ctypes.windll.kernel32.GetTickCount64()/1000)
        except Exception: pass
        try:
            cmd=["powershell.exe","-NoProfile","-NonInteractive","-Command","$n=Get-NetAdapterStatistics -ErrorAction SilentlyContinue | Measure-Object -Property ReceivedBytes,SentBytes -Sum; $n.Sum -join ','"]
            vals=run(cmd,4).strip().split(',')
            if len(vals)>=2: rx,tx=int(float(vals[0])),int(float(vals[1]))
        except Exception: pass
    else:
        try:
            vals={}
            for line in open('/proc/meminfo',encoding='ascii'):
                k,v=line.split(':',1); vals[k]=int(v.strip().split()[0])*1024
            mem_total=vals.get('MemTotal'); mem_available=vals.get('MemAvailable',vals.get('MemFree'))
        except Exception: pass
        try: uptime=int(float(open('/proc/uptime',encoding='ascii').read().split()[0]))
        except Exception: pass
        try:
            for line in open('/proc/net/dev',encoding='ascii').read().splitlines()[2:]:
                if ':' not in line: continue
                iface,data=line.split(':',1)
                if iface.strip()=='lo': continue
                n=[int(x) for x in data.split()]
                if len(n)>=9: rx+=n[0]; tx+=n[8]
        except Exception: pass
    disk_total=disk_free=None
    try:
        d=shutil.disk_usage(os.environ.get('SystemDrive','C:\\')+'\\' if WIN else '/')
        disk_total=int(d.total); disk_free=int(d.free)
    except Exception: pass
    now=time.time(); rx_mbps=tx_mbps=None
    if SYSTEM_PREVIOUS:
        old_t,old_rx,old_tx=SYSTEM_PREVIOUS; dt=now-old_t
        if dt>0 and rx>=old_rx and tx>=old_tx:
            rx_mbps=round((rx-old_rx)*8/dt/1000000,3); tx_mbps=round((tx-old_tx)*8/dt/1000000,3)
    SYSTEM_PREVIOUS=(now,rx,tx)
    return {"hostname":socket.gethostname(),"os":platform.platform(),"os_name":platform.system(),"architecture":platform.machine(),"cpu":platform.processor() or platform.machine(),"cpu_cores":os.cpu_count(),"memory_total_bytes":mem_total,"memory_available_bytes":mem_available,"disk_total_bytes":disk_total,"disk_free_bytes":disk_free,"uptime_seconds":uptime,"network_rx_bytes":rx,"network_tx_bytes":tx,"network_rx_mbps":rx_mbps,"network_tx_mbps":tx_mbps,"sampled_at":int(now*1000),"source":"agente autorizado"}


def do_scan(force=False):
    global SCAN_THREAD
    with LOCK:
        if not force and time.time() - CACHE["t"] < 15:
            PROGRESS.update(active=False, done=PROGRESS["total"], phase="Resultado em cache", count=len(CACHE["devices"]), found=CACHE["devices"][:])
            return CACHE["devices"][:]
        if PROGRESS["active"]:
            return CACHE["devices"][:]
        PROGRESS.update(active=True, done=0, total=254, phase="Preparando varredura", count=0, found=[])
    try:
        me, gw = local_ip(), gateway()
        network=local_network(me); hosts=list(network.hosts())
        # Limita ranges anormalmente grandes para evitar uma varredura excessiva.
        if len(hosts)>1024: hosts=hosts[:1024]
        ips=[str(x) for x in hosts]; PROGRESS["total"]=len(ips)
        latencies, partial = {}, []
        PROGRESS["phase"] = "Sondando endereços IPv4 e tabela ARP"
        with ThreadPoolExecutor(max_workers=96) as pool:
            for idx, (ip, ms) in enumerate(zip(ips, pool.map(lambda value: ping(value, 650), ips)), 1):
                latencies[ip] = ms
                if ms is not None: partial.append(build_device(ip, None, None, gw, me, ms))
                if idx % 8 == 0 or idx == len(ips):
                    with LOCK: PROGRESS.update(done=idx, count=len(partial), found=partial[:])
        arp = arp_table()
        # Alguns hosts so entram na tabela ARP apos sondagem; consultar novamente ao final.
        live = sorted({ip for ip in ips if latencies.get(ip) is not None or ip in arp}, key=lambda x: tuple(map(int, x.split("."))))
        # Alguns dispositivos bloqueiam ICMP e ARP ainda não os conhece: tenta TCP
        # em portas de serviços típicos nos demais endereços, com timeout curto.
        candidates=[ip for ip in ips if ip not in set(live)]
        PROGRESS["phase"] = "Procurando dispositivos que bloqueiam ping"
        with ThreadPoolExecutor(max_workers=96) as pool:
            candidate_ports=list(pool.map(probe_services,candidates))
        for ip,ports in zip(candidates,candidate_ports):
            if ports:
                live.append(ip); latencies.setdefault(ip,None)
        live=sorted(set(live),key=lambda x:tuple(map(int,x.split("."))))
        PROGRESS["phase"] = "Resolvendo nomes e identificando serviços"
        with ThreadPoolExecutor(max_workers=32) as pool:
            names=list(pool.map(reverse_name, live))
        names=dict(zip(live,names))
        # Windows NetBIOS revela nomes que não aparecem no DNS reverso.
        unknown=[ip for ip in live if not names.get(ip) and ip not in {me,gw}]
        if WIN:
            with ThreadPoolExecutor(max_workers=24) as pool:
                names.update({ip:n for ip,n in zip(unknown,pool.map(netbios_name,unknown)) if n})
        with ThreadPoolExecutor(max_workers=32) as pool:
            scanned=list(pool.map(probe_services,live))
        systems={me:system_inventory()}
        # O segredo desta instalação nunca é enviado a dispositivos descobertos.
        # Hardware detalhado é coletado apenas no próprio agente autorizado.
        arp.update({ip: mac for ip, mac in arp_table().items() if mac})
        if me in live and not valid_mac(arp.get(me)):
            local_mac = local_adapter_mac(me)
            if local_mac: arp[me] = local_mac
        result=[]
        seen_macs=set()
        for ip,ports in zip(live,scanned):
            mac=valid_mac(arp.get(ip))
            if not mac: continue  # Detectado por IP, mas sem identidade permanente confirmada.
            if mac in seen_macs: continue  # Mesmo equipamento encontrado por outro IP.
            seen_macs.add(mac)
            result.append(build_device(ip,mac,names.get(ip),gw,me,latencies.get(ip),ports=ports))
        for device in result:
            if device["ip"] in systems: device["system_data"]=systems[device["ip"]]
        with LOCK:
            CACHE.update(t=time.time(), devices=result)
            PROGRESS.update(active=False, done=len(ips), total=len(ips), phase="Varredura concluída", count=len(result), found=result[:])
        return result
    except Exception:
        with LOCK: PROGRESS.update(active=False, phase="Falha na varredura")
        raise


PORTS = {21: "FTP", 22: "SSH", 23: "Telnet", 53: "DNS", 80: "HTTP", 139: "NetBIOS", 443: "HTTPS", 445: "SMB", 554: "RTSP", 631: "IPP", 1900: "UPnP", 3389: "RDP", 5900: "VNC", 8080: "HTTP-alt", 8443: "HTTPS-alt", 9100: "Impressora"}
HOST_RE = re.compile(r"^[A-Za-z0-9.:%_-]{1,100}$")


def private_host(host):
    try: return ipaddress.ip_address(socket.gethostbyname(host)).is_private
    except Exception: return False


def port_open(host, port):
    s = socket.socket(); s.settimeout(.7)
    try: return s.connect_ex((host, port)) == 0
    except Exception: return False
    finally: s.close()


def wol(mac):
    raw = bytes.fromhex(re.sub(r"[^0-9a-fA-F]", "", mac))
    if len(raw) != 6: raise ValueError("MAC inválido")
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
    s.sendto(b"\xff" * 6 + raw * 16, ("255.255.255.255", 9)); s.close()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_): pass
    def reply(self, code, payload, content_type="application/json; charset=utf-8"):
        body = payload if isinstance(payload, bytes) else json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(code); self.send_header("Content-Type", content_type); self.send_header("Content-Length", str(len(body))); self.send_header("Cache-Control", "no-store")
        origin = self.headers.get("Origin", "").rstrip("/")
        if origin and origin in ALLOWED_ORIGINS:
            self.send_header("Access-Control-Allow-Origin", origin); self.send_header("Access-Control-Allow-Private-Network", "true"); self.send_header("Access-Control-Allow-Methods", "GET, OPTIONS"); self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Vary", "Origin"); self.end_headers(); self.wfile.write(body)
    def allowed(self):
        try:
            if not ipaddress.ip_address(self.client_address[0]).is_loopback: return False
        except Exception: return False
        origin = self.headers.get("Origin", "").rstrip("/")
        return not origin or origin in ALLOWED_ORIGINS
    def do_OPTIONS(self):
        return self.reply(204, b"") if self.allowed() else self.reply(403, {"error": "origem não autorizada ou acesso fora deste computador"})
    def do_GET(self):
        if not self.allowed(): return self.reply(403, {"error": "origem não autorizada ou acesso fora deste computador"})
        try:
            u = urlparse(self.path); q = {k: v[0] for k, v in parse_qs(u.query).items()}; p = u.path
            if p == "/api/health": return self.reply(200, {"ok": True, "arcoll": True, "version": VERSION, "host": socket.gethostname(), "os": platform.platform(), "ip": local_ip(), "gateway": gateway(), "port": PORT, "capabilities": ["devices", "icmp", "arp", "ports", "dns", "wol", "service-fingerprint", "authorized-system-inventory"]})
            if p == "/api/capabilities": return self.reply(200, {"version": VERSION, "platform": platform.system(), "devices": True, "icmp": True, "arp": True, "ports": True, "dns": True, "wol": True, "service_fingerprint": True, "system_inventory": bool(AGENT_TOKEN)})
            if p == "/api/neighbors": return self.reply(200, {"neighbors": arp_table(), "states": neighbor_states(), "captured_at": int(time.time()), "method": "os_neighbor_cache"})
            if p == "/api/scan-status": return self.reply(200, dict(PROGRESS))
            if p == "/api/devices": return self.reply(200, {"devices": do_scan(q.get("refresh") == "1")})
            if p == "/api/system":
                auth=self.headers.get("Authorization", "")
                if not AGENT_TOKEN or not __import__("hmac").compare_digest(auth, "Bearer "+AGENT_TOKEN):
                    return self.reply(403, {"error": "inventário remoto desativado ou credencial inválida"})
                return self.reply(200, {"system": system_inventory()})
            if p == "/api/ping":
                host = q.get("host", ""); return self.reply(200, {"host": host, "ms": ping(host, 1200)}) if HOST_RE.match(host) else self.reply(400, {"error": "host inválido"})
            if p == "/api/ports":
                host = q.get("host", "")
                if not HOST_RE.match(host) or not private_host(host): return self.reply(400, {"error": "apenas hosts da rede local"})
                with ThreadPoolExecutor(max_workers=16) as pool: values = list(pool.map(lambda port: (port, port_open(host, port)), PORTS))
                return self.reply(200, {"host": host, "open": [{"port": port, "service": PORTS[port]} for port, opened in values if opened], "checked": len(PORTS)})
            if p == "/api/dns":
                name = q.get("name", "")
                if not HOST_RE.match(name): return self.reply(400, {"error": "nome inválido"})
                ips = sorted({x[4][0] for x in socket.getaddrinfo(name, None) if x[0] == socket.AF_INET})
                return self.reply(200, {"name": name, "ips": ips, "reverse": [reverse_name(x) for x in ips]})
            if p == "/api/wol": wol(q.get("mac", "")); return self.reply(200, {"ok": True})
            return self.reply(404, {"error": "não encontrado"})
        except Exception as exc: return self.reply(500, {"error": str(exc)})


if __name__ == "__main__":
    print("\nARCOLL AGENT %s ativo" % VERSION)
    print("PWA local: http://localhost:%d" % PORT)
    print("Origens autorizadas: %s" % (", ".join(ALLOWED_ORIGINS) or "nenhuma (use --origin ou ARCOLL_ORIGIN)"))
    print("Pressione Ctrl+C para encerrar.\n")
    try: ThreadingHTTPServer((LISTEN_HOST, PORT), Handler).serve_forever()
    except KeyboardInterrupt: pass
