#!/usr/bin/env python3
# Caminho: scripts/package_update.py
"""Package only committed runtime changes and zero-byte manual deletion markers."""
from pathlib import Path
import subprocess
import sys
import zipfile
ROOT=Path(__file__).resolve().parents[1]
RETIRED_PATHS=['assets/css/appearance.css','assets/css/arcoll-modern.css','assets/css/mobile-app.css','assets/css/network-agent-states.css','assets/css/panel.css','assets/css/platform-mobile.css','assets/js/appearance.js','assets/js/arcoll-modern.js','assets/js/network-agent-states.js','assets/js/panel.js','pwa/legacy-dashboard.html']
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def allowed(name):
    if name in {'README.md','AGENTS.md','.gitignore','includes/config.php'} or name.startswith(('docs/','tests/','scripts/','pacotes/','uploads/','includes/storage/','_sql/')):return False
    if Path(name).name in {'agent-config.json','agent.json','appearance.json'} or name.endswith(('.zip','.log','.pyc')):return False
    if name.endswith('.sql'):return name=='database/schema.sql' or name.startswith('database/migrations/')
    return True
def main():
    if len(sys.argv) not in (3,4):raise SystemExit('Use: package_update.py OUTPUT.zip INSTRUCTIONS.txt [BASE_COMMIT]')
    output=Path(sys.argv[1]).resolve();instructions=Path(sys.argv[2]).read_bytes();base=sys.argv[3] if len(sys.argv)==4 else 'HEAD^'
    git('rev-parse','--verify',base+'^{commit}')
    if git('status','--porcelain').strip():raise SystemExit('Commit the validated release before packaging')
    changed=set(git('diff','--name-only',base,'HEAD').decode().splitlines())
    removed={n for n in git('diff','--name-only','--diff-filter=D',base,'HEAD').decode().splitlines() if allowed(n)}
    removed.update(RETIRED_PATHS)
    files={n for n in changed if allowed(n) and (ROOT/n).is_file()};files.add('VERSION')
    if files&removed:raise SystemExit('Runtime file conflicts with deletion marker')
    instructions+=('\n\nEXCLUIR MANUALMENTE (NÃO ENVIAR ESTES MARCADORES VAZIOS AO SERVIDOR)\n'+'\n'.join(sorted(removed))+'\n\nARQUIVOS ATUALIZADOS/NOVOS\n'+'\n'.join(sorted(files))+'\n').encode()
    output.parent.mkdir(parents=True,exist_ok=True)
    def put(archive,name,data):
        info=zipfile.ZipInfo(name);info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,data)
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(files):
            data=git('show','HEAD:'+name)
            if data!=(ROOT/name).read_bytes():raise SystemExit('Changed after commit: '+name)
            put(archive,name,data)
        for name in sorted(removed):put(archive,name,b'')
        put(archive,'LEIA-ME-ATUALIZACAO.txt',instructions)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:raise SystemExit('ZIP verification failed')
        for name in files:
            if archive.read(name)!=git('show','HEAD:'+name):raise SystemExit('ZIP content mismatch')
        for name in removed:
            if archive.getinfo(name).file_size!=0:raise SystemExit('Nonempty deletion marker')
    print(f'Verified release {(ROOT/"VERSION").read_text().strip()}: {len(files)} runtime files; {len(removed)} deletion markers; {output}')
if __name__=='__main__':main()
