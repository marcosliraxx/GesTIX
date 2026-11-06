# Caminho: agentes/windows/arcoll_vdrive.py
"""Disco virtual do Backup em Nuvem (somente leitura).

O agente publica um servidor WebDAV que escuta APENAS em 127.0.0.1 e o monta como uma unidade do Windows (ex.: Z:),
como o Google Drive para computador faz. Os arquivos não são copiados para o disco: cada abertura é lida sob demanda
pela plataforma (api/cloud.php: agent-list e agent-download). O agente nunca recebe credenciais do provedor.

Segurança: só aceita Host 127.0.0.1/localhost na porta do servidor e exige um segredo aleatório no caminho da URL,
guardado em arquivo privado do usuário; outro usuário do mesmo Windows não consegue adivinhá-lo.
"""
import email.utils
import http.server
import mimetypes
import os
import re
import secrets
import socketserver
import subprocess
import threading
import time
import urllib.parse
from pathlib import Path
from xml.sax.saxutils import escape

PORTS = range(47831, 47851)
FRIENDLY = 'Arcoll Nuvem'
INVALID = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
NO_WINDOW = 0x08000000 if os.name == 'nt' else 0
LIST_TTL = 20
MAX_PAGES = 10


def clean_name(name):
    name = INVALID.sub('_', str(name)).strip().rstrip('. ')
    return name[:120] or '_'


class Entry:
    __slots__ = ('display', 'raw', 'directory', 'size', 'modified')

    def __init__(self, display, raw, directory, size, modified):
        self.display, self.raw, self.directory, self.size, self.modified = display, raw, directory, size, modified


class Catalog:
    """Cache curto das listagens. Os nomes exibidos (amigáveis) são mapeados de volta para os nomes reais no destino."""

    def __init__(self, cloud):
        self.cloud = cloud
        self.lock = threading.RLock()
        self.cache = {}   # tupla de nomes reais -> (momento, {nome em minúsculas: Entry})

    def children(self, raw_parts):
        key = tuple(raw_parts)
        with self.lock:
            hit = self.cache.get(key)
            if hit and time.monotonic() - hit[0] < LIST_TTL:
                return hit[1]
        entries, page = {}, None
        for _ in range(MAX_PAGES):
            data = {'path': '/'.join(raw_parts)}
            if page:
                data['page'] = page
            browser = self.cloud.request('agent-list', data)['browser']
            for item in browser.get('entries', []):
                display = clean_name(item.get('display') or item.get('name'))
                base, n = display, 2
                while display.casefold() in entries:
                    display = '%s (%d)' % (base[:110], n)
                    n += 1
                modified = 0
                try:
                    if item.get('modified'):
                        from datetime import datetime
                        modified = datetime.fromisoformat(str(item['modified']).replace('Z', '+00:00')).timestamp()
                except (ValueError, OSError):
                    modified = 0
                entries[display.casefold()] = Entry(display, str(item.get('name', '')), bool(item.get('directory')), int(item.get('size') or 0), modified)
            page = browser.get('next') or ''
            if not page:
                break
        with self.lock:
            self.cache[key] = (time.monotonic(), entries)
            if len(self.cache) > 300:
                self.cache.pop(next(iter(self.cache)))
        return entries

    def resolve(self, display_parts):
        """Retorna (entrada, nomes reais) ou (None, None). A raiz é entrada None com lista vazia."""
        raw, current = [], None
        for part in display_parts:
            if current is not None and not current.directory:
                return None, None
            found = self.children(raw).get(part.casefold())
            if found is None:
                return None, None
            raw.append(found.raw)
            current = found
        return current, raw

    def forget(self):
        with self.lock:
            self.cache.clear()


def http_date(ts):
    return email.utils.formatdate(ts or 0, usegmt=True)


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = False


def make_handler(catalog, cloud, secret, port_ref):
    prefix = '/' + secret + '/' + urllib.parse.quote(FRIENDLY)

    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'
        server_version = 'ArcollDrive'

        def log_message(self, *args):
            return

        def host_ok(self):
            host = (self.headers.get('Host') or '').lower()
            return host in ('127.0.0.1:%d' % port_ref[0], 'localhost:%d' % port_ref[0], '127.0.0.1', 'localhost')

        def parts(self):
            path = urllib.parse.unquote(urllib.parse.urlsplit(self.path).path)
            base = '/' + secret + '/' + FRIENDLY
            if path.rstrip('/') != base and not path.startswith(base + '/'):
                return None
            rest = path[len(base):].strip('/')
            return [p for p in rest.split('/') if p] if rest else []

        def reply(self, code, body=b'', headers=None):
            self.send_response(code)
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.send_header('Content-Length', str(len(body)))
            self.send_header('DAV', '1')
            self.send_header('MS-Author-Via', 'DAV')
            self.end_headers()
            if body and self.command != 'HEAD':
                self.wfile.write(body)

        def guard(self):
            if not self.host_ok():
                self.reply(403)
                return None
            parts = self.parts()
            if parts is None:
                self.reply(404)
            return parts

        def do_OPTIONS(self):
            if not self.host_ok():
                return self.reply(403)
            self.reply(200, b'', {'Allow': 'OPTIONS, GET, HEAD, PROPFIND', 'Accept-Ranges': 'bytes'})

        def deny(self):
            if self.host_ok():
                self.reply(403, b'Disco virtual somente leitura.')
            else:
                self.reply(403)
        do_PUT = do_DELETE = do_MKCOL = do_MOVE = do_COPY = do_PROPPATCH = do_LOCK = do_UNLOCK = do_POST = do_PATCH = deny

        def href(self, parts, directory):
            text = prefix + ''.join('/' + urllib.parse.quote(p) for p in parts)
            return text + ('/' if directory else '')

        def item_xml(self, parts, entry):
            directory = entry is None or entry.directory
            name = parts[-1] if parts else FRIENDLY
            props = '<D:displayname>%s</D:displayname>' % escape(name)
            props += '<D:resourcetype>%s</D:resourcetype>' % ('<D:collection/>' if directory else '')
            props += '<D:getlastmodified>%s</D:getlastmodified>' % http_date(entry.modified if entry else time.time())
            if not directory:
                props += '<D:getcontentlength>%d</D:getcontentlength>' % entry.size
                props += '<D:getcontenttype>%s</D:getcontenttype>' % escape(mimetypes.guess_type(name)[0] or 'application/octet-stream')
            return '<D:response><D:href>%s</D:href><D:propstat><D:prop>%s</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>' % (escape(self.href(parts, directory)), props)

        def do_PROPFIND(self):
            parts = self.guard()
            if parts is None:
                return
            try:
                length = int(self.headers.get('Content-Length') or 0)
                if length:
                    self.rfile.read(min(length, 65536))
                entry, raw = catalog.resolve(parts)
                if raw is None:
                    return self.reply(404)
                items = [self.item_xml(parts, entry)]
                if (self.headers.get('Depth') or '1') != '0' and (entry is None or entry.directory):
                    for child in sorted(catalog.children(raw).values(), key=lambda e: (not e.directory, e.display.casefold())):
                        items.append(self.item_xml(parts + [child.display], child))
                body = ('<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">%s</D:multistatus>' % ''.join(items)).encode('utf-8')
                self.reply(207, body, {'Content-Type': 'text/xml; charset="utf-8"'})
            except Exception:
                self.reply(502, 'Nuvem indisponível no momento.'.encode('utf-8'))

        def do_HEAD(self):
            self.do_GET()

        def do_GET(self):
            parts = self.guard()
            if parts is None:
                return
            try:
                entry, raw = catalog.resolve(parts)
                if raw is None:
                    return self.reply(404)
                if entry is None or entry.directory:
                    return self.reply(200, b'', {'Content-Type': 'text/plain'}) if self.command == 'HEAD' else self.reply(403, b'Abra pelo Explorador de Arquivos.')
                mime = mimetypes.guess_type(entry.display)[0] or 'application/octet-stream'
                if self.command == 'HEAD':
                    self.send_response(200)
                    self.send_header('Content-Type', mime)
                    self.send_header('Content-Length', str(entry.size))
                    self.send_header('Accept-Ranges', 'bytes')
                    self.send_header('Last-Modified', http_date(entry.modified))
                    self.end_headers()
                    return
                data = {'path': '/'.join(raw)}
                requested = self.headers.get('Range')
                if requested and re.fullmatch(r'bytes=\d*-\d*', requested.strip()):
                    data['range'] = requested.strip()
                try:
                    upstream = cloud.stream('agent-download', data)
                except Exception as exc:
                    return self.reply(502, str(exc)[:200].encode('utf-8'))
                with upstream:
                    status = 206 if upstream.status == 206 else 200
                    self.send_response(status)
                    self.send_header('Content-Type', mime)
                    self.send_header('Accept-Ranges', 'bytes')
                    self.send_header('Last-Modified', http_date(entry.modified))
                    length = upstream.headers.get('Content-Length')
                    if length is None:
                        self.close_connection = True
                    else:
                        self.send_header('Content-Length', length)
                    if status == 206 and upstream.headers.get('Content-Range'):
                        self.send_header('Content-Range', upstream.headers['Content-Range'])
                    self.end_headers()
                    while True:
                        block = upstream.read(65536)
                        if not block:
                            break
                        self.wfile.write(block)
            except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
                return
            except Exception:
                try:
                    self.reply(502)
                except OSError:
                    pass

    return Handler


def run(command, timeout=25):
    try:
        done = subprocess.run(command, capture_output=True, text=True, timeout=timeout, creationflags=NO_WINDOW, errors='replace')
        return done.returncode, (done.stdout or '') + (done.stderr or '')
    except (OSError, subprocess.SubprocessError) as exc:
        return 1, str(exc)


class VirtualDrive:
    """Mantém o servidor local e a unidade montada conforme a configuração publicada pela plataforma."""

    def __init__(self, cloud, stop_event):
        self.cloud = cloud
        self.stop_event = stop_event
        self.server = None
        self.port = [0]
        self.secret = self._secret()
        self.catalog = Catalog(cloud)
        self.mounted = ''
        self.thread = None

    def _secret(self):
        path = Path(self.cloud.directory) / 'vdrive.key'
        try:
            value = path.read_text(encoding='utf-8').strip()
            if re.fullmatch(r'[0-9a-f]{40}', value):
                return value
        except OSError:
            pass
        value = secrets.token_hex(20)
        path.write_text(value, encoding='utf-8')
        try:
            path.chmod(0o600)
        except OSError:
            pass
        return value

    def start(self):
        self.thread = threading.Thread(target=self.loop, name='ArcollVirtualDrive', daemon=True)
        self.thread.start()

    def say(self, text):
        self.cloud.update(vdrive=text[:160])

    def ensure_server(self):
        if self.server:
            return True
        for port in PORTS:
            try:
                self.port[0] = port
                server = Server(('127.0.0.1', port), make_handler(self.catalog, self.cloud, self.secret, self.port))
            except OSError:
                continue
            self.server = server
            threading.Thread(target=server.serve_forever, kwargs={'poll_interval': 0.5}, name='ArcollVirtualDriveHTTP', daemon=True).start()
            return True
        self.say('Nenhuma porta local livre para o disco virtual.')
        return False

    def url(self):
        return 'http://127.0.0.1:%d/%s/%s/' % (self.port[0], self.secret, urllib.parse.quote(FRIENDLY))

    def webclient_ready(self):
        code, out = run(['sc', 'query', 'WebClient'])
        if 'RUNNING' in out:
            return True
        run(['sc', 'start', 'WebClient'])
        for _ in range(10):
            time.sleep(1)
            if 'RUNNING' in run(['sc', 'query', 'WebClient'])[1]:
                return True
        return False

    def raise_size_limit(self):
        """O Windows limita arquivos WebDAV a 50 MB. Só consegue alterar quando o agente roda como administrador."""
        key = r'HKLM\SYSTEM\CurrentControlSet\Services\WebClient\Parameters'
        code, out = run(['reg', 'query', key, '/v', 'FileSizeLimitInBytes'])
        if code == 0 and '0xffffffff' in out.lower():
            return
        code, _ = run(['reg', 'add', key, '/v', 'FileSizeLimitInBytes', '/t', 'REG_DWORD', '/d', '4294967295', '/f'])
        if code == 0:
            run(['net', 'stop', 'webclient'])
            run(['net', 'start', 'webclient'])

    def drive_in_use(self, letter):
        return os.path.exists('%s:\\' % letter)

    def mapped_to_us(self, letter):
        code, out = run(['net', 'use', '%s:' % letter])
        return code == 0 and ('127.0.0.1@%d' % self.port[0]) in out.replace('http://127.0.0.1:%d' % self.port[0], '127.0.0.1@%d' % self.port[0])

    def mount(self, letter):
        if not self.ensure_server():
            return
        if self.mounted == letter and self.drive_in_use(letter):
            return
        if os.name != 'nt':
            self.say('Disco virtual disponível somente no Windows.')
            return
        if self.drive_in_use(letter) and not self.mapped_to_us(letter):
            free = [c for c in 'ZYXWVUTSRQPONMLKJIHGFED' if not self.drive_in_use(c)]
            if not free:
                self.say('Sem letras de unidade livres.')
                return
            letter = free[0]
        if not self.webclient_ready():
            self.say('Inicie o serviço "Cliente Web" (WebClient) do Windows, como administrador, para montar o disco.')
            return
        self.raise_size_limit()
        if not (self.drive_in_use(letter) and self.mapped_to_us(letter)):
            code, out = run(['net', 'use', '%s:' % letter, self.url(), '/persistent:no'], timeout=40)
            if code != 0:
                self.say('Não foi possível montar %s: %s' % (letter, ' '.join(out.split())[:110]))
                return
        self.mounted = letter
        self.say('Disco virtual montado em %s: (somente leitura).' % letter)

    def unmount(self):
        if self.mounted and os.name == 'nt':
            run(['net', 'use', '%s:' % self.mounted, '/delete', '/y'])
        self.mounted = ''
        self.catalog.forget()

    def loop(self):
        last_letter = ''
        while not self.stop_event.is_set():
            try:
                info = getattr(self.cloud, 'latest_vdrive', None) or {}
                if info.get('available'):
                    letter = str(info.get('letter') or 'Z').upper()[:1]
                    if self.mounted and letter != last_letter:
                        self.unmount()
                    last_letter = letter
                    self.mount(letter)
                elif self.mounted or self.server:
                    self.unmount()
                    if self.server:
                        self.server.shutdown()
                        self.server.server_close()
                        self.server = None
                    self.say('')
            except Exception as exc:
                self.say('Disco virtual indisponível: %s' % type(exc).__name__)
            self.stop_event.wait(10)
        try:
            self.unmount()
        except Exception:
            pass
