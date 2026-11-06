# Caminho: agentes/servidor/Arcoll.WinUI3/Worker/clean_worker.py
"""Protocolo local JSON por stdin/stdout; reutiliza o motor original, sem porta HTTP nem comandos arbitrários."""
import json
import hashlib
import os
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')


def emit(**packet):
    print(json.dumps(packet, ensure_ascii=True), flush=True)


def run(request):
    if not isinstance(request, dict) or request.get('mode') not in ('scan', 'execute', 'tool', 'backup_status', 'backup_run'):
        raise ValueError('Operação local não permitida.')
    import arcoll_clean as clean
    identity = hashlib.sha256(json.dumps(request.get('config', {}), sort_keys=True).encode()).hexdigest()
    clean.DATA_DIR = Path(os.environ.get('LOCALAPPDATA', str(Path.home()))) / 'TI-Digital' / 'Clean' / identity
    payload = request.get('payload') or {}
    if not isinstance(payload, dict):
        raise ValueError('Requisição inválida.')
    progress = lambda text: emit(progress=str(text)[:500])
    mode = request['mode']
    if mode in ('scan', 'execute'):
        return clean.execute_clean(payload.get('categories'), mode == 'execute', payload.get('age_days', 7), progress,
                                   payload.get('exclusions'), payload.get('custom_folders'))
    if mode == 'tool':
        return clean.execute_tool(payload, progress)
    from arcoll_cloud import CloudBackup
    config = request['config']
    worker = CloudBackup(config['origin'], config['fingerprint'], config['site_code'], config['download_key'])
    response = worker.request('agent-state', {'status': worker.snapshot()})
    if mode == 'backup_run':
        job = response['job']
        if not job.get('available') or not job.get('settings', {}).get('folders'):
            raise ValueError('Configure e ative o backup e as pastas na plataforma online.')
        worker.run_backup(job, int(response['max_file_bytes']))
        worker.report(True)
        return {'tool': 'backup', 'status': worker.snapshot(), 'job': job, 'connection': response.get('connection')}
    return {'tool': 'backup', 'job': response['job'], 'connection': response.get('connection'), 'status': worker.snapshot()}


if __name__ == '__main__':
    try:
        data = sys.stdin.read(262145)
        if len(data) > 262144:
            raise ValueError('Requisição muito grande.')
        emit(result=run(json.loads(data)))
    except Exception as exc:
        emit(error=str(exc)[:1000])
        sys.exit(1)
