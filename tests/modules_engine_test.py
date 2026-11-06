# Caminho: tests/modules_engine_test.py (validacao temporaria, nao distribuida)
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('clean',ROOT/'agentes/windows/arcoll_clean.py')
clean=importlib.util.module_from_spec(spec);spec.loader.exec_module(clean)

class EngineTests(unittest.TestCase):
 def test_restore_failure_not_empty_success(self):
  with patch.object(clean,'_powershell',return_value=subprocess.CompletedProcess([],1,'','access denied')):
   r=clean._tool_restore({'action':'list'},None)
  self.assertFalse(r['complete']);self.assertEqual(r['points'],[]);self.assertIn('consultar',r['message'])
 def test_restore_empty_success(self):
  with patch.object(clean,'_powershell',return_value=subprocess.CompletedProcess([],0,'[]','')):
   r=clean._tool_restore({'action':'list'},None)
  self.assertTrue(r['complete']);self.assertEqual(r['points'],[])
 def test_restore_real_point_shape(self):
  raw=json.dumps([{'SequenceNumber':7,'Description':'Sintetico','CreationTime':'20261106100000.000000-180','RestorePointType':12}])
  with patch.object(clean,'_powershell',return_value=subprocess.CompletedProcess([],0,raw,'')):
   r=clean._tool_restore({'action':'list'},None)
  self.assertTrue(r['complete']);self.assertEqual(r['points'][0]['sequence'],7);self.assertEqual(r['points'][0]['created'],'20261106100000')
 def test_restore_create_failure_retained(self):
  with patch.object(clean,'_powershell',side_effect=[subprocess.CompletedProcess([],1,'','disabled'),subprocess.CompletedProcess([],0,'[]','')]):
   r=clean._tool_restore({'action':'create'},None)
  self.assertFalse(r['created']);self.assertTrue(r['complete']);self.assertIn('disabled',r['message'])
 def test_driver_error_is_not_no_updates(self):
  with patch.object(clean,'_powershell',return_value=subprocess.CompletedProcess([],1,'','denied')):
   self.assertIsNone(clean._pending_drivers(None))
 def test_driver_valid_empty_result(self):
  with patch.object(clean,'_powershell',return_value=subprocess.CompletedProcess([],0,'[]','')):
   self.assertEqual(clean._pending_drivers(None),[])
 def test_duplicates_on_synthetic_files_only(self):
  with tempfile.TemporaryDirectory() as folder:
   paths=[Path(folder)/n for n in ('a.bin','b.bin','c.bin')]
   for p,data in zip(paths,[b'x'*150000,b'x'*150000,b'y'*150000]):p.write_bytes(data)
   with patch.object(clean,'_duplicate_roots',return_value=[Path(folder)]),patch.object(clean,'_save_cache') as cache:
    r=clean._tool_duplicates({'action':'scan','roots':['users'],'min_size_kb':100},None)
   self.assertEqual(len(r['groups']),1);self.assertEqual(len(r['groups'][0]['files']),2)
   stored=cache.call_args.args[1];ids=[f['id'] for f in stored[0]['files']]
   with patch.object(clean,'_load_cache',return_value=stored),patch.object(clean,'_recycle',return_value=True) as recycle:
    result=clean._delete_duplicates({'ids':ids,'permanent':False})
    self.assertEqual(result['removed'],0);self.assertEqual(result['failed'],2);recycle.assert_not_called()
   with patch.object(clean,'_load_cache',return_value=stored),patch.object(clean,'_recycle',return_value=True) as recycle:
    result=clean._delete_duplicates({'ids':[ids[0]],'permanent':False})
    self.assertEqual(result['removed'],1);self.assertEqual(recycle.call_count,1)
 def test_unknown_tool_rejected(self):
  with self.assertRaises(ValueError):clean.execute_tool({'tool':'arbitrary_shell','action':'run'})

if __name__=='__main__':unittest.main()
