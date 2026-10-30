#!/usr/bin/env python3
"""Package a committed release without private configuration or production data."""
from pathlib import Path
import subprocess
import sys
import zipfile
ROOT=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def allowed(name):
    return not (name in {'README.md','AGENTS.md','.gitignore','includes/config.php'} or name.startswith(('docs/','tests/','scripts/','uploads/','includes/storage/','_sql/')) or Path(name).name in {'agent-config.json','agent.json','appearance.json'} or name.endswith(('.sql','.zip','.log','.pyc')))
def main():
    if len(sys.argv)!=3:raise SystemExit('Use: package_update.py OUTPUT.zip INSTRUCTIONS.txt')
    output=Path(sys.argv[1]).resolve();instructions=Path(sys.argv[2]).read_bytes()
    version=(ROOT/'VERSION').read_text().strip()
    if git('status','--porcelain').strip():raise SystemExit('Commit the validated release before packaging')
    files=set(git('diff-tree','--no-commit-id','--name-only','-r','HEAD').decode().splitlines())
    files.update(git('ls-files','agent').decode().splitlines())
    files={name for name in files if allowed(name) and (ROOT/name).is_file()}
    files.add('VERSION');output.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name in sorted(files,key=lambda n:(n.endswith('.php'),n)):
            data=git('show','HEAD:'+name)
            if data!=(ROOT/name).read_bytes():raise SystemExit('Changed after commit: '+name)
            info=zipfile.ZipInfo(name);info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,data)
        info=zipfile.ZipInfo('LEIA-ME-ATUALIZACAO.txt');info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(info,instructions)
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:raise SystemExit('ZIP verification failed')
        for name in files:
            if archive.read(name)!=git('show','HEAD:'+name):raise SystemExit('ZIP content mismatch')
    print(f'Verified release {version}: {len(files)} application files; {output}')
if __name__=='__main__':main()
