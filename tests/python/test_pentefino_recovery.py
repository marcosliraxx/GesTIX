"""Exercise the real mutation paths with an isolated fake registry; never touch Windows registry."""
import importlib.util
import tempfile
import os
import time
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('clean', 'gestores/windows/arcoll_clean.py')
clean = importlib.util.module_from_spec(spec)
spec.loader.exec_module(clean)

class NoRecovery(unittest.TestCase):
    def test_period_filters_recent_files_and_preserves_older_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            recent, old = root / 'recent.tmp', root / 'old.tmp'
            recent.write_text('recent')
            old.write_text('old')
            os.utime(recent, (time.time()-86400, time.time()-86400))
            os.utime(old, (time.time()-30*86400, time.time()-30*86400))
            with patch.object(clean, '_base_paths', return_value=[root]):
                scan = clean.execute_clean(['user_temp'], age_days=0, period_days=7)
                self.assertEqual(scan['file_count'], 1)
                self.assertTrue(recent.exists())
                fixed = clean.execute_clean(['user_temp'], execute=True, age_days=0, period_days=7)
                self.assertEqual(fixed['file_count'], 1)
                self.assertFalse(recent.exists())
                self.assertTrue(old.exists())

    def test_invalid_period_is_refused(self):
        for period in (True, -1, 14, 366, '7'):
            with self.assertRaises(ValueError):
                clean.execute_clean(['user_temp'], age_days=0, period_days=period)

    def test_registry_selected_deletion_without_export(self):
        issue = {'id':'chosen', 'hive':'HKCU', 'key':'Fixture', 'value':None}
        with tempfile.TemporaryDirectory() as tmp, patch.object(clean, 'DATA_DIR', Path(tmp)), patch.object(clean, '_export_key') as export, patch.object(clean, '_delete_key_tree') as delete:
            result = clean._fix_registry([issue], {'chosen'}, None, recovery_copies=False)
            self.assertEqual(result, {'fixed':1, 'failed':0, 'backup':None})
            export.assert_not_called()
            delete.assert_called_once_with('HKCU', 'Fixture')
            self.assertEqual(list(Path(tmp).iterdir()), [])

    def test_unselected_registry_is_untouched(self):
        issue = {'id':'other', 'hive':'HKCU', 'key':'Fixture', 'value':None}
        with patch.object(clean, '_delete_key_tree') as delete, patch.object(clean, '_export_key') as export:
            result = clean._fix_registry([issue], {'chosen'}, None, recovery_copies=False)
            delete.assert_not_called()
            export.assert_not_called()
            self.assertEqual(result['fixed'], 0)

    def test_startup_file_removal_without_recycle_copy(self):
        with tempfile.TemporaryDirectory() as tmp:
            fixture = Path(tmp) / 'fixture.lnk'
            fixture.write_text('fixture')
            item = {'id':'chosen','kind':'folder','name':'Fixture','_entry':'fixture.lnk','_file':str(fixture)}
            with patch.object(clean, '_recycle') as recycle:
                result = clean._apply_startup([item], [{'id':'chosen','op':'delete'}], recovery_copies=False)
                recycle.assert_not_called()
                self.assertFalse(fixture.exists())
                self.assertEqual(result, {'done':1, 'failed':0})

    def test_startup_unselected_file_survives(self):
        with tempfile.TemporaryDirectory() as tmp:
            fixture = Path(tmp) / 'fixture.lnk'
            fixture.write_text('fixture')
            item = {'id':'chosen','kind':'folder','name':'Fixture','_entry':'fixture.lnk','_file':str(fixture)}
            result = clean._apply_startup([item], [{'id':'foreign','op':'delete'}], recovery_copies=False)
            self.assertTrue(fixture.exists())
            self.assertEqual(result['failed'], 1)

if __name__ == '__main__':
    unittest.main()
