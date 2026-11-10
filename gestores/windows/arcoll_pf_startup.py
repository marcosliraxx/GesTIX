# Caminho: gestores/windows/arcoll_pf_startup.py
"""Módulo independente de startup; contratos e validações compartilhados no núcleo."""
from pathlib import Path
import json
import os
import re
import subprocess
import time
from arcoll_pentefino import winreg, DATA_DIR, _CMD_PATH, _item_id, _powershell, _profiles, _recycle, _reg_default, _reg_open, _reg_subkeys, _reg_values, _registry_hive, _run, _safe_listdir, _step, _system_root
from arcoll_pf_registry import _APPROVED, _RUN_SOURCES, _backup_root, _delete_key_tree, _export_key

def _approved_state(hive, sub, name):
    for _n, data, kind in _reg_values(hive, _APPROVED + '\\' + sub):
        if _n.lower() == name.lower() and kind == winreg.REG_BINARY and data:
            return (data[0] & 1) == 0
    return True


def _set_approved(hive, sub, name, enabled):
    key = winreg.CreateKeyEx(_registry_hive(hive), _APPROVED + '\\' + sub, 0, winreg.KEY_SET_VALUE | winreg.KEY_WOW64_64KEY)
    with key:
        stamp = b'\0' * 8 if enabled else int((time.time() + 11644473600) * 10000000).to_bytes(8, 'little')
        winreg.SetValueEx(key, name, 0, winreg.REG_BINARY, (b'\x02' if enabled else b'\x03') + b'\0\0\0' + stamp)


def _startup_folders():
    folders = [(Path(os.environ.get('ProgramData', r'C:\ProgramData')) / 'Microsoft/Windows/Start Menu/Programs/Startup', 'HKLM', 'Pasta Inicializar (todos)')]
    folders += [(p['roaming'] / 'Microsoft/Windows/Start Menu/Programs/Startup', 'HKCU', 'Pasta Inicializar (%s)' % p['home'].name) for p in _profiles()][:1]
    return folders


def _scheduled_startup_tasks():
    script = ("$r=@(Get-ScheduledTask | Where-Object { $_.TaskPath -notlike '\\Microsoft\\*' } | ForEach-Object { $t=$_; "
              "$trig=($t.Triggers | ForEach-Object { $_.CimClass.CimClassName }) -join ','; "
              "[pscustomobject]@{path=$t.TaskPath;name=$t.TaskName;state=[string]$t.State;trig=$trig;"
              "exec=(($t.Actions | Select-Object -First 1 | ForEach-Object { ($_.Execute + ' ' + $_.Arguments).Trim() }))} } | "
              "Where-Object { $_.trig -match 'Logon|Boot' }); ConvertTo-Json -InputObject $r -Compress")
    try:
        out = _powershell(script, 90).stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return []
    return data if isinstance(data, list) else [data]


def _startup_items():
    items = []
    for hive, path, sub, label in _RUN_SOURCES:
        for name, data, _t in _reg_values(hive, path):
            if name:
                items.append({'id': _item_id('run', hive, path, name), 'kind': 'run', 'name': name, 'command': str(data)[:260], 'source': label,
                              'enabled': _approved_state(hive, sub, name), '_hive': hive, '_key': path, '_sub': sub})
    for folder, hive, label in _startup_folders():
        for entry in _safe_listdir(folder):
            if entry.is_file() and entry.name.lower() != 'desktop.ini':
                items.append({'id': _item_id('folder', str(folder), entry.name), 'kind': 'folder', 'name': entry.stem, 'command': str(entry)[:260], 'source': label,
                              'enabled': _approved_state(hive, 'StartupFolder', entry.name), '_hive': hive, '_sub': 'StartupFolder', '_file': str(entry), '_entry': entry.name})
    for task in _scheduled_startup_tasks():
        if isinstance(task, dict) and task.get('name'):
            items.append({'id': _item_id('task', task.get('path'), task.get('name')), 'kind': 'task', 'name': str(task['name'])[:120], 'command': str(task.get('exec') or '')[:260],
                          'source': 'Tarefa agendada', 'enabled': str(task.get('state')).lower() != 'disabled', '_path': str(task.get('path')), '_task': str(task['name'])})
    return items


def _ps_quote(text):
    return "'" + str(text).replace("'", "''") + "'"


def _exe_of(command):
    """Executável citado em uma linha de comando (variáveis de ambiente expandidas) ou None."""
    found = _CMD_PATH.match(os.path.expandvars(str(command or '')))
    return found.group(1) if found else None


def _file_company(paths):
    """Fabricante (CompanyName) de executáveis em uma única chamada do PowerShell. Falhas devolvem {}."""
    unique = sorted({str(p) for p in paths if p})[:400]
    if not unique:
        return {}
    listing = DATA_DIR / ('paths-%d.json' % os.getpid())
    try:
        DATA_DIR.mkdir(parents=True, exist_ok=True)
        listing.write_text(json.dumps(unique), encoding='utf-8')
        script = ("$l=Get-Content -LiteralPath %s -Raw -Encoding UTF8 | ConvertFrom-Json; "
                  "$r=@($l | ForEach-Object { try { $v=(Get-Item -LiteralPath $_ -ErrorAction Stop).VersionInfo; "
                  "[pscustomobject]@{p=[string]$_;c=[string]$v.CompanyName} } catch {} }); ConvertTo-Json -InputObject $r -Compress") % _ps_quote(listing)
        data = json.loads(_powershell(script, 120).stdout.strip() or '[]')
    except (OSError, subprocess.SubprocessError, ValueError):
        data = []
    finally:
        try:
            listing.unlink()
        except OSError:
            pass
    data = data if isinstance(data, list) else [data]
    return {str(r['p']).lower(): str(r.get('c') or '').strip()[:80] for r in data if isinstance(r, dict) and r.get('p')}


def _all_scheduled_tasks():
    """Tarefas agendadas que não são do Windows (\\Microsoft\\*), com o autor e a primeira ação."""
    script = ("$r=@(Get-ScheduledTask | Where-Object { $_.TaskPath -notlike '\\Microsoft\\*' } | ForEach-Object { $t=$_; "
              "[pscustomobject]@{path=$t.TaskPath;name=$t.TaskName;state=[string]$t.State;author=[string]$t.Author;"
              "exec=(($t.Actions | Select-Object -First 1 | ForEach-Object { ($_.Execute + ' ' + $_.Arguments).Trim() }))} }); "
              "ConvertTo-Json -InputObject $r -Compress")
    try:
        out = _powershell(script, 120).stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return []
    return data if isinstance(data, list) else [data]


_SERVICE_SKIP = re.compile(r'(?i)(antivirus|antimalware|defender|firewall|security|endpoint|mbam|avast|avg|kaspersky|eset|norton|mcafee|bitdefender|'
                           r'sophos|crowdstrike|arcoll|vss|backup)')


def _service_items():
    """Serviços de terceiros (fora da pasta do Windows). Antivírus e o próprio gestor nunca são listados."""
    script = ("$r=@(Get-CimInstance Win32_Service | ForEach-Object { $p=([string]$_.PathName).Trim().Trim('\"'); "
              "if($p -and ($p -notlike ($env:SystemRoot+'\\*'))){ [pscustomobject]@{name=$_.Name;display=$_.DisplayName;path=[string]$_.PathName;mode=[string]$_.StartMode} } }); "
              "ConvertTo-Json -InputObject $r -Compress")
    try:
        out = _powershell(script, 120).stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return []
    data = data if isinstance(data, list) else [data]
    return [d for d in data if isinstance(d, dict) and d.get('name') and not _SERVICE_SKIP.search('%s %s' % (d.get('name'), d.get('display')))]


_CTX_LOCATIONS = (('*', 'Arquivos'), ('AllFilesystemObjects', 'Arquivos e pastas'), ('Directory', 'Pastas'),
                  ('Directory\\Background', 'Fundo das pastas'), ('Folder', 'Pastas'), ('Drive', 'Unidades'))
_CTX_BLOCKED = r'SOFTWARE\Microsoft\Windows\CurrentVersion\Shell Extensions\Blocked'


def _blocked_handlers():
    return {str(n).lower() for n, _d, _t in _reg_values('HKLM', _CTX_BLOCKED)}


def _context_items():
    """Extensões de terceiros do menu de contexto (clique direito). Itens do Windows não são listados."""
    blocked, items, windows = _blocked_handlers(), [], str(_system_root()).lower()
    for location, label in _CTX_LOCATIONS:
        base = 'SOFTWARE\\Classes\\%s\\shellex\\ContextMenuHandlers' % location
        for name in _reg_subkeys('HKLM', base):
            clsid = str(_reg_default('HKLM', base + '\\' + name) or '').strip()
            if not clsid.startswith('{'):
                clsid = name if name.startswith('{') else ''
            if not clsid:
                continue
            clsid_key = 'SOFTWARE\\Classes\\CLSID\\' + clsid
            server = str(_reg_default('HKLM', clsid_key + '\\InprocServer32') or _reg_default('HKLM', clsid_key + '\\LocalServer32') or '')
            exe = _exe_of(server) or server.strip('"')
            if not exe or exe.lower().startswith(windows):
                continue
            friendly = str(_reg_default('HKLM', clsid_key) or name)
            items.append({'id': _item_id('context', location, name), 'kind': 'context', 'name': friendly[:120] if friendly != clsid else name[:120],
                          'detail': name[:120], 'command': exe[:260], 'source': label, 'enabled': clsid.lower() not in blocked,
                          '_clsid': clsid, '_location': location, '_name': name, '_exe': exe})
    return items


def _startup_catalog():
    """Todas as categorias do Gerenciador de inicialização, com o fabricante de cada programa."""
    items = [dict(i, detail='') for i in _startup_items() if i['kind'] in ('run', 'folder')]
    for task in _all_scheduled_tasks():
        if isinstance(task, dict) and task.get('name'):
            exe = _exe_of(task.get('exec'))
            items.append({'id': _item_id('task', task.get('path'), task.get('name')), 'kind': 'task', 'name': (Path(exe).stem if exe else str(task['name']))[:120],
                          'detail': str(task['name'])[:120], 'command': str(task.get('exec') or '')[:260], 'source': 'Tarefa agendada',
                          'enabled': str(task.get('state')).lower() != 'disabled', '_path': str(task.get('path')), '_task': str(task['name']),
                          '_exe': exe, '_author': str(task.get('author') or '')[:80]})
    for svc in _service_items():
        exe = _exe_of(svc.get('path'))
        items.append({'id': _item_id('service', svc['name']), 'kind': 'service', 'name': (Path(exe).stem if exe else str(svc.get('display') or svc['name']))[:120],
                      'detail': str(svc.get('display') or svc['name'])[:120], 'command': str(svc.get('path') or '')[:260], 'source': 'Serviço do Windows',
                      'enabled': str(svc.get('mode')).lower() == 'auto', '_svc': str(svc['name']), '_exe': exe})
    items += _context_items()
    companies = _file_company([i.get('_exe') or _exe_of(i.get('command')) for i in items])
    result = []
    for item in items:
        exe = item.get('_exe') or _exe_of(item.get('command'))
        item['publisher'] = companies.get(str(exe or '').lower()) or item.get('_author') or ''
        if item['kind'] in ('service', 'context') and re.search(r'(?i)microsoft', item['publisher']):
            continue   # componentes da Microsoft não entram na lista de serviços e do menu de contexto
        result.append(item)
    return result


def _apply_startup(items, operations, recovery_copies=True):
    by_id = {i['id']: i for i in items}
    done, failed = 0, 0
    folder = _backup_root() / ('startup-' + time.strftime('%Y%m%d-%H%M%S'))
    for op in operations:
        item = by_id.get(op.get('id')) if isinstance(op, dict) else None
        action = op.get('op') if isinstance(op, dict) else None
        if not item or action not in ('enable', 'disable', 'delete'):
            failed += 1
            continue
        try:
            kind = item['kind']
            if kind in ('run', 'folder'):
                name = item['name'] if kind == 'run' else item['_entry']
                if action == 'delete':
                    if kind == 'run':
                        if recovery_copies:
                            folder.mkdir(parents=True, exist_ok=True)
                        if recovery_copies and not _export_key(item['_hive'], item['_key'], folder / ('%s.reg' % _item_id(item['_hive'], item['_key']))):
                            raise OSError('backup')
                        with _reg_open(item['_hive'], item['_key'], write=True) as key:
                            winreg.DeleteValue(key, name)
                    elif recovery_copies:
                        if not _recycle(item['_file']):
                            raise OSError('recycle')
                    else:
                        Path(item['_file']).unlink()
                else:
                    _set_approved(item['_hive'], item['_sub'], name, action == 'enable')
            elif kind == 'task':
                cmd = {'enable': 'Enable-ScheduledTask', 'disable': 'Disable-ScheduledTask', 'delete': 'Unregister-ScheduledTask -Confirm:$false'}[action]
                if _powershell('%s -TaskPath %s -TaskName %s -ErrorAction Stop' % (cmd, _ps_quote(item['_path']), _ps_quote(item['_task'])), 60).returncode != 0:
                    raise OSError('task')
            elif kind == 'service':
                if action == 'delete':
                    raise OSError('service')   # serviços só alternam entre Automático e Manual; nunca são removidos
                mode = 'auto' if action == 'enable' else 'demand'
                if _run(['sc.exe', 'config', item['_svc'], 'start=', mode], 30).returncode != 0:
                    raise OSError('service')
            elif kind == 'context':
                if action == 'delete':
                    if recovery_copies:
                        folder.mkdir(parents=True, exist_ok=True)
                    path = 'SOFTWARE\\Classes\\%s\\shellex\\ContextMenuHandlers\\%s' % (item['_location'], item['_name'])
                    if recovery_copies and not _export_key('HKLM', path, folder / ('%s.reg' % item['id'])):
                        raise OSError('backup')
                    _delete_key_tree('HKLM', path)
                else:
                    key = winreg.CreateKeyEx(_registry_hive('HKLM'), _CTX_BLOCKED, 0, winreg.KEY_SET_VALUE | winreg.KEY_WOW64_64KEY)
                    with key:
                        if action == 'disable':
                            winreg.SetValueEx(key, item['_clsid'], 0, winreg.REG_SZ, item['name'][:60])
                        else:
                            try:
                                winreg.DeleteValue(key, item['_clsid'])
                            except FileNotFoundError:
                                pass
            else:
                raise OSError('kind')
            done += 1
        except (OSError, subprocess.SubprocessError, KeyError):
            failed += 1
    return {'done': done, 'failed': failed}


def _public_startup(items):
    return [{k: v for k, v in i.items() if not k.startswith('_')} for i in items][:700]


def _tool_startup(payload, progress):
    report = {'tool': 'startup'}
    _step(progress, 5, 'Lendo itens de inicialização...')
    items = _startup_catalog()
    if payload.get('action') == 'apply':
        operations = payload.get('operations')
        if not isinstance(operations, list) or not operations or len(operations) > 100:
            raise ValueError('Operações de inicialização inválidas.')
        _step(progress, 50, 'Aplicando alterações...')
        report.update(_apply_startup(items, operations, payload.get("recovery_copies") is not False))
        items = _startup_catalog()
    report['items'] = _public_startup(items)
    return report


# ----------------------------------------------------------------------------------------------------------------
# Analisador de disco
# ----------------------------------------------------------------------------------------------------------------
