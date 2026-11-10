# Caminho: gestores/windows/arcoll_pentefino.py
"""Arcoll Pente-Fino (motor de manutenção): manutenção remota executada pelo gestor com privilégios de administrador.

Contrato com a plataforma (includes/network-pentefino.php):
  * execute_clean(...)  -> comandos pentefino_scan / pentefino_execute (Limpeza personalizada).
  * execute_tool(...)   -> comando pentefino_tool (registro, inicialização, disco, duplicados, restauração,
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
DATA_DIR = Path(os.environ.get('ProgramData', r'C:\ProgramData')) / 'Arcoll' / 'PenteFino'

# ----------------------------------------------------------------------------------------------------------------
# Catálogo. As chaves são o contrato com includes/network-pentefino.php (arc_pf_catalog); os caminhos ficam só aqui.
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
    """Texto do andamento; o catálogo completo de rótulos está em includes/network-pentefino.php."""
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

    def __init__(self, exclusions=None, modified_since=0):
        self.modified_since = modified_since
        self.exclusions = [_policy_norm(p, pattern=True) for p in (exclusions or []) if isinstance(p, str) and p.strip()][:50]

    def excluded(self, path):
        if self.modified_since:
            try:
                if Path(path).is_file() and Path(path).stat().st_mtime < self.modified_since:
                    return True
            except OSError:
                return True
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


def execute_clean(categories, execute=False, age_days=DEFAULT_AGE_DAYS, progress=None, exclusions=None, custom_folders=None, period_days=0):
    """Analisa (execute=False) ou limpa (execute=True). `progress(texto)` recebe o andamento a cada poucos segundos."""
    if (not isinstance(categories, list) or not categories or len(categories) > len(CATEGORIES)
            or len(set(categories)) != len(categories) or any(x not in CATEGORIES for x in categories)):
        raise ValueError('Categorias de limpeza inválidas.')
    if not isinstance(age_days, int) or isinstance(age_days, bool) or age_days not in AGE_OPTIONS:
        raise ValueError('Prazo de limpeza inválido.')
    cutoff = time.time() - age_days * 86400
    if isinstance(period_days, bool) or period_days not in (0, 7, 15, 30, 180, 365):
        raise ValueError('Período inválido.')
    policy = Policy(exclusions, time.time() - period_days * 86400 if period_days else 0)
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



# Despacho compatível; cada domínio possui seu próprio arquivo e estado de relatório.
TOOLS = {'registry': 'arcoll_pf_registry', 'startup': 'arcoll_pf_startup', 'disk': 'arcoll_pf_disk', 'duplicates': 'arcoll_pf_duplicates', 'restore': 'arcoll_pf_restore', 'updater': 'arcoll_pf_updater', 'health': 'arcoll_pf_health', 'performance': 'arcoll_pf_performance', 'drivers': 'arcoll_pf_drivers', 'cloud': 'arcoll_pf_cloud'}

def execute_tool(payload, progress=None):
    if not isinstance(payload,dict) or payload.get('tool') not in TOOLS:
        raise ValueError('Ferramenta inválida.')
    from importlib import import_module
    tool=payload['tool']
    module=import_module(TOOLS[tool])
    return getattr(module,'_tool_'+tool)(payload,progress)
