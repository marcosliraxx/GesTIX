#!/usr/bin/env python3
# Caminho: scripts/bump_version.py
"""Atualiza a fonte única VERSION; componentes e cache já consultam essa fonte."""
from pathlib import Path
import os
import tempfile
import re
import sys

ROOT = Path(__file__).resolve().parents[1]


def main():
    version = sys.argv[1] if len(sys.argv) == 2 else ''
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise SystemExit('Use: python3 scripts/bump_version.py X.Y.Z')
    with tempfile.NamedTemporaryFile('w', encoding='utf-8', dir=ROOT, prefix='.release-', delete=False) as stream:
        stream.write(version + '\n')
        temporary = Path(stream.name)
    try:
        temporary.chmod(0o644)
        os.replace(temporary, ROOT / 'VERSION')
    finally:
        temporary.unlink(missing_ok=True)
    native = ROOT / 'agentes/windows/Arcoll.WinUI3'
    for name in ('Arcoll.WinUI3.csproj', 'BootWorker/BootWorker.csproj'):
        project = native / name
        if project.exists():
            source = project.read_text(encoding='utf-8-sig')
            source = re.sub(r'<Version>[^<]+</Version>', '<Version>' + version + '</Version>', source)
            project.write_text(source, encoding='utf-8')
    snapshot = native / 'Models/AgentSnapshot.cs'
    if snapshot.exists():
        source = re.sub(r'\?\? "\d+\.\d+\.\d+"', '?? "' + version + '"', snapshot.read_text())
        snapshot.write_text(source)
    print('Versão única da plataforma e dos componentes:', version)


if __name__ == '__main__':
    main()
