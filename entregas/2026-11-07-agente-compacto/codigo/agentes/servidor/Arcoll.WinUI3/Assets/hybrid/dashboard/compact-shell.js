/* Agent-only outer shell. Does not change bridges, page data, cards or dialogs. */
(()=>{
 'use strict';
 const origins=new Set(['https://tidigital.local','https://internet.tidigital.local','https://pentefino.tidigital.local','https://backup.tidigital.local']);
 if(!origins.has(location.origin)||window.__tiCompactShell)return;
 window.__tiCompactShell=true;
 const set=(node,key,value)=>{if(node&&node.style.getPropertyValue(key)!==value)node.style.setProperty(key,value,'important');};
 const apply=()=>{
  const body=document.body;if(!body)return false;
  for(const node of [document.documentElement,body]){set(node,'margin','0px');set(node,'padding','0px');set(node,'width','100%');set(node,'box-sizing','border-box');}
  const root=body.querySelector(':scope > main,:scope > .np-panel,:scope > #backup-center');if(!root)return false;
  for(const [key,value]of Object.entries({'margin':'0px','max-width':'none','width':'100%','border-radius':'0px','border-width':'0px','box-sizing':'border-box'}))set(root,key,value);
  if(root.matches('.np-panel,#backup-center')){set(document.documentElement,'height','100%');set(body,'height','100%');set(root,'height','100%');set(root,'max-height','100%');set(root,'min-height','0px');}
  // Pente-Fino's padding is the only outer page frame there; internal cards keep their own spacing.
  if(location.origin==='https://pentefino.tidigital.local')set(root,'padding','0px');
  return true;
 };
 if(!apply()){const observer=new MutationObserver(()=>{if(apply())observer.disconnect();});observer.observe(document.documentElement||document,{childList:true,subtree:true});}
 document.addEventListener('DOMContentLoaded',apply,{once:true});
})();
