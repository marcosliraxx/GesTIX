<?php /* Caminho: redes/_rodape.php */ ?>
</div></div></main>
<script>
(function(){
'use strict';
const networkId=<?= (int)$networkId ?>, csrf=<?= json_encode($csrf) ?>, base=<?= json_encode(arc_workspace_base_url()) ?>;
const target=document.getElementById('arc-agent-target'), log=document.getElementById('arc-command-log'), status=document.getElementById('arc-connection-status'), refresh=document.getElementById('arc-network-global-state');
if(!target||!log||!status)return;
const progress=document.createElement('div');progress.id='arc-operation-progress';progress.style.cssText='position:fixed;z-index:7000;bottom:12px;left:max(110px,12vw);right:16px;background:#10283f;color:#c8eaff;border:1px solid #277ba1;border-radius:8px;padding:7px 11px;box-shadow:0 8px 30px #001827a8;font:12px Segoe UI,Arial,sans-serif;display:none';
progress.innerHTML='<div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:5px"><strong id="arc-operation-label">Aguardando execução</strong><span id="arc-operation-state"></span></div><div style="height:4px;overflow:hidden;border-radius:4px;background:#1b4464"><div id="arc-operation-fill" style="height:100%;width:30%;background:linear-gradient(90deg,#35cff0,#556cf0);transition:width .3s"></div></div>';
document.body.append(progress);const progressLabel=progress.querySelector('#arc-operation-label'),progressState=progress.querySelector('#arc-operation-state'),progressFill=progress.querySelector('#arc-operation-fill');
let activeProgressId=0,progressStarted=0,progressTimer=0;
function showProgress(label,state='Na fila',id=0){
 progressFill.style.background='linear-gradient(90deg,#35cff0,#556cf0)';progress.style.display='block';progressLabel.textContent=label;progressState.textContent=state+' · etapa 1/3';progressStarted=Date.now();activeProgressId=id;
 clearInterval(progressTimer);progressFill.style.width='12%';
}
function endProgress(label,ok){clearInterval(progressTimer);progressLabel.textContent=label;progressState.textContent=ok?'100% · Concluído':'Falhou';progressFill.style.width=ok?'100%':'0%';progressFill.style.background=ok?'#2dd4a6':'#e97c91';setTimeout(()=>{if(!activeProgressId)progress.style.display='none'},1800)}

let toastTimer, lastSignature='', activeCommand=false;let queuedCommandId=0;
function toast(message,type='info'){
message=window.arcPlainMessage?window.arcPlainMessage(message):String(message);

 clearTimeout(toastTimer);log.textContent=message;log.dataset.kind=type;log.hidden=false;log.classList.remove('arc-toast-show');void log.offsetWidth;log.classList.add('arc-toast-show');
 toastTimer=setTimeout(()=>{log.classList.remove('arc-toast-show');log.hidden=true},5000);
}
function setRunning(on){}
function setOnline(online){const wrap=status.closest('.arc-agent-select-wrap');if(wrap){wrap.classList.toggle('is-online',!!online);wrap.classList.toggle('is-offline',!online)}status.classList.toggle('is-online',!!online);status.classList.toggle('is-offline',!online);status.title=online?'Agente online':'Agente offline';status.setAttribute('aria-label',status.title)}
let releaseStatus=null;
const versionInfo=document.getElementById('arc-agent-version-info'),updateRemote=document.getElementById('arc-agent-update-remote');
async function refreshAgentVersions(){
 if(!versionInfo)return;
 try{
  const resp=await fetch(base+'api/api.php?action=network-agent-versions&network_id='+networkId,{cache:'no-store',credentials:'same-origin'});
  const result=await resp.json();if(!resp.ok||!result.ok)throw Error(result.error||'Consulta indisponível');
  releaseStatus=result.release||null;document.dispatchEvent(new CustomEvent('arc:release-status',{detail:releaseStatus}));
  const current=(result.agents||[]).find(a=>Number(a.id)===Number(target.value));
  versionInfo.textContent=current?.version?'v. '+String(current.version).replace(/^v\.?\s*/i,''):'v. --';
  updateRemote.style.display='inline-flex';
  updateRemote.disabled=!(current?.approved);
  updateRemote.title=updateRemote.disabled?'Selecione um agente aprovado':'Atualizar agente; aguarda conexão por até 3 minutos';
 }catch(_e){releaseStatus=null;document.dispatchEvent(new CustomEvent('arc:release-status',{detail:null}));versionInfo.textContent='v. --';updateRemote.style.display='inline-flex';updateRemote.disabled=true}
}
refreshAgentVersions();setInterval(()=>{if(!document.hidden)refreshAgentVersions()},8000);
target.addEventListener('change',()=>document.getElementById('arc-agent-form').requestSubmit());
document.addEventListener('arc:command-queued',e=>{const d=e.detail||{};if(d.command_id&&Number(d.agent_id)===Number(target.value)&&!activeProgressId)showProgress(d.command||'Comando remoto','Na fila',Number(d.command_id))});
const labels={monitor_start:'Monitoramento iniciado',monitor_pause:'Monitoramento pausado',speed_test:'Teste de velocidade',scan_devices:'Varredura de dispositivos',scan_map:'Atualização da topologia',diagnostic:'Diagnóstico',quick_health:'Saúde rápida',dns_test:'Teste de DNS',public_ip:'IP público',system_snapshot:'Recursos do computador',flush_dns:'Limpeza de DNS',wifi_info:'Sinal Wi-Fi',agent_update:'Atualização remota do agente',power_shutdown:'Desligamento',power_restart:'Reinicialização',power_cancel:'Cancelamento do agendamento'};
async function askRemoteUpdateConfirm(){
 await refreshAgentVersions();
 return new Promise(resolve=>{
  const dialog=document.getElementById('arc-agent-update-confirm');
  if(!dialog||typeof dialog.showModal!=='function')return resolve(window.confirm('Atualizar remotamente o agente selecionado? O aplicativo será reiniciado.'));
  const label=(document.querySelector('#arc-agent-target option:checked')?.textContent||'Agente selecionado').trim();
  const title=dialog.querySelector('[data-update-agent-name]'); if(title) title.textContent=label;
  const details=dialog.querySelector('[data-update-agent-details]'); if(details) details.textContent='A plataforma reinstalará os arquivos publicados, mesmo que a versão seja a mesma. O agente será reiniciado automaticamente.';
  const cancel=dialog.querySelector('[data-update-cancel]');
  const confirm=dialog.querySelector('[data-update-confirm]');
  const report=dialog.querySelector('[data-update-release]');report.replaceChildren();if(releaseStatus){for(const row of releaseStatus.components||[]){const line=document.createElement('p');line.textContent=row.name+': '+(row.version||'não encontrado')+(row.aligned?'':' · precisa atualizar no servidor');report.append(line);}const info=document.createElement('p');info.textContent='Plataforma: '+releaseStatus.platform_version;report.prepend(info);}else report.textContent='Não foi possível conferir o pacote antes da tentativa.';confirm.disabled=!!releaseStatus&&!releaseStatus.ready;if(releaseStatus&&!releaseStatus.ready)details.textContent='O pacote Windows no servidor está incompleto. Reenvie a pasta agent da atualização. Isso é diferente da versão antiga instalada no computador.';
  const onCancel=(ev)=>{if(ev)ev.preventDefault(); cleanup(false)};
  const onOk=(ev)=>{if(ev)ev.preventDefault(); cleanup(true)};
  let finished=false;const onClose=()=>cleanup(false);
  const cleanup=(answer)=>{if(finished)return;finished=true;cancel?.removeEventListener('click',onCancel);confirm?.removeEventListener('click',onOk);dialog.removeEventListener('cancel',onCancel);dialog.removeEventListener('close',onClose);if(dialog.open)dialog.close();resolve(answer)};
  cancel?.addEventListener('click', onCancel);
  confirm?.addEventListener('click',onOk);dialog.addEventListener('cancel',onCancel);dialog.addEventListener('close',onClose);
  dialog.showModal();
 });
}
async function poll(notify=false){
 if(!target.value){setRunning(false);setOnline(false);return}
 try{
 const r=await fetch(base+'api/api.php?action=network-command-status&network_id='+networkId,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw Error('Não foi possível consultar os comandos');
 const j=await r.json();if(!j.ok)throw Error(j.error||'Erro de sincronização');
 const heartbeat=(j.agents||[]).find(a=>Number(a.id)===Number(target.value));
 setOnline(!!heartbeat&&heartbeat.status==='approved'&&Number(j.server_time)-Number(heartbeat.last_seen_unix||0)<120);
 const commands=(j.commands||[]).filter(x=>Number(x.agent_id)===Number(target.value));
 const x=(activeProgressId?commands.find(c=>Number(c.id)===activeProgressId):null)||commands[0];if(!x){setRunning(false);return}
 const state=String(x.status||'').toLowerCase();const busy=['queued','pending','running','processing'].includes(state);
 setRunning(busy);
 if(activeProgressId&&Number(x.id)===activeProgressId){if(busy){const pct=Number(x.progress_percent);if(Number.isFinite(pct)&&x.progress_percent!==undefined&&x.progress_percent!==null){progressFill.style.width=Math.max(0,Math.min(99,pct))+'%';progressState.textContent=Math.round(pct)+'% · '+(state==='running'?'Executando':'Na fila')}else{progressFill.style.width=state==='running'?'50%':'12%';progressState.textContent=state==='running'?'Etapa 2/3 · Executando':'Etapa 1/3 · Na fila'}}else{progressState.textContent=state;}if(!busy){const ok=state==='completed';const label=(labels[x.command_name]||x.command_name)+' — '+(ok?'concluído':'falhou');activeProgressId=0;endProgress(label,ok);document.dispatchEvent(new CustomEvent('arc:operation-updated',{detail:{id:x.id,status:state,command:x.command_name}}));if(ok&&['scan_devices','scan_map','diagnostic','speed_test'].includes(x.command_name))setTimeout(()=>location.reload(),1400)}}
 const sig=[x.id,x.status,x.result_text].join(':');
 if(sig!==lastSignature){lastSignature=sig;if(['completed','failed','expired'].includes(state)){document.dispatchEvent(new CustomEvent('arc:command-finished',{detail:{id:x.id,status:state,command:x.command_name}}));if(queuedCommandId===Number(x.id)&&state==='completed'){queuedCommandId=0;if(!document.querySelector('.arc-terminal'))setTimeout(()=>location.reload(),1100)}}if(notify||activeCommand){toast((labels[x.command_name]||x.command_name||'Comando')+' — '+(state==='failed'?'falhou':busy?'em execução':'recebido')+(x.result_text?' · '+x.result_text:''),state==='failed'?'error':'info');activeCommand=false}}
 }catch(e){if(notify)toast(e.message,'error')}
}
refresh?.addEventListener('click',()=>{refresh.classList.add('is-refreshing');poll(true).finally(()=>setTimeout(()=>refresh.classList.remove('is-refreshing'),650))});
document.addEventListener('click',async e=>{
 const b=e.target.closest('[data-arc-command]');if(!b)return;
 e.preventDefault();if(!target.value){toast('Selecione um agente aprovado antes de executar comandos.','error');return}
 if(b.disabled)return;
 if(b.dataset.arcCommand==='agent_update'){const proceed=await askRemoteUpdateConfirm();if(!proceed)return;}
 b.disabled=true;setRunning(true);toast('Enviando comando ao agente…');
 try{const r=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:networkId,agent_id:Number(target.value),command:b.dataset.arcCommand,_csrf:csrf})});const j=await r.json();if(!r.ok||!j.ok){const error=Error(j.error||'Falha ao enviar comando');error.supportRef=j.error_ref||'';throw error;}activeCommand=true;queuedCommandId=Number(j.command_id||0);showProgress(labels[b.dataset.arcCommand]||b.dataset.arcCommand,'Na fila',queuedCommandId);document.dispatchEvent(new CustomEvent('arc:command-queued',{detail:{command:b.dataset.arcCommand,command_id:Number(j.command_id),agent_id:Number(target.value)}}));toast('Comando enviado. Aguardando o agente.','success');setTimeout(()=>poll(true),1200)}catch(err){setRunning(false);activeProgressId=0;endProgress('Falha ao enviar comando',false);toast(err.message+(err.supportRef?' Referência para suporte: '+err.supportRef:''),'error')}finally{b.disabled=false}
});
poll();setInterval(()=>{if(!document.hidden)poll()},5000);
})();
</script>
<dialog id="arc-remote-assistance-modal" class="arc-remote-screen-modal" aria-labelledby="arc-remote-assistance-title">
 <div class="arc-remote-screen-head">
  <div class="arc-remote-heading"><h2 id="arc-remote-assistance-title">ACESSO REMOTO <small id="arc-remote-assistance-host"></small></h2></div>
  <div class="arc-remote-screen-tools" id="arc-remote-tools" hidden role="toolbar" aria-label="Ferramentas do acesso remoto">
  <label class="arc-remote-select-label" id="arc-remote-monitor-wrap" hidden title="Selecionar monitor remoto"><i class="fa fa-desktop" aria-hidden="true"></i><select id="arc-remote-monitor" aria-label="Selecionar tela remota"></select></label>
  
   <button type="button" class="arc-remote-tool" data-rc-combo="win" title="Abrir menu Iniciar" aria-label="Abrir menu Iniciar"><i class="fa fa-windows"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="win_r" title="Executar (Win + R)" aria-label="Executar"><i class="fa fa-terminal"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="win_d" title="Mostrar área de trabalho" aria-label="Mostrar área de trabalho"><i class="fa fa-desktop"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="alt_tab" title="Alternar janelas (Alt + Tab)" aria-label="Alternar janelas"><i class="fa fa-clone"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="ctrl_shift_esc" title="Gerenciador de tarefas" aria-label="Gerenciador de tarefas"><i class="fa fa-tasks"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="ctrl_a" title="Selecionar tudo" aria-label="Selecionar tudo"><i class="fa fa-i-cursor"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="ctrl_c" title="Copiar seleção remota" aria-label="Copiar seleção remota"><i class="fa fa-copy"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="ctrl_v" title="Ctrl+V no computador remoto" aria-label="Ctrl+V no computador remoto"><i class="fa fa-paste"></i></button>
   <button type="button" class="arc-remote-tool" data-rc-combo="alt_f4" title="Fechar janela ativa (Alt + F4)" aria-label="Fechar janela ativa"><i class="fa fa-window-close"></i></button>
   <span class="arc-remote-divider" aria-hidden="true"></span>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-out" title="Diminuir zoom" aria-label="Diminuir zoom"><i class="fa fa-search-minus"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-fit" title="Ajustar tela à janela" aria-label="Ajustar tela à janela"><i class="fa fa-arrows-alt"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-zoom-in" title="Aumentar zoom" aria-label="Aumentar zoom"><i class="fa fa-search-plus"></i></button>
   <span id="arc-remote-zoom-value" class="arc-remote-zoom-value" title="Zoom da visualização">100%</span>
   <label class="arc-remote-select-label" title="Qualidade real da transmissão"><i class="fa fa-sliders" aria-hidden="true"></i><select id="arc-remote-quality" aria-label="Qualidade da transmissão"><option value="balanced">Qualidade: padrão</option><option value="low">Qualidade: econômica</option><option value="high">Qualidade: alta</option></select></label>
   <button type="button" class="arc-remote-tool" id="arc-remote-snapshot" title="Salvar captura da tela atual" aria-label="Salvar captura da tela atual"><i class="fa fa-camera"></i></button>
   <button type="button" class="arc-remote-tool" id="arc-remote-fullscreen" title="Tela cheia" aria-label="Tela cheia"><i class="fa fa-expand"></i></button>
   <div class="arc-remote-text-wrap"><input type="text" id="arc-remote-text" maxlength="400" placeholder="Digitar texto..." aria-label="Texto no computador remoto" autocomplete="off"><button type="button" class="arc-remote-tool" id="arc-remote-text-send" title="Enviar texto" aria-label="Enviar texto"><i class="fa fa-paper-plane"></i></button></div>
  </div>
  <button type="button" class="arc-remote-close" id="arc-remote-assistance-close" aria-label="Fechar visualizador"><i class="fa fa-times"></i></button>
 </div>
 <div class="arc-remote-screen-canvas" id="arc-remote-canvas" tabindex="0"><img id="arc-remote-screen-image" alt="Tela compartilhada pelo agente autorizado" draggable="false" hidden><div id="arc-remote-screen-placeholder"><button type="button" class="arc-remote-request" id="arc-remote-assistance-send"><i class="fa fa-desktop" aria-hidden="true"></i><span>Solicitar acesso</span></button></div></div>
 <div class="arc-remote-screen-actions"><small id="arc-remote-assistance-state" role="status">Aguardando solicitação de acesso.</small><small id="arc-remote-control-hint" hidden></small><button type="button" id="arc-remote-screen-stop" hidden>Encerrar sessão</button></div>
</dialog>
<style>
.arc-remote-screen-modal[open]{display:flex;flex-direction:column}
.arc-remote-screen-modal::backdrop{background:#000b;backdrop-filter:blur(5px)}
.arc-remote-heading{flex:0 0 auto;min-width:0}.arc-remote-screen-head small{color:var(--muted);font-size:11px;font-weight:400}
#arc-remote-assistance-state{padding:3px 8px;color:#80dcca;font-size:11px;flex:0 0 auto}
.arc-remote-screen-tools{display:flex;flex:1 1 auto;min-width:0;align-items:center;justify-content:flex-end;gap:2px;overflow-x:auto;scrollbar-width:thin;padding:0 5px;background:transparent}
.arc-remote-screen-tools[hidden]{display:none!important}
.arc-remote-tool{appearance:none;display:inline-flex;align-items:center;justify-content:center;flex:0 0 27px;width:27px;height:27px;padding:0;border:1px solid transparent;border-radius:5px;background:transparent;color:var(--text);cursor:pointer;font-size:12px}
.arc-remote-tool:hover{background:var(--surface-alt);border-color:var(--border);outline:none}
.arc-remote-tool:focus-visible{background:var(--surface-alt);border-color:var(--border);outline:none}
.arc-remote-divider{width:1px;height:19px;background:var(--surface-alt);flex:0 0 1px;margin:0 4px}
.arc-remote-select-label{display:inline-flex;align-items:center;gap:4px;color:var(--text);font-size:11px;flex:0 0 auto}.arc-remote-select-label select{font-size:11px;max-width:145px;background:var(--surface-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);padding:4px;outline:none}
.arc-remote-zoom-value{font:10px 'Segoe UI',sans-serif;color:var(--primary);white-space:nowrap;min-width:30px;text-align:center}
.arc-remote-text-wrap{display:flex;align-items:center;flex:0 0 136px;min-width:0;border:1px solid var(--border);border-radius:6px;background:var(--surface)}
.arc-remote-text-wrap input{width:100%;min-width:0;padding:5px 3px 5px 7px;border:0;outline:none;background:transparent;color:var(--text);font-size:11px}
.arc-remote-text-wrap .arc-remote-tool{flex:0 0 25px;width:25px;height:25px}
.arc-remote-screen-canvas.controlling{cursor:crosshair;box-shadow:inset 0 0 0 2px #2fbf9b}
.arc-remote-screen-canvas img{width:100%;height:100%;object-fit:contain;user-select:none;-webkit-user-drag:none}#arc-remote-screen-placeholder i{font-size:50px;margin-bottom:10px}
.arc-remote-screen-actions small{color:var(--muted)}
.arc-remote-screen-modal{width:min(98vw,1600px);max-width:1600px;height:min(96vh,1000px);max-height:96vh;padding:0;border:1px solid #315c7d;border-radius:12px;background:var(--surface);color:var(--text);box-shadow:0 20px 80px #000b;overflow:hidden}
.arc-remote-screen-head h2{font-size:12px;margin:0;white-space:nowrap;display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.08em}
.arc-remote-screen-head h2 small{font-size:11px;font-weight:500;letter-spacing:0;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50vw}
.arc-remote-close{flex:0 0 auto;width:29px;height:29px;border:1px solid var(--border);border-radius:9px;background:var(--surface-alt);color:var(--text);cursor:pointer;display:grid;place-items:center;transition:background .15s,border-color .15s}
.arc-remote-close:hover{background:#9f3248;border-color:#d3556e}
.arc-remote-screen-canvas{flex:1 1 auto;min-height:0;display:flex;align-items:center;justify-content:center;position:relative;background:var(--surface);overflow:hidden;outline:none}
#arc-remote-screen-placeholder{text-align:center;color:var(--muted);display:flex;align-items:center;justify-content:center;width:100%;height:100%;padding:15px}
#arc-remote-screen-placeholder[hidden]{display:none}
.arc-remote-request{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;min-width:168px;padding:22px 26px;border:1px solid var(--border);border-radius:14px;background:var(--surface-alt);color:var(--text);font:700 13px 'Segoe UI',sans-serif;cursor:pointer;box-shadow:0 12px 32px #0005;transition:transform .15s,background .15s}
.arc-remote-request i{font-size:30px;color:var(--primary)}
.arc-remote-request:hover{background:var(--surface-alt);transform:translateY(-2px)}
.arc-remote-request:disabled{opacity:.65;cursor:wait;transform:none}
.arc-remote-request[hidden]{display:none!important}
.arc-remote-screen-actions{display:flex;align-items:center;justify-content:center;gap:9px;padding:4px 12px;background:var(--surface-alt);flex:0 0 auto;min-height:26px}
.arc-remote-screen-actions #arc-remote-assistance-state{font-size:11px;color:#9ddfcc;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center}
.arc-remote-screen-actions #arc-remote-control-hint{display:none!important}
.arc-remote-screen-actions #arc-remote-screen-stop{display:none!important}

#arc-remote-monitor-wrap[hidden]{display:none!important}
#arc-remote-monitor{min-width:85px;max-width:140px;color:var(--text)}
.arc-remote-screen-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 13px;background:var(--surface-alt);flex:0 0 auto;min-height:42px;box-sizing:border-box;min-width:0}
.arc-remote-screen-head .arc-remote-screen-tools{min-width:0;overflow-x:auto;flex-wrap:nowrap;scrollbar-width:thin}
@media(max-width:800px){.arc-remote-screen-head{gap:4px}.arc-remote-screen-tools{justify-content:flex-start}.arc-remote-heading h2{font-size:10px}.arc-remote-heading small{display:block;max-width:100px;overflow:hidden;text-overflow:ellipsis}}
</style>
<!-- Confirmação de sessão para agentes autorizados pelo gestor como "sem monitor/teclado/mouse"
     (aba Agentes do gerenciador da rede). Faz parte do mesmo botão "Solicitar acesso" acima — não é um
     atalho separado nem escondido; só aparece quando não há como o computador remoto pedir confirmação
     a ninguém. A sessão continua visível na tela remota (se houver uma) e registrada na auditoria. -->
<dialog id="arc-remote-auto-modal" class="arc-remote-auto-modal" aria-labelledby="arc-remote-auto-title">
 <form id="arc-remote-auto-form">
  <h2 id="arc-remote-auto-title">Confirmar acesso remoto</h2>
  <p>Este computador está marcado como servidor sem monitor, teclado ou mouse, então ninguém poderia autorizar o acesso na tela dele. Confirme a abertura da sessão com sua conta já autenticada — ela ficará registrada na auditoria.</p>
  <small id="arc-remote-auto-error" role="alert"></small>
  <div class="arc-remote-auto-actions">
   <button type="button" class="button secondary" id="arc-remote-auto-cancel">Cancelar</button>
   <button type="submit" class="button" id="arc-remote-auto-submit">Confirmar e abrir acesso</button>
  </div>
 </form>
</dialog>
<style>
.arc-remote-auto-modal{width:min(92vw,340px);padding:0;border:1px solid #315c7d;border-radius:14px;background:var(--surface-alt);color:var(--text);box-shadow:0 20px 70px #000b}
.arc-remote-auto-modal::backdrop{background:#000b;backdrop-filter:blur(4px)}
.arc-remote-auto-modal form{padding:18px 20px}
.arc-remote-auto-modal h2{font-size:15px;margin:0 0 4px}
.arc-remote-auto-modal p{font-size:12px;color:var(--muted);margin:0 0 10px}
.arc-remote-auto-modal input{width:100%;box-sizing:border-box;padding:9px 10px;border-radius:8px;border:1px solid #315c7d;background:var(--surface);color:var(--text);font-size:14px}
#arc-remote-auto-error{display:block;min-height:16px;color:var(--text);font-size:12px;margin-top:6px}
.arc-remote-auto-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
</style>
<script>
(()=>{'use strict';
const $=id=>document.getElementById(id);
const dialog=$('arc-remote-assistance-modal'),open=$('arc-agent-remote-view'),send=$('arc-remote-assistance-send'),close=$('arc-remote-assistance-close'),stop=$('arc-remote-screen-stop'),state=$('arc-remote-assistance-state'),select=$('arc-agent-target'),host=$('arc-remote-assistance-host'),image=$('arc-remote-screen-image'),placeholder=$('arc-remote-screen-placeholder'),canvas=$('arc-remote-canvas'),tools=$('arc-remote-tools'),hint=$('arc-remote-control-hint'),textInput=$('arc-remote-text'),textSend=$('arc-remote-text-send'),fsBtn=$('arc-remote-fullscreen'),monitorWrap=$('arc-remote-monitor-wrap'),monitorSelect=$('arc-remote-monitor');
if(!dialog||!open||!send||!select)return;
const network=<?= (int)$networkId ?>,csrf=<?= json_encode($csrf) ?>,base=<?= json_encode(arc_workspace_base_url()) ?>;
const HINT_VIEW='Tela completa, incluindo a barra de tarefas. O controle de mouse e teclado depende da autorização no computador remoto.';
const HINT_CTRL='Controle ativo: clique na tela para usar mouse e teclado. Ctrl+V digita o texto copiado no computador remoto.';
let commandId=0,timer=null,locked=false,live=false,controlOn=false,pumping=false,lastMonitorKey='';
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
 if(!on){releaseAll();inq=[]}
}
/* ---------- quadros: carrega o próximo assim que o anterior chega ---------- */
async function pump(){
 if(pumping)return;pumping=true;
 while(live&&commandId&&dialog.open){
  try{
   const r=await fetch(frameUrl('image')+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'});
   if(r.ok){const blob=await r.blob();const url=URL.createObjectURL(blob);const old=image.src;image.src=url;if(old&&old.startsWith('blob:'))URL.revokeObjectURL(old);image.hidden=false;placeholder.hidden=true}
  }catch(e){}
  await new Promise(res=>setTimeout(res,controlOn?70:450));
 }
 pumping=false;
}
async function endSession(){
 clearTimers();live=false;setControl(false);
 if(commandId){try{await fetch(frameUrl('stop'),{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/json'},body:JSON.stringify({_csrf:csrf})})}catch(e){}}
 if(monitorWrap)monitorWrap.hidden=true;lastMonitorKey='';
 commandId=0;if(image.src&&image.src.startsWith('blob:'))URL.revokeObjectURL(image.src);
 image.hidden=true;image.removeAttribute('src');placeholder.hidden=false;stop.hidden=true;send.hidden=false;send.disabled=false;tools.hidden=true;hint.textContent=HINT_VIEW;
}
async function refresh(){
 if(!commandId||!dialog.open||locked)return;locked=true;
 try{
  const response=await fetch(frameUrl('status'),{credentials:'same-origin',cache:'no-store'});const result=await response.json().catch(()=>({ok:false,error:'Resposta inválida da API (HTTP '+response.status+').'}));if(!response.ok)throw Error(result.error||'Erro HTTP '+response.status);
  if(!result.ok||result.stopped||['failed','completed','expired'].includes(result.status)){
   setState('A sessão foi encerrada ou recusada.');await endSession();return;
  }
  renderMonitorPicker(result.monitors||[],result.selected_monitor||'');
  if(result.has_frame){
   if(!live){live=true;pump();qualitySelect?.dispatchEvent(new Event('change'))}
   setControl(result.control);
   setState(result.control?'● Acesso remoto ativo · controle de mouse e teclado autorizado':'● Visualização ativa · controle não autorizado (somente tela)');
  }else setState('Aguardando autorização ou primeira imagem do computador...');
 }catch(e){setState('Falha ao consultar sessão remota: '+(e.message||'erro desconhecido'))}finally{locked=false}
}
/* ---------- entrada: mouse e teclado ---------- */
function push(ev){
 if(!controlOn)return;
 if(ev.t==='m'&&inq.length&&inq[inq.length-1].t==='m')inq[inq.length-1]=ev;else inq.push(ev);
 if(!flushT)flushT=setTimeout(flush,35);
}
async function flush(){
 flushT=null;if(!controlOn||!commandId){inq=[];return}
 if(inflight||!inq.length){if(inq.length&&!flushT)flushT=setTimeout(flush,35);return}
 inflight=true;const batch=inq.splice(0,200);
 try{
  const res=await fetch(frameUrl('input'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({events:batch,_csrf:csrf})});
  if(res.status===409){setControl(false);setState('O controle foi desativado no computador remoto.')}
 }catch(e){}
 inflight=false;if(inq.length&&!flushT)flushT=setTimeout(flush,35);
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
canvas.addEventListener('mousemove',e=>{if(!controlOn)return;const p=pos(e);if(p&&p.inside)push({t:'m',x:p.x,y:p.y})});
canvas.addEventListener('mousedown',e=>{if(!controlOn)return;canvas.focus();e.preventDefault();const p=pos(e);if(!p||!p.inside)return;buttonsDown.add(btn(e));push({t:'d',x:p.x,y:p.y,b:btn(e)})});
window.addEventListener('mouseup',e=>{if(!controlOn||!buttonsDown.has(btn(e)))return;const p=pos(e)||{x:0,y:0};buttonsDown.delete(btn(e));push({t:'u',x:p.x,y:p.y,b:btn(e)})});
canvas.addEventListener('contextmenu',e=>{if(controlOn)e.preventDefault()});
canvas.addEventListener('wheel',e=>{if(!controlOn)return;e.preventDefault();const k=e.deltaMode===0?100:1;push({t:'w',dx:Math.round(e.deltaX/k)||0,dy:Math.round(e.deltaY/k)||(e.deltaY?Math.sign(e.deltaY):0)})},{passive:false});
canvas.addEventListener('keydown',e=>{
 if(!controlOn)return;
 if((e.ctrlKey||e.metaKey)&&e.code==='KeyV')return; /* o evento paste envia o texto */
 e.preventDefault();pressed.add(e.code);push({t:'k',d:1,c:e.code});
});
canvas.addEventListener('keyup',e=>{if(!controlOn)return;e.preventDefault();pressed.delete(e.code);push({t:'k',d:0,c:e.code})});
canvas.addEventListener('blur',()=>{if(controlOn)releaseAll()});
canvas.addEventListener('paste',e=>{if(!controlOn)return;e.preventDefault();const t=(e.clipboardData||window.clipboardData).getData('text');if(t)push({t:'x',s:t.slice(0,400)})});
tools.addEventListener('click',e=>{const b=e.target.closest('[data-rc-combo]');if(b&&controlOn)push({t:'c',n:b.getAttribute('data-rc-combo')})});
function sendText(){const t=textInput.value;if(!t||!controlOn)return;push({t:'x',s:t.slice(0,400)});textInput.value=''}
textSend.addEventListener('click',sendText);
textInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();sendText()}e.stopPropagation()});
fsBtn.addEventListener('click',()=>{if(document.fullscreenElement)document.exitFullscreen();else if(canvas.requestFullscreen)canvas.requestFullscreen()});
/* Zoom altera apenas a apresentação, sem alterar as coordenadas normalizadas do mouse. */
let zoomFactor=1;
const qualitySelect=$('arc-remote-quality');
qualitySelect?.addEventListener('change',async()=>{
 if(!commandId){setState('A qualidade selecionada será usada após iniciar uma sessão.');return}
 try{
  const r=await fetch(frameUrl('preferences'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({_csrf:csrf,quality:qualitySelect.value})});
  const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Não foi possível ajustar a qualidade');
  setState('Qualidade da transmissão atualizada: '+qualitySelect.selectedOptions[0].textContent+'.');
 }catch(err){setState(err.message)}
});
const zoomValue=$('arc-remote-zoom-value');
function setZoom(value){
 zoomFactor=Math.max(0.5,Math.min(3,Math.round(value*10)/10));
 image.style.width=zoomFactor===1?'100%':(zoomFactor*100)+'%';
 image.style.height=zoomFactor===1?'100%':(zoomFactor*100)+'%';
 image.style.maxWidth='none';image.style.maxHeight='none';
 if(zoomValue)zoomValue.textContent=Math.round(zoomFactor*100)+'%';
}
$('arc-remote-zoom-in')?.addEventListener('click',()=>setZoom(zoomFactor+0.2));
$('arc-remote-zoom-out')?.addEventListener('click',()=>setZoom(zoomFactor-0.2));
$('arc-remote-zoom-fit')?.addEventListener('click',()=>setZoom(1));
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

/* ---------- solicitação ---------- */
open.addEventListener('click',()=>{
 if(!select.value){setState('Selecione um agente aprovado.');return dialog.showModal()}
 host.textContent='• '+(select.selectedOptions[0]?.textContent||'Agente');setState('Solicite autorização ao operador do computador.');dialog.showModal();
});
async function sendRemoteAssistanceRequest(agent,confirmPassword){
 const payload={network_id:network,agent_id:agent,command:'remote_assistance',_csrf:csrf};
 if(confirmPassword)payload.confirm_password=confirmPassword;
 const res=await fetch(base+'api/api.php?action=network-command',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
 const value=await res.json().catch(()=>({error:'O servidor retornou uma resposta inválida. Verifique o log PHP (HTTP '+res.status+').'}));if(!res.ok||!value.ok||!value.command_id)throw Error(value.error||'Falha ao solicitar acesso');
 commandId=Number(value.command_id);send.hidden=true;stop.hidden=false;
 setState(confirmPassword?'Acesso automático liberado (dispositivo autorizado)...':'Aguardando o usuário permitir o acesso no Windows...');
 clearTimers();timer=setInterval(refresh,1200);refresh();
}
const autoDialog=$('arc-remote-auto-modal'),autoForm=$('arc-remote-auto-form'),autoError=$('arc-remote-auto-error'),autoCancel=$('arc-remote-auto-cancel'),autoSubmit=$('arc-remote-auto-submit');
send.addEventListener('click',async()=>{
 const agent=Number(select.value);if(!agent)return setState('Selecione um agente aprovado.');
 const isHeadless=select.selectedOptions[0]?.dataset.headless==='1';
 if(isHeadless){
  if(autoDialog){autoError.textContent='';autoDialog.showModal()}
  return;
 }
 send.disabled=true;setState('Enviando solicitação ao agente...');
 try{await sendRemoteAssistanceRequest(agent)}catch(e){setState('Erro: '+e.message);send.disabled=false}
});
if(autoForm){
 autoForm.addEventListener('submit',async(e)=>{
  e.preventDefault();const agent=Number(select.value);if(!agent)return;
  autoSubmit.disabled=true;autoError.textContent='';
  try{
   await sendRemoteAssistanceRequest(agent);
   autoDialog.close();
  }catch(err){autoError.textContent=err.message}
  finally{autoSubmit.disabled=false}
 });
 autoCancel?.addEventListener('click',()=>{autoDialog.close()});
}
stop.addEventListener('click',async()=>{await endSession();setState('Sessão encerrada pelo gestor.');});
close.addEventListener('click',async()=>{await endSession();dialog.close()});
dialog.addEventListener('cancel',e=>{e.preventDefault();if(document.fullscreenElement)return;endSession().then(()=>dialog.close())});
})();
</script>
<dialog id="arc-agent-update-confirm" class="arc-agent-update-confirm arc-service-dialog" aria-labelledby="arc-agent-update-title">
<div class="arc-agent-update-card">
 <div class="arc-agent-update-icon"><i class="fa fa-refresh" aria-hidden="true"></i></div>
 <div class="arc-agent-update-copy">
  <span class="arc-agent-update-eyebrow">Atualização remota</span>
  <h2 id="arc-agent-update-title">Confirmar atualização do agente</h2>
  <strong data-update-agent-name>Agente selecionado</strong>
  <p data-update-agent-details>O agente será reiniciado após receber a atualização.</p>
  <div data-update-release class="arc-update-release-details" role="status"></div>
 </div>
 <div class="arc-agent-update-actions arc-service-actions">
  <button type="button" class="button secondary" data-update-cancel>Cancelar</button>
  <button type="button" class="button" data-update-confirm><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar agente</button>
 </div>
</div>
</dialog>
<dialog id="arc-network-confirm" class="arc-network-confirm" aria-labelledby="arc-network-confirm-title">
<div class="arc-confirm-head"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i><h2 id="arc-network-confirm-title">Confirmar operação</h2></div>
<p data-confirm-text>Deseja continuar?</p><div class="arc-confirm-actions"><button type="button" class="button secondary" data-confirm-cancel>Cancelar</button><button type="button" class="button danger" data-confirm-proceed>Confirmar</button></div>
</dialog>
<script>
(function(){
 const modal=document.getElementById('arc-network-manager');if(!modal)return;
 const tabs=[...modal.querySelectorAll('[data-manager-tab]')];
 const panels=[...modal.querySelectorAll('[data-manager-panel]')];
 function choose(key){
  tabs.forEach(b=>{const active=b.dataset.managerTab===key;b.classList.toggle('active',active);b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1});
  panels.forEach(p=>{const active=p.dataset.managerPanel===key;p.hidden=!active;p.classList.toggle('active',active)});
  const body=modal.querySelector('.arc-manager-body');if(body)body.scrollTop=0;
 }
 function open(key){choose(key||'agents');if(!modal.open)modal.showModal()}
 document.addEventListener('click',function(e){
  const opener=e.target.closest('[data-network-modal-open]');if(opener){e.preventDefault();open(opener.dataset.networkModalOpen||'agents');return}
  const closer=e.target.closest('[data-network-modal-close]');if(closer){e.preventDefault();modal.close();return}
  const tab=e.target.closest('[data-manager-tab]');if(tab){e.preventDefault();choose(tab.dataset.managerTab)}
 });
 // Fechar apenas quando o clique ocorrer no backdrop, sem fechar ao clicar no conteúdo.
 function backdropClick(dialog,e){
  if(!dialog.open || e.target!==dialog)return;
  const r=dialog.getBoundingClientRect();
  if(e.clientX<r.left || e.clientX>r.right || e.clientY<r.top || e.clientY>r.bottom || (dialog===modal && !e.target.closest('.arc-network-manager-shell')))dialog.close();
 }
 [modal,document.getElementById('arc-network-confirm'),modal.querySelector('#arc-agent-detail-modal'),modal.querySelector('#arc-team-editor'),modal.querySelector('#arc-headless-confirm'),document.getElementById('arc-remote-auto-modal'),document.getElementById('arc-remote-assistance-modal')].filter(Boolean).forEach(d=>d.addEventListener('click',e=>backdropClick(d,e)));

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
 const agentDetail=modal.querySelector('#arc-agent-detail-modal');
 modal.querySelectorAll('[data-agent-details]').forEach(row=>{
  const show=()=>{const source=document.getElementById(row.dataset.agentDetails);if(!source||!agentDetail)return;agentDetail.querySelector('[data-detail-content]').replaceChildren(source.cloneNode(true));agentDetail.querySelector('[data-detail-content] > div').hidden=false;agentDetail.showModal()};
  row.addEventListener('click',e=>{if(!e.target.closest('button,a,form,input,select'))show()});
  row.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target===row){e.preventDefault();show()}});
 });
 agentDetail?.querySelector('[data-detail-close]')?.addEventListener('click',()=>agentDetail.close());
 const teamEditor=modal.querySelector('#arc-team-editor');
 const editTeam=(email,role)=>{if(!teamEditor)return;teamEditor.querySelector('[name=member_email]').value=email||'';teamEditor.querySelector('[name=member_email]').readOnly=!!email;teamEditor.querySelector('[name=member_role]').value=role||'viewer';teamEditor.querySelector('#arc-team-editor-title').textContent=email?'Editar perfil':'Adicionar integrante';teamEditor.showModal()};
 modal.querySelector('[data-team-add]')?.addEventListener('click',()=>editTeam('','viewer'));
 modal.querySelectorAll('[data-team-edit]').forEach(btn=>btn.addEventListener('click',()=>editTeam(btn.dataset.teamEmail,btn.dataset.teamRole)));
 modal.querySelectorAll('[data-team-close]').forEach(btn=>btn.addEventListener('click',()=>teamEditor?.close()));
 const headless=modal.querySelector('#arc-headless-confirm');
 modal.querySelectorAll('.arc-headless-toggle-btn').forEach(btn=>btn.addEventListener('click',()=>{
  headless.querySelector('#arc-headless-confirm-agent-id').value=btn.dataset.headlessAgentId;
  headless.querySelector('#arc-headless-confirm-text').textContent=(btn.dataset.headlessCurrent==='1'?'Desativar':'Ativar')+' acesso sem confirmação local para '+btn.dataset.headlessAgentName+'?';headless.showModal();
 }));
 modal.querySelector('#arc-headless-confirm-cancel')?.addEventListener('click',()=>headless?.close());
 const hash=(location.hash||'').toLowerCase();if(hash==='#team'||hash==='#users')open('team');else if(hash==='#agents'||hash==='#installations')open('agents');else if(hash==='#settings'||hash==='#preferences')open('settings');else if(hash==='#cadastro')open('data');
})();
</script>
</div></main><?php require dirname(__DIR__).'/includes/footer.php'; ?>