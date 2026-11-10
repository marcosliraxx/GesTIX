<?php
/* Caminho: redes/arquivos.php */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();$networkId=(int)($_GET['network']??0);
if(!arc_is_user()&&!arc_is_admin()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
try{arc_support_access($pdo,$networkId,(int)arc_user_id());}catch(RuntimeException $e){http_response_code(403);exit('Rede não autorizada.');}
$arcSectionFile='arquivos';require __DIR__.'/_base.php';require __DIR__.'/_topo.php';
$canSupportAdmin=in_array($teamRole,['owner','administrator'],true);
$approved=array_values(array_filter($networkGestores,static fn(array $g):bool=>($g['status']??'')==='approved'));
?>
<link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url())?>assets/css/arc-files.css?v=<?=arc_h(arc_app_version())?>">
<style>
.arc-files-standalone{background:#18283b;color:#eaf3ff;min-height:0;flex:1 1 auto;display:flex;flex-direction:column;padding:0;gap:0}.arc-files-standalone .arc-files-workspace{flex:1;min-height:0;display:flex;flex-direction:column}.arc-files-standalone .arc-fs-entries{overflow:auto;flex:1;min-height:100px}.arc-files-standalone .arc-explorer-menu,.arc-files-standalone .arc-explorer-preview{color:#eaf3ff;background:#192e46;border:1px solid #45617b;border-radius:10px;max-width:min(650px,95vw);max-height:82vh;overflow:auto}.arc-files-standalone [hidden]{display:none!important}
</style>
<div class="arc-files-standalone">
<section class="arc-files-workspace" id="file-transfer" aria-label="Arquivos do computador">
  <header class="arc-files-header">
    <nav class="arc-files-address" id="arc-files-address" aria-label="Endereço do explorador">
      <div class="arc-files-crumbs" id="arc-fs-crumbs"><button type="button" class="arc-fs-crumb" data-fs-path="" title="Dispositivos e unidades" aria-label="Dispositivos e unidades"><i class="fa fa-desktop"></i></button></div>
      <span id="arc-fs-current" class="arc-fs-current-path" hidden>Dispositivos e unidades</span>
    </nav>
    <div class="arc-files-actions" role="toolbar" aria-label="Ações dos arquivos">
      <button type="button" class="arc-files-icon" id="arc-files-download" title="Baixar selecionados" aria-label="Baixar selecionados" <?=!$canSupportAdmin?'disabled':''?>><i class="fa fa-download"></i></button>
      <button type="button" class="arc-files-icon" id="arc-files-upload" title="Enviar arquivo" aria-label="Enviar arquivo" <?=!$canSupportAdmin?'disabled':''?>><i class="fa fa-upload"></i></button><button type="button" class="arc-files-icon" id="arc-files-upload-folder" title="Enviar pasta" aria-label="Enviar pasta" <?=!$canSupportAdmin?'disabled':''?>><i class="fa fa-folder-open"></i></button>
      <div class="arc-fs-view-switch" role="group" aria-label="Modo de visualização"><button type="button" class="arc-files-icon" data-fs-view="list" title="Lista" aria-label="Visualização em lista"><i class="fa fa-list"></i></button><button type="button" class="arc-files-icon" data-fs-view="grid" title="Ícones" aria-label="Visualização em ícones"><i class="fa fa-th-large"></i></button><button type="button" class="arc-files-icon" data-fs-view="details" title="Detalhes" aria-label="Visualização detalhada"><i class="fa fa-align-justify"></i></button></div>
      <button type="button" class="arc-files-icon" id="arc-fs-back" title="Voltar" aria-label="Voltar"><i class="fa fa-arrow-left"></i></button>
      <button type="button" class="arc-files-icon" id="arc-files-options" title="Opções da pasta e colar" aria-label="Opções da pasta e colar"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></button><input type="file" id="file-source" hidden><input type="file" id="folder-source" webkitdirectory directory multiple hidden>
    </div>
  </header>
  <div class="arc-files-toolbar" id="arc-files-toolbar" hidden><div class="arc-files-selection" id="arc-files-selection"></div><button type="button" class="arc-files-icon" id="arc-files-selection-options" title="Opções dos selecionados" aria-label="Opções dos selecionados"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></button><button type="button" class="arc-files-icon" id="arc-fs-clear-selection" aria-label="Limpar seleção" title="Limpar seleção"><i class="fa fa-times"></i></button></div>
  <div class="arc-files-filter"><input type="search" id="arc-fs-search" placeholder="Pesquisar nesta listagem" aria-label="Pesquisar nesta listagem"><select id="arc-fs-sort" aria-label="Ordenar"><option value="name">Nome</option><option value="modified">Data de modificação</option><option value="size">Tamanho</option></select><button type="button" class="arc-files-icon" id="arc-fs-up" title="Pasta acima" aria-label="Pasta acima">↑</button><button type="button" class="arc-files-icon" id="arc-fs-forward" title="Avançar" aria-label="Avançar">→</button><button type="button" class="arc-files-icon" id="arc-fs-refresh" title="Atualizar" aria-label="Atualizar">↻</button></div>
  <div id="arc-fs-entries" class="arc-fs-entries" role="list" aria-label="Discos e arquivos"></div>
  <button type="button" id="arc-fs-center-load" class="arc-fs-center-load" aria-label="Carregar arquivos" title="Carregar os discos disponíveis"><i class="fa fa-folder-open" aria-hidden="true"></i><span>Carregar arquivos</span></button>
  <p id="arc-fs-state" role="status" aria-live="polite">Selecione um computador conectado.</p>
  <div id="file-feedback" class="arc-files-feedback" role="status" aria-live="polite"></div>

  <aside id="arc-transfer-dock" class="arc-transfer-dock" hidden aria-live="polite"><div class="arc-transfer-dock-head"><strong id="arc-transfer-title">Transferência</strong><button type="button" id="arc-transfer-cancel" class="arc-files-icon" aria-label="Cancelar transferência" title="Cancelar transferência"><i class="fa fa-times"></i></button></div><progress id="arc-transfer-progress" max="100" value="0"></progress><small id="arc-transfer-detail">Aguardando…</small></aside>
</section>
<dialog id="arc-fs-menu" class="arc-explorer-menu" aria-labelledby="arc-fs-menu-name"><header><strong id="arc-fs-menu-name"></strong><button type="button" id="arc-fs-menu-close" class="arc-icon-action" aria-label="Fechar opções"><i class="fa fa-times" aria-hidden="true"></i></button></header><div id="arc-fs-menu-options"></div></dialog>
<dialog id="arc-file-preview" class="arc-explorer-preview" aria-labelledby="arc-file-preview-title"><header><strong id="arc-file-preview-title"></strong><button type="button" id="arc-file-preview-close" class="arc-icon-action" aria-label="Fechar visualização"><i class="fa fa-times" aria-hidden="true"></i></button></header><div id="arc-file-preview-content"></div></dialog>
</div>
<script src="<?=arc_h(arc_workspace_base_url())?>assets/js/support-transfers.js?v=<?=arc_h(arc_app_version())?>"></script>
<script>
(()=>{'use strict';const network=<?=json_encode($networkId)?>,csrf=<?=json_encode($csrf)?>,base=<?=json_encode(arc_workspace_base_url())?>;
const plain=value=>window.arcPlainMessage?window.arcPlainMessage(value):String(value||'');
async function json(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não foi possível verificar.');return j;}
const fileDialog=document.getElementById('file-transfer'),fileMessage=document.getElementById('file-feedback');let fileBusy=false,fileLimit=<?=ARC_FILE_MAX_BYTES?>,fileFolderLimit=300;
const fsDefaultGestor=<?=json_encode((int)$selectedGestorId)?>;
function fsGestorId(){const selector=document.getElementById('arc-gestor-target');const current=Number(selector?.value||0);if(current>0)return current;if(fsDefaultGestor>0){if(selector&&[...selector.options].some(o=>Number(o.value)===fsDefaultGestor))selector.value=String(fsDefaultGestor);return fsDefaultGestor;}return 0;}
const fsEntries=document.getElementById('arc-fs-entries'),fsState=document.getElementById('arc-fs-state'),fsCurrent=document.getElementById('arc-fs-current'),fsSelection=document.getElementById('arc-files-selection');
let fsNavigatingHistory=false;let fsPath='',fsBusy=false,fsPendingRoot=false,fsLoadState='idle',fsRows=[],fsHistory=[],fsHistoryIndex=-1,fsInitialized=false;let fsView='details';try{const saved=localStorage.getItem('arc_support_file_view');if(['list','grid','details'].includes(saved))fsView=saved}catch{};fsEntries?.setAttribute('data-view',fsView);const fsCanTransfer=<?=json_encode($canSupportAdmin)?>,fsCanExecute=<?=json_encode(in_array($teamRole,['owner','administrator'],true))?>;
const fsSelected=new Set();let fsSelectionMode=false;
const fileLabels={cancelled:'Cancelado',pending:'Aguardando o computador',ready:'Pronto para baixar',completed:'Concluído',declined:'Não autorizado no computador',failed:'Não foi possível concluir',expired:'Expirado'};
const formatBytes=n=>{n=Number(n)||0;if(!n)return '0 B';const u=['B','KB','MB','GB','TB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return n.toLocaleString('pt-BR',{maximumFractionDigits:i<2?0:1})+' '+u[i];};
const extOf=name=>{const pos=String(name||'').lastIndexOf('.');return pos>-1?String(name).slice(pos+1).toLowerCase():'';};
const fileIcon=item=>{if(item.drive)return 'fa-hdd-o'; if(item.directory)return 'fa-folder'; const ext=extOf(item.name), map={zip:'fa-file-archive-o',rar:'fa-file-archive-o','7z':'fa-file-archive-o',tar:'fa-file-archive-o',gz:'fa-file-archive-o',bz2:'fa-file-archive-o',png:'fa-file-image-o',jpg:'fa-file-image-o',jpeg:'fa-file-image-o',gif:'fa-file-image-o',bmp:'fa-file-image-o',webp:'fa-file-image-o',svg:'fa-file-image-o',mp4:'fa-file-video-o',avi:'fa-file-video-o',mkv:'fa-file-video-o',mov:'fa-file-video-o',wmv:'fa-file-video-o',mp3:'fa-file-audio-o',wav:'fa-file-audio-o',ogg:'fa-file-audio-o',flac:'fa-file-audio-o',pdf:'fa-file-pdf-o',doc:'fa-file-word-o',docx:'fa-file-word-o',xls:'fa-file-excel-o',xlsx:'fa-file-excel-o',csv:'fa-file-excel-o',ppt:'fa-file-powerpoint-o',pptx:'fa-file-powerpoint-o',txt:'fa-file-text-o',log:'fa-file-text-o',ini:'fa-file-text-o',json:'fa-file-code-o',xml:'fa-file-code-o',html:'fa-file-code-o',css:'fa-file-code-o',js:'fa-file-code-o',php:'fa-file-code-o',py:'fa-file-code-o',sql:'fa-file-code-o',exe:'fa-cogs',msi:'fa-cogs',bat:'fa-terminal',cmd:'fa-terminal',com:'fa-cogs'}; return map[ext]||'fa-file-o';};
const isExecutable=item=>!item.directory&&['exe','msi','bat','cmd','com'].includes(extOf(item.name));
const previewExtensions=new Set(['pdf','doc','docx','txt','log','ini','csv','json','xml','md','svg','png','jpg','jpeg','gif','webp','bmp','mp4','webm','ogg','mov','mp3','wav','flac','m4a']);
function setSelectionMode(on){fsSelectionMode=!!on;if(!on)fsSelected.clear();renderSelectionInfo();renderEntries();}
function renderSelectionInfo(){if(!fsSelection)return;const total=fsSelected.size;fsSelection.textContent=total?total+' selecionado(s)':'';document.getElementById('arc-files-toolbar').hidden=total===0;fsSelection.classList.toggle('is-active',total>0);}
function toggleSelected(path){if(!fsCanTransfer)return; if(fsSelected.has(path))fsSelected.delete(path); else fsSelected.add(path); renderSelectionInfo(); renderEntries();}
function rowLabel(item){if(item.drive){const free=Number(item.free||0),total=Number(item.total||item.size||0);return (item.name||'Disco')+' · '+(total?formatBytes(free)+' livres de '+formatBytes(total):'Unidade local');} return item.directory?'Pasta':(extOf(item.name).toUpperCase()||'Sem extensão')+' · Arquivo';}
function renderBreadcrumbs(){
 const el=document.getElementById('arc-fs-crumbs');if(!el)return;el.replaceChildren();
 const add=(label,path,icon)=>{const b=document.createElement('button');b.type='button';b.className='arc-fs-crumb';b.title=path||'Este Computador';if(icon){const i=document.createElement('i');i.className='fa '+icon;b.append(i);}b.append(document.createTextNode(label));b.addEventListener('click',()=>fsOpen(path));el.append(b);};
 add('','','fa-desktop');
 if(!fsPath)return;
 const parts=fsPath.replace(/\\/g,'/').replace(/\/+$/,'').split('/').filter(Boolean);let current='';
 for(let i=0;i<parts.length;i++){const sep=document.createElement('i');sep.className='fa fa-angle-right arc-fs-separator';el.append(sep);current=i===0?parts[i]+'\\':current.replace(/[\\/]+$/,'')+'\\'+parts[i];add(parts[i],current);}
}
function renderEntries(){fsEntries.replaceChildren();fsEntries.dataset.view=fsView;thumbnailSlots.clear();
 if(!fsRows.length){
  const panel=document.createElement('div');panel.className='arc-fs-empty-panel';
  const icon=document.createElement('i');icon.className=fsLoadState==='loading'?'fa fa-circle-o-notch fa-spin':fsLoadState==='loaded'&&fsPath?'fa fa-folder-open-o':'fa fa-folder-open';icon.setAttribute('aria-hidden','true');panel.append(icon);
  const description=document.createElement('span');
  description.textContent=fsLoadState==='loading'?'Carregando arquivos…':fsLoadState==='loaded'&&fsPath?'Esta pasta está vazia.':fsLoadState==='error'?'Não foi possível carregar os arquivos.':'Os discos ainda não foram carregados.';
  panel.append(description);
  fsEntries.append(panel);return;
 }

 const query=document.getElementById('arc-fs-search').value.toLocaleLowerCase('pt-BR'),sort=document.getElementById('arc-fs-sort').value;
 const visible=fsRows.filter(i=>String(i.name+' '+(i.label||'')).toLocaleLowerCase('pt-BR').includes(query)).sort((a,b)=>Number(b.directory)-Number(a.directory)||(sort==='name'?String(a.name).localeCompare(String(b.name),'pt-BR',{numeric:true}):Number(b[sort]||0)-Number(a[sort]||0)));
 for(const item of visible){
  const button=document.createElement('button');button.type='button';button.className='arc-fs-item'+(item.drive?' arc-fs-drive':'')+(fsSelected.has(item.path)?' is-selected':'');button.setAttribute('role','listitem');
  const check=document.createElement('span');check.className='arc-fs-check';check.innerHTML='<i class="fa fa-check" aria-hidden="true"></i>';
  if(fsSelectionMode&&fsCanTransfer&&!item.drive){check.setAttribute('role','checkbox');check.setAttribute('aria-checked',String(fsSelected.has(item.path)));check.setAttribute('aria-label','Selecionar '+item.name);button.append(check);}
  const icon=document.createElement('i');icon.className='fa '+fileIcon(item);icon.setAttribute('aria-hidden','true');
  const label=document.createElement('span');label.className='arc-fs-label';
  const name=document.createElement('strong');name.textContent=item.drive?(item.label||({'Removable':'Unidade USB','2':'Unidade USB','Network':'Unidade de rede','4':'Unidade de rede','CDRom':'Unidade de CD/DVD','5':'Unidade de CD/DVD'}[item.drive_type])||'Unidade')+' ('+String(item.name||'').replace(/[\\/]+$/,'')+')':item.name;label.append(name);
  const details=document.createElement('small');details.textContent=item.drive?rowLabel(item):(item.directory?'Pasta · '+(item.folder_size===null||item.folder_size===undefined?'tamanho indisponível':formatBytes(item.folder_size)+(item.folder_size_complete?'':' ou mais')):rowLabel(item)+' · '+formatBytes(item.size));label.append(details);
  button.append(icon,label);if(typeof scheduleThumbnail==='function')scheduleThumbnail(item,icon);if(fsView==='details'&&!item.drive){const modified=document.createElement('small');modified.className='arc-fs-modified';modified.textContent=item.modified?new Date(item.modified*1000).toLocaleString('pt-BR'):'—';button.append(modified);}
  if(item.drive){const total=Number(item.total||item.size||0),free=Number(item.free||0);if(total){const meter=document.createElement('span');meter.className='arc-drive-meter';const fill=document.createElement('span');fill.style.width=Math.max(0,Math.min(100,100*(total-free)/total))+'%';meter.append(fill);label.append(meter);}}else if(!item.directory){const size=document.createElement('small');size.className='arc-fs-size';size.textContent=formatBytes(item.size);button.append(size);}
  if(isExecutable(item)&&fsCanExecute&&fsCanTransfer){const run=document.createElement('span');run.className='arc-fs-run';run.textContent='Executar';button.append(run);}  
  let timer=null,suppressClick=false,startX=0,startY=0;
  const cancelPress=()=>{clearTimeout(timer);timer=null;};
  button.addEventListener('pointerdown',e=>{if(!fsCanTransfer||item.drive||e.button!==0)return;startX=e.clientX;startY=e.clientY;cancelPress();timer=setTimeout(()=>{suppressClick=true;openFileMenu(item);},650);});
  button.addEventListener('pointermove',e=>{if(Math.abs(e.clientX-startX)>8||Math.abs(e.clientY-startY)>8)cancelPress();});
  for(const event of ['pointerup','pointercancel','pointerleave'])button.addEventListener(event,cancelPress);
  button.addEventListener('contextmenu',e=>{if(!fsCanTransfer)return;e.preventDefault();e.stopPropagation();cancelPress();const rect=button.getBoundingClientRect();openFileMenu(item,{x:e.clientX||rect.left,y:e.clientY||rect.bottom});});
  const activate=()=>{if(item.directory){fsOpen(item.path);return;}if(previewExtensions.has(extOf(item.name))){fileOperation('preview',[item.path],{},true);return;}openFileMenu(item);};
  button.addEventListener('click',e=>{e.preventDefault();if(suppressClick){suppressClick=false;return;}
   if(matchMedia('(pointer:coarse)').matches&&!fsSelectionMode){activate();return;}
   if(!fsCanTransfer)return;
   if(!e.ctrlKey&&!e.metaKey&&!e.shiftKey)fsSelected.clear();
   if(!item.drive){if((e.ctrlKey||e.metaKey)&&fsSelected.has(item.path))fsSelected.delete(item.path);else fsSelected.add(item.path);}
   fsSelectionMode=fsSelected.size>0;renderSelectionInfo();
   for(const row of fsEntries.children)row.classList.toggle('is-selected',fsSelected.has(row.dataset.path));
   if(item.drive)button.classList.add('is-selected');
  });
  button.dataset.path=item.path;button.addEventListener('dblclick',e=>{e.preventDefault();activate();});
  button.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();activate();}else if(e.key==='ContextMenu'||(e.shiftKey&&e.key==='F10')){e.preventDefault();openFileMenu(item);}else if(e.key==='F2'&&!item.drive&&fsCanTransfer){e.preventDefault();handleFileOption('rename',[item]);}else if(e.key==='Delete'&&!item.drive&&fsCanTransfer){e.preventDefault();handleFileOption('delete',fsSelected.has(item.path)?fsRows.filter(r=>fsSelected.has(r.path)):[item]);}});
  if(!fsCanTransfer && !item.drive)button.disabled=true;
  fsEntries.append(button);
 }
}
async function fsOpen(path=''){
 if(!fsEntries)return;
 if(fsBusy){if(path==='')fsPendingRoot=true;return;}
 if(!fsCanTransfer && path!==''){fsState.textContent='Seu perfil permite visualizar somente os discos.';return;}
 const gestor=fsGestorId();
 if(!gestor){fsLoadState='idle';fsState.textContent='Selecione um computador conectado.';fsRows=[];renderEntries();return;}
 fsBusy=true;fsLoadState='loading';setSelectionMode(false);fsState.textContent='Carregando arquivos…';fsRows=[];renderEntries();
 try{
  await abortThumbnails();
  const started=await json(base+'api/api.php?action=network-file-browser-start',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,gestor_id:gestor,path,_csrf:csrf})});
  let result=null;
  for(let i=0;i<24;i++){
   await new Promise(resolve=>setTimeout(resolve,900));
   result=await json(base+'api/api.php?action=network-file-browser-status&network_id='+network+'&request_id='+started.request_id);
   if(['completed','failed','expired'].includes(result.status))break;
  }
  if(result?.status!=='completed'||!result.data||result.data.error)throw Error(result?.data?.error||result?.message||'O servidor não respondeu à consulta.');
  fsPath=result.data.path||'';fsCurrent.textContent=fsPath||'Dispositivos e unidades';fsRows=(result.data.entries||[]).map(item=>({...item,drive:!fsPath&&!!item.directory}));fsLoadState='loaded';fsInitialized=true;fsState.textContent=fsRows.length+' item(ns) encontrado(s).'+(result.data.truncated?' Listagem limitada aos primeiros 150 itens.':'');if(!fsNavigatingHistory&&fsHistory[fsHistoryIndex]!==fsPath){fsHistory=fsHistory.slice(0,fsHistoryIndex+1);fsHistory.push(fsPath);fsHistoryIndex=fsHistory.length-1;}renderBreadcrumbs();renderEntries();
 }catch(e){fsRows=[];fsLoadState='error';renderEntries();fsState.textContent=e.message;}finally{fsNavigatingHistory=false;fsBusy=false;if(fsPendingRoot){fsPendingRoot=false;fsOpen('');}}
}
async function fileApi(action,options={}){return json(base+'api/support.php?action='+action,{headers:{'X-CSRF-Token':csrf},...options});}
const thumbnailVersions=new Map(),thumbnailCache=new Map(),thumbnailSlots=new Map(),thumbnailTypes=new Set(['png','jpg','jpeg','webp','gif','bmp','tif','tiff','heic','mp4','webm','avi','mov','mkv','wmv','pdf','doc','docx','ppt','pptx','xls','xlsx','odt','ods','odp']);let thumbnailJob=null,thumbnailTimer=null,thumbnailUnsupported=false;
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function showThumbnail(icon,url,name){if(!icon?.isConnected)return;const el=document.createElement('img');el.className='arc-fs-thumb';el.alt=name;el.src=url;icon.replaceWith(el);}
function scheduleThumbnail(item,icon){
 if(item.directory||item.drive||!fsCanTransfer||thumbnailUnsupported||!thumbnailTypes.has(extOf(item.name)))return;
 const key=fsGestorId()+':'+item.path,version=String(item.size)+':'+String(item.modified);
 if(thumbnailVersions.get(key)!==version){const old=thumbnailCache.get(key);if(old)URL.revokeObjectURL(old);thumbnailCache.delete(key);thumbnailVersions.set(key,version);}
 const cached=thumbnailCache.get(key);
 if(cached){showThumbnail(icon,cached,item.name);return;}
 thumbnailSlots.set(key,{icon,name:item.name});
 if(cached===undefined){clearTimeout(thumbnailTimer);thumbnailTimer=setTimeout(loadThumbnails,200);}
}
async function cancelTransfer(id,gestor=fsGestorId()){if(!id)return;const form=new FormData();form.set('network',network);form.set('gestor',gestor);form.set('id',id);await fileApi('file-cancel',{method:'POST',body:form}).catch(()=>{});}
/* Todas as imagens da pasta chegam em um único pedido ao computador (até 24 por vez). */
async function loadThumbnails(){
 if(thumbnailJob||fileBusy||fileDialog.hidden||thumbnailUnsupported||!fsCanTransfer)return;
 const gestor=fsGestorId();if(!gestor)return;
 const wanted=[...thumbnailSlots.keys()].filter(key=>key.startsWith(gestor+':')&&thumbnailCache.get(key)===undefined).slice(0,24);
 if(!wanted.length)return;
 const prefix=String(gestor).length+1;for(const key of wanted)thumbnailCache.set(key,null);
 const job={gestor,id:null,controller:new AbortController(),requested:null};thumbnailJob=job;
 try{
  const form=new FormData();form.set('network',network);form.set('gestor',gestor);form.set('operation','thumbnails');form.set('paths',JSON.stringify(wanted.map(key=>key.slice(prefix))));form.set('confirmed','1');
  job.requested=fileApi('file-operation-request',{method:'POST',body:form});job.id=(await job.requested).id;
  const row=await waitTransfer(job.id,['ready'],job.controller.signal,120000);
  const response=await fetch(base+'api/support.php?action=file-download&id='+row.id,{credentials:'same-origin',cache:'no-store',signal:job.controller.signal});
  if(!response.ok)throw Error('Miniaturas indisponíveis.');
  const bytes=new Uint8Array(await response.arrayBuffer());
  if(bytes.length<8||String.fromCharCode(...bytes.subarray(0,4))!=='ARCT')throw Error('Miniaturas inválidas.');
  const size=new DataView(bytes.buffer).getUint32(4);if(size>bytes.length-8||size>65536)throw Error('Cabeçalho de miniaturas inválido.');const entries=JSON.parse(new TextDecoder().decode(bytes.subarray(8,8+size)));let offset=8+size;
  if(!Array.isArray(entries)||entries.length>24)throw Error('Miniaturas inválidas.');
  for(const entry of entries){
   if(!Number.isInteger(entry.size)||entry.size<1||entry.size>262144||offset+entry.size>bytes.length||!wanted.includes(gestor+':'+entry.path))throw Error('Conteúdo de miniaturas inválido.');
   const part=bytes.slice(offset,offset+entry.size);offset+=entry.size;
   if(!/^image\/(jpeg|png|webp|gif|bmp)$/.test(entry.type))continue;
   const key=gestor+':'+entry.path,url=URL.createObjectURL(new Blob([part],{type:entry.type}));thumbnailCache.set(key,url);
   const slot=thumbnailSlots.get(key);if(slot)showThumbnail(slot.icon,url,slot.name);
  }
 }catch(error){if(/Atualize o gestor/.test(error.message)){thumbnailUnsupported=true;fileMessage.textContent=plain(error.message);}}
 finally{
  if(thumbnailJob===job)thumbnailJob=null;
  if(job.controller.signal.aborted)for(const key of wanted)if(thumbnailCache.get(key)===null)thumbnailCache.delete(key);
  await cancelTransfer(job.id,gestor);
  if(!job.controller.signal.aborted)setTimeout(loadThumbnails,150);
 }
}
/* Libera o computador antes de qualquer pedido do usuário. */
async function abortThumbnails(){const job=thumbnailJob;if(!job)return;thumbnailJob=null;job.controller.abort();const result=await job.requested?.catch(()=>null);await cancelTransfer(result?.id||job.id,job.gestor);}
window.addEventListener('pagehide',()=>{for(const url of thumbnailCache.values())if(url)URL.revokeObjectURL(url);});
let activeTransfer=null;const transferDock=document.getElementById('arc-transfer-dock'),transferProgress=document.getElementById('arc-transfer-progress'),transferDetail=document.getElementById('arc-transfer-detail');document.body.append(transferDock);function transferStart(title){transferDock.hidden=false;document.getElementById('arc-transfer-title').textContent=title;transferProgress.removeAttribute('value');transferDetail.textContent='Iniciando…';}function transferUpdate(loaded,total,started,initialLoaded=0){if(total>0){transferProgress.value=Math.min(100,loaded/total*100);const speed=(Math.max(0,loaded-initialLoaded)/Math.max(.25,(Date.now()-started)/1000));const remaining=speed>0?(total-loaded)/speed:0;transferDetail.textContent=Math.round(loaded/total*100)+'% · '+formatBytes(loaded)+' de '+formatBytes(total)+' · '+(speed<=0?'calculando tempo…':remaining<1?'quase concluído':Math.ceil(remaining)+' s restantes');}else transferDetail.textContent=formatBytes(loaded)+' enviados';}function transferFinish(message=''){if(message)transferDetail.textContent=message;setTimeout(()=>{if(!activeTransfer)transferDock.hidden=true;},2500);}document.getElementById('arc-transfer-cancel').addEventListener('click',()=>{if(activeTransfer){activeTransfer.abort();activeTransfer=null;fileBusy=false;transferFinish('Transferência cancelada.');fileMessage.textContent='Transferência cancelada.';}});const uploadUser=<?=json_encode((int)arc_user_id())?>;
window.ArcDirectUpload?.purgeLegacyQueue();
/* Envio direto: sai do navegador na hora, grava no computador e a lista é atualizada ao terminar. */
async function sendFiles(files,folder=false){
 if(fileBusy||!files.length)return;
 if(!window.ArcDirectUpload){fileMessage.textContent='Recarregue a página para enviar arquivos.';return;}
 if(!fsPath||!fsGestorId()){fileMessage.textContent='Abra a pasta de destino no computador selecionado antes de enviar.';return;}
 fileBusy=true;const controller=new AbortController(),destination=fsPath,gestor=fsGestorId(),label=folder?files[0].webkitRelativePath.split('/')[0]:files[0].name;let id=null;
 try{
  await abortThumbnails();activeTransfer=controller;transferStart('Enviando '+label);fileMessage.textContent='';const started=Date.now();
  id=await ArcDirectUpload.send({base,csrf,user:uploadUser,network:Number(network),gestor,destination},files,folder,{signal:controller.signal,onProgress:(loaded,total)=>transferUpdate(loaded,total,started)});
  transferProgress.removeAttribute('value');transferDetail.textContent='Salvando no computador…';
  await waitTransfer(id,['completed'],controller.signal,150000);
  fileMessage.textContent='Enviado: '+label+'.';transferFinish('Enviado · '+label);activeTransfer=null;
  if(fsPath===destination&&fsGestorId()===gestor)await fsOpen(destination);
 }catch(error){
  if(id&&controller.signal.aborted)await cancelTransfer(id,gestor);
  const message=error.name==='AbortError'?'Envio cancelado.':plain(error.message);fileMessage.textContent=message;transferFinish(message);
 }finally{activeTransfer=null;fileBusy=false;document.getElementById('file-source').value='';document.getElementById('folder-source').value='';setTimeout(loadThumbnails,200);}
}
async function requestFileSend(file){if(file)return sendFiles([file]);}
async function requestBundleDownload(){if(fsSelected.size)return fileOperation('download',[...fsSelected]);}
const fileMenu=document.getElementById('arc-fs-menu'),preview=document.getElementById('arc-file-preview');let clipboard=null,previewUrl=null;
const operationLabels={open:'Abrir',rename:'Alterar nome',execute:'Executar',download:'Baixar arquivo',select:'Selecionar',delete:'Excluir',preview:'Visualizar',extract:'Extrair ZIP',copy:'Copiar',cut:'Recortar',move:'Mover',paste:'Colar'};
const operationIcons={open:'folder-open',rename:'pencil',execute:'play',download:'download',select:'check-square-o',delete:'trash',preview:'eye',extract:'file-archive-o',copy:'copy',cut:'cut',move:'arrows',paste:'paste'};
function openFileMenu(item=null,position=null){
 if(!fsCanTransfer||fileBusy)return;if(fileMenu.open)fileMenu.close();const items=fsSelectionMode&&fsSelected.has(item?.path)?fsRows.filter(r=>fsSelected.has(r.path)):item?[item]:[];
 const list=document.getElementById('arc-fs-menu-options');list.replaceChildren();document.getElementById('arc-fs-menu-name').textContent=items.length>1?items.length+' itens':item?.name||'Esta pasta';
 for(const [action,label] of Object.entries(operationLabels)){
  const single=items.length===1,regular=single&&!items[0].directory;
  if(items.some(i=>i.drive)&&!['open'].includes(action))continue;
  const available=action==='execute'&&!fsCanExecute?false:action==='paste'?!!clipboard&&clipboard.gestor===fsGestorId()&&!!fsPath:action==='execute'?regular&&isExecutable(items[0]):action==='preview'?regular&&previewExtensions.has(extOf(items[0].name)):action==='extract'?regular&&extOf(items[0].name)==='zip':action==='rename'||action==='open'?single:items.length>0;
  const b=document.createElement('button');b.type='button';b.disabled=!available;b.dataset.fileOperation=action;
  const icon=document.createElement('i');icon.className='fa fa-'+operationIcons[action];icon.setAttribute('aria-hidden','true');b.append(icon,document.createTextNode(label));
  b.addEventListener('click',()=>{fileMenu.close();handleFileOption(action,items);});list.append(b);
 }
 fileMenu.style.margin='';fileMenu.style.inset='';fileMenu.style.left='';fileMenu.style.top='';
 fileMenu.style.zIndex='10020';fileMenu.show();
 if(position){const rect=fileMenu.getBoundingClientRect();fileMenu.style.margin='0';fileMenu.style.inset='auto';fileMenu.style.left=Math.max(8,Math.min(position.x,innerWidth-rect.width-8))+'px';fileMenu.style.top=Math.max(8,Math.min(position.y,innerHeight-rect.height-8))+'px';}
 fileMenu.querySelector('#arc-fs-menu-options button:not(:disabled)')?.focus();
}
function handleFileOption(action,items){
 const paths=items.map(i=>i.path);
 if(action==='select'){setSelectionMode(true);for(const p of paths)fsSelected.add(p);renderSelectionInfo();renderEntries();return;}
 if(action==='open'&&items[0]?.directory){fsOpen(items[0].path);return;}
 if(action==='copy'||action==='cut'){clipboard={gestor:fsGestorId(),paths,operation:action==='cut'?'move':'copy'};fileMessage.textContent=paths.length+' item(ns) na área de transferência. Abra o destino e use Colar.';return;}
 let name='',destination='';
 if(action==='paste'){
  if(!clipboard||clipboard.gestor!==fsGestorId())return;
  return fileOperation(clipboard.operation,clipboard.paths,{destination:fsPath},false,true);
 }
 if(action==='rename'){name=window.prompt('Novo nome:',items[0].name);if(!name)return;}
 if(action==='move'){destination=window.prompt('Pasta de destino neste computador:',fsPath);if(!destination)return;}
 if(action==='delete'&&!window.confirm('Enviar '+paths.length+' item(ns) à Lixeira do Windows?'))return;
 if(action==='execute'&&!window.confirm('Executar "'+items[0].name+'" no computador remoto? O aplicativo pode exigir privilégios próprios do Windows.'))return;
 fileOperation(action,paths,{name,destination},action==='preview');
}
/* Aguarda o computador responder: consulta curta e contínua, sem fila. */
async function waitTransfer(id,done=['ready'],signal=null,limit=150000){
 const end=Date.now()+limit;let delay=350;
 while(Date.now()<end){
  if(signal?.aborted)throw new DOMException('Cancelado','AbortError');
  const row=(await fileApi('file-status&id='+id)).transfer;
  if(done.includes(row.status))return row;
  if(['failed','expired','declined','cancelled'].includes(row.status))throw Error(row.message||'Operação não concluída.');
  await sleep(delay);delay=Math.min(800,delay+75);
 }
 throw Error('O computador não respondeu a tempo.');
}
/* O navegador baixa direto do servidor para o dispositivo, sem janela de permissão e sem copiar o arquivo para a memória. */
function startBrowserDownload(row){
 const url=base+'api/support.php?action=file-download&id='+row.id,link=document.createElement('a');link.href=url;link.download=row.filename||'';link.rel='noopener';document.body.append(link);link.click();link.remove();return url;
}
async function showPreview(row,sourcePath){
 const response=await fetch(base+'api/support.php?action=file-download&id='+row.id,{credentials:'same-origin',cache:'no-store'});
 if(!response.ok)throw Error('Visualização indisponível.');const data=await response.blob();if(data.size>8388608)throw Error('Arquivo maior que 8 MB.');
 cancelTransfer(row.id);
 const container=document.getElementById('arc-file-preview-content');container.replaceChildren();document.getElementById('arc-file-preview-title').textContent=row.filename;
 const ext=extOf(row.filename),images={png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',gif:'image/gif',webp:'image/webp',bmp:'image/bmp',svg:'image/svg+xml'},videos={mp4:'video/mp4',webm:'video/webm',ogg:'video/ogg',mov:'video/quicktime'},audio={mp3:'audio/mpeg',wav:'audio/wav',flac:'audio/flac',m4a:'audio/mp4'};
 if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=null;
 if(images[ext]||videos[ext]||audio[ext]||ext==='pdf'){
  const mime=images[ext]||videos[ext]||audio[ext]||'application/pdf';previewUrl=URL.createObjectURL(new Blob([data],{type:mime}));
  const element=document.createElement(images[ext]?'img':videos[ext]?'video':audio[ext]?'audio':'iframe');element.src=previewUrl;
  if(images[ext])element.alt=row.filename;else if(videos[ext]||audio[ext]){element.controls=true;element.preload='metadata';}else{element.title='Visualizador PDF';element.setAttribute('sandbox','allow-scripts');}
  container.append(element);
 }else{
  const pre=document.createElement('pre');pre.textContent=await data.text();container.append(pre);
 }
 const download=document.createElement('a');download.href='#';download.textContent='Baixar arquivo';download.className='button';
 download.addEventListener('click',e=>{e.preventDefault();preview.close();fileOperation('download',[sourcePath]);});container.append(download);preview.showModal();
}
async function fileOperation(operation,paths,extra={},view=false,clearClipboard=false){
 if(fileBusy||!fsCanTransfer)return;fileBusy=true;const gestor=fsGestorId(),controller=new AbortController(),item=fsRows.find(i=>i.path===paths[0]),label=paths.length>1?paths.length+' itens':(item?.name||'arquivo');let id=null;
 try{
  await abortThumbnails();
  if(['download','preview'].includes(operation)){activeTransfer=controller;transferStart((operation==='download'?'Baixando ':'Abrindo ')+label);fileMessage.textContent='';}
  else fileMessage.textContent='Processando '+(operationLabels[operation]||operation).toLowerCase()+'…';
  const form=new FormData();form.set('network',network);form.set('gestor',gestor);form.set('operation',operation);form.set('paths',JSON.stringify(paths));form.set('confirmed','1');for(const [key,value] of Object.entries(extra))form.set(key,value);
  const result=await fileApi('file-operation-request',{method:'POST',body:form});id=result.id||null;
  if(id){
   const row=await waitTransfer(id,['ready'],controller.signal);
   if(view){await showPreview(row,paths[0]);transferFinish('Visualização aberta.');}
   else{const url=startBrowserDownload(row),fallback=document.createElement('a');fallback.href=url;fallback.textContent='Se o download não iniciar, toque aqui.';fileMessage.replaceChildren(fallback);transferProgress.value=100;transferFinish('Download iniciado · '+formatBytes(row.file_size));}
  }else{
   let done=false;
   for(let i=0;i<60;i++){
    await sleep(900);const status=await json(base+'api/api.php?action=network-command-status&network_id='+network+'&gestor_id='+gestor);const row=(status.commands||[]).find(r=>Number(r.id)===Number(result.command_id));
    if(!row)continue;if(['failed','expired'].includes(row.status))throw Error(row.result_text||'Operação não concluída.');if(row.status==='completed'){done=true;fileMessage.textContent=row.result_text||'Concluído.';break;}
   }
   if(!done)throw Error('O computador não concluiu a operação no prazo.');
   if(clearClipboard)clipboard=null;
   if(['rename','delete','copy','move','extract'].includes(operation)){setSelectionMode(false);await fsOpen(fsPath);}
  }
 }catch(error){
  if(id&&controller.signal.aborted)await cancelTransfer(id,gestor);
  fileMessage.textContent=error.name==='AbortError'?'Transferência cancelada.':plain(error.message);transferFinish(fileMessage.textContent);
 }finally{activeTransfer=null;fileBusy=false;setTimeout(loadThumbnails,200);}
}
document.addEventListener('pointerdown',e=>{if(fileMenu.open&&!fileMenu.contains(e.target))fileMenu.close();});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&fileMenu.open)fileMenu.close();});
fileMenu.addEventListener('click',e=>{if(e.target===fileMenu)fileMenu.close();});
document.getElementById('arc-fs-menu-close').addEventListener('click',()=>fileMenu.close());
document.getElementById('arc-file-preview-close').addEventListener('click',()=>preview.close());
preview.addEventListener('close',()=>{document.getElementById('arc-file-preview-content').replaceChildren();if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=null;});
document.querySelectorAll('[data-fs-view]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.fsView===fsView)));
document.querySelectorAll('[data-fs-view]').forEach(button=>button.addEventListener('click',()=>{fsView=button.dataset.fsView;fsEntries.dataset.view=fsView;try{localStorage.setItem('arc_support_file_view',fsView)}catch{};document.querySelectorAll('[data-fs-view]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));renderEntries();}));document.getElementById('arc-files-options').addEventListener('click',()=>openFileMenu());
document.getElementById('arc-files-selection-options').addEventListener('click',()=>openFileMenu(fsRows.find(i=>fsSelected.has(i.path))));
document.getElementById('arc-fs-center-load')?.addEventListener('click',()=>fsOpen(''));
function historyStep(delta){if(fsBusy)return;const next=fsHistoryIndex+delta;if(next<0||next>=fsHistory.length)return;fsNavigatingHistory=true;const previous=fsHistoryIndex;fsHistoryIndex=next;fsOpen(fsHistory[next]).then(()=>{if(fsLoadState==='error')fsHistoryIndex=previous;});}
 document.getElementById('arc-fs-back')?.addEventListener('click',()=>historyStep(-1));
 document.getElementById('arc-fs-forward').onclick=()=>historyStep(1);
 const parentPath=()=>{const p=fsPath.replace(/[\\/]+$/,'');return /^[A-Za-z]:$/.test(p)?'':p.slice(0,p.lastIndexOf('\\')+1)};
 document.getElementById('arc-fs-up').onclick=()=>fsOpen(parentPath());document.getElementById('arc-fs-refresh').onclick=()=>fsOpen(fsPath);
 document.getElementById('arc-fs-search').oninput=renderEntries;document.getElementById('arc-fs-sort').onchange=renderEntries;
 fsEntries.addEventListener('contextmenu',e=>{if(e.target.closest('.arc-fs-item'))return;e.preventDefault();openFileMenu(null,{x:e.clientX,y:e.clientY});});
 fileDialog.addEventListener('keydown',e=>{if(e.target.matches('input,textarea,select')||fileMenu.open||preview.open)return;
 if(e.key==='F5'){e.preventDefault();fsOpen(fsPath);}else if(e.altKey&&e.key==='ArrowLeft'){e.preventDefault();historyStep(-1);}else if(e.altKey&&e.key==='ArrowRight'){e.preventDefault();historyStep(1);}else if(e.altKey&&e.key==='ArrowUp'){e.preventDefault();fsOpen(parentPath());}
 });
document.getElementById('arc-fs-clear-selection')?.addEventListener('click',()=>setSelectionMode(false));


document.getElementById('arc-files-upload')?.addEventListener('click',()=>{if(!fsCanTransfer)return;document.getElementById('file-source').click();});
document.getElementById('file-source')?.addEventListener('change',e=>requestFileSend(e.target.files?.[0]||null));document.getElementById('arc-files-upload-folder')?.addEventListener('click',()=>{if(fsCanTransfer)document.getElementById('folder-source').click();});document.getElementById('folder-source')?.addEventListener('change',e=>requestFolderSend([...e.target.files||[]]));
async function requestFolderSend(files){return sendFiles(files,true);}

document.getElementById('arc-files-download')?.addEventListener('click',()=>{if(!fsCanTransfer){fileMessage.textContent='Seu perfil não pode transferir arquivos.';return;}if(!fsSelected.size){fileMessage.textContent='Marque um ou mais arquivos ou pastas para baixar.';return;}requestBundleDownload();});
document.getElementById('arc-gestor-target')?.addEventListener('change',()=>{fsInitialized=false;fsOpen('')});
if(fsGestorId())fsOpen('');
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
