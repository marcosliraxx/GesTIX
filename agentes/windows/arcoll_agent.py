#!/usr/bin/env python3
# Caminho: agentes/windows/arcoll_agent.py
# Caminho: agentes/windows/arcoll_agent.p
"""Arcoll Network Agent - companion local para o PWA.

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

from arcoll_release import VERSION
LISTEN_HOST = "127.0.0.1"
PORT = int(os.environ.get("ARCOLL_PORT", "8787"))
WIN = platform.system().lower() == "windows"
CREATE_FLAGS = 0x08000000 if WIN else 0
CACHE = {"t": 0.0, "devices": []}
PROGRESS = {"active": False, "done": 0, "total": 254, "phase": "idle", "count": 0, "found": []}
LOCK = threading.RLock()
SCAN_THREAD = None
SYSTEM_PREVIOUS = None
PROBE_LOCK=threading.Lock()
LAST_PROBE=0.0
PORT_CACHE={}
NAME_CACHE={}
PORT_SCAN_LOCK=threading.Lock()
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
    cached=NAME_CACHE.get(ip)
    if cached and time.monotonic()-cached[0]<300:return cached[1]
    pace_probe()
    try:
        name=socket.gethostbyaddr(ip)[0].split(".")[0]
    except Exception:name=None
    if len(NAME_CACHE)>=512:NAME_CACHE.clear()
    NAME_CACHE[ip]=(time.monotonic(),name)
    return name


SERVICE_PORTS = {8787:"Arcoll Agent",21:"FTP",22:"SSH",23:"Telnet",53:"DNS",80:"HTTP",139:"NetBIOS",443:"HTTPS",445:"SMB",515:"LPD",554:"RTSP",631:"IPP",1900:"UPnP",2869:"UPnP/HTTP",3389:"RDP",5357:"WSD",8008:"Chromecast",8009:"Chromecast",8080:"HTTP-alt",8443:"HTTPS-alt",9100:"JetDirect",62078:"Apple-mux",5900:"VNC"}

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


def pace_probe():
    """Bound ICMP/TCP discovery traffic across concurrent local API requests."""
    global LAST_PROBE
    with PROBE_LOCK:
        delay=.125-(time.monotonic()-LAST_PROBE)
        if delay>0:time.sleep(delay)
        LAST_PROBE=time.monotonic()


def probe_services(ip):
    """Explicit diagnosis of one local host; never called by inventory discovery."""
    with PORT_SCAN_LOCK:
        cached=PORT_CACHE.get(ip)
        if cached and time.monotonic()-cached[0]<60:return list(cached[1])
        found=[]
        for port in SERVICE_PORTS:
            pace_probe()
            with socket.socket() as connection:
                connection.settimeout(.3)
                try:
                    if connection.connect_ex((ip,port))==0:found.append(port)
                except OSError:pass
        if len(PORT_CACHE)>=128:PORT_CACHE.clear()
        PORT_CACHE[ip]=(time.monotonic(),found)
        with LOCK:
            for device in CACHE['devices']:
                if device.get('ip')==ip:device['open_ports']=[{'port':port,'service':SERVICE_PORTS[port]} for port in found]
        return list(found)


def netbios_name(ip):
    if not WIN: return None
    pace_probe()
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


def discover_ping(address):
    pace_probe()
    return ping(address,650)


def do_scan(force=False):
    global SCAN_THREAD
    me=local_ip()
    with LOCK:
        if CACHE["t"] and CACHE.get("local_ip")==me and time.time() - CACHE["t"] < 60:
            PROGRESS.update(active=False, done=PROGRESS["total"], phase="Resultado em cache", count=len(CACHE["devices"]), found=CACHE["devices"][:])
            return CACHE["devices"][:]
        if PROGRESS["active"]:
            return CACHE["devices"][:]
        PROGRESS.update(active=True, done=0, total=254, phase="Preparando varredura", count=0, found=[])
    try:
        gw = gateway()
        network=local_network(me)
        # Large subnets are not enumerated: sweep only the agent's /24 and retain known neighbors.
        sweep=network if network.num_addresses<=256 else ipaddress.IPv4Network(me+'/24',strict=False)
        ips=[str(ip) for ip in sweep.hosts()] if ipaddress.ip_address(me).is_private else [me]
        known=arp_table()
        for address in known:
            try:
                if ipaddress.ip_address(address) in network and address not in ips and len(ips)<512:ips.append(address)
            except ValueError:continue
        previous_ports={d.get('ip'):[p['port'] for p in d.get('open_ports',[]) if isinstance(p,dict) and p.get('port') in SERVICE_PORTS] for d in CACHE['devices']}
        PROGRESS["total"]=len(ips)
        latencies, partial = {}, []
        PROGRESS["phase"] = "Sondando endereços IPv4 e tabela ARP"
        with ThreadPoolExecutor(max_workers=4) as pool:
            for idx, (ip, ms) in enumerate(zip(ips, pool.map(discover_ping, ips)), 1):
                latencies[ip] = ms
                if ms is not None: partial.append(build_device(ip, None, None, gw, me, ms))
                if idx % 8 == 0 or idx == len(ips):
                    with LOCK: PROGRESS.update(done=idx, count=len(partial), found=partial[:])
        arp = arp_table()
        # Alguns hosts so entram na tabela ARP apos sondagem; consultar novamente ao final.
        live = sorted({ip for ip in ips if latencies.get(ip) is not None or ip in arp}, key=lambda x: tuple(map(int, x.split("."))))
        # ICMP + local neighbor cache discover devices without scanning their service ports.
        live=sorted(set(live),key=lambda x:tuple(map(int,x.split("."))))
        PROGRESS["phase"] = "Resolvendo nomes e identificando serviços"
        names={d.get('ip'):d.get('name') for d in CACHE['devices'] if d.get('name')}
        names.update({ip:cached[1] for ip,cached in NAME_CACHE.items() if time.monotonic()-cached[0]<300})
        unresolved=[ip for ip in live if ip not in names][:32]
        with ThreadPoolExecutor(max_workers=4) as pool:
            names.update(zip(unresolved,pool.map(reverse_name,unresolved)))
        # Windows NetBIOS revela nomes que não aparecem no DNS reverso.
        unknown=[ip for ip in live if not names.get(ip) and ip not in {me,gw}][:16]
        if WIN:
            with ThreadPoolExecutor(max_workers=4) as pool:
                names.update({ip:n for ip,n in zip(unknown,pool.map(netbios_name,unknown)) if n})
        scanned=[previous_ports.get(ip,[]) for ip in live]
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
            CACHE.update(t=time.time(), devices=result, local_ip=me)
            PROGRESS.update(active=False, done=len(ips), total=len(ips), phase="Varredura concluída", count=len(result), found=result[:])
        return result
    except Exception:
        with LOCK: PROGRESS.update(active=False, phase="Falha na varredura")
        raise



def private_host(host):
    try: return ipaddress.ip_address(socket.gethostbyname(host)).is_private
    except Exception: return False


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
                address=socket.gethostbyname(host)
                if ipaddress.ip_address(address) not in local_network(local_ip()):return self.reply(400,{'error':'apenas endereços da sub-rede local'})
                opened=probe_services(address)
                return self.reply(200,{'host':address,'open':[{'port':port,'service':SERVICE_PORTS[port]} for port in opened],'checked':len(SERVICE_PORTS)})
            if p == "/api/dns":
                name = q.get("name", "")
                if not HOST_RE.match(name): return self.reply(400, {"error": "nome inválido"})
                ips = sorted({x[4][0] for x in socket.getaddrinfo(name, None) if x[0] == socket.AF_INET})
                return self.reply(200, {"name": name, "ips": ips, "reverse": [reverse_name(x) for x in ips]})
            if p == "/api/wol": wol(q.get("mac", "")); return self.reply(200, {"ok": True})
            return self.reply(404, {"error": "não encontrado"})
        except Exception as exc: return self.reply(500, {"error": str(exc)})


# BLOCO 02 - PRESENÇA PRÉ-LOGIN AUTORIZADA
# Executado pelo núcleo LOCAL SERVICE. Não abre janelas nem cria acesso remoto.
def prelogin_presence_loop():
    import hashlib
    config_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "agent-config.json")
    try:
        with open(config_path, encoding="utf-8-sig") as handle:
            cfg = json.load(handle)
        origin = str(cfg.get("origin") or "").rstrip("/")
        code = str(cfg.get("site_code") or "").upper()
        key = str(cfg.get("download_key") or "")
        if not origin.startswith("https://") or not re.fullmatch(r"[A-Z0-9]{5}", code) or not re.fullmatch(r"[a-fA-F0-9]{64}", key):
            return
        fingerprint = str(cfg.get("fingerprint") or "").lower()
        if not re.fullmatch(r"[a-f0-9]{64}", fingerprint):
            machine_guid = ""
            if WIN:
                try:
                    import winreg
                    with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r"SOFTWARE\Microsoft\Cryptography") as registry:
                        machine_guid = str(winreg.QueryValueEx(registry, "MachineGuid")[0])
                except (OSError, ValueError):
                    pass
            raw = machine_guid or "|".join((platform.node(), platform.system(), platform.machine()))
            fingerprint = hashlib.sha256(("arcoll-speed-connection|" + raw).encode()).hexdigest()
        endpoint = origin + "/api/api.php?action=agent-presence"
        payload = json.dumps({"fingerprint": fingerprint, "site_code": code, "download_key": key,
                              "state": "online", "version": VERSION}).encode("utf-8")
    except (OSError, ValueError, TypeError):
        return
    while True:
        try:
            request = urllib.request.Request(endpoint, data=payload,
                headers={"Content-Type": "application/json"}, method="POST")
            with urllib.request.urlopen(request, timeout=8) as reply:
                reply.read(2048)
        except (OSError, ValueError, urllib.error.URLError):
            pass
        time.sleep(15)


if __name__ == "__main__":
    print("\nARCOLL AGENT %s ativo" % VERSION)
    print("PWA local: http://localhost:%d" % PORT)
    print("Origens autorizadas: %s" % (", ".join(ALLOWED_ORIGINS) or "nenhuma (use --origin ou ARCOLL_ORIGIN)"))
    print("Pressione Ctrl+C para encerrar.\n")
    threading.Thread(target=prelogin_presence_loop, daemon=True, name="arcoll-presence").start()
    try: ThreadingHTTPServer((LISTEN_HOST, PORT), Handler).serve_forever()
    except KeyboardInterrupt: pass
