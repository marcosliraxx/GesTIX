<?php
declare(strict_types=1);
$arcSectionFile = 'dispositivos';
require __DIR__ . '/_base.php';
require dirname(__DIR__) . '/includes/network-inventory.php';
arc_inventory_schema($pdo);
$devicesPersisted = arc_inventory_list($pdo, (int)$account, $networkId, $selectedAgentId);
// Migra o último inventário sem tocar nos registros legados ou nas personalizações.
if (!$devicesPersisted && isset($remoteByType['network_scan'][0])) {
    $legacy = json_decode((string)$remoteByType['network_scan'][0]['payload_json'], true);
    if (is_array($legacy)) {
        arc_inventory_sync($pdo,(int)$account,$networkId,$selectedAgentId,$legacy);
        $devicesPersisted = arc_inventory_list($pdo,(int)$account,$networkId,$selectedAgentId);
    }
}
foreach ($devicesPersisted as &$row) {
    $row['payload'] = json_decode((string)($row['payload_json'] ?? ''),true) ?: [];
    unset($row['payload_json']);
}
unset($row);
require __DIR__.'/_topo.php';
?>
<section class="asc-ops arc-inventory" aria-label="Inventário da rede">
  <style>
  .arc-inventory .arc-inventory-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:6px;width:100%;max-width:none;box-sizing:border-box;padding:7px 9px;min-height:0}
  .arc-inventory-toolbar .arc-inventory-search{flex:1 1 205px;min-width:160px;margin:0}
  .arc-inventory-toolbar .arc-inventory-search input{width:100%;height:32px;font-size:12px}
  .arc-inventory-toolbar select{height:32px;min-width:105px;max-width:170px;padding:3px 7px;font-size:12px;flex:1 1 108px;margin:0}
  .arc-inventory-toolbar .arc-inventory-refresh[hidden]{display:none!important}
  .arc-inventory-toolbar .arc-inventory-refresh{height:32px;width:34px;padding:0;display:grid;place-items:center;flex:0 0 34px}
  .arc-inventory-table th{white-space:nowrap}
  .arc-inventory-table th.arc-sort{cursor:pointer;user-select:none}
  .arc-inventory-table th.arc-sort:hover{background:rgba(100,135,190,.11)}
  .arc-inventory-table th.arc-sort:after{content:' ↕';font-size:10px;opacity:.45}
  .arc-inventory-table th.arc-sort[aria-sort="ascending"]:after{content:' ↑';opacity:1}
  .arc-inventory-table th.arc-sort[aria-sort="descending"]:after{content:' ↓';opacity:1}
  .arc-inventory-table .arc-checkcol{width:36px;text-align:center}
  .arc-inventory-table .arc-numcol{width:44px;text-align:center;font-variant-numeric:tabular-nums}
  .arc-inventory-table .arc-checkcol input{width:15px;height:15px;cursor:pointer}
  .arc-inventory-empty-start{display:flex;min-height:280px;align-items:center;justify-content:center;text-align:center;padding:30px 16px}
  .arc-inventory-startbox{display:grid;justify-items:center;gap:12px;max-width:380px}
  .arc-inventory-startbox .arc-inventory-starticon{font-size:34px;opacity:.65}
  .arc-inventory-startbox strong{font-size:18px}
  .arc-inventory-startbox small{font-size:13px;line-height:1.6;opacity:.75}
  .arc-inventory-startbox button{padding:11px 22px}
  .arc-bulkbar{display:flex;gap:7px;align-items:center;flex-wrap:wrap;padding:7px 9px;border-bottom:1px solid rgba(120,140,165,.2)}
  .arc-bulkbar[hidden],.arc-inventory-empty-start[hidden],.arc-inventory-tablewrap[hidden]{display:none!important}
  .arc-bulkbar select,.arc-bulkbar input{height:30px;min-width:100px;max-width:170px;font-size:12px;padding:3px 7px}
  .arc-bulkbar button{height:30px;padding:3px 10px;font-size:12px}
  .arc-inventory-feedback{font-size:12px;padding:3px 10px}
  @media(max-width:720px){.arc-inventory-toolbar .arc-inventory-search{flex-basis:100%}.arc-inventory-toolbar select{flex:1 1 125px}}
  </style>
  <div class="arc-inventory-toolbar">
    <div class="arc-inventory-search"><i class="fa fa-search" aria-hidden="true"></i><input id="device-search" type="search" placeholder="Buscar nome, IP, MAC, grupo..." aria-label="Pesquisar dispositivos"></div>
    <select id="device-filter" aria-label="Filtrar situação"><option value="all">Todas as situações</option><option value="online">Online</option><option value="offline">Offline</option><option value="pinned">Fixados</option></select>
    <select id="device-type-filter" aria-label="Filtrar tipo"><option value="all">Todos os tipos</option></select>
    <select id="device-group-filter" aria-label="Filtrar grupo"><option value="all">Todos os grupos</option></select>
    <select id="device-source-filter" aria-label="Filtrar origem"><option value="all">Todas as origens</option></select>
    <button type="button" class="asc-btn asc-primary arc-inventory-refresh" data-arc-command="scan_devices" id="device-scan" title="Atualizar varredura" aria-label="Atualizar varredura" hidden><i class="fa fa-refresh" aria-hidden="true"></i></button>
  </div>
  <div class="arc-bulkbar" id="device-bulkbar" hidden>
    <strong id="device-selected-count">0 selecionados</strong>
    <select id="device-bulk-action" aria-label="Ação em massa"><option value="">Escolher ação...</option><option value="pin">Fixar</option><option value="unpin">Desafixar</option><option value="group">Alterar grupo</option><option value="hide">Remover da lista</option></select>
    <input id="device-bulk-group" list="device-groups" placeholder="Novo grupo" maxlength="90" aria-label="Novo grupo" hidden>
    <button type="button" class="asc-btn asc-primary" id="device-bulk-apply">Aplicar aos selecionados</button>
    <button type="button" class="asc-btn" id="device-bulk-clear">Limpar seleção</button>
  </div>
  <div class="arc-inventory-empty-start" id="device-start" hidden><div class="arc-inventory-startbox"><i class="fa fa-sitemap arc-inventory-starticon" aria-hidden="true"></i><strong>Nenhum dispositivo inventariado</strong><small>Inicie uma varredura pelo agente Windows para identificar os dispositivos conectados à rede.</small><button type="button" class="asc-btn asc-primary" data-arc-command="scan_devices" id="device-scan-start"><i class="fa fa-refresh" aria-hidden="true"></i> Examinar rede</button></div></div>
  <div class="arc-inventory-tablewrap" id="device-tablewrap"><table class="arc-inventory-table">
   <thead><tr><th class="arc-checkcol"><input type="checkbox" id="device-select-all" aria-label="Selecionar todos os dispositivos visíveis"></th><th class="arc-numcol arc-sort" data-sort="number" tabindex="0" aria-sort="none" title="Ordenar pela posição">Nº</th><th class="arc-sort" data-sort="name" tabindex="0" aria-sort="none">Dispositivo</th><th class="arc-sort" data-sort="ip" tabindex="0" aria-sort="none">Endereço IP</th><th class="arc-sort" data-sort="mac" tabindex="0" aria-sort="none">MAC</th><th class="arc-sort" data-sort="type" tabindex="0" aria-sort="none">Tipo</th><th class="arc-sort" data-sort="group" tabindex="0" aria-sort="none">Grupo</th><th class="arc-sort" data-sort="status" tabindex="0" aria-sort="none">Situação</th><th class="arc-sort" data-sort="duration" tabindex="0" aria-sort="none">Tempo no estado</th><th class="arc-sort" data-sort="seen" tabindex="0" aria-sort="none">Última atividade</th></tr></thead>
   <tbody id="device-rows"></tbody>
  </table><p id="device-empty" class="asc-ops-empty" hidden>Nenhum dispositivo corresponde aos filtros selecionados.</p></div>
  <div id="device-local-state" class="arc-inventory-feedback" role="status" aria-live="polite"></div>
</section>

<style>
.arc-device-dialog{width:min(880px,96vw)!important;max-width:96vw!important;max-height:88dvh!important;padding:0!important;overflow:hidden!important;border-radius:13px!important}
.arc-device-modal{max-height:88dvh!important;display:flex!important;flex-direction:column!important;min-height:0!important}
.arc-device-modalhead{flex-shrink:0!important;padding:9px 14px!important}
.arc-device-modalbody{flex:1!important;min-height:0!important;overflow-y:auto!important;padding:10px 13px!important}
.arc-device-modalfoot{flex-shrink:0!important;padding:8px 12px!important}
.arc-device-summary{padding:6px 10px!important;margin-bottom:7px!important}
.arc-device-formgrid{gap:7px!important;margin-bottom:8px!important}
.arc-device-formgrid input,.arc-device-formgrid select{min-height:32px!important;padding:5px 8px!important}
.arc-device-facts{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:7px!important}
.arc-device-fact-card{min-width:0;padding:8px 9px!important;border:1px solid #34506d;border-radius:9px;background:#152b42;overflow:hidden}
.arc-device-fact-card small{display:block;color:#9dbbd4;margin-bottom:4px;font-size:11px}.arc-device-fact-card small i{color:#5bc9ef;margin-right:4px}
.arc-device-fact-card strong{display:block;font-size:12px;line-height:1.3;overflow-wrap:anywhere}
.arc-device-name-row{display:flex;align-items:center;gap:6px;min-width:0}.arc-device-name-row strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.arc-device-agent-icon{font-size:12px;flex-shrink:0;color:#5ce3b4;background:#163d35;padding:4px;border-radius:5px}
@media(max-width:700px){.arc-device-facts{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
</style>
<dialog id="device-detail" class="arc-device-dialog" aria-labelledby="device-modal-title">
 <form id="device-form" method="dialog" class="arc-device-modal">
  <div class="arc-device-modalhead"><div><small>ARCOLL / INVENTÁRIO</small><h2 id="device-modal-title">Identificar dispositivo</h2></div><button type="button" class="arc-device-close" id="device-close" aria-label="Fechar">&times;</button></div>
  <div class="arc-device-modalbody">
   <div class="arc-device-summary"><span id="device-symbol" class="arc-device-symbol"><i class="fa fa-desktop"></i></span><div><strong id="device-summary-name">Dispositivo</strong><small id="device-summary-address">—</small></div><span id="device-summary-status" class="arc-device-badge">—</span></div>
   <div class="arc-device-formgrid">
    <label>Nome personalizado<input name="custom_name" maxlength="160" placeholder="Nome do equipamento"></label>
    <label>Tipo do dispositivo<select name="custom_type" id="device-type"><option value="">Identificação automática</option><option value="computer">Computador</option><option value="phone">Celular</option><option value="tablet">Tablet</option><option value="tv">TV</option><option value="router">Roteador</option><option value="printer">Impressora</option><option value="server">Servidor</option><option value="camera">Câmera</option><option value="iot">IoT</option><option value="console">Console</option><option value="speaker">Caixa de som</option><option value="switch">Switch</option><option value="ap">Ponto de acesso</option><option value="nas">NAS</option><option value="device">Outro</option></select></label>
    <label>Grupo / setor<input name="group_name" id="device-group" list="device-groups" maxlength="90" placeholder="Ex.: Sala, Escritório, IoT"></label><datalist id="device-groups"></datalist>
    <label class="arc-device-check"><input type="checkbox" name="pinned"> Fixar no início da lista</label>
   </div>
   <div class="arc-device-facts" id="device-facts"></div>
  </div>
  <div class="arc-device-modalfoot"><button type="button" class="arc-device-danger" id="device-delete"><i class="fa fa-trash-o"></i> Excluir do inventário</button><div><button type="button" class="asc-btn" id="device-cancel">Cancelar</button><button type="submit" class="asc-btn asc-primary" id="device-save">Salvar alterações</button></div></div>
 </form>
</dialog>
<script>
(()=>{'use strict';
 const networkId=<?= (int)$networkId ?>, agentId=<?= (int)$selectedAgentId ?>, base=<?=json_encode(arc_workspace_base_url())?>, csrf=<?=json_encode($csrf)?>;
 let inventory=<?=json_encode($devicesPersisted,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>, current=null, busy=false, activeScan=0, sortBy="name", sortDir=1;
 const selected=new Set();
 const $=id=>document.getElementById(id), table=$('device-rows'), dlg=$('device-detail'), form=$('device-form');
 const types={computer:['Computador','desktop'],phone:['Celular','mobile'],tablet:['Tablet','tablet'],tv:['TV','television'],router:['Roteador','wifi'],printer:['Impressora','print'],server:['Servidor','server'],camera:['Câmera','video-camera'],iot:['IoT','microchip'],console:['Console','gamepad'],speaker:['Som','volume-up'],switch:['Switch','sitemap'],ap:['Access point','wifi'],nas:['NAS','hdd-o'],device:['Dispositivo','laptop']};
 function kind(raw){let v=String(raw||'device').toLowerCase().trim();let aliases={'computador':'computer','desktop':'computer','pc':'computer','celular':'phone','mobile':'phone','smartphone':'phone','telefone':'phone','roteador':'router','printer':'printer','impressora':'printer','smart tv':'tv','televisor':'tv','televisao':'tv','servidor':'server','camera':'camera','câmera':'camera','iot':'iot','access point':'ap','dispositivo':'device'};return types[v]?v:(aliases[v]||'device')}
 const text=(tag,value,className)=>{const el=document.createElement(tag);el.textContent=value==null?'—':String(value);if(className)el.className=className;return el};
 function seconds(n){if(!Number(n))return '—';let s=Math.max(0,Math.floor(n));let d=Math.floor(s/86400),h=Math.floor((s%86400)/3600),m=Math.floor((s%3600)/60);return d?d+'d '+h+'h':h?h+'h '+m+'m':m?m+'m':s+'s'}
 function formatDate(v){return Number(v)?new Date(Number(v)*1000).toLocaleString('pt-BR'):'—'}
 function title(d){return d.custom_name||d.detected_name||d.ip||'Dispositivo'}
 function effectiveKind(d){return kind(d.custom_type||d.detected_type)}
 function notify(msg){const local=$('device-local-state');if(local)local.textContent=msg;const shared=$('device-state');if(shared)shared.textContent=msg}
 function visibleInventory(){return inventory.filter(d=>!Number(d.hidden))}
 function updateChoices(id,items,placeholder){const el=$(id),old=el.value;el.replaceChildren(new Option(placeholder,'all'),...items.map(v=>new Option(v,v)));el.value=items.includes(old)?old:'all'}
 function filterRows(){const q=$('device-search').value.trim().toLocaleLowerCase(),state=$('device-filter').value,type=$('device-type-filter').value,group=$('device-group-filter').value,source=$('device-source-filter').value;
  return visibleInventory().filter(d=>{const search=[title(d),d.ip,d.mac,d.group_name,d.detected_type,d.source].join(' ').toLocaleLowerCase();return search.includes(q)&&(state==='all'||(state==='pinned'&&Number(d.pinned))||state===d.status)&&(type==='all'||effectiveKind(d)===type)&&(group==='all'||(d.group_name||'Sem grupo')===group)&&(source==='all'||(d.source||'Não informada')===source)})}
 function sortValue(d,key){switch(key){case 'number':return Number(d.id)||0;case 'name':return title(d);case 'ip':return String(d.ip||'').split('.').map(x=>x.padStart(3,'0')).join('.');case 'mac':return d.mac||'';case 'type':return types[effectiveKind(d)][0];case 'group':return d.group_name||'Sem grupo';case 'status':return d.status||'';case 'duration':return Number(d.last_change)||0;case 'seen':return Number(d.last_seen)||0;default:return title(d)}}
 function orderedRows(){return filterRows().sort((a,b)=>{const pinOrder=Number(Boolean(Number(b.pinned)))-Number(Boolean(Number(a.pinned)));if(pinOrder)return pinOrder;const va=sortValue(a,sortBy),vb=sortValue(b,sortBy);return (typeof va==='number'&&typeof vb==='number'?va-vb:String(va).localeCompare(String(vb),'pt-BR',{numeric:true,sensitivity:'base'}))*sortDir})}
 function selectionUI(rows){const ids=rows.map(d=>String(d.id)),checked=ids.filter(id=>selected.has(id)).length;const all=$('device-select-all');all.checked=ids.length>0&&checked===ids.length;all.indeterminate=checked>0&&checked<ids.length;$('device-bulkbar').hidden=selected.size===0;$('device-selected-count').textContent=selected.size+' selecionado(s)'}
 function render(){table.replaceChildren();const visible=visibleInventory();const rows=orderedRows();$('device-start').hidden=visible.length>0;$('device-tablewrap').hidden=visible.length===0;$('device-scan').hidden=visible.length===0;$('device-empty').hidden=rows.length>0;
  const groups=[...new Set(visible.map(x=>x.group_name).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'pt-BR'));
  $('device-groups').replaceChildren(...groups.map(g=>new Option(g,g)));
  for(const d of rows){const tr=document.createElement('tr');tr.tabIndex=0;tr.className=Number(d.pinned)?'is-pinned':'';tr.title='Abrir informações e editar dispositivo';
   let td=document.createElement('td');td.className='arc-checkcol';const check=document.createElement('input');check.type='checkbox';check.checked=selected.has(String(d.id));check.setAttribute('aria-label','Selecionar '+title(d));check.addEventListener('click',e=>e.stopPropagation());check.addEventListener('change',()=>{check.checked?selected.add(String(d.id)):selected.delete(String(d.id));selectionUI(rows)});td.append(check);tr.append(td);
   tr.append(text('td',String(rows.indexOf(d)+1),'arc-numcol'));
   td=document.createElement('td');const ident=document.createElement('div');ident.className='arc-device-ident';const icon=text('span','');icon.className='arc-device-icon';const i=document.createElement('i');i.className='fa fa-'+types[effectiveKind(d)][1];icon.append(i);ident.append(icon);const info=document.createElement('div');const nameRow=document.createElement('span');nameRow.className='arc-device-name-row';nameRow.append(text('strong',title(d)));if(d.payload?.agent_installed){const badge=document.createElement('i');badge.className='fa fa-cube arc-device-agent-icon';badge.title='Agente Windows instalado neste dispositivo';badge.setAttribute('aria-label',badge.title);nameRow.append(badge)}info.append(nameRow);info.append(text('small',d.payload?.agent_installed?'Agente Windows instalado':(Number(d.pinned)?'★ Fixado':'Clique para gerenciar')));ident.append(info);td.append(ident);tr.append(td);
   for(const field of ['ip','mac'])tr.append(text('td',d[field]||'—',field==='mac'?'arc-device-mono':''));tr.append(text('td',types[effectiveKind(d)][0]));tr.append(text('td',d.group_name||'Sem grupo'));
   td=document.createElement('td');td.append(text('span',d.status==='online'?'Online':'Offline','arc-device-badge '+(d.status==='online'?'online':'offline')));tr.append(td);
   tr.append(text('td',seconds(Math.floor(Date.now()/1000)-Number(d.last_change||0))));tr.append(text('td',formatDate(d.last_seen)));
   tr.addEventListener('click',e=>{if(e.target.closest('input,button,a'))return;openDevice(d)});tr.addEventListener('keydown',e=>{if(e.target===tr&&e.key==='Enter')openDevice(d)});table.append(tr);
  }
  selectionUI(rows);
  for(const th of document.querySelectorAll('.arc-inventory-table th[data-sort]'))th.setAttribute('aria-sort',th.dataset.sort===sortBy?(sortDir===1?'ascending':'descending'):'none');
 }
 function refreshFilters(){const visible=visibleInventory();updateChoices('device-type-filter',[...new Set(visible.map(effectiveKind))].sort().map(k=>k),'Todos os tipos');const typeSelect=$('device-type-filter');for(const option of [...typeSelect.options].slice(1))option.textContent=types[option.value]?.[0]||option.value;
  updateChoices('device-group-filter',[...new Set(visible.map(d=>d.group_name||'Sem grupo'))].sort(),'Todos os grupos');updateChoices('device-source-filter',[...new Set(visible.map(d=>d.source||'Não informada'))].sort(),'Todas as origens');const alive=new Set(visible.map(d=>String(d.id)));for(const id of selected)if(!alive.has(id))selected.delete(id)}
 const factIcons={'Sistema operacional':'fa-windows','Processador':'fa-microchip','Memória RAM':'fa-tasks','Placa-mãe':'fa-server','IP atual':'fa-globe','Tipo identificado':'fa-desktop','Primeira identificação':'fa-calendar','Última atividade':'fa-clock-o','Origem da descoberta':'fa-sitemap','MAC (identidade permanente)':'fa-barcode','Agente instalado':'fa-cube'};function info(label,val){const cell=document.createElement('div');cell.className='arc-device-fact-card';const caption=text('small','');const i=document.createElement('i');i.className='fa '+(factIcons[label]||(label.startsWith('Armazenamento')?'fa-hdd-o':'fa-info-circle'));caption.append(i,document.createTextNode(' '+label));cell.append(caption,text('strong',val));return cell}
 function openDevice(d){current=d;form.elements.namedItem('custom_name').value=d.custom_name||'';form.elements.namedItem('custom_type').value=d.custom_type||'';form.elements.namedItem('group_name').value=d.group_name||'';form.elements.namedItem('pinned').checked=Boolean(Number(d.pinned));$('device-summary-name').textContent=title(d);$('device-summary-address').textContent=(d.ip||'IP indisponível')+' · '+(d.mac||'MAC não identificado');$('device-summary-status').textContent=d.status==='online'?'Online':'Offline';$('device-summary-status').className='arc-device-badge '+(d.status==='online'?'online':'offline');$('device-symbol').replaceChildren();const i=document.createElement('i');i.className='fa fa-'+types[effectiveKind(d)][1];$('device-symbol').append(i);
  const raw=d.payload||{};const hw=raw.hardware||{};$('device-facts').replaceChildren(info('MAC (identidade permanente)',d.mac||'MAC não disponível — identidade provisória por IP'),info('IP atual',d.ip),info('Tipo identificado',d.detected_type),info('Primeira identificação',formatDate(d.first_seen)),info('Última atividade',formatDate(d.last_seen)),info('Tempo neste estado',seconds(Date.now()/1000-Number(d.last_change||0))),info('Origem da descoberta',d.source||raw.source||'Agente Windows'),info('Agente responsável pela coleta','#'+agentId));
  if(raw.agent_installed){const flag=info('Agente instalado','Este computador executa o agente Windows');flag.classList.add('arc-device-agent-mark');$('device-facts').prepend(flag)}
  if(raw.agent_installed && Object.keys(hw).length){
   const facts=[['Sistema operacional',hw.operating_system?('⊞ '+hw.operating_system):null],['Versão do Windows',hw.os_version],['Arquitetura',hw.os_architecture],['Processador',hw.processor],['Núcleos / threads',(hw.processor_cores||'—')+' / '+(hw.processor_threads||'—')],['Memória RAM',hw.memory_bytes?(Number(hw.memory_bytes)/1073741824).toLocaleString('pt-BR',{maximumFractionDigits:1})+' GB':null],['Placa-mãe',[hw.motherboard_manufacturer,hw.motherboard_model].filter(Boolean).join(' ')]];
   for(const [label,value] of facts){$('device-facts').append(info(label,value||'Não informado pelo Windows'))}
   if(Array.isArray(hw.disks))for(const disk of hw.disks){$('device-facts').append(info('Armazenamento · '+(disk.type||'Disco'),(disk.model||'Modelo desconhecido')+' · '+(disk.size_bytes?(Number(disk.size_bytes)/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' GB':'Capacidade indisponível')))}
  } else {$('device-facts').append(info('Hardware detalhado','Disponível somente para o computador que executa o agente Windows.'))}
  const shown=new Set(['hardware','agent_installed','id','name','mac','ip','type','status','first_seen','last_seen','last_change','source','group','pinned']);
  for(const [key,value] of Object.entries(raw).slice(0,40)){if(shown.has(key)||value==null||typeof value==='object')continue;const label=key.replaceAll('_',' ').replace(/^./,c=>c.toUpperCase());$('device-facts').append(info(label,String(value).slice(0,300)))}
  dlg.showModal();}
 async function api(url,opt){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...opt});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Falha ao consultar a rede');return j}
 async function load(){if(busy||!agentId)return;try{const j=await api(base+'api/api.php?action=network-devices&network_id='+networkId+'&agent_id='+agentId);inventory=j.devices||[];refreshFilters();render()}catch(e){notify('Não foi possível atualizar o inventário: '+e.message)}}
 async function save(hidden){if(!current||busy)return;busy=true;$('device-save').disabled=true;try{const data={id:Number(current.id),network_id:networkId,agent_id:agentId,custom_name:form.elements.namedItem('custom_name').value.trim(),custom_type:form.elements.namedItem('custom_type').value,group_name:form.elements.namedItem('group_name').value.trim(),pinned:form.elements.namedItem('pinned').checked?1:0,hidden:hidden?1:0};await api(base+'api/api.php?action=network-device-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});dlg.close();notify(hidden?'Dispositivo excluído da lista. Ele pode continuar presente na rede.':'Identificação salva e disponível para sincronização com o agente.');}catch(e){notify(e.message)}finally{busy=false;$('device-save').disabled=false;load()}}
 form.addEventListener('submit',e=>{e.preventDefault();save(false)});$('device-delete').addEventListener('click',()=>{if(current && confirm('Excluir este equipamento do inventário exibido? A exclusão é lógica e preserva a identificação por MAC.'))save(true)});$('device-cancel').addEventListener('click',()=>dlg.close());$('device-close').addEventListener('click',()=>dlg.close());dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});$('device-search').addEventListener('input',render);for(const id of ['device-filter','device-type-filter','device-group-filter','device-source-filter'])$(id).addEventListener('change',render);
 $('device-select-all').addEventListener('change',e=>{for(const d of orderedRows())e.target.checked?selected.add(String(d.id)):selected.delete(String(d.id));render()});
 for(const th of document.querySelectorAll('.arc-inventory-table th[data-sort]')){const change=()=>{const key=th.dataset.sort;if(sortBy===key)sortDir*=-1;else{sortBy=key;sortDir=1}render()};th.addEventListener('click',change);th.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();change()}})}
 $('device-bulk-clear').addEventListener('click',()=>{selected.clear();render()});$('device-bulk-action').addEventListener('change',()=>{$('device-bulk-group').hidden=$('device-bulk-action').value!=='group'});
 $('device-bulk-apply').addEventListener('click',async()=>{if(busy||!selected.size)return;const action=$('device-bulk-action').value;if(!action){notify('Selecione uma ação em massa.');return}const group=$('device-bulk-group').value.trim();if(action==='group'&&!group){notify('Informe o novo grupo.');return}const items=inventory.filter(d=>selected.has(String(d.id))&&!Number(d.hidden));if(!items.length)return;if(action==='hide'&&!confirm('Remover '+items.length+' dispositivo(s) do inventário exibido? Esta ação é lógica e não remove equipamentos da rede.'))return;
  busy=true;$('device-bulk-apply').disabled=true;let done=0;let failure='';try{for(const d of items){const data={id:Number(d.id),network_id:networkId,agent_id:agentId,custom_name:d.custom_name||'',custom_type:d.custom_type||'',group_name:action==='group'?group:(d.group_name||''),pinned:action==='pin'?1:action==='unpin'?0:Number(d.pinned)?1:0,hidden:action==='hide'?1:0};try{await api(base+'api/api.php?action=network-device-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});done++;selected.delete(String(d.id))}catch(e){failure=e.message;break}}notify(done+' de '+items.length+' dispositivos atualizados.'+(failure?' Erro: '+failure:''))}finally{busy=false;$('device-bulk-apply').disabled=false;await load()}});

 // O comando real também é executado no desktop; o portal acompanha sua conclusão.
 document.addEventListener('arc:command-queued',e=>{const d=e.detail||{};if(d.command==='scan_devices' && Number(d.agent_id)===agentId){activeScan=Number(d.command_id||0);notify('Varredura iniciada no agente Windows. Aguardando a sincronização do inventário...')}});
 setInterval(async()=>{if(document.hidden||!agentId)return;await load();if(activeScan){try{const j=await api(base+'api/api.php?action=network-command-status&network_id='+networkId);const cmd=(j.commands||[]).find(c=>Number(c.id)===activeScan);if(cmd && ['completed','failed','expired'].includes(cmd.status)){notify(cmd.status==='completed'?'Varredura concluída e inventário sincronizado.':'Varredura não concluída: '+(cmd.result_text||cmd.status));activeScan=0;await load()}}catch(e){}}},7000);
 refreshFilters();render();
})();
</script>
<?php require __DIR__.'/_rodape.php';
