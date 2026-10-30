"""The release version is generated, and divergence blocks release validation."""
import re
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
class ReleaseVersionTests(unittest.TestCase):
    def test_all_runtime_components_match_release(self):
        version=(ROOT/'VERSION').read_text().strip()
        self.assertRegex(version,r'^\d+\.\d+\.\d+$')
        sources={'includes/bootstrap.php':r"const ARC_APP_VERSION = '([^']+)'",'agent/arcoll_app.py':r"AGENT_VERSION='([^']+)'",'agent/arcoll_updater.py':r"VERSION = '([^']+)'",'agent/arcoll_desktop.py':r"VERSION = '([^']+)'",'agent/arcoll_agent.py':r'VERSION = "([^"]+)"','pwa/legacy-dashboard.html':r'VERSION = "([^"]+)"'}
        for name,pattern in sources.items():
            with self.subTest(component=name):self.assertEqual(re.search(pattern,(ROOT/name).read_text()).group(1),version)
        self.assertIn('arcoll-static-v'+version,(ROOT/'sw.js').read_text())
    def test_updates_remain_compatible_with_older_agent_allowlists(self):
        # Appearance support must stay in an already-approved module during migration.
        app=(ROOT/'agent/arcoll_app.py').read_text();toolkit=(ROOT/'agent/arcoll_toolkit.py').read_text()
        self.assertIn('run_quick_command, AppearanceManager',app)
        self.assertIn('class AppearanceManager:',toolkit)
        self.assertNotIn('arcoll_theme.py',(ROOT/'api/api.php').read_text())
if __name__=='__main__':unittest.main()
