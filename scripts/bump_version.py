#!/usr/bin/env python3
"""Keep all shipped components aligned. Run for every release: bump_version.py X.Y.Z."""
from pathlib import Path
import re
import sys
ROOT=Path(__file__).resolve().parents[1]
TARGETS={
    'includes/bootstrap.php':r"(const ARC_APP_VERSION = ')[^']+(')",
    'agent/arcoll_app.py':r"(AGENT_VERSION=')[^']+(')",
    'agent/arcoll_updater.py':r"(VERSION = ')[^']+(')",
    'agent/arcoll_desktop.py':r"(VERSION = ')[^']+(')",
    'agent/arcoll_agent.py':r'(VERSION = ")[^"]+(")',
    'pwa/legacy-dashboard.html':r'(VERSION = ")[^"]+(")',
}
def main():
    version=sys.argv[1] if len(sys.argv)==2 else ''
    if not re.fullmatch(r'\d+\.\d+\.\d+',version):raise SystemExit('Use: python3 scripts/bump_version.py X.Y.Z')
    updates={}
    for name,pattern in TARGETS.items():
        path=ROOT/name
        text,count=re.subn(pattern,lambda m:m[1]+version+m[2],path.read_text())
        if count!=1:raise SystemExit('Expected one version in '+name)
        updates[path]=text
    for name in ['includes/header.php','includes/footer.php','index.php','pages/install.php','pages/renovar-senha.php']:
        path=ROOT/name
        updates[path]=re.sub(r'(?<=\?v=)\d+\.\d+\.\d+',version,updates.get(path,path.read_text()))
    path=ROOT/'sw.js';updates[path]=re.sub(r'arcoll-static-v[\d.]+','arcoll-static-v'+version,path.read_text())
    for path,text in updates.items():path.write_text(text)
    (ROOT/'VERSION').write_text(version+'\n')
    print('Aligned platform and agent release:',version)
if __name__=='__main__':main()
