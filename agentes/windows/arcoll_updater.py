#!/usr/bin/env python3
# Caminho: agentes/windows/arcoll_updater.py
"""Apply an authenticated, preverified Arcoll update after the desktop exits."""
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import sys
import time
import threading
import tkinter as tk
from tkinter import ttk
from arcoll_toolkit import AppearanceManager, theme_color, bind_theme, bind_canvas

from arcoll_release import VERSION

import re

VALID_NAME = re.compile(r'[A-Za-z][A-Za-z0-9_-]*\.(?:py|ps1)\Z')
MANAGED = '.arcoll-managed-files.json'


def wait_for_exit(pid):
    """os.kill(pid, 0) terminates processes on Windows; use a read-only wait handle."""
    if pid<=0 or pid==os.getpid():raise ValueError('Processo de origem inválido')
    if os.name=='nt':
        import ctypes
        from ctypes import wintypes
        kernel=ctypes.WinDLL('kernel32',use_last_error=True)
        kernel.OpenProcess.argtypes=[wintypes.DWORD,wintypes.BOOL,wintypes.DWORD]
        kernel.OpenProcess.restype=wintypes.HANDLE
        kernel.WaitForSingleObject.argtypes=[wintypes.HANDLE,wintypes.DWORD]
        kernel.WaitForSingleObject.restype=wintypes.DWORD
        kernel.CloseHandle.argtypes=[wintypes.HANDLE]
        handle=kernel.OpenProcess(0x00100000,False,pid)
        if not handle:
            if ctypes.get_last_error()==87:return
            raise RuntimeError('Não foi possível aguardar o processo anterior com segurança.')
        try:
            if kernel.WaitForSingleObject(handle,30000)!=0:raise RuntimeError('O agente anterior não encerrou. Nenhum arquivo foi alterado.')
        finally:kernel.CloseHandle(handle)
    else:
        for _ in range(120):
            try:os.kill(pid,0)
            except ProcessLookupError:return
            time.sleep(.25)
        raise RuntimeError('O agente anterior não encerrou. Nenhum arquivo foi alterado.')


def installer_path(name):
    from pathlib import PurePosixPath
    if not isinstance(name,str) or '\\' in name or ':' in name or not name or name.startswith('/') or any(ord(c)<32 for c in name):raise ValueError('Caminho inválido no instalador.')
    path=PurePosixPath(name)
    if any(p in ('.','..') or not p or p.endswith(('.', ' ')) for p in name.split('/')):raise ValueError('Caminho inseguro no instalador.')
    if any(re.match(r'^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)',p,re.I) for p in path.parts):raise ValueError('Nome reservado no instalador.')
    if path.name.casefold() in ('agent-config.json','agent.json','appearance.json','.arcoll-managed-files.json'):raise ValueError('O instalador não pode substituir configuração privada.')
    return path


def stage_installer(package,stage):
    """Verify the whole installer and each entry before extracting any managed file."""
    import base64,io,zipfile,stat
    if not isinstance(package,dict) or not package.get('ok'):raise ValueError('Instalador indisponível.')
    binary=base64.b64decode(package.get('archive',''),validate=True)
    if not binary or len(binary)>33554432 or hashlib.sha256(binary).hexdigest()!=package.get('sha256'):raise ValueError('Integridade do instalador inválida.')
    rows=package.get('manifest');required={'arcoll_app.py','arcoll_programs.py','arcoll_clean.py','arcoll_cc_ui.py','arcoll_cloud.py','arcoll_desktop.py','arcoll_remote.py','arcoll_secure_helper.py','arcoll_release.py','arcoll_agent.py','arcoll_updater.py','arcoll_toolkit.py','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1','Desinstalar_Arcoll.ps1','rt_engine.py','connection_diagnostics.py'}
    if not isinstance(rows,list) or not 6<=len(rows)<=2000:raise ValueError('Inventário do instalador inválido.')
    expected={};seen=set()
    for row in rows:
        path=str(installer_path(row.get('path')));fold=path.casefold()
        if fold in seen:raise ValueError('Arquivo duplicado no instalador.')
        seen.add(fold);expected[path]=row
    if not required.issubset(expected):raise ValueError('Instalador completo sem componentes essenciais.')
    validated={};total=0
    with zipfile.ZipFile(io.BytesIO(binary)) as archive:
        entries=archive.infolist()
        if len(entries)!=len(expected):raise ValueError('O ZIP não corresponde ao manifesto.')
        for entry in entries:
            path=str(installer_path(entry.filename))
            if entry.is_dir() or path not in expected or path in validated or stat.S_ISLNK(entry.external_attr>>16) or entry.flag_bits&1:raise ValueError('Entrada inválida no instalador.')
            total+=entry.file_size
            if total>134217728 or entry.file_size>33554432:raise ValueError('Instalador excede o limite.')
            data=archive.read(entry);row=expected[path]
            if len(data)!=row.get('size') or hashlib.sha256(data).hexdigest()!=row.get('sha256'):raise ValueError('Arquivo corrompido no instalador: '+path)
            if path.endswith('.py'):compile(data,path,'exec')
            validated[path]=data
    # No extraction occurs until every file has passed integrity and Python syntax checks.
    for name,data in validated.items():
        dest=stage/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
    (stage/'installer.zip').write_bytes(binary)
    return {'files':[{'name':name,'sha256':hashlib.sha256(data).hexdigest()} for name,data in validated.items()],'inventory':list(validated),'remove':[],'installer_complete':True,'release_version':str(package.get('version') or ''),'release_notes':str(package.get('notes') or '')[:12000]}


def download_installer(origin,root):
    import urllib.request
    config={}
    for path in (root/'agent-config.json',pathlib.Path(os.environ.get('APPDATA',str(pathlib.Path.home())))/'Arcoll'/'agent.json'):
        if path.is_file():config.update(json.loads(path.read_text(encoding='utf-8-sig')))
    if str(config.get('origin','')).rstrip('/')!=origin.rstrip('/'):raise ValueError('A origem do instalador diverge da instalação.')
    auth={key:str(config.get(key) or '') for key in ('fingerprint','site_code','download_key')}
    if not re.fullmatch(r'[a-f0-9]{64}',auth['fingerprint']) or not re.fullmatch(r'[a-f0-9]{64}',auth['download_key']) or not re.fullmatch(r'[A-Z0-9]{5}',auth['site_code']):raise ValueError('Cadastro local incompleto para reparar a instalação.')
    request=urllib.request.Request(origin.rstrip('/')+'/api/api.php?action=agent-installer-package',data=json.dumps(auth).encode(),headers={'Content-Type':'application/json'},method='POST')
    with urllib.request.urlopen(request,timeout=90) as response:
        if response.geturl()!=request.full_url:raise ValueError('Redirecionamento de instalador não autorizado.')
        data=response.read(50000001)
    if len(data)>50000000:raise ValueError('Resposta do instalador excede o limite.')
    return json.loads(data)


def safe_install_target(root,name):
    installer_path(name);target=root/name
    for path in (target,*target.parents):
        if path.is_symlink() or (path.exists() and getattr(path.lstat(),'st_file_attributes',0)&0x400):raise ValueError('Redirecionamento na instalação não permitido.')
        if path==root:break
    return target


def run(report=lambda _message,_percent:None):
    if len(sys.argv) not in (4,5):
        raise ValueError('Argumentos de atualização inválidos')
    staging = pathlib.Path(sys.argv[1]).resolve()
    root = pathlib.Path(sys.argv[4]).resolve() if len(sys.argv)==5 else pathlib.Path(__file__).resolve().parent
    if not (root/'arcoll_app.py').is_file():raise ValueError('Pasta do agente não encontrada')
    pid = int(sys.argv[2]); origin = sys.argv[3]
    if not origin.startswith('https://'):
        raise ValueError('Origem HTTPS inválida')
    data = json.loads((staging / 'manifest.json').read_text(encoding='utf-8'))
    # Transitional older agents launch this updater with the legacy source manifest.
    # They also receive the complete installer before any installed file is changed.
    if os.name=='nt' and not data.get('installer_complete') and (staging/'Instalar_Arcoll.ps1').is_file() and (staging/'arcoll_release.py').is_file():
        original_command=data.get('remote_command_id');data=stage_installer(download_installer(origin,root),staging);data['remote_command_id']=original_command
    files = data.get('files'); inventory=data.get('inventory'); removals=data.get('remove')
    complete=bool(data.get('installer_complete'))

    if not isinstance(files,list) or not isinstance(inventory,list) or not isinstance(removals,list):
        raise ValueError('Manifesto incompleto')
    if not 6 <= len(inventory) <= (2000 if complete else 80) or len(set(inventory)) != len(inventory) or any(not isinstance(n,str) or (not complete and not VALID_NAME.fullmatch(n)) for n in inventory):
        raise ValueError('Inventário inválido')
    if len(set(removals))!=len(removals) or any(not isinstance(n,str) or (not complete and not VALID_NAME.fullmatch(n)) or n in inventory for n in removals):
        raise ValueError('Remoção inválida')
    prior_path=root/MANAGED
    try:
        prior=json.loads(prior_path.read_text(encoding='utf-8-sig')) if prior_path.exists() else []
        if not isinstance(prior,list):prior=[]
    except (OSError,ValueError):prior=[]
    if not set(removals).issubset(set(prior)):
        raise ValueError('Não é permitido remover arquivo não gerenciado')
    seen=set()
    for row in files:
        if not isinstance(row,dict):raise ValueError('Entrada inválida')
        name=row.get('name')
        if not isinstance(name,str) or (not complete and not VALID_NAME.fullmatch(name)) or name not in inventory or name in seen:
            raise ValueError('Arquivo não permitido')
        seen.add(name)
        if hashlib.sha256((staging/name).read_bytes()).hexdigest()!=row.get('sha256'):
            raise ValueError('Falha de integridade: '+name)
    if complete and seen!=set(inventory):raise ValueError('Inventário incompleto.')
    if complete:
        removals=sorted(set(n for n in prior if isinstance(n,str) and n not in inventory and n not in ('agent-config.json','agent.json','appearance.json')))
        for name in inventory+removals:installer_path(name)
    report('Aguardando encerramento seguro...', 10)
    wait_for_exit(pid)
    global _stopped_agent
    _stopped_agent=(root,origin)
    backup=staging/'backup';backup.mkdir(exist_ok=True)
    touched=[]
    old_manifest=prior_path.read_bytes() if prior_path.exists() else None
    try:
        operations=[('write',row['name']) for row in files]+[('remove',n) for n in removals]
        for index,(action,name) in enumerate(operations):
            report(('Instalando ' if action=='write' else 'Removendo ')+name,int(20+index*65/max(1,len(operations))))
            dest=safe_install_target(root,name) if complete else root/name
            (backup/name).parent.mkdir(parents=True,exist_ok=True);dest.parent.mkdir(parents=True,exist_ok=True)
            if dest.exists():shutil.copy2(dest,backup/name)
            touched.append(name)
            if action=='write':
                temp=dest.with_name(dest.name+'.updating')
                shutil.copy2(staging/name,temp)
                os.replace(temp,dest)
            else:dest.unlink(missing_ok=True)
        if complete:
            report('Executando o instalador completo em modo de reparo...',88)
            powershell=pathlib.Path(os.environ.get('SystemRoot',r'C:\Windows'))/'System32/WindowsPowerShell/v1.0/powershell.exe'
            subprocess.run([str(powershell),'-NoLogo','-NoProfile','-NonInteractive','-File',str(root/'Instalar_Arcoll.ps1'),'-Repair','-Background'],cwd=str(root),check=True,timeout=600,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        temp=root/(MANAGED+'.updating')
        temp.write_text(json.dumps(inventory,ensure_ascii=False),encoding='utf-8')
        os.replace(temp,prior_path)
    except Exception:
        for name in reversed(touched):
            old=backup/name
            if old.exists():shutil.copy2(old,root/name)
            else:(root/name).unlink(missing_ok=True)
        if old_manifest is None:prior_path.unlink(missing_ok=True)
        else:prior_path.write_bytes(old_manifest)
        raise
    # Notas permanecem no histórico da plataforma; não interromper o computador atualizado.
    local=pathlib.Path(os.environ.get('LOCALAPPDATA',str(pathlib.Path.home())))/'Arcoll'
    try:(local/'update-release-notes.json').unlink(missing_ok=True)
    except OSError:pass
    # Confirmação remota só é emitida após o novo processo iniciar.
    command_id=data.get('remote_command_id')
    if command_id is not None:
        command_id=int(command_id)
        if command_id>0:
            local=pathlib.Path(os.environ.get('LOCALAPPDATA',str(pathlib.Path.home())))/'Arcoll'
            local.mkdir(parents=True,exist_ok=True)
            (local/'update-awaiting-confirmation.json').write_text(json.dumps({'command_id':command_id,'version':str(data.get('release_version') or '')[:32]}),encoding='utf-8')
    report('Arquivos instalados; reiniciando agente...',95)
    exe=pathlib.Path(sys.executable)
    if exe.name.lower()=='python.exe' and exe.with_name('pythonw.exe').exists():exe=exe.with_name('pythonw.exe')
    subprocess.Popen([str(exe),str(root/'arcoll_desktop.py'),'--origin',origin,'--background'],cwd=str(root),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    report('Reiniciando...',100)


def updater_window():
    # Window lives in the helper process while the GUI process exits.
    root = tk.Tk()
    root.title('Atualizando agente Arcoll · v'+VERSION)
    bind_theme(root, background='alt').configure(bg=theme_color('alt'))
    root.resizable(False, False)
    root.withdraw()
    w, h = 430, 165
    root.geometry(f'{w}x{h}+{max(0,(root.winfo_screenwidth()-w)//2)}+{max(0,(root.winfo_screenheight()-h)//2)}')
    bind_theme(tk.Label(root, text='Atualizando agente', bg=theme_color('alt'), fg=theme_color('text'), font=('Segoe UI', 15, 'bold')), background='alt', foreground='text').pack(pady=(18, 5))
    status = tk.StringVar(value='Preparando atualização segura...')
    bind_theme(tk.Label(root, textvariable=status, bg=theme_color('alt'), fg=theme_color('muted'), font=('Segoe UI', 10)), background='alt', foreground='muted').pack(pady=4)
    bar = ttk.Progressbar(root, mode='determinate', length=370, maximum=100)
    bar.pack(pady=(10, 6))
    root.protocol('WM_DELETE_WINDOW', lambda: None)
    root.deiconify()
    from queue import Queue, Empty
    queue = Queue()
    def worker():
        try:
            run(lambda message, percent: queue.put(('progress', message, percent)))
            queue.put(('done', None, 100))
        except Exception as exc:
            log_dir = pathlib.Path(os.environ.get('LOCALAPPDATA', str(pathlib.Path.home()))) / 'Arcoll'
            log_dir.mkdir(parents=True, exist_ok=True)
            (log_dir / 'update-error.log').write_text(str(exc), encoding='utf-8')
            previous=globals().get('_stopped_agent')
            if not previous and len(sys.argv) in (4,5):
                try:
                    install=pathlib.Path(sys.argv[4]).resolve() if len(sys.argv)==5 else pathlib.Path(__file__).resolve().parent
                    if (install/'arcoll_desktop.py').is_file() and sys.argv[3].startswith('https://'):
                        wait_for_exit(int(sys.argv[2]));previous=(install,sys.argv[3])
                except (OSError,ValueError,RuntimeError):pass
            if previous:
                install,origin=previous
                try:subprocess.Popen([sys.executable,str(install/'arcoll_desktop.py'),'--origin',origin,'--background'],cwd=str(install),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
                except OSError:pass
            queue.put(('error', str(exc), 0))
    threading.Thread(target=worker, daemon=True).start()
    def poll():
        try:
            while True:
                kind, message, pct = queue.get_nowait()
                if kind == 'progress':
                    status.set(message); bar['value'] = pct
                elif kind == 'done':
                    root.after(1100, root.destroy); return
                elif kind == 'error':
                    status.set('Falha: ' + message[:80]);root.after(6500,root.destroy);return
        except Empty:
            pass
        root.after(100, poll)
    poll()
    appearance=AppearanceManager(root,controls=False)
    root.mainloop()


if __name__ == '__main__':
    updater_window()
