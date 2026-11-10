# Caminho: gestores/windows/arcoll_pf_updater.py
"""Módulo independente de updater; contratos e validações compartilhados no núcleo."""
import re
import shutil
import subprocess
from arcoll_pentefino import _run

_WINGET_ID = re.compile(r'[A-Za-z0-9][A-Za-z0-9.+_-]{1,120}\Z')


def _parse_winget_table(text):
    """Lê a tabela de `winget upgrade` pelas posições das colunas do cabeçalho (funciona em qualquer idioma)."""
    lines = [l.rstrip() for l in text.replace('\r', '\n').split('\n')]
    lines = [l for l in lines if l.strip() and l.strip() not in ('-', '\\', '|', '/') and not set(l.strip()) <= set('█▒░ ')]
    for index, line in enumerate(lines):
        if index and re.fullmatch(r'-{5,}', line.strip()):
            starts = [m.start() for m in re.finditer(r'\S+(?: \S+)*', lines[index - 1])]
            if len(starts) < 4:
                continue
            rows = []
            for row in lines[index + 1:]:
                if re.match(r'^\d+\s', row.strip()) and len(row) < starts[1] + 5:
                    break
                cells = [row[a:b].strip() for a, b in zip(starts, starts[1:] + [None])]
                if len(cells) >= 4 and _WINGET_ID.fullmatch(cells[1]):
                    rows.append({'name': cells[0][:120], 'id': cells[1], 'version': cells[2][:40], 'available': cells[3][:40]})
            return rows
    return []


def _winget_exe():
    path = shutil.which('winget')
    if not path:
        raise RuntimeError('O winget não está disponível neste computador.')
    return path


def _winget_list():
    out = _run([_winget_exe(), 'upgrade', '--accept-source-agreements', '--disable-interactivity'], 180)
    return _parse_winget_table(out.stdout)


def _tool_updater(payload, progress):
    report = {'tool': 'updater'}
    if payload.get('action') == 'apply':
        ids = payload.get('ids')
        if not isinstance(ids, list) or not ids or len(ids) > 30 or any(not isinstance(i, str) or not _WINGET_ID.fullmatch(i) for i in ids):
            raise ValueError('Seleção de atualizações inválida.')
        available = {r['id'] for r in _winget_list()}
        results = []
        for number, ident in enumerate(ids, 1):
            if ident not in available:
                results.append({'id': ident, 'ok': False, 'message': 'Sem atualização disponível.'})
                continue
            if progress:
                progress('Atualizando %d/%d: %s' % (number, len(ids), ident))
            try:
                proc = _run([_winget_exe(), 'upgrade', '--id', ident, '--exact', '--silent', '--accept-package-agreements',
                             '--accept-source-agreements', '--disable-interactivity'], 1800)
                results.append({'id': ident, 'ok': proc.returncode == 0, 'message': 'Atualizado.' if proc.returncode == 0 else 'Código %d' % proc.returncode})
            except (OSError, subprocess.SubprocessError) as exc:
                results.append({'id': ident, 'ok': False, 'message': str(exc)[:120]})
        report['results'] = results
    report['items'] = _winget_list()[:200]
    return report


# ----------------------------------------------------------------------------------------------------------------
# Verificação de integridade
# ----------------------------------------------------------------------------------------------------------------
