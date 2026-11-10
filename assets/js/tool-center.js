/* Caminho: assets/js/tool-center.js — central única de atividades e preferências por ferramenta. */
(()=>{
'use strict';
const root=document.querySelector('[data-arc-tool-center]');
if(!root||root.dataset.arcInitialized==='1')return;
root.dataset.arcInitialized='1';
const tool=root.dataset.arcToolCenter, endpoint=root.dataset.endpoint, csrf=root.dataset.csrf;
const title=root.dataset.title||tool;
const trigger=document.createElement('button');
trigger.type='button';trigger.className='arc-tool-center-trigger';trigger.title='Atividades e configurações';
trigger.setAttribute('aria-label','Atividades e configurações de '+title);
trigger.setAttribute('aria-expanded','false');trigger.textContent='⚙';
const panel=document.createElement('aside');panel.className='arc-tool-center';panel.hidden=true;
panel.setAttribute('aria-label','Central de '+title);
panel.innerHTML='<header><div class="arc-tool-heading"><strong></strong><small>Atividades e preferências da ferramenta</small></div><button type="button" data-close aria-label="Fechar">×</button></header><nav><button type="button" data-tab="activities" aria-selected="true">Atividades</button><button type="button" data-tab="settings" aria-selected="false">Configurações</button></nav><section data-view="activities"><ol data-activity-list></ol></section><section data-view="settings" hidden><label>Sincronização<select data-interval></select></label><label><input type="checkbox" data-notify checked> Notificar mudanças</label><label><input type="checkbox" data-background> Atualizar em segundo plano</label><p data-status aria-live="polite"></p></section>';
panel.querySelector('strong').textContent=title;
root.append(trigger,panel);
// Barra padrão única: mantém controles nativos e conecta a lateral existente.
const docking={
 programas:['#arc-install-panel','.pi-controls','.pi-workspace'],
 velocidade:['.arc-speed-shell','.arc-speed-toolbar','.arc-speed-content'],
 servidor:['#performance','.perf-toolbar','.perf-layout']
};
const toolbars={
 dispositivos:'.arc-inventory-toolbar',mapa:'.arc-topology-toolbar',arquivos:'.arc-files-actions',
 remoto:'.arc-rm-toolbar',agenda:'.arc-ag-toolbar',chamados:'.arc-filter-bar',
 relatorios:'.arc-filter-bar',historico:'.arc-up-history-toolbar',
 usuarios:'.au-toolbar',liberacao:'.arc-release-toolbar',
 pentefino:'.cc-manual-toolbar',backup:'.arc-backup-toolbar',
 suporte:'.arc-support-toolbar',cadastros:'.arc-filter-bar',
 permissoes:'.arc-filter-bar',preferencias:'.arc-filter-bar',
 atualizacoes:'.arc-up-actions'
};
let dockHost=null,dockBar=null,dockWorkspace=null;
const d=docking[tool];
if(d){
 dockHost=document.querySelector(d[0]);dockBar=dockHost?.querySelector(d[1]);dockWorkspace=dockHost?.querySelector(d[2]);
}
if(!dockBar){
 dockBar=document.querySelector(toolbars[tool]||'__arc_no_toolbar__');
 if(!dockBar) dockBar=[...document.querySelectorAll('.arc-redes-conteudo [role=toolbar],.arc-redes-conteudo [class*=toolbar],.arc-redes-conteudo [class*=filter-bar]')].find(el=>el.getClientRects().length&&el.querySelector('button,input,select'));
 dockHost=dockBar?.closest('section,main,article')||document.querySelector('.arc-redes-conteudo');
 dockWorkspace=dockBar?.parentElement;
}
if(!dockBar){
 // Ferramentas sem barra anterior ganham a mesma barra compacta sem alterar seus formulários.
 dockHost=document.querySelector('.arc-redes-conteudo');
 if(dockHost){dockBar=document.createElement('div');dockBar.className='arc-standard-tool-bar arc-generated-tool-bar';dockBar.setAttribute('role','toolbar');dockBar.setAttribute('aria-label','Controles de '+title);const heading=document.createElement('strong');heading.className='arc-standard-tool-title';heading.textContent=title;dockBar.append(heading);dockHost.prepend(dockBar);dockWorkspace=dockHost;}
}
if(dockBar){
 dockBar.classList.add('arc-standard-tool-bar');
 root.classList.add('arc-tool-center-inline');root.classList.remove('arc-tool-center-floating');
 dockBar.append(root);
 if(dockWorkspace&&dockWorkspace!==dockBar){dockWorkspace.append(panel);panel.classList.add('arc-tool-center-docked');}
 // A central é a última ação, inclusive após mudanças dinâmicas da ferramenta.
 root.style.order='999';
}
const select=panel.querySelector('[data-interval]'),notify=panel.querySelector('[data-notify]'),background=panel.querySelector('[data-background]'),status=panel.querySelector('[data-status]');
const formatMode=mode=>mode==='auto'?'Automático (verificação periódica)':mode==='continuous'?'Contínuo (limitado)':mode+' segundos';
let mode='15',allowed=['auto','15'],timer=null,nextSyncAt=0,inflight=false,entries=[];
function addActivity(message){entries.unshift({message,time:new Date()});entries=entries.slice(0,40);const list=panel.querySelector('[data-activity-list]');list.replaceChildren();for(const e of entries){const li=document.createElement('li');li.textContent=e.time.toLocaleTimeString('pt-BR')+' · '+e.message;list.append(li);}}
addActivity('Ferramenta aberta');
function duration(){if(mode==='continuous')return 3000;if(mode==='auto')return 15000;return Math.max(1000,Math.min(120000,Number(mode)*1000||15000));}
function announce(){window.dispatchEvent(new CustomEvent('arc-tool-sync-settings',{detail:{tool,interval:mode,milliseconds:duration(),nextSyncAt}}));}
function schedule(){clearTimeout(timer);nextSyncAt=Date.now()+duration();announce();timer=setTimeout(()=>{if(!document.hidden||background.checked)refresh();schedule();},duration());}
function refresh(){if(inflight||document.hidden&&!background.checked)return;if(document.querySelector('dialog[open]'))return;
 const marked=document.querySelector('[data-tool-refresh]');
 if(marked&&!marked.disabled){marked.click();addActivity('Atualização solicitada');}
 else window.dispatchEvent(new CustomEvent('arc-tool-refresh',{detail:{tool}}));
}
function apply(result){
 if(Array.isArray(result.allowed)&&result.allowed.length)allowed=result.allowed.filter(x=>typeof x==='string'&&/^(auto|continuous|\d{1,3})$/.test(x));
 const requested=allowed.includes(result.interval)?result.interval:(allowed.includes(result.default)?result.default:allowed[0]||'auto');
 mode=requested;
 select.replaceChildren();for(const value of allowed){const option=new Option(formatMode(value),value);select.add(option);}
 select.value=mode;notify.checked=result.notify!==false;background.checked=!!result.background;
 schedule();
}
async function save(){if(!allowed.includes(select.value))return;const data=new URLSearchParams({tool,interval:select.value,notify:notify.checked?'1':'0',background:background.checked?'1':'0'});status.textContent='Salvando…';
 try{const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/x-www-form-urlencoded'},body:data,cache:'no-store'});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Não foi possível salvar');apply(result);status.textContent='Preferências salvas';addActivity('Preferências atualizadas');}
 catch(error){status.textContent=error.message||'Falha ao salvar';}
}
for(const element of [select,notify,background])element.addEventListener('change',save);
function toggle(open){panel.hidden=!open;trigger.setAttribute('aria-expanded',String(open));if(dockHost)dockHost.classList.toggle('arc-tool-center-open',open);if(dockWorkspace&&dockWorkspace!==dockHost)dockWorkspace.classList.toggle('arc-tool-center-open',open);}
trigger.addEventListener('click',()=>toggle(panel.hidden));panel.querySelector('[data-close]').addEventListener('click',()=>toggle(false));
panel.querySelector('nav').addEventListener('click',event=>{const btn=event.target.closest('[data-tab]');if(!btn)return;for(const b of panel.querySelectorAll('[data-tab]'))b.setAttribute('aria-selected',String(b===btn));for(const view of panel.querySelectorAll('[data-view]'))view.hidden=view.dataset.view!==btn.dataset.tab;});
window.addEventListener('arc-tool-activity',event=>{if(typeof event.detail?.message==='string')addActivity(event.detail.message.slice(0,250));});
window.addEventListener('arc-tool-preference-change',event=>{if(event.detail?.tool!==tool)return;const value=String(event.detail.interval||'');if(allowed.includes(value)&&select.value!==value){select.value=value;save();}});
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&Date.now()>=nextSyncAt){refresh();schedule();}});
window.addEventListener('pagehide',()=>clearTimeout(timer),{once:true});
fetch(endpoint+'?tool='+encodeURIComponent(tool),{credentials:'same-origin',cache:'no-store'}).then(async response=>{const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Preferências indisponíveis');apply(result);}).catch(error=>{status.textContent=error.message||'Preferências indisponíveis';apply({interval:'15',default:'15',allowed:['auto','15','30','60']});});
})();
