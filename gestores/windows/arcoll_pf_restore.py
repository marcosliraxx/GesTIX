# Caminho: gestores/windows/arcoll_pf_restore.py
"""Módulo independente de restore; contratos e validações compartilhados no núcleo."""
import json
import subprocess
from arcoll_pentefino import _powershell

def _tool_restore(payload, progress):
    report = {'tool': 'restore'}
    if payload.get('action') == 'create':
        if progress:
            progress('Criando ponto de restauração...')
        proc = _powershell("Checkpoint-Computer -Description 'Arcoll Pente-Fino' -RestorePointType MODIFY_SETTINGS -ErrorAction Stop", 300)
        report['created'] = proc.returncode == 0
        if proc.returncode != 0:
            report['message'] = (proc.stderr or proc.stdout or 'Falha ao criar o ponto.').strip()[:300]
    try:
        query = _powershell('ConvertTo-Json -InputObject @(Get-ComputerRestorePoint -ErrorAction Stop | Select-Object SequenceNumber,Description,CreationTime,RestorePointType) -Compress', 90)
        if query.returncode != 0:
            raise RuntimeError((query.stderr or query.stdout or 'O Windows não confirmou a consulta.').strip()[:300])
        raw = query.stdout.strip()
        points = json.loads(raw) if raw else []
        points = points if isinstance(points, list) else [points]
        report['points'] = [{'sequence': int(p.get('SequenceNumber') or 0), 'description': str(p.get('Description') or '')[:120],
                             'created': str(p.get('CreationTime') or '')[:14], 'type': int(p.get('RestorePointType') or 0)} for p in points if isinstance(p, dict)][-30:]
        report['complete'] = True
    except (OSError, subprocess.SubprocessError, ValueError, TypeError, RuntimeError) as exc:
        report['points'] = []
        report['complete'] = False
        detail = 'Não foi possível consultar os pontos de restauração: ' + str(exc)[:300]
        report['message'] = (report.get('message', '') + ' ' + detail).strip()
    return report


# ----------------------------------------------------------------------------------------------------------------
# Atualizador de software (winget)
# ----------------------------------------------------------------------------------------------------------------
