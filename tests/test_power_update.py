# Caminho: tests/test_power_update.py
"""Power commands never touch the host: OS calls are mocked; updates use temporary roots."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'agentes/windows'))
import arcoll_toolkit as toolkit
import arcoll_updater as updater

class PowerUpdateTests(unittest.TestCase):
    def test_power_uses_fixed_arguments_and_never_forces_unsaved_work(self):
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit.subprocess,'run') as run:
            run.return_value.returncode=0
            for action,flag in [('power_shutdown','/s'),('power_restart','/r'),('power_cancel','/a')]:
                toolkit.schedule_power_action(action);args=run.call_args.args[0]
                self.assertIn(flag,args);self.assertNotIn('/f',args);self.assertFalse(run.call_args.kwargs.get('shell',False))
                if flag!='/a':self.assertEqual(args[-2:],['/t','60'])
    def test_power_rejects_arbitrary_command(self):
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit.subprocess,'run') as run:
            with self.assertRaises(ValueError):toolkit.schedule_power_action('shutdown & echo invalid')
            run.assert_not_called()
    def test_power_reports_windows_rejection(self):
        with patch.object(toolkit.platform,'system',return_value='Windows'),patch.object(toolkit.subprocess,'run') as run:
            run.return_value.returncode=5
            with self.assertRaisesRegex(RuntimeError,'Windows'):toolkit.schedule_power_action('power_restart')
    def test_update_accepts_legacy_manifest_and_preserves_private_configuration(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)/'installed';root.mkdir();stage=Path(directory)/'stage';stage.mkdir()
            names=['arcoll_app.py','arcoll_desktop.py','arcoll_updater.py','arcoll_toolkit.py','arcoll_agent.py','arcoll_extra.py']
            files=[]
            for name in names:
                (root/name).write_text('# previous\n');data=b'# updated\n';(stage/name).write_bytes(data);files.append({'name':name,'sha256':hashlib.sha256(data).hexdigest()})
            (root/'agent-config.json').write_text('{"installation":"preserve"}')
            (stage/'manifest.json').write_text(json.dumps({'files':files,'inventory':names,'remove':[]}))
            # Original 2.8+ manifests and argument count remain accepted.
            with patch.object(updater,'__file__',str(root/'arcoll_updater.py')),patch.object(sys,'argv',['updater',str(stage),'1234','https://app.example.test']),patch.object(updater,'wait_for_exit'),patch.object(updater.subprocess,'Popen') as launch:
                updater.run()
            self.assertEqual((root/'arcoll_app.py').read_bytes(),b'# updated\n')
            self.assertEqual(json.loads((root/'agent-config.json').read_text())['installation'],'preserve')
            self.assertIn(str(root/'arcoll_app.py'),launch.call_args.args[0])
    def test_update_rejects_corrupted_release_before_modification(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'arcoll_app.py').write_text('# previous\n')
            names=['arcoll_app.py','arcoll_desktop.py','arcoll_updater.py','arcoll_toolkit.py','arcoll_agent.py','arcoll_extra.py']
            (root/'manifest.json').write_text(json.dumps({'files':[{'name':'arcoll_app.py','sha256':'0'*64}],'inventory':names,'remove':[]}))
            with patch.object(sys,'argv',['updater',directory,'1234','https://app.example.test',directory]),patch.object(updater,'wait_for_exit') as wait:
                with self.assertRaisesRegex(ValueError,'integridade'):updater.run()
                wait.assert_not_called()
            self.assertEqual((root/'arcoll_app.py').read_text(),'# previous\n')
    def test_wait_timeout_does_not_overwrite_running_agent(self):
        with patch.object(updater.os,'name','posix'),patch.object(updater.os,'kill'),patch.object(updater.time,'sleep'):
            with self.assertRaisesRegex(RuntimeError,'não encerrou'):updater.wait_for_exit(1234)
if __name__=='__main__':unittest.main()
