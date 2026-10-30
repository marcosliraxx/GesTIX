<?php
require dirname(__DIR__).'/includes/header.php';
require dirname(__DIR__).'/includes/sidebar.php';
?>
<?php $arcRedesStylesVersion = (string)(filemtime(dirname(__DIR__).'/assets/css/redes.css') ?: 1); ?>
<link rel="stylesheet" href="<?= arc_h(arc_workspace_base_url()) ?>assets/css/redes.css?v=<?= arc_h($arcRedesStylesVersion) ?>">
<?php
$arcAlertRows = array_values(array_filter($remoteEvents, static fn($event) => (string)($event['event_type'] ?? '') === 'alert'));
$arcAlertFeed = [];
foreach (array_slice($arcAlertRows, 0, 75) as $event) {
 $decoded = json_decode((string)($event['payload_json'] ?? ''), true);
 if (!is_array($decoded)) $decoded = [];
 $kind = strtolower((string)($decoded['kind'] ?? $decoded['type'] ?? $decoded['event'] ?? ''));
 $latency = isset($decoded['latency_ms']) && is_numeric($decoded['latency_ms']) ? (float)$decoded['latency_ms'] : null;
 $threshold = isset($decoded['threshold_ms']) && is_numeric($decoded['threshold_ms']) ? (float)$decoded['threshold_ms'] : null;
 $gateway = trim((string)($decoded['gateway'] ?? ''));
 $fields=[];
 $title='Alerta de monitoramento'; $description='O agente registrou uma ocorrência que precisa ser verificada.';
 $suggestion='Verifique a conectividade e os indicadores de monitoramento deste agente.';
 $severity='info'; $icon='fa-info-circle';
 switch ($kind) {
  case 'gateway_unreachable':
   $title='Gateway sem resposta'; $description='O agente não conseguiu obter resposta do gateway da rede durante a verificação.';
   $suggestion='Verifique o roteador, a conexão do computador à rede e a disponibilidade do gateway.';
   $severity='critical'; $icon='fa-exclamation-triangle'; break;
  case 'high_latency':
   $title='Latência elevada'; $description='O tempo de resposta observado ultrapassou o limite configurado para este agente.';
   $suggestion='Confira a utilização da rede e repita a medição para identificar se a lentidão persiste.';
   $severity='warning'; $icon='fa-clock-o'; break;
  case 'packet_loss': case 'high_packet_loss':
   $title='Perda de pacotes detectada'; $description='Parte das tentativas de comunicação não recebeu resposta.';
   $suggestion='Confira o Wi-Fi, o cabeamento e a estabilidade do gateway.';
   $severity='warning'; $icon='fa-exclamation-circle'; break;
  case 'agent_offline':
   $title='Agente desconectado'; $description='O agente deixou de comunicar-se com a plataforma no intervalo esperado.';
   $suggestion='Verifique se o Windows e o serviço do agente estão ligados e conectados.';
   $severity='warning'; $icon='fa-desktop'; break;
  case 'connection_restored': case 'agent_online':
   $title='Conexão restabelecida'; $description='A comunicação voltou a funcionar conforme o registro recebido.';
   $suggestion='Acompanhe o monitoramento para confirmar a estabilidade.';
   $severity='success'; $icon='fa-check-circle'; break;
  default:
   $message=trim((string)($decoded['message'] ?? $decoded['description'] ?? $decoded['text'] ?? ''));
   if ($message!=='' && !preg_match('/^[\[{]/', $message)) $description=mb_substr($message,0,450);
 }
 if ($gateway!=='') $fields[]=['label'=>'Gateway','value'=>mb_substr($gateway,0,120)];
 if ($latency!==null) $fields[]=['label'=>'Latência medida','value'=>number_format($latency,1,',','.').' ms'];
 if ($threshold!==null) $fields[]=['label'=>'Limite configurado','value'=>number_format($threshold,1,',','.').' ms'];
 foreach (['ip'=>'Endereço IP','loss_percent'=>'Perda de pacotes (%)','status'=>'Situação'] as $field=>$label) {
  if (isset($decoded[$field]) && is_scalar($decoded[$field])) $fields[]=['label'=>$label,'value'=>mb_substr((string)$decoded[$field],0,120)];
 }
 $arcAlertFeed[]=['id'=>(string)($event['id']??''),'title'=>$title,'description'=>$description,'suggestion'=>$suggestion,'severity'=>$severity,'icon'=>$icon,'fields'=>$fields,'when'=>(string)($event['captured_at']??''),'machine'=>(string)($event['machine_name']??''),'kind'=>$kind];
}
?>
<style>
.arc-bell-wrap{position:relative}.arc-alert-bell{position:relative}.arc-alert-count{position:absolute;right:-4px;top:-5px;background:#d43b58;color:var(--text);border-radius:11px;min-width:16px;padding:2px 4px;font-size:10px;line-height:1.1}
.arc-alert-list{position:fixed;z-index:2147483000;right:16px;top:70px;width:min(370px,85vw);max-height:340px;overflow-y:auto;border:1px solid var(--border);background:var(--surface-alt);box-shadow:0 16px 34px #0009;border-radius:11px;padding:10px}
.arc-alert-list[hidden]{display:none}.arc-alert-item:hover{background:var(--surface-alt)}.arc-alert-item small{display:block;color:var(--muted);margin-top:5px}.arc-alert-dialog{max-width:min(550px,95vw);width:100%;background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:13px;padding:0;overflow:hidden}.arc-alert-detail-head{display:flex;gap:14px;align-items:flex-start;padding:22px 24px;border-bottom:1px solid var(--border)}.arc-alert-symbol{display:grid;place-items:center;width:46px;height:46px;border-radius:12px;background:var(--surface-alt);color:var(--primary);font-size:21px;flex-shrink:0}.arc-alert-symbol.warning{background:var(--surface-alt);color:#ffcb6b}.arc-alert-symbol.critical{background:var(--surface-alt);color:var(--text)}.arc-alert-symbol.success{background:var(--surface-alt);color:#6ee7b7}.arc-alert-dialog h3{margin:3px 0 5px;font-size:19px}.arc-alert-detail-body{padding:18px 24px 22px;max-height:65vh;overflow:auto}.arc-alert-description{line-height:1.6;color:var(--text);margin:0 0 16px}.arc-alert-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.arc-alert-fact{background:var(--surface-alt);border:1px solid var(--border);border-radius:8px;padding:10px;min-width:0}.arc-alert-fact small{display:block;color:var(--muted);margin-bottom:4px}.arc-alert-fact strong{overflow-wrap:anywhere;font-size:13px}.arc-alert-guidance{margin-top:15px;padding:13px;border-left:3px solid #54c6e7;background:var(--surface-alt);border-radius:6px;line-height:1.55}.arc-alert-detail-foot{display:flex;justify-content:flex-end;padding:12px 24px;background:var(--surface-alt);border-top:1px solid var(--border)}.arc-alert-item{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid var(--border);padding:10px;color:var(--text);background:transparent;cursor:pointer;border-radius:7px;margin:3px 0}.arc-alert-item .arc-alert-item-icon{display:inline-block;width:24px;color:var(--primary)}.arc-alert-item .arc-alert-item-icon.warning{color:#ffca79}.arc-alert-item .arc-alert-item-icon.critical{color:var(--text)}.arc-alert-item .arc-alert-item-icon.success{color:#7de7ba}.arc-alert-item.is-read{opacity:.7}
.arc-agent-update-confirm{border:none;padding:0;background:transparent}.arc-agent-update-confirm::backdrop{background:rgba(3,10,18,.72);backdrop-filter:blur(4px)}.arc-network-confirm::backdrop{background:rgba(3,10,18,.72);backdrop-filter:blur(4px)}.arc-agent-update-card{max-width:520px;width:min(92vw,520px);background:linear-gradient(180deg,var(--surface-alt),var(--surface));border:1px solid #53b4ff;border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.45);padding:22px}.arc-agent-update-icon{width:58px;height:58px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:26px;color:#7ef6d5;background:radial-gradient(circle at 30% 30%,#7ef6d5,#255d7e);border:1px solid #7ef6d5;margin-bottom:12px}.arc-agent-update-eyebrow{display:inline-block;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}.arc-agent-update-copy h2{margin:0 0 8px;font-size:22px;color:var(--text)}.arc-agent-update-copy strong{display:block;color:var(--text);font-size:16px;margin-bottom:8px}.arc-agent-update-copy p{margin:0;color:var(--text);line-height:1.55}.arc-agent-update-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}.arc-agent-update-actions .button{display:inline-flex;align-items:center;gap:8px}.arc-network-confirm{border:1px solid #53b4ff;padding:20px;background:linear-gradient(180deg,var(--surface-alt),var(--surface));max-width:520px;width:min(92vw,520px);border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.45)}.arc-confirm-head{display:flex;align-items:center;gap:12px;color:var(--text)}.arc-confirm-head i{width:38px;height:38px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#ffc966;color:#ffc966}.arc-network-confirm p{color:var(--text);line-height:1.55;margin:14px 0 18px}.arc-confirm-actions{display:flex;justify-content:flex-end;gap:10px}@media(max-width:520px){.arc-alert-facts{grid-template-columns:1fr}}
</style>
<script type="application/json" id="arc-alert-payload"><?=json_encode($arcAlertFeed,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<main class="app-main arc-redes-page"><div class="container-wide arc-redes-shell" style=" max-width: none;">
<header class="arc-redes-topo arc-redes-identity-bar arc-redes-operational-header" aria-label="Controle da rede">
 <form class="arc-redes-header-controls" id="arc-agent-form" method="get" action="" aria-label="Controle do agente">
  <input type="hidden" name="network" value="<?= (int)$networkId ?>">
  <div class="arc-redes-header-left">
   <?php
    $arcAgentOnline=false;
    foreach($networkAgents as $a){if((int)$a['id']===$selectedAgentId){$arcAgentOnline=($a['status']??'')==='approved' && !empty($a['last_seen_at']) && strtotime((string)$a['last_seen_at'])>=time()-12;break;}}
   ?>
   <label class="arc-agent-select-wrap <?= $arcAgentOnline?'is-online':'is-offline' ?>" for="arc-agent-target" title="Escolher agente Windows">
    <span id="arc-connection-status" class="arc-agent-state <?= $arcAgentOnline?'is-online':'is-offline' ?>" role="status" title="<?= $arcAgentOnline?'Agente online':'Agente offline' ?>" aria-label="<?= $arcAgentOnline?'Agente online':'Agente offline' ?>"></span><i class="fa fa-desktop arc-agent-picker-icon" aria-hidden="true"></i>
    <select id="arc-agent-target" name="agent" aria-label="Selecionar agente Windows">
     <option value="">Selecionar agente</option>
     <?php foreach($networkAgents as $a): if(($a['status']??'')!=='approved')continue;
      $arcOptionOnline=!empty($a['last_seen_at']) && strtotime((string)$a['last_seen_at'])>=time()-12;
     ?>
      <option value="<?= (int)$a['id'] ?>" class="<?= $arcOptionOnline?'arc-option-online':'arc-option-offline' ?>" data-headless="<?= (int)($a['headless_mode']??0)===1?'1':'0' ?>" <?= $selectedAgentId===(int)$a['id']?'selected':'' ?>><?= arc_h((string)($a['machine_name']??'Agente')) ?><?= (int)($a['headless_mode']??0)===1?' (sem monitor)':'' ?></option>
     <?php endforeach; ?>
    </select>
   </label>

   <span class="arc-net-status-badge" id="arc-network-global-state" data-network-status-id="<?= (int)$networkId ?>" data-agent-state="none" role="status" title="Verificando agentes" aria-label="Verificando agentes"><i class="fa fa-desktop" aria-hidden="true"></i><span class="arc-net-status-text">–/–</span></span>
   <button class="arc-identity-icon" type="button" id="arc-agent-refresh" aria-label="Atualizar informações" title="Atualizar informações"><i class="fa fa-refresh" aria-hidden="true"></i></button>
   <button class="arc-identity-icon" type="button" id="arc-agent-remote-view" aria-label="Solicitar acesso remoto" title="Solicitar acesso remoto (pede autorização local; se o agente estiver marcado como sem monitor, pede sua senha em vez disso)"><i class="fa fa-desktop" aria-hidden="true"></i></button>
  </div>
  <div class="arc-redes-header-right">
   <span id="arc-agent-version-info" style="font-size:11px;color:#a7c8dc;white-space:nowrap" title="Versão instalada do agente">v. --</span>
   <button type="button" id="arc-agent-update-remote" class="arc-identity-icon" data-arc-command="agent_update" disabled style="color:#35e9bd" title="Reinstalar arquivos do agente Windows (inclusive na mesma versão)" aria-label="Atualizar agente Windows remotamente"><i class="fa fa-refresh" aria-hidden="true"></i></button>
   <a class="arc-identity-icon" href="<?= arc_h(arc_workspace_base_url()) ?>api/download-agent.php?network=<?= (int)$networkId ?>" aria-label="Baixar agente Windows" title="Baixar agente Windows"><i class="fa fa-download" aria-hidden="true"></i></a>
   <button type="button" class="arc-identity-icon" id="arc-report-open" title="Imprimir relatório" aria-label="Imprimir relatório"><i class="fa fa-print" aria-hidden="true"></i></button>
   <div class="arc-bell-wrap"><button class="arc-identity-icon arc-alert-bell" type="button" id="arc-alert-toggle" title="Notificações de alerta" aria-label="Abrir notificações de alerta" aria-expanded="false"><i class="fa fa-bell" aria-hidden="true"></i><span class="arc-alert-count" id="arc-alert-count" <?=count($arcAlertFeed)?'':'hidden' ?>><?= count($arcAlertFeed) ?></span></button><div class="arc-alert-list" id="arc-alert-list" hidden><strong>Notificações da rede</strong><div id="arc-alert-items"></div></div></div>
   <button type="button" class="arc-identity-icon arc-identity-manage" data-network-modal-open aria-label="Gerenciar redes" title="Gerenciar redes"><i class="fa fa-sliders" aria-hidden="true"></i><?php if ($pendingAgentCount): ?><span class="arc-identity-count"><?= (int)$pendingAgentCount ?></span><?php endif; ?></button>
  </div>
 </form>
</header>
<?php require __DIR__.'/_relatorio_modal.php'; ?>
<?php require __DIR__.'/_menu.php'; ?>
<div class="arc-command-log" id="arc-command-log" role="status" aria-live="polite" aria-atomic="true" hidden></div>
<?php require __DIR__.'/_modal_gestao.php'; ?>
<div class="arc-redes-conteudo" id="arc-tab-scroll">

<dialog id="arc-alert-detail" class="arc-alert-dialog" aria-labelledby="arc-alert-detail-title">
 <div class="arc-alert-detail-head"><span class="arc-alert-symbol" id="arc-alert-detail-icon"><i class="fa fa-info-circle"></i></span><div><small id="arc-alert-detail-severity">NOTIFICAÇÃO DA REDE</small><h3 id="arc-alert-detail-title">Notificação</h3><small id="arc-alert-detail-sub"></small></div></div>
 <div class="arc-alert-detail-body"><p id="arc-alert-detail-description" class="arc-alert-description"></p><div class="arc-alert-facts" id="arc-alert-detail-facts"></div><div class="arc-alert-guidance"><strong><i class="fa fa-lightbulb-o"></i> Orientação</strong><div id="arc-alert-detail-suggestion"></div></div></div>
 <div class="arc-alert-detail-foot"><button type="button" class="asc-btn asc-primary" id="arc-alert-detail-close">Entendi</button></div>
</dialog>
<script>
(()=>{'use strict';const payload=document.getElementById('arc-alert-payload');if(!payload)return;let events=[];try{events=JSON.parse(payload.textContent||'[]')}catch(e){}const $=id=>document.getElementById(id),toggle=$('arc-alert-toggle'),list=$('arc-alert-list'),items=$('arc-alert-items'),dialog=$('arc-alert-detail');if(!toggle||!list||!items||!dialog)return;document.body.appendChild(list);const key='arc-alert-read-'+<?= (int)$networkId ?>+'-'+<?= (int)$selectedAgentId ?>;let read=new Set();try{read=new Set(JSON.parse(sessionStorage.getItem(key)||'[]'))}catch(e){}const idOf=e=>e.id||[e.when,e.machine,e.title].join('|');function count(){const n=events.filter(e=>!read.has(idOf(e))).length,b=$('arc-alert-count');b.textContent=n>99?'99+':String(n);b.hidden=n===0}function openItem(e,btn){read.add(idOf(e));try{sessionStorage.setItem(key,JSON.stringify([...read]))}catch(err){}btn.classList.add('is-read');count();$('arc-alert-detail-title').textContent=e.title;$('arc-alert-detail-sub').textContent=[e.machine,e.when].filter(Boolean).join(' · ');$('arc-alert-detail-description').textContent=e.description;$('arc-alert-detail-suggestion').textContent=e.suggestion;const sym=$('arc-alert-detail-icon');sym.className='arc-alert-symbol '+(e.severity||'info');sym.replaceChildren();const icon=document.createElement('i');icon.className='fa '+(e.icon||'fa-info-circle');sym.append(icon);$('arc-alert-detail-severity').textContent=({critical:'ATENÇÃO NECESSÁRIA',warning:'AVISO DE MONITORAMENTO',success:'INFORMAÇÃO POSITIVA',info:'NOTIFICAÇÃO DA REDE'})[e.severity]||'NOTIFICAÇÃO DA REDE';const facts=$('arc-alert-detail-facts');facts.replaceChildren();for(const f of e.fields||[]){const cell=document.createElement('div');cell.className='arc-alert-fact';const label=document.createElement('small'),value=document.createElement('strong');label.textContent=f.label;value.textContent=f.value;cell.append(label,value);facts.append(cell)}facts.hidden=!(e.fields||[]).length;list.hidden=true;toggle.setAttribute('aria-expanded','false');dialog.showModal()};events.forEach(e=>{const b=document.createElement('button');b.type='button';b.className='arc-alert-item'+(read.has(idOf(e))?' is-read':'');const icon=document.createElement('span');icon.className='arc-alert-item-icon '+(e.severity||'info');const i=document.createElement('i');i.className='fa '+(e.icon||'fa-info-circle');icon.append(i);const label=document.createElement('strong');label.textContent=e.title;b.append(icon,label);const t=document.createElement('small');t.textContent=[e.machine,e.when].filter(Boolean).join(' · ');b.append(t);b.onclick=()=>openItem(e,b);items.append(b)});if(!events.length){const p=document.createElement('p');p.textContent='Nenhum alerta recebido deste agente.';items.append(p)}function placeAlerts(){const r=toggle.getBoundingClientRect();const w=Math.min(370,window.innerWidth-24);list.style.width=w+'px';list.style.left=Math.max(12,Math.min(window.innerWidth-w-12,r.right-w))+'px';list.style.top=Math.min(window.innerHeight-24,r.bottom+9)+'px';list.style.maxHeight=Math.max(120,window.innerHeight-r.bottom-28)+'px'}toggle.onclick=()=>{list.hidden=!list.hidden;if(!list.hidden)placeAlerts();toggle.setAttribute('aria-expanded',String(!list.hidden))};window.addEventListener('resize',()=>{if(!list.hidden)placeAlerts()});window.addEventListener('scroll',()=>{if(!list.hidden)placeAlerts()},true);$('arc-alert-detail-close').onclick=()=>dialog.close();dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close()});document.addEventListener('click',e=>{if(!e.target.closest('.arc-bell-wrap')&&!list.contains(e.target)){list.hidden=true;toggle.setAttribute('aria-expanded','false')}});count()})();
</script>