#!/usr/bin/env python3
# Caminho: agent/arcoll_app.py
"""Caminho: agent/arcoll_app.py
Arcoll Speed Connection - aplicativo desktop nativo integrado ao agente local."""
import os, sys, json, time, socket, platform, threading, subprocess, urllib.request, urllib.error, csv, ipaddress, re, shutil, hashlib, html, webbrowser
from pathlib import Path
import tkinter as tk
from tkinter import ttk, messagebox, filedialog, simpledialog
import sqlite3, statistics, math, urllib.parse, queue, codecs, tempfile, base64
from contextlib import contextmanager
try:
 from rt_engine import RealtimeNetworkWatcher
except ImportError:
 RealtimeNetworkWatcher=None
try:
 from arcoll_toolkit import QUICK_COMMANDS, run_quick_command, AppearanceManager, theme_color, bind_theme, bind_canvas, friendly_error
except Exception:
 QUICK_COMMANDS={}; run_quick_command=None

APP_NAME='Arcoll Speed Connection'
AGENT_VERSION='3.2.0'
ROOT=Path(__file__).resolve().parent
CFG_PATH=Path(os.environ.get('APPDATA',str(Path.home()))) / 'Arcoll' / 'agent.json'
DATA_DIR=Path(os.environ.get('LOCALAPPDATA',str(Path.home()))) / 'Arcoll'
DATA_DIR.mkdir(parents=True, exist_ok=True)
HISTORY_DB=DATA_DIR/'desktop-history.sqlite3'
@contextmanager
def history_db(timeout=15):
 connection=sqlite3.connect(HISTORY_DB,timeout=timeout)
 try:
  with connection:yield connection
 finally:connection.close()

try: CFG=json.loads(CFG_PATH.read_text(encoding='utf-8-sig'))
except Exception: CFG={}
try:
 BUNDLED_CFG=json.loads((ROOT/'agent-config.json').read_text(encoding='utf-8-sig'))
except Exception:
 BUNDLED_CFG={}
if not isinstance(CFG,dict):CFG={}
if not isinstance(BUNDLED_CFG,dict):BUNDLED_CFG={}
# O cadastro existente nem sempre gravava a origem. A configuração da instalação
# preenche a URL, sem substituir o fingerprint autorizado ou a chave revogável.
for _key in ('origin','site_code','download_key'):
 if not CFG.get(_key) and BUNDLED_CFG.get(_key):CFG[_key]=BUNDLED_CFG[_key]

ORIGIN=str(CFG.get('origin') or os.environ.get('ARCOLL_ORIGIN','')).rstrip('/')
if not ORIGIN.startswith('https://'):ORIGIN=''

SITE_CODE=str(CFG.get('site_code') or os.environ.get('ARCOLL_SITE_CODE','')).upper()
DOWNLOAD_KEY=str(CFG.get('download_key') or os.environ.get('ARCOLL_DOWNLOAD_KEY',''))
FINGERPRINT=str(CFG.get('fingerprint') or '')
if not FINGERPRINT:
 raw=''
 if platform.system()=='Windows':
  try:
   import winreg
   with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE,r'SOFTWARE\Microsoft\Cryptography') as k: raw=str(winreg.QueryValueEx(k,'MachineGuid')[0])
  except Exception: pass
 raw=raw or '|'.join((platform.node(),platform.system(),platform.machine()))
 FINGERPRINT=hashlib.sha256(('arcoll-speed-connection|'+raw).encode()).hexdigest()

LOCAL_AGENT='http://127.0.0.1:8787'
INSTANCE_HOST='127.0.0.1'
INSTANCE_PORT=38000 + (int(FINGERPRINT[:6], 16) % 2000)

def signal_running_instance():
 try:
  with socket.create_connection((INSTANCE_HOST, INSTANCE_PORT), timeout=0.7) as client:
   client.sendall(b'SHOW')
  return True
 except OSError:
  return False

class HoverTip:
 def __init__(self, root):
  self.root=root; self.tip=None; self.after_id=None; self.widget=None
 def attach(self, widget, text):
  if not widget or not text or getattr(widget,'_arc_tip_bound',False): return
  try:
   widget._arc_tip_bound=True
   widget._arc_tip_text=str(text).strip()
  except Exception:
   return
  widget.bind('<Enter>', lambda _e,w=widget:self._schedule(w), add='+')
  widget.bind('<Leave>', lambda _e:self.hide(), add='+')
  widget.bind('<ButtonPress>', lambda _e:self.hide(), add='+')
 def _schedule(self, widget):
  self.widget=widget
  self.hide(cancel_only=True)
  self.after_id=self.root.after(420, lambda w=widget:self.show(w))
 def show(self, widget):
  self.hide(cancel_only=True)
  text=getattr(widget,'_arc_tip_text','').strip()
  if not text: return
  try:
   x,y=widget.winfo_pointerxy()
   self.tip=tk.Toplevel(self.root)
   self.tip.withdraw(); self.tip.overrideredirect(True); self.tip.attributes('-topmost', True)
   body=bind_theme(tk.Frame(self.tip, bg=theme_color('surface'), highlightbackground=theme_color('accent'), highlightthickness=1), background='surface', highlightbackground='accent')
   body.pack(fill='both',expand=True)
   bind_theme(tk.Label(body, text=text, bg=theme_color('surface'), fg=theme_color('text'), justify='left', wraplength=280, padx=10, pady=8, font=('Segoe UI', 8)), background='surface', foreground='text').pack()
   self.tip.geometry(f'+{x+14}+{y+16}')
   self.tip.deiconify()
  except tk.TclError:
   self.hide(cancel_only=True)
 def hide(self, cancel_only=False):
  if self.after_id:
   try:self.root.after_cancel(self.after_id)
   except Exception:pass
   self.after_id=None
  if cancel_only:return
  if self.tip is not None:
   try:self.tip.destroy()
   except Exception:pass
   self.tip=None


def request_json(url, data=None, timeout=8, headers=None):
 body=None if data is None else json.dumps(data).encode()
 h={'Accept':'application/json','User-Agent':'ArcollDesktop/2.0'}
 if body is not None: h['Content-Type']='application/json'
 h.update(headers or {})
 req=urllib.request.Request(url,data=body,headers=h,method='POST' if body is not None else 'GET')
 with urllib.request.urlopen(req,timeout=timeout) as r: return json.loads(r.read().decode('utf-8','replace'))

def local_api(path, timeout=10): return request_json(LOCAL_AGENT+path,timeout=timeout,headers={'Authorization':'Bearer '+str(CFG.get('local_agent_token',''))})
def local_ip():
 try:
  with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:
   s.connect(('10.255.255.255',1)); return s.getsockname()[0]
 except Exception: return '127.0.0.1'

def gateway_ip():
 if platform.system()=='Windows':
  try:
   o=_arc_hidden_run(['route','print','-4','0.0.0.0'],capture_output=True,text=True,timeout=3,errors='ignore').stdout
   for line in o.splitlines():
    p=line.split()
    if len(p)>=3 and p[0]=='0.0.0.0' and p[1]=='0.0.0.0': return p[2]
  except Exception: pass
 else:
  try:
   o=_arc_hidden_run(['ip','route','show','default'],capture_output=True,text=True,timeout=3).stdout
   m=re.search(r'default via (\S+)',o)
   if m:return m.group(1)
  except Exception: pass
 return ''

def arcoll_host_hardware():
 """Hardware of this Windows machine only; no assumptions about neighbor devices."""
 if platform.system()!='Windows':return {}
 script=r"""
 $ErrorActionPreference='SilentlyContinue'
 [Console]::OutputEncoding=[System.Text.Encoding]::UTF8
 $os=Get-CimInstance Win32_OperatingSystem | Select-Object -First 1
 $cpu=Get-CimInstance Win32_Processor | Select-Object -First 1
 $board=Get-CimInstance Win32_BaseBoard | Select-Object -First 1
 $system=Get-CimInstance Win32_ComputerSystem | Select-Object -First 1
 $disk=@(Get-CimInstance Win32_DiskDrive | ForEach-Object { [pscustomobject]@{model=$_.Model;type=($(if ($_.MediaType -match 'SSD') {'SSD'} elseif ($_.MediaType -match 'HDD|hard disk') {'HDD'} else {'Não identificado'}));media=$_.MediaType;size_bytes=[int64]$_.Size} })
 $addr=Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.IPAddress -eq '__LOCAL_IP__'} | Select-Object -First 1
 $nic=$null
 if ($addr) {$nic=Get-NetAdapter -InterfaceIndex $addr.InterfaceIndex | Select-Object -First 1}
 [pscustomobject]@{operating_system=$os.Caption;os_version=$os.Version;os_architecture=$os.OSArchitecture;processor=$cpu.Name;processor_cores=$cpu.NumberOfCores;processor_threads=$cpu.NumberOfLogicalProcessors;memory_bytes=[int64]$system.TotalPhysicalMemory;motherboard_manufacturer=$board.Manufacturer;motherboard_model=$board.Product;disks=$disk;host_mac=$(if ($nic) {$nic.MacAddress} else {''});host_ip='__LOCAL_IP__';hostname=$env:COMPUTERNAME} | ConvertTo-Json -Depth 5 -Compress
 """.replace('__LOCAL_IP__', local_ip().replace("'",''))
 try:
  import base64
  encoded=base64.b64encode(script.encode('utf-16-le')).decode('ascii')
  r=_arc_hidden_run(['powershell.exe','-NoProfile','-NonInteractive','-EncodedCommand',encoded],capture_output=True,timeout=16)
  if r.returncode!=0:return {}
  value=json.loads(r.stdout.decode('utf-8-sig','replace'))
  return value if isinstance(value,dict) else {}
 except Exception:return {}

def _arc_hidden_run(*args, **kwargs):
    """Run background Windows diagnostics without creating a visible console."""
    if platform.system() == 'Windows':
        kwargs.setdefault('creationflags', getattr(subprocess, 'CREATE_NO_WINDOW', 0x08000000))
        startup = subprocess.STARTUPINFO()
        startup.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        startup.wShowWindow = 0
        kwargs.setdefault('startupinfo', startup)
    return subprocess.run(*args, **kwargs)

def arcoll_diagnostic_argv(raw):
 """CMD-looking diagnostics, run without cmd.exe, shell, PowerShell or redirection."""
 raw=' '.join(str(raw or '').strip().split())
 if not raw or len(raw)>180 or any(ord(c)<32 for c in raw):
  raise ValueError('Comando de diagnóstico inválido')
 host=r'(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,90}[A-Za-z0-9])?)'
 m=re.fullmatch(r'ping(?: (?:-n (10|[1-9])|(-t)))? ('+host+r')',raw,re.I)
 if m:
  # -t mantém o envio progressivo, porém tem limite de 60 amostras por segurança.
  count='60' if m.group(2) else (m.group(1) or '4')
  return ['ping','-n',count,'-w','1200',m.group(3)]
 m=re.fullmatch(r'tracert( -d)? ('+host+r')',raw,re.I)
 if m:
  return ['tracert','-d','-h','16','-w','900',m.group(2)] if m.group(1) else ['tracert','-h','16','-w','900',m.group(2)]
 m=re.fullmatch(r'nslookup ('+host+r')',raw,re.I)
 if m:return ['nslookup',m.group(1)]
 commands={
  'ipconfig':['ipconfig'],
  'ipconfig /all':['ipconfig','/all'],
  'ipconfig /flushdns':['ipconfig','/flushdns'],
  'arp -a':['arp','-a'],
  'route print':['route','print'],
  'netsh wlan show interfaces':['netsh','wlan','show','interfaces'],
  'hostname':['hostname'],
 }
 if raw.lower() in commands:return commands[raw.lower()]
 raise ValueError('Comando não autorizado. Consulte ajuda no terminal da plataforma.')

# ---------------------------------------------------------------------------
# Métricas do computador (aba Servidor). psutil é opcional: sem ele, a coleta
# usa as APIs nativas do Windows, para que a aba nunca fique vazia.
# ---------------------------------------------------------------------------
class SystemMetrics:
    _NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0x08000000) if os.name == 'nt' else 0

    def __init__(self):
        self._lock = threading.Lock()
        self._cpu_prev = None
        self._net_prev = None
        self._slow = {}
        self._slow_time = 0.0
        self._slow_busy = False
        self._psutil = None
        try:
            import psutil
            self._psutil = psutil
            psutil.cpu_percent(interval=None)
        except Exception:
            self._psutil = None
        self.cpu()   # inicializa os contadores para que a primeira amostra real já tenha delta
        self.network()
        self.refresh_slow()

    # ---------------- CPU ----------------
    def cpu(self):
        ps = self._psutil
        if ps:
            try:
                return float(ps.cpu_percent(interval=None))
            except Exception:
                pass
        if os.name == 'nt':
            try:
                import ctypes
                from ctypes import wintypes
                class FILETIME(ctypes.Structure):
                    _fields_ = [('lo', wintypes.DWORD), ('hi', wintypes.DWORD)]
                idle, kern, user = FILETIME(), FILETIME(), FILETIME()
                if ctypes.windll.kernel32.GetSystemTimes(ctypes.byref(idle), ctypes.byref(kern), ctypes.byref(user)):
                    val = lambda f: (f.hi << 32) | f.lo
                    cur = (val(idle), val(kern) + val(user))   # o tempo de kernel já inclui o ocioso
                    prev, self._cpu_prev = self._cpu_prev, cur
                    if prev:
                        total = cur[1] - prev[1]
                        if total > 0:
                            return max(0.0, min(100.0, 100.0 * (total - (cur[0] - prev[0])) / total))
            except Exception:
                pass
        return None

    # ---------------- Memória ----------------
    def memory(self):
        ps = self._psutil
        if ps:
            try:
                vm = ps.virtual_memory()
                return {'percent': float(vm.percent), 'used': int(vm.used), 'available': int(vm.available), 'total': int(vm.total)}
            except Exception:
                pass
        if os.name == 'nt':
            try:
                import ctypes
                from ctypes import wintypes
                class MEMORYSTATUSEX(ctypes.Structure):
                    _fields_ = [('dwLength', wintypes.DWORD), ('dwMemoryLoad', wintypes.DWORD),
                                ('ullTotalPhys', ctypes.c_ulonglong), ('ullAvailPhys', ctypes.c_ulonglong),
                                ('ullTotalPageFile', ctypes.c_ulonglong), ('ullAvailPageFile', ctypes.c_ulonglong),
                                ('ullTotalVirtual', ctypes.c_ulonglong), ('ullAvailVirtual', ctypes.c_ulonglong),
                                ('ullAvailExtendedVirtual', ctypes.c_ulonglong)]
                st = MEMORYSTATUSEX(); st.dwLength = ctypes.sizeof(st)
                if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(st)):
                    total, avail = int(st.ullTotalPhys), int(st.ullAvailPhys)
                    return {'percent': float(st.dwMemoryLoad), 'used': total - avail, 'available': avail, 'total': total}
            except Exception:
                pass
        return None

    # ---------------- Ocupação dos discos ----------------
    def drives(self):
        roots = []
        if os.name == 'nt':
            try:
                import ctypes
                mask = ctypes.windll.kernel32.GetLogicalDrives()
                for i in range(26):
                    if mask & (1 << i):
                        root = chr(65 + i) + ':\\'
                        if ctypes.windll.kernel32.GetDriveTypeW(root) == 3:   # somente discos fixos
                            roots.append(root)
            except Exception:
                roots = [os.environ.get('SystemDrive', 'C:') + '\\']
        else:
            roots = ['/']
        out = []
        for root in roots:
            try:
                u = shutil.disk_usage(root)
                if u.total > 0:
                    out.append((root.rstrip('\\'), 100.0 * u.used / u.total, u.used, u.total))
            except OSError:
                pass
        return out

    # ---------------- Rede (Mbps) ----------------
    def _net_counters(self):
        """Retorna {interface: (bytes_recebidos, bytes_enviados, largura_do_contador)} apenas de adaptadores ativos."""
        ps = self._psutil
        if ps:
            try:
                stats = ps.net_if_stats()
                out = {}
                for name, c in ps.net_io_counters(pernic=True).items():
                    st = stats.get(name)
                    if st is not None and not st.isup:
                        continue
                    if re.search(r'loopback|isatap|teredo|pseudo', name, re.I):
                        continue
                    out[name] = (int(c.bytes_recv), int(c.bytes_sent), 1 << 64)
                return out
            except Exception:
                pass
        if os.name == 'nt':
            try:
                import ctypes
                from ctypes import wintypes
                class MIB_IFROW(ctypes.Structure):
                    _fields_ = [('wszName', ctypes.c_wchar * 256), ('dwIndex', wintypes.DWORD), ('dwType', wintypes.DWORD),
                                ('dwMtu', wintypes.DWORD), ('dwSpeed', wintypes.DWORD), ('dwPhysAddrLen', wintypes.DWORD),
                                ('bPhysAddr', ctypes.c_ubyte * 8), ('dwAdminStatus', wintypes.DWORD), ('dwOperStatus', wintypes.DWORD),
                                ('dwLastChange', wintypes.DWORD), ('dwInOctets', wintypes.DWORD), ('dwInUcastPkts', wintypes.DWORD),
                                ('dwInNUcastPkts', wintypes.DWORD), ('dwInDiscards', wintypes.DWORD), ('dwInErrors', wintypes.DWORD),
                                ('dwInUnknownProtos', wintypes.DWORD), ('dwOutOctets', wintypes.DWORD), ('dwOutUcastPkts', wintypes.DWORD),
                                ('dwOutNUcastPkts', wintypes.DWORD), ('dwOutDiscards', wintypes.DWORD), ('dwOutErrors', wintypes.DWORD),
                                ('dwOutQLen', wintypes.DWORD), ('dwDescrLen', wintypes.DWORD), ('bDescr', ctypes.c_ubyte * 256)]
                size = wintypes.ULONG(0)
                iphlpapi = ctypes.windll.iphlpapi
                iphlpapi.GetIfTable(None, ctypes.byref(size), False)
                if size.value:
                    buf = ctypes.create_string_buffer(size.value)
                    if iphlpapi.GetIfTable(buf, ctypes.byref(size), False) == 0:
                        count = ctypes.c_ulong.from_buffer(buf).value
                        row_size = ctypes.sizeof(MIB_IFROW)
                        out = {}
                        for i in range(count):
                            row = MIB_IFROW.from_buffer_copy(buf, 4 + i * row_size)
                            if row.dwType == 24 or row.dwOperStatus not in (4, 5):   # loopback / inativo
                                continue
                            out[str(row.dwIndex)] = (int(row.dwInOctets), int(row.dwOutOctets), 1 << 32)
                        return out
            except Exception:
                pass
        return None

    def network(self):
        counters = self._net_counters()
        now = time.time()
        if counters is None:
            return None
        prev, self._net_prev = self._net_prev, (now, counters)
        if not prev or now <= prev[0]:
            return None
        elapsed = now - prev[0]
        total_down = total_up = 0.0
        interfaces = []
        for name, (rx, tx, wrap) in counters.items():
            old = prev[1].get(name)
            if not old:
                continue
            down_bits = (rx - old[0]) % wrap
            up_bits = (tx - old[1]) % wrap
            down = down_bits * 8 / elapsed / 1e6
            up = up_bits * 8 / elapsed / 1e6
            total_down += down
            total_up += up
            interfaces.append({'name': name, 'download_mbps': down, 'upload_mbps': up})
        return {'download_mbps': total_down, 'upload_mbps': total_up, 'interfaces': interfaces}

    # ---------------- GPU, atividade do disco e nome da CPU (em segundo plano) ----------------
    def refresh_slow(self):
        """Inicia (uma única vez) os coletores contínuos; as leituras ficam em cache e a interface nunca espera por elas."""
        if os.name != 'nt' or getattr(self, '_bg_started', False):
            return
        self._bg_started = True
        threading.Thread(target=self._ps_stream, daemon=True).start()
        threading.Thread(target=self._nvidia_loop, daemon=True).start()

    def slow(self):
        with self._lock:
            return dict(self._slow)

    def _set_slow(self, **values):
        with self._lock:
            self._slow.update(values)

    def _nvidia_exe(self):
        for exe in (shutil.which('nvidia-smi'),
                    os.path.join(os.environ.get('SystemRoot', r'C:\Windows'), 'System32', 'nvidia-smi.exe'),
                    os.path.join(os.environ.get('ProgramFiles', r'C:\Program Files'), 'NVIDIA Corporation', 'NVSMI', 'nvidia-smi.exe')):
            if exe and os.path.isfile(exe):
                return exe
        return None

    def _nvidia_loop(self):
        exe = self._nvidia_exe()
        if not exe:
            return
        while True:
            try:
                r = subprocess.run([exe, '--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu', '--format=csv,noheader,nounits'],
                                   capture_output=True, timeout=8, creationflags=self._NO_WINDOW)
                gpus = []
                details = []
                for line in r.stdout.decode('utf-8', 'replace').splitlines():
                    parts = [x.strip() for x in line.split(',')]
                    if len(parts) >= 2 and parts[1].replace('.', '', 1).isdigit():
                        gpu = {
                            'name': parts[0],
                            'utilization': float(parts[1]),
                            'memory_used_mb': float(parts[2]) if len(parts) >= 3 and parts[2].replace('.', '', 1).isdigit() else None,
                            'memory_total_mb': float(parts[3]) if len(parts) >= 4 and parts[3].replace('.', '', 1).isdigit() else None,
                            'temperature_c': float(parts[4]) if len(parts) >= 5 and parts[4].replace('.', '', 1).isdigit() else None,
                        }
                        gpus.append(gpu)
                        mem = f" • VRAM {gpu['memory_used_mb']:.0f}/{gpu['memory_total_mb']:.0f} MB" if gpu.get('memory_used_mb') is not None and gpu.get('memory_total_mb') is not None else ''
                        temp = f" • {gpu['temperature_c']:.0f}°C" if gpu.get('temperature_c') is not None else ''
                        details.append(f"{gpu['name']}: {gpu['utilization']:.0f}%{mem}{temp}")
                if gpus:
                    self._set_slow(nv_gpu=max(g.get('utilization') or 0.0 for g in gpus), nv_detail=' | '.join(details), nv_gpus=gpus)
            except Exception:
                pass
            time.sleep(2)

    _PS_SCRIPT = (
        "$ErrorActionPreference='SilentlyContinue';[Console]::OutputEncoding=[Text.Encoding]::UTF8;"
        "$pp=%d;"
        "$vid=@(Get-CimInstance Win32_VideoController|Select-Object -ExpandProperty Name);"
        "$cpu=Get-CimInstance Win32_Processor|Select-Object -First 1 -ExpandProperty Name;"
        "[Console]::Out.WriteLine(([pscustomobject]@{vid=$vid;cpu=$cpu}|ConvertTo-Json -Compress));[Console]::Out.Flush();"
        "while($true){"
        "if(-not (Get-Process -Id $pp -ErrorAction SilentlyContinue)){exit};"
        "$eng=@(Get-CimInstance Win32_PerfFormattedData_GPUPerformanceCounters_GPUEngine|Select-Object Name,UtilizationPercentage);"
        "$dsk=Get-CimInstance Win32_PerfFormattedData_PerfDisk_PhysicalDisk -Filter \"Name='_Total'\"|Select-Object -First 1;"
        "[Console]::Out.WriteLine(([pscustomobject]@{eng=$eng;disk=$dsk.PercentDiskTime}|ConvertTo-Json -Compress -Depth 4));[Console]::Out.Flush();"
        "Start-Sleep -Milliseconds 800}"
    )

    def _ps_stream(self):
        import base64
        failures = 0
        while failures < 4:
            started = time.time()
            try:
                script = self._PS_SCRIPT % os.getpid()
                encoded = base64.b64encode(script.encode('utf-16-le')).decode('ascii')
                proc = subprocess.Popen(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', encoded],
                                        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, stdin=subprocess.DEVNULL, creationflags=self._NO_WINDOW)
                self._ps_proc = proc
                for raw in proc.stdout:
                    line = raw.decode('utf-8', 'replace').strip()
                    if line.startswith('{'):
                        try:
                            self._apply_ps(json.loads(line))
                        except ValueError:
                            pass
                proc.wait(timeout=5)
            except Exception as exc:
                self._set_slow(error=str(exc)[:120])
            failures = failures + 1 if time.time() - started < 20 else 0
            time.sleep(3)
        self._set_slow(error='o coletor de GPU/disco do Windows (PowerShell) não iniciou')

    def _apply_ps(self, info):
        if 'vid' in info or 'cpu' in info:
            vids = info.get('vid') or []
            if isinstance(vids, str): vids = [vids]
            self._set_slow(gpu_names=[str(v) for v in vids if v], cpu_name=str(info.get('cpu') or '').strip())
            return
        update = {'ps_ok': True, 'error': ''}
        disk = info.get('disk')
        if isinstance(disk, (int, float)):
            update['disk_active'] = max(0.0, min(100.0, float(disk)))
        engines = info.get('eng') or []
        if isinstance(engines, dict): engines = [engines]
        groups = {}
        for e in engines:
            m = re.search(r'luid_(0x[0-9a-f]+_0x[0-9a-f]+)_phys_(\d+)_eng_(\d+)_engtype_(\w+)', str(e.get('Name', '')), re.I)
            if not m: continue
            try: value = float(e.get('UtilizationPercentage') or 0)
            except (TypeError, ValueError): continue
            key = (m.group(1), m.group(2), m.group(3), m.group(4))
            groups[key] = groups.get(key, 0.0) + value   # soma dos processos em cada engine, como o Gerenciador de Tarefas
        if groups:
            adapters = {}
            for (luid, _phys, _eng, kind), value in groups.items():
                best = adapters.get(luid)
                if not best or value > best[0]: adapters[luid] = (value, kind)
            ordered = sorted(adapters.items())
            top = max(v for v, _ in adapters.values())
            update['engine_gpu'] = max(0.0, min(100.0, top))
            update['engine_detail'] = ' | '.join(f'Adaptador {i+1}: {min(100.0, v):.0f}% ({k})' for i, (_l, (v, k)) in enumerate(ordered))
        else:
            update['engine_gpu'] = None
        self._set_slow(**update)

    # ---------------- Amostra completa ----------------
    def sample(self):
        self.refresh_slow()
        slow = self.slow()
        out = {'time': int(time.time()), 'details': {}, 'meta': {'drives': [], 'interfaces': [], 'gpus': []}}
        d = out['details']
        meta = out['meta']
        cpu = self.cpu()
        cpu_name = str(slow.get('cpu_name') or '').strip()
        if cpu is not None:
            out['CPU'] = round(cpu, 2)
            meta['cpu_name'] = cpu_name
            d['CPU'] = f'CPU: {cpu:.1f}% • {os.cpu_count() or "?"} processadores lógicos' + (' • ' + cpu_name if cpu_name else '')
        else:
            d['CPU'] = 'Calculando uso da CPU...'
        mem = self.memory()
        if mem:
            out['Memória'] = round(mem['percent'], 2)
            gb = 2 ** 30
            meta['memory'] = {'used': int(mem['used']), 'available': int(mem['available']), 'total': int(mem['total']), 'percent': float(mem['percent'])}
            d['Memória'] = f"Usada: {mem['used']/gb:.2f} GB • Disponível: {mem['available']/gb:.2f} GB • Total: {mem['total']/gb:.2f} GB"
        else:
            d['Memória'] = 'Leitura de memória indisponível neste computador.'
        drives = self.drives()
        for index, (name, percent, used, total) in enumerate(drives[:12]):
            label = f'Disco {index} ({name})'
            meta['drives'].append({'index': index, 'name': name, 'label': label, 'percent': float(percent), 'used': int(used), 'total': int(total)})
        occupancy = ', '.join(f"{drv['name']} {drv['percent']:.1f}% ({drv['used']/2**30:.0f}/{drv['total']/2**30:.0f} GB)" for drv in meta['drives'])
        if isinstance(slow.get('disk_active'), float):
            out['Discos'] = round(slow['disk_active'], 2)
            d['Discos'] = f"Atividade: {slow['disk_active']:.1f}%" + (' • Ocupação: ' + occupancy if occupancy else '')
        elif meta['drives'] and (slow.get('ps_ok') or slow.get('error') or os.name != 'nt'):
            out['Discos'] = round(sum(drv['percent'] for drv in meta['drives']) / len(meta['drives']), 2)
            d['Discos'] = 'Ocupação média (atividade do disco indisponível): ' + occupancy
        else:
            d['Discos'] = 'Aguardando leitura da atividade do disco...'
        net = self.network()
        if net:
            down = float(net.get('download_mbps') or 0.0)
            up = float(net.get('upload_mbps') or 0.0)
            out['Rede'] = round(down + up, 3)
            out['Rede_download'] = round(down, 3)
            out['Rede_upload'] = round(up, 3)
            meta['interfaces'] = list(net.get('interfaces') or [])
            d['Rede'] = f'Download {down:.2f} Mbps • Upload {up:.2f} Mbps (adaptadores ativos)'
        else:
            d['Rede'] = 'Calculando taxa de rede...'
        names = [str(n) for n in (slow.get('gpu_names') or []) if n]
        gpus = []
        nv_gpus = slow.get('nv_gpus') or []
        if nv_gpus:
            gpus.extend([dict(item) for item in nv_gpus])
        elif names:
            util = slow.get('engine_gpu') if isinstance(slow.get('engine_gpu'), float) else None
            for name in names:
                gpus.append({'name': name, 'utilization': util})
        meta['gpus'] = gpus
        if isinstance(slow.get('nv_gpu'), float):
            engine = slow.get('engine_gpu')
            value = max(slow['nv_gpu'], engine) if isinstance(engine, float) else slow['nv_gpu']
            out['GPU'] = round(value, 2)
            d['GPU'] = f"GPU: {value:.1f}% • {slow.get('nv_detail', '')}" + (' | ' + slow['engine_detail'] if slow.get('engine_detail') else '')
        elif isinstance(slow.get('engine_gpu'), float):
            out['GPU'] = round(slow['engine_gpu'], 2)
            d['GPU'] = f"GPU: {slow['engine_gpu']:.1f}% • {slow.get('engine_detail', '')}" + (f' • {", ".join(names)}' if names else '')
        elif slow.get('ps_ok'):
            d['GPU'] = 'Sem contador de uso de GPU neste Windows/driver' + (f' ({", ".join(names)})' if names else '') + ' - requer Windows 10 1709+ e driver WDDM 2.0+.'
        elif slow.get('error'):
            d['GPU'] = 'Uso de GPU indisponível: ' + slow['error']
        else:
            d['GPU'] = 'Aguardando leitura da GPU...'
        if not self._psutil:
            d['Aviso'] = 'Coleta nativa do Windows (psutil não instalado).'
        return out



class RemoteInput:
    """Mouse e teclado do Windows via SendInput, usados somente na assistência remota autorizada."""
    VK={'Enter':0x0D,'NumpadEnter':0x0D,'Escape':0x1B,'Backspace':0x08,'Tab':0x09,'Space':0x20,
        'ArrowLeft':0x25,'ArrowUp':0x26,'ArrowRight':0x27,'ArrowDown':0x28,'Home':0x24,'End':0x23,
        'PageUp':0x21,'PageDown':0x22,'Insert':0x2D,'Delete':0x2E,'ShiftLeft':0xA0,'ShiftRight':0xA1,
        'ControlLeft':0xA2,'ControlRight':0xA3,'AltLeft':0xA4,'AltRight':0xA5,'MetaLeft':0x5B,'MetaRight':0x5C,
        'ContextMenu':0x5D,'CapsLock':0x14,'NumLock':0x90,'ScrollLock':0x91,'Pause':0x13,'PrintScreen':0x2C,
        'Semicolon':0xBA,'Equal':0xBB,'Comma':0xBC,'Minus':0xBD,'Period':0xBE,'Slash':0xBF,'Backquote':0xC0,
        'BracketLeft':0xDB,'Backslash':0xDC,'BracketRight':0xDD,'Quote':0xDE,'IntlBackslash':0xE2,'IntlRo':0xC1,
        'NumpadComma':0xC2,'NumpadMultiply':0x6A,'NumpadAdd':0x6B,'NumpadSubtract':0x6D,'NumpadDecimal':0x6E,
        'NumpadDivide':0x6F}
    EXTENDED={0x25,0x26,0x27,0x28,0x24,0x23,0x21,0x22,0x2D,0x2E,0xA3,0xA5,0x5B,0x5C,0x5D,0x90,0x2C,0x6F}
    COMBOS={'win':[0x5B],'win_r':[0x5B,0x52],'win_d':[0x5B,0x44],'win_e':[0x5B,0x45],'alt_tab':[0xA4,0x09],
            'ctrl_shift_esc':[0xA2,0xA0,0x1B],'alt_f4':[0xA4,0x73],'ctrl_c':[0xA2,0x43],'ctrl_v':[0xA2,0x56],
            'ctrl_a':[0xA2,0x41],'ctrl_z':[0xA2,0x5A],'f5':[0x74],'esc':[0x1B]}

    @staticmethod
    def thread_dpi_aware():
        """Torna só a thread atual consciente de DPI (pixels reais), sem alterar a interface do aplicativo."""
        try:
            import ctypes
            fn=ctypes.windll.user32.SetThreadDpiAwarenessContext
            fn.argtypes=[ctypes.c_void_p]; fn.restype=ctypes.c_void_p
            fn(ctypes.c_void_p(-4))  # DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2
            return True
        except Exception:
            return False

    @staticmethod
    def cursor_pos():
        try:
            import ctypes
            from ctypes import wintypes
            pt=wintypes.POINT()
            if ctypes.windll.user32.GetCursorPos(ctypes.byref(pt)): return (int(pt.x),int(pt.y))
        except Exception:
            pass
        return None

    def __init__(self):
        import ctypes
        from ctypes import wintypes
        self.ct=ctypes
        self.user32=ctypes.windll.user32
        class KEYBDINPUT(ctypes.Structure):
            _fields_=[('wVk',wintypes.WORD),('wScan',wintypes.WORD),('dwFlags',wintypes.DWORD),('time',wintypes.DWORD),('dwExtraInfo',ctypes.c_size_t)]
        class MOUSEINPUT(ctypes.Structure):
            _fields_=[('dx',wintypes.LONG),('dy',wintypes.LONG),('mouseData',wintypes.DWORD),('dwFlags',wintypes.DWORD),('time',wintypes.DWORD),('dwExtraInfo',ctypes.c_size_t)]
        class HARDWAREINPUT(ctypes.Structure):
            _fields_=[('uMsg',wintypes.DWORD),('wParamL',wintypes.WORD),('wParamH',wintypes.WORD)]
        class _UNION(ctypes.Union):
            _fields_=[('mi',MOUSEINPUT),('ki',KEYBDINPUT),('hi',HARDWAREINPUT)]
        class INPUT(ctypes.Structure):
            _fields_=[('type',wintypes.DWORD),('u',_UNION)]
        self.KEYBDINPUT,self.MOUSEINPUT,self.INPUT=KEYBDINPUT,MOUSEINPUT,INPUT
        self.user32.SendInput.argtypes=[wintypes.UINT,ctypes.POINTER(INPUT),ctypes.c_int]
        self.user32.SendInput.restype=wintypes.UINT
        self.user32.SetCursorPos.argtypes=[ctypes.c_int,ctypes.c_int]
        self.user32.MapVirtualKeyW.argtypes=[wintypes.UINT,wintypes.UINT]
        self.user32.MapVirtualKeyW.restype=wintypes.UINT
        self.keys_down=set(); self.buttons_down=set()

    def _send(self,inp): self.user32.SendInput(1,self.ct.byref(inp),self.ct.sizeof(self.INPUT))

    def _mouse(self,flags,data=0):
        inp=self.INPUT(type=0); inp.u.mi=self.MOUSEINPUT(0,0,data&0xFFFFFFFF,flags,0,0); self._send(inp)

    def _key(self,vk,down,scan=0,flags=0):
        inp=self.INPUT(type=1); inp.u.ki=self.KEYBDINPUT(vk,scan,flags|(0 if down else 2),0,0); self._send(inp)

    def screen_size(self):
        return max(1,self.user32.GetSystemMetrics(0)),max(1,self.user32.GetSystemMetrics(1))

    def move(self,nx,ny):
        w,h=self.screen_size()
        self.user32.SetCursorPos(int(max(0.0,min(1.0,float(nx)))*(w-1)),int(max(0.0,min(1.0,float(ny)))*(h-1)))

    def move_in_rect(self,nx,ny,bounds=None):
        if bounds and len(bounds)==4:
            left,top,right,bottom=[int(v) for v in bounds]
            width=max(1,right-left); height=max(1,bottom-top)
            x=left+int(max(0.0,min(1.0,float(nx)))*(width-1))
            y=top+int(max(0.0,min(1.0,float(ny)))*(height-1))
            self.user32.SetCursorPos(x,y)
            return
        self.move(nx,ny)

    def button(self,b,down):
        b=b if b in (0,1,2) else 0
        down_flag,up_flag={0:(0x0002,0x0004),1:(0x0020,0x0040),2:(0x0008,0x0010)}[b]
        if down: self.buttons_down.add(b)
        else: self.buttons_down.discard(b)
        self._mouse(down_flag if down else up_flag)

    def wheel(self,dx,dy):
        dy=int(dy); dx=int(dx)
        if dy: self._mouse(0x0800,-dy*120)
        if dx: self._mouse(0x1000,dx*120)

    def vk_for(self,code):
        if code in self.VK: return self.VK[code]
        if len(code)==4 and code.startswith('Key') and code[3].isalpha(): return ord(code[3].upper())
        if len(code)==6 and code.startswith('Digit') and code[5].isdigit(): return ord(code[5])
        if code.startswith('Numpad') and len(code)==7 and code[6].isdigit(): return 0x60+int(code[6])
        if code.startswith('F') and code[1:].isdigit() and 1<=int(code[1:])<=24: return 0x70+int(code[1:])-1
        return None

    def key(self,code,down):
        vk=self.vk_for(code)
        if vk is None: return
        self.vk_event(vk,down)

    def vk_event(self,vk,down):
        scan=self.user32.MapVirtualKeyW(vk,0)
        self._key(vk,down,scan,0x0001 if vk in self.EXTENDED else 0)
        if down: self.keys_down.add(vk)
        else: self.keys_down.discard(vk)

    def combo(self,name):
        seq=self.COMBOS.get(name)
        if not seq: return
        for vk in seq: self.vk_event(vk,True)
        for vk in reversed(seq): self.vk_event(vk,False)

    def text(self,value):
        for ch in str(value)[:400]:
            if ch=='\r': continue
            if ch=='\n': self.vk_event(0x0D,True); self.vk_event(0x0D,False); continue
            if ch=='\t': self.vk_event(0x09,True); self.vk_event(0x09,False); continue
            raw=ch.encode('utf-16-le')
            for i in range(0,len(raw),2):
                unit=raw[i]|(raw[i+1]<<8)
                self._key(0,True,unit,0x0004); self._key(0,False,unit,0x0004)

    def has_pressed(self): return bool(self.keys_down or self.buttons_down)

    def release_all(self):
        for vk in list(self.keys_down):
            try: self.vk_event(vk,False)
            except Exception: pass
        for b in list(self.buttons_down):
            try: self.button(b,False)
            except Exception: pass

    def apply(self,ev,bounds=None):
        t=ev.get('t')
        if t=='m': self.move_in_rect(ev.get('x',0),ev.get('y',0),bounds)
        elif t in ('d','u'):
            self.move_in_rect(ev.get('x',0),ev.get('y',0),bounds); self.button(int(ev.get('b',0)),t=='d')
        elif t=='w': self.wheel(ev.get('dx',0),ev.get('dy',0))
        elif t=='k': self.key(str(ev.get('c','')),bool(ev.get('d')))
        elif t=='x': self.text(ev.get('s',''))
        elif t=='c': self.combo(str(ev.get('n','')))


class ArcollApp:
 def __init__(self):
  self.root=tk.Tk(); self.root.title(APP_NAME); self.root.minsize(720,520); bind_theme(self.root, background='bg').configure(bg=theme_color('bg'))
  width,height=1360,790
  sx,sy=self.root.winfo_screenwidth(),self.root.winfo_screenheight()
  width=min(width,max(720,sx-48));height=min(height,max(520,sy-96))
  self.root.geometry(f'{width}x{height}+{max(0,(sx-width)//2)}+{max(0,(sy-height)//2)}')
  self.stop_event=threading.Event(); self.samples=[]; self.devices=[]; self.alerts=[]; self.running=True; self.monitor_on=True; self.agent_proc=None; self.last_status='Verificando autorização'; self._auth_status='checking'; self._auth_error=''; self._command_poll_busy=False; self._last_command_error=''; self.last_sync='Ainda não sincronizado'; self.latency='--'; self.loss='0%'; self.last_error=''; self.scan_running=False; self.speed_busy=False; self.session_started=time.time();self.last_alert_sent=0; self.monitor_interval=5; self.alert_threshold=150; self._init_history_db(); self._load_history()
  self._style(); self._modern_header(); self._enable_custom_chrome(); self._tabs(); self._footer(); self._animate_ui(); self._fade_in(); self._animate_header(); self._tick_cards()
  self.allowed_remote_tabs=None;self.latest_release=None
  try:
   if (ROOT/'favicon.ico').is_file(): self.root.iconbitmap(default=str(ROOT/'favicon.ico'))
  except Exception: pass
  self.root.bind('<Control-q>',lambda _event:self.hide_window())
  self.root.protocol('WM_DELETE_WINDOW',self.hide_window); self.tray=None; self.tray_ready=False; self._focus_server=None; self._start_focus_server(); self._start_tray();self.root.after(900,self.hide_window) if '--background' in sys.argv else None
  self._install_tooltips()
  self._presence_worker=threading.Thread(target=self._presence_loop,daemon=True)
  self._presence_worker.start()
  self.root.after(400,self.ensure_local_agent)
  # Apenas monitoramento e comunicação de controle são periódicos.
  # Varredura, velocidade e diagnóstico ocorrem EXCLUSIVAMENTE por solicitação.
  self.root.after(1000,self.refresh_status)
  self.root.after(1500,self.monitor_tick)
  self.root.after(3000,self._poll_loop)
  self.root.after(20000,self.periodic_remote_snapshot)
  self._realtime=None
  self._start_realtime()
  self.root.after(1500,self._server_tick)
  self.root.after(15000,self._confirm_installed_update)
  self.root.after(2500,self._show_update_news)
  self.root.after(1200,self._refresh_platform_config)
  self.appearance=AppearanceManager(self.root,AGENT_VERSION,restyle=self._style)
  self.root.mainloop()
 def _show_update_news(self):
  """Apresenta uma única vez as notas verificadas da versão instalada."""
  marker=DATA_DIR/'update-release-notes.json'
  try:
   if not marker.is_file():return
   payload=json.loads(marker.read_text(encoding='utf-8'))
   marker.unlink(missing_ok=True)
   notes=str(payload.get('notes') or '').strip()[:3000]
   version=str(payload.get('version') or '')[:32]
   if not notes:return
   dlg=tk.Toplevel(self.root)
   dlg.title('Arcoll • Novidades da atualização')
   dlg.configure(bg=theme_color('bg'))
   dlg.resizable(False,False)
   w,h=560,430
   dlg.geometry(f'{w}x{h}+{max(0,(dlg.winfo_screenwidth()-w)//2)}+{max(0,(dlg.winfo_screenheight()-h)//2)}')
   tk.Label(dlg,text='Atualização concluída',font=('Segoe UI',17,'bold'),bg=theme_color('bg'),fg=theme_color('text')).pack(padx=20,pady=(22,6))
   tk.Label(dlg,text='Versão '+version,font=('Segoe UI',10),bg=theme_color('bg'),fg=theme_color('muted')).pack(pady=(0,12))
   text=tk.Text(dlg,wrap='word',font=('Segoe UI',11),relief='flat',bg=theme_color('alt'),fg=theme_color('text'),padx=15,pady=15)
   text.insert('1.0',notes);text.config(state='disabled');text.pack(fill='both',expand=True,padx=20,pady=(0,15))
   tk.Button(dlg,text='Entendi',command=dlg.destroy,padx=22,pady=7).pack(pady=(0,18))
   dlg.lift();dlg.focus_force()
  except (OSError,ValueError,TypeError,tk.TclError) as exc:
   self.log('Não foi possível exibir as notas da versão: '+str(exc))

 def _confirm_installed_update(self):
  marker=DATA_DIR/'update-awaiting-confirmation.json'
  try:
   if not marker.is_file():return
   row=json.loads(marker.read_text(encoding='utf-8'))
   ident=int(row.get('command_id') or 0)
   if ident<=0:return
   if row.get('version') and row['version']!=AGENT_VERSION:return
   if not (ORIGIN and SITE_CODE and DOWNLOAD_KEY):return
   def confirm():
    for attempt in range(5):
     try:
      result=request_json(ORIGIN+'/api/api.php?action=agent-command-ack',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'command_id':ident,'status':'completed','result':'Versão '+AGENT_VERSION+' instalada e agente reiniciado'},timeout=12)
      if isinstance(result,dict) and result.get('ok') and result.get('acknowledged'):
       marker.unlink(missing_ok=True);return
     except Exception:pass
     time.sleep(6)
   threading.Thread(target=confirm,daemon=True).start()
  except (OSError,ValueError,TypeError):pass

 def _presence_loop(self):
  # Canal de presença separado da telemetria e da interface, sem acumular registros.
  if not (ORIGIN and SITE_CODE and DOWNLOAD_KEY):return
  body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'state':'online','version':AGENT_VERSION}
  while not self.stop_event.is_set():
   try:
    request_json(ORIGIN+'/api/api.php?action=agent-presence',body,timeout=3)
   except Exception:pass
   self.stop_event.wait(3)

 def _presence_goodbye(self):
  if not (ORIGIN and SITE_CODE and DOWNLOAD_KEY):return
  try:
   request_json(ORIGIN+'/api/api.php?action=agent-presence',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'state':'offline'},timeout=2)
  except Exception:pass

 def _init_history_db(self):
  """Inicializa o histórico local sem remover dados de versões anteriores."""
  with history_db() as db:
   db.execute('PRAGMA busy_timeout=15000')
   db.execute('CREATE TABLE IF NOT EXISTS samples (id INTEGER PRIMARY KEY AUTOINCREMENT, captured REAL NOT NULL, latency REAL, host TEXT)')
   db.execute('CREATE TABLE IF NOT EXISTS speedtests (id INTEGER PRIMARY KEY AUTOINCREMENT, captured REAL NOT NULL, download REAL, upload REAL, latency REAL, server TEXT)')
   existing={row[1] for row in db.execute('PRAGMA table_info(samples)')}
   for field in ('download_mbps','upload_mbps'):
    if field not in existing:db.execute('ALTER TABLE samples ADD COLUMN '+field+' REAL')
   db.execute('CREATE TABLE IF NOT EXISTS topology_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER)')
   db.execute('CREATE TABLE IF NOT EXISTS inventory (device_id TEXT PRIMARY KEY, payload TEXT, first_seen REAL, last_seen REAL, last_change REAL, online INTEGER DEFAULT 0, custom_name TEXT DEFAULT "", custom_type TEXT DEFAULT "", group_id INTEGER, hidden INTEGER DEFAULT 0, pinned INTEGER DEFAULT 0)')
   db.execute('CREATE INDEX IF NOT EXISTS idx_samples_captured ON samples(captured)')
   db.execute('CREATE TABLE IF NOT EXISTS map_positions (mode TEXT NOT NULL, node_id TEXT NOT NULL, x REAL NOT NULL, y REAL NOT NULL, PRIMARY KEY(mode,node_id))')

 def _load_history(self):
  """Reconstitui as amostras no gráfico sem exigir uma aba legada de histórico."""
  try:
   with history_db() as db:
    rows=db.execute('SELECT captured,latency,host,download_mbps,upload_mbps FROM samples ORDER BY id DESC LIMIT 2000').fetchall()
   self.samples=[{'time':float(t),'latency':(float(v) if v is not None else None),'host':str(host or ''),'download_mbps':(float(d) if d is not None else None),'upload_mbps':(float(u) if u is not None else None)} for t,v,host,d,u in reversed(rows)]
  except (sqlite3.Error,ValueError,TypeError):
   self.samples=[]

 def _save_sample(self,sample):
  """Armazena uma única medição, respeitando a ausência de resposta ao ping."""
  try:
   with history_db() as db:
    db.execute('INSERT INTO samples(captured,latency,host,download_mbps,upload_mbps) VALUES(?,?,?,?,?)',(float(sample.get('time',time.time())),sample.get('latency'),str(sample.get('host') or ''),sample.get('download_mbps'),sample.get('upload_mbps')))
  except sqlite3.Error as exc:
   self.last_error='Histórico local: '+str(exc)

 def _tree(self,parent,cols):
  """Cria Treeview com os identificadores de coluna registrados antes dos cabeçalhos."""
  frame=bind_theme(tk.Frame(parent, bg=theme_color('bg')), background='bg')
  frame.pack(fill='both',expand=True,padx=12,pady=(2,8))
  names=tuple(cols.keys())
  tree=ttk.Treeview(frame,columns=names,show='headings',selectmode='browse')
  for name,width in cols.items():
   tree.heading(name,text=name)
   tree.column(name,width=int(width),minwidth=45,anchor='w',stretch=True)
  scroll=ttk.Scrollbar(frame,orient='vertical',command=tree.yview)
  tree.configure(yscrollcommand=scroll.set)
  scroll.pack(side='right',fill='y')
  tree.pack(side='left',fill='both',expand=True)
  return tree

 def _start_tray(self):
  if platform.system()!='Windows':return
  try:
   import pystray
   from PIL import Image, ImageDraw
   icon_path=ROOT/'favicon.ico'
   if icon_path.is_file(): im=Image.open(icon_path).convert('RGBA')
   else:
    im=Image.new('RGBA',(64,64),(12,26,44,255)); draw=ImageDraw.Draw(im); draw.ellipse((7,7,57,57),outline=(34,211,238,255),width=5); draw.text((23,21),'A',fill='white')
   menu=pystray.Menu(pystray.MenuItem('Abrir Arcoll Speed Connection',lambda icon,item:self.root.after(0,self.show_window),default=True),pystray.MenuItem('Ocultar janela',lambda icon,item:self.root.after(0,self.hide_window)),pystray.Menu.SEPARATOR,pystray.MenuItem('Fechar agente (autenticação do gestor)',lambda icon,item:self.root.after(0,self._request_authenticated_exit)))
   self.tray=pystray.Icon('ArcollSpeedConnection',im,APP_NAME+' · monitoramento ativo',menu)
   threading.Thread(target=self.tray.run,daemon=True).start(); self.tray_ready=True
  except Exception as exc:
   self.tray_ready=False; self.last_error='Ícone da bandeja indisponível: '+str(exc)
 def hide_window(self):
  if self.tray_ready:self.root.withdraw()
  else:self.root.deiconify(); self.last_error='Bandeja indisponível: instale pystray e Pillow para ocultar a janela.'
 def show_window(self):
  self.root.deiconify(); self.root.lift(); self.root.focus_force()
 def _start_focus_server(self):
  try:
   server=socket.socket(socket.AF_INET,socket.SOCK_STREAM)
   server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
   server.bind((INSTANCE_HOST, INSTANCE_PORT))
   server.listen(2)
   server.settimeout(1.0)
   self._focus_server=server
  except OSError:
   self._focus_server=None
   return
  def loop():
   while not self.stop_event.is_set():
    try: conn,_addr=server.accept()
    except socket.timeout: continue
    except OSError: break
    try: conn.recv(32)
    except OSError: pass
    try: conn.close()
    except OSError: pass
    self.root.after(0,self._focus_existing_window)
  threading.Thread(target=loop,daemon=True).start()

 def _focus_existing_window(self):
  try:
   self.show_window()
   self.root.attributes('-topmost', True)
   self.root.after(220, lambda: self.root.attributes('-topmost', False))
  except tk.TclError:
   pass

 def _style(self):
  st=ttk.Style(); st.theme_use('clam')
  st.configure('TNotebook', background=theme_color('bg'), borderwidth=0, tabmargins=(0, 2, 0, 0))
  st.configure('TNotebook.Tab', background=theme_color('alt'), foreground=theme_color('muted'), padding=(10, 7), font=('Segoe UI', 9, 'bold'), borderwidth=0)
  st.map('TNotebook.Tab', background=[('selected', theme_color('accent')), ('active', theme_color('alt'))], foreground=[('selected', theme_color('onaccent')), ('active', theme_color('text'))])
  st.configure('Treeview', background=theme_color('surface'), foreground=theme_color('text'), fieldbackground=theme_color('surface'), rowheight=28, borderwidth=0, font=('Segoe UI', 9))
  st.configure('Treeview.Heading', background=theme_color('alt'), foreground=theme_color('text'), font=('Segoe UI', 9, 'bold'), padding=7)
  st.map('Treeview', background=[('selected', theme_color('accent'))], foreground=[('selected', theme_color('onaccent'))])
  st.configure('TProgressbar', background=theme_color('accent'), troughcolor=theme_color('alt'), borderwidth=0)
  st.configure('Vertical.TScrollbar', background=theme_color('alt'), troughcolor=theme_color('bg'))
  st.configure('Arcoll.TCombobox', fieldbackground=theme_color('alt'), background=theme_color('alt'), foreground=theme_color('text'), arrowcolor=theme_color('accent'), padding=5)
  st.map('Arcoll.TCombobox', fieldbackground=[('readonly', theme_color('alt'))], foreground=[('readonly', theme_color('text'))])
 def label(self,parent,text,size=10,color=theme_color('text'),bold=False): return tk.Label(parent, text=text, font=('Segoe UI', size, 'bold' if bold else 'normal'), bg=parent.cget('bg'), fg=color, anchor='w')
 def btn(self,parent,text,command,primary=False):
  b=bind_theme(tk.Button(parent, text=text, command=command, font=('Segoe UI', 9, 'bold'), bg=theme_color('accent') if primary else theme_color('alt'), fg=theme_color('onaccent') if primary else theme_color('text'), activebackground=theme_color('accent'), activeforeground=theme_color('text'), relief='flat', padx=12, pady=7, cursor='hand2'), activebackground='accent', activeforeground='text', background='accent' if primary else 'alt', foreground='onaccent' if primary else 'text')
  self._hover_fx(b, theme_color('accent') if primary else theme_color('alt'), theme_color('accent') if primary else theme_color('accent')); return b
 def _tooltip_text(self, widget):
  explicit=getattr(widget,'arc_tooltip',None)
  if explicit:return str(explicit)
  kind=widget.winfo_class(); text=''
  for attr in ('text','title'):
   try:
    value=widget.cget(attr)
    if value:
     text=str(value).strip(); break
   except Exception:
    pass
  mapping={
   'Atualizar':'Reinstala os arquivos publicados do agente, mesmo sem mudança de versão.',
   '—':'Minimiza a janela do agente para a barra de tarefas.',
   '□':'Alterna entre o tamanho normal e o modo maximizado da janela.',
   'Relatório':'Gera um relatório resumido com os dados exibidos nesta tela.',
   'Cadastrar este computador':'Envia este equipamento para aprovação e vínculo com a rede cadastrada.',
   'Escuro':'Exibe o mapa com tema escuro.',
   'Ruas':'Exibe o mapa no modo de ruas.',
   'Satélite':'Exibe o mapa no modo satélite.'
  }
  if text in mapping:return mapping[text]
  if kind in ('Entry','TEntry'): return 'Campo do sistema. Informe ou consulte os dados exibidos nesta área.'
  if kind == 'TCombobox': return 'Selecione uma opção nesta lista.'
  if kind == 'Button' and text: return 'Clique para executar: '+text+'.'
  if kind == 'Treeview': return 'Lista de informações monitoradas. Clique em um item para ver mais detalhes.'
  if kind == 'Canvas' and widget is getattr(self,'hdr',None): return 'Cabeçalho do agente. Arraste para mover a janela.'
  return ''

 def _install_tooltips(self):
  self._hover_tips=HoverTip(self.root)
  def walk(widget):
   try: children=widget.winfo_children()
   except Exception: children=[]
   tip=self._tooltip_text(widget)
   if tip:self._hover_tips.attach(widget, tip)
   for child in children: walk(child)
  walk(self.root)

 def card(self,parent,title,value,sub=''):
  f=bind_theme(tk.Frame(parent, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1, height=108), background='surface', highlightbackground='border'); bind_theme(tk.Label(f, text=title, bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='surface', foreground='muted').pack(anchor='w', padx=13, pady=(11, 2)); v=bind_theme(tk.Label(f, text=value, bg=theme_color('surface'), fg=theme_color('accent'), font=('Segoe UI', 22, 'bold')), background='surface', foreground='accent'); v.pack(anchor='w', padx=13); bind_theme(tk.Label(f, text=sub, bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 8)), background='surface', foreground='muted').pack(anchor='w', padx=13, pady=(0, 11)); f.pack_propagate(False); return f,v
 def _tabs(self):
  """Sete telas com a aba Servidor em primeiro plano."""
  self.nb=ttk.Notebook(self.root)
  self.nb.pack(fill='both',expand=True,padx=12,pady=(8,7))
  self.server_page=self._page('Servidor')
  self.dashboard=self._page('Monitoramento')
  self.speed_page=self._page('Velocidade')
  self.devices_page=self._page('Dispositivos')
  self.map_page=self._page('Mapa')
  self.connection_page=self._page('Conexão')
  self.command_page=self._page('Comandos')
  self._setup_settings_button()
  self._apply_tab_visibility()
  self.quick_busy=False
  self._build_server()
  self._build_dashboard()
  self._build_speed()
  self._build_devices()
  self._build_map()
  self._build_connection()
  self._build_command_center()
  self.nb.hide(self.dashboard)
  self.nb.hide(self.command_page)
  self.nb.select(self.server_page)
 def _settings_file(self):
  return DATA_DIR/'desktop-settings.json'
 def _read_settings(self):
  try:
   data=json.loads(self._settings_file().read_text(encoding='utf-8'))
   return data if isinstance(data,dict) else {}
  except (ValueError,OSError):return {}
 def _setup_settings_button(self):
  # The gear is overlaid at the right edge of the notebook; it is not a tab.
  self._settings_gear=bind_theme(tk.Button(self.root, text='⚙', font=('Segoe UI Symbol', 15), bg=theme_color('alt'), fg=theme_color('text'), activebackground=theme_color('accent'), activeforeground=theme_color('text'), relief='flat', bd=0, width=3, cursor='hand2', command=self._open_agent_settings), background='alt', foreground='text', activebackground='accent', activeforeground='text')
  def place_gear(_event=None):
   try:
    self._settings_gear.place(in_=self.nb,relx=1,x=-5,y=4,anchor='ne',height=33)
    self._settings_gear.lift()
   except tk.TclError:pass
  self.nb.bind('<Configure>',place_gear,add='+')
  self.root.after(250,place_gear)
 def _apply_tab_visibility(self):
  data=self._read_settings().get('visible_tabs',{})
  if not isinstance(data,dict):data={}
  for label,page in self._configurable_tabs():
   if not data.get(label,True):
    try:self.nb.hide(page)
    except tk.TclError:pass
 def _configurable_tabs(self):
  return [('Servidor',self.server_page),
          ('Velocidade',self.speed_page),('Dispositivos',self.devices_page),
          ('Mapa',self.map_page),('Conexão',self.connection_page)]
 def _center_dialog(self, dlg, width, height):
  # Centraliza no monitor que contém a janela principal, mesmo que esteja oculta.
  self.root.update_idletasks()
  sx,sy=self.root.winfo_screenwidth(),self.root.winfo_screenheight()
  x=max(0,(sx-width)//2);y=max(0,(sy-height)//2)
  dlg.geometry(f'{width}x{height}+{x}+{y}')
  dlg.lift()
  dlg.after(50,lambda:dlg.focus_force() if dlg.winfo_exists() else None)
 def _request_authenticated_exit(self):
  self._show_manager_auth('Fechar agente',self.close)
 def _open_agent_settings(self):
  self._show_manager_auth('Configurações do agente',self._show_settings_panel)
 def _show_manager_auth(self,title,on_success):
  existing=getattr(self,'_manager_auth_dialog',None)
  if existing is not None and existing.winfo_exists():
   existing.lift();existing.focus_force();return
  dlg=tk.Toplevel(self.root);self._manager_auth_dialog=dlg
  dlg.title(title);bind_theme(dlg, background='bg').configure(bg=theme_color('bg'))
  dlg.resizable(False,False);self._center_dialog(dlg,440,280)
  bind_theme(tk.Label(dlg,text=title.upper(),bg=theme_color('bg'),fg=theme_color('text'),font=('Segoe UI',12,'bold')),background='bg',foreground='text').pack(pady=(19,10))
  bind_theme(tk.Label(dlg,text='Login (e-mail) do gestor da rede vinculada',bg=theme_color('bg'),fg=theme_color('muted')),background='bg',foreground='muted').pack()
  email=tk.Entry(dlg,width=40,font=('Segoe UI',10));email.pack(pady=5)
  bind_theme(tk.Label(dlg,text='Senha',bg=theme_color('bg'),fg=theme_color('muted')),background='bg',foreground='muted').pack()
  password=tk.Entry(dlg,width=40,show='•',font=('Segoe UI',10));password.pack(pady=5)
  status=bind_theme(tk.Label(dlg,text='',bg=theme_color('bg'),fg=theme_color('danger'),font=('Segoe UI',9),wraplength=400),background='bg',foreground='danger');status.pack()
  def authenticate():
   user=email.get().strip();secret=password.get()
   if not user or not secret:status.config(text='Informe o e-mail e a senha.');return
   bind_theme(status,foreground='muted').config(text='Validando credenciais...',fg=theme_color('muted'))
   login.config(state='disabled')
   def work():
    try:
     result=request_json(ORIGIN+'/api/api.php?action=agent-settings-auth',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'email':user,'password':secret},timeout=15)
     error='' if result.get('ok') else str(result.get('error') or 'Gestor não autorizado')
    except urllib.error.HTTPError as exc:
     try:error=str(json.loads(exc.read().decode('utf-8','replace')).get('error') or f'Erro HTTP {exc.code}')
     except Exception:error=f'Erro HTTP {exc.code} ao autenticar'
    except Exception as exc:error='Não foi possível autenticar: '+str(exc)[:110]
    def finished():
     if not dlg.winfo_exists():return
     password.delete(0,'end');login.config(state='normal')
     if error:bind_theme(status,foreground='danger').config(text=error,fg=theme_color('danger'))
     else:
      dlg.destroy();self._manager_auth_dialog=None;on_success()
    try:self.root.after(0,finished)
    except (RuntimeError,tk.TclError):pass
   threading.Thread(target=work,daemon=True).start()
  login=self.btn(dlg,'Entrar',authenticate,True);login.pack(pady=8)
  dlg.bind('<Return>',lambda _e:authenticate() if login['state']=='normal' else None)
  dlg.bind('<Escape>',lambda _e:dlg.destroy())
  email.focus_set()
 def _show_settings_panel(self):
  dlg=tk.Toplevel(self.root);dlg.title('Configurações do agente');bind_theme(dlg, background='bg').configure(bg=theme_color('bg'))
  dlg.resizable(False,False);self._center_dialog(dlg,465,490)
  bind_theme(tk.Label(dlg, text='ABAS VISÍVEIS NO AGENTE', bg=theme_color('bg'), fg=theme_color('text'), font=('Segoe UI', 12, 'bold')), background='bg', foreground='text').pack(anchor='w', padx=23, pady=(20, 10))
  saved=self._read_settings().get('visible_tabs',{})
  if not isinstance(saved,dict):saved={}
  variables={}
  for label,_ in self._configurable_tabs():
   var=tk.BooleanVar(value=saved.get(label,True));variables[label]=var
   bind_theme(tk.Checkbutton(dlg, text=label, variable=var, bg=theme_color('bg'), fg=theme_color('text'), selectcolor=theme_color('alt'), activebackground=theme_color('bg'), activeforeground=theme_color('text'), font=('Segoe UI', 10)), background='bg', foreground='text', activebackground='bg', activeforeground='text').pack(anchor='w', padx=24, pady=3)
  def save():
   if not any(var.get() for var in variables.values()):
    messagebox.showwarning('Configurações','Deixe pelo menos uma aba visível.',parent=dlg);return
   new=self._read_settings();new['visible_tabs']={k:v.get() for k,v in variables.items()}
   self._settings_file().parent.mkdir(parents=True,exist_ok=True)
   tmp=self._settings_file().with_suffix('.tmp')
   tmp.write_text(json.dumps(new,ensure_ascii=False,indent=2),encoding='utf-8')
   tmp.replace(self._settings_file())
   for label,page in self._configurable_tabs():
    try:
     self.nb.add(page) if variables[label].get() else self.nb.hide(page)
    except tk.TclError:pass
   dlg.destroy()
  self.btn(dlg,'Salvar abas',save,True).pack(fill='x',padx=22,pady=(14,10))
  bind_theme(tk.Label(dlg, text='ACESSO REMOTO ADMINISTRATIVO', bg=theme_color('bg'), fg=theme_color('text'), font=('Segoe UI', 10, 'bold')), background='bg', foreground='text').pack(anchor='w', padx=23, pady=(8, 4))
  bind_theme(tk.Label(dlg, text='O acesso sem confirmação local exige cadastro de servidor\nsem monitor e autenticação do gestor na plataforma.', bg=theme_color('bg'), fg=theme_color('muted'), justify='left', font=('Segoe UI', 9)), background='bg', foreground='muted').pack(anchor='w', padx=23)
  self.btn(dlg,'Gerenciar autorização na plataforma',
   lambda:webbrowser.open(ORIGIN+'/pages/painel.php') if ORIGIN else None).pack(fill='x',padx=22,pady=10)
 def _page(self,title):
  f=bind_theme(tk.Frame(self.nb, bg=theme_color('bg')), background='bg');self.nb.add(f, text=title);return f
 def _footer(self):
  f=bind_theme(tk.Frame(self.root, bg=theme_color('alt'), height=33, highlightbackground=theme_color('border'), highlightthickness=1), background='alt', highlightbackground='border')
  f.pack(fill='x',side='bottom');f.pack_propagate(False)
  self.status_label=bind_theme(tk.Label(f, text='Execução local - aguardando operação', font=('Segoe UI', 9, 'bold'), fg=theme_color('muted'), bg=theme_color('alt'), anchor='w'), foreground='muted', background='alt')
  self.status_label.pack(side='left',padx=(14,8),fill='x',expand=True)
  self.sync_label=bind_theme(tk.Label(f, text=self.last_sync, bg=theme_color('alt'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='alt', foreground='muted')
  self.sync_label.pack(side='right',padx=(4,12))
  self.uninstall_footer=bind_theme(tk.Button(f, text='Atualizar', font=('Segoe UI', 8), bg=theme_color('alt'), fg=theme_color('muted'), activebackground=theme_color('alt'), activeforeground=theme_color('text'), relief='flat', bd=0, cursor='hand2', command=self.update_agent), background='alt', foreground='muted', activebackground='alt', activeforeground='text')
  # O botão Atualizar fica no cabeçalho, abaixo da versão.
  self.operation_progress=ttk.Progressbar(f,mode='determinate',maximum=100,length=145)
  self.operation_progress.pack(side='right',padx=(6,10))
  self.operation_progress['value']=0
  self.operation_progress.pack_forget()

 def _progress_operation(self,value=None,message=None,finished=False):
  """Barra de etapas da operação; não simula porcentagem de hosts descobertos."""
  def update():
   try:
    if finished:
     if message:self.status_label.configure(text=message)
     self.operation_progress.stop()
     self.operation_progress.pack_forget()
    else:
     if not self.operation_progress.winfo_manager():self.operation_progress.pack(side='right',padx=(6,10),before=self.sync_label)
     if value is None:
      self.operation_progress.configure(mode='indeterminate');self.operation_progress.start(12)
     else:
      self.operation_progress.stop();self.operation_progress.configure(mode='determinate');self.operation_progress['value']=min(100,max(0,value))
     if message:self.status_label.configure(text=(f'{min(100,max(0,value)):.0f}% · ' if value is not None else '')+message)
   except (tk.TclError,AttributeError):pass
  self.root.after(0,update)
 def _build_dashboard(self):
  p=self.dashboard
  bar=bind_theme(tk.Frame(p, bg=theme_color('bg')), background='bg');bar.pack(fill='x', padx=12, pady=(12, 9))
  self.metric_vars={}
  self.live_badge=bind_theme(tk.Label(bar, text='● MONITORAMENTO CONTÍNUO', bg=theme_color('alt'), fg=theme_color('success'), font=('Segoe UI', 9, 'bold'), padx=12, pady=12), background='alt', foreground='success')
  self.live_badge.pack(side='left',padx=(0,8))
  for title,key in [('Latência','latency'),('Perda','loss'),('Dispositivos','devices'),('Estado','uptime')]:
   cell=bind_theme(tk.Frame(bar, bg=theme_color('surface'), highlightbackground=theme_color('accent'), highlightthickness=1), background='surface', highlightbackground='accent')
   cell.pack(side='left',fill='both',expand=True,padx=3)
   bind_theme(tk.Label(cell, text=title.upper(), bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 8, 'bold')), background='surface', foreground='muted').pack(anchor='w', padx=13, pady=(8, 1))
   value=bind_theme(tk.Label(cell, text='--', bg=theme_color('surface'), fg=theme_color('text'), font=('Segoe UI', 16, 'bold')), background='surface', foreground='text')
   value.pack(anchor='w',padx=13,pady=(0,9));self.metric_vars[key]=value
  box=bind_theme(tk.Frame(p, bg=theme_color('surface'), highlightbackground=theme_color('accent'), highlightthickness=1), background='surface', highlightbackground='accent')
  box.pack(fill='both',expand=True,padx=13,pady=(0,11))
  caption=bind_theme(tk.Frame(box, bg=theme_color('surface')), background='surface');caption.pack(fill='x', padx=15, pady=(14, 5))
  bind_theme(tk.Label(caption, text='MONITORAMENTO · LATÊNCIA / DOWNLOAD / UPLOAD', bg=theme_color('surface'), fg=theme_color('accent'), font=('Segoe UI', 10, 'bold')), background='surface', foreground='accent').pack(side='left')
  bind_theme(tk.Label(caption, text='Amostras do computador com agente', bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='surface', foreground='muted').pack(side='right')
  controls=bind_theme(tk.Frame(box, bg=theme_color('surface')), background='surface');controls.pack(fill='x', padx=13, pady=(2, 3))
  self.trade_style=tk.StringVar(value='Linhas')
  ttk.Combobox(controls,textvariable=self.trade_style,values=['Linhas','Área','Barras'],state='readonly',width=11).pack(side='left',padx=(0,8))
  self.trade_flags={}
  for name,col,display in [('latency',theme_color('accent'),'Latência'),('download_mbps',theme_color('success'),'Download'),('upload_mbps',theme_color('accent'),'Upload'),('loss',theme_color('danger'),'Perdas')]:
   v=tk.BooleanVar(value=True);self.trade_flags[name]=v
   bind_theme(tk.Checkbutton(controls, text=display, variable=v, command=self.draw_chart, selectcolor=theme_color('alt'), bg=theme_color('surface'), fg=col, activebackground=theme_color('surface'), activeforeground=col, font=('Segoe UI', 9)), background='surface', activebackground='surface').pack(side='left', padx=(0, 8))
  bind_theme(tk.Button(controls, text='Relatório', command=self._trade_report, bg=theme_color('alt'), fg=theme_color('text'), relief='flat', padx=10), background='alt', foreground='text').pack(side='right')
  self.chart=bind_theme(tk.Canvas(box, bg=theme_color('surface'), highlightthickness=0), background='surface')
  self.chart.pack(fill='both',expand=True,padx=9,pady=(2,12))
  self.chart.bind('<Configure>',lambda e:self.draw_chart());self.chart.bind('<Motion>',self._chart_hover);self.chart.bind('<Button-1>',self._chart_click);self.chart.bind('<Leave>',lambda e:self.draw_chart())
  self.dashboard_log=bind_theme(tk.Text(p, height=1, bg=theme_color('surface'), fg=theme_color('text'), state='disabled'), background='surface', foreground='text')
 def _build_speed(self):
  p=self.speed_page
  center=bind_theme(tk.Frame(p, bg=theme_color('bg')), background='bg');center.pack(fill='both', expand=True, padx=13, pady=(15, 6))
  bind_theme(tk.Label(center, text='TESTE DE VELOCIDADE', bg=theme_color('bg'), fg=theme_color('muted'), font=('Segoe UI', 10, 'bold')), background='bg', foreground='muted').pack(pady=(7, 5))
  self.speed_gauge=bind_theme(tk.Canvas(center, width=345, height=268, bg=theme_color('bg'), highlightthickness=0, cursor='hand2'), background='bg')
  self.speed_gauge.pack(expand=True,pady=(4,5))
  self._draw_speed_gauge(0,'INICIAR');self.speed_gauge.bind('<Button-1>',lambda e:self.run_speed_test('full'))
  self.speed_btn=tk.Button(p,command=lambda:self.run_speed_test('full')) # suporte para o estado do mecanismo existente
  self.speed_status=bind_theme(tk.Label(center, text='Clique no medidor para executar o teste.', bg=theme_color('bg'), fg=theme_color('muted'), font=('Segoe UI', 10)), background='bg', foreground='muted')
  self.speed_status.pack(pady=5)
  self.speed_progress=ttk.Progressbar(center,mode='determinate',maximum=100)
  self.speed_progress.pack(fill='x',padx=90,pady=(7,12))
  metrics=bind_theme(tk.Frame(center, bg=theme_color('bg')), background='bg');metrics.pack(fill='x', padx=14, pady=(5, 15))
  self.speed_vars={}
  for title,key,sub in [('Download','download','Mbps'),('Upload','upload','Mbps'),('Latência','ping','ms')]:
   cell,value=self.card(metrics,title,'--',sub);cell.pack(side='left',fill='both',expand=True,padx=5,ipady=7)
   self.speed_vars[key]=value
  self.speed_log=bind_theme(tk.Text(p, height=1, bg=theme_color('surface'), fg=theme_color('text')), background='surface', foreground='text')
 def _draw_speed_gauge(self,value,label):
  c=self.speed_gauge;c.delete('all');cx,cy=172,131;r=106
  bind_canvas(c, c.create_oval(35, -2, 309, 267, fill=theme_color('alt'), outline=theme_color('border'), width=2), fill='alt', outline='border')
  for i in range(51):
   ang=math.radians(225-i*270/50)
   a=r+(7 if i%5 else 3);b=r+19
   c.create_line(cx + a * math.cos(ang), cy - a * math.sin(ang), cx + b * math.cos(ang), cy - b * math.sin(ang), fill=theme_color('border') if i % 5 else theme_color('accent'), width=1 if i % 5 else 2)
  bind_canvas(c, c.create_arc(cx - r, cy - r, cx + r, cy + r, start=225, extent=-270, style='arc', outline=theme_color('border'), width=14), outline='border')
  if value>0:c.create_arc(cx - r, cy - r, cx + r, cy + r, start=225, extent=-min(269, max(1, value) * 2.69), style='arc', outline=theme_color('accent') if label != 'FALHOU' else theme_color('danger'), width=14)
  big=('START' if label=='INICIAR' else f'{value:.0f}%')
  c.create_text(cx, cy - 14, text=big, fill=theme_color('accent') if label != 'FALHOU' else theme_color('danger'), font=('Segoe UI', 33, 'bold'))
  bind_canvas(c, c.create_text(cx, cy + 28, text=label, fill=theme_color('text'), font=('Segoe UI', 11, 'bold')), fill='text')
  bind_canvas(c, c.create_text(cx, cy + 50, text='CLIQUE PARA INICIAR' if not self.speed_busy else 'MEDIÇÃO EM ANDAMENTO', fill=theme_color('muted'), font=('Segoe UI', 8, 'bold')), fill='muted')
 def _animate_ui(self):
  if self.stop_event.is_set():return
  try:
   if hasattr(self,'speed_gauge') and self.speed_busy:
    self._gauge_phase=getattr(self,'_gauge_phase',0)+1
    val=float(self.speed_progress['value'])
    self._draw_speed_gauge(min(100,val+(self._gauge_phase%3)*0.3),'TESTANDO')
   if hasattr(self,'status_label') and self.monitor_on:
    self.status_label.configure(text=('● ' if int(time.time())%2 else '◉ ')+self.last_status[:52])
  except (tk.TclError,AttributeError):return
  self.root.after(280,self._animate_ui)
 def _chart_hover(self,event):
  vals=[x for x in self.samples[-80:] if x.get('latency') is not None]
  if len(vals)<2:return
  w=max(self.chart.winfo_width(),300); pad=30
  index=round((event.x-pad)/(w-2*pad)*(len(vals)-1))
  if 0<=index<len(vals):
   self.draw_chart()
   x=pad+index*(w-2*pad)/(len(vals)-1)
   bind_canvas(self.chart, self.chart.create_line(x, 30, x, max(60, self.chart.winfo_height() - 30), fill=theme_color('border'), dash=(3, 4)), fill='border')
   bind_canvas(self.chart, self.chart.create_text(min(w - 100, max(100, x)), 28, text=f"{vals[index]['latency']:.1f} ms", fill=theme_color('text'), font=('Segoe UI', 10, 'bold')), fill='text')
 def run_speed_test(self,mode='full'):
  if self.speed_busy:return
  self._speed_last_sent=0;self._speed_last_value=-1;self.speed_busy=True; self.speed_btn.configure(state='disabled'); self.speed_progress['value']=0; bind_theme(self.speed_status, foreground='accent').configure(text='Preparando teste…', fg=theme_color('accent'))
  def work():
   result={'download':None,'upload':None,'ping':None,'server':'Cloudflare Speed Test'}; logs=[]
   try:
    t0=time.perf_counter(); host=gateway_ip() or '1.1.1.1'; cmd=['ping','-n','1','-w','1500',host] if platform.system()=='Windows' else ['ping','-c','1','-W','1',host]
    try:
     out=_arc_hidden_run(cmd,capture_output=True,text=True,timeout=3,errors='ignore').stdout; m=re.search(r'(?:time|tempo)[=<]\s*([\d.,]+)\s*ms',out,re.I); result['ping']=float(m.group(1).replace(',','.')) if m else None
    except Exception: result['ping']=None
    logs.append('Latência para gateway: '+(f"{result['ping']:.1f} ms" if result['ping'] is not None else 'sem resposta'))
    if mode in ('full','download'):
     self.root.after(0,lambda:self._speed_progress(15,'Medindo download…'))
     url='https://speed.cloudflare.com/__down?bytes=10000000&cache='+str(int(time.time()))
     req=urllib.request.Request(url,headers={'User-Agent':'ArcollSpeedConnection/3.0','Cache-Control':'no-cache'})
     start=time.perf_counter()
     with urllib.request.urlopen(req,timeout=35) as resp:
      total=0
      while True:
       chunk=resp.read(65536)
       if not chunk:break
       total+=len(chunk)
       self.root.after(0,lambda n=total:self._speed_progress(min(48,15+n/10000000*33),'Recebendo dados para o teste de download…',download=round(n*8/max(.001,time.perf_counter()-start)/1_000_000,2)))
     elapsed=max(.001,time.perf_counter()-start); result['download']=round(total*8/elapsed/1_000_000,2); logs.append(f'Download: {result["download"]} Mbps ({total/1048576:.2f} MiB em {elapsed:.2f}s)')
    if mode in ('full','upload'):
     self.root.after(0,lambda:self._speed_progress(55,'Medindo upload…',download=result.get('download')))
     payload=os.urandom(3*1024*1024); req=urllib.request.Request('https://speed.cloudflare.com/__up',data=payload,headers={'User-Agent':'ArcollSpeedConnection/3.0','Content-Type':'application/octet-stream','Cache-Control':'no-cache'},method='POST')
     start=time.perf_counter()
     with urllib.request.urlopen(req,timeout=35) as resp: resp.read(2048)
     elapsed=max(.001,time.perf_counter()-start); result['upload']=round(len(payload)*8/elapsed/1_000_000,2); logs.append(f'Upload: {result["upload"]} Mbps ({len(payload)/1048576:.2f} MiB em {elapsed:.2f}s)')
    logs.append('Teste concluído em '+time.strftime('%H:%M:%S')); result['event']='speed_test'
    with history_db() as db: db.execute('INSERT INTO speedtests(captured,download,upload,latency,server) VALUES(?,?,?,?,?)',(time.time(),result['download'],result['upload'],result['ping'],result['server']))
    self.sync_data({'event':'speed_test','command_id':getattr(self,'_remote_speed_command',None),'download_mbps':result['download'],'upload_mbps':result['upload'],'latency_ms':result['ping'],'server':result['server']},ack_id=getattr(self,'_remote_speed_command',None))
    self.root.after(0,lambda:self._speed_done(result,logs))
   except Exception as e:
    logs.append('Falha no teste: '+str(e)); self.root.after(0,lambda:self._speed_done(result,logs,True))
  threading.Thread(target=work,daemon=True).start()
 def draw_speed_history(self):
  if not hasattr(self,'speed_history'):return
  c=self.speed_history;c.delete('all');w=max(240,c.winfo_width());h=max(100,c.winfo_height())
  for n in range(1,4):
   y=12+(h-25)*n/4;bind_canvas(c, c.create_line(8, y, w - 8, y, fill=theme_color('border'), dash=(3, 5)), fill='border')
  try:
   with history_db() as db: rows=list(reversed(db.execute('SELECT captured,download,upload FROM speedtests ORDER BY id DESC LIMIT 20').fetchall()))
  except Exception: rows=[]
  if not rows:bind_canvas(c, c.create_text(w / 2, h / 2, text='O GRÁFICO SERÁ PREENCHIDO APÓS O PRIMEIRO TESTE', fill=theme_color('muted'), font=('Segoe UI', 9)), fill='muted');return
  limit=max(1,max(float(v or 0) for _,dl,ul in rows for v in (dl,ul)))*1.12
  def poly(index,color):
   points=[]
   for i,row in enumerate(rows):
    v=row[index]
    if v is None:continue
    x=12+i*(w-24)/max(1,len(rows)-1);y=h-14-(float(v)/limit)*(h-30)
    points.append((x,y))
   if len(points)>1:c.create_line(*[z for pair in points for z in pair],fill=color,width=3,smooth=True)
   for x,y in points:bind_canvas(c, c.create_oval(x - 3, y - 3, x + 3, y + 3, fill=color, outline=theme_color('surface')), outline='surface')
  poly(1, theme_color('accent'));poly(2, theme_color('success'))
  bind_canvas(c, c.create_text(10, 9, text=f'{limit:.0f} Mbps', fill=theme_color('muted'), anchor='w', font=('Segoe UI', 8)), fill='muted');bind_canvas(c, c.create_text(w - 10, 9, text=f'{len(rows)} testes', fill=theme_color('muted'), anchor='e', font=('Segoe UI', 8)), fill='muted')
  bind_canvas(c, c.create_text(12, h - 3, text='● Download', fill=theme_color('accent'), anchor='w', font=('Segoe UI', 8)), fill='accent');bind_canvas(c, c.create_text(115, h - 3, text='● Upload', fill=theme_color('success'), anchor='w', font=('Segoe UI', 8)), fill='success')
 def _speed_progress(self,value,msg,download=None):
  if download is not None:self.speed_vars['download'].configure(text=f'{download:.2f}')
  self.speed_progress['value']=value;self._draw_speed_gauge(value,'TESTANDO'); bind_theme(self.speed_status, foreground='accent').configure(text=msg, fg=theme_color('accent'))
  now=time.monotonic()
  if getattr(self,'_remote_speed_command',None) and (now-getattr(self,'_speed_last_sent',0)>2 or value>=99):
   self._speed_last_sent=now
   self.sync_data({'event':'speed_progress','command_id':self._remote_speed_command,'percent':round(float(value),1),'message':msg,'download_mbps':download,'captured_at':int(time.time())})
 def _speed_done(self,result,logs,failed=False):
  for key in ('download','upload','ping'):
   val=result.get(key); self.speed_vars[key].configure(text='--' if val is None else f'{val:.2f}' if isinstance(val,(float,int)) else str(val))
  self.speed_log.insert('end','\n['+time.strftime('%Y-%m-%d %H:%M:%S')+']\n'+'\n'.join(logs)+'\n'); self.speed_log.see('end'); self.speed_busy=False; self.speed_btn.configure(state='normal'); self.speed_progress['value']=100 if not failed else 0;self._draw_speed_gauge(100 if not failed else 0,'CONCLUÍDO' if not failed else 'FALHOU'); self.speed_status.configure(text='Teste finalizado.' if not failed else 'Teste interrompido; confira o detalhe abaixo.', fg=theme_color('success') if not failed else theme_color('danger')); self.draw_speed_history(); self.log('Teste de velocidade ' + ('falhou.' if failed else 'concluído.'))
  remote=getattr(self,'_remote_speed_command',None)
  if remote:
   self._remote_speed_command=None
   if failed:self._ack_remote_command(remote,'failed',('Falha: '+str(logs[-1]))[:450])
   # Sucesso é confirmado somente após persistir o evento no servidor.
 def _build_map(self):
  p=self.map_page
  bar=bind_theme(tk.Frame(p, bg=theme_color('bg')), background='bg');bar.pack(fill='x', padx=12, pady=(11, 8))
  bind_theme(tk.Label(bar, text='TOPOLOGIA LÓGICA DA REDE', bg=theme_color('bg'), fg=theme_color('accent'), font=('Segoe UI', 10, 'bold')), background='bg', foreground='accent').pack(side='left', padx=(0, 10))
  self.map_mode=tk.StringVar(value='Grupos / setores')
  choices=['Grupos / setores','Tipos de equipamentos','Infraestrutura detectada']
  self.map_mode_box=ttk.Combobox(bar,textvariable=self.map_mode,values=choices,state='readonly',width=23,style='Arcoll.TCombobox')
  self.map_mode_box.pack(side='left',padx=(3,8));self.map_mode_box.bind('<<ComboboxSelected>>',lambda e:self._map_change_mode())
  self.btn(bar,'Examinar rede',self.scan_devices).pack(side='left',padx=3)
  for title,handler in [('Largura',lambda:self._map_center('x')),('Altura',lambda:self._map_center('y')),('Tudo',lambda:self._map_center('all')),('−',lambda:self._map_zoom(.83)),('+',lambda:self._map_zoom(1.2)),('Tela inteira',self._map_fullscreen),('A4',self._map_print)]:
   self.btn(bar,title,handler).pack(side='left',padx=3)
  panel=bind_theme(tk.Frame(p, bg=theme_color('surface'), highlightbackground=theme_color('accent'), highlightthickness=1), background='surface', highlightbackground='accent')
  panel.pack(fill='both',expand=True,padx=12,pady=(0,11))
  self.map_canvas=bind_theme(tk.Canvas(panel, bg=theme_color('alt'), highlightthickness=0, cursor='hand2'), background='alt')
  self.map_canvas.pack(fill='both',expand=True)
  self.map_canvas.bind('<Configure>',lambda e:self.draw_map())
  self.map_canvas.bind('<ButtonPress-1>',self._map_drag_start)
  self.map_canvas.bind('<B1-Motion>',self._map_drag_motion)
  self.map_canvas.bind('<ButtonRelease-1>',self._map_drag_end)
  self.map_canvas.bind('<MouseWheel>',lambda e:self._map_zoom(1.12 if e.delta>0 else .88))
  self.map_canvas.bind('<Button-4>',lambda e:self._map_zoom(1.12))
  self.map_canvas.bind('<Button-5>',lambda e:self._map_zoom(.88))
  self.map_canvas.bind('<ButtonPress-2>',self._map_pan_start)
  self.map_canvas.bind('<B2-Motion>',self._map_pan_move)
  self.map_canvas.bind('<Double-Button-1>',self._map_open_device)
  self.map_positions={};self.map_scale=1.0;self.map_offset_x=0.0;self.map_offset_y=0.0
  self.map_drag_id=None;self.map_pan_anchor=None;self.map_drawn=[];self.map_groups_info=[];self.map_full=False
  self._map_load_positions()
  self.root.after(250,lambda:self._map_center('all'))
 def get_groups(self):
  try:
   with history_db() as db: rows=db.execute('SELECT id,name,parent_id FROM topology_groups ORDER BY name').fetchall()
   lookup={i:(name,parent) for i,name,parent in rows}
   def label(i):
    name,parent=lookup[i];return ((lookup[parent][0]+' / ') if parent in lookup else '')+name
   return [(i,label(i)) for i,_,_ in rows]
  except Exception:return []
 def group_name(self,gid):
  return dict(self.get_groups()).get(gid,'Sem grupo')
 def create_group(self):
  name=simpledialog.askstring('Topologia','Nome do grupo, departamento, andar ou setor:',parent=self.root)
  if not name or not name.strip():return
  groups=self.get_groups();choices='0: Sem grupo (raiz)\n'+'\n'.join(f'{i}: {label}' for i,label in groups)
  parent=simpledialog.askinteger('Subgrupo','ID do grupo pai (0 para grupo principal):\n'+choices,parent=self.root,minvalue=0)
  if parent is None:return
  if parent!=0 and parent not in dict(groups):messagebox.showerror(APP_NAME,'Grupo pai não encontrado.');return
  with history_db() as db:db.execute('INSERT INTO topology_groups(name,parent_id) VALUES(?,?)',(name.strip()[:90],parent or None))
  self.draw_map();self.send_inventory_snapshot()
 def refresh_map(self):
  # Atualizar visualização não é permissão para sondar a rede.
  self.draw_map()
 def draw_map(self):
  if not hasattr(self,'map_canvas'):return
  c=self.map_canvas;c.delete('all');w=max(400,c.winfo_width());h=max(230,c.winfo_height())
  mode=self._map_mode_key();groups={}
  for i,d in enumerate(self.devices):
   kind=str(d.get('custom_type') or d.get('type') or 'device').lower()
   if mode=='type':g=kind.replace('_',' ').capitalize()
   elif mode=='infra':
    g='Equipamentos de infraestrutura' if any(k in kind for k in ('router','roteador','switch','ap','access','wifi')) else 'Conexão física não determinada'
   else:g=self.group_name(d.get('group_id'))
   groups.setdefault(g,[]).append(i)
  groups=dict(sorted(groups.items(),key=lambda x:x[0].casefold()))
  root=self.map_positions.get('root') or (270.0,145.0)
  layout={'root':root}
  # Agrupamento lógico: conexões desenhadas não equivalem a portas físicas comprovadas.
  for col,(g,indices) in enumerate(groups.items()):
   gid='group:'+g
   gx,gy=self.map_positions.get(gid,(145.0+(col%4)*500,380.0+(col//4)*560))
   layout[gid]=(gx,gy)
   for row,index in enumerate(indices):
    key='device:'+str(self.devices[index].get('_device_id') or index)
    layout[key]=self.map_positions.get(key,(gx-105.0+(row%3)*118,gy+115+(row//3)*92))
  self.map_drawn=list(layout.items());self.map_groups_info=[]
  def xy(position):return ((position[0]+self.map_offset_x)*self.map_scale,(position[1]+self.map_offset_y)*self.map_scale)
  def line(a,b,source=False):
   x1,y1=xy(a);x2,y2=xy(b)
   c.create_line(x1, y1, x1 + (x2 - x1) * 0.48, y1, x1 + (x2 - x1) * 0.48, y2, x2, y2, fill=theme_color('accent') if source else theme_color('border'), width=2 if source else 1.5, smooth=True, dash=() if source else (6, 5), tags=('connection',))
  # Linhas primeiro, cartões depois: acompanham drag e zoom sem gambiarras de geometria.
  for g,indices in groups.items():
   gp=layout['group:'+g];line(root,gp)
   for index in indices:line(gp,layout['device:'+str(self.devices[index].get('_device_id') or index)])
  def card(pos,ww,hh,label,sub,color,tag):
   x,y=xy(pos);wide=ww*self.map_scale;high=hh*self.map_scale
   bind_canvas(c, c.create_rectangle(x - wide / 2, y - high / 2, x + wide / 2, y + high / 2, fill=theme_color('alt'), outline=color, width=2, tags=(tag, 'node')), fill='alt')
   bind_canvas(c, c.create_text(x, y - 6 * self.map_scale, text=label[:29], fill=theme_color('text'), font=('Segoe UI', max(7, int(11 * self.map_scale)), 'bold'), width=max(90, wide - 10), tags=(tag, 'node')), fill='text')
   if sub:bind_canvas(c, c.create_text(x, y + 14 * self.map_scale, text=sub[:40], fill=theme_color('muted'), font=('Segoe UI', max(6, int(8 * self.map_scale))), width=max(90, wide - 8), tags=(tag, 'node')), fill='muted')
  card(root, 245, 70, SITE_CODE or 'REDE REGISTRADA', 'Agente Windows • gateway ' + (gateway_ip() or '?'), theme_color('accent'), 'root')
  for g,indices in groups.items():
   gp=layout['group:'+g];card(gp, 205, 68, g, str(len(indices)) + ' equipamentos', theme_color('accent'), 'group:' + g)
   x,y=gp;self.map_groups_info.append((g,(x-110,y-40,x+110,y+40)))
   for index in indices:
    d=self.devices[index];key='device:'+str(d.get('_device_id') or index)
    name=str(d.get('custom_name') or d.get('name') or d.get('ip') or 'Dispositivo')
    status='Online' if d.get('status')=='online' else 'Offline'
    card(layout[key], 110, 62, name, status, theme_color('success') if d.get('status') == 'online' else theme_color('alt'), 'device_index:' + str(index))
  bind_canvas(c, c.create_text(13, h - 12, text='Conexões lógicas / grupos  •  Arraste os cartões para organizar  •  A ligação física só é confirmada com dados de infraestrutura', anchor='w', fill=theme_color('muted'), font=('Segoe UI', 8), tags=('legend',)), fill='muted')
  if not self.devices:bind_canvas(c, c.create_text(w / 2, h / 2, text='Aguardando inventário do agente Windows', fill=theme_color('muted'), font=('Segoe UI', 12)), fill='muted')

 def _map_mode_key(self):
  return {'Grupos / setores':'group','Tipos de equipamentos':'type','Infraestrutura detectada':'infra'}.get(self.map_mode.get(),'group')

 def _map_load_positions(self):
  try:
   with history_db() as db:rows=db.execute('SELECT node_id,x,y FROM map_positions WHERE mode=?',(self._map_mode_key(),)).fetchall()
   self.map_positions={name:(float(x),float(y)) for name,x,y in rows}
  except (sqlite3.Error,ValueError):self.map_positions={}

 def _map_save_positions(self,items):
  if not items:return
  try:
   with history_db() as db:
    db.executemany('INSERT OR REPLACE INTO map_positions(mode,node_id,x,y) VALUES(?,?,?,?)',[(self._map_mode_key(),key,float(pos[0]),float(pos[1])) for key,pos in items.items()])
  except sqlite3.Error as exc:self.log('Mapa: não foi possível salvar posição: '+str(exc),'WARN')

 def _map_change_mode(self):
  self._map_load_positions();self._map_center('all')

 def _map_bounds(self):
  arr=[pos for _key,pos in getattr(self,'map_drawn',[])]
  if not arr:return (0,0,600,400)
  return (min(x for x,y in arr)-140,min(y for x,y in arr)-95,max(x for x,y in arr)+140,max(y for x,y in arr)+95)

 def _map_center(self,axis):
  c=self.map_canvas;w=max(250,c.winfo_width());h=max(180,c.winfo_height())
  if not getattr(self,'map_drawn',None):self.draw_map()
  x0,y0,x1,y1=self._map_bounds()
  if axis=='all':
   self.map_scale=max(.32,min(1.25,min((w-70)/max(1,x1-x0),(h-65)/max(1,y1-y0))))
  if axis in ('all','x'):self.map_offset_x=w/(2*self.map_scale)-(x0+x1)/2
  if axis in ('all','y'):self.map_offset_y=h/(2*self.map_scale)-(y0+y1)/2
  self.draw_map()

 def _map_zoom(self,factor):
  c=self.map_canvas;w=max(300,c.winfo_width());h=max(180,c.winfo_height());old=self.map_scale
  new=max(.28,min(2.3,old*factor))
  center_x=w/(2*old)-self.map_offset_x;center_y=h/(2*old)-self.map_offset_y
  self.map_scale=new;self.map_offset_x=w/(2*new)-center_x;self.map_offset_y=h/(2*new)-center_y
  self.draw_map()

 def _map_fullscreen(self):
  self.map_full=not self.map_full
  self.root.attributes('-fullscreen',self.map_full)
  self.root.bind('<Escape>',lambda _e:self._map_fullscreen() if self.map_full else None)
  self.root.after(120,self.draw_map)

 def _map_pan_start(self,event):self.map_pan_anchor=(event.x,event.y)
 def _map_pan_move(self,event):
  if not self.map_pan_anchor:return
  ox,oy=self.map_pan_anchor;self.map_offset_x+=(event.x-ox)/self.map_scale;self.map_offset_y+=(event.y-oy)/self.map_scale
  self.map_pan_anchor=(event.x,event.y);self.draw_map()

 def _map_drag_motion(self,event):
  info=getattr(self,'map_drag_id',None)
  if not info:return
  tag,old_x,old_y=info
  dx=(event.x-old_x)/self.map_scale;dy=(event.y-old_y)/self.map_scale
  actual=next((k for k,v in self.map_drawn if k==tag),None)
  if actual is None:return
  self.map_positions[tag]=(self.map_positions.get(tag) or dict(self.map_drawn)[tag])[0]+dx,(self.map_positions.get(tag) or dict(self.map_drawn)[tag])[1]+dy
  if tag.startswith('group:'):
   for index,d in enumerate(self.devices):
    gkey='device:'+str(d.get('_device_id') or index)
    # Apenas os dispositivos ligados ao grupo movido.
    type_name=str(d.get('custom_type') or d.get('type') or 'device').lower()
    belongs=(self.group_name(d.get('group_id')) if self._map_mode_key()=='group' else type_name.replace('_',' ').capitalize() if self._map_mode_key()=='type' else 'Equipamentos de infraestrutura' if any(z in type_name for z in ('router','roteador','switch','ap','access','wifi')) else 'Conexão física não determinada')
    if tag=='group:'+belongs:
     x,y=self.map_positions.get(gkey,dict(self.map_drawn).get(gkey,(0,0)));self.map_positions[gkey]=(x+dx,y+dy)
  self.map_drag_id=(tag,event.x,event.y)
  self.draw_map()

 def _map_open_device(self,event):
  found=next((t for t in self.map_canvas.gettags('current') if t.startswith('device_index:')),None)
  if not found:return
  idx=int(found.split(':',1)[1])
  if 0<=idx<len(self.devices):
   self.nb.select(self.devices_page);self.device_tree.selection_set(str(idx));self.device_tree.see(str(idx));self.edit_device()

 def _map_print(self):
  """Gera página A4 horizontal com SVG contendo as posições ajustadas localmente."""
  import tempfile
  from xml.sax.saxutils import escape
  nodes=dict(self.map_drawn)
  if not nodes:return
  x0,y0,x1,y1=self._map_bounds();bw=max(1,x1-x0);bh=max(1,y1-y0)
  svg=['<svg xmlns="http://www.w3.org/2000/svg" viewBox="%.1f %.1f %.1f %.1f" width="1120" height="780">'%(x0,y0,bw,bh),'<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#0b2538"/>'%(x0,y0,bw,bh)]
  for key,pos in nodes.items():
   if key=='root':name=SITE_CODE;w=230;stroke=theme_color('accent');parent=None
   elif key.startswith('group:'):name=key[6:];w=195;stroke=theme_color('accent');parent='root'
   else:
    d=next((d for d in self.devices if key=='device:'+str(d.get('_device_id'))),None)
    if not d:continue
    name=str(d.get('custom_name') or d.get('name') or 'Dispositivo');w=112;stroke=theme_color('success') if d.get('status')=='online' else theme_color('alt')
    group_name=self.group_name(d.get('group_id')) if self._map_mode_key()=='group' else str(d.get('custom_type') or d.get('type') or 'device').lower().replace('_',' ').capitalize()
    if self._map_mode_key()=='infra':group_name='Equipamentos de infraestrutura' if any(q in str(d.get('type') or '').lower() for q in ('router','roteador','switch','ap','wifi')) else 'Conexão física não determinada'
    parent='group:'+group_name
   if parent in nodes:
    xp,yp=nodes[parent];svg.append('<path d="M %.1f %.1f L %.1f %.1f" stroke="#3c7897" stroke-dasharray="7 4" fill="none" stroke-width="2"/>'%(xp,yp,pos[0],pos[1]))
   x,y=pos;svg.append('<rect x="%.1f" y="%.1f" width="%d" height="64" rx="8" fill="#11334c" stroke="%s" stroke-width="2"/>'%(x-w/2,y-32,w,stroke))
   svg.append('<text x="%.1f" y="%.1f" fill="white" font-family="Arial" font-size="12" text-anchor="middle">%s</text>'%(x,y+5,escape(name[:22])))
  svg.append('</svg>')
  page="""<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:8mm}body{margin:0;font-family:Arial}svg{display:block;width:100%;height:auto;max-height:180mm}</style></head><body><h3>Topologia Arcoll — """+html.escape(SITE_CODE)+"""</h3>"""+''.join(svg)+"""<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),500))</script></body></html>"""
  try:
   tmp=Path(tempfile.gettempdir())/('arcoll-topologia-'+re.sub(r'[^A-Za-z0-9]','',SITE_CODE)+'.html')
   tmp.write_text(page,encoding='utf-8');webbrowser.open(tmp.as_uri())
  except (OSError,ValueError) as exc:messagebox.showerror(APP_NAME,'Não foi possível preparar a impressão A4: '+str(exc))
 def _map_drag_start(self,event):
  tags=self.map_canvas.gettags('current')
  device=next((t for t in tags if t.startswith('device_index:')),None)
  group=next((t for t in tags if t.startswith('group:')),None)
  root='root' if 'root' in tags else None
  if device:
   index=int(device.split(':',1)[1]);tag='device:'+str(self.devices[index].get('_device_id') or index)
  else:tag=group or root
  self.map_drag_id=(tag,event.x,event.y) if tag else None
 def _map_drag_end(self,event):
  info=self.map_drag_id;self.map_drag_id=None
  if not info:return
  tag=info[0]
  if tag in self.map_positions:self._map_save_positions({tag:self.map_positions[tag]})
  if tag.startswith('group:'):
   positions={key:pos for key,pos in self.map_positions.items() if key.startswith('device:')}
   self._map_save_positions(positions)
  # Se o cartão foi colocado sobre outro grupo no modo grupos, atualizar a identificação real.
  if tag.startswith('device:') and self._map_mode_key()=='group':
   x,y=self.map_positions.get(tag,dict(self.map_drawn).get(tag,(0,0)))
   for group,(gx0,gy0,gx1,gy1) in self.map_groups_info:
    if gx0<=x<=gx1 and gy0<=y<=gy1:
     item=next((d for d in self.devices if tag=='device:'+str(d.get('_device_id'))),None)
     if item and self.group_name(item.get('group_id'))!=group:
      gid=next((gid for gid,name in [(None,'Sem grupo')]+self.get_groups() if name==group),None)
      with history_db() as db:db.execute('UPDATE inventory SET group_id=? WHERE device_id=?',(gid,item['_device_id']))
      self.load_inventory();self.render_devices();self.send_inventory_snapshot()
     break
  self.draw_map()
 def _build_connection(self):
  p=self.connection_page
  shell=bind_theme(tk.Frame(p, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1), background='surface', highlightbackground='border')
  shell.pack(fill='both',expand=True,padx=12,pady=(12,11))
  head=bind_theme(tk.Frame(shell, bg=theme_color('surface')), background='surface');head.pack(fill='x', padx=12, pady=9)
  bind_theme(tk.Label(head, text='ARCOLL / CONEXÃO', bg=theme_color('surface'), fg=theme_color('accent'), font=('Segoe UI', 9, 'bold')), background='surface', foreground='accent').pack(side='left')
  bind_theme(tk.Label(head, text='  Mapa e provedora', bg=theme_color('surface'), fg=theme_color('text'), font=('Segoe UI', 11, 'bold')), background='surface', foreground='text').pack(side='left')
  self.btn(head,'GPS origem',lambda:self._connection_set_gps('origin')).pack(side='right',padx=3)
  self.btn(head,'GPS destino',lambda:self._connection_set_gps('dest')).pack(side='right',padx=3)
  self.btn(head,'Diagnosticar',self.run_diagnostics,True).pack(side='right')
  self.connection_status=bind_theme(tk.Label(head, text='Aguardando diagnóstico do agente', bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='surface', foreground='muted')
  self.connection_status.pack(side='right',padx=13)
  fields=[('PROVEDORA DE INTERNET','provider'),('IP PÚBLICO','public_ip'),('ASN','asn'),('REGIÃO DO IP','region'),('GATEWAY','gateway'),('AGENTE / IP LOCAL','local_ip')]
  band=bind_theme(tk.Frame(shell, bg=theme_color('surface')), background='surface');band.pack(fill='x', padx=10, pady=(0, 4));self.connection_fields={}
  for name,key in fields:
   cell=bind_theme(tk.Frame(band, bg=theme_color('alt'), highlightbackground=theme_color('accent'), highlightthickness=1), background='alt', highlightbackground='accent')
   cell.pack(side='left',fill='x',expand=True,padx=2,ipady=3)
   bind_theme(tk.Label(cell, text=name, bg=theme_color('alt'), fg=theme_color('muted'), font=('Segoe UI', 8, 'bold')), background='alt', foreground='muted').pack(anchor='w', padx=9, pady=(8, 3))
   lbl=bind_theme(tk.Label(cell, text='Não identificado', bg=theme_color('alt'), fg=theme_color('text'), font=('Segoe UI', 9, 'bold'), anchor='w', justify='left', wraplength=160), background='alt', foreground='text')
   lbl.pack(anchor='w',fill='x',padx=9,pady=(0,7));self.connection_fields[key]=lbl
  self.connection_map=bind_theme(tk.Canvas(shell, bg=theme_color('alt'), highlightthickness=0), background='alt')
  self.connection_map.pack(fill='both',expand=True,padx=10,pady=(0,8))
  self.connection_map.bind('<Configure>',lambda _e:self._draw_connection_map())
  self.connection_out=tk.Text(p,height=1)
  self.refresh_connection()
 def _connection_set_gps(self,kind):
  # Coordenadas fornecidas e confirmadas pelo operador. O IP não fornece GPS exato.
  title='Origem (provedor)' if kind=='origin' else 'Destino (rede)'
  raw=simpledialog.askstring('Coordenadas GPS verificadas',f'{title}: informe latitude, longitude.\nExemplo: -1.4558, -48.4902\nNão use localização por IP como GPS exato.',parent=self.root)
  if raw is None:return
  try:
   parts=[float(p.strip()) for p in raw.replace(';',',').split(',')]
   if len(parts)!=2 or not (-90<=parts[0]<=90 and -180<=parts[1]<=180):raise ValueError()
  except (ValueError,TypeError):
   messagebox.showerror('GPS','Informe latitude e longitude válidas, separadas por vírgula.',parent=self.root);return
  if not hasattr(self,'_connection_gps'):self._connection_gps={}
  self._connection_gps[kind]=parts
  self._draw_connection_map()

 def _set_connection_fields(self,details):
  if not hasattr(self,'connection_fields'):return
  provider=details.get('provider') or {};iface=details.get('interface') or {}
  region=', '.join(str(provider.get(k)) for k in ('city','region','country') if provider.get(k))
  if provider.get('latitude') is not None and provider.get('longitude') is not None:
   region += f" • IP aprox.: {provider.get('latitude')}, {provider.get('longitude')}"
  values={'provider':provider.get('isp'),'asn':provider.get('asn'),'public_ip':details.get('public_ip'),'region':region,'gateway':iface.get('gateway') or gateway_ip(),'local_ip':platform.node()+' · '+str(iface.get('ip') or local_ip())}
  for k,widget in self.connection_fields.items():widget.configure(text=str(values.get(k) or 'Não identificado'))
  self._draw_connection_map()
 def _draw_connection_map(self):
  if not hasattr(self,'connection_map'):return
  c=self.connection_map;c.delete('all');w=max(420,c.winfo_width());h=max(180,c.winfo_height())
  # Esquema lógico do mesmo percurso representado no portal; não inferir saltos físicos.
  for i in range(1,6):
   yy=h*i/6;bind_canvas(c, c.create_line(0, yy, w, yy - 12, fill=theme_color('border'), smooth=True), fill='border')
  x1=max(85,int(w*.20));x2=int(w*.50);x3=min(w-100,int(w*.81));y=int(h*.52)
  bind_canvas(c, c.create_line(x1 + 46, y, x2 - 60, y - 38, fill=theme_color('accent'), width=3, dash=(9, 7), smooth=True), fill='accent')
  bind_canvas(c, c.create_line(x2 + 60, y - 38, x3 - 55, y, fill=theme_color('accent'), width=3, dash=(9, 7), smooth=True), fill='accent')
  bind_canvas(c, c.create_oval(x1 - 47, y - 32, x1 + 47, y + 32, fill=theme_color('alt'), outline=theme_color('accent'), width=2), fill='alt', outline='accent')
  bind_canvas(c, c.create_oval(x2 - 60, y - 75, x2 + 60, y + 5, fill=theme_color('alt'), outline=theme_color('accent'), width=2), fill='alt', outline='accent')
  bind_canvas(c, c.create_oval(x3 - 53, y - 32, x3 + 53, y + 32, fill=theme_color('alt'), outline=theme_color('accent'), width=2), fill='alt', outline='accent')
  bind_canvas(c, c.create_text(x1, y - 5, text='ORIGEM', fill=theme_color('muted'), font=('Segoe UI', 8, 'bold')), fill='muted')
  bind_canvas(c, c.create_text(x1, y + 12, text='PROVEDORA', fill=theme_color('text'), font=('Segoe UI', 10, 'bold')), fill='text')
  bind_canvas(c, c.create_text(x2, y - 47, text='INTERNET', fill=theme_color('text'), font=('Segoe UI', 14, 'bold')), fill='text')
  bind_canvas(c, c.create_text(x2, y - 25, text='ROTA LÓGICA', fill=theme_color('muted'), font=('Segoe UI', 8)), fill='muted')
  bind_canvas(c, c.create_text(x3, y - 5, text='DESTINO', fill=theme_color('muted'), font=('Segoe UI', 8, 'bold')), fill='muted')
  bind_canvas(c, c.create_text(x3, y + 12, text='AGENTE WINDOWS', fill=theme_color('text'), font=('Segoe UI', 9, 'bold')), fill='text')
  coords=getattr(self,'_connection_gps',{})
  for key,xx in [('origin',x1),('dest',x3)]:
   if key in coords:
    ll=coords[key]
    bind_canvas(c, c.create_text(xx, y + 43, text=f'GPS verificado: {ll[0]:.5f}, {ll[1]:.5f}', fill=theme_color('accent'), font=('Segoe UI', 8), anchor='center'), fill='accent')
  bind_canvas(c, c.create_text(18, h - 18, text='Rota lógica ilustrativa. Coordenadas GPS somente se informadas e verificadas no local.', fill=theme_color('muted'), font=('Segoe UI', 8), anchor='w'), fill='muted')
 def _connection_cache_path(self):
  return Path(os.environ.get('LOCALAPPDATA') or str(Path.home()))/'Arcoll'/'connection-last-confirmed.json'
 def _load_confirmed_connection(self):
  try:
   path=self._connection_cache_path()
   if not path.is_file():return None
   details=json.loads(path.read_text(encoding='utf-8'))
   if not isinstance(details,dict) or not isinstance(details.get('provider'),dict):return None
   if not details.get('public_ip') or not details['provider'].get('isp'):return None
   return details
  except (OSError,ValueError,TypeError):return None
 def _save_confirmed_connection(self,details):
  if not details.get('public_ip') or not (details.get('provider') or {}).get('isp'):return
  try:
   path=self._connection_cache_path();path.parent.mkdir(parents=True,exist_ok=True)
   tmp=path.with_suffix('.tmp');tmp.write_text(json.dumps(details,ensure_ascii=False),encoding='utf-8');tmp.replace(path)
  except (OSError,ValueError,TypeError):pass
 def refresh_connection(self):
  info={'provider':{},'interface':{'ip':local_ip(),'gateway':gateway_ip()}}
  cached=self._load_confirmed_connection()
  if cached:
   self._set_connection_fields(cached)
   self.connection_status.configure(text='Última identificação confirmada. Atualizando diagnóstico…')
  else:self._set_connection_fields(info)
  self.connection_out.delete('1.0','end')
  self.connection_out.insert('end','\n'.join([f'{k}: {v.cget("text")}' for k,v in self.connection_fields.items()]))
 def copy_connection(self):
  try:self.root.clipboard_clear(); self.root.clipboard_append(self.connection_out.get('1.0','end')); self.log('Resumo da conexão copiado.')
  except Exception as e:messagebox.showerror(APP_NAME,str(e))

 def _build_devices(self):
  p=self.devices_page
  tools=bind_theme(tk.Frame(p, bg=theme_color('bg')), background='bg');tools.pack(fill='x', padx=12, pady=(12, 8))
  self.device_filter=tk.StringVar()
  search=bind_theme(tk.Entry(tools, textvariable=self.device_filter, bg=theme_color('alt'), fg=theme_color('text'), insertbackground=theme_color('text'), relief='flat', font=('Segoe UI', 10), width=27), background='alt', foreground='text', insertbackground='text')
  search.pack(side='left',ipady=7,padx=(0,8))
  self.device_filter.trace_add('write',lambda *_:self.render_devices())
  self.device_status_filter=tk.StringVar(value='Todas as situações')
  self.device_status_select=ttk.Combobox(tools,textvariable=self.device_status_filter,state='readonly',width=18,style='Arcoll.TCombobox',values=('Todas as situações','Online','Offline','Fixados'))
  self.device_status_select.pack(side='left',padx=4);self.device_status_select.bind('<<ComboboxSelected>>',lambda e:self.render_devices())
  self.device_type_filter=tk.StringVar(value='Todos os tipos')
  self.device_type_select=ttk.Combobox(tools,textvariable=self.device_type_filter,state='readonly',width=19,style='Arcoll.TCombobox',values=('Todos os tipos',))
  self.device_type_select.pack(side='left',padx=4);self.device_type_select.bind('<<ComboboxSelected>>',lambda e:self.render_devices())
  self.device_group_filter=tk.StringVar(value='Todos os grupos')
  self.device_group_select=ttk.Combobox(tools,textvariable=self.device_group_filter,state='readonly',width=19,style='Arcoll.TCombobox',values=('Todos os grupos',))
  self.device_group_select.pack(side='left',padx=4);self.device_group_select.bind('<<ComboboxSelected>>',lambda e:self.render_devices())
  self.device_sort=tk.StringVar(value='Mais recentes')
  self.device_sort_select=ttk.Combobox(tools,textvariable=self.device_sort,state='readonly',width=17,style='Arcoll.TCombobox',values=('Mais recentes','Mais antigos','Primeira detecção','Nome'))
  self.device_sort_select.pack(side='left',padx=4);self.device_sort_select.bind('<<ComboboxSelected>>',lambda e:self.render_devices())
  self.device_count_label=bind_theme(tk.Label(tools, text='0 dispositivos', bg=theme_color('bg'), fg=theme_color('accent'), font=('Segoe UI', 9, 'bold')), background='bg', foreground='accent')
  self.device_count_label.pack(side='right',padx=5)
  bind_theme(tk.Button(tools, text='⟳  Atualizar varredura', command=self.scan_devices, font=('Segoe UI', 9, 'bold'), fg='white', bg=theme_color('accent'), activebackground=theme_color('accent'), activeforeground='white', relief='flat', padx=9, pady=5, cursor='hand2'), background='accent', activebackground='accent').pack(side='right', padx=8)

  self.device_tree=self._tree(p,{'Nº':45,'Dispositivo':185,'Endereço IP':125,'MAC':148,'Tipo':111,'Grupo':148,'Situação':87,'Tempo no estado':110,'Última atividade':138})
  self.device_tree.bind('<Double-1>',self.edit_device)
  self.load_inventory()
  self.scan_status=bind_theme(tk.Label(p, text='Inventário sincronizado com o agente Windows.', bg=theme_color('bg'), fg=theme_color('muted'), anchor='w', font=('Segoe UI', 9)), background='bg', foreground='muted')
  self.scan_status.pack(fill='x',padx=14,pady=(2,9))
 def render_toolkit(self):
  selected=self.tool_selected.get() if hasattr(self,'tool_selected') else 'Visão geral'
  key=next((k for k,v in getattr(self,'tool_names',{}).items() if v==selected),'pulse')
  obj=getattr(self,'toolkit_latest',{}).get('results',{}).get(key)
  translations={'exit_code':'Resultado','output':'Informações','data':'Detalhes','status':'Situação','host':'Destino','latency_ms':'Latência (ms)','response_count':'Respostas','gateway':'Gateway','public_dns':'DNS público','addresses':'Endereços IP','default_route':'Rota padrão','routes':'Rotas','adapters':'Adaptadores','servers':'Servidores DNS','example_lookup':'Teste de resolução','profiles':'Perfis','summary':'Resumo','winhttp':'Proxy Windows','services':'Serviços','destination':'Destino','port':'Porta','reachable':'Disponível','connect_ms':'Conexão (ms)','score':'Índice de saúde','total_checks':'Testes realizados','successful_checks':'Testes aprovados','findings':'Conclusões','note':'Observação','error':'Erro','interface':'Interface','interface_alias':'Adaptador'}
  def lines(v,level=0):
   if level>4:return []
   if isinstance(v,dict):
    if 'data' in v and v['data'] is not None:return lines(v['data'],level)
    out=[]
    for k,x in v.items():
     if k in ('output','exit_code') and v.get('data') is not None:continue
     label=translations.get(k,k.replace('_',' ').capitalize())
     if isinstance(x,(dict,list)):
      out.append('  '*level+label+':');out.extend(lines(x,level+1))
     elif x is not None and x!='':out.append('  '*level+label+': '+('Sim' if x is True else 'Não' if x is False else str(x)))
    return out
   if isinstance(v,list):
    out=[]
    for index,x in enumerate(v[:35],1):out.extend(lines(x,level+1) if isinstance(x,(dict,list)) else ['  '*level+str(index)+'. '+str(x)])
    return out
   if isinstance(v,str) and v.strip().startswith(('{','[')):
    try:return lines(json.loads(v),level)
    except ValueError:pass
   return ['  '*level+str(v)]
  info='Execute uma análise para visualizar informações da rede.' if obj is None else '\n'.join(lines(obj))
  if hasattr(self,'tools_out'):
   self.tools_out.delete('1.0','end');self.tools_out.insert('end',info)
  elif hasattr(self,'cmd_console'):self._console_write('','Diagnóstico: '+info,'info')
 def run_toolkit(self,remote_id=None,selected_tool=None):
  if getattr(self,'toolkit_busy',False):
   if remote_id:self._ack_remote_command(remote_id,'failed','Análise já em execução')
   return
  self.toolkit_busy=True
  if hasattr(self,'tool_status'):bind_theme(self.tool_status, foreground='warning').configure(text='Coletando medições…', fg=theme_color('warning'))
  elif hasattr(self,'cmd_status'):bind_theme(self.cmd_status, foreground='warning').configure(text='Coletando medições…', fg=theme_color('warning'))
  def work():
   try:
    from arcoll_toolkit import collect_toolkit,collect_toolkit_selected
    selected=selected_tool or next((k for k,v in getattr(self,'tool_names',{}).items() if v==(self.tool_selected.get() if hasattr(self,'tool_selected') else '')),'pulse')
    report=collect_toolkit_selected(selected) if selected in ('traceroute','services','health','troubleshooter') else collect_toolkit()
    def show():
     self.toolkit_latest=report;self.render_toolkit()
     if hasattr(self,'tool_status'):bind_theme(self.tool_status, foreground='success').configure(text='Coletado ' + time.strftime('%H:%M:%S'), fg=theme_color('success'))
    self.root.after(0,show)
    if remote_id:
     payload={'event':'toolkit','captured_at':int(time.time()),'report':report}
     body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'machine_name':platform.node(),'event':payload,'captured_at':int(time.time())}
     response=request_json(ORIGIN+'/api/api.php?action=agent-data',body,timeout=16)
     if not response.get('ok'):raise RuntimeError(response.get('error','Falha ao gravar resultado'))
     self._ack_remote_command(remote_id,'completed','Medições sincronizadas com sucesso')
    else:self.sync_data({'event':'toolkit','report':report,'captured_at':int(time.time())})
   except Exception as exc:
    self.root.after(0, lambda msg=str(exc): bind_theme(self.cmd_status, foreground='danger').configure(text='Erro: ' + msg[:120], fg=theme_color('danger')))
    if remote_id:self._ack_remote_command(remote_id,'failed',str(exc)[:350])
   finally:self.toolkit_busy=False
  threading.Thread(target=work,daemon=True).start()
 def log(self,msg,level='INFO'):
  stamp=time.strftime('%Y-%m-%d %H:%M:%S'); line=f'[{stamp}] [{level}] {msg}'
  try:
   self.dashboard_log.configure(state='normal'); self.dashboard_log.insert('end',line+'\n'); self.dashboard_log.see('end'); self.dashboard_log.configure(state='disabled')
   self.history.insert('end',line+'\n'); self.history.see('end')
  except Exception: pass
  if level in ('WARN','ERROR'): self.alerts.append(line)
 def ensure_local_agent(self):
  try:
   h=request_json(LOCAL_AGENT+'/api/health',timeout=2)
   self.log('Agente local conectado na porta 8787.'); return
  except Exception: pass
  script=ROOT/'arcoll_agent.py'
  if script.exists():
   try:
    flags=getattr(subprocess,'CREATE_NO_WINDOW',0) if platform.system()=='Windows' else 0
    self.agent_proc=subprocess.Popen([sys.executable,str(script),'--origin',ORIGIN],cwd=str(ROOT),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=flags)
    self.log('Serviço local iniciado automaticamente.')
   except Exception as e: self.log('Não foi possível iniciar serviço local: '+str(e),'ERROR')
 def _refresh_platform_config(self):
  if self.stop_event.is_set():return
  if ORIGIN and SITE_CODE and DOWNLOAD_KEY:
   def work():
    body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY}
    try:
     response=request_json(ORIGIN+'/api/api.php?action=agent-config',body,timeout=10)
     if isinstance(response,dict) and response.get('ok'):
      self.root.after(0,lambda data=response:self._apply_platform_config(data))
    except Exception as exc:
     self.root.after(0,lambda err=str(exc):self.log('Configuração remota indisponível: '+err[:110]))
   threading.Thread(target=work,daemon=True).start()
  self.root.after(60000,self._refresh_platform_config)
 def _apply_platform_config(self,data):
  # Parâmetros administrados por rede na plataforma, sem preferências locais.
  try:
   seconds=data.get('monitor_interval_seconds',data.get('monitor_refresh'))
   if seconds is not None:
    self.monitor_interval=max(1,min(60,int(seconds)))
   limit=data.get('alert_latency_ms',data.get('alert_latency'))
   if limit is not None:
    self.alert_threshold=max(10,min(2000,int(limit)))
  except (ValueError,TypeError):
   self.log('Configuração remota recebida com valores inválidos.','WARN')
  valid={'monitor','speed_test','devices','topology','connection','tools','diagnostic','alerts','reports','settings'}
  tabs=data.get('allowed_tabs')
  if isinstance(tabs,list):
   permitted={str(t) for t in tabs if str(t) in valid}
   self.allowed_remote_tabs=permitted
   mapping=[('monitor',self.server_page),('speed_test',self.speed_page),('devices',self.devices_page),('topology',self.map_page),('connection',self.connection_page)]
   for i,(key,frame) in enumerate(mapping):
    try:
     if key in permitted:self.nb.insert(i,frame)
     else:self.nb.hide(frame)
    except tk.TclError:pass
  if isinstance(data.get('device_overrides'),list):self._apply_device_overrides(data['device_overrides'])
  release=data.get('latest_release')
  if isinstance(release,dict) and re.fullmatch(r'[a-f0-9]{24}',str(release.get('id',''))):
   self.latest_release=release
   ver=str(release.get('version',''))
   if ver and ver!=AGENT_VERSION:
    self.log('Nova versão disponível no painel de gestão: '+ver[:16])
    self.log('Nova versão publicada: '+ver+'. Atualização disponível na plataforma.')
   else:pass
 def _apply_device_overrides(self,items):
  """Sincroniza identificações do portal pelo MAC, sem depender de IP."""
  try:
   with history_db() as db:
    groups={str(name):gid for gid,name in db.execute('SELECT id,name FROM topology_groups')}
    for entry in items[:500]:
     if not isinstance(entry,dict):continue
     mac=re.sub(r'[^0-9a-f]','',str(entry.get('mac','')).lower())
     if len(mac)!=12:continue
     key='mac:'+':'.join(mac[i:i+2] for i in range(0,12,2))
     group=str(entry.get('group') or '').strip()[:90]
     gid=None
     if group:
      if group not in groups:
       db.execute('INSERT INTO topology_groups(name,parent_id) VALUES(?,NULL)',(group,))
       groups[group]=db.execute('SELECT last_insert_rowid()').fetchone()[0]
      gid=groups[group]
     db.execute('UPDATE inventory SET custom_name=?,custom_type=?,group_id=?,pinned=?,hidden=? WHERE device_id=?',
       (str(entry.get('custom_name') or '')[:120],str(entry.get('custom_type') or '')[:50],gid,int(bool(entry.get('pinned'))),int(bool(entry.get('hidden'))),key))
   self.load_inventory()
   self.render_devices()
   self.draw_map()
  except Exception as exc:self.log('Identificações remotas: '+str(exc)[:160],'ERROR')
 def open_update_page(self):
  if not ORIGIN:return messagebox.showinfo('Atualização','Conecte este agente à plataforma para verificar atualizações.')
  release=self.latest_release
  if release and re.fullmatch(r'[a-f0-9]{24}',str(release.get('id',''))):
   webbrowser.open(ORIGIN+'/api/download.php?id='+urllib.parse.quote(str(release['id'])))
  else:webbrowser.open(ORIGIN+'/pages/clientes.php')
 def _poll_loop(self):
  if self.stop_event.is_set():return
  self._fetch_remote_command()
  self.root.after(3000,self._poll_loop)
 def _fetch_remote_command(self):
  if not ORIGIN or not SITE_CODE or not DOWNLOAD_KEY:
   self._set_remote_state('config_error','Instalação sem endereço HTTPS, código ou chave. Reinstale pelo painel de Redes.')
   return
  # Evita consultas concorrentes: uma resposta atrasada não pode consumir
  # uma nova tarefa nem ocultar a falha de autorização.
  if self._command_poll_busy:return
  self._command_poll_busy=True
  def work():
   try:
    body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY}
    response=request_json(ORIGIN+'/api/api.php?action=agent-command-poll',body,timeout=10)
    if not isinstance(response,dict) or not response.get('ok'):
     raise RuntimeError(str((response or {}).get('error') or 'Consulta de comandos não confirmada'))
    command=response.get('command')
    self.root.after(0,lambda command=command:self._remote_poll_ok(command))
   except urllib.error.HTTPError as exc:
    try:
     detail=json.loads(exc.read().decode('utf-8','replace'))
     reason=str(detail.get('error') or detail.get('message') or '')
    except Exception:reason=''
    msg=('HTTP '+str(exc.code)+': '+(reason or 'Falha de autorização ou consulta de comandos'))[:180]
    self.root.after(0,lambda msg=msg:self._set_remote_state('error',msg))
   except Exception as exc:
    msg=(type(exc).__name__+': '+str(exc))[:180]
    self.root.after(0,lambda msg=msg:self._set_remote_state('error',msg))
   finally:
    self.root.after(0,lambda:setattr(self,'_command_poll_busy',False))
  threading.Thread(target=work,daemon=True).start()
 def _set_remote_state(self,state,message=''):
  self._auth_status=state
  if state in ('error','config_error'):
   if message!=self._last_command_error:
    self._last_command_error=message
    self.log('Comandos remotos: '+message,'WARN')
   self.last_status='Sem comunicação de comandos: '+message
   bind_theme(self.status_label, foreground='danger').configure(text=self.last_status[:180], fg=theme_color('danger'))
  elif state=='ready':
   if self.last_status.startswith(('Verificando autorização','Sem comunicação de comandos','Autorização pendente')):
    self._operation_message('local','Agente autorizado · pronto para comandos',completed=True)
   self._last_command_error=''
 def _remote_poll_ok(self,command):
  self._set_remote_state('ready')
  if command:self._run_remote_command(command)
 def _run_remote_command(self,command):
  ident=int(command.get('id',0));name=str(command.get('command_name',''))
  if name in ('power_shutdown','power_restart','power_cancel'):
   self._request_power_action(ident,name,headless=bool(int(command.get('headless_mode') or 0)),role=str(command.get('requester_role') or ''));return
  if name=='agent_update':
   self.update_agent(remote_command_id=ident);return
  if name=='remote_assistance':
   self._request_remote_assistance(ident,headless=bool(int(command.get('headless_mode') or 0)),requester_role=str(command.get('requester_role') or ''),requester_name=str(command.get('requester_name') or ''));return
  if name=='terminal_diagnostic':
   self._run_terminal_diagnostic(ident,str(command.get('request_text') or ''));return
  handlers={'monitor_start':self.start_monitor,'monitor_pause':self.pause_monitor,'speed_test':self.run_speed_test,'scan_devices':self.scan_devices,'scan_map':self.scan_devices,'diagnostic':self.run_diagnostics,'toolkit':self.run_toolkit,'toolkit_traceroute':self.run_toolkit,'toolkit_services':self.run_toolkit,'toolkit_health':self.run_toolkit,'toolkit_troubleshooter':self.run_toolkit}
  result='Comando executado';status='completed'
  labels={'monitor_start':'iniciando monitoramento','monitor_pause':'pausando monitoramento','speed_test':'medindo velocidade da internet','scan_devices':'examinando dispositivos da rede','scan_map':'examinando a topologia da rede','diagnostic':'identificando a provedora e conexão'}
  self._operation_message('remote',labels.get(name,'executando '+name))
  targets={'monitor_start':self.server_page,'monitor_pause':self.server_page,'speed_test':self.speed_page,'scan_devices':self.devices_page,'scan_map':self.map_page,'diagnostic':self.connection_page}
  if name in targets:self.nb.select(targets[name])
  if name in ('speed_test','scan_devices','scan_map'):
   if name in ('scan_devices','scan_map') and self.scan_running:
    self._ack_remote_command(ident,'failed','Varredura já em andamento');return
   if name in ('scan_devices','scan_map'):
    self._remote_scan_command=ident
    self.nb.select(self.map_page if name=='scan_map' else self.devices_page)
  if name=='speed_test':
   if self.speed_busy:
    self._ack_remote_command(ident,'failed','Já existe um teste em andamento');return
   self._remote_speed_command=ident
   self.nb.select(self.speed_page)
  try:
   if name in QUICK_COMMANDS:
    self.nb.select(self.server_page);self.run_quick(name,ident);return
   if name not in handlers:raise ValueError('Comando não permitido')
   if name=='diagnostic':
    self.run_diagnostics(ident);return
   if name.startswith('toolkit'):
    handlers[name](ident,name[8:] if name.startswith('toolkit_') else None);return
   self._handling_remote=True
   try:handlers[name]()
   finally:self._handling_remote=False
   self.log('Comando remoto autorizado: '+name)
   self.sync_data({'event':'command_execution','command_name':name,'command_id':ident,'status':'started','origin':'platform','captured_at':int(time.time())})
  except Exception as exc:status='failed';result=str(exc)[:400]
  if status=='failed':self.sync_data({'event':'command_execution','command_name':name,'command_id':ident,'status':'failed','origin':'platform','message':result,'captured_at':int(time.time())})
  if (name not in ('speed_test','scan_devices','scan_map')) or status=='failed':
   if status=='failed' and name=='speed_test':self._remote_speed_command=None
   if status=='failed' and name in ('scan_devices','scan_map'):self._remote_scan_command=None
   self._ack_remote_command(ident,status,result)
 def _request_power_action(self,ident,name,headless=False,role=''):
  from arcoll_toolkit import schedule_power_action
  if name!='power_cancel' and not (headless and role=='administrator'):
   self.show_window()
   label='desligar' if name=='power_shutdown' else 'reiniciar'
   if not messagebox.askyesno('Controle de energia', 'O gestor solicitou '+label+' este computador. Salve seu trabalho. Deseja autorizar? A operação será agendada para daqui a 60 segundos.',parent=self.root):
    self._ack_remote_command(ident,'failed','Operação não autorizada no computador.');return
  def work():
   try:
    authorized=request_json(ORIGIN+'/api/api.php?action=agent-power-authorize',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'command_id':ident},timeout=8)
    if not authorized.get('ok') or authorized.get('command')!=name:raise RuntimeError('Autorização expirada. Solicite novamente.')
    if headless and role=='administrator' and not authorized.get('headless'):raise RuntimeError('O modo de servidor sem monitor foi desativado. Solicite novamente.')
    message=schedule_power_action(name)
    self._ack_remote_command(ident,'completed',message)
    self.root.after(0,lambda:messagebox.showinfo('Controle de energia',message,parent=self.root))
   except Exception as exc:
    self._ack_remote_command(ident,'failed',str(exc))
  threading.Thread(target=work,daemon=True).start()

 def _ask_remote_consent(self):
  """Confirmação local compacta: controlar, somente visualizar ou recusar."""
  result={'v':None}
  dlg=tk.Toplevel(self.root)
  dlg.title('Acesso remoto')
  bind_theme(dlg, background='alt').configure(bg=theme_color('alt'))
  dlg.resizable(False,False)
  dlg.attributes('-topmost',True)
  bind_theme(tk.Label(dlg, text='ACESSO REMOTO', font=('Segoe UI', 11, 'bold'), fg=theme_color('text'), bg=theme_color('alt')), foreground='text', background='alt').pack(padx=16, pady=(14, 12))
  row=bind_theme(tk.Frame(dlg, bg=theme_color('alt')), background='alt');row.pack(padx=12, pady=(0, 14))
  def choose(value):
   result['v']=value
   try:dlg.destroy()
   except tk.TclError:pass
  for label,value,color in (('PERMITIR','control',theme_color('success')),
                            ('VISUALIZAR','view',theme_color('accent')),
                            ('RECUSAR',None,theme_color('danger'))):
   tk.Button(row,text=label,command=lambda v=value:choose(v),
             font=('Segoe UI',9,'bold'),bg=color,fg='white',
             activebackground=color,activeforeground='white',
             relief='flat',bd=0,padx=11,pady=7,cursor='hand2').pack(side='left',padx=3)
  dlg.protocol('WM_DELETE_WINDOW',lambda:choose(None))
  dlg.update_idletasks()
  w,h=dlg.winfo_reqwidth(),dlg.winfo_reqheight()
  dlg.geometry(f'+{max(0,(dlg.winfo_screenwidth()-w)//2)}+{max(0,(dlg.winfo_screenheight()-h)//3)}')
  dlg.after(120000,lambda:choose(None) if dlg.winfo_exists() else None)
  try:dlg.grab_set();dlg.focus_force()
  except tk.TclError:pass
  self.root.wait_window(dlg)
  return result['v']

 def _detect_physical_display(self):
  """Estimativa best-effort de monitor físico conectado (não é garantia de segurança, só um alerta)."""
  if platform.system()!='Windows':return False
  try:
   import ctypes
   return int(ctypes.windll.user32.GetSystemMetrics(80))>0  # SM_CMONITORS
  except Exception:
   return False
 def _list_remote_monitors(self):
  """Lista os monitores do Windows em ordem estável para o acesso remoto."""
  if platform.system()!='Windows':
   width=max(1,self.root.winfo_screenwidth());height=max(1,self.root.winfo_screenheight())
   return [{'id':'1','label':'Tela 1','left':0,'top':0,'right':width,'bottom':height,'width':width,'height':height,'primary':True}]
  try:
   import ctypes
   from ctypes import wintypes
   user32=ctypes.windll.user32
   class RECT(ctypes.Structure):
    _fields_=[('left',wintypes.LONG),('top',wintypes.LONG),('right',wintypes.LONG),('bottom',wintypes.LONG)]
   class MONITORINFOEXW(ctypes.Structure):
    _fields_=[('cbSize',wintypes.DWORD),('rcMonitor',RECT),('rcWork',RECT),('dwFlags',wintypes.DWORD),('szDevice',wintypes.WCHAR*32)]
   monitors=[]
   CALLBACK=ctypes.WINFUNCTYPE(ctypes.c_int,wintypes.HANDLE,wintypes.HDC,ctypes.POINTER(RECT),wintypes.LPARAM)
   def _enum(hmon,_hdc,_lprc,_lparam):
    info=MONITORINFOEXW();info.cbSize=ctypes.sizeof(info)
    if user32.GetMonitorInfoW(hmon,ctypes.byref(info)):
     left,top,right,bottom=int(info.rcMonitor.left),int(info.rcMonitor.top),int(info.rcMonitor.right),int(info.rcMonitor.bottom)
     if right>left and bottom>top:
      monitors.append({'left':left,'top':top,'right':right,'bottom':bottom,'width':right-left,'height':bottom-top,'primary':bool(info.dwFlags&1),'device':str(info.szDevice or '').strip()})
    return 1
   user32.EnumDisplayMonitors(0,0,CALLBACK(_enum),0)
   if not monitors: raise RuntimeError('sem monitores enumerados')
   monitors.sort(key=lambda m:(0 if m.get('primary') else 1,m.get('top',0),m.get('left',0),m.get('device','')))
   for idx,monitor in enumerate(monitors,1):
    monitor['id']=str(idx)
    monitor['label']='Tela '+str(idx)
   return monitors
  except Exception:
   width=max(1,self.root.winfo_screenwidth());height=max(1,self.root.winfo_screenheight())
   return [{'id':'1','label':'Tela 1','left':0,'top':0,'right':width,'bottom':height,'width':width,'height':height,'primary':True}]

 def _request_remote_assistance(self, ident, headless=False, requester_role='', requester_name=''):
  """Acesso remoto: com consentimento local (padrão) ou, para servidores cadastrados pelo gestor
  como sem monitor/teclado/mouse, liberado automaticamente (a senha da plataforma já foi exigida
  no site antes de este comando chegar). Mesmo no modo automático a tela de aviso continua
  aparecendo caso exista um monitor — nada fica escondido de quem estiver fisicamente no local."""
  if platform.system()!='Windows':
   self._ack_remote_command(ident,'failed','Acesso remoto disponível apenas no Windows');return
  if getattr(self,'_remote_screen_active',False):
   self._ack_remote_command(ident,'failed','Outra sessão está em andamento');return
  # Confia apenas no papel fornecido pela API autenticada, nunca no usuário digitado localmente.
  # Servidores sem monitor continuam exigindo habilitação explícita e senha reconfirmada na plataforma.
  if headless and requester_role != 'administrator':
   self._ack_remote_command(ident,'failed','Acesso não assistido negado: solicitante sem permissão administrativa validada');return
  if headless:
   mode='control'
   self._ack_remote_command(ident,'running','Acesso automático administrativo autorizado: '+(requester_name[:60] or 'proprietário'))
   if self._detect_physical_display():
    self.sync_data({'event':'remote_access_monitor_detected','command_id':ident,'captured_at':int(time.time())})
    self.log('Acesso remoto automático: monitor físico detectado e reportado ao gestor.','WARN')
  else:
   self._ack_remote_command(ident,'running','Aguardando autorização local')
   self.show_window()
   mode=self._ask_remote_consent()
   if mode not in ('control','view'):
    self._ack_remote_command(ident,'failed','Acesso recusado no computador de destino');return
  self._remote_screen_active=True
  self._remote_screen_stop=threading.Event()
  self._remote_control=threading.Event()
  self._remote_activity=0.0
  if mode=='control':self._remote_control.set()
  stop_event=self._remote_screen_stop;control_event=self._remote_control
  # Indicador compacto, sempre visível enquanto houver compartilhamento.
  # Um clique abre os controles locais; a sessão nunca fica sem sinalização.
  banner=tk.Toplevel(self.root)
  banner.title('Arcoll · Acesso remoto ativo')
  bind_theme(banner, background='alt').configure(bg=theme_color('alt'));banner.resizable(False, False)
  banner.overrideredirect(True)
  banner.attributes('-topmost',True)
  bw,bh=38,38
  banner.geometry(f'{bw}x{bh}+{max(0,banner.winfo_screenwidth()-bw-12)}+12')
  control_panel=None
  def close_panel():
   nonlocal control_panel
   if control_panel is not None:
    try:control_panel.destroy()
    except tk.TclError:pass
    control_panel=None
  def toggle_control():
   if control_event.is_set():control_event.clear()
   else:control_event.set()
   update_indicator()
   close_panel()
  def end_access():
   stop_event.set()
   close_panel()
  def update_indicator():
   is_control=control_event.is_set()
   icon_btn.configure(text='◉', fg=theme_color('warning') if is_control else theme_color('success'), activeforeground=theme_color('warning') if is_control else theme_color('success'))
  def open_controls():
   nonlocal control_panel
   if control_panel is not None:
    close_panel();return
   control_panel=tk.Toplevel(self.root)
   control_panel.title('Controle do compartilhamento')
   bind_theme(control_panel, background='alt').configure(bg=theme_color('alt'));control_panel.resizable(False, False)
   control_panel.attributes('-topmost',True)
   pw,ph=248,120
   x=max(0,banner.winfo_screenwidth()-pw-12)
   control_panel.geometry(f'{pw}x{ph}+{x}+54')
   mode_text='Controle remoto ativo' if control_event.is_set() else 'Compartilhamento: somente visualização'
   bind_theme(tk.Label(control_panel, text=mode_text, font=('Segoe UI', 9, 'bold'), bg=theme_color('alt'), fg=theme_color('text')), background='alt', foreground='text').pack(pady=(12, 8))
   actions=bind_theme(tk.Frame(control_panel, bg=theme_color('alt')), background='alt');actions.pack()
   toggle_text='Pausar controle' if control_event.is_set() else 'Permitir controle'
   bind_theme(tk.Button(actions, text=toggle_text, command=toggle_control, font=('Segoe UI', 9), bg=theme_color('accent'), fg='white', relief='flat', padx=8, pady=6), background='accent').pack(side='left', padx=3)
   bind_theme(tk.Button(actions, text='Encerrar acesso', command=end_access, font=('Segoe UI', 9), bg=theme_color('danger'), fg='white', relief='flat', padx=8, pady=6), background='danger').pack(side='left', padx=3)
   control_panel.protocol('WM_DELETE_WINDOW',close_panel)
  icon_btn=bind_theme(tk.Button(banner, text='◉', command=open_controls, font=('Segoe UI', 17, 'bold'), bg=theme_color('alt'), fg=theme_color('warning'), activebackground=theme_color('alt'), activeforeground=theme_color('warning'), relief='flat', bd=0, cursor='hand2'), background='alt', foreground='warning', activebackground='alt', activeforeground='warning')
  icon_btn.pack(fill='both',expand=True)
  update_indicator()
  self._remote_screen_banner=banner
  creds={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY}
  base_url=ORIGIN+'/api/remote-screen.php?command_id='+str(ident)+'&action='
  remote_view={'selected_id':'','bounds':None,'monitors':[]}
  remote_view_lock=threading.Lock()
  def _remote_monitor_payload(items):
   return [{'id':str(item.get('id') or ''),'label':str(item.get('label') or ''),'width':int(item.get('width') or 0),'height':int(item.get('height') or 0),'primary':bool(item.get('primary'))} for item in items]
  def _choose_monitor(monitors, wanted=''):
   chosen=None
   for item in monitors:
    if wanted and str(item.get('id'))==str(wanted):
     chosen=item;break
   if chosen is None:
    for item in monitors:
     if item.get('primary'):
      chosen=item;break
   return chosen or (monitors[0] if monitors else None)
  def input_loop():
   rin=None
   try:
    RemoteInput.thread_dpi_aware()
    rin=RemoteInput();errors=0
    while not stop_event.is_set() and not self.stop_event.is_set():
     if not control_event.is_set():
      if rin.has_pressed():rin.release_all()
      stop_event.wait(0.4);continue
     try:
      reply=request_json(base_url+'poll',dict(creds,control=True),timeout=6);errors=0
      if reply.get('stop'):stop_event.set();break
      with remote_view_lock:
       if reply.get('selected_monitor'):remote_view['selected_id']=str(reply.get('selected_monitor') or '')
       bounds=tuple(remote_view['bounds']) if remote_view.get('bounds') else None
      events=reply.get('events') or []
      for ev in events:
       if not control_event.is_set():break
       try:rin.apply(ev,bounds=bounds)
       except Exception as exc:self.last_error='Controle remoto: '+str(exc)[:160]
      if events:self._remote_activity=time.time()
     except Exception as exc:
      errors+=1;self.last_error='Controle remoto: '+str(exc)[:160]
      if errors>=8:break
      stop_event.wait(min(2.0,0.3*errors))
   except Exception as exc:
    self.last_error='Controle remoto: '+str(exc)[:160]
   finally:
    try:
     if rin:rin.release_all()
    except Exception:pass
  def capture_loop():
   import io, json
   try:
    from PIL import ImageGrab, ImageDraw
    RemoteInput.thread_dpi_aware()
    last_sig=None;last_sent=0.0;last_ctl=None;errors=0;server_selected='';last_monitor_id='';last_meta_sig='';stream_quality='balanced'
    while not stop_event.is_set() and not self.stop_event.is_set():
     try:
      ctl=control_event.is_set()
      monitors=self._list_remote_monitors()
      current=_choose_monitor(monitors,server_selected)
      if current is None: raise RuntimeError('Nenhuma tela disponível para o acesso remoto')
      bbox=(int(current['left']),int(current['top']),int(current['right']),int(current['bottom']))
      with remote_view_lock:
       remote_view['selected_id']=str(current.get('id') or '')
       remote_view['bounds']=bbox
       remote_view['monitors']=monitors
      frame=ImageGrab.grab(bbox=bbox,all_screens=True).convert('RGB')
      sw,sh=frame.size
      max_w,max_h,jpeg_quality={'low':(960,600,40),'balanced':(1280,800,55),'high':(1600,1000,75)}.get(stream_quality,(1280,800,55))
      scale=min(1.0,float(max_w)/max(1,sw),float(max_h)/max(1,sh))
      if scale<1.0:frame=frame.resize((max(1,int(sw*scale)),max(1,int(sh*scale))))
      cur=RemoteInput.cursor_pos()
      if cur and bbox[0]<=cur[0]<bbox[2] and bbox[1]<=cur[1]<bbox[3]:
       cx,cy=int((cur[0]-bbox[0])*scale),int((cur[1]-bbox[1])*scale)
       ImageDraw.Draw(frame).polygon([(cx,cy),(cx,cy+17),(cx+4,cy+13),(cx+7,cy+20),(cx+10,cy+19),(cx+7,cy+12),(cx+12,cy+12)],fill='white',outline='black')
      sig=hashlib.md5(frame.tobytes()).digest()
      meta=_remote_monitor_payload(monitors)
      meta_sig=json.dumps(meta,sort_keys=True,separators=(',',':'))
      now=time.time()
      if sig==last_sig and ctl==last_ctl and now-last_sent<2.5 and last_monitor_id==str(current.get('id') or '') and meta_sig==last_meta_sig:
       stop_event.wait(0.15 if ctl else 0.5);continue
      payload=dict(creds,control=ctl,selected_monitor=str(current.get('id') or ''))
      if meta and (meta_sig!=last_meta_sig or now-last_sent>8 or not last_sent):
       payload['monitors']=meta
      if sig==last_sig and last_sent and ctl==last_ctl and last_monitor_id==str(current.get('id') or ''):
       payload['keepalive']=True
      else:
       data=b''
       for quality in (jpeg_quality,40,28,20):
        buffer=io.BytesIO();frame.save(buffer,format='JPEG',quality=quality,optimize=False);data=buffer.getvalue()
        if len(data)<=360000:break
       payload['image']=base64.b64encode(data).decode('ascii')
      response=request_json(base_url+'frame',payload,timeout=10)
      errors=0;last_sent=time.time();last_ctl=ctl;last_monitor_id=str(current.get('id') or '')
      if 'monitors' in payload:last_meta_sig=meta_sig
      if not payload.get('keepalive'):last_sig=sig
      server_selected=str(response.get('selected_monitor') or last_monitor_id)
      stream_quality=str(response.get('quality') or 'balanced')
      if not response.get('ok') or response.get('stop'):break
     except Exception as exc:
      errors+=1;self.last_error='Tela remota: '+str(exc)[:160]
      if errors>=5:break
      stop_event.wait(1.0);continue
     active=time.time()-getattr(self,'_remote_activity',0.0)<1.5
     stop_event.wait(0.12 if (ctl and active) else (0.3 if ctl else 1.0))
   except Exception as exc:
    self.last_error='Tela remota: '+str(exc)[:160]
   finally:
    stop_event.set()
    self._remote_screen_active=False
    try:self.root.after(0,lambda:(close_panel(),banner.destroy() if banner.winfo_exists() else None))
    except Exception:pass
    self._ack_remote_command(ident,'completed','Acesso remoto encerrado')
  threading.Thread(target=input_loop,daemon=True).start()
  threading.Thread(target=capture_loop,daemon=True).start()

 def _run_terminal_diagnostic(self,ident,raw):
  """Executa apenas diagnósticos autorizados; transmite a saída ao painel por trechos confirmados."""
  try:
   if platform.system()!='Windows':raise ValueError('Diagnósticos CMD disponíveis apenas no Windows')
   argv=arcoll_diagnostic_argv(raw)
  except ValueError as exc:
   if ident is not None:self._ack_remote_command(ident,'failed',str(exc))
   else:self._console_write('','Erro: '+str(exc),'bad')
   return
  self.nb.select(self.server_page)
  if ident is not None:self._console_write('', 'C:\\ARCOLL> '+raw,'info')
  bind_theme(self.cmd_status, foreground='accent').configure(text='CMD diagnóstico remoto: ' + raw, fg=theme_color('accent'))
  self._operation_message('remote','executando diagnóstico '+raw)
  def work():
   proc=None; timer=None
   live_id=ident
   if live_id is None and ORIGIN and SITE_CODE and DOWNLOAD_KEY:
    try:
     response=request_json(ORIGIN+'/api/api.php?action=agent-terminal-local-start',{
      'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,
      'request_text':raw},timeout=8)
     if not response.get('ok') or not response.get('command_id'):
      raise RuntimeError(response.get('error','Servidor não confirmou o terminal local'))
     live_id=int(response['command_id'])
    except Exception as exc:
     self.root.after(0,lambda msg=str(exc):self._console_write('Sincronização','A saída local não será transmitida: '+msg,'bad'))
   output_queue=queue.Queue()
   pending=''; offset=0; emitted=0; last_upload=time.monotonic()
   truncated=False; cancelled=False; timed_out=threading.Event()
   last_error=''; max_errors=0
   def announce(message,kind='bad'):
    self.root.after(0,lambda msg=message,k=kind:self._console_write('Sincronização',msg,k))
   def reader():
    try:
     while True:
      # read(1024) com bufsize=0 retorna assim que chegam bytes do Windows,
      # independentemente de haver um fim de linha ou do comando ter terminado.
      raw_bytes=proc.stdout.read(1024)
      if not raw_bytes:break
      output_queue.put(raw_bytes)
    except Exception as exc:
     output_queue.put(('erro',str(exc)))
    finally:output_queue.put(None)
   def send_chunk(chunk):
    nonlocal offset,cancelled,last_error,max_errors
    size=len(chunk.encode('utf-8'))
    if live_id is None:
     offset+=size;return True
    for attempt in range(3):
     try:
      result=request_json(ORIGIN+'/api/api.php?action=agent-command-output',{
       'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,
       'command_id':live_id,'offset':offset,'chunk':chunk},timeout=6)
      expected=int(result.get('next_offset',-1))
      if result.get('received') and expected==offset+size:
       offset=expected;max_errors=0;last_error='';return True
      if result.get('status') in ('failed','expired','completed'):
       cancelled=True;last_error='Comando encerrado na plataforma';return False
      if expected==offset+size:
       # Resposta de reenvio: trecho já estava gravado no MySQL.
       offset=expected;max_errors=0;last_error='';return True
      last_error='Resposta de sequência inesperada (offset servidor: '+str(expected)+')'
      if expected>=0 and expected!=offset:
       # Nunca adicionar novamente linhas já gravadas; não adivinhar posição.
       break
     except Exception as exc:
      last_error=str(exc)[:180]
     if attempt<2:time.sleep(0.25*(attempt+1))
    max_errors+=1
    if max_errors in (1,4,8):announce('Falha no envio da saída ao painel: '+last_error)
    return False
   def flush():
    nonlocal pending,last_upload
    while pending and not cancelled:
     chunk=pending[:900] # UTF-8 até 3.600 B; ajuste defensivo abaixo.
     while len(chunk.encode('utf-8'))>3000:chunk=chunk[:max(1,len(chunk)//2)]
     if not send_chunk(chunk):return False
     pending=pending[len(chunk):]
     last_upload=time.monotonic()
    return True
   try:
    options={'stdout':subprocess.PIPE,'stderr':subprocess.STDOUT,'stdin':subprocess.DEVNULL,'bufsize':0,'shell':False}
    if platform.system()=='Windows':options['creationflags']=getattr(subprocess,'CREATE_NO_WINDOW',0x08000000)
    proc=subprocess.Popen(argv,**options)
    self._active_diagnostic_proc=proc
    decoder=codecs.getincrementaldecoder('oem')('replace')
    def on_timeout():
     timed_out.set()
     if proc and proc.poll() is None:
      try:proc.kill()
      except OSError:pass
    timer=threading.Timer(80,on_timeout);timer.daemon=True;timer.start()
    threading.Thread(target=reader,daemon=True).start()
    done=False
    while not done and not cancelled:
     try:piece=output_queue.get(timeout=0.18)
     except queue.Empty:piece=b''
     if piece is None:
      done=True
      text=decoder.decode(b'',final=True)
     elif isinstance(piece,tuple):
      raise RuntimeError('Falha na leitura do processo: '+piece[1])
     else:text=decoder.decode(piece,final=False) if piece else ''
     if text:
      remaining=max(0,30000-emitted)
      if len(text)>remaining:
       text=text[:remaining];truncated=True
      if text:
       emitted+=len(text);pending+=text
       self.root.after(0,lambda msg=text:self._console_write('',msg,'raw'))
     if pending and (done or len(pending)>=900 or time.monotonic()-last_upload>=0.28):
      if not flush() and max_errors>=8:break
     if truncated:
      if proc.poll() is None:proc.kill()
      break
    if proc.poll() is None and (cancelled or max_errors>=8):proc.kill()
    code=proc.wait(timeout=5)
    if truncated:pending+='\n[Saída limitada a 30 mil caracteres]\n'
    if timed_out.is_set():pending+='\n[Tempo limite de 80 segundos]\n'
    # Completar a transmissão antes de confirmar o comando como concluído.
    for _ in range(3):
     if not pending or cancelled:break
     if flush():break
     time.sleep(0.5)
    transferred=not pending and emitted>0 and not max_errors
    ok=code==0 and not truncated and not timed_out.is_set() and not cancelled and transferred
    if cancelled:summary='Interrompido pelo operador (Ctrl+C)'
    elif not transferred:summary='Falha na sincronização da saída do CMD: '+(last_error or 'nenhum resultado transferido')
    else:summary=('Concluído' if ok else 'Falha')+f' (código {code})'
    self.root.after(0,lambda msg=summary,k=('ok' if ok else 'bad'):self._console_write('CMD',msg,k))
    self.root.after(0, lambda msg=summary, k=theme_color('success') if ok else theme_color('danger'): self.cmd_status.configure(text=msg, fg=k))
    if not cancelled and live_id is not None:self._ack_remote_command(live_id,'completed' if ok else 'failed',summary[:350])
   except Exception as exc:
    error='Falha ao executar diagnóstico: '+str(exc)[:320]
    self.root.after(0,lambda msg=error:self._console_write('CMD',msg,'bad'))
    if live_id is not None:self._ack_remote_command(live_id,'failed',error)
   finally:
    if timer:timer.cancel()
    if self._active_diagnostic_proc is proc:self._active_diagnostic_proc=None
    if proc and proc.poll() is None:
     try:proc.kill()
     except OSError:pass
  threading.Thread(target=work,daemon=True).start()

 def _ack_remote_command(self,ident,status,result):
  def ack():
   try:
    response=request_json(ORIGIN+'/api/api.php?action=agent-command-ack',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'command_id':ident,'status':status,'result':result},timeout=8)
    self.root.after(0,lambda:self._operation_message('remote',result if status=='completed' else 'Falha: '+result,completed=status=='completed',error=status!='completed'))
   except Exception as exc:self.root.after(0,lambda message=str(exc):self._operation_message('remote','Falha ao confirmar resultado: '+message,error=True))
  threading.Thread(target=ack,daemon=True).start()
 def _operation_message(self, origin, message, completed=False, error=False):
  prefix='Solicitação remota' if origin=='remote' else 'Execução local'
  suffix=' · Resultado enviado ao solicitante remoto' if completed and origin=='remote' else ''
  line=prefix+' - '+str(message)+suffix
  self.status_label.configure(text=line[:180], fg=theme_color('danger') if error else theme_color('success') if completed else theme_color('accent'))
  self.last_status=line
 def refresh_status(self):
  if not ORIGIN or not SITE_CODE:
   self._set_remote_state('config_error','Endereço HTTPS ou código de rede ausente')
   return
  def work():
   try:
    r=request_json(ORIGIN+'/api/api.php?action=agent-poll',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE},timeout=8)
    state=str(r.get('status') or '') if isinstance(r,dict) else ''
    if state=='approved':
     self.root.after(0,lambda:self._set_remote_state('ready'))
    else:
     reason=str(r.get('message') or r.get('error') or 'Agente sem aprovação') if isinstance(r,dict) else 'Resposta inválida'
     self.root.after(0,lambda reason=reason:self._set_remote_state('error','Autorização pendente: '+reason))
   except Exception as exc:
    # A falha é exibida no rodapé, sem expor credenciais da instalação.
    reason=(type(exc).__name__+': '+str(exc))[:140]
    self.root.after(0,lambda reason=reason:self._set_remote_state('error','Verificação de autorização: '+reason))
  threading.Thread(target=work,daemon=True).start()
 def set_status(self,msg,color):
  self._operation_message('local', msg, error=color == theme_color('danger'))
  self.log(msg, 'INFO' if color == theme_color('success') else 'WARN' if color == theme_color('warning') else 'ERROR')
 def start_monitor(self): self._operation_message('remote' if getattr(self,'_handling_remote',False) else 'local','monitoramento iniciado',completed=True); self.monitor_on=True; self._realtime.set_paused(False) if self._realtime else None; bind_theme(self.live_badge, background='alt', foreground='success').configure(text='◉  MONITORAMENTO CONTÍNUO', bg=theme_color('alt'), fg=theme_color('success')); self.log('Monitoramento iniciado.'); self.sync_data({'event': 'monitor_state', 'monitoring': True, 'origin': 'windows', 'captured_at': int(time.time())})
 def pause_monitor(self): self._operation_message('remote' if getattr(self,'_handling_remote',False) else 'local','monitoramento pausado',completed=True); self.monitor_on=False; self._realtime.set_paused(True) if self._realtime else None; bind_theme(self.live_badge, background='alt', foreground='warning').configure(text='Ⅱ  MONITORAMENTO PAUSADO', bg=theme_color('alt'), fg=theme_color('warning')); self.log('Monitoramento pausado.', 'WARN'); self.sync_data({'event': 'monitor_state', 'monitoring': False, 'origin': 'windows', 'captured_at': int(time.time())})
 def _network_byte_totals(self):
  """Bytes reais da máquina com agente; não efetua teste de velocidade."""
  try:
   import psutil
   n=psutil.net_io_counters()
   if n:return (int(n.bytes_recv),int(n.bytes_sent))
  except (ImportError,AttributeError,OSError):pass
  if platform.system()=='Windows':
   try:
    output=_arc_hidden_run(['netstat','-e'],capture_output=True,text=True,timeout=3,errors='replace').stdout
    for line in output.splitlines():
     if re.match(r'^\s*(?:Bytes|Octetos)\s+',line,re.I):
      numbers=re.findall(r'\d+',line)
      if len(numbers)>=2:return (int(numbers[0]),int(numbers[1]))
   except Exception:pass
  return None
 def _network_rates(self,now):
  counters=self._network_byte_totals()
  previous=getattr(self,'_traffic_previous',None)
  self._traffic_previous=(now,counters) if counters else None
  if not previous or not counters or previous[1] is None:return (None,None)
  elapsed=now-previous[0]
  if elapsed<=0:return (None,None)
  received=counters[0]-previous[1][0];sent=counters[1]-previous[1][1]
  if received<0 or sent<0:return (None,None)
  return (round(received*8/elapsed/1_000_000,3),round(sent*8/elapsed/1_000_000,3))
 def monitor_tick(self):
  if self.stop_event.is_set(): return
  if self.monitor_on:
   def work():
    t=time.time(); host=gateway_ip() or '1.1.1.1'; latency=None
    try:
     if platform.system()=='Windows': cmd=['ping','-n','1','-w','900',host]
     else: cmd=['ping','-c','1','-W','1',host]
     out=_arc_hidden_run(cmd,capture_output=True,text=True,timeout=3,errors='ignore').stdout
     m=re.search(r'(?:time|tempo)[=<]\s*([\d.,]+)\s*ms',out,re.I)
     if m: latency=float(m.group(1).replace(',','.'))
    except Exception: pass
    down,up=self._network_rates(time.time()); sample={'time':time.time(),'latency':latency,'host':host,'download_mbps':down,'upload_mbps':up}; self.samples.append(sample); self.samples=self.samples[-2000:]; self._save_sample(sample)
    if (latency is None or latency>self.alert_threshold) and time.time()-self.last_alert_sent>=120:
     self.last_alert_sent=time.time();self.sync_data({'event':'alert','kind':'gateway_unreachable' if latency is None else 'high_latency','latency_ms':latency,'threshold_ms':self.alert_threshold,'gateway':host})
    if latency is None: self.root.after(0,lambda:self.log('Gateway sem resposta ao ping.','WARN'))
    elif latency > self.alert_threshold: self.root.after(0,lambda:self.log(f'Latência alta detectada: {latency:.1f} ms','WARN'))
    self.root.after(0,lambda:self.update_metrics(latency))
    if len(self.samples)%5==0: self.sync_data({'event':'telemetry','sample':sample,'summary':{'loss_percent':round(sum(1 for x in self.samples[-20:] if x['latency'] is None)/max(1,len(self.samples[-20:]))*100,2),'sample_count':len(self.samples)}})
   threading.Thread(target=work,daemon=True).start()
  self.root.after(max(1000,int(self.monitor_interval*1000)),self.monitor_tick)
 def update_metrics(self,latency):
  self.latency='sem resposta' if latency is None else f'{latency:.1f} ms'; self.metric_vars['latency'].configure(text=self.latency); self.metric_vars['loss'].configure(text=f'{sum(1 for s in self.samples[-20:] if s["latency"] is None) / max(1,len(self.samples[-20:]))*100:.0f}%'); self.metric_vars['devices'].configure(text=str(len(self.devices))); self.metric_vars['uptime'].configure(text='Ativo' if self.monitor_on and not self.stop_event.is_set() else 'Pausado'); self.draw_chart()
 def _chart_click(self,event):
  if not self.samples:return
  data=self.samples[-90:];left=49;right=53;width=max(self.chart.winfo_width(),300)-left-right
  idx=max(0,min(len(data)-1,round((event.x-left)/max(1,width)*max(1,len(data)-1))))
  sample=data[idx];stamp=time.strftime('%d/%m/%Y %H:%M:%S',time.localtime(sample['time']))
  messagebox.showinfo('Detalhes da medição · '+stamp,'\n'.join((f"Latência: {sample.get('latency') if sample.get('latency') is not None else 'Sem resposta'} ms",f"Download: {float(sample.get('download_mbps') or 0):.2f} Mbps",f"Upload: {float(sample.get('upload_mbps') or 0):.2f} Mbps",f"Perda de resposta: {'Sim' if sample.get('latency') is None else 'Não'}")),parent=self.root)
 def _trade_report(self):
  dialog=tk.Toplevel(self.root);dialog.title('Relatório de monitoramento');bind_theme(dialog, background='bg').configure(bg=theme_color('bg'));dialog.geometry('400x240');dialog.transient(self.root);dialog.grab_set()
  bind_theme(tk.Label(dialog, text='Filtrar amostras por data e hora', fg=theme_color('text'), bg=theme_color('bg'), font=('Segoe UI', 11, 'bold')), foreground='text', background='bg').pack(pady=12)
  inputs=[]
  for label,value in [('Início',time.strftime('%d/%m/%Y 00:00')),('Fim',time.strftime('%d/%m/%Y %H:%M'))]:
   line=bind_theme(tk.Frame(dialog, bg=theme_color('bg')), background='bg');line.pack(fill='x', padx=20, pady=4);bind_theme(tk.Label(line, text=label, fg=theme_color('text'), bg=theme_color('bg'), width=8), foreground='text', background='bg').pack(side='left');entry=tk.Entry(line);entry.insert(0, value);entry.pack(side='left', fill='x', expand=True);inputs.append(entry)
  def export():
   try:
    from datetime import datetime
    begin=datetime.strptime(inputs[0].get(),'%d/%m/%Y %H:%M').timestamp();end=datetime.strptime(inputs[1].get(),'%d/%m/%Y %H:%M').timestamp()
    if end<begin:raise ValueError('O fim deve ser posterior ao início')
    matches=[x for x in self.samples if begin<=x['time']<=end]
    if not matches:messagebox.showwarning('Relatório','Não há amostras no período.',parent=dialog);return
    path=filedialog.asksaveasfilename(parent=dialog,defaultextension='.csv',filetypes=[('CSV','*.csv')],initialfile='arcoll_monitoramento.csv')
    if not path:return
    with open(path,'w',encoding='utf-8-sig',newline='') as fh:
     w=csv.writer(fh,delimiter=';');w.writerow(['Data/hora','Latência (ms)','Download (Mbps)','Upload (Mbps)','Perda'])
     for x in matches:w.writerow([time.strftime('%d/%m/%Y %H:%M:%S',time.localtime(x['time'])),x.get('latency'),x.get('download_mbps'),x.get('upload_mbps'),int(x.get('latency') is None)])
    dialog.destroy();messagebox.showinfo('Relatório',f'{len(matches)} medições exportadas.',parent=self.root)
   except Exception as e:messagebox.showerror('Relatório',str(e),parent=dialog)
  bind_theme(tk.Button(dialog, text='Exportar CSV', command=export, bg=theme_color('accent'), fg='white', relief='flat', padx=18, pady=6), background='accent').pack(pady=14)
 def draw_chart(self):
  if not hasattr(self,'chart'):return
  c=self.chart;c.delete('all');w=max(c.winfo_width(),300);h=max(c.winfo_height(),150);left=49;right=53;top=36;bottom=41
  samples=self.samples[-90:];chart_h=max(20,h-top-bottom);chart_w=max(20,w-left-right)
  max_latency=max([10.0]+[float(x['latency']) for x in samples if isinstance(x.get('latency'),(int,float))])*1.15
  max_traffic=max([1.0]+[float(x.get(k)) for x in samples for k in ('download_mbps','upload_mbps') if isinstance(x.get(k),(int,float))])*1.15
  for i in range(5):
   y=top+i*chart_h/4;bind_canvas(c, c.create_line(left, y, w - right, y, fill=theme_color('border'), dash=(2, 3)), fill='border');bind_canvas(c, c.create_text(left - 7, y, text=f'{max_latency * (4 - i) / 4:.0f}', fill=theme_color('muted'), anchor='e', font=('Segoe UI', 8)), fill='muted');bind_canvas(c, c.create_text(w - right + 7, y, text=f'{max_traffic * (4 - i) / 4:.1f}', fill=theme_color('success'), anchor='w', font=('Segoe UI', 8)), fill='success')
  style=self.trade_style.get() if hasattr(self,'trade_style') else 'Linhas'
  for k,col in [('latency',theme_color('accent')),('download_mbps',theme_color('success')),('upload_mbps',theme_color('accent')),('loss',theme_color('danger'))]:
   if not self.trade_flags[k].get():continue
   previous=None
   for i,sample in enumerate(samples):
    v=(100 if sample.get('latency') is None else 0) if k=='loss' else sample.get(k)
    x=left+(i/max(1,len(samples)-1))*chart_w
    if not isinstance(v,(int,float)):
     previous=None;continue
    scale=100 if k=='loss' else (max_latency if k=='latency' else max_traffic)
    y=top+chart_h-float(v)/scale*chart_h
    if style=='Barras':
     c.create_rectangle(x-2,y,x+2,top+chart_h,fill=col,outline='')
    else:
     if previous:
      c.create_line(previous[0],previous[1],x,y,fill=col,width=2.4)
      if style=='Área':c.create_line(x,y,x,top+chart_h,fill=col,width=1)
     c.create_oval(x-1.5,y-1.5,x+1.5,y+1.5,fill=col,outline='')
    previous=(x,y)
  if samples:
   for i in range(5):
    idx=round(i*(len(samples)-1)/4);ss=samples[idx];xx=left+i*chart_w/4
    bind_canvas(c, c.create_text(xx, h - 14, text=time.strftime('%H:%M:%S', time.localtime(ss['time'])), fill=theme_color('muted'), font=('Consolas', 8)), fill='muted')
   peaks=[]
   for key,lab in [('download_mbps','↓'),('upload_mbps','↑')]:
    good=[x for x in samples if isinstance(x.get(key),(int,float))]
    if good:
     p=max(good,key=lambda x:x[key]);peaks.append(f"{lab} {p[key]:.2f} Mbps às {time.strftime('%H:%M:%S',time.localtime(p['time']))}")
   bind_canvas(c, c.create_text(w - right, 27, text='  |  '.join(peaks), fill=theme_color('muted'), anchor='e', font=('Segoe UI', 8)), fill='muted')
 def scan_devices(self):
  if self.scan_running:return
  self._operation_message('remote' if getattr(self,'_remote_scan_command',None) else 'local','examinando dispositivos da rede')
  self.scan_running=True
  self._progress_operation(None,'Varredura: consultando o agente local…')
  self.scan_status.configure(text='Examinando a rede local…')
  self.log('Varredura local solicitada pelo operador.')
  def work():
   try:
    data=local_api('/api/devices?refresh=1',timeout=120)
    if not isinstance(data,dict) or not isinstance(data.get('devices'),list):
     raise RuntimeError('Resposta inválida do serviço de inventário')
    result=data['devices']
    self._progress_operation(72,'Varredura: organizando inventário…')
    self.merge_inventory(result)
    if self._realtime:self._realtime.sync_inventory(result)
    self.root.after(0,self.render_devices)
    self.root.after(0,self.draw_map)
    self.root.after(0,lambda n=len(result):self.scan_status.configure(text=f'Varredura concluída: {n} dispositivo(s) encontrado(s).'))
    self.root.after(0,lambda n=len(result):self.log(f'Varredura concluída: {n} dispositivo(s).'))
    scan_command=getattr(self,'_remote_scan_command',None)
    self._remote_scan_command=None
    self._progress_operation(90,'Varredura: sincronizando inventário…')
    self.send_inventory_snapshot(scan_command)
   except Exception as exc:
    error='Varredura não concluída: '+str(exc)[:220]
    self.root.after(0,lambda msg=error:self.scan_status.configure(text=msg))
    self.root.after(0,lambda msg=error:self.log(msg,'ERROR'))
    scan_command=getattr(self,'_remote_scan_command',None)
    self._remote_scan_command=None
    self._progress_operation(finished=True,message=error)
    if scan_command:self._ack_remote_command(scan_command,'failed',error)
    # Não transformar todos os registros em offline quando o scanner falhar.
   finally:
    self.scan_running=False
  threading.Thread(target=work,daemon=True).start()
 def _start_realtime(self):
  """Inicia o monitor nativo sem realizar sweep na sub-rede."""
  if platform.system()!='Windows' or RealtimeNetworkWatcher is None:
   self.log('Observação nativa indisponível nesta instalação.', 'WARN')
   return
  try:
   watcher=RealtimeNetworkWatcher(self._realtime_events, log=lambda msg,level='INFO':self.root.after(0,lambda m=msg,l=level:self.log(m,l)))
   watcher.seed(self.devices)
   watcher.start()
   watcher.set_paused(not self.monitor_on)
   self._realtime=watcher
  except Exception as exc:
   self.log('Monitor nativo indisponível: '+str(exc)[:180], 'WARN')

 def _realtime_events(self, events):
  """Recebe eventos do observador; atualiza SQLite e publica apenas mudanças."""
  if self.stop_event.is_set() or not self.monitor_on:return
  now=time.time();changed=[]
  try:
   with history_db() as db:
    db.execute('PRAGMA busy_timeout=15000')
    for ev in events[:40]:
     mac=str(ev.get('mac') or '');key=self.device_id({'mac':mac})
     if not key:continue
     typ=str(ev.get('type') or '')
     if typ not in ('new','online','offline','ip_change','update'):continue
     row=db.execute('SELECT payload,first_seen,last_seen,last_change,online FROM inventory WHERE device_id=?',(key,)).fetchone()
     prior=json.loads(row[0]) if row else {}
     was_online=bool(row[4]) if row else False
     online=typ!='offline'
     name=str(ev.get('name') or prior.get('name') or ev.get('ip') or '')[:120]
     device=dict(prior,mac=mac,ip=str(ev.get('ip') or prior.get('ip') or '')[:45],name=name,type=str(ev.get('dtype') or prior.get('type') or 'device')[:50],source='windows_realtime')
     if row and was_online==online and str(prior.get('ip') or '')==device['ip'] and (typ!='update' or prior.get('name')==name):continue
     first=row[1] if row else now
     last=now if online else (row[2] if row else now)
     change=now if not row or was_online!=online or prior.get('ip')!=device['ip'] else (row[3] if row else now)
     db.execute('INSERT INTO inventory (device_id,payload,first_seen,last_seen,last_change,online) VALUES (?,?,?,?,?,?) ON CONFLICT(device_id) DO UPDATE SET payload=excluded.payload,last_seen=excluded.last_seen,last_change=excluded.last_change,online=excluded.online', (key,json.dumps(device,ensure_ascii=False),first,last,change,int(online)))
     changed.append(dict(device,status='online' if online else 'offline',first_seen=int(first),last_seen=int(last),last_change=int(change),change_type=typ))
   if changed:
    self.root.after(0,self._neighbor_update_ui)
    self.sync_data({'event':'device_event','devices':changed,'captured_at':int(now)})
  except Exception as exc:
   self.last_error='Tempo real: '+str(exc)[:180]

 def _watch_neighbors(self):
  """Observa somente a tabela de vizinhos do Windows, sem sondar a sub-rede.

  ARP não é uma fonte confiável de desconexão imediata: só reportamos offline
  depois de ausência prolongada e confirmação pontual da falta de resposta.
  """
  if self.stop_event.is_set():return
  if self.monitor_on and not self.scan_running and not self._neighbor_busy:
   self._neighbor_busy=True
   threading.Thread(target=self._neighbor_worker,daemon=True).start()
  self.root.after(2000,self._watch_neighbors)

 def _neighbor_worker(self):
  try:
   response=local_api('/api/neighbors',timeout=8)
   observed=response.get('neighbors',{}) if isinstance(response,dict) else {}
   if not isinstance(observed,dict):return
   states=response.get("states",{}) if isinstance(response.get("states"),dict) else {}
   now=time.time();updates=[]
   with history_db() as db:
    db.execute('PRAGMA busy_timeout=15000')
    rows=db.execute('SELECT device_id,payload,online,last_change FROM inventory').fetchall()
    known={key:(json.loads(payload),bool(online),changed) for key,payload,online,changed in rows}
    active=set()
    for ip,mac in list(observed.items())[:512]:
     if not isinstance(ip,str) or not isinstance(mac,str):continue
     ident=self.device_id({'mac':mac})
     if not ident:continue
     state=str(states.get(ip,{}).get('state','')).lower()
     # Stale é apenas cache; não é evidência de presença.
     if state not in ('reachable','permanent'):
      continue
     active.add(ident)
     self._neighbor_missing.pop(ident,None)
     if ident in known:
      old,online,last_change=known[ident]
      if old.get('ip')!=ip or not online:
       old['ip']=ip;old['mac']=mac
       db.execute('UPDATE inventory SET payload=?,online=1,last_seen=?,last_change=? WHERE device_id=?',(json.dumps(old,ensure_ascii=False),now,now if not online else last_change,ident))
       updates.append(('online' if not online else 'ip',ip))
      else:db.execute('UPDATE inventory SET last_seen=? WHERE device_id=?',(now,ident))
     else:
      # Não gera identidade por IP nem confunde entradas multicast com hosts.
      d={'ip':ip,'mac':mac,'name':ip,'type':'device','source':'os_neighbor_cache'}
      db.execute('INSERT OR IGNORE INTO inventory(device_id,payload,first_seen,last_seen,last_change,online) VALUES (?,?,?,?,?,1)',(ident,json.dumps(d),now,now,now))
      updates.append(('novo',ip))
    # Uma ausência na tabela não prova desconexão. Apenas o estado explícito
    # Unreachable, observado repetidamente, permite a transição offline.
    for ident,(d,online,changed) in known.items():
     if not online or ident in active:continue
     ip=str(d.get('ip') or '')
     state=str(states.get(ip,{}).get('state','')).lower()
     if state!='unreachable':
      self._neighbor_missing.pop(ident,None)
      continue
     first,count=self._neighbor_missing.get(ident,(now,0))
     count+=1
     self._neighbor_missing[ident]=(first,count)
     if count>=2 and now-first>=3:
      db.execute('UPDATE inventory SET online=0,last_change=? WHERE device_id=?',(now,ident))
      updates.append(('offline',ip))
      self._neighbor_missing.pop(ident,None)
   if updates:
    self.root.after(0,self._neighbor_update_ui)
    self.root.after(0,lambda n=len(updates):self.log(f'Monitor de vizinhos: {n} alteração(ões) detectada(s).'))
    # O snapshot existente preserva o contrato e permissões do servidor.
    self.root.after(0,self.send_inventory_snapshot)
  except Exception as e:
   self.last_error='Monitor de vizinhos: '+str(e)[:180]
  finally:self._neighbor_busy=False

 def _neighbor_update_ui(self):
  self.load_inventory()
  self.render_devices()
  self.draw_map()

 def periodic_scan(self):
  # Mantido apenas por compatibilidade; nenhuma varredura automática.
  return

 def device_id(self,d):
  raw=re.sub(r'[^0-9a-f]','',str(d.get('mac') or '').lower())
  if len(raw)==12 and raw not in ('000000000000','ffffffffffff') and not (int(raw[:2],16) & 1):
   return 'mac:'+':'.join(raw[i:i+2] for i in range(0,12,2))
  return ''
 def load_inventory(self):
  try:
   with history_db() as db:
    rows=db.execute('SELECT device_id,payload,first_seen,last_seen,last_change,online,custom_name,custom_type,group_id,pinned FROM inventory WHERE hidden=0 ORDER BY pinned DESC,last_seen DESC').fetchall()
   self.devices=[]
   for key,payload,first,last,changed,online,name,kind,group_id,pinned in rows:
    d=json.loads(payload); d.update(_device_id=key,first_seen=first,last_seen=last,last_change=changed,status='online' if online else 'offline',custom_name=name or '',custom_type=kind or '',group_id=group_id,pinned=bool(pinned));self.devices.append(d)
  except Exception: self.devices=[]
 def merge_inventory(self,found):
  now=time.time(); found={self.device_id(d):d for d in found if d.get('ip') and self.device_id(d).startswith('mac:')}
  try:
   with history_db() as db:
    for key,d in found.items():
     if key.startswith('mac:') and d.get('ip'):
      provisional='ip:'+str(d['ip'])
      # Ao descobrir o MAC, migra personalizações de eventual registro provisório por IP.
      old=db.execute('SELECT custom_name,custom_type,group_id FROM inventory WHERE device_id=?',(provisional,)).fetchone()
      current=db.execute('SELECT device_id FROM inventory WHERE device_id=?',(key,)).fetchone()
      if old and not current:
       db.execute('UPDATE inventory SET device_id=? WHERE device_id=?',(key,provisional))
      elif old and current:db.execute('DELETE FROM inventory WHERE device_id=?',(provisional,))
     row=db.execute('SELECT first_seen,last_change,online,custom_name,custom_type,group_id,hidden,pinned FROM inventory WHERE device_id=?',(key,)).fetchone()
     first,changed,was_online,name,kind,group_id,hidden,pinned=row if row else (now,now,0,'','',None,0,0)
     if not was_online:changed=now
     db.execute('INSERT OR REPLACE INTO inventory(device_id,payload,first_seen,last_seen,last_change,online,custom_name,custom_type,group_id,hidden,pinned) VALUES(?,?,?,?,?,?,?,?,?,?,?)',(key,json.dumps(d,ensure_ascii=False),first,now,changed,1,name,kind,group_id,hidden,pinned))
    for key,changed,online in db.execute('SELECT device_id,last_change,online FROM inventory').fetchall():
     if key not in found and online:db.execute('UPDATE inventory SET online=0,last_change=? WHERE device_id=?',(now,key))
   self.load_inventory()
  except Exception as e:self.root.after(0,lambda err=str(e):self.log('Inventário: '+err,'ERROR'))
 def edit_device(self,event=None):
  selected=self.device_tree.selection()
  if not selected:return
  idx=int(selected[0]);
  if idx>=len(self.devices):return
  d=self.devices[idx];win=tk.Toplevel(self.root);win.title('Identificar dispositivo');win.geometry('410x340');bind_theme(win, background='bg').configure(bg=theme_color('bg'));win.transient(self.root);win.grab_set()
  bind_theme(tk.Label(win, text=str(d.get('ip', '')), bg=theme_color('bg'), fg=theme_color('accent')), background='bg', foreground='accent').pack(pady=8)
  name=tk.StringVar(value=d.get('custom_name') or d.get('name',''));kind=tk.StringVar(value=d.get('custom_type') or d.get('type','device'))
  bind_theme(tk.Label(win, text='Nome do dispositivo', bg=theme_color('bg'), fg=theme_color('text')), background='bg', foreground='text').pack();tk.Entry(win, textvariable=name, width=40).pack(pady=5)
  bind_theme(tk.Label(win, text='Tipo', bg=theme_color('bg'), fg=theme_color('text')), background='bg', foreground='text').pack();ttk.Combobox(win, textvariable=kind, values=('computador', 'celular', 'tablet', 'TV', 'roteador', 'impressora', 'servidor', 'camera', 'IoT', 'outro'), width=37).pack(pady=5)
  bind_theme(tk.Label(win, text='Grupo / setor', bg=theme_color('bg'), fg=theme_color('text')), background='bg', foreground='text').pack()
  groups=self.get_groups(); group_labels=['Sem grupo']+[label for gid,label in groups];group_ids=[None]+[gid for gid,label in groups]
  current=next((i for i,gid in enumerate(group_ids) if gid==d.get('group_id')),0);group_var=tk.StringVar(value=group_labels[current]);ttk.Combobox(win,textvariable=group_var,values=group_labels,state='readonly',width=37).pack(pady=5)
  def save():
   gid=group_ids[group_labels.index(group_var.get())] if group_var.get() in group_labels else None
   with history_db() as db:db.execute('UPDATE inventory SET custom_name=?,custom_type=?,group_id=? WHERE device_id=?',(name.get().strip()[:120],kind.get().strip()[:50],gid,d['_device_id']))
   self.load_inventory();self.render_devices();self.draw_map();self.send_inventory_snapshot();win.destroy()
  self.btn(win,'Salvar identificação',save,True).pack(pady=8)
 def render_devices(self):
  if not hasattr(self,'device_tree'):return
  for i in self.device_tree.get_children():self.device_tree.delete(i)
  now=time.time();query=self.device_filter.get().casefold().strip()
  status=self.device_status_filter.get() if hasattr(self,'device_status_filter') else 'Todas as situações'
  kind_filter=self.device_type_filter.get() if hasattr(self,'device_type_filter') else 'Todos os tipos'
  group_filter=self.device_group_filter.get() if hasattr(self,'device_group_filter') else 'Todos os grupos'
  kinds=sorted({str(d.get('custom_type') or d.get('type') or 'Outro') for d in self.devices},key=str.casefold)
  groups=sorted({self.group_name(d.get('group_id')) for d in self.devices},key=str.casefold)
  if hasattr(self,'device_type_select'):
   self.device_type_select.configure(values=['Todos os tipos']+kinds)
   if kind_filter not in kinds and kind_filter!='Todos os tipos':self.device_type_filter.set('Todos os tipos');kind_filter='Todos os tipos'
  if hasattr(self,'device_group_select'):
   self.device_group_select.configure(values=['Todos os grupos']+groups)
   if group_filter not in groups and group_filter!='Todos os grupos':self.device_group_filter.set('Todos os grupos');group_filter='Todos os grupos'
  shown=0
  sort_mode=self.device_sort.get() if hasattr(self,'device_sort') else 'Mais recentes'
  if sort_mode=='Mais antigos':sort_key=lambda entry:(float(entry[1].get('last_change') or 0),str(entry[1].get('name') or '').casefold())
  elif sort_mode=='Primeira detecção':sort_key=lambda entry:(float(entry[1].get('first_seen') or 0),str(entry[1].get('name') or '').casefold())
  elif sort_mode=='Nome':sort_key=lambda entry:str(entry[1].get('custom_name') or entry[1].get('name') or '').casefold()
  else:sort_key=lambda entry:(-float(entry[1].get('last_change') or 0),str(entry[1].get('name') or '').casefold())
  order=sorted(enumerate(self.devices),key=sort_key)
  for ix,d in order:
   kind=str(d.get('custom_type') or d.get('type') or 'Outro');group=self.group_name(d.get('group_id'))
   if query and not any(query in str(d.get(k,'')).casefold() for k in ('name','custom_name','ip','mac','type','custom_type','status','source')):continue
   if status=='Online' and d.get('status')!='online':continue
   if status=='Offline' and d.get('status')=='online':continue
   if status=='Fixados' and not d.get('pinned'):continue
   if kind_filter!='Todos os tipos' and kind!=kind_filter:continue
   if group_filter!='Todos os grupos' and group!=group_filter:continue
   shown+=1
   elapsed=max(0,int(now-float(d.get('last_change') or now)))
   duration=f'{elapsed//86400}d {elapsed%86400//3600}h' if elapsed>=86400 else f'{elapsed//3600}h {elapsed%3600//60}m'
   seen=time.strftime('%d/%m/%Y %H:%M',time.localtime(float(d.get('last_seen') or now)))
   self.device_tree.insert('','end',iid=str(ix),values=(shown,('★ ' if d.get('pinned') else '')+(d.get('custom_name') or d.get('name') or 'Dispositivo'),d.get('ip',''),d.get('mac',''),kind,group,'Online' if d.get('status')=='online' else 'Offline',duration,seen))
  self.device_count_label.configure(text=f'{shown} / {len(self.devices)} dispositivos')
  if hasattr(self,'metric_vars'):self.metric_vars['devices'].configure(text=str(len(self.devices)))
 def tool_write(self,text): self.tools_out.insert('end','\n'+time.strftime('%H:%M:%S')+'  '+text+'\n'); self.tools_out.see('end')
 def run_diagnostics(self,remote_id=None):
  if getattr(self,'diagnostic_busy',False):
   if remote_id:self._ack_remote_command(remote_id,'failed','Diagnóstico já em execução')
   return
  self.diagnostic_busy=True
  self.nb.select(self.connection_page)
  self._operation_message('remote' if remote_id else 'local','identificando IP público e provedora')
  def work():
   try:
    from connection_diagnostics import collect
    details=collect()
    # A plataforma já tem a medição persistida do mesmo agente. Reaproveitar
    # somente um registro autenticado, sem sobrescrever uma coleta recente válida.
    platform_connection=None
    if not details.get('public_ip') or not (details.get('provider') or {}).get('isp'):
     try:
      answer=request_json(ORIGIN+'/api/api.php?action=agent-config',{'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY},timeout=12)
      record=answer.get('last_connection') if isinstance(answer,dict) and answer.get('ok') else None
      if isinstance(record,dict) and record.get('public_ip') and (record.get('provider') or {}).get('isp'):
       current_ip=details.get('public_ip')
       if not current_ip or current_ip==record['public_ip']:
        platform_connection=record
        details['provider']=details.get('provider') if (details.get('provider') or {}).get('isp') else record['provider']
        details['public_ip']=current_ip or record['public_ip']
        if not (details.get('interface') or {}).get('dns'):
         details['interface']={**(record.get('interface') or {}),**{k:v for k,v in (details.get('interface') or {}).items() if v}}
        if not details.get('route'):details['route']=record.get('route') or []
        details['provider_cached_at']=record.get('captured_at')
        details['provider_source']='platform'
     except Exception as lookup_error:
      details.setdefault('errors',[]).append('Último diagnóstico da plataforma indisponível: '+str(lookup_error)[:90])
    cached=self._load_confirmed_connection()
    # A ausência de respostas externas não deve zerar dados reais já confirmados.
    # A provedora antiga só é reaproveitada quando o IP público é igual ou não pôde ser consultado.
    if cached and not (details.get('provider') or {}).get('isp') and (not details.get('public_ip') or details.get('public_ip')==cached.get('public_ip')):
     details['provider']=cached.get('provider') or {}
     if not details.get('public_ip'):details['public_ip']=cached['public_ip']
     details['provider_cached_at']=cached.get('captured_at')
    else:self._save_confirmed_connection(details)
    payload=dict(details, event='diagnostic', text='Diagnóstico estruturado da conexão')
    # Aguarda confirmação da API antes de concluir o comando remoto.
    if remote_id:
     body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'machine_name':platform.node(),'event':payload,'captured_at':int(time.time())}
     response=request_json(ORIGIN+'/api/api.php?action=agent-data',body,timeout=20)
     if not response.get('ok'):raise RuntimeError(response.get('error','Falha ao sincronizar diagnóstico'))
    else:self.sync_data(payload)
    def show():
     if hasattr(self,'diag_out'):
      self.diag_out.delete('1.0','end')
      provider=details.get('provider') or {};iface=details.get('interface') or {}
      lines=['Diagnóstico de conexão','Provedora: '+str(provider.get('isp') or 'Não identificada'),'IP público: '+str(details.get('public_ip') or 'Indisponível'),'IP local: '+str(iface.get('ip') or 'Indisponível'),'Gateway: '+str(iface.get('gateway') or 'Indisponível')]
      self.diag_out.insert('end','\n'.join(lines))
     if hasattr(self,'connection_out'):
      lines=['CONEXÃO E PROVEDORA','Provedora: '+str(provider.get('isp') or 'Não identificada'),'Organização: '+str(provider.get('org') or 'Não informada'),'ASN: '+str(provider.get('asn') or 'Não informado'),'IP público: '+str(details.get('public_ip') or 'Indisponível'),'Região aproximada: '+', '.join(str(provider.get(k) or '') for k in ('city','region','country') if provider.get(k)),'Adaptador: '+str(iface.get('name') or 'Indisponível'),'IP local: '+str(iface.get('ip') or 'Indisponível'),'Gateway: '+str(iface.get('gateway') or 'Indisponível'),'DNS: '+', '.join(iface.get('dns') or []),'','Rota de rede: '+str(len(details.get('route') or []))+' saltos observados']
      self.connection_out.delete('1.0','end');self.connection_out.insert('end','\n'.join(lines))
      self._set_connection_fields(details)
     if hasattr(self,'connection_status'):
      problems='; '.join(details.get('errors') or [])
      cached_time=details.get('provider_cached_at')
      if cached_time:
       date=time.strftime('%d/%m/%Y %H:%M',time.localtime(int(cached_time)))
       self.connection_status.configure(text='Provedora: última identificação confirmada em '+date+' · Consulta atual indisponível')
      else:self.connection_status.configure(text='Diagnóstico atualizado'+(' · '+problems[:190] if problems else ''))
    self.root.after(0,show)
    if remote_id:self._ack_remote_command(remote_id,'completed','Diagnóstico da conexão sincronizado')
    else:self.root.after(0,lambda:self._operation_message('local','diagnóstico da conexão concluído',completed=True))
   except Exception as exc:
    self.root.after(0,lambda msg=str(exc):self.log('Falha no diagnóstico: '+msg[:250]))
    if remote_id:self._ack_remote_command(remote_id,'failed',str(exc)[:350])
    else:self.root.after(0,lambda msg=str(exc):self._operation_message('local','falha no diagnóstico: '+msg,error=True))
   finally:self.diagnostic_busy=False
  threading.Thread(target=work,daemon=True).start()
 def _build_server(self):
  """Desempenho da máquina local em uma pegada inspirada no Gerenciador de Tarefas."""
  page=self.server_page
  self.SERVER_WINDOW=60
  self._server_selected=None
  self._server_history={}
  self._server_last_sent=0.0
  self._server_points=[]
  self._server_items={}
  self._server_components={}
  self._server_order=[]
  shell=bind_theme(tk.Frame(page, bg=theme_color('bg')), background='bg')
  shell.pack(fill='both',expand=True,padx=10,pady=10)
  sidebar=bind_theme(tk.Frame(shell, bg=theme_color('surface'), width=250, highlightbackground=theme_color('border'), highlightthickness=1), background='surface', highlightbackground='border')
  sidebar.pack(side='left',fill='y',padx=(0,8))
  sidebar.pack_propagate(False)
  self._server_sidebar=sidebar
  self._server_sidebar_title=bind_theme(tk.Label(sidebar, text='COMPONENTES', bg=theme_color('surface'), fg=theme_color('text'), font=('Segoe UI', 9, 'bold')), background='surface', foreground='text')
  self._server_sidebar_title.pack(fill='x',padx=12,pady=(10,6))
  self._server_list=bind_theme(tk.Frame(sidebar, bg=theme_color('surface')), background='surface')
  self._server_list.pack(fill='both',expand=True,padx=6,pady=(0,6))

  content=bind_theme(tk.Frame(shell, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1), background='surface', highlightbackground='border')
  content.pack(side='left',fill='both',expand=True)
  head=bind_theme(tk.Frame(content, bg=theme_color('surface')), background='surface')
  head.pack(fill='x',padx=18,pady=(14,2))
  self._server_title=bind_theme(tk.Label(head, text='Servidor', font=('Segoe UI', 24, 'bold'), fg=theme_color('text'), bg=theme_color('surface')), foreground='text', background='surface')
  self._server_title.pack(side='left')
  self._server_value=bind_theme(tk.Label(head, text='Aguardando leitura', font=('Segoe UI', 17), fg=theme_color('accent'), bg=theme_color('surface')), foreground='accent', background='surface')
  self._server_value.pack(side='right')
  self._server_subtitle=bind_theme(tk.Label(content, text='Monitoramento passivo dos componentes detectados automaticamente.', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w'), foreground='muted', background='surface')
  self._server_subtitle.pack(fill='x',padx=20)
  self._server_statbar=bind_theme(tk.Frame(content, bg=theme_color('surface')), background='surface')
  self._server_statbar.pack(fill='x',padx=18,pady=(10,6))
  self._server_stats=[]
  for _ in range(4):
   box=bind_theme(tk.Frame(self._server_statbar, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1), background='surface', highlightbackground='border')
   box.pack(side='left',fill='both',expand=True,padx=4)
   lbl=bind_theme(tk.Label(box, text='—', bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9, 'bold')), background='surface', foreground='muted')
   lbl.pack(anchor='w',padx=10,pady=(8,0))
   val=bind_theme(tk.Label(box, text='--', bg=theme_color('surface'), fg=theme_color('text'), font=('Segoe UI', 13, 'bold')), background='surface', foreground='text')
   val.pack(anchor='w',padx=10,pady=(0,8))
   self._server_stats.append((lbl,val))
  bind_theme(tk.Label(content, text='Últimos 60 segundos', font=('Segoe UI', 9), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w'), foreground='muted', background='surface').pack(fill='x', padx=20)
  self._server_canvas=bind_theme(tk.Canvas(content, background=theme_color('surface'), highlightthickness=1, highlightbackground=theme_color('border'), cursor='crosshair'), background='surface', highlightbackground='border')
  self._server_canvas.pack(fill='both',expand=True,padx=18,pady=6)
  self._server_canvas.bind('<Configure>',lambda _e:self._server_draw())
  self._server_canvas.bind('<Button-1>',self._server_point_detail)
  self._server_note=bind_theme(tk.Label(content, text='Aguardando medições da máquina local.', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w', justify='left', wraplength=980), foreground='muted', background='surface')
  self._server_note.pack(fill='x',padx=18,pady=(4,14))

 def _server_palette(self,group,index=0):
  palettes={
   'internet':[theme_color('accent'),theme_color('success'),theme_color('violet'),theme_color('warning')],
   'cpu':[theme_color('accent')],
   'memory':[theme_color('accent')],
   'disk':[theme_color('success'),theme_color('success'),theme_color('success')],
   'network':[theme_color('danger'),theme_color('danger'),theme_color('danger')],
   'gpu':[theme_color('violet'),theme_color('violet'),theme_color('violet')],
   'generic':[theme_color('accent'),theme_color('success'),theme_color('warning'),theme_color('violet')]
  }
  options=palettes.get(group,palettes['generic'])
  return options[index % len(options)]

 def _server_format_unit(self,unit,value,compact=False):
  if not isinstance(value,(int,float)):
   return 'Sem dados'
  if unit=='%':
   return f'{value:.0f}%' if compact else f'{value:.1f}%'
  if unit=='Mbps':
   return f'{value:.2f} Mbps' if not compact else f'{value:.2f} Mbps'
  if unit=='ms':
   return f'{value:.0f} ms' if compact else f'{value:.1f} ms'
  if unit=='GB':
   return f'{value:.2f} GB'
  return f'{value:.2f} {unit}'.strip()

 def _server_nice_max(self,unit,values,default=100.0):
  clean=[float(v) for v in values if isinstance(v,(int,float))]
  if not clean:
   return default
  peak=max(clean)
  if unit=='%':
   return 100.0
  if unit=='ms':
   for step in (10,20,30,50,75,100,150,200,300,500,750,1000):
    if peak*1.15<=step:return float(step)
   return max(10.0,peak*1.15)
  for step in (1,2,5,10,20,50,100,200,500,1000,2000,5000):
   if peak*1.15<=step:return float(step)
  return max(default,peak*1.15)

 def _server_make_component(self,key,title,group,value,unit,detail,subtitle='',series=None,series_meta=None,accent=None,header=None):
  return {
   'key':key,
   'title':title,
   'group':group,
   'value':value,
   'unit':unit,
   'detail':detail,
   'subtitle':subtitle,
   'series':series or {},
   'series_meta':series_meta or {},
   'accent':accent or self._server_palette(group),
   'header':header or subtitle,
  }

 def _server_prepare_components(self,sample):
  details=sample.get('details') or {}
  meta=sample.get('meta') or {}
  comps=[]
  recent=self.samples[-20:] if getattr(self,'samples',None) else []
  last_net=self.samples[-1] if getattr(self,'samples',None) else {}
  latency=last_net.get('latency') if isinstance(last_net,dict) else None
  download=last_net.get('download_mbps') if isinstance(last_net,dict) else None
  upload=last_net.get('upload_mbps') if isinstance(last_net,dict) else None
  loss=round(sum(1 for item in recent if item.get('latency') is None)/max(1,len(recent))*100,2) if recent else None
  internet_detail='Latência, download, upload e perdas acompanhados pelo agente.'
  if isinstance(latency,(int,float)) or isinstance(download,(int,float)) or isinstance(upload,(int,float)):
   internet_detail=f'Latência: {self._server_format_unit("ms",latency)} • Download: {self._server_format_unit("Mbps",download)} • Upload: {self._server_format_unit("Mbps",upload)}'
   if isinstance(loss,(int,float)):
    internet_detail+=f' • Perdas: {loss:.1f}%'
  comps.append(self._server_make_component('internet', 'Internet', 'internet', latency, 'ms', internet_detail, subtitle='Latência • tráfego • perdas', series={'latency_ms': latency, 'download_mbps': download, 'upload_mbps': upload, 'loss_percent': loss}, series_meta={'latency_ms': {'label': 'Latência', 'unit': 'ms', 'color': theme_color('accent')}, 'download_mbps': {'label': 'Download', 'unit': 'Mbps', 'color': theme_color('success')}, 'upload_mbps': {'label': 'Upload', 'unit': 'Mbps', 'color': theme_color('violet')}, 'loss_percent': {'label': 'Perdas', 'unit': '%', 'color': theme_color('warning')}}, accent=theme_color('accent'), header='Monitoramento em tempo real'))
  comps.append(self._server_make_component('cpu','CPU','cpu',sample.get('CPU'),'%',details.get('CPU','Calculando uso da CPU...'),subtitle=str(meta.get('cpu_name') or 'Processador'),accent=self._server_palette('cpu')))
  memory=meta.get('memory') or {}
  mem_sub='Memória do sistema'
  if memory:
   mem_sub=f"{memory.get('used',0)/(2**30):.1f}/{memory.get('total',0)/(2**30):.1f} GB"
  comps.append(self._server_make_component('memory','Memória','memory',sample.get('Memória'),'%',details.get('Memória','Leitura de memória indisponível'),subtitle=mem_sub,accent=self._server_palette('memory')))
  drives=meta.get('drives') or []
  if drives:
   for idx,drv in enumerate(drives):
    subtitle=f"{drv.get('used',0)/(2**30):.0f}/{drv.get('total',0)/(2**30):.0f} GB"
    detail=f"Ocupação de {drv.get('name')}: {drv.get('percent',0):.1f}% • {subtitle}"
    comps.append(self._server_make_component(f"disk_{idx}",drv.get('label') or f"Disco {idx}",'disk',drv.get('percent'),'%',detail,subtitle=subtitle,accent=self._server_palette('disk',idx),header=drv.get('name') or 'Disco'))
  else:
    comps.append(self._server_make_component('disks','Discos','disk',sample.get('Discos'),'%',details.get('Discos','Aguardando leitura dos discos'),subtitle='Armazenamento',accent=self._server_palette('disk')))
  net_down=sample.get('Rede_download')
  net_up=sample.get('Rede_upload')
  total_net=(net_down or 0.0)+(net_up or 0.0) if isinstance(net_down,(int,float)) or isinstance(net_up,(int,float)) else sample.get('Rede')
  net_detail=details.get('Rede','Calculando taxa de rede...')
  if not (meta.get('interfaces') or []):
   comps.append(self._server_make_component('network_total', 'Rede', 'network', total_net, 'Mbps', net_detail, subtitle=f"Enviar {self._server_format_unit('Mbps', net_up, True)} • Receber {self._server_format_unit('Mbps', net_down, True)}", accent=self._server_palette('network'), series={'download_mbps': net_down, 'upload_mbps': net_up}, series_meta={'download_mbps': {'label': 'Receber', 'unit': 'Mbps', 'color': theme_color('danger')}, 'upload_mbps': {'label': 'Enviar', 'unit': 'Mbps', 'color': theme_color('danger')}}, header='Adaptadores ativos'))
  for idx,iface in enumerate(meta.get('interfaces') or []):
   iface_down=iface.get('download_mbps'); iface_up=iface.get('upload_mbps')
   comps.append(self._server_make_component(f'network_{idx}', iface.get('name') or f'Adaptador {idx + 1}', 'network', (iface_down or 0.0) + (iface_up or 0.0) if isinstance(iface_down, (int, float)) or isinstance(iface_up, (int, float)) else None, 'Mbps', f"Enviar: {self._server_format_unit('Mbps', iface_up)} • Receber: {self._server_format_unit('Mbps', iface_down)}", subtitle=f"S {self._server_format_unit('Mbps', iface_up, True)} • R {self._server_format_unit('Mbps', iface_down, True)}", series={'download_mbps': iface_down, 'upload_mbps': iface_up}, series_meta={'download_mbps': {'label': 'Receber', 'unit': 'Mbps', 'color': theme_color('danger')}, 'upload_mbps': {'label': 'Enviar', 'unit': 'Mbps', 'color': theme_color('danger')}}, accent=self._server_palette('network', idx + 1), header='Interface de rede'))
  gpus=meta.get('gpus') or []
  if gpus:
   for idx,gpu in enumerate(gpus):
    if not isinstance(gpu.get('utilization'),(int,float)):
     continue
    mem=''
    if gpu.get('memory_used_mb') is not None and gpu.get('memory_total_mb') is not None:
     mem=f" • VRAM {gpu.get('memory_used_mb'):.0f}/{gpu.get('memory_total_mb'):.0f} MB"
    temp=f" • {gpu.get('temperature_c'):.0f}°C" if gpu.get('temperature_c') is not None else ''
    detail=f"Uso: {self._server_format_unit('%',gpu.get('utilization'))}{mem}{temp}"
    comps.append(self._server_make_component(f"gpu_{idx}",f"GPU {idx}",'gpu',gpu.get('utilization'),'%',detail,subtitle=str(gpu.get('name') or f'GPU {idx}'),accent=self._server_palette('gpu',idx),header=str(gpu.get('name') or f'GPU {idx}')))
  elif isinstance(sample.get('GPU'), (int,float)):
   comps.append(self._server_make_component('gpu','GPU','gpu',sample.get('GPU'),'%',details.get('GPU','Uso da GPU'),subtitle='Processador gráfico',accent=self._server_palette('gpu')))
  return comps

 def _server_rebuild_sidebar(self):
  for child in self._server_list.winfo_children():
   child.destroy()
  self._server_items={}
  for key in self._server_order:
   comp=self._server_components[key]
   box=bind_theme(tk.Frame(self._server_list, bg=theme_color('surface'), cursor='hand2', highlightthickness=1, highlightbackground=theme_color('border')), background='surface', highlightbackground='border')
   box.pack(fill='x',padx=4,pady=4)
   spark=bind_theme(tk.Canvas(box, width=98, height=54, bg=theme_color('surface'), highlightthickness=1, highlightbackground=theme_color('border'), cursor='hand2'), background='surface', highlightbackground='border')
   spark.pack(side='left',padx=8,pady=8)
   texts=bind_theme(tk.Frame(box, bg=theme_color('surface'), cursor='hand2'), background='surface')
   texts.pack(side='left',fill='both',expand=True,padx=(0,8),pady=8)
   title=bind_theme(tk.Label(texts, text=comp['title'], anchor='w', bg=theme_color('surface'), fg=theme_color('text'), font=('Segoe UI', 10, 'bold'), cursor='hand2'), background='surface', foreground='text')
   title.pack(fill='x')
   subtitle=bind_theme(tk.Label(texts, text=comp.get('subtitle') or '', anchor='w', bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9), cursor='hand2'), background='surface', foreground='muted')
   subtitle.pack(fill='x')
   for widget in (box,spark,texts,title,subtitle):
    widget.bind('<Button-1>',lambda _e,k=key:self._server_select(k))
   self._server_items[key]={'box':box,'spark':spark,'title':title,'subtitle':subtitle}
  self._server_refresh_sidebar_style()

 def _server_refresh_sidebar_style(self):
  for key,item in self._server_items.items():
   selected=(key==self._server_selected)
   comp=self._server_components.get(key,{})
   item['box'].config(highlightbackground=comp.get('accent') if selected else theme_color('border'), bg=theme_color('alt') if selected else theme_color('surface'))
   item['title'].config(bg=theme_color('alt') if selected else theme_color('surface'))
   item['subtitle'].config(bg=theme_color('alt') if selected else theme_color('surface'))

 def _server_select(self,key):
  self._server_selected=key
  self._server_refresh_sidebar_style()
  self._server_draw()

 def _server_sample(self):
  if not hasattr(self,'_sysmetrics'):self._sysmetrics=SystemMetrics()
  try:return self._sysmetrics.sample()
  except Exception as e:
   return {'time':int(time.time()),'details':{'CPU':'Falha na coleta: '+str(e)[:110],'Memória':'Falha na coleta','Discos':'Falha na coleta','Rede':'Falha na coleta','GPU':'Falha na coleta'},'meta':{'drives':[],'interfaces':[],'gpus':[]}}

 def _server_history_entries(self,key):
  end=time.time();start=end-self.SERVER_WINDOW
  history=self._server_history.get(key,[])
  return [item for item in history if item.get('time',0)>=start]

 def _server_draw_spark(self,canvas,comp,entries):
  canvas.delete('all')
  w=max(96,int(canvas.winfo_width() or 96));h=max(54,int(canvas.winfo_height() or 54))
  if comp.get('group')=='internet':
   values=[e.get('series',{}).get('latency_ms') for e in entries]
   unit='ms';color=theme_color('accent');fill=theme_color('alt')
  else:
   values=[e.get('value') for e in entries]
   unit=comp.get('unit','%');color=comp.get('accent', theme_color('accent'));fill=theme_color('alt')
  maxv=self._server_nice_max(unit,values,100.0 if unit=='%' else 10.0)
  points=[]
  for idx,e in enumerate(entries):
   value=(e.get('series',{}).get('latency_ms') if comp.get('group')=='internet' else e.get('value'))
   if not isinstance(value,(int,float)):
    continue
   x=2+(w-6)*(idx/max(1,len(entries)-1))
   y=(h-6)-(max(0.0,min(float(value),maxv))/maxv)*(h-10)
   points.extend((x,y))
  if len(points)>=4:
   canvas.create_polygon(points[0],h-2,*points,points[-2],h-2,fill=fill,outline='')
   canvas.create_line(*points,fill=color,width=1.4)
  elif len(points)==2:
   canvas.create_oval(points[0]-2,points[1]-2,points[0]+2,points[1]+2,fill=color,outline=color)

 def _server_sparks(self):
  for key,item in self._server_items.items():
   comp=self._server_components.get(key)
   if not comp:
    continue
   entries=self._server_history_entries(key)
   self._server_draw_spark(item['spark'],comp,entries)
   value_text='Sem dados'
   if comp.get('group')=='internet':
    last=entries[-1] if entries else {}
    value_text=self._server_format_unit('ms', (last.get('series') or {}).get('latency_ms'), True)
   else:
    last=entries[-1] if entries else {}
    value_text=self._server_format_unit(comp.get('unit','%'), last.get('value'), True)
   item['subtitle'].config(text=comp.get('subtitle') or value_text)

 def _server_draw_grid(self,c,w,h,maxv,unit):
  x0,y0,x1,y1=70,24,w-18,h-34
  for i in range(6):
   y=y0+(y1-y0)*i/5
   bind_canvas(c, c.create_line(x0, y, x1, y, fill=theme_color('border')), fill='border')
   bind_canvas(c, c.create_text(x0 - 8, y, text=f'{maxv * (5 - i) / 5:g}', fill=theme_color('muted'), anchor='e', font=('Consolas', 9)), fill='muted')
  for i in range(11):
   x=x0+(x1-x0)*i/10
   bind_canvas(c, c.create_line(x, y0, x, y1, fill=theme_color('border')), fill='border')
  bind_canvas(c, c.create_text(x0, y1 + 18, text='60 segundos', fill=theme_color('muted'), anchor='w', font=('Consolas', 9)), fill='muted')
  bind_canvas(c, c.create_text(x1, y1 + 18, text='0', fill=theme_color('muted'), anchor='e', font=('Consolas', 9)), fill='muted')
  return x0,y0,x1,y1

 def _server_plot_single(self,c,entries,comp,w,h):
  values=[e.get('value') for e in entries]
  maxv=self._server_nice_max(comp.get('unit','%'),values,100.0 if comp.get('unit')=='%' else 10.0)
  x0,y0,x1,y1=self._server_draw_grid(c,w,h,maxv,comp.get('unit'))
  pts=[];plot=[]
  for idx,e in enumerate(entries):
   value=e.get('value')
   if not isinstance(value,(int,float)):
    continue
   x=x0+(x1-x0)*(idx/max(1,len(entries)-1))
   y=y1-(max(0.0,min(float(value),maxv))/maxv)*(y1-y0)
   plot.extend((x,y));pts.append((x,y,e.get('time'),value,e.get('detail','')))
  if len(plot)>=4:
   bind_canvas(c, c.create_polygon(plot[0], y1, *plot, plot[-2], y1, fill=theme_color('alt'), outline=''), fill='alt')
   c.create_line(*plot, fill=comp.get('accent', theme_color('accent')), width=2)
  elif len(plot)==2:
   c.create_oval(plot[0] - 3, plot[1] - 3, plot[0] + 3, plot[1] + 3, fill=comp.get('accent', theme_color('accent')), outline=comp.get('accent', theme_color('accent')))
  self._server_points=pts

 def _server_plot_internet(self,c,entries,comp,w,h):
  x0,y0,x1,y1=self._server_draw_grid(c,w,h,100.0,'%')
  self._server_points=[]
  legend=[]
  for key,meta in (comp.get('series_meta') or {}).items():
   vals=[(e.get('series') or {}).get(key) for e in entries]
   maxv=self._server_nice_max(meta.get('unit'),vals,100.0 if meta.get('unit')=='%' else 10.0)
   plot=[];pts=[]
   for idx,e in enumerate(entries):
    value=(e.get('series') or {}).get(key)
    if not isinstance(value,(int,float)):
     continue
    x=x0+(x1-x0)*(idx/max(1,len(entries)-1))
    y=y1-(max(0.0,min(float(value),maxv))/maxv)*(y1-y0)
    plot.extend((x,y));pts.append((x,y,e.get('time'),value,f"{meta.get('label')}: {self._server_format_unit(meta.get('unit'),value)}"))
   if len(plot)>=4:
    c.create_line(*plot, fill=meta.get('color', theme_color('accent')), width=2)
   elif len(plot)==2:
    c.create_oval(plot[0] - 2, plot[1] - 2, plot[0] + 2, plot[1] + 2, fill=meta.get('color', theme_color('accent')), outline=meta.get('color', theme_color('accent')))
   self._server_points.extend(pts)
   legend.append(meta)
  lx=x0
  for meta in legend:
   c.create_rectangle(lx,y0-14,lx+10,y0-4,fill=meta.get('color'),outline='')
   bind_canvas(c, c.create_text(lx + 14, y0 - 9, text=meta.get('label'), fill=theme_color('muted'), anchor='w', font=('Segoe UI', 8)), fill='muted')
   lx+=90

 def _server_update_stats(self,comp,entries):
  labels_values=[]
  if comp.get('group')=='internet':
   last=entries[-1] if entries else {}
   series=last.get('series') or {}
   labels_values=[
    ('Latência',self._server_format_unit('ms',series.get('latency_ms'))),
    ('Download',self._server_format_unit('Mbps',series.get('download_mbps'))),
    ('Upload',self._server_format_unit('Mbps',series.get('upload_mbps'))),
    ('Perdas',self._server_format_unit('%',series.get('loss_percent'))),
   ]
  else:
   nums=[e.get('value') for e in entries if isinstance(e.get('value'),(int,float))]
   last=nums[-1] if nums else None
   labels_values=[
    ('Atual',self._server_format_unit(comp.get('unit','%'),last)),
    ('Mínimo',self._server_format_unit(comp.get('unit','%'),min(nums) if nums else None)),
    ('Máximo',self._server_format_unit(comp.get('unit','%'),max(nums) if nums else None)),
    ('Amostras',str(len(nums))),
   ]
  for (lbl,val),(w_lbl,w_val) in zip(labels_values,self._server_stats):
   w_lbl.config(text=lbl)
   w_val.config(text=val)

 def _server_draw(self):
  if not hasattr(self,'_server_canvas') or not self._server_components:
   return
  if self._server_selected not in self._server_components:
   self._server_selected=self._server_order[0]
  comp=self._server_components[self._server_selected]
  c=self._server_canvas
  w=max(320,c.winfo_width());h=max(180,c.winfo_height())
  c.delete('all')
  entries=self._server_history_entries(comp['key'])
  self._server_title.config(text=comp['title'])
  if comp.get('group')=='internet':
   last=entries[-1] if entries else {}
   main=(last.get('series') or {}).get('latency_ms')
   self._server_value.config(text=self._server_format_unit('ms', main), fg=comp.get('accent', theme_color('accent')))
  else:
   self._server_value.config(text=self._server_format_unit(comp.get('unit', '%'), comp.get('value')), fg=comp.get('accent', theme_color('accent')))
  self._server_subtitle.config(text=comp.get('header') or comp.get('subtitle') or '')
  self._server_note.config(text=comp.get('detail') or 'Aguardando medições da máquina.')
  self._server_update_stats(comp,entries)
  if comp.get('group')=='internet':
   self._server_plot_internet(c,entries,comp,w,h)
  else:
   self._server_plot_single(c,entries,comp,w,h)

 def _server_tick(self):
  if self.stop_event.is_set():return
  try:
   sample=self._server_sample()
   sample['components']=self._server_prepare_components(sample)
   stamp=time.time()
   comps={item['key']:item for item in sample['components']}
   order=list(comps.keys())
   order_changed=(order!=self._server_order)
   self._server_components=comps
   self._server_order=order
   for comp in sample['components']:
    history=self._server_history.setdefault(comp['key'],[])
    history.append({'time':stamp,'value':comp.get('value'),'detail':comp.get('detail',''),'series':dict(comp.get('series') or {})})
    while history and history[0].get('time',0)<stamp-self.SERVER_WINDOW-10:
     history.pop(0)
   if order_changed or set(self._server_items.keys())!=set(order):
    self._server_rebuild_sidebar()
   if not self._server_selected or self._server_selected not in self._server_components:
    self._server_selected=order[0] if order else None
   self._server_refresh_sidebar_style()
   self._server_sparks()
   self._server_draw()
   self._server_current=sample
   if time.time()-self._server_last_sent>=5:
    self._server_last_sent=time.time()
    self.sync_data({'event':'server_metrics','metrics':sample})
  except Exception as exc:
   self.last_error='Servidor: '+str(exc)[:160]
   try:self._server_note.config(text='Falha temporária na leitura: '+str(exc)[:140])
   except Exception:pass
  finally:
   if not self.stop_event.is_set():self.root.after(1000,self._server_tick)

 def _server_point_detail(self,event):
  pts=getattr(self,'_server_points',[])
  if not pts:return
  x,y,t,v,detail=min(pts,key=lambda p:(p[0]-event.x)**2+(p[1]-event.y)**2)
  if abs(x-event.x)>22:return
  current=self._server_components.get(self._server_selected,{})
  unit='ms' if current.get('group')=='internet' else current.get('unit','')
  messagebox.showinfo('Arcoll • Servidor',f"{current.get('title','Servidor')} • {time.strftime('%d/%m/%Y %H:%M:%S',time.localtime(t))}\n{self._server_format_unit(unit,v)}\n{detail}",parent=self.root)

 def inventory_snapshot(self):
  items=[]
  for d in self.devices[:180]:
   item={'id':str(d.get('_device_id',''))[:100],'name':str(d.get('custom_name') or d.get('name') or '')[:120],'ip':str(d.get('ip') or '')[:45],'mac':str(d.get('mac') or '')[:32],'type':str(d.get('custom_type') or d.get('type') or 'device')[:50],'status':d.get('status','offline'),'group':self.group_name(d.get('group_id')),'last_seen':d.get('last_seen'),'last_change':d.get('last_change'),'first_seen':d.get('first_seen'),'source':str(d.get('source') or '')[:80],'pinned':bool(d.get('pinned'))}
   items.append(item)
  hardware=arcoll_host_hardware()
  mac=str(hardware.get('host_mac') or '').replace('-',':').upper()
  ip=str(hardware.get('host_ip') or local_ip())
  if re.fullmatch(r'(?:[0-9A-F]{2}:){5}[0-9A-F]{2}',mac) and mac not in ('00:00:00:00:00:00','FF:FF:FF:FF:FF:FF'):
   host=next((d for d in items if str(d.get('mac','')).replace('-',':').upper()==mac),None)
   if host is None:
    host={'name':platform.node(),'ip':ip,'mac':mac,'type':'computer','status':'online','group':'Computador do agente','first_seen':int(time.time()),'last_seen':int(time.time()),'last_change':int(time.time()),'source':'Agente Windows'}
    items.insert(0,host)
   host['agent_installed']=True
   host['hardware']=hardware
   host['source']='Agente Windows (este computador)'
   host['status']='online'
  return {'event':'network_scan','device_count':len(items),'devices':items[:180],'groups':[{'id':i,'name':label} for i,label in self.get_groups()],'captured_at':int(time.time())}
 def send_inventory_snapshot(self,command_id=None):
  self.sync_data(self.inventory_snapshot(),ack_id=command_id)
 def periodic_remote_snapshot(self):
  if self.stop_event.is_set():return
  if self.monitor_on:
   last=self.samples[-1] if self.samples else {}
   self.sync_data({'event':'dashboard','monitoring':True,'gateway':gateway_ip(),'local_ip':local_ip(),'latency_ms':last.get('latency'),'loss_percent':round(sum(1 for x in self.samples[-20:] if x.get('latency') is None)/max(1,len(self.samples[-20:]))*100,2),'device_count':len(self.devices),'online_count':sum(1 for d in self.devices if d.get('status')=='online'),'offline_count':sum(1 for d in self.devices if d.get('status')!='online'),'captured_at':int(time.time())})
  self.root.after(60000,self.periodic_remote_snapshot)
 def sync_data(self,payload,ack_id=None):
  if not ORIGIN or not SITE_CODE or not DOWNLOAD_KEY:return
  body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY,'machine_name':platform.node(),'event':payload,'captured_at':int(time.time())}
  def work():
   try:
    r=request_json(ORIGIN+'/api/api.php?action=agent-data',body,timeout=10)
    if not isinstance(r,dict) or not r.get('ok'): raise RuntimeError((r or {}).get('error','O servidor não confirmou a gravação.'))
    self.last_sync=time.strftime('%H:%M:%S'); self.root.after(0,lambda:self.sync_label.configure(text='Sincronizado '+self.last_sync))
    if ack_id:self._ack_remote_command(ack_id,'completed','Inventário sincronizado no servidor')
    if payload.get('event')=='network_scan':self._progress_operation(finished=True,message='Varredura concluída e sincronizada')
   except Exception as e:
    self.root.after(0,lambda:self.sync_label.configure(text='Sincronização pendente')); self.last_error=str(e)
    if ack_id:self._ack_remote_command(ack_id,'failed','Falha ao sincronizar inventário: '+str(e)[:300])
    if payload.get('event')=='network_scan':self._progress_operation(finished=True,message='Varredura realizada; sincronização pendente')
  threading.Thread(target=work,daemon=True).start()
 def export_devices(self): self._export_csv('dispositivos',list(self.devices[0].keys()) if self.devices else ['name','ip','mac','type','status'],self.devices)
 def _export_csv(self,name,headers,rows):
  path=filedialog.asksaveasfilename(defaultextension='.csv',initialfile=f'arcoll_{name}_{time.strftime("%Y%m%d_%H%M%S")}.csv',filetypes=[('CSV','*.csv')])
  if not path:return
  try:
   with open(path,'w',newline='',encoding='utf-8-sig') as f:
    w=csv.DictWriter(f,fieldnames=headers,extrasaction='ignore');w.writeheader();w.writerows(rows)
   messagebox.showinfo('Arcoll','Arquivo exportado com sucesso.')
  except Exception as e:messagebox.showerror('Arcoll','Não foi possível exportar: '+str(e))

 # ------------------------------------------------------------------
 # v2.5 - alinhamento visual com o portal e Prompt de Diagnósticos
 # ------------------------------------------------------------------
 @staticmethod
 def _mix(c1,c2,t):
  t=max(0.0,min(1.0,t))
  a=[int(c1[i:i+2],16) for i in (1,3,5)];b=[int(c2[i:i+2],16) for i in (1,3,5)]
  return '#%02x%02x%02x'%tuple(int(a[i]+(b[i]-a[i])*t) for i in range(3))
 def _hover_fx(self,w,base,hover):
  w._fx=0.0;w._fxd=0;w._fxjob=None
  def step():
   w._fx=max(0.0,min(1.0,w._fx+w._fxd*0.28))
   try:w.configure(bg=self._mix(base,hover,w._fx))
   except tk.TclError:return
   if 0.0<w._fx<1.0:w._fxjob=w.after(16,step)
  def go(d):
   w._fxd=d
   try:
    if w._fxjob:w.after_cancel(w._fxjob)
   except Exception:pass
   step()
  w.bind('<Enter>',lambda _e:go(1),add='+');w.bind('<Leave>',lambda _e:go(-1),add='+')
 def _fade_in(self,step=0):
  try:
   self.root.attributes('-alpha',min(1.0,step*0.09))
   if step<12:self.root.after(24,lambda:self._fade_in(step+1))
  except tk.TclError:pass
 def update_agent(self,remote_command_id=None):
  """Download an authenticated code-only update and delegate the swap to a separate process."""
  if platform.system()!='Windows' or not ORIGIN or not SITE_CODE or not DOWNLOAD_KEY:
   return messagebox.showerror('Atualizar','Este agente precisa estar conectado e autorizado na plataforma.',parent=self.root)
  if getattr(self,'_update_running',False):
   if remote_command_id:self._ack_remote_command(remote_command_id,'failed','Atualização já está em execução')
   return
  self._update_running=True
  self.hdr_uninstall.config(state='disabled',text='Verificando...')
  progress=tk.Toplevel(self.root)
  progress.title('Atualizando agente Arcoll')
  progress.transient(self.root)
  progress.resizable(False,False)
  bind_theme(progress, background='alt').configure(bg=theme_color('alt'))
  pw,ph=410,145
  progress.geometry(f'{pw}x{ph}+{max(0,(progress.winfo_screenwidth()-pw)//2)}+{max(0,(progress.winfo_screenheight()-ph)//2)}')
  bind_theme(tk.Label(progress, text='Atualizando agente', font=('Segoe UI', 14, 'bold'), bg=theme_color('alt'), fg=theme_color('text')), background='alt', foreground='text').pack(pady=(16, 5))
  progress_text=bind_theme(tk.Label(progress, text='Verificando atualização disponível...', bg=theme_color('alt'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='alt', foreground='muted')
  progress_text.pack(pady=4)
  progress_bar=ttk.Progressbar(progress,mode='indeterminate',length=345)
  progress_bar.pack(pady=(8,6));progress_bar.start(12)
  progress.protocol('WM_DELETE_WINDOW',lambda:None)
  def update_progress(msg):
   try:
    if progress.winfo_exists():progress_text.config(text=msg)
   except tk.TclError:pass
  def close_progress():
   try:
    progress_bar.stop();progress.destroy()
   except tk.TclError:pass
  def worker():
   try:
    body={'fingerprint':FINGERPRINT,'site_code':SITE_CODE,'download_key':DOWNLOAD_KEY}
    response=request_json(ORIGIN+'/api/api.php?action=agent-update-package',body,timeout=30)
    if not isinstance(response,dict) or not response.get('ok'):
     raise RuntimeError((response or {}).get('error','Servidor não disponibilizou a atualização.'))
    rows=response.get('files')
    if not isinstance(rows,list) or len(rows)<6 or len(rows)>80:raise ValueError('Inventário da release inválido.')
    changed=[];seen=set();inventory=[]
    stage=Path(tempfile.mkdtemp(prefix='arcoll-update-',dir=str(DATA_DIR)))
    for row in rows:
     self.root.after(0,lambda: update_progress('Validando arquivos e integridade...'))
     name=row.get('name') if isinstance(row,dict) else None
     if not isinstance(name,str) or not re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]*\.(?:py|ps1)',name) or name in seen:raise ValueError('Arquivo inesperado na atualização.')
     seen.add(name)
     binary=base64.b64decode(row.get('content',''),validate=True)
     if len(binary)>1500000 or hashlib.sha256(binary).hexdigest()!=row.get('sha256'):raise ValueError('Integridade da atualização inválida: '+name)
     # Atualização manual/solicitada pelo gestor: reinstala o pacote autenticado
     # mesmo quando os hashes e AGENT_VERSION permanecem iguais.
     (stage/name).write_bytes(binary)
     inventory.append(name)
     if not (ROOT/name).is_file() or hashlib.sha256((ROOT/name).read_bytes()).hexdigest()!=row['sha256']:
      changed.append({'name':name,'sha256':row['sha256']})
    if not {'arcoll_updater.py','arcoll_toolkit.py','arcoll_desktop.py','arcoll_app.py'}.issubset(seen):raise ValueError('Pacote incompleto. Envie a atualização completa para a plataforma.')
    managed=ROOT/'.arcoll-managed-files.json'
    try:
     previous=json.loads(managed.read_text(encoding='utf-8-sig')) if managed.is_file() else []
     if not isinstance(previous,list):previous=[]
    except (OSError,ValueError):previous=[]
    # Apenas arquivos controlados por manifesto anterior podem ser removidos.
    removed=sorted(set(x for x in previous if isinstance(x,str) and re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]*\.(?:py|ps1)',x))-set(inventory))
    if not changed and not removed and managed.is_file():
     if remote_command_id:self._ack_remote_command(remote_command_id,'completed','Inventário e hashes já coincidem com a release publicada')
     self.root.after(0,close_progress)
     self.root.after(0,lambda:messagebox.showinfo('Atualizar','O agente já está atualizado com a versão disponível no servidor.',parent=self.root))
     return
    (stage/'manifest.json').write_text(json.dumps({'files':changed,'inventory':inventory,'remove':removed,'remote_command_id':remote_command_id,'release_notes':str(response.get('notes') or '')[:3000],'release_version':str(response.get('version') or '')[:32]}),encoding='utf-8')
    def confirm():
     close_progress()
     if remote_command_id is None and not messagebox.askyesno('Atualizar agente',f'{len(changed)} arquivo(s) serão instalados/alterados e {len(removed)} removidos conforme inventário. O agente será reiniciado.\n\nDeseja continuar?',parent=self.root):return
     try:
      subprocess.Popen([sys.executable,str(stage/'arcoll_updater.py'),str(stage),str(os.getpid()),ORIGIN,str(ROOT)],cwd=str(ROOT),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
      # O comando será confirmado somente após o retorno do agente atualizado.
      self.close()
     except Exception as exc:
      if remote_command_id:self._ack_remote_command(remote_command_id,'failed',str(exc)[:300])
      messagebox.showerror('Atualizar','Não foi possível iniciar a atualização: '+str(exc),parent=self.root)
    self.root.after(0,confirm)
   except Exception as exc:
    message=str(exc)
    if remote_command_id:self._ack_remote_command(remote_command_id,'failed',message[:300])
    self.root.after(0,close_progress)
    self.root.after(0,lambda:messagebox.showerror('Atualizar','Falha ao verificar atualização: '+message,parent=self.root))
   finally:
    def reset():
     self._update_running=False
     try:self.hdr_uninstall.config(state='normal',text='Atualizar')
     except Exception:pass
    self.root.after(0,reset)
  threading.Thread(target=worker,daemon=True).start()

 def _enable_custom_chrome(self):
  """Mantém a moldura nativa para redimensionar e fechar a janela."""
  self._custom_maximized=False
  self._restore_geometry=self.root.geometry()
  self._borderless=False
  self.root.resizable(True,True)
  self.root.overrideredirect(False)
  self.root.bind('<Map>',self._restore_borderless,add='+')
  self.root.bind('<Escape>',lambda _e:self._restore_window(),add='+')
  # A interface pode ser encerrada pelo atalho Ctrl+Q ou pelo ícone da bandeja.

 def _restore_borderless(self,_event=None):
  if not getattr(self,'_borderless',False):return
  try:self.root.after(50,lambda:self.root.overrideredirect(True) if self.root.state()=='normal' else None)
  except tk.TclError:pass

 def _minimize_window(self):
  try:
   self.root.overrideredirect(False)
   self.root.iconify()
  except tk.TclError:pass

 def _maximize_window(self):
  try:
   if self._custom_maximized:
    self._custom_maximized=False
    if platform.system()=='Windows':self.root.state('normal')
    self.root.geometry(self._restore_geometry)
   else:
    self._restore_geometry=self.root.geometry()
    self._custom_maximized=True
    if platform.system()=='Windows':self.root.state('zoomed')
    else:self.root.geometry(f'{max(720,self.root.winfo_screenwidth()-24)}x{max(520,self.root.winfo_screenheight()-80)}+12+20')
   self._hdr_layout()
  except tk.TclError:pass

 def _restore_window(self):
  if self._custom_maximized:self._maximize_window()

 def _modern_header(self):
  try:self.root.attributes('-alpha',0.0)
  except tk.TclError:pass
  h=bind_theme(tk.Canvas(self.root, height=60, bg=theme_color('surface'), highlightthickness=0), background='surface');h.pack(fill='x');self.hdr=h
  self._hdr_phase=0.0;self._hdr_segs=[]
  bind_canvas(h, h.create_text(22, 18, text='ARCOLL DIGITAL  ·  GESTÃO DE REDES', anchor='w', fill=theme_color('accent'), font=('Segoe UI', 9, 'bold')), fill='accent')
  bind_canvas(h, h.create_text(22, 40, text='Arcoll Speed Connection', anchor='w', fill=theme_color('text'), font=('Segoe UI', 17, 'bold')), fill='text')
  self.hdr_version=bind_canvas(h, h.create_text(0, 0, anchor='e', fill=theme_color('muted'), font=('Consolas', 9), text='v. ' + AGENT_VERSION), fill='muted')
  self.hdr_led=None
  self.hdr_state=None
  self.hdr_clock=None
  self.hdr_local=None
  self.hdr_min=bind_theme(tk.Label(self.root, text='—', font=('Segoe UI', 10), bg=theme_color('surface'), fg=theme_color('muted'), cursor='hand2'), background='surface', foreground='muted')
  self.hdr_max=bind_theme(tk.Label(self.root, text='□', font=('Segoe UI', 9), bg=theme_color('surface'), fg=theme_color('muted'), cursor='hand2'), background='surface', foreground='muted')
  self.hdr_min.bind('<Button-1>',lambda _e:self._minimize_window())
  self.hdr_max.bind('<Button-1>',lambda _e:self._maximize_window())
  h.create_window(0,0,window=self.hdr_min,anchor='e',tags='window-min')
  h.create_window(0,0,window=self.hdr_max,anchor='e',tags='window-max')
  self.hdr_uninstall=bind_theme(tk.Button(self.root, text='Atualizar', font=('Segoe UI', 8), bg=theme_color('alt'), fg=theme_color('muted'), activeforeground=theme_color('text'), activebackground=theme_color('alt'), relief='flat', bd=0, cursor='hand2', command=self.update_agent), background='alt', foreground='muted', activeforeground='text', activebackground='alt');h.create_window(0, 0, window=self.hdr_uninstall, anchor='e', tags='uninstall')
  for _ in range(56):self._hdr_segs.append(bind_canvas(h, h.create_rectangle(0, 0, 0, 0, fill=theme_color('accent'), outline=''), fill='accent'))
  h.bind('<Configure>',lambda _e:self._hdr_layout())
  h.bind('<ButtonPress-1>',self._header_drag_start)
  h.bind('<B1-Motion>',self._header_drag_move)

 def _header_drag_start(self,e):
  self._drag_origin=(e.x_root,e.y_root,self.root.winfo_x(),self.root.winfo_y())

 def _header_drag_move(self,e):
  if getattr(self,'_custom_maximized',False):return
  if not hasattr(self,'_drag_origin'):return
  ox,oy,x,y=self._drag_origin
  self.root.geometry(f'+{x+e.x_root-ox}+{y+e.y_root-oy}')

 def _hdr_layout(self):
  try:
   h=self.hdr;w=max(300,h.winfo_width());n=len(self._hdr_segs);sw=w/float(n)
   for i,s in enumerate(self._hdr_segs):h.coords(s,i*sw,57,(i+1)*sw+1,60)
   h.coords(self.hdr_version,w-112,17);h.coords('window-min',w-54,17);h.coords('window-max',w-28,17);h.coords('uninstall',w-17,41)
   # Indicador duplicado de comunicação removido.
  except (tk.TclError,AttributeError):pass
 def _animate_header(self):
  if self.stop_event.is_set():return
  try:
   h=self.hdr;self._hdr_phase+=0.11;w=max(300,h.winfo_width());n=len(self._hdr_segs)
   for i,s in enumerate(self._hdr_segs):
    k=(math.sin(self._hdr_phase+i*0.24)+1)/2
    h.itemconfigure(s, fill=self._mix(theme_color('alt'), self._mix(theme_color('accent'), theme_color('violet'), k), 0.35 + 0.65 * k))
   # Status da comunicação permanece no rodapé do aplicativo.
  except (tk.TclError,AttributeError):return
  self.root.after(70,self._animate_header)
 # ---- Central de Comandos -------------------------------------------------
 def _build_command_center(self):
  """Prompt com digitação dentro da área preta; mesmas ações da aba web."""
  p=self.command_page;self.cmd_cards={}
  quick=ttk.LabelFrame(p,text='Verificações rápidas',padding=6);quick.pack(fill='x',padx=10,pady=(6,0))
  for index,(name,label) in enumerate([('quick_health','Internet'),('dns_test','Sites'),('system_snapshot','Recursos'),('storage_health','Discos'),('service_health','Serviços'),('restart_readiness','Reiniciar?')]):
   quick.columnconfigure(index%3,weight=1)
   ttk.Button(quick,text=label,command=lambda key=name:self.run_quick(key)).grid(row=index//3,column=index%3,sticky='ew',padx=3,pady=3)

  outer=bind_theme(tk.Frame(p, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1), background='surface', highlightbackground='border')
  outer.pack(fill='both',expand=True,padx=12,pady=(10,11))
  title=bind_theme(tk.Frame(outer, bg=theme_color('alt'), height=36), background='alt');title.pack(fill='x');title.pack_propagate(False)
  bind_theme(tk.Label(title, text='▣  Arcoll: Prompt de Comando - Agente Windows', bg=theme_color('alt'), fg=theme_color('text'), font=('Segoe UI', 10), anchor='w'), background='alt', foreground='text').pack(side='left', fill='x', expand=True, padx=12)
  controls=bind_theme(tk.Frame(outer, bg=theme_color('surface')), background='surface');controls.pack(fill='x')
  for label,raw in (('Teste DNS','nslookup google.com'),('Detalhes Wi-Fi','netsh wlan show interfaces'),('Limpar cache DNS','ipconfig /flushdns')):
   self._cmd_shortcut_button(controls,label,raw)
  self.btn(controls,'Ctrl+C',self._cancel_local_diagnostic).pack(side='left',padx=3,pady=6)
  self.btn(controls,'Limpar tela',self._clear_command_screen).pack(side='left',padx=3,pady=6)
  self.cmd_status=bind_theme(tk.Label(controls, text='Pronto', bg=theme_color('surface'), fg=theme_color('muted'), font=('Segoe UI', 9)), background='surface', foreground='muted');self.cmd_status.pack(side='right', padx=10)
  console_area=bind_theme(tk.Frame(outer, bg=theme_color('surface')), background='surface');console_area.pack(fill='both', expand=True)
  self.cmd_console=bind_theme(tk.Text(console_area, bg=theme_color('surface'), fg=theme_color('text'), insertbackground=theme_color('text'), font=('Consolas', 10), wrap='word', relief='flat', highlightthickness=0, padx=11, pady=10, spacing1=0, spacing3=0), background='surface', foreground='text', insertbackground='text')
  self.cmd_console.pack(fill='both',expand=True)
  self.cmd_console.configure(state='disabled')
  for tag,col in (('ok',theme_color('success')),('bad',theme_color('danger')),('info',theme_color('text')),('time',theme_color('alt'))):self.cmd_console.tag_configure(tag, foreground=col)
  prompt=bind_theme(tk.Frame(console_area, bg=theme_color('surface')), background='surface');prompt.pack(fill='x', padx=11, pady=(0, 12))
  bind_theme(tk.Label(prompt, text='C:\\ARCOLL> ', bg=theme_color('surface'), fg=theme_color('text'), font=('Consolas', 10)), background='surface', foreground='text').pack(side='left')
  self.cmd_input=bind_theme(tk.Entry(prompt, bg=theme_color('surface'), fg=theme_color('text'), insertbackground=theme_color('text'), relief='flat', highlightthickness=0, bd=0, font=('Consolas', 10)), background='surface', foreground='text', insertbackground='text')
  self.cmd_input.pack(side='left',fill='x',expand=True)
  self.cmd_input.bind('<Return>',self._command_enter)
  self.cmd_input.bind('<Up>',self._command_history_up)
  self.cmd_input.bind('<Down>',self._command_history_down)
  self.cmd_console.bind('<ButtonRelease-1>',lambda _e:self.cmd_input.focus_set())
  self.cmd_history=[];self.cmd_history_index=0;self._active_diagnostic_proc=None
  foot=bind_theme(tk.Frame(outer, bg=theme_color('alt'), height=24), background='alt');foot.pack(fill='x');foot.pack_propagate(False)
  bind_theme(tk.Label(foot, text='Comandos de diagnóstico autorizados · Digite ajuda', bg=theme_color('alt'), fg=theme_color('muted'), font=('Segoe UI', 8)), background='alt', foreground='muted').pack(side='left', padx=10)
  bind_theme(tk.Label(foot, text='AGENTE ' + SITE_CODE + '  ·  v' + AGENT_VERSION, bg=theme_color('alt'), fg=theme_color('muted'), font=('Consolas', 8)), background='alt', foreground='muted').pack(side='right', padx=10)
  self._console_write('',"Arcoll Speed Connection - Prompt de Diagnóstico Windows\n(c) Arcoll Digital. Operações autorizadas e auditadas.\nDigite ajuda para consultar os comandos disponíveis.\n",'info')
  self.cmd_input.focus_set()

 def _cmd_shortcut_button(self,parent,label,command):
  b=self.btn(parent,label,lambda r=command:self._submit_desktop_command(r))
  b.pack(side='left',padx=(6,0),pady=6)
  return b

 def _clear_command_screen(self):
  self.cmd_console.configure(state='normal');self.cmd_console.delete('1.0','end');self.cmd_console.configure(state='disabled');self.cmd_input.focus_set()

 def _command_history_up(self,_event):
  if self.cmd_history:self.cmd_history_index=max(0,self.cmd_history_index-1);self.cmd_input.delete(0,'end');self.cmd_input.insert(0,self.cmd_history[self.cmd_history_index])
  return 'break'

 def _command_history_down(self,_event):
  if self.cmd_history:self.cmd_history_index=min(len(self.cmd_history),self.cmd_history_index+1);self.cmd_input.delete(0,'end');self.cmd_input.insert(0,self.cmd_history[self.cmd_history_index] if self.cmd_history_index<len(self.cmd_history) else '')
  return 'break'

 def _command_enter(self,_event):
  raw=self.cmd_input.get().strip();self.cmd_input.delete(0,'end');self._submit_desktop_command(raw);return 'break'

 def _submit_desktop_command(self,raw):
  raw=' '.join(str(raw).strip().split())
  if not raw:return
  self.cmd_history.append(raw);self.cmd_history=self.cmd_history[-70:];self.cmd_history_index=len(self.cmd_history)
  if raw.lower() in ('cls','limpar'):
   self._clear_command_screen();return
  self._console_write('', 'C:\\ARCOLL> '+raw,'info')
  if raw.lower() in ('ajuda','help'):
   self._console_write('', 'ping 8.8.8.8 | ping -n 3 google.com | ping -t google.com\ntracert -d 1.1.1.1 | nslookup google.com | ipconfig /all\narp -a | route print | netsh wlan show interfaces | hostname\nipconfig /flushdns | cls | ajuda','info');return
  if raw.lower()=='ipconfig /flushdns' and not messagebox.askyesno('Arcoll','Limpar o cache DNS deste computador?',parent=self.root):return
  if self._active_diagnostic_proc and self._active_diagnostic_proc.poll() is None:
   self._console_write('','Aguarde o comando atual terminar ou interrompa com Ctrl+C.','bad');return
  self._run_terminal_diagnostic(None,raw)

 def _cancel_local_diagnostic(self):
  proc=getattr(self,'_active_diagnostic_proc',None)
  if proc is not None and proc.poll() is None:
   try:proc.kill();self._console_write('','^C  Diagnóstico interrompido.','time')
   except OSError:pass
  self.cmd_input.focus_set()
 def _command_card(self,parent,item,row,col):
  key,icon,title,desc=item
  f=bind_theme(tk.Frame(parent, bg=theme_color('surface'), highlightbackground=theme_color('border'), highlightthickness=1, cursor='hand2'), background='surface', highlightbackground='border')
  f.grid(row=row,column=col,sticky='nsew',padx=6,pady=6)
  ic=bind_theme(tk.Label(f, text=icon, font=('Segoe UI Emoji', 20), bg=theme_color('surface'), fg=theme_color('accent')), background='surface', foreground='accent');ic.pack(anchor='w', padx=14, pady=(12, 0))
  tt=bind_theme(tk.Label(f, text=title, font=('Segoe UI', 11, 'bold'), bg=theme_color('surface'), fg=theme_color('text')), background='surface', foreground='text');tt.pack(anchor='w', padx=14)
  ds=bind_theme(tk.Label(f, text=desc, font=('Segoe UI', 8), bg=theme_color('surface'), fg=theme_color('muted'), wraplength=300, justify='left'), background='surface', foreground='muted');ds.pack(anchor='w', padx=14)
  st=bind_theme(tk.Label(f, text='PRONTO', font=('Segoe UI', 8, 'bold'), bg=theme_color('surface'), fg=theme_color('muted'), wraplength=300, justify='left'), background='surface', foreground='muted');st.pack(anchor='w', padx=14, pady=(6, 4))
  bar=bind_theme(tk.Canvas(f, height=3, bg=theme_color('surface'), highlightthickness=0), background='surface');bar.pack(fill='x', side='bottom')
  card={'frame':f,'labels':[ic,tt,ds,st],'state':st,'bar':bar,'mode':'idle','pos':0,'hover':0.0,'hd':0}
  self.cmd_cards[key]=card
  def hover(d):
   card['hd']=d
  for w in [f,ic,tt,ds,st,bar]:
   w.bind('<Button-1>',lambda _e,k=key:self._card_click(k),add='+')
   w.bind('<Enter>',lambda _e:hover(1),add='+');w.bind('<Leave>',lambda _e:hover(-1),add='+')
 def _tick_cards(self):
  # Compatibilidade com o ciclo legado: os nove cartões antigos foram removidos.
  if not self.stop_event.is_set():self.root.after(60000,self._tick_cards)
 def _command_card_state(self,key,mode,text=''):
  c=getattr(self,'cmd_cards',{}).get(key)
  if not c:return
  c['mode']=mode
  label={'running':'EXECUTANDO…','ok':text or 'CONCLUÍDO','bad':text or 'FALHOU','idle':'PRONTO'}[mode]
  color={'running':theme_color('accent'),'ok':theme_color('success'),'bad':theme_color('danger'),'idle':theme_color('muted')}[mode]
  try:c['state'].configure(text=label[:150],fg=color)
  except tk.TclError:pass
 def _console_write(self,title,message,kind='info'):
  try:
   t=self.cmd_console;t.configure(state='normal')
   # A saída é a mesma do processo (sem carimbar cada linha com títulos artificiais).
   content=str(message).replace('\r\n','\n')
   if content:
    t.insert('end',content if kind=='raw' else content + ('' if content.endswith('\n') else '\n'),'info' if kind=='raw' else kind)
   t.see('end');t.configure(state='disabled')
  except (tk.TclError,AttributeError):pass
 def _card_click(self,key):
  if key in QUICK_COMMANDS:return self.run_quick(key)
  try:
   if key=='speed_test':self.nb.select(self.speed_page);return self.run_speed_test('full')
   if key=='scan_devices':self.nb.select(self.devices_page);return self.scan_devices()
   if key=='diagnostic':self.nb.select(self.connection_page);return self.run_diagnostics()
  except Exception as exc:self._console_write('Erro',str(exc)[:200],'bad')
 def run_quick(self,name,remote_id=None):
  if name not in QUICK_COMMANDS or run_quick_command is None:
   if remote_id:self._ack_remote_command(remote_id,'failed','Comando indisponível nesta versão do agente')
   return
  if self.quick_busy:
   if remote_id:self._ack_remote_command(remote_id,'failed','Outro comando rápido já está em andamento')
   else:bind_theme(self.cmd_status, foreground='warning').configure(text='Aguarde o comando atual terminar', fg=theme_color('warning'))
   return
  title=QUICK_COMMANDS[name][0];self.quick_busy=True
  self._command_card_state(name,'running');bind_theme(self.cmd_status, foreground='accent').configure(text='Executando: ' + title, fg=theme_color('accent'))
  self._operation_message('remote' if remote_id else 'local','executando '+title.lower())
  def work():
   res=run_quick_command(name)
   def done():
    self.quick_busy=False;good=res.get('status')=='completed'
    self._command_card_state(name,'ok' if good else 'bad',res['summary'])
    self._console_write(title,res['summary'],'ok' if good else 'bad')
    self.cmd_status.configure(text=title + (' concluído' if good else ' falhou') + ' · ' + time.strftime('%H:%M:%S'), fg=theme_color('success') if good else theme_color('danger'))
    self.log(title+': '+res['summary'],'INFO' if good else 'WARN')
    if not remote_id:(messagebox.showwarning if res.get('details',{}).get('level')=='warning' else messagebox.showinfo)(title,res['summary'],parent=self.root)
    self.sync_data({'event':'quick_command','command_name':name,'command_id':remote_id,'title':title,'status':res.get('status'),'summary':res['summary'],'details':res.get('details',{}),'origin':'platform' if remote_id else 'local','captured_at':int(time.time())})
    if remote_id:self._ack_remote_command(remote_id,'completed' if good else 'failed',res['summary'])
   self.root.after(0,done)
  threading.Thread(target=work,daemon=True).start()
 def close(self):
  if getattr(self,"_remote_screen_stop",None):self._remote_screen_stop.set()
  self.stop_event.set()
  self._presence_goodbye()
  if self._realtime:self._realtime.stop()
  try:
   if self.tray:self.tray.stop()
  except Exception:pass
  try:
   if self._focus_server:self._focus_server.close()
  except Exception:pass
  try:
   if self.agent_proc and self.agent_proc.poll() is None:self.agent_proc.terminate()
  except Exception:pass
  self.root.destroy()

if __name__=='__main__':
 if signal_running_instance():
  sys.exit(0)
 if not ORIGIN or not SITE_CODE or not DOWNLOAD_KEY:
  r=tk.Tk();r.withdraw();messagebox.showerror(APP_NAME,'A configuração do agente está incompleta. Baixe novamente o agente pela gestão de redes.');sys.exit(2)
 try:
  ArcollApp()
 except Exception:
  import traceback
  log_file=DATA_DIR/'agent-runtime.log'
  with log_file.open('a',encoding='utf-8') as f: traceback.print_exc(file=f)
  try:
   r=tk.Tk();r.withdraw()
   messagebox.showerror(APP_NAME,'Falha ao iniciar a interface. Consulte o registro:\n'+str(log_file))
   r.destroy()
  except Exception:pass
  raise