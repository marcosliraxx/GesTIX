# Caminho: tests/test_native_clean_worker.py
"""Exercita o motor real apenas com arquivos sintéticos em diretórios temporários."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'agentes/windows'))
import arcoll_clean as clean
spec = importlib.util.spec_from_file_location('clean_worker', ROOT / 'agentes/windows/Arcoll.WinUI3/Worker/clean_worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

class NativeCleanTests(unittest.TestCase):
    def test_unrecognized_mode_never_runs_cleaner(self):
        with patch.object(clean, 'execute_clean') as execute:
            with self.assertRaises(ValueError): worker.run({'mode': 'shell', 'payload': {'command': 'arbitrary'}})
            execute.assert_not_called()

    def test_scan_preserves_files_and_execution_preserves_exclusions_and_recent_files(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            old, excluded, recent = folder / 'old.tmp', folder / 'keep.tmp', folder / 'recent.tmp'
            for path in (old, excluded, recent): path.write_text('synthetic')
            for path in (old, excluded): os.utime(path, (time.time() - 10 * 86400,) * 2)
            payload = {'categories': ['user_temp'], 'age_days': 7, 'exclusions': [str(excluded)], 'custom_folders': []}
            with patch.dict(os.environ, {'LOCALAPPDATA': directory}), patch.object(clean, '_file_targets', return_value=[(folder, 'tree', ())]), patch.object(clean, '_disk', return_value=None):
                report = worker.run({'mode': 'scan', 'payload': payload})
                self.assertFalse(report['execute']); self.assertTrue(old.exists())
                self.assertEqual(report['file_count'], 1)
                report = worker.run({'mode': 'execute', 'payload': payload})
                self.assertTrue(report['execute']); self.assertFalse(old.exists())
                self.assertTrue(excluded.exists()); self.assertTrue(recent.exists())

    def test_tool_rejects_arbitrary_names_and_invalid_categories(self):
        with self.assertRaises(ValueError): worker.run({'mode': 'tool', 'payload': {'tool': 'shell'}})
        with self.assertRaises(ValueError): worker.run({'mode': 'execute', 'payload': {'categories': ['../../outside'], 'age_days': 7}})

if __name__ == '__main__': unittest.main()
