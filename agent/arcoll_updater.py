#!/usr/bin/env python3
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

VERSION = '2.8.1'

FILES = {'arcoll_app.py', 'arcoll_desktop.py', 'arcoll_agent.py', 'arcoll_toolkit.py', 'rt_engine.py', 'connection_diagnostics.py'}


def run(report=lambda _message,_percent:None):
    if len(sys.argv) != 4:
        raise ValueError('Argumentos de atualização inválidos')
    staging = pathlib.Path(sys.argv[1]).resolve()
    root = pathlib.Path(__file__).resolve().parent
    pid = int(sys.argv[2])
    origin = sys.argv[3]
    if origin.startswith('https://') is False:
        raise ValueError('Origem HTTPS inválida')
    data = json.loads((staging / 'manifest.json').read_text(encoding='utf-8'))
    files = data.get('files')
    if not isinstance(files, list) or not files:
        raise ValueError('Pacote vazio')
    for row in files:
        name = row.get('name', '')
        if name not in FILES or not isinstance(row.get('sha256'), str):
            raise ValueError('Arquivo não permitido no pacote')
        actual = hashlib.sha256((staging / name).read_bytes()).hexdigest()
        if actual != row['sha256']:
            raise ValueError('Falha de integridade: ' + name)
    report('Aguardando encerramento seguro...', 10)
    # Let the running GUI and bootstrap gate terminate before replacing scripts.
    for _ in range(100):
        try:
            os.kill(pid, 0)
        except OSError:
            break
        time.sleep(.25)
    backup = staging / 'backup'
    backup.mkdir(exist_ok=True)
    installed = []
    try:
        for index, row in enumerate(files):
            report('Aplicando ' + row['name'], int(20 + index * 65 / len(files)))
            name = row['name']
            dest = root / name
            if dest.exists():
                shutil.copy2(dest, backup / name)
            temp = root / (name + '.updating')
            shutil.copy2(staging / name, temp)
            os.replace(temp, dest)
            installed.append(name)
    except Exception:
        for name in reversed(installed):
            old = backup / name
            if old.exists():
                shutil.copy2(old, root / name)
            else:
                (root / name).unlink(missing_ok=True)
        raise
    report('Arquivos atualizados; iniciando agente...', 95)
    exe = pathlib.Path(sys.executable)
    if exe.name.lower() == 'python.exe' and exe.with_name('pythonw.exe').exists():
        exe = exe.with_name('pythonw.exe')
    subprocess.Popen([str(exe), str(root / 'arcoll_desktop.py'), '--origin', origin], cwd=str(root), creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    report('Concluído! Reiniciando...', 100)


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
