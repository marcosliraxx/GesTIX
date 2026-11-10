# Caminho: gestores/windows/arcoll_files.py
"""Operações de Arquivos para o gestor nativo; mantém o protocolo e a autorização existentes."""
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import tempfile
import urllib.parse
import urllib.request
import zipfile
from arcoll_toolkit import explorer_path, explorer_name, explorer_tree, explorer_operation, explorer_preview, explorer_thumbnails, transfer_request


def platform_api(config, action, command_id):
    if action not in ('gestor-command-authorize', 'gestor-file-transfer'):
        raise ValueError('Operação inválida.')
    origin=config['origin'].rstrip('/')
    parsed=urllib.parse.urlsplit(origin)
    if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError('Servidor HTTPS inválido.')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    body={key:config[key] for key in ('fingerprint','site_code','download_key')}
    body['command_id']=int(command_id)
    request=urllib.request.Request(origin+'/api/api.php?action='+action,data=json.dumps(body).encode(),headers={'Content-Type':'application/json','Accept':'application/json','User-Agent':'ArcollGestor/Files'},method='POST')
    with urllib.request.build_opener(NoRedirect).open(request,timeout=15) as response:
        raw=response.read(65537)
    if len(raw)>65536:
        raise ValueError('Resposta inválida.')
    result=json.loads(raw)
    if not result.get('ok'):
        raise PermissionError(result.get('error') or 'Autorização revogada.')
    return result


def receive_folder(data, destination, name, authorize):
    parent=explorer_path(destination,directory=True)
    target=parent/explorer_name(name)
    if target.exists():
        raise FileExistsError('A pasta de destino já existe.')
    stage=Path(tempfile.mkdtemp(prefix='.arcoll-folder-',dir=parent))
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            entries=archive.infolist(); total=0; seen=set()
            if not entries or len(entries)>300:
                raise ValueError('Pasta vazia ou com mais de 300 itens.')
            for entry in entries:
                normalized=entry.filename.replace('\\','/')
                parts=PurePosixPath(normalized).parts
                if not parts or normalized.startswith('/') or any(p in ('.','..') or ':' in p for p in parts) or stat.S_ISLNK(entry.external_attr>>16) or entry.flag_bits&1:
                    raise ValueError('Caminho de extração inseguro.')
                for part in parts:
                    explorer_name(part)
                key=normalized.rstrip('/').casefold()
                if key in seen:
                    raise ValueError('Caminho duplicado na pasta.')
                seen.add(key); total+=entry.file_size
                if total>8388608:
                    raise ValueError('Pasta maior que o limite de transferência.')
            for entry in entries:
                output=stage.joinpath(*PurePosixPath(entry.filename.replace('\\','/')).parts)
                if entry.is_dir():
                    output.mkdir(parents=True,exist_ok=True)
                else:
                    output.parent.mkdir(parents=True,exist_ok=True)
                    with archive.open(entry) as source,output.open('xb') as dest:
                        shutil.copyfileobj(source,dest,65536)
        authorize()
        explorer_path(destination,directory=True)
        if target.exists():
            raise FileExistsError('A pasta de destino já existe.')
        os.rename(stage,target)
    finally:
        if stage.exists():
            shutil.rmtree(stage)


def run_command(config, command_id, name, payload):
    origin=config['origin'];auth={key:config[key] for key in ('fingerprint','site_code','download_key')}
    transfer_id=None
    def authorize():
        reply=platform_api(config,'gestor-command-authorize',command_id)
        if reply.get('command')!=name or not reply.get('headless'):
            raise PermissionError('Autorização sem monitor revogada.')
    try:
        if name=='file_transfer':
            transfer_id=int(platform_api(config,'gestor-file-transfer',command_id)['transfer_id'])
            row=transfer_request(origin,'file-gestor-details',auth,transfer_id)['transfer']
            if not row.get('headless'):
                raise PermissionError('Ative o modo sem monitor para a transferência remota.')
            def authorize_transfer():
                fresh=transfer_request(origin,'file-gestor-details',auth,transfer_id)['transfer']
                if not fresh.get('headless'):
                    raise PermissionError('Autorização sem monitor revogada.')
            if row['direction']!='to_gestor':
                raise ValueError('Selecione os arquivos na plataforma para baixar.')
            folder=explorer_path(row.get('destination',''),directory=True)
            data=transfer_request(origin,'file-gestor-download',auth,transfer_id,binary=True)
            if len(data)!=int(row['file_size']) or hashlib.sha256(data).hexdigest()!=row['sha256']:
                raise ValueError('Integridade do arquivo recebido inválida.')
            authorize_transfer()
            name=explorer_name(row['filename'])
            if name.startswith('__arcoll_folder__') and name.lower().endswith('.zip'):
                receive_folder(data,str(folder),name[len('__arcoll_folder__'):-4],authorize_transfer)
            else:
                explorer_path(str(folder),directory=True)
                with (folder/name).open('xb') as stream:
                    stream.write(data)
            transfer_request(origin,'file-gestor-finish',auth,transfer_id,{'status':'completed'})
            return {'message':'Arquivo recebido e salvo, sem sobrescrever arquivos existentes.'}
        if name not in ('file_operation','file_bundle','file_execute'):
            raise ValueError('Comando de Arquivos inválido.')
        authorize()
        payload=dict(payload)
        if name=='file_bundle':
            payload['operation']='download'
        elif name=='file_execute':
            payload.update(operation='execute',paths=[payload.get('path')])
        operation=payload.get('operation')
        if operation not in ('download','preview','thumbnails'):
            return {'message':explorer_operation(payload,authorize)}
        transfer_id=int(platform_api(config,'gestor-file-transfer',command_id)['transfer_id'])
        row=transfer_request(origin,'file-gestor-details',auth,transfer_id)['transfer']
        if not row.get('headless'):
            raise PermissionError('Autorização sem monitor revogada.')
        raw=payload.get('paths')
        if not isinstance(raw,list) or not 1<=len(raw)<=(24 if operation=='thumbnails' else 25):
            raise ValueError('Seleção inválida.')
        if operation=='thumbnails':
            data,filename=explorer_thumbnails(raw)
        else:
            paths=[explorer_path(value,writable=True) for value in raw]
            with tempfile.TemporaryDirectory(prefix='arcoll-files-') as temporary:
                if operation=='preview':
                    if len(paths)!=1 or not paths[0].is_file():
                        raise ValueError('Selecione um arquivo para visualizar.')
                    data,filename=explorer_preview(str(paths[0]),Path(temporary))
                elif len(paths)==1 and paths[0].is_file():
                    if paths[0].stat().st_size>8388608:
                        raise ValueError('Arquivo maior que o limite de transferência.')
                    data=paths[0].read_bytes();filename=paths[0].name
                else:
                    trees=[(path,explorer_tree(path)) for path in paths]
                    if sum(p.stat().st_size for _,items in trees for p in items if p.is_file())>8388608:
                        raise ValueError('Seleção maior que o limite de transferência.')
                    output=io.BytesIO();names=set()
                    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
                        for path,items in trees:
                            authorize()
                            for child in items:
                                relative=str(Path(path.name)/child.relative_to(path))
                                if relative.casefold() in names:
                                    raise ValueError('Nomes duplicados na seleção.')
                                names.add(relative.casefold());archive.write(child,relative)
                    data=output.getvalue();filename='arquivos.zip'
        if len(data)>8388608:
            raise ValueError('Arquivo maior que o limite de transferência.')
        authorize()
        transfer_request(origin,'file-gestor-upload',auth,transfer_id,payload=data,filename=filename)
        return {'message':'Arquivo transmitido e confirmado pela plataforma.'}
    except Exception as error:
        if transfer_id:
            try:
                transfer_request(origin,'file-gestor-finish',auth,transfer_id,{'status':'failed','message':str(error)[:160]})
            except Exception:
                pass
        raise
