<?php
/* Caminho: redes/_support_workspace.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();arc_ensure_user_schema($pdo);$networkId=(int)($_GET['network']??$_POST['network']??0);
if(!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
try{arc_support_access($pdo,$networkId,(int)arc_user_id());}catch(RuntimeException $e){http_response_code(403);exit('Rede não autorizada.');}
$arcSectionFile=$arcSupportSection;require __DIR__.'/_base.php';
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{arc_verify_csrf();$id=arc_support_ticket_write($pdo,$networkId,(int)arc_user_id(),$_POST);$_SESSION['arc_flash']='Atendimento registrado.';header('Location: '.arc_workspace_base_url().'redes/chamados.php?network='.$networkId.'&ticket='.$id,true,303);exit;}
 catch(RuntimeException $e){$error=$e instanceof PDOException?'Serviço indisponível. Verifique os registros do servidor.':$e->getMessage();}catch(Throwable $e){error_log('Atendimento: '.$e->getMessage());$error='Não foi possível salvar o chamado.';}
 // The support workflow is already authorized above; _base handles only its own native management actions.

}
$write=in_array($teamRole,['owner','administrator','operator'],true);$uid=(int)arc_user_id();
$states=['open'=>'Aberto','in_progress'=>'Em atendimento','waiting'=>'Aguardando cliente','resolved'=>'Resolvido','cancelled'=>'Cancelado'];$priorities=['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'];
$page=max(1,min(10000,(int)($_GET['p']??1)));$offset=($page-1)*50;$filter=(string)($_GET['status']??'active');$search=mb_substr(trim((string)($_GET['q']??'')),0,100);$q=$pdo->prepare('SELECT t.*,u.display_name AS technician,COALESCE((SELECT SUM(e.minutes_spent) FROM '.arc_table('support_events').' e WHERE e.ticket_id=t.id),0) AS minutes FROM '.arc_table('support_tickets').' t LEFT JOIN '.arc_table('user_accounts').' u ON u.id=t.assigned_to WHERE t.network_id=? AND t.account_id=? '.($filter==='active'?"AND t.status NOT IN ('resolved','cancelled') ":(isset($states[$filter])?'AND t.status=? ':'')).($search!==''?"AND (t.title LIKE ? OR CAST(t.id AS CHAR)=?) ":'').'ORDER BY t.due_at ASC,t.id DESC LIMIT 51 OFFSET '.(int)$offset);$params=[$networkId,$account];if(isset($states[$filter]))$params[]=$filter;if($search!==''){$params[]='%'.$search.'%';$params[]=$search;}$q->execute($params);$tickets=$q->fetchAll();$hasNext=count($tickets)>50;$tickets=array_slice($tickets,0,50);
$selected=null;$events=[];$tid=(int)($_GET['ticket']??0);if($tid){$q=$pdo->prepare('SELECT * FROM '.arc_table('support_tickets').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$tid,$networkId,$account]);$selected=$q->fetch();if($selected){$q=$pdo->prepare('SELECT e.*,u.display_name FROM '.arc_table('support_events').' e LEFT JOIN '.arc_table('user_accounts').' u ON u.id=e.actor_id WHERE e.ticket_id=? ORDER BY e.id DESC LIMIT 150');$q->execute([$tid]);$events=$q->fetchAll();}}
$technicians=[['id'=>$account,'display_name'=>'Gestor da rede']];foreach($networkTeamMembers as $member){if($member['enabled']&&in_array($member['role'],['operator','administrator'],true))$technicians[]=['id'=>$member['member_user_id'],'display_name'=>$member['display_name']];}
require __DIR__.'/_topo.php';
$canSupportAdmin=in_array($teamRole,['owner','administrator'],true);

?>
<section class="arc-service arc-tools-page arc-support-page" aria-label="Suporte da rede">
<div class="arc-support-workspace">
<nav class="arc-support-mosaic arc-support-page-links" aria-label="Áreas de gestão">
<?php foreach(['remoto'=>['Acesso remoto','fa-eye'],'suporte'=>['Suporte','fa-life-ring'],'arquivos'=>['Arquivos','fa-folder-open'],'chamados'=>['Chamados','fa-ticket'],'agenda'=>['Agenda','fa-calendar-check-o']] as $file=>$data): ?>
<a class="arc-tool-card <?= $arcSupportSection===$file?'is-active':'' ?>" href="<?=arc_h(arc_workspace_base_url().'redes/'.$file.'.php?'.http_build_query(['network'=>$networkId,'agent'=>$selectedAgentId]))?>" <?= $arcSupportSection===$file?'aria-current="page"':'' ?>><i class="fa <?=arc_h($data[1])?>" aria-hidden="true"></i><strong><?=arc_h($data[0])?></strong></a>
<?php endforeach; ?></nav>
<button type="button" id="arc-agent-remote-view" hidden aria-hidden="true" tabindex="-1"></button>
<div class="arc-support-stage" id="arc-support-stage" aria-label="Área de trabalho do suporte">
<div class="arc-support-intro" id="arc-support-intro"><i class="fa fa-eye" aria-hidden="true"></i><h2>Acesso</h2><p>Selecione o computador para solicitar acesso.</p></div>
<section id="arc-support-dashboard" aria-label="Painel de suporte"><header><h2>Painel de suporte</h2><p>Acesso rápido às ferramentas do computador selecionado.</p></header><div id="arc-dashboard-tools"></div></section>
<section id="arc-support-actions-pane" class="arc-support-actions-pane" hidden aria-label="Ferramentas de suporte"></section>
<section class="arc-support-page arc-tickets-page arc-service arc-support-tickets" id="arc-support-tickets" aria-label="Chamados da rede" hidden>
<?php if($error):?><p class="arc-service-error" role="alert"><?=arc_h($error)?></p><?php endif;?>
<?php if($write):?><dialog id="support-new" class="arc-service-dialog"><header class="arc-service-heading"><h2>Abrir chamado</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><form method="post" class="arc-service-form"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="ticket_create"><input type="hidden" name="network" value="<?=$networkId?>"><label>Título<input name="title" maxlength="160" required></label><label>Descrição do problema<textarea name="description" maxlength="12000" rows="3" required></textarea></label><div class="arc-service-fields"><label>Prioridade<select name="priority"><?php foreach($priorities as $k=>$label):?><option value="<?=$k?>" <?=$k==='normal'?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Computador<select name="agent_id"><option value="">Não vincular</option><?php foreach($networkAgents as $a):?><option value="<?=(int)$a['id']?>" <?=(int)$a['id']===$selectedAgentId?'selected':''?>><?=arc_h($a['machine_name'])?></option><?php endforeach;?></select></label></div><button class="button primary" aria-label="Criar chamado" title="Criar chamado"><i class="fa fa-plus" aria-hidden="true"></i><span class="arc-action-label">Criar chamado</span></button></form></dialog><?php endif;?>
<?php if($selected):?><dialog id="support-ticket" class="arc-service-dialog arc-support-detail"><header class="arc-service-heading"><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button><h2>#<?=(int)$selected['id']?> · <?=arc_h($selected['title'])?></h2><span class="arc-service-pill"><?=arc_h($states[$selected['status']])?></span></header><p class="arc-service-prose"><?=arc_h($selected['description'])?></p><p>Prioridade <?=arc_h($priorities[$selected['priority']])?> · prazo <?=arc_h(arc_when($selected['due_at']))?></p><?php if($write):?><form method="post" class="arc-service-form"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="ticket_update"><input type="hidden" name="network" value="<?=$networkId?>"><input type="hidden" name="ticket_id" value="<?=(int)$selected['id']?>"><div class="arc-service-fields"><label>Situação<select name="status"><?php foreach($states as $k=>$label):?><option value="<?=$k?>" <?=$selected['status']===$k?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Responsável<select name="assigned_to"><option value="0">Não atribuído</option><?php foreach($technicians as $tech):?><option value="<?=(int)$tech['id']?>" <?=(int)$selected['assigned_to']===(int)$tech['id']?'selected':''?>><?=arc_h($tech['display_name'])?></option><?php endforeach;?></select></label><label>Tempo trabalhado neste registro (minutos)<input type="number" name="minutes" min="0" max="1440" value="0"></label></div><label>Anotação técnica ou solução<textarea name="message" maxlength="12000" rows="3"></textarea></label><button class="button primary" aria-label="Registrar atendimento" title="Registrar atendimento"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Registrar atendimento</span></button></form><?php endif;?><h3>Histórico</h3><div class="arc-service-timeline"><?php foreach($events as $e):?><article><small><?=arc_h($e['display_name']??'Conta removida')?> · <?=arc_h(arc_when($e['created_at']))?> · <?=(int)$e['minutes_spent']?> min</small><p class="arc-service-prose"><?=arc_h($e['message'])?></p></article><?php endforeach;?></div></dialog><?php endif;?>
<section class="arc-support-queue"><form method="get" class="arc-filter-bar"><input type="hidden" name="pane" value="tickets"><input type="hidden" name="network" value="<?=$networkId?>"><input type="hidden" name="agent" value="<?=$selectedAgentId?>"><input type="search" name="q" value="<?=arc_h($search)?>" placeholder="Buscar chamado" aria-label="Buscar chamado"><select name="status" aria-label="Situação dos chamados"><option value="active">Em aberto</option><option value="all" <?=$filter==='all'?'selected':''?>>Todos</option><?php foreach($states as $k=>$label):?><option value="<?=$k?>" <?=$filter===$k?'selected':''?>><?=$label?></option><?php endforeach;?></select><button class="arc-icon-action" aria-label="Filtrar" title="Filtrar"><i class="fa fa-search" aria-hidden="true"></i></button><?php if($write):?>
<button type="button" class="arc-icon-action" data-open-dialog="support-new" title="Novo chamado" aria-label="Novo chamado"><i class="fa fa-plus" aria-hidden="true"></i></button><?php endif;?></form><div class="arc-service-list"><?php foreach($tickets as $t):?><a href="<?=arc_h(arc_workspace_base_url())?>redes/chamados.php?network=<?=$networkId?>&agent=<?=$selectedAgentId?>&ticket=<?=(int)$t['id']?>" class="arc-service-ticket"><div><strong>#<?=(int)$t['id']?> · <?=arc_h($t['title'])?></strong><p><?=arc_h($t['technician']?:'Sem responsável')?> · <?=arc_h($priorities[$t['priority']])?> · <?=(int)$t['minutes']?> min</p></div><div><span class="arc-service-pill"><?=arc_h($states[$t['status']])?></span><small><?=strtotime($t['due_at'])<time()&&!in_array($t['status'],['resolved','cancelled'],true)?'Prazo excedido · ':''?><?=arc_h(arc_when($t['due_at']))?></small></div></a><?php endforeach;?><?php if(!$tickets):?><p>Nenhum chamado neste filtro.</p><?php endif;?></div><nav class="arc-service-actions" aria-label="Páginas de chamados"><?php if($page>1):?><a class="button" href="<?=arc_h(arc_workspace_base_url().'redes/chamados.php?'.http_build_query(['network'=>$networkId,'agent'=>$selectedAgentId,'q'=>$search,'status'=>$filter,'p'=>$page-1]))?>" aria-label="Anterior" title="Anterior"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="arc-action-label">Anterior</span></a><?php endif;?><span>Página <?=$page?></span><?php if($hasNext):?><a class="button" href="<?=arc_h(arc_workspace_base_url().'redes/chamados.php?'.http_build_query(['network'=>$networkId,'agent'=>$selectedAgentId,'q'=>$search,'status'=>$filter,'p'=>$page+1]))?>" aria-label="Próxima" title="Próxima"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="arc-action-label">Próxima</span></a><?php endif;?></nav></section>
<script src="<?=arc_h(arc_workspace_base_url())?>assets/js/support-transfers.js?v=<?=arc_h(arc_app_version())?>"></script>
<script>document.addEventListener('DOMContentLoaded',()=>{<?php if($selected):?>document.getElementById('support-ticket')?.showModal();<?php elseif(isset($_GET['new'])&&$write):?>document.getElementById('support-new')?.showModal();<?php endif;?>});</script>
</section>

</div></div>
<section class="arc-files-workspace" id="file-transfer" aria-label="Arquivos do computador" hidden>
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
  <div id="arc-fs-entries" class="arc-fs-entries" role="list" aria-label="Discos e arquivos"></div>
  <button type="button" id="arc-fs-center-load" class="arc-fs-center-load" aria-label="Carregar arquivos" title="Carregar os discos disponíveis"><i class="fa fa-folder-open" aria-hidden="true"></i><span>Carregar arquivos</span></button>
  <p id="arc-fs-state" role="status" aria-live="polite">Selecione um computador conectado.</p>
  <div id="file-feedback" class="arc-files-feedback" role="status" aria-live="polite"></div>

  <aside id="arc-transfer-dock" class="arc-transfer-dock" hidden aria-live="polite"><div class="arc-transfer-dock-head"><strong id="arc-transfer-title">Transferência</strong><button type="button" id="arc-transfer-cancel" class="arc-files-icon" aria-label="Cancelar transferência" title="Cancelar transferência"><i class="fa fa-times"></i></button></div><progress id="arc-transfer-progress" max="100" value="0"></progress><small id="arc-transfer-detail">Aguardando…</small></aside>
</section>
<dialog id="arc-fs-menu" class="arc-explorer-menu" aria-labelledby="arc-fs-menu-name"><header><strong id="arc-fs-menu-name"></strong><button type="button" id="arc-fs-menu-close" class="arc-icon-action" aria-label="Fechar opções"><i class="fa fa-times" aria-hidden="true"></i></button></header><div id="arc-fs-menu-options"></div></dialog>
<dialog id="arc-file-preview" class="arc-explorer-preview" aria-labelledby="arc-file-preview-title"><header><strong id="arc-file-preview-title"></strong><button type="button" id="arc-file-preview-close" class="arc-icon-action" aria-label="Fechar visualização"><i class="fa fa-times" aria-hidden="true"></i></button></header><div id="arc-file-preview-content"></div></dialog>
<dialog class="arc-service-dialog arc-schedules-dialog" id="support-schedules" aria-label="Agendar serviços">
<div class="arc-schedule-toolbar" role="toolbar" aria-label="Ferramentas de agendamento">
<input type="search" id="schedule-search" placeholder="Buscar agendamento" aria-label="Buscar agendamentos" tabindex="-1" autocomplete="off">
<select id="schedule-filter" aria-label="Filtrar agendamentos"><option value="all">Todos</option><option value="active">Ativos</option><option value="inactive">Cancelados ou encerrados</option></select>
<button class="arc-icon-action" type="button" id="schedule-refresh" aria-label="Atualizar agendamentos" title="Atualizar"><i class="fa fa-refresh" aria-hidden="true"></i></button>
<button class="arc-icon-action" type="button" id="arc-agent-force-update" title="Forçar atualização do agente selecionado" aria-label="Forçar atualização do agente" <?=!in_array($teamRole,['owner','administrator'],true)?'disabled':''?>> <i class="fa fa-cloud-download" aria-hidden="true"></i></button>
<button class="arc-icon-action" type="button" id="schedule-new" aria-label="Novo agendamento" title="Novo agendamento"><i class="fa fa-plus" aria-hidden="true"></i></button>

</div>
<dialog id="schedule-create-modal" class="arc-schedule-modal" aria-labelledby="schedule-create-title"><div class="arc-schedule-modal-header"><div><h2 id="schedule-create-title"><i class="fa fa-calendar-plus-o" aria-hidden="true"></i> Novo agendamento</h2><p>Configure o serviço para o computador selecionado.</p></div><button type="button" class="arc-icon-action" id="schedule-modal-close" aria-label="Fechar formulário" title="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div><form id="schedule-form" class="arc-service-form arc-schedule-editor"><label>Serviço<select name="command" required><option value="" selected disabled>Escolha o serviço</option><?php foreach(arc_support_schedule_actions() as $command=>$label):?><option value="<?=$command?>"><?=$label?></option><?php endforeach;?></select></label><div class="arc-service-fields"><label>Início<input type="datetime-local" name="starts_at" value="<?=date('Y-m-d\TH:i',time()+300)?>" required></label><label>Repetir<select name="recurrence"><option value="once">Uma vez</option><option value="weekdays">Dias da semana</option><option value="interval">A cada intervalo</option></select></label><label>Fim do período (opcional)<input type="datetime-local" name="ends_at"></label><label data-schedule-interval hidden>Intervalo em minutos<input type="number" name="interval_minutes" min="15" max="10080" value="60"></label></div><fieldset data-schedule-days hidden><legend>Dias da semana</legend><div class="arc-schedule-weekdays"><?php foreach([1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex',6=>'Sáb',7=>'Dom'] as $day=>$label):?><label><input type="checkbox" name="weekdays" value="<?=$day?>"> <?=$label?></label><?php endforeach;?></div></fieldset>
<div data-schedule-power><label>Senha da sua conta<input type="password" name="password" autocomplete="current-password"></label><label class="arc-schedule-consent"><input type="checkbox" name="confirmed"> Confirmo a ação de energia no computador selecionado.</label></div><button type="submit" class="button primary"><i class="fa fa-calendar-plus-o" aria-hidden="true"></i> Agendar</button><div class="arc-schedule-editor-actions"><button type="button" class="button" id="schedule-form-cancel">Cancelar</button></div></form></dialog><p id="schedule-feedback" role="status" aria-live="polite"></p>
<div class="arc-schedule-grid" role="region" aria-label="Lista de serviços agendados" tabindex="0">
<table class="arc-schedule-table"><thead><tr><th>Serviço</th><th>Recorrência</th><th>Próxima execução</th><th>Situação</th><th>Ações</th></tr></thead><tbody id="schedule-list"></tbody></table>
</div>
<dialog id="schedule-history-modal" class="arc-schedule-modal" aria-labelledby="schedule-history-title"><div class="arc-schedule-modal-header"><div><h2 id="schedule-history-title"><i class="fa fa-history" aria-hidden="true"></i> Histórico de execução</h2><p id="schedule-history-context"></p></div><button type="button" class="arc-icon-action" id="schedule-history-close" aria-label="Fechar histórico" title="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div><div id="schedule-runs" class="arc-schedule-run-list"></div></dialog>
</dialog>

</section>
<p id="arc-agent-force-update-status" role="status" aria-live="polite" style="padding:4px 12px;min-height:1.5em;margin:0"></p>
<dialog id="arc-windows-update-dialog" class="arc-service-dialog arc-windows-dialog" aria-labelledby="arc-windows-title"><header><h2 id="arc-windows-title"><i class="fa fa-windows" aria-hidden="true"></i> Atualizações do Windows</h2><button type="button" id="arc-windows-close" class="arc-icon-action" aria-label="Fechar atualizações"><i class="fa fa-times" aria-hidden="true"></i></button></header><p id="arc-windows-feedback" role="status"></p><ul id="arc-windows-updates"></ul><p>O Windows baixa e instala as atualizações listadas e aceita suas licenças. Nenhuma reinicialização automática será solicitada por esta ferramenta.</p><div class="arc-service-actions"><button type="button" class="button primary" id="arc-windows-install" hidden>Baixar e instalar</button><button type="button" class="button" id="arc-windows-rescan">Verificar novamente</button></div></dialog>
<?php require __DIR__.'/_rodape.php';?>
<script>
(()=>{'use strict';const network=<?=json_encode($networkId)?>,csrf=<?=json_encode($csrf)?>,base=<?=json_encode(arc_workspace_base_url())?>;
const plain=value=>window.arcPlainMessage?window.arcPlainMessage(value):String(value||'');
async function json(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não foi possível verificar.');return j;}
const fileDialog=document.getElementById('file-transfer'),fileMessage=document.getElementById('file-feedback');let fileBusy=false,fileLimit=<?=ARC_FILE_MAX_BYTES?>,fileFolderLimit=300;
const fsDefaultAgent=<?=json_encode((int)$selectedAgentId)?>;
function fsAgentId(){const selector=document.getElementById('arc-agent-target');const current=Number(selector?.value||0);if(current>0)return current;if(fsDefaultAgent>0){if(selector&&[...selector.options].some(o=>Number(o.value)===fsDefaultAgent))selector.value=String(fsDefaultAgent);return fsDefaultAgent;}return 0;}
const fsEntries=document.getElementById('arc-fs-entries'),fsState=document.getElementById('arc-fs-state'),fsCurrent=document.getElementById('arc-fs-current'),fsSelection=document.getElementById('arc-files-selection');
let fsPath='',fsBusy=false,fsPendingRoot=false,fsLoadState='idle',fsRows=[],fsHistory=[],fsHistoryIndex=-1,fsInitialized=false;let fsView='details';try{const saved=localStorage.getItem('arc_support_file_view');if(['list','grid','details'].includes(saved))fsView=saved}catch{};fsEntries?.setAttribute('data-view',fsView);const fsCanTransfer=<?=json_encode($canSupportAdmin)?>,fsCanExecute=<?=json_encode(in_array($teamRole,['owner','administrator'],true))?>;
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

 for(const item of fsRows){
  const button=document.createElement('button');button.type='button';button.className='arc-fs-item'+(item.drive?' arc-fs-drive':'')+(fsSelected.has(item.path)?' is-selected':'');button.setAttribute('role','listitem');
  const check=document.createElement('span');check.className='arc-fs-check';check.innerHTML='<i class="fa fa-check" aria-hidden="true"></i>';
  if(fsSelectionMode&&fsCanTransfer&&!item.drive){check.setAttribute('role','checkbox');check.setAttribute('aria-checked',String(fsSelected.has(item.path)));check.setAttribute('aria-label','Selecionar '+item.name);button.append(check);}
  const icon=document.createElement('i');icon.className='fa '+fileIcon(item);icon.setAttribute('aria-hidden','true');
  const label=document.createElement('span');label.className='arc-fs-label';
  const name=document.createElement('strong');name.textContent=item.drive?'Disco local ('+String(item.name||'').replace(/[\\/]+$/,'')+')':item.name;label.append(name);
  const details=document.createElement('small');details.textContent=item.drive?rowLabel(item):(item.directory?'Pasta · '+(item.folder_size===null||item.folder_size===undefined?'tamanho indisponível':formatBytes(item.folder_size)+(item.folder_size_complete?'':' ou mais')):rowLabel(item)+' · '+formatBytes(item.size));label.append(details);
  button.append(icon,label);if(typeof scheduleThumbnail==='function')scheduleThumbnail(item,icon);if(fsView==='details'&&!item.drive){const modified=document.createElement('small');modified.className='arc-fs-modified';modified.textContent=item.modified?new Date(item.modified*1000).toLocaleString('pt-BR'):'—';button.append(modified);}
  if(item.drive){const total=Number(item.total||item.size||0),free=Number(item.free||0);if(total){const meter=document.createElement('span');meter.className='arc-drive-meter';const fill=document.createElement('span');fill.style.width=Math.max(0,Math.min(100,100*(total-free)/total))+'%';meter.append(fill);label.append(meter);}}else if(!item.directory){const size=document.createElement('small');size.className='arc-fs-size';size.textContent=formatBytes(item.size);button.append(size);}
  if(isExecutable(item)&&fsCanExecute&&fsCanTransfer){const run=document.createElement('span');run.className='arc-fs-run';run.textContent='Executar';button.append(run);}  
  let timer=null,suppressClick=false,startX=0,startY=0;
  const cancelPress=()=>{clearTimeout(timer);timer=null;};
  button.addEventListener('pointerdown',e=>{if(!fsCanTransfer||item.drive||e.button!==0)return;startX=e.clientX;startY=e.clientY;cancelPress();timer=setTimeout(()=>{suppressClick=true;openFileMenu(item);},650);});
  button.addEventListener('pointermove',e=>{if(Math.abs(e.clientX-startX)>8||Math.abs(e.clientY-startY)>8)cancelPress();});
  for(const event of ['pointerup','pointercancel','pointerleave'])button.addEventListener(event,cancelPress);
  button.addEventListener('contextmenu',e=>{if(!fsCanTransfer||item.drive)return;e.preventDefault();cancelPress();const rect=button.getBoundingClientRect();openFileMenu(item,{x:e.clientX||rect.left,y:e.clientY||rect.bottom});});
  button.addEventListener('click',e=>{e.preventDefault();if(suppressClick){suppressClick=false;return;}if(fsSelectionMode&&fsCanTransfer&&!item.drive){toggleSelected(item.path);return;}if(item.directory){fsOpen(item.path);return;}if(previewExtensions.has(extOf(item.name))){fileOperation('preview',[item.path],{},true);return;}openFileMenu(item);});
  if(!fsCanTransfer && !item.drive)button.disabled=true;
  fsEntries.append(button);
 }
}
async function fsOpen(path=''){
 if(!fsEntries)return;
 if(fsBusy){if(path==='')fsPendingRoot=true;return;}
 if(!fsCanTransfer && path!==''){fsState.textContent='Seu perfil permite visualizar somente os discos.';return;}
 const agent=fsAgentId();
 if(!agent){fsLoadState='idle';fsState.textContent='Selecione um computador conectado.';fsRows=[];renderEntries();return;}
 fsBusy=true;fsLoadState='loading';setSelectionMode(false);fsState.textContent='Carregando arquivos…';fsRows=[];renderEntries();
 try{
  await abortThumbnails();
  const started=await json(base+'api/api.php?action=network-file-browser-start',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent,path,_csrf:csrf})});
  let result=null;
  for(let i=0;i<24;i++){
   await new Promise(resolve=>setTimeout(resolve,900));
   result=await json(base+'api/api.php?action=network-file-browser-status&network_id='+network+'&request_id='+started.request_id);
   if(['completed','failed','expired'].includes(result.status))break;
  }
  if(result?.status!=='completed'||!result.data||result.data.error)throw Error(result?.data?.error||result?.message||'O servidor não respondeu à consulta.');
  fsPath=result.data.path||'';fsCurrent.textContent=fsPath||'Dispositivos e unidades';fsRows=(result.data.entries||[]).map(item=>({...item,drive:!fsPath&&!!item.directory}));fsLoadState='loaded';fsInitialized=true;fsState.textContent=fsRows.length+' item(ns) encontrado(s).'+(result.data.truncated?' Listagem limitada aos primeiros 150 itens.':'');if(fsHistory[fsHistoryIndex]!==fsPath){fsHistory=fsHistory.slice(0,fsHistoryIndex+1);fsHistory.push(fsPath);fsHistoryIndex=fsHistory.length-1;}renderBreadcrumbs();renderEntries();
 }catch(e){fsRows=[];fsLoadState='error';renderEntries();fsState.textContent=e.message;}finally{fsBusy=false;if(fsPendingRoot){fsPendingRoot=false;fsOpen('');}}
}
async function fileApi(action,options={}){return json(base+'api/support.php?action='+action,{headers:{'X-CSRF-Token':csrf},...options});}
const thumbnailCache=new Map(),thumbnailSlots=new Map(),thumbnailTypes=new Set(['png','jpg','jpeg','webp','gif','bmp']);let thumbnailJob=null,thumbnailTimer=null,thumbnailUnsupported=false;
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function showThumbnail(icon,url,name){if(!icon?.isConnected)return;const el=document.createElement('img');el.className='arc-fs-thumb';el.alt=name;el.src=url;icon.replaceWith(el);}
function scheduleThumbnail(item,icon){
 if(item.directory||item.drive||!fsCanTransfer||thumbnailUnsupported||!thumbnailTypes.has(extOf(item.name)))return;
 const key=fsAgentId()+':'+item.path,cached=thumbnailCache.get(key);
 if(cached){showThumbnail(icon,cached,item.name);return;}
 thumbnailSlots.set(key,{icon,name:item.name});
 if(cached===undefined){clearTimeout(thumbnailTimer);thumbnailTimer=setTimeout(loadThumbnails,200);}
}
async function cancelTransfer(id,agent=fsAgentId()){if(!id)return;const form=new FormData();form.set('network',network);form.set('agent',agent);form.set('id',id);await fileApi('file-cancel',{method:'POST',body:form}).catch(()=>{});}
/* Todas as imagens da pasta chegam em um único pedido ao computador (até 24 por vez). */
async function loadThumbnails(){
 if(thumbnailJob||fileBusy||fileDialog.hidden||thumbnailUnsupported||!fsCanTransfer)return;
 const agent=fsAgentId();if(!agent)return;
 const wanted=[...thumbnailSlots.keys()].filter(key=>key.startsWith(agent+':')&&thumbnailCache.get(key)===undefined).slice(0,24);
 if(!wanted.length)return;
 const prefix=String(agent).length+1;for(const key of wanted)thumbnailCache.set(key,null);
 const job={agent,id:null,controller:new AbortController(),requested:null};thumbnailJob=job;
 try{
  const form=new FormData();form.set('network',network);form.set('agent',agent);form.set('operation','thumbnails');form.set('paths',JSON.stringify(wanted.map(key=>key.slice(prefix))));form.set('confirmed','1');
  job.requested=fileApi('file-operation-request',{method:'POST',body:form});job.id=(await job.requested).id;
  const row=await waitTransfer(job.id,['ready'],job.controller.signal,45000);
  const response=await fetch(base+'api/support.php?action=file-download&id='+row.id,{credentials:'same-origin',cache:'no-store',signal:job.controller.signal});
  if(!response.ok)throw Error('Miniaturas indisponíveis.');
  const bytes=new Uint8Array(await response.arrayBuffer());
  if(bytes.length<8||String.fromCharCode(...bytes.subarray(0,4))!=='ARCT')throw Error('Miniaturas inválidas.');
  const size=new DataView(bytes.buffer).getUint32(4),entries=JSON.parse(new TextDecoder().decode(bytes.subarray(8,8+size)));let offset=8+size;
  for(const entry of entries){
   const part=bytes.slice(offset,offset+entry.size);offset+=entry.size;
   if(!/^image\/(jpeg|png|webp|gif|bmp)$/.test(entry.type))continue;
   const key=agent+':'+entry.path,url=URL.createObjectURL(new Blob([part],{type:entry.type}));thumbnailCache.set(key,url);
   const slot=thumbnailSlots.get(key);if(slot)showThumbnail(slot.icon,url,slot.name);
  }
 }catch(error){if(/Atualize o agente/.test(error.message)){thumbnailUnsupported=true;fileMessage.textContent=plain(error.message);}}
 finally{
  if(thumbnailJob===job)thumbnailJob=null;
  if(job.controller.signal.aborted)for(const key of wanted)if(thumbnailCache.get(key)===null)thumbnailCache.delete(key);
  await cancelTransfer(job.id,agent);
  if(!job.controller.signal.aborted)setTimeout(loadThumbnails,150);
 }
}
/* Libera o computador antes de qualquer pedido do usuário. */
async function abortThumbnails(){const job=thumbnailJob;if(!job)return;thumbnailJob=null;job.controller.abort();const result=await job.requested?.catch(()=>null);await cancelTransfer(result?.id||job.id,job.agent);}
window.addEventListener('pagehide',()=>{for(const url of thumbnailCache.values())if(url)URL.revokeObjectURL(url);});
let activeTransfer=null;const transferDock=document.getElementById('arc-transfer-dock'),transferProgress=document.getElementById('arc-transfer-progress'),transferDetail=document.getElementById('arc-transfer-detail');document.body.append(transferDock);function transferStart(title){transferDock.hidden=false;document.getElementById('arc-transfer-title').textContent=title;transferProgress.removeAttribute('value');transferDetail.textContent='Iniciando…';}function transferUpdate(loaded,total,started,initialLoaded=0){if(total>0){transferProgress.value=Math.min(100,loaded/total*100);const speed=(Math.max(0,loaded-initialLoaded)/Math.max(.25,(Date.now()-started)/1000));const remaining=speed>0?(total-loaded)/speed:0;transferDetail.textContent=Math.round(loaded/total*100)+'% · '+formatBytes(loaded)+' de '+formatBytes(total)+' · '+(speed<=0?'calculando tempo…':remaining<1?'quase concluído':Math.ceil(remaining)+' s restantes');}else transferDetail.textContent=formatBytes(loaded)+' enviados';}function transferFinish(message=''){if(message)transferDetail.textContent=message;setTimeout(()=>{if(!activeTransfer)transferDock.hidden=true;},2500);}document.getElementById('arc-transfer-cancel').addEventListener('click',()=>{if(activeTransfer){activeTransfer.abort();activeTransfer=null;fileBusy=false;transferFinish('Transferência cancelada.');fileMessage.textContent='Transferência cancelada.';}});const uploadUser=<?=json_encode($uid)?>;
window.ArcDirectUpload?.purgeLegacyQueue();
/* Envio direto: sai do navegador na hora, grava no computador e a lista é atualizada ao terminar. */
async function sendFiles(files,folder=false){
 if(fileBusy||!files.length)return;
 if(!window.ArcDirectUpload){fileMessage.textContent='Recarregue a página para enviar arquivos.';return;}
 if(!fsPath||!fsAgentId()){fileMessage.textContent='Abra a pasta de destino no computador selecionado antes de enviar.';return;}
 fileBusy=true;const controller=new AbortController(),destination=fsPath,agent=fsAgentId(),label=folder?files[0].webkitRelativePath.split('/')[0]:files[0].name;let id=null;
 try{
  await abortThumbnails();activeTransfer=controller;transferStart('Enviando '+label);fileMessage.textContent='';const started=Date.now();
  id=await ArcDirectUpload.send({base,csrf,user:uploadUser,network:Number(network),agent,destination},files,folder,{signal:controller.signal,onProgress:(loaded,total)=>transferUpdate(loaded,total,started)});
  transferProgress.removeAttribute('value');transferDetail.textContent='Salvando no computador…';
  await waitTransfer(id,['completed'],controller.signal,150000);
  fileMessage.textContent='Enviado: '+label+'.';transferFinish('Enviado · '+label);activeTransfer=null;
  if(fsPath===destination&&fsAgentId()===agent)await fsOpen(destination);
 }catch(error){
  if(id&&controller.signal.aborted)await cancelTransfer(id,agent);
  const message=error.name==='AbortError'?'Envio cancelado.':plain(error.message);fileMessage.textContent=message;transferFinish(message);
 }finally{activeTransfer=null;fileBusy=false;document.getElementById('file-source').value='';document.getElementById('folder-source').value='';setTimeout(loadThumbnails,200);}
}
async function requestFileSend(file){if(file)return sendFiles([file]);}
async function requestBundleDownload(){if(fsSelected.size)return fileOperation('download',[...fsSelected]);}
const fileMenu=document.getElementById('arc-fs-menu'),preview=document.getElementById('arc-file-preview');let clipboard=null,previewUrl=null;
const operationLabels={open:'Abrir',rename:'Alterar nome',execute:'Executar',download:'Baixar arquivo',select:'Selecionar',delete:'Excluir',preview:'Visualizar',extract:'Extrair ZIP',copy:'Copiar',cut:'Recortar',move:'Mover',paste:'Colar'};
const operationIcons={open:'folder-open',rename:'pencil',execute:'play',download:'download',select:'check-square-o',delete:'trash',preview:'eye',extract:'file-archive-o',copy:'copy',cut:'cut',move:'arrows',paste:'paste'};
function openFileMenu(item=null,position=null){
 if(!fsCanTransfer||fileBusy)return;const items=fsSelectionMode&&fsSelected.has(item?.path)?fsRows.filter(r=>fsSelected.has(r.path)):item?[item]:[];
 const list=document.getElementById('arc-fs-menu-options');list.replaceChildren();document.getElementById('arc-fs-menu-name').textContent=items.length>1?items.length+' itens':item?.name||'Esta pasta';
 for(const [action,label] of Object.entries(operationLabels)){
  const single=items.length===1,regular=single&&!items[0].directory;
  const available=action==='execute'&&!fsCanExecute?false:action==='paste'?!!clipboard&&clipboard.agent===fsAgentId()&&!!fsPath:action==='execute'?regular&&isExecutable(items[0]):action==='preview'?regular&&previewExtensions.has(extOf(items[0].name)):action==='extract'?regular&&extOf(items[0].name)==='zip':action==='rename'||action==='open'?single:items.length>0;
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
 if(action==='copy'||action==='cut'){clipboard={agent:fsAgentId(),paths,operation:action==='cut'?'move':'copy'};fileMessage.textContent=paths.length+' item(ns) na área de transferência. Abra o destino e use Colar.';return;}
 let name='',destination='';
 if(action==='paste'){
  if(!clipboard||clipboard.agent!==fsAgentId())return;
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
 if(fileBusy||!fsCanTransfer)return;fileBusy=true;const agent=fsAgentId(),controller=new AbortController(),item=fsRows.find(i=>i.path===paths[0]),label=paths.length>1?paths.length+' itens':(item?.name||'arquivo');let id=null;
 try{
  await abortThumbnails();
  if(['download','preview'].includes(operation)){activeTransfer=controller;transferStart((operation==='download'?'Baixando ':'Abrindo ')+label);fileMessage.textContent='';}
  else fileMessage.textContent='Processando '+(operationLabels[operation]||operation).toLowerCase()+'…';
  const form=new FormData();form.set('network',network);form.set('agent',agent);form.set('operation',operation);form.set('paths',JSON.stringify(paths));form.set('confirmed','1');for(const [key,value] of Object.entries(extra))form.set(key,value);
  const result=await fileApi('file-operation-request',{method:'POST',body:form});id=result.id||null;
  if(id){
   const row=await waitTransfer(id,['ready'],controller.signal);
   if(view){await showPreview(row,paths[0]);transferFinish('Visualização aberta.');}
   else{const url=startBrowserDownload(row),fallback=document.createElement('a');fallback.href=url;fallback.textContent='Se o download não iniciar, toque aqui.';fileMessage.replaceChildren(fallback);transferProgress.value=100;transferFinish('Download iniciado · '+formatBytes(row.file_size));}
  }else{
   let done=false;
   for(let i=0;i<60;i++){
    await sleep(900);const status=await json(base+'api/api.php?action=network-command-status&network_id='+network+'&agent_id='+agent);const row=(status.commands||[]).find(r=>Number(r.id)===Number(result.command_id));
    if(!row)continue;if(['failed','expired'].includes(row.status))throw Error(row.result_text||'Operação não concluída.');if(row.status==='completed'){done=true;fileMessage.textContent=row.result_text||'Concluído.';break;}
   }
   if(!done)throw Error('O computador não concluiu a operação no prazo.');
   if(clearClipboard)clipboard=null;
   if(['rename','delete','copy','move','extract'].includes(operation)){setSelectionMode(false);await fsOpen(fsPath);}
  }
 }catch(error){
  if(id&&controller.signal.aborted)await cancelTransfer(id,agent);
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
document.getElementById('arc-fs-back')?.addEventListener('click',()=>{if(fsHistoryIndex>0){fsHistoryIndex--;fsOpen(fsHistory[fsHistoryIndex]);}else fsOpen('');});
document.getElementById('arc-fs-clear-selection')?.addEventListener('click',()=>setSelectionMode(false));


document.getElementById('arc-files-upload')?.addEventListener('click',()=>{if(!fsCanTransfer)return;document.getElementById('file-source').click();});
document.getElementById('file-source')?.addEventListener('change',e=>requestFileSend(e.target.files?.[0]||null));document.getElementById('arc-files-upload-folder')?.addEventListener('click',()=>{if(fsCanTransfer)document.getElementById('folder-source').click();});document.getElementById('folder-source')?.addEventListener('change',e=>requestFolderSend([...e.target.files||[]]));
async function requestFolderSend(files){return sendFiles(files,true);}

document.getElementById('arc-files-download')?.addEventListener('click',()=>{if(!fsCanTransfer){fileMessage.textContent='Seu perfil não pode transferir arquivos.';return;}if(!fsSelected.size){fileMessage.textContent='Marque um ou mais arquivos ou pastas para baixar.';return;}requestBundleDownload();});
const scheduleDialog=document.getElementById('support-schedules'),scheduleForm=document.getElementById('schedule-form'),scheduleMessage=document.getElementById('schedule-feedback'),scheduleList=document.getElementById('schedule-list'),scheduleRuns=document.getElementById('schedule-runs'),scheduleLabels=<?=json_encode(arc_support_schedule_actions(),JSON_UNESCAPED_UNICODE)?>;
let scheduleBusy=false;
function scheduleFields(){const mode=scheduleForm.elements.recurrence.value;document.querySelector('[data-schedule-days]').hidden=mode!=='weekdays';document.querySelector('[data-schedule-interval]').hidden=mode!=='interval';document.querySelector('[data-schedule-power]').hidden=!scheduleForm.elements.command.value.startsWith('power_');}
scheduleForm.elements.recurrence.addEventListener('change',scheduleFields);scheduleForm.elements.command.addEventListener('change',scheduleFields);scheduleFields();
const scheduleDate=value=>value?value.slice(8,10)+'/'+value.slice(5,7)+'/'+value.slice(0,4)+' '+value.slice(11,16):'Encerrado';
async function scheduleApi(action,data={}){return json(base+'api/support.php?action=schedule-'+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:fsAgentId(),...data})});}
let scheduleRows=[];
const scheduleSearch=document.getElementById('schedule-search'),scheduleFilter=document.getElementById('schedule-filter');
const scheduleEditor=document.getElementById('schedule-create-modal'),scheduleHistory=document.getElementById('schedule-history-modal');
let scheduleRunRows=[];let selectedScheduleHistory=null;
const scheduleNew=document.getElementById('schedule-new');
const scheduleRecurrence=row=>row.recurrence==='once'?'Uma vez':row.recurrence==='interval'?'A cada '+row.interval_minutes+' min':String(row.weekdays||'').split(',').map(d=>['','Seg','Ter','Qua','Qui','Sex','Sáb','Dom'][Number(d)]||'').filter(Boolean).join(', ');
function renderSchedules(){
 const term=scheduleSearch.value.trim().toLocaleLowerCase('pt-BR'),filter=scheduleFilter.value;
 scheduleList.replaceChildren();
 const rows=scheduleRows.filter(row=>{const active=Number(row.enabled)&&Boolean(row.next_run_at);return(filter==='all'||(filter==='active'?active:!active))&&(!term||(scheduleLabels[row.command_name]||row.command_name||'').toLocaleLowerCase('pt-BR').includes(term));});
 for(const row of rows){
  const tr=document.createElement('tr'),active=Number(row.enabled)&&Boolean(row.next_run_at);
  const cells=[scheduleLabels[row.command_name]||'Serviço',scheduleRecurrence(row),row.next_run_at?scheduleDate(row.next_run_at):'—',active?'Ativo':Number(row.enabled)?'Encerrado':'Cancelado'];
  for(const [i,value] of cells.entries()){const td=document.createElement('td');td.dataset.label=['Serviço','Recorrência','Próxima execução','Situação'][i];td.textContent=value;tr.append(td);}
  const actions=document.createElement('td');actions.className='arc-schedule-actions';actions.dataset.label='Ações';
  const historyButton=document.createElement('button');historyButton.type='button';historyButton.className='arc-icon-action';historyButton.title='Histórico de execução';historyButton.setAttribute('aria-label','Histórico do agendamento '+row.id);historyButton.innerHTML='<i class="fa fa-history" aria-hidden="true"></i>';historyButton.addEventListener('click',()=>showScheduleHistory(row));actions.append(historyButton);
  if(Number(row.enabled)){const cancel=document.createElement('button');cancel.type='button';cancel.className='arc-icon-action';cancel.setAttribute('aria-label','Cancelar agendamento de '+cells[0]);cancel.title='Cancelar agendamento';cancel.innerHTML='<i class="fa fa-calendar-times-o" aria-hidden="true"></i>';cancel.addEventListener('click',async()=>{cancel.disabled=true;try{await scheduleApi('cancel',{id:row.id});scheduleMessage.textContent='Agendamento cancelado.';await loadSchedules();}catch(e){scheduleMessage.textContent=plain(e.message);cancel.disabled=false;}});actions.append(cancel);}
  tr.append(actions);scheduleList.append(tr);
 }
 if(!rows.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=5;td.className='arc-schedule-empty';td.textContent='Nenhum agendamento encontrado.';tr.append(td);scheduleList.append(tr);}
}
async function loadSchedules(){if(!scheduleDialog.open)return;try{
 if(!fsAgentId()){scheduleMessage.textContent='Selecione um computador para consultar os agendamentos.';scheduleRows=[];renderSchedules();scheduleRunRows=[];scheduleRuns.replaceChildren();return;}
 const data=await scheduleApi('list');scheduleRows=Array.isArray(data.schedules)?data.schedules:[];renderSchedules();
 scheduleRunRows=Array.isArray(data.runs)?data.runs:[];
 if(scheduleHistory.open&&selectedScheduleHistory!==null)renderScheduleHistory();
 }catch(e){scheduleMessage.textContent=plain(e.message);}}
scheduleSearch.addEventListener('input',renderSchedules);scheduleFilter.addEventListener('change',renderSchedules);
function closeScheduleCreate(){if(scheduleEditor.open)scheduleEditor.close();scheduleNew.setAttribute('aria-expanded','false');scheduleForm.elements.password.value='';scheduleForm.elements.confirmed.checked=false;}
scheduleNew.addEventListener('click',()=>{if(!fsAgentId()){scheduleMessage.textContent='Selecione um computador para agendar.';return;}scheduleEditor.showModal();scheduleNew.setAttribute('aria-expanded','true');scheduleForm.elements.command.focus();});
document.getElementById('schedule-form-cancel').addEventListener('click',closeScheduleCreate);
document.getElementById('schedule-modal-close').addEventListener('click',closeScheduleCreate);
scheduleEditor.addEventListener('close',()=>{scheduleNew.setAttribute('aria-expanded','false');scheduleForm.elements.password.value='';scheduleForm.elements.confirmed.checked=false;});
function renderScheduleHistory(){
 scheduleRuns.replaceChildren();const statuses={pending:'Na fila',running:'Em execução',completed:'Concluído',failed:'Não concluído',expired:'Não executado'};
 const entries=scheduleRunRows.filter(run=>Number(run.schedule_id)===Number(selectedScheduleHistory));
 for(const run of entries){const item=document.createElement('article'),title=document.createElement('strong'),info=document.createElement('small');title.textContent=scheduleDate(run.scheduled_for);info.textContent=run.task_status?(statuses[run.task_status]||'Em andamento'):plain(run.message||run.outcome||'');if(run.result_text)info.textContent+=' · '+plain(run.result_text);item.append(title,info);scheduleRuns.append(item);}
 if(!entries.length)scheduleRuns.textContent='Não há execuções registradas para este agendamento.';
}
function showScheduleHistory(row){selectedScheduleHistory=row.id;document.getElementById('schedule-history-context').textContent=(scheduleLabels[row.command_name]||'Serviço')+' · Agendamento #'+row.id;renderScheduleHistory();scheduleHistory.showModal();}
document.getElementById('schedule-history-close').addEventListener('click',()=>scheduleHistory.close());
scheduleHistory.addEventListener('close',()=>{selectedScheduleHistory=null;});
document.getElementById('schedule-refresh').addEventListener('click',loadSchedules);
scheduleDialog.addEventListener('close',()=>{scheduleForm.elements.password.value='';scheduleForm.elements.confirmed.checked=false;});
scheduleForm.addEventListener('submit',async event=>{event.preventDefault();if(scheduleBusy)return;scheduleBusy=true;const submit=scheduleForm.querySelector('[type=submit]');submit.disabled=true;const data=Object.fromEntries(new FormData(scheduleForm));data.weekdays=[...scheduleForm.querySelectorAll('[name=weekdays]:checked')].map(x=>Number(x.value));data.confirmed=scheduleForm.elements.confirmed.checked;try{await scheduleApi('save',data);scheduleMessage.textContent='Serviço agendado.';closeScheduleCreate();await loadSchedules();}catch(e){scheduleMessage.textContent=plain(e.message);}finally{scheduleBusy=false;submit.disabled=false;}});
setInterval(()=>{if(!document.hidden&&scheduleDialog.open&&!scheduleBusy)loadSchedules()},10000);

/* Controle dos painéis no mesmo escopo do explorador e dos agendamentos. */
const stage=document.getElementById('arc-support-stage');
const workspace=document.querySelector('.arc-support-workspace');
const collapse=document.getElementById('arc-support-collapse');
const mobileMenu=window.matchMedia('(max-width:700px)');
if(!stage||!workspace)return;
const panels={dashboard:document.getElementById('arc-support-dashboard'),remote:document.getElementById('arc-remote-assistance-modal'),support:document.getElementById('arc-support-actions-pane'),tickets:document.getElementById('arc-support-tickets'),files:document.getElementById('file-transfer'),schedules:document.getElementById('support-schedules')};
const buttons=[...document.querySelectorAll('.arc-support-mosaic [data-support-pane]')];
const intro=document.getElementById('arc-support-intro');
if(panels.files){stage.append(panels.files);panels.files.hidden=true;}
if(panels.support){stage.append(panels.support);panels.support.hidden=true;const grid=document.getElementById('arc-support-moved-grid');if(grid){grid.hidden=false;panels.support.append(grid);}}
// BLOCO 01 — Modais de edição devem ficar fora dos painéis ocultáveis.
// Evita o modal inacessível quando a aba pai está hidden e evita diálogos aninhados.
for(const id of ['support-new','support-ticket','schedule-create-modal','schedule-history-modal']){
 const child=document.getElementById(id);
 if(child && child.parentElement!==document.body)document.body.append(child);
}
const isInline=dialog=>dialog instanceof HTMLDialogElement;
for(const [name,panel] of Object.entries(panels)){
 if(name==='dashboard'||name==='tickets'||name==='files'||name==='support'||!panel)continue;
 // Move native dialogs once into the support panel; show() is nonmodal on all screens.
 stage.append(panel);
 if(panel.open)panel.close();
}
let active=<?=json_encode($arcSupportPane)?>;
function activate(name){
 if(name==='schedules'){const search=document.getElementById('schedule-search');if(search)search.blur();}

 const button=buttons.find(b=>b.dataset.supportPane===name);
 if(!panels[name]||button?.disabled)return;
 active=name;
 const grid=document.getElementById('arc-support-moved-grid');if(grid&&(name==='dashboard'||name==='support')){grid.hidden=false;(name==='dashboard'?document.getElementById('arc-dashboard-tools'):panels.support).append(grid);}
 for(const [key,panel] of Object.entries(panels)){
  if(!panel)continue;
  if(key==='dashboard'||key==='tickets'||key==='files'||key==='support'){panel.hidden=key!==name;continue;}
  if(key==='remote'){
   // Não fechar o diálogo: o visualizador controla sua própria sessão de vídeo.
   // Apenas ocultar visualmente enquanto outras abas estão em primeiro plano.
   panel.classList.toggle('arc-pane-dormant',name!=='remote');
   if(name==='remote'&&!panel.open)panel.show();
   continue;
  }
  if(key===name){if(!panel.open)panel.show();}
  else if(panel.open)panel.close();
 }
 if(intro)intro.hidden=true;
 for(const b of buttons){const selected=b.dataset.supportPane===name;b.classList.toggle('is-active',selected);b.setAttribute('aria-pressed',String(selected));}
 if(name==='files'){fsInitialized=false;requestAnimationFrame(()=>{if(!panels.files.hidden){fsOpen('');}});}
 if(name==='schedules'){document.getElementById('schedule-refresh')?.click();requestAnimationFrame(()=>document.getElementById('schedule-new')?.focus({preventScroll:true}));}
}
buttons.forEach(button=>button.addEventListener('click',()=>activate(button.dataset.supportPane)));
document.querySelectorAll('[data-support-shortcut]').forEach(button=>button.addEventListener('click',()=>{const route={remote:'remoto',support:'suporte',files:'arquivos',tickets:'chamados',schedules:'agenda'}[button.dataset.supportShortcut];if(route)location.assign(base+'redes/'+route+'.php?'+new URLSearchParams({network:String(network),agent:String(fsAgentId())}));}));
document.getElementById('arc-dashboard-access')?.addEventListener('click',()=>location.assign(base+'redes/remoto.php?'+new URLSearchParams({network:String(network),agent:String(fsAgentId())})));
// BLOCO 02 — X acompanha o estado efetivo da sessão.
const remotePanel=panels.remote;
if(remotePanel){
 const bar=remotePanel.querySelector('.arc-remote-screen-head');
 const stop=document.getElementById('arc-remote-screen-stop');
 const send=document.getElementById('arc-remote-assistance-send');
 const remoteImage=document.getElementById('arc-remote-screen-image');
 const remoteState=document.getElementById('arc-remote-assistance-state');
 if(bar&&stop){
  let exit=document.getElementById('arc-remote-force-exit');
  if(!exit){exit=document.createElement('button');exit.type='button';exit.id='arc-remote-force-exit';exit.className='arc-remote-tool arc-remote-force-exit';exit.title='Encerrar sessão';exit.setAttribute('aria-label','Encerrar sessão remota');exit.innerHTML='<i class="fa fa-times" aria-hidden="true"></i>';bar.append(exit);}
  const confirmDialog=document.createElement('dialog');confirmDialog.id='arc-access-exit-confirm';confirmDialog.className='arc-access-exit-confirm';confirmDialog.innerHTML='<form method="dialog"><strong>Encerrar acesso?</strong><p>A conexão com o computador será finalizada.</p><div class="arc-access-exit-actions"><button type="submit" value="cancel">Cancelar</button><button type="submit" value="end">Encerrar</button></div></form>';document.body.append(confirmDialog);
  let requestPending=false;
  const loader=document.createElement('div');loader.id='arc-remote-loading';loader.hidden=true;loader.innerHTML='<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i><span>Conectando ao computador…</span>';remotePanel.querySelector('.arc-remote-screen-canvas')?.append(loader);
  const refreshExit=()=>{
   const stateText=(remoteState?.textContent||'').toLowerCase();
   const hasImage=!!remoteImage&&!remoteImage.hidden&&!!remoteImage.getAttribute('src');
   const sessionActive=hasImage||(!send?.hidden&&false)||requestPending||(!stop.hidden)||/conectad|sessão ativa|transmitindo|aguardando imagem|controle autorizado|recuperando/.test(stateText);
   if(/encerrad|recusad|expirad|aguardando solicitação|falha ao iniciar/.test(stateText)&&!hasImage){requestPending=false;}
   exit.hidden=!(sessionActive&& !/encerrad|recusad|expirad/.test(stateText));
   loader.hidden=!(requestPending&&!hasImage);
   if(hasImage){requestPending=false;loader.hidden=true;}
  };
  exit.addEventListener('click',()=>confirmDialog.showModal());
  confirmDialog.addEventListener('close',()=>{if(confirmDialog.returnValue==='end'){requestPending=false;stop.click();refreshExit();}});
  send?.addEventListener('click',()=>{requestPending=true;refreshExit();});
  const obs=new MutationObserver(refreshExit);[remoteState,remoteImage,stop,send].filter(Boolean).forEach(el=>obs.observe(el,{attributes:true,childList:true,characterData:true,subtree:true}));
  refreshExit();
  // Solicitação direta: sem confirmação extra na interface. Permissões do servidor permanecem.
  document.addEventListener('click',event=>{
   const button=event.target.closest('[data-open-dialog="arc-remote-assistance-modal"]');
   if(button&&button!==send)requestPending=true;
  });
 }
}

// BLOCO 01 — ARQUIVOS É UM PAINEL NATIVO DO SUPORTE, SEM MODAL OU ATALHO DUPLICADO.
function updateCollapse(){
 if(mobileMenu.matches)workspace.classList.add('is-collapsed');
 const folded=workspace.classList.contains('is-collapsed');
 if(!collapse)return;
 collapse.setAttribute('aria-expanded',String(!folded));collapse.setAttribute('aria-label',folded?'Expandir menu lateral':'Recolher menu lateral');
 collapse.title=folded?'Expandir menu':'Recolher menu';
 const icon=collapse.querySelector('i');if(icon)icon.className='fa '+(folded?'fa-angle-double-right':'fa-angle-double-left');
}
collapse?.addEventListener('click',()=>{if(mobileMenu.matches)return;workspace.classList.toggle('is-collapsed');updateCollapse();});
const syncMobileMenu=()=>{if(mobileMenu.matches)workspace.classList.add('is-collapsed');updateCollapse();};
mobileMenu.addEventListener('change',syncMobileMenu);
syncMobileMenu();
const nav=document.querySelector('.arc-support-mosaic');
nav?.addEventListener('keydown',e=>{
 if(!['ArrowUp','ArrowDown','Home','End'].includes(e.key))return;
 const available=buttons.filter(b=>!b.disabled);if(!available.length)return;
 let index=available.indexOf(document.activeElement);
 index=e.key==='Home'?0:e.key==='End'?available.length-1:(index+(e.key==='ArrowDown'?1:-1)+available.length)%available.length;
 available[index].focus();e.preventDefault();
});
document.getElementById('arc-agent-target')?.addEventListener('change',()=>{
 if(active==='files'){fsInitialized=false;fsOpen('');}
 if(active==='schedules')document.getElementById('schedule-refresh')?.click();
});

// Solicita atualização imediata pelo canal autenticado e mostra o estado real.
const forceUpdateBtn=document.getElementById('arc-agent-force-update');
const forceUpdateStatus=document.getElementById('arc-agent-force-update-status');
if(forceUpdateBtn){forceUpdateBtn.addEventListener('click',async()=>{
 const agent=Number(document.getElementById('arc-agent-target')?.value||0);
 if(!agent){forceUpdateStatus.textContent='Selecione um computador conectado.';return;}
 forceUpdateBtn.disabled=true;forceUpdateStatus.textContent='Enviando atualização forçada ao agente…';
 try{
  const sent=await json(base+'api/api.php?action=network-command',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent,command:'agent_update'})});
  const ident=Number(sent.command_id);forceUpdateStatus.textContent='Solicitação #'+ident+' enviada. Aguardando instalação e reinicialização…';
  for(let i=0;i<180;i++){
   await new Promise(resolve=>setTimeout(resolve,5000));
   const result=await json(base+'api/api.php?action=network-command-status&network_id='+network+'&agent_id='+agent);
   const record=(result.commands||[]).find(item=>Number(item.id)===ident);
   if(!record)continue;
   if(record.status==='completed'){forceUpdateStatus.textContent=record.result_text||'Agente atualizado e reiniciado.';return;}
   if(['failed','expired'].includes(record.status))throw Error(record.result_text||'A atualização não foi concluída.');
   forceUpdateStatus.textContent=record.status==='running'?'Atualização iniciada. Aguardando reinicialização do agente…':'Aguardando o agente receber a solicitação…';
  }
  forceUpdateStatus.textContent='Ainda sem confirmação. Verifique a conexão do computador e o histórico de comandos.';
 }catch(e){forceUpdateStatus.textContent='Falha na atualização: '+(e.message||String(e));}
 finally{forceUpdateBtn.disabled=false;}
});}
// Windows Update uses the authenticated queue and results reported by this agent.
const windowsTile=document.getElementById('arc-windows-update'),windowsDialog=document.getElementById('arc-windows-update-dialog');
if(windowsTile&&!windowsTile.disabled){
 const version=document.getElementById('arc-windows-version'),status=document.getElementById('arc-windows-status'),list=document.getElementById('arc-windows-updates'),feedback=document.getElementById('arc-windows-feedback'),install=document.getElementById('arc-windows-install'),target=document.getElementById('arc-agent-target');
 let state=null,timer=null,loading=false,generation=0;
 function text(message){status.textContent=message;feedback.textContent=message;}
 function render(data){
  state=data;version.textContent=String(data.report?.windows||data.windows||'Windows não informado');
  const busy=['pending','running'].includes(data.command?.status);windowsTile.disabled=busy;install.disabled=busy;document.getElementById('arc-windows-rescan').disabled=busy;
  const available=data.report?.mode==='scan'&&data.report.updates?.length>0;
  windowsTile.classList.toggle('arc-windows-available',!!available);list.replaceChildren();
  if(available){text((data.report.total||data.report.updates.length)+' atualização(ões) disponível(is).');for(const update of data.report.updates){const item=document.createElement('li'),name=document.createElement('strong'),detail=document.createElement('small');name.textContent=update.title;detail.textContent=(update.kb||[]).map(k=>'KB'+k).join(', ')+' · '+Math.ceil((update.size||0)/1048576)+' MB';item.append(name,detail);list.append(item);}install.hidden=false;install.textContent='Baixar e instalar';}
  else {install.hidden=true;if(busy)text('Windows Update em andamento…');else if(data.command?.status==='failed'||data.command?.status==='expired')text(data.command.message||'Não foi possível concluir. Tente novamente.');else if(data.report?.mode==='scan')text('O Windows está atualizado.'+(data.report.reboot_required?' Reinicialização pendente.':''));else if(data.report?.mode==='install'){text(data.command.message||'Confira o resultado da instalação.');for(const row of data.report.results||[]){const item=document.createElement('li');item.textContent=row.id+': '+(row.code===2?'Instalada':'Falha ou instalação incompleta (código '+row.code+')');list.append(item);}}else text('Verificar atualizações');}
  clearTimeout(timer);if(busy)timer=setTimeout(refreshWindows,2000);
 }
 async function refreshWindows(){const agent=Number(target.value),epoch=generation;if(!agent)return;try{const data=await json(base+'api/support.php?action=windows-update-status',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent})});if(epoch===generation&&agent===Number(target.value))render(data);}catch(e){if(epoch===generation)text(plain(e.message));}}
 async function requestWindows(mode){if(loading||!target.value||['pending','running'].includes(state?.command?.status))return;loading=true;const epoch=generation,agent=Number(target.value);windowsTile.disabled=true;install.disabled=true;text(mode==='scan'?'Verificando atualizações no computador…':'Baixando e instalando no computador…');try{const data=await json(base+'api/api.php?action=network-command',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:agent,command:'windows_update',update_mode:mode,scan_id:state?.command?.id,confirmed:mode==='install'})});if(epoch===generation){state={command:{id:data.command_id,status:'pending'}};await refreshWindows();}}catch(e){if(epoch===generation){text(plain(e.message));windowsTile.disabled=false;install.disabled=false;}}finally{loading=false;}}
 windowsTile.addEventListener('click',()=>{if(state?.report?.mode==='scan'&&state.report.updates?.length){windowsDialog.showModal();return;}if(state?.report?.mode==='install'){windowsDialog.showModal();return;}requestWindows('scan');});
 install.addEventListener('click',()=>requestWindows('install'));
 document.getElementById('arc-windows-rescan').addEventListener('click',()=>requestWindows('scan'));
 document.getElementById('arc-windows-close').addEventListener('click',()=>windowsDialog.close());
 target.addEventListener('change',()=>{generation++;state=null;clearTimeout(timer);windowsDialog.close();windowsTile.classList.remove('arc-windows-available');windowsTile.disabled=false;version.textContent='Windows: verificando…';text('Verificar atualizações');refreshWindows();});
 refreshWindows();
}


activate(active);

})();
</script>





























<style>
/* Caminho: redes/_support_workspace.php */
/* Estilos nativos consolidados do suporte; sem blocos de sobreposição por versão. */
.arc-fs-view-switch{display:flex;gap:3px}.arc-fs-modified{flex:0 1 145px!important;text-align:right;min-width:0}.arc-fs-thumb{width:44px;height:40px;object-fit:cover;flex:0 0 44px;border-radius:5px}.arc-files-workspace #arc-fs-entries[data-view="grid"] .arc-fs-thumb{width:100%;height:96px;flex:auto}.arc-transfer-dock progress:indeterminate{animation:arc-transfer-pulse 1s ease-in-out infinite alternate}@keyframes arc-transfer-pulse{to{opacity:.4}}@media(max-width:700px){.arc-files-workspace .arc-fs-modified{display:none}.arc-files-workspace #arc-fs-entries[data-view="details"] .arc-fs-size{flex:0 0 60px!important;font-size:10px}}.arc-files-workspace #arc-fs-entries[data-view="details"] .arc-fs-size{flex:0 0 85px;text-align:right}.arc-files-workspace #arc-fs-entries[data-view="grid"] .arc-fs-size{display:none}.arc-files-workspace #arc-fs-entries[data-view="list"] .arc-fs-label small{display:none}.arc-files-icon[aria-pressed="true"]{border-color:#56b4e8;background:#234d70;color:#fff}
.arc-files-workspace #arc-fs-entries[data-view="grid"]{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));align-content:start;gap:8px}.arc-files-workspace #arc-fs-entries[data-view="grid"] .arc-fs-item{min-height:115px;flex-direction:column;align-items:center;justify-content:center}.arc-files-workspace #arc-fs-entries[data-view="grid"] .arc-fs-item>i{font-size:30px}.arc-files-workspace #arc-fs-entries[data-view="list"] .arc-fs-item{min-height:38px;padding:5px 10px!important}.arc-files-workspace #arc-fs-entries[data-view="list"] .arc-fs-item>i{font-size:19px}.arc-transfer-history{max-height:110px;overflow:auto}.arc-transfer-history-item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 10px;border-top:1px solid #294763;color:#c9dceb;font-size:11px}.arc-transfer-history-item button{border:1px solid #496d8c;border-radius:5px;background:#1d3a57;color:#e6f3ff;padding:4px 8px;cursor:pointer}.arc-transfer-dock{position:fixed;right:12px;bottom:85px;z-index:10010;width:min(360px,calc(100% - 28px));padding:12px;border:1px solid #5483a9;border-radius:9px;background:#142943;box-shadow:0 8px 28px #0008;color:#edf7ff}.arc-transfer-dock[hidden]{display:none!important}.arc-transfer-dock-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.arc-transfer-dock progress{width:100%;height:8px;margin:8px 0}.arc-transfer-dock small{display:block;color:#bad0e0;font-size:11px}

.arc-file-browser { border: 1px solid rgb(49, 81, 115); border-radius: 11px; padding: 10px; margin: 8px 0px; background: rgb(14, 28, 48); }
.arc-fs-current { padding: 9px; color: rgb(142, 219, 243); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.arc-fs-entries { display: grid; gap: 5px; max-height: 42vh; min-height: 90px; overflow: auto; }
.arc-fs-item { display: flex; align-items: center; gap: 10px; width: 100%; color: rgb(228, 242, 255); background: rgb(25, 46, 70); border: 1px solid rgb(48, 74, 101); border-radius: 7px; padding: 9px 11px; text-align: left; cursor: pointer; }
.arc-fs-item i { color: rgb(84, 196, 243); }
.arc-fs-item span { flex: 1 1 0%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.arc-fs-item small { color: rgb(179, 196, 214); }
.arc-fs-item:disabled { opacity: 0.75; cursor: default; }
#arc-fs-state { font-size: 12px; color: rgb(168, 201, 216); margin: 8px 0px 0px; }
.arc-files-workspace { width: 100%; height: 100%; background: rgb(15, 28, 46); border: 1px solid rgb(43, 68, 99); border-radius: 12px; overflow: hidden; }
.arc-files-workspace[hidden] { display: none !important; }
.arc-files-header { display: flex; flex-direction: column; background: rgb(27, 42, 66); border-bottom: 1px solid rgb(48, 75, 103); }
.arc-files-title { display: flex; align-items: center; gap: 9px; color: rgb(231, 242, 255); min-width: 0px; }
.arc-files-title i { color: rgb(80, 197, 240); }
.arc-files-title span { color: rgb(154, 187, 208); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0px; }
.arc-files-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.arc-files-actions .button { font-size: 12px; padding: 8px 10px; margin: 0px; }
.arc-files-icon { border: 1px solid rgb(51, 83, 112); border-radius: 7px; background: rgb(20, 38, 61); color: rgb(146, 215, 251); min-width: 34px; height: 34px; cursor: pointer; }
.arc-files-workspace .arc-fs-entries { flex: 1 1 0%; max-height: none; }
.arc-files-workspace .arc-fs-item { width: 100%; }
.arc-files-workspace .arc-fs-item .fa-folder { color: rgb(225, 180, 81); }
.arc-files-workspace .arc-fs-item:disabled { cursor: default; }
.arc-file-transfers { border-top: 1px solid rgb(55, 81, 107); padding: 9px; max-height: 40%; overflow: auto; }
.arc-file-transfers[hidden] { display: none !important; }
@media (max-width: 700px) {
.arc-files-header { padding: 8px; }
.arc-files-actions .button { flex: 1 1 0%; min-width: 130px; font-size: 11px; }
.arc-files-workspace { min-height: 50vh; }
}
.arc-files-workspace .arc-fs-entries { align-content: start; }
.arc-files-workspace .arc-fs-item:hover:not(:disabled) { border-color: rgb(71, 116, 155); }
.arc-files-workspace .arc-fs-label strong { font-weight: 550; }
.arc-files-workspace .arc-fs-drive { max-width: 460px; }
.arc-files-workspace .arc-drive-meter { width: 100%; }
.arc-files-workspace .arc-drive-meter > span { display: block; height: 100%; background: linear-gradient(90deg, rgb(48, 182, 220), rgb(89, 209, 237)); border-radius: 5px; }
@media (min-width: 900px) {
.arc-files-workspace .arc-fs-entries:has(.arc-fs-drive) { display: grid; grid-template-columns: repeat(2, minmax(0px, 1fr)); align-content: start; }
.arc-files-workspace .arc-fs-drive { max-width: none; }
}
.arc-files-toolbar { display: flex; justify-content: space-between; align-items: center; background: rgb(19, 34, 56); border-bottom: 1px solid rgb(45, 72, 100); flex-wrap: wrap; }
.arc-files-selection { color: rgb(169, 199, 220); font-size: 12px; }
.arc-files-selection.is-active { color: rgb(223, 245, 255); font-weight: 700; }
.arc-files-hint { color: rgb(127, 167, 191); font-size: 11px; }
.arc-files-actions .arc-files-icon { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 10px; background: rgb(22, 43, 67); border: 1px solid rgb(49, 80, 109); color: rgb(213, 236, 255); box-shadow: rgba(255, 255, 255, 0.03) 0px 1px 0px inset; }
.arc-files-actions .arc-files-icon[disabled] { opacity: 0.45; cursor: not-allowed; }
.arc-files-workspace .arc-fs-entries { background: linear-gradient(rgb(17, 29, 46), rgb(20, 34, 54)); }
.arc-files-workspace .arc-fs-item { position: relative; grid-template-columns: 26px 42px minmax(0px, 1fr) auto auto; background: transparent; }
.arc-files-workspace .arc-fs-item:nth-child(2n) { background: rgba(255, 255, 255, 0.02); }
.arc-files-workspace .arc-fs-item:hover:not(:disabled) { background: rgb(32, 58, 89); }
.arc-fs-item.is-selected { box-shadow: rgb(89, 199, 255) 0px 0px 0px 1px inset; background: rgb(37, 75, 115) !important; }
.arc-fs-check { width: 18px; height: 18px; border-radius: 4px; border: 1px solid rgb(85, 128, 163); background: rgb(16, 33, 49); display: flex; align-items: center; justify-content: center; color: rgb(18, 40, 59); font-size: 11px; }
.arc-fs-item.is-selected .arc-fs-check { background: rgb(99, 208, 255); color: rgb(7, 33, 53); border-color: rgb(130, 219, 255); }
.arc-files-workspace .arc-fs-item > i { font-size: 28px; min-width: 0px; color: rgb(225, 181, 83); }
.arc-files-workspace .arc-fs-item.arc-fs-drive > i { font-size: 26px; color: rgb(120, 200, 255); }
.arc-files-workspace .arc-fs-item .fa-file-image-o { color: rgb(133, 211, 255); }
.arc-files-workspace .arc-fs-item .fa-file-video-o, .arc-files-workspace .arc-fs-item .fa-file-audio-o { color: rgb(143, 211, 166); }
.arc-files-workspace .arc-fs-item .fa-file-archive-o { color: rgb(240, 204, 114); }
.arc-files-workspace .arc-fs-item .fa-file-pdf-o { color: rgb(255, 125, 125); }
.arc-files-workspace .arc-fs-item .fa-file-word-o { color: rgb(132, 191, 255); }
.arc-files-workspace .arc-fs-item .fa-file-excel-o { color: rgb(125, 211, 154); }
.arc-files-workspace .arc-fs-item .fa-file-code-o, .arc-files-workspace .arc-fs-item .fa-terminal, .arc-files-workspace .arc-fs-item .fa-cogs { color: rgb(158, 199, 255); }
.arc-files-workspace .arc-fs-label { display: flex; flex-direction: column; }
.arc-files-workspace .arc-fs-label strong { font-size: 13px; }
.arc-files-workspace .arc-fs-label small { font-size: 11px; color: rgb(155, 184, 207); overflow: hidden; text-overflow: ellipsis; }
.arc-fs-size { color: rgb(229, 238, 248); font-size: 12px; white-space: nowrap; }
.arc-fs-run { padding: 5px 10px; border-radius: 999px; border: 1px solid rgb(72, 106, 136); background: rgb(24, 49, 73); color: rgb(202, 236, 255); font-size: 11px; white-space: nowrap; }
.arc-files-feedback { min-height: 30px; padding: 7px 12px; border-top: 1px solid rgb(40, 71, 97); color: rgb(167, 217, 199); font-size: 12px; background: rgb(16, 33, 52); }
.arc-files-workspace #arc-fs-state { margin-right: 0px; margin-bottom: 0px; margin-left: 0px; }
@media (max-width: 700px) {
.arc-files-toolbar { padding: 8px 10px; }
.arc-fs-run { display: none; }
.arc-fs-size { font-size: 11px; }
}
.arc-files-header { padding: 8px 10px; gap: 7px; }
.arc-files-address { display: flex; align-items: center; min-width: 0px; gap: 7px; }
.arc-files-crumbs { display: flex; align-items: center; gap: 3px; min-width: 0px; flex: 1 1 0%; overflow-x: auto; scrollbar-width: none; white-space: nowrap; border: 1px solid rgb(53, 81, 109); border-radius: 8px; padding: 2px 7px; background: rgb(18, 35, 58); }
.arc-files-crumbs::-webkit-scrollbar { display: none; }
.arc-fs-crumb { font-style: inherit; font-variant: inherit; font-weight: inherit; font-stretch: inherit; line-height: inherit; font-family: inherit; font-optical-sizing: inherit; font-size-adjust: inherit; font-kerning: inherit; font-feature-settings: inherit; font-variation-settings: inherit; font-language-override: inherit; font-size: 12px; background: none; border: 0px; color: rgb(214, 234, 250); padding: 7px 5px; cursor: pointer; white-space: nowrap; }
.arc-fs-crumb:hover { background: rgb(38, 62, 90); border-radius: 5px; }
.arc-fs-crumb i { margin-right: 5px; color: rgb(105, 196, 240); }
.arc-fs-separator { font-size: 10px; color: rgb(130, 153, 177); }
.arc-files-toolbar[hidden] { display: none !important; }
.arc-files-toolbar { padding: 5px 10px; min-height: 0px; gap: 5px; }
.arc-files-workspace .arc-fs-entries { padding: 6px 8px; display: flex; flex-direction: column; gap: 3px; overflow: auto; }
.arc-files-workspace .arc-fs-item { align-items: center; min-height: 52px; height: auto; border: 1px solid transparent; border-radius: 8px; text-align: left; white-space: normal; display: flex !important; max-width: none !important; padding: 10px !important; }
.arc-files-workspace .arc-fs-item.arc-fs-drive { grid-template-columns: 35px minmax(0px, 1fr); width: 100%; gap: 10px; min-height: 87px; background: rgb(24, 45, 69); border: 1px solid rgb(50, 78, 107); display: grid !important; }
.arc-files-workspace .arc-fs-item > i { flex: 0 0 31px; text-align: center; }
.arc-files-workspace .arc-fs-label { flex: 1 1 auto; min-width: 80px; overflow: hidden; padding: 0px; gap: 5px; }
.arc-files-workspace .arc-fs-label strong { display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35; }
.arc-files-workspace .arc-fs-label small { white-space: normal; overflow-wrap: anywhere; }
.arc-files-workspace .arc-fs-drive .arc-fs-label strong { font-weight: 600; }
.arc-files-workspace .arc-drive-meter { height: 6px; max-width: 100%; border-radius: 4px; background: rgb(51, 72, 96); overflow: hidden; }
.arc-files-workspace .arc-drive-meter span { display: block; height: 100%; background: rgb(66, 189, 232); }
.arc-files-workspace .arc-fs-size { flex-shrink: 0; }
.arc-files-workspace .arc-fs-item:disabled { opacity: 1; }
.arc-files-workspace .arc-fs-entries:has(.arc-fs-drive) { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr)); align-content: start; gap: 8px; }
@media (max-width: 700px) {
.arc-files-workspace .arc-fs-item { grid-template-columns: unset !important; }
.arc-files-workspace .arc-fs-item.arc-fs-drive { grid-template-columns: 30px minmax(0px, 1fr) !important; }
.arc-files-workspace .arc-fs-entries:has(.arc-fs-drive) { grid-template-columns: 1fr; }
.arc-files-workspace .arc-fs-item { padding: 10px 8px !important; }
.arc-files-crumbs { min-width: 0px; }
}
.arc-files-workspace { min-height: 0px; display: flex; flex-direction: column; }
.arc-files-workspace .arc-files-header { align-items: center; gap: 6px; min-height: 48px; display: flex !important; flex-flow: row !important; padding: 7px 9px !important; }
.arc-files-workspace .arc-files-address { min-width: 0px; flex: 1 1 0%; display: flex; align-items: center; }
.arc-files-workspace .arc-files-crumbs { min-width: 0px; flex: 1 1 0%; overflow-x: auto; padding: 2px 4px; }
.arc-files-workspace .arc-fs-crumb { font-size: 12px; padding: 6px 5px; display: inline-flex; align-items: center; gap: 3px; }
.arc-files-workspace .arc-files-actions { display: flex; flex: 0 0 auto; gap: 4px; margin: 0px; flex-wrap: nowrap !important; }
.arc-files-workspace .arc-files-icon { border-radius: 7px; width: 32px !important; min-width: 32px !important; height: 34px !important; padding: 0px !important; }
.arc-files-workspace .arc-files-actions #arc-fs-back { order: 10; }
.arc-files-workspace .arc-fs-check { flex: 0 0 18px; aspect-ratio: 1 / 1; align-items: center; justify-content: center; padding: 0px; border-radius: 5px; width: 18px !important; min-width: 18px !important; max-width: 18px !important; height: 18px !important; display: inline-flex !important; }
.arc-files-workspace .arc-fs-item { gap: 8px !important; }
.arc-files-workspace .arc-files-toolbar { flex: 0 0 auto; }
.arc-files-workspace #arc-fs-entries { flex: 1 1 auto; min-height: 0px; overflow: auto; }
.arc-files-workspace #arc-fs-state { flex: 0 0 20px; line-height: 14px; color: rgb(131, 152, 173); background: rgb(14, 25, 41); border-top: 1px solid rgb(42, 64, 87); margin-top: auto !important; min-height: 20px !important; padding: 3px 10px !important; font-size: 10px !important; }
.arc-files-workspace #file-feedback:empty { display: none; }
@media (max-width: 700px) {
.arc-files-workspace .arc-files-header { gap: 4px; padding: 5px !important; }
.arc-files-workspace .arc-files-icon { width: 30px !important; min-width: 30px !important; height: 32px !important; }
.arc-files-workspace .arc-files-crumbs { padding: 1px 3px; }
.arc-files-workspace .arc-fs-item { gap: 8px !important; }
}
.arc-files-workspace .arc-fs-empty-panel { flex: 1 1 0%; min-height: 180px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 12px; padding: 24px; text-align: center; color: rgb(168, 199, 220); font-size: 13px; }
.arc-files-workspace .arc-fs-empty-panel > i { font-size: 29px; color: rgb(107, 198, 240); }
.arc-files-workspace .arc-fs-load-button { border: 1px solid rgb(69, 136, 174); border-radius: 10px; background: rgb(28, 67, 99); color: rgb(239, 250, 255); font-weight: 650; padding: 10px 17px; cursor: pointer; }
.arc-files-workspace .arc-fs-load-button:hover { background: rgb(37, 86, 119); }
@media (max-width: 700px) {
.arc-files-workspace .arc-fs-empty-panel { min-height: 140px; padding: 15px; }
}
.arc-files-workspace { position: relative; }
.arc-files-workspace #arc-fs-center-load { position: absolute; top: 53%; left: 50%; transform: translate(-50%, -50%); z-index: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; min-width: 180px; min-height: 108px; padding: 15px 20px; border: 1px solid rgb(65, 108, 145); border-radius: 12px; background: rgb(27, 54, 82); color: rgb(229, 246, 255); cursor: pointer; box-shadow: rgba(0, 0, 0, 0.2) 0px 8px 24px; }
.arc-files-workspace #arc-fs-center-load i { font-size: 27px; color: rgb(105, 201, 250); }
.arc-files-workspace #arc-fs-center-load:focus-visible { outline: rgb(113, 214, 255) solid 2px; outline-offset: 3px; }
.arc-files-workspace:has(#arc-fs-entries .arc-fs-item) #arc-fs-center-load, .arc-files-workspace:has(#arc-fs-entries .fa-spin) #arc-fs-center-load, .arc-files-workspace:has(#arc-fs-entries .fa-folder-open-o) #arc-fs-center-load { display: none; }
@media (max-width: 700px) {
.arc-files-workspace #arc-fs-center-load { min-width: 150px; min-height: 94px; padding: 12px 16px; }
}
.arc-support-actions-pane { height: 100%; min-height: 0px; overflow: auto; padding: 12px; background: rgb(16, 29, 48); box-sizing: border-box; }
.arc-support-actions-pane[hidden] { display: none !important; }
.arc-support-actions-pane .arc-support-action-grid { margin: 0px auto; width: 100%; max-width: 100%; align-content: start; }
#arc-remote-screen-placeholder .arc-access-only { display: flex; justify-content: center; align-items: center; min-height: 200px; width: 100%; height: 100%; }
#arc-remote-screen-placeholder .arc-access-only #arc-remote-assistance-send { width: min(245px, 70%); min-width: 0px; min-height: 145px; }
#arc-remote-screen-placeholder .arc-access-only #arc-remote-assistance-send i { font-size: 46px; color: rgb(91, 197, 242); }
@media (max-width: 700px) {
.arc-support-actions-pane { padding: 8px; }
.arc-support-actions-pane .arc-support-action-grid { gap: 9px; }
}
.arc-support-stage .arc-support-tickets { padding: 6px; gap: 5px; min-height: 0px; overflow: hidden; }
.arc-support-tickets .arc-filter-bar { display: flex; align-items: center; gap: 5px; flex-wrap: nowrap; margin: 0px; padding: 5px; border: 1px solid rgb(43, 73, 103); border-radius: 9px; background: rgba(24, 43, 66, 0.72); }
.arc-support-tickets .arc-filter-bar input[type="search"] { min-width: 0px; flex: 1 1 0%; width: 0px; height: 34px; padding: 5px 8px; border-radius: 6px; }
.arc-support-tickets .arc-filter-bar select { flex: 0 1 122px; min-width: 75px; max-width: 122px; height: 34px; padding: 3px; font-size: 12px; }
.arc-support-tickets .arc-filter-bar .arc-icon-action { width: 34px; height: 34px; min-width: 34px; padding: 5px; display: inline-grid; place-items: center; }
.arc-support-tickets .arc-support-queue { gap: 5px; }
.arc-support-tickets .arc-support-queue > .arc-service-list { gap: 4px; overflow: auto; scrollbar-width: none; }
.arc-support-tickets .arc-support-queue > .arc-service-list::-webkit-scrollbar { display: none; }
.arc-support-tickets .arc-support-queue > .arc-service-list > .arc-service-ticket { display: grid; grid-template-columns: minmax(0px, 1fr) auto; align-items: center; gap: 7px; min-height: 48px; padding: 7px 9px; border: 1px solid rgb(48, 75, 103); border-radius: 8px; background: rgba(27, 46, 70, 0.73); text-decoration: none; }
.arc-support-tickets .arc-service-ticket > div { min-width: 0px; }
.arc-support-tickets .arc-service-ticket strong { font-size: 12px; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.arc-support-tickets .arc-service-ticket p { font-size: 10px; margin: 3px 0px 0px; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.arc-support-tickets .arc-service-ticket > div:last-child { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.arc-support-tickets .arc-service-ticket small { font-size: 9px; white-space: nowrap; }
.arc-support-tickets .arc-service-actions { padding: 3px 6px; min-height: 30px; }
#support-new, #support-ticket, #schedule-create-modal, #schedule-history-modal { padding: 0px; border: 1px solid rgb(66, 98, 127); background: rgb(24, 41, 64); color: rgb(231, 241, 255); }
#support-new::backdrop, #support-ticket::backdrop, #schedule-create-modal::backdrop, #schedule-history-modal::backdrop { background: rgba(3, 11, 22, 0.7); backdrop-filter: blur(5px); }
#support-new .arc-service-heading, #support-ticket .arc-service-heading, #schedule-create-modal .arc-schedule-modal-header, #schedule-history-modal .arc-schedule-modal-header { position: sticky; top: 0px; z-index: 4; display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 0px; padding: 11px 13px; background: rgb(32, 54, 80); border-bottom: 1px solid rgb(54, 86, 116); }
#support-new .arc-service-heading h2, #support-ticket .arc-service-heading h2, #schedule-create-modal h2, #schedule-history-modal h2 { font-size: 15px; line-height: 1.25; margin: 0px; min-width: 0px; overflow: hidden; text-overflow: ellipsis; }
#support-new .arc-service-form, #support-ticket .arc-service-form, #schedule-create-modal .arc-service-form { display: flex; flex-direction: column; gap: 8px; padding: 12px; }
#support-new .arc-service-form label, #support-ticket .arc-service-form label, #schedule-create-modal .arc-service-form label { font-size: 12px; min-width: 0px; }
#support-new :is(input, select, textarea), #support-ticket :is(input, select, textarea), #schedule-create-modal :is(input, select, textarea) { max-width: 100%; width: 100%; min-height: 35px; padding: 7px 9px; box-sizing: border-box; border-radius: 7px; }
#support-new textarea, #support-ticket textarea { min-height: 75px; resize: vertical; }
#support-new .arc-service-fields, #support-ticket .arc-service-fields, #schedule-create-modal .arc-service-fields { display: grid; grid-template-columns: repeat(2, minmax(0px, 1fr)); gap: 8px; }
#support-ticket > .arc-service-prose, #support-ticket > p, #support-ticket > h3, #support-ticket > .arc-service-timeline { margin: 8px 12px; }
#support-ticket > .arc-service-timeline { max-height: 180px; overflow: auto; }
.arc-support-stage > .arc-schedules-dialog[open] { display: flex; flex-direction: column; flex: 1 1 0%; min-height: 0px; padding: 6px; gap: 5px; border: 0px; box-shadow: none; background: transparent; }
.arc-schedules-dialog .arc-schedule-toolbar { margin: 0px; padding: 6px; background: rgb(25, 43, 66); border: 1px solid rgb(48, 76, 105); border-radius: 9px; flex: 0 0 auto; }
.arc-schedules-dialog .arc-schedule-toolbar input[type="search"] { height: 34px; padding: 5px 8px; }
.arc-schedules-dialog .arc-schedule-toolbar select { width: 110px; height: 34px; padding: 3px; font-size: 11px; }
.arc-schedules-dialog .arc-schedule-toolbar .arc-icon-action { width: 34px; height: 34px; min-width: 34px; display: grid; place-items: center; padding: 5px; }
.arc-schedules-dialog .arc-schedule-grid { border: 1px solid rgb(48, 76, 105); border-radius: 9px; background: rgb(23, 40, 62); }
.arc-schedules-dialog .arc-schedule-table { width: 100%; min-width: 450px; border-collapse: collapse; font-size: 11px; }
.arc-schedules-dialog .arc-schedule-table :is(th, td) { padding: 9px 7px; vertical-align: middle; border-bottom: 1px solid rgb(45, 70, 98); }
.arc-schedules-dialog .arc-schedule-table thead { position: sticky; top: 0px; background: rgb(34, 59, 88); z-index: 2; }
.arc-schedules-dialog .arc-schedule-actions { white-space: nowrap; }
#schedule-create-modal .arc-schedule-modal-header p { font-size: 11px; margin: 3px 0px 0px; }
#schedule-create-modal .arc-schedule-weekdays { gap: 4px; display: flex; flex-wrap: wrap; }
#schedule-create-modal .arc-schedule-weekdays label { white-space: nowrap; font-size: 11px; width: auto !important; }
#schedule-create-modal .arc-schedule-consent { display: flex; gap: 7px; align-items: flex-start; }
#schedule-create-modal .arc-schedule-consent input { width: auto; min-height: auto; margin-top: 3px; }
@media (max-width: 700px) {
.arc-support-tickets .arc-filter-bar select { max-width: 88px; min-width: 65px; font-size: 10px; }
.arc-support-tickets .arc-filter-bar .arc-icon-action { min-width: 30px; width: 30px; height: 32px; }
.arc-support-tickets .arc-service-ticket strong { font-size: 11px; }
.arc-support-tickets .arc-service-ticket p { font-size: 9px; }
.arc-support-tickets .arc-service-ticket > div:last-child { max-width: 105px; }
.arc-schedules-dialog .arc-schedule-toolbar select { width: 84px; min-width: 65px; }
.arc-schedules-dialog .arc-schedule-toolbar .arc-icon-action { width: 30px; min-width: 30px; height: 32px; }
}
#arc-remote-assistance-modal.arc-pane-dormant { display: none !important; visibility: hidden !important; pointer-events: none !important; }
#arc-remote-force-exit { flex: 0 0 30px; min-width: 30px; width: 30px; height: 30px; padding: 4px; border: 1px solid rgb(142, 76, 93); border-radius: 8px; color: rgb(255, 185, 191); background: rgb(75, 36, 50); display: inline-grid; place-items: center; margin-left: auto; cursor: pointer; }
#arc-remote-force-exit:hover { background: rgb(113, 48, 66); color: rgb(255, 255, 255); }
#support-new, #support-ticket, #schedule-create-modal, #schedule-history-modal { position: fixed; inset: 0px; margin: auto; box-sizing: border-box; max-width: min(610px, -20px + 100vw); width: min(610px, -20px + 100vw); max-height: min(86dvh, 760px); overflow: auto; border-radius: 12px; z-index: 30000; box-shadow: rgba(0, 0, 0, 0.667) 0px 20px 80px; }
#support-new:not([open]), #support-ticket:not([open]), #schedule-create-modal:not([open]), #schedule-history-modal:not([open]) { display: none !important; }
.arc-support-tickets .arc-service-list { min-height: 0px; flex: 1 1 0%; overflow-y: auto; }
.arc-support-tickets .arc-service-ticket { overflow: hidden; }
@media (max-width: 700px) {
#support-new, #support-ticket, #schedule-create-modal, #schedule-history-modal { width: calc(-18px + 100vw); max-width: calc(-18px + 100vw); max-height: 83dvh; font-size: 12px; }
#support-new .arc-service-heading, #support-ticket .arc-service-heading, #schedule-create-modal .arc-schedule-modal-header, #schedule-history-modal .arc-schedule-modal-header { padding: 9px 10px; }
#support-new .arc-service-form, #support-ticket .arc-service-form, #schedule-create-modal .arc-service-form { padding: 9px; gap: 7px; }
#support-new .arc-service-fields, #support-ticket .arc-service-fields, #schedule-create-modal .arc-service-fields { grid-template-columns: minmax(0px, 1fr); }
#support-new :is(input, select, textarea), #support-ticket :is(input, select, textarea), #schedule-create-modal :is(input, select, textarea) { font-size: 16px; max-width: 100%; }
.arc-support-tickets .arc-filter-bar { gap: 4px; padding: 5px; }
.arc-support-tickets .arc-service-ticket { min-height: 44px; padding: 6px 7px; }
.arc-schedules-dialog .arc-schedule-toolbar :is(button, select) { flex: 0 0 auto; min-width: 30px; }
}
#arc-remote-force-exit[hidden], #arc-remote-loading[hidden] { display: none !important; }
#arc-remote-loading { position: absolute; inset: 0px; display: flex; align-items: center; justify-content: center; gap: 9px; background: rgba(10, 25, 44, 0.87); color: rgb(189, 232, 255); font-size: 12px; z-index: 8; pointer-events: none; }
#arc-remote-loading i { color: rgb(84, 198, 245); font-size: 21px; }
.arc-access-exit-confirm { position: fixed; margin: auto; inset: 0px; width: min(315px, -28px + 100vw); max-width: calc(-28px + 100vw); border: 1px solid rgb(66, 100, 130); border-radius: 12px; background: rgb(26, 43, 65); color: rgb(233, 244, 255); padding: 16px; box-shadow: rgba(0, 0, 0, 0.6) 0px 15px 45px; z-index: 31000; }
.arc-access-exit-confirm::backdrop { background: rgba(0, 7, 18, 0.55); }
.arc-access-exit-confirm strong { font-size: 15px; }
.arc-access-exit-confirm p { font-size: 12px; color: rgb(185, 202, 221); margin: 7px 0px 15px; }
.arc-access-exit-actions { display: flex; gap: 8px; }
.arc-access-exit-actions button { flex: 1 1 0%; padding: 10px 8px; border: 1px solid rgb(67, 103, 134); border-radius: 8px; background: rgb(34, 58, 85); color: rgb(230, 245, 255); font-weight: 600; cursor: pointer; }
.arc-access-exit-actions button[value="end"] { background: rgb(116, 49, 66); border-color: rgb(172, 97, 112); }
@media (max-width: 700px) {
.arc-schedules-dialog .arc-schedule-grid { min-width: 0px; width: 100%; }
.arc-schedules-dialog .arc-schedule-table { table-layout: fixed; }
.arc-schedules-dialog .arc-schedule-table thead { display: none; }
.arc-schedules-dialog .arc-schedule-table tbody tr { grid-template-columns: repeat(2, minmax(0px, 1fr)); }
.arc-schedules-dialog .arc-schedule-table tbody tr td { min-width: 0px; width: auto; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:first-child { grid-column: 1 / -1; font-weight: 700; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:last-child { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 5px; align-items: center; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:last-child::before { margin-right: auto; align-self: center; }
.arc-schedules-dialog .arc-schedule-toolbar { max-width: 100%; box-sizing: border-box; overflow: hidden; }
}
.arc-support-stage > .arc-schedules-dialog[open] { width: 100%; max-width: 100%; min-width: 0px; box-sizing: border-box; overflow: hidden; }
.arc-schedules-dialog .arc-schedule-grid { flex: 1 1 0%; min-height: 0px; min-width: 0px; max-width: 100%; overflow: auto; }
.arc-schedules-dialog .arc-schedule-toolbar { width: 100%; max-width: 100%; box-sizing: border-box; display: flex; align-items: center; gap: 5px; flex-wrap: nowrap; }
.arc-schedules-dialog .arc-schedule-toolbar input[type="search"] { width: 1%; min-width: 0px; flex: 1 1 auto; outline-offset: 0px; }
.arc-schedules-dialog .arc-schedule-toolbar select { flex: 0 0 80px; min-width: 0px; max-width: 80px; }
.arc-schedules-dialog .arc-schedule-toolbar button { flex: 0 0 32px; min-width: 32px; width: 32px; height: 32px; padding: 3px; }
@media (max-width: 700px) {
.arc-schedules-dialog .arc-schedule-grid { box-sizing: border-box; padding: 5px !important; overflow: hidden auto !important; }
.arc-schedules-dialog .arc-schedule-table { display: block !important; min-width: 0px !important; width: 100% !important; border: 0px !important; }
.arc-schedules-dialog .arc-schedule-table tbody { flex-direction: column; gap: 8px; min-width: 0px; width: 100%; display: flex !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr { align-items: flex-start; gap: 0px 10px; box-sizing: border-box; border: 1px solid rgb(55, 85, 112); border-radius: 10px; background: rgb(27, 48, 74); display: flex !important; flex-wrap: wrap !important; width: 100% !important; min-width: 0px !important; height: auto !important; padding: 10px !important; overflow: visible !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td { display: block !important; position: static !important; float: none !important; box-sizing: border-box !important; height: auto !important; min-height: 0px !important; max-width: 100% !important; padding: 5px 0px !important; margin: 0px !important; border: 0px !important; white-space: normal !important; overflow-wrap: anywhere !important; text-align: left !important; line-height: 1.35 !important; font-size: 12px !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(1) { flex: 0 0 100%; font-weight: 700; font-size: 13px !important; padding-bottom: 8px !important; border-bottom: 1px solid rgb(58, 87, 116) !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(2), .arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(3) { flex: 1 1 calc(50% - 5px); min-width: 0px; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(4) { flex: 1 1 100%; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(5) { flex: 1 1 100%; gap: 7px; justify-content: flex-end; align-items: center; min-height: 38px; display: flex !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td::before { content: attr(data-label); font-weight: 600; color: rgb(159, 190, 213); position: static !important; display: block !important; font-size: 10px !important; margin: 0px 0px 3px !important; line-height: 1.25 !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td:nth-child(5)::before { margin-right: auto !important; }
.arc-schedules-dialog .arc-schedule-table tbody tr td .arc-icon-action { width: 34px; min-width: 34px; height: 34px; flex: 0 0 34px; }
.arc-schedules-dialog .arc-schedule-table tbody tr td[colspan] { flex: 0 0 100%; }
.arc-schedules-dialog .arc-schedule-toolbar { gap: 4px !important; padding: 5px !important; }
.arc-schedules-dialog .arc-schedule-toolbar select { flex-basis: 65px; max-width: 65px; font-size: 10px; }
.arc-schedules-dialog .arc-schedule-toolbar button { flex-basis: 29px; min-width: 29px; width: 29px; }
}
</style>

<style>/* Caminho: redes/_support_workspace.php */
.arc-support-workspace{display:flex!important;flex-direction:column!important;min-height:0!important}.arc-support-page-links{display:none!important}.arc-support-stage{flex:1!important;width:100%!important;max-width:none!important;min-height:0!important}.arc-support-workspace>.arc-support-stage{margin:0!important}.arc-support-workspace .arc-support-intro{display:none!important}
</style>
