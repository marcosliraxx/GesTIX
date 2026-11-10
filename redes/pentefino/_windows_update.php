<?php /* Caminho: redes/pentefino/_windows_update.php */ ?>
/* ---------- Windows Update: visual do Windows 11 e ações pelo gestor autorizado ---------- */
let wuState=null,wuBusy=false,wuTimer=null,wuScreen='home',wuLastScan=null,wuHistory=null,wuSettings=null,wuScanId=0;
const wuCard=(symbol,title,subtitle,action,tail)=>h('div',{class:'wu-row',role:action?'button':null,tabindex:action?'0':null,onclick:action||null,onkeydown:action?e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();action()}}:null},h('div',{class:'wu-symbol'},ico(symbol)),h('div',{class:'wu-description'},h('div',{class:'wu-row-title'},title),subtitle&&h('div',{class:'wu-subtitle'},subtitle)),h('div',{class:'wu-tail'},tail||ico('angle-right')));
const wuBusyNow=()=>wuBusy||['pending','running'].includes(wuState?.command?.status);
async function wuApi(action,body){const url=new URL(action==='windows-update-status'?api.replace(/api\/api\.php(?:\?.*)?$/,'api/support.php'):api,location.href);url.searchParams.set('action',action);const resp=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,gestor_id:gestor,...body})});let data={};try{data=await resp.json()}catch{}if(!resp.ok||!data.ok)throw Error(data.error||'Falha no Windows Update');return data}
async function wuRefresh(){try{wuState=await wuApi('windows-update-status',{});if(['failed','expired'].includes(wuState?.command?.status))window.arcRemoteFailure?.({command_id:wuState.command.id,command:'windows_update',message:wuState.command.message||'Falha no Windows Update'});if(wuState.scan_report){wuLastScan=wuState.scan_report;wuScanId=wuState.scan_id}const r=wuState.report;if(r?.mode==='scan'){wuLastScan=r;wuScanId=wuState.command.id}else if(r?.mode==='history')wuHistory=r;else if(r?.mode==='settings')wuSettings=r;render()}catch(e){status(e.message,true)}finally{clearTimeout(wuTimer);if(section==='windows_update'&&['pending','running'].includes(wuState?.command?.status))wuTimer=setTimeout(wuRefresh,2800)}}
async function wuRun(mode){if(wuBusyNow())return;wuBusy=true;render();try{await wuApi('network-command',{command:'windows_update',update_mode:mode,scan_id:wuScanId,confirmed:mode==='install'});await wuRefresh()}catch(e){status(e.message,true);window.arcRemoteFailure?.({command:'windows_update',message:e.message})}finally{wuBusy=false;render()}}
function wuBack(){wuScreen='home';render()}
function wuDetail(title,subtitle,...content){return h('div',{class:'wu-page'},h('button',{class:'wu-back',onclick:wuBack},ico('arrow-left'),' Windows Update'),h('h2',{class:'wu-heading'},title),subtitle&&h('p',{class:'wu-note'},subtitle),content)}
VIEWS.windows_update=b=>{
 const r=wuLastScan,c=wuState?.command,working=wuBusyNow();
 const btn=(label,mode,cls='wu-btn')=>h('button',{class:cls,disabled:working||!can(),onclick:()=>wuRun(mode)},label);
 const note=(text)=>h('p',{class:'wu-note'},text);
 const row=(i,t,d,fn,tail)=>wuCard(i,t,d,fn,tail);
 if(wuScreen==='history'){
  b.append(wuDetail('Histórico de atualização','Atualizações registradas pelo Windows Update Gestor no computador selecionado.',
   h('div',{class:'wu-detail-actions'},btn('Atualizar histórico','history'),btn('Abrir histórico no Windows','advanced','wu-btn secondary')),
   h('div',{class:'wu-history'},...((wuHistory?.history||[]).map(x=>row(x.result===2?'check-circle':'info-circle',x.title||'Atualização',x.date+' · '+(x.result===2?'Instalada':'Resultado '+x.result),null))))));return;
 }
 if(wuScreen==='advanced'){
  b.append(wuDetail('Opções avançadas','Gerencie a entrega e a instalação no próprio Windows, incluindo atualizações opcionais, horas ativas e otimização de entrega.',
   h('div',{class:'wu-detail-actions'},btn('Abrir opções avançadas no gestor','advanced'),btn('Consultar configurações','settings','wu-btn secondary')),
   row('clock-o','Horas ativas','Evite reinicializações durante o uso. Configuração disponível no Windows.',()=>wuRun('advanced')),
   row('download','Atualizações opcionais','Drivers e componentes adicionais disponibilizados pela Microsoft.',()=>wuRun('advanced')),
   row('exchange','Otimização de entrega','Configure como o Windows recebe atualizações.',()=>wuRun('advanced')),
   row('refresh','Reinicializações','O Windows poderá solicitar reinicialização após uma instalação.',()=>wuRun('advanced'))));return;
 }
 if(wuScreen==='insider'){
  b.append(wuDetail('Programa Windows Insider','Participar do programa exige vínculo e consentimento na conta Microsoft utilizada no Windows.',
   row('users','Programa Windows Insider','Abra as configurações no gestor para visualizar a inscrição e os canais disponíveis.',()=>wuRun('insider')),
   btn('Abrir Windows Insider no gestor','insider')));return;
 }
 const known=r?.mode==='scan',updates=r?.updates||[],latest=wuSettings?.latest_opt_in??r?.latest_opt_in;
 b.append(h('div',{class:'wu-page'},
  h('h2',{class:'wu-heading'},'Windows Update'),
  h('div',{class:'wu-status'},h('div',{class:'wu-status-icon'},ico('refresh'),h('span',{class:'wu-check'},ico('check'))),
   h('div',{class:'wu-status-text'},h('h3',{},known?(updates.length?'Atualizações disponíveis':'Você está atualizado'):'Verifique as atualizações'),
    note(known?'Última verificação concluída no gestor'+(r.windows?' · '+r.windows:''):'Status do Windows obtido diretamente do computador remoto.')),
   btn(working?'Executando...':'Verificar se há atualizações','scan','wu-btn primary')),
  working&&h('div',{class:'wu-running'},h('span',{},ico('spinner'),' ',c?.message||'Executando no Windows...'),h('button',{class:'wu-btn secondary',onclick:async()=>{if(await confirmBox('Cancelar Windows Update',[note('O cancelamento de operações pendentes é imediato. O instalador do Windows poderá concluir uma etapa em andamento antes de parar.')],'Solicitar cancelamento')){try{await wuApi('windows-update-cancel',{command_id:c?.id});await wuRefresh()}catch(e){status(e.message,true)}}}},'Cancelar')),
  r?.reboot_required&&h('div',{class:'wu-alert'},ico('exclamation-triangle'),' Há uma reinicialização pendente; ela não será iniciada automaticamente.'),
  updates.length>0&&h('div',{class:'wu-updates'},h('h3',{},'Atualizações disponíveis ('+fmt(r.total)+')'),...updates.map(u=>row('download',u.title,(u.kb||[]).map(k=>'KB'+k).join(', ')+' · '+size(u.size),null)),btn('Baixar e instalar atualizações','install','wu-btn primary')),
  h('h4',{class:'wu-more'},'Mais opções'),
  row('bullhorn','Obtenha as atualizações mais recentes assim que elas estiverem disponíveis',
   'Esteja entre os primeiros a obter as atualizações, correções e melhorias que não são de segurança mais recentes.',()=>{if(latest==null)wuRun('settings');else wuRun(latest?'latest_off':'latest_on')},h('span',{class:'wu-switch-info'},latest==null?'Consultar':latest?'Ativado':'Desativado',h('span',{class:'wu-switch'+(latest?' active':'')},h('i',{})))),
  row('pause','Pausar atualizações','Escolha uma data no Windows ou pause pelo mecanismo do gestor.',()=>{wuScreen='pause';render()},h('span',{class:'wu-selectdate'},'Escolha uma data ',ico('calendar'))),
  row('history','Histórico de atualização',null,()=>{wuScreen='history';render();wuRun('history')}),
  row('cogs','Opções avançadas','Otimização de entrega, atualizações opcionais, horas ativas, outras configurações de atualização',()=>{wuScreen='advanced';render()}),
  row('users','Programa Windows Insider','Obtenha versões prévias do Windows para compartilhar comentários sobre novas funcionalidades e atualizações',()=>{wuScreen='insider';render()})
 ));
 if(wuScreen==='pause'){
  b.replaceChildren(wuDetail('Pausar atualizações','A duração exata da pausa é gerenciada pelo Windows. A solicitação remota não define uma data arbitrária.',
   row('pause','Pausar atualizações','Pausar pelo mecanismo oficial do Windows Update Gestor.',()=>wuRun('pause')),
   row('play','Retomar atualizações','Retomar o Windows Update quando estiver pausado.',()=>wuRun('resume')),
   btn('Abrir escolha de data no Windows','advanced','wu-btn secondary')));
 }
};
