#!/usr/bin/env python3
# Caminho: scripts/package_update.py
"""Package only committed runtime changes and zero-byte manual deletion markers."""
from pathlib import Path
import subprocess
import sys
import zipfile
import struct
from datetime import datetime
from zoneinfo import ZoneInfo
ROOT=Path(__file__).resolve().parents[1]
RETIRED_PATHS=['support/Instalar_Suporte.cmd','redes/ferramentas.php','_manifest.json','api/network-report.php','redes/_relatorio_modal.php','assets/css/appearance.css','assets/css/arcoll-modern.css','assets/css/mobile-app.css','assets/css/network-agent-states.css','assets/css/panel.css','assets/css/platform-mobile.css','assets/js/appearance.js','assets/js/arcoll-modern.js','assets/js/network-agent-states.js','assets/js/panel.js','pwa/legacy-dashboard.html']
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def allowed(name):
    if name in {'README.md','AGENTS.md','.gitignore','includes/config.php','_manifest.json','error_log','LEIA-ME-ATUALIZACAO.txt'} or name.startswith(('.github/','docs/','tests/','scripts/','pacotes/','uploads/','includes/storage/','_sql/')):return False
    if Path(name).name in {'agent-config.json','agent.json','appearance.json','error_log','.gitkeep'} or name.endswith(('.zip','.log','.pyc')):return False
    if name.endswith('.sql'):return name=='database/schema.sql' or name.startswith('database/migrations/')
    return True
def release_zip_info(name, timestamp):
    # A constant DOS timestamp makes PHP OPcache keep old bytecode after cPanel extraction.
    info=zipfile.ZipInfo(name,timestamp.timetuple()[:6]);info.create_system=3
    info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED
    # Extended UTC mtime avoids timezone ambiguity for extractors which support it.
    info.extra=struct.pack('<HHBI',0x5455,5,1,int(timestamp.timestamp()))
    return info

def main():
    if len(sys.argv) not in (3,4,5) or (len(sys.argv)==5 and sys.argv[3]!='--backup'):raise SystemExit('Use: package_update.py OUTPUT.zip INSTRUCTIONS.txt [BASE_COMMIT | --backup BACKUP.zip]')
    output=Path(sys.argv[1]).resolve();instructions=Path(sys.argv[2]).read_bytes();base=sys.argv[3] if len(sys.argv)==4 else 'HEAD^'
    backup=Path(sys.argv[4]).resolve() if len(sys.argv)==5 else None
    if not backup:git('rev-parse','--verify',base+'^{commit}')
    if git('status','--porcelain').strip():raise SystemExit('Commit the validated release before packaging')
    timestamp=datetime.now(ZoneInfo('America/Belem')).replace(microsecond=0)
    refresh=[]
    if backup:
        # Compare only tracked distributable paths. Never read private config, uploads or production SQL.
        names={n for n in git('ls-files').decode().splitlines() if allowed(n) and (ROOT/n).is_file()}
        files=set();removed=set(RETIRED_PATHS)
        with zipfile.ZipFile(backup) as original:
            previous=set(original.namelist())
            for n in names:
                if n not in previous or original.read(n)!=(ROOT/n).read_bytes():files.add(n)
                elif Path(n).suffix in {'.php','.css','.js','.py','.ps1','.cmd','.bat'} and original.getinfo(n).date_time!=timestamp.timetuple()[:6]:
                    files.add(n);refresh.append(n)
        files.add('VERSION')
    else:
        changed=set(git('diff','--name-only',base,'HEAD').decode().splitlines())
        removed={n for n in git('diff','--name-only','--diff-filter=D',base,'HEAD').decode().splitlines() if allowed(n)}
        files={n for n in changed if allowed(n) and (ROOT/n).is_file()};files.add('VERSION')
    if files&removed:raise SystemExit('Runtime file conflicts with deletion marker')
    for name in files:
        if (ROOT/name).stat().st_size==0:raise SystemExit('Empty runtime file must be classified explicitly: '+name)
    instructions+=('\n\nEXCLUIR MANUALMENTE (NÃO ENVIAR ESTES MARCADORES VAZIOS AO SERVIDOR)\n'+'\n'.join(sorted(removed))+'\n\nARQUIVOS ATUALIZADOS/NOVOS\n'+'\n'.join(sorted(files))+'\n').encode()
    if refresh:instructions+=('\n\nARQUIVOS COM CONTEÚDO JÁ ATUAL: DATA RENOVADA PARA INVALIDAR CACHE ANTIGO\n'+'\n'.join(sorted(refresh))+'\n').encode()
    output.parent.mkdir(parents=True,exist_ok=True)
    def put(archive,name,data):
        archive.writestr(release_zip_info(name,timestamp),data)
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(files):
            data=git('show','HEAD:'+name)
            if data!=(ROOT/name).read_bytes():raise SystemExit('Changed after commit: '+name)
            put(archive,name,data)
        for name in sorted(removed):put(archive,name,b'')
        put(archive,'LEIA-ME-ATUALIZACAO.txt',instructions)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:raise SystemExit('ZIP verification failed')
        for info in archive.infolist():
            if info.date_time[0]<2026 or info.extra!=release_zip_info(info.filename,timestamp).extra:raise SystemExit('Invalid release modification time: '+info.filename)
        for name in files:
            if archive.read(name)!=git('show','HEAD:'+name):raise SystemExit('ZIP content mismatch')
        for name in removed:
            if archive.getinfo(name).file_size!=0:raise SystemExit('Nonempty deletion marker')
    print(f'Verified release {(ROOT/"VERSION").read_text().strip()}: {len(files)} runtime files; {len(removed)} deletion markers; {output}')
if __name__=='__main__':main()
