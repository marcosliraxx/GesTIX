<?php /* Caminho: redes/_rodape.php */ ?>
</div></div></main>
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
if(!target||!log||!status)return;
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
.arc-support-action-grid .arc-remote-request.arc-update-available{border-color:#f2bd43!important;box-shadow:0 0 0 1px #f2bd4366,0 0 18px #f2bd4333!important;animation:arcUpdateAlert 1.5s ease-in-out infinite!important}
.arc-support-action-grid .arc-remote-request.arc-update-available i{color:#f2c44e!important}
.arc-support-action-grid .arc-remote-request.arc-update-available::after{content:'Nova versão';display:block;color:#f2c44e;font:700 10px Segoe UI,Arial,sans-serif}
@keyframes arcUpdateAlert{0%,100%{filter:brightness(1)}50%{filter:brightness(1.4)}}
@media(prefers-reduced-motion:reduce){.arc-support-action-grid .arc-remote-request.arc-update-available{animation:none!important}}

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
function setOnline(online){const wrap=status.closest('.arc-gestor-select-wrap');if(wrap){wrap.classList.toggle('is-online',!!online);wrap.classList.toggle('is-offline',!online)}status.classList.toggle('is-online',!!online);status.classList.toggle('is-offline',!online);status.title=online?'Gestor online':'Gestor offline';status.setAttribute('aria-label',status.title)}
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
 const heartbeat=(j.gestores||[]).find(a=>Number(a.id)===Number(target.value));
 setOnline(!!heartbeat&&heartbeat.status==='approved'&&Number(j.server_time)-Number(heartbeat.last_seen_unix||0)<120);
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
function chooseDelay(command){return new Promise(resolve=>{const dlg=powerDlg;if(!dlg||!dlg.showModal)return resolve(null);powerForm.reset();powerForm.elements.custom.disabled=true;dlg.querySelector('#arc-power-title').textContent=command==='power_restart'?'Reiniciar computador':command==='screen_lock'?'Bloquear computador':'Desligar computador';const finish=()=>{dlg.removeEventListener('close',finish);if(dlg.returnValue!=='confirm')return resolve(null);const v=powerForm.querySelector('[name="delay"]:checked')?.value;const seconds=Number(v==='custom'?powerForm.elements.custom.value:v);resolve(Number.isInteger(seconds)&&seconds>=5&&seconds<=3600?seconds:null)};dlg.addEventListener('close',finish);dlg.showModal();});}
async function sendPower(command,delay,tile){
 const body={network_id:networkId,gestor_id:Number(target.value),command,_csrf:csrf,confirmed:true};
 if(delay!==undefined)body.delay_seconds=delay;
 tile.disabled=true;notice('Já estou enviando para o computador…','info',true,null,5);
 try{const r=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não consegui enviar o pedido');activeProgressId=Number(j.command_id||0);showProgress('','Agendando para '+(delay===undefined?'agora':delay>=60?(delay/60+' min'):(delay+' segundos'))+'…',activeProgressId);const id=Number(j.command_id||0);if(delay!==undefined){if(command==='screen_lock'){deadlines.set(command,{until:Date.now()+delay*1000});drawCountdown()}const check=async()=>{for(let tries=0;tries<45;tries++){await new Promise(resolve=>setTimeout(resolve,2000));const response=await fetch(base+'api/api.php?action=network-command-status&network_id='+networkId,{credentials:'same-origin',cache:'no-store'});if(!response.ok)continue;const data=await response.json();const item=(data.commands||[]).find(x=>Number(x.id)===id);if(item&&['failed','expired'].includes(item.status)){deadlines.delete(command);document.querySelector('[data-power-tile="'+command+'"] .arc-power-countdown')?.remove();return;}if(item?.status==='completed'){if(delay!==undefined)notice('Pronto! Prazo selecionado: '+(delay>=60?(delay/60+' min'):(delay+' segundos'))+'.','success',false,100);const tileCmd=command==='power_cancel'?'power_shutdown':command;if(command==='power_cancel'){deadlines.delete('power_shutdown');document.querySelector('[data-power-tile="power_shutdown"] .arc-power-countdown')?.remove();swapShutdown(false)}else if(command!=='screen_lock'){deadlines.set(tileCmd,{until:Date.now()+delay*1000});drawCountdown();if(command==='power_shutdown')swapShutdown(true)}return}}};check().catch(()=>{})}poll()}
 catch(err){activeProgressId=0;showFailure({command,message:err.message})}finally{tile.disabled=false}
}
document.addEventListener('click',async e=>{
 const tile=e.target.closest('[data-support-action]');if(!tile||tile.disabled)return;
 const command=tile.dataset.supportAction;
 if(!target.value){notice('Escolha um computador conectado.','error');return}
 if(!['power_restart','power_shutdown','screen_lock'].includes(command))return;
 const delay=await chooseDelay(command);if(delay===null)return;
 await sendPower(command,delay,tile)
});
document.addEventListener('click',async e=>{const b=e.target.closest('[data-power-cancel]');if(!b)return;if(!window.confirm('Cancelar o desligamento agendado?'))return;await sendPower('power_cancel',undefined,b)});
// Falhas antigas não são reenviadas automaticamente; o histórico permanece no modal.
poll();setInterval(()=>{if(!document.hidden && activeProgressId)poll()},1000);
// Histórico consultado apenas quando solicitado no modal.
})();
</script>

<dialog id="arc-unlock-dialog" class="arc-unlock-dialog" aria-labelledby="arc-unlock-title"><form id="arc-unlock-form" autocomplete="off"><header><i class="fa fa-user-circle" aria-hidden="true"></i><h2 id="arc-unlock-title">Entrar no Windows</h2><button type="button" id="arc-unlock-close" class="arc-icon-action" aria-label="Fechar desbloqueio"><i class="fa fa-times" aria-hidden="true"></i></button></header><p id="arc-unlock-host"></p><p>Selecione seu usuário para entrar neste computador com a senha do Windows.</p><label>Usuário do Windows<select name="windows_user" required aria-label="Usuário local do Windows"><option value="">Carregando usuários…</option></select></label><label>Senha do Windows<input name="windows_password" type="password" maxlength="256" autocomplete="off" required></label><small>As credenciais são cifradas para o componente seguro e não ficam salvas na plataforma.</small><p id="arc-unlock-feedback" role="status" aria-live="polite"></p><button type="submit" class="button primary" id="arc-unlock-submit"><i class="fa fa-unlock-alt" aria-hidden="true"></i> Desbloquear</button></form></dialog>
<dialog id="arc-remote-assistance-modal" class="arc-remote-screen-modal" aria-labelledby="arc-remote-assistance-title">
 <div class="arc-remote-screen-head">
  <span class="arc-remote-sr-title" id="arc-remote-assistance-title">Acesso remoto</span><span id="arc-remote-assistance-host" class="arc-remote-sr-title"></span>
  <div class="arc-remote-screen-tools" id="arc-remote-tools" hidden role="toolbar" aria-label="Ferramentas do acesso remoto">
  <label class="arc-remote-select-label" id="arc-remote-monitor-wrap" hidden title="Selecionar monitor remoto"><i class="fa fa-desktop" aria-hidden="true"></i><select id="arc-remote-monitor" aria-label="Selecionar tela remota"></select></label>
  
   <button type="button" class="arc-remote-tool arc-mobile-keyboard" id="arc-remote-mobile-keyboard" title="Abrir teclado do celular" aria-label="Abrir teclado do celular" aria-pressed="false"><i class="fa fa-keyboard-o" aria-hidden="true"></i></button>
   <button type="button" class="arc-remote-tool arc-remote-feature" id="arc-remote-keyboard-menu" title="Atalhos do Windows" aria-label="Atalhos do Windows"><i class="fa fa-keyboard-o"></i></button>
   <button type="button" class="arc-remote-tool arc-remote-feature" id="arc-remote-window-menu" title="Ferramentas do Windows" aria-label="Ferramentas do Windows"><i class="fa fa-windows"></i></button>
   <button type="button" class="arc-remote-tool arc-remote-feature" id="arc-remote-view-menu" title="Configurações de visualização" aria-label="Configurações de visualização"><i class="fa fa-sliders"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-fullscreen" title="Tela cheia" aria-label="Tela cheia"><i class="fa fa-expand"></i></button>

  </div>
  <button type="button" class="arc-remote-close" id="arc-remote-assistance-close" aria-label="Fechar visualizador"><i class="fa fa-times"></i></button>
 </div>
 <dialog id="arc-remote-tool-modal" class="arc-remote-tool-modal" aria-labelledby="arc-remote-tool-heading">
   <div class="arc-remote-tool-modal-header"><strong id="arc-remote-tool-heading">Atalhos do Windows</strong><button type="button" id="arc-remote-tool-close" aria-label="Fechar ferramentas" title="Fechar"><i class="fa fa-times"></i></button></div>
   <div id="arc-remote-key-list" class="arc-remote-shortcuts" hidden>
     <button type="button" data-rc-combo="win" title="Menu Iniciar"><i class="fa fa-windows"></i><span>Iniciar</span><small>Win</small></button>
     <button type="button" data-rc-combo="win_e"><i class="fa fa-folder-open"></i><span>Explorador</span><small>Win + E</small></button>
     <button type="button" data-rc-combo="win_r"><i class="fa fa-terminal"></i><span>Executar</span><small>Win + R</small></button>
     <button type="button" data-rc-combo="win_d"><i class="fa fa-desktop"></i><span>Área de trabalho</span><small>Win + D</small></button>
     <button type="button" data-rc-combo="alt_tab"><i class="fa fa-clone"></i><span>Alternar janelas</span><small>Alt + Tab</small></button>
     <button type="button" data-rc-combo="ctrl_shift_esc"><i class="fa fa-tasks"></i><span>Gerenciador de tarefas</span><small>Ctrl + Shift + Esc</small></button>
     <button type="button" data-rc-combo="alt_f4"><i class="fa fa-window-close"></i><span>Fechar janela</span><small>Alt + F4</small></button>
     <button type="button" data-rc-combo="ctrl_a"><i class="fa fa-i-cursor"></i><span>Selecionar tudo</span><small>Ctrl + A</small></button>
     <button type="button" data-rc-combo="ctrl_c"><i class="fa fa-copy"></i><span>Copiar</span><small>Ctrl + C</small></button>
     <button type="button" data-rc-combo="ctrl_v"><i class="fa fa-paste"></i><span>Colar</span><small>Ctrl + V</small></button>
     <button type="button" data-rc-combo="ctrl_z"><i class="fa fa-undo"></i><span>Desfazer</span><small>Ctrl + Z</small></button>
     <button type="button" data-rc-combo="f5"><i class="fa fa-refresh"></i><span>Atualizar janela</span><small>F5</small></button>
     <button type="button" data-rc-combo="esc"><i class="fa fa-sign-out"></i><span>Escape</span><small>Esc</small></button>
     <button type="button" data-rc-combo="ctrl_alt_del" title="Solicitar a tela de credenciais do Windows"><i class="fa fa-lock"></i><span>Ctrl + Alt + Del</span><small>Requer auxiliar seguro instalado</small></button>
   </div>
   <div id="arc-remote-window-list" class="arc-remote-shortcuts" hidden><p>Ferramentas do Windows disponíveis durante uma sessão com controle autorizado.</p><button type="button" data-rc-combo="win_e"><i class="fa fa-folder-open"></i><span>Explorador</span><small>Win + E</small></button><button type="button" data-rc-combo="win_r"><i class="fa fa-terminal"></i><span>Executar</span><small>Win + R</small></button><button type="button" data-rc-combo="ctrl_shift_esc"><i class="fa fa-tasks"></i><span>Gerenciador</span><small>Ctrl + Shift + Esc</small></button></div>
   <div id="arc-remote-view-list" class="arc-remote-view-settings" hidden>
        <span class="arc-remote-divider" aria-hidden="true"></span>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-out" title="Diminuir zoom" aria-label="Diminuir zoom"><i class="fa fa-search-minus"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-fit" title="Ajustar tela à janela" aria-label="Ajustar tela à janela"><i class="fa fa-arrows-alt"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-in" title="Aumentar zoom" aria-label="Aumentar zoom"><i class="fa fa-search-plus"></i></button>
   <span id="arc-remote-zoom-value" class="arc-remote-zoom-value" title="Zoom da visualização">100%</span>
   <label class="arc-remote-select-label" title="Qualidade real da transmissão"><i class="fa fa-sliders" aria-hidden="true"></i><select id="arc-remote-quality" aria-label="Qualidade da transmissão"><option value="auto" selected>Qualidade: automática</option><option value="balanced">Qualidade: padrão</option><option value="low">Qualidade: econômica</option><option value="high">Qualidade: alta</option></select></label>
   <button type="button" class="arc-remote-tool" id="arc-remote-snapshot" title="Salvar captura da tela atual" aria-label="Salvar captura da tela atual"><i class="fa fa-camera"></i></button>

     <p>Os controles de zoom e qualidade não alteram a autorização da sessão remota.</p>
   </div>
 </dialog>
 <div class="arc-remote-screen-canvas" id="arc-remote-canvas" tabindex="0"><textarea id="arc-remote-mobile-input" aria-label="Teclado do computador remoto" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" inputmode="text" tabindex="-1"></textarea><img id="arc-remote-screen-image" alt="Tela compartilhada pelo gestor autorizado" draggable="false" hidden><div id="arc-remote-screen-placeholder"><div class="arc-access-only"><button type="button" class="arc-remote-request" id="arc-remote-assistance-send"><i class="fa fa-eye" aria-hidden="true"></i><span>Solicitar acesso</span><small class="arc-tile-version" data-tile-version="access">Versão: verificando…</small></button></div></div></div>
 <div class="arc-remote-screen-actions"><small id="arc-remote-assistance-state" role="status">Aguardando solicitação de acesso.</small><small id="arc-remote-control-hint" hidden></small><button type="button" id="arc-remote-screen-stop" hidden>Encerrar sessão</button></div>
</dialog>
<div id="arc-support-moved-grid" class="arc-support-action-grid" hidden>

<a class="arc-remote-request arc-remote-download" id="arc-remote-support-download" href="<?=arc_h(arc_workspace_base_url().'api/download-support.php')?>" download><i class="fa fa-download"></i><span>Baixar suporte</span><small class="arc-tile-version" data-tile-version="support">Versão: verificando…</small></a>
<button type="button" class="arc-remote-request" data-arc-command="gestor_update" title="Atualizar arquivos do gestor instalado"><i class="fa fa-refresh" aria-hidden="true"></i><span>Atualizar gestor</span><small class="arc-tile-version" data-tile-version="update">Versão: verificando…</small></button>
<button type="button" class="arc-remote-request" data-arc-command="gestor_update" data-update-force="1" title="Reinstalar integralmente o pacote gerenciado, preservando cadastro e configurações"><i class="fa fa-cloud-download" aria-hidden="true"></i><span>Atualização forçada</span><small>Reinstalar pacote completo</small></button>
<button type="button" class="arc-remote-request" data-support-action="power_restart" data-power-tile="power_restart"><i class="fa fa-refresh"></i><span>Reiniciar PC</span></button>
<button type="button" class="arc-remote-request" data-support-action="screen_lock" data-power-tile="screen_lock"><i class="fa fa-lock"></i><span>Bloquear PC</span></button>
<button type="button" class="arc-remote-request" id="arc-unlock-pc" <?=!in_array($teamRole,['owner','administrator'],true)?'disabled aria-disabled="true"':''?>><i class="fa fa-unlock-alt" aria-hidden="true"></i><span>Desbloquear PC</span></button>
<button type="button" class="arc-remote-request" id="arc-windows-update" <?=!in_array($teamRole,['owner','administrator'],true)?'disabled':''?>><i class="fa fa-windows"></i><span>Atualizar Windows</span><small id="arc-windows-version">Windows: verificando…</small><small id="arc-windows-status" role="status"></small></button>

<button type="button" class="arc-remote-request <?=!in_array($teamRole,['owner','administrator'],true)?'arc-action-locked':''?>" data-support-action="power_shutdown" data-power-tile="power_shutdown" <?=!in_array($teamRole,['owner','administrator'],true)?'disabled aria-disabled="true"':''?>><i class="fa fa-power-off"></i><span>Desligar PC</span><?=!in_array($teamRole,['owner','administrator'],true)?'<small><i class="fa fa-lock"></i> Somente admin</small>':''?></button><button type="button" class="arc-power-cancel arc-remote-request" data-power-cancel="1" hidden title="Cancelar desligamento agendado" style="font-size:11px;border:1px solid #376587;border-radius:8px;color:#bfe9ff;background:#193149;padding:8px">Cancelar desligamento</button>
</div>

<!-- Confirmação de sessão para gestores autorizados pelo gestor como "sem monitor/teclado/mouse"
     (aba Gestores do gerenciador da rede). Faz parte do mesmo botão "Solicitar acesso" acima — não é um
     atalho separado nem escondido; só aparece quando não há como o computador remoto pedir confirmação
     a ninguém. A sessão continua visível na tela remota (se houver uma) e registrada na auditoria. -->
<dialog id="arc-remote-auto-modal" class="arc-remote-auto-modal" aria-labelledby="arc-remote-auto-title">
 <form id="arc-remote-auto-form">
  <h2 id="arc-remote-auto-title">Confirmar acesso remoto</h2>
  <p>Este computador está marcado como servidor sem monitor, teclado ou mouse, então ninguém poderia autorizar o acesso na tela dele. Confirme a abertura da sessão com sua conta já autenticada — ela ficará registrada na auditoria.</p>
  <small id="arc-remote-auto-error" role="alert"></small>
  <div class="arc-remote-auto-actions">
   <button type="button" class="button secondary" id="arc-remote-auto-force" hidden>Encerrar acesso preso</button>
   <button type="button" class="button secondary" id="arc-remote-auto-cancel">Cancelar</button>
   <button type="submit" class="button" id="arc-remote-auto-submit">Confirmar e abrir acesso</button>
  </div>
 </form>
</dialog>

<script>
(()=>{'use strict';
const $=id=>document.getElementById(id);
const dialog=$('arc-remote-assistance-modal'),open=$('arc-gestor-remote-view'),send=$('arc-remote-assistance-send'),close=$('arc-remote-assistance-close'),stop=$('arc-remote-screen-stop'),state=$('arc-remote-assistance-state'),select=$('arc-gestor-target'),host=$('arc-remote-assistance-host'),image=$('arc-remote-screen-image'),placeholder=$('arc-remote-screen-placeholder'),canvas=$('arc-remote-canvas'),tools=$('arc-remote-tools'),hint=$('arc-remote-control-hint'),mobileInput=$('arc-remote-mobile-input'),fsBtn=$('arc-remote-fullscreen'),monitorWrap=$('arc-remote-monitor-wrap'),monitorSelect=$('arc-remote-monitor');
if(!dialog||!open||!send||!select)return;
const network=<?= (int)$networkId ?>,csrf=<?= json_encode($csrf) ?>,base=<?= json_encode(arc_workspace_base_url()) ?>;
const HINT_VIEW='Tela completa, incluindo a barra de tarefas. O controle de mouse e teclado depende da autorização no computador remoto.';
const HINT_CTRL='Controle ativo: toque ou clique para controlar. No celular, use o botão de teclado para digitar. Ctrl+V digita o texto copiado no computador remoto.';
let commandId=0,timer=null,locked=false,live=false,controlOn=false,pumping=false,lastMonitorKey='';
// BLOCO 31 — SESSÃO PERSISTENTE: compartilhada entre abas da mesma origem e rede.
const REMOTE_KEY='arcoll:remote-session:'+network;
function saveRemoteSession(){try{if(commandId)localStorage.setItem(REMOTE_KEY,JSON.stringify({command_id:commandId,gestor_id:Number(select.value),saved_at:Date.now()}));}catch(e){}}
function clearRemoteSession(){try{localStorage.removeItem(REMOTE_KEY)}catch(e){}}
function showRemoteViewer(activate=true){
 const stage=document.getElementById('arc-support-stage');
 if(stage && !stage.contains(dialog))stage.append(dialog);
 const button=document.querySelector('[data-support-pane="remote"]');
 if(activate&&button&&!button.classList.contains('is-active'))button.click();
 if(!dialog.open){try{dialog.show()}catch(e){try{dialog.showModal()}catch(_e){}}}
}
async function restoreRemoteSession(){
 if(commandId)return;
 let saved=null;try{saved=JSON.parse(localStorage.getItem(REMOTE_KEY)||'null')}catch(e){clearRemoteSession()}
 const gestor=Number(select.value||0);
 let id=0,chosenGestor=gestor;
 if(saved&&Number(saved.command_id)>0&&Date.now()-Number(saved.saved_at||0)<1800000){id=Number(saved.command_id);chosenGestor=Number(saved.gestor_id||gestor)}
 const option=[...select.options].find(opt=>Number(opt.value)===chosenGestor);
 if(id&&!option){id=0;chosenGestor=gestor}
 if(!id&&gestor){
  try{
   const response=await fetch(base+'api/api.php?action=network-remote-existing&network_id='+network+'&gestor_id='+gestor,{credentials:'same-origin',cache:'no-store'});
   const found=await response.json();if(response.ok&&found.ok&&Number(found.command_id)>0)id=Number(found.command_id);
  }catch(e){}
 }
 if(!id)return;
 const activeOption=[...select.options].find(opt=>Number(opt.value)===chosenGestor);
 if(!activeOption)return;
 select.value=activeOption.value;commandId=id;
 try{
  const response=await fetch(frameUrl('status'),{credentials:'same-origin',cache:'no-store'});
  const result=await response.json();
  if(!response.ok||!result.ok||result.stopped||['failed','completed','expired'].includes(result.status))throw Error('Sessão encerrada');
  saveRemoteSession();showRemoteViewer(false);send.hidden=true;stop.hidden=false;
  host.textContent='• '+(activeOption.textContent||'Gestor');
  clearTimers();timer=setInterval(refresh,1200);await refresh();
 }catch(e){commandId=0;clearRemoteSession();send.hidden=false;stop.hidden=true}
}

let inq=[],inflight=false,flushT=null;const pressed=new Set(),buttonsDown=new Set();
function setState(text){state.textContent=text}
function frameUrl(action){return base+'api/remote-screen.php?action='+action+'&command_id='+commandId}
function monitorKey(list,selected){return JSON.stringify({list:(Array.isArray(list)?list:[]).map(item=>({id:String(item.id||''),label:String(item.label||'')})),selected:String(selected||'')})}
function renderMonitorPicker(list,selected){
 const monitors=Array.isArray(list)?list.filter(item=>item&&item.id):[];
 if(!monitorWrap||!monitorSelect)return;
 if(monitors.length<=1){monitorWrap.hidden=true;monitorSelect.innerHTML='';lastMonitorKey='';return;}
 const key=monitorKey(monitors,selected);
 if(key!==lastMonitorKey){
  monitorSelect.replaceChildren(...monitors.map(item=>{const opt=document.createElement('option');opt.value=String(item.id);opt.textContent=String(item.label||('Tela '+item.id));return opt;}));
  lastMonitorKey=key;
 }
 monitorWrap.hidden=false;
 if(selected&&monitorSelect.value!==String(selected))monitorSelect.value=String(selected);
 if(!monitorSelect.value&&monitors[0])monitorSelect.value=String(monitors[0].id);
}
async function chooseMonitor(id){
 if(!commandId||!id)return;
 try{
  setState('Alternando a visualização para '+(monitorSelect?.selectedOptions[0]?.textContent||'a tela selecionada')+'...');
  const res=await fetch(frameUrl('select-monitor'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({monitor_id:id,_csrf:csrf})});
  const data=await res.json().catch(()=>({ok:false,error:'Falha ao alternar a tela remota.'}));
  if(!res.ok||!data.ok)throw Error(data.error||'Falha ao alternar a tela remota.');
  if(data.selected_monitor&&monitorSelect)monitorSelect.value=String(data.selected_monitor);
  refresh();
 }catch(err){setState('Erro: '+err.message)}
}

monitorSelect?.addEventListener('change',()=>chooseMonitor(monitorSelect.value));
function clearTimers(){if(timer){clearInterval(timer);timer=null}}
function setControl(on){
 on=!!on;tools.hidden=!live;if(on===controlOn)return;controlOn=on;
 canvas.classList.toggle('controlling',on);tools.hidden=!live;hint.textContent=on?HINT_CTRL:HINT_VIEW;
 mobileInput.disabled=!on;$('arc-remote-mobile-keyboard').disabled=!on;
 if(!on){releaseAll();inq=[];mobileInput.blur();cursor.hidden=true;cancelHold();touchStart=null;}
}
/* ---------- quadros: carrega o próximo assim que o anterior chega ---------- */
let frameEtag='',slowFrames=0,fastFrames=0,autoQuality='balanced',lastDownloadMs=0;
async function pump(){
 if(pumping)return;pumping=true;
 try{while(live&&commandId&&dialog.open&&!document.hidden){
  if(dialog.classList.contains('arc-pane-dormant')){await new Promise(r=>setTimeout(r,150));continue;}
  const abort=new AbortController(),expiry=setTimeout(()=>abort.abort(),8000);let changed=false,idle=0;
  try{
   const response=await fetch(frameUrl('image'),{credentials:'same-origin',cache:'no-store',headers:frameEtag?{'If-None-Match':frameEtag}:{},signal:abort.signal});
   const headersAt=performance.now();/* Medir a recepção do quadro sem contar o tempo de decodificação na tela. */
   if(!response.ok)idle=response.status===304?0:300;/* 304: o servidor já esperou por um quadro novo; 404: ainda sem quadro */
   if(response.ok){const blob=await response.blob(),downloadMs=performance.now()-headersAt,url=URL.createObjectURL(blob),old=image.src;image.src=url;image.hidden=false;placeholder.hidden=true;try{await image.decode();}catch(_){}if(old.startsWith('blob:'))URL.revokeObjectURL(old);frameEtag=response.headers.get('ETag')||'';changed=true;lastDownloadMs=downloadMs;}
   if(changed&&qualitySelect?.value==='auto'){
    const elapsed=lastDownloadMs;slowFrames=elapsed>350?slowFrames+1:0;fastFrames=elapsed<140?fastFrames+1:0;
    if(slowFrames>=3&&autoQuality!=='low'){autoQuality='low';qualitySelect.dispatchEvent(new Event('change'));slowFrames=0;}
    if(fastFrames>=20&&autoQuality!=='balanced'){autoQuality='balanced';qualitySelect.dispatchEvent(new Event('change'));fastFrames=0;}
   }
  }catch(_){idle=500;}finally{clearTimeout(expiry);}
  if(idle)await new Promise(r=>setTimeout(r,idle));
 }}finally{pumping=false;}
}
async function endSession(){
 if(visualFullscreen)await exitRemoteFullscreen();
 clearTimers();live=false;frameEtag='';setControl(false);
 if(commandId){try{await fetch(frameUrl('stop'),{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf})})}catch(e){}}
 if(monitorWrap)monitorWrap.hidden=true;lastMonitorKey='';
 commandId=0;clearRemoteSession();if(image.src&&image.src.startsWith('blob:'))URL.revokeObjectURL(image.src);
 image.hidden=true;image.removeAttribute('src');placeholder.hidden=false;stop.hidden=true;send.hidden=false;send.disabled=false;tools.hidden=true;hint.textContent=HINT_VIEW;
}
async function refresh(){
 if(!commandId||!dialog.open||locked)return;locked=true;
 try{
  const response=await fetch(frameUrl('status'),{credentials:'same-origin',cache:'no-store'});const result=await response.json().catch(()=>({ok:false,error:'Resposta inválida da API (HTTP '+response.status+').'}));if(!response.ok)throw Error(result.error||'Erro HTTP '+response.status);
  if(!result.ok||result.stopped||['failed','completed','expired'].includes(result.status)){
   const reason=String(result.state_message||'A sessão foi encerrada ou recusada.');
   await endSession();setState(reason);return;
  }
  renderMonitorPicker(result.monitors||[],result.selected_monitor||'');
  if(result.has_frame){
   if(!live){live=true;pump();qualitySelect?.dispatchEvent(new Event('change'))}
   setControl(result.control);
   setState(result.control?'● Acesso remoto ativo · controle de mouse e teclado autorizado':'● Visualização ativa · controle não autorizado (somente tela)');
  }else if(result.status==='pending')setState('Pedido aguardando entrega ao gestor Windows. Confirme se o gestor está conectado e atualizado.');
  else if(Number(result.elapsed_seconds||0)>45)setState('Ainda sem imagem. Confira no computador a janela de autorização do gestor. '+String(result.state_message||''));
  else setState(String(result.state_message||'Aguardando autorização ou primeira imagem do computador...'));
 }catch(e){setState('Falha ao consultar sessão remota: '+(e.message||'erro desconhecido'))}finally{locked=false}
}
/* ---------- entrada: mouse e teclado ---------- */
function push(ev){
 if(!controlOn)return;
 if(ev.t==='m'&&inq.length&&inq[inq.length-1].t==='m')inq[inq.length-1]=ev;else inq.push(ev);
 if(!flushT)flushT=setTimeout(flush,12);
}
async function flush(){
 flushT=null;if(!controlOn||!commandId){inq=[];return}
 if(inflight||!inq.length){if(inq.length&&!flushT)flushT=setTimeout(flush,12);return}
 inflight=true;const batch=inq.splice(0,200);
 try{
  const res=await fetch(frameUrl('input'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({events:batch,_csrf:csrf})});
  if(res.status===409){setControl(false);setState('O controle foi desativado no computador remoto.')}
 }catch(e){}
 inflight=false;if(inq.length&&!flushT)flushT=setTimeout(flush,12);
}
function pos(e){
 const iw=image.naturalWidth,ih=image.naturalHeight;if(!iw||!ih)return null;
 const r=image.getBoundingClientRect(),s=Math.min(r.width/iw,r.height/ih),w=iw*s,h=ih*s,ox=(r.width-w)/2,oy=(r.height-h)/2;
 const x=(e.clientX-r.left-ox)/w,y=(e.clientY-r.top-oy)/h;
 return{x:Math.max(0,Math.min(1,x)),y:Math.max(0,Math.min(1,y)),inside:x>=0&&x<=1&&y>=0&&y<=1};
}
function btn(e){return e.button===2?2:(e.button===1?1:0)}
function releaseAll(){
 for(const c of pressed)inq.push({t:'k',d:0,c});pressed.clear();
 for(const b of buttonsDown)inq.push({t:'u',x:0,y:0,b});buttonsDown.clear();
 if(controlOn&&inq.length&&!flushT)flushT=setTimeout(flush,10);
}
canvas.addEventListener('mousemove',e=>{if(!controlOn)return;const p=pos(e);if(p&&p.inside){drawCursor(e);push({t:'m',x:p.x,y:p.y})}});
canvas.addEventListener('mousedown',e=>{if(!controlOn)return;if(!e.sourceCapabilities?.firesTouchEvents)canvas.focus();e.preventDefault();const p=pos(e);if(!p||!p.inside)return;drawCursor(e);clickFeedback(e,btn(e));buttonsDown.add(btn(e));push({t:'d',x:p.x,y:p.y,b:btn(e)})});
window.addEventListener('mouseup',e=>{if(!controlOn||!buttonsDown.has(btn(e)))return;const p=pos(e)||{x:0,y:0};buttonsDown.delete(btn(e));push({t:'u',x:p.x,y:p.y,b:btn(e)})});
canvas.addEventListener('contextmenu',e=>{if(controlOn)e.preventDefault()});
canvas.addEventListener('wheel',e=>{if(!controlOn)return;e.preventDefault();const k=e.deltaMode===0?100:1;push({t:'w',dx:Math.round(e.deltaX/k)||0,dy:Math.round(e.deltaY/k)||(e.deltaY?Math.sign(e.deltaY):0)})},{passive:false});
canvas.addEventListener('keydown',e=>{
 if(!controlOn||e.target===mobileInput)return;
 if((e.ctrlKey||e.metaKey)&&e.code==='KeyV')return; /* o evento paste envia o texto */
 if(e.ctrlKey&&e.altKey&&e.code==='End'){e.preventDefault();push({t:'c',n:'ctrl_alt_del'});return} /* o navegador não repassa Ctrl+Alt+Del; Ctrl+Alt+End é o equivalente (como no RDP) */
 e.preventDefault();pressed.add(e.code);push({t:'k',d:1,c:e.code});
});
canvas.addEventListener('keyup',e=>{if(!controlOn||e.target===mobileInput)return;e.preventDefault();pressed.delete(e.code);push({t:'k',d:0,c:e.code})});
canvas.addEventListener('blur',()=>{if(controlOn)releaseAll()});
canvas.addEventListener('paste',e=>{if(!controlOn||e.target===mobileInput)return;e.preventDefault();const t=(e.clipboardData||window.clipboardData).getData('text');if(t)push({t:'x',s:t.slice(0,400)})});
const toolDlg=$('arc-remote-tool-modal');
const sentinel='\u200b';let composing=false,touchStart=null;
function resetMobileInput(){mobileInput.value=sentinel;mobileInput.setSelectionRange(1,1);}
function keyStroke(code){push({t:'k',d:1,c:code});push({t:'k',d:0,c:code});}
function mobileText(text){if(!controlOn||!text)return;const chars=[...text];while(chars.length)push({t:'x',s:chars.splice(0,400).join('')});}
resetMobileInput();mobileInput.disabled=true;
mobileInput.addEventListener('compositionstart',()=>{composing=true;});
mobileInput.addEventListener('compositionend',e=>{composing=false;mobileText(e.data||mobileInput.value.replaceAll(sentinel,''));resetMobileInput();});
mobileInput.addEventListener('beforeinput',e=>{
 if(!controlOn||composing||e.isComposing)return;
 if(e.inputType==='deleteContentBackward'||e.inputType==='deleteContentForward'){e.preventDefault();keyStroke(e.inputType==='deleteContentBackward'?'Backspace':'Delete');resetMobileInput();}
 else if(e.inputType==='insertLineBreak'||e.inputType==='insertParagraph'){e.preventDefault();keyStroke('Enter');resetMobileInput();}
});
mobileInput.addEventListener('input',e=>{if(composing||e.isComposing)return;const text=mobileInput.value.replaceAll(sentinel,'');mobileText(text);resetMobileInput();});
mobileInput.addEventListener('keydown',e=>{e.stopPropagation();if(!controlOn||e.isComposing)return;if(['Enter','Tab','Escape','ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.code)){e.preventDefault();keyStroke(e.code);}});
const mobileKeyboard=$('arc-remote-mobile-keyboard');
mobileKeyboard.disabled=true;
mobileKeyboard.addEventListener('click',()=>{if(!controlOn)return;if(document.activeElement===mobileInput){mobileInput.blur();return;}resetMobileInput();mobileInput.focus({preventScroll:true});});
mobileInput.addEventListener('focus',()=>mobileKeyboard.setAttribute('aria-pressed','true'));mobileInput.addEventListener('blur',()=>mobileKeyboard.setAttribute('aria-pressed','false'));
const cursor=document.createElement('span');cursor.className='arc-remote-cursor';cursor.hidden=true;cursor.setAttribute('aria-hidden','true');cursor.innerHTML='<svg viewBox="0 0 24 28"><path d="M2 1v22l6-6 5 10 4-2-5-10h9z" fill="white" stroke="#15253a" stroke-width="1.6" stroke-linejoin="round"/></svg>';canvas.append(cursor);
function drawCursor(e){if(!controlOn){cursor.hidden=true;return;}const p=pos(e);if(!p?.inside){cursor.hidden=true;return;}const r=canvas.getBoundingClientRect();cursor.hidden=false;cursor.style.left=(e.clientX-r.left+canvas.scrollLeft)+'px';cursor.style.top=(e.clientY-r.top+canvas.scrollTop)+'px';}
function clickFeedback(e,button=0){const dot=document.createElement('span'),r=canvas.getBoundingClientRect();dot.className='arc-remote-click'+(button===2?' is-right':'');dot.setAttribute('aria-hidden','true');dot.style.left=(e.clientX-r.left+canvas.scrollLeft)+'px';dot.style.top=(e.clientY-r.top+canvas.scrollTop)+'px';canvas.append(dot);setTimeout(()=>dot.remove(),500);}
canvas.addEventListener('mouseleave',()=>{cursor.hidden=true;});
let holdTimer=null,longPressed=false;
function cancelHold(){clearTimeout(holdTimer);holdTimer=null;}
canvas.addEventListener('touchstart',e=>{
 cancelHold();touchStart=null;if(!controlOn||e.touches.length!==1)return;const t=e.touches[0];if(!pos(t)?.inside)return;touchStart={x:t.clientX,y:t.clientY};longPressed=false;drawCursor(t);
 holdTimer=setTimeout(()=>{if(!controlOn||!touchStart)return;const p=pos(t);if(!p?.inside)return;longPressed=true;push({t:'d',x:p.x,y:p.y,b:2});push({t:'u',x:p.x,y:p.y,b:2});clickFeedback(t,2);},1000);
},{passive:true});
canvas.addEventListener('touchmove',e=>{if(e.touches.length!==1){cancelHold();touchStart=null;return;}const t=e.touches[0];drawCursor(t);if(touchStart&&Math.hypot(t.clientX-touchStart.x,t.clientY-touchStart.y)>10){cancelHold();touchStart=null;}const p=pos(t);if(p?.inside)push({t:'m',x:p.x,y:p.y});},{passive:true});
canvas.addEventListener('touchcancel',()=>{cancelHold();touchStart=null;});
canvas.addEventListener('touchend',e=>{
 cancelHold();if(!controlOn||!touchStart||e.changedTouches.length!==1){touchStart=null;return;}const t=e.changedTouches[0];touchStart=null;if(longPressed){e.preventDefault();return;}const p=pos(t);if(!p?.inside)return;e.preventDefault();drawCursor(t);clickFeedback(t);push({t:'d',x:p.x,y:p.y,b:0});push({t:'u',x:p.x,y:p.y,b:0});
},{passive:false});
const toolModes={ 'arc-remote-keyboard-menu':['arc-remote-key-list','Atalhos do Windows'], 'arc-remote-window-menu':['arc-remote-window-list','Ferramentas do Windows'], 'arc-remote-view-menu':['arc-remote-view-list','Visualização']};
Object.keys(toolModes).forEach(id=>$(id)?.addEventListener('click',()=>{const [pane,label]=toolModes[id];['arc-remote-key-list','arc-remote-window-list','arc-remote-view-list'].forEach(x=>$(x).hidden=x!==pane);$('arc-remote-tool-heading').textContent=label;toolDlg.showModal();}));
$('arc-remote-tool-close')?.addEventListener('click',()=>toolDlg.close());
toolDlg.addEventListener('click',e=>{const b=e.target.closest('[data-rc-combo]');if(!b)return;if(!controlOn){setState('Controle de teclado não autorizado nesta sessão.');return;}push({t:'c',n:b.dataset.rcCombo});toolDlg.close();canvas.focus();});
let visualFullscreen=false,nativeFullscreen=false,fullscreenAnchor=null;
const fullscreenRoot=document.createElement('div');fullscreenRoot.id='arc-remote-fullscreen-root';fullscreenRoot.hidden=true;document.body.append(fullscreenRoot);
function fullscreenLabel(){const label=visualFullscreen?'Tela normal':'Tela cheia';fsBtn.title=label;fsBtn.setAttribute('aria-label',label);fsBtn.setAttribute('aria-pressed',String(visualFullscreen));fsBtn.querySelector('i').className='fa '+(visualFullscreen?'fa-compress':'fa-expand');}
function viewportSize(){const v=window.visualViewport;fullscreenRoot.style.setProperty('--arc-remote-height',(v?.height||innerHeight)+'px');fullscreenRoot.style.setProperty('--arc-remote-top',(v?.offsetTop||0)+'px');if(!visualFullscreen)dialog.style.setProperty('--arc-remote-visible-height',Math.max(120,(v?.offsetTop||0)+(v?.height||innerHeight)-dialog.getBoundingClientRect().top-8)+'px');applyZoom();}
async function exitRemoteFullscreen(){
 visualFullscreen=false;nativeFullscreen=false;
 if(document.fullscreenElement===fullscreenRoot){try{await document.exitFullscreen()}catch(e){}}
 fullscreenRoot.hidden=true;document.documentElement.classList.remove('arc-remote-fullscreen-open');
 if(fullscreenAnchor?.parentNode){fullscreenAnchor.replaceWith(dialog);fullscreenAnchor=null;}
 fullscreenLabel();requestAnimationFrame(viewportSize);
}
fsBtn.addEventListener('click',async()=>{
 if(visualFullscreen){await exitRemoteFullscreen();return;}
 visualFullscreen=true;fullscreenAnchor=document.createComment('remote-viewer-position');dialog.before(fullscreenAnchor);fullscreenRoot.append(dialog);fullscreenRoot.hidden=false;document.documentElement.classList.add('arc-remote-fullscreen-open');fullscreenLabel();viewportSize();
 try{if(fullscreenRoot.requestFullscreen){await fullscreenRoot.requestFullscreen();nativeFullscreen=true;}}catch(e){/* The viewport alternative supports Safari without Fullscreen API. */}
 requestAnimationFrame(applyZoom);
});
document.addEventListener('fullscreenchange',()=>{if(document.fullscreenElement===fullscreenRoot)nativeFullscreen=true;else if(nativeFullscreen)exitRemoteFullscreen();});
window.addEventListener('keydown',e=>{if(e.key==='Escape'&&visualFullscreen&&!document.fullscreenElement){e.preventDefault();exitRemoteFullscreen();}});
window.addEventListener('resize',viewportSize);window.visualViewport?.addEventListener('resize',viewportSize);
let zoomFactor=1;
const qualitySelect=$('arc-remote-quality');
qualitySelect?.addEventListener('change',async()=>{
 if(!commandId)return;
 try{const r=await fetch(frameUrl('preferences'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({_csrf:csrf,quality:qualitySelect.value==='auto'?autoQuality:qualitySelect.value,cursor_overlay:true})});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não foi possível ajustar a qualidade');}catch(err){setState(err.message)}
});
const zoomValue=$('arc-remote-zoom-value');
function applyZoom(){
 if(!image.naturalWidth||!image.naturalHeight)return;
 const width=canvas.clientWidth,height=canvas.clientHeight;if(!width||!height)return;
 const scale=Math.min(width/image.naturalWidth,height/image.naturalHeight)*zoomFactor;
 image.style.width=Math.max(1,Math.floor(image.naturalWidth*scale))+'px';image.style.height=Math.max(1,Math.floor(image.naturalHeight*scale))+'px';
 canvas.classList.toggle('is-zoomed',zoomFactor>1);
 if(zoomValue)zoomValue.textContent=Math.round(zoomFactor*100)+'%';
}
function setZoom(value){zoomFactor=Math.max(.5,Math.min(3,Math.round(value*10)/10));applyZoom();}
image.addEventListener('load',applyZoom);new ResizeObserver(applyZoom).observe(canvas);
$('arc-remote-zoom-in')?.addEventListener('click',()=>setZoom(zoomFactor+.2));
$('arc-remote-zoom-out')?.addEventListener('click',()=>setZoom(zoomFactor-.2));
$('arc-remote-zoom-fit')?.addEventListener('click',()=>{setZoom(1);canvas.scrollTo(0,0);toolDlg.close();});
$('arc-remote-snapshot')?.addEventListener('click',()=>{
 if(!live||image.hidden||!image.naturalWidth){setState('Aguardando imagem para salvar a captura.');return}
 try{
  const capture=document.createElement('canvas');capture.width=image.naturalWidth;capture.height=image.naturalHeight;
  capture.getContext('2d').drawImage(image,0,0);
  capture.toBlob(blob=>{
   if(!blob){setState('Não foi possível salvar a captura.');return}
   const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;
   a.download='acesso-remoto-'+new Date().toISOString().replace(/[:.]/g,'-')+'.png';a.click();
   setTimeout(()=>URL.revokeObjectURL(url),3000);
  },'image/png');
 }catch(_e){setState('Não foi possível salvar a captura atual.')}
});

const unlockDialog=$('arc-unlock-dialog'),unlockForm=$('arc-unlock-form'),unlockFeedback=$('arc-unlock-feedback'),unlockSubmit=$('arc-unlock-submit');let unlockGeneration=0,unlockBusy=false,unlockCommandId=0,unlockOwnSession=false,unlockStatusBusy=false;
const unlockButton=$('arc-unlock-pc'),unlockRoleAllowed=!!unlockButton&&!unlockButton.disabled;
const unlockUrl=action=>base+'api/remote-screen.php?action='+action+'&command_id='+unlockCommandId;
async function queryUnlockStatus(){const response=await fetch(base+'api/support.php?action=unlock-status',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,gestor_id:Number(select.value),_csrf:csrf})});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Não foi possível consultar o Windows.');return result;}
async function refreshUnlockButton(){if(!unlockButton||!unlockRoleAllowed||unlockStatusBusy)return;unlockStatusBusy=true;const gestor=select.value;try{const status=await queryUnlockStatus();if(gestor!==select.value)return;unlockButton.disabled=!status.available||status.desktop_state!=='locked';unlockButton.title=status.desktop_state==='unlocked'?'O computador já está desbloqueado':status.desktop_state==='locked'?'Desbloquear Windows':'Aguardando o gestor atualizado informar o estado do Windows';}catch{unlockButton.disabled=true;unlockButton.title='Estado do Windows indisponível';}finally{unlockStatusBusy=false;}}
if(unlockButton)unlockButton.disabled=true;setInterval(refreshUnlockButton,5000);select.addEventListener('change',()=>{if(unlockButton)unlockButton.disabled=true;refreshUnlockButton();});refreshUnlockButton();
unlockButton?.addEventListener('click',async()=>{
 unlockForm.reset();const generation=++unlockGeneration;unlockFeedback.textContent='Consultando usuários no computador…';unlockSubmit.disabled=true;
 const users=unlockForm.elements.windows_user;users.replaceChildren(new Option('Carregando usuários…',''));$('arc-unlock-host').textContent=select.selectedOptions[0]?.textContent||'Computador selecionado';unlockDialog.showModal();
 try{const status=await queryUnlockStatus();if(generation!==unlockGeneration)return;
  if(!status.available||status.desktop_state!=='locked')throw Error('O computador não está bloqueado ou o gestor precisa ser atualizado.');
  if(status.windows_users_error)throw Error('O gestor não conseguiu consultar as contas locais do Windows. Verifique as permissões do serviço do gestor.');
  if(!status.windows_users?.length)throw Error('Nenhuma conta foi informada. Aguarde a atualização do gestor e tente novamente.');
  users.replaceChildren(new Option('Selecione seu usuário',''));for(const entry of status.windows_users)users.add(new Option(entry.label,entry.username));if(status.windows_users.length===1)users.selectedIndex=1;
  unlockFeedback.textContent='Selecione o usuário e digite a senha. O desbloqueio não abre acesso remoto.';unlockSubmit.disabled=false;unlockForm.elements.windows_password.focus();
 }catch(error){if(generation===unlockGeneration){users.replaceChildren(new Option('Usuários indisponíveis',''));unlockFeedback.textContent=error.message;}}});
$('arc-unlock-close').addEventListener('click',()=>unlockDialog.close());
unlockDialog.addEventListener('close',()=>{unlockGeneration++;unlockForm.reset();if(unlockOwnSession&&unlockCommandId){fetch(unlockUrl('stop'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({_csrf:csrf})}).catch(()=>{});}unlockCommandId=0;unlockOwnSession=false;refreshUnlockButton();});
function encode64(bytes){let value='';for(const b of bytes)value+=String.fromCharCode(b);return btoa(value);}
async function encryptUnlock(publicKey,credentials,command){
 const key=await crypto.subtle.importKey('jwk',publicKey.jwk,{name:'RSA-OAEP',hash:'SHA-256'},false,['encrypt']);
 const bytes=new TextEncoder().encode(JSON.stringify(credentials));if(bytes.length>4096)throw Error('Credenciais excedem o limite.');
 const count=Math.ceil(bytes.length/120),blocks=[];
 try{for(let i=0;i<count;i++){const prefix=new TextEncoder().encode(publicKey.id+':'+command+':'+i+':'+count+':'),chunk=bytes.slice(i*120,(i+1)*120),message=new Uint8Array(prefix.length+chunk.length);message.set(prefix);message.set(chunk,prefix.length);blocks.push(encode64(new Uint8Array(await crypto.subtle.encrypt({name:'RSA-OAEP'},key,message))));message.fill(0);chunk.fill(0);}}finally{bytes.fill(0);}
 return blocks;
}
unlockForm.addEventListener('submit',async e=>{
 e.preventDefault();if(unlockBusy)return;const generation=++unlockGeneration;unlockBusy=true;unlockSubmit.disabled=true;
 const credentials={username:unlockForm.elements.windows_user.value.trim(),password:unlockForm.elements.windows_password.value};unlockForm.reset();
 try{
  if(!window.isSecureContext||!crypto.subtle)throw Error('Desbloqueio requer conexão HTTPS e navegador atualizado.');
  unlockFeedback.textContent='Preparando sessão segura…';
  const response=await fetch(base+'api/support.php?action=unlock-session',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,gestor_id:Number(select.value),_csrf:csrf})});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível preparar o desbloqueio.');
  if(generation!==unlockGeneration)return;
  unlockCommandId=Number(data.command_id);unlockOwnSession=!!data.created;
  let publicKey=null;
  for(let i=0;i<30;i++){
   if(generation!==unlockGeneration)return;
   const response=await fetch(unlockUrl('status'),{credentials:'same-origin',cache:'no-store'});const status=await response.json();if(!response.ok||!status.ok||status.stopped)throw Error(status.error||'Atendimento encerrado.');
   if(status.desktop_state==='unlocked'){unlockDialog.close();return;}
   if(status.control&&status.unlock_key&&status.unlock_key.expires*1000>Date.now()){publicKey=status.unlock_key;break;}
   await new Promise(resolve=>setTimeout(resolve,1000));
  }
  if(!publicKey)throw Error('O componente seguro não respondeu. Atualize/reinstale o gestor como administrador e tente novamente.');
  const blocks=await encryptUnlock(publicKey,credentials,unlockCommandId);credentials.username='';credentials.password='';
  if(generation!==unlockGeneration)return;
  const sent=await fetch(unlockUrl('unlock-submit'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({key_id:publicKey.id,blocks,_csrf:csrf})});blocks.fill('');const queued=await sent.json();if(!sent.ok||!queued.ok)throw Error(queued.error||'Desbloqueio não autorizado.');
  unlockFeedback.textContent='Pedido enviado ao Windows. Aguardando confirmação…';
  for(let i=0;i<40;i++){
   if(generation!==unlockGeneration)return;
   await new Promise(resolve=>setTimeout(resolve,1000));const response=await fetch(unlockUrl('status'),{credentials:'same-origin',cache:'no-store'}),result=await response.json();
   if(!response.ok||!result.ok||result.stopped)throw Error('Atendimento encerrado.');
   if(result.desktop_state==='unlocked'||(result.unlock_result?.request_id===queued.request_id&&result.unlock_result.status==='unlocked')){unlockFeedback.textContent='Computador desbloqueado.';setState('Windows desbloqueado.');unlockDialog.close();return;}
   if(result.unlock_result?.request_id===queued.request_id&&result.unlock_result.status==='failed')throw Error('O Windows não confirmou o desbloqueio. Confira o usuário, a senha e as políticas de login do Windows.');
  }
  throw Error('O Windows não confirmou o resultado no prazo. Confira a tela antes de tentar novamente.');
 }catch(error){unlockFeedback.textContent=error.message;}finally{credentials.username='';credentials.password='';unlockForm.reset();unlockBusy=false;unlockSubmit.disabled=false;}
});
/* ---------- solicitação ---------- */
open.addEventListener('click',()=>{
 if(!select.value){setState('Selecione um gestor aprovado.');return;}
 host.textContent='• '+(select.selectedOptions[0]?.textContent||'Gestor');setState('Solicite autorização ao operador do computador.');
 if(!document.getElementById('arc-support-stage')?.contains(dialog))dialog.showModal();
});
async function sendRemoteAssistanceRequest(gestor,confirmPassword){
 const payload={network_id:network,gestor_id:gestor,command:'remote_assistance',_csrf:csrf};
 if(confirmPassword)payload.confirm_password=confirmPassword;
 const res=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
 const value=await res.json().catch(()=>({error:'O servidor retornou uma resposta inválida. Verifique o log PHP (HTTP '+res.status+').'}));if(!res.ok||!value.ok||!value.command_id)throw Error(value.error||'Falha ao solicitar acesso');
 commandId=Number(value.command_id);saveRemoteSession();send.hidden=true;stop.hidden=false;
 setState(value.unattended?'Acesso administrativo sem monitor autorizado; aguardando a imagem...':'Aguardando o usuário permitir o acesso no Windows...');
 clearTimers();timer=setInterval(refresh,1200);refresh();
}
const autoDialog=$('arc-remote-auto-modal'),autoForm=$('arc-remote-auto-form'),autoError=$('arc-remote-auto-error'),autoCancel=$('arc-remote-auto-cancel'),autoSubmit=$('arc-remote-auto-submit');
const forceStop=$('arc-remote-auto-force');
function remoteConflict(message){return /Já existe uma solicitação remota|Encerre-a antes/i.test(String(message||''))}
async function forceCloseStuckSession(){
 const gestor=Number(select.value);if(!gestor)return;
 if(!window.confirm('Encerrar a solicitação remota anterior deste computador? A conexão atual será interrompida e a ação registrada na auditoria.'))return;
 forceStop.disabled=true;autoError.textContent='Encerrando sessão anterior...';
 try{
  const response=await fetch(base+'api/api.php?action=network-remote-force-stop',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,gestor_id:gestor,_csrf:csrf})});
  const data=await response.json().catch(()=>({error:'Resposta inválida do servidor'}));
  if(!response.ok||!data.ok)throw Error(data.error||'Não foi possível encerrar a sessão');
  autoError.textContent=(Number(data.closed)||0)+' sessão(ões) encerrada(s). Agora confirme para abrir um novo acesso.';
  forceStop.hidden=true;
 }catch(error){autoError.textContent=error.message||'Falha ao encerrar sessão'}
 finally{forceStop.disabled=false}
}
forceStop?.addEventListener('click',forceCloseStuckSession);

send.addEventListener('click',async()=>{
 await restoreRemoteSession();
 if(commandId){showRemoteViewer();refresh();return;}
 const gestor=Number(select.value);if(!gestor)return setState('Selecione um gestor aprovado.');
 // A solicitação usa a sessão autenticada e as permissões já verificadas pela API.
 // Não abrir confirmação adicional para computadores previamente autorizados.
 send.disabled=true;setState('Enviando solicitação ao gestor...');
 try{await sendRemoteAssistanceRequest(gestor)}catch(e){setState('Erro: '+e.message);send.disabled=false;if(remoteConflict(e.message)&&autoDialog){autoError.textContent=e.message;forceStop.hidden=false;autoDialog.showModal()}}
});
if(autoForm){
 autoForm.addEventListener('submit',async(e)=>{
  e.preventDefault();const gestor=Number(select.value);if(!gestor)return;
  autoSubmit.disabled=true;autoError.textContent='';
  try{
   await sendRemoteAssistanceRequest(gestor);
   autoDialog.close();
  }catch(err){autoError.textContent=err.message;forceStop.hidden=!remoteConflict(err.message)}
  finally{autoSubmit.disabled=false}
 });
 autoCancel?.addEventListener('click',()=>{autoDialog.close()});
}
stop.addEventListener('click',async()=>{await endSession();setState('Sessão encerrada pelo gestor.');});
close.addEventListener('click',async()=>{if(visualFullscreen)await exitRemoteFullscreen();dialog.close();setState('Visualizador oculto; acesso remoto permanece ativo.');});
// Ao voltar à página, recupere a sessão sem pedir uma nova autorização.
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restoreRemoteSession,{once:true});else restoreRemoteSession();
window.addEventListener('pageshow',()=>{if(!commandId)restoreRemoteSession()});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&live&&commandId&&!pumping&&dialog.open)pump()});
open.addEventListener('click',()=>{if(commandId){showRemoteViewer();refresh()}});
dialog.addEventListener('cancel',e=>{e.preventDefault();if(visualFullscreen){exitRemoteFullscreen();return;}dialog.close()});
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
 [...(inline?[]:[modal]),document.getElementById('arc-network-confirm'),modal.querySelector('#arc-gestor-detail-modal'),modal.querySelector('#arc-team-editor'),modal.querySelector('#arc-headless-confirm'),document.getElementById('arc-remote-auto-modal'),document.getElementById('arc-remote-assistance-modal')].filter(Boolean).forEach(d=>d.addEventListener('click',e=>backdropClick(d,e)));

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

</div></main><?php require dirname(__DIR__).'/includes/footer.php'; ?>




<style>
/* Estilos nativos consolidados do suporte; sem blocos de sobreposição por versão. */
.arc-tile-version { display: block; color: rgb(145, 176, 200); max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 10px !important; }
.arc-power-dialog { border: 1px solid rgb(55, 101, 135); border-radius: 12px; background: rgb(23, 39, 62); color: rgb(233, 245, 255); width: min(94vw, 390px); padding: 17px; box-shadow: rgba(0, 0, 0, 0.6) 0px 14px 60px; }
.arc-power-dialog::backdrop { background: rgba(0, 0, 0, 0.667); backdrop-filter: blur(3px); }
.arc-power-dialog h3 { margin: 0px 0px 8px; font-size: 16px; }
.arc-power-dialog p { font-size: 12px; color: rgb(166, 189, 209); }
.arc-power-choices { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.arc-power-choices label { border: 1px solid rgb(61, 89, 121); border-radius: 8px; padding: 9px 5px; font-size: 12px; text-align: center; cursor: pointer; }
.arc-power-custom { display: block; margin: 12px 0px; font-size: 12px; }
.arc-power-custom input { width: 100%; margin-top: 4px; background: rgb(14, 32, 55); border: 1px solid rgb(69, 106, 135); color: rgb(255, 255, 255); border-radius: 6px; padding: 8px; box-sizing: border-box; }
.arc-power-buttons { display: flex; justify-content: end; gap: 8px; }
.arc-power-buttons button { border: 1px solid rgb(81, 119, 149); background: rgb(36, 60, 88); color: rgb(255, 255, 255); padding: 9px 14px; border-radius: 7px; }
.arc-power-buttons .arc-power-confirm { background: rgb(20, 126, 174); }
.arc-power-countdown { font-weight: 700; font-size: 11px; display: block; margin-top: 4px; color: rgb(247, 205, 99) !important; }
.arc-remote-screen-modal[open] { display: flex; flex-direction: column; }
.arc-remote-screen-modal::backdrop { background: rgba(0, 0, 0, 0.733); backdrop-filter: blur(5px); }
.arc-remote-heading { flex: 0 0 auto; min-width: 0px; }
.arc-remote-screen-head small { color: var(--muted); font-size: 11px; font-weight: 400; }
#arc-remote-assistance-state { padding: 3px 8px; color: rgb(128, 220, 202); font-size: 11px; flex: 0 0 auto; }
.arc-remote-screen-tools { display: flex; flex: 1 1 auto; min-width: 0px; align-items: center; justify-content: flex-end; gap: 2px; overflow-x: auto; scrollbar-width: thin; padding: 0px 5px; background: transparent; }
.arc-remote-screen-tools[hidden] { display: none !important; }
.arc-remote-tool { appearance: none; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 27px; width: 27px; height: 27px; padding: 0px; border: 1px solid transparent; border-radius: 5px; background: transparent; color: var(--text); cursor: pointer; font-size: 12px; }
.arc-remote-tool:hover { background: var(--surface-alt); border-color: var(--border); outline: none; }
.arc-remote-tool:focus-visible { background: var(--surface-alt); border-color: var(--border); outline: none; }
.arc-remote-divider { width: 1px; height: 19px; background: var(--surface-alt); flex: 0 0 1px; margin: 0px 4px; }
.arc-remote-select-label { display: inline-flex; align-items: center; gap: 4px; color: var(--text); font-size: 11px; flex: 0 0 auto; }
.arc-remote-select-label select { font-size: 11px; max-width: 145px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 5px; color: var(--text); padding: 4px; outline: none; }
.arc-remote-zoom-value { font: 10px "Segoe UI", sans-serif; color: var(--primary); white-space: nowrap; min-width: 30px; text-align: center; }
.arc-remote-screen-canvas.controlling { cursor: crosshair; box-shadow: rgb(47, 191, 155) 0px 0px 0px 2px inset; }
.arc-unlock-dialog { width: min(430px, -24px + 100vw); max-height: 85dvh; overflow: auto; position: fixed; inset: 0px; margin: auto; padding: 0px; border: 1px solid var(--border); border-radius: 16px; background: linear-gradient(155deg,#154d77,#091c35); color: #fff; box-shadow: rgba(0, 0, 0, 0.533) 0px 20px 80px; }
.arc-unlock-dialog::backdrop { background: rgba(4, 16, 28, 0.72); backdrop-filter: blur(5px); }
.arc-unlock-dialog form { display: flex; flex-direction: column; gap: 16px; padding: 30px; }
.arc-unlock-dialog header { position: relative; display: flex; flex-direction: column; gap: 14px; align-items: center; padding-top: 12px; }
.arc-unlock-dialog header button { position: absolute; right: -18px; top: -20px; color: #fff; }
#arc-unlock-host { text-align: center; color: #c7e4f7; }
.arc-unlock-dialog header > i { font-size: 76px; color: #e9f5ff; }
.arc-unlock-dialog header h2 { font-size: 24px; font-weight: 500; margin: 0px; }
.arc-unlock-dialog label { display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
.arc-unlock-dialog input, .arc-unlock-dialog select { font-size: 16px; min-width: 0px; width: 100%; box-sizing: border-box; padding: 11px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-alt); color: var(--text); }
.arc-unlock-dialog p { margin: 0px; font-size: 14px; line-height: 1.4; }
.arc-unlock-dialog small { color: #c1d7e7; line-height: 1.4; }
#arc-support-dashboard{width:100%;min-width:0;min-height:0;flex:1;overflow:auto}#arc-support-dashboard header{padding:8px 12px}#arc-support-dashboard h2{font-size:18px;margin:0 0 6px}#arc-support-dashboard p{font-size:14px;color:var(--muted)}
.arc-windows-dialog header{display:flex;align-items:center;justify-content:space-between;gap:12px}.arc-windows-dialog ul{padding-left:22px;max-height:45dvh;overflow:auto}.arc-windows-dialog li{padding:8px 0;overflow-wrap:anywhere}.arc-windows-dialog li small{display:block;color:var(--muted)}
#arc-windows-update.arc-windows-available{border-color:#efb544;animation:arcUpdateAlert 1.5s ease-in-out infinite}#arc-windows-update.arc-windows-available>i{color:#efb544}
@media(prefers-reduced-motion:reduce){#arc-windows-update.arc-windows-available{animation:none}}
.arc-mobile-keyboard{display:none}
@media(pointer:coarse),(max-width:700px){.arc-mobile-keyboard{display:inline-flex}}
.arc-remote-screen-canvas.controlling,.arc-remote-screen-canvas.controlling img{cursor:none;-webkit-touch-callout:none}
.arc-remote-cursor{position:absolute;width:20px;height:25px;pointer-events:none;z-index:4;transform:translate(-2px,-1px)}.arc-remote-cursor svg{width:100%;height:100%}.arc-remote-cursor[hidden]{display:none}
.arc-remote-click{position:absolute;width:30px;height:30px;border:2px solid #45c8ff;border-radius:50%;pointer-events:none;z-index:5;animation:arcClickFeedback .45s ease-out forwards}.arc-remote-click.is-right{border-color:#f5bc52}
@keyframes arcClickFeedback{from{transform:translate(-50%,-50%) scale(.3);opacity:1}to{transform:translate(-50%,-50%) scale(1.7);opacity:0}}
@media(prefers-reduced-motion:reduce){.arc-remote-click{animation:none;transform:translate(-50%,-50%)}}
.arc-remote-screen-canvas.is-zoomed { place-items: start; }
#arc-remote-mobile-input { position: absolute; left: 2px; top: 2px; width: 2px; height: 2px; opacity: 0.01; padding: 0px; border: 0px; font-size: 16px; resize: none; z-index: 0; }
#arc-remote-fullscreen-root[hidden] { display: none; }
#arc-remote-fullscreen-root { position: fixed; inset: var(--arc-remote-top,0) 0 auto; height: var(--arc-remote-height,100dvh); width: 100%; z-index: 2147483600; background: var(--surface,#07111e); }
#arc-remote-fullscreen-root .arc-remote-screen-modal { box-sizing: border-box; position: absolute !important; inset: 0px !important; width: 100% !important; height: 100% !important; max-width: none !important; max-height: none !important; margin: 0px !important; display: flex !important; flex-direction: column !important; padding: 0px !important; border: 0px !important; border-radius: 0px !important; }
#arc-remote-fullscreen-root .arc-remote-screen-head { display: flex; flex: 0 0 auto; min-height: 42px; padding-top: max(5px,env(safe-area-inset-top)); overflow: visible; }
#arc-remote-fullscreen-root .arc-remote-screen-canvas { flex: 1 1 0%; min-height: 0px; height: auto !important; }
#arc-remote-fullscreen-root .arc-remote-screen-actions { flex: 0 0 auto; padding-bottom: max(5px,env(safe-area-inset-bottom)); }
.arc-remote-fullscreen-open { overflow: hidden; }
.arc-remote-screen-canvas img { width: 100%; height: 100%; max-width: none; max-height: none; object-fit: contain; user-select: none; -webkit-user-drag: none; }
#arc-remote-screen-placeholder i { font-size: 50px; margin-bottom: 10px; }
.arc-remote-screen-actions small { color: var(--muted); }
.arc-remote-screen-modal { width: min(98vw, 1600px); max-width: 1600px; height: min(96vh, 1000px); max-height: 96vh; padding: 0px; border: 1px solid rgb(49, 92, 125); border-radius: 12px; background: var(--surface); color: var(--text); box-shadow: rgba(0, 0, 0, 0.733) 0px 20px 80px; overflow: hidden; }
.arc-remote-screen-head h2 { font-size: 12px; margin: 0px; white-space: nowrap; display: flex; align-items: center; gap: 10px; font-weight: 800; letter-spacing: 0.08em; }
.arc-remote-screen-head h2 small { font-size: 11px; font-weight: 500; letter-spacing: 0px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 50vw; }
.arc-remote-close { flex: 0 0 auto; width: 29px; height: 29px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-alt); color: var(--text); cursor: pointer; display: grid; place-items: center; transition: background 0.15s, border-color 0.15s; }
.arc-remote-close:hover { background: rgb(159, 50, 72); border-color: rgb(211, 85, 110); }
.arc-remote-screen-canvas { flex: 1 1 auto; min-height: 0px; min-width: 0px; display: grid; place-items: center; position: relative; background: var(--surface); overflow: auto; outline: none; padding: 0px; }
#arc-remote-screen-placeholder { text-align: center; color: var(--muted); display: flex; justify-content: center; width: 100%; height: 100%; padding: 15px; }
#arc-remote-screen-placeholder[hidden] { display: none; }
.arc-remote-request { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; min-width: 168px; padding: 22px 26px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-alt); color: var(--text); font: 700 13px "Segoe UI", sans-serif; cursor: pointer; box-shadow: rgba(0, 0, 0, 0.333) 0px 12px 32px; transition: transform 0.15s, background 0.15s; }
.arc-remote-request i { font-size: 30px; color: var(--primary); }
.arc-remote-request:hover { background: var(--surface-alt); transform: translateY(-2px); }
.arc-remote-request:disabled { opacity: 0.65; cursor: wait; transform: none; }
.arc-remote-request[hidden] { display: none !important; }
.arc-remote-screen-actions { display: flex; align-items: center; justify-content: center; gap: 9px; padding: 4px 12px; background: var(--surface-alt); flex: 0 0 auto; min-height: 26px; }
.arc-remote-screen-actions #arc-remote-assistance-state { font-size: 11px; color: rgb(157, 223, 204); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }
.arc-remote-screen-actions #arc-remote-control-hint { display: none !important; }
.arc-remote-screen-actions #arc-remote-screen-stop { display: none !important; }
#arc-remote-monitor-wrap[hidden] { display: none !important; }
#arc-remote-monitor { min-width: 85px; max-width: 140px; color: var(--text); }
.arc-remote-screen-head { display: flex; align-items: center; justify-content: space-between; background: var(--surface-alt); flex: 0 0 auto; box-sizing: border-box; min-width: 0px; }
.arc-remote-screen-head .arc-remote-screen-tools { flex-wrap: nowrap; scrollbar-width: thin; }
@media (max-width: 800px) {
.arc-remote-screen-head { gap: 4px; }
.arc-remote-screen-tools { justify-content: flex-start; }
.arc-remote-heading h2 { font-size: 10px; }
.arc-remote-heading small { display: block; max-width: 100px; overflow: hidden; text-overflow: ellipsis; }
}
#arc-remote-screen-placeholder { overflow: auto; scrollbar-width: none; align-items: flex-start; }
#arc-remote-screen-placeholder::-webkit-scrollbar { display: none; }
.arc-support-action-grid { width: 100%; display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 8px; margin: auto; padding: 8px; }
.arc-support-action-grid .arc-remote-request { width: 100%; min-height: 100px; box-sizing: border-box; text-align: center; text-decoration: none; min-width: 0px !important; max-width: none !important; gap: 8px !important; padding: 10px 4px !important; font-size: 11px !important; }
.arc-support-action-grid .arc-remote-request i { font-size: 29px !important; margin: 0px !important; }
.arc-support-action-grid .arc-remote-request span { white-space: normal; line-height: 1.2; }
.arc-support-action-grid .arc-remote-request small { font-size: 10px; }
.arc-support-action-grid .arc-action-locked { opacity: 0.37; filter: grayscale(1); cursor: not-allowed; }
@media (max-width: 700px) {
.arc-support-action-grid { grid-template-columns:repeat(3,minmax(0,1fr));gap: 7px; padding: 3px; }
.arc-support-action-grid .arc-remote-request { min-height: 86px; padding: 8px 4px !important; font-size: 10px !important; }
.arc-support-action-grid .arc-remote-request i { font-size: 23px !important; }
}
.arc-remote-auto-modal { width: min(92vw, 340px); padding: 0px; border: 1px solid rgb(49, 92, 125); border-radius: 14px; background: var(--surface-alt); color: var(--text); box-shadow: rgba(0, 0, 0, 0.733) 0px 20px 70px; }
.arc-remote-auto-modal::backdrop { background: rgba(0, 0, 0, 0.733); backdrop-filter: blur(4px); }
.arc-remote-auto-modal form { padding: 18px 20px; }
.arc-remote-auto-modal h2 { font-size: 15px; margin: 0px 0px 4px; }
.arc-remote-auto-modal p { font-size: 12px; color: var(--muted); margin: 0px 0px 10px; }
.arc-remote-auto-modal input { width: 100%; box-sizing: border-box; padding: 9px 10px; border-radius: 8px; border: 1px solid rgb(49, 92, 125); background: var(--surface); color: var(--text); font-size: 14px; }
#arc-remote-auto-error { display: block; min-height: 16px; color: var(--text); font-size: 12px; margin-top: 6px; }
.arc-remote-auto-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
#arc-gestor-update-confirm::backdrop { background: rgba(0, 0, 0, 0.55) !important; backdrop-filter: blur(3px) !important; }
.arc-remote-sr-title { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0px !important; margin: -1px !important; overflow: hidden !important; clip: rect(0px, 0px, 0px, 0px) !important; white-space: nowrap !important; border: 0px !important; }
.arc-remote-screen-head { padding: 4px 8px !important; min-height: 35px !important; gap: 3px !important; }
.arc-remote-screen-head .arc-remote-screen-tools { overflow-x: auto; justify-content: flex-start !important; flex: 1 1 auto !important; min-width: 0px !important; }
.arc-remote-screen-head .arc-remote-tool { width: 32px; height: 30px; flex-basis: 32px; font-size: 14px; }
.arc-remote-tool-modal { width: min(94vw, 640px); max-height: 82dvh; overflow: auto; padding: 0px; border: 1px solid rgb(66, 97, 127); border-radius: 13px; background: rgb(21, 37, 59); color: rgb(237, 245, 255); box-shadow: rgba(0, 0, 0, 0.6) 0px 18px 70px; }
.arc-remote-tool-modal::backdrop { background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); }
.arc-remote-tool-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid rgb(52, 77, 102); }
.arc-remote-tool-modal-header strong { font-size: 14px; }
.arc-remote-tool-modal-header button { border: 0px; background: transparent; color: rgb(216, 239, 255); font-size: 16px; padding: 4px 7px; }
.arc-remote-shortcuts { display: grid; grid-template-columns: repeat(2, minmax(0px, 1fr)); gap: 8px; padding: 13px; }
.arc-remote-shortcuts[hidden], .arc-remote-view-settings[hidden] { display: none !important; }
.arc-remote-shortcuts > p { grid-column: 1 / -1; color: rgb(170, 196, 221); font-size: 12px; margin: 2px; }
.arc-remote-shortcuts button { display: grid; grid-template-columns: 24px 1fr; align-items: center; gap: 4px 9px; text-align: left; padding: 10px; border: 1px solid rgb(54, 84, 114); border-radius: 9px; background: rgb(29, 48, 75); color: rgb(231, 245, 255); cursor: pointer; }
.arc-remote-shortcuts button:disabled { opacity: 0.55; cursor: not-allowed; }
.arc-remote-shortcuts button i { grid-row: span 2; color: rgb(87, 194, 238); }
.arc-remote-shortcuts button span { font-size: 12px; font-weight: 650; }
.arc-remote-shortcuts button small { font-size: 10px; color: rgb(158, 188, 212); }
.arc-remote-view-settings { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding: 15px; }
.arc-remote-view-settings p { width: 100%; font-size: 11px; color: rgb(157, 187, 209); margin: 0px; }
.arc-remote-view-settings .arc-remote-tool { border: 1px solid rgb(66, 97, 127); background: rgb(33, 55, 82); }
.arc-remote-view-settings select { color: rgb(231, 245, 255); background: rgb(33, 55, 82); }
@media (max-width: 700px) {
.arc-remote-screen-modal { width: 100vw; max-width: none; }
.arc-remote-shortcuts { gap: 6px; }
}
</style>
