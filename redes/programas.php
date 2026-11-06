<?php
/* Caminho: redes/programas.php — catálogo e instalação remota de aplicativos confiáveis. */
declare(strict_types=1);
$arcSectionFile='programas';
require __DIR__.'/_base.php';
if (!in_array((string)$teamRole,['owner','administrator'],true)) {http_response_code(403);exit('Permissão administrativa necessária.');}
$rights=arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
if ($teamRole!=='owner' && $rights!==null && !in_array('programas',$rights,true)) {http_response_code(403);exit('Permissão de programas indisponível.');}
require __DIR__.'/_topo.php';
?>
<style>
.arc-install{--pi-bg:var(--bg);--pi-card:var(--surface);--pi-card2:var(--surface-alt);--pi-line:var(--border);--pi-text:var(--text);--pi-muted:#9eb5cd;--pi-ok:#2ea043;--pi-warn:#bf8700;--pi-accent:#3179ea;color:var(--pi-text);background:var(--pi-bg);border:1px solid var(--pi-line);border-radius:16px;padding:clamp(12px,2vw,22px);font-family:system-ui,'Segoe UI',sans-serif;min-height:560px}
.arc-install *{box-sizing:border-box}.arc-install h1{font-size:clamp(20px,2.4vw,27px);font-weight:750;margin:0 0 5px}.arc-install p{color:var(--pi-muted);margin:0;line-height:1.5}.pi-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}.pi-pill{border:1px solid var(--pi-line);border-radius:999px;padding:7px 12px;background:var(--surface-alt);white-space:nowrap;font-size:12px}.pi-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}.pi-search{flex:2 1 220px;min-width:0}.pi-controls input,.pi-controls select{font:inherit;background:#0e1c2e;border:1px solid #455b78;color:var(--pi-text);border-radius:9px;padding:11px 13px;outline:none;max-width:100%}.pi-controls select{flex:1 1 180px}.pi-controls input:focus,.pi-controls select:focus{border-color:#6da8ff}.pi-retry,.pi-bottom button,.pi-modal-foot button{font:inherit;cursor:pointer;border:0;border-radius:9px;padding:11px 18px;background:var(--pi-accent);color:#fff;font-weight:700}.pi-button-muted{background:#354c6a!important}.pi-retry:disabled,.pi-bottom button:disabled,.pi-modal-foot button:disabled{opacity:.45;cursor:not-allowed}.pi-status{margin:10px 0 12px;padding:12px 14px;background:var(--surface-alt);border:1px solid var(--pi-line);border-radius:10px;font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere}.pi-status[data-kind=error]{border-color:#a85b67;color:#ffd0d2}.pi-status[data-kind=success]{border-color:#4ba889;color:#baffdf}.pi-status[hidden]{display:none}.pi-summary{margin:2px 0 14px;font-size:12px;color:#bad1eb}.pi-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:22px;align-items:start}.pi-group h2{font-size:16px;font-weight:750;margin:0 0 10px}.pi-list{display:flex;flex-direction:column;gap:6px}.pi-item{display:flex;align-items:flex-start;gap:10px;padding:2px 0;cursor:pointer;min-width:0}.pi-item input{width:16px;height:16px;accent-color:#4f98ff;flex:none;margin-top:4px}.pi-icon{width:24px;height:24px;border-radius:7px;display:grid;place-items:center;flex:none;overflow:hidden;}.pi-icon img{width:100%;height:100%;object-fit:contain}.pi-name{display:block;font-size:14px;font-weight:650;color:var(--text);line-height:1.2}.pi-meta,.pi-version{display:block;font-size:11px;color:var(--pi-muted);line-height:1.3}.pi-item.pi-installed .pi-name{color:#d9f7e4}.pi-item.pi-outdated .pi-name{color:#ffe1a8}.pi-bottom{display:flex;align-items:center;gap:14px;justify-content:space-between;flex-wrap:wrap;border-top:1px solid var(--pi-line);background:var(--pi-bg);position:sticky;bottom:0;margin-top:20px;padding:14px 0 2px;z-index:4}.pi-hint{font-size:12px;line-height:1.4;max-width:700px;color:var(--pi-muted)}.pi-busy{display:inline-block;width:14px;height:14px;border:2px solid #a3c9ff;border-right-color:transparent;border-radius:50%;animation:pi-spin .7s linear infinite;vertical-align:-2px;margin-right:7px}@keyframes pi-spin{to{transform:rotate(360deg)}}
.pi-modal{position:fixed;inset:0;background:rgba(4,11,20,.72);display:none;align-items:center;justify-content:center;padding:18px;z-index:1200}.pi-modal.show{display:flex}.pi-modal-box{width:min(760px,100%);background:#f3f3f3;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.45);color:#111;overflow:hidden}.pi-modal-head,.pi-modal-foot{padding:12px 16px;background:#f9f9f9;border-bottom:1px solid var(--muted)}.pi-modal-foot{border-top:1px solid var(--muted);border-bottom:0;display:flex;justify-content:flex-end;gap:10px}.pi-modal-title{font-size:14px;font-weight:700}.pi-modal-body{padding:16px}.pi-modal-current{font-size:14px;margin-bottom:10px}.pi-progress-track{height:22px;border:1px solid var(--muted);background:#fff;overflow:hidden}.pi-progress-bar{height:100%;width:0;background:#178a17;transition:width .2s}.pi-modal-grid{margin-top:12px;border:1px solid var(--muted);background:#fff;max-height:250px;overflow:auto}.pi-modal-table{width:100%;border-collapse:collapse;font-size:13px}.pi-modal-table th,.pi-modal-table td{padding:6px 8px;border-bottom:1px solid #ececec;text-align:left}.pi-modal-table th{position:sticky;top:0;background:#f6f6f6}.pi-fine{color:#1b6f1b}.pi-fail{color:#a52727}.pi-wait{color:#666}
@media(max-width:700px){.pi-columns{grid-template-columns:1fr 1fr;gap:16px}.pi-bottom button{width:100%}}
@media(max-width:560px){.arc-install{padding:12px;border-radius:12px}.pi-columns{grid-template-columns:1fr}.pi-bottom{position:static}.pi-bottom button{width:100%}.pi-controls{gap:7px}.pi-item{gap:8px}.pi-name{font-size:13px}}
/* Layout compacto: topo e rodapé fixos no painel; rolagem exclusiva do catálogo. */
.arc-install{display:flex;flex-direction:column;height:calc(100dvh - 195px);min-height:360px;max-height:calc(100dvh - 125px);padding:8px 10px;overflow:hidden;border-radius:10px;gap:6px}
.pi-controls{flex:0 0 auto;display:flex;flex-wrap:nowrap;gap:6px;margin:0;min-height:35px;align-items:stretch}
.pi-controls input,.pi-controls select{height:35px;border-radius:7px;padding:5px 9px;font-size:12px}
.pi-search{flex:1 1 48%;width:1px;min-width:0}.pi-controls select{flex:1 1 27%;width:1px;min-width:0}
.pi-controls .pi-retry{height:35px;padding:5px 10px;border-radius:7px;white-space:nowrap;font-size:12px}
.pi-status{flex:0 0 auto;margin:0;padding:6px 9px;font-size:12px;max-height:68px;overflow:auto}
.pi-columns{flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;align-content:start;overscroll-behavior:contain;scrollbar-width:thin;padding:5px 3px 6px 1px;gap:16px}
.pi-group h2{font-size:13px;margin:0 0 6px}.pi-list{gap:2px}
.pi-item{padding:3px 5px;min-height:30px;align-items:center;border-radius:6px;transition:background .15s;user-select:none}
.pi-item:hover,.pi-item:focus-within{background:rgba(105,157,225,.13)}.pi-item.pi-pending{background:rgba(53,118,210,.12)}
.pi-item input{margin:0;width:14px;height:14px}.pi-icon{height:28px;width:28px;border-radius:7px}
.pi-name{font-size:12px;line-height:1.2}.pi-version{font-size:10px;margin-top:2px}.pi-meta{display:none}
.pi-bottom{flex:0 0 auto;position:static;display:flex;justify-content:flex-end;align-items:center;margin:0;padding:7px 1px 0;border-top:1px solid var(--pi-line);background:var(--pi-bg)}
.pi-bottom button{width:auto;min-width:175px;padding:8px 14px;font-size:12px;border-radius:7px}
@media(max-width:700px){.arc-install{height:calc(100dvh - 160px);min-height:340px}.pi-controls{gap:4px}.pi-controls .pi-retry{padding:5px 7px;font-size:11px}.pi-bottom button{width:auto}.pi-columns{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:460px){.arc-install{height:calc(100dvh - 145px);padding:7px}.pi-controls input,.pi-controls select{font-size:11px;padding:4px}.pi-controls .pi-retry{font-size:10px;padding:4px}.pi-columns{grid-template-columns:1fr 1fr;gap:10px}.pi-name{font-size:11px}.pi-bottom button{width:100%}}
/* Logos reais dos programas e botão de cancelamento */
.pi-icon{padding:3px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;box-shadow:0 0 0 1px rgba(255,255,255,.08)}
.pi-icon img{width:100%;height:100%;object-fit:contain;border-radius:4px;display:block}
.pi-modal-table .pi-icon{width:26px;height:26px;margin-right:8px;vertical-align:middle}
.pi-danger{background:#c9344b!important}.pi-danger:hover:not(:disabled){background:#e0475e!important}
.pi-bottom{gap:8px;justify-content:space-between}
.pi-modal-foot button{min-width:150px}
</style>
<section class="arc-install" id="arc-install-panel" aria-label="Instalação de programas">
 <div class="pi-controls"><input id="pi-search" class="pi-search" type="search" placeholder="Pesquisar aplicativos" aria-label="Pesquisar aplicativos"><select id="pi-category" aria-label="Filtrar categoria"><option value="">Todas as categorias</option></select><button class="pi-retry pi-button-muted" id="pi-clear" type="button">Limpar</button></div>
 <div class="pi-status" id="pi-message" role="status" aria-live="polite" hidden></div>
 <div id="pi-catalog" class="pi-columns"></div>
 <div class="pi-bottom"><span class="pi-summary" id="pi-selection-summary" role="status" aria-live="polite">Nenhum programa selecionado</span><button type="button" id="pi-cancel" class="pi-danger" hidden><i class="fa fa-ban" aria-hidden="true"></i> Cancelar operação</button><button type="button" id="pi-install" disabled><i class="fa fa-download" aria-hidden="true"></i> Instalar e Atualizar</button></div>
</section>
<div class="pi-modal" id="pi-progress-modal" hidden aria-hidden="true">
  <div class="pi-modal-box" role="dialog" aria-modal="true" aria-labelledby="pi-modal-title">
    <div class="pi-modal-head"><div class="pi-modal-title" id="pi-modal-title">Instalação de programas</div></div>
    <div class="pi-modal-body">
      <div class="pi-modal-current" id="pi-modal-current">Preparando operação…</div>
      <div class="pi-progress-track"><div class="pi-progress-bar" id="pi-progress-bar"></div></div>
      <div class="pi-modal-grid"><table class="pi-modal-table"><thead><tr><th>Aplicativo</th><th>Status</th></tr></thead><tbody id="pi-progress-rows"></tbody></table></div>
    </div>
    <div class="pi-modal-foot"><button class="pi-danger" type="button" id="pi-progress-cancel">Cancelar operação</button><button class="pi-button-muted" type="button" id="pi-progress-close" disabled>Fechar</button></div>
  </div>
</div>
<script>
(()=>{'use strict';
const network=<?= (int)$networkId ?>, agent=<?= (int)$selectedAgentId ?>, csrf=<?= json_encode($csrf) ?>, base=<?= json_encode(arc_workspace_base_url()) ?>;
const PROGRESS_PREFIX='@@ARP@@';
const $=id=>document.getElementById(id);
const choices=new Set();
// Catálogo definido pelo servidor, sem inventário do computador e sem consultas automáticas.
const catalog=<?= json_encode(arc_program_install_catalog(),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
<?php
$arcProgramAgent = null;
foreach ($networkAgents as $arcCandidate) {
    if ((int)($arcCandidate['id'] ?? 0) === (int)$selectedAgentId) { $arcProgramAgent = $arcCandidate; break; }
}
$arcProgramOnline = $arcProgramAgent && !empty($arcProgramAgent['last_seen_at']) && strtotime((string)$arcProgramAgent['last_seen_at']) >= time()-120;
$arcProgramHeadless = $arcProgramAgent && (int)($arcProgramAgent['headless_mode'] ?? 0) === 1;
?>
let available=<?= $arcProgramOnline && $arcProgramHeadless ? 'true':'false' ?>,busy=false,cancelled=false;
const normalize=s=>String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().replace(/[^a-z0-9]/g,'');
function isChecked(app){return choices.has(app.id)}
function checkedApps(){return catalog.filter(isChecked)}
function status(message,kind='info'){const node=$('pi-message');node.hidden=!message||kind==='working';node.dataset.kind=kind;node.replaceChildren();if(kind==='working'){const i=document.createElement('span');i.className='pi-busy';node.append(i)}node.append(document.createTextNode(message||''))}
async function api(op,body){const post=body!==undefined,u=new URL(base+'api/api.php',location.href);u.searchParams.set('action','network-program-'+op);if(!post){u.searchParams.set('network_id',network);u.searchParams.set('agent_id',agent)}const response=await fetch(u,{method:post?'POST':'GET',cache:'no-store',credentials:'same-origin',headers:post?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:post?JSON.stringify({network_id:network,agent_id:agent,...body}):undefined});let data={};try{data=await response.json()}catch{}if(!response.ok||!data.ok)throw new Error(data.error||'Falha na comunicação com a plataforma.');return data}
const cell=(tag,className,txt)=>{const el=document.createElement(tag);if(className)el.className=className;if(txt!==undefined)el.textContent=txt;return el};
function fallbackSrc(app){return base+'api/program-logo.php?id='+encodeURIComponent(app.id)}
function iconSrc(app){return app.logo?base+app.logo:fallbackSrc(app)}
function logoImg(app){const img=document.createElement('img');img.alt='';img.decoding='async';img.addEventListener('error',()=>{const f=fallbackSrc(app);if(img.dataset.fb!=='1'){img.dataset.fb='1';img.src=f}},{once:false});img.src=iconSrc(app);return img}
function update(){const checked=checkedApps();$('pi-install').disabled=busy||!available||!checked.length||checked.length>20;$('pi-install').title=checked.length?`${checked.length} selecionado(s) · máximo 20 por operação`:'Selecione pelo menos um programa';$('pi-clear').disabled=busy||!catalog.length;$('pi-selection-summary').textContent=checked.length+' programa(s) selecionado(s) · escolha manual';if(checked.length>20)status('Selecione no máximo 20 programas por operação.','error')}

function render(){const out=$('pi-catalog');out.replaceChildren();const q=normalize($('pi-search').value.trim()),category=$('pi-category').value;const groups=new Map();for(const app of catalog){if(category&&category!==app.category)continue;if(q&&!normalize(`${app.name} ${app.category}`).includes(q))continue;if(!groups.has(app.category))groups.set(app.category,[]);groups.get(app.category).push(app)}let shown=0;for(const [label,apps] of groups){shown+=apps.length;const section=cell('section','pi-group'),title=cell('h2','',label),list=cell('div','pi-list');section.append(title);for(const app of apps){const checked=isChecked(app);const row=cell('label','pi-item'+(checked?' pi-pending':'')),box=cell('input');box.type='checkbox';box.checked=checked;box.disabled=busy;box.addEventListener('change',()=>{box.checked?choices.add(app.id):choices.delete(app.id);update();render()});const icon=cell('span','pi-icon');const img=logoImg(app);img.loading='lazy';icon.append(img);const text=cell('span','');const name=cell('span','pi-name',app.name);text.append(name);row.append(box,icon,text);list.append(row)}section.append(list);out.append(section)}if(!shown)out.append(cell('p','',catalog.length?'Nenhum programa corresponde ao filtro.':'Catálogo indisponível.'));update()}
function loadCatalog(){
 const select=$('pi-category');
 for(const category of [...new Set(catalog.map(app=>app.category))]){
  const option=cell('option','',category);option.value=category;select.append(option);
 }
 render();
}
function modal(show){$('pi-progress-modal').classList.toggle('show',!!show);$('pi-progress-modal').setAttribute('aria-hidden',show?'false':'true')}
function seedProgress(items){const body=$('pi-progress-rows');body.replaceChildren();for(const app of items){const tr=document.createElement('tr');tr.dataset.id=app.id;const first=cell('td',''),logo=cell('span','pi-icon'),pic=logoImg(app);logo.append(pic);first.append(logo,document.createTextNode(app.name));tr.append(first,cell('td','pi-wait','Aguardando'));body.append(tr)}$('pi-progress-bar').style.width='0%';$('pi-modal-current').textContent='Preparando operação…';$('pi-progress-close').disabled=true;$('pi-progress-cancel').hidden=false;$('pi-progress-cancel').disabled=false;modal(true)}
function parseProgress(message){if(typeof message!=='string'||!message.startsWith(PROGRESS_PREFIX))return null;try{return JSON.parse(message.slice(PROGRESS_PREFIX.length))}catch{return null}}
function paintProgress(packet){if(!packet)return;const total=Math.max(1,Number(packet.t)||1),done=Math.max(0,Math.min(total,Number(packet.i)||0));$('pi-progress-bar').style.width=(done/total*100).toFixed(0)+'%';$('pi-modal-current').textContent=((packet.c||'')+(packet.s?' — '+packet.s:''))||packet.m||'Processando…';const rows=$('pi-progress-rows').querySelectorAll('tr');(packet.r||[]).forEach((row,idx)=>{if(!rows[idx])return;const nameCell=rows[idx].children[0],statusCell=rows[idx].children[1];const value=typeof row==='string'?row:(row?.s||'');if(nameCell&&row?.n)nameCell.textContent=row.n;if(statusCell){statusCell.textContent=value;statusCell.className=(/Concl|OK/i.test(value)?'pi-fine':/Falh|Tempo/i.test(value)?'pi-fail':'pi-wait')}})}
async function poll(id,refresh=false,showModal=false){busy=true;cancelled=false;render();try{for(let n=0;n<(refresh?240:2500);n++){if(cancelled)return;const u=new URL(base+'api/api.php',location.href);u.searchParams.set('action','network-program-status');u.searchParams.set('network_id',network);u.searchParams.set('agent_id',agent);u.searchParams.set('command_id',id);const res=await fetch(u,{cache:'no-store',credentials:'same-origin'}),r=await res.json();if(!res.ok||!r.ok)throw new Error(r.error||'Falha ao acompanhar a operação.');const packet=parseProgress(r.message||'');if(packet){paintProgress(packet);if(showModal)modal(true)}if(['completed','failed','expired'].includes(r.status)){if(showModal){if(packet)paintProgress(packet);$('pi-progress-close').disabled=false;$('pi-progress-cancel').hidden=true}$('pi-progress-close').onclick=()=>modal(false);status((packet?packet.m:r.message)||'Operação encerrada.',r.status==='completed'?'success':'error');if(r.status!=='completed')window.arcRemoteFailure?.({command_id:id,command:refresh?'program_inventory':'program_install',message:(packet?packet.m:r.message)||'Instalação não concluída'});if(r.status==='completed'&&!refresh){choices.clear();render()}return}if(showModal){$('pi-modal-current').textContent=(packet&&packet.m)||(r.status==='pending'?'Aguardando o agente…':'Instalando ou atualizando…')}else if(n>4&&n%10===0){status('Verificando o estado do inventário…','working')}await new Promise(resolve=>setTimeout(resolve,3000))}throw new Error('A operação não confirmou a conclusão dentro do prazo de acompanhamento. Verifique o estado do agente ou atualize a lista; não foi considerada concluída.')}catch(e){status(e.message,'error');window.arcRemoteFailure?.({command_id:id,command:refresh?'program_inventory':'program_install',message:e.message});$('pi-progress-close').disabled=false;$('pi-progress-close').onclick=()=>modal(false)}finally{busy=false;render()}}
async function cancelAll(){const a=$('pi-cancel'),b=$('pi-progress-cancel');a.disabled=b.disabled=true;status('Cancelando todas as operações...','working');try{const res=await fetch(base+'api/api.php?action=network-operation-cancel',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent})});let d={};try{d=await res.json()}catch{}if(!res.ok||!d.ok)throw new Error(d.error||'Falha ao cancelar.');cancelled=true;busy=false;modal(false);status(d.cancelled?'Operação cancelada. O agente está encerrando os processos no computador.':'Nenhuma operação em andamento.','success');choices.clear()}catch(e){status(e.message,'error')}finally{a.disabled=false;b.disabled=false;render()}}
$('pi-cancel').addEventListener('click',cancelAll);$('pi-progress-cancel').addEventListener('click',cancelAll);
$('pi-search').addEventListener('input',render);$('pi-category').addEventListener('change',render);$('pi-clear').addEventListener('click',()=>{choices.clear();status('Seleção limpa.');render()});$('pi-progress-close').addEventListener('click',()=>modal(false));
$('pi-install').addEventListener('click',async()=>{const picked=checkedApps();if(!picked.length||busy||!available)return;if(picked.length>20){status('Selecione no máximo 20 programas por operação.','error');return}busy=true;render();status('Enviando a seleção à fila...','working');try{const r=await api('start',{mode:'install',package_ids:picked.map(app=>app.id)});choices.clear();status('Solicitação adicionada à fila global. Acompanhe a bolinha lateral.','success');window.dispatchEvent(new Event('arc-queue-refresh'));busy=false;render()}catch(e){busy=false;status(e.message,'error');window.arcRemoteFailure?.({command:'program_install',message:e.message});render()}});
loadCatalog();if(!available)status('O agente precisa estar conectado e com o modo sem monitor ativo. Após reconectá-lo, reabra esta página.','info');
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
