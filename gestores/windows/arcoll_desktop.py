#!/usr/bin/env python3
# Caminho: gestores/windows/arcoll_desktop.py
"""Atalho de compatibilidade para instalacoes anteriores do gestor."""
from pathlib import Path
import runpy
from arcoll_release import VERSION

if __name__ == '__main__':
    runpy.run_path(str(Path(__file__).with_name('arcoll_app.py')), run_name='__main__')
