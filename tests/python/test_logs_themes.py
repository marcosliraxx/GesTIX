import ast,importlib.util,json,os,pathlib,sys,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'gestores/windows'))
from arcoll_logsync import LogSync,redact
class Tests(unittest.TestCase):
 def test_redaction(self):
  secret='a'*64;clean=redact('password="many secret words" download_key='+secret+' ?t='+secret+' Bearer abc.def')
  for value in [secret,'secret words','abc.def']:self.assertNotIn(value,clean)
  self.assertLess(len(redact('😀'*10000).encode()),12000)
 def test_offline_scope(self):
  with tempfile.TemporaryDirectory()as temporary:
   base=pathlib.Path(temporary);logs=base/'root/logs';logs.mkdir(parents=True);source=logs/'runtime.log';source.write_text('old A\n',encoding='utf-8')
   identity={'origin':'https://fixture.test','fingerprint':'b'*64,'site_code':'TEST1','download_key':'a'*64}
   sync=LogSync(lambda:identity,state_dir=base/'state');sync.roots=lambda:[(base/'root','test')]
   class Offline:
    def open(self,*a,**k):raise OSError('offline fixture')
   sync.http=Offline();sync.pump();files=list((base/'state/outbox').glob('**/*.json'));self.assertEqual(len(files),1)
   sync.pump();self.assertTrue(files[0].exists())
   identity['site_code']='TEST2';sync.pump();self.assertEqual(len(list((base/'state/outbox').glob('**/*.json'))),1)
   with source.open('a',encoding='utf-8')as f:f.write('new B\n')
   sync.pump();batches=[json.loads(p.read_text())for p in (base/'state/outbox').glob('**/*.json')];messages=[[e['message']for e in b['events']]for b in batches];self.assertIn(['new B'],messages)
 def test_all_contrasts(self):
  data=json.loads((ROOT/'assets/themes/catalog.json').read_text());self.assertEqual(len(data['themes']),10)
  def lum(h):
   c=[int(h[i:i+2],16)/255 for i in (1,3,5)];return sum((v/12.92 if v<=.04045 else ((v+.055)/1.055)**2.4)*w for v,w in zip(c,[.2126,.7152,.0722]))
  def ratio(a,b):x,y=sorted([lum(a),lum(b)]);return (y+.05)/(x+.05)
  for name,t in data['themes'].items():
   for mode,p in t['variants'].items():
    for foreground,background in [('text','surface'),('muted','surface'),('accent','surface'),('onaccent','accent'),('onaccent','accent_hover')]:self.assertGreaterEqual(ratio(p[foreground],p[background]),4.5,(name,mode,foreground))
  self.assertEqual(json.loads((ROOT/'gestores/servidor/Arcoll.WinUI3/Assets/theme-catalog.json').read_text()),data)
 def test_python_accepts_all_themes(self):
  # Extract class declarations without importing/starting the desktop or touching a Windows user profile.
  import re
  tree=ast.parse((ROOT/'gestores/windows/arcoll_toolkit.py').read_text());klass=next(n for n in tree.body if isinstance(n,ast.ClassDef)and n.name=='AppearanceManager');scope={'os':os,'re':re,'json':json,'platform':__import__('platform'),'Path':pathlib.Path};exec(compile(ast.Module(body=[klass],type_ignores=[]),'appearance-fixture','exec'),scope)
  manager=scope['AppearanceManager'].__new__(scope['AppearanceManager']);manager.remote_choice=None;manager.remote_mode='theme';manager.apply=lambda:None
  for t in json.loads((ROOT/'assets/themes/catalog.json').read_text())['themes'].values():
   for mode in ['theme','light','dark','auto']:
    self.assertTrue(manager.set_published_theme(dict(t,mode=mode)));self.assertEqual(manager.remote_choice,t['id'])
if __name__=='__main__':unittest.main()
