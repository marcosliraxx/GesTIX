<?php
/* Caminho: redes/atualizacoes.php */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
$arcSectionFile='atualizacoes';
require __DIR__.'/_base.php';
require __DIR__.'/_topo.php';
$manage=in_array((string)$teamRole,['owner','administrator','system_admin'],true);
?>
<link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url())?>assets/css/arc-update-pages.css?v=<?=arc_h(arc_app_version())?>"><section class="arc-up" id="arc-up" data-network="<?=(int)$networkId?>" data-gestor="<?=(int)$selectedGestorId?>" data-csrf="<?=arc_h($csrf)?>" data-base="<?=arc_h(arc_workspace_base_url())?>" data-manage="<?=$manage?'1':'0'?>">
<section class="arc-up-view arc-up-check-view" id="arc-up-check-view"  aria-label="Verificar e atualizar o AGestor">
<div class="arc-up-circle-zone"><div class="arc-up-orbit" aria-hidden="true"></div><button class="arc-up-circle" id="arc-up-check" type="button" <?=$manage?'':'disabled'?> aria-label="Verificar atualização"><svg class="arc-up-ring" viewBox="0 0 240 240" aria-hidden="true"><circle class="track" cx="120" cy="120" r="100"/><circle class="progress" id="arc-up-progress" cx="120" cy="120" r="100" pathLength="100"/></svg><span class="arc-up-circle-copy"><span class="arc-up-circle-icon" id="arc-up-icon">↻</span><strong class="arc-up-circle-value" id="arc-up-percent">VERIFICAR</strong><small class="arc-up-circle-desc" id="arc-up-desc">Clique para buscar atualização</small></span></button><div class="arc-up-phases" id="arc-up-phases" hidden><span class="arc-up-phase">◌ Verificando</span><span class="arc-up-phase">↓ Recebendo</span><span class="arc-up-phase">◇ Validando</span><span class="arc-up-phase">⚙ Preparando</span><span class="arc-up-phase">↻ Reiniciando</span><span class="arc-up-phase">✓ Confirmando</span></div></div>
<p id="arc-up-state" class="arc-up-stage" role="status" aria-live="polite">Consultando publicação…</p><div class="arc-up-actions"><button type="button" class="primary" id="arc-up-update" hidden>↓ Atualizar</button><button type="button" id="arc-up-force" hidden>↻ Reinstalar arquivos</button><button type="button" id="arc-up-refresh" title="Atualizar informações"><i class="fa fa-refresh"></i> Recarregar</button></div><p class="arc-up-note" id="arc-up-notes"></p>
</section>
</section>
<script>
/* Caminho: redes/atualizacoes.php — controle da publicação e acompanhamento do comando selecionado. */
(()=>{'use strict';
 const root=document.getElementById('arc-up');if(!root)return;
 const $=id=>document.getElementById('arc-up-'+id),base=root.dataset.base,network=Number(root.dataset.network),csrf=root.dataset.csrf;
 const selector=document.getElementById('arc-gestor-target');
 const gestorId=()=>Number(selector?.value||root.dataset.gestor||0);
 let manage=root.dataset.manage==='1',active=false,checking=false,versionBusy=false,monitoring=false,commandId=0,timer=0,generation=0;
 const message=text=>{$('state').textContent=String(text||'')};
 const labels={pending:'Aguardando recebimento',running:'Atualizando',completed:'Concluída',failed:'Falha',expired:'Expirada',cancelled:'Cancelada'};
 async function api(action,body,query={}){
  const url=new URL(base+'api/api.php',location.href);url.search=new URLSearchParams({action,network_id:network,gestor_id:gestorId(),...query});
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
  try{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...body,network_id:network,gestor_id:gestorId(),_csrf:csrf})}:{})});const data=await response.json().catch(()=>{throw Error('O servidor retornou uma resposta inválida.');});if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível consultar o gestor.');return data;}
  catch(error){if(error.name==='AbortError')throw Error('Tempo esgotado ao consultar o servidor. Use Recarregar para continuar.');throw error;}
  finally{clearTimeout(timeout);}
 }
 function paint(mode,label,percent){
  root.classList.toggle('arc-up-checking',mode==='checking');root.classList.toggle('arc-up-working',mode==='working');
  $('phases').hidden=mode!=='working';$('check').disabled=!manage||active||checking;
  $('refresh').disabled=checking;
  $('percent').textContent=mode==='working'?(Number.isFinite(percent)?Math.min(99,Math.max(0,percent))+'%':'EM ANDAMENTO'):label;
  $('desc').textContent=mode==='working'?'Acompanhando o gestor':mode==='checking'?'Consultando publicação':'Clique para verificar a publicação';
  $('icon').textContent=mode==='working'?'':'↻';$('progress').style.strokeDashoffset=Number.isFinite(percent)?String(100-Math.min(100,Math.max(0,percent))):'80';
  if(mode==='working'){$('update').hidden=true;$('force').hidden=true;const phase=Number.isFinite(percent)?Math.min(5,Math.floor(Math.max(0,percent)/17)):0;$('phases').querySelectorAll('span').forEach((node,i)=>{node.classList.toggle('active',i===phase);node.classList.toggle('done',i<phase)});}
 }
 async function versions(announce=true){
  if(versionBusy)return;versionBusy=true;
  try{const data=await api('network-gestor-versions');manage=data.can_update===true;const gestor=(data.gestores||[]).find(x=>Number(x.id)===gestorId());$('notes').textContent=String(data.release_notes||'');
   if(!active){const available=!!gestor?.outdated,online=!!gestor?.online;
    paint('idle',!gestor?'SELECIONE':!online?'OFFLINE':available?'DISPONÍVEL':'EM DIA');
    $('update').hidden=!manage||!online||!available||!!gestor?.requires_reinstall;$('force').hidden=!manage||!online;
    if(announce)message(!gestor?'Selecione um computador aprovado.':!online?'O gestor está sem comunicação. Conecte-o antes de atualizar.':gestor.requires_reinstall?'Reinstalação necessária. Use Reinstalar arquivos ou baixe o instalador no ícone power.':available?'Atualização disponível: '+data.published_version:'Instalada: '+(gestor.version||'não informada')+' · Publicada: '+(data.published_version||'não informada'));
   }
  }finally{versionBusy=false;}
 }
 async function check(){if(active||checking)return;checking=true;paint('checking','VERIFICANDO');message('Consultando a versão publicada…');try{await versions();}catch(error){paint('idle','TENTAR NOVAMENTE');message(error.message);}finally{checking=false;if(!active)$('check').disabled=!manage;$('refresh').disabled=false;}}
 async function monitor(token){
  if(!active||token!==generation||monitoring)return;monitoring=true;clearTimeout(timer);
  try{const data=await api('network-command-status',null,{command_id:commandId});if(token!==generation)return;const row=(data.commands||[]).find(x=>Number(x.id)===commandId&&Number(x.gestor_id)===gestorId());
   if(!row){message('Aguardando o registro da atualização no servidor…');}
   else{const status=String(row.status),detail=String(row.result_text||''),percent=detail.match(/(\d{1,3})\s*%/);message((labels[status]||status)+(detail?' · '+detail:''));if(percent)paint('working','',Number(percent[1]));
    if(['completed','failed','expired','cancelled'].includes(status)){active=false;clearTimeout(timer);paint('idle',status==='completed'?'CONCLUÍDO':'VERIFICAR',status==='completed'?100:undefined);await versions(false);message((labels[status]||status)+(detail?' · '+detail:''));return;}
   }
  }catch(error){if(token!==generation)return;message(error.message+' O acompanhamento será retomado automaticamente.');}
  finally{monitoring=false;}
  if(active&&token===generation)timer=setTimeout(()=>monitor(token),2000);
 }
 async function update(force){
  if(active||checking||!manage)return;if(!gestorId()){message('Selecione um computador aprovado.');return;}
  if(!window.confirm(force?'Reinstalar os arquivos do gestor selecionado, preservando sua configuração?':'Atualizar o gestor selecionado?'))return;
  active=true;const token=++generation;paint('working','');message('Solicitando atualização ao gestor…');
  try{const data=await api('network-command',{command:'gestor_update',force_update:force});commandId=Number(data.command_id);if(!commandId)throw Error('O servidor não identificou a solicitação.');document.dispatchEvent(new CustomEvent('arc:command-queued',{detail:{command:'gestor_update',command_id:commandId,gestor_id:gestorId()}}));window.dispatchEvent(new Event('arc-queue-refresh'));await monitor(token);}
  catch(error){active=false;paint('idle','TENTAR NOVAMENTE');await versions(false).catch(()=>{});message(error.message);}
 }
 $('check').addEventListener('click',check);$('refresh').addEventListener('click',()=>active?monitor(generation):check());
 $('update').addEventListener('click',()=>update(false));$('force').addEventListener('click',()=>update(true));
 window.addEventListener('arc-tool-refresh',event=>{if(event.detail?.tool==='atualizacoes'&&!active&&!checking)versions(false).catch(()=>{});});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&active){clearTimeout(timer);monitor(generation);}});
 window.addEventListener('pagehide',()=>{generation++;clearTimeout(timer);});
 check();
})();

</script>
<?php require __DIR__.'/_rodape.php'; ?>
