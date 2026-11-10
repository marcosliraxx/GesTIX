<?php /* Caminho: redes/_rodape.php */ 
require_once __DIR__ . '/../includes/error-logging.php';
?>
</div></div></main>
<?php
/* Central da ferramenta: reaproveita a preferência do usuário sem duplicar a lateral do Painel. */
$arcToolNames=['servidor'=>'Servidor','pentefino'=>'Pente-Fino','backup'=>'Backup','velocidade'=>'Internet','programas'=>'Programas','remoto'=>'Acesso remoto','suporte'=>'Suporte','chamados'=>'Helpdesk','arquivos'=>'Arquivos','mapa'=>'Mapa','dispositivos'=>'Dispositivos','preferencias'=>'Preferências','usuarios'=>'Usuários','permissoes'=>'Permissões','cadastros'=>'Cadastros','agenda'=>'Agenda','atualizacoes'=>'Atualizações','relatorios'=>'Relatórios','historico'=>'Histórico','clean'=>'Pente-Fino'];
$arcTool=(string)($arcSectionFile??'');
if(isset($arcToolNames[$arcTool])):
?>
<link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url())?>assets/css/tool-center.css">
<div data-arc-tool-center="<?=arc_h($arcTool)?>" data-title="<?=arc_h($arcToolNames[$arcTool])?>" data-endpoint="<?=arc_h(arc_workspace_base_url())?>api/tool-preferences.php" data-csrf="<?=arc_h(arc_csrf_token())?>"></div>
<script src="<?=arc_h(arc_workspace_base_url())?>assets/js/tool-center.js" defer></script>
<?php endif; ?>
<!-- BLOCO 03 — AGENDAMENTO SEGURO DE AÇÕES DO COMPUTADOR -->
<dialog id="arc-power-schedule" class="arc-power-dialog" aria-labelledby="arc-power-title">
<form method="dialog" id="arc-power-form">
<h3 id="arc-power-title">Agendar operação</h3>
<p>Escolha quando executar no computador selecionado.</p>
<div class="arc-power-choices" role="group" aria-label="Tempo de espera">
<label><input type="radio" name="delay" value="5" checked>5 seg</label><label><input type="radio" name="delay" value="30">30 seg</label><label><input type="radio" name="delay" value="60">60 seg</label><label><input type="radio" name="delay" value="300">5 min</label><label><input type="radio" name="delay" value="custom">Outro</label>
</div>
<label class="arc-power-custom">Segundos (5 a 3600) <input type="number" name="custom" min="5" max="3600" step="1" value="120" disabled></label>
<div class="arc-power-buttons"><button value="cancel">Cancelar</button><button value="confirm" class="arc-power-confirm">Confirmar</button></div>
</form></dialog>

<script>
(function(){
'use strict';
const networkId=<?= (int)$networkId ?>, csrf=<?= json_encode($csrf) ?>, base=<?= json_encode(arc_workspace_base_url()) ?>;
const target=document.getElementById('arc-gestor-target'), log=document.getElementById('arc-command-log'), status=document.getElementById('arc-connection-status'), refresh=document.getElementById('arc-network-global-state');
if(!target||!log)return;
// BLOCO 01 — AVISO ÚNICO PARA COMANDOS DA REDE (SEM BARRA DUPLICADA).
// Percentuais reais vêm da API; na ausência deles, há estimativa visual identificada e animação de atividade.
// BLOCO 01 — Notificação única, inicialmente invisível, fora dos contêineres.
log.classList.add('arc-operation-notice');
document.body.appendChild(log);
log.hidden=true;
const progressMessage=document.createElement('div');progressMessage.className='arc-notice-message';
const progressPercent=document.createElement('span');progressPercent.className='arc-notice-percent';progressPercent.setAttribute('aria-label','Progresso de comunicação');
const progressTrack=document.createElement('div');progressTrack.className='arc-notice-track';
const progressFill=document.createElement('div');progressFill.className='arc-notice-fill';
progressTrack.append(progressFill);const progressHeader=document.createElement('div');progressHeader.className='arc-notice-header';progressHeader.append(progressMessage,progressPercent);
const errorButton=document.createElement('button');errorButton.type='button';errorButton.className='arc-notice-error-action';errorButton.textContent='Ver motivo';errorButton.hidden=true;
const dismissButton=document.createElement('button');dismissButton.type='button';dismissButton.className='arc-notice-dismiss';dismissButton.setAttribute('aria-label','Fechar aviso de erro');dismissButton.title='Fechar';dismissButton.textContent='×';dismissButton.hidden=true;
const cancelCommandButton=document.createElement('button');cancelCommandButton.type='button';cancelCommandButton.className='arc-notice-error-action';cancelCommandButton.textContent='Cancelar';cancelCommandButton.hidden=true;
progressHeader.append(cancelCommandButton,errorButton,dismissButton);log.replaceChildren(progressHeader,progressTrack);
const errorDialog=document.createElement('dialog');errorDialog.id='arc-remote-error-details';errorDialog.setAttribute('aria-labelledby','arc-remote-error-title');
errorDialog.innerHTML=`<div class="arc-error-head"><div><h2 id="arc-remote-error-title">Diagnóstico de operação remota</h2><small>Relatório técnico da plataforma e do gestor</small></div><button type="button" data-error-close aria-label="Fechar">×</button></div><div class="arc-error-body"><p id="arc-error-explanation">Carregando diagnóstico…</p><pre id="arc-error-report" tabindex="0"></pre><div class="arc-error-history" id="arc-error-history" hidden></div></div><footer class="arc-error-actions"><button type="button" data-error-history>Falhas recentes</button><button type="button" data-error-copy>Copiar relatório</button><button type="button" data-error-close>Fechar</button></footer>`;
document.body.append(errorDialog);
const style=document.createElement('style');style.textContent=`
body>.arc-command-log.arc-operation-notice{position:fixed!important;top:auto!important;bottom:calc(env(safe-area-inset-bottom,0px) + 78px)!important;left:auto!important;right:12px!important;width:min(420px,calc(100vw - 24px))!important;max-width:none!important;min-width:0!important;z-index:2147483000!important;box-sizing:border-box!important;pointer-events:none!important;margin:0!important;padding:9px 12px 11px!important;max-height:64px!important;border:1px solid rgba(105,196,235,.6)!important;border-radius:12px!important;background:rgba(15,32,56,.79)!important;backdrop-filter:blur(18px) saturate(140%)!important;-webkit-backdrop-filter:blur(18px) saturate(140%)!important;box-shadow:0 10px 26px #0007!important;transform:translateX(calc(100% + 24px))!important;opacity:0!important;visibility:hidden!important;transition:transform .25s ease,opacity .25s ease,visibility .25s!important}
body>.arc-command-log.arc-operation-notice.arc-toast-show:not([hidden]){transform:translateX(0)!important;opacity:1!important;visibility:visible!important}
body>.arc-command-log.arc-operation-notice[hidden]{display:none!important;visibility:hidden!important}
body>.arc-command-log.arc-operation-notice[data-kind=error]{pointer-events:auto!important;max-height:104px!important}
.arc-operation-notice .arc-notice-error-action,.arc-operation-notice .arc-notice-dismiss{pointer-events:auto!important;flex:none;border:1px solid #f2aeb9;border-radius:7px;background:#95334e;color:white;padding:5px 8px;font:700 11px Segoe UI,Arial;cursor:pointer}
.arc-operation-notice .arc-notice-dismiss{padding:2px 7px;font-size:18px;line-height:1.15;background:transparent;border-color:#7291aa}
.arc-operation-notice button[hidden]{display:none!important}
#arc-remote-error-details{position:fixed;inset:0;margin:auto;width:min(640px,calc(100vw - 20px));max-height:min(85dvh,720px);padding:0;border:1px solid #537394;border-radius:13px;background:#132a43;color:#e8f4ff;box-shadow:0 18px 65px #0009;z-index:2147483001;overflow:hidden}
#arc-remote-error-details:not([open]){display:none!important}#arc-remote-error-details::backdrop{background:#031222c9;backdrop-filter:blur(3px)}
#arc-remote-error-details .arc-error-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:13px 16px;border-bottom:1px solid #35516a}
#arc-remote-error-details .arc-error-head h2{font-size:16px;margin:0 0 3px}#arc-remote-error-details .arc-error-head small{font-size:11px;color:#b4cfe6}
#arc-remote-error-details .arc-error-head button{border:0;background:none;color:#fff;font-size:24px;cursor:pointer}
#arc-remote-error-details .arc-error-body{padding:14px 16px;overflow:auto;max-height:calc(85dvh - 132px)}
#arc-remote-error-details .arc-error-body p{font-size:12px;margin:0 0 10px;color:#b8d5e9}
#arc-remote-error-details pre{white-space:pre-wrap;overflow-wrap:anywhere;user-select:text;font:12px/1.55 Consolas,monospace;margin:0;padding:12px;background:#0b1a2d;color:#eef6ff;border:1px solid #35516a;border-radius:8px;max-height:46dvh;overflow:auto}
#arc-remote-error-details .arc-error-actions{display:flex;gap:7px;align-items:center;justify-content:flex-end;padding:12px 16px;border-top:1px solid #35516a;flex-wrap:wrap}
#arc-remote-error-details .arc-error-actions button,.arc-error-history button{border:1px solid #6684a0;border-radius:7px;background:#274968;color:#fff;font:600 12px Segoe UI,Arial;padding:8px 10px;cursor:pointer}
#arc-remote-error-details [data-error-copy]{background:#007fa3!important;border-color:#279cbe!important}
#arc-remote-error-details .arc-error-history{margin-top:12px;display:grid;gap:6px}
#arc-remote-error-details .arc-error-history[hidden]{display:none!important}
#arc-remote-error-details .arc-error-history button{text-align:left;background:#203952;white-space:normal}
@media(max-width:600px){.arc-operation-notice .arc-notice-header{gap:6px}.arc-operation-notice .arc-notice-message{font-size:11px}.arc-operation-notice .arc-notice-percent{display:none}#arc-remote-error-details .arc-error-actions{justify-content:stretch}#arc-remote-error-details .arc-error-actions button{flex:1 1 100px}}
@media(max-width:600px){body>.arc-command-log.arc-operation-notice{bottom:calc(env(safe-area-inset-bottom,0px) + 72px)!important;right:8px!important;width:calc(100vw - 16px)!important;padding:7px 10px 9px!important;max-height:56px!important;border-radius:10px!important}.arc-operation-notice .arc-notice-message{font-size:11px!important;line-height:1.5!important}}
.arc-operation-notice .arc-notice-header{display:flex;align-items:center;gap:10px;min-width:0;padding-bottom:5px}
.arc-operation-notice .arc-notice-message{flex:1;min-width:0;font:600 12px/1.45 Segoe UI,Arial,sans-serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.arc-operation-notice .arc-notice-percent{flex:none;font:700 12px/1.3 Segoe UI,Arial,sans-serif;color:#72daf2;font-variant-numeric:tabular-nums}
.arc-operation-notice .arc-notice-track{height:3px;position:absolute;bottom:0;left:0;right:0;overflow:hidden;background:rgba(75,158,203,.15)}
 .arc-operation-notice .arc-notice-fill{height:100%;width:0;background:linear-gradient(90deg,#35cff0,#6477ff);transition:width .22s linear;position:relative;overflow:hidden}
.arc-operation-notice.is-working .arc-notice-fill::after{content:'';position:absolute;inset:0;background:linear-gradient(105deg,transparent 15%,rgba(255,255,255,.55) 50%,transparent 85%);background-size:180px 100%;animation:arcNoticeShimmer 1.15s linear infinite}
@keyframes arcNoticeShimmer{0%{background-position:-180px 0}100%{background-position:360px 0}}

.arc-operation-notice[data-kind=error] .arc-notice-fill{background:#ee657c;animation:none;width:100%}
.arc-operation-notice[data-kind=success] .arc-notice-fill{background:#2dd4a6;animation:none;width:100%}

@media(prefers-reduced-motion:reduce){.arc-operation-notice.is-working .arc-notice-fill::after{animation:none;display:none}}
`;document.head.append(style);
// A fila global substitui a barra antiga; o modal de diagnóstico continua disponível.
cancelCommandButton.addEventListener('click',async()=>{
 if(!window.confirm('Cancelar a operação remota em andamento?'))return;
 cancelCommandButton.disabled=true;
 try{const response=await fetch(base+'api/api.php?action=network-operation-cancel',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:networkId,gestor_id:Number(target.value)})});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Cancelamento não confirmado');notice('Cancelamento solicitado à plataforma.','info',false,0)}catch(e){showFailure({command:'operation_cancel',message:e.message})}finally{cancelCommandButton.disabled=false;cancelCommandButton.hidden=true}
});
let pollBusy=false;
let toastTimer, lastSignature='', activeCommand=false,queuedCommandId=0,activeProgressId=0;
let selectedError=null, errorReport='', stickyError=false;
const seenErrors=new Set();
const errorSeenKey='arc:errors:'+networkId+':'+String(target.value);
try { JSON.parse(sessionStorage.getItem(errorSeenKey)||'[]').forEach(id=>seenErrors.add(Number(id))); } catch (_) {}
let initialErrorsChecked=false;
function persistSeenError(id){if(id){seenErrors.add(Number(id));try{sessionStorage.setItem(errorSeenKey,JSON.stringify([...seenErrors].slice(-100)))}catch(_){}}}

function dismissFailure(){cancelCommandButton.hidden=true;stickyError=false;selectedError=null;errorButton.hidden=true;dismissButton.hidden=true;log.hidden=true;log.classList.remove('arc-toast-show');}
function showFailure(info){
 const item=info||{};const id=Number(item.command_id||item.id)||0;
 if(id)persistSeenError(id);
 selectedError={command_id:id,command:item.command||item.command_name||'Operação remota',message:String(item.message||item.result_text||'Falha não detalhada'),time:item.completed_at||new Date().toISOString(),gestor:target?.selectedOptions?.[0]?.textContent?.trim()||'Gestor não identificado'};
 stickyError=true;activeProgressId=0;cancelCommandButton.hidden=true;
 notice('Erro em '+(labels[selectedError.command]||selectedError.command)+' · '+selectedError.message,'error',false,0);
 clearTimeout(toastTimer);errorButton.hidden=true;dismissButton.hidden=true;log.hidden=true;
}
async function diagnostic(id){
 const u=new URL(base+'api/api.php',location.href);u.searchParams.set('action','network-command-diagnostic');u.searchParams.set('network_id',networkId);u.searchParams.set('command_id',id);
 const r=await fetch(u,{credentials:'same-origin',cache:'no-store'});let data={};try{data=await r.json()}catch{}
 if(!r.ok||!data.ok)throw Error(data.error||'Detalhes indisponíveis');return data.diagnostic;
}
function composeReport(row){
 return ['DIAGNÓSTICO REMOTO — T.I Digital',
 'Comando: '+(row.command_name||row.command||'Desconhecido'),
 'ID do comando: '+(row.command_id||'Não enviado'),
 'Computador: '+(row.machine_name||row.gestor||'Não identificado'),
 'Versão do gestor: '+(row.gestor_version||'Não informada'),
 'Status: '+(row.status||'Falhou'),
 'Código: '+(row.error_code||'Não informado'),
 'Solicitado: '+(row.created_at||''),
 'Finalizado: '+(row.completed_at||row.time||''),
 'Origem do diagnóstico: '+(row.source==='gestor_report'?'Gestor Windows':row.source==='platform_status'?'Plataforma':'Interface'),
 '', 'MOTIVO', row.summary||row.message||'Não informado', '', 'DETALHES TÉCNICOS', row.details||row.message||'O gestor não enviou mais detalhes.'].join('\n');
}
async function openErrorDetails(info=selectedError){
 if(!info)return;
 errorDialog.querySelector('#arc-error-history').hidden=true;
 errorDialog.querySelector('#arc-error-report').textContent='Consultando o motivo do erro…';
 errorDialog.querySelector('#arc-error-explanation').textContent='Os detalhes são carregados somente quando você abre este modal.';
 if(!errorDialog.open)errorDialog.showModal();
 try{
  const row=info.command_id?await diagnostic(info.command_id):{...info,source:'interface',summary:info.message,details:info.message};
  errorReport=composeReport(row);
  errorDialog.querySelector('#arc-error-report').textContent=errorReport;
  errorDialog.querySelector('#arc-error-explanation').textContent=row.source==='platform_status'?'O gestor não enviou informações adicionais; este relatório mostra o estado registrado pelo servidor.':'Copie o relatório para análise técnica. Informações sensíveis conhecidas são ocultadas automaticamente.';
 }catch(e){errorReport=composeReport({...info,source:'interface',details:'Não foi possível consultar os detalhes no servidor: '+e.message});errorDialog.querySelector('#arc-error-report').textContent=errorReport;errorDialog.querySelector('#arc-error-explanation').textContent='A consulta falhou; os dados disponíveis ainda podem ser copiados.';}
}
errorButton.addEventListener('click',()=>openErrorDetails());dismissButton.addEventListener('click',dismissFailure);
errorDialog.querySelectorAll('[data-error-close]').forEach(b=>b.addEventListener('click',()=>errorDialog.close()));
errorDialog.querySelector('[data-error-copy]').addEventListener('click',async e=>{
 const button=e.currentTarget;
 try{
  if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(errorReport);
  else {const box=document.createElement('textarea');box.value=errorReport;box.style.position='fixed';box.style.opacity='0';document.body.append(box);box.select();if(!document.execCommand('copy'))throw Error('Cópia indisponível');box.remove();}
  button.textContent='Copiado!';setTimeout(()=>button.textContent='Copiar relatório',1700);
 }catch{const field=errorDialog.querySelector('#arc-error-report');const range=document.createRange();range.selectNodeContents(field);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);button.textContent='Selecione e copie o texto';}
});
errorDialog.querySelector('[data-error-history]').addEventListener('click',async()=>{
 const el=errorDialog.querySelector('#arc-error-history');el.hidden=false;el.replaceChildren();el.textContent='Consultando falhas recentes…';
 try{
  const u=new URL(base+'api/api.php',location.href);u.searchParams.set('action','network-command-errors');u.searchParams.set('network_id',networkId);if(target.value)u.searchParams.set('gestor_id',target.value);
  const r=await fetch(u,{credentials:'same-origin',cache:'no-store'});const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Histórico indisponível');
  el.replaceChildren();if(!data.failures?.length){el.textContent='Nenhuma falha registrada para este gestor.';return}
  data.failures.forEach(row=>{const b=document.createElement('button');b.type='button';b.textContent='#'+row.command_id+' · '+(labels[row.command_name]||row.command_name)+' · '+(row.summary||row.status);b.addEventListener('click',()=>openErrorDetails({command_id:row.command_id,command:row.command_name,message:row.summary}));el.append(b)});
 }catch(e){el.textContent=e.message}
});
window.arcRemoteFailure=showFailure;
document.addEventListener('arc:remote-error',e=>{if(!e.detail)return;const d=e.detail;if(d.gestor_id&&Number(d.gestor_id)!==Number(target.value))return;showFailure(d)});

// BLOCO 02 — Animação independente das consultas ao servidor, sem simular confirmação.
let estimateActive=false,estimateStarted=0,estimateFloor=5,estimateShown=0,estimateTimer=null,realPercent=null;
function tickProgress(){
 if(!estimateActive||realPercent!==null)return;
 const elapsed=Math.max(0,(performance.now()-estimateStarted)/1000);
 // Curva contínua, desacelerando conforme a espera aumenta. Nunca marca 100% sem resposta.
 const target=Math.min(95,estimateFloor+(95-estimateFloor)*(1-Math.exp(-elapsed/65)));
 estimateShown=Math.max(estimateShown,Math.min(95,target));
 progressFill.style.width=estimateShown.toFixed(2)+'%';
 progressPercent.textContent='≈'+estimateShown.toFixed(1).replace('.',',')+'%';
 progressPercent.setAttribute('aria-label','Estimativa visual: '+estimateShown.toFixed(1)+' por cento; execução não confirmada');
 progressPercent.title='Estimativa visual de espera, não é o percentual real do processamento. A barra só chega a 100% após confirmação.';
}
function stopProgressAnimation(){estimateActive=false;if(estimateTimer!==null){clearInterval(estimateTimer);estimateTimer=null}}
function notice(message,type='info',working=false,pct=null,stageFloor=null){
 // A fila global é o único indicador visual: não reativar o toast legado.
 stopProgressAnimation();log.hidden=true;log.classList.remove('arc-toast-show');return;
 clearTimeout(toastTimer);
 if(type!=='error'){stickyError=false;errorButton.hidden=true;dismissButton.hidden=true;selectedError=null;}
 cancelCommandButton.hidden=(!working||type==='error');log.dataset.kind=type;log.hidden=true;log.classList.remove('arc-toast-show');
 log.classList.toggle('is-working',working);progressMessage.textContent=window.arcPlainMessage?window.arcPlainMessage(message):String(message);
 const valid=pct!==null&&pct!==undefined&&Number.isFinite(Number(pct));
 if(working){
  if(!estimateActive){estimateActive=true;estimateStarted=performance.now();estimateFloor=5;estimateShown=5;realPercent=null;estimateTimer=setInterval(tickProgress,100)}
  if(Number.isFinite(stageFloor))estimateFloor=Math.max(estimateFloor,Math.min(95,stageFloor));
  if(valid){realPercent=Math.min(99,Math.max(0,Number(pct)));progressFill.style.width=realPercent+'%';progressPercent.textContent=Math.round(realPercent)+'%';progressPercent.title='Progresso informado pelo gestor';progressPercent.setAttribute('aria-label','Progresso informado pelo gestor: '+Math.round(realPercent)+' por cento')}
  else{realPercent=null;tickProgress()}
 }else{
  stopProgressAnimation();realPercent=null;
  const value=valid?Math.max(0,Math.min(100,Number(pct))):(type==='success'?100:0);
  progressPercent.textContent=Math.round(value)+'%';progressFill.style.width=value+'%';progressPercent.title=type==='success'?'Concluído e confirmado':'Operação encerrada';progressPercent.setAttribute('aria-label',progressPercent.title);
  toastTimer=setTimeout(()=>{if(!activeProgressId&&!stickyError){log.classList.remove('arc-toast-show');log.hidden=true}},type==='error'?6500:4200);
 }
}
function toast(message,type='info'){if(activeProgressId||(stickyError&&type!=='error'))return;notice(message,type,false,type==='success'?100:0)}
function showProgress(label,state='Tudo certo, estou aguardando o computador…',id=0){activeProgressId=Number(id)||0;notice(state,'info',true,null,25)}
function endProgress(label,ok){activeProgressId=0;notice(label,ok?'success':'error',false,ok?100:0)}
function setRunning(on){}
function setOnline(online){if(status){const wrap=status.closest('.arc-gestor-select-wrap');if(wrap){wrap.classList.toggle('is-online',!!online);wrap.classList.toggle('is-offline',!online)}status.classList.toggle('is-online',!!online);status.classList.toggle('is-offline',!online);status.title=online?'Gestor online':'Gestor offline';status.setAttribute('aria-label',status.title)}}
document.addEventListener('arc:gestor-presence',e=>setOnline(e.detail?.state==='online'));
// Modal, versões e envio de atualização do gestor: assets/js/gestor-update.js (window.arcGestorUpdate).
target.addEventListener('change',()=>document.getElementById('arc-gestor-form').requestSubmit());
document.addEventListener('arc:command-queued',e=>{const d=e.detail||{};if(d.command_id&&Number(d.gestor_id)===Number(target.value)&&!activeProgressId)showProgress(labels[d.command]||'Comando remoto','Aguardando o gestor',Number(d.command_id))});
const labels={monitor_start:'Monitoramento iniciado',monitor_pause:'Monitoramento pausado',speed_test:'Teste de velocidade',scan_devices:'Varredura de dispositivos',scan_map:'Atualização da topologia',diagnostic:'Diagnóstico',quick_health:'Saúde rápida',dns_test:'Teste de DNS',public_ip:'IP público',system_snapshot:'Recursos do computador',flush_dns:'Limpeza de DNS',wifi_info:'Sinal Wi-Fi',gestor_update:'Atualização remota do gestor',power_shutdown:'Desligamento',power_restart:'Reinicialização',power_cancel:'Cancelamento do agendamento'};
// O módulo gestor-update.js avisa quando um pedido de atualização entra na fila; aqui só acompanhamos o progresso.
document.addEventListener('arc:gestor-update-queued',e=>{const d=e.detail||{};activeCommand=true;queuedCommandId=Number(d.command_id||0);poll(false)});

async function poll(notify=false){
 if(pollBusy)return;pollBusy=true;
 if(!target.value){pollBusy=false;}
 if(!target.value){setRunning(false);setOnline(false);return}
 try{
 const r=await fetch(base+'api/api.php?action=network-command-status&network_id='+networkId,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw Error('Não foi possível consultar os comandos');
 const j=await r.json();if(!j.ok)throw Error(j.error||'Erro de sincronização');
 // Presença do cabeçalho é gerenciada exclusivamente por assets/js/gestor-update.js.
 const commands=(j.commands||[]).filter(x=>Number(x.gestor_id)===Number(target.value));
 if(!initialErrorsChecked){
  commands.filter(c=>['failed','expired'].includes(String(c.status).toLowerCase())).forEach(c=>persistSeenError(c.id));
  initialErrorsChecked=true;
 }
 const recentFailures=commands.filter(c=>['failed','expired'].includes(String(c.status).toLowerCase())&&!seenErrors.has(Number(c.id)));
 // Historical failures remain in the on-demand list; do not spam old failures on each page load.
 recentFailures.forEach(c=>{if(Date.now()-Date.parse(c.completed_at||c.created_at||0)>12*3600*1000)persistSeenError(c.id)});
 const fresh=recentFailures.filter(c=>!seenErrors.has(Number(c.id))).sort((a,b)=>Number(a.id)-Number(b.id));
 if(fresh.length){fresh.slice(0,-1).forEach(c=>persistSeenError(c.id));const newest=fresh[fresh.length-1];if(!activeProgressId||Number(newest.id)!==activeProgressId)showFailure(newest);}

 const x=activeProgressId?commands.find(c=>Number(c.id)===activeProgressId):commands[0];if(!x){setRunning(false);return}
 const state=String(x.status||'').toLowerCase();const busy=['queued','pending','running','processing'].includes(state);
 setRunning(busy);
 if(activeProgressId&&Number(x.id)===activeProgressId){
  const name=labels[x.command_name]||x.command_name||'Comando';
  if(busy){
   const steps={queued:'Já mandei! Assim que o computador responder, aviso aqui.',pending:'Chegou ao servidor. Falta o computador confirmar.',running:'O computador já começou. Acompanhando por aqui…',processing:'Está em andamento. Aviso assim que terminar.'};
   const detail=String(x.result_text||'').trim();
   const reported=x.progress_percent===undefined||x.progress_percent===null||x.progress_percent===''?null:Number(x.progress_percent);
   const updatePercent=x.command_name==='gestor_update'?String(x.result_text||'').match(/(?:WinUI 3|Atualização)\s+(\d{1,3})%/i):null;
   const pct=updatePercent?Math.min(99,Number(updatePercent[1])):reported;
   const phases={queued:25,pending:40,running:70,processing:70};notice(detail||steps[state]||'Estou acompanhando a execução…','info',true,Number.isFinite(pct)?Math.min(99,pct):null,phases[state]??25);
  }else{
   const ok=state==='completed';const detail=String(x.result_text||'').trim();
   let finalText=ok?'Pronto, deu tudo certo!':'Não deu certo desta vez.';
   if(detail)finalText+=': '+detail;
   // A conclusão é a confirmação retornada pela API, e não apenas o envio do comando.
   endProgress(finalText,ok);
   if(!ok)showFailure(x);
   document.dispatchEvent(new CustomEvent('arc:operation-updated',{detail:{id:x.id,status:state,command:x.command_name}}));
   if(ok&&['scan_devices','scan_map','diagnostic','speed_test'].includes(x.command_name))setTimeout(()=>location.reload(),1400);
   if(x.command_name==='gestor_update')window.arcGestorUpdate?.refresh();
  }
 }

 const sig=[x.id,x.status,x.result_text].join(':');
 if(sig!==lastSignature){lastSignature=sig;if(['completed','failed','expired'].includes(state)){document.dispatchEvent(new CustomEvent('arc:command-finished',{detail:{id:x.id,status:state,command:x.command_name}}));if(queuedCommandId===Number(x.id)&&state==='completed'){queuedCommandId=0;if(!document.querySelector('.arc-terminal'))setTimeout(()=>location.reload(),4800)}}if(activeCommand){if(!activeProgressId)toast((labels[x.command_name]||x.command_name||'Comando')+' · '+(busy?'Em execução':state==='completed'?'Concluído':'Não concluído')+(x.result_text?' · '+x.result_text:''),state==='failed'?'error':'info');activeCommand=false}}
 }catch(e){if(notify)toast(e.message,'error')}finally{pollBusy=false}
}
refresh?.addEventListener('click',()=>{refresh.classList.add('is-refreshing');poll(true).finally(()=>setTimeout(()=>refresh.classList.remove('is-refreshing'),650))});
document.addEventListener('click',async e=>{
 const b=e.target.closest('[data-arc-command]');if(!b)return;
 e.preventDefault();if(!target.value){toast('Selecione um gestor aprovado antes de executar comandos.','error');return}
 if(b.disabled)return;
 if(b.dataset.arcCommand==='gestor_update'){
  // Tratado por gestor-update.js (captura o clique antes deste ouvinte); este desvio só cobre o carregamento tardio.
  if(window.arcGestorUpdate){if(b.dataset.updateForce==='1')window.arcGestorUpdate.open();else window.arcGestorUpdate.queue('normal');}
  else toast('Carregando o módulo de atualização. Tente novamente em instantes.','info');
  return;
 }
 b.disabled=true;setRunning(true);notice('Só um instante, estou enviando…','info',true,null,5);
 try{const r=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:networkId,gestor_id:Number(target.value),command:b.dataset.arcCommand,_csrf:csrf})});const j=await r.json();if(!r.ok||!j.ok){const error=Error(j.error||'Falha ao enviar comando');error.supportRef=j.error_ref||'';throw error;}activeCommand=true;queuedCommandId=Number(j.command_id||0);showProgress(labels[b.dataset.arcCommand]||b.dataset.arcCommand,'Enviei! Estou aguardando o computador confirmar…',queuedCommandId);document.dispatchEvent(new CustomEvent('arc:command-queued',{detail:{command:b.dataset.arcCommand,command_id:Number(j.command_id),gestor_id:Number(target.value)}}));poll(false)}catch(err){setRunning(false);activeProgressId=0;showFailure({command:b.dataset.arcCommand,message:'Falha ao enviar comando: '+err.message+(err.supportRef?' Referência: '+err.supportRef:'')})}finally{b.disabled=false}
});
const powerDlg=document.getElementById('arc-power-schedule');
const powerForm=document.getElementById('arc-power-form');
powerForm?.addEventListener('change',()=>{powerForm.elements.custom.disabled=powerForm.querySelector('[name="delay"]:checked')?.value!=='custom'});
const deadlines=new Map();
function swapShutdown(on){const power=document.querySelector('[data-power-tile="power_shutdown"]');const cancel=document.querySelector('[data-power-cancel]');if(!power||!cancel)return;power.hidden=!!on;cancel.hidden=!on;}
swapShutdown(false);
function drawCountdown(){for(const [command,data] of deadlines){const tile=document.querySelector('[data-power-tile="'+command+'"]');if(!tile)continue;let badge=tile.querySelector('.arc-power-countdown');if(!badge){badge=document.createElement('small');badge.className='arc-power-countdown';tile.append(badge)}const remaining=Math.max(0,Math.ceil((data.until-Date.now())/1000));badge.textContent=remaining?'Faltam '+Math.floor(remaining/60)+':'+String(remaining%60).padStart(2,'0'):'';if(!remaining){deadlines.delete(command);badge.remove();if(command==='power_shutdown')swapShutdown(false)}}}
setInterval(drawCountdown,1000);
function chooseDelay(command){return new Promise(resolve=>{const dlg=powerDlg;if(!dlg||!dlg.showModal)return resolve(null);powerForm.reset();powerForm.elements.custom.disabled=true;dlg.querySelector('#arc-power-title').textContent=command==='power_restart'?'Reiniciar computador':'Desligar computador';const finish=()=>{dlg.removeEventListener('close',finish);if(dlg.returnValue!=='confirm')return resolve(null);const v=powerForm.querySelector('[name="delay"]:checked')?.value;const seconds=Number(v==='custom'?powerForm.elements.custom.value:v);resolve(Number.isInteger(seconds)&&seconds>=5&&seconds<=3600?seconds:null)};dlg.addEventListener('close',finish);dlg.showModal();});}
async function sendPower(command,delay,tile){
 const body={network_id:networkId,gestor_id:Number(target.value),command,_csrf:csrf,confirmed:true};
 if(delay!==undefined)body.delay_seconds=delay;
 tile.disabled=true;notice('Já estou enviando para o computador…','info',true,null,5);
 try{const r=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não consegui enviar o pedido');activeProgressId=Number(j.command_id||0);showProgress('',delay===undefined?'Solicitando bloqueio imediato…':'Agendando para '+(delay>=60?(delay/60+' min'):(delay+' segundos'))+'…',activeProgressId);const id=Number(j.command_id||0);if(delay!==undefined){const check=async()=>{for(let tries=0;tries<45;tries++){await new Promise(resolve=>setTimeout(resolve,2000));const response=await fetch(base+'api/api.php?action=network-command-status&network_id='+networkId,{credentials:'same-origin',cache:'no-store'});if(!response.ok)continue;const data=await response.json();const item=(data.commands||[]).find(x=>Number(x.id)===id);if(item&&['failed','expired'].includes(item.status)){deadlines.delete(command);document.querySelector('[data-power-tile="'+command+'"] .arc-power-countdown')?.remove();return;}if(item?.status==='completed'){if(delay!==undefined)notice('Operação concluída.','success',false,100);const tileCmd=command==='power_cancel'?'power_shutdown':command;if(command==='power_cancel'){deadlines.delete('power_shutdown');document.querySelector('[data-power-tile="power_shutdown"] .arc-power-countdown')?.remove();swapShutdown(false)}else if(command!=='screen_lock'){deadlines.set(tileCmd,{until:Date.now()+delay*1000});drawCountdown();if(command==='power_shutdown')swapShutdown(true)}return}}};check().catch(()=>{})}poll()}
 catch(err){activeProgressId=0;showFailure({command,message:err.message})}finally{tile.disabled=false}
}
document.addEventListener('click',async e=>{
 const tile=e.target.closest('[data-support-action]');if(!tile||tile.disabled)return;
 const command=tile.dataset.supportAction;
 if(!target.value){notice('Escolha um computador conectado.','error');return}
 if(!['power_restart','power_shutdown','screen_lock'].includes(command))return;
 const delay=command==='screen_lock'?undefined:await chooseDelay(command);if(delay===null)return;
 await sendPower(command,delay,tile)
});
document.addEventListener('click',async e=>{const b=e.target.closest('[data-power-cancel]');if(!b)return;if(!window.confirm('Cancelar o desligamento agendado?'))return;await sendPower('power_cancel',undefined,b)});
// Falhas antigas não são reenviadas automaticamente; o histórico permanece no modal.
poll();setInterval(()=>{if(!document.hidden && activeProgressId)poll()},1000);
// Histórico consultado apenas quando solicitado no modal.
})();
</script>

<?php require __DIR__.'/_gestor_update_dialog.php'; ?>
<dialog id="arc-network-confirm" class="arc-network-confirm" aria-labelledby="arc-network-confirm-title">
<div class="arc-confirm-head"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i><h2 id="arc-network-confirm-title">Confirmar operação</h2></div>
<p data-confirm-text>Deseja continuar?</p><div class="arc-confirm-actions"><button type="button" class="button secondary" data-confirm-cancel>Cancelar</button><button type="button" class="button danger" data-confirm-proceed>Confirmar</button></div>
</dialog>
<script>
(function(){
 const modal=document.getElementById('arc-network-manager');if(!modal)return;
 const preferredPanel=<?= json_encode((string)($arcManagerInitialTab ?? 'gestores'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
 const inline=true; // Painel administrativo permanente; não é um diálogo.
 const tabs=[...modal.querySelectorAll('[data-manager-tab]')];
 const panels=[...modal.querySelectorAll('[data-manager-panel]')];
 function choose(key){
  tabs.forEach(b=>{const active=b.dataset.managerTab===key;b.classList.toggle('active',active);b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1});
  panels.forEach(p=>{const active=p.dataset.managerPanel===key;p.hidden=!active;p.classList.toggle('active',active)});
  const body=modal.querySelector('.arc-manager-body');if(body)body.scrollTop=0;
 }
 choose(preferredPanel);
 function open(key){choose(key||'gestores')}
 document.addEventListener('click',function(e){
  const tab=e.target.closest('[data-manager-tab]');if(tab){e.preventDefault();choose(tab.dataset.managerTab)}
 });
 // Fechar apenas quando o clique ocorrer no backdrop, sem fechar ao clicar no conteúdo.
 function backdropClick(dialog,e){
  if(!dialog.open || e.target!==dialog)return;
  const r=dialog.getBoundingClientRect();
  if(e.clientX<r.left || e.clientX>r.right || e.clientY<r.top || e.clientY>r.bottom || (dialog===modal && !e.target.closest('.arc-network-manager-shell')))dialog.close();
 }
 [...(inline?[]:[modal]),document.getElementById('arc-network-confirm'),modal.querySelector('#arc-gestor-detail-modal'),modal.querySelector('#arc-team-editor'),modal.querySelector('#arc-headless-confirm')].filter(Boolean).forEach(d=>d.addEventListener('click',e=>backdropClick(d,e)));

 // The original forms submit to their own PHP actions; use a styled confirmation dialog.
 const confirm=document.getElementById('arc-network-confirm');let pending=null;
 document.addEventListener('submit',function(e){const form=e.target.closest('form[data-confirm]');if(!form||!confirm||form.dataset.confirmed==='1')return;e.preventDefault();pending=form;const message=confirm.querySelector('[data-confirm-text]');if(message)message.textContent=form.dataset.confirm;confirm.showModal()},true);
 if(confirm){confirm.querySelector('[data-confirm-cancel]')?.addEventListener('click',()=>{pending=null;confirm.close()});confirm.querySelector('[data-confirm-proceed]')?.addEventListener('click',()=>{if(!pending)return;const f=pending;pending=null;confirm.close();f.dataset.confirmed='1';f.requestSubmit()})}
 const logo=modal.querySelector('#arc-network-logo-input');logo?.addEventListener('change',()=>{const file=logo.files?.[0];if(!file)return;if(file.size>2*1024*1024){alert('A imagem deve ter até 2 MB.');logo.value='';return}const image=modal.querySelector('#arc-logo-preview'),placeholder=modal.querySelector('#arc-logo-placeholder');if(image){const url=URL.createObjectURL(file);image.onload=()=>URL.revokeObjectURL(url);image.src=url;image.hidden=false}if(placeholder)placeholder.hidden=true});
 // Gerenciamento compacto: busca, detalhes, edição do perfil e modo sem monitor.
 modal.querySelectorAll('[data-manager-search]').forEach(input=>input.addEventListener('input',()=>{
  const section=input.closest('.arc-manage-section'),needle=input.value.trim().toLocaleLowerCase('pt-BR');let visible=0;
  section.querySelectorAll('tbody tr[data-search-text]').forEach(row=>{const hit=(row.dataset.searchText||'').includes(needle);row.hidden=!hit;if(hit)visible++});
  const empty=section.querySelector('[data-manager-empty]');if(empty)empty.hidden=visible!==0;
 }));
 const gestorDetail=modal.querySelector('#arc-gestor-detail-modal');
 modal.querySelectorAll('[data-gestor-details]').forEach(row=>{
  const show=()=>{const source=document.getElementById(row.dataset.gestorDetails);if(!source||!gestorDetail)return;gestorDetail.querySelector('[data-detail-content]').replaceChildren(source.cloneNode(true));gestorDetail.querySelector('[data-detail-content] > div').hidden=false;gestorDetail.showModal()};
  row.addEventListener('click',e=>{if(!e.target.closest('button,a,form,input,select'))show()});
  row.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target===row){e.preventDefault();show()}});
 });
 gestorDetail?.querySelector('[data-detail-close]')?.addEventListener('click',()=>gestorDetail.close());
 const teamEditor=modal.querySelector('#arc-team-editor');
 const editTeam=(email,role)=>{if(!teamEditor)return;teamEditor.querySelector('[name=member_email]').value=email||'';teamEditor.querySelector('[name=member_email]').readOnly=!!email;teamEditor.querySelector('[name=member_role]').value=role||'viewer';teamEditor.querySelector('#arc-team-editor-title').textContent=email?'Editar perfil':'Adicionar integrante';teamEditor.showModal()};
 modal.querySelector('[data-team-add]')?.addEventListener('click',()=>editTeam('','viewer'));
 modal.querySelectorAll('[data-team-edit]').forEach(btn=>btn.addEventListener('click',()=>editTeam(btn.dataset.teamEmail,btn.dataset.teamRole)));
 modal.querySelectorAll('[data-team-close]').forEach(btn=>btn.addEventListener('click',()=>teamEditor?.close()));
 const headless=modal.querySelector('#arc-headless-confirm');
 modal.querySelectorAll('.arc-headless-toggle-btn').forEach(btn=>btn.addEventListener('click',()=>{
  headless.querySelector('#arc-headless-confirm-gestor-id').value=btn.dataset.headlessGestorId;
  headless.querySelector('#arc-headless-confirm-text').textContent=(btn.dataset.headlessCurrent==='1'?'Desativar':'Ativar')+' acesso sem confirmação local para '+btn.dataset.headlessGestorName+'?';headless.showModal();
 }));
 modal.querySelector('#arc-headless-confirm-cancel')?.addEventListener('click',()=>headless?.close());
 if(inline)choose('gestores');
 const hash=(location.hash||'').toLowerCase();if(hash==='#team'||hash==='#users')open('team');else if(hash==='#gestores'||hash==='#installations')open('gestores');else if(hash==='#settings'||hash==='#preferences')open('settings');else if(hash==='#cadastro')open('data');
})();
</script>

<?php require dirname(__DIR__).'/includes/footer.php'; ?>
