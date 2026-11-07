# Caminho: tests/modules_engine_test.py (validacao temporaria, nao distribuida)
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import sys
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
 def test_backup_status_does_not_overwrite_server_progress(self):
  worker_spec=importlib.util.spec_from_file_location('worker',ROOT/'agentes/servidor/Arcoll.WinUI3/Worker/clean_worker.py')
  worker=importlib.util.module_from_spec(worker_spec);worker_spec.loader.exec_module(worker)
  calls=[]
  class Backup:
   def __init__(self,*args):pass
   def request(self,action,data):calls.append((action,data));return {'job':{'available':True},'status':{'state':'uploading','files_done':9},'connection':None}
   def snapshot(self):return {'state':'waiting'}
  with tempfile.TemporaryDirectory() as folder,patch.object(clean,'DATA_DIR',Path(folder)),patch.dict(sys.modules,{'arcoll_clean':clean,'arcoll_cloud':types.SimpleNamespace(CloudBackup=Backup)}):
   result=worker.run({'mode':'backup_status','config':{'origin':'https://example.test','fingerprint':'synthetic','site_code':'TEST','download_key':'synthetic'}})
  self.assertEqual(calls,[('agent-state',{})]);self.assertEqual(result['status']['state'],'uploading');self.assertEqual(result['status']['files_done'],9)
 def test_health_four_domains_no_registry_mutation(self):
  empty={'categories':{},'file_count':0,'bytes':0,'errors':0}
  with patch.object(clean,'execute_clean',return_value=empty) as scan,patch.object(clean,'_startup_catalog',return_value=[]),patch.object(clean,'_scan_registry',side_effect=AssertionError('Registro nao pertence ao nucleo')),patch.object(clean,'_winget_exe',return_value='synthetic'),patch.object(clean,'_run',return_value=subprocess.CompletedProcess([],0,'','')),patch.object(clean,'_parse_winget_table',return_value=[{'id':'Test.App','name':'Synthetic','version':'1','available':'2'}]):
   result=clean._tool_health({'space':['user_temp'],'privacy':['recent_docs'],'exclusions':['synthetic exclusion']},None)
   self.assertEqual(result['security']['available'],1);self.assertTrue(result['security']['complete']);self.assertEqual(result['registry']['issues'],0)
   self.assertEqual(scan.call_args_list[0].args[4],['synthetic exclusion'])
 def test_health_unavailable_updates_not_secure_zero(self):
  with patch.object(clean,'_startup_catalog',return_value=[]),patch.object(clean,'_winget_exe',side_effect=RuntimeError('missing')):
   result=clean._tool_health({'space':[],'privacy':[]},None)
  self.assertFalse(result['security']['complete']);self.assertTrue(result['incomplete'])
 def test_unknown_tool_rejected(self):
  with self.assertRaises(ValueError):clean.execute_tool({'tool':'arbitrary_shell','action':'run'})

if __name__=='__main__':unittest.main()
