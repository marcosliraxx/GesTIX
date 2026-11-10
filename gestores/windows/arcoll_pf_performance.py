# Caminho: gestores/windows/arcoll_pf_performance.py
"""Módulo independente de performance; contratos e validações compartilhados no núcleo."""
import ctypes
import json
import re
import subprocess
from arcoll_pentefino import _item_id, _load_cache, _powershell, _save_cache, _step, _system_root

_PERF_PROTECTED = {'system', 'registry', 'smss', 'csrss', 'wininit', 'winlogon', 'services', 'lsass', 'lsaiso', 'svchost', 'dwm', 'explorer',
                   'fontdrvhost', 'audiodg', 'sihost', 'ctfmon', 'taskhostw', 'runtimebroker', 'searchhost', 'searchapp', 'searchindexer',
                   'startmenuexperiencehost', 'shellexperiencehost', 'textinputhost', 'conhost', 'dllhost', 'spoolsv', 'securityhealthsystray',
                   'securityhealthservice', 'msmpeng', 'nissrv', 'mpdefendercoreservice', 'wudfhost', 'dashost', 'python', 'pythonw', 'py',
                   'powershell', 'pwsh', 'cmd', 'wmiprvse', 'lockapp', 'logonui', 'userinit', 'idle'}
_PERF_STATE = 'performance-suspended.json'


def _perf_processes():
    """Aplicativos do usuário agrupados por executável: {id: {...}}. Sistema, o gestor e o interpretador Python ficam de fora."""
    script = ("$me=$PID; $r=@(Get-Process -ErrorAction SilentlyContinue | ForEach-Object { try { $p=$_; "
              "if($p.Id -ne $me -and $p.SessionId -ne 0 -and $p.Path){ [pscustomobject]@{id=$p.Id;name=$p.ProcessName;path=$p.Path;ws=[int64]$p.WorkingSet64;"
              "start=[int64]$p.StartTime.ToFileTimeUtc();company=[string]$p.Company;product=[string]$p.Product;desc=[string]$p.Description} } } catch {} }); "
              "ConvertTo-Json -InputObject $r -Compress")
    try:
        out = _powershell(script, 120).stdout.strip()
        data = json.loads(out) if out else []
    except (OSError, subprocess.SubprocessError, ValueError):
        return {}
    data = data if isinstance(data, list) else [data]
    windows = str(_system_root()).lower()
    groups = {}
    for row in data:
        if not isinstance(row, dict) or not row.get('path'):
            continue
        path, name = str(row['path']), str(row.get('name') or '')
        low = path.lower()
        if low.startswith(windows) or name.lower() in _PERF_PROTECTED or 'arcoll' in low:
            continue
        key = _item_id('perf', low)
        group = groups.setdefault(key, {'id': key, 'name': (str(row.get('product') or '').strip() or str(row.get('desc') or '').strip() or name)[:80],
                                        'publisher': str(row.get('company') or '').strip()[:60], 'memory': 0, 'pids': []})
        group['memory'] += max(0, int(row.get('ws') or 0))
        group['pids'].append((int(row['id']), int(row.get('start') or 0)))
    return groups


def _nt_process(pid, suspend):
    kernel32, ntdll = ctypes.windll.kernel32, ctypes.windll.ntdll
    kernel32.OpenProcess.restype = ctypes.c_void_p
    handle = kernel32.OpenProcess(0x0800, False, int(pid))   # PROCESS_SUSPEND_RESUME
    if not handle:
        return False
    try:
        return (ntdll.NtSuspendProcess if suspend else ntdll.NtResumeProcess)(ctypes.c_void_p(handle)) == 0
    finally:
        kernel32.CloseHandle(ctypes.c_void_p(handle))


def _perf_impact(memory):
    return 3 if memory >= 600 * 1048576 else 2 if memory >= 200 * 1048576 else 1


def _tool_performance(payload, progress):
    action = str(payload.get('action') or 'list')
    ids = payload.get('ids')
    if action in ('suspend', 'resume') and (not isinstance(ids, list) or not 1 <= len(ids) <= 40 or any(not re.fullmatch(r'[a-f0-9]{16}', str(i)) for i in ids)):
        raise ValueError('Seleção de aplicativos inválida.')
    _step(progress, 10, 'Lendo aplicativos em execução...')
    groups = _perf_processes()
    state = (_load_cache(_PERF_STATE) or {}).get('items', [])
    alive = {(pid, start) for g in groups.values() for pid, start in g['pids']}
    state = [e for e in state if isinstance(e, dict) and (e.get('pid'), e.get('start')) in alive]   # saiu do ar: deixa de contar como suspenso
    report, done, failed = {'tool': 'performance'}, 0, 0
    if action == 'suspend':
        for number, ident in enumerate(ids, 1):
            _step(progress, 20 + 70 * number // len(ids), 'Suspendendo %d/%d...' % (number, len(ids)))
            group = groups.get(ident)
            if not group or any(e.get('key') == ident for e in state):
                failed += 1
                continue
            ok = [(pid, start) for pid, start in group['pids'] if _nt_process(pid, True)]
            state += [{'key': ident, 'pid': pid, 'start': start} for pid, start in ok]
            done += 1 if ok else 0
            failed += 0 if ok else 1
    elif action == 'resume':
        for number, ident in enumerate(ids, 1):
            _step(progress, 20 + 70 * number // len(ids), 'Retomando %d/%d...' % (number, len(ids)))
            mine = [e for e in state if e.get('key') == ident]
            if not mine:
                failed += 1
                continue
            good = [_nt_process(e['pid'], False) for e in mine]
            state = [e for e in state if e.get('key') != ident]
            done += 1 if any(good) else 0
            failed += 0 if any(good) else 1
    _save_cache(_PERF_STATE, {'items': state})
    suspended_keys = {e.get('key') for e in state}
    rows = [{'id': g['id'], 'name': g['name'], 'publisher': g['publisher'], 'memory': g['memory'], 'impact': _perf_impact(g['memory']), 'count': len(g['pids'])}
            for g in groups.values()]
    rows.sort(key=lambda r: (-r['memory'], r['name'].lower()))
    report['active'] = [r for r in rows if r['id'] not in suspended_keys][:150]
    report['suspended'] = [r for r in rows if r['id'] in suspended_keys][:150]
    if action in ('suspend', 'resume'):
        report['done'], report['failed'] = done, failed
    _step(progress, 100, 'Concluído.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Driver Updater (Windows Update) 
# ----------------------------------------------------------------------------------------------------------------
