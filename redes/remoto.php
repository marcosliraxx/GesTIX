<?php
/* Caminho: redes/remoto.php */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
$arcSectionFile='remoto';
require __DIR__.'/_base.php';
require __DIR__.'/_topo.php';
$approvedGestores = [];
foreach (($networkGestores ?? []) as $g) {
    if (($g['status'] ?? '') === 'approved') {
        $approvedGestores[] = $g;
    }
}
$defaultGestorId = 0;
if ($selectedGestorId > 0) {
    foreach ($approvedGestores as $g) {
        if ((int)($g['id'] ?? 0) === (int)$selectedGestorId) {
            $defaultGestorId = (int)$selectedGestorId;
            break;
        }
    }
}
if ($defaultGestorId <= 0 && $approvedGestores) {
    $defaultGestorId = (int)($approvedGestores[0]['id'] ?? 0);
}
// A indicação visual usa a política do servidor; o POST de acesso remoto revalida
// identidade, vínculo, modo sem monitor e permissões antes de criar a sessão.
$canDirectRemote = false;
if ($defaultGestorId > 0 && in_array((string)$teamRole, ['owner', 'administrator'], true)) {
    $remotePolicy = $pdo->prepare('SELECT headless_mode FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved' LIMIT 1");
    $remotePolicy->execute([$defaultGestorId, $networkId, $account]);
    $canDirectRemote = (int)$remotePolicy->fetchColumn() === 1;
}
?>
<style>
.arc-rm{--rm-border:#33506b;--rm-surface:#11283f;--rm-fg:#dcecfb;--rm-accent:#1b87c9;--rm-accent-2:#39a6e9;max-width:100%;margin:8px auto 14px;color:var(--text,var(--rm-fg));font:13px/1.45 Segoe UI,Arial,sans-serif}
.arc-rm *{box-sizing:border-box}.arc-rm button,.arc-rm select,.arc-rm input,.arc-rm textarea{font:inherit}
.arc-rm-shell{position:relative;overflow:hidden;border:1px solid var(--rm-border);border-radius:12px;background:var(--surface,#12283d);box-shadow:0 12px 34px #0002}
.arc-rm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:56px;padding:7px 10px;border-bottom:1px solid var(--rm-border);background:var(--rm-surface);border-radius:11px 11px 0 0;flex-wrap:wrap}
.arc-rm-toolgroup{display:flex;align-items:center;gap:6px;min-width:0;flex-wrap:wrap}.arc-rm-toolbar strong{font-size:13px;font-weight:650;white-space:nowrap}
.arc-rm .arc-rm-icon{display:inline-grid;place-items:center;flex:0 0 auto;width:35px;height:35px;padding:0;border:1px solid transparent;background:transparent;color:#d0e3f7;border-radius:8px;cursor:pointer}
.arc-rm .arc-rm-icon:hover:not(:disabled),.arc-rm .arc-rm-icon[aria-expanded=true],.arc-rm .arc-rm-toolbtn:hover:not(:disabled){background:#245275;border-color:#467397}
.arc-rm .arc-rm-icon:focus-visible,.arc-rm .arc-rm-toolbtn:focus-visible,.arc-rm button:focus-visible,.arc-rm select:focus-visible,.arc-rm input:focus-visible,.arc-rm textarea:focus-visible{outline:2px solid #75caff;outline-offset:2px}
.arc-rm .arc-rm-icon:disabled,.arc-rm .arc-rm-toolbtn:disabled{opacity:.36;cursor:not-allowed}
.arc-rm .arc-rm-eye{background:#165c8b;border-color:#347fae;color:white;font-size:17px}
.arc-rm .arc-rm-danger:not(:disabled){color:#ffc2c2}
.arc-rm-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid #3b647f;border-radius:99px;padding:4px 9px;color:#a5d3f2;font-size:11px;white-space:nowrap}
.arc-rm-badge:before{content:'';height:6px;width:6px;border-radius:50%;background:#8197a8}.arc-rm-badge[data-running=true]:before{background:#31cd94}
.arc-rm-tool-select{display:inline-flex;align-items:center;gap:6px;color:#b5d2e5;font-size:12px}.arc-rm-tool-select select{border:1px solid #41627d;color:#e7f5ff;background:#17364e;padding:7px;border-radius:7px;max-width:115px}
.arc-rm-toolbtn{display:inline-flex;align-items:center;gap:6px;border:1px solid #3f6886;border-radius:7px;background:#17344e;color:#deeeff;padding:7px 10px;cursor:pointer;min-height:35px}
.arc-rm-toolbtn i{font-size:13px}
.arc-rm-canvas{position:relative;display:grid;place-items:center;min-height:420px;height:clamp(420px,68vh,860px);overflow:hidden;background:#081422}
.arc-rm-canvas img{width:100%;height:100%;object-fit:contain;display:block}.arc-rm-canvas img[hidden],.arc-rm [hidden]{display:none!important}
.arc-rm-placeholder{text-align:center;color:#8eabc3;padding:22px;display:grid;gap:16px;justify-items:center}
.arc-rm-placeholder .arc-rm-launch{display:inline-grid;place-items:center;width:74px;height:74px;border-radius:50%;border:1px solid #4e7a98;background:#15344f;color:#dff1ff;font-size:34px;cursor:pointer;box-shadow:0 10px 24px #0004}
.arc-rm-placeholder .arc-rm-launch:hover{background:#1a5077;border-color:#67add9;color:#fff}
.arc-rm-placeholder h2{margin:0;font-size:18px;color:#dceafb}.arc-rm-placeholder p{margin:0;max-width:460px}
.arc-rm-canvas.is-control{outline:2px solid #39c59a;outline-offset:-2px;cursor:crosshair}.arc-rm-canvas.is-control img{user-select:none;-webkit-user-drag:none;cursor:crosshair}
.arc-rm-bottom{display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap;padding:7px 12px;background:var(--rm-surface);border-radius:0 0 11px 11px;border-top:1px solid var(--rm-border)}
.arc-rm-status{color:#afcce0;font-size:12px;min-width:0;overflow-wrap:anywhere}
.arc-rm #arc-rm-text{display:block;width:calc(100% - 22px);margin:9px 11px;background:#163148;color:#e5f6ff;border:1px solid #52708a;border-radius:7px;padding:9px;resize:vertical}
.arc-rm-dialog-backdrop{position:absolute;inset:0;background:#030b14c2;z-index:28}.arc-rm-dialog{position:absolute;z-index:30;top:50%;left:50%;transform:translate(-50%,-50%);width:min(430px,calc(100% - 26px));padding:18px;background:#142d45;color:#ecf6ff;border:1px solid #537b98;border-radius:14px;box-shadow:0 16px 48px #0009}
.arc-rm-dialog h2{font-size:18px;margin:0 0 8px;text-align:center}.arc-rm-dialog p{font-size:13px;color:#b3d0e2;line-height:1.55;margin:0 0 14px;text-align:center}
.arc-rm-dialog label{display:block;font-size:12px;color:#c9e3f3;margin-bottom:8px}.arc-rm-dialog input{width:100%;border:1px solid #52718b;color:white;background:#0f2439;border-radius:9px;padding:12px 14px;margin:4px 0 10px;text-transform:uppercase;letter-spacing:6px;text-align:center;font-size:20px;font-weight:700}
.arc-rm-dialog .arc-rm-actions{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.arc-rm-dialog .arc-rm-actions button{border:1px solid #3982b5;border-radius:8px;background:#17689c;color:white;padding:10px 14px;cursor:pointer;font-size:13px;min-width:160px}
.arc-rm-dialog .arc-rm-actions button.secondary{background:transparent;border-color:#54738c}
.arc-rm-dialog small{display:block;color:#86bbda;text-align:center;line-height:1.45}.arc-rm-dialog .arc-rm-help{margin-top:12px}
.arc-rm-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
@media(max-width:860px){.arc-rm-toolbar{gap:6px}.arc-rm-toolbar strong{display:none}.arc-rm-canvas{height:58vh;min-height:260px}.arc-rm-tool-select select{max-width:92px}.arc-rm-badge{padding:4px 6px}.arc-rm-bottom{gap:6px}.arc-rm-toolbtn span{display:none}.arc-rm-toolbtn{padding:7px 8px}}
</style>
<section class="arc-rm" id="arc-rm" data-network="<?=(int)$networkId?>" data-base="<?=arc_h(arc_workspace_base_url())?>" data-csrf="<?=arc_h($csrf)?>" data-gestor="<?=(int)$defaultGestorId?>" data-unattended="<?=$canDirectRemote?'1':'0'?>">
 <div class="arc-rm-shell">
  <div class="arc-rm-toolbar" role="toolbar" aria-label="Ferramentas do acesso remoto">
   <div class="arc-rm-toolgroup">
    <button type="button" class="arc-rm-icon arc-rm-eye" id="arc-rm-eye" title="Abrir conexão por código" aria-label="Abrir conexão remota"><i class="fa fa-eye" aria-hidden="true"></i></button>
    <strong>Acesso remoto</strong>
    <span class="arc-rm-badge" id="arc-rm-badge" role="status">Sem conexão</span>
   </div>
   <div class="arc-rm-toolgroup">
    <label id="arc-rm-monitors-wrap" class="arc-rm-tool-select" hidden title="Monitor remoto"><i class="fa fa-desktop" aria-hidden="true"></i><select id="arc-rm-monitors" aria-label="Selecionar monitor"></select></label>
    <label class="arc-rm-tool-select" title="Qualidade da transmissão"><i class="fa fa-signal" aria-hidden="true"></i><select id="arc-rm-quality" aria-label="Qualidade de vídeo"><option value="balanced">Padrão</option><option value="low">Econômica</option><option value="high">Alta</option></select></label>
    <button type="button" class="arc-rm-toolbtn" data-rc="win" id="arc-rm-win" disabled title="Abrir menu Iniciar"><i class="fa fa-windows" aria-hidden="true"></i><span>Iniciar</span></button>
    <button type="button" class="arc-rm-toolbtn" data-rc="win_e" id="arc-rm-explorer" disabled title="Abrir Explorador de arquivos"><i class="fa fa-folder-open" aria-hidden="true"></i><span>Explorador</span></button>
    <button type="button" class="arc-rm-toolbtn" data-rc="alt_tab" id="arc-rm-alt-tab" disabled title="Alternar janelas"><i class="fa fa-clone" aria-hidden="true"></i><span>Alt+Tab</span></button>
    <button type="button" class="arc-rm-toolbtn" data-rc="ctrl_shift_esc" id="arc-rm-tasks" disabled title="Abrir Gerenciador de tarefas"><i class="fa fa-tasks" aria-hidden="true"></i><span>Tarefas</span></button>
    <button type="button" class="arc-rm-toolbtn" data-rc="esc" id="arc-rm-esc" disabled title="Enviar tecla Esc"><i class="fa fa-sign-out" aria-hidden="true"></i><span>Esc</span></button>
    <button type="button" class="arc-rm-icon" id="arc-rm-keyboard" disabled title="Digitar texto no computador" aria-label="Teclado remoto" aria-pressed="false"><i class="fa fa-keyboard-o" aria-hidden="true"></i></button>
    <button type="button" class="arc-rm-icon" id="arc-rm-fullscreen" title="Tela cheia" aria-label="Alternar para tela cheia"><i class="fa fa-expand" aria-hidden="true"></i></button>
    <button type="button" class="arc-rm-icon arc-rm-danger" id="arc-rm-stop" disabled title="Encerrar sessão" aria-label="Encerrar sessão remota"><i class="fa fa-times-circle" aria-hidden="true"></i></button>
   </div>
  </div>
  <div class="arc-rm-dialog-backdrop" id="arc-rm-dialog-backdrop" hidden></div>
  <div class="arc-rm-dialog" id="arc-rm-dialog" hidden role="dialog" aria-modal="true" aria-labelledby="arc-rm-dialog-title">
   <h2 id="arc-rm-dialog-title"><i class="fa fa-eye" aria-hidden="true"></i> Acesso remoto por código</h2>
   <p>Ao abrir esta janela, o AGestor exibe o código temporário no Windows. Digite o código abaixo e pressione Enter ou clique em <strong>Acessar remotamente</strong>.</p>
   <label for="arc-rm-code">Código do acesso remoto</label>
   <input type="text" id="arc-rm-code" maxlength="5" placeholder="XXXXX" autocomplete="off" spellcheck="false">
   <div class="arc-rm-actions">
    <button type="button" id="arc-rm-connect">Acessar remotamente</button>
    <button type="button" class="secondary" id="arc-rm-close">Cancelar</button>
   </div>
   <small class="arc-rm-help">O código é temporário, aparece no AGestor Windows e expira automaticamente.</small>
  </div>
  <div class="arc-rm-canvas" id="arc-rm-canvas" tabindex="0" aria-label="Tela remota; controle disponível somente durante uma sessão autorizada">
   <img hidden id="arc-rm-image" alt="Tela do computador remoto">
   <div class="arc-rm-placeholder" id="arc-rm-empty">
    <button type="button" class="arc-rm-launch" id="arc-rm-launch" aria-label="Abrir acesso remoto por código"><i class="fa fa-eye" aria-hidden="true"></i></button>
    <h2><?=$canDirectRemote?'Acesso remoto direto':'Abra o acesso remoto'?></h2>
    <p><?=$canDirectRemote?'Clique no olho para acessar diretamente o computador configurado sem monitor, com autorização administrativa validada pela plataforma.':'Clique no olho para exibir o código no AGestor Windows e digite o código aqui na plataforma para iniciar a sessão.'?></p>
   </div>
  </div>
  <textarea id="arc-rm-text" aria-label="Texto para digitar no computador remoto" placeholder="Texto a enviar durante a sessão autorizada" hidden maxlength="400" rows="2"></textarea>
  <div class="arc-rm-bottom"><div role="status" aria-live="polite" id="arc-rm-state" class="arc-rm-status">Aguardando o código de acesso remoto.</div></div>
 </div>
</section>
<script>
(()=>{'use strict';const root=document.getElementById('arc-rm'),base=root.dataset.base,network=Number(root.dataset.network),csrf=root.dataset.csrf,gestorId=Number(root.dataset.gestor||0),canDirectRemote=root.dataset.unattended==='1',image=document.getElementById('arc-rm-image'),empty=document.getElementById('arc-rm-empty'),label=document.getElementById('arc-rm-state'),badge=document.getElementById('arc-rm-badge'),stop=document.getElementById('arc-rm-stop');let statusPending=false,sessionGeneration=0;let commandId=0,timer=0,framePending=false,controlAllowed=false,monitorsSignature='',inputPending=[],inputSending=false,flushTimeout=0,promptBusy=false;
const eye=document.getElementById('arc-rm-eye'),launchBtn=document.getElementById('arc-rm-launch'),canvas=document.getElementById('arc-rm-canvas'),monWrap=document.getElementById('arc-rm-monitors-wrap'),monSelect=document.getElementById('arc-rm-monitors'),quality=document.getElementById('arc-rm-quality'),keyboardBtn=document.getElementById('arc-rm-keyboard'),textInput=document.getElementById('arc-rm-text'),dialog=document.getElementById('arc-rm-dialog'),backdrop=document.getElementById('arc-rm-dialog-backdrop'),codeInput=document.getElementById('arc-rm-code'),connectBtn=document.getElementById('arc-rm-connect'),closeBtn=document.getElementById('arc-rm-close'),shortcutButtons=[...document.querySelectorAll('.arc-rm-toolbtn[data-rc]')];
function setControl(enabled){controlAllowed=!!enabled;canvas.classList.toggle('is-control',controlAllowed);keyboardBtn.disabled=!controlAllowed;shortcutButtons.forEach(btn=>btn.disabled=!controlAllowed);if(!controlAllowed){inputPending=[];textInput.hidden=true;keyboardBtn.setAttribute('aria-pressed','false')}}
function remotePosition(e){if(!image.naturalWidth||!image.naturalHeight||image.hidden)return null;const r=image.getBoundingClientRect(),ratio=Math.min(r.width/image.naturalWidth,r.height/image.naturalHeight),w=image.naturalWidth*ratio,h=image.naturalHeight*ratio,x=(e.clientX-r.left-(r.width-w)/2)/w,y=(e.clientY-r.top-(r.height-h)/2)/h;return x>=0&&x<=1&&y>=0&&y<=1?{x:Math.max(0,Math.min(1,x)),y:Math.max(0,Math.min(1,y))}:null}
function enqueue(ev){if(!controlAllowed||!commandId)return;if(ev.t==='m'&&inputPending.length&&inputPending[inputPending.length-1].t==='m')inputPending[inputPending.length-1]=ev;else inputPending.push(ev);if(!flushTimeout)flushTimeout=setTimeout(flushInputs,35)}
async function flushInputs(){flushTimeout=0;if(inputSending||!controlAllowed||!commandId){if(!controlAllowed)inputPending=[];return}const events=inputPending.splice(0,100);if(!events.length)return;inputSending=true;try{await request(endpoint('input'),{events});}catch(e){setControl(false);say('Controle desativado: '+e.message)}finally{inputSending=false;if(inputPending.length&&!flushTimeout)flushTimeout=setTimeout(flushInputs,35)}}
function showMonitors(items,selected){items=Array.isArray(items)?items.filter(x=>x&&x.id):[];monWrap.hidden=items.length<=1;const signature=JSON.stringify(items.map(x=>[x.id,x.label]));if(signature!==monitorsSignature){monitorsSignature=signature;monSelect.replaceChildren(...items.map(x=>{let o=document.createElement('option');o.value=String(x.id);o.textContent=String(x.label||'Monitor');return o}))}if(selected&&monSelect.value!==String(selected))monSelect.value=String(selected)}
function setDialog(open){dialog.hidden=!open;backdrop.hidden=!open;if(open){codeInput.focus();codeInput.select()} }
function ensureGestor(){if(gestorId>0)return true;say('Nenhum AGestor aprovado está disponível para exibir o código.');return false}
monSelect.addEventListener('change',async()=>{if(!commandId)return;try{await request(endpoint('select-monitor'),{monitor_id:monSelect.value});say('Monitor alterado.')}catch(e){say(e.message)}});
quality.addEventListener('change',async()=>{if(!commandId)return;try{await request(endpoint('preferences'),{quality:quality.value,cursor_overlay:false})}catch(e){say(e.message)}});
canvas.addEventListener('pointermove',e=>{if(!controlAllowed||e.pointerType==='touch')return;const p=remotePosition(e);if(p)enqueue({t:'m',...p})});
canvas.addEventListener('pointerdown',e=>{if(!controlAllowed)return;const p=remotePosition(e);if(!p)return;e.preventDefault();canvas.focus();const b=e.button===2?2:e.button===1?1:0;enqueue({t:'d',...p,b});if(e.pointerType!=='touch')canvas.setPointerCapture?.(e.pointerId)});
canvas.addEventListener('pointerup',e=>{if(!controlAllowed)return;const p=remotePosition(e);if(p)enqueue({t:'u',...p,b:e.button===2?2:e.button===1?1:0})});
canvas.addEventListener('contextmenu',e=>{if(controlAllowed)e.preventDefault()});
canvas.addEventListener('wheel',e=>{if(!controlAllowed)return;e.preventDefault();enqueue({t:'w',dx:Math.sign(e.deltaX),dy:Math.sign(e.deltaY)})},{passive:false});
canvas.addEventListener('keydown',e=>{if(!controlAllowed||e.target!==canvas||!/^([a-z0-9]{1,20})$/i.test(e.code))return;e.preventDefault();enqueue({t:'k',d:1,c:e.code})});
canvas.addEventListener('keyup',e=>{if(!controlAllowed||e.target!==canvas||!/^([a-z0-9]{1,20})$/i.test(e.code))return;e.preventDefault();enqueue({t:'k',d:0,c:e.code})});
canvas.addEventListener('paste',e=>{if(!controlAllowed)return;const v=e.clipboardData?.getData('text')||'';if(v){e.preventDefault();enqueue({t:'x',s:v.slice(0,400)})}});
keyboardBtn.addEventListener('click',()=>{if(!controlAllowed)return;textInput.hidden=!textInput.hidden;keyboardBtn.setAttribute('aria-pressed',String(!textInput.hidden));if(!textInput.hidden)textInput.focus()});
textInput.addEventListener('input',()=>{if(!controlAllowed)return;const v=textInput.value;if(v){enqueue({t:'x',s:v.slice(0,400)});textInput.value=''}});
shortcutButtons.forEach(btn=>btn.addEventListener('click',()=>{if(controlAllowed)enqueue({t:'c',n:btn.dataset.rc})}));
const say=s=>{label.textContent=s};function endpoint(action){return base+'api/remote-screen.php?action='+action+'&command_id='+commandId}
async function request(url,body){const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',...(body?{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...body,_csrf:csrf})}:{})});const d=await response.json().catch(()=>({error:'Resposta inválida do servidor.'}));if(!response.ok||!d.ok){const error=Error(d.error||'Operação indisponível');error.status=response.status;throw error;}return d}
async function command(name){if(!ensureGestor())throw Error('Nenhum AGestor disponível para conexão.');return request(base+'api/api.php?action=network-command',{network_id:network,gestor_id:gestorId,command:name})}
function clear(){sessionGeneration++;framePending=false;inputPending=[];clearTimeout(flushTimeout);flushTimeout=0;clearInterval(timer);timer=0;setControl(false);showMonitors([],null);commandId=0;image.hidden=true;image.removeAttribute('src');empty.hidden=false;stop.disabled=true;badge.textContent='Sem conexão';badge.dataset.running='false'}
async function tick(){if(!commandId||statusPending)return;statusPending=true;const generation=sessionGeneration,id=commandId;try{const d=await request(endpoint('status'));if(generation!==sessionGeneration||id!==commandId)return;if(d.stopped||['completed','failed','expired','cancelled'].includes(d.status)){say('A sessão foi encerrada: '+(d.state_message||d.status));clear();return;}badge.textContent=d.status==='running'?'Conectado':'Aguardando';badge.dataset.running=String(d.status==='running');setControl(d.status==='running'&&d.control===true);showMonitors(d.monitors||[],d.selected_monitor||'');if(d.has_frame&&d.status==='running'&&!framePending){framePending=true;const next=new Image();next.onload=()=>{if(generation===sessionGeneration&&id===commandId){image.src=next.src;image.hidden=false;empty.hidden=true;}framePending=false;};next.onerror=()=>{framePending=false;};next.src=endpoint('image')+'&t='+Date.now();}say(d.status==='running'?(d.control?'Sessão ativa — controle autorizado no Windows.':'Imagem disponível. Controle depende da autorização no Windows.'):'Aguardando a confirmação de acesso no AGestor Windows…');}catch(e){if(generation!==sessionGeneration)return;setControl(false);say(e.message);if([401,403,404].includes(e.status)){clear();}}finally{statusPending=false;}}
async function resume(){if(commandId||promptBusy||!gestorId)return;try{const found=await request(base+'api/api.php?action=network-remote-existing&network_id='+network+'&gestor_id='+gestorId);if(Number(found.command_id)>0&&!commandId&&!promptBusy)use(found.command_id,'Retomando a sessão remota autorizada…');}catch(e){say('Não foi possível consultar uma sessão anterior. Você pode iniciar uma conexão pelo botão de acesso.');}}
function use(id,msg){setDialog(false);clear();commandId=Number(id);stop.disabled=false;badge.textContent='Conectando';say(msg);timer=setInterval(tick,1500);tick()}
async function promptCode(){if(promptBusy||!ensureGestor())return;promptBusy=true;eye.disabled=true;launchBtn.disabled=true;setDialog(true);say('Solicitando a exibição do código no AGestor Windows...');try{await command('support_code_prompt');say('Digite o código exibido no AGestor para iniciar o acesso remoto.');}catch(e){say(e.message)}finally{promptBusy=false;eye.disabled=false;launchBtn.disabled=false}}
async function connect(){const code=codeInput.value.trim().toUpperCase();if(!/^[A-HJ-NP-Z2-9]{5}$/.test(code)){say('Informe o código de 5 caracteres exibido no AGestor.');codeInput.focus();return}connectBtn.disabled=true;try{const d=await request(base+'api/support.php?action=web-code-connect',{network_id:network,code});codeInput.value='';use(d.command_id,'Código aceito. Iniciando a sessão remota.')}catch(e){say(e.message)}finally{connectBtn.disabled=false}}
async function terminate(){if(!commandId)return;if(!confirm('Encerrar esta sessão remota?'))return;try{await request(endpoint('stop'),{});say('Sessão encerrada.');clear()}catch(e){say(e.message)}}
async function openFlow(){
 if(!ensureGestor())return;
 if(commandId){say('A sessão atual já está aberta.');return;}
 if(!canDirectRemote){await promptCode();return}
 if(promptBusy)return;
 promptBusy=true;eye.disabled=true;launchBtn.disabled=true;
 say('Validando acesso administrativo sem monitor...');
 try{
  const d=await command('remote_assistance');
  if(!d.command_id)throw Error('O servidor não retornou uma sessão válida.');
  use(d.command_id,d.unattended===true?'Acesso autorizado sem código. Aguardando imagem do AGestor...':'Modo sem monitor alterado. Aguardando autorização local no Windows.');
 }catch(e){say(e.message)}
 finally{promptBusy=false;eye.disabled=false;launchBtn.disabled=false}
}
[eye,launchBtn].forEach(btn=>btn.addEventListener('click',openFlow));
connectBtn.addEventListener('click',connect);closeBtn.addEventListener('click',()=>setDialog(false));stop.addEventListener('click',terminate);document.getElementById('arc-rm-fullscreen').addEventListener('click',()=>document.getElementById('arc-rm-canvas').requestFullscreen?.());
codeInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();connect()}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!dialog.hidden){setDialog(false)}});backdrop.addEventListener('click',()=>setDialog(false));window.addEventListener('pagehide',()=>{clearInterval(timer);clearTimeout(flushTimeout);setControl(false)});
window.addEventListener('pageshow',()=>{if(commandId){clearInterval(timer);timer=setInterval(tick,1500);tick();}else resume();});
resume();
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
