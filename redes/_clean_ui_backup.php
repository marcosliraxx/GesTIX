<?php /* Caminho: redes/_clean_ui_backup.php | Backup em Nuvem (v2): navegador de arquivos estilo Google Drive, escolha do destino
   (conta própria ou Drive da plataforma), árvore de discos/pastas do computador e painel de atividade.
   Incluído dentro do escopo de _clean_ui_js.php (usa h, ico, fmt, size, confirmBox, status, wait, api, csrf, network, agent, section, render, root, $).
   Contrato: api/cloud.php (state, save, run, browse, download-link, folders-start/status, connect, refresh, disconnect). v3.10: disco virtual, download/visualização e árvore de pastas sem modo sem monitor. */ ?>
/* ---------- Backup em Nuvem v2 ---------- */
const BK={data:null,loading:false,error:'',tab:null,draft:null,dirty:false,saving:false,sig:'',
 view:'list',sort:'name',q:'',trail:[],entries:[],next:'',bstate:'idle',berror:'',bconn:0,sel:null,
 fs:new Map(),fsOpen:new Set(),chain:Promise.resolve(),manual:''};
const BK_STATES={idle:['Em dia','ok'],waiting:['Aguardando','mut'],scanning:['Analisando','blue'],uploading:['Enviando','blue'],paused:['Pausado','mut'],disabled:['Desativado','mut'],error:['Requer atenção','err']};
const BK_ICONS={folder:['folder','#f2b84b'],network:['sitemap','#6aa0ff'],computer:['desktop','#6aa0ff'],image:['file-image-o','#e0709b'],video:['file-video-o','#c07be0'],audio:['file-audio-o','#e0a050'],pdf:['file-pdf-o','#ef5b5b'],
 doc:['file-word-o','#5b8def'],sheet:['file-excel-o','#37c871'],slide:['file-powerpoint-o','#ff8a4c'],archive:['file-archive-o','#c9b458'],data:['database','#9aa6bd'],file:['file-o','#a7b0c2']};
const BK_SKIP_ROOT=new Set(['windows','program files','program files (x86)','programdata','$recycle.bin','system volume information','recovery','config.msi','$windows.~bt','perflogs']);
const bkIcon=(type,big)=>{const [i,c]=BK_ICONS[type]||BK_ICONS.file;const e=ico(i);e.style.color=c;if(big)e.classList.add('big');return e};
const bkWhen=v=>{if(!v)return '—';const d=new Date(v);return isNaN(d)?'—':d.toLocaleString('pt-BR',{dateStyle:'short',timeStyle:'short'})};
const bkNorm=p=>String(p||'').replace(/\//g,'\\').replace(/\\+$/,'').toLowerCase();
const bkIsRoot=p=>/^[A-Za-z]:\\?$/.test(p);

async function backupApi(action,data={}){
 const u=new URL(api.replace(/api\/api\.php(?:\?.*)?$/,'api/cloud.php'),location.href);u.searchParams.set('action',action);
 const response=await fetch(u,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent,...data})});
 let result;try{result=await response.json()}catch{throw Error('Não foi possível consultar o backup.')}
 if(!response.ok||!result.ok)throw Error(result.error||'Backup indisponível.');return result;
}
const draftFrom=d=>{const s=d.job.settings;return{connection_id:d.job.connection_id,enabled:!!d.job.enabled,folders:(s.folders||[]).map(f=>f.path),schedule:s.schedule,interval_minutes:s.interval_minutes,daily_time:s.daily_time,exclusions:(s.exclusions||[]).join('\n'),max_file_mb:s.max_file_mb,bandwidth_kbps:s.bandwidth_kbps,remote_root:s.remote_root,vdrive:!!s.vdrive,vdrive_letter:s.vdrive_letter||'AUTO'}};
async function backupLoad(force=false){
 if(BK.loading||(!force&&BK.data))return;BK.loading=true;
 try{
  const d=await backupApi('state'),sig=JSON.stringify([d.job,d.connections,d.providers,d.policy,d.agent,d.status&&[d.status.state,d.status.message,d.status.files_done,d.status.bytes_done,d.status.reported_at]]);
  const first=!BK.data,changed=sig!==BK.sig;BK.data=d;BK.sig=sig;BK.error='';
  if(!BK.dirty)BK.draft=draftFrom(d);
  if(BK.tab===null)BK.tab=d.job.connection_id&&d.job.settings.folders.length?'files':'setup';
  if(first||changed){if(BK.tab==='files'&&(first||BK.bconn!==d.job.connection_id))bkBrowse([],false);if(section==='backup')render()}
 }catch(e){BK.error=e.message;if(section==='backup')render()}finally{BK.loading=false}
}
async function backupAction(action,data={},ok='Backup atualizado.'){try{await backupApi(action,data);BK.dirty=false;await backupLoad(true);status(ok)}catch(e){status(e.message,true)}}
async function bkSave(){
 const dr=BK.draft;if(BK.saving)return;BK.saving=true;render();
 try{
  await backupApi('save',{connection_id:dr.connection_id||null,enabled:dr.enabled,settings:{folders:dr.folders.map(path=>({path})),schedule:dr.schedule,interval_minutes:Number(dr.interval_minutes),daily_time:dr.daily_time,
   exclusions:String(dr.exclusions).split(/\r?\n/).map(x=>x.trim()).filter(Boolean),max_file_mb:Number(dr.max_file_mb),bandwidth_kbps:Number(dr.bandwidth_kbps),remote_root:dr.remote_root,vdrive:!!dr.vdrive,vdrive_letter:dr.vdrive_letter||'AUTO',mirror_deletes:false}});
  BK.dirty=false;BK.bconn=-1;BK.saving=false;await backupLoad(true);status('Configuração salva. O agente aplica as mudanças em até 30 segundos.');
 }catch(e){BK.saving=false;status(e.message,true);render()}
}
const bkTouch=(rerender)=>{BK.dirty=true;if(rerender)render();else bkSavebar()};

/* ----- navegador dos arquivos na nuvem (somente leitura) ----- */
async function bkBrowse(trail,more){
 const conn=BK.data?.job.connection_id;BK.bconn=conn||0;
 if(!conn){BK.bstate='none';BK.entries=[];if(section==='backup')render();return}
 if(!more){BK.trail=trail;BK.entries=[];BK.next='';BK.sel=null}
 BK.bstate='loading';BK.berror='';if(section==='backup')render();
 try{
  const r=await backupApi('browse',{connection_id:conn,path:BK.trail.map(t=>t.name).join('/'),page:more?BK.next:undefined});
  const b=r.browser;BK.entries=more?BK.entries.concat(b.entries||[]):(b.entries||[]);BK.next=b.next||'';BK.bstate=b.missing?'missing':'ready';BK.limited=!!b.limited;
  if(!more&&!BK.trail.length&&BK.autoFor!==conn){BK.autoFor=conn;const c=(BK.data.connections||[]).find(x=>x.id===conn),me=(b.entries||[]).find(x=>x.name==='Computador-'+agent);if(c&&!c.managed&&me)return bkBrowse([{name:me.name,display:me.display}],false)}
 }catch(e){BK.bstate='error';BK.berror=e.message}
 if(section==='backup')render();
}
function bkFiles(body,d){
 const job=d.job,conn=(d.connections||[]).find(c=>c.id===job.connection_id);
 if(!conn)return body.append(h('div',{class:'cb-empty'},ico('cloud-upload'),h('h3',{},'Nenhum destino escolhido'),h('p',{class:'mut'},'Escolha onde o backup será guardado para visualizar os arquivos aqui, como no Google Drive.'),h('button',{class:'cc-pill blue',onclick:()=>{BK.tab='setup';render()}},'Configurar backup')));
 const crumbs=h('nav',{class:'cb-crumbs','aria-label':'Caminho'},h('button',{type:'button',onclick:()=>bkBrowse([],false)},ico(conn.managed?'building-o':conn.icon==='google'?'google':'cloud'),' ',conn.managed?'Drive da plataforma':'Meu backup'));
 BK.trail.forEach((t,i)=>crumbs.append(ico('chevron-right'),h('button',{type:'button',class:i===BK.trail.length-1?'on':'',onclick:()=>bkBrowse(BK.trail.slice(0,i+1),false)},t.display||t.name)));
 const search=h('input',{class:'cc-input',type:'search',placeholder:'Pesquisar nesta pasta',value:BK.q,'aria-label':'Pesquisar nesta pasta',oninput:e=>{BK.q=e.target.value;bkList()}});
 const sort=h('select',{class:'cc-sel','aria-label':'Ordenar',onchange:e=>{BK.sort=e.target.value;bkList()}},[['name','Nome'],['modified','Modificado'],['size','Tamanho']].map(([v,l])=>h('option',{value:v,selected:BK.sort===v},l)));
 const toggle=h('div',{class:'cb-seg',role:'group','aria-label':'Modo de exibição'},[['list','list','Lista'],['grid','th-large','Grade']].map(([v,i,l])=>h('button',{type:'button',class:BK.view===v?'on':'','aria-pressed':String(BK.view===v),title:l,onclick:()=>{BK.view=v;bkList();render()}},ico(i))));
 body.append(h('div',{class:'cb-toolbar'},crumbs,h('div',{class:'cb-tools'},search,sort,toggle,h('button',{class:'cc-pill sm',onclick:()=>bkBrowse(BK.trail,false)},ico('refresh'),' Atualizar'))));
 if(conn.quota_total!=null){const pct=Math.min(100,100*conn.quota_used/Math.max(1,conn.quota_total));body.append(h('div',{class:'cb-quota'},h('span',{},ico('hdd-o'),' '+conn.identity+' · '+size(conn.quota_used)+' de '+size(conn.quota_total)+' usados'),h('div',{class:'cc-bar'},h('i',{style:'width:'+pct+'%'}))))}
 else if(conn.managed)body.append(h('div',{class:'cb-quota'},h('span',{},ico('lock'),' Você vê somente os arquivos desta rede e deste computador.')));
 const wrap=h('div',{class:'cb-browser'},h('div',{id:'cb-list',class:'cb-listwrap'}),h('aside',{id:'cb-detail',class:'cb-detail',hidden:true}));body.append(wrap);bkList();
}
function bkSorted(){
 const q=BK.q.trim().toLowerCase(),dir=a=>a.directory?0:1,cmp={name:(a,b)=>(a.display||a.name).localeCompare(b.display||b.name,'pt-BR',{numeric:true}),modified:(a,b)=>String(b.modified).localeCompare(String(a.modified)),size:(a,b)=>b.size-a.size}[BK.sort];
 return BK.entries.filter(e=>!q||(e.display||e.name).toLowerCase().includes(q)).sort((a,b)=>dir(a)-dir(b)||cmp(a,b));
}
function bkList(){
 const box=$('cb-list');if(!box)return;box.replaceChildren();
 if(BK.bstate==='loading'&&!BK.entries.length)return box.append(h('div',{class:'cb-empty small',role:'status'},ico('spinner fa-spin'),h('p',{class:'mut'},'Carregando arquivos...')));
 if(BK.bstate==='error')return box.append(h('div',{class:'cb-empty small',role:'alert'},ico('exclamation-triangle'),h('p',{},BK.berror),h('button',{class:'cc-pill',onclick:()=>bkBrowse(BK.trail,false)},'Tentar novamente')));
 const rows=bkSorted();
 if(!rows.length)return box.append(h('div',{class:'cb-empty small'},ico('folder-open-o'),h('p',{class:'mut'},BK.q?'Nada encontrado para “'+BK.q+'”.':BK.bstate==='missing'?'Ainda não há arquivos neste destino. Ative o backup e clique em Sincronizar agora.':'Esta pasta está vazia.')));
 const open=e=>{if(e.directory)bkBrowse(BK.trail.concat([{name:e.name,display:e.display}]),false);else{if(BK.sel===e&&(BK_VIEW[e.type]||bkTextFile(e.name)))return bkPreview(e);BK.sel=e;bkList();bkDetail()}};
 if(BK.view==='grid')box.append(h('div',{class:'cb-grid'},rows.map(e=>h('button',{type:'button',class:'cb-card'+(BK.sel===e?' on':''),onclick:()=>open(e),title:e.display||e.name},bkIcon(e.type,true),h('b',{},e.display||e.name),h('small',{},e.directory?'Pasta':size(e.size))))));
 else box.append(h('table',{class:'cc-tbl cb-table'},h('thead',{},h('tr',{},h('th',{},'Nome'),h('th',{},'Modificado'),h('th',{style:'text-align:right'},'Tamanho'))),h('tbody',{},rows.map(e=>h('tr',{class:'cl'+(BK.sel===e?' sel':''),tabindex:0,onclick:()=>open(e),onkeydown:ev=>{if(ev.key==='Enter')open(e)}},h('td',{},bkIcon(e.type),' ',e.display||e.name,e.display&&e.display!==e.name&&e.type==='folder'?h('small',{class:'mut'},' · '+e.name):null),h('td',{class:'mut'},bkWhen(e.modified)),h('td',{class:'mut',style:'text-align:right'},e.directory?'—':size(e.size)))))));
 if(BK.next)box.append(h('div',{class:'cb-more'},h('button',{class:'cc-pill',disabled:BK.bstate==='loading',onclick:()=>bkBrowse(BK.trail,true)},'Carregar mais')));
 else if(BK.limited)box.append(h('p',{class:'mut cb-more'},'Mostrando os primeiros '+BK.entries.length+' itens desta pasta. Use a pesquisa para filtrar.'));
 bkDetail();
}
const bkRel=e=>BK.trail.map(t=>t.name).concat([e.name]).join('/');
const bkTextFile=n=>/\.(txt|csv|log)$/i.test(n);
async function bkLink(e,inline){const r=await backupApi('download-link',{connection_id:BK.data.job.connection_id,path:bkRel(e),inline:!!inline});return r.url}
async function bkDownload(e){try{const url=await bkLink(e,false);const a=h('a',{href:url,download:e.name,rel:'noopener'});document.body.append(a);a.click();a.remove();status('Download iniciado: '+e.name)}catch(x){status(x.message,true)}}
const BK_VIEW={image:'img',video:'video',audio:'audio',pdf:'pdf'};
async function bkPreview(e){
 const kind=BK_VIEW[e.type]||(bkTextFile(e.name)?'text':'');if(!kind)return bkDownload(e);
 try{
  const url=await bkLink(e,true),close=()=>ov.remove();let media;
  if(kind==='img')media=h('img',{src:url,alt:e.name});else if(kind==='video')media=h('video',{src:url,controls:true,autoplay:true});else if(kind==='audio')media=h('audio',{src:url,controls:true,autoplay:true});else media=h('iframe',{src:url,title:e.name});
  const ov=h('div',{class:'cb-prev',role:'dialog','aria-label':'Visualização de '+e.name,onclick:ev=>{if(ev.target===ov)close()}},h('div',{class:'cb-prev-bar'},h('b',{},e.name),h('span',{class:'mut'},size(e.size)),h('button',{class:'cc-pill sm',type:'button',onclick:()=>bkDownload(e)},ico('download'),' Baixar'),h('button',{class:'cc-pill sm',type:'button','aria-label':'Fechar',onclick:close},ico('times'))),h('div',{class:'cb-prev-body'},media));
  ov.addEventListener('keydown',ev=>{if(ev.key==='Escape')close()});root.append(ov);ov.tabIndex=-1;ov.focus();
 }catch(x){status(x.message,true)}
}
function bkDetail(){
 const p=$('cb-detail');if(!p)return;const e=BK.sel;p.hidden=!e;if(!e)return;
 const conn=(BK.data.connections||[]).find(c=>c.id===BK.data.job.connection_id),viewable=!!BK_VIEW[e.type]||bkTextFile(e.name);
 p.replaceChildren(h('button',{class:'cb-x',type:'button','aria-label':'Fechar detalhes',onclick:()=>{BK.sel=null;bkList()}},ico('times')),bkIcon(e.type,true),h('h4',{},e.display||e.name),
  h('dl',{},h('dt',{},'Tamanho'),h('dd',{},size(e.size)),h('dt',{},'Modificado'),h('dd',{},bkWhen(e.modified)),h('dt',{},'Local'),h('dd',{},['Backup'].concat(BK.trail.map(t=>t.display||t.name)).join(' / '))),
  h('div',{class:'cb-detail-a'},viewable?h('button',{class:'cc-pill blue',type:'button',onclick:()=>bkPreview(e)},ico('eye'),' Visualizar'):null,h('button',{class:'cc-pill'+(viewable?'':' blue'),type:'button',onclick:()=>bkDownload(e)},ico('download'),' Baixar'),
   e.link&&conn&&!conn.managed?h('a',{class:'cc-pill',href:e.link,target:'_blank',rel:'noopener noreferrer'},ico('external-link'),' Abrir no Google Drive'):null));
}

/* ----- árvore de discos e pastas do computador ----- */
function fsFetch(path){
 return (async()=>{
  try{
   const start=await backupApi('folders-start',{path});
   let res=null;for(let n=0;n<40;n++){await wait(n<4?700:1500);res=await backupApi('folders-status',{request_id:start.request_id});if(['completed','failed','expired'].includes(res.status))break}
   if(res?.status!=='completed'||!res.data||res.data.error)throw Error(res?.data?.error||res?.message||'O agente não respondeu a tempo. Verifique se ele está conectado.');
   let list=(res.data.entries||[]).filter(e=>e.directory);if(bkIsRoot(path))list=list.filter(e=>!BK_SKIP_ROOT.has(e.name.toLowerCase()));
   BK.fs.set(path,{entries:list.sort((a,b)=>(a.drive&&b.drive)?a.path.localeCompare(b.path):a.name.localeCompare(b.name,'pt-BR',{numeric:true})),truncated:!!res.data.truncated});
  }catch(e){BK.fs.set(path,{error:e.message})}
  renderTree();
 })();
}
function fsLoad(path,force){const cur=BK.fs.get(path);if(cur&&!force&&(cur.loading||cur.entries))return;BK.fs.set(path,{loading:true});renderTree();BK.chain=BK.chain.then(()=>fsFetch(path))}
const bkCovered=p=>{const n=bkNorm(p);return BK.draft.folders.some(f=>{const x=bkNorm(f);return n!==x&&n.startsWith(x+'\\')})};
function bkToggleFolder(path,on){
 const n=bkNorm(path),dr=BK.draft;
 if(on){if(dr.folders.length>=20)return status('Escolha até 20 pastas.',true);dr.folders=dr.folders.filter(f=>!bkNorm(f).startsWith(n+'\\')&&bkNorm(f)!==n).concat([path])}
 else dr.folders=dr.folders.filter(f=>bkNorm(f)!==n);
 bkTouch(false);renderTree();renderChosen();
}
function treeRows(parent,depth,out){
 const node=BK.fs.get(parent);if(!node)return;
 if(node.loading)return out.push(h('div',{class:'cb-tr mut',style:'padding-left:'+(12+depth*20)+'px'},ico('spinner fa-spin'),' Lendo...'));
 if(node.error)return out.push(h('div',{class:'cb-tr err',style:'padding-left:'+(12+depth*20)+'px'},ico('exclamation-triangle'),' '+node.error+' ',h('button',{type:'button',class:'cc-pill sm',onclick:()=>fsLoad(parent,true)},'Tentar de novo')));
 if(!node.entries.length)out.push(h('div',{class:'cb-tr mut',style:'padding-left:'+(12+depth*20)+'px'},'Sem subpastas'));
 for(const e of node.entries){
  const open=BK.fsOpen.has(e.path),inList=BK.draft.folders.some(f=>bkNorm(f)===bkNorm(e.path)),covered=bkCovered(e.path);
  const chk=h('input',{type:'checkbox',checked:inList||covered,disabled:!!e.drive||covered,'aria-label':'Sincronizar '+e.name,title:covered?'Já incluída pela pasta superior':'',onchange:ev=>bkToggleFolder(e.path,ev.target.checked)});
  const row=h('div',{class:'cb-tr'+(inList?' on':''),style:'padding-left:'+(8+depth*20)+'px'},
   h('button',{type:'button',class:'cb-chev','aria-expanded':String(open),'aria-label':(open?'Recolher ':'Expandir ')+e.name,onclick:()=>{open?BK.fsOpen.delete(e.path):(BK.fsOpen.add(e.path),fsLoad(e.path));renderTree()}},ico(open?'chevron-down':'chevron-right')),
   e.drive?h('span',{class:'cb-nocheck'}):chk,ico(e.drive?'hdd-o':open?'folder-open':'folder'),
   h('button',{type:'button',class:'cb-name',onclick:()=>{open?BK.fsOpen.delete(e.path):(BK.fsOpen.add(e.path),fsLoad(e.path));renderTree()}},e.name+(e.drive&&e.total?'  ·  '+size(e.free)+' livres de '+size(e.total):'')),e.drive&&e.total?h('span',{class:'cb-drivebar',title:size(e.total-e.free)+' usados'},h('i',{style:'width:'+Math.min(100,100*(e.total-e.free)/e.total)+'%'})):null);
  out.push(row);if(open)treeRows(e.path,depth+1,out);
 }
 if(node.truncated)out.push(h('div',{class:'cb-tr mut',style:'padding-left:'+(12+depth*20)+'px'},'Mostrando as primeiras 150 pastas.'));
}
function renderTree(){const box=$('cb-tree');if(!box)return;const out=[];treeRows('',0,out);box.replaceChildren(...(out.length?out:[h('div',{class:'cb-tr mut'},'Consultando os discos do computador...')]))}
function renderChosen(){
 const box=$('cb-chosen');if(!box)return;const f=BK.draft.folders;
 box.replaceChildren(h('h4',{},ico('check-square-o'),' Pastas que serão copiadas ('+f.length+'/20)'),
  f.length?h('ul',{},f.map(p=>h('li',{},ico('folder'),h('span',{title:p},p),h('button',{type:'button','aria-label':'Remover '+p,onclick:()=>bkToggleFolder(p,false)},ico('times'))))):h('p',{class:'mut'},'Nenhuma pasta escolhida. Marque as pastas na lista ao lado.'));
}
function bkSavebar(){
 const b=$('cb-savebar');if(!b)return;const d=BK.data,dr=BK.draft;
 const issues=[];if(!dr.connection_id)issues.push('escolha o destino');if(!dr.folders.length)issues.push('escolha ao menos uma pasta');
 b.replaceChildren(h('span',{class:'cb-dirty'+(BK.dirty?' on':'')},BK.dirty?'● Alterações não salvas':'✓ Tudo salvo'),
  h('span',{class:'mut'},dr.enabled&&issues.length?'Para ativar: '+issues.join(' e ')+'.':''),
  h('button',{type:'button',class:'cc-pill blue',disabled:BK.saving||!BK.dirty||(dr.enabled&&issues.length>0),onclick:bkSave},BK.saving?'Salvando...':'Salvar configuração'),
  h('button',{type:'button',class:'cc-pill',disabled:!d.job.enabled||BK.dirty||BK.saving,title:BK.dirty?'Salve antes de sincronizar':'',onclick:()=>backupAction('run',{},'Sincronização solicitada. O agente inicia em até 30 segundos.')},ico('refresh'),' Sincronizar agora'));
}

/* ----- configuração ----- */
function bkSetup(body,d){
 const dr=BK.draft,pol=d.policy||{own:true,platform:true},conns=d.connections||[],managed=conns.filter(c=>c.managed),own=conns.filter(c=>!c.managed),providers=d.providers||[];
 const selManaged=managed.find(c=>c.id===dr.connection_id),selOwn=own.find(c=>c.id===dr.connection_id);
 const pick=id=>{dr.connection_id=id;bkTouch(true)};
 /* 1. destino */
 const platform=h('div',{class:'cb-opt'+(selManaged?' on':'')+(!pol.platform||!managed.length?' off':'')},
  h('div',{class:'cb-opt-h'},ico('building-o'),h('div',{},h('b',{},'Drive da plataforma'),h('small',{},'Sem configurar nada: o armazenamento é fornecido e administrado pela plataforma.'))),
  !pol.platform?h('p',{class:'mut'},'O administrador não liberou esta opção.'):!managed.length?h('p',{class:'mut'},'Nenhum Drive da plataforma foi conectado ainda. Peça ao administrador para conectar em Configurações → Drive.'):
  h('div',{class:'cb-opt-b'},managed.length>1?h('select',{class:'cc-sel','aria-label':'Drive da plataforma',onchange:e=>pick(Number(e.target.value))},managed.map(c=>h('option',{value:c.id,selected:c.id===dr.connection_id},c.label))):h('span',{class:'mut'},managed[0].label+(managed[0].error?' · '+managed[0].error:'')),
   h('button',{type:'button',class:'cc-pill '+(selManaged?'':'blue'),onclick:()=>pick(selManaged?dr.connection_id:managed[0].id)},selManaged?[ico('check'),' Selecionado']:'Usar o Drive da plataforma')));
 const ownBox=h('div',{class:'cb-opt'+(selOwn?' on':'')+(!pol.own?' off':'')},
  h('div',{class:'cb-opt-h'},ico('google'),h('div',{},h('b',{},'Minha conta de nuvem'),h('small',{},'Entre com a sua conta Google (ou outro provedor) e o backup vai para o seu próprio Drive.'))),
  !pol.own?h('p',{class:'mut'},'O administrador não permite contas próprias.'):h('div',{class:'cb-opt-b'},
   own.map(c=>h('div',{class:'cb-conn'+(c.id===dr.connection_id?' on':'')},h('label',{},h('input',{type:'radio',name:'cb-conn',checked:c.id===dr.connection_id,onchange:()=>pick(c.id)}),' ',ico(c.icon==='google'?'google':c.icon),' ',h('b',{},c.label)),
    h('small',{class:'mut'},c.identity+(c.error?' · '+c.error:'')+(c.quota_total!=null?' · '+size(c.quota_used)+' de '+size(c.quota_total):'')),
    h('span',{class:'cb-conn-a'},h('button',{type:'button',class:'cc-pill sm',onclick:()=>backupAction('refresh',{connection_id:c.id},'Conta verificada.')},'Verificar'),
     h('button',{type:'button',class:'cc-pill sm',onclick:async()=>{if(await confirmBox('Desconectar conta',[h('p',{},'Os backups que usam esta conta serão pausados. Os arquivos já enviados continuam no provedor.')],'Desconectar',true)){BK.bconn=-1;backupAction('disconnect',{connection_id:c.id,confirmed:true},'Conta desconectada.')}}},'Desconectar')))),
   h('div',{class:'cb-prov'},providers.map(p=>h('button',{type:'button',class:'cc-pill'+(p.key==='gdrive'?' blue':''),onclick:()=>bkConnect(p)},ico(p.icon==='google'?'google':p.icon),' Conectar '+p.name))),
   !providers.length?h('p',{class:'mut'},'Nenhum provedor liberado. Solicite ao administrador em Configurações → Drive.'):null,
   providers.some(p=>p.key==='gdrive')?h('small',{class:'mut'},'Você será levado ao Google para autorizar e voltará automaticamente. O Arcoll só acessa a pasta de backup que ele mesmo cria.'):null));
 body.append(h('section',{class:'cb-step'},h('h3',{},h('span',{},'1'),' Onde guardar o backup'),h('div',{class:'cb-opts'},platform,ownBox)));
 /* 2. pastas */
 const tree=h('div',{id:'cb-tree',class:'cb-tree',role:'tree','aria-label':'Discos e pastas do computador'}),chosen=h('div',{id:'cb-chosen',class:'cb-chosen'});
 const manual=h('input',{class:'cc-input',placeholder:'Ou digite um caminho: D:\\Projetos',value:BK.manual,'aria-label':'Caminho da pasta',oninput:e=>{BK.manual=e.target.value},onkeydown:e=>{if(e.key==='Enter'){e.preventDefault();addManual()}}});
 const addManual=()=>{const p=BK.manual.trim();if(!/^(?:[A-Za-z]:[\\/]|%[A-Za-z_][A-Za-z0-9_]*%[\\/])/.test(p)||bkIsRoot(p))return status('Informe um caminho completo de pasta (ex.: D:\\Projetos). Raízes de disco não são permitidas.',true);BK.manual='';manual.value='';bkToggleFolder(p.replace(/[\\/]+$/,''),true)};
 const quick=[['Documentos','%USERPROFILE%\\Documents','file-text-o'],['Área de Trabalho','%USERPROFILE%\\Desktop','desktop'],['Imagens','%USERPROFILE%\\Pictures','picture-o']];
 body.append(h('section',{class:'cb-step'},h('h3',{},h('span',{},'2'),' Quais pastas sincronizar',h('button',{type:'button',class:'cc-pill sm',style:'margin-left:auto',onclick:()=>{BK.fs.clear();BK.fsOpen.clear();fsLoad('',true)}},ico('refresh'),' Atualizar discos')),
  !d.agent.online?h('p',{class:'cb-warn'},ico('plug'),' Agente desconectado: não é possível listar os discos agora. Você ainda pode digitar os caminhos.'):pol.folder_picker===false?h('p',{class:'cb-warn'},ico('lock'),' O administrador desativou a lista de discos pela plataforma. Digite os caminhos das pastas.'):h('p',{class:'mut'},ico('info-circle'),' Mostramos só os nomes das pastas do computador; o conteúdo dos arquivos nunca é lido aqui. Abra um disco e marque as pastas que deseja sincronizar.'),
  h('div',{class:'cb-pick'},h('div',{class:'cb-pick-l'},h('div',{class:'cb-quick'},quick.map(([l,p,i])=>h('button',{type:'button',class:'cc-pill sm',onclick:()=>bkToggleFolder(p,true)},ico(i),' '+l))),tree,h('div',{class:'cb-manual'},manual,h('button',{type:'button',class:'cc-pill sm',onclick:addManual},'Adicionar'))),chosen)));
 queueMicrotask(()=>{renderTree();renderChosen();if(!BK.fs.has('')&&d.agent.online&&pol.folder_picker!==false)fsLoad('')});
 /* 3. quando */
 const sch=[['continuous','Contínua','Verifica mudanças a cada 30 segundos'],['interval','A cada intervalo','Escolha de 15 minutos a 12 horas'],['daily','Diária','Em um horário fixo'],['manual','Somente manual','Só quando você clicar em Sincronizar agora']];
 const adv=h('details',{class:'cb-adv'},h('summary',{},'Opções avançadas'),h('div',{class:'cb-form'},
  h('label',{},'Nome da pasta no destino',h('input',{class:'cc-input',maxlength:80,value:dr.remote_root,oninput:e=>{dr.remote_root=e.target.value;bkTouch(false)}})),
  h('label',{},'Tamanho máximo por arquivo (MB)',h('input',{class:'cc-input',type:'number',min:1,max:d.policy?.max_file_mb||51200,value:dr.max_file_mb,oninput:e=>{dr.max_file_mb=e.target.value;bkTouch(false)}}),h('small',{class:'mut'},'Limite da plataforma: '+fmt(d.policy?.max_file_mb||0)+' MB')),
  h('label',{},'Limite de velocidade (KB/s, 0 = sem limite)',h('input',{class:'cc-input',type:'number',min:0,max:1048576,value:dr.bandwidth_kbps,oninput:e=>{dr.bandwidth_kbps=e.target.value;bkTouch(false)}})),
  h('label',{class:'wide'},'Arquivos ignorados (um padrão por linha, ex.: *.tmp)',h('textarea',{class:'cc-area',rows:3,oninput:e=>{dr.exclusions=e.target.value;bkTouch(false)}},dr.exclusions))));
 body.append(h('section',{class:'cb-step'},h('h3',{},h('span',{},'3'),' Quando enviar'),
  h('div',{class:'cb-sched'},sch.map(([v,t,s])=>h('label',{class:'cb-sch'+(dr.schedule===v?' on':'')},h('input',{type:'radio',name:'cb-sch',checked:dr.schedule===v,onchange:()=>{dr.schedule=v;bkTouch(true)}}),h('b',{},t),h('small',{},s)))),
  dr.schedule==='interval'?h('label',{class:'cb-inline'},'Repetir a cada ',h('select',{class:'cc-sel',onchange:e=>{dr.interval_minutes=Number(e.target.value);bkTouch(false)}},[15,30,60,180,360,720].map(n=>h('option',{value:n,selected:n===Number(dr.interval_minutes)},n<60?n+' minutos':(n/60)+' hora(s)')))):null,
  dr.schedule==='daily'?h('label',{class:'cb-inline'},'Horário (do computador) ',h('input',{class:'cc-input',type:'time',value:dr.daily_time,onchange:e=>{dr.daily_time=e.target.value;bkTouch(false)}})):null,adv));
 /* 4. disco virtual */
 if(pol.vdrive!==false){const letters=[['AUTO','Automática (primeira letra livre)']].concat('ZYXWVUTSRQPONMLKJIHGFED'.split('').map(c=>[c,c+':']));
  body.append(h('section',{class:'cb-step'},h('h3',{},h('span',{},'4'),' Disco virtual no Windows'),
   h('label',{class:'cb-enable'},h('input',{type:'checkbox',checked:!!dr.vdrive,onchange:e=>{dr.vdrive=e.target.checked;bkTouch(true)}}),h('div',{},h('b',{},'Abrir os arquivos da nuvem pelo Explorador de Arquivos'),h('small',{class:'mut'},'O agente cria uma unidade (como o Google Drive para computador). Os arquivos são lidos sob demanda, sem ocupar espaço no disco. Somente leitura.'))),
   dr.vdrive?h('label',{class:'cb-inline'},'Letra da unidade ',h('select',{class:'cc-sel',onchange:e=>{dr.vdrive_letter=e.target.value;bkTouch(false)}},letters.map(([c,t])=>h('option',{value:c,selected:c===(dr.vdrive_letter||'AUTO')},t)))):null,
   d.status&&d.status.vdrive?h('p',{class:'mut'},ico('hdd-o'),' '+d.status.vdrive):(dr.vdrive?h('p',{class:'mut'},'Salve e aguarde o agente montar a unidade (até 20 segundos). Requer o serviço Cliente Web do Windows (WebClient).'):null)))}
 /* 5. ativar */
 body.append(h('section',{class:'cb-step'},h('h3',{},h('span',{},pol.vdrive!==false?'5':'4'),' Ativar'),
  h('label',{class:'cb-enable'},h('input',{type:'checkbox',checked:dr.enabled,onchange:e=>{dr.enabled=e.target.checked;bkTouch(true)}}),h('div',{},h('b',{},'Backup ativo neste computador'),h('small',{class:'mut'},'Cópia em uma direção: computador → nuvem. Apagar um arquivo no computador não o remove do backup.')))));
}
async function bkConnect(p){
 if(p.key==='s3'){
  const fields={},labels={bucket:'Nome do bucket',access_key:'Access Key ID (IAM)',secret_key:'Secret Access Key (IAM)',region:'Região do bucket (ex.: us-east-1)',prefix:'Subpasta opcional no bucket'};
  const content=Object.entries(labels).map(([key,label])=>{const input=h('input',{class:'cc-input',type:key==='secret_key'?'password':'text',autocomplete:'off',value:key==='region'?(p.region||'us-east-1'):''});fields[key]=input;return h('label',{},label,input)});
  if(await confirmBox('Conectar Amazon S3 / compatível',[h('div',{class:'cb-form'},content),h('p',{class:'mut'},'Crie um bucket e credenciais IAM restritas a ele. Endpoint definido pela administração: '+(p.endpoint||'Amazon S3'))],'Conectar')){
   const credentials=Object.fromEntries(Object.entries(fields).map(([k,i])=>[k,i.value]));credentials.endpoint=p.endpoint||'';BK.bconn=-1;await backupAction('connect',{provider:'s3',credentials},'Conta conectada.');Object.values(fields).forEach(i=>i.value='');
  }return;
 }
 try{const r=await backupApi('connect',{provider:p.key});location.assign(r.url)}catch(e){status(e.message,true)}
}

/* ----- atividade ----- */
function bkActivity(body,d){
 const st=d.status||{},job=d.job,pct=st.bytes_total?Math.min(100,100*st.bytes_done/st.bytes_total):(st.state==='idle'&&st.last_run_at?100:0);
 body.append(h('div',{class:'cb-kpis'},
  [['Arquivos protegidos',fmt(st.synced_files||0),'files-o'],['Enviados na última execução',fmt(st.files_done||0)+' · '+size(st.bytes_done||0),'cloud-upload'],['Último sucesso',st.last_success_at?bkWhen(st.last_success_at):'ainda não concluído','check-circle-o'],...(st.skipped?[['Somente online (ignorados)',fmt(st.skipped),'cloud-download']]:[]),['Próxima execução',job.enabled?(job.settings.schedule==='manual'?'somente manual':job.settings.schedule==='continuous'?'em até 30 s':job.settings.schedule==='daily'?'hoje/amanhã às '+job.settings.daily_time:'a cada '+job.settings.interval_minutes+' min'):'backup desativado','clock-o']]
  .map(([t,v,i])=>h('div',{class:'cc-card cb-kpi'},ico(i),h('small',{},t),h('b',{},v)))));
 if(st.state==='uploading'||st.state==='scanning')body.append(h('div',{class:'cb-prog'},h('div',{class:'cc-bar'},h('i',{style:'width:'+pct+'%'})),h('span',{class:'mut'},fmt(st.files_done)+' de '+fmt(st.files_total)+' arquivos · '+size(st.bytes_done)+' de '+size(st.bytes_total)+(st.speed_bps?' · '+size(st.speed_bps)+'/s':'')+(st.current?' · '+st.current:''))));
 if(st.folders?.length)body.append(h('h3',{},'Pastas'),h('table',{class:'cc-tbl'},h('thead',{},h('tr',{},h('th',{},'Pasta'),h('th',{},'Arquivos'),h('th',{},'Tamanho'),h('th',{},'Situação'))),h('tbody',{},st.folders.map(f=>h('tr',{},h('td',{},ico('folder'),' '+f.label),h('td',{},fmt(f.files)),h('td',{},size(f.bytes)),h('td',{class:f.missing?'err':'ok'},f.missing?'Indisponível no computador':'Lida'))))));
 if(st.failures?.length)body.append(h('h3',{},'Itens que precisam de atenção'),h('ul',{class:'cb-fail'},st.failures.map(f=>h('li',{},ico('exclamation-triangle'),h('b',{},f.path),h('span',{class:'mut'},' — '+f.message)))));
 if(st.runs?.length)body.append(h('h3',{},'Últimas execuções'),h('table',{class:'cc-tbl'},h('thead',{},h('tr',{},h('th',{},'Quando'),h('th',{},'Arquivos'),h('th',{},'Tamanho'),h('th',{},'Erros'),h('th',{},'Duração'))),h('tbody',{},st.runs.map(r=>h('tr',{},h('td',{},bkWhen(r.at)),h('td',{},fmt(r.files)),h('td',{},size(r.bytes)),h('td',{class:r.errors?'err':''},fmt(r.errors)),h('td',{},r.seconds+' s'))))));
 if(!st.runs?.length&&!st.failures?.length)body.append(h('div',{class:'cb-empty small'},ico('history'),h('p',{class:'mut'},'Ainda não há execuções. Ative o backup e clique em Sincronizar agora.')));
}

VIEWS.backup=b=>{
 const body=h('div',{class:'cc-scroll cb-workspace','data-keep':'backup'});b.append(body);
 if(!BK.data){body.append(h('h2',{},'Backup em Nuvem'),h('p',{role:'status'},BK.error||'Consultando configurações...'),BK.error?h('button',{class:'cc-pill',onclick:()=>backupLoad(true)},'Tentar novamente'):null);return}
 const d=BK.data,st=d.status||{},ag=d.agent||{},[stateLabel,stateTone]=BK_STATES[st.state]||['Aguardando agente','mut'];
 body.append(h('header',{class:'cb-head'},h('div',{},h('h2',{},ico('cloud-upload'),' Backup em Nuvem'),h('p',{class:'mut'},(ag.name||'Computador')+' · agente '+(ag.version||'—')+(ag.online?' · conectado':' · desconectado'))),
  h('span',{class:'cb-pillstate '+stateTone,role:'status'},stateLabel)));
 if(ag.outdated)body.append(h('p',{class:'cb-warn'},ico('arrow-circle-up'),' O agente deste computador está na versão '+ag.version+' e a plataforma na '+ag.platform_version+'. Atualize o agente para receber as correções de envio.'));
 else if(!ag.online)body.append(h('p',{class:'cb-warn'},ico('plug'),' O agente está desconectado. O backup volta a rodar quando ele se reconectar.'));
 if(st.message&&(st.state==='error'||st.failures?.length))body.append(h('p',{class:'cb-warn',role:'alert'},ico('exclamation-triangle'),' '+st.message));
 const tabs=[['files','Arquivos na nuvem','folder-open-o'],['setup','Configuração','sliders'],['activity','Atividade','line-chart']];
 body.append(h('div',{class:'cb-tabs',role:'tablist'},tabs.map(([k,l,i])=>h('button',{type:'button',role:'tab','aria-selected':String(BK.tab===k),class:BK.tab===k?'on':'',onclick:()=>{BK.tab=k;if(k==='files'&&BK.bconn!==d.job.connection_id)bkBrowse([],false);else render()}},ico(i),' '+l,k==='setup'&&BK.dirty?h('i',{class:'cb-dot'}):null))));
 if(BK.tab==='files')bkFiles(body,d);else if(BK.tab==='activity')bkActivity(body,d);else{bkSetup(body,d);body.append(h('div',{id:'cb-savebar',class:'cb-savebar'}));bkSavebar()}
};
setInterval(()=>{if(section==='backup'&&!document.hidden&&!BK.dirty&&!BK.loading&&!$('cc-dlg').open&&!root.contains(document.activeElement))backupLoad(true)},12000);
