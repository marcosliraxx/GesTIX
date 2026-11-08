# Caminho: gestores/windows/arcoll_programs.py
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
import sys
import datetime
import json
import subprocess
import time
import winreg
from ctypes import wintypes
import threading
import urllib.parse
import urllib.request

# --- Cancelamento cooperativo e forçado ---------------------------------------------------------------------------
# Fonte única: toda chamada externa de Programas/Clean passa por run_tracked(); cancel_all() derruba os processos
# filhos (taskkill /T), o auxiliar elevado de instalação e impede novas etapas até reset_cancel().
_CANCEL = threading.Event()
_ACTIVE = set()
_ACTIVE_LOCK = threading.Lock()


class OperationCancelled(RuntimeError):
    """Operação interrompida pelo operador (plataforma ou tela do gestor)."""


def reset_cancel():
    _CANCEL.clear()


def is_cancelled():
    return _CANCEL.is_set()


def check_cancel():
    if _CANCEL.is_set():
        raise OperationCancelled('Operação cancelada pelo operador.')


def _kill_tree(proc):
    try:
        subprocess.run(['taskkill', '/PID', str(proc.pid), '/T', '/F'], capture_output=True, timeout=15,
                       creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    except Exception:
        pass
    try:
        proc.kill()
    except Exception:
        pass


def run_tracked(args, timeout=120, **kwargs):
    """subprocess.run com cancelamento: verifica o sinal a cada 0,5 s e encerra a árvore de processos."""
    check_cancel()
    kwargs.pop('capture_output', None)
    if kwargs.get('stdout') is None:
        kwargs['stdout'] = subprocess.PIPE
    if 'stderr' not in kwargs:
        kwargs['stderr'] = subprocess.PIPE
    proc = subprocess.Popen(args, **kwargs)
    with _ACTIVE_LOCK:
        _ACTIVE.add(proc)
    deadline = time.monotonic() + timeout
    try:
        while True:
            try:
                out, err = proc.communicate(timeout=0.5)
                break
            except subprocess.TimeoutExpired:
                if _CANCEL.is_set():
                    _kill_tree(proc)
                    proc.communicate()
                    raise OperationCancelled('Operação cancelada pelo operador.')
                if time.monotonic() > deadline:
                    _kill_tree(proc)
                    proc.communicate()
                    raise subprocess.TimeoutExpired(args, timeout)
    finally:
        with _ACTIVE_LOCK:
            _ACTIVE.discard(proc)
    check_cancel()
    return subprocess.CompletedProcess(args, proc.returncode, out, err)


def cancel_all():
    """Derruba tudo que Programas/Clean iniciou neste computador, inclusive o auxiliar elevado."""
    _CANCEL.set()
    with _ACTIVE_LOCK:
        running = list(_ACTIVE)
    for proc in running:
        _kill_tree(proc)
    try:
        with open(os.path.join(_job_dir(), 'install-cancel'), 'w', encoding='utf-8') as handle:
            handle.write(str(time.time()))
    except OSError:
        pass
    try:
        subprocess.run(['schtasks', '/End', '/TN', _HELPER_TASK], capture_output=True, timeout=20,
                       creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    except Exception:
        pass


# --- Logos dos programas (favicon oficial do site de cada fabricante; fallback: monograma colorido) -------------
LOGO_DOMAINS = {"AnyDesk.AnyDesk":"anydesk.com","RustDesk.RustDesk":"rustdesk.com","TeamViewer.TeamViewer":"teamviewer.com","GlavSoft.TightVNC":"tightvnc.com","uvncbvba.UltraVNC":"uvnc.com","Dropbox.Dropbox":"dropbox.com","Google.GoogleDrive":"drive.google.com","Microsoft.OneDrive":"onedrive.live.com","RARLab.WinRAR":"win-rar.com","qBittorrent.qBittorrent":"qbittorrent.org","Microsoft.DotNet.DesktopRuntime.10":"dotnet.microsoft.com","Microsoft.DotNet.DesktopRuntime.8":"dotnet.microsoft.com","Microsoft.DotNet.DesktopRuntime.9":"dotnet.microsoft.com","Microsoft.DotNet.AspNetCore.10":"dotnet.microsoft.com","Microsoft.DotNet.AspNetCore.8":"dotnet.microsoft.com","Microsoft.DotNet.AspNetCore.9":"dotnet.microsoft.com","Microsoft.VCRedist.2015+.x64":"visualstudio.microsoft.com","Microsoft.VCRedist.2015+.x86":"visualstudio.microsoft.com","Anysphere.Cursor":"cursor.com","DBeaver.DBeaver.Community":"dbeaver.io","Docker.DockerDesktop":"docker.com","EclipseFoundation.EclipseIDEforJavaDevelopers":"eclipse.org","TimKosse.FileZilla.Client":"filezilla-project.org","Git.Git":"git-scm.com","GitHub.GitHubDesktop":"desktop.github.com","JetBrains.Toolbox":"jetbrains.com","OpenJS.NodeJS.22":"nodejs.org","OpenJS.NodeJS.24":"nodejs.org","OpenJS.NodeJS.LTS":"nodejs.org","Postman.Postman":"postman.com","PuTTY.PuTTY":"putty.org","Python.Python.3.12":"python.org","Python.Python.3.13":"python.org","Python.Python.3.14":"python.org","SublimeHQ.SublimeText.4":"sublimetext.com","Microsoft.VisualStudioCode":"code.visualstudio.com","VSCodium.VSCodium":"vscodium.com","WinMerge.WinMerge":"winmerge.org","WinSCP.WinSCP":"winscp.net","Adobe.Acrobat.Reader.64-bit":"acrobat.adobe.com","AcroSoftware.CutePDFWriter":"cutepdf.com","Foxit.FoxitReader":"foxit.com","TheDocumentFoundation.LibreOffice":"libreoffice.org","Notepad++.Notepad++":"notepad-plus-plus.org","Apache.OpenOffice":"openoffice.org","geeksoftwareGmbH.PDF24Creator":"pdf24.org","SumatraPDF.SumatraPDF":"sumatrapdfreader.org","BlenderFoundation.Blender":"blender.org","FastStone.Viewer":"faststone.org","GIMP.GIMP":"gimp.org","Inkscape.Inkscape":"inkscape.org","IrfanSkiljan.IrfanView":"irfanview.com","KDE.Krita":"krita.org","dotPDN.PaintDotNet":"getpaint.net","XnSoft.XnViewMP":"xnview.com","BambuLab.BambuStudio":"bambulab.com","Ultimaker.Cura":"ultimaker.com","Amazon.Corretto.11.JDK":"aws.amazon.com","Amazon.Corretto.17.JDK":"aws.amazon.com","Amazon.Corretto.21.JDK":"aws.amazon.com","Amazon.Corretto.25.JDK":"aws.amazon.com","Amazon.Corretto.8.JDK":"aws.amazon.com","EclipseAdoptium.Temurin.11.JDK":"adoptium.net","EclipseAdoptium.Temurin.17.JDK":"adoptium.net","EclipseAdoptium.Temurin.21.JDK":"adoptium.net","EclipseAdoptium.Temurin.25.JDK":"adoptium.net","EclipseAdoptium.Temurin.8.JDK":"adoptium.net","EclipseAdoptium.Temurin.8.JRE":"adoptium.net","EpicGames.EpicGamesLauncher":"epicgames.com","Valve.Steam":"steampowered.com","Discord.Discord":"discord.com","Microsoft.Teams":"teams.microsoft.com","Pidgin.Pidgin":"pidgin.im","OpenWhisperSystems.Signal":"signal.org","SlackTechnologies.Slack":"slack.com","Telegram.TelegramDesktop":"telegram.org","Mozilla.Thunderbird":"thunderbird.net","CeruleanStudios.Trillian":"trillian.im","WhatsApp.WhatsApp":"whatsapp.com","Zoom.Zoom":"zoom.us","AIMP.AIMP":"aimp.ru","Audacity.Audacity":"audacityteam.org","PeterPawlowski.foobar2000":"foobar2000.org","HandBrake.HandBrake":"handbrake.fr","Apple.iTunes":"apple.com","CodecGuide.K-LiteCodecPack.Full":"codecguide.com","VentisMedia.MediaMonkey":"mediamonkey.com","MusicBee.MusicBee":"getmusicbee.com","OBSProject.OBSStudio":"obsproject.com","Spotify.Spotify":"spotify.com","VideoLAN.VLC":"videolan.org","Winamp.Winamp":"winamp.com","Brave.Brave":"brave.com","Google.Chrome":"chrome.google.com","Microsoft.Edge":"microsoftedge.microsoft.com","Mozilla.Firefox":"mozilla.org","Opera.Opera":"opera.com","Vivaldi.Vivaldi":"vivaldi.com","Evernote.Evernote":"evernote.com","Google.EarthPro":"earth.google.com","Famatech.AdvancedIPScanner":"advanced-ip-scanner.com","Malwarebytes.Malwarebytes":"malwarebytes.com","OpenVPNTechnologies.OpenVPNConnect":"openvpn.net","Tailscale.Tailscale":"tailscale.com","WireGuard.WireGuard":"wireguard.com","WiresharkFoundation.Wireshark":"wireshark.org","AgileBits.1Password":"1password.com","Bitwarden.Bitwarden":"bitwarden.com","KeePassXCTeam.KeePassXC":"keepassxc.org","7zip.7zip":"7-zip.org","Piriform.CCleaner":"ccleaner.com","voidtools.Everything":"voidtools.com","Glarysoft.GlaryUtilities":"glarysoft.com","Greenshot.Greenshot":"getgreenshot.org","DominikReichl.KeePass":"keepass.info","Launchy.Launchy":"launchy.net","Open-Shell.Open-Shell-Menu":"open-shell.github.io","Giorgiotani.Peazip":"peazip.github.io","Microsoft.PowerToys":"microsoft.com","RevoUninstaller.RevoUninstaller":"revouninstaller.com","Rufus.Rufus":"rufus.ie","ShareX.ShareX":"getsharex.com","CodeSector.TeraCopy":"codesector.com","WinDirStat.WinDirStat":"windirstat.net","AntibodySoftware.WizTree":"diskanalyzer.com"}
_LOGO_CACHE = {}
_LOGO_PENDING = set()


def _logo_dir():
    base = os.environ.get('LOCALAPPDATA') or os.environ.get('TEMP') or '.'
    path = os.path.join(base, 'Arcoll', 'logos')
    os.makedirs(path, exist_ok=True)
    return path


def _logo_download(domain):
    """Baixa o favicon (PNG 64 px) uma única vez; mantém em disco. Retorna o caminho ou ''."""
    target = os.path.join(_logo_dir(), re.sub(r'[^a-z0-9.-]', '_', domain.lower()) + '.png')
    if os.path.isfile(target) and os.path.getsize(target) > 100:
        return target
    try:
        request = urllib.request.Request('https://www.google.com/s2/favicons?sz=64&domain=' + domain, headers={'User-Agent': 'ArcollGestor'})
        with urllib.request.urlopen(request, timeout=8) as reply:
            data = reply.read(200000)
        if data[:8] == b'\x89PNG\r\n\x1a\n' and len(data) > 100:
            with open(target, 'wb') as handle:
                handle.write(data)
            return target
    except Exception:
        pass
    return ''


def _logo_safe_name(package_id):
    """Mesmo nome de arquivo da plataforma (assets/program-logos): tudo fora de [A-Za-z0-9.-] vira "_"."""
    return re.sub(r'[^A-Za-z0-9.-]', '_', str(package_id)) + '.png'


def _logo_origin():
    """Endereço da plataforma (o mesmo ORIGIN do gestor), usado só para baixar logos que não vieram no pacote."""
    for module_name in ('arcoll_app', '__main__'):
        value = getattr(sys.modules.get(module_name), 'ORIGIN', '')
        if value:
            return str(value).rstrip('/')
    return str(os.environ.get('ARCOLL_ORIGIN', '')).rstrip('/')


def _logo_local_path(package_id):
    """Logo já disponível: pasta program-logos ao lado do gestor ou cópia guardada em %LOCALAPPDATA%\\Arcoll\\logos."""
    name = _logo_safe_name(package_id)
    here = os.path.dirname(os.path.abspath(__file__))
    for folder in (os.path.join(here, 'program-logos'), os.path.join(here, 'Assets', 'program-logos'), os.path.join(_logo_dir(), 'programs')):
        path = os.path.join(folder, name)
        if os.path.isfile(path) and os.path.getsize(path) > 100:
            return path
    return ''


def _logo_fetch_platform(package_id):
    """Baixa o PNG do logo na própria plataforma (arquivo estático público) e guarda em disco. Retorna o caminho ou ''."""
    origin = _logo_origin()
    if not origin.startswith('https://'):
        return ''
    folder = os.path.join(_logo_dir(), 'programs')
    target = os.path.join(folder, _logo_safe_name(package_id))
    try:
        os.makedirs(folder, exist_ok=True)
        request = urllib.request.Request(origin + '/assets/program-logos/' + urllib.parse.quote(_logo_safe_name(package_id)), headers={'User-Agent': 'ArcollGestor'})
        with urllib.request.urlopen(request, timeout=8) as reply:
            data = reply.read(300000)
        if data[:8] == b'\x89PNG\r\n\x1a\n' and len(data) > 100:
            with open(target, 'wb') as handle:
                handle.write(data)
            return target
    except Exception:
        pass
    return ''


def logo_photo(tk, package_id, name, size=26, on_ready=None):
    """PhotoImage do logo do programa.
    Ordem: 1) PNG embutido/baixado da plataforma; 2) favicon do site oficial em cache; 3) monograma colorido.
    Quando só há o monograma, ele aparece na hora e é trocado pelo logo real assim que o download termina."""
    key = (package_id, size)
    cached = _LOGO_CACHE.get(key)
    if cached is not None:
        return cached
    domain = LOGO_DOMAINS.get(package_id, '')
    photo = None
    path = _logo_local_path(package_id)
    if path:
        photo = _photo_from_file(tk, path, size)
    if photo is None and domain:
        favicon = os.path.join(_logo_dir(), re.sub(r'[^a-z0-9.-]', '_', domain.lower()) + '.png')
        if os.path.isfile(favicon):
            photo = _photo_from_file(tk, favicon, size)
    if photo is None:
        fallback = _fallback_icon_png(name, package_id)
        if fallback:
            try:
                photo = tk.PhotoImage(data=fallback.partition(',')[2])
            except Exception:
                photo = None
        if callable(on_ready) and key not in _LOGO_PENDING:
            _LOGO_PENDING.add(key)
            def fetch():
                try:
                    found = _logo_fetch_platform(package_id) or (_logo_download(domain) if domain else '')
                finally:
                    _LOGO_PENDING.discard(key)
                if found:
                    try:
                        on_ready()
                    except Exception:
                        pass
            threading.Thread(target=fetch, daemon=True).start()
        return photo
    _LOGO_CACHE[key] = photo
    return photo


def _photo_from_file(tk, path, size):
    try:
        from PIL import Image, ImageTk
        image = Image.open(path).convert('RGBA').resize((size, size), Image.LANCZOS)
        return ImageTk.PhotoImage(image)
    except Exception:
        pass
    try:
        photo = tk.PhotoImage(file=path)
        factor = max(1, round(photo.width() / float(size)))
        return photo.subsample(factor) if factor > 1 else photo
    except Exception:
        return None


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
        result = run_tracked(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', ps], text=True, timeout=120, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
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
        check_cancel()
        if entry['method'] == 'interactive' and not interactive:
            skipped += 1
            outcomes.append(name + ': ignorado (exige janela no computador)')
            continue
        try:
            proc = run_tracked(command, timeout=1800 if entry['method'] == 'interactive' else 900,
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


# Catálogo local independente: a plataforma nunca pode fornecer uma linha de comando.
# Mesmo catálogo permitido no servidor; a autorização final também é verificada no backend.
INSTALL_CATALOG = [{'name': 'AnyDesk', 'id': 'AnyDesk.AnyDesk', 'category': 'Acesso remoto'}, {'name': 'RustDesk', 'id': 'RustDesk.RustDesk', 'category': 'Acesso remoto'}, {'name': 'TeamViewer', 'id': 'TeamViewer.TeamViewer', 'category': 'Acesso remoto'}, {'name': 'TightVNC', 'id': 'GlavSoft.TightVNC', 'category': 'Acesso remoto'}, {'name': 'UltraVNC', 'id': 'uvncbvba.UltraVNC', 'category': 'Acesso remoto'}, {'name': 'Dropbox', 'id': 'Dropbox.Dropbox', 'category': 'Armazenamento'}, {'name': 'Google Drive para computador', 'id': 'Google.GoogleDrive', 'category': 'Armazenamento'}, {'name': 'OneDrive', 'id': 'Microsoft.OneDrive', 'category': 'Armazenamento'}, {'name': 'WinRAR', 'id': 'RARLab.WinRAR', 'category': 'Compactação'}, {'name': 'qBittorrent', 'id': 'qBittorrent.qBittorrent', 'category': 'Compartilhamento'}, {'name': '.NET Desktop Runtime 10', 'id': 'Microsoft.DotNet.DesktopRuntime.10', 'category': 'Componentes Windows'}, {'name': '.NET Desktop Runtime 8', 'id': 'Microsoft.DotNet.DesktopRuntime.8', 'category': 'Componentes Windows'}, {'name': '.NET Desktop Runtime 9', 'id': 'Microsoft.DotNet.DesktopRuntime.9', 'category': 'Componentes Windows'}, {'name': 'ASP.NET Core Runtime 10', 'id': 'Microsoft.DotNet.AspNetCore.10', 'category': 'Componentes Windows'}, {'name': 'ASP.NET Core Runtime 8', 'id': 'Microsoft.DotNet.AspNetCore.8', 'category': 'Componentes Windows'}, {'name': 'ASP.NET Core Runtime 9', 'id': 'Microsoft.DotNet.AspNetCore.9', 'category': 'Componentes Windows'}, {'name': 'Visual C++ Redistributable 2015+ x64', 'id': 'Microsoft.VCRedist.2015+.x64', 'category': 'Componentes Windows'}, {'name': 'Visual C++ Redistributable 2015+ x86', 'id': 'Microsoft.VCRedist.2015+.x86', 'category': 'Componentes Windows'}, {'name': 'Cursor', 'id': 'Anysphere.Cursor', 'category': 'Desenvolvimento'}, {'name': 'DBeaver', 'id': 'DBeaver.DBeaver.Community', 'category': 'Desenvolvimento'}, {'name': 'Docker Desktop', 'id': 'Docker.DockerDesktop', 'category': 'Desenvolvimento'}, {'name': 'Eclipse IDE', 'id': 'EclipseFoundation.EclipseIDEforJavaDevelopers', 'category': 'Desenvolvimento'}, {'name': 'FileZilla', 'id': 'TimKosse.FileZilla.Client', 'category': 'Desenvolvimento'}, {'name': 'Git', 'id': 'Git.Git', 'category': 'Desenvolvimento'}, {'name': 'GitHub Desktop', 'id': 'GitHub.GitHubDesktop', 'category': 'Desenvolvimento'}, {'name': 'JetBrains Toolbox', 'id': 'JetBrains.Toolbox', 'category': 'Desenvolvimento'}, {'name': 'Node.js 22', 'id': 'OpenJS.NodeJS.22', 'category': 'Desenvolvimento'}, {'name': 'Node.js 24', 'id': 'OpenJS.NodeJS.24', 'category': 'Desenvolvimento'}, {'name': 'Node.js LTS', 'id': 'OpenJS.NodeJS.LTS', 'category': 'Desenvolvimento'}, {'name': 'Postman', 'id': 'Postman.Postman', 'category': 'Desenvolvimento'}, {'name': 'PuTTY', 'id': 'PuTTY.PuTTY', 'category': 'Desenvolvimento'}, {'name': 'Python 3.12', 'id': 'Python.Python.3.12', 'category': 'Desenvolvimento'}, {'name': 'Python 3.13', 'id': 'Python.Python.3.13', 'category': 'Desenvolvimento'}, {'name': 'Python 3.14', 'id': 'Python.Python.3.14', 'category': 'Desenvolvimento'}, {'name': 'Sublime Text', 'id': 'SublimeHQ.SublimeText.4', 'category': 'Desenvolvimento'}, {'name': 'Visual Studio Code', 'id': 'Microsoft.VisualStudioCode', 'category': 'Desenvolvimento'}, {'name': 'VSCodium', 'id': 'VSCodium.VSCodium', 'category': 'Desenvolvimento'}, {'name': 'WinMerge', 'id': 'WinMerge.WinMerge', 'category': 'Desenvolvimento'}, {'name': 'WinSCP', 'id': 'WinSCP.WinSCP', 'category': 'Desenvolvimento'}, {'name': 'Adobe Acrobat Reader', 'id': 'Adobe.Acrobat.Reader.64-bit', 'category': 'Documentos'}, {'name': 'CutePDF Writer', 'id': 'AcroSoftware.CutePDFWriter', 'category': 'Documentos'}, {'name': 'Foxit PDF Reader', 'id': 'Foxit.FoxitReader', 'category': 'Documentos'}, {'name': 'LibreOffice', 'id': 'TheDocumentFoundation.LibreOffice', 'category': 'Documentos'}, {'name': 'Notepad++', 'id': 'Notepad++.Notepad++', 'category': 'Documentos'}, {'name': 'OpenOffice', 'id': 'Apache.OpenOffice', 'category': 'Documentos'}, {'name': 'PDF24 Creator', 'id': 'geeksoftwareGmbH.PDF24Creator', 'category': 'Documentos'}, {'name': 'SumatraPDF', 'id': 'SumatraPDF.SumatraPDF', 'category': 'Documentos'}, {'name': 'Blender', 'id': 'BlenderFoundation.Blender', 'category': 'Imagens e design'}, {'name': 'FastStone Image Viewer', 'id': 'FastStone.Viewer', 'category': 'Imagens e design'}, {'name': 'GIMP', 'id': 'GIMP.GIMP', 'category': 'Imagens e design'}, {'name': 'Inkscape', 'id': 'Inkscape.Inkscape', 'category': 'Imagens e design'}, {'name': 'IrfanView', 'id': 'IrfanSkiljan.IrfanView', 'category': 'Imagens e design'}, {'name': 'Krita', 'id': 'KDE.Krita', 'category': 'Imagens e design'}, {'name': 'Paint.NET', 'id': 'dotPDN.PaintDotNet', 'category': 'Imagens e design'}, {'name': 'XnView MP', 'id': 'XnSoft.XnViewMP', 'category': 'Imagens e design'}, {'name': 'Bambu Studio', 'id': 'BambuLab.BambuStudio', 'category': 'Impressão 3D'}, {'name': 'Ultimaker Cura', 'id': 'Ultimaker.Cura', 'category': 'Impressão 3D'}, {'name': 'Amazon Corretto JDK 11', 'id': 'Amazon.Corretto.11.JDK', 'category': 'Java'}, {'name': 'Amazon Corretto JDK 17', 'id': 'Amazon.Corretto.17.JDK', 'category': 'Java'}, {'name': 'Amazon Corretto JDK 21', 'id': 'Amazon.Corretto.21.JDK', 'category': 'Java'}, {'name': 'Amazon Corretto JDK 25', 'id': 'Amazon.Corretto.25.JDK', 'category': 'Java'}, {'name': 'Amazon Corretto JDK 8', 'id': 'Amazon.Corretto.8.JDK', 'category': 'Java'}, {'name': 'Java Temurin JDK 11', 'id': 'EclipseAdoptium.Temurin.11.JDK', 'category': 'Java'}, {'name': 'Java Temurin JDK 17', 'id': 'EclipseAdoptium.Temurin.17.JDK', 'category': 'Java'}, {'name': 'Java Temurin JDK 21', 'id': 'EclipseAdoptium.Temurin.21.JDK', 'category': 'Java'}, {'name': 'Java Temurin JDK 25', 'id': 'EclipseAdoptium.Temurin.25.JDK', 'category': 'Java'}, {'name': 'Java Temurin JDK 8', 'id': 'EclipseAdoptium.Temurin.8.JDK', 'category': 'Java'}, {'name': 'Java Temurin JRE 8', 'id': 'EclipseAdoptium.Temurin.8.JRE', 'category': 'Java'}, {'name': 'Epic Games Launcher', 'id': 'EpicGames.EpicGamesLauncher', 'category': 'Jogos'}, {'name': 'Steam', 'id': 'Valve.Steam', 'category': 'Jogos'}, {'name': 'Discord', 'id': 'Discord.Discord', 'category': 'Mensagens'}, {'name': 'Microsoft Teams', 'id': 'Microsoft.Teams', 'category': 'Mensagens'}, {'name': 'Pidgin', 'id': 'Pidgin.Pidgin', 'category': 'Mensagens'}, {'name': 'Signal', 'id': 'OpenWhisperSystems.Signal', 'category': 'Mensagens'}, {'name': 'Slack', 'id': 'SlackTechnologies.Slack', 'category': 'Mensagens'}, {'name': 'Telegram Desktop', 'id': 'Telegram.TelegramDesktop', 'category': 'Mensagens'}, {'name': 'Thunderbird', 'id': 'Mozilla.Thunderbird', 'category': 'Mensagens'}, {'name': 'Trillian', 'id': 'CeruleanStudios.Trillian', 'category': 'Mensagens'}, {'name': 'WhatsApp', 'id': 'WhatsApp.WhatsApp', 'category': 'Mensagens'}, {'name': 'Zoom', 'id': 'Zoom.Zoom', 'category': 'Mensagens'}, {'name': 'AIMP', 'id': 'AIMP.AIMP', 'category': 'Multimídia'}, {'name': 'Audacity', 'id': 'Audacity.Audacity', 'category': 'Multimídia'}, {'name': 'foobar2000', 'id': 'PeterPawlowski.foobar2000', 'category': 'Multimídia'}, {'name': 'HandBrake', 'id': 'HandBrake.HandBrake', 'category': 'Multimídia'}, {'name': 'iTunes', 'id': 'Apple.iTunes', 'category': 'Multimídia'}, {'name': 'K-Lite Codec Pack', 'id': 'CodecGuide.K-LiteCodecPack.Full', 'category': 'Multimídia'}, {'name': 'MediaMonkey', 'id': 'VentisMedia.MediaMonkey', 'category': 'Multimídia'}, {'name': 'MusicBee', 'id': 'MusicBee.MusicBee', 'category': 'Multimídia'}, {'name': 'OBS Studio', 'id': 'OBSProject.OBSStudio', 'category': 'Multimídia'}, {'name': 'Spotify', 'id': 'Spotify.Spotify', 'category': 'Multimídia'}, {'name': 'VLC', 'id': 'VideoLAN.VLC', 'category': 'Multimídia'}, {'name': 'Winamp', 'id': 'Winamp.Winamp', 'category': 'Multimídia'}, {'name': 'Brave', 'id': 'Brave.Brave', 'category': 'Navegadores'}, {'name': 'Google Chrome', 'id': 'Google.Chrome', 'category': 'Navegadores'}, {'name': 'Microsoft Edge', 'id': 'Microsoft.Edge', 'category': 'Navegadores'}, {'name': 'Mozilla Firefox', 'id': 'Mozilla.Firefox', 'category': 'Navegadores'}, {'name': 'Opera', 'id': 'Opera.Opera', 'category': 'Navegadores'}, {'name': 'Vivaldi', 'id': 'Vivaldi.Vivaldi', 'category': 'Navegadores'}, {'name': 'Evernote', 'id': 'Evernote.Evernote', 'category': 'Produtividade'}, {'name': 'Google Earth Pro', 'id': 'Google.EarthPro', 'category': 'Produtividade'}, {'name': 'Advanced IP Scanner', 'id': 'Famatech.AdvancedIPScanner', 'category': 'Rede e segurança'}, {'name': 'Malwarebytes', 'id': 'Malwarebytes.Malwarebytes', 'category': 'Rede e segurança'}, {'name': 'OpenVPN Connect', 'id': 'OpenVPNTechnologies.OpenVPNConnect', 'category': 'Rede e segurança'}, {'name': 'Tailscale', 'id': 'Tailscale.Tailscale', 'category': 'Rede e segurança'}, {'name': 'WireGuard', 'id': 'WireGuard.WireGuard', 'category': 'Rede e segurança'}, {'name': 'Wireshark', 'id': 'WiresharkFoundation.Wireshark', 'category': 'Rede e segurança'}, {'name': '1Password', 'id': 'AgileBits.1Password', 'category': 'Senhas'}, {'name': 'Bitwarden', 'id': 'Bitwarden.Bitwarden', 'category': 'Senhas'}, {'name': 'KeePassXC', 'id': 'KeePassXCTeam.KeePassXC', 'category': 'Senhas'}, {'name': '7-Zip', 'id': '7zip.7zip', 'category': 'Utilitários'}, {'name': 'CCleaner', 'id': 'Piriform.CCleaner', 'category': 'Utilitários'}, {'name': 'Everything', 'id': 'voidtools.Everything', 'category': 'Utilitários'}, {'name': 'Glary Utilities', 'id': 'Glarysoft.GlaryUtilities', 'category': 'Utilitários'}, {'name': 'Greenshot', 'id': 'Greenshot.Greenshot', 'category': 'Utilitários'}, {'name': 'KeePass', 'id': 'DominikReichl.KeePass', 'category': 'Utilitários'}, {'name': 'Launchy', 'id': 'Launchy.Launchy', 'category': 'Utilitários'}, {'name': 'Open-Shell', 'id': 'Open-Shell.Open-Shell-Menu', 'category': 'Utilitários'}, {'name': 'PeaZip', 'id': 'Giorgiotani.Peazip', 'category': 'Utilitários'}, {'name': 'PowerToys', 'id': 'Microsoft.PowerToys', 'category': 'Utilitários'}, {'name': 'Revo Uninstaller', 'id': 'RevoUninstaller.RevoUninstaller', 'category': 'Utilitários'}, {'name': 'Rufus', 'id': 'Rufus.Rufus', 'category': 'Utilitários'}, {'name': 'ShareX', 'id': 'ShareX.ShareX', 'category': 'Utilitários'}, {'name': 'TeraCopy', 'id': 'CodeSector.TeraCopy', 'category': 'Utilitários'}, {'name': 'WinDirStat', 'id': 'WinDirStat.WinDirStat', 'category': 'Utilitários'}, {'name': 'WizTree', 'id': 'AntibodySoftware.WizTree', 'category': 'Utilitários'}]
INSTALL_ALLOWED = frozenset(row['id'] for row in INSTALL_CATALOG)

# Registros do Windows oferecem ícone e versão; WinGet complementa identidade e atualização.
def _canonical_name(name):
    return re.sub(r'[^a-z0-9]+', '', str(name).casefold())



_PROGRESS_PREFIX = '@@ARP@@'


def _catalog_label(package_id):
    app = next((row for row in INSTALL_CATALOG if row['id'] == package_id), None)
    return app['name'] if app else package_id


def _logo_badge(name):
    # Iniciais por palavra ou CamelCase: WinRAR => WR, SumatraPDF => SP, Paint.NET => PN (igual à plataforma).
    spaced = re.sub(r'([a-z0-9])([A-Z])', r'\1 \2', str(name or '').strip())
    parts = [p for p in re.split(r'[^A-Za-z0-9]+', spaced) if p]
    if not parts:
        return '?'
    if len(parts) == 1:
        token = parts[0]
        return (token[:2] if len(token) > 1 else token).upper()
    return (parts[0][0] + parts[1][0]).upper()


def _logo_color(key):
    palette = ['#1f6feb', '#2ea043', '#bf8700', '#8957e5', '#d1242f', '#0969da', '#8250df', '#1a7f37', '#0a7ea4', '#bc4c00']
    digest = hashlib.sha256(str(key).encode('utf-8')).hexdigest()
    return palette[int(digest[:2], 16) % len(palette)]


def _fallback_icon_png(name, key=''):
    try:
        import io, base64
        from PIL import Image, ImageDraw, ImageFont
        color = _logo_color(key or name)
        badge = _logo_badge(name)
        img = Image.new('RGBA', (32, 32), (0, 0, 0, 0))
        draw = ImageDraw.Draw(img)
        draw.rounded_rectangle((1, 1, 31, 31), radius=8, fill=color)
        try:
            font = ImageFont.truetype('arial.ttf', 14)
        except Exception:
            try:
                font = ImageFont.truetype('seguiemj.ttf', 14)
            except Exception:
                font = ImageFont.load_default()
        bbox = draw.textbbox((0, 0), badge, font=font)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]
        draw.text(((32 - tw) / 2, (32 - th) / 2 - 1), badge, fill='white', font=font)
        out = io.BytesIO()
        img.save(out, format='PNG')
        return 'data:image/png;base64,' + base64.b64encode(out.getvalue()).decode('ascii')
    except Exception:
        return ''


def _progress_payload(order, states, index, total, current_name='', current_state='', note=''):
    """Compact wire format: gestor-command-ack persists at most 480 characters."""
    # The UI already knows the chosen order and names, so send only row states.
    statuses = [str(states.get(package_id, {}).get('status', 'Aguardando'))[:18] for package_id in order[:20]]
    body = {
        'i': int(index), 't': int(total), 'c': str(current_name)[:35],
        's': str(current_state)[:18], 'm': str(note)[:80], 'r': statuses,
    }
    serialized = _PROGRESS_PREFIX + json.dumps(body, ensure_ascii=False, separators=(',', ':'))
    if len(serialized) > 460:
        # Preserve a valid packet when a translated status or name is unusually long.
        body['m'] = ''
        body['c'] = ''
        body['r'] = [row[:12] for row in statuses]
        serialized = _PROGRESS_PREFIX + json.dumps(body, ensure_ascii=False, separators=(',', ':'))
    return serialized


def _emit_progress(progress, order, states, index, total, current_name='', current_state='', note=''):
    if callable(progress):
        progress(_progress_payload(order, states, index, total, current_name, current_state, note))

def catalog_inventory(progress=None):
    """Estado conhecido dos pacotes, sem inventar atualização quando o WinGet falha."""
    installed = installed_programs()
    by_name = {_canonical_name(r['name']): r for r in installed}
    state = {a['id']: {'installed': False, 'version': '', 'available_version': '', 'icon': '', 'update_available': False} for a in INSTALL_CATALOG}
    aliases = {'Google.GoogleDrive': ['Google Drive', 'Google Drive para computador'],
               'Microsoft.VCRedist.2015+.x64':['Microsoft Visual C++ 2015-2022 Redistributable (x64)'],
               'Microsoft.VCRedist.2015+.x86':['Microsoft Visual C++ 2015-2022 Redistributable (x86)'],
               'Microsoft.VisualStudioCode':['Microsoft Visual Studio Code'],
               'Mozilla.Firefox':['Mozilla Firefox (x64 en-US)','Mozilla Firefox (x64 pt-BR)']}
    for app in INSTALL_CATALOG:
        choices = [app['name']] + aliases.get(app['id'], [])
        record = next((by_name[_canonical_name(n)] for n in choices if _canonical_name(n) in by_name), None)
        if record:
            state[app['id']].update(installed=True, version=record.get('version',''), icon=record.get('icon',''))
    import shutil
    winget=shutil.which('winget')
    if not winget:
        if progress:progress('WinGet indisponível: versões locais consultadas no Registro.')
        return state
    try:
        if progress:progress('Consultando versões e atualizações do WinGet...')
        flags=getattr(subprocess,'CREATE_NO_WINDOW',0)
        result=run_tracked([winget,'list','--accept-source-agreements','--disable-interactivity'],errors='replace',text=True,timeout=110,creationflags=flags)
        # Identificação exata: ID é um campo isolado e não um fragmento de outro pacote.
        lines=(result.stdout or '').splitlines()
        for line in lines:
            for app in INSTALL_CATALOG:
                ident=app['id']
                match=re.search(r'(?<!\S)'+re.escape(ident)+r'(?=\s)',line,re.I)
                if not match:continue
                tail=line[match.end():].strip().split()
                row=state[ident];row['installed']=True
                if tail:row['version']=tail[0]
                # WinGet pode ter coluna Available; conferência via --upgrade-available abaixo.
                if not row['icon']:
                    record=next((r for r in installed if _canonical_name(app['name']) in _canonical_name(r['name'])),None)
                    if record:row['icon']=record.get('icon','')
                break
        updates=run_tracked([winget,'list','--upgrade-available','--accept-source-agreements','--disable-interactivity'],text=True,errors='replace',timeout=110,creationflags=flags)
        if updates.returncode==0:
            for line in (updates.stdout or '').splitlines():
                for app in INSTALL_CATALOG:
                    ident=app['id']
                    match=re.search(r'(?<!\S)'+re.escape(ident)+r'(?=\s)',line,re.I)
                    if match and state[ident]['installed']:
                        values=line[match.end():].strip().split()
                        if len(values)>=2:
                            state[ident]['update_available']=True
                            state[ident]['available_version']=values[1]
                        break
    except (OSError,subprocess.TimeoutExpired) as exc:
        if progress:progress('Consulta WinGet incompleta: '+str(exc)[:100])
    return state




# ---------------------------------------------------------------------------------------------------------------
# Instalação / atualização via WinGet (somente IDs do catálogo aprovado)
# ---------------------------------------------------------------------------------------------------------------
# 0x8A15002B (nenhuma atualização aplicável) e 0x8A150061 (pacote já instalado) significam "nada a fazer".
_WINGET_OK_CODES = {0, -1978335189, -1978335135}
_HELPER_TASK = '\\Arcoll\\Install Helper'
_NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0)


def _is_admin():
    try:
        return bool(ctypes.windll.shell32.IsUserAnAdmin())
    except Exception:
        return False


def _winget_path():
    """Localiza o winget mesmo quando o PATH do processo não inclui o alias do App Installer."""
    import glob
    import shutil
    found = shutil.which('winget')
    if found:
        return found
    candidates = [os.path.join(os.environ.get('LOCALAPPDATA', ''), 'Microsoft', 'WindowsApps', 'winget.exe')]
    pattern = os.path.join(os.environ.get('ProgramFiles', r'C:\Program Files'), 'WindowsApps',
                           'Microsoft.DesktopAppInstaller_*_8wekyb3d8bbwe', 'winget.exe')
    candidates += sorted(glob.glob(pattern), reverse=True)
    return next((c for c in candidates if c and os.path.isfile(c)), '')


def _tail(text, limit=160):
    """Últimas linhas úteis da saída do winget (sem barras de progresso) para explicar uma falha."""
    lines = [l.strip() for l in str(text or '').replace('\r', '\n').split('\n')]
    lines = [l for l in lines if l and not set(l) <= set('-\\|/█▒░ ') and not re.fullmatch(r'[\d.]+\s*[KMG]?B\s*/\s*[\d.]+\s*[KMG]?B', l)]
    return ' | '.join(lines[-2:])[:limit]


def _run_winget(winget, args, timeout):
    return run_tracked([winget] + args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                       errors='replace', timeout=timeout, creationflags=_NO_WINDOW)


def _install_core(ids, progress=None):
    """Executa o winget neste processo. Precisa estar elevado para instalar em todo o computador sem janela de UAC."""
    winget = _winget_path()
    if not winget:
        raise RuntimeError('WinGet não está disponível neste Windows. Instale ou atualize o "Instalador de Aplicativo" da Microsoft Store.')
    existing = catalog_inventory(progress=progress)
    order = list(ids)
    states = {package: {'status': 'Aguardando'} for package in order}
    _emit_progress(progress, order, states, 0, len(order), '', 'Aguardando', 'Fila preparada')
    outcomes = []
    reasons = {}
    elevated = _is_admin()
    source_refreshed = False
    for index, package in enumerate(order, 1):
        check_cancel()
        label = _catalog_label(package)
        installed = existing.get(package, {}).get('installed')
        can_upgrade = existing.get(package, {}).get('update_available')
        command = 'upgrade' if installed and can_upgrade else 'install'
        if installed and command != 'upgrade':
            states[package]['status'] = 'OK'
            outcomes.append((package, True))
            _emit_progress(progress, order, states, index, len(order), label, 'OK', 'Já instalado e sem atualização identificada')
            continue
        states[package]['status'] = 'Baixando'
        _emit_progress(progress, order, states, index - 1, len(order), label, states[package]['status'], ('Atualizando ' if command == 'upgrade' else 'Instalando ') + label)
        args = [command, '--id', package, '--exact', '--source', 'winget', '--silent', '--disable-interactivity',
                '--accept-source-agreements', '--accept-package-agreements']
        success, detail = False, ''
        try:
            result = _run_winget(winget, args, 1800)
            success, detail = result.returncode in _WINGET_OK_CODES, _tail(result.stdout)
            if not success and not source_refreshed:
                # Catálogo do winget desatualizado/corrompido é a causa mais comum de falha na primeira tentativa.
                source_refreshed = True
                _emit_progress(progress, order, states, index - 1, len(order), label, 'Baixando', 'Atualizando a lista de fontes do WinGet...')
                try:
                    _run_winget(winget, ['source', 'update', '--disable-interactivity'], 180)
                except (OSError, subprocess.SubprocessError):
                    pass
                result = _run_winget(winget, args, 1800)
                success, detail = result.returncode in _WINGET_OK_CODES, _tail(result.stdout)
            if not success and not elevated:
                # Sem privilégio de administrador: tenta a instalação por usuário (nem todo pacote oferece).
                result = _run_winget(winget, args + ['--scope', 'user'], 1800)
                if result.returncode in _WINGET_OK_CODES:
                    success, detail = True, ''
                else:
                    detail = (detail or _tail(result.stdout)) + ' (sem privilégio de administrador)'
            if not success:
                reasons[package] = detail or ('código ' + str(result.returncode))
        except subprocess.TimeoutExpired:
            states[package]['status'] = 'Tempo esgotado'
            reasons[package] = 'tempo esgotado (provável janela de permissão aguardando resposta)'
            outcomes.append((package, False))
            _emit_progress(progress, order, states, index, len(order), label, 'Tempo esgotado', 'Tempo limite excedido')
            continue
        except OSError as exc:
            reasons[package] = str(exc)[:120]
        states[package]['status'] = 'Concluído' if success else 'Falhou'
        outcomes.append((package, success))
        _emit_progress(progress, order, states, index, len(order), label, states[package]['status'],
                       (('Atualizado ' if command == 'upgrade' else 'Instalado ') + label) if success else ('Falha em ' + label + ': ' + reasons.get(package, '')))
    ok = sum(1 for _, success in outcomes if success)
    failed = [name for name, success in outcomes if not success]
    if failed:
        raise RuntimeError('Instalações concluídas: %d/%d. Falharam: %s' % (
            ok, len(outcomes), '; '.join(_catalog_label(n) + ' (' + reasons.get(n, 'erro') + ')' for n in failed)))
    return 'Instalações concluídas: %d/%d.' % (ok, len(outcomes))


# --- Auxiliar elevado -------------------------------------------------------------------------------------------
# O gestor roda como o usuário comum (sem UAC). Para instalar programas sem ninguém clicar em "Sim", existe uma
# tarefa agendada com privilégio máximo ("\Arcoll\Install Helper"), criada uma única vez com permissão de administrador
# (pelo instalador elevado ou pela primeira solicitação). Ela só executa IDs do catálogo, revalidados aqui.
def _job_dir():
    base = os.environ.get('LOCALAPPDATA') or os.environ.get('TEMP') or '.'
    path = os.path.join(base, 'Arcoll', 'jobs')
    os.makedirs(path, exist_ok=True)
    return path


def _helper_ready():
    try:
        r = subprocess.run(['schtasks', '/Query', '/TN', _HELPER_TASK], capture_output=True, timeout=20, creationflags=_NO_WINDOW)
        return r.returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False


def _ensure_helper(progress=None, wait=150):
    """Cria a tarefa elevada uma vez (um aviso do Windows neste computador). Retorna True se ficou disponível."""
    if _helper_ready():
        return True
    script = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'Registrar_Instalador_Programas.ps1')
    if not os.path.isfile(script):
        return False
    if callable(progress):
        progress('Aguardando permissão de administrador neste computador (uma única vez)...')
    user = os.environ.get('USERDOMAIN', '') + '\\' + os.environ.get('USERNAME', '')
    pyw = os.path.join(os.path.dirname(os.path.abspath(sys.executable)), 'pythonw.exe')
    runtime = pyw if os.path.isfile(pyw) else sys.executable
    args = ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "%s" -User "%s" -Python "%s" -Script "%s"'
            % (script, user.strip('\\'), runtime, os.path.abspath(__file__)))
    try:
        if ctypes.windll.shell32.ShellExecuteW(None, 'runas', 'powershell.exe', args, None, 0) <= 32:
            return False
    except Exception:
        return False
    deadline = time.monotonic() + wait
    while time.monotonic() < deadline:
        check_cancel()
        if _helper_ready():
            return True
        time.sleep(2)
    return False


def _install_via_helper(ids, progress=None):
    jobs = _job_dir()
    job_file, status_file = os.path.join(jobs, 'install-job.json'), os.path.join(jobs, 'install-status.json')
    for stale in (status_file, os.path.join(jobs, 'install-cancel')):
        try:
            os.remove(stale)
        except OSError:
            pass
    job_id = hashlib.sha256((str(time.time()) + str(os.getpid())).encode()).hexdigest()[:16]
    with open(job_file, 'w', encoding='utf-8') as handle:
        json.dump({'job': job_id, 'ids': list(ids)}, handle)
    r = subprocess.run(['schtasks', '/Run', '/TN', _HELPER_TASK], capture_output=True, text=True, errors='replace', timeout=30, creationflags=_NO_WINDOW)
    if r.returncode != 0:
        raise RuntimeError('Não foi possível iniciar o auxiliar de instalação: ' + _tail(r.stdout + r.stderr))
    started, last, deadline = time.monotonic(), None, time.monotonic() + 3600
    while time.monotonic() < deadline:
        time.sleep(1.5)
        check_cancel()
        try:
            with open(status_file, 'r', encoding='utf-8') as handle:
                status = json.load(handle)
        except (OSError, ValueError):
            status = None
        if not status or status.get('job') != job_id:
            if time.monotonic() - started > 90:
                raise RuntimeError('O auxiliar de instalação não respondeu em 90 segundos. Reinstale o gestor como administrador.')
            continue
        message = status.get('message') or ''
        if message and message != last:
            last = message
            if callable(progress):
                progress(message)
        if status.get('done'):
            if status.get('ok'):
                return status.get('summary') or 'Instalações concluídas.'
            raise RuntimeError(status.get('summary') or 'Falha na instalação.')
    raise RuntimeError('A instalação não terminou dentro de 1 hora.')


def _helper_main():
    """Ponto de entrada da tarefa elevada: lê o trabalho, valida o catálogo e grava o andamento."""
    jobs = _job_dir()
    job_file, status_file = os.path.join(jobs, 'install-job.json'), os.path.join(jobs, 'install-status.json')
    try:
        os.remove(os.path.join(jobs, 'install-cancel'))
    except OSError:
        pass
    with open(job_file, 'r', encoding='utf-8') as handle:
        job = json.load(handle)
    job_id, ids = str(job.get('job') or ''), job.get('ids')

    def write(**fields):
        tmp = status_file + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as handle:
            json.dump(dict(fields, job=job_id), handle, ensure_ascii=False)
        os.replace(tmp, status_file)

    write(message='Auxiliar elevado iniciado...', done=False)
    flag = os.path.join(jobs, 'install-cancel')

    def watch():
        while not _CANCEL.is_set():
            if os.path.isfile(flag):
                cancel_all()
                return
            time.sleep(1)
    threading.Thread(target=watch, daemon=True).start()
    try:
        summary = install_packages(ids, lambda text: write(message=str(text), done=False), _direct=True)
        write(message=summary, done=True, ok=True, summary=summary)
    except Exception as exc:
        write(message=str(exc)[:400], done=True, ok=False, summary=str(exc)[:470])


def install_packages(ids, progress=None, _direct=False):
    """Instala ou atualiza exclusivamente IDs aprovados do catálogo WinGet."""
    if not isinstance(ids, list) or not (1 <= len(ids) <= 20) or any(type(x) is not str or x not in INSTALL_ALLOWED for x in ids) or len(set(ids)) != len(ids):
        raise ValueError('Seleção de aplicativos inválida ou não permitida.')
    if _direct or _is_admin():
        return _install_core(ids, progress)
    check_cancel()
    if _helper_ready() or _ensure_helper(progress):
        return _install_via_helper(ids, progress)
    if callable(progress):
        progress('Sem permissão de administrador: tentando instalar apenas para o usuário atual...')
    return _install_core(ids, progress)


if __name__ == '__main__' and '--elevated-job' in sys.argv:
    _helper_main()


class _InstallProgressDialog:
    """Janela de instalação do gestor: mesma apresentação da plataforma, com logos e botão Cancelar."""
    BG, CARD, LINE, TEXT, MUTED = '#172234', '#1d2a3b', '#38506c', '#eef5ff', '#9eb5cd'

    def __init__(self, parent, title, items, on_cancel=None):
        import tkinter as tk
        self.tk = tk
        self.items = list(items)
        self.on_cancel = on_cancel
        self.finished = False
        self.rows = {}
        self.photos = {}
        # Sem transient(): um pai oculto na bandeja impediria a janela de aparecer.
        self.win = tk.Toplevel(parent)
        self.win.title(title)
        self.win.configure(bg=self.BG)
        self.win.resizable(False, True)
        self.win.minsize(560, 360)
        width, height = 640, min(560, 230 + 40 * max(1, len(self.items)))
        try:
            x = max(0, (self.win.winfo_screenwidth() - width) // 2)
            y = max(0, (self.win.winfo_screenheight() - height) // 3)
            self.win.geometry('%dx%d+%d+%d' % (width, height, x, y))
        except Exception:
            self.win.geometry('%dx%d' % (width, height))
        head = tk.Frame(self.win, bg=self.BG)
        head.pack(fill='x', padx=18, pady=(16, 6))
        tk.Label(head, text='Instalação de programas', bg=self.BG, fg=self.TEXT, font=('Segoe UI', 14, 'bold')).pack(anchor='w')
        self.current_var = tk.StringVar(value='Preparando operação...')
        tk.Label(head, textvariable=self.current_var, bg=self.BG, fg=self.MUTED, font=('Segoe UI', 10), wraplength=590, justify='left').pack(anchor='w', pady=(4, 8))
        self.track = tk.Canvas(head, height=14, bg='#0e1c2e', highlightthickness=1, highlightbackground=self.LINE)
        self.track.pack(fill='x')
        self.fill = self.track.create_rectangle(0, 0, 0, 14, fill='#2ea043', width=0)
        self.percent = 0.0
        self.track.bind('<Configure>', lambda _e: self._paint())
        body = tk.Frame(self.win, bg=self.BG)
        body.pack(fill='both', expand=True, padx=18, pady=8)
        self.canvas = tk.Canvas(body, bg=self.BG, highlightthickness=0)
        scroll = tk.Scrollbar(body, orient='vertical', command=self.canvas.yview)
        self.canvas.configure(yscrollcommand=scroll.set)
        self.canvas.pack(side='left', fill='both', expand=True)
        scroll.pack(side='right', fill='y')
        self.list = tk.Frame(self.canvas, bg=self.BG)
        self.canvas.create_window((0, 0), window=self.list, anchor='nw', tags='list')
        self.list.bind('<Configure>', lambda _e: self.canvas.configure(scrollregion=self.canvas.bbox('all')))
        self.canvas.bind('<Configure>', lambda e: self.canvas.itemconfigure('list', width=e.width))
        for pkg in self.items:
            self._row(pkg)
        foot = tk.Frame(self.win, bg=self.BG)
        foot.pack(fill='x', padx=18, pady=(4, 16))
        self.cancel_btn = tk.Button(foot, text='Cancelar operação', command=self._cancel, bg='#c9344b', fg='white', activebackground='#e0475e',
                                    activeforeground='white', relief='flat', bd=0, padx=16, pady=7, font=('Segoe UI', 10, 'bold'), cursor='hand2')
        self.cancel_btn.pack(side='left')
        self.close_btn = tk.Button(foot, text='Fechar', command=self.close, state='disabled', bg='#354c6a', fg='white', disabledforeground='#7f93ab',
                                   activebackground='#46608a', activeforeground='white', relief='flat', bd=0, padx=16, pady=7, font=('Segoe UI', 10, 'bold'))
        self.close_btn.pack(side='right')
        self.win.protocol('WM_DELETE_WINDOW', self._cancel)
        self.show()

    def show(self):
        try:
            self.win.deiconify()
            self.win.update_idletasks()
            self.win.lift()
            self.win.attributes('-topmost', True)
            self.win.after(400, lambda: self.win.attributes('-topmost', False))
            self.win.focus_force()
        except Exception:
            pass

    def _row(self, pkg):
        tk = self.tk
        frame = tk.Frame(self.list, bg=self.CARD, highlightthickness=1, highlightbackground=self.LINE)
        frame.pack(fill='x', pady=3)
        photo = logo_photo(tk, pkg, _catalog_label(pkg), 28, on_ready=lambda p=pkg: self.win.after(0, lambda: self._refresh_logo(p)))
        self.photos[pkg] = photo
        icon = tk.Label(frame, image=photo, bg=self.CARD) if photo is not None else tk.Label(frame, text='', bg=self.CARD, width=3)
        icon.pack(side='left', padx=(10, 10), pady=7)
        tk.Label(frame, text=_catalog_label(pkg), bg=self.CARD, fg=self.TEXT, font=('Segoe UI', 10, 'bold'), anchor='w').pack(side='left', fill='x', expand=True)
        state = tk.Label(frame, text='Aguardando', bg=self.CARD, fg=self.MUTED, font=('Segoe UI', 9, 'bold'), anchor='e')
        state.pack(side='right', padx=12)
        self.rows[pkg] = (icon, state)

    def _refresh_logo(self, pkg):
        try:
            _LOGO_CACHE.pop((pkg, 28), None)
            photo = logo_photo(self.tk, pkg, _catalog_label(pkg), 28)
            if photo is not None:
                self.photos[pkg] = photo
                self.rows[pkg][0].configure(image=photo)
        except Exception:
            pass

    def _paint(self):
        try:
            self.track.coords(self.fill, 0, 0, self.track.winfo_width() * self.percent, 14)
        except Exception:
            pass

    def _cancel(self):
        if self.finished:
            self.close()
            return
        self.cancel_btn.configure(state='disabled', text='Cancelando...')
        self.current_var.set('Cancelando e encerrando os processos...')
        if callable(self.on_cancel):
            try:
                self.on_cancel()
            except Exception:
                pass

    def update_message(self, message):
        packet = None
        if isinstance(message, str) and message.startswith(_PROGRESS_PREFIX):
            try:
                packet = json.loads(message[len(_PROGRESS_PREFIX):])
            except Exception:
                packet = None
        if not packet:
            self.current_var.set(str(message))
            return
        total = max(1, int(packet.get('t') or 1))
        self.percent = min(1.0, max(0, int(packet.get('i') or 0)) / float(total))
        self._paint()
        current = (str(packet.get('c') or '') + ' — ' + str(packet.get('s') or '')).strip(' —')
        self.current_var.set(current or str(packet.get('m') or '') or 'Processando...')
        for idx, row in enumerate(packet.get('r') or []):
            if idx >= len(self.items):
                break
            value = (row if isinstance(row, str) else (row or {}).get('s')) or 'Aguardando'
            color = '#3fb950' if re.search(r'Concl|OK', value, re.I) else '#ff7b72' if re.search(r'Falh|Tempo|Cancel', value, re.I) else '#e3b341' if re.search(r'Baix|Instal|Atual', value, re.I) else self.MUTED
            try:
                self.rows[self.items[idx]][1].configure(text=value, fg=color)
            except Exception:
                pass

    def finish(self, message, ok=True):
        self.finished = True
        self.current_var.set(str(message))
        try:
            self.cancel_btn.pack_forget()
            self.close_btn.configure(state='normal')
            self.win.protocol('WM_DELETE_WINDOW', self.close)
            if ok:
                self.percent = 1.0
                self._paint()
        except Exception:
            pass

    def close(self):
        try:
            self.win.destroy()
        except Exception:
            pass


class ProgramsPanel:
    """Aba nativa do gestor: catálogo de instalação/atualização em lista simples."""
    def __init__(self, parent, app):
        import tkinter as tk
        from tkinter import ttk
        self.tk = tk
        self.ttk = ttk
        self.app = app
        self.parent = parent
        self.states = {}
        self.choices = {}
        self.busy = False
        self.images = {}
        self.progress_dialog = None

        top = ttk.Frame(parent)
        top.pack(fill='x', padx=12, pady=10)
        ttk.Label(top, text='Programas', font=('Segoe UI', 15, 'bold')).pack(side='left', padx=(0, 12))
        self.query = tk.StringVar()
        search = ttk.Entry(top, textvariable=self.query, width=34)
        search.pack(side='left', fill='x', expand=True)
        self.query.trace_add('write', lambda *_: self.render())
        self.refresh_button = ttk.Button(top, text='Verificar instalados', command=self.refresh)
        self.refresh_button.pack(side='left', padx=7)
        self.clear_button = ttk.Button(top, text='Limpar seleção', command=self.clear_selection)
        self.clear_button.pack(side='left', padx=(0, 7))
        self.action_button = ttk.Button(top, text='Instalar / Atualizar selecionados', command=self.install)
        self.action_button.pack(side='left')

        body = ttk.Frame(parent)
        body.pack(fill='both', expand=True, padx=12, pady=(0, 8))
        ttk.Style(parent).configure('Programs.Treeview', rowheight=32)
        self.tree = ttk.Treeview(body, columns=('mark', 'name', 'state', 'version'), show='tree headings', selectmode='none', style='Programs.Treeview')
        self.tree.heading('#0', text='')
        self.tree.column('#0', width=44, minwidth=44, stretch=False)
        self.tree.heading('mark', text='✓')
        self.tree.column('mark', width=40, anchor='center', stretch=False)
        self.tree.heading('name', text='Aplicativo')
        self.tree.column('name', width=280, anchor='w')
        self.tree.heading('state', text='Status')
        self.tree.column('state', width=180, anchor='w')
        self.tree.heading('version', text='Versão')
        self.tree.column('version', width=150, anchor='w')
        scroll = ttk.Scrollbar(body, orient='vertical', command=self.tree.yview)
        self.tree.configure(yscrollcommand=scroll.set)
        self.tree.pack(side='left', fill='both', expand=True)
        scroll.pack(side='right', fill='y')
        self.tree.bind('<ButtonRelease-1>', self.toggle)

        self.summary_var = tk.StringVar(value='Clique em Verificar instalados para atualizar o catálogo.')
        self.status_text = tk.StringVar(value='')
        ttk.Label(parent, textvariable=self.summary_var, wraplength=900).pack(fill='x', padx=12)
        ttk.Label(parent, textvariable=self.status_text, wraplength=900).pack(fill='x', padx=12, pady=(4, 8))
        self.render()
        self.refresh()

    def post(self, fn):
        try:
            self.parent.after(0, fn)
        except Exception:
            pass

    def status(self, msg):
        def apply():
            if isinstance(msg, str) and msg.startswith(_PROGRESS_PREFIX):
                if self.progress_dialog:
                    self.progress_dialog.update_message(msg)
                try:
                    packet = json.loads(msg[len(_PROGRESS_PREFIX):])
                    current = str(packet.get('c') or '')
                    state = str(packet.get('s') or '')
                    note = str(packet.get('m') or '')
                    self.status_text.set((current + ' — ' + state).strip(' —') or note)
                except Exception:
                    self.status_text.set('Processando instalação...')
            else:
                self.status_text.set(str(msg))
        self.post(apply)

    def _state_of(self, app_id):
        return self.states.get(app_id, {})

    def _is_checked(self, app_id):
        default = bool(self._state_of(app_id).get('installed'))
        return self.choices.get(app_id, default)

    def checked_ids(self):
        return [app['id'] for app in INSTALL_CATALOG if self._is_checked(app['id'])]

    def clear_selection(self):
        for app in INSTALL_CATALOG:
            self.choices[app['id']] = False
        self.render()
        self.status_text.set('Seleção limpa.')

    def refresh(self):
        if self.busy:
            return
        self.busy = True
        self.refresh_button.configure(state='disabled')
        self.action_button.configure(state='disabled')
        self.clear_button.configure(state='disabled')
        self.status_text.set('Verificando programas instalados...')
        def worker():
            try:
                result = catalog_inventory(progress=self.status)
                self.post(lambda: self._loaded(result))
            except Exception as exc:
                self.post(lambda e=str(exc): self._failed(e))
        import threading
        threading.Thread(target=worker, daemon=True).start()

    def _loaded(self, result):
        self.states = result
        self.busy = False
        self.render()
        installed = sum(1 for v in result.values() if v.get('installed'))
        updates = sum(1 for v in result.values() if v.get('update_available'))
        self.summary_var.set(f'{len(INSTALL_CATALOG)} programas no catálogo · {installed} instalados · {updates} com atualização disponível')
        self.status_text.set('Catálogo atualizado.')

    def _failed(self, msg):
        self.busy = False
        self.render()
        self.status_text.set('Falha na leitura: ' + msg)

    def _photo(self, app, state):
        # Logo oficial do programa (favicon do fabricante em cache); o ícone do Registro só entra como reserva.
        photo = logo_photo(self.tk, app['id'], app['name'], 24, on_ready=lambda: self.post(self.render))
        if photo is None:
            icon = state.get('icon', '')
            if isinstance(icon, str) and icon.startswith('data:image/png;base64,'):
                try:
                    photo = self.tk.PhotoImage(data=icon.partition(',')[2])
                except Exception:
                    photo = None
        if photo is not None:
            self.images[app['id']] = photo
        return photo

    def render(self):
        if not hasattr(self, 'tree'):
            return
        query = self.query.get().casefold().strip()
        self.tree.delete(*self.tree.get_children())
        self.images.clear()
        for app in INSTALL_CATALOG:
            if query and query not in (app['name'] + ' ' + app['category']).casefold():
                continue
            state = self._state_of(app['id'])
            installed = bool(state.get('installed'))
            update_available = bool(state.get('update_available'))
            checked = self._is_checked(app['id'])
            mark = '☑' if checked else '☐'
            if checked:
                if installed and update_available:
                    label = 'Atualizar'
                elif installed:
                    label = 'Verificar / reinstalar'
                else:
                    label = 'Instalar'
            else:
                label = 'Opcional'
            version = state.get('version') or ('Nova instalação' if not installed else '—')
            if update_available and state.get('available_version'):
                version = (state.get('version') or '—') + ' → ' + state.get('available_version')
            opts = {'iid': app['id'], 'values': (mark, app['name'], label, version)}
            photo = self._photo(app, state)
            if photo is not None:
                opts['image'] = photo
            self.tree.insert('', 'end', **opts)
        checked_total = len(self.checked_ids())
        self.action_button.configure(state='disabled' if self.busy or checked_total == 0 else 'normal')
        state_btn = 'disabled' if self.busy else 'normal'
        self.refresh_button.configure(state=state_btn)
        self.clear_button.configure(state=state_btn)

    def toggle(self, event):
        if self.busy or self.tree.identify_column(event.x) != '#1':
            return
        ident = self.tree.identify_row(event.y)
        if not ident:
            return
        current = self._is_checked(ident)
        next_value = not current
        checked_count = len(self.checked_ids())
        if next_value and not current and checked_count >= 20:
            self.status_text.set('Selecione no máximo 20 programas por operação.')
            return
        self.choices[ident] = next_value
        self.render()

    def install(self):
        from tkinter import messagebox
        ids = self.checked_ids()
        if self.busy or not ids:
            return
        if len(ids) > 20:
            self.status_text.set('Selecione no máximo 20 programas por operação.')
            return
        if not messagebox.askyesno('Confirmar instalação', f'Instalar ou atualizar {len(ids)} aplicativo(s) neste computador?', parent=self.parent):
            return
        self.busy = True
        self.render()
        reset_cancel()
        self.progress_dialog = _InstallProgressDialog(self.parent, 'Instalação de programas', ids, on_cancel=cancel_all)
        self.status_text.set('Iniciando a instalação...')
        def worker():
            try:
                outcome = install_packages(ids, self.status)
                self.post(lambda: self._finished(outcome, True))
            except OperationCancelled:
                self.post(lambda: self._finished('Instalação cancelada.', False))
            except Exception as exc:
                self.post(lambda e=str(exc): self._finished('Instalação: ' + e, False))
        import threading
        threading.Thread(target=worker, daemon=True).start()

    def _finished(self, msg, ok):
        self.busy = False
        if self.progress_dialog:
            self.progress_dialog.finish(msg, ok)
        self.status_text.set(msg)
        self.render()
        self.refresh()
