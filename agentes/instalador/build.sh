#!/usr/bin/env bash
# Caminho: agentes/instalador/build.sh
# Compila o instalador-base (ArcollSetup.exe) para Windows (.NET Framework 4.x).
# Requer: mono-mcs (apt install mono-mcs mono-devel libmono-system-windows-forms4.0-cil libmono-system-management4.0-cil)
# O executável gerado é o "stub": o servidor anexa o pacote do agente a ele a cada download.
set -euo pipefail
cd "$(dirname "$0")"
mcs -nologo -optimize+ -target:winexe -platform:anycpu -codepage:utf8 \
  -win32icon:ArcollSetup.ico -win32manifest:ArcollSetup.manifest \
  -r:System.dll -r:System.Core.dll -r:System.Drawing.dll -r:System.Windows.Forms.dll \
  -r:System.IO.Compression.dll -r:System.Management.dll \
  -out:ArcollSetup.exe ArcollSetup.cs
sha256sum ArcollSetup.exe
