"""Diagnóstico autenticado; fila local redigida, HTTPS e ACK por lote. Não coleta arquivos pessoais."""
import hashlib,json,os,re,threading,time,urllib.request,urllib.parse
from pathlib import Path

def redact(text):
 text=str(text)
 text=re.sub(r'(?i)([?&](?:t|token|key|download_key|access_token|refresh_token)=)[^\s&\"\'<>]+',r'\1[oculto]',text)
 text=re.sub(r'''(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\s*["']?\s*[:=]\s*)(["'])(.*?)\2''',r'\1\2[oculto]\2',text)
 text=re.sub(r'''(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\s*["']?\s*[:=]\s*["']?)[^\s,"'\r\n}]+''',r'\1[oculto]',text)
 text=re.sub(r'(?i)\bBearer\s+[A-Za-z0-9._~+/-]+','Bearer [oculto]',text)
 text=re.sub(r'(?i)\b[a-f0-9]{64}\b','[hash/chave oculto]',text)
 text=re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]','',text)
 return text[:3800].encode('utf-8')[:10000].decode('utf-8',errors='ignore')

def write_log(message,level='INFO',component='gestor-python'):
 try:
  directory=Path(os.environ.get('LOCALAPPDATA',str(Path.home())))/'Arcoll'/'logs';directory.mkdir(parents=True,exist_ok=True)
  target=directory/(re.sub('[^a-zA-Z0-9_-]','',component)+'.log')
  if target.exists() and target.stat().st_size>2097152:
   old=target.with_name(target.stem+'-previous.log');os.replace(target,old)
  with target.open('a',encoding='utf-8')as f:f.write(time.strftime('%Y-%m-%d %H:%M:%S')+' ['+str(level)[:10]+'] '+redact(message)+'\n')
 except (OSError,ValueError):pass

class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None

class LogSync:
 def __init__(self,identity,system_only=False,state_dir=None):
  self.identity=identity;self.system_only=system_only;self.stop_event=threading.Event();self.thread=None
  self.state=Path(state_dir)if state_dir else Path(os.environ.get('LOCALAPPDATA',str(Path.home())))/'Arcoll'/'LogUploadPython'
  self.http=urllib.request.build_opener(NoRedirect())
 def start(self):
  if self.thread is None:self.thread=threading.Thread(target=self.run,name='GestorLogSync',daemon=True);self.thread.start()
  return self
 def close(self):self.stop_event.set()
 def run(self):
  while not self.stop_event.is_set():
   try:self.pump()
   except Exception:pass # A falha de envio não pode alimentar o próprio coletor.
   self.stop_event.wait(30)
 def roots(self):
  root=[]
  if not self.system_only:
   for env,name,label in [('LOCALAPPDATA','Arcoll','user-arcoll'),('LOCALAPPDATA','TI-Digital','user-ti'),('APPDATA','Arcoll','profile-arcoll')]:root.append((Path(os.environ.get(env,str(Path.home())))/name,label))
  root.append((Path(os.environ.get('PROGRAMDATA','C:/ProgramData'))/'TI-Digital','system'))
  return root
 @staticmethod
 def atomic(path,data):
  temp=path.with_name(path.name+'.'+os.urandom(8).hex()+'.tmp')
  try:temp.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8');os.replace(temp,path)
  finally:
   if temp.exists():temp.unlink()
 def pump(self):
  auth=dict(self.identity()or{});origin=str(auth.pop('origin','')).rstrip('/');uri=urllib.parse.urlsplit(origin)
  if uri.scheme!='https' or not uri.hostname or uri.username or uri.password or uri.query:return
  if not re.fullmatch('[a-f0-9]{64}',str(auth.get('fingerprint','')))or not re.fullmatch('[A-Z0-9]{5}',str(auth.get('site_code','')))or not re.fullmatch('[a-fA-F0-9]{64}',str(auth.get('download_key',''))):return
  scope=hashlib.sha256((origin+'|'+auth['fingerprint']+'|'+auth['site_code']+'|'+auth['download_key']).encode()).hexdigest();queue=self.state/'outbox'/scope;queue.mkdir(parents=True,exist_ok=True)
  for path in sorted(queue.glob('*.json'),key=lambda p:p.stat().st_mtime)[:4]:
   batch=json.loads(path.read_text(encoding='utf-8'));body=dict(auth,**batch);payload=json.dumps(body).encode();request=urllib.request.Request(origin+'/api/gestor-logs.php',data=payload,headers={'Content-Type':'application/json','Accept':'application/json','User-Agent':'ArcollGestor/LogSync'},method='POST')
   try:
    with self.http.open(request,timeout=12)as response:data=response.read(8193)
    if len(data)>8192:break
    ack=json.loads(data)
    if ack.get('ok')is not True or ack.get('batch_id')!=batch['batch_id'] or ack.get('accepted')!=len(batch['events']):break
    path.unlink()
   except (OSError,ValueError):break
  if sum(p.stat().st_size for p in (self.state/'outbox').glob('**/*.json'))>32*1024*1024:return
  cursor_path=self.state/('cursor-'+scope+'.json')
  try:cursors=json.loads(cursor_path.read_text())if cursor_path.exists()else{}
  except (OSError,ValueError):cursors={}
  current_scope=self.state/'current-scope.json'
  previous=json.loads(current_scope.read_text()).get('scope','')if current_scope.exists()else''
  changed=bool(previous and previous!=scope)
  if previous!=scope:self.atomic(current_scope,{'scope':scope})
  count=0;seen=set()
  for root,label in self.roots():
   if not root.is_dir()or root.is_symlink():continue
   for directory,dirs,names in os.walk(root,followlinks=False):
    dirs[:]=[d for d in dirs if not Path(directory,d).is_symlink() and len(Path(directory,d).relative_to(root).parts)<=5]
    if not any(p.lower()=='logs'for p in Path(directory).relative_to(root).parts):continue
    for name in names:
     path=Path(directory,name)
     if path.is_symlink()or path in seen or not name.lower().endswith(('.log','.txt','.jsonl')):continue
     seen.add(path)
     if len(seen)>200:return
     stat=path.stat();key=hashlib.sha256((str(path).lower()+'|'+str(getattr(stat,'st_birthtime_ns',stat.st_ctime_ns if os.name=='nt'else stat.st_ino))).encode()).hexdigest();offset=stat.st_size if changed else int(cursors.get(key,0));offset=0 if offset>stat.st_size else max(0,offset)
     if changed:cursors[key]=offset;self.atomic(cursor_path,cursors);continue
     with path.open('rb')as f:
      bom=f.read(3);encoding='utf-16-le'if bom[:2]==b'\xff\xfe'else'utf-8';skip=2 if encoding=='utf-16-le'else 3 if bom==b'\xef\xbb\xbf'else 0;offset=max(offset,skip);f.seek(offset);raw=f.read(16384)
     if not raw:continue
     unit=b'\n\x00'if encoding=='utf-16-le'else b'\n';end=0;lines=0;position=0
     while lines<100:
      n=raw.find(unit,position)
      if n<0:break
      end=n+len(unit);position=end;lines+=1
     if not end:
      if len(raw)<16384 and time.time()-stat.st_mtime<5:continue
      end=len(raw)-(len(raw)%2 if encoding=='utf-16-le'else 0)
     source=(label+'/'+str(path.relative_to(root)).replace('\\','/'))[-150:];events=[{'source':source,'level':'ERROR'if re.search('error|erro|falha|critical',s,re.I)else'INFO','message':redact(s.rstrip('\r'))}for s in raw[:end].decode(encoding,errors='replace').split('\n')if s.strip()][:100]
     if events:
      batch_id=hashlib.sha256((scope+'|'+key+'|'+str(offset)+'|'+str(offset+end)+'|'+json.dumps(events,sort_keys=True)).encode()).hexdigest();self.atomic(queue/(batch_id+'.json'),{'batch_id':batch_id,'events':events});count+=1
     cursors[key]=offset+end;self.atomic(cursor_path,cursors)
     if count>=4:return
