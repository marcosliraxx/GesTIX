# Caminho: tests/test_file_transfer.py
"""Transport and real temporary-file writes, without a live Windows or remote host."""
import ast
import hashlib
import json
from pathlib import Path
import tempfile
import threading
import types
import unittest
from unittest.mock import patch, MagicMock
import sys
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'agent'))
import arcoll_toolkit as toolkit

class FileTransportTests(unittest.TestCase):
    auth={'fingerprint':'a'*64,'site_code':'ABCDE','download_key':'b'*64}
    def response(self,data,url='https://app.example.test/api/support.php?action=file-agent-details&id=1'):
        response=MagicMock();response.__enter__.return_value=response;response.read.return_value=data;response.geturl.return_value=url;return response
    def test_transport_keeps_credentials_in_headers_and_reads_bounded_json(self):
        with patch('urllib.request.build_opener') as build:
            build.return_value.open.return_value=self.response(b'{"ok":true}')
            self.assertTrue(toolkit.transfer_request('https://app.example.test','file-agent-details',self.auth,1)['ok'])
            request=build.return_value.open.call_args.args[0]
            self.assertNotIn(self.auth['download_key'],request.full_url)
            self.assertEqual(request.get_header('X-arcoll-key'),self.auth['download_key'])
            build.return_value.open.return_value.read.assert_called_once_with(65537)
    def test_redirect_is_rejected(self):
        with patch('urllib.request.build_opener') as build:
            build.return_value.open.return_value=self.response(b'{"ok":true}',url='https://other.example.test/')
            with self.assertRaisesRegex(RuntimeError,'Resposta inválida'):toolkit.transfer_request('https://app.example.test','file-agent-details',self.auth,1)
    def test_insecure_origin_and_arbitrary_action_are_rejected(self):
        with patch('urllib.request.build_opener') as build:
            for origin,action in [('http://app.example.test','file-agent-details'),('https://user:pass@app.example.test','file-agent-details'),('https://app.example.test','other')]:
                with self.assertRaises(ValueError):toolkit.transfer_request(origin,action,self.auth,1)
            build.assert_not_called()
    def test_upload_limit_precedes_network(self):
        with patch('urllib.request.build_opener') as build:
            with self.assertRaises(ValueError):toolkit.transfer_request('https://app.example.test','file-agent-upload',self.auth,1,payload=b'x'*8388609,filename='x.txt')
            build.assert_not_called()

class AgentFileFlowTests(unittest.TestCase):
    def run_flow(self,direction,selected,data=b'file contents',digest=None,consent=True,headless=False):
        source=ast.parse((ROOT/'agent/arcoll_app.py').read_text());cls=next(n for n in source.body if isinstance(n,ast.ClassDef) and n.name=='ArcollApp');method=next(n for n in cls.body if isinstance(n,ast.FunctionDef) and n.name=='_request_file_transfer')
        class ImmediateThread:
            def __init__(self,target,**kwargs):self.target=target
            def start(self):self.target()
        root=types.SimpleNamespace(after=lambda delay,fn:fn())
        app=types.SimpleNamespace(root=root,show_window=MagicMock(),_ack_remote_command=MagicMock(),_operation_message=MagicMock())
        file_dialog=types.SimpleNamespace(asksaveasfilename=MagicMock(return_value=str(selected)),askopenfilename=MagicMock(return_value=str(selected)))
        message=types.SimpleNamespace(askyesno=MagicMock(return_value=consent),showerror=MagicMock())
        row={'headless':headless,'direction':direction,'filename':'test.txt','file_size':len(data),'sha256':digest or hashlib.sha256(data).hexdigest(),'requester':'Synthetic support'}
        calls=[]
        def request(origin,action,auth,ident,payload=None,**kwargs):
            calls.append((action,payload,kwargs))
            return {'transfer':row} if action=='file-agent-details' else data if kwargs.get('binary') else {'ok':True}
        scope={'ORIGIN':'https://app.example.test','FINGERPRINT':'a'*64,'SITE_CODE':'ABCDE','DOWNLOAD_KEY':'b'*64,'request_json':lambda *a,**k:{'ok':True,'transfer_id':1},'threading':types.SimpleNamespace(Thread=ImmediateThread),'filedialog':file_dialog,'messagebox':message,'friendly_error':str,'Path':Path,'tempfile':tempfile,'os':__import__('os'),'hashlib':hashlib}
        exec(compile(ast.Module(body=[method],type_ignores=[]),'<agent-file-flow>','exec'),scope)
        with patch.object(toolkit,'transfer_request',side_effect=request):scope['_request_file_transfer'](app,123)
        self.assertFalse(app._file_transfer_busy);return calls,message,file_dialog
    def test_received_file_is_saved_only_at_chosen_destination(self):
        with tempfile.TemporaryDirectory() as folder:
            target=Path(folder)/'chosen.txt';calls,_,dialog=self.run_flow('to_agent',target)
            self.assertEqual(target.read_bytes(),b'file contents');self.assertIn(('file-agent-finish',{'status':'completed'},{}),calls)
            self.assertTrue(dialog.asksaveasfilename.call_args.kwargs['confirmoverwrite'])
    def test_invalid_hash_does_not_replace_existing_file(self):
        with tempfile.TemporaryDirectory() as folder:
            target=Path(folder)/'chosen.txt';target.write_bytes(b'original')
            calls,message,_=self.run_flow('to_agent',target,digest='0'*64)
            self.assertEqual(target.read_bytes(),b'original');self.assertIn(('file-agent-finish',{'status':'failed'},{}),calls);message.showerror.assert_called_once()
    def test_send_reads_only_the_file_selected_locally(self):
        with tempfile.TemporaryDirectory() as folder:
            target=Path(folder)/'chosen.txt';target.write_bytes(b'selected only');calls,_,_=self.run_flow('from_agent',target)
            upload=next(c for c in calls if c[0]=='file-agent-upload');self.assertEqual(upload[1],b'selected only');self.assertEqual(upload[2]['filename'],'chosen.txt')
    def test_headless_receive_uses_managed_inbox_without_prompt(self):
        with tempfile.TemporaryDirectory() as folder,patch.object(Path,'home',return_value=Path(folder)):
            calls,message,dialog=self.run_flow('to_agent','',headless=True)
            saved=list((Path(folder)/'Downloads/ArcollRecebidos').glob('*'))
            self.assertEqual(len(saved),1);self.assertEqual(saved[0].read_bytes(),b'file contents');message.askyesno.assert_not_called();dialog.asksaveasfilename.assert_not_called()
    def test_headless_send_reads_only_explicit_shared_file(self):
        with tempfile.TemporaryDirectory() as folder,patch.object(Path,'home',return_value=Path(folder)):
            share=Path(folder)/'Documents/ArcollCompartilhados';share.mkdir(parents=True);(share/'shared.txt').write_bytes(b'explicitly shared')
            (Path(folder)/'private.txt').write_bytes(b'never read')
            calls,message,dialog=self.run_flow('from_agent','',headless=True)
            upload=next(c for c in calls if c[0]=='file-agent-upload');self.assertEqual(upload[1],b'explicitly shared');message.askyesno.assert_not_called();dialog.askopenfilename.assert_not_called()
    def test_declining_never_downloads_or_uploads(self):
        calls,_,dialog=self.run_flow('to_agent','',consent=False)
        self.assertFalse(any(c[0] in ('file-agent-upload','file-agent-download') for c in calls));self.assertIn(('file-agent-finish',{'status':'declined'},{}),calls);dialog.asksaveasfilename.assert_not_called()
if __name__=='__main__':unittest.main()
