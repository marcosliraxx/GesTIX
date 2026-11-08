# Caminho: gestores/windows/arcoll_secure_helper.py
"""Arcoll - componente SYSTEM para a tela segura do Windows (bloqueio, login e UAC).

Instalado SOMENTE em %ProgramFiles%\\Arcoll\\SecureDesktop (gravável apenas por administradores)
pelo instalador elevado, e executado como SYSTEM por uma tarefa agendada.

  --broker            serviço leve (sessão 0): atende pedidos válidos do aplicativo e inicia, na sessão
                      do console, um processo --worker com a identidade SYSTEM. Também é o único que
                      envia Ctrl+Alt+Del (SendSAS só é aceito de um processo da sessão 0).
  --worker <pedido>   captura a tela e injeta mouse/teclado na área de trabalho de entrada (Winlogon,
                      UAC...) enquanto o pedido do aplicativo for renovado; encerra quando o Windows
                      volta à área de trabalho do usuário.

O pedido é tratado como entrada não confiável: formato, validade (8 s), sessão do console e
credenciais são validados, e o destino é sempre a origem HTTPS gravada no local protegido.
"""
import argparse, base64, ctypes, hashlib, json, os, re, shutil, subprocess, sys, threading, time
from ctypes import wintypes
from pathlib import Path

HERE = Path(__file__).resolve().parent
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))  # garante o arcoll_remote.py ao lado deste arquivo (também no autoteste da atualização)
import arcoll_remote as remote

from arcoll_release import VERSION as SECURE_HELPER_VERSION
LOG_FILE = remote.PROGRAM_DATA / 'logs' / 'secure-helper.log'
HEX64 = re.compile(r'[a-f0-9]{64}\Z')
SITE = re.compile(r'[A-Z0-9]{5}\Z')
ERROR_ALREADY_EXISTS = 183
SAS_MIN_INTERVAL = 1.0          # segundos entre dois Ctrl+Alt+Del da mesma sessão
NO_WINDOW = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
UPDATE_MIN_INTERVAL = 300       # segundos entre duas tentativas de autoatualização
SECURE_FILES = ('arcoll_secure_helper.py', 'arcoll_remote.py','arcoll_release.py')   # ficam em %ProgramFiles%\\Arcoll\\SecureDesktop
CORE_FILE = 'arcoll_gestor.py'                                      # fica em %ProgramFiles%\\Arcoll\\LocalCore
CORE_DIR = Path(os.environ.get('ProgramFiles', r'C:\Program Files')) / 'Arcoll' / 'LocalCore'
CORE_TASK = 'ArcollLocalCore'
MAX_SOURCE_BYTES = 1500000
STAGE_ROOT = remote.PROGRAM_DATA / 'HelperUpdate'                  # somente SYSTEM/Administradores
SAS_POLICY_KEY = r'SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System'


def log(message):
    try:
        LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
        if LOG_FILE.exists() and LOG_FILE.stat().st_size > 512 * 1024:
            LOG_FILE.replace(LOG_FILE.with_suffix('.old'))
        with LOG_FILE.open('a', encoding='utf-8') as handle:
            handle.write('[%s] %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), message))
    except OSError:
        pass


def load_origin():
    try:
        data = json.loads((HERE / 'helper-config.json').read_text(encoding='utf-8-sig'))
        origin = str(data.get('origin') or '').rstrip('/')
    except (OSError, ValueError, AttributeError):
        origin = ''
    if not re.fullmatch(r'https://[^/\s]+(?:/[^\s]*)?', origin):
        raise RuntimeError('helper-config.json sem origem HTTPS válida')
    return origin


def read_request(path, console_session=None):
    """Valida o pedido do aplicativo. Retorna None para qualquer irregularidade."""
    try:
        if path.stat().st_size > 4096:
            return None
        data = json.loads(path.read_text(encoding='utf-8'))
        if not isinstance(data, dict) or int(data.get('protocol', 0)) != remote.SECURE_PROTOCOL:
            return None
        command, session, stamp = int(data['command_id']), int(data['session']), float(data['ts'])
        creds = data['creds']
        fingerprint, site, key = str(creds['fingerprint']).lower(), str(creds['site_code']).upper(), str(creds['download_key']).lower()
        if not (1 <= command < 2 ** 31) or path.name != 'request-%d.json' % session:
            return None
        if not HEX64.fullmatch(fingerprint) or not SITE.fullmatch(site) or not HEX64.fullmatch(key):
            return None
        if not abs(time.time() - stamp) <= remote.REQUEST_TTL:
            return None
        if console_session is not None and session != console_session:
            return None
        return {'command_id': command, 'session': session, 'control': bool(data.get('control')),
                'creds': {'fingerprint': fingerprint, 'site_code': site, 'download_key': key}}
    except (OSError, ValueError, KeyError, TypeError):
        return None


def read_sas_request(path, console_session=None):
    """Valida um pedido de Ctrl+Alt+Del (sas-<sessão>.json). Retorna None para qualquer irregularidade."""
    try:
        if path.stat().st_size > 1024:
            return None
        data = json.loads(path.read_text(encoding='utf-8'))
        if not isinstance(data, dict) or int(data.get('protocol', 0)) != remote.SECURE_PROTOCOL:
            return None
        session, stamp = int(data['session']), float(data['ts'])
        if path.name != '%s%d.json' % (remote.SAS_REQUEST_PREFIX, session):
            return None
        if not abs(time.time() - stamp) <= remote.REQUEST_TTL:
            return None
        if console_session is not None and session != console_session:
            return None
        return {'session': session, 'command_id': int(data.get('command_id') or 0)}
    except (OSError, ValueError, KeyError, TypeError):
        return None


def ensure_sas_policy():
    """Garante que o Windows aceite SendSAS de serviços (SoftwareSASGeneration 1 ou 3)."""
    try:
        import winreg
        with winreg.CreateKeyEx(winreg.HKEY_LOCAL_MACHINE, SAS_POLICY_KEY, 0, winreg.KEY_READ | winreg.KEY_SET_VALUE | winreg.KEY_WOW64_64KEY) as key:
            try:
                current = int(winreg.QueryValueEx(key, 'SoftwareSASGeneration')[0])
            except FileNotFoundError:
                current = None
            if current not in (1, 3):
                winreg.SetValueEx(key, 'SoftwareSASGeneration', 0, winreg.REG_DWORD, 1)
                log('Política SoftwareSASGeneration ajustada de %s para 1.' % current)
            return True
    except Exception as exc:
        log('Não foi possível verificar SoftwareSASGeneration: %r' % (exc,))
        return False


def send_sas():
    """Envia a sequência de atenção segura (Ctrl+Alt+Del) ao Winlogon. Só funciona no broker (SYSTEM, sessão 0)."""
    sas = ctypes.WinDLL('sas', use_last_error=True)
    sas.SendSAS.argtypes = [wintypes.BOOL]
    sas.SendSAS.restype = None
    sas.SendSAS(False)  # FALSE: o chamador é um serviço/SYSTEM, não o usuário


def serve_sas_requests(console, last_sent):
    """Atende pedidos de Ctrl+Alt+Del feitos pelo aplicativo ou pelo worker."""
    try:
        candidates = list(remote.REQUEST_DIR.glob(remote.SAS_REQUEST_PREFIX + '*.json'))
    except OSError:
        return
    for path in candidates:
        req = read_sas_request(path, console if console != 0xFFFFFFFF else -1)
        if req is None:
            try:
                if time.time() - path.stat().st_mtime > 10:
                    path.unlink()
            except OSError:
                pass
            continue
        try:
            path.unlink()  # um pedido = um Ctrl+Alt+Del
        except OSError:
            continue
        if time.time() - last_sent.get(req['session'], 0.0) < SAS_MIN_INTERVAL:
            continue
        last_sent[req['session']] = time.time()
        try:
            send_sas()
            log('Ctrl+Alt+Del enviado: sessão %d, comando %d.' % (req['session'], req['command_id']))
        except Exception as exc:
            log('Falha ao enviar Ctrl+Alt+Del: %r' % (exc,))


# ---------------------------------------------------------------------------
# Autoatualização (broker, SYSTEM)
# ---------------------------------------------------------------------------
# Esta pasta (Program Files) só é gravável por administradores, então a atualização remota do aplicativo
# (que roda como usuário) nunca chegava aqui. O próprio broker busca a versão publicada, valida e troca.
def source_version(data, name):
    match = re.search(rb'^%s\s*=\s*[\'"]([^\'"]+)[\'"]' % name.encode(), data, re.M)
    return match.group(1).decode('ascii', 'replace') if match else None


def core_version():
    try:
        return source_version((HERE / 'arcoll_release.py').read_bytes()[:8192], 'VERSION')
    except OSError:
        return None


def read_update_request(path, console_session=None):
    """Valida o aviso de atualização (update-<sessão>.json). Retorna None para qualquer irregularidade."""
    try:
        if path.stat().st_size > 2048:
            return None
        data = json.loads(path.read_text(encoding='utf-8'))
        if not isinstance(data, dict) or int(data.get('protocol', 0)) != remote.SECURE_PROTOCOL:
            return None
        session, stamp = int(data['session']), float(data['ts'])
        creds = data['creds']
        fingerprint, site, key = str(creds['fingerprint']).lower(), str(creds['site_code']).upper(), str(creds['download_key']).lower()
        if path.name != '%s%d.json' % (remote.HELPER_UPDATE_PREFIX, session):
            return None
        if not HEX64.fullmatch(fingerprint) or not SITE.fullmatch(site) or not HEX64.fullmatch(key):
            return None
        if abs(time.time() - stamp) > 60:
            return None
        if console_session is not None and session != console_session:
            return None
        return {'session': session, 'creds': {'fingerprint': fingerprint, 'site_code': site, 'download_key': key}}
    except (OSError, ValueError, KeyError, TypeError):
        return None


def fetch_release(creds):
    """Baixa a release publicada e devolve {nome: bytes} dos arquivos que este componente mantém."""
    wanted = set(SECURE_FILES) | {CORE_FILE}
    reply = remote.request_json(load_origin() + '/api/api.php?action=gestor-update-package', creds, timeout=65)
    if not isinstance(reply, dict) or not reply.get('ok') or not isinstance(reply.get('files'), list):
        raise RuntimeError('servidor sem pacote: %s' % str((reply or {}).get('error') if isinstance(reply, dict) else reply)[:80])
    found = {}
    for row in reply['files']:
        name = row.get('name') if isinstance(row, dict) else None
        if name not in wanted or name in found:
            continue
        data = base64.b64decode(str(row.get('content') or ''), validate=True)
        if not data or len(data) > MAX_SOURCE_BYTES or hashlib.sha256(data).hexdigest() != str(row.get('sha256') or '').lower():
            raise RuntimeError('integridade inválida: ' + name)
        found[name] = data
    for name in SECURE_FILES:
        if name not in found:
            raise RuntimeError('release sem ' + name)
    return found


def _replace(path, data):
    temp = path.with_name(path.name + '.updating')
    temp.write_bytes(data)
    os.replace(temp, path)


def apply_release(found):
    """Troca os arquivos que mudaram. Devolve (componente_seguro_mudou, nucleo_local_mudou)."""
    secure_changed = [n for n in SECURE_FILES if not (HERE / n).is_file() or (HERE / n).read_bytes() != found[n]]
    core_path = CORE_DIR / CORE_FILE
    core_changed = CORE_FILE in found and core_path.is_file() and (core_path.read_bytes() != found[CORE_FILE] or not (CORE_DIR/'arcoll_release.py').is_file() or (CORE_DIR/'arcoll_release.py').read_bytes() != found['arcoll_release.py'])
    if not secure_changed and not core_changed:
        return False, False
    stage = STAGE_ROOT / time.strftime('%Y%m%d-%H%M%S')
    stage.mkdir(parents=True, exist_ok=True)
    try:
        # 1) valida tudo ANTES de tocar em qualquer arquivo instalado
        for name in SECURE_FILES:
            (stage / name).write_bytes(found[name])
            compile(found[name], name, 'exec')
        if core_changed:
            compile(found[CORE_FILE], CORE_FILE, 'exec')
        if secure_changed:
            python = Path(sys.executable).with_name('python.exe')
            test = subprocess.run([str(python if python.is_file() else sys.executable), str(stage / 'arcoll_secure_helper.py'), '--selftest'],
                                  capture_output=True, timeout=45, cwd=str(stage), creationflags=NO_WINDOW)
            if test.returncode != 0:
                lines = (test.stderr or b'').decode('utf-8', 'replace').strip().splitlines()
                raise RuntimeError('autoteste do código novo falhou (%d): %s' % (test.returncode, (lines[-1] if lines else '')[:160]))
        # 2) troca com cópia de segurança e retorno ao estado anterior se algo falhar
        backup = HERE / 'backup'
        backup.mkdir(exist_ok=True)
        done = []
        try:
            for name in secure_changed:
                if (HERE / name).is_file():
                    (backup / name).write_bytes((HERE / name).read_bytes())
                done.append(name)
                _replace(HERE / name, found[name])
            if core_changed:
                (backup / (CORE_FILE + '.core')).write_bytes(core_path.read_bytes())
                _replace(core_path, found[CORE_FILE])
                metadata=CORE_DIR/'arcoll_release.py'
                if metadata.is_file():(backup/'arcoll_release.py.core').write_bytes(metadata.read_bytes())
                _replace(metadata,found['arcoll_release.py'])
        except Exception:
            if core_changed:
                if (backup/(CORE_FILE+'.core')).is_file():_replace(core_path,(backup/(CORE_FILE+'.core')).read_bytes())
                if (backup/'arcoll_release.py.core').is_file():_replace(CORE_DIR/'arcoll_release.py',(backup/'arcoll_release.py.core').read_bytes())
            for name in done:
                old = backup / name
                if old.is_file():
                    _replace(HERE / name, old.read_bytes())
            raise
    finally:
        shutil.rmtree(stage, ignore_errors=True)  # inclui o __pycache__ criado pelo autoteste
    return bool(secure_changed), bool(core_changed)


def restart_core_task():
    flags = NO_WINDOW
    subprocess.run(['schtasks', '/End', '/TN', CORE_TASK], capture_output=True, timeout=30, creationflags=flags)
    time.sleep(1.5)
    subprocess.run(['schtasks', '/Run', '/TN', CORE_TASK], capture_output=True, timeout=30, creationflags=flags)


class SelfUpdater:
    """Executa a autoatualização em segundo plano para não travar o atendimento de Ctrl+Alt+Del e pedidos."""
    def __init__(self):
        self.busy = False
        self.last_try = 0.0
        self.restart_pending = False

    def start(self, creds):
        if self.busy or time.time() - self.last_try < UPDATE_MIN_INTERVAL:
            return
        self.busy = True
        self.last_try = time.time()
        threading.Thread(target=self._run, args=(creds,), daemon=True).start()

    def _run(self, creds):
        try:
            found = fetch_release(creds)
            secure, core = apply_release(found)
            if secure or core:
                log('Autoatualização aplicada (componente seguro: %s; núcleo local: %s).' % ('sim' if secure else 'não', 'sim' if core else 'não'))
            if core:
                try:
                    restart_core_task()
                    log('Núcleo local reiniciado (v%s).' % core_version())
                except Exception as exc:
                    log('Núcleo local atualizado, mas não reiniciado: %r' % (exc,))
            if secure:
                self.restart_pending = True  # o broker reinicia quando não houver sessão remota ativa
        except Exception as exc:
            log('Autoatualização não concluída: %s' % str(exc)[:220])
        finally:
            self.busy = False


def serve_update_requests(console, updater):
    try:
        candidates = list(remote.REQUEST_DIR.glob(remote.HELPER_UPDATE_PREFIX + '*.json'))
    except OSError:
        return
    for path in candidates:
        req = read_update_request(path, console if console != 0xFFFFFFFF else -1)
        if req is None:
            try:
                if time.time() - path.stat().st_mtime > 120:
                    path.unlink()
            except OSError:
                pass
            continue
        try:
            path.unlink()
        except OSError:
            continue
        updater.start(req['creds'])


# ---------------------------------------------------------------------------
# Desinstalação remota (broker, SYSTEM)
# ---------------------------------------------------------------------------
REMOVAL_TASK = 'ArcollRemoval'
REMOVAL_SCRIPT = r"""$ErrorActionPreference = 'SilentlyContinue'
Start-Sleep -Seconds 4
foreach ($n in @('ArcollLocalCore','ArcollSecureDesktop')) { Stop-ScheduledTask -TaskName $n; Unregister-ScheduledTask -TaskName $n -Confirm:$false }
Get-ScheduledTask -TaskPath '\Arcoll\*' | ForEach-Object { Stop-ScheduledTask -InputObject $_; Unregister-ScheduledTask -InputObject $_ -Confirm:$false }
Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -and $_.CommandLine -match '(?i)arcoll_(gestor|desktop|app|secure_helper|updater)\.py' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
Start-Sleep -Seconds 2
$profiles = @(Get-ChildItem -LiteralPath 'C:\Users' -Directory -Force | Where-Object { $_.Name -notin @('Public','Default','Default User','All Users') })
$links = @(); $links += (Join-Path $env:PUBLIC 'Desktop'); $links += (Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\StartUp')
foreach ($p in $profiles) { $links += (Join-Path $p.FullName 'Desktop'); $links += (Join-Path $p.FullName 'AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup') }
$shell = New-Object -ComObject WScript.Shell
foreach ($folder in $links) {
  if (-not (Test-Path -LiteralPath $folder)) { continue }
  Get-ChildItem -LiteralPath $folder -Filter '*.lnk' -Force | ForEach-Object {
    try { $sc = $shell.CreateShortcut($_.FullName); $blob = ($sc.TargetPath + ' ' + $sc.Arguments + ' ' + $sc.WorkingDirectory).ToLowerInvariant()
      if ($blob.Contains('c:\arcoll') -or $blob.Contains('arcoll_desktop.py') -or $blob.Contains('arcoll_app.py') -or $blob.Contains('instalar_arcoll.ps1')) { Remove-Item -LiteralPath $_.FullName -Force } } catch {}
  }
}
$dirs = @('C:\Arcoll', (Join-Path $env:ProgramFiles 'Arcoll'), (Join-Path $env:ProgramData 'Arcoll'))
foreach ($p in $profiles) { $dirs += (Join-Path $p.FullName 'AppData\Roaming\Arcoll'); $dirs += (Join-Path $p.FullName 'AppData\Local\Arcoll') }
for ($try = 1; $try -le 8; $try++) {
  foreach ($d in $dirs) { if (Test-Path -LiteralPath $d) { Remove-Item -LiteralPath $d -Recurse -Force } }
  if (-not ($dirs | Where-Object { Test-Path -LiteralPath $_ })) { break }
  Start-Sleep -Seconds 2
}
schtasks.exe /Delete /TN ArcollRemoval /F | Out-Null
"""


def read_uninstall_request(path, console_session=None):
    """Valida uninstall-<sessão>.json. Qualquer irregularidade devolve None."""
    try:
        if path.stat().st_size > 2048:
            return None
        data = json.loads(path.read_text(encoding='utf-8'))
        if not isinstance(data, dict) or int(data.get('protocol', 0)) != remote.SECURE_PROTOCOL:
            return None
        session, stamp = int(data['session']), float(data['ts'])
        creds = data['creds']
        fingerprint, site, key = str(creds['fingerprint']).lower(), str(creds['site_code']).upper(), str(creds['download_key']).lower()
        if path.name != '%s%d.json' % (remote.HELPER_UNINSTALL_PREFIX, session):
            return None
        if not HEX64.fullmatch(fingerprint) or not SITE.fullmatch(site) or not HEX64.fullmatch(key):
            return None
        if abs(time.time() - stamp) > 60:
            return None
        if console_session is not None and session != console_session:
            return None
        return {'session': session, 'creds': {'fingerprint': fingerprint, 'site_code': site, 'download_key': key}}
    except (OSError, ValueError, KeyError, TypeError):
        return None


def launch_removal():
    """Cria e inicia uma tarefa SYSTEM avulsa: ela sobrevive à parada das tarefas do gestor que o script executa."""
    stage = STAGE_ROOT / 'removal'
    stage.mkdir(parents=True, exist_ok=True)
    script = stage / 'remover.ps1'
    script.write_text(REMOVAL_SCRIPT, encoding='utf-8-sig')
    command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "%s"' % script
    flags = NO_WINDOW
    subprocess.run(['schtasks', '/Delete', '/TN', REMOVAL_TASK, '/F'], capture_output=True, timeout=30, creationflags=flags)
    made = subprocess.run(['schtasks', '/Create', '/TN', REMOVAL_TASK, '/SC', 'ONCE', '/ST', '23:59', '/RU', 'SYSTEM', '/RL', 'HIGHEST', '/F', '/TR', command],
                          capture_output=True, timeout=30, creationflags=flags)
    if made.returncode != 0:
        raise RuntimeError('tarefa de remoção não criada (%d)' % made.returncode)
    ran = subprocess.run(['schtasks', '/Run', '/TN', REMOVAL_TASK], capture_output=True, timeout=30, creationflags=flags)
    if ran.returncode != 0:
        raise RuntimeError('tarefa de remoção não iniciada (%d)' % ran.returncode)


class RemoteUninstaller:
    """Confirma a ordem no servidor e remove o gestor. Roda em segundo plano para não travar o broker."""
    def __init__(self):
        self.busy = False
        self.last_try = 0.0

    def start(self, creds):
        if self.busy or time.time() - self.last_try < 20:
            return
        self.busy = True
        self.last_try = time.time()
        threading.Thread(target=self._run, args=(creds,), daemon=True).start()

    def _run(self, creds):
        try:
            origin = load_origin()
            # Nunca confia no arquivo de pedido: só prossegue se a plataforma confirmar a ordem.
            reply = remote.request_json(origin + '/api/api.php?action=gestor-poll', creds, timeout=20)
            if not isinstance(reply, dict) or not reply.get('ok') or reply.get('uninstall') is not True:
                log('Pedido de desinstalação ignorado: a plataforma não confirmou a ordem.')
                return
            done = remote.request_json(origin + '/api/api.php?action=gestor-uninstall-confirm', creds, timeout=20)
            if not isinstance(done, dict) or not done.get('ok'):
                log('Desinstalação não confirmada pelo servidor; nada foi removido.')
                return
            log('Desinstalação ordenada pela plataforma: iniciando a remoção do gestor.')
            launch_removal()
        except Exception as exc:
            log('Desinstalação remota não concluída: %s' % str(exc)[:200])
        finally:
            self.busy = False


def serve_uninstall_requests(console, remover):
    try:
        candidates = list(remote.REQUEST_DIR.glob(remote.HELPER_UNINSTALL_PREFIX + '*.json'))
    except OSError:
        return
    for path in candidates:
        req = read_uninstall_request(path, console if console != 0xFFFFFFFF else -1)
        if req is None:
            try:
                if time.time() - path.stat().st_mtime > 120:
                    path.unlink()
            except OSError:
                pass
            continue
        try:
            path.unlink()
        except OSError:
            continue
        remover.start(req['creds'])


# ---------------------------------------------------------------------------
# Processos do Windows (broker)
# ---------------------------------------------------------------------------
class STARTUPINFOW(ctypes.Structure):
    _fields_ = [('cb', wintypes.DWORD), ('lpReserved', wintypes.LPWSTR), ('lpDesktop', wintypes.LPWSTR), ('lpTitle', wintypes.LPWSTR),
                ('dwX', wintypes.DWORD), ('dwY', wintypes.DWORD), ('dwXSize', wintypes.DWORD), ('dwYSize', wintypes.DWORD),
                ('dwXCountChars', wintypes.DWORD), ('dwYCountChars', wintypes.DWORD), ('dwFillAttribute', wintypes.DWORD),
                ('dwFlags', wintypes.DWORD), ('wShowWindow', wintypes.WORD), ('cbReserved2', wintypes.WORD),
                ('lpReserved2', ctypes.c_void_p), ('hStdInput', wintypes.HANDLE), ('hStdOutput', wintypes.HANDLE), ('hStdError', wintypes.HANDLE)]


class PROCESS_INFORMATION(ctypes.Structure):
    _fields_ = [('hProcess', wintypes.HANDLE), ('hThread', wintypes.HANDLE), ('dwProcessId', wintypes.DWORD), ('dwThreadId', wintypes.DWORD)]


class LUID(ctypes.Structure):
    _fields_ = [('LowPart', wintypes.DWORD), ('HighPart', wintypes.LONG)]


class TOKEN_PRIVILEGES(ctypes.Structure):
    _fields_ = [('PrivilegeCount', wintypes.DWORD), ('Luid', LUID), ('Attributes', wintypes.DWORD)]


def _winapi():
    k32 = ctypes.WinDLL('kernel32', use_last_error=True)
    adv = ctypes.WinDLL('advapi32', use_last_error=True)
    k32.GetCurrentProcess.restype = wintypes.HANDLE
    k32.CloseHandle.argtypes = [wintypes.HANDLE]
    k32.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
    k32.WaitForSingleObject.restype = wintypes.DWORD
    k32.WTSGetActiveConsoleSessionId.restype = wintypes.DWORD
    k32.CreateMutexW.argtypes = [ctypes.c_void_p, wintypes.BOOL, wintypes.LPCWSTR]
    k32.CreateMutexW.restype = wintypes.HANDLE
    adv.OpenProcessToken.argtypes = [wintypes.HANDLE, wintypes.DWORD, ctypes.POINTER(wintypes.HANDLE)]
    adv.DuplicateTokenEx.argtypes = [wintypes.HANDLE, wintypes.DWORD, ctypes.c_void_p, ctypes.c_int, ctypes.c_int, ctypes.POINTER(wintypes.HANDLE)]
    adv.SetTokenInformation.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    adv.LookupPrivilegeValueW.argtypes = [wintypes.LPCWSTR, wintypes.LPCWSTR, ctypes.POINTER(LUID)]
    adv.AdjustTokenPrivileges.argtypes = [wintypes.HANDLE, wintypes.BOOL, ctypes.POINTER(TOKEN_PRIVILEGES), wintypes.DWORD, ctypes.c_void_p, ctypes.c_void_p]
    adv.CreateProcessAsUserW.argtypes = [wintypes.HANDLE, wintypes.LPCWSTR, wintypes.LPWSTR, ctypes.c_void_p, ctypes.c_void_p, wintypes.BOOL,
                                         wintypes.DWORD, ctypes.c_void_p, wintypes.LPCWSTR, ctypes.POINTER(STARTUPINFOW), ctypes.POINTER(PROCESS_INFORMATION)]
    return k32, adv


def _enable_privilege(adv, token, name):
    luid = LUID()
    if not adv.LookupPrivilegeValueW(None, name, ctypes.byref(luid)):
        raise OSError('Privilégio %s indisponível (erro %d)' % (name, ctypes.get_last_error()))
    privileges = TOKEN_PRIVILEGES(1, luid, 0x00000002)  # SE_PRIVILEGE_ENABLED
    adv.AdjustTokenPrivileges(token, False, ctypes.byref(privileges), 0, None, None)
    if ctypes.get_last_error() != 0:
        raise OSError('Não foi possível habilitar %s (erro %d)' % (name, ctypes.get_last_error()))


def spawn_worker(session_id, request_path):
    """Inicia este mesmo script em modo --worker, como SYSTEM, na sessão interativa do console."""
    k32, adv = _winapi()
    process_token = wintypes.HANDLE()
    primary = wintypes.HANDLE()
    if not adv.OpenProcessToken(k32.GetCurrentProcess(), 0x000F01FF, ctypes.byref(process_token)):
        raise OSError('OpenProcessToken falhou (%d)' % ctypes.get_last_error())
    try:
        _enable_privilege(adv, process_token, 'SeTcbPrivilege')
        if not adv.DuplicateTokenEx(process_token, 0x02000000, None, 2, 1, ctypes.byref(primary)):  # SecurityImpersonation, TokenPrimary
            raise OSError('DuplicateTokenEx falhou (%d)' % ctypes.get_last_error())
        try:
            session = wintypes.DWORD(session_id)
            if not adv.SetTokenInformation(primary, 12, ctypes.byref(session), ctypes.sizeof(session)):  # TokenSessionId
                raise OSError('SetTokenInformation falhou (%d)' % ctypes.get_last_error())
            startup = STARTUPINFOW()
            startup.cb = ctypes.sizeof(startup)
            startup.lpDesktop = 'winsta0\\default'
            info = PROCESS_INFORMATION()
            command = ctypes.create_unicode_buffer('"%s" "%s" --worker "%s"' % (sys.executable, Path(__file__).resolve(), request_path), 1024)
            if not adv.CreateProcessAsUserW(primary, None, command, None, None, False, 0x08000000, None, str(HERE), ctypes.byref(startup), ctypes.byref(info)):  # CREATE_NO_WINDOW
                raise OSError('CreateProcessAsUserW falhou (%d)' % ctypes.get_last_error())
            k32.CloseHandle(info.hThread)
            return info.hProcess, int(info.dwProcessId)
        finally:
            k32.CloseHandle(primary)
    finally:
        k32.CloseHandle(process_token)


def write_status(workers):
    try:
        remote.STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
        temp = remote.STATUS_FILE.with_suffix('.tmp')
        temp.write_text(json.dumps({'protocol': remote.SECURE_PROTOCOL, 'version': SECURE_HELPER_VERSION, 'ts': time.time(), 'pid': os.getpid(), 'workers': workers,
                                    'self_update': True, 'remote_uninstall': True, 'core_version': core_version()}), encoding='utf-8')
        os.replace(temp, remote.STATUS_FILE)
    except OSError as exc:
        log('Status não gravado: ' + str(exc))


def single_instance(name):
    k32, _adv = _winapi()
    handle = k32.CreateMutexW(None, False, name)
    if not handle or ctypes.get_last_error() == ERROR_ALREADY_EXISTS:
        return None
    return handle


def broker():
    load_origin()  # falha cedo se a instalação estiver incompleta
    guard = single_instance('Global\\ArcollSecureBroker')
    if guard is None:
        return 0
    k32, _adv = _winapi()
    log('Broker iniciado (v%s).' % SECURE_HELPER_VERSION)
    ensure_sas_policy()
    workers = {}  # sessão -> (handle, pid, comando)
    sas_sent = {}  # sessão -> instante do último Ctrl+Alt+Del
    updater = SelfUpdater()
    remover = RemoteUninstaller()
    last_scan = 0.0
    while True:
        console = int(k32.WTSGetActiveConsoleSessionId())
        serve_sas_requests(console, sas_sent)  # a cada ciclo (0,2 s) para o Ctrl+Alt+Del responder rápido
        if time.time() - last_scan < 1.0:
            time.sleep(0.2)
            continue
        last_scan = time.time()
        serve_update_requests(console, updater)
        serve_uninstall_requests(console, remover)
        if updater.restart_pending and not workers and not updater.busy:
            # Código novo gravado: libera a trava de instância única e inicia o broker atualizado.
            log('Reiniciando o broker com o código atualizado.')
            k32.CloseHandle(guard)
            try:
                subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--broker'], cwd=str(HERE), creationflags=NO_WINDOW)
                return 0
            except OSError as exc:
                log('Falha ao reiniciar o broker: %r' % (exc,))
                guard = single_instance('Global\\ArcollSecureBroker')
                updater.restart_pending = False
        for session, (handle, pid, _cmd) in list(workers.items()):
            if k32.WaitForSingleObject(handle, 0) != 0x102:  # WAIT_TIMEOUT: ainda executando
                k32.CloseHandle(handle)
                del workers[session]
        try:
            candidates = list(remote.REQUEST_DIR.glob('request-*.json'))
        except OSError:
            candidates = []
        for path in candidates:
            req = read_request(path, console if console != 0xFFFFFFFF else -1)
            if req is None:
                try:
                    if time.time() - path.stat().st_mtime > 60:
                        path.unlink()
                except OSError:
                    pass
                continue
            if req['session'] in workers:
                continue
            try:
                handle, pid = spawn_worker(req['session'], path)
                workers[req['session']] = (handle, pid, req['command_id'])
                log('Worker iniciado: sessão %d, comando %d, PID %d.' % (req['session'], req['command_id'], pid))
            except OSError as exc:
                log('Falha ao iniciar worker: ' + str(exc))
        write_status(len(workers))
        time.sleep(0.2)


def worker(request_path):
    path = Path(request_path)
    req = read_request(path)
    if req is None:
        return 1
    guard = single_instance('Local\\ArcollSecureWorker')
    if guard is None:
        return 0
    origin = load_origin()
    stop, control = threading.Event(), threading.Event()
    if req['control']:
        control.set()
    base_url = '%s/api/remote-screen.php?command_id=%d&action=' % (origin, req['command_id'])
    session = remote.RemoteScreenSession(req['creds'], base_url, stop, control, role='system', request_path=path,
                                         expected_command=req['command_id'], on_error=log)
    log('Worker ativo: comando %d.' % req['command_id'])
    session.start()
    while not stop.is_set():
        stop.wait(0.5)
    for thread in session.threads:thread.join(timeout=8)
    log('Worker encerrado: comando %d.' % req['command_id'])
    return 0


def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--broker', action='store_true')
    mode.add_argument('--worker', metavar='PEDIDO')
    mode.add_argument('--selftest', action='store_true')  # usado pela autoatualização: o módulo importou e o parser funciona
    args = parser.parse_args()
    try:
        if args.selftest:
            return 0
        return broker() if args.broker else worker(args.worker)
    except Exception as exc:
        log('Erro fatal: %r' % (exc,))
        return 1


if __name__ == '__main__':
    sys.exit(main())
