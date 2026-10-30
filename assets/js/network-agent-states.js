(function(){
 'use strict';
 const labels={online:'Todos os agentes online',partial:'Atenção: alguns agentes offline',offline:'Alerta: todos os agentes offline',none:'Nenhum agente instalado'};
 const source=new URL('api/api.php?action=network-agent-states',document.baseURI);
 let inFlight=false, states={};
 function update(){
   document.querySelectorAll('[data-panel-network], [data-network-status-id]').forEach(el=>{
     const id=el.getAttribute('data-panel-network')||el.getAttribute('data-network-status-id');
     const s=states[id];if(!s)return;
     el.dataset.agentState=s.state;el.dataset.online=s.state==='online'?'1':'0';
     const description=labels[s.state]+' ('+s.online+'/'+s.total+' online)';
     el.title=(el.getAttribute('data-name')||'Rede')+' — '+description;
     if(el.hasAttribute('data-panel-network')){el.setAttribute('aria-label',el.title);const label=el.querySelector('.arc-panel-network-state');if(label)label.textContent=description;}
   });
   const selected=document.getElementById('arc-network-global-state');
   if(selected){const id=selected.dataset.networkStatusId, s=states[id];if(s){selected.dataset.agentState=s.state;selected.title=labels[s.state]+' — '+s.online+' de '+s.total+' agentes online';selected.setAttribute('aria-label',selected.title);selected.querySelector('.arc-net-status-text').textContent=s.online+'/'+s.total;}}
 }
 async function refresh(){
   if(document.hidden||inFlight)return;
   inFlight=true;
   try{const r=await fetch(source.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!r.ok||r.redirected||!(r.headers.get('content-type')||'').includes('application/json'))return;
     const d=await r.json();if(!d.ok||!d.networks)return;states=d.networks;update();
   }catch(_){/* Falha de conexão: não mostrar estado falso. */}
   finally{inFlight=false;}
 }
 window.arcNetworkAgentStates={refresh:refresh,get:function(id){return states[String(id)]||null;}};
 const alertButton=document.getElementById('arc-network-global-overview');
 const alertOverlay=document.getElementById('arc-network-alert-dialog');
 const alertList=document.getElementById('arc-network-alert-list');
 const alertSummary=document.getElementById('arc-network-alert-summary');
 let overview=null,lastFocus=null;
 const overviewURL=new URL('pages/painel.php?ajax=offline_overview',document.baseURI);
 function elapsed(ts,now){
   if(!ts)return 'Sem comunicação registrada';
   const seconds=Math.max(0,now-ts);
   if(seconds<60)return 'Última comunicação há '+seconds+' s';
   const minutes=Math.floor(seconds/60);if(minutes<60)return 'Última comunicação há '+minutes+' min';
   const hours=Math.floor(minutes/60);if(hours<24)return 'Última comunicação há '+hours+' h e '+(minutes%60)+' min';
   const days=Math.floor(hours/24);return 'Última comunicação há '+days+' dia(s) e '+(hours%24)+' h';
 }
 function renderOverview(){
   if(!alertButton||!overview)return;
   const {total,offline,now}=overview;
   const fraction=total?offline.length/total:0;
   const health=total===0?'none':fraction>0.30?'critical':offline.length?'warning':'healthy';
   alertButton.dataset.health=health;
   alertButton.dataset.agentState=health==='healthy'?'online':health==='warning'?'partial':health==='critical'?'offline':'none';
   alertButton.title=total===0?'Nenhuma rede cadastrada':(offline.length+' de '+total+' redes offline');
   alertButton.setAttribute('aria-label',alertButton.title+'. Clique para detalhes.');
   if(!alertOverlay||alertOverlay.hidden)return;
   alertSummary.textContent=total===0?'Nenhuma rede cadastrada.':offline.length+' rede(s) offline de '+total+' cadastrada(s).';
   alertList.replaceChildren();
   if(!offline.length){const p=document.createElement('p');p.textContent='Nenhuma rede offline no momento.';alertList.appendChild(p);return;}
   offline.forEach(n=>{
     const item=document.createElement('div');item.className='arc-network-alert-item';
     const name=document.createElement('strong');name.textContent=n.name;
     const detail=document.createElement('small');detail.textContent=n.inactive?'Rede arquivada/inativa':n.no_agent?'Sem agente aprovado — '+elapsed(n.last_seen,now):elapsed(n.last_seen,now);
     item.append(name,detail);alertList.appendChild(item);
   });
 }
 async function refreshOverview(){
   if(document.hidden)return;
   try{const r=await fetch(overviewURL.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!r.ok)throw Error('http '+r.status);const json=await r.json();if(!json.ok)throw Error('api');overview=json;renderOverview();
   }catch(_){if(alertButton){alertButton.dataset.health='unknown';alertButton.title='Não foi possível consultar as redes';}if(alertOverlay&&!alertOverlay.hidden)alertSummary.textContent='Não foi possível atualizar o estado das redes.';}
 }
 function closeAlert(){if(!alertOverlay)return;alertOverlay.hidden=true;lastFocus?.focus();}
 if(alertButton&&alertOverlay){
   alertButton.addEventListener('click',()=>{lastFocus=document.activeElement;alertOverlay.hidden=false;alertOverlay.querySelector('[data-network-alert-close]').focus();renderOverview();refreshOverview();});
   alertOverlay.querySelector('[data-network-alert-close]').addEventListener('click',closeAlert);
   alertOverlay.addEventListener('click',e=>{if(e.target===alertOverlay)closeAlert();});
   document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!alertOverlay.hidden)closeAlert();});
   refreshOverview();setInterval(refreshOverview,12000);
 }
 refresh();setInterval(refresh,2000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
})();
