# Caminho: agentes/windows/arcoll_programs.py
"""Inventário e desinstalação de aplicativos Windows registrados; sem shell genérico.

Cada programa recebe um método de desinstalação:
  silent       comando sem janela (MSI, Inno Setup, NSIS, QuietUninstallString ou opções silenciosas já registradas);
  interactive  desinstalador do próprio programa, que pode abrir janela no computador (exige autorização);
  ''           sem desinstalador utilizável (indisponível).
"""
import ctypes
import hashlib
import os
import re
import datetime
import subprocess
import time
import winreg
from ctypes import wintypes

_UNINSTALL = r'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall'
_ROOTS = [(winreg.HKEY_LOCAL_MACHINE, 'HKLM'), (winreg.HKEY_CURRENT_USER, 'HKCU')]
_VIEWS = [winreg.KEY_WOW64_64KEY, winreg.KEY_WOW64_32KEY]
_GUID = re.compile(r'^\{[0-9A-Fa-f]{8}-(?:[0-9A-Fa-f]{4}-){3}[0-9A-Fa-f]{12}\}$')
_GUID_IN_COMMAND = re.compile(r'(\{[0-9A-Fa-f]{8}-(?:[0-9A-Fa-f]{4}-){3}[0-9A-Fa-f]{12}\})')
BATCH_MAX = 100  # mesmo valor de ARC_PROGRAM_BATCH_MAX em includes/network-clean.php
RESTORE_MODES = ('off', 'try', 'require')
_SILENT_FLAGS = {'/s', '/silent', '/verysilent', '/quiet', '/qn', '/q', '/qb', '-s', '-silent', '--silent', '-quiet', '--quiet', '--force-uninstall'}
_NSIS_NAMES = ('uninstall.exe', 'uninst.exe', 'uninstaller.exe')


def _read_value(key, name, default=''):
    try:
        value, _ = winreg.QueryValueEx(key, name)
        return value
    except OSError:
        return default


def _command_args(command):
    """Use native Windows argv parsing, never cmd.exe / shell=True."""
    if not isinstance(command, str) or not command.strip() or len(command) > 3000:
        return []
    count = ctypes.c_int()
    fn = ctypes.windll.shell32.CommandLineToArgvW
    fn.argtypes = [wintypes.LPCWSTR, ctypes.POINTER(ctypes.c_int)]
    fn.restype = ctypes.POINTER(wintypes.LPWSTR)
    ptr = fn(command.strip(), ctypes.byref(count))
    if not ptr:
        return []
    try:
        argv = [ptr[i] for i in range(count.value)]
    finally:
        ctypes.windll.kernel32.LocalFree(ctypes.cast(ptr, ctypes.c_void_p))
    if not argv:
        return []
    exe = os.path.expandvars(argv[0]).strip('"')
    if not os.path.isabs(exe) or not exe.lower().endswith('.exe') or not os.path.isfile(exe):
        return []
    if os.path.basename(exe).lower() in ('cmd.exe', 'powershell.exe', 'pwsh.exe', 'wscript.exe', 'cscript.exe', 'mshta.exe', 'rundll32.exe'):
        return []
    return [exe] + argv[1:]


def _icon_data(value):
    """Extrai o ícone local por Win32/GDI, sem expor o caminho do programa ao site."""
    if not value:
        return ''
    path = os.path.expandvars(str(value).strip())
    if path.startswith('"'):
        path = path.split('"', 2)[1]
    else:
        path = re.sub(r',-?\d+$', '', path)
    path = path.strip('" ')
    if not os.path.isfile(path) or not path.lower().endswith(('.exe', '.dll', '.ico')):
        return ''
    try:
        import io
        import base64
        from PIL import Image
        icon_index = 0
        match = re.search(r',(-?\d+)$', str(value))
        if match:
            icon_index = max(0, int(match.group(1)))
        shell = ctypes.windll.shell32
        user32 = ctypes.windll.user32
        gdi = ctypes.windll.gdi32
        shell.ExtractIconExW.argtypes = [wintypes.LPCWSTR, ctypes.c_int, ctypes.POINTER(wintypes.HICON), ctypes.POINTER(wintypes.HICON), wintypes.UINT]
        shell.ExtractIconExW.restype = wintypes.UINT
        gdi.CreateCompatibleDC.argtypes = [wintypes.HDC]
        gdi.CreateCompatibleDC.restype = wintypes.HDC
        gdi.CreateCompatibleBitmap.argtypes = [wintypes.HDC, ctypes.c_int, ctypes.c_int]
        gdi.CreateCompatibleBitmap.restype = wintypes.HBITMAP
        gdi.SelectObject.argtypes = [wintypes.HDC, wintypes.HGDIOBJ]
        gdi.SelectObject.restype = wintypes.HGDIOBJ
        gdi.GetDIBits.argtypes = [wintypes.HDC, wintypes.HBITMAP, wintypes.UINT, wintypes.UINT, ctypes.c_void_p, ctypes.c_void_p, wintypes.UINT]
        gdi.GetDIBits.restype = ctypes.c_int
        user32.DrawIconEx.argtypes = [wintypes.HDC, ctypes.c_int, ctypes.c_int, wintypes.HICON, ctypes.c_int, ctypes.c_int, wintypes.UINT, wintypes.HBRUSH, wintypes.UINT]
        user32.DrawIconEx.restype = wintypes.BOOL
        user32.DestroyIcon.argtypes = [wintypes.HICON]
        gdi.DeleteObject.argtypes = [wintypes.HGDIOBJ]
        gdi.DeleteDC.argtypes = [wintypes.HDC]
        large = wintypes.HICON()
        if shell.ExtractIconExW(ctypes.c_wchar_p(path), icon_index, ctypes.byref(large), None, 1) < 1 or not large.value:
            return ''
        class BITMAPINFOHEADER(ctypes.Structure):
            _fields_ = [('biSize', wintypes.DWORD), ('biWidth', wintypes.LONG), ('biHeight', wintypes.LONG), ('biPlanes', wintypes.WORD), ('biBitCount', wintypes.WORD), ('biCompression', wintypes.DWORD), ('biSizeImage', wintypes.DWORD), ('biXPelsPerMeter', wintypes.LONG), ('biYPelsPerMeter', wintypes.LONG), ('biClrUsed', wintypes.DWORD), ('biClrImportant', wintypes.DWORD)]
        class BITMAPINFO(ctypes.Structure):
            _fields_ = [('bmiHeader', BITMAPINFOHEADER), ('bmiColors', wintypes.DWORD * 3)]
        user32.GetDC.argtypes = [wintypes.HWND]
        user32.GetDC.restype = wintypes.HDC
        user32.ReleaseDC.argtypes = [wintypes.HWND, wintypes.HDC]
        screen = user32.GetDC(None)
        hdc = gdi.CreateCompatibleDC(screen)
        bmp = gdi.CreateCompatibleBitmap(screen, 32, 32)
        user32.ReleaseDC(None, screen)
        original = gdi.SelectObject(hdc, bmp)
        try:
            user32.DrawIconEx(hdc, 0, 0, large, 32, 32, 0, None, 3)
            bmi = BITMAPINFO()
            bmi.bmiHeader.biSize = ctypes.sizeof(BITMAPINFOHEADER)
            bmi.bmiHeader.biWidth = 32
            bmi.bmiHeader.biHeight = -32
            bmi.bmiHeader.biPlanes = 1
            bmi.bmiHeader.biBitCount = 32
            buffer = (ctypes.c_ubyte * 4096)()
            if not gdi.GetDIBits(hdc, bmp, 0, 32, ctypes.byref(buffer), ctypes.byref(bmi), 0):
                return ''
            image = Image.frombytes('RGBA', (32, 32), bytes(buffer), 'raw', 'BGRA')
            # Alguns ícones Win32 antigos não usam canal alpha.
            if image.getextrema()[3][1] == 0:
                image.putalpha(255)
            out = io.BytesIO()
            image.save(out, format='PNG')
            data = out.getvalue()
            return 'data:image/png;base64,' + base64.b64encode(data).decode('ascii') if len(data) < 24000 else ''
        finally:
            gdi.SelectObject(hdc, original)
            gdi.DeleteObject(bmp)
            gdi.DeleteDC(hdc)
            user32.DestroyIcon(large)
    except (ImportError, OSError, ValueError, OverflowError, AttributeError):
        return ''


def _install_date(text):
    """Date reported by installer, never a guessed usage date."""
    try:
        return datetime.datetime.strptime(str(text), '%Y%m%d').strftime('%Y-%m-%d')
    except ValueError:
        return ''


def _is_nsis(exe):
    """Reconhece instaladores NSIS pela assinatura; só eles aceitam /S com segurança."""
    try:
        with open(exe, 'rb') as stream:
            return b'Nullsoft' in stream.read(1048576)
    except OSError:
        return False


def _classify(key_name, windows_installer, quiet, normal):
    """Escolhe o comando de desinstalação. Retorna (argv, método). Nunca usa shell nem texto livre do servidor."""
    guid = key_name if _GUID.fullmatch(key_name) else ''
    if not guid and normal:
        found = _GUID_IN_COMMAND.search(normal) if 'msiexec' in normal.lower() else None
        guid = found.group(1) if found else ''
    if guid:
        return ['msiexec.exe', '/x', guid, '/qn', '/norestart'], 'silent'
    if windows_installer:
        return [], ''
    argv = _command_args(quiet)
    if argv and os.path.basename(argv[0]).lower() not in ('msiexec.exe', 'msiexec'):
        return argv, 'silent'
    argv = _command_args(normal)
    if not argv or os.path.basename(argv[0]).lower() in ('msiexec.exe', 'msiexec'):
        return [], ''
    exe_name = os.path.basename(argv[0]).lower()
    flags = {item.lower() for item in argv[1:]}
    if flags & _SILENT_FLAGS:
        return argv, 'silent'
    if re.fullmatch(r'unins\d{3}\.exe', exe_name):
        return argv + ['/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART'], 'silent'
    if '--uninstall' in flags:
        return argv + ['--force-uninstall'], 'silent'
    if exe_name in _NSIS_NAMES and _is_nsis(argv[0]):
        return argv + ['/S'], 'silent'
    return argv, 'interactive'


def _exists(root, view, key_name):
    try:
        winreg.CloseKey(winreg.OpenKey(root, _UNINSTALL + '\\' + key_name, 0, winreg.KEY_READ | view))
        return True
    except OSError:
        return False


def _records():
    records = {}
    for root, label in _ROOTS:
        for view in _VIEWS:
            try:
                parent = winreg.OpenKey(root, _UNINSTALL, 0, winreg.KEY_READ | view)
            except OSError:
                continue
            with parent:
                index = 0
                while True:
                    try:
                        key_name = winreg.EnumKey(parent, index)
                        index += 1
                    except OSError:
                        break
                    try:
                        with winreg.OpenKey(parent, key_name) as key:
                            name = str(_read_value(key, 'DisplayName')).strip()
                            if not name or _read_value(key, 'SystemComponent', 0) == 1 or _read_value(key, 'ParentKeyName', ''):
                                continue
                            # Registry path is a machine-local immutable selection key (not an executable).
                            seed = label + '/' + str(view) + '/' + key_name
                            ident = hashlib.sha256(seed.encode('utf-8')).hexdigest()
                            if _read_value(key, 'NoRemove', 0) == 1:
                                argv, method = [], ''
                            else:
                                argv, method = _classify(key_name, _read_value(key, 'WindowsInstaller', 0) == 1,
                                                         str(_read_value(key, 'QuietUninstallString')), str(_read_value(key, 'UninstallString')))
                            size = _read_value(key, 'EstimatedSize', 0)
                            size = int(size) if isinstance(size, (int, float)) else 0
                            icon_path = str(_read_value(key, 'DisplayIcon'))[:700]
                            entry = {'icon': _icon_data(icon_path), 'id': ident, 'name': name[:160], 'version': str(_read_value(key, 'DisplayVersion'))[:80], 'publisher': str(_read_value(key, 'Publisher'))[:130], 'date': _install_date(_read_value(key, 'InstallDate')), 'size': max(0, min(size, 10000000)), 'uninstallable': bool(argv), 'method': method}
                            records[ident] = (entry, argv, str(_read_value(key, 'InstallLocation')), (root, view, key_name))
                    except (OSError, ValueError, TypeError):
                        continue
    return records


def _dedupe_records(records):
    """Consolida registros equivalentes das visões do Registro sem unir instalações distintas."""
    unique = {}
    for record in records.values():
        entry, command, install_location, location = record
        command_key = tuple(str(part).strip().casefold() for part in command)
        location_key = os.path.normcase(os.path.normpath(os.path.expandvars(str(install_location or ''))))
        key = (
            str(entry['name']).strip().casefold(), str(entry['version']).strip().casefold(),
            str(entry['publisher']).strip().casefold(), str(entry['date']).strip(),
            int(entry['size']), command_key, location_key,
        )
        current = unique.get(key)
        if current is None:
            unique[key] = record
            continue
        current_entry, _current_command, _current_location, current_registry = current
        current_priority = (
            bool(current_entry['uninstallable']), current_entry['method'] == 'silent',
            current_registry[0] == 'HKLM', current_registry[1] == winreg.KEY_WOW64_64KEY,
            bool(current_entry.get('icon')),
        )
        candidate_priority = (
            bool(entry['uninstallable']), entry['method'] == 'silent',
            location[0] == 'HKLM', location[1] == winreg.KEY_WOW64_64KEY,
            bool(entry.get('icon')),
        )
        if candidate_priority > current_priority:
            unique[key] = record
    return unique


def installed_programs():
    return sorted((v[0] for v in _dedupe_records(_records()).values()), key=lambda x: x['name'].casefold())[:1500]


def _restore_point(mode):
    """Cria o ponto de restauração. Retorna aviso (texto) quando não foi possível e o modo é 'try'."""
    ps = '[Console]::OutputEncoding=[Text.Encoding]::UTF8; Checkpoint-Computer -Description "Arcoll: antes de desinstalar programas" -RestorePointType MODIFY_SETTINGS -ErrorAction Stop'
    try:
        result = subprocess.run(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', ps], capture_output=True, text=True, timeout=120, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        failure = (result.stderr or '').strip()[:150] if result.returncode else ''
    except (OSError, subprocess.TimeoutExpired) as exc:
        failure = str(exc)[:150] or 'tempo limite'
    if not failure:
        return ''
    if mode == 'require':
        raise RuntimeError('Não foi possível criar ponto de restauração. Operação cancelada: ' + failure)
    return 'sem ponto de restauração'


def _wait_removed(location, seconds=25):
    """Alguns desinstaladores retornam antes de terminar: confirma no registro que a entrada sumiu."""
    deadline = time.monotonic() + seconds
    while True:
        if not _exists(*location):
            return True
        if time.monotonic() >= deadline:
            return False
        time.sleep(2)


def uninstall_programs(ids, restore='try', residual=False, interactive=False, progress=None):
    """Desinstala em lote. `progress(texto)` recebe o andamento. Falha somente se nenhum programa for removido."""
    # Do not accept paths, command lines, executable names or unknown IDs from the server.
    if not isinstance(ids, list) or not 1 <= len(ids) <= BATCH_MAX or len(set(ids)) != len(ids):
        raise ValueError('Seleção inválida de programas.')
    mode = 'require' if restore is True else 'off' if restore is False else restore
    if mode not in RESTORE_MODES:
        mode = 'try'
    programs = _records()
    chosen = []
    for item in ids:
        if not isinstance(item, str) or item not in programs or not programs[item][1]:
            raise ValueError('Programa não encontrado ou sem desinstalador autorizado. Atualize a lista.')
        chosen.append(programs[item])
    notes = []
    if mode != 'off' and any(entry['method'] == 'silent' or interactive for entry, _c, _l, _r in chosen):
        if progress:
            progress('Criando ponto de restauração...')
        warning = _restore_point(mode)
        if warning:
            notes.append(warning)
    outcomes = []
    done = failed = skipped = 0
    flags = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
    for number, (entry, command, install_location, location) in enumerate(chosen, 1):
        name = entry['name']
        if progress:
            progress('Desinstalando %d/%d: %s' % (number, len(chosen), name))
        if entry['method'] == 'interactive' and not interactive:
            skipped += 1
            outcomes.append(name + ': ignorado (exige janela no computador)')
            continue
        try:
            proc = subprocess.run(command, capture_output=True, timeout=1800 if entry['method'] == 'interactive' else 900,
                                  creationflags=0 if entry['method'] == 'interactive' else flags)
            # 0 success; 3010 requires reboot; 1641 reboot started; 1605 means already removed (MSI).
            if proc.returncode not in (0, 3010, 1641, 1605):
                failed += 1
                outcomes.append(name + (': outra instalação em andamento' if proc.returncode == 1618 else ': falha (' + str(proc.returncode) + ')'))
                continue
            if not _wait_removed(location):
                failed += 1
                outcomes.append(name + ': executado, mas ainda aparece instalado')
                continue
            cleanup = ''
            if residual and install_location:
                # Only remove an *empty* installation folder, never recursively remove user files.
                folder = os.path.normpath(os.path.expandvars(install_location.strip('" ')))
                system = os.path.normcase(os.environ.get('SystemRoot', r'C:\Windows'))
                if os.path.isabs(folder) and len(folder) > 8 and not os.path.normcase(folder).startswith(system):
                    try:
                        os.rmdir(folder)
                        cleanup = ', pasta vazia removida'
                    except OSError:
                        pass
            done += 1
            outcomes.append(name + (': concluído' if proc.returncode not in (3010, 1641) else ': requer reinicialização') + cleanup)
        except subprocess.TimeoutExpired:
            failed += 1
            outcomes.append(name + ': tempo limite excedido')
        except Exception as exc:
            failed += 1
            outcomes.append(name + ': ' + str(exc)[:80])
    head = '%d desinstalado(s)' % done + (', %d falha(s)' % failed if failed else '') + (', %d ignorado(s)' % skipped if skipped else '') + (' · ' + '; '.join(notes) if notes else '')
    text = (head + ' — ' + '; '.join(o for o in outcomes if not o.endswith(': concluído')))[:480] if (failed or skipped) else head
    if not done:
        raise RuntimeError(text)
    return text
