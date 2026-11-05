# Caminho: tests/test_cloud_backup.py
"""Dados exclusivamente sintéticos em diretórios temporários; nenhuma varredura real."""
import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'agentes/windows'))
from arcoll_cloud import CloudBackup, CloudError, safe_local_path, excluded


class MemoryProvider(CloudBackup):
    def __init__(self, folder):
        super().__init__('https://platform.example.test', 'f' * 64, 'T3ST1', 'a' * 64, directory=Path(folder) / 'state')
        self.remote = {}
        self.uploads = {}
        self.begins = 0
        self.break_after = None
        self.mutate = None

    def request(self, action, data=None, binary=None, headers=None):
        if action == 'agent-folders':
            return {'ok': True}
        if action == 'agent-state':
            return {'ok': True, 'job': self.job or {}}
        if action == 'agent-begin':
            self.begins += 1
            uid = hashlib.sha256((data['relative'] + data['file_version']).encode()).hexdigest()[:32]
            item = self.uploads.setdefault(uid, {'data': bytearray(), 'name': data['relative'], 'size': data['size']})
            done = len(item['data']) == item['size']
            if done:
                self.remote[item['name']] = bytes(item['data'])
            return {'ok': True, 'upload_id': uid, 'chunk': 4, 'next_offset': len(item['data']), 'done': done}
        if action == 'agent-chunk':
            item = self.uploads[headers['X-Arcoll-Upload']]
            if self.break_after is not None and len(item['data']) >= self.break_after:
                raise CloudError('Interrupção sintética.', 503)
            assert headers['X-Arcoll-Sha256'] == hashlib.sha256(binary).hexdigest()
            assert int(headers['X-Arcoll-Offset']) == len(item['data'])
            item['data'].extend(binary)
            if self.mutate:
                mutate, self.mutate = self.mutate, None
                mutate()
            done = len(item['data']) == item['size']
            if done:
                self.remote[item['name']] = bytes(item['data'])
            return {'ok': True, 'next_offset': len(item['data']), 'done': done}
        raise AssertionError(action)


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name) / 'data'
        self.root.mkdir()
        self.worker = MemoryProvider(self.tmp.name)
        self.worker.authorize(self.root)
        self.job = {'available': True, 'revision': 'revision-1', 'run_request': 1, 'settings': {
            'folders': [{'path': str(self.root), 'label': 'Dados'}], 'exclusions': ['*.tmp'],
            'max_file_mb': 2, 'bandwidth_kbps': 0, 'schedule': 'manual', 'interval_minutes': 60, 'daily_time': '02:00'}}

    def tearDown(self):
        self.tmp.cleanup()

    def run_backup(self):
        self.worker.run_backup(self.job, 2 * 1048576)

    def test_incremental_empty_exclusion_change_and_local_delete(self):
        path = self.root / 'document.txt'
        path.write_bytes(b'abcdefghijk')
        (self.root / 'empty.txt').write_bytes(b'')
        (self.root / 'ignored.tmp').write_bytes(b'ignore')
        self.run_backup()
        self.assertEqual(self.worker.remote, {'document.txt': b'abcdefghijk', 'empty.txt': b''})
        self.assertEqual(self.worker.snapshot()['state'], 'idle')
        count = self.worker.begins
        self.run_backup()
        self.assertEqual(self.worker.begins, count)
        path.write_bytes(b'changed')
        self.run_backup()
        self.assertEqual(self.worker.remote['document.txt'], b'changed')
        path.unlink()
        self.run_backup()
        self.assertIn('document.txt', self.worker.remote)

    def test_interrupted_file_resumes_and_commits_only_after_completion(self):
        (self.root / 'file.bin').write_bytes(b'0123456789ab')
        self.worker.break_after = 4
        self.run_backup()
        self.assertEqual(self.worker.snapshot()['state'], 'error')
        self.assertNotIn('file.bin', self.worker.remote)
        self.worker.break_after = None
        self.run_backup()
        self.assertEqual(self.worker.remote['file.bin'], b'0123456789ab')
        self.assertEqual(len(self.worker.uploads), 1)

    def test_unapproved_remote_folder_is_never_read(self):
        (self.root / 'private.txt').write_bytes(b'private')
        self.worker.remember('roots', [])
        self.run_backup()
        self.assertFalse(self.worker.remote)
        self.assertEqual(self.worker.snapshot()['state'], 'error')

    def test_symbolic_link_cannot_escape_authorized_root(self):
        target = Path(self.tmp.name) / 'outside.txt'
        target.write_bytes(b'outside')
        (self.root / 'link.txt').symlink_to(target)
        self.run_backup()
        self.assertFalse(self.worker.remote)
        with self.assertRaises(CloudError):
            safe_local_path(self.root / 'link.txt', self.root)

    def test_revocation_is_rechecked_between_blocks(self):
        (self.root / 'file.bin').write_bytes(b'0123456789ab')
        self.worker.mutate = lambda: self.worker.remove_root(str(self.root))
        self.run_backup()
        self.assertNotIn('file.bin', self.worker.remote)
        self.assertEqual(self.worker.snapshot()['state'], 'error')

    def test_changed_open_file_is_not_marked_synced(self):
        file = self.root / 'file.bin'
        file.write_bytes(b'0123456789ab')
        self.worker.mutate = lambda: file.write_bytes(b'NEW-VERSION!')
        self.run_backup()
        self.assertEqual(self.worker.snapshot()['state'], 'error')
        with self.worker.db() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM files').fetchone()[0], 0)

    def test_pause_persists_and_interrupts_before_reading(self):
        self.worker.pause(True)
        with self.assertRaises(CloudError):
            self.worker.check()
        new = MemoryProvider(self.tmp.name)
        self.assertTrue(new.local_paused)

    def test_manual_schedule_requires_request_and_acknowledges_after_run(self):
        self.worker.remember('run_ack', 1)
        self.assertFalse(self.worker.due(self.job))
        self.worker.request_run()
        self.assertTrue(self.worker.due(self.job))
        self.run_backup()
        self.assertFalse(self.worker.due(self.job))

    def test_full_backup_recreates_deleted_remote_files(self):
        (self.root / 'document.txt').write_bytes(b'backup')
        self.run_backup()
        self.worker.remote.clear()
        self.worker.request_run(full=True)
        self.worker.prepare_full_run()
        self.run_backup()
        self.assertEqual(self.worker.remote['document.txt'], b'backup')
        self.assertEqual(self.worker.preference('generation'), 1)

    def test_credentials_are_scoped_to_installation(self):
        new = CloudBackup('https://platform.example.test', 'f' * 64, 'T3ST1', 'b' * 64, directory=self.worker.directory)
        self.assertEqual(new.preference('roots', []), [])

    def test_directory_links_and_drive_root_are_refused(self):
        linked = Path(self.tmp.name) / 'linked'
        linked.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(CloudError):
            self.worker.authorize(linked)
        with self.assertRaises(CloudError):
            self.worker.authorize(Path(self.tmp.name).anchor)

    def test_origin_rejects_plaintext_and_embedded_credentials(self):
        for url in ['http://platform.test', 'https://user:pass@platform.test', 'https://platform.test?key=secret']:
            with self.assertRaises(ValueError):
                CloudBackup(url, 'f' * 64, 'T3ST1', 'a' * 64, directory=self.worker.directory)

    def test_case_insensitive_exclusions_and_size_limits(self):
        self.assertTrue(excluded('Documents/FILE.TMP', ['*.tmp']))
        self.assertTrue(excluded('secret/folder/file.txt', ['secret/*']))
        (self.root / 'large.bin').write_bytes(b'x' * (2 * 1048576 + 1))
        self.run_backup()
        self.assertFalse(self.worker.remote)
        self.assertEqual(self.worker.snapshot()['state'], 'error')


if __name__ == '__main__':
    unittest.main()
