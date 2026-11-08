/* Agent-only outer shell. Does not change bridges, page data, cards or dialogs. */
(()=>{
 'use strict';
 const origins=new Set(['https://tidigital.local','https://internet.tidigital.local','https://pentefino.tidigital.local','https://backup.tidigital.local','https://update.tidigital.local']);
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
 window.__tiSetAreas=paintAreas;
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
