<?php
/* Caminho: redes/dispositivos.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

$arcSectionFile = 'dispositivos';
require __DIR__ . '/_base.php';
require dirname(__DIR__) . '/includes/network-inventory.php';
arc_inventory_schema($pdo);
$devicesPersisted = arc_inventory_list($pdo, (int)$account, $networkId, $selectedGestorId);
// Migra o último inventário sem tocar nos registros legados ou nas personalizações.
if (!$devicesPersisted && isset($remoteByType['network_scan'][0])) {
    $legacy = json_decode((string)$remoteByType['network_scan'][0]['payload_json'], true);
    if (is_array($legacy)) {
        arc_inventory_sync($pdo,(int)$account,$networkId,$selectedGestorId,$legacy);
        $devicesPersisted = arc_inventory_list($pdo,(int)$account,$networkId,$selectedGestorId);
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

  <div class="arc-inventory-toolbar">
    <div class="arc-inventory-search"><i class="fa fa-search" aria-hidden="true"></i><input id="device-search" type="search" placeholder="Buscar dispositivo" aria-label="Pesquisar dispositivos"></div>
    <button type="button" class="asc-btn arc-inventory-filter-button" data-open-dialog="device-filters" aria-label="Filtrar dispositivos" title="Filtros"><i class="fa fa-sliders" aria-hidden="true"></i></button>
    <button type="button" class="asc-btn arc-inventory-filter-button" id="device-export" aria-label="Baixar lista de dispositivos" title="Baixar lista"><i class="fa fa-download" aria-hidden="true"></i></button>
    <button type="button" class="asc-btn asc-primary arc-inventory-refresh" data-arc-command="scan_devices" id="device-scan" title="Atualizar varredura" aria-label="Atualizar varredura" hidden><i class="fa fa-refresh" aria-hidden="true"></i></button>
  </div>
  <div class="arc-bulkbar" id="device-bulkbar" hidden>
    <strong id="device-selected-count">0 selecionados</strong>
    <select id="device-bulk-action" aria-label="Ação em massa"><option value="">Escolher ação...</option><option value="pin">Fixar</option><option value="unpin">Desafixar</option><option value="group">Alterar grupo</option><option value="hide">Remover da lista</option></select>
    <input id="device-bulk-group" list="device-groups" placeholder="Novo grupo" maxlength="90" aria-label="Novo grupo" hidden>
    <button type="button" class="asc-btn asc-primary" id="device-bulk-apply">Aplicar aos selecionados</button>
    <button type="button" class="asc-btn" id="device-bulk-clear">Limpar seleção</button>
  </div>
  <div class="arc-inventory-empty-start" id="device-start" hidden><div class="arc-inventory-startbox"><i class="fa fa-sitemap arc-inventory-starticon" aria-hidden="true"></i><strong>Nenhum dispositivo inventariado</strong><small>Inicie uma varredura pelo gestor Windows para identificar os dispositivos conectados à rede.</small><button type="button" class="asc-btn asc-primary" data-arc-command="scan_devices" id="device-scan-start"><i class="fa fa-refresh" aria-hidden="true"></i> Examinar rede</button></div></div>
  <div class="arc-inventory-tablewrap" id="device-tablewrap"><table class="arc-inventory-table">
   <thead><tr><th class="arc-checkcol"><input type="checkbox" id="device-select-all" aria-label="Selecionar todos os dispositivos visíveis"></th><th class="arc-numcol arc-sort" data-sort="number" tabindex="0" aria-sort="none" title="Ordenar pela posição">Nº</th><th class="arc-sort" data-sort="name" tabindex="0" aria-sort="none">Dispositivo</th><th class="arc-sort" data-sort="ip" tabindex="0" aria-sort="none">Endereço IP</th><th class="arc-sort" data-sort="mac" tabindex="0" aria-sort="none">MAC</th><th class="arc-sort" data-sort="type" tabindex="0" aria-sort="none">Tipo</th><th class="arc-sort" data-sort="group" tabindex="0" aria-sort="none">Grupo</th><th class="arc-sort" data-sort="status" tabindex="0" aria-sort="none">Situação</th><th class="arc-sort" data-sort="duration" tabindex="0" aria-sort="none">Há quanto tempo</th><th class="arc-sort" data-sort="seen" tabindex="0" aria-sort="none">Última atividade</th></tr></thead>
   <tbody id="device-rows"></tbody>
  </table><p id="device-empty" class="asc-ops-empty" hidden>Nenhum dispositivo corresponde aos filtros selecionados.</p></div>
  <div id="device-local-state" class="arc-inventory-feedback" role="status" aria-live="polite"></div>
</section>

<dialog id="device-filters" class="arc-service-dialog" aria-labelledby="device-filters-title"><header class="arc-service-heading"><h2 id="device-filters-title">Filtros</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar filtros"><i class="fa fa-times" aria-hidden="true"></i></button></header><div class="arc-service-form arc-inventory-filters">
<label>Situação<select id="device-filter" aria-label="Filtrar situação"><option value="all">Todas as situações</option><option value="online">Conectados</option><option value="offline">Sem resposta</option><option value="pinned">Fixados</option></select></label><label>Tipo<select id="device-type-filter" aria-label="Filtrar tipo"><option value="all">Todos os tipos</option></select></label><label>Grupo<select id="device-group-filter" aria-label="Filtrar grupo"><option value="all">Todos os grupos</option></select></label><label>Descoberta<select id="device-source-filter" aria-label="Filtrar origem"><option value="all">Todas as origens</option></select></label><label>Organizar<select id="device-sort"><option value="name:1">Nome: A a Z</option><option value="name:-1">Nome: Z a A</option><option value="ip:1">Endereço da rede</option><option value="status:1">Situação</option><option value="group:1">Grupo</option><option value="seen:-1">Mais recentes</option></select></label></div><div class="arc-service-actions"><button type="button" class="arc-icon-action" id="device-select-filtered" title="Selecionar lista filtrada" aria-label="Selecionar todos desta lista"><i class="fa fa-check-square-o" aria-hidden="true"></i></button><button type="button" class="arc-icon-action" data-close-dialog aria-label="Aplicar filtros" title="Aplicar filtros"><i class="fa fa-check" aria-hidden="true"></i></button></div></dialog>

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
  <div class="arc-device-modalfoot"><button type="button" class="arc-device-danger" id="device-delete" aria-label="Remover da lista" title="Remover da lista"><i class="fa fa-trash-o" aria-hidden="true"></i></button><div><button type="button" class="asc-btn" id="device-cancel" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i></button><button type="submit" class="asc-btn asc-primary" id="device-save" aria-label="Salvar alterações" title="Salvar alterações"><i class="fa fa-check" aria-hidden="true"></i></button></div></div>
 </form>
</dialog>
<script>
(()=>{'use strict';
 const networkId=<?= (int)$networkId ?>, gestorId=<?= (int)$selectedGestorId ?>, base=<?=json_encode(arc_workspace_base_url())?>, csrf=<?=json_encode($csrf)?>;
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
   td=document.createElement('td');const ident=document.createElement('div');ident.className='arc-device-ident';const icon=text('span','');icon.className='arc-device-icon';const i=document.createElement('i');i.className='fa fa-'+types[effectiveKind(d)][1];icon.append(i);ident.append(icon);const info=document.createElement('div');const nameRow=document.createElement('span');nameRow.className='arc-device-name-row';nameRow.append(text('strong',title(d)));if(d.payload?.gestor_installed){const badge=document.createElement('i');badge.className='fa fa-cube arc-device-gestor-icon';badge.title='Gestor Windows instalado neste dispositivo';badge.setAttribute('aria-label',badge.title);nameRow.append(badge)}info.append(nameRow);info.append(text('small',d.payload?.gestor_installed?'Gestor Windows instalado':(Number(d.pinned)?'★ Fixado':(d.group_name||types[effectiveKind(d)][0]))));ident.append(info);td.append(ident);tr.append(td);
   for(const field of ['ip','mac'])tr.append(text('td',d[field]||'—',field==='mac'?'arc-device-mono':''));tr.append(text('td',types[effectiveKind(d)][0]));tr.append(text('td',d.group_name||'Sem grupo'));
   td=document.createElement('td');td.append(text('span',d.status==='online'?'Conectado':'Sem resposta','arc-device-badge '+(d.status==='online'?'online':'offline')));tr.append(td);
   tr.append(text('td',seconds(Math.floor(Date.now()/1000)-Number(d.last_change||0))));tr.append(text('td',formatDate(d.last_seen)));
   tr.addEventListener('click',e=>{if(e.target.closest('input,button,a'))return;openDevice(d)});tr.addEventListener('keydown',e=>{if(e.target===tr&&['Enter',' '].includes(e.key)){e.preventDefault();openDevice(d)}});[...tr.children].forEach((cell,index)=>cell.dataset.deviceField=['select','number','name','ip','mac','type','group','status','duration','seen'][index]);table.append(tr);
  }
  selectionUI(rows);
  for(const th of document.querySelectorAll('.arc-inventory-table th[data-sort]'))th.setAttribute('aria-sort',th.dataset.sort===sortBy?(sortDir===1?'ascending':'descending'):'none');
 }
 function refreshFilters(){const visible=visibleInventory();updateChoices('device-type-filter',[...new Set(visible.map(effectiveKind))].sort().map(k=>k),'Todos os tipos');const typeSelect=$('device-type-filter');for(const option of [...$('device-source-filter').options].slice(1))option.textContent='Detectado pelo gestor';for(const option of [...typeSelect.options].slice(1))option.textContent=types[option.value]?.[0]||option.value;
  updateChoices('device-group-filter',[...new Set(visible.map(d=>d.group_name||'Sem grupo'))].sort(),'Todos os grupos');updateChoices('device-source-filter',[...new Set(visible.map(d=>d.source||'Não informada'))].sort(),'Todas as origens');const alive=new Set(visible.map(d=>String(d.id)));for(const id of selected)if(!alive.has(id))selected.delete(id)}
 const factIcons={'Sistema operacional':'fa-windows','Processador':'fa-microchip','Memória RAM':'fa-tasks','Placa-mãe':'fa-server','IP atual':'fa-globe','Tipo identificado':'fa-desktop','Primeira identificação':'fa-calendar','Última atividade':'fa-clock-o','Origem da descoberta':'fa-sitemap','MAC (identidade permanente)':'fa-barcode','Gestor instalado':'fa-cube'};function info(label,val){const cell=document.createElement('div');cell.className='arc-device-fact-card';const caption=text('small','');const i=document.createElement('i');i.className='fa '+(factIcons[label]||(label.startsWith('Armazenamento')?'fa-hdd-o':'fa-info-circle'));caption.append(i,document.createTextNode(' '+label));cell.append(caption,text('strong',val));return cell}
 function openDevice(d){current=d;form.elements.namedItem('custom_name').value=d.custom_name||'';form.elements.namedItem('custom_type').value=d.custom_type||'';form.elements.namedItem('group_name').value=d.group_name||'';form.elements.namedItem('pinned').checked=Boolean(Number(d.pinned));$('device-summary-name').textContent=title(d);$('device-summary-address').textContent=(d.ip||'IP indisponível')+' · '+(d.mac||'MAC não identificado');$('device-summary-status').textContent=d.status==='online'?'Conectado':'Sem resposta';$('device-summary-status').className='arc-device-badge '+(d.status==='online'?'online':'offline');$('device-symbol').replaceChildren();const i=document.createElement('i');i.className='fa fa-'+types[effectiveKind(d)][1];$('device-symbol').append(i);
  const raw=d.payload||{};const hw=raw.hardware||{};$('device-facts').replaceChildren(info('MAC (identidade permanente)',d.mac||'MAC não disponível — identidade provisória por IP'),info('IP atual',d.ip),info('Tipo identificado',types[kind(d.detected_type)][0]),info('Primeira identificação',formatDate(d.first_seen)),info('Última atividade',formatDate(d.last_seen)),info('Tempo neste estado',seconds(Date.now()/1000-Number(d.last_change||0))),info('Descoberta','Inventário da rede'),info('Gestor responsável pela coleta','#'+gestorId));
  if(raw.gestor_installed){const flag=info('Gestor instalado','Este computador executa o gestor Windows');flag.classList.add('arc-device-gestor-mark');$('device-facts').prepend(flag)}
  if(raw.gestor_installed && Object.keys(hw).length){
   const facts=[['Sistema operacional',hw.operating_system?('⊞ '+hw.operating_system):null],['Versão do Windows',hw.os_version],['Arquitetura',hw.os_architecture],['Processador',hw.processor],['Núcleos / threads',(hw.processor_cores||'—')+' / '+(hw.processor_threads||'—')],['Memória RAM',hw.memory_bytes?(Number(hw.memory_bytes)/1073741824).toLocaleString('pt-BR',{maximumFractionDigits:1})+' GB':null],['Placa-mãe',[hw.motherboard_manufacturer,hw.motherboard_model].filter(Boolean).join(' ')]];
   for(const [label,value] of facts){$('device-facts').append(info(label,value||'Não informado pelo Windows'))}
   if(Array.isArray(hw.disks))for(const disk of hw.disks){$('device-facts').append(info('Armazenamento · '+(disk.type||'Disco'),(disk.model||'Modelo desconhecido')+' · '+(disk.size_bytes?(Number(disk.size_bytes)/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1})+' GB':'Capacidade indisponível')))}
  } else {$('device-facts').append(info('Hardware detalhado','Disponível somente para o computador que executa o gestor Windows.'))}
  dlg.showModal();}
 $('device-export').addEventListener('click',()=>{const rows=orderedRows();if(!rows.length){notify('Nenhum dispositivo para baixar.');return;}const safe=value=>'"'+String(value??'').replace(/^[\s]*[=+@-]/,v=>"'"+v).replaceAll('"','""')+'"';const csv=[['Nome','IP','MAC','Tipo','Grupo','Situação'],...rows.map(d=>[title(d),d.ip,d.mac,types[effectiveKind(d)][0],d.group_name,d.status==='online'?'Conectado':'Sem resposta'])].map(row=>row.map(safe).join(';')).join('\r\n');const url=URL.createObjectURL(new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download='dispositivos.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)});
 async function api(url,opt){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...opt});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Falha ao consultar a rede');return j}
 async function load(){if(busy||!gestorId)return;try{const j=await api(base+'api/api.php?action=network-devices&network_id='+networkId+'&gestor_id='+gestorId);inventory=j.devices||[];refreshFilters();render()}catch(e){notify('Não foi possível atualizar o inventário: '+e.message)}}
 async function save(hidden){if(!current||busy)return;busy=true;$('device-save').disabled=true;try{const data={id:Number(current.id),network_id:networkId,gestor_id:gestorId,custom_name:form.elements.namedItem('custom_name').value.trim(),custom_type:form.elements.namedItem('custom_type').value,group_name:form.elements.namedItem('group_name').value.trim(),pinned:form.elements.namedItem('pinned').checked?1:0,hidden:hidden?1:0};await api(base+'api/api.php?action=network-device-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});dlg.close();notify(hidden?'Dispositivo excluído da lista. Ele pode continuar presente na rede.':'Identificação salva e disponível para sincronização com o gestor.');}catch(e){notify(e.message)}finally{busy=false;$('device-save').disabled=false;load()}}
 form.addEventListener('submit',e=>{e.preventDefault();save(false)});$('device-delete').addEventListener('click',()=>{if(current && confirm('Excluir este equipamento do inventário exibido? A exclusão é lógica e preserva a identificação por MAC.'))save(true)});$('device-cancel').addEventListener('click',()=>dlg.close());$('device-close').addEventListener('click',()=>dlg.close());dlg.addEventListener('click',e=>{if(e.target===dlg)dlg.close()});$('device-search').addEventListener('input',render);for(const id of ['device-filter','device-type-filter','device-group-filter','device-source-filter'])$(id).addEventListener('change',render);
 $('device-sort').addEventListener('change',()=>{const [key,direction]=$('device-sort').value.split(':');sortBy=key;sortDir=Number(direction);render()});$('device-select-filtered').addEventListener('click',()=>{for(const d of orderedRows())selected.add(String(d.id));render();$('device-filters').close()});
 $('device-select-all').addEventListener('change',e=>{for(const d of orderedRows())e.target.checked?selected.add(String(d.id)):selected.delete(String(d.id));render()});
 for(const th of document.querySelectorAll('.arc-inventory-table th[data-sort]')){const change=()=>{const key=th.dataset.sort;if(sortBy===key)sortDir*=-1;else{sortBy=key;sortDir=1}render()};th.addEventListener('click',change);th.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();change()}})}
 $('device-bulk-clear').addEventListener('click',()=>{selected.clear();render()});$('device-bulk-action').addEventListener('change',()=>{$('device-bulk-group').hidden=$('device-bulk-action').value!=='group'});
 $('device-bulk-apply').addEventListener('click',async()=>{if(busy||!selected.size)return;const action=$('device-bulk-action').value;if(!action){notify('Selecione uma ação em massa.');return}const group=$('device-bulk-group').value.trim();if(action==='group'&&!group){notify('Informe o novo grupo.');return}const items=inventory.filter(d=>selected.has(String(d.id))&&!Number(d.hidden));if(!items.length)return;if(action==='hide'&&!confirm('Remover '+items.length+' dispositivo(s) do inventário exibido? Esta ação é lógica e não remove equipamentos da rede.'))return;
  busy=true;$('device-bulk-apply').disabled=true;let done=0;let failure='';try{for(const d of items){const data={id:Number(d.id),network_id:networkId,gestor_id:gestorId,custom_name:d.custom_name||'',custom_type:d.custom_type||'',group_name:action==='group'?group:(d.group_name||''),pinned:action==='pin'?1:action==='unpin'?0:Number(d.pinned)?1:0,hidden:action==='hide'?1:0};try{await api(base+'api/api.php?action=network-device-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});done++;selected.delete(String(d.id))}catch(e){failure=e.message;break}}notify(done+' de '+items.length+' dispositivos atualizados.'+(failure?' Erro: '+failure:''))}finally{busy=false;$('device-bulk-apply').disabled=false;await load()}});

 // O comando real também é executado no desktop; o portal acompanha sua conclusão.
 document.addEventListener('arc:command-queued',e=>{const d=e.detail||{};if(d.command==='scan_devices' && Number(d.gestor_id)===gestorId){activeScan=Number(d.command_id||0);notify('Varredura iniciada no gestor Windows. Aguardando a sincronização do inventário...')}});
 // Só acompanha comandos iniciados pelo operador; nenhum polling de inventário em segundo plano.
 const scanTimer=setInterval(async()=>{if(document.hidden||!gestorId||!activeScan)return;try{const j=await api(base+'api/api.php?action=network-command-status&network_id='+networkId);const cmd=(j.commands||[]).find(c=>Number(c.id)===activeScan);if(cmd && ['completed','failed','expired'].includes(cmd.status)){notify(cmd.status==='completed'?'Varredura concluída e inventário sincronizado.':'Varredura não concluída: '+(cmd.result_text||cmd.status));activeScan=0;await load()}}catch(e){}},7000);
 window.addEventListener('pagehide',()=>clearInterval(scanTimer));
 refreshFilters();render();
})();
</script>
<?php require __DIR__.'/_rodape.php';
