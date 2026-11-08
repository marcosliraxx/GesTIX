#!/usr/bin/env python3
# Caminho: gestores/windows/arcoll_updater.py
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
            if kernel.WaitForSingleObject(handle,30000)!=0:raise RuntimeError('O gestor anterior não encerrou. Nenhum arquivo foi alterado.')
        finally:kernel.CloseHandle(handle)
    else:
        for _ in range(120):
            try:os.kill(pid,0)
            except ProcessLookupError:return
            time.sleep(.25)
        raise RuntimeError('O gestor anterior não encerrou. Nenhum arquivo foi alterado.')


def installer_path(name):
    from pathlib import PurePosixPath
    if not isinstance(name,str) or '\\' in name or ':' in name or not name or name.startswith('/') or any(ord(c)<32 for c in name):raise ValueError('Caminho inválido no instalador.')
    path=PurePosixPath(name)
    if any(p in ('.','..') or not p or p.endswith(('.', ' ')) for p in name.split('/')):raise ValueError('Caminho inseguro no instalador.')
    if any(re.match(r'^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)',p,re.I) for p in path.parts):raise ValueError('Nome reservado no instalador.')
    if path.name.casefold() in ('gestor-config.json','gestor.json','appearance.json','.arcoll-managed-files.json'):raise ValueError('O instalador não pode substituir configuração privada.')
    return path


def stage_installer(package,stage):
    """Verify the whole installer and each entry before extracting any managed file."""
    import base64,io,zipfile,stat
    if not isinstance(package,dict) or not package.get('ok'):raise ValueError('Instalador indisponível.')
    binary=base64.b64decode(package.get('archive',''),validate=True)
    if not binary or len(binary)>33554432 or hashlib.sha256(binary).hexdigest()!=package.get('sha256'):raise ValueError('Integridade do instalador inválida.')
    rows=package.get('manifest');required={'arcoll_app.py','arcoll_programs.py','arcoll_clean.py','arcoll_cc_ui.py','arcoll_cloud.py','arcoll_vdrive.py','arcoll_remote.py','arcoll_secure_helper.py','arcoll_release.py','arcoll_gestor.py','arcoll_updater.py','arcoll_toolkit.py','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1','Desinstalar_Arcoll.ps1','rt_engine.py','connection_diagnostics.py'}
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



def stage_code_update(package,stage):
    """Stage only signed-by-manifest code components; leave installed resources and credentials intact."""
    import base64
    if not isinstance(package,dict) or package.get('ok') is not True:
        raise ValueError('Pacote de atualização indisponível: '+str((package or {}).get('error','resposta inválida'))[:180])
    rows=package.get('files')
    if not isinstance(rows,list) or not 6<=len(rows)<=80:
        raise ValueError('Inventário de atualização incompleto.')
    verified={}
    for entry in rows:
        if not isinstance(entry,dict):raise ValueError('Entrada de atualização inválida.')
        name=entry.get('name')
        if not isinstance(name,str) or not VALID_NAME.fullmatch(name) or name in verified:
            raise ValueError('Caminho de atualização inválido ou repetido.')
        content=entry.get('content')
        if not isinstance(content,str):raise ValueError('Conteúdo da atualização inválido.')
        blob=base64.b64decode(content,validate=True)
        if not blob or len(blob)>1500000 or hashlib.sha256(blob).hexdigest()!=entry.get('sha256'):
            raise ValueError('Integridade inválida no arquivo: '+name)
        if name.endswith('.py'):compile(blob,name,'exec')
        verified[name]=blob
    required={'arcoll_app.py','arcoll_programs.py','arcoll_gestor.py','arcoll_updater.py','arcoll_release.py','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1'}
    if not required.issubset(verified):raise ValueError('Atualização sem componentes essenciais.')
    for name,blob in verified.items(): (stage/name).write_bytes(blob)
    return {'files':[{'name':name,'sha256':hashlib.sha256(blob).hexdigest()} for name,blob in verified.items()],
            'inventory':list(verified),'remove':[],'installer_complete':False,
            'release_version':str(package.get('version') or '')[:32],
            'release_notes':str(package.get('notes') or '')[:12000]}


def _native_identity(legacy_root, origin):
    """Read the existing, revocable enrollment; never create or change an enrollment."""
    import urllib.parse
    profile = pathlib.Path(os.environ.get('APPDATA', str(pathlib.Path.home()))) / 'Arcoll' / 'gestor.json'
    settings = {}
    for path in (legacy_root / 'gestor-config.json', profile):
        if path.is_file():
            content = json.loads(path.read_text(encoding='utf-8-sig'))
            if isinstance(content, dict):
                settings.update({k: v for k, v in content.items() if isinstance(v, str) and v})
    source = urllib.parse.urlsplit(origin)
    if source.scheme != 'https' or not source.hostname or source.username or source.password:
        raise ValueError('A origem HTTPS da instalacao antiga nao e valida.')
    if str(settings.get('origin', origin)).rstrip('/') != origin.rstrip('/'):
        raise ValueError('A origem do cadastro nao corresponde ao servidor autorizado.')
    if not re.fullmatch(r'[A-Z0-9]{5}', str(settings.get('site_code', '')).upper()):
        raise ValueError('Codigo de rede nao localizado no cadastro antigo.')
    if not re.fullmatch(r'[a-fA-F0-9]{64}', str(settings.get('download_key', ''))):
        raise ValueError('Chave revogavel nao localizada no cadastro antigo.')
    fingerprint = str(settings.get('fingerprint') or '').lower()
    if not re.fullmatch(r'[a-f0-9]{64}', fingerprint):
        # Mesmo algoritmo do gestor Python que ja esta autenticado na plataforma.
        import platform
        raw = ''
        try:
            import winreg
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r'SOFTWARE\Microsoft\Cryptography') as key:
                raw = str(winreg.QueryValueEx(key, 'MachineGuid')[0])
        except (OSError, ImportError):
            pass
        raw = raw or '|'.join((platform.node(), platform.system(), platform.machine()))
        fingerprint = hashlib.sha256(('arcoll-speed-connection|' + raw).encode()).hexdigest()
    return {'origin': origin.rstrip('/'), 'site_code': str(settings['site_code']).upper(),
            'download_key': str(settings['download_key']).lower(), 'fingerprint': fingerprint,
            'system_name': str(settings.get('system_name') or 'T.I Digital')[:100]}


def _native_request(origin, auth, command_id, status, result):
    """Progress and failures use the existing authenticated command, never a second channel."""
    if not command_id or int(command_id) <= 0:
        return False
    import urllib.request
    payload = dict(auth, command_id=int(command_id), status=status, result=str(result)[:400])
    if status == 'failed':
        payload['error_code'] = 'LEGACY_INSTALLER_ERROR'
        payload['error_detail'] = str(result)[:5000]
    req = urllib.request.Request(origin + '/api/api.php?action=gestor-command-ack',
        data=json.dumps(payload).encode('utf-8'), method='POST', headers={
            'Accept': 'application/json', 'Content-Type': 'application/json',
            'User-Agent': 'ArcollGestor/' + VERSION})
    with urllib.request.urlopen(req, timeout=12) as response:
        answer = json.loads(response.read(8192))
        return bool(answer.get('ok') and answer.get('acknowledged'))


def _stage_native_source(origin, auth, staging, expected_version):
    """Authenticated HTTPS package, SHA-256 and strictly bounded ZIP extraction."""
    import io
    import stat
    import urllib.request
    import zipfile
    if not re.fullmatch(r'\d+(?:\.\d+){1,4}', expected_version):
        raise ValueError('A versao publicada do novo gestor e invalida.')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            raise ValueError('Redirecionamento de atualizacao nao autorizado.')
    req = urllib.request.Request(origin + '/api/download-gestor.php?mode=native-update',
        data=json.dumps({k: auth[k] for k in ('fingerprint', 'site_code', 'download_key')}).encode('utf-8'),
        method='POST', headers={'Accept': 'application/zip', 'Content-Type': 'application/json',
                                'User-Agent': 'ArcollGestor/' + VERSION})
    with urllib.request.build_opener(NoRedirect).open(req, timeout=150) as response:
        digest = str(response.headers.get('X-Package-SHA256', '')).strip().lower()
        published = str(response.headers.get('X-Gestor-Version', '')).strip()
        if published != expected_version:
            raise ValueError('A versao mudou durante a migracao; solicite novamente.')
        if not re.fullmatch(r'[a-f0-9]{64}', digest):
            raise ValueError('O servidor nao apresentou SHA-256 do pacote nativo.')
        if int(response.headers.get('Content-Length') or 0) > 100 * 1024 * 1024:
            raise ValueError('O pacote nativo excede 100 MB.')
        blob = response.read(100 * 1024 * 1024 + 1)
    if len(blob) > 100 * 1024 * 1024 or hashlib.sha256(blob).hexdigest() != digest:
        raise ValueError('O pacote nativo recebido nao passou na verificacao SHA-256.')
    native = staging / 'native'
    native.mkdir(parents=True, exist_ok=True)
    seen = set()
    count = 0
    total = 0
    with zipfile.ZipFile(io.BytesIO(blob)) as archive:
        rows = archive.infolist()
        if not 5 <= len(rows) <= 550:
            raise ValueError('Inventario nativo incompleto.')
        for row in rows:
            name = row.filename
            bits = pathlib.PurePosixPath(name).parts
            if (not name.startswith('Arcoll.WinUI3/') or not bits or any(x in ('..', '.') for x in bits)
                    or '\\' in name or ':' in name or name.startswith('/') or not name.strip()
                    or (row.external_attr >> 16) & 0o170000 == stat.S_IFLNK or row.flag_bits & 1):
                raise ValueError('O ZIP nativo possui caminhos nao permitidos.')
            if row.is_dir():
                continue
            if name.lower() in seen:
                raise ValueError('O ZIP nativo possui arquivos duplicados.')
            seen.add(name.lower())
            total += row.file_size
            count += 1
            if total > 100 * 1024 * 1024 or row.file_size > 32 * 1024 * 1024:
                raise ValueError('O pacote nativo excede o limite de arquivos.')
        essential = {'Arcoll.WinUI3/Arcoll.WinUI3.csproj', 'Arcoll.WinUI3/INSTALAR_WINUI3.ps1',
                     'Arcoll.WinUI3/App.xaml', 'Arcoll.WinUI3/MainWindow.xaml'}
        if not essential.issubset(set(x.filename for x in rows)):
            raise ValueError('O pacote WinUI 3 nao contem os arquivos essenciais.')
        for row in rows:
            if row.is_dir():
                continue
            target = native.joinpath(*pathlib.PurePosixPath(row.filename).parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            data = archive.read(row)
            if len(data) != row.file_size:
                raise ValueError('Arquivo truncado no ZIP nativo.')
            target.write_bytes(data)
    config_path = native / 'Arcoll.WinUI3' / 'gestor-config.json'
    config_path.write_text(json.dumps(auth, ensure_ascii=False), encoding='utf-8')
    return native / 'Arcoll.WinUI3'


def _retire_legacy_autostart():
    """Retire only old desktop autostart links; keep all legacy files for manual rollback."""
    ps = pathlib.Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
    script = ("$startup=[Environment]::GetFolderPath('Startup'); "
              "if($startup -and (Test-Path -LiteralPath $startup)) {"
              "$shell=New-Object -ComObject WScript.Shell; "
              "Get-ChildItem -LiteralPath $startup -Filter '*.lnk' -File | ForEach-Object {"
              "try {$link=$shell.CreateShortcut($_.FullName); "
              "if(($link.Arguments -match '(?i)arcoll_app\\.py') -and "
              "($link.TargetPath -match '(?i)pythonw?\\.exe$')) "
              "{Remove-Item -LiteralPath $_.FullName -Force -ErrorAction Stop}} catch {}}}")
    subprocess.run([str(ps), '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', script],
                   timeout=25, capture_output=True, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0), check=True)


def _migrate_to_native(origin, legacy_root, staging, data, report):
    """One-click old-Python -> WinUI bridge. Native must start before retiring old autostart."""
    if os.name != 'nt':
        return False
    version = str(data.get('release_version') or '')
    if not re.fullmatch(r'\d+(?:\.\d+){1,4}', version) or tuple(map(int, version.split('.'))) < (5, 0, 0):
        return False
    command = int(data.get('remote_command_id') or 0)
    auth = _native_identity(legacy_root, origin)
    report('Baixando pacote nativo autenticado...', 88)
    try:
        native = _stage_native_source(origin, auth, staging, version)
        report('Pacote verificado. Preparando instalador...', 90)
        local = pathlib.Path(os.environ.get('LOCALAPPDATA', str(pathlib.Path.home()))) / 'TI-Digital'
        local.mkdir(parents=True, exist_ok=True)
        pending = local / 'update-pending.json'
        result = local / 'update-result.json'
        progress_path = local / 'update-progress.json'
        for stale in (result, progress_path):
            stale.unlink(missing_ok=True)
        if command > 0:
            pending.write_text(json.dumps({'command_id': command, 'version': version,
                            'force': bool(data.get('installer_complete')), 'started': int(time.time())}), encoding='utf-8')
        else:
            pending.unlink(missing_ok=True)
        ps = pathlib.Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
        args = [str(ps), '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', str(native / 'INSTALAR_WINUI3.ps1'), '-Update']
        if data.get('installer_complete'):
            args.append('-ForceRebuild')
        logs = local / 'logs'
        logs.mkdir(parents=True, exist_ok=True)
        log = logs / ('Migracao-Python-WinUI3-' + time.strftime('%Y%m%d-%H%M%S') + '.log')
        with log.open('wb') as out:
            process = subprocess.Popen(args, cwd=str(native), stdout=out, stderr=subprocess.STDOUT,
                                       creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
            started = time.monotonic()
            last_send = 0
            while process.poll() is None:
                if time.monotonic() - started > 40 * 60:
                    process.terminate()
                    raise TimeoutError('A compilacao nativa excedeu 40 minutos.')
                if time.monotonic() - last_send > 12:
                    last_send = time.monotonic()
                    pct, msg = 92, 'Compilando e instalando o gestor WinUI 3...'
                    try:
                        stage = json.loads(progress_path.read_text(encoding='utf-8-sig'))
                        pct = int(stage.get('percent', pct))
                        msg = str(stage.get('message') or msg)[:130]
                    except (OSError, ValueError, TypeError):
                        pass
                    # O Python ja aplicou os arquivos legados (ate 88%). Reserve
                    # os 90-99% finais para as etapas reais do instalador WinUI.
                    overall = min(99, max(90, 90 + int(max(0, pct - 55) * 9 / 44)))
                    report('Instalacao WinUI 3: ' + msg, overall)
                    if command > 0:
                        try:
                            _native_request(origin, auth, command, 'running', 'WinUI 3 ' + str(overall) + '%: ' + msg)
                        except (OSError, ValueError):
                            pass
                time.sleep(2)
            if process.returncode != 0:
                raise RuntimeError('O instalador nativo falhou (codigo ' + str(process.returncode) + '). Consulte ' + str(log))
        try:
            finished = json.loads(result.read_text(encoding='utf-8-sig'))
        except (OSError, ValueError):
            raise RuntimeError('O instalador nao produziu confirmacao de integridade e inicializacao.')
        if finished.get('ok') is not True:
            raise RuntimeError('O instalador nao confirmou sucesso: ' + str(finished.get('message', 'erro'))[:170])
        report('Gestor WinUI 3 iniciado; mantendo arquivos antigos para reversao.', 99)
        try:
            _retire_legacy_autostart()
        except (OSError, subprocess.SubprocessError):
            # Nao deixa a migracao travada apos a instalacao comprovadamente concluida.
            report('Novo gestor ativo. Revise o atalho de inicializacao do gestor antigo.', 99)
        return True
    except Exception as exc:
        if command > 0:
            try:
                _native_request(origin, auth, command, 'failed', 'Falha na migracao WinUI; gestor anterior restaurado: ' + str(exc)[:220])
            except (OSError, ValueError):
                pass
        try:
            (pathlib.Path(os.environ.get('LOCALAPPDATA', str(pathlib.Path.home()))) / 'TI-Digital' / 'update-pending.json').unlink(missing_ok=True)
        except OSError:
            pass
        raise


def download_installer(origin,root):
    import urllib.request
    config={}
    for path in (root/'gestor-config.json',pathlib.Path(os.environ.get('APPDATA',str(pathlib.Path.home())))/'Arcoll'/'gestor.json'):
        if path.is_file():config.update(json.loads(path.read_text(encoding='utf-8-sig')))
    if str(config.get('origin','')).rstrip('/')!=origin.rstrip('/'):raise ValueError('A origem do instalador diverge da instalação.')
    auth={key:str(config.get(key) or '') for key in ('fingerprint','site_code','download_key')}
    if not re.fullmatch(r'[a-f0-9]{64}',auth['fingerprint']) or not re.fullmatch(r'[a-f0-9]{64}',auth['download_key']) or not re.fullmatch(r'[A-Z0-9]{5}',auth['site_code']):raise ValueError('Cadastro local incompleto para reparar a instalação.')
    request=urllib.request.Request(origin.rstrip('/')+'/api/api.php?action=gestor-installer-package',data=json.dumps(auth).encode(),headers={'Content-Type':'application/json'},method='POST')
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
    if not (root/'arcoll_app.py').is_file():raise ValueError('Pasta do gestor não encontrada')
    pid = int(sys.argv[2]); origin = sys.argv[3]
    if not origin.startswith('https://'):
        raise ValueError('Origem HTTPS inválida')
    data = json.loads((staging / 'manifest.json').read_text(encoding='utf-8'))
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
        removals=sorted(set(n for n in prior if isinstance(n,str) and n not in inventory and n not in ('gestor-config.json','gestor.json','appearance.json')))
        for name in inventory+removals:installer_path(name)
    report('Aguardando encerramento seguro...', 10)
    wait_for_exit(pid)
    global _stopped_gestor
    _stopped_gestor=(root,origin)
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
        # Uma migracao nativa verificada nao precisa reparar tarefas e servicos
        # do Python antes de substituir a interface. Mantemos os arquivos antigos
        # intactos para recuperar a operacao se o instalador WinUI falhar.
        release_parts = str(data.get('release_version') or '').split('.')
        native_target = os.name == 'nt' and 2 <= len(release_parts) <= 5 and all(x.isdigit() for x in release_parts) and tuple(map(int, release_parts)) >= (5, 0, 0)
        if complete and not native_target:
            report('Executando o instalador completo em modo de reparo...',88)
            powershell=pathlib.Path(os.environ.get('SystemRoot',r'C:\Windows'))/'System32/WindowsPowerShell/v1.0/powershell.exe'
            subprocess.run([str(powershell),'-NoLogo','-NoProfile','-NonInteractive','-File',str(root/'Instalar_Arcoll.ps1'),'-Repair','-Background'],cwd=str(root),check=True,timeout=600,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
        # Atualização de código não muda o inventário de recursos do instalador completo.
        if complete:
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
    # O atualizador distribuido junto ao pacote antigo e executado pelo gestor
    # Python que ja recebe gestor_update. A migracao e concluida somente se o
    # instalador WinUI verificar o novo processo; em falha, o Python reinicia.
    if _migrate_to_native(origin, root, staging, data, report):
        report('Gestor WinUI 3 instalado. Aguardando confirmacao da plataforma.', 100)
        return
    # Confirmação remota só é emitida após o novo processo iniciar.
    command_id=data.get('remote_command_id')
    if command_id is not None:
        command_id=int(command_id)
        if command_id>0:
            local=pathlib.Path(os.environ.get('LOCALAPPDATA',str(pathlib.Path.home())))/'Arcoll'
            local.mkdir(parents=True,exist_ok=True)
            (local/'update-awaiting-confirmation.json').write_text(json.dumps({'command_id':command_id,'version':str(data.get('release_version') or '')[:32]}),encoding='utf-8')
    report('Arquivos instalados; reiniciando gestor...',95)
    exe=pathlib.Path(sys.executable)
    if exe.name.lower()=='python.exe' and exe.with_name('pythonw.exe').exists():exe=exe.with_name('pythonw.exe')
    subprocess.Popen([str(exe),str(root/'arcoll_app.py'),'--origin',origin,'--show'],cwd=str(root),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    report('Reiniciando...',100)


def updater_window():
    # Window lives in the helper process while the GUI process exits.
    root = tk.Tk()
    root.title('Atualizando gestor Arcoll · v'+VERSION)
    bind_theme(root, background='alt').configure(bg=theme_color('alt'))
    root.resizable(False, False)
    root.withdraw()
    w, h = 430, 165
    root.geometry(f'{w}x{h}+{max(0,(root.winfo_screenwidth()-w)//2)}+{max(0,(root.winfo_screenheight()-h)//2)}')
    bind_theme(tk.Label(root, text='Atualizando gestor', bg=theme_color('alt'), fg=theme_color('text'), font=('Segoe UI', 15, 'bold')), background='alt', foreground='text').pack(pady=(18, 5))
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
            log_dir = pathlib.Path(os.environ.get('LOCALAPPDATA', str(pathlib.Path.home()))) / 'Arcoll' / 'logs'
            log_dir.mkdir(parents=True, exist_ok=True)
            (log_dir / 'update-error.log').write_text(str(exc), encoding='utf-8')
            previous=globals().get('_stopped_gestor')
            if not previous and len(sys.argv) in (4,5):
                try:
                    install=pathlib.Path(sys.argv[4]).resolve() if len(sys.argv)==5 else pathlib.Path(__file__).resolve().parent
                    if (install/'arcoll_app.py').is_file() and sys.argv[3].startswith('https://'):
                        wait_for_exit(int(sys.argv[2]));previous=(install,sys.argv[3])
                except (OSError,ValueError,RuntimeError):pass
            if previous:
                install,origin=previous
                try:subprocess.Popen([sys.executable,str(install/'arcoll_app.py'),'--origin',origin,'--show'],cwd=str(install),creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
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
