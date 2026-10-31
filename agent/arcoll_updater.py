#!/usr/bin/env python3
# Caminho: agent/arcoll_updater.py
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

VERSION = '3.5.0'

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
    files = data.get('files'); inventory=data.get('inventory'); removals=data.get('remove')
    if not isinstance(files,list) or not isinstance(inventory,list) or not isinstance(removals,list):
        raise ValueError('Manifesto incompleto')
    if not 6 <= len(inventory) <= 80 or len(set(inventory)) != len(inventory) or any(not isinstance(n,str) or not VALID_NAME.fullmatch(n) for n in inventory):
        raise ValueError('Inventário inválido')
    if len(set(removals))!=len(removals) or any(not isinstance(n,str) or not VALID_NAME.fullmatch(n) or n in inventory for n in removals):
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
        if not isinstance(name,str) or not VALID_NAME.fullmatch(name) or name not in inventory or name in seen:
            raise ValueError('Arquivo não permitido')
        seen.add(name)
        if hashlib.sha256((staging/name).read_bytes()).hexdigest()!=row.get('sha256'):
            raise ValueError('Falha de integridade: '+name)
    report('Aguardando encerramento seguro...', 10)
    wait_for_exit(pid)
    backup=staging/'backup';backup.mkdir(exist_ok=True)
    touched=[]
    old_manifest=prior_path.read_bytes() if prior_path.exists() else None
    try:
        operations=[('write',row['name']) for row in files]+[('remove',n) for n in removals]
        for index,(action,name) in enumerate(operations):
            report(('Instalando ' if action=='write' else 'Removendo ')+name,int(20+index*65/max(1,len(operations))))
            dest=root/name
            if dest.exists():shutil.copy2(dest,backup/name)
            touched.append(name)
            if action=='write':
                temp=root/(name+'.updating')
                shutil.copy2(staging/name,temp)
                os.replace(temp,dest)
            else:dest.unlink(missing_ok=True)
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
    # Notas são entregues ao processo atualizado, inclusive nas atualizações locais.
    notes=str(data.get('release_notes') or '').strip()[:3000]
    if notes:
        local=pathlib.Path(os.environ.get('LOCALAPPDATA',str(pathlib.Path.home())))/'Arcoll'
        local.mkdir(parents=True,exist_ok=True)
        (local/'update-release-notes.json').write_text(json.dumps({'version':str(data.get('release_version') or '')[:32],'notes':notes},ensure_ascii=False),encoding='utf-8')
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
    subprocess.Popen([str(exe),str(root/'arcoll_desktop.py'),'--origin',origin],cwd=str(root),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
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
