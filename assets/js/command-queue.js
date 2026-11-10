/* Caminho: assets/js/command-queue.js — acompanhamento discreto na barra de estado da rede. */
(()=>{'use strict';
const cfg=window.arcQueueConfig;if(!cfg||window.arcQueueFooterInitialized)return;
window.arcQueueFooterInitialized=true;
const target=document.getElementById('arc-gestor-inventory-status');
if(!target)return;
const baseline=target.textContent.trim();
let loading=false,operations=[],lastSummary='';
const titles={pentefino_scan:'Pente-Fino',pentefino_execute:'Correção do Pente-Fino',program_inventory:'Inventário de programas',program_install:'Instalação de programas',program_uninstall:'Desinstalação de programas',gestor_update:'Atualização do AGestor',windows_update:'Windows Update',speed_test:'Teste de velocidade',scan_devices:'Descoberta de dispositivos',remote_assistance:'Acesso remoto',file_operation:'Arquivos'};
const active=x=>x&&['running','pending'].includes(x.status);
function progress(x){const msg=String(x.message||'');if(msg.startsWith('@@ARP@@'))try{const p=JSON.parse(msg.slice(7));return Math.max(0,Math.min(99,Math.round(100*Number(p.i||0)/Math.max(1,Number(p.t||1))))); }catch{}const m=msg.match(/(\d{1,3})\s*%/);return m?Math.min(99,Number(m[1])):null;}
function render(){const jobs=operations.filter(active).sort((a,b)=>(a.status==='running'?0:1)-(b.status==='running'?0:1)||Number(a.id)-Number(b.id));
const running=jobs.filter(x=>x.status==='running').length;
if(!jobs.length){lastSummary='';target.removeAttribute('data-sync-active');target.removeAttribute('title');target.textContent=target.dataset.arcFooterBase||baseline;return;}
const job=jobs[0],label=titles[job.name]||String(job.name||'Operação').replaceAll('_',' '),pct=progress(job);
lastSummary=(running?'Sincronizando':'Na fila')+' · '+label+(pct!==null?' '+pct+'%':'')+(jobs.length>1?' · '+running+'/'+jobs.length+' em execução':'');
target.dataset.syncActive='1';target.dataset.arcSyncSummary=lastSummary;target.textContent=lastSummary;target.title=jobs.map(x=>(titles[x.name]||x.name)+' — '+(x.status==='running'?'Executando':'Na fila')+(progress(x)!==null?' '+progress(x)+'%':'')).join('\n');}
async function refresh(){if(loading||document.hidden)return;loading=true;try{const response=await fetch(cfg.base+'api/api.php?action=network-queue',{credentials:'same-origin',cache:'no-store'});if(!response.ok)return;const data=await response.json();if(data.ok&&Array.isArray(data.commands)){operations=data.commands;render();}}catch{}finally{loading=false;}}
// Outro módulo pode atualizar os dados básicos do rodapé; a sincronização prevalece só durante a operação.
const observer=new MutationObserver(()=>{if(target.dataset.syncActive==='1'&&lastSummary&&target.textContent!==lastSummary){target.dataset.arcFooterBase=target.textContent;target.textContent=lastSummary;}else if(!lastSummary&&target.textContent!==baseline){target.dataset.arcFooterBase=target.textContent;}});
observer.observe(target,{childList:true,characterData:true});
refresh();setInterval(refresh,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});window.addEventListener('arc-queue-refresh',refresh);
})();
