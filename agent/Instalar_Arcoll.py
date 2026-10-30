# Caminho: agent/Instalar_Arcoll.py
# -*- coding: utf-8 -*-
"""Instalador Arcoll usando Python local, sem PowerShell e sem downloads remotos."""
import argparse, datetime, json, os, pathlib, shutil, subprocess, sys, traceback

REQUIRED = ('arcoll_agent.py', 'arcoll_desktop.py', 'arcoll_app.py', 'arcoll_toolkit.py', 'rt_engine.py', 'agent-config.json')
OPTIONAL = ('connection_diagnostics.py', 'favicon.ico', 'Desinstalar_Arcoll.ps1')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--origin', required=True)
    parser.add_argument('--run', action='store_true')
    opts = parser.parse_args()
    if not opts.origin.startswith('https://'):
        raise RuntimeError('A URL HTTPS da plataforma nao foi configurada.')
    source = pathlib.Path(opts.source).resolve()
    appdata = pathlib.Path(os.environ.get('LOCALAPPDATA', str(pathlib.Path.home()))) / 'Arcoll'
    appdata.mkdir(parents=True, exist_ok=True)
    log = appdata / 'agent-install.log'
    def write(msg):
        with log.open('a', encoding='utf-8') as f:
            f.write(f'[{datetime.datetime.now():%Y-%m-%d %H:%M:%S}] {msg}\n')
        print(msg, flush=True)
    for n in REQUIRED:
        if not (source/n).is_file():
            raise RuntimeError('Pacote incompleto: '+n)
    # Preserve installed identity and data. Do not alter system security settings.
    target = pathlib.Path(os.environ.get('ARCOLL_INSTALL_DIR', 'C:\\Arcoll'))
    try:
        target.mkdir(parents=True,exist_ok=True)
        probe=target/'.arcoll-write-test'
        probe.write_text('ok',encoding='utf-8');probe.unlink()
    except OSError:
        target=appdata/'Aplicativo'
        target.mkdir(parents=True,exist_ok=True)
    for n in REQUIRED+OPTIONAL:
        item=source/n
        if not item.is_file(): continue
        dest=target/n
        if n=='agent-config.json' and dest.is_file():
            # Don't overwrite approved installation credentials.
            try:
                old=json.loads(dest.read_text(encoding='utf-8-sig'))
                new=json.loads(item.read_text(encoding='utf-8-sig'))
                if isinstance(old,dict) and isinstance(new,dict):
                    for k,v in old.items():
                        if k not in ('origin','server_url') and v and (k not in new or not new[k]):new[k]=v
                    new['origin']=opts.origin
                    dest.write_text(json.dumps(new,ensure_ascii=False,indent=2),encoding='utf-8')
                    continue
            except (OSError,ValueError): pass
        if item.resolve()!=dest.resolve():shutil.copy2(item,dest)
    config=target/'agent-config.json'
    try:
        data=json.loads(config.read_text(encoding='utf-8-sig'))
        if not isinstance(data,dict): raise ValueError('formato invalido')
        data['origin']=opts.origin
        config.write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
    except (OSError,ValueError) as exc:raise RuntimeError('Falha na configuracao do agente: '+str(exc)) from exc
    write('Arquivos do agente instalados em '+str(target))
    # Checagem de bibliotecas fundamentais antes de abrir a interface.
    import ssl, sqlite3, tkinter, base64
    write('Runtime Python validado: '+sys.executable)
    # Dependencias complementares nao impedem instalacao do aplicativo.
    if opts.run is False:
        try:
            import importlib.util
            missing=[name for module,name in (('psutil','psutil'),('PIL','Pillow'),('pystray','pystray')) if importlib.util.find_spec(module) is None]
            if missing:
                pip_check=subprocess.run([sys.executable,'-m','pip','--version'],capture_output=True,timeout=20)
                if pip_check.returncode:
                    subprocess.run([sys.executable,'-m','ensurepip','--upgrade'],capture_output=True,timeout=100)
                pip_check=subprocess.run([sys.executable,'-m','pip','--version'],capture_output=True,timeout=20)
                if pip_check.returncode==0:
                    res=subprocess.run([sys.executable,'-m','pip','install','--disable-pip-version-check',*missing],capture_output=True,timeout=150)
                    write('Dependencias opcionais: '+('instaladas' if res.returncode==0 else 'nao instaladas; recursos complementares podem ficar indisponiveis'))
                else:
                    write('Aviso: pip indisponivel. Recursos complementares permanecem opcionais.')
        except (OSError,subprocess.SubprocessError) as exc:
            write('Aviso: nao foi possivel preparar bibliotecas opcionais: '+str(exc))
    # Shortcut as a transparent CMD file; no hidden or bypassed PowerShell.
    pythonw=pathlib.Path(sys.executable).with_name('pythonw.exe')
    interpreter=pythonw if pythonw.exists() else pathlib.Path(sys.executable)
    cmd='@echo off\r\ncd /d "'+str(target)+'"\r\nstart "" "'+str(interpreter)+'" "'+str(target/'arcoll_desktop.py')+'" --origin "'+opts.origin+'"\r\n'
    launch=target/'Iniciar Arcoll.cmd';launch.write_text(cmd,encoding='cp1252',errors='replace')
    desktop=pathlib.Path(os.environ.get('USERPROFILE',str(pathlib.Path.home())))/'Desktop'
    # OneDrive desktop may be redirected; shortcut in install folder always works.
    if desktop.is_dir():
        try: shutil.copy2(launch, desktop/'Arcoll Speed Connection.cmd')
        except OSError: pass
    write('Inicializador disponivel em '+str(launch))
    if not opts.run:
        subprocess.Popen([str(interpreter),str(target/'arcoll_desktop.py'),'--origin',opts.origin],cwd=str(target),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        write('Agente iniciado. Se nao abrir, consulte agent-runtime.log.')
    return 0

if __name__=='__main__':
    try:sys.exit(main())
    except Exception:
        msg=traceback.format_exc()
        print(msg, file=sys.stderr)
        try:
            d=pathlib.Path(os.environ.get('LOCALAPPDATA',str(pathlib.Path.home())))/'Arcoll'
            d.mkdir(parents=True,exist_ok=True)
            with (d/'agent-install.log').open('a',encoding='utf-8') as f:f.write(msg+'\n')
        except OSError:pass
        sys.exit(1)
