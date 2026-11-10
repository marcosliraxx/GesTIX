<?php /* Caminho: redes/_pentefino_ui_js.php | Lógica da interface do Pente-Fino (incluída por _pentefino_ui.php). */ 
require_once __DIR__ . '/../includes/error-logging.php';
?>
<script>
(()=>{'use strict';
const root=document.getElementById('cc'),api=root.dataset.api,network=Number(root.dataset.network),gestor=Number(root.dataset.gestor),csrf=root.dataset.csrf,cfg=JSON.parse(root.dataset.config),$=id=>document.getElementById(id);
const catalog=cfg.catalog,BROWSERS=new Set(cfg.browsers),batchMax=Number(cfg.batch)||100;
const h=(tag,attrs={},...kids)=>{const e=document.createElement(tag);for(const [k,v] of Object.entries(attrs)){if(v===false||v==null)continue;if(k==='class')e.className=v;else if(k.startsWith('on'))e[k]=v;else if(k==='value')e.value=v;else e.setAttribute(k,v===true?'':v)}for(const x of kids.flat(Infinity))if(x!=null&&x!==false)e.append(x.nodeType?x:document.createTextNode(String(x)));return e};
const ico=n=>h('i',{class:'fa fa-'+n,'aria-hidden':'true'});
const fmt=n=>Number(n||0).toLocaleString('pt-BR');
const size=n=>{n=Number(n||0);return n>=1073741824?(n/1073741824).toLocaleString('pt-BR',{maximumFractionDigits:1})+' GB':n>=1048576?(n/1048576).toLocaleString('pt-BR',{maximumFractionDigits:1})+' MB':n>=1024?(n/1024).toLocaleString('pt-BR',{maximumFractionDigits:1})+' KB':fmt(n)+' B'};
const kbSize=k=>k>0?(k>=1048576?(k/1048576).toLocaleString('pt-BR',{maximumFractionDigits:2})+' GB':(k/1024).toLocaleString('pt-BR',{maximumFractionDigits:1})+' MB'):'—';
const ageText=d=>d===0?'qualquer idade':d===1?'1 dia':d+' dias';
const NAV=[
 {h:'PENTE-FINO',items:[['health','Análise geral','filter']]},
 {h:'SISTEMA',items:[['custom:reg','Registro','wrench'],['startup','Inicialização','play'],['perf','Desempenho','eye'],['restore','Restauração','undo']]},
 {h:'APLICATIVOS',items:[['uninstall','Desinstalar','minus'],['updater','Atualizações','refresh'],['drivers','Drivers','th'],['windows_update','Windows Update','download']]},
 {h:'ARMAZENAMENTO',items:[['disk','Discos','folder'],['duplicates','Duplicados','files-o'],['cloud','Sincronizados','globe']]}
];
const OPT=['options','Configurações','cog'];
const ALLOWED=new Set(cfg.sections);
const LX={'Windows Explorer':'Windows','Sistema':'Sistema','Avançado':'Avançado','Microsoft Office':'Programas','Outros aplicativos':'Programas'};
const GI={Windows:'windows',Sistema:'cogs',Programas:'clone','Avançado':'sliders'};
const BRAND={'Google Chrome':['conic-gradient(#ea4335 0 33%,#fbbc05 0 66%,#34a853 0)','G'],'Microsoft Edge':['linear-gradient(135deg,#35c1f1,#0a7ed8 60%,#2ec27e)','e'],'Brave':['#fb542b','B'],'Opera':['#ff1b2d','O'],'Vivaldi':['#ef3939','V'],'Mozilla Firefox':['linear-gradient(135deg,#ffbd4f,#ff5a3c 60%,#a8398f)','F']};
const REG_STATE={ids:new Set(),checks:new Set(Object.keys(cfg.registry))};
let activeCommandId=0,section=cfg.start,tab='lixo',busy=false,busyLabel='',headless=false,online=false,age=7,progressText='',lastKey='';
let cleanReport=null,history=[],settings={exclusions:[],custom_folders:[]},reports={},stamps={},programs=[],progStamp=null,progLoaded=false;
const selected=new Set(Object.keys(catalog).filter(k=>catalog[k].default)),openGr=new Set(),openRes=new Set(),dupSel=new Set(),updSel=new Set(),progSel=new Set();
let hfreed={bytes:0,files:0},dupStage='intro',diskStage='intro',startTab='apps',progSearch='',progSort='name',progDir=1,progFilter='all',dupRoots=new Set(['users']),dupMin=1024,diskDrive='C:';
const status=(m,err)=>{const s=$('cc-status');s.textContent=m;s.classList.toggle('err',!!err)};
/* ---------- comunicação ---------- */
async function api2(prefix,action,body,query){const post=body!==undefined,u=new URL(api,location.href);u.searchParams.set('action',prefix+action);if(!post){u.searchParams.set('network_id',network);u.searchParams.set('gestor_id',gestor);for(const [k,v] of Object.entries(query||{}))u.searchParams.set(k,v)}
 const r=await fetch(u,{method:post?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:post?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:post?JSON.stringify({network_id:network,gestor_id:gestor,...body}):undefined});let j={};try{j=await r.json()}catch(e){}
 if(!r.ok||!j.ok){const err=new Error(j.error||'Falha na solicitação ('+r.status+')');err.active=j.active||null;throw err}return j}
const call=(a,b,q)=>api2('network-pentefino-',a,b,q),pcall=(a,b,q)=>api2('network-program-',a,b,q);
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function setProgress(t){progressText=t;status(t);if(busy){const v=$('cc-view');v.replaceChildren(busyView())}}
async function track(id,P){const end=Date.now()+7200000;let done=null;while(Date.now()<end){done=await P('status',{command_id:id});if(['completed','failed','expired'].includes(done.status))break;setProgress((done.status==='pending'?'Aguardando o gestor receber a solicitação... ':'')+(done.message||'Em execução no computador...'));await wait(3000)}
 if(!done||!['completed','failed','expired'].includes(done.status))throw Error('Operação ainda em andamento. Recarregue a página para retomar.');
 if(done.status!=='completed')throw Error(done.status==='expired'?'O gestor não recebeu a solicitação. Verifique a conexão e tente novamente.':(done.message||'Operação não concluída'));return done}
async function run(start,body,reload,label,P=call){busy=true;busyLabel=label||'Executando no computador';progressText='';render();status('Aguardando gestor...');let ok=false;try{let id;try{id=(await P(start,body)).command_id}catch(e){if(e.active&&e.active.command_id){status('Já existe uma operação em andamento. Acompanhando...');id=e.active.command_id}else throw e}
 activeCommandId=id;window.dispatchEvent(new Event('arc-queue-refresh'));await track(id,P);if(reload)await reload();status('Operação concluída e resultado sincronizado.');ok=true}catch(e){status(e.message,true);window.arcRemoteFailure?.({command_id:activeCommandId,command:label||start,message:e.message})}finally{activeCommandId=0;busy=false;progressText='';render()}return ok}
const startTool=(payload,kind,label)=>run('tool',payload,()=>loadReport(kind),label);
const startClean=(mode,cats,days,label)=>run('start',{mode,categories:cats,age_days:days},refreshList,label||(mode==='scan'?'Verificando o computador':'Limpando'));
const progTask=(payload,label)=>run('start',payload,pload,label,pcall);
async function loadReport(kind){const r=await call('report',undefined,{kind});reports[kind]=r.report;stamps[kind]=r.collected_at}
async function refreshList(){const r=await call('list');cleanReport=r.report;history=r.history||[];settings=r.settings||settings;stamps=Object.assign({},r.index||{});headless=r.headless!==false;online=!!r.online;return r}
async function pload(){const r=await pcall('list');programs=r.programs||[];progStamp=r.collected_at||null;progLoaded=true;progSel.clear();headless=r.headless!==false;online=!!r.online;return r}
/* ---------- diálogo ---------- */
function confirmBox(title,nodes,yes='Confirmar',danger=false,validate){return new Promise(res=>{const d=$('cc-dlg');$('cc-dlg-title').textContent=title;$('cc-dlg-err').textContent='';$('cc-dlg-body').replaceChildren(...[].concat(nodes));const y=$('cc-dlg-yes'),n=$('cc-dlg-no');y.textContent=yes;y.className='cc-pill '+(danger?'red':'blue');
 const end=v=>{d.close();y.onclick=n.onclick=d.oncancel=null;res(v)};y.onclick=()=>{const msg=validate?validate():'';if(msg){$('cc-dlg-err').textContent=msg;return}end(true)};n.onclick=()=>end(false);d.oncancel=()=>end(false);d.showModal()})}
/* ---------- ilustrações e anel de progresso ---------- */
const ill=(g,tone)=>{const d=document.createElement('div');d.innerHTML='<svg viewBox="0 0 320 220" class="ill" aria-hidden="true"><ellipse cx="160" cy="198" rx="118" ry="11" fill="#171c28"/><circle cx="262" cy="56" r="13" fill="#3f78ff" opacity=".75"/><circle cx="52" cy="92" r="8" fill="#6f9bff" opacity=".55"/><circle cx="278" cy="120" r="5" fill="#ffbf02" opacity=".8"/><rect x="62" y="36" width="196" height="126" rx="11" fill="#9ebfff"/><rect x="72" y="46" width="176" height="106" rx="6" fill="'+(tone||'#2c4fa6')+'"/><path d="M38 168h244l-17 20H55z" fill="#d6e4ff"/><text x="160" y="118" fill="#fff" font-family="FontAwesome" font-size="52" text-anchor="middle">'+g+'</text></svg>';return d.firstChild};
function pctOf(t){const m=/(\d+)\/(\d+)/.exec(t||'');return m&&Number(m[2])>0?Math.min(99,Math.round((Number(m[1])-1)/Number(m[2])*100)):null}
function ring(pct){const d=document.createElement('div');d.className='cc-ring'+(pct==null?' ind':'');const C=289;d.innerHTML='<svg viewBox="0 0 104 104"><circle class="bg" cx="52" cy="52" r="46"/><circle class="fg" cx="52" cy="52" r="46" stroke-dasharray="'+C+'" stroke-dashoffset="'+(pct==null?C*0.72:C*(1-pct/100))+'"/></svg><b>'+(pct==null?'':pct+'<small>%</small>')+'</b>';return d}
function busyView(){return h('div',{class:'cc-center'},ring(pctOf(progressText)),h('h2',{},busyLabel||'Trabalhando...'),h('p',{class:'mut',style:'max-width:520px'},progressText||'Aguardando o gestor...'),h('button',{class:'cc-pill red',style:'display:none',onclick:async()=>{if(!await confirmBox('Cancelar operação',[h('p',{},'O gestor derruba imediatamente os processos em andamento no computador e libera a tela. Alterações já realizadas não serão revertidas.')],'Cancelar tudo',true))return;try{await api2('network-pentefino-','cancel',{command_id:activeCommandId||0});activeCommandId=0;busy=false;progressText='';render();status('Operação cancelada. O gestor está encerrando os processos.')}catch(e){status(e.message,true)}}},'Cancelar operação'))}
const intro=(g,title,lead,feats,btn,tone)=>h('div',{class:'cc-intro'},h('div',{class:'tx'},h('h2',{},title),lead&&h('p',{class:'mut'},lead),feats.map(([i,t,s])=>h('div',{class:'ft'},ico(i),h('div',{},h('b',{},t),h('span',{},s)))),h('div',{},btn)),ill(g,tone));
const can=()=>!busy&&headless&&online;
const stamp=k=>stamps[k]?'Última verificação: '+stamps[k]:'';
/* ---------- navegação e estrutura ---------- */
function renderNav(){const nav=$('cc-nav'),fn=$('cc-foot-nav');nav.replaceChildren();fn.replaceChildren();
 const isOn=key=>key==='custom:reg'?(section==='custom'&&tab==='reg'):key===section;
 const go=key=>{if(busy)return;if(key==='custom:reg')key='registry';if(cfg.routes?.[key]){location.assign(cfg.routes[key]);return;}if(key==='custom:reg'){section='custom';tab='reg'}else{if(key===section)return;section=key}render()};
 const mk=([key,label,icon])=>h('button',{type:'button',class:isOn(key)?'on':'',title:label,onclick:()=>go(key)},ico(icon),h('span',{},label));
 for(const g of NAV){const its=g.items.filter(n=>ALLOWED.has(n[0].split(':')[0]));if(!its.length)continue;nav.append(h('div',{class:'cc-nav-h'},g.h));for(const n of its)nav.append(mk(n))}
 if(ALLOWED.has(OPT[0]))fn.append(mk(OPT));
 const a=$('cc-gestor');a.className='cc-gestor '+(online?'on':'off');a.lastChild.textContent=online?(headless?'Gestor conectado':'Gestor conectado (sem modo sem monitor)'):'Gestor desconectado'}
function banner(){const b=$('cc-banner');b.replaceChildren();if(section==='backup'){b.append(h('div',{class:'cc-banner'},'Escolha o destino e as pastas aqui; o gestor Windows envia os arquivos automaticamente e você acompanha tudo nesta tela.'));return}if(!online)b.append(h('div',{class:'cc-banner'},'Gestor desconectado: as solicitações só funcionam com o computador online.'));else if(!headless)b.append(h('div',{class:'cc-banner'},'Ative o modo sem monitor no gestor para analisar ou limpar remotamente.'))}
const VIEWS={};
function render(){if(section!==cfg.start){const next=section==='custom'&&tab==='reg'?'registry':section;if(cfg.routes?.[next]){location.assign(cfg.routes[next]);return;}section=cfg.start;}renderNav();banner();const v=$('cc-view');const keep=[...v.querySelectorAll('[data-keep]')].map(e=>[e.dataset.keep,e.scrollTop]);const key=[section,busy,tab,dupStage,diskStage,startTab].join('|');if(section==='backup'&&BK.dirty&&BK.tab==='setup'&&BK.saving===false&&v.querySelector('.cb-step')&&document.activeElement&&v.contains(document.activeElement)&&/^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName)&&document.activeElement.type!=='checkbox'&&document.activeElement.type!=='radio')return;v.replaceChildren();if(key!==lastKey){v.style.animation='none';void v.offsetWidth;v.style.animation='';lastKey=key}
 if(busy){v.append(busyView());return}
 (VIEWS[section]||VIEWS[cfg.start])(v);for(const [k,t] of keep){const e=v.querySelector('[data-keep="'+k+'"]');if(e)e.scrollTop=t}}
// Trocar de aba nunca consulta a API. Relatórios são lidos apenas mediante clique.
function ensureLoaded(){}
// Cada ferramenta consulta somente pelo seu botão de ação, nunca ao entrar na aba.
const tri=(cb,keys,set)=>{const n=keys.filter(k=>set.has(k)).length;cb.checked=keys.length>0&&n===keys.length;cb.indeterminate=n>0&&n<keys.length;return cb};
<?php require __DIR__.'/pentefino/_shared.php';
$arcPfViewFiles=['health','registry','startup','perf','restore','uninstall','updater','drivers','windows_update','disk','duplicates','cloud','custom','options'];
if(!in_array($ccStart,$arcPfViewFiles,true))throw new RuntimeException('Módulo indisponível.');
if($ccStart==='custom')require __DIR__.'/pentefino/_registry.php';
require __DIR__.'/pentefino/_'.$ccStart.'.php'; ?>
$('cc-burger').onclick=()=>root.classList.toggle('mini');
render();
refreshList().then(()=>{render();status('Escolha a ação deste módulo para consultar o computador.');}).catch(e=>{online=false;render();status(e.message,true);});
document.addEventListener('arc:gestor-presence',e=>{if(Number(e.detail?.gestor_id)!==gestor)return;online=e.detail.state==='online';render();});
})();
</script>
