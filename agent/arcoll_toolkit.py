# Caminho: agent/arcoll_toolkit.py
"""Medições locais e operações Windows limitadas para o agente Arcoll."""
import os, platform, socket, subprocess, time, re, ipaddress, json, locale

VERSION = '3.3.1'

def _decode_bytes(value):
    if not value:return ''
    if isinstance(value,str):return value
    candidates=['utf-8-sig','cp850','cp1252',locale.getpreferredencoding(False)]
    for codec in candidates:
        try:return value.decode(codec,'strict')
        except (UnicodeError,LookupError):pass
    return value.decode('utf-8','replace')

def _exec(args, timeout=6):
    try:
        r=subprocess.run(args, capture_output=True, timeout=timeout,
                         creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        data=_decode_bytes(r.stdout or r.stderr).strip()
        return {'exit_code':r.returncode,'output':data[:12000]}
    except Exception as e:
        return {'error':str(e)[:180]}

def _ps(command):
    # UTF-8 explícito evita texto corrompido na leitura do PowerShell do Windows.
    setup="[Console]::OutputEncoding=[System.Text.UTF8Encoding]::new($false);$OutputEncoding=[Console]::OutputEncoding;"
    result=_exec(['powershell.exe','-NoProfile','-NonInteractive','-Command',setup+command],12)
    out=result.get('output','')
    if out and result.get('exit_code')==0:
        try: result['data']=json.loads(out)
        except (ValueError,TypeError):pass
    return result

def _probe(host):
    if platform.system()!='Windows':return {'status':'indisponível'}
    r=_exec(['ping','-n','2','-w','900',host],5)
    times=[float(x.replace(',','.')) for x in re.findall(r'(?:time|tempo)[=<]\s*([0-9]+(?:[.,][0-9]+)?)\s*ms',r.get('output',''),re.I)]
    return {'host':host,'response_count':len(times),'latency_ms':round(sum(times)/len(times),2) if times else None,'status':'responde' if times else 'sem resposta'}

def _path_check():
    # ICMP trace limited to a safe fixed public reference, never user-entered targets.
    return {'trace':_exec(['tracert','-d','-h','12','-w','650','1.1.1.1'],14)}

def _service_check():
    # Outbound TCP handshake tests to established HTTPS/DNS services.
    checks=[]
    for host,port in [('1.1.1.1',443),('1.1.1.1',53),('example.com',443)]:
        started=time.monotonic()
        try:
            with socket.create_connection((host,port),timeout=2.5):pass
            checks.append({'destination':host,'port':port,'reachable':True,'connect_ms':round((time.monotonic()-started)*1000,1)})
        except (OSError,TimeoutError) as e:
            checks.append({'destination':host,'port':port,'reachable':False,'error':type(e).__name__})
    return {'services':checks}

def _health_check():
    gateway=_ps('Get-NetRoute -DestinationPrefix "0.0.0.0/0" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty NextHop')
    gw=(gateway.get('output') or '').strip().splitlines()
    local=gw[0].strip() if gw else ''
    try: local=local if ipaddress.ip_address(local).is_private else ''
    except ValueError:local=''
    external=_probe('1.1.1.1');internal=_probe(local) if local else {'status':'indisponível'}
    dns=_ps('Resolve-DnsName example.com -Type A -ErrorAction Stop | Select-Object -First 1 -ExpandProperty IPAddress')
    facts={'external_ping':external,'gateway_ping':internal,'dns_lookup':dns}
    checks=[external.get('status')=='responde', dns.get('exit_code')==0]
    if local:checks.append(internal.get('status')=='responde')
    return {'score':round(100*sum(checks)/len(checks)),'successful_checks':sum(checks),'total_checks':len(checks),'checks':facts,'note':'Índice baseado somente nos testes executados, não é uma garantia de disponibilidade'}

def _troubleshoot():
    result=_health_check()
    issues=[]
    if result['checks']['external_ping'].get('status')!='responde':issues.append('Destino ICMP não respondeu; pode ser bloqueio de ICMP')
    if result['checks']['dns_lookup'].get('exit_code')!=0:issues.append('Resolução DNS de referência falhou')
    if result['checks']['gateway_ping'].get('status')=='sem resposta':issues.append('Gateway não respondeu ao ICMP')
    return {'findings':issues or ['Nenhuma falha identificada nos testes executados'],'measurements':result,'disclaimer':'Diagnóstico indicativo; não altera a configuração do Windows'}

TOOL_EXTRA={'traceroute':_path_check,'services':_service_check,'health':_health_check,'troubleshooter':_troubleshoot}

def collect_toolkit_selected(selected):
    if platform.system()!='Windows':raise RuntimeError('Coleta disponível no Windows')
    if selected not in TOOL_EXTRA:raise ValueError('Ferramenta não autorizada')
    return {'version':2,'machine':platform.node(),'captured_at':int(time.time()),'results':{selected:TOOL_EXTRA[selected]()},'selected':selected}

def collect_toolkit():
    if platform.system()!='Windows':raise RuntimeError('Coleta disponível no Windows')
    # No scanning, modification, arbitrary commands, public topology or external endpoints.
    routes=_ps('Get-NetRoute -AddressFamily IPv4 | Where-Object {$_.DestinationPrefix -eq "0.0.0.0/0"} | Select-Object -First 5 InterfaceAlias,NextHop,RouteMetric | ConvertTo-Json -Compress')
    gateways=[]
    for line in routes.get('output','').splitlines():
        gateways += re.findall(r'"NextHop"\s*:\s*"([0-9.]+)"',line)
    gateway=next((ip for ip in gateways if ip not in ('0.0.0.0','') and ipaddress.ip_address(ip).is_private),'')
    dns=_ps('Get-DnsClientServerAddress -AddressFamily IPv4 | Select-Object InterfaceAlias,ServerAddresses | ConvertTo-Json -Compress')
    test=_ps('Resolve-DnsName example.com -Type A -ErrorAction Stop | Where-Object {$_.IPAddress} | Select-Object -First 3 Name,IPAddress | ConvertTo-Json -Compress')
    return {'version':1,'machine':platform.node(),'captured_at':int(time.time()),'results':{
      'pulse':{'gateway':_probe(gateway) if gateway else {'status':'gateway não identificado'},'public_dns':_probe('1.1.1.1')},
      'gateway':{'default_route':routes},
      'dns':{'servers':dns,'example_lookup':test},
      'routing':{'routes':_ps('Get-NetRoute -AddressFamily IPv4 | Sort-Object RouteMetric | Select-Object -First 18 DestinationPrefix,NextHop,InterfaceAlias,RouteMetric | ConvertTo-Json -Compress')},
      'interfaces':{'adapters':_ps('Get-NetAdapter | Select-Object Name,Status,LinkSpeed,MacAddress,InterfaceDescription | ConvertTo-Json -Compress')},
      'system':{'os':platform.platform(),'hostname':platform.node(),'resources':_ps('Get-CimInstance Win32_OperatingSystem | Select-Object FreePhysicalMemory,TotalVisibleMemorySize,LastBootUpTime | ConvertTo-Json -Compress')},
      'firewall':{'profiles':_ps('Get-NetFirewallProfile | Select-Object Name,Enabled,DefaultInboundAction,DefaultOutboundAction | ConvertTo-Json -Compress')},
      'tcp':{'summary':_ps('Get-NetTCPConnection | Group-Object State | Select-Object Name,Count | ConvertTo-Json -Compress')},
      'wifi':{'interface':_exec(['netsh','wlan','show','interfaces'],6)},
      'dhcp':{'leases':_ps('Get-CimInstance Win32_NetworkAdapterConfiguration -Filter "IPEnabled=True" | Select-Object Description,DHCPEnabled,DHCPServer,DHCPLeaseObtained,DHCPLeaseExpires | ConvertTo-Json -Compress')},
      'ipconfig':{'addresses':_ps('Get-NetIPConfiguration | Select-Object -First 16 InterfaceAlias,@{n="IPv4";e={($_.IPv4Address.IPAddress -join ", ")}},@{n="IPv6";e={($_.IPv6Address.IPAddress -join ", ")}},@{n="Gateway";e={$_.IPv4DefaultGateway.NextHop}},@{n="DNS";e={($_.DNSServer.ServerAddresses -join ", ")}} | ConvertTo-Json -Compress')},
      'neighbors':{'arp':_ps('Get-NetNeighbor -AddressFamily IPv4 | Where-Object {$_.State -ne "Unreachable"} | Select-Object -First 50 InterfaceAlias,IPAddress,LinkLayerAddress,State | ConvertTo-Json -Compress')},
      'statistics':{'counters':_ps('Get-NetAdapterStatistics | Select-Object Name,ReceivedBytes,SentBytes,ReceivedPacketErrors,OutboundPacketErrors,ReceivedDiscardedPackets,OutboundDiscardedPackets | ConvertTo-Json -Compress')},
      'proxy':{'winhttp':_exec(['netsh','winhttp','show','proxy'],5)},
      'events':{'recent_network_events':_ps('Get-WinEvent -FilterHashtable @{LogName="System";Level=2,3;StartTime=(Get-Date).AddHours(-24)} -MaxEvents 30 -ErrorAction SilentlyContinue | Where-Object {$_.ProviderName -match "Tcpip|DNS|Dhcp|Network|WLAN|NDIS"} | Select-Object -First 12 TimeCreated,ProviderName,Id,LevelDisplayName,Message | ConvertTo-Json -Compress')},
      'uptime':{'system':_ps('Get-CimInstance Win32_OperatingSystem | Select-Object LastBootUpTime,LocalDateTime,Caption,Version | ConvertTo-Json -Compress')}
    }}

# ---------------------------------------------------------------------------
# Comandos rápidos (v2.4) - leitura/medição; a única ação que altera algo é
# limpar o cache DNS do próprio Windows. Nenhum alvo é informado pela web.
# ---------------------------------------------------------------------------
import urllib.request

def _fmt_ms(value):
    return '--' if value is None else (str(round(value)) + ' ms')

def _tcp_ms(host, port, timeout=2.5):
    started = time.monotonic()
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return round((time.monotonic() - started) * 1000, 1)
    except (OSError, TimeoutError):
        return None

def _dns_ms(name, timeout=3.0):
    started = time.monotonic()
    old = socket.getdefaulttimeout()
    try:
        socket.setdefaulttimeout(timeout)
        socket.getaddrinfo(name, 443, socket.AF_INET)
        return round((time.monotonic() - started) * 1000, 1)
    except OSError:
        return None
    finally:
        socket.setdefaulttimeout(old)

def _default_gateway():
    try:
        if platform.system() == 'Windows':
            out = _exec(['route', 'print', '-4', '0.0.0.0'], 4).get('output', '')
            for line in out.splitlines():
                p = line.split()
                if len(p) >= 3 and p[0] == '0.0.0.0' and p[1] == '0.0.0.0':
                    return p[2]
        else:
            m = re.search(r'default via (\S+)', _exec(['ip', 'route', 'show', 'default'], 3).get('output', ''))
            if m:
                return m.group(1)
    except Exception:
        pass
    return ''

def _grade(score):
    return 'Excelente' if score >= 90 else 'Boa' if score >= 70 else 'Instável' if score >= 40 else 'Crítica'

def quick_health():
    gw = _default_gateway()
    gw_ms = None
    if gw:
        for port in (80, 443, 53, 445):
            gw_ms = _tcp_ms(gw, port, 0.8)
            if gw_ms is not None:
                break
    inet = _tcp_ms('1.1.1.1', 443)
    dns = _dns_ms('example.com')
    checks = [inet is not None, dns is not None] + ([gw_ms is not None] if gw else [])
    score = round(100 * sum(checks) / len(checks))
    if inet is not None and inet > 150:
        score = max(0, score - 15)
    summary = 'Saúde %d/100 (%s) · Gateway %s · Internet %s · DNS %s' % (
        score, _grade(score), _fmt_ms(gw_ms) if gw else 'n/d', _fmt_ms(inet), _fmt_ms(dns))
    return {'status': 'completed', 'summary': summary, 'details': {'score': score, 'gateway': gw, 'gateway_ms': gw_ms, 'internet_ms': inet, 'dns_ms': dns}}

def dns_test():
    names = ['example.com', 'google.com', 'cloudflare.com', 'microsoft.com']
    rows = {n: _dns_ms(n) for n in names}
    ok = [v for v in rows.values() if v is not None]
    avg = round(sum(ok) / len(ok)) if ok else None
    summary = 'DNS: %d/%d nomes resolvidos · média %s · %s' % (
        len(ok), len(names), _fmt_ms(avg), ' · '.join('%s %s' % (k.split('.')[0], _fmt_ms(v)) for k, v in rows.items()))
    return {'status': 'completed' if ok else 'failed', 'summary': summary, 'details': rows}

def public_ip():
    for url in ('https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': 'ArcollAgent/2.4'})
            with urllib.request.urlopen(req, timeout=5) as r:
                value = r.read(64).decode('ascii', 'ignore').strip()
            ipaddress.ip_address(value)
            return {'status': 'completed', 'summary': 'IP público desta rede: ' + value, 'details': {'public_ip': value, 'source': url}}
        except Exception:
            continue
    return {'status': 'failed', 'summary': 'Não foi possível descobrir o IP público (sem acesso à internet?)', 'details': {}}

def system_snapshot():
    mem_total = mem_free = uptime = None
    if platform.system() == 'Windows':
        import ctypes
        class MEM(ctypes.Structure):
            _fields_ = [('l', ctypes.c_ulong), ('load', ctypes.c_ulong), ('tp', ctypes.c_ulonglong), ('ap', ctypes.c_ulonglong),
                        ('tpf', ctypes.c_ulonglong), ('apf', ctypes.c_ulonglong), ('tv', ctypes.c_ulonglong), ('av', ctypes.c_ulonglong), ('ae', ctypes.c_ulonglong)]
        m = MEM(); m.l = ctypes.sizeof(m)
        try:
            if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m)):
                mem_total, mem_free = int(m.tp), int(m.ap)
            uptime = int(ctypes.windll.kernel32.GetTickCount64() / 1000)
        except Exception:
            pass
    else:
        try:
            vals = {}
            for line in open('/proc/meminfo', encoding='ascii'):
                k, v = line.split(':', 1); vals[k] = int(v.split()[0]) * 1024
            mem_total, mem_free = vals.get('MemTotal'), vals.get('MemAvailable')
            uptime = int(float(open('/proc/uptime').read().split()[0]))
        except Exception:
            pass
    try:
        import shutil
        d = shutil.disk_usage(os.environ.get('SystemDrive', 'C:') + '\\' if platform.system() == 'Windows' else '/')
        disk_free, disk_total = d.free, d.total
    except Exception:
        disk_free = disk_total = None
    gb = lambda b: '--' if b is None else '%.1f GB' % (b / 1073741824)
    up = '--' if uptime is None else '%dd %02dh' % (uptime // 86400, (uptime % 86400) // 3600)
    used = None if not mem_total or mem_free is None else round(100 * (mem_total - mem_free) / mem_total)
    summary = '%s · %d núcleos · RAM %s (uso %s%%) · Disco livre %s de %s · Ligado há %s' % (
        platform.node(), os.cpu_count() or 0, gb(mem_total), '--' if used is None else used, gb(disk_free), gb(disk_total), up)
    return {'status': 'completed', 'summary': summary, 'details': {'host': platform.node(), 'os': platform.platform(), 'cores': os.cpu_count(), 'memory_total': mem_total, 'memory_free': mem_free, 'disk_total': disk_total, 'disk_free': disk_free, 'uptime_seconds': uptime}}

def flush_dns():
    if platform.system() != 'Windows':
        return {'status': 'failed', 'summary': 'Limpeza de DNS disponível apenas no Windows', 'details': {}}
    r = _exec(['ipconfig', '/flushdns'], 8)
    ok = r.get('exit_code') == 0
    return {'status': 'completed' if ok else 'failed', 'summary': 'Cache DNS do Windows limpo com sucesso' if ok else 'Não foi possível limpar o cache DNS (execute o agente como administrador)', 'details': r}

def wifi_info():
    if platform.system() != 'Windows':
        return {'status': 'failed', 'summary': 'Informações de Wi-Fi disponíveis apenas no Windows', 'details': {}}
    out = _exec(['netsh', 'wlan', 'show', 'interfaces'], 6).get('output', '')
    def pick(*keys):
        for line in out.splitlines():
            k, _, v = line.partition(':')
            if k.strip().lower() in keys and v.strip():
                return v.strip()
        return ''
    ssid, signal, radio, channel, rate = pick('ssid'), pick('signal', 'sinal'), pick('radio type', 'tipo de rádio', 'tipo de radio'), pick('channel', 'canal'), pick('receive rate (mbps)', 'taxa de recepção (mbps)', 'taxa de recepcao (mbps)')
    if not ssid:
        return {'status': 'completed', 'summary': 'Este computador não está conectado a uma rede Wi-Fi (cabo ou adaptador desligado)', 'details': {}}
    summary = 'Wi-Fi "%s" · sinal %s · %s · canal %s · %s Mbps' % (ssid, signal or '--', radio or '--', channel or '--', rate or '--')
    return {'status': 'completed', 'summary': summary, 'details': {'ssid': ssid, 'signal': signal, 'radio': radio, 'channel': channel, 'rate': rate}}

def storage_health():
    """Read-only capacity check; does not claim SMART or physical disk health."""
    import shutil
    volumes=[]
    if platform.system()=='Windows':
        result=_ps("Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | Select-Object DeviceID,Size,FreeSpace | ConvertTo-Json -Compress")
        data=result.get('data');data=data if isinstance(data,list) else [data] if isinstance(data,dict) else []
        for item in data:
            total=int(item.get('Size') or 0);free=int(item.get('FreeSpace') or 0)
            if total>0:volumes.append({'name':str(item.get('DeviceID') or 'Disco'),'total_bytes':total,'free_bytes':free,'free_percent':round(100*free/total,1)})
    else:
        disk=shutil.disk_usage('/');volumes=[{'name':'Sistema','total_bytes':disk.total,'free_bytes':disk.free,'free_percent':round(100*disk.free/disk.total,1)}]
    if not volumes:return {'status':'failed','summary':'Não foi possível consultar o espaço dos discos.','details':{}}
    low=[d for d in volumes if d['free_percent']<15]
    summary='Há pouco espaço em '+', '.join(d['name'] for d in low)+'. Libere arquivos antes que o disco fique cheio.' if low else 'Os discos têm espaço livre suficiente.'
    return {'status':'completed','summary':summary,'details':{'level':'warning' if low else 'good','volumes':volumes}}

def service_health():
    if platform.system()!='Windows':return {'status':'failed','summary':'Esta verificação está disponível no Windows.','details':{}}
    result=_ps("Get-CimInstance Win32_Service | Where-Object { $_.Name -in @('Winmgmt','Dnscache','Dhcp','LanmanWorkstation') } | Select-Object Name,State,StartMode | ConvertTo-Json -Compress")
    data=result.get('data');data=data if isinstance(data,list) else [data] if isinstance(data,dict) else []
    if not data:return {'status':'failed','summary':'Não foi possível verificar os serviços do Windows.','details':{}}
    labels={'Winmgmt':'Monitoramento do Windows','Dnscache':'Abertura de sites','Dhcp':'Configuração da conexão','LanmanWorkstation':'Pastas compartilhadas'}
    items=[{'name':labels.get(d.get('Name'),'Serviço do Windows'),'running':d.get('State')=='Running','expected':d.get('StartMode')=='Auto'} for d in data]
    stopped=[d for d in items if d['expected'] and not d['running']]
    return {'status':'completed','summary':'Um serviço importante está parado. Peça ao responsável para conferir.' if stopped else 'Os serviços essenciais estão funcionando conforme sua configuração.','details':{'level':'warning' if stopped else 'good','services':items}}

def restart_readiness():
    if platform.system()!='Windows':return {'status':'failed','summary':'Esta verificação está disponível no Windows.','details':{}}
    import winreg
    pending=False;checked=0
    keys=[(r'SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending',None),(r'SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired',None),(r'SYSTEM\CurrentControlSet\Control\Session Manager','PendingFileRenameOperations')]
    for path,value in keys:
        try:
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE,path,0,winreg.KEY_READ|winreg.KEY_WOW64_64KEY) as key:
                pending=bool(winreg.QueryValueEx(key,value)[0]) or pending if value else True
            checked+=1
        except FileNotFoundError:checked+=1
        except OSError:pass
    if checked!=len(keys):return {'status':'failed','summary':'O Windows não permitiu conferir todos os avisos de reinicialização.','details':{}}
    return {'status':'completed','summary':'O Windows precisa reiniciar para concluir uma alteração. Combine um horário com o usuário.' if pending else 'O Windows não informou reinicialização pendente.','details':{'level':'warning' if pending else 'good','restart_pending':pending}}

def friendly_error(error):
    text=str(error)
    if any(word in text.lower() for word in ('permission denied','access is denied')):return 'Sem permissão para ler ou salvar o arquivo. Escolha outra pasta ou peça ajuda ao responsável.'
    if 'no such file' in text.lower():return 'Arquivo não encontrado. Escolha o arquivo novamente.'
    if any(word in text.lower() for word in ('certificate','ssl','tls')):return 'Não foi possível confirmar a segurança da conexão. Confira a data do computador e o certificado do site.'
    if any(word in text.lower() for word in ('timeout','timed out','connection','network','urlopen','name resolution')):return 'A conexão demorou ou caiu. Confira a internet e tente novamente.'
    if text.startswith(('Não ','Acesso ','Sessão ','Código ','Conta ','Limite ','Selecione ','Computador ','Agente ','Escolha ','Arquivo ','Transferência ','O arquivo ')) and not any(word in text for word in ('Traceback','SQLSTATE','{')):return text[:250]
    return 'Não foi possível concluir. Confira a conexão e tente novamente.'

def schedule_power_action(action):
    """Fixed local Windows actions only. Never executes a hostname or arbitrary shell text."""
    if platform.system() != 'Windows':
        raise RuntimeError('Controle de energia disponível apenas no Windows.')
    flags = {'power_shutdown': ['/s', '/t', '60'], 'power_restart': ['/r', '/t', '60'], 'power_cancel': ['/a']}
    if action not in flags:
        raise ValueError('Operação de energia inválida.')
    executable = os.path.join(os.environ.get('SystemRoot', r'C:\Windows'), 'System32', 'shutdown.exe')
    result = subprocess.run([executable] + flags[action], capture_output=True, timeout=10, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    if result.returncode != 0:
        raise RuntimeError('O Windows não autorizou a operação ou não há desligamento agendado.')
    return 'Agendamento cancelado.' if action == 'power_cancel' else 'Operação agendada para daqui a 60 segundos. Salve os arquivos abertos. O Windows pode adiar se houver trabalho não salvo.'


QUICK_COMMANDS = {
    'storage_health': ('Espaço dos discos', storage_health),
    'service_health': ('Serviços essenciais', service_health),
    'restart_readiness': ('Precisa reiniciar?', restart_readiness),
    'quick_health': ('Saúde rápida da rede', quick_health),
    'dns_test': ('Teste de DNS', dns_test),
    'public_ip': ('IP público', public_ip),
    'system_snapshot': ('Recursos do computador', system_snapshot),
    'flush_dns': ('Limpar cache DNS', flush_dns),
    'wifi_info': ('Sinal Wi-Fi', wifi_info),
}

def run_quick_command(name):
    """Executa um comando rápido autorizado e devolve resumo curto (<= 480 caracteres)."""
    if name not in QUICK_COMMANDS:
        raise ValueError('Comando não autorizado')
    try:
        result = QUICK_COMMANDS[name][1]()
    except Exception as exc:
        result = {'status': 'failed', 'summary': friendly_error(exc), 'details': {}}
    result['summary'] = str(result.get('summary', ''))[:480]
    result['title'] = QUICK_COMMANDS[name][0]
    result['captured_at'] = int(time.time())
    return result

# Theme roles are declared by the original UI components, not inferred from colors.
_CURRENT_PALETTE = None

def theme_color(role):
    global _CURRENT_PALETTE
    if _CURRENT_PALETTE is None:
        choice=AppearanceManager.read_choice()
        _CURRENT_PALETTE=AppearanceManager.PALETTES[AppearanceManager.system_theme() if choice=='auto' else choice]
    return _CURRENT_PALETTE[role]

def bind_theme(widget, **roles):
    declared=getattr(widget,'_arc_theme_roles',{})
    declared.update(roles);widget._arc_theme_roles=declared
    return widget

def bind_canvas(canvas,item,**roles):
    declared=getattr(canvas,'_arc_canvas_roles',{})
    declared[item]=roles;canvas._arc_canvas_roles=declared
    return item

class AppearanceManager:
    PALETTES = {
        'light': {'bg':'#edf2f7','surface':'#ffffff','alt':'#f4f7fb','text':'#203249','muted':'#5d7189','border':'#d8e2ed','accent':'#087eac','onaccent':'#ffffff','success':'#12856b','danger':'#bc4356','warning':'#a86b12','violet':'#6c51bf'},
        'dark': {'bg':'#0b1420','surface':'#111f30','alt':'#182a40','text':'#e5edf8','muted':'#9db0c8','border':'#2b4059','accent':'#47b9e5','onaccent':'#071521','success':'#40c6a2','danger':'#f28291','warning':'#e6b95c','violet':'#b5a3ee'},
    }
    LABELS={'auto':'Automático','light':'Claro','dark':'Escuro'}

    @staticmethod
    def preference_path():
        from pathlib import Path
        return Path(os.environ.get('APPDATA',str(Path.home()))) / 'Arcoll' / 'appearance.json'

    @classmethod
    def read_choice(cls):
        try:
            data=json.loads(cls.preference_path().read_text(encoding='utf-8'))
            value=data.get('theme','auto') if isinstance(data,dict) else 'auto'
            return value if value in cls.LABELS else 'auto'
        except (OSError,ValueError,TypeError):return 'auto'

    @staticmethod
    def system_theme():
        if platform.system()=='Windows':
            try:
                import winreg
                with winreg.OpenKey(winreg.HKEY_CURRENT_USER,r'Software\Microsoft\Windows\CurrentVersion\Themes\Personalize') as key:
                    return 'light' if winreg.QueryValueEx(key,'AppsUseLightTheme')[0] else 'dark'
            except OSError:return 'light'
        if platform.system()=='Darwin':
            try:
                value=subprocess.run(['defaults','read','-g','AppleInterfaceStyle'],capture_output=True,text=True,timeout=1)
                return 'dark' if 'dark' in value.stdout.lower() else 'light'
            except (OSError,subprocess.TimeoutExpired):return 'light'
        return 'dark' if 'dark' in os.environ.get('GTK_THEME','').lower() else 'light'

    def __init__(self,root,version='',controls=True,restyle=None):
        import tkinter as tk
        from tkinter import ttk
        self.root=root;self.choice=self.read_choice();self.resolved=None;self.restyle=restyle;self.selected=None
        self.apply()
        if controls:
            bar=bind_theme(tk.Frame(root,height=36,bg=theme_color('surface')),background='surface')
            packed=next((child for child in root.winfo_children() if child is not bar and child.winfo_manager()=='pack'),None)
            if packed:bar.pack(side='bottom',fill='x',before=packed)
            else:bar.pack(side='bottom',fill='x')
            bind_theme(tk.Label(bar,text='Aparência',font=('Segoe UI',9),bg=theme_color('surface'),fg=theme_color('text')),background='surface',foreground='text').pack(side='left',padx=(12,5))
            self.selected=tk.StringVar(value=self.LABELS[self.choice])
            select=ttk.Combobox(bar,textvariable=self.selected,values=list(self.LABELS.values()),state='readonly',width=12)
            select.pack(side='left',pady=4)
            select.bind('<<ComboboxSelected>>',lambda _event:self.set_choice(next(key for key,value in self.LABELS.items() if value==self.selected.get())))
            if version:bind_theme(tk.Label(bar,text='Arcoll · v'+version,font=('Segoe UI',8),bg=theme_color('surface'),fg=theme_color('muted')),background='surface',foreground='muted').pack(side='right',padx=12)
        root.after(1800,self.poll)

    def set_choice(self,choice):
        if choice not in self.LABELS:raise ValueError('Tema inválido')
        import tempfile
        path=self.preference_path();path.parent.mkdir(parents=True,exist_ok=True);temporary=None
        try:
            with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8',dir=str(path.parent),delete=False) as stream:
                temporary=stream.name;json.dump({'theme':choice},stream)
            os.replace(temporary,path)
        finally:
            if temporary and os.path.exists(temporary):os.unlink(temporary)
        self.choice=choice;self.apply()

    def apply(self):
        global _CURRENT_PALETTE
        import tkinter as tk
        from tkinter import ttk
        self.resolved=self.system_theme() if self.choice=='auto' else self.choice
        _CURRENT_PALETTE=self.PALETTES[self.resolved]
        def apply_declared(widget):
            roles=getattr(widget,'_arc_theme_roles',{})
            if roles:widget.configure(**{name:theme_color(role) for name,role in roles.items()})
            items=getattr(widget,'_arc_canvas_roles',{})
            if items:
                alive=set(widget.find_all());widget._arc_canvas_roles={item:roles for item,roles in items.items() if item in alive}
                for item,roles in widget._arc_canvas_roles.items():widget.itemconfigure(item,**{name:theme_color(role) for name,role in roles.items()})
            for child in widget.winfo_children():apply_declared(child)
        try:
            apply_declared(self.root)
            if self.restyle:self.restyle()
            else:
                p=_CURRENT_PALETTE;style=ttk.Style(self.root);style.theme_use('clam')
                for name in ('TFrame','TLabel','TNotebook','TNotebook.Tab','TCombobox','Arcoll.TCombobox','Treeview','Treeview.Heading','TProgressbar','Vertical.TScrollbar'):
                    style.configure(name,background=p['surface'],foreground=p['text'],fieldbackground=p['alt'],troughcolor=p['bg'],bordercolor=p['border'])
            if self.selected:self.selected.set(self.LABELS[self.choice])
        except tk.TclError:return

    def poll(self):
        import tkinter as tk
        try:
            saved=self.read_choice();resolved=self.system_theme() if saved=='auto' else saved
            if saved!=self.choice or resolved!=self.resolved:self.choice=saved;self.apply()
            self.root.after(1800,self.poll)
        except tk.TclError:return

# Authenticated file transport: fixed platform endpoint, bounded reads, verified HTTPS, no redirects.
def transfer_request(origin, action, auth, transfer_id, payload=None, binary=False, filename=''):
    import hashlib
    import urllib.request
    import urllib.parse
    import urllib.error
    allowed={'file-agent-details','file-agent-download','file-agent-upload','file-agent-finish'}
    parsed=urllib.parse.urlsplit(origin)
    if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or action not in allowed:
        raise ValueError('Endereço da plataforma ou operação inválida.')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):return None
    headers={'X-Arcoll-Fingerprint':auth['fingerprint'],'X-Arcoll-Site':auth['site_code'],'X-Arcoll-Key':auth['download_key'],'Accept':'application/json'}
    if isinstance(payload,bytes):
        if len(payload)>8388608:raise ValueError('O arquivo excede 8 MB.')
        data=payload;headers.update({'Content-Type':'application/octet-stream','X-File-Name':urllib.parse.quote(filename),'X-File-Sha256':hashlib.sha256(payload).hexdigest()})
    else:data=json.dumps(payload or {}).encode('utf-8');headers['Content-Type']='application/json'
    url=origin.rstrip('/')+'/api/support.php?action='+action+'&id='+str(int(transfer_id))
    request=urllib.request.Request(url,data=data,headers=headers,method='POST')
    try:
        with urllib.request.build_opener(NoRedirect).open(request,timeout=60) as response:
            limit=8388608 if binary else 65536;raw=response.read(limit+1)
            if response.geturl()!=url or len(raw)>limit:raise RuntimeError('Resposta inválida ou arquivo maior que o limite.')
            if binary:return raw
            result=json.loads(raw.decode('utf-8'))
            if not result.get('ok'):raise RuntimeError(result.get('error') or 'Transferência indisponível.')
            return result
    except urllib.error.HTTPError as exc:
        try:message=json.loads(exc.read(4096).decode('utf-8')).get('error')
        except Exception:message=None
        raise RuntimeError(message or 'A plataforma não autorizou a transferência.') from None
