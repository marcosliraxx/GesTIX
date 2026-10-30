# Caminho: tests/test_package_metadata.py
"""Release archives must refresh modification time and keep private data out."""
from datetime import datetime
import importlib.util
from pathlib import Path
import struct
import tempfile
import unittest
import zipfile
from zoneinfo import ZoneInfo
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('package_update',ROOT/'scripts/package_update.py');package=importlib.util.module_from_spec(spec);spec.loader.exec_module(package)
class PackageMetadataTests(unittest.TestCase):
    def test_archive_roundtrip_preserves_real_date_utc_time_permissions_and_bytes(self):
        stamp=datetime.now(ZoneInfo('America/Belem')).replace(microsecond=0)
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'release.zip'
            with zipfile.ZipFile(path,'w') as archive:archive.writestr(package.release_zip_info('redes/ferramentas.php',stamp),b'fixture')
            with zipfile.ZipFile(path) as archive:
                info=archive.getinfo('redes/ferramentas.php')
                self.assertEqual(info.date_time[:5],stamp.timetuple()[:5]);self.assertNotEqual(info.date_time[0],1980)
                self.assertEqual(struct.unpack('<HHBI',info.extra),(0x5455,5,1,int(stamp.timestamp())))
                self.assertEqual((info.external_attr>>16)&0o777,0o644);self.assertEqual(archive.read(info),b'fixture')
    def test_private_config_data_logs_and_backup_manifest_are_excluded(self):
        for path in ['includes/config.php','uploads/logo.png','includes/storage/transfers/file','_sql/production.sql','api/error_log','pages/error_log','_manifest.json','agent/agent-config.json']:
            self.assertFalse(package.allowed(path),path)
    def test_canonical_migration_and_source_remain_distributable(self):
        self.assertTrue(package.allowed('database/migrations/3.3.0.sql'));self.assertTrue(package.allowed('redes/ferramentas.php'))
if __name__=='__main__':unittest.main()
