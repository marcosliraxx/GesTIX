# Caminho: agentes/windows/connection_diagnostics.py
"""Medições de conexão geradas no Windows; sem credenciais nem endereço físico."""
import ipaddress, json, platform, re, socket, subprocess, time, urllib.request, pathlib, os

def _run(args, timeout=5):
    try:
        p=subprocess.run(args, capture_output=True, text=True, errors='replace',timeout=timeout,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        return p.stdout[:14000]
    except (OSError,subprocess.SubprocessError):return ''

def collect():
    result={'schema':1,'captured_at':int(time.time()),'machine':platform.node(),'os':platform.platform(), 'public_ip':'', 'provider':{}, 'interface':{},'latency':{},'route':[], 'errors':[]}
    iface=result['interface']
    if platform.system()=='Windows':
        # PowerShell provides the true configured DNS/gateway/interface, not DNS answers of a test domain.
        script="[Console]::OutputEncoding=[System.Text.Encoding]::UTF8; $OutputEncoding=[System.Text.Encoding]::UTF8; $ErrorActionPreference='Stop'; $r=Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Sort-Object RouteMetric,InterfaceMetric | Select-Object -First 1; if(!$r){throw 'No default route'}; $i=Get-NetIPConfiguration -InterfaceIndex $r.InterfaceIndex; $a=Get-NetAdapter -InterfaceIndex $r.InterfaceIndex; [pscustomobject]@{name=$a.Name;description=$a.InterfaceDescription;mac=$a.MacAddress;speed_bps=$a.LinkSpeed;status=$a.Status;ip=@($i.IPv4Address.IPAddress)[0];prefix=@($i.IPv4Address.PrefixLength)[0];gateway=$r.NextHop;dns=@($i.DNSServer.ServerAddresses)} | ConvertTo-Json -Compress -Depth 4"
        raw=_run(['powershell','-NoProfile','-NonInteractive','-Command',script],12)
        try:iface.update(json.loads(raw))
        except (ValueError,TypeError):result['errors'].append('Interface Windows indisponível')
    else:
        result['errors'].append('Coleta detalhada de interface disponível no Windows')
    for host in [iface.get('gateway'), '1.1.1.1', '8.8.8.8']:
        if not host:continue
        try:ipaddress.ip_address(str(host))
        except ValueError:continue
        out=_run(['ping','-n','2','-w','1200',str(host)] if platform.system()=='Windows' else ['ping','-c','2','-W','1',str(host)],6)
        times=[float(v.replace(',','.')) for v in re.findall(r'(?:time|tempo)[=<]\s*([\d,.]+)\s*ms',out,re.I)]
        result['latency'][str(host)]={'responded':bool(times),'avg_ms':round(sum(times)/len(times),2) if times else None,'samples':len(times)}
    try:
        req=urllib.request.Request('https://api.ipify.org?format=json',headers={'User-Agent':'ArcollConnection/1.0'})
        with urllib.request.urlopen(req,timeout=7) as r: public=json.load(r).get('ip','')
        ip=ipaddress.ip_address(public)
        if not ip.is_global:raise ValueError('Endereço não público')
        result['public_ip']=str(ip)
    except Exception:result['errors'].append('IP público não confirmado')
    if not result['public_ip']:
        for endpoint in ('https://api64.ipify.org?format=json','https://api.ip.sb/ip','https://checkip.amazonaws.com/'):
            try:
                request=urllib.request.Request(endpoint,headers={'User-Agent':'ArcollConnection/1.0','Accept':'application/json,text/plain'})
                with urllib.request.urlopen(request,timeout=5) as response:
                    raw=response.read(200).decode('utf-8','replace').strip()
                try: candidate=json.loads(raw)
                except ValueError: candidate=raw
                if isinstance(candidate,dict): candidate=candidate.get('ip','')
                ip=ipaddress.ip_address(str(candidate).strip())
                if ip.is_global:
                    result['public_ip']=str(ip)
                    break
            except (OSError,ValueError,TypeError):
                continue
    if result['public_ip']:
        try:
            req=urllib.request.Request('https://ipwho.is/'+result['public_ip'],headers={'User-Agent':'ArcollConnection/1.0'})
            with urllib.request.urlopen(req,timeout=8) as r: geo=json.load(r)
            if geo.get('success') and geo.get('ip')==result['public_ip']:
                connection=geo.get('connection') or {}
                result['provider']={'isp':connection.get('isp'),'org':connection.get('org'),'asn':connection.get('asn'),'domain':connection.get('domain'),'city':geo.get('city'),'region':geo.get('region'),'country':geo.get('country'),'country_code':geo.get('country_code'),'postal':geo.get('postal'),'latitude':geo.get('latitude'),'longitude':geo.get('longitude'),'timezone':(geo.get('timezone') or {}).get('id'),'source':'ipwho.is','approximate':True}
            else:result['errors'].append('Geolocalização externa não confirmou o IP')
        except Exception:result['errors'].append('Consulta de provedora indisponível')
    # Consulta secundária quando o primeiro provedor de geolocalização falha.
    # A correspondência exata do IP é obrigatória para evitar dados de outra conexão.
    if result['public_ip'] and not result['provider'].get('isp'):
        try:
            request=urllib.request.Request('https://ipapi.co/'+result['public_ip']+'/json/',headers={'User-Agent':'ArcollConnection/1.0','Accept':'application/json'})
            with urllib.request.urlopen(request,timeout=7) as response:
                fallback=json.load(response)
            if not fallback.get('error') and fallback.get('ip')==result['public_ip']:
                result['provider']={'isp':fallback.get('org'),'org':fallback.get('org'),'asn':fallback.get('asn'),'domain':None,'city':fallback.get('city'),'region':fallback.get('region'),'country':fallback.get('country_name'),'country_code':fallback.get('country_code'),'postal':fallback.get('postal'),'latitude':fallback.get('latitude'),'longitude':fallback.get('longitude'),'timezone':fallback.get('timezone'),'source':'ipapi.co','approximate':True}
        except (OSError,ValueError,TypeError,KeyError):
            result['errors'].append('Consulta alternativa da provedora indisponível')
    if result['public_ip'] and not result['provider'].get('isp'):
        try:
            request=urllib.request.Request('https://ipinfo.io/'+result['public_ip']+'/json',headers={'User-Agent':'ArcollConnection/1.0','Accept':'application/json'})
            with urllib.request.urlopen(request,timeout=6) as response: data=json.load(response)
            if data.get('ip')==result['public_ip'] and not data.get('bogon'):
                organization=str(data.get('org') or '')
                match=re.match(r'^AS(\d+)\s+(.+)$',organization)
                result['provider']={'isp':match.group(2) if match else organization or None,'org':organization or None,'asn':'AS'+match.group(1) if match else None,'city':data.get('city'),'region':data.get('region'),'country':data.get('country'),'postal':data.get('postal'),'source':'ipinfo.io','approximate':True}
        except (OSError,ValueError,TypeError,KeyError):
            result['errors'].append('Consultas externas de provedora indisponíveis')
    # Traceroute is measured; these are network hops, never a geographic path.
    if platform.system()=='Windows':
        route=_run(['tracert','-d','-h','8','-w','350','1.1.1.1'],9)
        for line in route.splitlines():
            m=re.match(r'^\s*(\d{1,2})\s+(.+)$',line)
            if m and int(m.group(1))<=8:
                addr=re.findall(r'\b(?:\d{1,3}\.){3}\d{1,3}\b',m.group(2))
                result['route'].append({'hop':int(m.group(1)),'ip':addr[-1] if addr else None,'responded':bool(addr)})
    return result
