# Caminho: agentes/windows/arcoll_cloud.py
"""Backup incremental com pastas definidas na plataforma autenticada.
O agente não recebe credenciais do provedor; o manifesto permanece local.
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


class OnlineOnly(CloudError):
    """Arquivo de pasta de sincronização (OneDrive/Google Drive) que existe só na nuvem: é ignorado, não é falha."""


def stamp():
    return datetime.now().astimezone().isoformat(timespec='seconds')


def file_version(info):
    """Identidade estável do conteúdo: tamanho + data de modificação (microssegundos).
    NÃO usar st_dev/st_ino/st_ctime: no Windows, os.stat(caminho) e os.fstat(handle) devolvem valores diferentes
    para esses campos, o que fazia todo arquivo parecer "alterado antes do envio" e nunca ser enviado."""
    return hashlib.sha256(('%s:%s' % (info.st_size, info.st_mtime_ns // 1000)).encode()).hexdigest()


def excluded(relative, patterns):
    name = relative.rsplit('/', 1)[-1]
    return any(fnmatch.fnmatchcase(relative.casefold(), p.casefold()) or fnmatch.fnmatchcase(name.casefold(), p.casefold()) for p in patterns)


UNSAFE_TAGS = {0xA000000C, 0xA0000003}   # IO_REPARSE_TAG_SYMLINK e IO_REPARSE_TAG_MOUNT_POINT (junction)


def is_redirect(info):
    """Somente links simbólicos e junctions são redirecionamentos. Pastas de sincronização (OneDrive/Drive)
    também têm o atributo de ponto de análise, mas são pastas normais e podem ser copiadas."""
    if stat.S_ISLNK(info.st_mode):
        return True
    if not getattr(info, 'st_file_attributes', 0) & REPARSE:
        return False
    tag = getattr(info, 'st_reparse_tag', None)
    return tag in UNSAFE_TAGS if tag is not None else True


def safe_local_path(path, root=None):
    """Não segue links, junctions, UNC, ADS ou arquivos somente online. A verificação de redirecionamento vai da
    pasta escolhida para baixo; pastas acima dela pertencem à escolha do administrador."""
    path = Path(path).absolute()
    text = str(path)
    if text.startswith(('\\\\', '//')) or (os.name == 'nt' and ':' in text[2:]):
        raise CloudError('Use somente uma pasta de disco local.')
    candidates = [path]
    if root is not None:
        base = Path(root).absolute()
        try:
            path.relative_to(base)
        except ValueError as exc:
            raise CloudError('Arquivo fora da pasta autorizada.') from exc
        for parent in path.parents:
            candidates.append(parent)
            if parent == base:
                break
    for candidate in candidates:
        if is_redirect(candidate.lstat()):
            raise CloudError('Links e pontos de redirecionamento não são incluídos.')
    if getattr(path.stat(), 'st_file_attributes', 0) & PLACEHOLDER:
        raise OnlineOnly('Arquivo disponível somente online; mantenha uma cópia local para backup.')
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
        self.latest_vdrive = {}
        self.last_report = 0
        self.opener = urllib.request.build_opener(NoRedirect())
        self._pending_identity = None

    def queue_credentials(self, fingerprint, site_code, key):
        """Ativa a nova credencial entre ciclos de sincronização, sem interromper transferências."""
        with self.guard:
            self._pending_identity = (fingerprint, site_code, key)
        self.wake.set()

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
                # Somente a mensagem curta do JSON da própria API é exibida. Nunca repasse cabeçalhos,
                # token, corpo de arquivo ou páginas HTML de erro ao painel de backup.
                explanation = ''
                if code in (400, 404, 409, 410, 413, 422, 429, 502, 503):
                    try:
                        failure = json.loads(exc.read(4096))
                        candidate = failure.get('error', '') if isinstance(failure, dict) else ''
                        if isinstance(candidate, str) and len(candidate) <= 200 and not any(c in candidate for c in '\r\n\x00'):
                            explanation = candidate
                    except (ValueError, OSError, UnicodeError):
                        pass
                if code not in (429, 500, 502, 503, 504) or attempt == 3:
                    message = explanation or {401: 'Credencial expirada.', 403: 'Backup sem autorização.', 409: 'Backup pausado ou configuração alterada.', 410: 'Envio expirado; será reiniciado.'}.get(code, 'A plataforma recusou o backup (HTTP %d).' % code)
                    raise CloudError(message, code) from None
            except (urllib.error.URLError, TimeoutError, OSError, ValueError):
                if attempt == 3:
                    raise CloudError('Sem comunicação segura com a plataforma.') from None
            if self.stop.wait(min(30, 2 ** attempt)):
                raise CloudError('Agente encerrando.')
        raise CloudError('Backup indisponível.')

    def stream(self, action, data=None):
        """Abre uma resposta binária da plataforma (download para o disco virtual). O chamador fecha o objeto."""
        payload = json.dumps(data or {}).encode('utf-8')
        request_headers = dict(self.headers, **{'Content-Type': 'application/json'})
        req = urllib.request.Request(self.origin + '/api/cloud.php?action=' + action, data=payload, headers=request_headers, method='POST')
        try:
            return self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as exc:
            message = ''
            try:
                failure = json.loads(exc.read(4096))
                message = failure.get('error', '') if isinstance(failure, dict) else ''
            except (ValueError, OSError, UnicodeError):
                pass
            raise CloudError((message if isinstance(message, str) and len(message) <= 200 else '') or 'A plataforma recusou a leitura (HTTP %d).' % exc.code, exc.code) from None
        except (urllib.error.URLError, TimeoutError, OSError):
            raise CloudError('Sem comunicação segura com a plataforma.') from None

    def report(self, force=False):
        if force or time.monotonic() - self.last_report >= 10:
            result = self.request('agent-state', {'status': self.snapshot()})
            self.last_report = time.monotonic()
            if self.job and (not result['job']['available'] or result['job']['revision'] != self.job['revision']):
                raise CloudError('Configuração alterada ou backup pausado.', 409)
            return result
        return None

    def check(self, path=None, root=None):
        if self.stop.is_set():
            raise CloudError('Agente encerrando.')
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
        self.update(state='scanning', message='Analisando pastas configuradas na plataforma.', started_at=stamp(), failures=[], files_done=0,
                    bytes_done=0, files_total=0, bytes_total=0, errors=0, current='')
        failures = []
        errors = 0
        def fail(path, message):
            nonlocal errors
            errors += 1
            if len(failures) < 12:
                failures.append({'path': path, 'message': message})
        files_done = bytes_done = total_files = total_bytes = unchanged = skipped = 0
        folder_stats = []
        self.report(True)
        for index, folder in enumerate(settings['folders']):
            root = Path(os.path.expandvars(folder['path']))
            stat_before = (total_files, total_bytes, files_done)
            missing = False
            try:
                safe_local_path(root)
                if not root.is_dir():
                    raise CloudError('Pasta indisponível.')
                scope = job['revision'] + ':' + hashlib.sha256(str(root).casefold().encode()).hexdigest()
                with self.db() as db:
                    known = dict(db.execute('SELECT relative,version FROM files WHERE identity=? AND scope=?', (self.identity, scope)).fetchall())
                def walk_error(error):
                    name = getattr(error, 'filename', None)
                    fail(str(name or folder['label'])[-200:], 'Subpasta sem permissão de leitura; as demais continuam.')
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
                        except OnlineOnly:
                            skipped += 1
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
                            if known.get(relative) == file_version(info):
                                unchanged += 1
                                continue
                            self.update(state='uploading', message='Enviando arquivos para a nuvem.', current=relative,
                                        files_total=total_files, bytes_total=total_bytes)
                            version = self.send_file(path, root, index, relative, info, job['revision'])
                            with self.db() as db:
                                db.execute('INSERT OR REPLACE INTO files VALUES (?,?,?,?)', (self.identity, scope, relative, version))
                            known[relative] = version
                            files_done += 1
                            bytes_done += info.st_size
                            self.update(files_done=files_done, bytes_done=bytes_done)
                        except OnlineOnly:
                            skipped += 1
                            total_files -= 1 if total_files else 0
                        except (CloudError, OSError) as exc:
                            if isinstance(exc, CloudError) and exc.status in (401, 403, 409):
                                raise
                            fail(relative, str(exc)[:160] if isinstance(exc, CloudError) else 'Arquivo indisponível para leitura.')
                        self.check()
                        self.report()
            except (CloudError, OSError) as exc:
                missing = True
                fail(folder['label'], str(exc)[:160] if isinstance(exc, CloudError) else 'Pasta indisponível neste computador.')
            folder_stats.append({'label': folder['label'], 'files': total_files - stat_before[0], 'bytes': total_bytes - stat_before[1],
                                 'pending': 0, 'missing': missing})
        at = stamp()
        run = {'at': at, 'files': files_done, 'bytes': bytes_done, 'errors': errors, 'seconds': int(time.monotonic() - started), 'result': 'error' if failures else 'success'}
        history = ([run] + self.preference('runs', []))[:15]
        self.remember('runs', history)
        synced_any = files_done + unchanged > 0
        state = 'error' if failures and not synced_any else 'idle'
        if failures:
            message = 'Backup concluído com %d pendência(s). Veja os itens que precisam de atenção.' % errors
        elif not settings['folders']:
            message = 'Nenhuma pasta configurada.'
        else:
            message = 'Pastas sincronizadas; arquivos da nuvem preservados.'
            if skipped:
                message += ' %d arquivo(s) somente online foram ignorados.' % skipped
        self.update(state=state, message=message, files_total=total_files, bytes_total=total_bytes, files_done=files_done, bytes_done=bytes_done,
                    synced_files=files_done + unchanged, errors=errors, skipped=skipped, failures=failures[:12], last_run_at=at, runs=history, current='',
                    folders=folder_stats)
        if synced_any and not failures:
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
            with self.guard:
                updated = self._pending_identity
                self._pending_identity = None
            if updated is not None:
                fingerprint, site_code, key = updated
                self.headers.update({'X-Arcoll-Fingerprint': fingerprint, 'X-Arcoll-Site-Code': site_code, 'X-Arcoll-Key': key})
                self.identity = hashlib.sha256((self.origin + '|' + fingerprint + '|' + key).encode()).hexdigest()
                self.update(state='waiting', message='Credencial atualizada. Verificando backup na plataforma.')
            ran = False
            try:
                self.job = None
                response = self.request('agent-state', {'status': self.snapshot()})
                self.latest_vdrive = response.get('vdrive') or {}
                job = response['job']
                if not job['available']:
                    self.update(state='disabled', message='Configure e ative o backup na plataforma.')
                elif not job['settings']['folders']:
                    self.update(state='waiting', message='Configure as pastas de origem na plataforma online.')
                elif self.due(job):
                    ran = True
                    self.prepare_full_run()
                    self.run_backup(job, int(response['max_file_bytes']))
            except CloudError as exc:
                ran = True
                self.update(state='error', message=str(exc)[:160])
            except Exception as exc:   # a thread de backup nunca pode morrer por um erro inesperado
                ran = True
                self.update(state='error', message='Backup indisponível: %s. Será tentado novamente.' % type(exc).__name__)
            try:
                if ran:
                    self.report(True)   # mesmo com erro, a plataforma recebe o motivo
            except Exception:
                pass
            self.wake.wait(6)
            self.wake.clear()
