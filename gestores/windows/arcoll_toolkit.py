# Caminho: gestores/windows/arcoll_toolkit.py
"""Medições locais e operações Windows limitadas para o gestor Arcoll."""
import os, platform, socket, subprocess, time, re, ipaddress, json, locale
from pathlib import Path

from arcoll_release import VERSION

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
            req = urllib.request.Request(url, headers={'User-Agent': 'ArcollGestor/2.4'})
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
    return {'status': 'completed' if ok else 'failed', 'summary': 'Cache DNS do Windows limpo com sucesso' if ok else 'Não foi possível limpar o cache DNS (execute o gestor como administrador)', 'details': r}

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
    if text.startswith(('Não ','Acesso ','Sessão ','Código ','Conta ','Limite ','Selecione ','Computador ','Gestor ','Escolha ','Arquivo ','Transferência ','O arquivo ','A plataforma ','Conexão ')) and not any(word in text for word in ('Traceback','SQLSTATE','{')):return text[:250]
    return 'Não foi possível concluir. Confira a conexão e tente novamente.'

def schedule_power_action(action,delay_seconds=60):
    """Fixed local Windows actions only. Never executes a hostname or arbitrary shell text."""
    if platform.system() != 'Windows':
        raise RuntimeError('Controle de energia disponível apenas no Windows.')
    delay_seconds = int(delay_seconds)
    if not 5 <= delay_seconds <= 3600: raise ValueError('Tempo de espera inválido.')
    flags = {'power_shutdown': ['/s', '/t', str(delay_seconds)], 'power_restart': ['/r', '/t', str(delay_seconds)], 'power_cancel': ['/a']}
    if action not in flags:
        raise ValueError('Operação de energia inválida.')
    executable = os.path.join(os.environ.get('SystemRoot', r'C:\Windows'), 'System32', 'shutdown.exe')
    result = subprocess.run([executable] + flags[action], capture_output=True, timeout=10, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    if result.returncode != 0:
        raise RuntimeError('O Windows não autorizou a operação ou não há desligamento agendado.')
    return 'Agendamento cancelado.' if action == 'power_cancel' else f'Operação agendada para daqui a {delay_seconds} segundos. Salve os arquivos abertos. O Windows pode adiar se houver trabalho não salvo.'


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
    # Temas vinculados ao catálogo da plataforma; sem dependências gráficas extras.
    PALETTES['windows'] = {'bg':'#edf3fa','surface':'#ffffff','alt':'#e3ebf5','text':'#172b44','muted':'#536982','border':'#d0ddea','accent':'#2563eb','onaccent':'#ffffff','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    PALETTES['ocean'] = {'bg':'#081c30','surface':'#112b44','alt':'#193851','text':'#e7f7ff','muted':'#a7c1d5','border':'#315169','accent':'#26bae9','onaccent':'#071521','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    PALETTES['crystal'] = {'bg':'#f2f5fa','surface':'#ffffff','alt':'#e9edf7','text':'#222842','muted':'#64718c','border':'#dce2ee','accent':'#6366f1','onaccent':'#ffffff','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    PALETTES['graphite'] = {'bg':'#16191f','surface':'#242a34','alt':'#303744','text':'#f1f4fa','muted':'#b0bbc9','border':'#485364','accent':'#9aaee5','onaccent':'#071521','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    PALETTES['neon'] = {'bg':'#110e27','surface':'#221a40','alt':'#302551','text':'#f5f0ff','muted':'#c5b4e3','border':'#534474','accent':'#a879ff','onaccent':'#ffffff','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    PALETTES['emerald'] = {'bg':'#0b211d','surface':'#15352d','alt':'#20463a','text':'#e6fcf3','muted':'#acd5c5','border':'#386352','accent':'#32d4a1','onaccent':'#071521','success':'#32c99a','danger':'#ef7886','warning':'#e9b75e','violet':'#ad83f5'}
    # Cores de fallback geradas do catálogo central 5.0.152.
    PALETTES['windows'] = {'bg': '#eef3fb', 'surface': '#ffffff', 'alt': '#e4edf9', 'text': '#172b45', 'muted': '#526782', 'border': '#c8d7eb', 'accent': '#225bce', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'violet': '#225bce'}
    PALETTES['ocean'] = {'bg': '#071b2b', 'surface': '#102c42', 'alt': '#1a3b51', 'text': '#eaf8ff', 'muted': '#abcbdc', 'border': '#35566c', 'accent': '#35c7ed', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#35c7ed'}
    PALETTES['crystal'] = {'bg': '#f2f2fa', 'surface': '#ffffff', 'alt': '#e9e9f6', 'text': '#24243e', 'muted': '#626281', 'border': '#d6d5ec', 'accent': '#5b56d6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'violet': '#5b56d6'}
    PALETTES['graphite'] = {'bg': '#171b23', 'surface': '#252c38', 'alt': '#323c4c', 'text': '#f1f5fc', 'muted': '#b4bfd0', 'border': '#4b5a70', 'accent': '#a3baf5', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#a3baf5'}
    PALETTES['neon'] = {'bg': '#120f25', 'surface': '#241d3f', 'alt': '#342a55', 'text': '#f6f0ff', 'muted': '#c9bfe3', 'border': '#594a79', 'accent': '#ba9bff', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#ba9bff'}
    PALETTES['emerald'] = {'bg': '#0b211c', 'surface': '#15362c', 'alt': '#204c3d', 'text': '#eafcf4', 'muted': '#b0d1c0', 'border': '#3b6a56', 'accent': '#4bd6a6', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#4bd6a6'}
    PALETTES['sapphire'] = {'bg': '#0b172c', 'surface': '#172b49', 'alt': '#233f62', 'text': '#eff6ff', 'muted': '#b6c9e3', 'border': '#405c81', 'accent': '#8bb8ff', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#8bb8ff'}
    PALETTES['sandstone'] = {'bg': '#f6f1e9', 'surface': '#fffdfa', 'alt': '#ece3d7', 'text': '#332b25', 'muted': '#76675a', 'border': '#d8c8b7', 'accent': '#995034', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'violet': '#995034'}
    PALETTES['aurora'] = {'bg': '#0d202b', 'surface': '#17343e', 'alt': '#224d56', 'text': '#edfcf8', 'muted': '#b0d0cf', 'border': '#3e6d72', 'accent': '#73e1d0', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'violet': '#73e1d0'}
    PALETTES['slate'] = {'bg': '#eef2f6', 'surface': '#ffffff', 'alt': '#e2eaf2', 'text': '#233348', 'muted': '#5f7288', 'border': '#c9d5e3', 'accent': '#435f80', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'violet': '#435f80'}
    REMOTE_VARIANTS = {'windows': {'light': {'bg': '#eef3fb', 'surface': '#ffffff', 'alt': '#e4edf9', 'text': '#172b45', 'muted': '#526782', 'accent': '#225bce', 'border': '#c8d7eb', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#225bce', 'accent_soft': '#e4ebf9', 'hover': '#d3e0f5'}, 'dark': {'bg': '#0e1724', 'surface': '#172536', 'alt': '#24364a', 'text': '#edf5ff', 'muted': '#afc1d7', 'accent': '#6089dc', 'border': '#3c536e', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#6089dc', 'accent_soft': '#20314a', 'hover': '#293d57'}}, 'ocean': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#227f98', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#227f98', 'accent_soft': '#e4f0f3', 'hover': '#d3e3ee'}, 'dark': {'bg': '#071b2b', 'surface': '#102c42', 'alt': '#1a3b51', 'text': '#eaf8ff', 'muted': '#abcbdc', 'accent': '#35c7ed', 'border': '#35566c', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#35c7ed', 'accent_soft': '#143f57', 'hover': '#1c485f'}}, 'crystal': {'light': {'bg': '#f2f2fa', 'surface': '#ffffff', 'alt': '#e9e9f6', 'text': '#24243e', 'muted': '#626281', 'accent': '#5b56d6', 'border': '#d6d5ec', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#5b56d6', 'accent_soft': '#ebebfa', 'hover': '#dcdcf3'}, 'dark': {'bg': '#0e1724', 'surface': '#172536', 'alt': '#24364a', 'text': '#edf5ff', 'muted': '#afc1d7', 'accent': '#8480e0', 'border': '#3c536e', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#8480e0', 'accent_soft': '#24304a', 'hover': '#2d3d58'}}, 'graphite': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#67759a', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#67759a', 'accent_soft': '#edeef3', 'hover': '#dae2ee'}, 'dark': {'bg': '#171b23', 'surface': '#252c38', 'alt': '#323c4c', 'text': '#f1f5fc', 'muted': '#b4bfd0', 'accent': '#a3baf5', 'border': '#4b5a70', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#a3baf5', 'accent_soft': '#343d4f', 'hover': '#3c475b'}}, 'neon': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#806bb0', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#806bb0', 'accent_soft': '#f0edf6', 'hover': '#dce1f0'}, 'dark': {'bg': '#120f25', 'surface': '#241d3f', 'alt': '#342a55', 'text': '#f6f0ff', 'muted': '#c9bfe3', 'accent': '#ba9bff', 'border': '#594a79', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#ba9bff', 'accent_soft': '#362c56', 'hover': '#403464'}}, 'emerald': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#2e8365', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#2e8365', 'accent_soft': '#e6f0ed', 'hover': '#d5e3e9'}, 'dark': {'bg': '#0b211c', 'surface': '#15362c', 'alt': '#204c3d', 'text': '#eafcf4', 'muted': '#b0d1c0', 'accent': '#4bd6a6', 'border': '#3b6a56', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#4bd6a6', 'accent_soft': '#1b493b', 'hover': '#245846'}}, 'sapphire': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#5976a3', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#5976a3', 'accent_soft': '#ebeff4', 'hover': '#d8e2ef'}, 'dark': {'bg': '#0b172c', 'surface': '#172b49', 'alt': '#233f62', 'text': '#eff6ff', 'muted': '#b6c9e3', 'accent': '#8bb8ff', 'border': '#405c81', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#8bb8ff', 'accent_soft': '#253c5f', 'hover': '#2c4a70'}}, 'sandstone': {'light': {'bg': '#f6f1e9', 'surface': '#fffdfa', 'alt': '#ece3d7', 'text': '#332b25', 'muted': '#76675a', 'accent': '#995034', 'border': '#d8c8b7', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#995034', 'accent_soft': '#f3e8e2', 'hover': '#e5d6c8'}, 'dark': {'bg': '#0e1724', 'surface': '#172536', 'alt': '#24364a', 'text': '#edf5ff', 'muted': '#afc1d7', 'accent': '#b47e69', 'border': '#3c536e', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#b47e69', 'accent_soft': '#2a303c', 'hover': '#313c4d'}}, 'aurora': {'light': {'bg': '#f0f4f9', 'surface': '#ffffff', 'alt': '#e5edf6', 'text': '#203248', 'muted': '#5c7188', 'accent': '#428077', 'border': '#cbd8e6', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#428077', 'accent_soft': '#e8f0ef', 'hover': '#d6e3eb'}, 'dark': {'bg': '#0d202b', 'surface': '#17343e', 'alt': '#224d56', 'text': '#edfcf8', 'muted': '#b0d0cf', 'accent': '#73e1d0', 'border': '#3e6d72', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#73e1d0', 'accent_soft': '#224950', 'hover': '#295a61'}}, 'slate': {'light': {'bg': '#eef2f6', 'surface': '#ffffff', 'alt': '#e2eaf2', 'text': '#233348', 'muted': '#5f7288', 'accent': '#435f80', 'border': '#c9d5e3', 'onaccent': '#ffffff', 'success': '#168268', 'danger': '#be344d', 'warning': '#94610b', 'focus': '#435f80', 'accent_soft': '#e8ecf0', 'hover': '#d4dde8'}, 'dark': {'bg': '#0e1724', 'surface': '#172536', 'alt': '#24364a', 'text': '#edf5ff', 'muted': '#afc1d7', 'accent': '#7a8da5', 'border': '#3c536e', 'onaccent': '#091624', 'success': '#53d7ad', 'danger': '#ff9daa', 'warning': '#f3ca78', 'focus': '#7a8da5', 'accent_soft': '#233143', 'hover': '#2c3e52'}}}
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
        self.root=root;self.choice=self.read_choice();self.remote_choice=None;self.remote_mode='theme';self._system_mode=None;self.resolved=None;self.restyle=restyle;self.selected=None
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
        if self.remote_choice is not None:return
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

    @classmethod
    def remote_palette(cls, choice, mode):
        """Conserva a identidade do tema publicado ao alternar a luminosidade."""
        palette=dict(cls.PALETTES[choice])
        target=cls.system_theme() if mode=='auto' else mode
        if target not in ('light','dark'):target='light' if choice in ('windows','crystal','sandstone','slate') else 'dark'
        if choice in cls.REMOTE_VARIANTS and target in cls.REMOTE_VARIANTS[choice]:
            palette.update(cls.REMOTE_VARIANTS[choice][target]);return palette
        if target not in ('light','dark'):
            return palette
        already_light=choice in ('windows','crystal','sandstone','slate')
        if (target=='light')==already_light:
            return palette
        neutral=cls.PALETTES[target]
        for role in ('bg','surface','alt','text','muted','border'):
            palette[role]=neutral[role]
        return palette

    def set_published_theme(self, visual):
        if not isinstance(visual,dict):return False
        choice=visual.get('id');mode=visual.get('mode','theme')
        if not isinstance(choice,str) or not re.fullmatch(r'[a-z0-9_-]{1,40}',choice) or mode not in ('theme','auto','light','dark'):return False
        variants=visual.get('variants',{})
        if not isinstance(variants,dict):return False
        clean={}
        for variant in ('light','dark'):
            value=variants.get(variant)
            if not isinstance(value,dict) or any(not isinstance(value.get(k),str) or not re.fullmatch(r'#[0-9a-fA-F]{6}',value[k]) for k in ('bg','surface','alt','text','muted','border','accent','onaccent')):return False
            clean[variant]={k:v for k,v in value.items() if isinstance(v,str) and re.fullmatch(r'#[0-9a-fA-F]{6}',v)}
        before=self.REMOTE_VARIANTS.get(choice)
        self.REMOTE_VARIANTS[choice]=clean
        palette=dict(self.PALETTES['light' if visual.get('light') else 'dark']);palette.update(clean['light' if visual.get('light') else 'dark']);self.PALETTES[choice]=palette
        if before!=clean:self.remote_choice=None
        self.set_remote_theme(choice,mode)
        return True

    def set_remote_theme(self, choice, mode='theme'):
        if choice not in self.PALETTES or choice in ('light','dark'):
            return
        if mode not in ('theme','auto','light','dark'):
            return
        if (choice,mode)==(self.remote_choice,self.remote_mode):
            return
        self.remote_choice=choice
        self.remote_mode=mode
        self.apply()

    def apply(self):
        global _CURRENT_PALETTE
        import tkinter as tk
        from tkinter import ttk
        selected=self.remote_choice if self.remote_choice is not None else self.choice
        self.resolved=(self.system_theme() if self.remote_mode=='auto' else
                       (self.remote_mode if self.remote_mode in ('light','dark') else
                        ('light' if selected in ('windows','crystal','sandstone','slate','light') else 'dark'))) if self.remote_choice is not None else (self.system_theme() if selected=='auto' else selected)
        _CURRENT_PALETTE=(self.remote_palette(self.remote_choice,self.remote_mode)
                          if self.remote_choice is not None else self.PALETTES[self.resolved])
        self._system_mode=self.system_theme() if self.remote_mode=='auto' else None
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
            saved=self.read_choice() if self.remote_choice is None else self.choice
            selected=self.remote_choice if self.remote_choice is not None else saved
            # A identidade do tema (por exemplo "windows") não deve ser
            # comparada com o modo claro/escuro efetivamente aplicado.
            resolved=(self.system_theme() if self.remote_mode=='auto' else
                      (self.remote_mode if self.remote_mode in ('light','dark') else
                       ('light' if selected in ('windows','crystal','sandstone','slate','light') else 'dark'))) if self.remote_choice is not None else (self.system_theme() if selected=='auto' else selected)
            system_changed=(self.remote_choice is not None and self.remote_mode=='auto'
                            and self._system_mode!=self.system_theme())
            if saved!=self.choice or resolved!=self.resolved or system_changed:
                self.choice=saved
                self.apply()
            self.root.after(1800,self.poll)
        except tk.TclError:return

# Authenticated file transport: fixed platform endpoint, bounded reads, verified HTTPS, no redirects.
def transfer_request(origin, action, auth, transfer_id, payload=None, binary=False, filename=''):
    import hashlib
    import urllib.request
    import urllib.parse
    import urllib.error
    allowed={'file-gestor-details','file-gestor-download','file-gestor-upload','file-gestor-finish'}
    parsed=urllib.parse.urlsplit(origin)
    if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or action not in allowed:
        raise ValueError('Endereço da plataforma ou operação inválida.')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):return None
    # Mesma identificação do canal de comandos: hospedagens costumam bloquear o User-Agent padrão do Python (HTTP 403 sem JSON).
    headers={'X-Arcoll-Fingerprint':auth['fingerprint'],'X-Arcoll-Site':auth['site_code'],'X-Arcoll-Key':auth['download_key'],'Accept':'application/json','User-Agent':'ArcollDesktop/2.0'}
    if isinstance(payload,bytes):
        if len(payload)>8388608:raise ValueError('O arquivo excede 8 MB.')
        data=payload;headers.update({'Content-Type':'application/octet-stream','X-File-Name':urllib.parse.quote(filename),'X-File-Sha256':hashlib.sha256(payload).hexdigest()})
    else:data=json.dumps(payload or {}).encode('utf-8');headers['Content-Type']='application/json'
    url=origin.rstrip('/')+'/api/support.php?action='+action+'&id='+str(int(transfer_id))
    request=urllib.request.Request(url,data=data,headers=headers,method='POST')
    import ssl
    deadline=time.monotonic()+140
    for attempt in range(3):
        try:
            with urllib.request.build_opener(NoRedirect).open(request,timeout=min(45,max(1,deadline-time.monotonic()))) as response:
                limit=8388608 if binary else 65536;raw=response.read(limit+1)
                if response.geturl()!=url or len(raw)>limit:raise RuntimeError('Resposta inválida ou arquivo maior que o limite.')
                if binary:return raw
                result=json.loads(raw.decode('utf-8'))
                if not result.get('ok'):raise RuntimeError(result.get('error') or 'Transferência indisponível.')
                return result
        except urllib.error.HTTPError as exc:
            try:message=json.loads(exc.read(4096).decode('utf-8')).get('error')
            except Exception:message=None
            code=exc.code;exc.close()
            if code not in (429,500,502,503,504) or attempt==2 or time.monotonic()>=deadline:raise RuntimeError(message or 'A plataforma recusou a transferência (HTTP '+str(code)+'). Confira o firewall da hospedagem para api/support.php.') from None
        except (urllib.error.URLError,TimeoutError,ConnectionError) as exc:
            reason=getattr(exc,'reason',exc)
            if isinstance(reason,ssl.SSLCertVerificationError) or attempt==2 or time.monotonic()>=deadline:raise RuntimeError('Conexão interrompida durante a transferência. Tente novamente quando o computador estiver conectado.') from None
        time.sleep(2**(attempt+1))


def apply_platform_design(design):
    """Apply validated public designer metadata to the original native theme roles."""
    global _CURRENT_PALETTE
    if not isinstance(design,dict):return
    accents={'azul':'#087eac','violeta':'#8b5cf6','verde':'#14836b','laranja':'#c36512'}
    accent=design.get('accent') if design.get('palette')=='personalizada' else accents.get(design.get('palette'),'#087eac')
    if not isinstance(accent,str) or not re.fullmatch(r'#[a-fA-F0-9]{6}',accent):return
    rgb=tuple(int(accent[i:i+2],16) for i in (1,3,5))
    def tint(base,weight):return '#'+''.join(f'{round(base*(1-weight)+v*weight):02x}' for v in rgb)
    for mode in ('light','dark'):
        p=dict(AppearanceManager.PALETTES[mode]);p.update(accent=accent,onaccent='#ffffff' if sum(rgb)<410 else '#071521',bg=tint(248,.04) if mode=='light' else tint(12,.12),surface=tint(255,.02) if mode=='light' else tint(20,.15),alt=tint(245,.05) if mode=='light' else tint(28,.2));AppearanceManager.PALETTES[mode]=p
    _CURRENT_PALETTE=None

def apply_platform_icon(root, origin, design):
    """Fetch only a bounded public icon with verified HTTPS; never send credentials."""
    import io,threading
    import urllib.request
    from urllib.parse import urlsplit
    from PIL import Image,ImageTk
    icon=design.get('icon') if isinstance(design,dict) else ''
    url=icon or origin.rstrip('/')+'/api/brand-logo.php?icon=192'
    parts=urlsplit(url)
    if parts.scheme!='https' or not parts.hostname or parts.username or parts.password or parts.port not in (None,443):return
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs):return None
    def work():
        try:
            with urllib.request.build_opener(NoRedirect()).open(url,timeout=8) as response:data=response.read(1572865)
            if len(data)>1572864:return
            image=Image.open(io.BytesIO(data));image.thumbnail((192,192));image.load()
            def show():
                try:
                    root._arc_brand_icon=ImageTk.PhotoImage(image);root.iconphoto(True,root._arc_brand_icon)
                    from pathlib import Path
                    try:image.save(Path(__file__).with_name('favicon.ico'),format='ICO',sizes=[(32,32),(64,64)])
                    except OSError:pass
                except Exception:pass
            root.after(0,show)
        except Exception:pass
    threading.Thread(target=work,daemon=True).start()

try:
    from pathlib import Path
    for _design_file in ('platform.json','gestor-config.json'):
        _design_path=Path(__file__).with_name(_design_file)
        if _design_path.is_file():
            apply_platform_design(json.loads(_design_path.read_text(encoding='utf-8')).get('design',{}));break
except (OSError,ValueError,AttributeError):pass

# The file explorer uses this one implementation for path validation and local operations.
def explorer_path(raw, *, directory=False, writable=False):
    import ntpath, stat
    from pathlib import Path
    value=str(raw or '')
    canonical=ntpath.normpath(value)
    if os.name!='nt' or len(value)>1024 or any(ord(c)<32 for c in value) or not re.match(r'^[A-Za-z]:\\',canonical) or ':' in canonical[2:]:
        raise ValueError('Selecione um caminho absoluto em um disco local.')
    drive=canonical[:3]
    import ctypes
    if ctypes.windll.kernel32.GetDriveTypeW(ctypes.c_wchar_p(drive)) not in (2,3,4,5,6):raise ValueError('Selecione uma unidade montada e acessível nesta sessão Windows.')
    path=Path(canonical)
    for part in (path,*path.parents):
        st=part.lstat()
        if stat.S_ISLNK(st.st_mode) or getattr(st,'st_file_attributes',0)&0x400:raise ValueError('Atalhos e redirecionamentos não são permitidos.')
    resolved=path.resolve(strict=True)
    blocked=[Path(os.environ.get('SystemRoot',r'C:\Windows')),Path(os.environ.get('ProgramData',r'C:\ProgramData'))/'Arcoll',Path(os.environ.get('ProgramFiles',r'C:\Program Files'))/'Arcoll']
    for root in blocked:
        if resolved==root or root in resolved.parents:raise PermissionError('Diretório protegido pelo sistema ou pelo gestor.')
    if any(p.name.casefold() in ('credentials','protect','vault','crypto') for p in (resolved,*resolved.parents)):raise PermissionError('Armazenamento de credenciais protegido.')
    if directory and not resolved.is_dir():raise ValueError('Selecione uma pasta.')
    if writable and resolved==Path(resolved.anchor):raise ValueError('Não é permitido alterar a raiz de um disco.')
    return resolved


def explorer_name(value):
    name=str(value or '')
    if not name or len(name)>180 or name!=name.strip().rstrip('. ') or re.search(r'[<>:"/\\|?*\x00-\x1f]',name) or name in ('.','..') or re.match(r'^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)',name,re.I):raise ValueError('Nome inválido.')
    return name


def explorer_tree(path):
    """Validate a bounded complete tree before copying, moving or archiving it."""
    items=[explorer_path(str(path),writable=True)];size=0
    for current in items:
        if current.is_dir():items.extend(explorer_path(str(p),writable=True) for p in current.iterdir())
        else:size+=current.stat().st_size
        if len(items)>500 or size>268435456:raise ValueError('Operação limitada a 500 itens e 256 MB. Reduza a seleção.')
    return items


def explorer_recycle(paths):
    import ctypes
    from ctypes import wintypes
    class Operation(ctypes.Structure):
        _fields_=[('hwnd',wintypes.HWND),('wFunc',wintypes.UINT),('pFrom',wintypes.LPCWSTR),('pTo',wintypes.LPCWSTR),('fFlags',ctypes.c_ushort),('fAnyOperationsAborted',wintypes.BOOL),('hNameMappings',ctypes.c_void_p),('lpszProgressTitle',wintypes.LPCWSTR)]
    source=ctypes.create_unicode_buffer('\0'.join(str(p) for p in paths)+'\0\0')
    op=Operation();op.wFunc=3;op.pFrom=ctypes.cast(source,wintypes.LPCWSTR);op.fFlags=0x4|0x10|0x40|0x400
    api=ctypes.windll.shell32.SHFileOperationW;api.argtypes=[ctypes.POINTER(Operation)];api.restype=ctypes.c_int
    if api(ctypes.byref(op)) or op.fAnyOperationsAborted:raise OSError('O Windows não concluiu a exclusão para a Lixeira.')


def explorer_operation(payload, authorize):
    """Fixed operations, server reauthorization, no remote shell or silent overwrite."""
    import shutil, zipfile, stat
    from pathlib import Path, PurePosixPath
    action=payload.get('operation');raw=payload.get('paths') or []
    if action not in ('rename','delete','copy','move','extract','open','execute') or not isinstance(raw,list) or not 1<=len(raw)<=25:raise ValueError('Operação inválida.')
    paths=[explorer_path(p,writable=True) for p in raw]
    if len(set(paths))!=len(paths) or any(a!=b and a in b.parents for a in paths for b in paths):raise ValueError('Selecione itens sem sobreposição.')
    plans=[]
    if action=='rename':
        if len(paths)!=1:raise ValueError('Selecione somente um item para renomear.')
        plans=[(paths[0],paths[0].with_name(explorer_name(payload.get('name'))))]
    elif action in ('copy','move'):
        destination=explorer_path(payload.get('destination'),directory=True)
        for path in paths:
            explorer_tree(path)
            target=destination/path.name
            if target==path or path in target.parents:raise ValueError('O destino não pode estar dentro da origem.')
            plans.append((path,target))
    elif action=='extract':
        if len(paths)!=1 or paths[0].suffix.lower()!='.zip':raise ValueError('Selecione um arquivo ZIP.')
        source=paths[0];target=source.with_name(explorer_name(source.stem+' extraído'))
        if target.exists():raise FileExistsError('A pasta de extração já existe.')
        with zipfile.ZipFile(source) as archive:
            entries=archive.infolist();total=0;seen=set()
            if len(entries)>500:raise ValueError('ZIP com mais de 500 itens.')
            for entry in entries:
                name=entry.filename.replace('\\','/');parts=PurePosixPath(name).parts
                if not parts or name.startswith('/') or any(p in ('.','..') or ':' in p for p in parts) or stat.S_ISLNK(entry.external_attr>>16) or entry.flag_bits&1:raise ValueError('ZIP contém caminho inseguro, atalho ou criptografia.')
                for part in parts:explorer_name(part)
                key=name.rstrip('/').casefold()
                if key in seen:raise ValueError('ZIP contém caminhos duplicados.')
                seen.add(key);total+=entry.file_size
                if total>268435456:raise ValueError('ZIP descompactado excede 256 MB.')
            authorize();target.mkdir()
            try:
                for entry in entries:
                    authorize();out=target.joinpath(*PurePosixPath(entry.filename.replace('\\','/')).parts)
                    if entry.is_dir():out.mkdir(parents=True,exist_ok=True);continue
                    out.parent.mkdir(parents=True,exist_ok=True)
                    with archive.open(entry) as src,out.open('xb') as dst:shutil.copyfileobj(src,dst,65536)
            except Exception:
                shutil.rmtree(target);raise
        return 'ZIP extraído em '+str(target)
    elif action in ('open','execute'):
        if len(paths)!=1:raise ValueError('Selecione somente um item.')
        path=paths[0];authorize()
        executable=path.suffix.lower() in ('.exe','.msi','.bat','.cmd','.com','.ps1','.vbs','.js','.scr','.lnk','.url','.hta')
        if action=='open' and (executable or path.is_dir()):raise ValueError('Use Executar para aplicativos e Abrir para documentos.')
        if action=='execute' and path.suffix.lower() not in ('.exe','.msi','.bat','.cmd','.com'):raise ValueError('Executável não suportado.')
        os.startfile(str(path));return 'Solicitado ao Windows: '+path.name
    elif action=='delete':
        for path in paths:explorer_tree(path)
        authorize();explorer_recycle(paths);return 'Itens enviados à Lixeira do Windows.'
    for source,target in plans:
        if target.exists():raise FileExistsError('O destino já existe; nenhum arquivo será sobrescrito.')
    for source,target in plans:
        authorize()
        if action=='copy':
            if source.is_dir():shutil.copytree(source,target)
            else:
                with source.open('rb') as src,target.open('xb') as dst:shutil.copyfileobj(src,dst,65536)
        elif action=='move':
            if source.drive.casefold()==target.drive.casefold():source.rename(target)
            else:
                if source.is_dir():shutil.copytree(source,target)
                else:
                    with source.open('rb') as src,target.open('xb') as dst:shutil.copyfileobj(src,dst,65536)
                import hashlib
                def digest(path):
                    h=hashlib.sha256()
                    with path.open('rb') as stream:
                        for chunk in iter(lambda:stream.read(65536),b''):h.update(chunk)
                    return h.digest()
                for original in explorer_tree(source):
                    if original.is_file():
                        copied=target/original.relative_to(source) if source.is_dir() else target
                        if digest(original)!=digest(copied):raise OSError('Cópia não confere; origem preservada.')
                authorize();explorer_recycle([source])
        else:source.rename(target)
    return 'Operação concluída: '+action


_RASTER_IMAGES={'.jpg':'image/jpeg','.jpeg':'image/jpeg','.png':'image/png','.webp':'image/webp','.bmp':'image/bmp','.gif':'image/gif'}

def _image_jpeg(target, limit, quality):
    """Reduced JPEG copy of a local image, or None when Pillow is unavailable."""
    try:
        from PIL import Image, ImageOps
    except ImportError:
        return None
    import io
    Image.MAX_IMAGE_PIXELS=120_000_000
    with Image.open(target) as source:
        source.draft('RGB',(limit,limit))
        picture=ImageOps.exif_transpose(source)
        picture.thumbnail((limit,limit))
        if picture.mode in ('RGBA','LA','P'):
            picture=picture.convert('RGBA');flat=Image.new('RGB',picture.size,(255,255,255));flat.paste(picture,mask=picture.getchannel('A'));picture=flat
        elif picture.mode!='RGB':picture=picture.convert('RGB')
        buffer=io.BytesIO();picture.save(buffer,'JPEG',quality=quality,optimize=True);return buffer.getvalue()

def explorer_thumbnails(paths, resolve=None):
    """Many small image previews in one reply: b'ARCT' + u32 header size + JSON header + bodies."""
    import struct
    resolve=resolve or explorer_path
    entries=[];bodies=[];total=0
    for value in paths[:24]:
        try:
            target=resolve(value);suffix=target.suffix.lower()
            if suffix not in _RASTER_IMAGES or not target.is_file():continue
            body=_image_jpeg(target,320,76);mime='image/jpeg'
            if body is None:
                if target.stat().st_size>262144:continue
                body=target.read_bytes();mime=_RASTER_IMAGES[suffix]
            if total+len(body)>7340032:break
            entries.append({'path':str(value),'type':mime,'size':len(body)});bodies.append(body);total+=len(body)
        except Exception:
            continue
    header=json.dumps(entries,ensure_ascii=False).encode('utf-8')
    return b'ARCT'+struct.pack('>I',len(header))+header+b''.join(bodies),'miniaturas.bin'

def explorer_preview(path, temporary):
    """Local document conversion: never uploads documents to third-party viewers."""
    import zipfile, xml.etree.ElementTree as ET
    target=explorer_path(path);suffix=target.suffix.lower()
    if suffix in _RASTER_IMAGES and suffix!='.gif' and target.stat().st_size>1048576:
        # Fotos grandes seguem reduzidas: a visualização abre rápido e cabe no limite de 8 MB.
        reduced=_image_jpeg(target,2400,85)
        if reduced is not None and len(reduced)<=8388608:return reduced,target.stem+'.jpg'
    if target.stat().st_size>8388608:raise ValueError('Visualização limitada a 8 MB.')
    if suffix=='.docx':
        with zipfile.ZipFile(target) as z:
            info=z.getinfo('word/document.xml')
            if info.file_size>8388608:raise ValueError('Documento muito grande.')
            root=ET.fromstring(z.read(info));ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
            data='\n'.join(''.join(p.itertext()) for p in root.findall('.//w:p',ns)).encode('utf-8')
        return data,target.stem+'.txt'
    if suffix=='.doc':
        output=temporary/'documento.pdf'
        script="$w=$null;$d=$null;try{$w=New-Object -ComObject Word.Application;$w.Visible=$false;$w.DisplayAlerts=0;$w.AutomationSecurity=3;$d=$w.Documents.Open($env:ARCOLL_DOC_INPUT,$false,$true,$false,'Arcoll-preview-no-password');$d.ExportAsFixedFormat($env:ARCOLL_DOC_OUTPUT,17)}finally{if($d){$d.Close(0)};if($w){$w.Quit()}}"
        try:
            subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-Command',script],env=dict(os.environ,ARCOLL_DOC_INPUT=str(target),ARCOLL_DOC_OUTPUT=str(output)),check=True,timeout=45,capture_output=True,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        except (OSError,subprocess.SubprocessError):raise RuntimeError('Visualização DOC requer Microsoft Word instalado e um documento acessível sem senha. Você também pode baixar o arquivo ou usar PDF/DOCX.') from None
        with output.open('rb') as stream:return stream.read(8388609),target.stem+'.pdf'
    supported={'.pdf','.txt','.log','.ini','.csv','.json','.xml','.md','.svg','.jpg','.jpeg','.png','.gif','.webp','.bmp','.mp4','.webm','.ogg','.mov','.mp3','.wav','.flac','.m4a'}
    if suffix not in supported:raise ValueError('Formato sem visualizador. Baixe o arquivo ou abra no computador remoto.')
    return target.read_bytes(),target.name


def windows_update(request, runner=None):
    """Use Windows Update Gestor COM; fixed script, bounded identities, never auto reboot."""
    import base64, uuid
    if platform.system()!='Windows':raise RuntimeError('Windows Update disponível somente no Windows.')
    mode=request.get('mode','scan')
    if mode not in ('scan','install','history','pause','resume','settings','advanced','insider','latest_on','latest_off'):raise ValueError('Operação de atualização inválida.')
    selected=request.get('updates',[])
    if mode=='install':
        if not isinstance(selected,list) or not 1<=len(selected)<=50:raise ValueError('Selecione as atualizações verificadas.')
        selected=[{'id':str(uuid.UUID(str(row['id']))),'revision':int(row['revision'])} for row in selected]
        if any(r['revision']<1 or r['revision']>2147483647 for r in selected):raise ValueError('Revisão inválida.')
    payload=base64.b64encode(json.dumps({'mode':mode,'updates':selected}).encode()).decode()
    script=r'''$ErrorActionPreference='Stop'
[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding($false)
$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__PAYLOAD__'))|ConvertFrom-Json
$os=Get-CimInstance Win32_OperatingSystem
$reg=Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
$version=($os.Caption+' '+$reg.DisplayVersion+' (build '+$reg.CurrentBuild+'.'+$reg.UBR+')').Trim()
$session=New-Object -ComObject Microsoft.Update.Session
$session.ClientApplicationID='Arcoll suporte'
$ux='HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings'
$optIn=$null
try{$optIn=([int](Get-ItemProperty -Path $ux -Name IsContinuousInnovationOptedIn -ErrorAction Stop).IsContinuousInnovationOptedIn) -eq 1}catch{}
if($p.mode -eq 'latest_on' -or $p.mode -eq 'latest_off'){
 if(-not (Test-Path $ux)){throw 'Configuração não disponível nesta edição do Windows.'}
 $value=if($p.mode -eq 'latest_on'){1}else{0}
 Set-ItemProperty -Path $ux -Name IsContinuousInnovationOptedIn -Value $value -Type DWord -ErrorAction Stop
 $check=[int](Get-ItemProperty -Path $ux -Name IsContinuousInnovationOptedIn -ErrorAction Stop).IsContinuousInnovationOptedIn
 @{mode='settings';windows=$version;latest_opt_in=($check -eq 1);message='Preferência atualizada no registro. Políticas corporativas podem prevalecer.'}|ConvertTo-Json -Depth 4 -Compress;exit
}
if($p.mode -eq 'history'){
 $searcher=$session.CreateUpdateSearcher();$total=$searcher.GetTotalHistoryCount()
 $rows=@();if($total -gt 0){foreach($h in $searcher.QueryHistory(0,[Math]::Min($total,80))){$rows+=@{title=[string]$h.Title;date=([datetime]$h.Date).ToString('yyyy-MM-dd HH:mm');operation=[int]$h.Operation;result=[int]$h.ResultCode;hresult=[int]$h.HResult}}}
 @{mode='history';windows=$version;history=@($rows);total=$total}|ConvertTo-Json -Depth 5 -Compress;exit
}
if($p.mode -eq 'pause' -or $p.mode -eq 'resume'){
 $auto=New-Object -ComObject Microsoft.Update.AutoUpdate
 if($p.mode -eq 'pause'){$auto.Pause()}else{$auto.Resume()}
 @{mode=$p.mode;windows=$version;message='Solicitação enviada ao Windows Update. A configuração efetiva depende das políticas do dispositivo.'}|ConvertTo-Json -Depth 4 -Compress;exit
}
if($p.mode -eq 'settings'){
 $auto=New-Object -ComObject Microsoft.Update.AutoUpdate
 $status=$auto.Settings
 $paused=$null
 try{$paused=[bool]$auto.Results.LastSearchSuccessDate -and $false}catch{}
 @{mode='settings';windows=$version;latest_opt_in=$optIn;notification_level=[int]$status.NotificationLevel;read_only=[bool]$status.ReadOnly;reboot_required=(New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired}|ConvertTo-Json -Depth 4 -Compress;exit
}
if($p.mode -eq 'advanced' -or $p.mode -eq 'insider'){
 throw 'Esta configuração precisa ser aberta na sessão interativa do Windows.'
}
$search=$session.CreateUpdateSearcher().Search("IsInstalled=0 and IsHidden=0 and Type='Software'")
if($search.ResultCode -ne 2){throw 'O Windows Update não concluiu a verificação. Tente novamente.'}
$rows=@();$collection=New-Object -ComObject Microsoft.Update.UpdateColl
for($i=0;$i -lt $search.Updates.Count;$i++){
 $u=$search.Updates.Item($i)
 if($p.mode -eq 'scan'){
  if($rows.Count -lt 50){$rows+=@{id=$u.Identity.UpdateID;revision=$u.Identity.RevisionNumber;title=([string]$u.Title).Substring(0,[Math]::Min(160,$u.Title.Length));kb=@($u.KBArticleIDs);size=[double]$u.MaxDownloadSize;reboot=[int]$u.InstallationBehavior.RebootBehavior}}
 }else{
  $wanted=@($p.updates|Where-Object {$_.id -eq $u.Identity.UpdateID -and $_.revision -eq $u.Identity.RevisionNumber})
  if($wanted.Count -gt 0){if($u.InstallationBehavior.CanRequestUserInput){throw 'Esta atualização exige interação local e não pode ser instalada silenciosamente.'};if(-not $u.EulaAccepted){$u.AcceptEula()};[void]$collection.Add($u)}
 }
}
if($p.mode -eq 'scan'){@{mode='scan';windows=$version;latest_opt_in=$optIn;updates=@($rows);total=$search.Updates.Count;reboot_required=(New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired}|ConvertTo-Json -Depth 5 -Compress;exit}
if($collection.Count -ne @($p.updates).Count){throw 'A lista de atualizações mudou. Verifique novamente antes de instalar.'}
$downloader=$session.CreateUpdateDownloader();$downloader.Updates=$collection;$download=$downloader.Download()
if($download.ResultCode -ne 2){throw 'Não foi possível baixar todas as atualizações. Nenhuma instalação foi iniciada.'}
$installer=$session.CreateUpdateInstaller();$installer.Updates=$collection;$installer.AllowSourcePrompts=$false
$installed=$installer.Install();$results=@()
for($i=0;$i -lt $collection.Count;$i++){$r=$installed.GetUpdateResult($i);$results+=@{id=$collection.Item($i).Identity.UpdateID;code=[int]$r.ResultCode;hresult=[int]$r.HResult}}
@{mode='install';windows=$version;result=[int]$installed.ResultCode;reboot_required=[bool]$installed.RebootRequired;results=@($results)}|ConvertTo-Json -Depth 5 -Compress
'''.replace('__PAYLOAD__',payload)
    command=[str(Path(os.environ.get('SystemRoot',r'C:\Windows'))/'System32/WindowsPowerShell/v1.0/powershell.exe'),'-NoLogo','-NoProfile','-NonInteractive','-EncodedCommand',base64.b64encode(script.encode('utf-16-le')).decode()]
    result=(runner or subprocess.run)(command,capture_output=True,timeout=6600 if mode=='install' else 900,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    if result.returncode:raise RuntimeError('Windows Update não concluiu a operação. Confira conexão, serviço e privilégios administrativos. '+_decode_bytes(result.stderr)[-240:])
    report=json.loads(_decode_bytes(result.stdout).strip())
    if not isinstance(report,dict) or report.get('mode')!=mode:raise RuntimeError('Resposta inválida do Windows Update.')
    return report


def windows_version():
    """Read the installed Windows product/build without launching a process."""
    if platform.system()!='Windows':return platform.platform()
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE,r'SOFTWARE\Microsoft\Windows NT\CurrentVersion') as key:
            def read(name,default=''):
                try:return str(winreg.QueryValueEx(key,name)[0])
                except OSError:return default
            product=read('ProductName','Windows');build=read('CurrentBuild','0')
            if int(build)>=22000 and 'Server' not in product:product=product.replace('Windows 10','Windows 11')
            return (product+' '+read('DisplayVersion',read('ReleaseId'))+' (build '+build+'.'+read('UBR','0')+')').strip()
    except (OSError,ValueError):return platform.platform()
