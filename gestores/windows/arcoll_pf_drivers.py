# Caminho: gestores/windows/arcoll_pf_drivers.py
"""Módulo independente de drivers; contratos e validações compartilhados no núcleo."""
import json
import re
import subprocess
from arcoll_pentefino import _item_id, _powershell, _step

_GUID = re.compile(r'[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\Z')
_DRIVER_GROUPS = {'NET': 'Conectividade', 'MEDIA': 'Áudio', 'BLUETOOTH': 'Bluetooth', 'SYSTEM': 'Sistema', 'DISPLAY': 'Vídeo', 'HIDCLASS': 'Dispositivos de entrada',
                  'KEYBOARD': 'Dispositivos de entrada', 'MOUSE': 'Dispositivos de entrada', 'USB': 'USB', 'PRINTER': 'Impressão', 'PRINTQUEUE': 'Impressão',
                  'DISKDRIVE': 'Armazenamento', 'SCSIADAPTER': 'Armazenamento', 'HDC': 'Armazenamento', 'CAMERA': 'Câmeras', 'IMAGE': 'Câmeras',
                  'PROCESSOR': 'Processador', 'MONITOR': 'Monitores', 'FIRMWARE': 'Firmware', 'BATTERY': 'Energia', 'SENSOR': 'Sensores'}


def _driver_group(device_class):
    return _DRIVER_GROUPS.get(str(device_class or '').upper(), 'Outros')


def _installed_drivers():
    script = ("$r=@(Get-CimInstance Win32_PnPSignedDriver | Where-Object { $_.DeviceName -and $_.DriverVersion } | ForEach-Object { "
              "[pscustomobject]@{name=[string]$_.DeviceName;cls=[string]$_.DeviceClass;ver=[string]$_.DriverVersion;man=[string]$_.Manufacturer;"
              "date=$(if($_.DriverDate){$_.DriverDate.ToString('yyyy-MM-dd')}else{''})} }); ConvertTo-Json -InputObject $r -Compress")
    try:
        out = _powershell(script, 180).stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return []
    data = data if isinstance(data, list) else [data]
    seen, rows = set(), []
    for d in data:
        if not isinstance(d, dict):
            continue
        key = (d.get('name'), d.get('ver'))
        if key in seen:
            continue
        seen.add(key)
        rows.append({'id': _item_id('driver', d.get('name'), d.get('ver'), d.get('cls')), 'name': str(d.get('name'))[:100], 'group': _driver_group(d.get('cls')),
                     'version': str(d.get('ver'))[:40], 'date': str(d.get('date') or '')[:10], 'manufacturer': str(d.get('man') or '')[:60]})
    rows.sort(key=lambda r: (r['group'], r['name'].lower()))
    return rows


_WUA_SEARCH = ("$s=New-Object -ComObject Microsoft.Update.Session; $se=$s.CreateUpdateSearcher(); "
               "$res=$se.Search(\"IsInstalled=0 and Type='Driver' and IsHidden=0\"); ")


def _pending_drivers(progress):
    script = _WUA_SEARCH + ("$r=@(); foreach($u in $res.Updates){ $r += [pscustomobject]@{uid=[string]$u.Identity.UpdateID;title=[string]$u.Title;cls=[string]$u.DriverClass;"
                            "man=[string]$u.DriverManufacturer;model=[string]$u.DriverModel;date=$(if($u.DriverVerDate){$u.DriverVerDate.ToString('yyyy-MM-dd')}else{''});"
                            "size=[int64]$u.MaxDownloadSize} }; ConvertTo-Json -InputObject $r -Compress")
    try:
        query = _powershell(script, 420)
        if query.returncode != 0:
            return None
        out = query.stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return None
    data = data if isinstance(data, list) else [data]
    rows = []
    for d in data:
        if not isinstance(d, dict) or not _GUID.fullmatch(str(d.get('uid') or '')):
            continue
        title = str(d.get('title') or '')
        version = re.search(r'(\d+(?:\.\d+){1,3})\s*$', title)
        rows.append({'id': _item_id('driver-update', d['uid']), 'uid': str(d['uid']).lower(), 'name': str(d.get('model') or title)[:100], 'group': _driver_group(d.get('cls')),
                     'version': version.group(1) if version else '', 'date': str(d.get('date') or '')[:10], 'manufacturer': str(d.get('man') or '')[:60], 'size': max(0, int(d.get('size') or 0))})
    return rows


def _install_drivers(updates, progress):
    """Baixa e instala pelo Windows Update somente os UpdateID escolhidos (validados como GUID)."""
    uids = [u['uid'] for u in updates]
    if not uids or any(not _GUID.fullmatch(u) for u in uids):
        return [], False
    wanted = ','.join("'%s'" % u for u in uids)
    script = (_WUA_SEARCH + "$want=@(%s); $col=New-Object -ComObject Microsoft.Update.UpdateColl; "
              "foreach($u in $res.Updates){ if($want -contains $u.Identity.UpdateID.ToLower()){ if(-not $u.EulaAccepted){$u.AcceptEula()}; [void]$col.Add($u) } }; "
              "if($col.Count -eq 0){ ConvertTo-Json -InputObject @{codes=@();reboot=$false} -Compress; exit 0 }; "
              "$d=$s.CreateUpdateDownloader(); $d.Updates=$col; [void]$d.Download(); "
              "$i=$s.CreateUpdateInstaller(); $i.Updates=$col; $ir=$i.Install(); $codes=@(); "
              "for($n=0;$n -lt $col.Count;$n++){ $codes += [pscustomobject]@{uid=[string]$col.Item($n).Identity.UpdateID;code=[int]$ir.GetUpdateResult($n).ResultCode} }; "
              "ConvertTo-Json -InputObject @{codes=$codes;reboot=[bool]$ir.RebootRequired} -Compress -Depth 4") % wanted
    try:
        out = _powershell(script, 3300).stdout.strip()
        data = json.loads(out) if out else {}
    except (OSError, subprocess.SubprocessError, ValueError):
        return [], False
    codes = data.get('codes') if isinstance(data, dict) else []
    codes = codes if isinstance(codes, list) else [codes]
    return [c for c in codes if isinstance(c, dict)], bool(isinstance(data, dict) and data.get('reboot'))


def _tool_drivers(payload, progress):
    action = str(payload.get('action') or 'scan')
    report = {'tool': 'drivers'}
    ids = payload.get('ids')
    if action == 'install':
        if not isinstance(ids, list) or not 1 <= len(ids) <= 20 or any(not re.fullmatch(r'[a-f0-9]{16}', str(i)) for i in ids):
            raise ValueError('Seleção de drivers inválida.')
        _step(progress, 5, 'Procurando drivers no Windows Update...')
        pending = _pending_drivers(progress)
        if pending is None:
            raise RuntimeError('O Windows Update não respondeu. Tente novamente.')
        chosen = [d for d in pending if d['id'] in set(ids)]
        results = [{'id': i, 'ok': False, 'message': 'Sem atualização disponível.'} for i in ids if i not in {d['id'] for d in chosen}]
        if chosen:
            try:   # ponto de restauração é uma proteção extra; o Windows limita a um a cada 24 h
                _step(progress, 15, 'Criando ponto de restauração...')
                _powershell("Checkpoint-Computer -Description 'Arcoll Driver Updater' -RestorePointType MODIFY_SETTINGS -ErrorAction Stop", 300)
            except (OSError, subprocess.SubprocessError):
                pass
            _step(progress, 30, 'Baixando e instalando %d driver(s)...' % len(chosen))
            codes, reboot = _install_drivers(chosen, progress)
            by_uid = {str(c.get('uid')).lower(): int(c.get('code') or 0) for c in codes}
            for d in chosen:
                code = by_uid.get(d['uid'])
                results.append({'id': d['id'], 'ok': code in (2, 3), 'message': 'Instalado.' if code == 2 else 'Instalado com avisos.' if code == 3 else 'Não instalado (código %s).' % (code if code is not None else '?')})
            report['reboot'] = reboot
        report['results'] = results[:40]
    _step(progress, 45, 'Verificando drivers instalados...')
    current = _installed_drivers()
    _step(progress, 70, 'Procurando atualizações de drivers...')
    pending = _pending_drivers(progress)
    report['outdated'] = [{k: v for k, v in d.items() if k != 'uid'} for d in (pending or [])][:100]
    report['current'] = current[:400]
    report['complete'] = pending is not None
    _step(progress, 100, 'Concluído.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Limpador de Disco na Nuvem: pastas sincronizadas (OneDrive, Google Drive, Dropbox, iCloud, Box)
# ----------------------------------------------------------------------------------------------------------------
