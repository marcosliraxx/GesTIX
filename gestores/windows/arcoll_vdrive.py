# Caminho: gestores/windows/arcoll_vdrive.py
"""Disco virtual do Backup em Nuvem (somente leitura).

O gestor publica um servidor WebDAV que escuta APENAS em 127.0.0.1 e o monta como uma unidade do Windows (ex.: Z:),
como o Google Drive para computador faz. Os arquivos não são copiados para o disco: cada abertura é lida sob demanda
pela plataforma (api/cloud.php: gestor-list e gestor-download). O gestor nunca recebe credenciais do provedor.

Segurança: só aceita Host 127.0.0.1/localhost na porta do servidor e exige um segredo aleatório no caminho da URL,
guardado em arquivo privado do usuário; outro usuário do mesmo Windows não consegue adivinhá-lo.

Montagem (em ordem de preferência):
  1. WinFsp (driver de sistema de arquivos, instalado junto com o gestor): unidade real, sem WebClient e sem porta local.
     O próprio gestor implementa o sistema de arquivos (somente leitura) e responde direto às leituras do Windows.
  2. WebDAV local + `net use` (reserva, quando o WinFsp não está instalado).
"""
import ctypes
import json
import email.utils
import hashlib
import http.server
import mimetypes
import os
import re
import secrets
import socketserver
import string
import subprocess
import threading
import struct
import time
import urllib.parse
from pathlib import Path
from xml.sax.saxutils import escape

PORTS = range(47831, 47851)
FRIENDLY = 'Arcoll Nuvem'
INVALID = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
NO_WINDOW = 0x08000000 if os.name == 'nt' else 0
LETTERS = 'ZYXWVUTSRQPONMLKJIHGFED'   # ordem de preferência da letra automática
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
            browser = self.cloud.request('gestor-list', data)['browser']
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
                    upstream = cloud.stream('gestor-download', data)
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


# ======================================================================================================
# WinFsp: sistema de arquivos em modo usuário (somente leitura). Estruturas e ordem da interface conforme
# winfsp.h / fsctl.h (WinFsp 2.x). Tamanhos conferidos em _check_layout().
# ======================================================================================================
STATUS_SUCCESS = 0
STATUS_UNSUCCESSFUL = 0xC0000001
STATUS_ACCESS_DENIED = 0xC0000022
STATUS_END_OF_FILE = 0xC0000011
STATUS_OBJECT_NAME_NOT_FOUND = 0xC0000034
STATUS_OBJECT_NAME_COLLISION = 0xC0000035
STATUS_FILE_IS_A_DIRECTORY = 0xC00000BA
STATUS_NOT_A_DIRECTORY = 0xC0000103
STATUS_IO_DEVICE_ERROR = 0xC0000185
FILE_DIRECTORY_FILE = 0x00000001
FILE_NON_DIRECTORY_FILE = 0x00000040
ATTR_READONLY, ATTR_DIRECTORY, ATTR_ARCHIVE = 0x1, 0x10, 0x20
CHUNK = 4 * 1024 * 1024        # leitura antecipada: cada pedido à nuvem traz até 4 MB
CHUNK_CACHE = 8                # blocos mantidos em memória (32 MB no máximo)
FILETIME_EPOCH = 11644473600
FUNC = getattr(ctypes, 'WINFUNCTYPE', ctypes.CFUNCTYPE)


class VolumeParams(ctypes.Structure):          # FSP_FSCTL_VOLUME_PARAMS (504 bytes)
    _fields_ = [('Version', ctypes.c_uint16), ('SectorSize', ctypes.c_uint16), ('SectorsPerAllocationUnit', ctypes.c_uint16),
                ('MaxComponentLength', ctypes.c_uint16), ('VolumeCreationTime', ctypes.c_uint64), ('VolumeSerialNumber', ctypes.c_uint32),
                ('TransactTimeout', ctypes.c_uint32), ('IrpTimeout', ctypes.c_uint32), ('IrpCapacity', ctypes.c_uint32),
                ('FileInfoTimeout', ctypes.c_uint32), ('Flags', ctypes.c_uint32),
                ('Prefix', ctypes.c_uint16 * 192), ('FileSystemName', ctypes.c_uint16 * 16),
                ('Flags1', ctypes.c_uint32), ('VolumeInfoTimeout', ctypes.c_uint32), ('DirInfoTimeout', ctypes.c_uint32),
                ('SecurityTimeout', ctypes.c_uint32), ('StreamInfoTimeout', ctypes.c_uint32), ('EaTimeout', ctypes.c_uint32),
                ('FsextControlCode', ctypes.c_uint32), ('Reserved32', ctypes.c_uint32), ('Reserved64', ctypes.c_uint64 * 2)]


class VolumeInfo(ctypes.Structure):            # FSP_FSCTL_VOLUME_INFO (88 bytes)
    _fields_ = [('TotalSize', ctypes.c_uint64), ('FreeSize', ctypes.c_uint64), ('VolumeLabelLength', ctypes.c_uint16),
                ('VolumeLabel', ctypes.c_uint16 * 32)]


class FileInfo(ctypes.Structure):              # FSP_FSCTL_FILE_INFO (72 bytes)
    _fields_ = [('FileAttributes', ctypes.c_uint32), ('ReparseTag', ctypes.c_uint32), ('AllocationSize', ctypes.c_uint64),
                ('FileSize', ctypes.c_uint64), ('CreationTime', ctypes.c_uint64), ('LastAccessTime', ctypes.c_uint64),
                ('LastWriteTime', ctypes.c_uint64), ('ChangeTime', ctypes.c_uint64), ('IndexNumber', ctypes.c_uint64),
                ('HardLinks', ctypes.c_uint32), ('EaSize', ctypes.c_uint32)]


DIR_INFO_HEAD = 104                            # sizeof(FSP_FSCTL_DIR_INFO); o nome (UTF-16, sem terminador) vem depois
FILE_INFO_FORMAT = '<IIQQQQQQQII'


def _check_layout():
    if (ctypes.sizeof(VolumeParams), ctypes.sizeof(VolumeInfo), ctypes.sizeof(FileInfo)) != (504, 88, 72) \
            or struct.calcsize(FILE_INFO_FORMAT) != 72:
        raise RuntimeError('Layout das estruturas do WinFsp não confere.')


def put_wstr(array, text):
    """Grava texto UTF-16 (WCHAR do Windows) em um array de 16 bits, com terminador."""
    units = struct.unpack('<%dH' % (len(text.encode('utf-16-le')) // 2), text.encode('utf-16-le'))[:len(array) - 1]
    for i, unit in enumerate(units):
        array[i] = unit
    array[len(units)] = 0
    return len(units)


def filetime(unix):
    return int((float(unix or 0) + FILETIME_EPOCH) * 10000000)


def find_winfsp_dll():
    """Caminho da DLL do WinFsp instalada, ou '' se o driver não estiver instalado."""
    if os.name != 'nt':
        return ''
    machine = (os.environ.get('PROCESSOR_ARCHITEW6432') or os.environ.get('PROCESSOR_ARCHITECTURE') or '').upper()
    name = 'winfsp-a64.dll' if machine == 'ARM64' else ('winfsp-x64.dll' if struct.calcsize('P') == 8 else 'winfsp-x86.dll')
    roots = []
    try:
        import winreg
        for sub, view in ((r'SOFTWARE\WOW6432Node\WinFsp', 0), (r'SOFTWARE\WinFsp', 0), (r'SOFTWARE\WinFsp', winreg.KEY_WOW64_32KEY)):
            try:
                with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, sub, 0, winreg.KEY_READ | view) as key:
                    roots.append(winreg.QueryValueEx(key, 'InstallDir')[0])
            except OSError:
                continue
    except ImportError:
        pass
    for var in ('ProgramFiles(x86)', 'ProgramFiles'):
        if os.environ.get(var):
            roots.append(os.path.join(os.environ[var], 'WinFsp'))
    for root in roots:
        path = os.path.join(str(root), 'bin', name)
        if os.path.isfile(path):
            return path
    return ''


class WinFspApi:
    """Funções exportadas pela DLL do WinFsp (assinaturas conforme winfsp.h)."""

    def __init__(self, dll_path):
        dll = ctypes.WinDLL(dll_path)
        P, W, U32, I32 = ctypes.c_void_p, ctypes.c_wchar_p, ctypes.c_uint32, ctypes.c_int32
        ref = ctypes.POINTER

        def bind(name, restype, *argtypes):
            fn = getattr(dll, name)
            fn.restype, fn.argtypes = restype, list(argtypes)
            return fn
        self.Create = bind('FspFileSystemCreate', I32, W, ref(VolumeParams), P, ref(P))
        self.Delete = bind('FspFileSystemDelete', None, P)
        self.SetMountPoint = bind('FspFileSystemSetMountPoint', I32, P, W)
        self.MountPoint = bind('FspFileSystemMountPointF', W, P)
        self.StartDispatcher = bind('FspFileSystemStartDispatcher', I32, P, U32)
        self.StopDispatcher = bind('FspFileSystemStopDispatcher', None, P)
        self.AcquireDirectoryBuffer = bind('FspFileSystemAcquireDirectoryBuffer', ctypes.c_ubyte, ref(P), ctypes.c_ubyte, ref(I32))
        self.FillDirectoryBuffer = bind('FspFileSystemFillDirectoryBuffer', ctypes.c_ubyte, ref(P), P, ref(I32))
        self.ReleaseDirectoryBuffer = bind('FspFileSystemReleaseDirectoryBuffer', None, ref(P))
        self.ReadDirectoryBuffer = bind('FspFileSystemReadDirectoryBuffer', None, ref(P), W, P, U32, ref(U32))
        self.DeleteDirectoryBuffer = bind('FspFileSystemDeleteDirectoryBuffer', None, ref(P))


class Node:
    __slots__ = ('directory', 'size', 'modified', 'raw', 'name')

    def __init__(self, directory, size, modified, raw, name):
        self.directory, self.size, self.modified, self.raw, self.name = directory, size, modified, tuple(raw), name


class Handle:
    __slots__ = ('node', 'parts', 'dirbuf')

    def __init__(self, node, parts):
        self.node, self.parts, self.dirbuf = node, parts, ctypes.c_void_p(None)


class WinFspDrive:
    """Sistema de arquivos somente leitura da nuvem, servido diretamente pelo gestor via WinFsp."""

    def __init__(self, api, catalog, cloud, label=FRIENDLY):
        _check_layout()
        self.api, self.catalog, self.cloud = api, catalog, cloud
        self.label = clean_name(label)[:31]
        self.fs = ctypes.c_void_p(None)
        self.alive = False
        self.mount_point = ''
        self.lock = threading.RLock()
        self.handles, self.next_id = {}, 1
        self.chunks = {}                 # (caminho real, bloco) -> bytes, em ordem de uso
        self.fetching = {}
        self.callbacks = []              # mantém as ponteiras vivas enquanto o sistema de arquivos existir
        self.interface = (ctypes.c_void_p * 64)()
        self._build_interface()

    # --- utilidades ---------------------------------------------------------------------------------
    @staticmethod
    def split(name):
        return [p for p in str(name or '').replace('/', '\\').split('\\') if p]

    def find(self, name):
        parts = self.split(name)
        entry, raw = self.catalog.resolve(parts)
        if raw is None:
            return None, parts
        if entry is None:
            return Node(True, 0, time.time(), [], self.label), parts
        return Node(entry.directory, entry.size, entry.modified, raw, entry.display), parts

    def fill_info(self, pointer, node):
        info, stamp = pointer.contents, filetime(node.modified)
        info.FileAttributes = ATTR_READONLY | (ATTR_DIRECTORY if node.directory else ATTR_ARCHIVE)
        info.ReparseTag = 0
        info.FileSize = 0 if node.directory else int(node.size)
        info.AllocationSize = (info.FileSize + 4095) // 4096 * 4096
        info.CreationTime = info.LastAccessTime = info.LastWriteTime = info.ChangeTime = stamp
        info.IndexNumber = int.from_bytes(hashlib.sha1('/'.join(node.raw).encode('utf-8')).digest()[:8], 'little') >> 1
        info.HardLinks = 0
        info.EaSize = 0

    def dir_info(self, node, name):
        encoded = name.encode('utf-16-le')
        info = FileInfo()
        self.fill_info(ctypes.pointer(info), node)
        raw = bytearray(DIR_INFO_HEAD + len(encoded))
        struct.pack_into('<H', raw, 0, len(raw))
        raw[8:80] = bytes(info)
        raw[DIR_INFO_HEAD:] = encoded
        return ctypes.create_string_buffer(bytes(raw), len(raw))

    def read_range(self, node, offset, length):
        """Lê da plataforma em blocos alinhados com cache pequeno em memória."""
        out, end = [], min(offset + length, node.size)
        while offset < end:
            index = offset // CHUNK
            key = (node.raw, index)
            with self.lock:
                data = self.chunks.pop(key, None)
                if data is not None:
                    self.chunks[key] = data      # reinsere: mantém a ordem de uso
                else:
                    gate = self.fetching.setdefault(key, threading.Lock())
            if data is None:
                with gate:
                    with self.lock:
                        data = self.chunks.get(key)
                    if data is None:
                        data = self.fetch(node, index * CHUNK, min((index + 1) * CHUNK, node.size))
                        with self.lock:
                            self.chunks[key] = data
                            while len(self.chunks) > CHUNK_CACHE:
                                self.chunks.pop(next(iter(self.chunks)))
                with self.lock:
                    self.fetching.pop(key, None)
            start = offset - index * CHUNK
            piece = data[start:start + (end - offset)]
            if not piece:
                break
            out.append(piece)
            offset += len(piece)
        return b''.join(out)

    def fetch(self, node, start, stop):
        data = {'path': '/'.join(node.raw), 'range': 'bytes=%d-%d' % (start, stop - 1)}
        with self.cloud.stream('gestor-download', data) as upstream:
            if upstream.status == 206:
                return upstream.read()
            # Servidor ignorou o Range: descarta o início e guarda só o bloco pedido.
            skipped = 0
            while skipped < start:
                block = upstream.read(min(65536, start - skipped))
                if not block:
                    return b''
                skipped += len(block)
            return upstream.read(stop - start)

    def forget(self):
        with self.lock:
            self.chunks.clear()

    # --- callbacks do sistema de arquivos -----------------------------------------------------------
    def _build_interface(self):
        P, W, U32, U64, B = ctypes.c_void_p, ctypes.c_wchar_p, ctypes.c_uint32, ctypes.c_uint64, ctypes.c_ubyte
        ref = ctypes.POINTER

        def guarded(default):
            def wrap(fn):
                def inner(*args):
                    try:
                        return fn(*args)
                    except Exception:
                        return default
                return inner
            return wrap

        @guarded(STATUS_IO_DEVICE_ERROR)
        def get_volume_info(fs, info):
            volume = info.contents
            volume.TotalSize = volume.FreeSize = 1 << 40
            volume.VolumeLabelLength = put_wstr(volume.VolumeLabel, self.label) * 2
            return STATUS_SUCCESS

        @guarded(STATUS_IO_DEVICE_ERROR)
        def get_security_by_name(fs, name, attributes, descriptor, size):
            node, _ = self.find(name)
            if node is None:
                return STATUS_OBJECT_NAME_NOT_FOUND
            if attributes:
                attributes[0] = ATTR_READONLY | (ATTR_DIRECTORY if node.directory else ATTR_ARCHIVE)
            if size:
                size[0] = 0
            return STATUS_SUCCESS

        @guarded(STATUS_IO_DEVICE_ERROR)
        def open_(fs, name, options, access, pcontext, info):
            node, parts = self.find(name)
            if node is None:
                return STATUS_OBJECT_NAME_NOT_FOUND
            if node.directory and options & FILE_NON_DIRECTORY_FILE:
                return STATUS_FILE_IS_A_DIRECTORY
            if not node.directory and options & FILE_DIRECTORY_FILE:
                return STATUS_NOT_A_DIRECTORY
            with self.lock:
                handle_id, self.next_id = self.next_id, self.next_id + 1
                self.handles[handle_id] = Handle(node, parts)
            pcontext[0] = handle_id
            self.fill_info(info, node)
            return STATUS_SUCCESS

        def cleanup(fs, context, name, flags):
            return None

        def close(fs, context):
            try:
                with self.lock:
                    handle = self.handles.pop(context, None)
                if handle is not None and handle.dirbuf.value:
                    self.api.DeleteDirectoryBuffer(ctypes.byref(handle.dirbuf))
            except Exception:
                pass

        @guarded(STATUS_IO_DEVICE_ERROR)
        def read(fs, context, buffer, offset, length, pbytes):
            handle = self.handles.get(context)
            if handle is None or handle.node.directory:
                return STATUS_UNSUCCESSFUL
            if offset >= handle.node.size:
                return STATUS_END_OF_FILE
            data = self.read_range(handle.node, offset, length)
            if not data:
                return STATUS_IO_DEVICE_ERROR
            ctypes.memmove(buffer, data, len(data))
            pbytes[0] = len(data)
            return STATUS_SUCCESS

        @guarded(STATUS_IO_DEVICE_ERROR)
        def get_file_info(fs, context, info):
            handle = self.handles.get(context)
            if handle is None:
                return STATUS_UNSUCCESSFUL
            self.fill_info(info, handle.node)
            return STATUS_SUCCESS

        @guarded(STATUS_IO_DEVICE_ERROR)
        def read_directory(fs, context, pattern, marker, buffer, length, pbytes):
            handle = self.handles.get(context)
            if handle is None or not handle.node.directory:
                return STATUS_NOT_A_DIRECTORY
            status = ctypes.c_int32(0)
            if self.api.AcquireDirectoryBuffer(ctypes.byref(handle.dirbuf), 1 if marker is None else 0, ctypes.byref(status)):
                try:
                    if handle.parts:
                        here = Node(True, 0, handle.node.modified, handle.node.raw, '.')
                        for dots in ('.', '..'):
                            if not self.api.FillDirectoryBuffer(ctypes.byref(handle.dirbuf), self.dir_info(here, dots), ctypes.byref(status)):
                                break
                    for child in sorted(self.catalog.children(list(handle.node.raw)).values(), key=lambda e: (not e.directory, e.display.casefold())):
                        node = Node(child.directory, child.size, child.modified, list(handle.node.raw) + [child.raw], child.display)
                        if not self.api.FillDirectoryBuffer(ctypes.byref(handle.dirbuf), self.dir_info(node, child.display), ctypes.byref(status)):
                            break
                finally:
                    self.api.ReleaseDirectoryBuffer(ctypes.byref(handle.dirbuf))
            if status.value:
                return status.value & 0xFFFFFFFF
            self.api.ReadDirectoryBuffer(ctypes.byref(handle.dirbuf), marker, buffer, length, pbytes)
            return STATUS_SUCCESS

        def dispatcher_stopped(fs, normally):
            self.alive = False

        table = [
            (0, FUNC(U32, P, ref(VolumeInfo)), get_volume_info),
            (2, FUNC(U32, P, W, ref(U32), P, ref(ctypes.c_size_t)), get_security_by_name),
            (4, FUNC(U32, P, W, U32, U32, ref(P), ref(FileInfo)), open_),
            (6, FUNC(None, P, P, W, U32), cleanup),
            (7, FUNC(None, P, P), close),
            (8, FUNC(U32, P, P, P, U64, U32, ref(U32)), read),
            (11, FUNC(U32, P, P, ref(FileInfo)), get_file_info),
            (18, FUNC(U32, P, P, W, W, P, U32, ref(U32)), read_directory),
            (32, FUNC(None, P, B), dispatcher_stopped),
        ]
        for slot, prototype, function in table:
            callback = prototype(function)
            self.callbacks.append(callback)
            self.interface[slot] = ctypes.cast(callback, ctypes.c_void_p)

    # --- ciclo de vida ------------------------------------------------------------------------------
    def start(self, mount_point=None):
        """mount_point: 'Z:' para letra fixa ou None para a primeira letra livre (de Z para trás)."""
        params = VolumeParams()
        params.Version = ctypes.sizeof(VolumeParams)
        params.SectorSize, params.SectorsPerAllocationUnit, params.MaxComponentLength = 4096, 1, 255
        params.VolumeCreationTime = filetime(time.time())
        params.VolumeSerialNumber = int.from_bytes(hashlib.sha1(self.label.encode('utf-8')).digest()[:4], 'little')
        params.FileInfoTimeout = 5000
        params.Flags = (1 << 1) | (1 << 2) | (1 << 9)     # nomes preservados, Unicode, volume somente leitura
        put_wstr(params.FileSystemName, 'ArcollNuvem')
        status = self.api.Create('WinFsp.Disk', ctypes.byref(params), ctypes.cast(self.interface, ctypes.c_void_p), ctypes.byref(self.fs))
        if status != 0:
            raise OSError('WinFsp: falha ao criar o volume (0x%08X).' % (status & 0xFFFFFFFF))
        try:
            status = self.api.SetMountPoint(self.fs, mount_point) & 0xFFFFFFFF
            if status != 0 and mount_point:     # letra ocupada: usa a primeira livre
                status = self.api.SetMountPoint(self.fs, None) & 0xFFFFFFFF
            if status != 0:
                raise OSError('WinFsp: falha ao definir a unidade (0x%08X).' % status)
            status = self.api.StartDispatcher(self.fs, 0)
            if status != 0:
                raise OSError('WinFsp: falha ao iniciar o volume (0x%08X).' % (status & 0xFFFFFFFF))
        except Exception:
            self.api.Delete(self.fs)
            self.fs = ctypes.c_void_p(None)
            raise
        self.alive = True
        self.mount_point = str(self.api.MountPoint(self.fs) or mount_point or '')
        return self.mount_point

    def stop(self):
        if self.fs.value:
            self.alive = False
            try:
                self.api.StopDispatcher(self.fs)
            finally:
                self.api.Delete(self.fs)
                self.fs = ctypes.c_void_p(None)
        self.handles.clear()
        self.forget()


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
        self.fs = None              # volume WinFsp (quando o driver está instalado)
        self.fs_retry = 0           # não repete o WinFsp antes deste instante após uma falha

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
        run(['sc', 'config', 'WebClient', 'start=', 'auto'])   # só tem efeito como administrador; se falhar, segue para o start
        run(['sc', 'start', 'WebClient'])
        for _ in range(10):
            time.sleep(1)
            if 'RUNNING' in run(['sc', 'query', 'WebClient'])[1]:
                return True
        return False

    def raise_size_limit(self):
        """O Windows limita arquivos WebDAV a 50 MB. Só consegue alterar quando o gestor roda como administrador."""
        key = r'HKLM\SYSTEM\CurrentControlSet\Services\WebClient\Parameters'
        code, out = run(['reg', 'query', key, '/v', 'FileSizeLimitInBytes'])
        if code == 0 and '0xffffffff' in out.lower():
            return
        code, _ = run(['reg', 'add', key, '/v', 'FileSizeLimitInBytes', '/t', 'REG_DWORD', '/d', '4294967295', '/f'])
        if code == 0:
            run(['net', 'stop', 'webclient'])
            run(['net', 'start', 'webclient'])

    def used_letters(self):
        """Letras já atribuídas no Windows (inclui mapeamentos de rede desconectados, que os.path.exists não enxerga)."""
        if os.name == 'nt':
            try:
                import ctypes
                mask = ctypes.windll.kernel32.GetLogicalDrives()
                return {chr(65 + i) for i in range(26) if mask & (1 << i)}
            except Exception:
                pass
        return {c for c in string.ascii_uppercase if os.path.exists('%s:\\' % c)}

    def drive_in_use(self, letter):
        return letter in self.used_letters()

    def mapped_to_us(self, letter):
        code, out = run(['net', 'use', '%s:' % letter])
        if code != 0:
            return False
        normalized = out.lower().replace('/', '\\')
        return ('127.0.0.1@%d' % self.port[0]) in normalized or (self.url().lower().rstrip('/') in out.lower())

    def dav_unc(self):
        # O redirector WebDAV do Windows reconhece melhor a forma UNC
        # (DavWWWRoot) que URLs HTTP com caminho e porta personalizada.
        return r'\\127.0.0.1@%d\DavWWWRoot\%s\%s' % (self.port[0], self.secret, FRIENDLY)

    def pick_letters(self, requested):
        """Letras candidatas, em ordem. 'AUTO' usa a primeira livre; uma letra fixa ocupada por outro programa cai para a primeira livre."""
        used = self.used_letters()
        if requested != 'AUTO' and (requested not in used or self.mapped_to_us(requested)):
            return [requested]
        return [c for c in LETTERS if c not in used][:4]

    def mount_webdav(self, requested):
        if not self.ensure_server():
            return
        if os.name != 'nt':
            self.say('Disco virtual disponível somente no Windows.')
            return
        used = self.used_letters()
        # O Windows pode desconectar o WebDAV sem notificar o processo.
        if self.mounted:
            if self.mounted in used and self.mapped_to_us(self.mounted):
                # Mantém a unidade atual: letra automática ou letra preferida ocupada por outro programa.
                if requested in ('AUTO', self.mounted) or requested in used:
                    return
            self.mounted = ''
        letters = self.pick_letters(requested)
        if not letters:
            self.say('Sem letras de unidade livres.')
            return
        if not self.webclient_ready():
            self.say('Disco não montado: serviço WebClient indisponível. Habilite Cliente Web no Windows; o gestor tentará novamente.')
            return
        self.raise_size_limit()
        error = ''
        for letter in letters:
            if self.drive_in_use(letter) and self.mapped_to_us(letter):
                self.mounted = letter
                self.say('Disco virtual montado em %s: (somente leitura). Visível na sessão Windows deste gestor.' % letter)
                return
            code, out = run(['net', 'use', '%s:' % letter, self.dav_unc(), '/persistent:no'], timeout=40)
            if code != 0:
                # Compatibilidade com versões do redirector que aceitam a URL HTTP.
                code, fallback_out = run(['net', 'use', '%s:' % letter, self.url(), '/persistent:no'], timeout=40)
                out = fallback_out or out
            if code != 0:
                error = 'Falha WebDAV em %s: %s. Verifique WebClient e a sessao do Windows.' % (letter, ' '.join(out.split())[:90])
                continue   # letra pode ter sido ocupada agora; tenta a próxima livre
            if not self.mapped_to_us(letter):
                error = 'WebDAV conectado, mas nao confirmado como unidade %s: no usuario atual.' % letter
                continue
            self.mounted = letter
            self.say('Disco virtual montado em %s: (somente leitura). Visível na sessão Windows deste gestor.' % letter)
            return
        self.say(error or 'Falha ao montar o disco virtual.')

    def mount_winfsp(self, requested, label):
        """Monta com o WinFsp. Retorna True se montou; False para usar a reserva WebDAV."""
        if time.monotonic() < self.fs_retry:
            return False
        dll = find_winfsp_dll()
        if not dll:
            self.fs_retry = time.monotonic() + 60
            return False
        drive = None
        try:
            drive = WinFspDrive(WinFspApi(dll), self.catalog, self.cloud, label or FRIENDLY)
            point = drive.start(None if requested == 'AUTO' else requested + ':')
        except Exception as exc:
            if drive is not None:
                try:
                    drive.stop()
                except Exception:
                    pass
            self.fs_retry = time.monotonic() + 120
            self.say('WinFsp indisponível (%s); usando WebDAV.' % str(exc)[:100])
            return False
        self.fs = drive
        self.mounted = point.rstrip(':\\')[:1]
        self.say('Disco virtual montado em %s: (somente leitura). Visível na sessão Windows deste gestor.' % self.mounted)
        return True

    def mount(self, requested, label=''):
        if os.name != 'nt':
            self.say('Disco virtual disponível somente no Windows.')
            return
        if self.fs is not None:
            if self.fs.alive:
                return                      # letra automática, ou letra fixa ocupada por outro programa: mantém a atual
            self.unmount()                  # o Windows removeu o volume; remonta
        if self.mount_winfsp(requested, label):
            return
        self.mount_webdav(requested)

    def unmount(self):
        if self.fs is not None:
            try:
                self.fs.stop()
            except Exception:
                pass
            self.fs = None
        elif self.mounted and os.name == 'nt':
            run(['net', 'use', '%s:' % self.mounted, '/delete', '/y'])
        self.mounted = ''
        self.catalog.forget()

    @staticmethod
    def native_gestor_active():
        """Uma unica implementacao deve ser dona do volume na sessao Windows.

        O recibo nativo somente e aceito se recente e vinculado a um PID ativo.
        Uma versao antiga de Python nao pode roubar a unidade do WinUI.
        """
        if os.name != 'nt':
            return False
        marker = Path(os.environ.get('LOCALAPPDATA', '')) / 'TI-Digital' / 'native-ready.json'
        try:
            data = json.loads(marker.read_text(encoding='utf-8'))
            age = time.time() - int(data.get('approved_at', 0))
            pid = int(data.get('pid', 0))
            if not (0 <= age <= 100 and pid > 0):
                return False
            # Verifica se o PID continua ativo sem precisar de privilegios especiais.
            kernel = ctypes.windll.kernel32
            kernel.OpenProcess.argtypes = [ctypes.c_ulong, ctypes.c_int, ctypes.c_ulong]
            kernel.OpenProcess.restype = ctypes.c_void_p
            kernel.CloseHandle.argtypes = [ctypes.c_void_p]
            kernel.CloseHandle.restype = ctypes.c_int
            handle = kernel.OpenProcess(0x1000, False, pid)
            if not handle:
                return False
            try:
                buffer = ctypes.create_unicode_buffer(4096)
                length = ctypes.c_ulong(len(buffer))
                api = ctypes.windll.kernel32.QueryFullProcessImageNameW
                api.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_wchar_p, ctypes.POINTER(ctypes.c_ulong)]
                api.restype = ctypes.c_int
                return bool(api(handle, 0, buffer, ctypes.byref(length))) and Path(buffer.value).name.lower() == 'arcoll.winui3.exe'
            finally:
                ctypes.windll.kernel32.CloseHandle(handle)
        except (OSError, ValueError, TypeError, KeyError):
            return False

    def loop(self):
        last_letter = ''
        while not self.stop_event.is_set():
            try:
                if self.native_gestor_active():
                    if self.mounted or self.server or self.fs is not None:
                        self.unmount()
                        if self.server:
                            self.server.shutdown()
                            self.server.server_close()
                            self.server = None
                        self.say('Disco gerenciado pelo gestor Windows nativo.')
                    self.stop_event.wait(10)
                    continue
                info = getattr(self.cloud, 'latest_vdrive', None) or {}
                if info.get('available'):
                    letter = str(info.get('letter') or 'AUTO').strip().upper()
                    letter = 'AUTO' if letter == 'AUTO' else letter[:1]
                    if self.mounted and letter != last_letter:
                        self.unmount()
                    last_letter = letter
                    self.mount(letter, str(info.get('label') or ''))
                elif self.mounted or self.server or self.fs is not None:
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
