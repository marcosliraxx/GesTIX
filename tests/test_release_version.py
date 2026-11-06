# Caminho: tests/test_release_version.py
"""A plataforma, os agentes e o atualizador compartilham VERSION."""
from pathlib import Path
import re
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'agentes/windows'))


class ReleaseTests(unittest.TestCase):
    def test_single_release_source(self):
        from arcoll_release import VERSION
        self.assertEqual(VERSION, (ROOT / 'VERSION').read_text().strip())
        self.assertRegex(VERSION, r'^\d+\.\d+\.\d+$')
        for name in ['arcoll_app.py', 'arcoll_agent.py', 'arcoll_toolkit.py', 'arcoll_desktop.py', 'arcoll_updater.py', 'arcoll_cloud.py']:
            source = (ROOT / 'agentes/windows' / name).read_text()
            self.assertIn('from arcoll_release import VERSION', source, name)
            self.assertFalse(re.search(r'^(?:AGENT_VERSION|VERSION)\s*=\s*[\'\"]\d', source, re.M), name)
        self.assertIn('arc_app_version()', (ROOT / 'includes/releases.php').read_text())
        self.assertIn('release-info', (ROOT / 'sw.js').read_text())

    def test_native_components_share_release_version(self):
        import xml.etree.ElementTree as ET
        version = (ROOT / 'VERSION').read_text().strip()
        native = ROOT / 'agentes/windows/Arcoll.WinUI3'
        for name in ('Arcoll.WinUI3.csproj', 'BootWorker/BootWorker.csproj'):
            self.assertEqual(ET.parse(native / name).findtext('PropertyGroup/Version'), version)
        worker = (native / 'BootWorker/Program.cs').read_text()
        self.assertIn('version = Version', worker)
        self.assertNotRegex(worker, r'version = "\d+\.\d+\.\d+"')

    def test_worker_is_in_canonical_installer_and_updater(self):
        self.assertIn("'arcoll_cloud.py'", (ROOT / 'includes/bootstrap.php').read_text())
        self.assertIn("'arcoll_cloud.py'", (ROOT / 'agentes/windows/arcoll_updater.py').read_text())

    def test_consolidated_schema_contains_no_production_records(self):
        schema = (ROOT / 'database/schema.sql').read_text()
        self.assertNotRegex(schema, r'(?im)^\s*(?:INSERT|REPLACE|DELETE|DROP|TRUNCATE)\s')
        self.assertNotIn('AUTO_INCREMENT=', schema)
        self.assertIn('CREATE TABLE IF NOT EXISTS', schema)


if __name__ == '__main__':
    unittest.main()
