# Caminho: agentes/windows/arcoll_cloud.py
"""Backup incremental: lê somente raízes autorizadas localmente e envia blocos pela plataforma.
Nunca recebe credenciais do provedor. Manifesto e consentimento ficam no perfil local.
"""
import fnmatch
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime
from contextlib import contextmanager

from arcoll_release import VERSION

MAX_RESPONSE = 262144
REPARSE = 0x400
PLACEHOLDER = 0x1000 | 0x400000 | 0x40000


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class CloudError(RuntimeError):
    def __init__(self, message, status=0):
        super().__init__(message)
        self.status = status


def stamp():
    return datetime.now().astimezone().isoformat(timespec='seconds')


def file_version(info):
    return hashlib.sha256(('%s:%s:%s:%s:%s' % (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)).encode()).hexdigest()


def excluded(relative, patterns):
    name = relative.rsplit('/', 1)[-1]
    return any(fnmatch.fnmatchcase(relative.casefold(), p.casefold()) or fnmatch.fnmatchcase(name.casefold(), p.casefold()) for p in patterns)


def safe_local_path(path, root=None):
    """Não segue links, junctions, reparse points, UNC, ADS ou arquivos somente online."""
    path = Path(path).absolute()
    text = str(path)
    if text.startswith(('\\\\', '//')) or (os.name == 'nt' and ':' in text[2:]):
        raise CloudError('Use somente uma pasta de disco local.')
    for candidate in (path, *path.parents):
        info = candidate.lstat()
        if stat.S_ISLNK(info.st_mode) or getattr(info, 'st_file_attributes', 0) & REPARSE:
            raise CloudError('Links e pontos de redirecionamento não são incluídos.')
    if root is not None:
        try:
            path.relative_to(Path(root).absolute())
        except ValueError as exc:
            raise CloudError('Arquivo fora da pasta autorizada.') from exc
    if getattr(path.stat(), 'st_file_attributes', 0) & PLACEHOLDER:
        raise CloudError('Arquivo disponível somente online; mantenha uma cópia local para backup.')
    return path


class CloudBackup:
    def __init__(self, origin, fingerprint, site_code, key, stop_event=None, directory=None):
        parsed = urllib.parse.urlsplit(origin)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('Origem HTTPS inválida.')
        self.origin = origin.rstrip('/')
        self.headers = {'X-Arcoll-Fingerprint': fingerprint, 'X-Arcoll-Site-Code': site_code, 'X-Arcoll-Key': key,
                        'User-Agent': 'ArcollCloud/' + VERSION, 'Accept': 'application/json'}
        self.stop = stop_event or threading.Event()
        self.directory = Path(directory) if directory else Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'Arcoll' / 'CloudBackup'
        self.directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.database = self.directory / 'manifest.sqlite3'
        self.identity = hashlib.sha256((origin + '|' + fingerprint + '|' + key).encode()).hexdigest()
        with self.db() as db:
            db.executescript('CREATE TABLE IF NOT EXISTS preferences (identity TEXT, name TEXT, value TEXT, PRIMARY KEY(identity,name));'
                             'CREATE TABLE IF NOT EXISTS files (identity TEXT, scope TEXT, relative TEXT, version TEXT, PRIMARY KEY(identity,scope,relative));')
        try:
            self.database.chmod(0o600)
        except OSError:
            pass
        self.wake = threading.Event()
        self.run_requested = threading.Event()
        self.full_requested = threading.Event()
        if self.preference('full_pending', False):
            self.full_requested.set()
        self.guard = threading.RLock()
        self.status = {'state': 'waiting', 'message': 'Aguardando configuração.', 'runs': [], 'agent_version': VERSION}
        self.status['last_success_at'] = self.preference('last_success_at', '')
        self.status['runs'] = self.preference('runs', [])
        self.job = None
        self.local_paused = self.preference('paused', False)
        self.last_report = 0
        self.opener = urllib.request.build_opener(NoRedirect())

    @contextmanager
    def db(self):
        connection = sqlite3.connect(str(self.database), timeout=15)
        try:
            with connection:
                yield connection
        finally:
            connection.close()

    def preference(self, name, default=None):
        with self.db() as db:
            row = db.execute('SELECT value FROM preferences WHERE identity=? AND name=?', (self.identity, name)).fetchone()
        return json.loads(row[0]) if row else default

    def remember(self, name, value):
        with self.db() as db:
            db.execute('INSERT OR REPLACE INTO preferences VALUES (?,?,?)', (self.identity, name, json.dumps(value)))

    def snapshot(self):
        with self.guard:
            return json.loads(json.dumps(self.status))

    def update(self, **fields):
        with self.guard:
            self.status.update(fields)

    def request(self, action, data=None, binary=None, headers=None):
        payload = binary if binary is not None else json.dumps(data or {}).encode('utf-8')
        request_headers = dict(self.headers, **{'Content-Type': 'application/octet-stream' if binary is not None else 'application/json'})
        request_headers.update(headers or {})
        for attempt in range(4):
            if self.stop.is_set():
                raise CloudError('Agente encerrando.')
            req = urllib.request.Request(self.origin + '/api/cloud.php?action=' + action, data=payload, headers=request_headers, method='POST')
            try:
                with self.opener.open(req, timeout=180 if binary is not None else 45) as response:
                    raw = response.read(MAX_RESPONSE + 1)
                if len(raw) > MAX_RESPONSE:
                    raise CloudError('Resposta de backup fora do limite.')
                result = json.loads(raw)
                if not isinstance(result, dict) or not result.get('ok'):
                    raise CloudError('A plataforma não confirmou o backup.')
                return result
            except urllib.error.HTTPError as exc:
                code = exc.code
                # Do not reflect raw server responses or authentication headers to status/logs.
                if code not in (429, 500, 502, 503, 504) or attempt == 3:
                    raise CloudError({401: 'Credencial expirada.', 403: 'Backup sem autorização.', 409: 'Backup pausado ou configuração alterada.', 410: 'Envio expirado; será reiniciado.'}.get(code, 'A plataforma recusou o backup (HTTP %d).' % code), code) from None
            except (urllib.error.URLError, TimeoutError, OSError, ValueError):
                if attempt == 3:
                    raise CloudError('Sem comunicação segura com a plataforma.') from None
            if self.stop.wait(min(30, 2 ** attempt)):
                raise CloudError('Agente encerrando.')
        raise CloudError('Backup indisponível.')

    def authorize(self, folder):
        """Chamada somente pelo seletor local, após autenticação do gestor na interface."""
        root = safe_local_path(folder)
        if not root.is_dir() or root == Path(root.anchor):
            raise CloudError('Selecione uma pasta de dados, não a raiz do disco.')
        if os.name == 'nt':
            forbidden = [Path(os.environ.get(k, default)).absolute() for k, default in
                         [('WINDIR', r'C:\Windows'), ('ProgramFiles', r'C:\Program Files'), ('ProgramData', r'C:\ProgramData')]]
            if any(root == p or p in root.parents for p in forbidden):
                raise CloudError('Pastas de sistema não podem ser incluídas.')
        roots = self.preference('roots', [])
        normalized = os.path.normcase(str(root))
        if any(os.path.normcase(x['path']) == normalized for x in roots):
            return roots
        if len(roots) >= 20:
            raise CloudError('Limite de 20 pastas autorizadas.')
        roots.append({'path': str(root), 'label': root.name})
        # Persist consent first. A failed publication cannot authorize any other directory.
        self.remember('roots', roots)
        self.publish_roots(roots)
        return roots

    def publish_roots(self, roots=None):
        self.request('agent-folders', {'folders': self.preference('roots', []) if roots is None else roots})

    def remove_root(self, path):
        roots = [r for r in self.preference('roots', []) if r['path'] != path]
        self.remember('roots', roots)  # immediately revokes local access, even offline
        self.publish_roots(roots)

    def pause(self, paused):
        self.local_paused = bool(paused)
        self.remember('paused', self.local_paused)
        self.update(state='paused' if self.local_paused else 'waiting', message='Pausado neste computador.' if self.local_paused else 'Aguardando próxima sincronização.')
        self.wake.set()

    def report(self, force=False):
        if force or time.monotonic() - self.last_report >= 10:
            result = self.request('agent-state', {'status': self.snapshot()})
            self.last_report = time.monotonic()
            if self.job and (not result['job']['available'] or result['job']['revision'] != self.job['revision']):
                raise CloudError('Configuração alterada ou backup pausado.', 409)
            return result
        return None

    def check(self, path=None, root=None):
        if self.stop.is_set() or self.local_paused:
            raise CloudError('Backup pausado neste computador.')
        if root and os.path.normcase(str(root)) not in {os.path.normcase(x['path']) for x in self.preference('roots', [])}:
            raise CloudError('Autorização local da pasta removida.')
        if path is not None:
            safe_local_path(path, root)

    def send_file(self, path, root, index, relative, info, revision):
        version = file_version(info)
        self.check(path, root)
        flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0)
        with os.fdopen(os.open(path, flags), 'rb') as source:
            self.check(path, root)
            if file_version(os.fstat(source.fileno())) != version:
                raise CloudError('Arquivo alterado antes do envio; nova tentativa no próximo ciclo.')
            begin = self.request('agent-begin', {'folder': index, 'relative': relative, 'size': info.st_size,
                                               'mtime': int(info.st_mtime), 'file_version': hashlib.sha256((version + ':' + str(self.preference('generation', 0))).encode()).hexdigest(), 'revision': revision})
            chunk = int(begin['chunk'])
            if not 1 <= chunk <= 5242880:
                raise CloudError('Tamanho de bloco inválido.')
            offset = int(begin.get('next_offset', 0))
            stalls = 0
            while not begin['done']:
                self.check(path, root)
                if file_version(os.fstat(source.fileno())) != version:
                    raise CloudError('Arquivo em uso ou alterado; nova tentativa no próximo ciclo.')
                if not 0 <= offset < info.st_size:
                    raise CloudError('Posição de envio inválida.')
                source.seek(offset)
                data = source.read(min(chunk, info.st_size - offset))
                if not data:
                    raise CloudError('Arquivo não está mais disponível.')
                if file_version(os.fstat(source.fileno())) != version:
                    raise CloudError('Arquivo alterado durante a leitura.')
                started = time.monotonic()
                result = self.request('agent-chunk', binary=data, headers={'X-Arcoll-Upload': begin['upload_id'],
                                      'X-Arcoll-Revision': revision, 'X-Arcoll-Offset': str(offset),
                                      'X-Arcoll-Sha256': hashlib.sha256(data).hexdigest()})
                next_offset = int(result['next_offset'])
                if next_offset < 0 or next_offset > info.st_size:
                    raise CloudError('Confirmação de envio inválida.')
                stalls = stalls + 1 if next_offset == offset else 0
                if stalls > 3:
                    raise CloudError('O provedor não avançou o envio.')
                offset = next_offset
                limit = int(self.job['settings'].get('bandwidth_kbps', 0)) * 1024
                if limit:
                    until = started + len(data) / limit
                    while time.monotonic() < until:
                        self.check(path, root)
                        self.stop.wait(min(1, until - time.monotonic()))
                self.update(current=relative, speed_bps=int(len(data) / max(.001, time.monotonic() - started)))
                self.report()
                if result.get('done'):
                    if offset != info.st_size:
                        raise CloudError('O provedor não confirmou o tamanho completo.')
                    break
            if file_version(os.fstat(source.fileno())) != version:
                raise CloudError('Arquivo mudou ao concluir; será reenviado no próximo ciclo.')
        return version

    def run_backup(self, job, max_bytes):
        self.job = job
        started = time.monotonic()
        settings = job['settings']
        with self.db() as db:
            db.execute('DELETE FROM files WHERE identity=? AND scope NOT LIKE ?', (self.identity, job['revision'] + ':%'))
        consent = {os.path.normcase(x['path']) for x in self.preference('roots', [])}
        self.update(state='scanning', message='Analisando pastas autorizadas.', started_at=stamp(), failures=[], files_done=0,
                    bytes_done=0, files_total=0, bytes_total=0, errors=0, current='')
        failures = []
        errors = 0
        def fail(path, message):
            nonlocal errors
            errors += 1
            if len(failures) < 12:
                failures.append({'path': path, 'message': message})
        files_done = bytes_done = total_files = total_bytes = unchanged = 0
        self.report(True)
        for index, folder in enumerate(settings['folders']):
            root = Path(folder['path'])
            if os.path.normcase(str(root)) not in consent:
                fail(folder['label'], 'Pasta sem consentimento local. Autorize-a no agente.')
                continue
            try:
                safe_local_path(root)
                if not root.is_dir():
                    raise CloudError('Pasta indisponível.')
                scope = job['revision'] + ':' + hashlib.sha256(str(root).casefold().encode()).hexdigest()
                def walk_error(error):
                    raise CloudError('Pasta ou subpasta sem permissão de leitura.')
                for directory, dirs, names in os.walk(root, followlinks=False, onerror=walk_error):
                    self.check(Path(directory), root)
                    kept = []
                    for name in dirs:
                        candidate = Path(directory) / name
                        rel = candidate.relative_to(root).as_posix()
                        if excluded(rel, settings['exclusions']):
                            continue
                        try:
                            safe_local_path(candidate, root)
                            kept.append(name)
                        except (CloudError, OSError):
                            fail(rel, 'Pasta redirecionada ou indisponível; não foi lida.')
                    dirs[:] = kept
                    for name in names:
                        path = Path(directory) / name
                        relative = path.relative_to(root).as_posix()
                        if excluded(relative, settings['exclusions']):
                            continue
                        try:
                            self.check(path, root)
                            info = path.stat()
                            if not stat.S_ISREG(info.st_mode):
                                continue
                            if info.st_size > min(max_bytes, int(settings['max_file_mb']) * 1048576):
                                raise CloudError('Arquivo acima do limite configurado.')
                            total_files += 1
                            total_bytes += info.st_size
                            with self.db() as db:
                                row = db.execute('SELECT version FROM files WHERE identity=? AND scope=? AND relative=?', (self.identity, scope, relative)).fetchone()
                            if row and row[0] == file_version(info):
                                unchanged += 1
                                continue
                            self.update(state='uploading', message='Enviando arquivos para a nuvem.', current=relative,
                                        files_total=total_files, bytes_total=total_bytes)
                            version = self.send_file(path, root, index, relative, info, job['revision'])
                            with self.db() as db:
                                db.execute('INSERT OR REPLACE INTO files VALUES (?,?,?,?)', (self.identity, scope, relative, version))
                            files_done += 1
                            bytes_done += info.st_size
                            self.update(files_done=files_done, bytes_done=bytes_done)
                        except (CloudError, OSError) as exc:
                            if isinstance(exc, CloudError) and exc.status in (401, 403, 409):
                                raise
                            fail(relative, str(exc)[:160] if isinstance(exc, CloudError) else 'Arquivo indisponível para leitura.')
                        self.check()
                        self.report()
            except OSError:
                fail(folder['label'], 'Pasta indisponível neste computador.')
        at = stamp()
        run = {'at': at, 'files': files_done, 'bytes': bytes_done, 'errors': errors, 'seconds': int(time.monotonic() - started), 'result': 'error' if failures else 'success'}
        history = ([run] + self.preference('runs', []))[:15]
        self.remember('runs', history)
        self.update(state='error' if failures else 'idle', message='Backup concluído com pendências.' if failures else 'Pastas sincronizadas; arquivos da nuvem preservados.',
                    files_total=total_files, bytes_total=total_bytes, files_done=files_done, bytes_done=bytes_done,
                    synced_files=files_done + unchanged, errors=errors, failures=failures[:12], last_run_at=at, runs=history, current='')
        if not failures:
            self.update(last_success_at=at)
            self.remember('last_success_at', at)
        self.remember('last_run', time.time())
        self.remember('run_ack', job['run_request'])
        self.run_requested.clear()
        self.update(run_ack=job['run_request'])
        self.report(True)

    def request_run(self, full=False):
        if full:
            self.remember('full_pending', True)
            self.full_requested.set()
        self.run_requested.set()
        self.wake.set()

    def prepare_full_run(self):
        if not self.full_requested.is_set():
            return
        with self.db() as db:
            db.execute('DELETE FROM files WHERE identity=?', (self.identity,))
        self.remember('generation', int(self.preference('generation', 0)) + 1)
        self.remember('full_pending', False)
        self.full_requested.clear()

    def due(self, job):
        if self.run_requested.is_set() or self.full_requested.is_set():
            return True
        if int(job['run_request']) > self.preference('run_ack', 0):
            return True
        last = float(self.preference('last_run', 0))
        settings = job['settings']
        if settings['schedule'] == 'continuous':
            return time.time() - last >= 30
        if settings['schedule'] == 'manual':
            return False
        if settings['schedule'] == 'interval':
            return time.time() - last >= int(settings['interval_minutes']) * 60
        now = datetime.now()
        return now.strftime('%H:%M') >= settings['daily_time'] and (not last or datetime.fromtimestamp(last).date() < now.date())

    def loop(self):
        while not self.stop.is_set():
            try:
                self.job = None
                response = self.request('agent-state', {'status': self.snapshot()})
                job = response['job']
                if self.local_paused:
                    self.update(state='paused', message='Pausado neste computador.')
                elif not job['available']:
                    self.update(state='disabled', message='Configure e ative o backup na plataforma.')
                elif not job['settings']['folders']:
                    self.update(state='waiting', message='Autorize as pastas no agente Windows.')
                elif self.due(job):
                    self.prepare_full_run()
                    self.run_backup(job, int(response['max_file_bytes']))
            except (CloudError, OSError, ValueError, KeyError) as exc:
                self.update(state='paused' if self.local_paused else 'error', message=str(exc)[:160] if isinstance(exc, CloudError) else 'Backup indisponível. Verifique a conexão e as pastas.')
            self.wake.wait(30)
            self.wake.clear()
