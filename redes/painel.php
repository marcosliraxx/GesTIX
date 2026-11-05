<?php
/* Caminho: redes/painel.php */
declare(strict_types=1);
$arcSectionFile = 'painel';
require __DIR__ . '/_base.php';
require __DIR__ . '/_topo.php';
$inventoryUrl = arc_workspace_base_url() . 'pages/painel.php?ajax=network_devices&network=' . (int)$networkId;
?>
<section class="arp-dashboard" id="arp-dashboard" data-url="<?= arc_h($inventoryUrl) ?>" aria-label="Painel de dispositivos da rede">
  <header class="arp-toolbar">
    <div class="arp-identity"><div class="arp-counters" aria-label="Situação dos dispositivos">
      <button type="button" data-filter="all" aria-pressed="true" title="Todos"><i class="fa fa-th" aria-hidden="true"></i> <span id="arp-all">0</span></button>
      <button type="button" data-filter="online" aria-pressed="false" title="Online"><i class="fa fa-circle arp-green" aria-hidden="true"></i> <span id="arp-online">0</span></button>
      <button type="button" data-filter="offline" aria-pressed="false" title="Offline"><i class="fa fa-circle arp-gray" aria-hidden="true"></i> <span id="arp-offline">0</span></button>
    </div></div>
    <div class="arp-actions" role="group" aria-label="Visualização">
      <button type="button" data-view="all" aria-pressed="true" title="Grade"><i class="fa fa-th" aria-hidden="true"></i></button>
      <button type="button" data-view="type" aria-pressed="false" title="Por tipo"><i class="fa fa-th-list" aria-hidden="true"></i></button>
      <button type="button" data-view="group" aria-pressed="false" title="Por grupo"><i class="fa fa-folder-open-o" aria-hidden="true"></i></button>
      <button type="button" id="arp-fit" aria-pressed="false" title="Mostrar todos os dispositivos sem rolagem" aria-label="Mostrar todos sem rolagem"><i class="fa fa-compress" aria-hidden="true"></i></button>
      <button type="button" id="arp-refresh" title="Atualizar inventário"><i class="fa fa-refresh" aria-hidden="true"></i></button>
      <button type="button" data-sort="recent" aria-pressed="true" title="Mais recentes"><i class="fa fa-sort-amount-desc" aria-hidden="true"></i></button>
      <button type="button" data-sort="oldest" aria-pressed="false" title="Mais antigos"><i class="fa fa-sort-amount-asc" aria-hidden="true"></i></button>
    </div>
  </header>
  <div class="arp-grid-scroll" id="arp-scroll"><div id="arp-grid" class="arp-sections"></div><p class="arp-message" id="arp-message" role="status">Carregando dispositivos…</p></div>
  <footer class="arp-footer"><span id="arp-updated">Aguardando inventário</span><span id="arp-state" role="status"></span></footer>
</section>
<dialog id="arp-details" class="arp-details" aria-labelledby="arp-detail-title">
  <header><span class="arp-detail-heading"><span id="arp-detail-icon" class="arp-detail-icon"><i class="fa fa-desktop" aria-hidden="true"></i></span><span class="arp-detail-text"><strong id="arp-detail-title">Dispositivo</strong><small id="arp-detail-subtitle">Detalhes do equipamento</small></span></span><button type="button" id="arp-detail-close" title="Fechar detalhes" aria-label="Fechar detalhes"><i class="fa fa-times"></i></button></header>
  <dl id="arp-detail-rows"></dl>
</dialog>
<style>
/* Caminho: redes/painel.php — estilos locais da grade do inventário */
 .arp-dashboard{height:100%;min-height:0;display:flex;flex-direction:column;background:#102033;color:#e4f1ff;border:1px solid #2d4565;border-radius:10px;overflow:hidden;font-family:inherit}
.arp-toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;gap:6px;padding:4px 8px;min-height:37px;background:#182d44;border-bottom:1px solid #324e6b}
.arp-identity,.arp-actions{min-width:0}.arp-counters,.arp-actions{display:flex;align-items:center;gap:3px;flex-wrap:nowrap}
.arp-counters button,.arp-actions button{display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid #355273;background:#213954;color:#d6e6f5;border-radius:5px;height:26px;min-height:26px;padding:2px 6px;white-space:nowrap;cursor:pointer;font:inherit;font-size:11px}
.arp-actions button{min-width:26px;padding:2px 5px}.arp-toolbar button[aria-pressed=true]{background:#2086ce;border-color:#49b8f2;color:#fff}
.arp-green{color:#22c55e}.arp-gray{color:#91a3b8}
.arp-grid-scroll{flex:1;min-height:0;overflow:auto;padding:9px;scrollbar-width:thin}
.arp-sections{display:flex;flex-direction:column;gap:10px}.arp-section>h3{font-size:11px;margin:0 0 6px;color:#a7cced}
.arp-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:6px;align-content:start}
.arp-tile{position:relative;min-height:72px;border:1px solid #385775;border-radius:8px;background:#182b40;color:#b7cfe7;padding:6px 3px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-width:0;cursor:pointer}
.arp-tile[data-online="1"]{background:#168945;border-color:#48cb7d;color:#fff}.arp-tile[data-stale="1"]{border-style:dashed}.arp-tile i{font-size:20px}
.arp-tile .arp-name{font-size:10px;text-align:center;overflow-wrap:anywhere;line-height:1.15;max-width:100%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.arp-tile:focus-visible,.arp-toolbar button:focus-visible{outline:2px solid #53ceff;outline-offset:2px}
.arp-footer{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:25px;padding:3px 8px;border-top:1px solid #324e6b;background:#182d44;color:#b6cce2;font-size:10px}
.arp-message{font-size:13px;text-align:center;color:#bad0e5}
.arp-details{width:min(355px,calc(100vw - 24px));max-height:min(78dvh,490px);overflow:auto;background:#142940;color:#e4f1ff;border:1px solid #4f7694;border-radius:12px;padding:0;box-shadow:0 18px 60px #0008}.arp-details::backdrop{background:#0009}
.arp-details header{display:flex;align-items:center;justify-content:space-between;padding:8px 11px;border-bottom:1px solid #355273}.arp-details header button{background:transparent;border:0;color:inherit;cursor:pointer;font-size:17px}.arp-details dl{margin:0;padding:4px 11px 9px}.arp-details dl div{display:flex;justify-content:space-between;gap:15px;border-bottom:1px solid #314761;padding:6px 0;font-size:11px}.arp-details dt{color:#95b9d7}.arp-details dd{margin:0;overflow-wrap:anywhere;text-align:right}
.arp-detail-heading{display:flex;align-items:center;gap:9px;min-width:0}.arp-detail-icon{height:35px;width:35px;flex:none;border-radius:9px;background:#204b71;color:#60d4ff;display:grid;place-items:center;font-size:18px}.arp-detail-icon.online{color:#5ff2ac;background:#174a3e}.arp-detail-text{display:flex;flex-direction:column;gap:2px;min-width:0}.arp-detail-text strong{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.arp-detail-text small{font-size:10px;color:#a6c3dc}.arp-details dl div:last-child{border-bottom:0}
/* Modo ver todos: dimensionamento calculado pelo espaço disponível, sem rolagem interna. */
.arp-dashboard.arp-fit .arp-grid-scroll{overflow:hidden;padding:4px}
.arp-dashboard.arp-fit .arp-sections{gap:0;height:100%}
.arp-dashboard.arp-fit .arp-section{min-height:0;flex:1;display:flex;flex-direction:column}
.arp-dashboard.arp-fit .arp-section>h3{margin:0;font-size:10px;line-height:14px;flex-shrink:0}
.arp-dashboard.arp-fit .arp-tiles{grid-template-columns:repeat(var(--arp-cols,8),minmax(0,1fr));grid-auto-rows:minmax(0,1fr);gap:2px;flex:1;min-height:0}
.arp-dashboard.arp-fit .arp-tile{min-height:0;height:100%;border-radius:4px;padding:1px;gap:1px;overflow:hidden}
.arp-dashboard.arp-fit .arp-tile i{font-size:clamp(9px, min(2.2vh, 1.25vw), 19px)}
.arp-dashboard.arp-fit .arp-tile .arp-name{font-size:clamp(7px, min(1.25vh, .8vw), 10px);line-height:1.05;-webkit-line-clamp:1}
.arp-dashboard.arp-fit .arp-tile .arp-rank{font-size:8px}
@media(max-width:600px){.arp-toolbar{gap:3px;padding:3px 4px;overflow-x:auto;scrollbar-width:none}.arp-toolbar::-webkit-scrollbar{display:none}.arp-counters,.arp-actions{gap:2px}.arp-counters button,.arp-actions button{height:25px;min-height:25px;min-width:24px;padding:1px 4px;font-size:10px}.arp-tiles{grid-template-columns:repeat(auto-fill,minmax(65px,1fr));gap:4px}.arp-tile{min-height:65px}.arp-grid-scroll{padding:6px}.arp-footer{font-size:9px;padding:2px 5px}}

</style>
<script>
/* Caminho: redes/painel.php — inventário com dados reais da API existente */
(function(){'use strict';
const root=document.getElementById('arp-dashboard');if(!root)return;
const byId=id=>document.getElementById(id),grid=byId('arp-grid'),message=byId('arp-message'),state=byId('arp-state'),dlg=byId('arp-details');
const icons={computer:'desktop',phone:'mobile',tablet:'tablet',tv:'television',router:'wifi',printer:'print',server:'server',camera:'video-camera',iot:'microchip',console:'gamepad',speaker:'volume-up',switch:'sitemap',ap:'wifi',nas:'hdd-o',device:'laptop'};
const aliases={computador:'computer',desktop:'computer',pc:'computer',celular:'phone',mobile:'phone',smartphone:'phone',roteador:'router',impressora:'printer',servidor:'server',dispositivo:'device'};
let devices=[],filter='all',view='all',sort='recent',fit=false,busy=false,controller=null;
const scroller=byId('arp-scroll'),fitButton=byId('arp-fit');
function layoutFit(){if(!fit)return;const sections=[...grid.querySelectorAll('.arp-section')],width=scroller.clientWidth-8,height=scroller.clientHeight-8;if(!sections.length||width<=0||height<=0)return;const counts=sections.map(s=>s.querySelectorAll('.arp-tile').length);let bestCols=1,bestScore=-1;const total=counts.reduce((a,b)=>a+b,0);for(let cols=1;cols<=Math.max(1,total);cols++){const rows=counts.reduce((a,n)=>a+Math.ceil(n/cols),0);const w=(width-2*(cols-1))/cols,h=(height-2*(rows-1)-sections.length*15*(view==='all'?0:1))/Math.max(1,rows);const score=Math.min(w/1.8,h);if(score>bestScore){bestScore=score;bestCols=cols}if(w<16)break}grid.style.setProperty('--arp-cols',bestCols);sections.forEach(sec=>{const n=sec.querySelectorAll('.arp-tile').length;const rows=Math.ceil(n/bestCols);sec.style.flex=String(Math.max(1,rows))})}

function type(d){const t=String(d.t||'device').toLowerCase().trim();return icons[t]?t:(aliases[t]||'device')}
function row(label,value){if(!value && value!==0)return;const r=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=String(value);r.append(dt,dd);byId('arp-detail-rows').append(r)}
function fmt(t){return t?new Date(Number(t)*1000).toLocaleString('pt-BR'):'Não informado'}
function details(d){byId('arp-detail-title').textContent=d.n||'Dispositivo';const badge=byId('arp-detail-icon');badge.classList.toggle('online',Boolean(d.o));badge.replaceChildren();const icon=document.createElement('i');icon.className='fa fa-'+icons[type(d)];icon.setAttribute('aria-hidden','true');badge.append(icon);byId('arp-detail-subtitle').textContent=d.x?'Sem comunicação':d.o?'Online · Conectado':'Offline · Desconectado';byId('arp-detail-rows').replaceChildren();row('Situação',d.x?'Agente sem comunicação':d.o?'Online':'Offline');row('IP',d.ip);row('MAC',d.m);row('Tipo',type(d));row('Grupo',d.g||'Sem grupo');row('Última mudança',fmt(d.c||d.s));row('Visto por último',fmt(d.l));row('Primeira detecção',fmt(d.f));dlg.showModal()}
function render(){const visible=devices.filter(d=>filter==='all'||(filter==='online'?Boolean(d.o):!d.o));visible.sort((a,b)=>sort==='oldest'?Number(a.c||a.s||0)-Number(b.c||b.s||0):Number(b.c||b.s||0)-Number(a.c||a.s||0));let groups=new Map();visible.forEach(d=>{let k=view==='type'?type(d):view==='group'?(d.g||'Sem grupo'):'Todos';if(!groups.has(k))groups.set(k,[]);groups.get(k).push(d)});grid.replaceChildren();for(const [name,items] of groups){let sec=document.createElement('section');sec.className='arp-section';if(view!=='all'){const h=document.createElement('h3');h.textContent=name+' · '+items.length;sec.append(h)}const tiles=document.createElement('div');tiles.className='arp-tiles';items.forEach((d,i)=>{const b=document.createElement('button');b.type='button';b.className='arp-tile';b.dataset.online=d.o?'1':'0';b.dataset.stale=d.x?'1':'0';b.title=(d.n||'Dispositivo')+' · '+(d.o?'Online':'Offline');const ico=document.createElement('i');ico.className='fa fa-'+icons[type(d)];ico.setAttribute('aria-hidden','true');const label=document.createElement('span');label.className='arp-name';label.textContent=d.n||'Dispositivo';b.append(ico,label);b.addEventListener('click',()=>details(d));tiles.append(b)});sec.append(tiles);grid.append(sec)}message.hidden=visible.length>0;message.textContent=devices.length?'Nenhum dispositivo neste filtro.':'Nenhum dispositivo encontrado na rede.';layoutFit()}
async function load(){if(busy)return;busy=true;state.textContent='Atualizando…';try{if(controller)controller.abort();controller=new AbortController();const r=await fetch(root.dataset.url+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store',signal:controller.signal});const data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Falha ao carregar inventário');devices=Array.isArray(data.devices)?data.devices:[];byId('arp-all').textContent=devices.length;byId('arp-online').textContent=devices.filter(d=>d.o).length;byId('arp-offline').textContent=devices.filter(d=>!d.o).length;byId('arp-updated').textContent='Última atualização: '+fmt(data.updated||data.now);state.textContent='Dados do agente';render()}catch(e){state.textContent='Falha na atualização';message.hidden=false;message.textContent=e.message||'Não foi possível carregar o inventário'}finally{busy=false}}
root.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.filter;root.querySelectorAll('[data-filter]').forEach(x=>x.setAttribute('aria-pressed',x===b?'true':'false'));render()}));root.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>{view=b.dataset.view;root.querySelectorAll('[data-view]').forEach(x=>x.setAttribute('aria-pressed',x===b?'true':'false'));render()}));root.querySelectorAll('[data-sort]').forEach(b=>b.addEventListener('click',()=>{sort=b.dataset.sort;root.querySelectorAll('[data-sort]').forEach(x=>x.setAttribute('aria-pressed',x===b?'true':'false'));render()}));fitButton.addEventListener('click',()=>{fit=!fit;root.classList.toggle('arp-fit',fit);fitButton.setAttribute('aria-pressed',String(fit));fitButton.title=fit?'Voltar à visualização com rolagem':'Mostrar todos sem rolagem';fitButton.setAttribute('aria-label',fitButton.title);fitButton.querySelector('i').className='fa fa-'+(fit?'expand':'compress');grid.querySelectorAll('.arp-section').forEach(sec=>sec.style.flex='');if(fit)layoutFit();else grid.style.removeProperty('--arp-cols')});if(typeof ResizeObserver!=='undefined'){const observer=new ResizeObserver(()=>layoutFit());observer.observe(scroller)}window.addEventListener('resize',layoutFit);byId('arp-refresh').addEventListener('click',load);byId('arp-detail-close').addEventListener('click',()=>dlg.close());dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});load();const timer=setInterval(()=>{if(!document.hidden)load()},5000);window.addEventListener('pagehide',()=>{clearInterval(timer);controller?.abort()});
})();
</script>
<?php require __DIR__ . '/_rodape.php'; ?>
