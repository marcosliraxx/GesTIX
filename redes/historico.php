<?php
/* Caminho: redes/historico.php */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
$arcSectionFile='historico';
require __DIR__.'/_base.php';
require __DIR__.'/_topo.php';
$manage=in_array((string)$teamRole,['owner','administrator','system_admin'],true);
?>
<link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url())?>assets/css/arc-update-pages.css?v=<?=arc_h(arc_app_version())?>"><section class="arc-up" id="arc-up" data-network="<?=(int)$networkId?>" data-gestor="<?=(int)$selectedGestorId?>" data-base="<?=arc_h(arc_workspace_base_url())?>">
<section class="arc-up-view arc-up-table-view" id="arc-up-history-view"  aria-label="Histórico das atualizações"><div class="arc-up-history-toolbar"><input type="search" id="arc-up-search" placeholder="Buscar atualização, versão ou descrição…" aria-label="Buscar no histórico"><div class="arc-up-filter" id="arc-up-filter"><button type="button" data-filter="all" class="active">Todas</button><button type="button" data-filter="complete">Concluídas</button><button type="button" data-filter="failed">Falhas</button><button type="button" data-filter="cancelled">Canceladas</button></div><span class="arc-up-count" id="arc-up-count">0 registros</span><button type="button" id="arc-up-history-refresh" title="Atualizar histórico" aria-label="Atualizar histórico" class="arc-up-result">↻</button></div><div class="arc-up-grid" tabindex="0"><table class="arc-up-table"><thead><tr><th><button data-sort="created">Data e hora</button></th><th><button data-sort="from">De</button></th><th><button data-sort="to">Para</button></th><th><button data-sort="mode">Modo</button></th><th><button data-sort="status">Resultado</button></th><th><button data-sort="duration">Duração</button></th><th><button data-sort="message">Descrição</button></th></tr></thead><tbody id="arc-up-rows"></tbody></table><p class="arc-up-empty" id="arc-up-empty">Consultando histórico…</p></div></section>
<dialog class="arc-up-dialog" id="arc-up-details" aria-labelledby="arc-up-details-title"><header><h2 id="arc-up-details-title">Detalhes da atualização</h2><button id="arc-up-close-details" type="button" aria-label="Fechar">×</button></header><div class="arc-up-dialog-content"><dl id="arc-up-detail-fields"></dl><h3>Descrição e retorno do computador</h3><pre id="arc-up-detail-message"></pre></div></dialog>
</section>
<script>
(()=>{'use strict';
const root=document.getElementById('arc-up');if(!root)return;
const $=id=>document.getElementById('arc-up-'+id),base=root.dataset.base,network=Number(root.dataset.network),selector={value:root.dataset.gestor};
let records=[],filter='all',sort='created',direction=-1,focusRow=null;
const labels={pending:'Aguardando',running:'Em execução',completed:'Concluída',failed:'Falha',expired:'Expirada',cancelled:'Cancelada'};
const statusGroup=s=>s==='completed'?'complete':['failed','expired'].includes(s)?'failed':s==='cancelled'?'cancelled':'other';
const formDate=x=>x?new Date(String(x).replace(' ','T')).toLocaleString('pt-BR'):'—';
const seconds=(a,b)=>{const x=Date.parse(String(a||'').replace(' ','T')),y=Date.parse(String(b||'').replace(' ','T'));return Number.isFinite(x)&&Number.isFinite(y)?Math.max(0,Math.round((y-x)/1000)):null};
const duration=r=>{let s=seconds(r.created_at,r.completed_at);return s===null?'—':Math.floor(s/60)+'m '+s%60+'s'};
async function api(action,opts){const url=base+'api/api.php?action='+encodeURIComponent(action)+'&network_id='+network+(!opts?'&gestor_id='+Number(selector.value):'');const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...opts});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível consultar.');return data;}
async function history(){const data=await api('network-gestor-update-history');records=(data.commands||[]).map(r=>({...r,from:'—',to:'—',mode:r.command_name==='gestor_update_check'?'verificação':'normal',message:r.result_text||''}));render();}
function render(){const q=$('search').value.toLocaleLowerCase('pt-BR').trim();const visible=records.filter(r=>(filter==='all'||statusGroup(r.status)===filter)&&(!q||[r.created_at,r.status,r.result_text,r.command_name,r.id].join(' ').toLocaleLowerCase('pt-BR').includes(q)));visible.sort((a,b)=>{let x=sort==='duration'?seconds(a.created_at,a.completed_at)??-1:sort==='created'?Date.parse(String(a.created_at).replace(' ','T')):a[sort]??'',y=sort==='duration'?seconds(b.created_at,b.completed_at)??-1:sort==='created'?Date.parse(String(b.created_at).replace(' ','T')):b[sort]??'';return direction*(typeof x==='number'&&typeof y==='number'?x-y:String(x).localeCompare(String(y),'pt-BR',{numeric:true}));});const tbody=$('rows');tbody.replaceChildren();for(const r of visible){const tr=document.createElement('tr');tr.tabIndex=0;tr.dataset.id=r.id;const values=[formDate(r.created_at),r.from,r.to,r.mode,labels[r.status]||r.status,duration(r),r.message||'—'];values.forEach((value,i)=>{const td=document.createElement('td');if(i===4){const pill=document.createElement('span');pill.className='arc-up-result '+statusGroup(r.status);pill.textContent=value;td.append(pill)}else td.textContent=value;td.title=value;tr.append(td)});const open=()=>details(r,tr);tr.addEventListener('click',open);tr.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open()}});tbody.append(tr);}$('count').textContent=visible.length+' de '+records.length+' registros';$('empty').hidden=visible.length!==0;$('empty').textContent=records.length?'Nenhum registro encontrado para o filtro.':'Nenhum registro de atualização enviado pela plataforma.';$('filter').querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.filter===filter));}
function details(r,row){focusRow=row;const fields=$('detail-fields');fields.replaceChildren();[['Identificação','#'+r.id],['Data e hora',formDate(r.created_at)],['De',r.from],['Para',r.to],['Operação',r.command_name==='gestor_update_check'?'Verificação':'Atualização'],['Resultado',labels[r.status]||r.status],['Duração',duration(r)],['Finalizado',formDate(r.completed_at)]].forEach(([title,value])=>{const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=title;dd.textContent=value;fields.append(dt,dd)});$('detail-message').textContent=r.message||'Sem detalhes retornados pelo agente.';$('details').showModal();}
$('history-refresh').addEventListener('click',()=>history().catch(e=>$('empty').textContent=e.message));
$('search').addEventListener('input',render);
$('filter').querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.filter;render()}));
root.querySelectorAll('[data-sort]').forEach(b=>b.addEventListener('click',()=>{if(sort===b.dataset.sort)direction=-direction;else{sort=b.dataset.sort;direction=sort==='created'?-1:1}render()}));
$('close-details').addEventListener('click',()=>$('details').close());
$('details').addEventListener('close',()=>focusRow?.focus());
history().catch(e=>$('empty').textContent=e.message);
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
