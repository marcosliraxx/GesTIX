/* Agent-only outer shell. Does not change bridges, page data, cards or dialogs. */
(()=>{
 'use strict';
 const origins=new Set(['https://tidigital.local','https://internet.tidigital.local','https://pentefino.tidigital.local','https://backup.tidigital.local','https://update.tidigital.local','https://server.tidigital.local']);
 if(!origins.has(location.origin))return;if(window.__tiCompactShell){window.__tiSetAreas?.(window.__tiAreaPalette);return;}
 window.__tiCompactShell=true;
 const set=(node,key,value)=>{if(node&&node.style.getPropertyValue(key)!==value)node.style.setProperty(key,value,'important');};
 const paintAreas=(c)=>{
  if(!c||!document.body)return;window.__tiAreaPalette=c;
  const color=(name,fallback)=>/^#[a-f0-9]{6}$/i.test(c[name]||'')?c[name]:fallback;
  const surface=color('area5','#112b44'),alt=color('area4','#0c2238'),ink=color('text','#e7f7ff'),muted=color('muted','#adc5d7'),line=color('line','#2b455f'),accent=color('accent','#2563eb');
  const root=document.body.querySelector(':scope > main,:scope > #backup-center');
  for(const node of [document.body,root])if(node){for(const [key,val]of Object.entries({'--surface':surface,'--alt':alt,'--text':ink,'--muted':muted,'--accent':accent,'--line':line,'--np-surface':surface,'--np-alt':alt,'--np-text':ink,'--np-muted':muted,'--np-line':line,'--np-accent':accent,'--bc-bg':surface,'--bc-surface':surface,'--bc-alt':alt,'--bc-text':ink,'--bc-muted':muted,'--bc-line':line,'--bc-accent':accent}))set(node,key,val);}
  set(document.body,'background-color',surface);
  for(const [selector,key]of [['.np-panel','area5'],['.np-toolbar','area4'],['.np-footer','area9'],['.np-activity','area7'],['.np-activity > header','area6'],['.np-history-note','area8']])document.querySelectorAll(selector).forEach(el=>set(el,'background',color(key,surface)));
 };
 window.__tiSetAreas=c=>{paintAreas(c);window.__tiPaintWorkspace?.();};
 const apply=()=>{
  const body=document.body;if(!body)return false;
  for(const node of [document.documentElement,body]){set(node,'margin','0px');set(node,'padding','0px');set(node,'width','100%');set(node,'box-sizing','border-box');}
  const root=body.querySelector(':scope > main,:scope > .np-panel,:scope > #backup-center');if(!root)return false;
  for(const [key,value]of Object.entries({'margin':'0px','max-width':'none','width':'100%','border-radius':'0px','border-width':'0px','box-sizing':'border-box'}))set(root,key,value);
  if(root.matches('.np-panel,#backup-center')){set(document.documentElement,'height','100%');set(body,'height','100%');set(root,'height','100%');set(root,'max-height','100%');set(root,'min-height','0px');}
  if(location.origin==='https://tidigital.local'&&root.matches('.np-panel')){
   const hideHeading=()=>{const heading=root.querySelector(':scope > .np-heading');if(heading){set(heading,'display','none');heading.setAttribute('aria-hidden','true');}paintAreas(window.__tiAreaPalette);};
   hideHeading();
   if(!root.__tiCompactHeadingObserver){root.__tiCompactHeadingObserver=new MutationObserver(hideHeading);root.__tiCompactHeadingObserver.observe(root,{childList:true});}
  }
  // Pente-Fino's padding is the only outer page frame there; internal cards keep their own spacing.
  if(location.origin==='https://pentefino.tidigital.local')set(root,'padding','0px');
  paintAreas(window.__tiAreaPalette);return true;
 };
 if(!apply()){const observer=new MutationObserver(()=>{if(apply())observer.disconnect();});observer.observe(document.documentElement||document,{childList:true,subtree:true});}
 document.addEventListener('DOMContentLoaded',apply,{once:true});
})();

/* Common agent-only workspace. Existing controls/listeners stay inside their original root. */
(()=>{'use strict';const hosts=['tidigital.local','server.tidigital.local','internet.tidigital.local','pentefino.tidigital.local','backup.tidigital.local','update.tidigital.local'];if(!hosts.includes(location.hostname)||window.__tiWorkspace)return;window.__tiWorkspace=true;const logs=new Map();let observer,queued=false,lastFooter='',activeRoot=null;const clean=v=>String(v||'').replace(/\s+/g,' ').trim().slice(0,1000);const label=()=>({'tidigital.local':'Painel','server.tidigital.local':'Servidor','internet.tidigital.local':'Internet','pentefino.tidigital.local':'Pente-Fino','backup.tidigital.local':'Backup','update.tidigital.local':'Atualização'})[location.hostname];
function paint(){const c=window.__tiAreaPalette;if(!c)return;for(const [key,value]of Object.entries({'--ti-toolbar':c.area4,'--ti-side':c.area6,'--surface':c.area5,'--alt':c.area4,'--text':c.text,'--muted':c.muted,'--line':c.line,'--accent':c.accent}))if(/^#[a-f0-9]{6}$/i.test(value||''))document.body.style.setProperty(key,value);document.documentElement.style.colorScheme=c.light?'light':'dark';}
function entries(root){const key=location.hostname+'|'+(root.id||'page');if(!logs.has(key))logs.set(key,[]);return logs.get(key);}
function log(root,text,at=new Date().toISOString()){text=clean(text);if(!text)return;const rows=entries(root);if(rows[0]?.text===text)return;rows.unshift({text,at});rows.splice(100);draw(root);}
function draw(root){const list=root.querySelector('.ti-events');if(!list)return;list.replaceChildren(...entries(root).map(e=>{const li=document.createElement('li'),t=document.createElement('time');li.append(document.createTextNode(e.text));t.dateTime=e.at;t.textContent=new Date(e.at).toLocaleString('pt-BR');li.append(t);return li;}));}
function toggle(root,toolbar,body,aside){const key='ti.activities.'+location.hostname+'.'+(root.id||'page');let open=false;try{open=localStorage.getItem(key)==='1';}catch{}let button=toolbar.querySelector('.ti-toggle');if(!button){button=document.createElement('button');button.type='button';button.className='ti-toggle';button.title='Mostrar ou recolher Atividades';button.setAttribute('aria-label','Atividades');button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 6v7m0 3v2"/></svg>';toolbar.append(button);button.addEventListener('click',()=>{const next=button.getAttribute('aria-pressed')!=='true';try{localStorage.setItem(key,next?'1':'0');}catch{}apply(next);});}const apply=value=>{button.setAttribute('aria-pressed',String(value));body.classList.toggle('ti-open',value);aside.hidden=!value;if(!value&&aside.contains(document.activeElement))button.focus();};aside.id=aside.id||'ti-activities-'+(root.id||'page');button.setAttribute('aria-controls',aside.id);apply(open);}
function generic(root){if(root.querySelector(':scope > .ti-body'))return;let toolbar=root.querySelector(':scope > .perf-toolbar,:scope > .history-toolbar');if(!toolbar){toolbar=document.createElement('div');root.prepend(toolbar);}toolbar.classList.add('ti-toolbar');const body=document.createElement('div');body.className='ti-body';const content=document.createElement('div');content.className='ti-content';const aside=document.createElement('aside');aside.className='ti-activities';aside.innerHTML='<header><h2>Atividades</h2><p>Eventos desta página</p></header><ol class="ti-events"></ol><p class="ti-side-note">Eventos desta sessão. Os históricos permanentes continuam nas áreas próprias.</p>';
 const keep=[];for(const child of [...root.children])if(child!==toolbar){if(child.tagName==='DIALOG')keep.push(child);else content.append(child);}body.append(content,aside);root.append(body,...keep);root.classList.add('ti-workspace');
 if(location.hostname==='internet.tidigital.local'){const row=document.createElement('div');row.className='ti-internet';row.append(...content.children);content.append(row);const start=root.querySelector('#start');if(start)toolbar.append(start);root.querySelector('.diagnosis>header')?.setAttribute('hidden','');}
 if(location.hostname==='pentefino.tidigital.local'){root.querySelector(':scope .top')?.setAttribute('hidden','');for(const node of root.querySelectorAll('.modes,#preferences'))toolbar.append(node);}
 if(location.hostname==='backup.tidigital.local'){const actions=root.querySelector('.bc-header .bc-actions');if(actions)toolbar.append(actions);root.querySelector('.bc-header')?.setAttribute('hidden','');root.querySelector('.bc-tabs')?.setAttribute('hidden','');}
 toggle(root,toolbar,body,aside);if(!entries(root).length)log(root,label()+' · página aberta');else draw(root);
}
function panel(root){root.classList.add('ti-workspace');const toolbar=root.querySelector('.np-toolbar'),body=root.querySelector('.np-body'),aside=root.querySelector('.np-activity');if(!toolbar||!body||!aside)return;toolbar.classList.add('ti-toolbar');const h=aside.querySelector('h2');if(h&&h.textContent!=='Atividades')h.textContent='Atividades';toggle(root,toolbar,body,aside);}
function footer(root){const selectors={ 'tidigital.local':'.np-footer','server.tidigital.local':'.perf-footer','internet.tidigital.local':'.diagnosis>footer,.map-footer','pentefino.tidigital.local':'.disclaimer,#report-date','backup.tidigital.local':'.bc-footer','update.tidigital.local':'#elapsed,#history-count'};const footers=[...root.querySelectorAll(selectors[location.hostname])];for(const el of footers)if(el.matches('.np-footer,.perf-footer,.diagnosis>footer,.map-footer,.disclaimer,.bc-footer'))el.classList.add('ti-page-footer');const text=clean(footers.map(e=>e.textContent).filter(Boolean).join(' · '))||label();if(text!==lastFooter){lastFooter=text;window.chrome?.webview?.postMessage({type:'workspace-footer',action:'workspace-footer',text});}}
function apply(){queued=false;observer?.disconnect();try{if(!document.body)return;document.documentElement.classList.add('ti-agent');if(!document.getElementById('ti-workspace-css')){const link=document.createElement('link');link.id='ti-workspace-css';link.rel='stylesheet';link.href='standard-workspace.css';document.head.append(link);}paint();const roots=location.hostname==='update.tidigital.local'?[...document.querySelectorAll('body>main')]:[document.querySelector('body>main,body>#backup-center')].filter(Boolean);for(const root of roots){if(root.matches('.np-panel'))panel(root);else generic(root);}activeRoot=roots.find(r=>!r.hidden)||roots[0];if(activeRoot){footer(activeRoot);const status=activeRoot.querySelector('#caption,#notice:not([hidden]),.bc-notice,#stage-message,#perf-warning:not([hidden])');if(status&&!activeRoot.matches('.np-panel'))log(activeRoot,status.textContent);}}finally{observer?.observe(document.documentElement,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['hidden']});}}
function schedule(){if(!queued){queued=true;requestAnimationFrame(apply);}}
window.addEventListener('ti-activity',e=>{if(activeRoot){observer?.disconnect();log(activeRoot,e.detail?.text,e.detail?.at);schedule();}});document.addEventListener('DOMContentLoaded',schedule,{once:true});observer=new MutationObserver(schedule);if(document.documentElement)observer.observe(document.documentElement,{childList:true,subtree:true});schedule();window.__tiPaintWorkspace=paint;
})();
