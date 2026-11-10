# Caminho: gestores/windows/arcoll_pf_registry.py
"""Módulo independente de registry; contratos e validações compartilhados no núcleo."""
import os
import re
import shutil
import time
from arcoll_pentefino import winreg, DATA_DIR, MAX_REGISTRY_ISSUES, REGISTRY_CHECKS, _BACKUP_NAME, _command_target, _issue, _path_missing, _reg_default, _reg_open, _reg_subkeys, _reg_values, _registry_hive, _run, _safe_listdir, _system_root

def _scan_registry(selected, progress=None):
    """Procura entradas órfãs. Só considera ausente o que aponta para unidade existente e arquivo inexistente."""
    issues = []
    if winreg is None:
        return issues
    cv = r'SOFTWARE\Microsoft\Windows\CurrentVersion'
    wow = r'SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion'
    deadline = time.time() + 120

    def add(item):
        if len(issues) < MAX_REGISTRY_ISSUES:
            issues.append(item)

    for check in selected:
        if progress:
            progress('Verificando registro: %s' % check.replace('_', ' '))
        if check == 'shared_dlls':
            for base in (cv, wow):
                for name, _data, _t in _reg_values('HKLM', base + r'\SharedDLLs'):
                    if _path_missing(name):
                        add(_issue(check, 'HKLM', base + r'\SharedDLLs', name, name))
        elif check == 'app_paths':
            for base in (cv, wow):
                for sub in _reg_subkeys('HKLM', base + r'\App Paths'):
                    target = _command_target(_reg_default('HKLM', base + r'\App Paths' + '\\' + sub))
                    if target and _path_missing(target):
                        add(_issue(check, 'HKLM', base + r'\App Paths' + '\\' + sub, None, target))
        elif check == 'startup_missing':
            for hive, base in (('HKCU', r'Software\Microsoft\Windows\CurrentVersion'), ('HKLM', cv), ('HKLM', wow)):
                for name, data, _t in _reg_values(hive, base + r'\Run'):
                    target = _command_target(data)
                    if target and _path_missing(target):
                        add(_issue(check, hive, base + r'\Run', name, target))
        elif check == 'fonts':
            fonts_key = r'SOFTWARE\Microsoft\Windows NT\CurrentVersion\Fonts'
            for name, data, _t in _reg_values('HKLM', fonts_key):
                if isinstance(data, str) and data:
                    full = data if re.match(r'[A-Za-z]:\\', data) else str(_system_root() / 'Fonts' / data)
                    if _path_missing(full):
                        add(_issue(check, 'HKLM', fonts_key, name, full))
        elif check == 'help_files':
            for hive, base in (('HKLM', r'SOFTWARE\Microsoft\Windows\Help'), ('HKCU', r'Software\Microsoft\Windows\Help')):
                for name, data, _t in _reg_values(hive, base):
                    if isinstance(data, str) and _path_missing(os.path.join(data, name)):
                        add(_issue(check, hive, base, name, os.path.join(data, name)))
        elif check == 'uninstall_orphans':
            for base in (cv, wow):
                root = base + r'\Uninstall'
                for sub in _reg_subkeys('HKLM', root):
                    values = {n.lower(): d for n, d, _t in _reg_values('HKLM', root + '\\' + sub)}
                    if values.get('systemcomponent') == 1 or values.get('windowsinstaller') == 1 or not values.get('displayname'):
                        continue
                    target = _command_target(values.get('uninstallstring'))
                    if not target or not _path_missing(target):
                        continue
                    location = str(values.get('installlocation') or '').strip('" ')
                    icon = _command_target(values.get('displayicon'))
                    if (location and os.path.isdir(location)) or (icon and not _path_missing(icon)):
                        continue
                    add(_issue(check, 'HKLM', root + '\\' + sub, None, '%s — %s' % (values.get('displayname'), target)))
        elif check == 'com_activex':
            base = r'SOFTWARE\Classes\CLSID'
            system_prefix = str(_system_root()).lower()
            for clsid in _reg_subkeys('HKLM', base):
                if time.time() > deadline or len(issues) >= MAX_REGISTRY_ISSUES:
                    break
                for server in ('InprocServer32', 'LocalServer32'):
                    target = _command_target(_reg_default('HKLM', base + '\\' + clsid + '\\' + server))
                    if target and not target.lower().startswith(system_prefix) and _path_missing(target):
                        add(_issue(check, 'HKLM', base + '\\' + clsid, None, target))
                        break
        elif check == 'mui_cache':
            key = r'Software\Classes\Local Settings\Software\Microsoft\Windows\Shell\MuiCache'
            for name, _data, _t in _reg_values('HKCU', key):
                match = re.match(r'([A-Za-z]:\\.*?\.exe)\.', name, re.I)
                if match and _path_missing(match.group(1)):
                    add(_issue(check, 'HKCU', key, name, match.group(1)))
    return issues


def _backup_root():
    return DATA_DIR / 'RegistryBackups'


def _delete_key_tree(hive, path):
    for sub in _reg_subkeys(hive, path):
        _delete_key_tree(hive, path + '\\' + sub)
    winreg.DeleteKeyEx(_registry_hive(hive), path, winreg.KEY_WOW64_64KEY)


def _export_key(hive, key, destination):
    return _run(['reg', 'export', '%s\\%s' % (hive, key), str(destination), '/y', '/reg:64'], 60).returncode == 0


def _prune_backups(keep=20):
    folders = sorted([d for d in _safe_listdir(_backup_root()) if d.is_dir() and _BACKUP_NAME.fullmatch(d.name)], key=lambda d: d.name)
    for old in folders[:-keep]:
        shutil.rmtree(old, ignore_errors=True)


def _fix_registry(issues, selected_ids, progress, recovery_copies=True):
    """Exporta cada chave afetada (.reg) e só então remove. Sem backup válido, o item não é alterado."""
    stamp = time.strftime('%Y%m%d-%H%M%S')
    folder = _backup_root() / stamp
    if recovery_copies:
        folder.mkdir(parents=True, exist_ok=True)
    exported, fixed, failed = {}, 0, 0
    chosen = [i for i in issues if i['id'] in selected_ids]
    for number, issue in enumerate(chosen, 1):
        if progress and number % 20 == 1:
            progress('Corrigindo registro %d/%d' % (number, len(chosen)))
        ident = (issue['hive'], issue['key'])
        try:
            if recovery_copies and ident not in exported:
                destination = folder / ('%04d.reg' % (len(exported) + 1))
                exported[ident] = destination if _export_key(issue['hive'], issue['key'], destination) else None
            if recovery_copies and exported[ident] is None:
                failed += 1
                continue
            if issue['value'] is None:
                _delete_key_tree(issue['hive'], issue['key'])
            else:
                with _reg_open(issue['hive'], issue['key'], write=True) as key:
                    winreg.DeleteValue(key, issue['value'])
            fixed += 1
        except OSError:
            failed += 1
    if not recovery_copies:
        stamp = None
    else:
        if not any(exported.values()):
            shutil.rmtree(folder, ignore_errors=True)
            stamp = None
        _prune_backups()
    return {'fixed': fixed, 'failed': failed, 'backup': stamp}


def _list_backups():
    return [d.name for d in sorted(_safe_listdir(_backup_root()), key=lambda d: d.name, reverse=True) if d.is_dir() and _BACKUP_NAME.fullmatch(d.name)][:20]


def _restore_registry(stamp):
    if not _BACKUP_NAME.fullmatch(stamp or ''):
        raise ValueError('Backup de registro inválido.')
    folder = _backup_root() / stamp
    files = sorted(folder.glob('*.reg')) if folder.is_dir() else []
    if not files:
        raise ValueError('Backup de registro não encontrado.')
    restored = sum(1 for f in files if _run(['reg', 'import', str(f), '/reg:64'], 60).returncode == 0)
    return {'restored': restored, 'total': len(files)}


def _tool_registry(payload, progress):
    action = payload.get('action', 'scan')
    selected = payload.get('checks') or list(REGISTRY_CHECKS)
    if not isinstance(selected, list) or any(c not in REGISTRY_CHECKS for c in selected):
        raise ValueError('Verificações de registro inválidas.')
    report = {'tool': 'registry'}
    if action == 'restore':
        report.update(_restore_registry(payload.get('backup')))
        action = 'scan'
    issues = _scan_registry(selected, progress)
    if action == 'fix':
        ids = payload.get('ids')
        if not isinstance(ids, list) or not ids or len(ids) > MAX_REGISTRY_ISSUES or any(not isinstance(i, str) for i in ids):
            raise ValueError('Seleção de registro inválida.')
        report.update(_fix_registry(issues, set(ids), progress, payload.get("recovery_copies") is not False))
        issues = _scan_registry(selected)   # estado resultante
    counts = {}
    for item in issues:
        counts[item['check']] = counts.get(item['check'], 0) + 1
    report.update({'issues': issues, 'counts': counts, 'checks': selected, 'backups': _list_backups()})
    return report


# ----------------------------------------------------------------------------------------------------------------
# Inicialização
# ----------------------------------------------------------------------------------------------------------------
_APPROVED = r'Software\Microsoft\Windows\CurrentVersion\Explorer\StartupApproved'
_RUN_SOURCES = (('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Run', 'Run', 'Registro (usuário)'),
                ('HKLM', r'SOFTWARE\Microsoft\Windows\CurrentVersion\Run', 'Run', 'Registro (todos os usuários)'),
                ('HKLM', r'SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Run', 'Run32', 'Registro (32 bits)'))


