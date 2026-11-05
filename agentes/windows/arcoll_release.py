# Caminho: agentes/windows/arcoll_release.py
"""Versão oficial da plataforma ou instantâneo da versão instalada."""
from pathlib import Path
import re

_here = Path(__file__).resolve().parent
_candidates = (_here / 'VERSION', _here.parent / 'VERSION', _here.parent.parent / 'VERSION')
for _path in _candidates:
    if _path.is_file():
        VERSION = _path.read_text(encoding='utf-8-sig').strip()
        break
else:
    raise RuntimeError('VERSION ausente: reinstale o agente com o pacote completo.')
if not re.fullmatch(r'\d+\.\d+\.\d+', VERSION):
    raise RuntimeError('VERSION inválido: reinstale o agente com o pacote completo.')
BUILD = ''
NOTES = ''
