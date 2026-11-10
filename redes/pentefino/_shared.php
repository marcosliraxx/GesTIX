<?php /* Caminho: redes/pentefino/_shared.php — controles compartilhados, sem regras de ferramentas. */ ?>
/* ---------- utilidades das telas extras ---------- */
let perfTab='active',drvTab='out',cloudCat='junk',cloudSel=new Set(),perfSel=new Set(),drvSel=new Set(),drvClosed=new Set();
const tabsBar=(items,cur,set)=>h('div',{class:'cc-tabs'},items.map(([k,n,i])=>h('button',{type:'button',class:'cc-tab'+(k===cur?' on':''),onclick:()=>{set(k);render()}},ico(i),n)));
const avatar=n=>h('span',{class:'cc-uicon'},(n||'?').trim().charAt(0).toLocaleUpperCase('pt-BR'));
const dots=n=>h('span',{class:'cc-dots'},[1,2,3].map(i=>h('i',{class:i<=n?'on':''})),' ',['','Baixo','Médio','Alto'][n]||'Baixo');
const emptyBox=(g,t,s,btn)=>h('div',{class:'cc-center'},ill(g),h('h2',{},t),s&&h('p',{class:'mut'},s),btn||null);
const headBar=(t,right)=>h('div',{class:'cc-head'},h('h2',{},t,' ',h('span',{class:'mut',title:'Informações'},ico('info-circle'))),h('div',{class:'r'},right||null));
const dateBr=s=>{if(!s)return '—';const d=new Date(s.length===10?s+'T12:00:00':s);return isNaN(d)?s:d.toLocaleDateString('pt-BR',{day:'numeric',month:'short',year:'numeric'})};
async function saveSet(patch){try{const s=Object.assign({exclusions:[],custom_folders:[],perf_excluded:[],drivers_ignored:[]},settings,patch);const r=await call('settings-save',{exclusions:s.exclusions||[],custom_folders:s.custom_folders||[],perf_excluded:s.perf_excluded||[],drivers_ignored:s.drivers_ignored||[]});settings=r.settings;render()}catch(e){status(e.message,true)}}
const toggleIn=(field,id)=>{const list=new Set(settings[field]||[]);list.has(id)?list.delete(id):list.add(id);return saveSet({[field]:[...list]})};
const lastScan=k=>stamps[k]?'Última verificação: '+stamps[k]:'';

function scopeView(b,title,heading,lead,controls,go,label){b.append(h('div',{class:'cc-head'},h('h2',{},title)),h('div',{style:'padding:2px 18px'},h('h2',{class:'cc-title-big'},heading),h('p',{class:'mut'},lead)),h('div',{class:'cc-scope'},controls()),h('div',{style:'flex:1'}),h('div',{class:'cc-foot'},h('button',{class:'cc-pill',onclick:()=>{dupStage='intro';diskStage='intro';render()}},'Voltar'),h('button',{class:'cc-pill blue',disabled:!can(),onclick:go},label)))}
