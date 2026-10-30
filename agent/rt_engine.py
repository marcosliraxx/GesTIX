# Caminho: agent/rt_engine.py
# ======================================================================
# DETECÇÃO EM TEMPO REAL DE DISPOSITIVOS (online / offline / novo / troca de IP)
# ----------------------------------------------------------------------
# Sem varredura da sub-rede. O monitor combina quatro fontes:
#   1) Tabela de vizinhos do Windows (GetIpNetTable2), lida a cada ~1 s dentro do
#      próprio processo: custo praticamente nulo e zero tráfego de rede.
#   2) Escuta passiva: DHCP, mDNS, SSDP, NetBIOS e LLMNR. Quando um aparelho entra
#      na rede ele se anuncia por esses protocolos; o agente apenas ouve.
#   3) Verificação ARP direcionada (SendARP) SOMENTE dos dispositivos já conhecidos
#      e dos endereços que acabaram de aparecer. ARP responde mesmo com firewall.
#   4) Sinais explícitos de saída (DHCPRELEASE, SSDP byebye, estado Unreachable do
#      Windows) que antecipam e aceleram a confirmação de "offline".
# Cada mudança vira um evento enviado na hora ao servidor (device_event).
# ======================================================================
import ctypes, struct, random, queue as _queue
import re, socket, ipaddress, threading, time, platform, subprocess, sys
from ctypes import wintypes as _wt
from concurrent.futures import ThreadPoolExecutor as _Pool

def _arc_hidden_run(args, **kwargs):
    if platform.system() == "Windows":
        kwargs.setdefault("creationflags", 0x08000000)
    return subprocess.run(args, **kwargs)

def local_ip():
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        try:
            sock.connect(("10.255.255.255", 1))
            return sock.getsockname()[0]
        except OSError:
            return "127.0.0.1"


_RT_GENERIC_NAME = re.compile(r'^(?:dispositivo\s|device\s|\d{1,3}(?:\.\d{1,3}){3}$)', re.I)
_RT_TYPE_WORDS = (
    ('printer', ('print', 'impressora', 'epson', 'brother', 'laserjet', 'canon')),
    ('phone', ('iphone', 'android', 'galaxy', 'pixel', 'redmi', 'xiaomi', 'moto', 'phone', 'celular', 'oneplus', 'huawei')),
    ('tablet', ('ipad', 'tablet', 'tab-')),
    ('tv', ('smarttv', 'smart-tv', 'webos', 'bravia', 'roku', 'tizen', 'chromecast', 'firetv', 'appletv', 'lgtv', '-tv', 'tv-')),
    ('camera', ('cam', 'nvr', 'dvr', 'hikvision', 'intelbras')),
    ('nas', ('nas', 'synology', 'qnap')),
    ('speaker', ('echo', 'homepod', 'speaker', 'sonos', 'alexa')),
    ('console', ('xbox', 'playstation', 'ps4', 'ps5', 'nintendo', 'switch-')),
    ('server', ('server', 'servidor', 'srv')),
    ('router', ('router', 'roteador', 'gateway', 'tplink', 'tp-link', 'archer', 'mikrotik', 'ubnt', 'dlink')),
    ('iot', ('esp-', 'esp32', 'esp8266', 'tuya', 'sensor', 'plug', 'shelly', 'sonoff', 'lamp')),
    ('computer', ('desktop', 'laptop', 'notebook', 'macbook', 'workstation', 'pc-', '-pc', 'win-', 'desktop-', 'laptop-')),
)


def rt_guess_type(name):
    text = str(name or '').lower()
    if not text:
        return ''
    for kind, words in _RT_TYPE_WORDS:
        if any(w in text for w in words):
            return kind
    return ''


def rt_valid_mac(value):
    raw = re.sub(r'[^0-9a-fA-F]', '', str(value or '')).upper()
    if len(raw) != 12 or raw in ('000000000000', 'FFFFFFFFFFFF'):
        return None
    if int(raw[:2], 16) & 1:  # grupo (multicast/broadcast) não identifica um host
        return None
    return ':'.join(raw[i:i + 2] for i in range(0, 12, 2))


class _WinBackend:
    """Acesso às APIs de rede do Windows (iphlpapi) sem processos externos."""
    NEIGHBOR_STATES = {0: 'unreachable', 1: 'incomplete', 2: 'probe', 3: 'delay', 4: 'stale', 5: 'reachable', 6: 'permanent'}

    class _Row(ctypes.Structure):
        # MIB_IPNET_ROW2 (88 bytes em x64): Address, InterfaceIndex, InterfaceLuid, PhysicalAddress[32],
        # PhysicalAddressLength, State, Flags, ReachabilityTime.
        _fields_ = [('Address', ctypes.c_ubyte * 28), ('InterfaceIndex', ctypes.c_uint32), ('InterfaceLuid', ctypes.c_uint64),
                    ('PhysicalAddress', ctypes.c_ubyte * 32), ('PhysicalAddressLength', ctypes.c_uint32), ('State', ctypes.c_uint32),
                    ('Flags', ctypes.c_ubyte), ('ReachabilityTime', ctypes.c_uint32)]

    def __init__(self):
        self.ip = ctypes.WinDLL('iphlpapi', use_last_error=True)
        self.ip.SendARP.argtypes = [ctypes.c_uint32, ctypes.c_uint32, ctypes.c_void_p, ctypes.POINTER(_wt.ULONG)]
        self.ip.SendARP.restype = _wt.DWORD
        self.ip.GetIpNetTable2.argtypes = [ctypes.c_ushort, ctypes.POINTER(ctypes.c_void_p)]
        self.ip.GetIpNetTable2.restype = _wt.DWORD
        self.ip.FreeMibTable.argtypes = [ctypes.c_void_p]
        self.ip.FreeMibTable.restype = None
        self.ip.GetIpAddrTable.argtypes = [ctypes.c_void_p, ctypes.POINTER(_wt.ULONG), _wt.BOOL]
        self.ip.GetIpAddrTable.restype = _wt.DWORD
        self.table_ok = True

    def arp(self, ip):
        """Resolve o MAC de UM endereço da rede local. None = ninguém respondeu."""
        try:
            dest = struct.unpack('<I', socket.inet_aton(ip))[0]
            buf = (ctypes.c_ubyte * 8)()
            size = _wt.ULONG(6)
            if self.ip.SendARP(dest, 0, ctypes.byref(buf), ctypes.byref(size)) != 0 or size.value < 6:
                return None
            return rt_valid_mac(bytes(buf[:6]).hex())
        except Exception:
            return None

    def neighbors(self):
        """[(ip, mac, estado)] do cache de vizinhos IPv4 do Windows."""
        rows = []
        if self.table_ok:
            ptr = ctypes.c_void_p()
            try:
                if self.ip.GetIpNetTable2(2, ctypes.byref(ptr)) == 0 and ptr.value:
                    count = ctypes.c_uint32.from_address(ptr.value).value
                    base = ptr.value + 8  # NumEntries (4) + preenchimento até o alinhamento de 8 bytes
                    size = ctypes.sizeof(self._Row)
                    for i in range(min(count, 4096)):
                        row = self._Row.from_address(base + i * size)
                        if row.Address[0] != 2 or row.PhysicalAddressLength != 6:  # AF_INET + MAC de 6 bytes
                            continue
                        mac = rt_valid_mac(bytes(row.PhysicalAddress[:6]).hex())
                        if not mac:
                            continue
                        rows.append(('.'.join(str(b) for b in row.Address[4:8]), mac, self.NEIGHBOR_STATES.get(row.State, 'unknown')))
                    return rows
                self.table_ok = False
            except Exception:
                self.table_ok = False
            finally:
                if ptr.value:
                    try: self.ip.FreeMibTable(ptr)
                    except Exception: pass
        # Contingência: tabela ARP textual (sem estados detalhados).
        try:
            out = _arc_hidden_run(['arp', '-a'], capture_output=True, text=True, timeout=4, errors='ignore').stdout
            for line in out.splitlines():
                m = re.match(r'\s*(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-fA-F]{2}(?:-[0-9a-fA-F]{2}){5})\s+(\w+)', line)
                if m and rt_valid_mac(m.group(2)):
                    rows.append((m.group(1), rt_valid_mac(m.group(2)), 'permanent' if m.group(3).lower() in ('static', 'estático', 'estatico') else 'stale'))
        except Exception:
            pass
        return rows

    def netmask(self, ip):
        try:
            size = _wt.ULONG(0)
            self.ip.GetIpAddrTable(None, ctypes.byref(size), False)
            buf = ctypes.create_string_buffer(size.value)
            if self.ip.GetIpAddrTable(buf, ctypes.byref(size), False) != 0:
                return None
            n = struct.unpack_from('<I', buf, 0)[0]
            want = socket.inet_aton(ip)
            for i in range(n):
                addr, _idx, mask = struct.unpack_from('<4sII', buf, 4 + i * 24)
                if addr == want:
                    return socket.inet_ntoa(struct.pack('<I', mask))
        except Exception:
            pass
        return None


class RealtimeNetworkWatcher:
    HEARTBEAT_ONLINE = 2.0      # s entre verificações ARP de cada dispositivo online
    HEARTBEAT_OFFLINE = 3.0     # s entre verificações dos conhecidos offline (a escuta passiva os traz de volta na hora)
    FAST_INTERVAL = 0.8         # s entre confirmações quando há sinal explícito de saída
    OFFLINE_MISSES = 2          # falhas ARP consecutivas para declarar offline (saída silenciosa)
    OFFLINE_MIN_SECONDS = 7.0  # ...e tempo mínimo sem nenhum sinal de vida (evita falso offline em Wi-Fi com economia de energia)
    FAST_MISSES = 2             # falhas consecutivas quando há sinal explícito (DHCPRELEASE, SSDP byebye, Unreachable)
    VERIFY_COOLDOWN = 2.0       # s mínimos entre verificações do mesmo IP disparadas por escuta passiva
    NETWORK_GRACE = 20.0        # s sem veredito de offline após trocar de rede/IP do computador

    def __init__(self, on_events, log=None, backend=None, own_ip=None, own_mac=None):
        self.on_events = on_events
        self._log = log or (lambda msg, level='INFO': None)
        self.backend = backend
        self.lock = threading.RLock()
        self.devices = {}          # mac -> registro
        self.ip_index = {}         # ip -> mac
        self.hints = {}            # ip -> (nome, instante)
        self.dhcp_names = {}       # mac -> (nome, instante)
        self.verify_at = {}        # ip -> instante da última verificação
        self.inflight = set()      # ips em verificação
        self.network = None
        self.own_ip = ''
        self.own_macs = set([m for m in [rt_valid_mac(own_mac)] if m])
        self._own_ip_override = own_ip
        self.grace_until = 0.0
        self.paused = False
        self.running = False
        self.epoch = 0
        self.listeners = {}        # nome -> ativo?
        self.stats = {'events': 0, 'probes': 0, 'passive': 0, 'started': 0.0}
        self._events = _queue.Queue()
        self._pool = None
        self._threads = []

    # ----------------------------------------------------------- ciclo de vida
    def start(self):
        if self.running:
            return
        if self.backend is None:
            self.backend = _WinBackend()
        self.running = True
        self.stats['started'] = time.time()
        self._pool = _Pool(max_workers=12, thread_name_prefix='arc-rt')
        self._refresh_network(initial=True)
        loops = [('table', self._table_loop), ('heartbeat', self._heartbeat_loop), ('network', self._network_loop), ('dispatch', self._dispatch_loop)]
        listeners = [('DHCP', 67, None, self._on_dhcp), ('mDNS', 5353, '224.0.0.251', self._on_mdns), ('SSDP', 1900, '239.255.255.250', self._on_ssdp),
                     ('NetBIOS', 137, None, self._on_nbns), ('LLMNR', 5355, '224.0.0.252', self._on_llmnr)]
        for name, fn in loops:
            self._spawn(fn, 'arc-rt-' + name)
        for name, port, group, handler in listeners:
            self._spawn(lambda n=name, p=port, g=group, h=handler: self._listener_loop(n, p, g, h), 'arc-rt-ln-' + name)
        self._log('Detecção em tempo real iniciada (vizinhos do Windows, escuta passiva e verificação ARP dirigida).')

    def stop(self):
        self.running = False
        try:
            if self._pool:
                self._pool.shutdown(wait=False, cancel_futures=True)
        except Exception:
            pass

    def set_paused(self, value):
        self.paused = bool(value)

    def _spawn(self, fn, name):
        t = threading.Thread(target=self._guard, args=(fn, name), name=name, daemon=True)
        t.start()
        self._threads.append(t)

    def _guard(self, fn, name):
        while self.running:
            try:
                fn()
                return
            except Exception as exc:
                self._log('Tempo real (%s): %s' % (name, str(exc)[:160]), 'ERROR')
                time.sleep(3)

    # ------------------------------------------------------------------ rede
    def _local_ip(self):
        return self._own_ip_override or local_ip()

    def _refresh_network(self, initial=False):
        ip = self._local_ip()
        mask = None
        try:
            mask = self.backend.netmask(ip) if hasattr(self.backend, 'netmask') else None
        except Exception:
            mask = None
        try:
            net = ipaddress.IPv4Network('%s/%s' % (ip, mask or '255.255.255.0'), strict=False)
        except Exception:
            net = ipaddress.IPv4Network(ip.rsplit('.', 1)[0] + '.0/24', strict=False)
        changed = (ip != self.own_ip) or (self.network is None) or (net != self.network)
        if changed:
            with self.lock:
                self.own_ip = ip
                self.network = net
                self.epoch += 1
                self.inflight.clear()
                self.verify_at.clear()
                if not initial:
                    self.grace_until = time.time() + self.NETWORK_GRACE
            try:
                mac = self.backend.arp(ip)
                if mac:
                    self.own_macs.add(mac)
            except Exception:
                pass
            if not initial:
                self._log('Rede do computador mudou (%s). Reavaliando dispositivos sem declarar quedas por %d s.' % (net, self.NETWORK_GRACE))
        return changed

    def _network_loop(self):
        while self.running:
            time.sleep(2.0)
            if not self.paused:
                self._refresh_network()

    def _in_scope(self, ip):
        try:
            a = ipaddress.ip_address(ip)
            if not isinstance(a, ipaddress.IPv4Address) or a.is_multicast or a.is_unspecified or a.is_loopback or a.is_link_local:
                return False
            if ip == self.own_ip or self.network is None or a not in self.network:
                return False
            if a == self.network.network_address or a == self.network.broadcast_address:
                return False
            return a.is_private
        except Exception:
            return False

    # ---------------------------------------------------------------- inventário
    def seed(self, devices):
        """Carrega o inventário conhecido (dicts com mac/ip/name/type/status)."""
        now = time.time()
        with self.lock:
            for d in devices or []:
                mac = rt_valid_mac(d.get('mac'))
                ip = str(d.get('ip') or '')
                if not mac or mac in self.own_macs:
                    continue
                online = str(d.get('status') or '') == 'online'
                self.devices[mac] = {'mac': mac, 'ip': ip, 'name': str(d.get('custom_name') or d.get('name') or ''), 'type': str(d.get('custom_type') or d.get('type') or ''),
                                     'online': online, 'last_alive': now if online else float(d.get('last_seen') or 0), 'misses': 0, 'fast': False,
                                     'next_probe': now + random.uniform(0.5, self.HEARTBEAT_ONLINE if online else self.HEARTBEAT_OFFLINE), 'busy': False}
                if ip:
                    self.ip_index[ip] = mac

    def sync_inventory(self, devices):
        """Alinha a memória do monitor com o inventário após uma varredura manual (sem emitir eventos)."""
        now = time.time()
        with self.lock:
            for d in devices or []:
                mac = rt_valid_mac(d.get('mac'))
                if not mac or mac in self.own_macs:
                    continue
                online = str(d.get('status') or '') == 'online'
                rec = self.devices.get(mac)
                ip = str(d.get('ip') or '')
                if rec is None:
                    rec = self.devices[mac] = {'mac': mac, 'ip': ip, 'name': '', 'type': '', 'online': online, 'last_alive': now if online else 0.0, 'misses': 0, 'fast': False,
                                              'next_probe': now + random.uniform(0.5, 4.0), 'busy': False}
                rec['online'] = online
                if online:
                    rec['last_alive'] = now
                    rec['misses'] = 0
                if ip:
                    if rec['ip'] and self.ip_index.get(rec['ip']) == mac and rec['ip'] != ip:
                        self.ip_index.pop(rec['ip'], None)
                    rec['ip'] = ip
                    self.ip_index[ip] = mac
                rec['name'] = str(d.get('custom_name') or d.get('name') or rec['name'])
                rec['type'] = str(d.get('custom_type') or d.get('type') or rec['type'])

    def snapshot_times(self):
        """{mac: last_alive} dos online, para o app registrar 'visto por último' sem gravar a cada batimento."""
        with self.lock:
            return {m: r['last_alive'] for m, r in self.devices.items() if r['online'] and r['last_alive'] > 0}

    def status_text(self):
        on = sum(1 for r in self.devices.values() if r['online'])
        active = [n for n, ok in sorted(self.listeners.items()) if ok]
        return 'Tempo real ativo · %d online de %d · escuta: %s' % (on, len(self.devices), ', '.join(active) if active else 'somente vizinhos/ARP')

    # ------------------------------------------------------------------ eventos
    def _emit(self, kind, rec, via, ts=None, prev_ip=''):
        ev = {'type': kind, 'mac': rec['mac'], 'ip': rec['ip'], 'prev_ip': prev_ip, 'name': rec['name'], 'dtype': rec['type'], 'via': via, 'ts': int(ts or time.time())}
        self.stats['events'] += 1
        self._events.put(ev)

    def _dispatch_loop(self):
        while self.running:
            try:
                first = self._events.get(timeout=1.0)
            except _queue.Empty:
                continue
            batch = [first]
            time.sleep(0.12)  # agrupa rajadas (ex.: roteador reiniciando) em um único envio
            while len(batch) < 40:
                try:
                    batch.append(self._events.get_nowait())
                except _queue.Empty:
                    break
            try:
                self.on_events(batch)
            except Exception as exc:
                self._log('Tempo real: falha ao entregar eventos: ' + str(exc)[:160], 'ERROR')

    def _name_for(self, mac, ip):
        n = ''
        h = self.dhcp_names.get(mac)
        if h and time.time() - h[1] < 600:
            n = h[0]
        if not n:
            h = self.hints.get(ip)
            if h and time.time() - h[1] < 600:
                n = h[0]
        return n

    def _set_online(self, mac, ip, via, name=''):
        if mac in self.own_macs or not self._in_scope(ip):
            return
        now = time.time()
        emit = None
        with self.lock:
            rec = self.devices.get(mac)
            name = name or self._name_for(mac, ip)
            if rec is None:
                rec = self.devices[mac] = {'mac': mac, 'ip': ip, 'name': name, 'type': rt_guess_type(name), 'online': True, 'last_alive': now, 'misses': 0, 'fast': False,
                                           'next_probe': now + self.HEARTBEAT_ONLINE, 'busy': False}
                self.ip_index[ip] = mac
                emit = ('new', '')
            else:
                old_ip = rec['ip']
                was_online = rec['online']
                if name and (not rec['name'] or _RT_GENERIC_NAME.match(rec['name'])):
                    rec['name'] = name
                    rec['type'] = rec['type'] if rec['type'] not in ('', 'device') else rt_guess_type(name)
                rec['last_alive'] = now
                rec['misses'] = 0
                rec['fast'] = False
                if old_ip != ip:
                    if self.ip_index.get(old_ip) == mac:
                        self.ip_index.pop(old_ip, None)
                    rec['ip'] = ip
                    self.ip_index[ip] = mac
                rec['online'] = True
                if not was_online:
                    emit = ('online', old_ip if old_ip != ip else '')
                elif old_ip and old_ip != ip:
                    emit = ('ip_change', old_ip)
                self.ip_index[ip] = mac
        if emit:
            self._emit(emit[0], rec, via, now, prev_ip=emit[1])
            if emit[0] == 'new' and not rec['name']:
                self._pool_submit(self._resolve_name, mac, ip)

    def _set_offline(self, mac, via):
        now = time.time()
        with self.lock:
            rec = self.devices.get(mac)
            if rec is None or not rec['online']:
                return
            rec['online'] = False
            rec['misses'] = 0
            rec['fast'] = False
        self._emit('offline', rec, via, now)

    def _miss(self, mac, via):
        now = time.time()
        with self.lock:
            rec = self.devices.get(mac)
            if rec is None or not rec['online']:
                return
            rec['misses'] += 1
            grace = now < self.grace_until
            fast = rec['fast']
            ready = (rec['misses'] >= self.FAST_MISSES) if fast else (rec['misses'] >= self.OFFLINE_MISSES and now - rec['last_alive'] >= self.OFFLINE_MIN_SECONDS)
        if ready and not grace and not self.paused:
            self._set_offline(mac, via)

    def _signal_departure(self, mac, via):
        """Sinal explícito de saída: acelera a confirmação (2 verificações ARP em ~2 s)."""
        with self.lock:
            rec = self.devices.get(mac)
            if rec is None or not rec['online']:
                return
            rec['fast'] = True
            rec['misses'] = 0
            rec['next_probe'] = 0.0

    # ---------------------------------------------------------------- verificações
    def _pool_submit(self, fn, *args):
        try:
            if self.running and self._pool:
                self._pool.submit(self._safe, fn, *args)
        except Exception:
            pass

    def _safe(self, fn, *args):
        try:
            fn(*args)
        except Exception as exc:
            self._log('Tempo real: ' + str(exc)[:160], 'ERROR')

    def _verify(self, ip, expected, via, epoch):
        """Pergunta por ARP quem tem o IP. Atualiza estado conforme a resposta."""
        try:
            self.stats['probes'] += 1
            found = self.backend.arp(ip)
        finally:
            with self.lock:
                self.inflight.discard(ip)
                if expected and expected in self.devices:
                    self.devices[expected]['busy'] = False
        if epoch != self.epoch or not self.running:
            return
        if found:
            if found in self.own_macs:
                return
            if expected and found != expected:
                # O IP passou a pertencer a outro aparelho: o antigo não está mais nesse endereço.
                self._miss(expected, 'arp')
            self._set_online(found, ip, via)
        elif expected:
            self._miss(expected, 'arp')

    def _request_verify(self, ip, expected=None, via='arp', force=False):
        now = time.time()
        with self.lock:
            if not self.running or ip in self.inflight or not self._in_scope(ip):
                return False
            if not force and now - self.verify_at.get(ip, 0) < self.VERIFY_COOLDOWN:
                return False
            self.verify_at[ip] = now
            self.inflight.add(ip)
            if expected and expected in self.devices:
                self.devices[expected]['busy'] = True
            epoch = self.epoch
        self._pool_submit(self._verify, ip, expected, via, epoch)
        return True

    def _heartbeat_loop(self):
        while self.running:
            time.sleep(0.5)
            if self.paused:
                continue
            now = time.time()
            due = []
            with self.lock:
                for mac, rec in self.devices.items():
                    if rec['busy'] or not rec['ip'] or now < rec['next_probe']:
                        continue
                    interval = self.FAST_INTERVAL if rec['fast'] else (self.HEARTBEAT_ONLINE if rec['online'] else self.HEARTBEAT_OFFLINE)
                    rec['next_probe'] = now + interval + random.uniform(0, 0.4)
                    due.append((mac, rec['ip']))
            for mac, ip in due[:64]:
                self._request_verify(ip, expected=mac, via='heartbeat', force=True)

    # ---------------------------------------------------------- tabela de vizinhos
    def _table_loop(self):
        last_state = {}
        while self.running:
            time.sleep(1.0)
            if self.paused:
                continue
            try:
                rows = self.backend.neighbors()
            except Exception:
                rows = []
            seen_now = {}
            for ip, mac, state in rows:
                if not self._in_scope(ip) or mac in self.own_macs:
                    continue
                seen_now[ip] = (mac, state)
                with self.lock:
                    rec = self.devices.get(mac)
                if state in ('reachable',):
                    # O Windows acabou de confirmar comunicação com o aparelho: prova de vida imediata.
                    if rec is None or not rec['online'] or rec['ip'] != ip:
                        self._request_verify(ip, expected=mac if rec else None, via='neighbor')
                    else:
                        with self.lock:
                            rec['last_alive'] = time.time()
                            rec['misses'] = 0
                elif state == 'unreachable' and rec is not None and rec['online'] and last_state.get(ip, (None, ''))[1] != 'unreachable':
                    self._signal_departure(mac, 'neighbor')
                elif rec is None and state in ('stale', 'delay', 'probe'):
                    # Entrada nova no cache de vizinhos: confirma por ARP antes de declarar um novo dispositivo.
                    self._request_verify(ip, via='neighbor')
                elif rec is not None and rec['ip'] != ip and state in ('reachable', 'stale', 'delay', 'probe'):
                    self._request_verify(ip, expected=mac, via='neighbor')
            last_state = seen_now

    # ------------------------------------------------------------ escuta passiva
    def _hear(self, ip, via, mac=None, name=''):
        if not self.running or self.paused or not self._in_scope(ip):
            return
        self.stats['passive'] += 1
        now = time.time()
        if name:
            self.hints[ip] = (name, now)
            if len(self.hints) > 600:
                for k in sorted(self.hints, key=lambda x: self.hints[x][1])[:200]:
                    self.hints.pop(k, None)
        mac = rt_valid_mac(mac) if mac else None
        with self.lock:
            known = mac or self.ip_index.get(ip)
            rec = self.devices.get(known) if known else None
            if rec is not None and rec['online'] and rec['ip'] == ip:
                rec['last_alive'] = now   # um pacote vindo deste IP é prova de vida
                rec['misses'] = 0
                rec['fast'] = False
                if name and (not rec['name'] or _RT_GENERIC_NAME.match(rec['name'])):
                    rec['name'] = name
                    rec['type'] = rec['type'] if rec['type'] not in ('', 'device') else rt_guess_type(name)
                return
        if mac and not rec:
            # MAC conhecido pelo pacote (DHCP): confirma presença sem esperar o ARP quando já temos o IP.
            if name:
                self.dhcp_names[mac] = (name, now)
        self._request_verify(ip, expected=known if rec else None, via=via)

    def _listener_loop(self, name, port, group, handler):
        sock = None
        bound_epoch = -1
        while self.running:
            if sock is None or bound_epoch != self.epoch:
                try:
                    if sock:
                        sock.close()
                except Exception:
                    pass
                sock = None
                try:
                    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
                    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                    s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
                    s.bind(('', port))
                    if group:
                        s.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, socket.inet_aton(group) + socket.inet_aton(self.own_ip))
                    s.settimeout(1.0)
                    sock = s
                    bound_epoch = self.epoch
                    self.listeners[name] = True
                except Exception:
                    self.listeners[name] = False
                    sock = None
                    time.sleep(30.0)  # porta ocupada/bloqueada: tenta de novo mais tarde, sem insistir
                    continue
            try:
                data, addr = sock.recvfrom(4096)
            except socket.timeout:
                continue
            except Exception:
                sock = None
                time.sleep(2.0)
                continue
            try:
                handler(addr[0], data)
            except Exception:
                pass

    # --- DHCP ---------------------------------------------------------------
    def _on_dhcp(self, src, data):
        if len(data) < 241 or data[0] != 1 or data[236:240] != b'\x63\x82\x53\x63':
            return  # só mensagens de cliente (BOOTREQUEST) com cookie válido
        hlen = data[2]
        mac = rt_valid_mac(data[28:28 + hlen].hex()) if hlen == 6 else None
        ciaddr = socket.inet_ntoa(data[12:16])
        i, mtype, host, req = 240, 0, '', ''
        while i + 1 < len(data):
            code = data[i]
            if code == 255:
                break
            if code == 0:
                i += 1
                continue
            ln = data[i + 1]
            val = data[i + 2:i + 2 + ln]
            if code == 53 and ln == 1:
                mtype = val[0]
            elif code == 12:
                host = val.decode('utf-8', 'ignore').strip('\x00 ').split('.')[0][:63]
            elif code == 50 and ln == 4:
                req = socket.inet_ntoa(val)
            i += 2 + ln
        if not mac:
            return
        ip = req or (ciaddr if ciaddr != '0.0.0.0' else '')
        if host:
            self.dhcp_names[mac] = (host, time.time())
        if mtype == 7:      # DHCPRELEASE: o aparelho informou que está saindo
            self._signal_departure(mac, 'dhcp-release')
            with self.lock:
                rec = self.devices.get(mac)
                ip_rel = rec['ip'] if rec else ip
            if ip_rel:
                self._request_verify(ip_rel, expected=mac, via='dhcp-release', force=True)
        elif mtype in (1, 3, 8) and ip:
            self._hear(ip, 'dhcp', mac=mac, name=host)

    # --- mDNS ----------------------------------------------------------------
    @staticmethod
    def _dns_name(data, off):
        labels, jumped, end, hops = [], False, off, 0
        while off < len(data) and hops < 20:
            ln = data[off]
            if ln == 0:
                if not jumped:
                    end = off + 1
                break
            if ln & 0xC0 == 0xC0:
                ptr = ((ln & 0x3F) << 8) | data[off + 1]
                if not jumped:
                    end = off + 2
                off, jumped, hops = ptr, True, hops + 1
                continue
            labels.append(data[off + 1:off + 1 + ln].decode('utf-8', 'ignore'))
            off += 1 + ln
            if not jumped:
                end = off
        return '.'.join(labels), end

    def _on_mdns(self, src, data):
        name = ''
        try:
            if len(data) >= 12:
                qd, an, ns, ar = struct.unpack('!4H', data[4:12])
                off = 12
                for _ in range(qd):
                    _n, off = self._dns_name(data, off)
                    off += 4
                for _ in range(min(an + ns + ar, 24)):
                    n, off = self._dns_name(data, off)
                    rtype, _cls, _ttl, rdlen = struct.unpack('!HHIH', data[off:off + 10])
                    off += 10
                    if rtype == 1 and rdlen == 4 and n.lower().endswith('.local') and socket.inet_ntoa(data[off:off + 4]) == src:
                        name = n[:-6].split('.')[0]
                        break
                    off += rdlen
        except Exception:
            pass
        self._hear(src, 'mdns', name=name)

    # --- SSDP ----------------------------------------------------------------
    def _on_ssdp(self, src, data):
        text = data[:1500].decode('utf-8', 'ignore')
        if text.upper().startswith('NOTIFY'):
            m = re.search(r'^NTS:\s*(\S+)', text, re.I | re.M)
            if m and m.group(1).lower() == 'ssdp:byebye':
                mac = self.ip_index.get(src)
                if mac:
                    self._signal_departure(mac, 'ssdp-byebye')
                return
            self._hear(src, 'ssdp')

    # --- NetBIOS / LLMNR -----------------------------------------------------
    def _on_nbns(self, src, data):
        name = ''
        try:
            if len(data) >= 50 and data[12] == 0x20:
                enc = data[13:45].decode('ascii', 'ignore')
                raw = bytes(((ord(enc[i]) - 65) << 4) | (ord(enc[i + 1]) - 65) for i in range(0, 32, 2))
                cand = raw[:15].decode('ascii', 'ignore').strip()
                if cand and cand.isprintable() and cand.upper() != 'WORKGROUP' and '*' not in cand:
                    name = cand
        except Exception:
            pass
        self._hear(src, 'netbios', name=name)

    def _on_llmnr(self, src, data):
        self._hear(src, 'llmnr')

    # ------------------------------------------------------------------ nomes
    def _resolve_name(self, mac, ip):
        name = ''
        try:
            name = socket.gethostbyaddr(ip)[0].split('.')[0]
        except Exception:
            name = ''
        if not name and platform.system() == 'Windows':
            try:
                out = _arc_hidden_run(['nbtstat', '-A', ip], capture_output=True, text=True, timeout=4, errors='ignore').stdout
                for line in out.splitlines():
                    m = re.match(r'\s*([^\s<]+)\s+<00>\s+UNIQUE', line, re.I)
                    if m and m.group(1) != '__MSBROWSE__':
                        name = m.group(1).strip()
                        break
            except Exception:
                pass
        if not name:
            with self.lock:
                name = self._name_for(mac, ip)
        if not name or _RT_GENERIC_NAME.match(name):
            return
        with self.lock:
            rec = self.devices.get(mac)
            if rec is None or (rec['name'] and not _RT_GENERIC_NAME.match(rec['name'])):
                return
            rec['name'] = name
            if rec['type'] in ('', 'device'):
                rec['type'] = rt_guess_type(name)
        self._emit('update', rec, 'name')


def rt_ensure_firewall_rule(log=None):
    """Libera UDP de entrada para o Python do agente (escuta de DHCP/mDNS/SSDP). Só funciona com privilégio de administrador; sem ele,
    a detecção continua por vizinhos + ARP e o Windows pode exibir o aviso padrão de firewall na primeira escuta."""
    if platform.system() != 'Windows':
        return
    try:
        rule = 'Arcoll Agent - descoberta passiva'
        probe = _arc_hidden_run(['netsh', 'advfirewall', 'firewall', 'show', 'rule', 'name=' + rule], capture_output=True, text=True, timeout=8, errors='ignore')
        if probe.returncode == 0 and rule.lower() in (probe.stdout or '').lower():
            return
        exe = sys.executable
        add = _arc_hidden_run(['netsh', 'advfirewall', 'firewall', 'add', 'rule', 'name=' + rule, 'dir=in', 'action=allow', 'protocol=UDP',
                               'program=' + exe, 'profile=private,domain', 'enable=yes'], capture_output=True, text=True, timeout=10, errors='ignore')
        if log:
            log('Regra de firewall da escuta passiva ' + ('criada.' if add.returncode == 0 else 'não criada (requer administrador); usando vizinhos/ARP.'))
    except Exception:
        pass
