#!/usr/bin/env python3
# Caminho: support/Instalar_Suporte.py
"""Install the technician app for the current Windows user, without administrator rights."""
import os
import pathlib
import shutil
import subprocess
import sys

def main():
    if sys.platform!='win32':raise RuntimeError('Este instalador é destinado ao Windows.')
    if sys.version_info<(3,10):raise RuntimeError('Instale Python 3.10 ou superior em python.org.')
    source=pathlib.Path(__file__).resolve().parent
    target=pathlib.Path(os.environ['LOCALAPPDATA'])/'ArcollSuporte';target.mkdir(parents=True,exist_ok=True)
    # Isolated runtime preserves the existing network agent and its dependencies.
    runtime=target/'runtime'
    if not (runtime/'Scripts/python.exe').exists():subprocess.run([sys.executable,'-m','venv',str(runtime)],check=True)
    python=runtime/'Scripts/python.exe';pythonw=runtime/'Scripts/pythonw.exe'
    subprocess.run([str(python),'-m','pip','install','--disable-pip-version-check','-r',str(source/'requirements.txt')],check=True)
    for name in ('arcoll_support.py','arcoll_toolkit.py','platform.json'):
        if not(source/name).is_file():raise RuntimeError('Arquivo ausente: '+name)
        shutil.copyfile(source/name,target/name)
    # Windows shortcuts use COM rather than interpolated shell execution.
    command=[str(python),'-c',"import tkinter;import PIL;print('Runtime validado')"]
    subprocess.run(command,check=True)
    script="""$s=New-Object -ComObject WScript.Shell
$p=[Environment]::GetFolderPath('Desktop')
$l=$s.CreateShortcut((Join-Path $p 'Arcoll Suporte.lnk'))
$l.TargetPath=$env:ARC_SUPPORT_PYTHON
$l.Arguments='"'+$env:ARC_SUPPORT_SCRIPT+'"'
$l.WorkingDirectory=$env:ARC_SUPPORT_DIR
$l.Save()
"""
    env=os.environ.copy();env.update(ARC_SUPPORT_PYTHON=str(pythonw),ARC_SUPPORT_SCRIPT=str(target/'arcoll_support.py'),ARC_SUPPORT_DIR=str(target))
    subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-Command',script],env=env,check=True)
    (target/'Desinstalar.txt').write_text('Feche o aplicativo, exclua esta pasta e o atalho Arcoll Suporte da Área de Trabalho. O agente de rede não é alterado.\n',encoding='utf-8')
    subprocess.Popen([str(pythonw),str(target/'arcoll_support.py')],cwd=str(target))
    print('Arcoll Suporte instalado. Abra pelo atalho da Área de Trabalho.')
if __name__=='__main__':
    try:main()
    except Exception as exc:print('Falha na instalação:',exc);sys.exit(1)
