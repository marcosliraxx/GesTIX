# Caminho: tests/opcache_release_http.py
"""Controlled local PHP server: reproduce stale bytecode with old ZIP dates, then extract a corrected ZIP."""
import argparse
from datetime import datetime
import importlib.util
import os
from pathlib import Path
import urllib.request
import zipfile
from zoneinfo import ZoneInfo

parser=argparse.ArgumentParser();parser.add_argument('--root',required=True);parser.add_argument('--url',required=True);args=parser.parse_args()
root=Path(args.root).resolve()
if root.name!='opcache-release' or not args.url.startswith('http://127.0.0.1:'):raise SystemExit('Dedicated local fixture required')
root.chmod(0o777)  # Dedicated throwaway fixture shared with the PHP worker.
module_path=Path(__file__).resolve().parents[1]/'scripts/package_update.py'
spec=importlib.util.spec_from_file_location('packaging',module_path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def write(name,content,mtime=None):
 p=root/name;p.write_bytes(content);p.chmod(0o666 if name=='probe.php' else 0o644)
 if mtime is not None:os.utime(p,(mtime,mtime))
def get(path):
 with urllib.request.urlopen(args.url+path,timeout=15) as response:return response.read()
(root/'probe.php').unlink(missing_ok=True)
write('write.php',b"<?php $v=($_GET['stage']??'')==='new'?'new':'old';file_put_contents(__DIR__.'/probe.php',\"<?php echo '\".$v.\"';\");touch(__DIR__.'/probe.php',315532800);echo 'ok';")
assert get('write.php?stage=old')==b'ok'
assert get('probe.php')==b'old'
assert get('write.php?stage=new')==b'ok'
assert get('probe.php')==b'old','Must reproduce PHP caching old code with identical modification time'
stamp=datetime.now(ZoneInfo('America/Belem')).replace(microsecond=0)
with zipfile.ZipFile(root/'update.zip','w') as archive:archive.writestr(module.release_zip_info('probe.php',stamp),b"<?php echo 'new';")
(root/'update.zip').chmod(0o644)
write('extract.php',b"<?php $z=new ZipArchive();if($z->open(__DIR__.'/update.zip')!==true||!$z->extractTo(__DIR__))exit('failed');$z->close();echo 'ok';")
assert get('extract.php')==b'ok'
assert (root/'probe.php').stat().st_mtime>315532800
assert get('probe.php')==b'new','A corrected release archive must invalidate cached old PHP code'
print('4 live OPcache checks passed: stale code reproduced with 1980 date; corrected ZIP extraction serves new code.')
