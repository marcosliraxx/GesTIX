#!/usr/bin/env python3
# Caminho: support/Instalar_Suporte.py
"""Install the technician app for the current Windows user, without administrator rights."""
import os
import pathlib
import shutil
import subprocess
import sys

def install(report=print):
    if sys.platform!='win32':raise RuntimeError('Este instalador é destinado ao Windows.')
    if sys.version_info<(3,10):raise RuntimeError('Instale Python 3.10 ou superior em python.org.')
    source=pathlib.Path(__file__).resolve().parent
    target=pathlib.Path(os.environ['LOCALAPPDATA'])/'ArcollSuporte';target.mkdir(parents=True,exist_ok=True)
    report('Preparando ambiente do aplicativo…')
    # Isolated runtime preserves the existing network agent and its dependencies.
    runtime=target/'runtime'
    if not (runtime/'Scripts/python.exe').exists():subprocess.run([sys.executable,'-m','venv',str(runtime)],check=True)
    python=runtime/'Scripts/python.exe';pythonw=runtime/'Scripts/pythonw.exe'
    report('Instalando componentes verificados…')
    subprocess.run([str(python),'-m','pip','install','--disable-pip-version-check','-r',str(source/'requirements.txt')],check=True)
    report('Copiando aplicativo e identidade visual…')
    for name in ('arcoll_support.py','arcoll_toolkit.py','platform.json','favicon.ico'):
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
$l.IconLocation=(Join-Path $env:ARC_SUPPORT_DIR 'favicon.ico')
$l.Save()
"""
    env=os.environ.copy();env.update(ARC_SUPPORT_PYTHON=str(pythonw),ARC_SUPPORT_SCRIPT=str(target/'arcoll_support.py'),ARC_SUPPORT_DIR=str(target))
    subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-Command',script],env=env,check=True)
    (target/'Desinstalar.txt').write_text('Feche o aplicativo, exclua esta pasta e o atalho Arcoll Suporte da Área de Trabalho. O agente de rede não é alterado.\n',encoding='utf-8')
    subprocess.Popen([str(pythonw),str(target/'arcoll_support.py')],cwd=str(target))
    print('Arcoll Suporte instalado. Abra pelo atalho da Área de Trabalho.')
def main():
    import tkinter as tk
    from tkinter import ttk
    import queue,threading,json
    from arcoll_toolkit import AppearanceManager,apply_platform_design,theme_color,bind_theme
    source=pathlib.Path(__file__).resolve().parent
    try:apply_platform_design(json.loads((source/'platform.json').read_text(encoding='utf-8')).get('design',{}))
    except (OSError,ValueError):pass
    root=tk.Tk();root.title('Instalar Arcoll Suporte');root.geometry('520x300');root.resizable(False,False);root.configure(bg=theme_color('bg'))
    appearance=AppearanceManager(root)
    bind_theme(tk.Label(root,text='Arcoll Suporte',font=('Segoe UI',24,'bold')),background='bg',foreground='accent').pack(pady=(25,10))
    status=tk.StringVar(value='Pronto para instalar no seu usuário do Windows.');bind_theme(tk.Label(root,textvariable=status,wraplength=470),background='bg',foreground='text').pack(pady=10)
    progress=ttk.Progressbar(root,mode='indeterminate');progress.pack(fill='x',padx=30,pady=10);events=queue.Queue();running=False
    def start():
        nonlocal running
        if running:return
        running=True;button.configure(state='disabled');progress.start()
        def work():
            try:install(lambda text:events.put(('status',text)));events.put(('done','Instalação concluída. Aplicativo aberto.'))
            except Exception as exc:events.put(('error','Não foi possível instalar: '+str(exc)))
        threading.Thread(target=work,daemon=True).start()
    def tick():
        nonlocal running
        while not events.empty():
            kind,text=events.get();status.set(text)
            if kind in ('done','error'):progress.stop();running=False;button.configure(state='normal',text='Fechar' if kind=='done' else 'Tentar novamente',command=root.destroy if kind=='done' else start)
        root.after(100,tick)
    button=ttk.Button(root,text='Instalar aplicativo de suporte',command=start);button.pack(pady=12)
    appearance.apply()
    root.protocol('WM_DELETE_WINDOW',lambda:None if running else root.destroy());tick();root.mainloop()
if __name__=='__main__':main()
