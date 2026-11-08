# Caminho: gestores/windows/arcoll_clean.py
"""Arcoll Clean: equivalente remoto das ferramentas do CCleaner, executado pelo gestor com privilégios de administrador.

Contrato com a plataforma (includes/network-clean.php):
  * execute_clean(...)  -> comandos clean_scan / clean_execute (Limpeza personalizada).
  * execute_tool(...)   -> comando clean_tool (registro, inicialização, disco, duplicados, restauração,
                           atualizador de software e verificação de integridade).
A plataforma nunca envia caminhos de arquivo para exclusão: envia apenas chaves de catálogo e identificadores
de itens que o próprio gestor produziu na última análise. Todo alvo é revalidado localmente antes de agir.
Navegadores: senhas, favoritos e extensões só são tocados nas opções explicitamente marcadas e nunca por padrão.
"""
import ctypes
import fnmatch
import hashlib
import heapq
import json
import os
import re
import shutil
import sqlite3
import subprocess
import time
from pathlib import Path

try:
    import winreg
except ImportError:  # análise em outros sistemas (testes); o gestor só roda no Windows
    winreg = None

AGE_OPTIONS = (0, 1, 7, 30)
DEFAULT_AGE_DAYS = 7
MAX_FILES = 300000          # por categoria; ao atingir, o relatório marca "truncated" e uma nova rodada continua
MAX_CUSTOM_PATHS = 20
NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
_REPARSE_POINT = 0x400
_PROTECTED_TEMP_PREFIXES = ('arcoll', '.arcoll', '_mei')   # temporários do próprio gestor e do PyInstaller em uso
DATA_DIR = Path(os.environ.get('ProgramData', r'C:\ProgramData')) / 'Arcoll' / 'Clean'

# ----------------------------------------------------------------------------------------------------------------
# Catálogo. As chaves são o contrato com includes/network-clean.php (arc_clean_catalog); os caminhos ficam só aqui.
# ----------------------------------------------------------------------------------------------------------------
# Alvos de arquivos: (base, caminho relativo, modo, padrões). base: local|roaming|locallow|system|programdata|drive.
# modo: tree = tudo dentro da pasta; files = apenas arquivos que casam com os padrões na própria pasta;
#       treematch = arquivos que casam com os padrões em qualquer nível.
_CHROMIUM = {   # nome: (raízes de dados, raízes de cache, perfis)   perfis: 'chromium' (Default, Profile N) ou 'self'
    'chrome':  ([('local', 'Google/Chrome/User Data')], [('local', 'Google/Chrome/User Data')], 'chromium'),
    'edge':    ([('local', 'Microsoft/Edge/User Data')], [('local', 'Microsoft/Edge/User Data')], 'chromium'),
    'brave':   ([('local', 'BraveSoftware/Brave-Browser/User Data')], [('local', 'BraveSoftware/Brave-Browser/User Data')], 'chromium'),
    'vivaldi': ([('local', 'Vivaldi/User Data')], [('local', 'Vivaldi/User Data')], 'chromium'),
    'opera':   ([('roaming', 'Opera Software/Opera Stable')], [('local', 'Opera Software/Opera Stable')], 'self'),
}
_CHROMIUM_FACETS = {
    'cache': ('cache', [('tree', 'Cache'), ('tree', 'Code Cache'), ('tree', 'GPUCache'), ('tree', 'DawnCache'),
                        ('tree', 'Service Worker/CacheStorage'), ('tree', 'Service Worker/ScriptCache')]),
    'history': ('data', [('file', 'History'), ('file', 'History-journal'), ('file', 'Visited Links'), ('file', 'Top Sites'),
                         ('file', 'Top Sites-journal'), ('file', 'Shortcuts'), ('file', 'Shortcuts-journal'), ('file', 'Network Action Predictor')]),
    'cookies': ('data', [('file', 'Network/Cookies'), ('file', 'Network/Cookies-journal'), ('file', 'Cookies'), ('file', 'Cookies-journal')]),
    'session': ('data', [('tree', 'Sessions'), ('file', 'Current Session'), ('file', 'Last Session'), ('file', 'Current Tabs'), ('file', 'Last Tabs')]),
    'forms': ('data', [('file', 'Web Data'), ('file', 'Web Data-journal')]),
    'passwords': ('data', [('file', 'Login Data'), ('file', 'Login Data-journal'), ('file', 'Login Data For Account'), ('file', 'Login Data For Account-journal')]),
}
_FILE_ITEMS = {
    # Windows Explorer
    'recent_docs': [('roaming', 'Microsoft/Windows/Recent', 'files', ('*.lnk',))],
    'jump_lists': [('roaming', 'Microsoft/Windows/Recent/AutomaticDestinations', 'tree', ()), ('roaming', 'Microsoft/Windows/Recent/CustomDestinations', 'tree', ())],
    'thumbnails': [('local', 'Microsoft/Windows/Explorer', 'files', ('thumbcache_*', 'iconcache_*'))],
    # Sistema
    'user_temp': [('usertemp', '', 'tree', ())],
    'windows_temp': [('system', 'Temp', 'tree', ())],
    'memory_dumps': [('system', 'MEMORY.DMP', 'single', ()), ('system', 'LiveKernelReports', 'tree', ())],
    'chkdsk_fragments': [('drive', '', 'chkdsk', ())],
    'system_logs': [('system', 'Logs/CBS', 'files', ('*.log', '*.cab')), ('system', 'Logs/DISM', 'files', ('*.log',)),
                    ('system', 'Panther', 'files', ('*.log',)), ('system', 'Logs/WindowsUpdate', 'files', ('*.etl',))],
    'error_reports': [('programdata', 'Microsoft/Windows/WER/ReportArchive', 'tree', ()), ('programdata', 'Microsoft/Windows/WER/ReportQueue', 'tree', ()),
                      ('system', 'Minidump', 'tree', ()), ('local', 'CrashDumps', 'tree', ()), ('local', 'Microsoft/Windows/WER', 'tree', ())],
    'windows_update': [('system', 'SoftwareDistribution/Download', 'tree', ())],
    'delivery_optimization': [('system', 'ServiceProfiles/NetworkService/AppData/Local/Microsoft/Windows/DeliveryOptimization/Cache', 'tree', ()),
                              ('system', 'SoftwareDistribution/DeliveryOptimization', 'tree', ())],
    'gpu_shader_cache': [('local', 'D3DSCache', 'tree', ()), ('local', 'NVIDIA/DXCache', 'tree', ()), ('local', 'NVIDIA/GLCache', 'tree', ()),
                         ('local', 'AMD/DxCache', 'tree', ()), ('local', 'AMD/GLCache', 'tree', ())],
    # Avançado
    'prefetch': [('system', 'Prefetch', 'files', ('*.pf',))],
    # Aplicativos
    'office_recent': [('roaming', 'Microsoft/Office/Recent', 'files', ('*.lnk',))],
    'office_cache': [('local', 'Microsoft/Office/16.0/OfficeFileCache', 'tree', ()), ('local', 'Microsoft/Office/OTele', 'tree', ())],
    'teams_cache': [('roaming', 'Microsoft/Teams/Cache', 'tree', ()), ('roaming', 'Microsoft/Teams/Code Cache', 'tree', ()),
                    ('roaming', 'Microsoft/Teams/GPUCache', 'tree', ()), ('roaming', 'Microsoft/Teams/blob_storage', 'tree', ()),
                    ('roaming', 'Microsoft/Teams/tmp', 'tree', ()), ('local', 'Packages/MSTeams_8wekyb3d8bbwe/LocalCache/Microsoft/MSTeams/EBWebView/Default/Cache', 'tree', ())],
    'discord_cache': [('roaming', 'discord/Cache', 'tree', ()), ('roaming', 'discord/Code Cache', 'tree', ()), ('roaming', 'discord/GPUCache', 'tree', ())],
    'spotify_cache': [('local', 'Spotify/Storage', 'tree', ()), ('local', 'Spotify/Data', 'tree', ())],
    'vscode_cache': [('roaming', 'Code/Cache', 'tree', ()), ('roaming', 'Code/CachedData', 'tree', ()), ('roaming', 'Code/Code Cache', 'tree', ()),
                     ('roaming', 'Code/GPUCache', 'tree', ()), ('roaming', 'Code/logs', 'tree', ())],
    'adobe_cache': [('roaming', 'Adobe/Common/Media Cache Files', 'tree', ()), ('roaming', 'Adobe/Common/Media Cache', 'tree', ()),
                    ('roaming', 'Adobe/Common/Peak Files', 'tree', ())],
    'java_cache': [('locallow', 'Sun/Java/Deployment/cache', 'tree', ())],
    'store_cache': [('local', 'Packages/Microsoft.WindowsStore_8wekyb3d8bbwe/LocalCache', 'tree', ())],
    # Ferramentas de IA: somente os caches do Chromium embutido (Cache, Code Cache, GPUCache, DawnCache); conversas e contas nunca são tocadas.
    'ai_cache': [('roaming', '%s/%s' % (app, sub), 'tree', ()) for app in ('Claude', 'Cursor', 'Windsurf', 'Perplexity', 'ChatGPT')
                 for sub in ('Cache', 'Code Cache', 'GPUCache', 'DawnCache')],
}
# Valores de registro apagados pelos itens de privacidade (a chave permanece): (raiz, chave, subchaves recursivas?)
_REG_VALUE_ITEMS = {
    'run_history': [('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Explorer\RunMRU', False)],
    'search_history': [('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Explorer\WordWheelQuery', False),
                       ('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Explorer\TypedPaths', False)],
    'user_assist': [('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Explorer\UserAssist', True)],
    'mspaint_recent': [('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Applets\Paint\Recent File List', False),
                       ('HKCU', r'Software\Microsoft\Windows\CurrentVersion\Applets\Wordpad\Recent File List', False)],
    'winrar_history': [('HKCU', r'Software\WinRAR\ArcHistory', False), ('HKCU', r'Software\WinRAR\DialogEditHistory', True)],
}
_SPECIAL_ITEMS = ('recycle_bin', 'clipboard', 'dns_cache', 'event_logs', 'firefox_cache', 'firefox_history', 'firefox_cookies',
                  'firefox_session', 'firefox_forms', 'firefox_passwords', 'custom_folders')
CATEGORIES = tuple(sorted(set(_FILE_ITEMS) | set(_REG_VALUE_ITEMS) | set(_SPECIAL_ITEMS)
                          | {'%s_%s' % (b, f) for b in _CHROMIUM for f in _CHROMIUM_FACETS}))
_BROWSER_NAMES = {'chrome': 'Google Chrome', 'edge': 'Microsoft Edge', 'brave': 'Brave', 'vivaldi': 'Vivaldi', 'opera': 'Opera', 'firefox': 'Firefox'}
_FACET_NAMES = {'cache': 'cache', 'history': 'histórico', 'cookies': 'cookies', 'session': 'sessão', 'forms': 'formulários', 'passwords': 'senhas'}


def _label(category):
    """Texto do andamento; o catálogo completo de rótulos está em includes/network-clean.php."""
    browser, _, facet = category.partition('_')
    if browser in _BROWSER_NAMES and facet in _FACET_NAMES:
        return '%s: %s' % (_BROWSER_NAMES[browser], _FACET_NAMES[facet])
    return category.replace('_', ' ')


def _system_root():
    return Path(os.environ.get('SystemRoot', r'C:\Windows'))


def _system_drive():
    return Path(os.environ.get('SystemDrive', 'C:') + os.sep)


def _is_link(path):
    """Links simbólicos e junções nunca são percorridos (evita sair da pasta da categoria)."""
    try:
        if Path(path).is_symlink():
            return True
        return bool(getattr(os.lstat(path), 'st_file_attributes', 0) & _REPARSE_POINT)
    except OSError:
        return True


def _profiles():
    """Perfis de usuário legíveis em C:\\Users (inclui o usuário atual): lista de dicts local/roaming/locallow."""
    found, seen = [], set()
    users = _system_drive() / 'Users'
    candidates = []
    own = os.environ.get('USERPROFILE')
    if own:
        candidates.append(Path(own))
    try:
        for entry in users.iterdir():
            if entry.name.lower() not in ('public', 'default', 'default user', 'all users') and entry.is_dir() and not _is_link(entry):
                candidates.append(entry)
    except OSError:
        pass
    for home in candidates:
        try:
            key = os.path.normcase(str(home.resolve()))
        except OSError:
            continue
        if key in seen or not home.is_dir():
            continue
        seen.add(key)
        found.append({'home': home, 'local': home / 'AppData' / 'Local', 'roaming': home / 'AppData' / 'Roaming',
                      'locallow': home / 'AppData' / 'LocalLow'})
    return found


def _base_paths(base):
    """Raízes absolutas de uma base (uma por perfil quando a base é por usuário)."""
    if base in ('local', 'roaming', 'locallow'):
        return [p[base] for p in _profiles()]
    if base == 'usertemp':
        roots = [p['local'] / 'Temp' for p in _profiles()]
        if os.environ.get('TEMP'):
            roots.append(Path(os.environ['TEMP']))
        return roots
    if base == 'system':
        return [_system_root()]
    if base == 'programdata':
        return [Path(os.environ.get('ProgramData', r'C:\ProgramData'))]
    if base == 'drive':
        return [_system_drive()]
    return []


def _dedupe(paths):
    unique, seen = [], set()
    for path in paths:
        try:
            key = os.path.normcase(str(Path(path).resolve()))
        except OSError:
            continue
        if key not in seen:
            seen.add(key)
            unique.append(Path(path))
    return unique


def _chromium_targets(browser, facet):
    data_roots, cache_roots, mode = _CHROMIUM[browser]
    kind, entries = _CHROMIUM_FACETS[facet]
    targets = []
    for base, rel in (cache_roots if kind == 'cache' else data_roots):
        for root in _base_paths(base):
            root = root / rel
            try:
                profiles = [root] if mode == 'self' else [d for d in root.iterdir() if d.is_dir() and (d.name == 'Default' or d.name.startswith('Profile ') or d.name == 'Guest Profile')]
            except OSError:
                continue
            for profile in profiles:
                for entry_kind, entry in entries:
                    targets.append((profile / entry, 'tree' if entry_kind == 'tree' else 'single', ()))
    return targets


def _file_targets(category):
    """Converte a categoria em [(caminho absoluto, modo, padrões)]."""
    targets = []
    if category in _FILE_ITEMS:
        for base, rel, mode, patterns in _FILE_ITEMS[category]:
            for root in _base_paths(base):
                targets.append((root / rel if rel else root, mode, patterns))
    else:
        browser, _, facet = category.partition('_')
        if browser in _CHROMIUM and facet in _CHROMIUM_FACETS:
            targets = _chromium_targets(browser, facet)
        elif browser == 'firefox':
            targets = _firefox_targets(facet)
    return targets


def _firefox_profiles(base):
    out = []
    for root in _base_paths(base):
        try:
            out += [d for d in (root / 'Mozilla' / 'Firefox' / 'Profiles').iterdir() if d.is_dir()]
        except OSError:
            pass
    return out


def _firefox_targets(facet):
    targets = []
    if facet == 'cache':
        for profile in _firefox_profiles('local'):
            targets += [(profile / 'cache2', 'tree', ()), (profile / 'startupCache', 'tree', ())]
    else:
        names = {'cookies': ('cookies.sqlite', 'cookies.sqlite-wal', 'cookies.sqlite-shm'),
                 'session': ('sessionstore.jsonlz4', 'sessionstore-backups'),
                 'forms': ('formhistory.sqlite', 'formhistory.sqlite-wal', 'formhistory.sqlite-shm'),
                 'passwords': ('logins.json', 'key4.db', 'logins-backup.json')}.get(facet, ())
        for profile in _firefox_profiles('roaming'):
            for name in names:
                path = profile / name
                targets.append((path, 'tree' if path.is_dir() else 'single', ()))
    return targets


# ----------------------------------------------------------------------------------------------------------------
# Exclusões e proteção
# ----------------------------------------------------------------------------------------------------------------
def _norm(path):
    return os.path.normcase(os.path.normpath(str(path)))


def _policy_norm(path, pattern=False):
    """Compara aliases 8.3/nomes longos sem transformar curingas relativos em caminhos fixos."""
    text = _norm(path)
    if pattern:
        marks = [text.index(c) for c in ('*', '?', '[') if c in text]
        if marks:
            prefix = os.path.dirname(text[:min(marks)])
            if not prefix or not os.path.isabs(prefix):
                return text
            try:
                return _norm(os.path.realpath(prefix) + text[len(prefix):])
            except OSError:
                return text
    try:
        return _norm(os.path.realpath(text))
    except OSError:
        return text


class Policy:
    """Exclusões definidas pelo cliente + proteções fixas do gestor."""

    def __init__(self, exclusions=None):
        self.exclusions = [_policy_norm(p, pattern=True) for p in (exclusions or []) if isinstance(p, str) and p.strip()][:50]

    def excluded(self, path):
        key = _policy_norm(path)
        return any(key == e or key.startswith(e + os.sep) or fnmatch.fnmatch(key, e) for e in self.exclusions)


def _protected_temp(root_kind, relative, name):
    return root_kind == 'temp' and not relative and name.lower().startswith(_PROTECTED_TEMP_PREFIXES)


def _remove_file(path):
    try:
        os.unlink(path)
    except PermissionError:
        os.chmod(path, 0o666)   # atributo somente leitura; arquivos em uso continuam falhando e são contados como ignorados
        os.unlink(path)


def _prune_empty(root, cutoff, policy):
    """Remove subpastas vazias e antigas (a pasta raiz permanece)."""
    for folder, _dirs, _files in os.walk(root, topdown=False, followlinks=False):
        if Path(folder) == root or policy.excluded(folder):
            continue
        try:
            if not _is_link(folder) and os.stat(folder).st_mtime <= cutoff:
                os.rmdir(folder)   # só funciona se estiver vazia
        except OSError:
            pass


def _scan_targets(category, targets, execute, cutoff, policy, notify, prune=False, temp_kind=False):
    count, size, errors = 0, 0, 0

    def walk_error(_error):
        nonlocal errors
        errors += 1

    def result(truncated=False):
        return {'file_count': count, 'bytes': size, 'errors': errors, 'truncated': truncated}

    def take(path, stat_result, root):
        nonlocal count, size, errors
        try:
            if stat_result.st_mtime > cutoff or policy.excluded(path):
                return
            if not os.path.realpath(path).startswith(os.path.realpath(root)):   # nunca fora da raiz da categoria
                return
            if execute:
                _remove_file(path)
            count += 1
            size += stat_result.st_size
        except OSError:
            errors += 1
        if count and count % 500 == 0:
            notify(count)

    for target, mode, patterns in targets:
        target = Path(target)
        try:
            if _is_link(target) or not target.exists():
                continue
        except OSError:
            continue
        if mode == 'single':
            try:
                if target.is_file():
                    take(target, target.stat(), target.parent)
            except OSError:
                errors += 1
            continue
        if mode == 'chkdsk':
            roots = [d for d in _safe_listdir(target) if d.name.lower().startswith('found.') and d.is_dir() and not _is_link(d)]
            patterns, mode = ('*.chk',), 'treematch'
            sub = [(r, 'treematch', patterns) for r in roots]
            partial = _scan_targets(category, sub, execute, cutoff, policy, notify)
            count += partial['file_count']
            size += partial['bytes']
            errors += partial['errors']
            continue
        if not target.is_dir():
            continue
        root = target.resolve()
        recursive = mode in ('tree', 'treematch')
        for folder, dirs, files in os.walk(root, topdown=True, followlinks=False, onerror=walk_error):
            current = Path(folder)
            relative = current.relative_to(root).parts
            dirs[:] = [d for d in dirs if not _is_link(current / d) and not policy.excluded(current / d)
                       and not _protected_temp('temp' if temp_kind else '', relative, d)]
            if not recursive:
                dirs[:] = []
            for filename in files:
                if count >= MAX_FILES:
                    return result(True)
                if patterns and not any(fnmatch.fnmatch(filename.lower(), p) for p in patterns):
                    continue
                if _protected_temp('temp' if temp_kind else '', relative, filename):
                    continue
                path = current / filename
                try:
                    if _is_link(path) or not path.is_file():
                        continue
                    take(path, path.stat(), root)
                except OSError:
                    errors += 1
        if execute and prune:
            _prune_empty(root, cutoff, policy)
    return result()


def _safe_listdir(path):
    try:
        return list(Path(path).iterdir())
    except OSError:
        return []


# ----------------------------------------------------------------------------------------------------------------
# Itens especiais da Limpeza personalizada
# ----------------------------------------------------------------------------------------------------------------
def _remove_tree(path):
    """Remove uma pasta da Lixeira sem seguir links. Retorna (arquivos, bytes, erros)."""
    count, size, errors = 0, 0, 0
    for folder, dirs, files in os.walk(path, topdown=False, followlinks=False):
        for name in files:
            target = os.path.join(folder, name)
            try:
                length = os.lstat(target).st_size
                _remove_file(target)
                count += 1
                size += length
            except OSError:
                errors += 1
        for name in dirs:
            try:
                os.rmdir(os.path.join(folder, name))
            except OSError:
                pass
    try:
        os.rmdir(path)
    except OSError:
        pass
    return count, size, errors


def _tree_stats(path):
    count, total = 0, 0
    for folder, _dirs, files in os.walk(path, followlinks=False):
        for name in files:
            try:
                total += os.lstat(os.path.join(folder, name)).st_size
                count += 1
            except OSError:
                pass
    return count, total


def _scan_recycle(execute, cutoff, policy, notify):
    """Lixeira: cada item é um par $I (metadados, data da exclusão) + $R (conteúdo)."""
    count, size, errors = 0, 0, 0
    base = _system_drive() / '$Recycle.Bin'
    try:
        owners = [p for p in base.iterdir() if p.is_dir() and not _is_link(p)]
    except OSError:
        return {'file_count': 0, 'bytes': 0, 'errors': 1, 'truncated': False}
    for owner in owners:
        try:
            names = [n for n in os.listdir(owner) if n.startswith('$I')]
        except OSError:
            errors += 1
            continue
        for meta in names:
            if count >= MAX_FILES:
                return {'file_count': count, 'bytes': size, 'errors': errors, 'truncated': True}
            info, content = owner / meta, owner / ('$R' + meta[2:])
            try:
                if os.lstat(info).st_mtime > cutoff or _is_link(content):
                    continue
                if content.is_dir():
                    if execute:
                        files, length, failed = _remove_tree(str(content))
                    else:
                        files, length = _tree_stats(content)
                        failed = 0
                    count, size, errors = count + files, size + length, errors + failed
                elif content.is_file():
                    length = os.lstat(content).st_size
                    if execute:
                        _remove_file(content)
                    count += 1
                    size += length
                if execute and not os.path.lexists(content):
                    _remove_file(info)
            except OSError:
                errors += 1
            notify(count)
    return {'file_count': count, 'bytes': size, 'errors': errors, 'truncated': False}


def _run(args, timeout=120, text=True):
    from arcoll_programs import run_tracked  # executor único com cancelamento (derruba a árvore de processos)
    return run_tracked(args, timeout=timeout, text=text, creationflags=NO_WINDOW,
                       encoding='utf-8' if text else None, errors='replace' if text else None)


def _powershell(script, timeout=180):
    """PowerShell sem perfil e sem janela; saída em UTF-8."""
    wrapped = '[Console]::OutputEncoding=[System.Text.Encoding]::UTF8; $ProgressPreference="SilentlyContinue"; ' + script
    return _run(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', wrapped], timeout)


def _clipboard(execute):
    if os.name != 'nt':
        return {'file_count': 0, 'bytes': 0, 'errors': 0, 'truncated': False}
    user32 = ctypes.windll.user32
    errors = 0
    if execute:
        if user32.OpenClipboard(None):
            try:
                user32.EmptyClipboard()
            finally:
                user32.CloseClipboard()
        else:
            errors = 1
    return {'file_count': 0, 'bytes': 0, 'errors': errors, 'truncated': False, 'action': True}


def _dns_cache(execute):
    errors = 0
    if execute:
        try:
            errors = 0 if _run(['ipconfig', '/flushdns'], 30).returncode == 0 else 1
        except (OSError, subprocess.SubprocessError):
            errors = 1
    return {'file_count': 0, 'bytes': 0, 'errors': errors, 'truncated': False, 'action': True}


def _event_logs(execute, progress):
    """Logs de eventos do Windows. O log Security fica de fora: é trilha de auditoria."""
    logs_dir = _system_root() / 'System32' / 'winevt' / 'Logs'
    count, size, errors = 0, 0, 0
    for path in _safe_listdir(logs_dir):
        if path.suffix.lower() == '.evtx' and path.name.lower() != 'security.evtx':
            try:
                size += path.stat().st_size
                count += 1
            except OSError:
                errors += 1
    if execute:
        try:
            names = [n.strip() for n in _run(['wevtutil', 'el'], 60).stdout.splitlines() if n.strip() and n.strip().lower() != 'security']
        except (OSError, subprocess.SubprocessError):
            return {'file_count': 0, 'bytes': 0, 'errors': 1, 'truncated': False}
        for name in names:
            try:
                if _run(['wevtutil', 'cl', name], 30).returncode != 0:
                    errors += 1
            except (OSError, subprocess.SubprocessError):
                errors += 1
    return {'file_count': count, 'bytes': size, 'errors': errors, 'truncated': False}


def _registry_hive(name):
    return {'HKCU': winreg.HKEY_CURRENT_USER, 'HKLM': winreg.HKEY_LOCAL_MACHINE}[name] if winreg else None


def _clear_registry_values(entries, execute):
    """Apaga valores (nunca chaves) das listas de histórico. Conta cada valor como um item."""
    count, errors = 0, 0
    if winreg is None:
        return {'file_count': 0, 'bytes': 0, 'errors': 0, 'truncated': False}

    def walk(hive, path, recursive):
        nonlocal count, errors
        try:
            key = winreg.OpenKey(hive, path, 0, winreg.KEY_READ | (winreg.KEY_SET_VALUE if execute else 0) | winreg.KEY_WOW64_64KEY)
        except OSError:
            return
        with key:
            names = []
            try:
                index = 0
                while True:
                    value = winreg.EnumValue(key, index)[0]
                    index += 1
                    if value != '':
                        names.append(value)
            except OSError:
                pass
            for value in names:
                try:
                    if execute:
                        winreg.DeleteValue(key, value)
                    count += 1
                except OSError:
                    errors += 1
            if recursive:
                subs = []
                try:
                    index = 0
                    while True:
                        subs.append(winreg.EnumKey(key, index))
                        index += 1
                except OSError:
                    pass
                for sub in subs:
                    walk(hive, path + '\\' + sub, recursive)

    for hive_name, path, recursive in entries:
        walk(_registry_hive(hive_name), path, recursive)
    return {'file_count': count, 'bytes': 0, 'errors': errors, 'truncated': False, 'registry': True}


def _firefox_history(execute):
    """Histórico do Firefox via SQL: favoritos (moz_bookmarks) e seus endereços permanecem."""
    rows, errors = 0, 0
    for profile in _firefox_profiles('roaming'):
        database = profile / 'places.sqlite'
        if not database.is_file():
            continue
        try:
            uri = 'file:' + str(database).replace('\\', '/') + ('?mode=rw' if execute else '?mode=ro&immutable=1')
            with sqlite3.connect(uri, uri=True, timeout=3) as con:
                rows += con.execute('SELECT COUNT(*) FROM moz_historyvisits').fetchone()[0]
                if execute:
                    con.execute('DELETE FROM moz_historyvisits')
                    con.execute('DELETE FROM moz_places WHERE id NOT IN (SELECT fk FROM moz_bookmarks WHERE fk IS NOT NULL) '
                                'AND id NOT IN (SELECT place_id FROM moz_inputhistory)')
                    con.commit()
        except (sqlite3.Error, OSError):
            errors += 1   # banco bloqueado: Firefox aberto
    return {'file_count': rows, 'bytes': 0, 'errors': errors, 'truncated': False, 'records': True}


# ----------------------------------------------------------------------------------------------------------------
# Pastas personalizadas (Incluir) — rígido: nada de raízes, perfis, Documentos, Desktop, Windows ou Arquivos de Programas
# ----------------------------------------------------------------------------------------------------------------
def _custom_folder_allowed(path):
    """Pasta personalizada: nunca raízes, perfis, bibliotecas do usuário, AppData, Windows, Arquivos de Programas ou ProgramData."""
    try:
        resolved = Path(path).resolve()
    except OSError:
        return False
    if not resolved.is_absolute() or len(resolved.parts) < 3 or _is_link(resolved) or not resolved.is_dir():
        return False
    key = _norm(resolved)
    tree = [_system_root(), os.environ.get('ProgramFiles', r'C:\Program Files'), os.environ.get('ProgramFiles(x86)', r'C:\Program Files (x86)'),
            os.environ.get('ProgramData', r'C:\ProgramData'), _system_drive() / 'Recovery', _system_drive() / 'System Volume Information',
            _system_drive() / '$Recycle.Bin']
    exact = [_system_drive() / 'Users']
    for profile in _profiles():
        home = profile['home']
        exact += [home, profile['local'], profile['roaming'], profile['locallow'], home / 'AppData']
        exact += [home / name for name in ('Desktop', 'Documents', 'Pictures', 'Videos', 'Music', 'Downloads', 'OneDrive')]
    if any(key == _norm(t) or key.startswith(_norm(t) + os.sep) for t in tree):
        return False
    return not any(key == _norm(e) for e in exact)


# ----------------------------------------------------------------------------------------------------------------
# Limpeza personalizada
# ----------------------------------------------------------------------------------------------------------------
def _disk():
    drive = os.environ.get('SystemDrive', 'C:')
    try:
        usage = shutil.disk_usage(drive + os.sep)
        return {'drive': drive, 'total': int(usage.total), 'free': int(usage.free)}
    except OSError:
        return None


def _scan_category(category, execute, cutoff, policy, custom, notify, progress):
    if category == 'recycle_bin':
        return _scan_recycle(execute, cutoff, policy, notify)
    if category == 'clipboard':
        return _clipboard(execute)
    if category == 'dns_cache':
        return _dns_cache(execute)
    if category == 'event_logs':
        return _event_logs(execute, progress)
    if category == 'firefox_history':
        return _firefox_history(execute)
    if category in _REG_VALUE_ITEMS:
        return _clear_registry_values(_REG_VALUE_ITEMS[category], execute)
    if category == 'custom_folders':
        targets = [(Path(p), 'tree', ()) for p in custom[:MAX_CUSTOM_PATHS] if _custom_folder_allowed(p)]
        return _scan_targets(category, targets, execute, cutoff, policy, notify, prune=True)
    targets = _file_targets(category)
    unique, seen = [], set()
    for target in targets:   # o mesmo Temp aparecia como perfil e como %TEMP%
        try:
            key = (os.path.normcase(str(Path(target[0]).resolve())), target[1])
        except OSError:
            continue
        if key not in seen:
            seen.add(key)
            unique.append(target)
    prune = category in ('user_temp', 'windows_temp', 'windows_update', 'delivery_optimization', 'error_reports')
    return _scan_targets(category, unique, execute, cutoff, policy, notify, prune=prune, temp_kind=category in ('user_temp', 'windows_temp'))


def execute_clean(categories, execute=False, age_days=DEFAULT_AGE_DAYS, progress=None, exclusions=None, custom_folders=None):
    """Analisa (execute=False) ou limpa (execute=True). `progress(texto)` recebe o andamento a cada poucos segundos."""
    if (not isinstance(categories, list) or not categories or len(categories) > len(CATEGORIES)
            or len(set(categories)) != len(categories) or any(x not in CATEGORIES for x in categories)):
        raise ValueError('Categorias de limpeza inválidas.')
    if not isinstance(age_days, int) or isinstance(age_days, bool) or age_days not in AGE_OPTIONS:
        raise ValueError('Prazo de limpeza inválido.')
    cutoff = time.time() - age_days * 86400
    policy = Policy(exclusions)
    custom = [p for p in (custom_folders or []) if isinstance(p, str)]
    verb = 'Limpando' if execute else 'Analisando'
    result = {'categories': {}, 'file_count': 0, 'bytes': 0, 'errors': 0, 'execute': bool(execute), 'age_days': age_days, 'truncated': False}
    last = [0.0]
    for position, category in enumerate(categories, 1):
        label = _label(category)

        percent = (position - 1) * 100 // len(categories)

        def notify(count, label=label, number=position, percent=percent):
            now = time.time()
            if progress and now - last[0] >= 5:
                last[0] = now
                progress('[%d%%] %s %d/%d: %s (%d itens)' % (percent, verb, number, len(categories), label, count))

        if progress:
            progress('[%d%%] %s %d/%d: %s' % (percent, verb, position, len(categories), label))
        try:
            item = _scan_category(category, execute, cutoff, policy, custom, notify, progress)
        except Exception:   # uma categoria com falha não interrompe as demais
            item = {'file_count': 0, 'bytes': 0, 'errors': 1, 'truncated': False}
        result['categories'][category] = item
        result['file_count'] += item['file_count']
        result['bytes'] += item['bytes']
        result['errors'] += item['errors']
        result['truncated'] = result['truncated'] or bool(item.get('truncated'))
    disk = _disk()
    if disk:
        result['disk'] = disk
    return result


_PCT = re.compile(r'^\[(\d{1,3})%\]\s*')


def _scaled(progress, low, high):
    """Converte o andamento de uma etapa (0-100 %) para a faixa [low, high] do andamento total."""
    if not progress:
        return None

    def inner(text):
        found = _PCT.match(text)
        if found:
            progress('[%d%%] %s' % (low + (high - low) * min(100, int(found.group(1))) // 100, text[found.end():]))
        else:
            progress('[%d%%] %s' % (low, text))
    return inner


def _step(progress, percent, text):
    if progress:
        progress('[%d%%] %s' % (max(0, min(100, int(percent))), text))


# ----------------------------------------------------------------------------------------------------------------
# Utilitários compartilhados pelas ferramentas
# ----------------------------------------------------------------------------------------------------------------
def _item_id(*parts):
    return hashlib.sha1('|'.join(str(p) for p in parts).encode('utf-8', 'replace')).hexdigest()[:16]


def _fixed_drive(letter):
    """Aceita apenas unidades fixas (nunca rede, USB ou CD)."""
    if os.name != 'nt' or not re.fullmatch(r'[A-Za-z]:', letter or ''):
        return False
    return ctypes.windll.kernel32.GetDriveTypeW(letter.upper() + '\\') == 3


def _recycle(path):
    """Envia para a Lixeira (desfazível). Retorna True em caso de sucesso."""
    from ctypes import wintypes

    class SHFILEOPSTRUCTW(ctypes.Structure):
        _pack_ = 1 if ctypes.sizeof(ctypes.c_void_p) == 4 else 8
        _fields_ = [('hwnd', wintypes.HWND), ('wFunc', wintypes.UINT), ('pFrom', ctypes.c_void_p), ('pTo', ctypes.c_void_p),
                    ('fFlags', ctypes.c_ushort), ('fAnyOperationsAborted', wintypes.BOOL), ('hNameMappings', ctypes.c_void_p),
                    ('lpszProgressTitle', wintypes.LPCWSTR)]

    source = ctypes.create_unicode_buffer(str(path) + '\0')
    op = SHFILEOPSTRUCTW()
    op.wFunc = 3                       # FO_DELETE
    op.pFrom = ctypes.addressof(source)
    op.fFlags = 0x40 | 0x10 | 0x4 | 0x400   # ALLOWUNDO | NOCONFIRMATION | SILENT | NOERRORUI
    return ctypes.windll.shell32.SHFileOperationW(ctypes.byref(op)) == 0 and not op.fAnyOperationsAborted


def _sha1_file(path, limit=None):
    digest = hashlib.sha1()
    remaining = limit
    with open(path, 'rb') as handle:
        while True:
            block = handle.read(1048576 if remaining is None else min(1048576, remaining))
            if not block:
                break
            digest.update(block)
            if remaining is not None:
                remaining -= len(block)
                if remaining <= 0:
                    break
    return digest.hexdigest()


def _save_cache(name, data):
    try:
        DATA_DIR.mkdir(parents=True, exist_ok=True)
        temp = DATA_DIR / (name + '.tmp')
        temp.write_text(json.dumps(data), encoding='utf-8')
        os.replace(temp, DATA_DIR / name)
    except OSError:
        pass


def _load_cache(name):
    try:
        return json.loads((DATA_DIR / name).read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return None


# ----------------------------------------------------------------------------------------------------------------
# Registro
# ----------------------------------------------------------------------------------------------------------------
REGISTRY_CHECKS = ('shared_dlls', 'app_paths', 'startup_missing', 'fonts', 'help_files', 'uninstall_orphans', 'com_activex', 'mui_cache')
MAX_REGISTRY_ISSUES = 1500
_CMD_PATH = re.compile(r'^\s*"?([A-Za-z]:\\[^"*?<>|]*?\.(?:exe|dll|bat|cmd|com|sys|ocx|cpl|scr))', re.I)
_BACKUP_NAME = re.compile(r'\d{8}-\d{6}\Z')


def _path_missing(path):
    """True somente quando o caminho absoluto aponta para uma unidade existente e o arquivo realmente não existe."""
    if not path or '%' in path or not re.match(r'[A-Za-z]:\\', path) or not os.path.exists(path[:3]):
        return False
    candidates = [path]
    low = path.lower()
    system32 = str(_system_root() / 'System32').lower()
    if low.startswith(system32):
        candidates.append(str(_system_root() / 'SysWOW64') + path[len(system32):])
    return not any(os.path.exists(c) for c in candidates)


def _command_target(command):
    match = _CMD_PATH.match(os.path.expandvars(str(command or '')))
    return match.group(1) if match else None


def _reg_open(hive, path, write=False):
    return winreg.OpenKey(_registry_hive(hive), path, 0, winreg.KEY_READ | (winreg.KEY_SET_VALUE if write else 0) | winreg.KEY_WOW64_64KEY)


def _reg_values(hive, path):
    try:
        with _reg_open(hive, path) as key:
            index = 0
            while True:
                try:
                    yield winreg.EnumValue(key, index)
                except OSError:
                    return
                index += 1
    except OSError:
        return


def _reg_subkeys(hive, path):
    names = []
    try:
        with _reg_open(hive, path) as key:
            index = 0
            while True:
                try:
                    names.append(winreg.EnumKey(key, index))
                except OSError:
                    break
                index += 1
    except OSError:
        pass
    return names


def _reg_default(hive, path):
    try:
        with _reg_open(hive, path) as key:
            return winreg.QueryValueEx(key, '')[0]
    except OSError:
        return None


def _issue(check, hive, key, value, detail):
    return {'id': _item_id(check, hive, key, value if value is not None else '<key>'), 'check': check, 'hive': hive, 'key': key,
            'value': value, 'detail': str(detail)[:300]}


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


def _fix_registry(issues, selected_ids, progress):
    """Exporta cada chave afetada (.reg) e só então remove. Sem backup válido, o item não é alterado."""
    stamp = time.strftime('%Y%m%d-%H%M%S')
    folder = _backup_root() / stamp
    folder.mkdir(parents=True, exist_ok=True)
    exported, fixed, failed = {}, 0, 0
    chosen = [i for i in issues if i['id'] in selected_ids]
    for number, issue in enumerate(chosen, 1):
        if progress and number % 20 == 1:
            progress('Corrigindo registro %d/%d' % (number, len(chosen)))
        ident = (issue['hive'], issue['key'])
        try:
            if ident not in exported:
                destination = folder / ('%04d.reg' % (len(exported) + 1))
                exported[ident] = destination if _export_key(issue['hive'], issue['key'], destination) else None
            if exported[ident] is None:
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
        report.update(_fix_registry(issues, set(ids), progress))
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


def _apply_startup(items, operations):
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
                        folder.mkdir(parents=True, exist_ok=True)
                        if not _export_key(item['_hive'], item['_key'], folder / ('%s.reg' % _item_id(item['_hive'], item['_key']))):
                            raise OSError('backup')
                        with _reg_open(item['_hive'], item['_key'], write=True) as key:
                            winreg.DeleteValue(key, name)
                    elif not _recycle(item['_file']):
                        raise OSError('recycle')
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
                    folder.mkdir(parents=True, exist_ok=True)
                    path = 'SOFTWARE\\Classes\\%s\\shellex\\ContextMenuHandlers\\%s' % (item['_location'], item['_name'])
                    if not _export_key('HKLM', path, folder / ('%s.reg' % item['id'])):
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
        report.update(_apply_startup(items, operations))
        items = _startup_catalog()
    report['items'] = _public_startup(items)
    return report


# ----------------------------------------------------------------------------------------------------------------
# Analisador de disco
# ----------------------------------------------------------------------------------------------------------------
_TYPES = {
    'Imagens': ('.jpg', '.jpeg', '.png', '.gif', '.bmp', '.tif', '.tiff', '.webp', '.heic', '.psd', '.cdr', '.svg', '.raw'),
    'Vídeos': ('.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.mpg', '.mpeg', '.m4v'),
    'Música': ('.mp3', '.wav', '.flac', '.aac', '.ogg', '.m4a', '.wma'),
    'Documentos': ('.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.pdf', '.txt', '.csv', '.odt', '.ods', '.rtf'),
    'Compactados': ('.zip', '.rar', '.7z', '.gz', '.tar', '.iso', '.cab', '.img', '.vhd', '.vhdx'),
    'Programas e sistema': ('.exe', '.dll', '.sys', '.msi', '.msu', '.dat', '.bin', '.mui', '.cat'),
}
_EXTENSION_TYPE = {ext: name for name, exts in _TYPES.items() for ext in exts}
_DISK_SKIP = ('system volume information',)


def _tool_disk(payload, progress):
    drive = str(payload.get('drive') or os.environ.get('SystemDrive', 'C:')).upper()
    if not _fixed_drive(drive):
        raise ValueError('Unidade inválida ou não fixa.')
    root = drive + '\\'
    deadline = time.time() + 240
    by_type = {name: [0, 0] for name in list(_TYPES) + ['Outros']}
    level1, level2, largest = {}, {}, []
    files, total, truncated = 0, 0, False
    stack = [(root, ())]
    while stack:
        if time.time() > deadline:
            truncated = True
            break
        folder, chain = stack.pop()
        try:
            with os.scandir(folder) as entries:
                for entry in entries:
                    try:
                        if entry.is_symlink() or (getattr(entry.stat(follow_symlinks=False), 'st_file_attributes', 0) & _REPARSE_POINT):
                            continue
                        if entry.is_dir(follow_symlinks=False):
                            if entry.name.lower() not in _DISK_SKIP:
                                stack.append((entry.path, chain + (entry.name,) if len(chain) < 2 else chain))
                            continue
                        size = entry.stat(follow_symlinks=False).st_size
                    except OSError:
                        continue
                    files += 1
                    total += size
                    kind = _EXTENSION_TYPE.get(os.path.splitext(entry.name)[1].lower(), 'Outros')
                    by_type[kind][0] += 1
                    by_type[kind][1] += size
                    if chain:
                        level1[chain[0]] = level1.get(chain[0], 0) + size
                        if len(chain) > 1:
                            level2[chain[0] + '\\' + chain[1]] = level2.get(chain[0] + '\\' + chain[1], 0) + size
                    if size > 1048576:
                        if len(largest) < 50:
                            heapq.heappush(largest, (size, entry.path))
                        elif size > largest[0][0]:
                            heapq.heapreplace(largest, (size, entry.path))
        except OSError:
            continue
        if progress and files and files % 20000 < 200:
            progress('Analisando %s: %d arquivos' % (drive, files))
    usage = shutil.disk_usage(root)
    return {'tool': 'disk', 'drive': drive, 'files': files, 'bytes': total, 'truncated': truncated, 'disk_total': int(usage.total), 'disk_free': int(usage.free),
            'types': [{'name': n, 'count': v[0], 'bytes': v[1]} for n, v in sorted(by_type.items(), key=lambda kv: -kv[1][1])],
            'folders': [{'path': drive + '\\' + k, 'bytes': v} for k, v in sorted(level1.items(), key=lambda kv: -kv[1])[:25]]
                       + [{'path': drive + '\\' + k, 'bytes': v} for k, v in sorted(level2.items(), key=lambda kv: -kv[1])[:40]],
            'largest': [{'path': p, 'bytes': s} for s, p in sorted(largest, reverse=True)]}


# ----------------------------------------------------------------------------------------------------------------
# Localizador de duplicados
# ----------------------------------------------------------------------------------------------------------------
_DUP_SKIP_DIRS = {'windows', 'program files', 'program files (x86)', 'programdata', '$recycle.bin', 'system volume information', 'appdata',
                  'node_modules', '.git', 'recovery', 'msocache', 'onedrivetemp'}
MAX_DUP_GROUPS = 300
MAX_DUP_PER_GROUP = 20


def _duplicate_roots(tokens):
    roots = []
    for token in tokens:
        if token == 'users':
            for profile in _profiles():
                roots += [profile['home'] / n for n in ('Desktop', 'Documents', 'Downloads', 'Pictures', 'Videos', 'Music')]
        elif _fixed_drive(str(token)):
            roots.append(Path(str(token).upper() + '\\'))
        else:
            raise ValueError('Origem de duplicados inválida.')
    return [r for r in _dedupe(roots) if r.is_dir()]


def _tool_duplicates(payload, progress):
    report = {'tool': 'duplicates'}
    if payload.get('action') == 'delete':
        report.update(_delete_duplicates(payload))
    else:
        tokens = payload.get('roots') or ['users']
        if not isinstance(tokens, list) or not tokens or len(tokens) > 6:
            raise ValueError('Origens de duplicados inválidas.')
        min_size = int(payload.get('min_size_kb') or 1024) * 1024
        if min_size < 1024 or min_size > 1073741824:
            raise ValueError('Tamanho mínimo inválido.')
        deadline = time.time() + 900
        by_size, scanned, truncated = {}, 0, False
        for root in _duplicate_roots(tokens):
            for folder, dirs, files in os.walk(root, followlinks=False):
                dirs[:] = [d for d in dirs if d.lower() not in _DUP_SKIP_DIRS and not _is_link(os.path.join(folder, d))]
                for name in files:
                    path = os.path.join(folder, name)
                    try:
                        info = os.lstat(path)
                    except OSError:
                        continue
                    if info.st_size >= min_size and not (getattr(info, 'st_file_attributes', 0) & _REPARSE_POINT):
                        by_size.setdefault(info.st_size, []).append(path)
                        scanned += 1
                if time.time() > deadline or scanned > 600000:
                    truncated = True
                    break
            if truncated:
                break
        candidates = {s: p for s, p in by_size.items() if len(p) > 1}
        groups, hashed = [], 0
        for number, (size, paths) in enumerate(sorted(candidates.items(), reverse=True), 1):
            if time.time() > deadline:
                truncated = True
                break
            if progress and number % 25 == 0:
                progress('Comparando arquivos: grupo %d/%d' % (number, len(candidates)))
            partial = {}
            for path in paths:
                try:
                    partial.setdefault(_sha1_file(path, 65536), []).append(path)
                except OSError:
                    pass
            for same in (v for v in partial.values() if len(v) > 1):
                full = {}
                for path in same:
                    try:
                        full.setdefault(_sha1_file(path), []).append(path)
                        hashed += size
                    except OSError:
                        pass
                for digest, equal in full.items():
                    if len(equal) > 1:
                        groups.append({'hash': digest, 'size': size, 'paths': sorted(equal)[:MAX_DUP_PER_GROUP]})
        groups.sort(key=lambda g: -g['size'] * (len(g['paths']) - 1))
        groups = groups[:MAX_DUP_GROUPS]
        cache = [{'hash': g['hash'], 'size': g['size'], 'files': [{'id': _item_id(p), 'path': p} for p in g['paths']]} for g in groups]
        _save_cache('duplicates.json', cache)
        report.update({'groups': [{'size': g['size'], 'files': [{'id': f['id'], 'path': f['path'], 'modified': int(os.path.getmtime(f['path'])) if os.path.exists(f['path']) else 0}
                                                                  for f in g['files']]} for g in cache],
                       'scanned': scanned, 'wasted': sum(g['size'] * (len(g['files']) - 1) for g in cache), 'truncated': truncated})
    return report


def _delete_duplicates(payload):
    ids = payload.get('ids')
    if not isinstance(ids, list) or not ids or len(ids) > 500 or any(not isinstance(i, str) for i in ids):
        raise ValueError('Seleção de duplicados inválida.')
    cache, chosen, removed, freed, failed = _load_cache('duplicates.json'), set(ids), 0, 0, 0
    if not cache:
        raise ValueError('Execute a busca de duplicados novamente.')
    for group in cache:
        victims = [f for f in group['files'] if f['id'] in chosen]
        keepers = [f for f in group['files'] if f['id'] not in chosen and os.path.isfile(f['path'])]
        if not victims or not keepers:   # nunca remove todas as cópias
            failed += len(victims)
            continue
        try:
            if os.path.getsize(keepers[0]['path']) != group['size'] or _sha1_file(keepers[0]['path']) != group['hash']:
                failed += len(victims)
                continue
        except OSError:
            failed += len(victims)
            continue
        for victim in victims:
            try:
                if os.path.getsize(victim['path']) != group['size'] or _sha1_file(victim['path']) != group['hash']:
                    failed += 1
                    continue
                if payload.get('permanent'):
                    _remove_file(victim['path'])
                elif not _recycle(victim['path']):
                    failed += 1
                    continue
                removed += 1
                freed += group['size']
            except OSError:
                failed += 1
    return {'removed': removed, 'freed': freed, 'failed': failed}


# ----------------------------------------------------------------------------------------------------------------
# Restauração do sistema
# ----------------------------------------------------------------------------------------------------------------
def _tool_restore(payload, progress):
    report = {'tool': 'restore'}
    if payload.get('action') == 'create':
        if progress:
            progress('Criando ponto de restauração...')
        proc = _powershell("Checkpoint-Computer -Description 'Arcoll Clean' -RestorePointType MODIFY_SETTINGS -ErrorAction Stop", 300)
        report['created'] = proc.returncode == 0
        if proc.returncode != 0:
            report['message'] = (proc.stderr or proc.stdout or 'Falha ao criar o ponto.').strip()[:300]
    try:
        query = _powershell('ConvertTo-Json -InputObject @(Get-ComputerRestorePoint -ErrorAction Stop | Select-Object SequenceNumber,Description,CreationTime,RestorePointType) -Compress', 90)
        if query.returncode != 0:
            raise RuntimeError((query.stderr or query.stdout or 'O Windows não confirmou a consulta.').strip()[:300])
        raw = query.stdout.strip()
        points = json.loads(raw) if raw else []
        points = points if isinstance(points, list) else [points]
        report['points'] = [{'sequence': int(p.get('SequenceNumber') or 0), 'description': str(p.get('Description') or '')[:120],
                             'created': str(p.get('CreationTime') or '')[:14], 'type': int(p.get('RestorePointType') or 0)} for p in points if isinstance(p, dict)][-30:]
        report['complete'] = True
    except (OSError, subprocess.SubprocessError, ValueError, TypeError, RuntimeError) as exc:
        report['points'] = []
        report['complete'] = False
        detail = 'Não foi possível consultar os pontos de restauração: ' + str(exc)[:300]
        report['message'] = (report.get('message', '') + ' ' + detail).strip()
    return report


# ----------------------------------------------------------------------------------------------------------------
# Atualizador de software (winget)
# ----------------------------------------------------------------------------------------------------------------
_WINGET_ID = re.compile(r'[A-Za-z0-9][A-Za-z0-9.+_-]{1,120}\Z')


def _parse_winget_table(text):
    """Lê a tabela de `winget upgrade` pelas posições das colunas do cabeçalho (funciona em qualquer idioma)."""
    lines = [l.rstrip() for l in text.replace('\r', '\n').split('\n')]
    lines = [l for l in lines if l.strip() and l.strip() not in ('-', '\\', '|', '/') and not set(l.strip()) <= set('█▒░ ')]
    for index, line in enumerate(lines):
        if index and re.fullmatch(r'-{5,}', line.strip()):
            starts = [m.start() for m in re.finditer(r'\S+(?: \S+)*', lines[index - 1])]
            if len(starts) < 4:
                continue
            rows = []
            for row in lines[index + 1:]:
                if re.match(r'^\d+\s', row.strip()) and len(row) < starts[1] + 5:
                    break
                cells = [row[a:b].strip() for a, b in zip(starts, starts[1:] + [None])]
                if len(cells) >= 4 and _WINGET_ID.fullmatch(cells[1]):
                    rows.append({'name': cells[0][:120], 'id': cells[1], 'version': cells[2][:40], 'available': cells[3][:40]})
            return rows
    return []


def _winget_exe():
    path = shutil.which('winget')
    if not path:
        raise RuntimeError('O winget não está disponível neste computador.')
    return path


def _winget_list():
    out = _run([_winget_exe(), 'upgrade', '--accept-source-agreements', '--disable-interactivity'], 180)
    return _parse_winget_table(out.stdout)


def _tool_updater(payload, progress):
    report = {'tool': 'updater'}
    if payload.get('action') == 'apply':
        ids = payload.get('ids')
        if not isinstance(ids, list) or not ids or len(ids) > 30 or any(not isinstance(i, str) or not _WINGET_ID.fullmatch(i) for i in ids):
            raise ValueError('Seleção de atualizações inválida.')
        available = {r['id'] for r in _winget_list()}
        results = []
        for number, ident in enumerate(ids, 1):
            if ident not in available:
                results.append({'id': ident, 'ok': False, 'message': 'Sem atualização disponível.'})
                continue
            if progress:
                progress('Atualizando %d/%d: %s' % (number, len(ids), ident))
            try:
                proc = _run([_winget_exe(), 'upgrade', '--id', ident, '--exact', '--silent', '--accept-package-agreements',
                             '--accept-source-agreements', '--disable-interactivity'], 1800)
                results.append({'id': ident, 'ok': proc.returncode == 0, 'message': 'Atualizado.' if proc.returncode == 0 else 'Código %d' % proc.returncode})
            except (OSError, subprocess.SubprocessError) as exc:
                results.append({'id': ident, 'ok': False, 'message': str(exc)[:120]})
        report['results'] = results
    report['items'] = _winget_list()[:200]
    return report


# ----------------------------------------------------------------------------------------------------------------
# Verificação de integridade
# ----------------------------------------------------------------------------------------------------------------
HEALTH_SPACE = ('user_temp', 'windows_temp', 'thumbnails', 'system_logs', 'error_reports', 'windows_update', 'gpu_shader_cache', 'chkdsk_fragments')
HEALTH_PRIVACY = ('recent_docs', 'jump_lists', 'run_history', 'search_history', 'clipboard', 'firefox_history', 'firefox_cookies') + tuple('%s_%s' % (browser, facet) for browser in _CHROMIUM for facet in ('history', 'cookies'))


def _tool_health(payload, progress):
    space, privacy = payload.get('space') or [], payload.get('privacy') or []
    for group, allowed in ((space, HEALTH_SPACE), (privacy, HEALTH_PRIVACY)):
        if not isinstance(group, list) or len(set(group)) != len(group) or any(c not in allowed or c not in CATEGORIES for c in group):
            raise ValueError('Categorias de verificação inválidas.')
    empty = {'categories': {}, 'file_count': 0, 'bytes': 0, 'errors': 0}
    report = {'tool': 'health'}
    _step(progress, 2, 'Coletando e analisando arquivos...')
    report['privacy'] = execute_clean(privacy, False, 0, _scaled(progress, 2, 40), payload.get('exclusions'), payload.get('custom_folders')) if privacy else empty
    _step(progress, 40, 'Coletando alguns arquivos inúteis antigos...')
    report['space'] = execute_clean(space, False, 7, _scaled(progress, 40, 80), payload.get('exclusions'), payload.get('custom_folders')) if space else empty
    report['incomplete'] = any(report[p].get('errors', 0) or report[p].get('truncated', False) for p in ('space', 'privacy'))
    _step(progress, 80, 'Inventariando inicialização...')
    items = _startup_items()
    report['speed'] = {'startup_total': len(items), 'startup_enabled': sum(1 for i in items if i.get('enabled'))}
    _step(progress, 90, 'Analisando o registro, sem corrigir...')
    try:
        issues = _scan_registry(REGISTRY_CHECKS, _scaled(progress, 90, 99))
        report['registry'] = {'issues': len(issues), 'complete': True}
    except (OSError, subprocess.SubprocessError, RuntimeError) as exc:
        report['registry'] = {'issues': 0, 'complete': False, 'message': str(exc)[:250]}
        report['incomplete'] = True
    report['created'] = True
    report['captured_at'] = int(time.time())
    report['message'] = 'Análise concluída, sem alterações. Revise espaço, privacidade, registro e inicialização.'
    _step(progress, 100, 'Análise concluída. Nenhuma correção foi aplicada.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Otimização de Desempenho: suspender e retomar aplicativos em segundo plano
# ----------------------------------------------------------------------------------------------------------------
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
_CLOUD_CACHE = 'cloud-last.json'
_CLOUD_JUNK = ('~$*', '*.tmp', 'thumbs.db', '.ds_store', '*conflicted copy*', '*cópia em conflito*', '*.crdownload', '*.part', '*.partial')
_CLOUD_PLACEHOLDER = 0x1000 | 0x400000 | 0x40000   # OFFLINE | RECALL_ON_DATA_ACCESS | RECALL_ON_OPEN: ler o arquivo o baixaria da nuvem
MAX_CLOUD_ITEMS = 120


def _cloud_roots():
    roots, seen = [], set()

    def add(key, name, path):
        try:
            resolved = Path(path).resolve()
        except (OSError, ValueError):
            return
        if resolved.is_dir() and len(resolved.parts) >= 3 and _norm(resolved) not in seen:
            seen.add(_norm(resolved))
            roots.append({'key': key, 'name': name, 'root': resolved})

    for profile in _profiles():
        home = profile['home']
        for entry in _safe_listdir(home):
            low = entry.name.lower()
            if Path(entry).is_dir() and (low == 'onedrive' or low.startswith('onedrive -')):
                add('onedrive', 'OneDrive' + (' (%s)' % entry.name.split('-', 1)[1].strip() if '-' in entry.name else ''), entry)
        for name, key, label in (('Google Drive', 'gdrive', 'Google Drive'), ('iCloudDrive', 'icloud', 'iCloud Drive'), ('iCloud Drive', 'icloud', 'iCloud Drive'), ('Box', 'box', 'Box')):
            add(key, label, Path(home) / name)
        for base in (profile['local'], profile['roaming']):
            info = base / 'Dropbox' / 'info.json'
            try:
                data = json.loads(info.read_text(encoding='utf-8'))
                for account in data.values() if isinstance(data, dict) else []:
                    if isinstance(account, dict) and account.get('path'):
                        add('dropbox', 'Dropbox', account['path'])
            except (OSError, ValueError):
                pass
    for env in ('OneDrive', 'OneDriveConsumer', 'OneDriveCommercial'):
        if os.environ.get(env):
            add('onedrive', 'OneDrive', os.environ[env])
    for letter in 'DEFGHIJKLMNOPQRSTUVWXYZ':
        for name in ('Meu Drive', 'My Drive'):
            add('gdrive', 'Google Drive', '%s:\\%s' % (letter, name))
    return roots


def _cloud_scan(roots, progress, deadline):
    items, providers, total_files = [], [], 0
    by_size, large_min, old_min = {}, 100 * 1048576, 10 * 1048576
    old_cutoff = time.time() - 730 * 86400
    truncated = False
    for number, entry in enumerate(roots, 1):
        root, files, size = entry['root'], 0, 0
        stack = [str(root)]
        _step(progress, 5 + 60 * (number - 1) // max(1, len(roots)), 'Analisando %s...' % entry['name'])
        while stack:
            if time.time() > deadline or total_files > 400000:
                truncated = True
                break
            folder = stack.pop()
            try:
                with os.scandir(folder) as it:
                    for child in it:
                        try:
                            if child.is_symlink():
                                continue
                            attrs = getattr(child.stat(follow_symlinks=False), 'st_file_attributes', 0)
                            if child.is_dir(follow_symlinks=False):
                                if not attrs & _REPARSE_POINT:
                                    stack.append(child.path)
                                continue
                            st = child.stat(follow_symlinks=False)
                        except OSError:
                            continue
                        files += 1
                        total_files += 1
                        size += st.st_size
                        local = not (getattr(st, 'st_file_attributes', 0) & _CLOUD_PLACEHOLDER)
                        item = {'provider': entry['key'], 'path': child.path, 'bytes': int(st.st_size), 'modified': int(st.st_mtime), 'local': local}
                        low = child.name.lower()
                        if any(fnmatch.fnmatch(low, pat) for pat in _CLOUD_JUNK):
                            items.append(dict(item, cat='junk'))
                        elif st.st_size >= large_min:
                            items.append(dict(item, cat='large'))
                        elif st.st_size >= old_min and st.st_mtime < old_cutoff:
                            items.append(dict(item, cat='old'))
                        if local and st.st_size >= 1048576 and st.st_size <= 2 * 1073741824:
                            by_size.setdefault(st.st_size, []).append(item)
            except OSError:
                continue
        providers.append({'key': entry['key'], 'name': entry['name'], 'root': str(root), 'files': files, 'bytes': size})
    # Duplicados: mesmo tamanho, depois conteúdo idêntico (somente arquivos já presentes no computador).
    _step(progress, 70, 'Procurando arquivos duplicados na nuvem...')
    for size, group in by_size.items():
        if len(group) < 2 or len(group) > 8 or time.time() > deadline:
            continue
        hashes = {}
        for item in group:
            try:
                hashes.setdefault(_sha1_file(item['path']), []).append(item)
            except OSError:
                continue
        for same in hashes.values():
            for extra in sorted(same, key=lambda i: i['modified'])[1:]:
                items.append(dict(extra, cat='duplicate'))
    return items, providers, truncated


def _cloud_public(items):
    cats = {c: {'count': 0, 'bytes': 0} for c in ('junk', 'duplicate', 'large', 'old')}
    for item in items:
        cats[item['cat']]['count'] += 1
        cats[item['cat']]['bytes'] += item['bytes']
    rows = []
    for cat in cats:
        mine = sorted((i for i in items if i['cat'] == cat), key=lambda i: -i['bytes'])[:MAX_CLOUD_ITEMS]
        rows += [{'id': _item_id('cloud', i['path']), 'cat': cat, 'provider': i['provider'], 'path': i['path'][:400], 'bytes': i['bytes'],
                  'modified': i['modified'], 'local': i['local']} for i in mine]
    return cats, rows


def _tool_cloud(payload, progress):
    action = str(payload.get('action') or 'scan')
    report = {'tool': 'cloud'}
    if action == 'delete':
        ids = payload.get('ids')
        if not isinstance(ids, list) or not 1 <= len(ids) <= 300 or any(not re.fullmatch(r'[a-f0-9]{16}', str(i)) for i in ids):
            raise ValueError('Seleção de arquivos inválida.')
        last = _load_cache(_CLOUD_CACHE) or {}
        known = {i['id']: i for i in last.get('items', []) if isinstance(i, dict) and 'id' in i and 'path' in i}
        roots = [_norm(r['root']) for r in _cloud_roots()]
        removed = failed = freed = 0
        for number, ident in enumerate(ids, 1):
            _step(progress, 10 + 85 * number // len(ids), 'Enviando para a Lixeira %d/%d...' % (number, len(ids)))
            entry = known.get(ident)
            try:
                path = Path(entry['path']).resolve() if entry else None
                inside = path is not None and any(_norm(path).startswith(r + os.sep) for r in roots)
                if not inside or not path.is_file() or _is_link(path):
                    raise OSError('fora das pastas de nuvem')
                size = path.stat().st_size
                if not _recycle(path):
                    raise OSError('lixeira')
                removed += 1
                freed += size
            except (OSError, KeyError, ValueError):
                failed += 1
        report.update({'removed': removed, 'freed': freed, 'failed': failed})
    roots = _cloud_roots()
    if not roots:
        report.update({'providers': [], 'items': [], 'cats': {}, 'truncated': False})
        _save_cache(_CLOUD_CACHE, {'items': []})
        return report
    items, providers, truncated = _cloud_scan(roots, progress, time.time() + 420)
    cats, rows = _cloud_public(items)
    full = {_item_id('cloud', i['path']): i['path'] for i in items}
    _save_cache(_CLOUD_CACHE, {'items': [dict(r, path=full.get(r['id'], r['path'])) for r in rows]})   # caminhos completos ficam só no computador
    report.update({'providers': providers, 'cats': cats, 'items': rows, 'truncated': truncated})
    _step(progress, 100, 'Análise concluída.')
    return report


# ----------------------------------------------------------------------------------------------------------------
# Ponto de entrada das ferramentas
# ----------------------------------------------------------------------------------------------------------------
TOOLS = {'registry': _tool_registry, 'startup': _tool_startup, 'disk': _tool_disk, 'duplicates': _tool_duplicates,
         'restore': _tool_restore, 'updater': _tool_updater, 'health': _tool_health, 'performance': _tool_performance,
         'drivers': _tool_drivers, 'cloud': _tool_cloud}


def execute_tool(payload, progress=None):
    """Executa uma ferramenta do comando clean_tool e devolve o relatório (`tool` identifica o tipo)."""
    if not isinstance(payload, dict) or payload.get('tool') not in TOOLS:
        raise ValueError('Ferramenta inválida.')
    return TOOLS[payload['tool']](payload, progress)
