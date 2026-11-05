# Caminho: agentes/windows/arcoll_remote.py
"""Arcoll - acesso remoto: captura de tela, entrada de mouse/teclado e tela segura do Windows.

Este módulo é a única implementação de captura/entrada remota. Ele é usado:
  * pelo aplicativo (arcoll_app.py), na sessão do usuário (role='user');
  * pelo componente SYSTEM (arcoll_secure_helper.py), quando a área de trabalho de entrada
    deixa de ser a do usuário (tela de bloqueio, login, UAC) (role='system').

Na tela segura do Windows um processo comum não captura imagem nem injeta teclado. Por isso o
aplicativo detecta a situação e entrega a sessão ao componente SYSTEM por um pedido curto
(renovado enquanto necessário); quando o Windows é desbloqueado, o aplicativo retoma sozinho.
"""
import base64, ctypes, hashlib, http.client, io, json, os, platform, ssl, threading, time, urllib.error, urllib.request
from urllib.parse import urlsplit
from ctypes import wintypes
from pathlib import Path

from arcoll_release import VERSION as REMOTE_VERSION
SECURE_PROTOCOL = 1
IS_WINDOWS = platform.system() == 'Windows'
PROGRAM_DATA = Path(os.environ.get('PROGRAMDATA', 'C:\\ProgramData')) / 'Arcoll'
REQUEST_DIR = PROGRAM_DATA / 'SecureRequests'      # usuários só criam o próprio pedido; não listam nem leem os de outros
STATUS_FILE = PROGRAM_DATA / 'SecureStatus' / 'helper.json'   # gravado pelo componente SYSTEM, somente leitura para usuários
REQUEST_TTL = 8.0                                  # segundos de validade de um pedido
HELPER_UPDATE_PREFIX = 'update-'                   # update-<sessão>.json: aviso para o componente SYSTEM se atualizar sozinho
HELPER_UNINSTALL_PREFIX = 'uninstall-'             # uninstall-<sessão>.json: ordem de desinstalação (revalidada pelo broker no servidor)
SAS_REQUEST_PREFIX = 'sas-'                        # sas-<sessão>.json: pedido de Ctrl+Alt+Del atendido pelo broker (sessão 0)
MAX_FRAME_BYTES = 360000


class SessionStopped(Exception):
    """O servidor encerrou a sessão remota (HTTP 409 com stop=true): não adianta repetir a chamada."""


# Conexão persistente: uma sessão remota envia vários quadros e consultas por segundo. Abrir uma conexão
# TCP/TLS nova para cada um deles esgota a tabela NAT de roteadores domésticos (a internet cai e só volta
# reiniciando o roteador). Com keepalive=True cada thread reaproveita a mesma conexão HTTPS.
KEEPALIVE_IDLE_LIMIT = 3.5                         # abaixo do tempo ocioso padrão dos servidores web (5 s)
_LINKS = threading.local()                         # conexões persistentes, uma por thread e destino
_PROXY_CACHE = {}
_TLS_CONTEXT = None
_TLS_LOCK = threading.Lock()


def _tls_context():
    global _TLS_CONTEXT
    with _TLS_LOCK:
        if _TLS_CONTEXT is None:
            _TLS_CONTEXT = ssl.create_default_context()
        return _TLS_CONTEXT


def _needs_proxy(parts):
    """Com proxy do sistema a conexão é gerida pelo urllib; sem proxy usamos a conexão persistente."""
    key = (parts.scheme, parts.hostname)
    known = _PROXY_CACHE.get(key)
    if known is None:
        try:
            known = bool(urllib.request.getproxies().get(parts.scheme)) and not urllib.request.proxy_bypass(parts.hostname)
        except Exception:
            known = True
        _PROXY_CACHE[key] = known
    return known


def _pooled_exchange(parts, method, body, headers, timeout):
    key = (parts.scheme, parts.hostname, parts.port)
    links = getattr(_LINKS, 'items', None)
    if links is None:
        links = _LINKS.items = {}
    target = (parts.path or '/') + ('?' + parts.query if parts.query else '')
    for attempt in (0, 1):
        entry = links.pop(key, None)
        conn = None
        reused = False
        if entry is not None:
            if time.monotonic() - entry[1] <= KEEPALIVE_IDLE_LIMIT:
                conn, reused = entry[0], True
            else:
                entry[0].close()
        if conn is None:
            if parts.scheme == 'https':
                conn = http.client.HTTPSConnection(parts.hostname, parts.port, timeout=timeout, context=_tls_context())
            else:
                conn = http.client.HTTPConnection(parts.hostname, parts.port, timeout=timeout)
        try:
            conn.timeout = timeout
            if conn.sock is not None:
                conn.sock.settimeout(timeout)
            conn.request(method, target, body=body, headers=headers)
            response = conn.getresponse()
            raw = response.read()
        except (http.client.RemoteDisconnected, OSError) as exc:
            conn.close()
            if reused and attempt == 0 and not isinstance(exc, TimeoutError):
                continue  # conexão ociosa já encerrada pelo servidor ou pela rede: abre outra e repete uma vez
            raise
        except BaseException:
            conn.close()
            raise
        if response.will_close:
            conn.close()
        else:
            links[key] = (conn, time.monotonic())
        return response.status, response.reason, response.msg, raw


def _exchange(url, body, headers, timeout, keepalive):
    parts = urlsplit(url)
    if keepalive and parts.scheme in ('http', 'https') and parts.hostname and not _needs_proxy(parts):
        status, reason, msg, raw = _pooled_exchange(parts, 'POST' if body is not None else 'GET', body, headers, timeout)
        if status < 300:
            return raw
        if status >= 400:
            raise urllib.error.HTTPError(url, status, reason, msg, io.BytesIO(raw))
        # redirecionamento: o urllib o acompanha com as mesmas regras de sempre
    req = urllib.request.Request(url, data=body, headers=headers, method='POST' if body is not None else 'GET')
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def request_json(url, data=None, timeout=8, headers=None, keepalive=False):
    body = None if data is None else json.dumps(data).encode()
    h = {'Accept': 'application/json', 'User-Agent': 'ArcollDesktop/2.0'}
    if body is not None:
        h['Content-Type'] = 'application/json'
    h.update(headers or {})
    try:
        return json.loads(_exchange(url, body, h, timeout, keepalive).decode('utf-8', 'replace'))
    except urllib.error.HTTPError as exc:
        if exc.code == 409:
            try:
                reply = json.loads(exc.read(4096).decode('utf-8', 'replace'))
            except (OSError, ValueError):
                reply = None
            if isinstance(reply, dict) and reply.get('stop'):
                raise SessionStopped(str(reply.get('error') or 'Sessão encerrada'))
        raise


# ---------------------------------------------------------------------------
# Áreas de trabalho do Windows
# ---------------------------------------------------------------------------
_USER32 = None


def _user32():
    global _USER32
    if _USER32 is None:
        u = ctypes.WinDLL('user32', use_last_error=True)
        u.OpenInputDesktop.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        u.OpenInputDesktop.restype = wintypes.HANDLE
        u.CloseDesktop.argtypes = [wintypes.HANDLE]
        u.CloseDesktop.restype = wintypes.BOOL
        u.SetThreadDesktop.argtypes = [wintypes.HANDLE]
        u.SetThreadDesktop.restype = wintypes.BOOL
        u.GetUserObjectInformationW.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD)]
        u.GetUserObjectInformationW.restype = wintypes.BOOL
        _USER32 = u
    return _USER32


class Desktop:
    """Consulta da área de trabalho que está recebendo a entrada do usuário."""
    DESKTOP_READOBJECTS = 0x0001
    UOI_NAME = 2

    @staticmethod
    def handle_name(handle):
        u = _user32()
        buf = ctypes.create_unicode_buffer(256)
        need = wintypes.DWORD(0)
        if u.GetUserObjectInformationW(handle, Desktop.UOI_NAME, buf, ctypes.sizeof(buf), ctypes.byref(need)):
            return buf.value
        return None

    @classmethod
    def input_name(cls):
        """'Default' na área de trabalho do usuário; 'Winlogon' (ou outra) na tela segura.
        None quando o Windows nega a consulta, o que ocorre justamente na tela segura."""
        if not IS_WINDOWS:
            return 'Default'
        u = _user32()
        handle = u.OpenInputDesktop(0, False, cls.DESKTOP_READOBJECTS)
        if not handle:
            return None
        try:
            return cls.handle_name(handle)
        finally:
            u.CloseDesktop(handle)

    @classmethod
    def is_user_desktop(cls):
        return (cls.input_name() or '').lower() == 'default'


class DesktopAttach:
    """Vincula a thread atual à área de trabalho de entrada (exige privilégios de SYSTEM).
    Cada thread de captura/entrada mantém a sua própria instância."""
    GENERIC_ALL = 0x10000000
    MAXIMUM_ALLOWED = 0x02000000

    def __init__(self):
        self.handle = None
        self.name = None

    def attach(self):
        u = _user32()
        handle = u.OpenInputDesktop(0, False, self.GENERIC_ALL) or u.OpenInputDesktop(0, False, self.MAXIMUM_ALLOWED)
        if not handle:
            raise OSError('Área de trabalho de entrada inacessível (erro %d)' % ctypes.get_last_error())
        name = Desktop.handle_name(handle)
        if self.handle and name == self.name:
            u.CloseDesktop(handle)
            return name
        if not u.SetThreadDesktop(handle):
            error = ctypes.get_last_error()
            u.CloseDesktop(handle)
            raise OSError('Não foi possível acompanhar a área de trabalho "%s" (erro %d)' % (name, error))
        previous, self.handle, self.name = self.handle, handle, name
        if previous:
            u.CloseDesktop(previous)
        return name

    def close(self):
        if self.handle:
            try:
                _user32().CloseDesktop(self.handle)
            except Exception:
                pass
        self.handle = None
        self.name = None


# ---------------------------------------------------------------------------
# Entrada de mouse e teclado
# ---------------------------------------------------------------------------
class RemoteInput:
    """Mouse e teclado do Windows via SendInput, usados somente na assistência remota autorizada."""
    VK = {'Enter': 0x0D, 'NumpadEnter': 0x0D, 'Escape': 0x1B, 'Backspace': 0x08, 'Tab': 0x09, 'Space': 0x20,
          'ArrowLeft': 0x25, 'ArrowUp': 0x26, 'ArrowRight': 0x27, 'ArrowDown': 0x28, 'Home': 0x24, 'End': 0x23,
          'PageUp': 0x21, 'PageDown': 0x22, 'Insert': 0x2D, 'Delete': 0x2E, 'ShiftLeft': 0xA0, 'ShiftRight': 0xA1,
          'ControlLeft': 0xA2, 'ControlRight': 0xA3, 'AltLeft': 0xA4, 'AltRight': 0xA5, 'MetaLeft': 0x5B, 'MetaRight': 0x5C,
          'ContextMenu': 0x5D, 'CapsLock': 0x14, 'NumLock': 0x90, 'ScrollLock': 0x91, 'Pause': 0x13, 'PrintScreen': 0x2C,
          'Semicolon': 0xBA, 'Equal': 0xBB, 'Comma': 0xBC, 'Minus': 0xBD, 'Period': 0xBE, 'Slash': 0xBF, 'Backquote': 0xC0,
          'BracketLeft': 0xDB, 'Backslash': 0xDC, 'BracketRight': 0xDD, 'Quote': 0xDE, 'IntlBackslash': 0xE2, 'IntlRo': 0xC1,
          'NumpadComma': 0xC2, 'NumpadMultiply': 0x6A, 'NumpadAdd': 0x6B, 'NumpadSubtract': 0x6D, 'NumpadDecimal': 0x6E,
          'NumpadDivide': 0x6F}
    EXTENDED = {0x25, 0x26, 0x27, 0x28, 0x24, 0x23, 0x21, 0x22, 0x2D, 0x2E, 0xA3, 0xA5, 0x5B, 0x5C, 0x5D, 0x90, 0x2C, 0x6F}
    COMBOS = {'win': [0x5B], 'win_r': [0x5B, 0x52], 'win_d': [0x5B, 0x44], 'win_e': [0x5B, 0x45], 'alt_tab': [0xA4, 0x09],
              'ctrl_shift_esc': [0xA2, 0xA0, 0x1B], 'alt_f4': [0xA4, 0x73], 'ctrl_c': [0xA2, 0x43], 'ctrl_v': [0xA2, 0x56],
              'ctrl_a': [0xA2, 0x41], 'ctrl_z': [0xA2, 0x5A], 'f5': [0x74], 'esc': [0x1B]}

    @staticmethod
    def thread_dpi_aware():
        """Torna só a thread atual consciente de DPI (pixels reais), sem alterar a interface do aplicativo."""
        try:
            fn = ctypes.windll.user32.SetThreadDpiAwarenessContext
            fn.argtypes = [ctypes.c_void_p]
            fn.restype = ctypes.c_void_p
            fn(ctypes.c_void_p(-4))  # DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2
            return True
        except Exception:
            return False

    @staticmethod
    def cursor_pos():
        try:
            pt = wintypes.POINT()
            if ctypes.windll.user32.GetCursorPos(ctypes.byref(pt)):
                return (int(pt.x), int(pt.y))
        except Exception:
            pass
        return None

    def __init__(self):
        self.ct = ctypes
        self.user32 = ctypes.windll.user32

        class KEYBDINPUT(ctypes.Structure):
            _fields_ = [('wVk', wintypes.WORD), ('wScan', wintypes.WORD), ('dwFlags', wintypes.DWORD), ('time', wintypes.DWORD), ('dwExtraInfo', ctypes.c_size_t)]

        class MOUSEINPUT(ctypes.Structure):
            _fields_ = [('dx', wintypes.LONG), ('dy', wintypes.LONG), ('mouseData', wintypes.DWORD), ('dwFlags', wintypes.DWORD), ('time', wintypes.DWORD), ('dwExtraInfo', ctypes.c_size_t)]

        class HARDWAREINPUT(ctypes.Structure):
            _fields_ = [('uMsg', wintypes.DWORD), ('wParamL', wintypes.WORD), ('wParamH', wintypes.WORD)]

        class _UNION(ctypes.Union):
            _fields_ = [('mi', MOUSEINPUT), ('ki', KEYBDINPUT), ('hi', HARDWAREINPUT)]

        class INPUT(ctypes.Structure):
            _fields_ = [('type', wintypes.DWORD), ('u', _UNION)]

        self.KEYBDINPUT, self.MOUSEINPUT, self.INPUT = KEYBDINPUT, MOUSEINPUT, INPUT
        self.user32.SendInput.argtypes = [wintypes.UINT, ctypes.POINTER(INPUT), ctypes.c_int]
        self.user32.SendInput.restype = wintypes.UINT
        self.user32.SetCursorPos.argtypes = [ctypes.c_int, ctypes.c_int]
        self.user32.MapVirtualKeyW.argtypes = [wintypes.UINT, wintypes.UINT]
        self.user32.MapVirtualKeyW.restype = wintypes.UINT
        self.keys_down = set()
        self.buttons_down = set()

    def _send(self, inp):
        self.user32.SendInput(1, self.ct.byref(inp), self.ct.sizeof(self.INPUT))

    def _mouse(self, flags, data=0):
        inp = self.INPUT(type=0)
        inp.u.mi = self.MOUSEINPUT(0, 0, data & 0xFFFFFFFF, flags, 0, 0)
        self._send(inp)

    def _key(self, vk, down, scan=0, flags=0):
        inp = self.INPUT(type=1)
        inp.u.ki = self.KEYBDINPUT(vk, scan, flags | (0 if down else 2), 0, 0)
        self._send(inp)

    def screen_size(self):
        return max(1, self.user32.GetSystemMetrics(0)), max(1, self.user32.GetSystemMetrics(1))

    def _move_to(self, x, y):
        """Move o cursor por entrada real (SendInput absoluto). SetCursorPos só reposiciona o desenho do cursor:
        não reinicia a inatividade, não acorda a tela e a tela de bloqueio/login não reage a ele."""
        x, y = int(x), int(y)
        try:
            gm = self.user32.GetSystemMetrics
            vx, vy, vw, vh = gm(76), gm(77), max(1, gm(78)), max(1, gm(79))  # SM_*VIRTUALSCREEN
            ax = max(0, min(65535, ((x - vx) * 65535) // max(1, vw - 1)))
            ay = max(0, min(65535, ((y - vy) * 65535) // max(1, vh - 1)))
            inp = self.INPUT(type=0)
            inp.u.mi = self.MOUSEINPUT(ax, ay, 0, 0x0001 | 0x4000 | 0x8000, 0, 0)  # MOVE | VIRTUALDESK | ABSOLUTE
            if self.user32.SendInput(1, self.ct.byref(inp), self.ct.sizeof(self.INPUT)) == 1:
                return
        except Exception:
            pass
        self.user32.SetCursorPos(x, y)  # alternativa se o SendInput for recusado

    def wake(self):
        """Pequeno movimento relativo (+1/-1 px) para acordar a tela e reiniciar a inatividade."""
        for dx in (1, -1):
            inp = self.INPUT(type=0)
            inp.u.mi = self.MOUSEINPUT(dx, 0, 0, 0x0001, 0, 0)  # MOVE relativo
            self._send(inp)

    def move(self, nx, ny):
        w, h = self.screen_size()
        self._move_to(int(max(0.0, min(1.0, float(nx))) * (w - 1)), int(max(0.0, min(1.0, float(ny))) * (h - 1)))

    def move_in_rect(self, nx, ny, bounds=None):
        if bounds and len(bounds) == 4:
            left, top, right, bottom = [int(v) for v in bounds]
            width = max(1, right - left)
            height = max(1, bottom - top)
            x = left + int(max(0.0, min(1.0, float(nx))) * (width - 1))
            y = top + int(max(0.0, min(1.0, float(ny))) * (height - 1))
            self._move_to(x, y)
            return
        self.move(nx, ny)

    def button(self, b, down):
        b = b if b in (0, 1, 2) else 0
        down_flag, up_flag = {0: (0x0002, 0x0004), 1: (0x0020, 0x0040), 2: (0x0008, 0x0010)}[b]
        if down:
            self.buttons_down.add(b)
        else:
            self.buttons_down.discard(b)
        self._mouse(down_flag if down else up_flag)

    def wheel(self, dx, dy):
        dy = int(dy)
        dx = int(dx)
        if dy:
            self._mouse(0x0800, -dy * 120)
        if dx:
            self._mouse(0x1000, dx * 120)

    def vk_for(self, code):
        if code in self.VK:
            return self.VK[code]
        if len(code) == 4 and code.startswith('Key') and code[3].isalpha():
            return ord(code[3].upper())
        if len(code) == 6 and code.startswith('Digit') and code[5].isdigit():
            return ord(code[5])
        if code.startswith('Numpad') and len(code) == 7 and code[6].isdigit():
            return 0x60 + int(code[6])
        if code.startswith('F') and code[1:].isdigit() and 1 <= int(code[1:]) <= 24:
            return 0x70 + int(code[1:]) - 1
        return None

    def key(self, code, down):
        vk = self.vk_for(code)
        if vk is None:
            return
        self.vk_event(vk, down)

    def vk_event(self, vk, down):
        scan = self.user32.MapVirtualKeyW(vk, 0)
        self._key(vk, down, scan, 0x0001 if vk in self.EXTENDED else 0)
        if down:
            self.keys_down.add(vk)
        else:
            self.keys_down.discard(vk)

    def combo(self, name):
        seq = self.COMBOS.get(name)
        if not seq:
            return
        for vk in seq:
            self.vk_event(vk, True)
        for vk in reversed(seq):
            self.vk_event(vk, False)

    def text(self, value):
        for ch in str(value)[:400]:
            if ch == '\r':
                continue
            if ch == '\n':
                self.vk_event(0x0D, True)
                self.vk_event(0x0D, False)
                continue
            if ch == '\t':
                self.vk_event(0x09, True)
                self.vk_event(0x09, False)
                continue
            raw = ch.encode('utf-16-le')
            for i in range(0, len(raw), 2):
                unit = raw[i] | (raw[i + 1] << 8)
                self._key(0, True, unit, 0x0004)
                self._key(0, False, unit, 0x0004)

    def has_pressed(self):
        return bool(self.keys_down or self.buttons_down)

    def release_all(self):
        for vk in list(self.keys_down):
            try:
                self.vk_event(vk, False)
            except Exception:
                pass
        for b in list(self.buttons_down):
            try:
                self.button(b, False)
            except Exception:
                pass

    def apply(self, ev, bounds=None):
        t = ev.get('t')
        if t == 'm':
            self.move_in_rect(ev.get('x', 0), ev.get('y', 0), bounds)
        elif t in ('d', 'u'):
            self.move_in_rect(ev.get('x', 0), ev.get('y', 0), bounds)
            self.button(int(ev.get('b', 0)), t == 'd')
        elif t == 'w':
            self.wheel(ev.get('dx', 0), ev.get('dy', 0))
        elif t == 'k':
            self.key(str(ev.get('c', '')), bool(ev.get('d')))
        elif t == 'x':
            self.text(ev.get('s', ''))
        elif t == 'c':
            self.combo(str(ev.get('n', '')))


# ---------------------------------------------------------------------------
# Monitores
# ---------------------------------------------------------------------------
def list_monitors(fallback_size=(1280, 720)):
    """Lista os monitores do Windows em ordem estável para o acesso remoto."""
    width, height = max(1, int(fallback_size[0])), max(1, int(fallback_size[1]))
    single = [{'id': '1', 'label': 'Tela 1', 'left': 0, 'top': 0, 'right': width, 'bottom': height, 'width': width, 'height': height, 'primary': True}]
    if not IS_WINDOWS:
        return single
    try:
        user32 = ctypes.windll.user32

        class RECT(ctypes.Structure):
            _fields_ = [('left', wintypes.LONG), ('top', wintypes.LONG), ('right', wintypes.LONG), ('bottom', wintypes.LONG)]

        class MONITORINFOEXW(ctypes.Structure):
            _fields_ = [('cbSize', wintypes.DWORD), ('rcMonitor', RECT), ('rcWork', RECT), ('dwFlags', wintypes.DWORD), ('szDevice', wintypes.WCHAR * 32)]

        monitors = []
        CALLBACK = ctypes.WINFUNCTYPE(ctypes.c_int, wintypes.HANDLE, wintypes.HDC, ctypes.POINTER(RECT), wintypes.LPARAM)

        def _enum(hmon, _hdc, _lprc, _lparam):
            info = MONITORINFOEXW()
            info.cbSize = ctypes.sizeof(info)
            if user32.GetMonitorInfoW(hmon, ctypes.byref(info)):
                left, top, right, bottom = int(info.rcMonitor.left), int(info.rcMonitor.top), int(info.rcMonitor.right), int(info.rcMonitor.bottom)
                if right > left and bottom > top:
                    monitors.append({'left': left, 'top': top, 'right': right, 'bottom': bottom, 'width': right - left, 'height': bottom - top,
                                     'primary': bool(info.dwFlags & 1), 'device': str(info.szDevice or '').strip()})
            return 1

        user32.EnumDisplayMonitors(0, 0, CALLBACK(_enum), 0)
        if not monitors:
            raise RuntimeError('sem monitores enumerados')
        monitors.sort(key=lambda m: (0 if m.get('primary') else 1, m.get('top', 0), m.get('left', 0), m.get('device', '')))
        for idx, monitor in enumerate(monitors, 1):
            monitor['id'] = str(idx)
            monitor['label'] = 'Tela ' + str(idx)
        return monitors
    except Exception:
        return single


def _monitor_payload(items):
    return [{'id': str(i.get('id') or ''), 'label': str(i.get('label') or ''), 'width': int(i.get('width') or 0),
             'height': int(i.get('height') or 0), 'primary': bool(i.get('primary'))} for i in items]


def _choose_monitor(monitors, wanted=''):
    chosen = None
    for item in monitors:
        if wanted and str(item.get('id')) == str(wanted):
            chosen = item
            break
    if chosen is None:
        for item in monitors:
            if item.get('primary'):
                chosen = item
                break
    return chosen or (monitors[0] if monitors else None)


# ---------------------------------------------------------------------------
# Ponte com o componente SYSTEM (lado do aplicativo)
# ---------------------------------------------------------------------------
def current_session_id():
    try:
        sid = wintypes.DWORD(0)
        if ctypes.windll.kernel32.ProcessIdToSessionId(os.getpid(), ctypes.byref(sid)):
            return int(sid.value)
    except Exception:
        pass
    return 0


def console_session_id():
    try:
        return int(ctypes.windll.kernel32.WTSGetActiveConsoleSessionId())
    except Exception:
        return None


def helper_info():
    """Status publicado pelo componente SYSTEM (versão, capacidades) ou None se não estiver ativo."""
    try:
        data = json.loads(STATUS_FILE.read_text(encoding='utf-8'))
        if int(data.get('protocol', 0)) == SECURE_PROTOCOL and abs(time.time() - float(data.get('ts', 0))) <= 6.0:
            return data
    except (OSError, ValueError, TypeError):
        pass
    return None


def request_helper_update(creds):
    """Avisa o componente SYSTEM para baixar e validar, por conta própria, a versão publicada no servidor.

    O aviso não carrega código: o componente só usa as credenciais para falar com a origem HTTPS gravada em
    local protegido, confere o SHA-256 de cada arquivo e testa o código novo antes de trocá-lo.
    """
    if not helper_info():
        return False
    session = current_session_id()
    console = console_session_id()
    if console is not None and console != session:
        return False
    payload = {'protocol': SECURE_PROTOCOL, 'session': session, 'ts': time.time(),
               'creds': {k: str(creds.get(k) or '') for k in ('fingerprint', 'site_code', 'download_key')}}
    path = REQUEST_DIR / ('%s%d.json' % (HELPER_UPDATE_PREFIX, session))
    try:
        path.write_text(json.dumps(payload), encoding='utf-8')
    except OSError:
        return False
    return True


def request_helper_uninstall(creds):
    """Pede ao componente SYSTEM que se desinstale por completo, sem interação com o usuário da máquina.

    O pedido não é uma ordem: o broker consulta o servidor com as credenciais da instalação e só remove o
    agente quando a plataforma confirmar que a desinstalação foi solicitada (ou que a rede foi excluída).
    """
    info = helper_info()
    if not info or not info.get('remote_uninstall'):
        return False
    session = current_session_id()
    payload = {'protocol': SECURE_PROTOCOL, 'session': session, 'ts': time.time(),
               'creds': {k: str(creds.get(k) or '') for k in ('fingerprint', 'site_code', 'download_key')}}
    path = REQUEST_DIR / ('%s%d.json' % (HELPER_UNINSTALL_PREFIX, session))
    try:
        path.write_text(json.dumps(payload), encoding='utf-8')
    except OSError:
        return False
    return True


def request_sas(command_id=0):
    """Pede ao broker SYSTEM (sessão 0) que envie Ctrl+Alt+Del (SAS) ao Winlogon.

    SendSAS só é aceito de um processo da sessão 0 (serviço/SYSTEM). O aplicativo (sessão do usuário) e o
    worker (sessão do console) não podem chamá-lo, por isso o pedido segue por arquivo, igual ao handoff.
    """
    if not SecureDesktopBridge.helper_available():
        raise RuntimeError('Componente da tela segura não está ativo; reinstale o agente como administrador.')
    session = current_session_id()
    console = console_session_id()
    if console is not None and console != session:
        raise RuntimeError('Ctrl+Alt+Del só é atendido na sessão do console (não em sessões RDP).')
    payload = {'protocol': SECURE_PROTOCOL, 'command_id': int(command_id or 0), 'session': session, 'ts': time.time()}
    path = REQUEST_DIR / ('%s%d.json' % (SAS_REQUEST_PREFIX, session))
    try:
        path.write_text(json.dumps(payload), encoding='utf-8')
    except OSError as exc:
        raise RuntimeError('Não foi possível pedir Ctrl+Alt+Del ao componente seguro: %s' % str(exc)[:100])


class SecureDesktopBridge:
    """Entrega a sessão ao componente SYSTEM enquanto a tela segura do Windows estiver ativa."""

    def __init__(self, command_id, creds):
        self.command_id = int(command_id)
        self.creds = {k: str(creds.get(k) or '') for k in ('fingerprint', 'site_code', 'download_key')}
        self.session = current_session_id()
        self.path = REQUEST_DIR / ('request-%d.json' % self.session)
        self._last_write = 0.0
        self._last_control = None
        self.active = False

    @staticmethod
    def helper_available():
        try:
            data = json.loads(STATUS_FILE.read_text(encoding='utf-8'))
            return int(data.get('protocol', 0)) == SECURE_PROTOCOL and abs(time.time() - float(data.get('ts', 0))) <= 6.0
        except (OSError, ValueError, TypeError):
            return False

    def handoff(self, control):
        """Mantém o pedido vigente. Retorna False quando o componente não está instalado/ativo."""
        if not self.helper_available():
            return False
        console = console_session_id()
        if console is not None and console != self.session:
            return False  # o componente só atende a sessão do console (não atende sessões RDP)
        control = bool(control)
        now = time.time()
        if control != self._last_control or now - self._last_write >= 1.0 or not self.active:
            payload = {'protocol': SECURE_PROTOCOL, 'command_id': self.command_id, 'control': control, 'session': self.session, 'ts': now, 'creds': self.creds}
            try:
                self.path.write_text(json.dumps(payload), encoding='utf-8')
            except OSError:
                return False
            self._last_write, self._last_control = now, control
        self.active = True
        return True

    def release(self):
        self.active = False
        self._last_control = None
        try:
            self.path.unlink()
        except OSError:
            pass


# ---------------------------------------------------------------------------
# Sessão de tela remota
# ---------------------------------------------------------------------------
class EphemeralUnlockKey:
    """Windows CNG RSA-OAEP; private key never leaves this worker's memory."""
    def __init__(self):
        import struct
        self.api=ctypes.WinDLL('bcrypt',use_last_error=True);self.algorithm=ctypes.c_void_p();self.key=ctypes.c_void_p()
        self.api.BCryptOpenAlgorithmProvider.argtypes=[ctypes.POINTER(ctypes.c_void_p),wintypes.LPCWSTR,wintypes.LPCWSTR,wintypes.ULONG]
        self.api.BCryptGenerateKeyPair.argtypes=[ctypes.c_void_p,ctypes.POINTER(ctypes.c_void_p),wintypes.ULONG,wintypes.ULONG]
        self.api.BCryptFinalizeKeyPair.argtypes=[ctypes.c_void_p,wintypes.ULONG]
        self.api.BCryptExportKey.argtypes=[ctypes.c_void_p,ctypes.c_void_p,wintypes.LPCWSTR,ctypes.c_void_p,wintypes.ULONG,ctypes.POINTER(wintypes.ULONG),wintypes.ULONG]
        self.api.BCryptDecrypt.argtypes=[ctypes.c_void_p,ctypes.c_void_p,wintypes.ULONG,ctypes.c_void_p,ctypes.c_void_p,wintypes.ULONG,ctypes.c_void_p,wintypes.ULONG,ctypes.POINTER(wintypes.ULONG),wintypes.ULONG]
        self.api.BCryptDestroyKey.argtypes=[ctypes.c_void_p];self.api.BCryptCloseAlgorithmProvider.argtypes=[ctypes.c_void_p,wintypes.ULONG]
        try:
            self.check(self.api.BCryptOpenAlgorithmProvider(ctypes.byref(self.algorithm),'RSA',None,0))
            self.check(self.api.BCryptGenerateKeyPair(self.algorithm,ctypes.byref(self.key),2048,0));self.check(self.api.BCryptFinalizeKeyPair(self.key,0))
            size=wintypes.ULONG();self.check(self.api.BCryptExportKey(self.key,None,'RSAPUBLICBLOB',None,0,ctypes.byref(size),0))
            output=ctypes.create_string_buffer(size.value);self.check(self.api.BCryptExportKey(self.key,None,'RSAPUBLICBLOB',output,size.value,ctypes.byref(size),0))
            blob=output.raw[:size.value];magic,bits,exponent,modulus,_,_=struct.unpack('<6I',blob[:24])
            if magic!=0x31415352 or bits!=2048:raise RuntimeError('Chave de desbloqueio inválida.')
            encode=lambda value:base64.urlsafe_b64encode(value).decode().rstrip('=')
            self.ident=os.urandom(16).hex();self.expires=time.time()+90
            self.public={'id':self.ident,'expires':self.expires,'jwk':{'kty':'RSA','n':encode(blob[24+exponent:24+exponent+modulus]),'e':encode(blob[24:24+exponent]),'alg':'RSA-OAEP-256','ext':True}}
        except Exception:self.close();raise
    @staticmethod
    def check(status):
        if status:raise RuntimeError('O Windows não disponibilizou a criptografia de desbloqueio.')
    def decrypt(self,data):
        class Padding(ctypes.Structure):_fields_=[('algorithm',wintypes.LPCWSTR),('label',ctypes.c_void_p),('length',wintypes.ULONG)]
        padding=Padding('SHA256',None,0);source=ctypes.create_string_buffer(data);out=ctypes.create_string_buffer(256);size=wintypes.ULONG()
        self.check(self.api.BCryptDecrypt(self.key,source,len(data),ctypes.byref(padding),None,0,out,256,ctypes.byref(size),4))
        return out.raw[:size.value]
    def consume(self,envelope,command):
        if envelope.get('key_id')!=self.ident or time.time()>self.expires or not 0<=float(envelope.get('expires',0))-time.time()<=30:raise ValueError('Pedido de desbloqueio expirado.')
        blocks=envelope.get('blocks');plain=bytearray()
        if not isinstance(blocks,list) or not 1<=len(blocks)<=32:raise ValueError('Pedido de desbloqueio inválido.')
        try:
            for index,block in enumerate(blocks):
                encrypted=base64.b64decode(block,validate=True)
                if len(encrypted)!=256:raise ValueError('Pedido de desbloqueio inválido.')
                decoded=self.decrypt(encrypted);prefix=('%s:%d:%d:%d:'%(self.ident,command,index,len(blocks))).encode()
                if not decoded.startswith(prefix):raise ValueError('Pedido de desbloqueio fora da sessão.')
                plain.extend(decoded[len(prefix):])
                if len(plain)>4096:raise ValueError('Credenciais excedem o limite.')
            data=json.loads(plain.decode('utf-8'))
            if not isinstance(data,dict) or set(data)!=set(('username','password')) or not all(isinstance(data[k],str) and 1<=len(data[k])<=256 and not any(ord(c)<32 or ord(c)==127 for c in data[k]) for k in data):raise ValueError('Credenciais inválidas.')
            return data
        finally:
            for i in range(len(plain)):plain[i]=0
            self.close()
    def close(self):
        if getattr(self,'key',None):self.api.BCryptDestroyKey(self.key);self.key=None
        if getattr(self,'algorithm',None):self.api.BCryptCloseAlgorithmProvider(self.algorithm,0);self.algorithm=None


def unlock_windows(credentials,stop,command):
    """Only identified LogonUI password controls receive input. No shell argument carries credentials."""
    import subprocess
    if Desktop.is_user_desktop():return 'unlocked'
    request_sas(command)
    if stop.wait(1.5):raise RuntimeError('Atendimento encerrado.')
    encoded=base64.b64encode(UNLOCK_POWERSHELL.encode('utf-16-le')).decode()
    body=json.dumps(credentials,ensure_ascii=False).encode('utf-8')
    try:
        executable=os.path.join(os.environ.get('SystemRoot',r'C:\Windows'),'System32','WindowsPowerShell','v1.0','powershell.exe')
        process=subprocess.Popen([executable,'-NoProfile','-NonInteractive','-EncodedCommand',encoded],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        deadline=time.monotonic()+25;pending=body
        while True:
            try:
                output,_=process.communicate(input=pending,timeout=.25);break
            except subprocess.TimeoutExpired:
                pending=None
                if stop.is_set() or time.monotonic()>deadline:
                    process.kill();process.communicate();raise RuntimeError('Desbloqueio interrompido ou expirado.')
        if process.returncode or output.strip()!=b'SUBMITTED':raise RuntimeError('Tela de login incompatível ou usuário não identificado. Use o acesso remoto para concluir.')
        for _ in range(20):
            if stop.wait(.4):raise RuntimeError('Atendimento encerrado.')
            if Desktop.is_user_desktop():return 'unlocked'
        raise RuntimeError('O Windows não confirmou o desbloqueio. Confira as credenciais na tela remota.')
    finally:body=b'';credentials.clear()


UNLOCK_POWERSHELL = r'''
$ErrorActionPreference='Stop'
[Console]::InputEncoding=[Text.UTF8Encoding]::new($false)
$inputData=[Console]::In.ReadToEnd() | ConvertFrom-Json
Add-Type -ReferencedAssemblies System,UIAutomationClient,UIAutomationTypes,WindowsBase -TypeDefinition @'
using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Threading;
using System.Windows.Automation;
public static class ArcollLogin {
 [DllImport("user32.dll")] static extern IntPtr OpenInputDesktop(uint flags,bool inherit,uint access);
 [DllImport("user32.dll")] static extern bool SetThreadDesktop(IntPtr desktop);
 [DllImport("user32.dll")] static extern IntPtr GetThreadDesktop(uint id);
 [DllImport("kernel32.dll")] static extern uint GetCurrentThreadId();
 [DllImport("user32.dll")] static extern bool CloseDesktop(IntPtr desktop);
 [DllImport("user32.dll")] static extern uint SendInput(uint count,Input[] inputs,int size);
 [DllImport("wtsapi32.dll",CharSet=CharSet.Unicode)] static extern bool WTSQuerySessionInformation(IntPtr server,int session,int info,out IntPtr buffer,out int size);
 [DllImport("wtsapi32.dll")] static extern void WTSFreeMemory(IntPtr buffer);
 [StructLayout(LayoutKind.Sequential)] struct Key {public ushort vk,scan;public uint flags,time;public UIntPtr extra;}
 [StructLayout(LayoutKind.Sequential)] struct Mouse {public int x,y;public uint data,flags,time;public UIntPtr extra;}
 [StructLayout(LayoutKind.Explicit)] struct Union {[FieldOffset(0)]public Key key;[FieldOffset(0)]public Mouse mouse;}
 [StructLayout(LayoutKind.Sequential)] struct Input {public uint type;public Union u;}
 static void KeyEvent(ushort vk,ushort scan,uint flags){Input i=new Input();i.type=1;i.u.key.vk=vk;i.u.key.scan=scan;i.u.key.flags=flags;if(SendInput(1,new Input[]{i},Marshal.SizeOf(typeof(Input)))!=1)throw new Exception();}
 static void Type(string value){KeyEvent(17,0,0);KeyEvent(65,0,0);KeyEvent(65,0,2);KeyEvent(17,0,2);foreach(char c in value){KeyEvent(0,c,4);KeyEvent(0,c,6);}}
 static string SessionInfo(int info){IntPtr p;int n;if(!WTSQuerySessionInformation(IntPtr.Zero,Process.GetCurrentProcess().SessionId,info,out p,out n))return "";try{return Marshal.PtrToStringUni(p)??"";}finally{WTSFreeMemory(p);}}
 public static bool Submit(string username,string password){bool ok=false;Thread t=new Thread(()=>{
  IntPtr desktop=IntPtr.Zero,original=GetThreadDesktop(GetCurrentThreadId());
  try{
   desktop=OpenInputDesktop(0,false,0x02000000);if(desktop==IntPtr.Zero||!SetThreadDesktop(desktop))return;
   Process logon=null;foreach(Process p in Process.GetProcessesByName("LogonUI"))if(p.SessionId==Process.GetCurrentProcess().SessionId){if(logon!=null)return;logon=p;}
   if(logon==null)return;
   AutomationElement pass=null,user=null;
   for(int attempt=0;attempt<15;attempt++){
    AutomationElementCollection edits=AutomationElement.RootElement.FindAll(TreeScope.Descendants,new AndCondition(new PropertyCondition(AutomationElement.ProcessIdProperty,logon.Id),new PropertyCondition(AutomationElement.ControlTypeProperty,ControlType.Edit),new PropertyCondition(AutomationElement.IsOffscreenProperty,false),new PropertyCondition(AutomationElement.IsEnabledProperty,true)));
    int passwords=0,users=0;pass=null;user=null;
    foreach(AutomationElement e in edits){if(!e.Current.IsKeyboardFocusable)continue;if(e.Current.IsPassword){pass=e;passwords++;}else{user=e;users++;}}
    if(passwords==1&&users<=1)break;pass=null;Thread.Sleep(400);
   }
   if(pass==null)return;
   string passName=(pass.Current.Name??"").ToLowerInvariant(),passId=(pass.Current.AutomationId??"").ToLowerInvariant();
   if(passName.Contains("pin")||(!passName.Contains("password")&&!passName.Contains("senha")&&!passId.Contains("password")))return;
   if(user!=null){user.SetFocus();AutomationElement f=AutomationElement.FocusedElement;if(f.Current.ProcessId!=logon.Id||f.Current.IsPassword)return;Type(username);}
   else{string actual=SessionInfo(5),domain=SessionInfo(7);if(actual.Length==0||(!String.Equals(username,actual,StringComparison.OrdinalIgnoreCase)&&!String.Equals(username,domain+"\\"+actual,StringComparison.OrdinalIgnoreCase)&&!(String.Equals(domain,Environment.MachineName,StringComparison.OrdinalIgnoreCase)&&String.Equals(username,".\\"+actual,StringComparison.OrdinalIgnoreCase))))return;}
   pass.SetFocus();AutomationElement focused=AutomationElement.FocusedElement;if(focused.Current.ProcessId!=logon.Id||!focused.Current.IsPassword)return;
   Type(password);focused=AutomationElement.FocusedElement;if(focused.Current.ProcessId!=logon.Id||!focused.Current.IsPassword)return;
   KeyEvent(13,0,0);KeyEvent(13,0,2);ok=true;
  }catch{ok=false;}finally{try{KeyEvent(17,0,2);}catch{}if(original!=IntPtr.Zero)SetThreadDesktop(original);if(desktop!=IntPtr.Zero)CloseDesktop(desktop);}
 });t.SetApartmentState(ApartmentState.STA);t.Start();if(!t.Join(15000))return false;return ok;}
}
'@
try {if([ArcollLogin]::Submit([string]$inputData.username,[string]$inputData.password)){[Console]::Out.Write('SUBMITTED');exit 0};exit 2}finally{$inputData=$null}
'''


def local_windows_users():
    """Enumerate enabled local Windows accounts without retrieving credentials."""
    if not IS_WINDOWS:return []
    class UserInfo(ctypes.Structure):
        _fields_=[('name',wintypes.LPWSTR),('password',wintypes.LPWSTR),
                  ('password_age',wintypes.DWORD),('priv',wintypes.DWORD),
                  ('home_dir',wintypes.LPWSTR),('comment',wintypes.LPWSTR),
                  ('flags',wintypes.DWORD),('script_path',wintypes.LPWSTR)]
    api=ctypes.WinDLL('Netapi32.dll')
    enum=api.NetUserEnum
    enum.argtypes=[wintypes.LPCWSTR,wintypes.DWORD,wintypes.DWORD,
                   ctypes.POINTER(ctypes.c_void_p),wintypes.DWORD,
                   ctypes.POINTER(wintypes.DWORD),ctypes.POINTER(wintypes.DWORD),
                   ctypes.POINTER(wintypes.DWORD)]
    enum.restype=wintypes.DWORD
    free=api.NetApiBufferFree;free.argtypes=[ctypes.c_void_p];free.restype=wintypes.DWORD
    resume=wintypes.DWORD();users=[]
    for _ in range(16):
        buffer=ctypes.c_void_p();read=wintypes.DWORD();total=wintypes.DWORD()
        status=enum(None,1,2,ctypes.byref(buffer),65536,ctypes.byref(read),ctypes.byref(total),ctypes.byref(resume))
        try:
            if status not in (0,234):raise OSError(status,'Não foi possível consultar os usuários locais do Windows.')
            rows=ctypes.cast(buffer,ctypes.POINTER(UserInfo))
            for i in range(read.value):
                row=rows[i];name=row.name or ''
                if name and not row.flags & 2 and len(name)<=256:
                    users.append({'username':'.\\'+name,'label':name})
                    if len(users)>=100:return sorted(users,key=lambda u:u['label'].casefold())
        finally:
            if buffer:free(buffer)
        if status==0:return sorted(users,key=lambda u:u['label'].casefold())
    raise RuntimeError('A lista de usuários excedeu o limite de consulta.')


class RemoteScreenSession:
    """Captura de tela e entrada remota de uma sessão autorizada (comando remote_assistance)."""
    QUALITY = {'low': (960, 600, 40), 'balanced': (1280, 800, 55), 'high': (1600, 1000, 75)}
    # Teto de envio contínuo (bytes/s) por qualidade: evita saturar o upload do local e o buffer do roteador.
    BANDWIDTH = {'low': 200000, 'balanced': 600000, 'high': 1000000}

    def __init__(self, creds, base_url, stop_event, control_event, role='user', bridge=None, request_path=None,
                 expected_command=None, on_error=None, on_finish=None, fallback_size=(1280, 720)):
        self.unlock_lock=threading.RLock();self.unlock_box=None;self.unlock_busy=False;self.unlock_result={};self.local_users=[];self.local_users_checked=0;self.local_users_error=False
        self.creds = creds
        self.base = base_url
        self.stop = stop_event
        self.control = control_event
        self.role = role
        self.bridge = bridge
        self.request_path = request_path
        self.expected_command = expected_command
        self.on_error = on_error or (lambda _m: None)
        self.on_finish = on_finish
        self.fallback_size = fallback_size
        self.capture_unavailable = threading.Event()  # suspende a entrada quando a tela não pode ser capturada
        self.view = {'selected_id': '', 'bounds': None, 'monitors': []}
        self.view_lock = threading.Lock()
        self.activity = 0.0
        self._placeholder_sent = False
        self._delegated = False
        self._bad_reads = 0

    def start(self):
        self.threads=[threading.Thread(target=self._input_loop,daemon=True),threading.Thread(target=self._capture_loop,daemon=True)]
        for thread in self.threads:thread.start()

    def _error(self, message):
        try:
            self.on_error(message)
        except Exception:
            pass

    # -- pedido do aplicativo (somente role='system') ------------------------
    def _sync_request(self):
        """Falso quando o pedido sumiu, expirou ou pertence a outro comando: o componente deve encerrar."""
        try:
            data = json.loads(Path(self.request_path).read_text(encoding='utf-8'))
            self._bad_reads = 0
        except (OSError, ValueError):
            self._bad_reads += 1
            return self._bad_reads < 4  # tolera leitura durante a gravação
        if not isinstance(data, dict) or time.time() - float(data.get('ts') or 0) > REQUEST_TTL:
            return False
        if self.expected_command is not None and int(data.get('command_id') or 0) != int(self.expected_command):
            return False
        if data.get('control'):
            self.control.set()
        else:
            self.control.clear()
        return True

    def _keepalive(self, control):
        payload=dict(self.creds,control=bool(control),keepalive=True)
        if not Desktop.is_user_desktop():payload['desktop_state']='locked'
        reply = request_json(self.base + 'frame',payload,timeout=10,keepalive=True)
        return bool(reply.get('ok')) and not reply.get('stop')

    # -- entrada --------------------------------------------------------------
    def unlock_metadata(self):
        unlocked=Desktop.is_user_desktop()
        result={'desktop_state':'unlocked' if unlocked else 'locked','unlock_result':self.unlock_result}
        with self.unlock_lock:
            if self.role=='system' and not unlocked and self.control.is_set() and not self.unlock_busy and not self.stop.is_set():
                if self.unlock_box is None or self.unlock_box.expires<time.time()+3:
                    if self.unlock_box:self.unlock_box.close()
                    self.unlock_box=EphemeralUnlockKey()
                result['unlock_key']=self.unlock_box.public
                if time.monotonic()-self.local_users_checked>30:
                    try:self.local_users=local_windows_users();self.local_users_error=False
                    except Exception:self.local_users=[];self.local_users_error=True
                    self.local_users_checked=time.monotonic()
                result['windows_users']=self.local_users
                result['windows_users_error']=self.local_users_error
        return result

    def handle_unlock(self,event):
        if self.role!='system' or not self.control.is_set() or self.stop.is_set():return
        command=int(self.expected_command or 0);credentials=None;ident=event.get('request_id');box=None
        try:
            with self.unlock_lock:
                if self.unlock_busy or self.unlock_box is None:return
                self.unlock_busy=True;box=self.unlock_box;self.unlock_box=None
                self.unlock_result={'request_id':ident,'status':'pending'}
                credentials=box.consume(event,command)
            reply=request_json(self.base+'unlock-authorize',dict(self.creds,request_id=ident,key_id=event.get('key_id')),timeout=6,keepalive=True)
            if not reply.get('ok') or self.stop.is_set():raise PermissionError('Autorização de desbloqueio revogada.')
            status=unlock_windows(credentials,self.stop,command)
            self.unlock_result={'request_id':ident,'status':status}
        except Exception:
            self.unlock_result={'request_id':ident,'status':'failed'}
        finally:
            if box:box.close()
            if credentials:credentials.clear()
            self.unlock_busy=False

    def _input_loop(self):
        rin = None
        attach = DesktopAttach() if self.role == 'system' else None
        try:
            RemoteInput.thread_dpi_aware()
            # Ctrl+Alt+Del não é simulável por SendInput. O SendSAS só funciona na sessão 0 (SYSTEM), então
            # tanto o aplicativo quanto o worker pedem a SAS ao broker por arquivo (ver request_sas).
            def sas_for_session():
                if not self.control.is_set():
                    raise PermissionError('Controle remoto não autorizado nesta sessão.')
                command = self.expected_command if self.expected_command is not None else (self.bridge.command_id if self.bridge else 0)
                request_sas(command)
            rin = RemoteInput()
            woke = False
            original_combo = rin.combo
            def combo(name):
                if name == 'ctrl_alt_del':
                    sas_for_session()
                else:
                    original_combo(name)
            rin.combo = combo
            errors = 0
            while not self.stop.is_set():
                if not self.control.is_set() or self.capture_unavailable.is_set():
                    if rin.has_pressed():
                        rin.release_all()
                    self.stop.wait(0.4)
                    continue
                try:
                    if attach:
                        attach.attach()
                        if not woke:
                            woke = True
                            rin.wake()  # já na área de entrada: acorda a tela de bloqueio ociosa/apagada
                    reply = request_json(self.base + 'poll', dict(self.creds, control=True), timeout=8, keepalive=True)
                    errors = 0
                    if reply.get('stop'):
                        self.stop.set()
                        break
                    with self.view_lock:
                        if reply.get('selected_monitor'):
                            self.view['selected_id'] = str(reply.get('selected_monitor') or '')
                        bounds = tuple(self.view['bounds']) if self.view.get('bounds') else None
                    events = reply.get('events') or []
                    for ev in events:
                        if self.stop.is_set() or not self.control.is_set() or self.capture_unavailable.is_set():
                            break
                        try:
                            if ev.get('t')=='unlock':self.handle_unlock(ev)
                            else:rin.apply(ev, bounds=bounds)
                        except Exception as exc:
                            self._error('Controle remoto: ' + str(exc)[:160])
                    if events:
                        self.activity = time.time()
                except SessionStopped:
                    self.stop.set()
                    break
                except Exception as exc:
                    errors += 1
                    self._error('Controle remoto: ' + str(exc)[:160])
                    if errors >= 8:
                        break
                    self.stop.wait(min(2.0, 0.3 * errors))
        except Exception as exc:
            self._error('Controle remoto: ' + str(exc)[:160])
        finally:
            try:
                if rin:
                    rin.release_all()
            except Exception:
                pass
            if attach:
                attach.close()
            with self.unlock_lock:
                if self.unlock_box:self.unlock_box.close();self.unlock_box=None

    # -- captura --------------------------------------------------------------
    def _capture_loop(self):
        attach = DesktopAttach() if self.role == 'system' else None
        try:
            from PIL import ImageGrab, ImageDraw, Image
            RemoteInput.thread_dpi_aware()
            last_sig = None
            last_sent = 0.0
            last_ctl = None
            errors = 0
            server_selected = ''
            last_monitor_id = ''
            last_meta_sig = ''
            stream_quality = 'balanced'
            cursor_overlay = False
            default_since = None
            last_desktop = None
            sent_bytes = 0
            send_seconds = 0.0
            while not self.stop.is_set():
                frame_started=time.monotonic()
                try:
                    # 1) Onde está a entrada do Windows agora?
                    if self.role == 'system':
                        if not self._sync_request():
                            break  # o aplicativo não precisa mais do componente
                        name = attach.attach()
                        if name != last_desktop:
                            self._error('Área de trabalho de entrada: %s' % (name or '?'))
                            last_desktop = name
                        if (name or '').lower() == 'default':
                            default_since = default_since or time.time()
                            if time.time() - default_since >= 1.5:
                                break  # Windows desbloqueado: o aplicativo retoma a sessão
                        else:
                            default_since = None
                    elif not Desktop.is_user_desktop():
                        # Tela de bloqueio/login/UAC: pertence ao sistema, não à sessão do usuário.
                        self.capture_unavailable.set()
                        control_now = self.control.is_set()
                        if self.bridge and self.bridge.handoff(control_now):
                            self._delegated = True
                            if not self._keepalive(control_now):
                                break
                            errors = 0
                            self.stop.wait(1.0)
                            continue
                        frame = self._placeholder(Image, ImageDraw)
                        if frame is None:
                            if not self._keepalive(False):
                                break
                            errors = 0
                            self.stop.wait(1.5)
                            continue
                        # primeira ocorrência: envia o aviso como quadro (fluxo normal abaixo)
                        self._send_notice(frame)
                        self.stop.wait(1.0)
                        continue
                    elif self._delegated:
                        # Desbloqueado: o aplicativo retoma e encerra o pedido ao componente.
                        self._delegated = False
                        if self.bridge:
                            self.bridge.release()
                    # 2) Captura da área de trabalho atual
                    monitors = list_monitors(self.fallback_size)
                    current = _choose_monitor(monitors, server_selected)
                    if current is None:
                        raise RuntimeError('Nenhuma tela disponível para o acesso remoto')
                    bbox = (int(current['left']), int(current['top']), int(current['right']), int(current['bottom']))
                    with self.view_lock:
                        self.view['selected_id'] = str(current.get('id') or '')
                        self.view['bounds'] = bbox
                        self.view['monitors'] = monitors
                    try:
                        frame = ImageGrab.grab(bbox=bbox, all_screens=True).convert('RGB')
                    except (OSError, RuntimeError, ValueError) as capture_exc:
                        self.capture_unavailable.set()
                        self._error('Captura temporariamente indisponível: ' + str(capture_exc)[:110])
                        shown = self._placeholder(Image, ImageDraw)
                        if shown is None:
                            if not self._keepalive(False):
                                break
                            errors = 0
                            self.stop.wait(1.5)
                            continue
                        self._send_notice(shown)
                        continue
                    self.capture_unavailable.clear()
                    self._placeholder_sent = False
                    ctl = self.control.is_set()
                    sw, sh = frame.size
                    max_w, max_h, jpeg_quality = self.QUALITY.get(stream_quality, self.QUALITY['balanced'])
                    scale = min(1.0, float(max_w) / max(1, sw), float(max_h) / max(1, sh))
                    if scale < 1.0:
                        frame = frame.resize((max(1, int(sw * scale)), max(1, int(sh * scale))))
                    cur = RemoteInput.cursor_pos()
                    if not cursor_overlay and cur and bbox[0] <= cur[0] < bbox[2] and bbox[1] <= cur[1] < bbox[3]:
                        cx, cy = int((cur[0] - bbox[0]) * scale), int((cur[1] - bbox[1]) * scale)
                        ImageDraw.Draw(frame).polygon([(cx, cy), (cx, cy + 17), (cx + 4, cy + 13), (cx + 7, cy + 20), (cx + 10, cy + 19), (cx + 7, cy + 12), (cx + 12, cy + 12)], fill='white', outline='black')
                    sig = hashlib.md5(frame.tobytes()).digest()
                    meta = _monitor_payload(monitors)
                    meta_sig = json.dumps(meta, sort_keys=True, separators=(',', ':'))
                    now = time.time()
                    monitor_id = str(current.get('id') or '')
                    if sig == last_sig and ctl == last_ctl and now - last_sent < 2.5 and last_monitor_id == monitor_id and meta_sig == last_meta_sig:
                        self.stop.wait(0.08 if ctl else 0.35)
                        continue
                    payload = dict(self.creds, control=ctl, selected_monitor=monitor_id)
                    try:payload.update(self.unlock_metadata())
                    except Exception:payload.update(desktop_state='locked',unlock_key=None,unlock_result={'status':'unavailable'})
                    if meta and (meta_sig != last_meta_sig or now - last_sent > 8 or not last_sent):
                        payload['monitors'] = meta
                    if sig == last_sig and last_sent and ctl == last_ctl and last_monitor_id == monitor_id:
                        payload['keepalive'] = True
                    else:
                        payload['image'] = self._encode(frame, jpeg_quality)
                    sent_bytes = len(payload.get('image') or '')
                    send_started = time.monotonic()
                    response = request_json(self.base + 'frame', payload, timeout=10, keepalive=True)
                    send_seconds = time.monotonic() - send_started
                    errors = 0
                    last_sent = time.time()
                    last_ctl = ctl
                    last_monitor_id = monitor_id
                    if 'monitors' in payload:
                        last_meta_sig = meta_sig
                    if not payload.get('keepalive'):
                        last_sig = sig
                    server_selected = str(response.get('selected_monitor') or last_monitor_id)
                    stream_quality = str(response.get('quality') or 'balanced')
                    cursor_overlay = bool(response.get('cursor_overlay'))
                    if not response.get('ok') or response.get('stop'):
                        break
                except SessionStopped:
                    break
                except Exception as exc:
                    # Falha de captura/monitor não encerra uma sessão autorizada imediatamente.
                    self.capture_unavailable.set()
                    errors += 1
                    self._error('Tela remota: ' + str(exc)[:160])
                    if errors >= 30:
                        break
                    self.stop.wait(min(5.0, 1.0 + errors * 0.2))
                    continue
                active = time.time() - self.activity < 1.5
                interval = .1 if ctl and active else .15 if ctl else .5
                # Ritmo do envio: respeita o teto de banda da qualidade e nunca deixa o upload ocupado mais da metade do tempo.
                budget = self.BANDWIDTH.get(stream_quality, self.BANDWIDTH['balanced'])
                interval = max(interval, sent_bytes / float(budget), send_seconds)
                self.stop.wait(max(.005, interval - (time.monotonic() - frame_started)))
        except Exception as exc:
            self._error('Tela remota: ' + str(exc)[:160])
        finally:
            self.stop.set()
            if attach:
                attach.close()
            if self.bridge:
                self.bridge.release()
            if self.on_finish:
                try:
                    self.on_finish()
                except Exception:
                    pass

    def _placeholder(self, Image, ImageDraw):
        """Aviso exibido uma única vez quando não há como capturar a tela (nem pelo componente SYSTEM)."""
        if self._placeholder_sent:
            return None
        self._placeholder_sent = True
        frame = Image.new('RGB', (960, 540), (26, 32, 42))
        draw = ImageDraw.Draw(frame)
        draw.text((75, 225), 'TELA DO WINDOWS INDISPONIVEL', fill=(240, 240, 240))
        if self.role == 'system':
            lines = ('Nao foi possivel capturar a tela segura do Windows.', 'Aguarde alguns segundos ou desbloqueie localmente.')
        else:
            lines = ('Componente de tela segura nao instalado neste computador.', 'Reinstale o agente como administrador ou desbloqueie localmente.')
        draw.text((75, 260), lines[0], fill=(200, 210, 220))
        draw.text((75, 285), lines[1], fill=(200, 210, 220))
        return frame

    @staticmethod
    def _encode(frame, jpeg_quality):
        data = b''
        for quality in (jpeg_quality, 40, 28, 20):
            buffer = io.BytesIO()
            frame.save(buffer, format='JPEG', quality=quality, optimize=False)
            data = buffer.getvalue()
            if len(data) <= MAX_FRAME_BYTES:
                break
        return base64.b64encode(data).decode('ascii')

    def _send_notice(self, frame):
        """Envia o aviso de tela indisponível como quadro; a entrada fica suspensa (control=False)."""
        payload = dict(self.creds, control=False, image=self._encode(frame, 55))
        reply = request_json(self.base + 'frame', payload, timeout=10, keepalive=True)
        if not reply.get('ok') or reply.get('stop'):
            self.stop.set()
