<?php
/* Caminho: redes/_menu.php — navegação por áreas alinhada ao gestor WinUI 3.
 * Origem das rotas, nomes, ícones e permissões: menu administrativo já existente.
 * Os grupos apenas organizam itens autorizados, sem criar permissões ou rotas.
 */
require_once __DIR__ . '/../includes/error-logging.php';
require_once __DIR__ . '/../includes/menu-favorites.php';
require_once __DIR__.'/../includes/pentefino-modules.php';
$arcNetworkLinks = arc_navigation_items($pdo, 'header', (int)$networkId);
$arcAllowedMenu = in_array(($teamRole ?? ''), ['owner', 'system_admin'], true)
    ? null : arc_network_profile_rights($pdo, (int)$networkId, (int)arc_user_id());
// Catálogo único espelhando o gestor Windows (MainWindow.xaml.cs): categorias, itens, ícones
// (Segoe MDL2 -> Font Awesome) e faixas. Rotas e permissões continuam vindo do menu administrativo.
$arcWorkspaceGroups = [
    'inicio' => ['label'=>'Início','icon'=>'fa-home'],
    'pentefino' => ['label'=>'Pente-Fino','icon'=>'fa-filter'],
    'manutencao' => ['label'=>'Manutenção','icon'=>'fa-wrench'],
    'aplicativos' => ['label'=>'Aplicativos','icon'=>'fa-book'],
    'backup' => ['label'=>'Backup','icon'=>'fa-floppy-o'],
    'rede' => ['label'=>'Rede','icon'=>'fa-globe'],
    'helpdesk' => ['label'=>'Helpdesk','icon'=>'fa-life-ring'],
    'gestao' => ['label'=>'Gestão','icon'=>'fa-cogs'],
];
// chave do gestor => [grupo, rótulo, ícone, faixa, seção/permissão, query extra]
$arcCatalog = [
    'panel'=>['inicio','Painel','fa-sitemap',0,'painel',''],
    'updates'=>['helpdesk','Atualizações','fa-refresh',0,'atualizacoes',''],
    'history'=>['helpdesk','Histórico','fa-history',1,'historico',''],
    'server'=>['manutencao','Servidor','fa-cog',0,'servidor',''],
    
    'programs'=>['aplicativos','Programas','fa-book',0,'programas',''],
    'backup'=>['backup','Visão geral','fa-home',0,'backup','tab=overview'],
    'backup_cloud'=>['backup','Nuvem','fa-globe',0,'backup','tab=cloud'],
    'backup_local'=>['backup','Local / NAS','fa-folder',0,'backup','tab=local'],
    'backup_restore'=>['backup','Restauração','fa-undo',1,'backup','tab=restore'],
    'backup_history'=>['backup','Atividade','fa-clock-o',1,'backup','tab=history'],
    'backup_settings'=>['backup','Destinos','fa-cog',1,'backup','tab=settings'],
    'backup_drive'=>['backup','Disco virtual','fa-floppy-o',1,'backup','tab=drive'],
    'speed'=>['rede','Internet','fa-globe',0,'velocidade',''],
    'devices'=>['rede','Dispositivos','fa-th',0,'dispositivos',''],
    'map'=>['rede','Mapa','fa-map-o',0,'mapa',''],
    'files'=>['rede','Arquivos','fa-folder',1,'arquivos',''],
    'remote'=>['helpdesk','Acesso remoto','fa-eye',0,'remoto',''],
    'support'=>['helpdesk','Suporte','fa-question-circle',0,'suporte',''],
    'tickets'=>['helpdesk','Chamados','fa-comment-o',0,'chamados',''],
    'agenda'=>['helpdesk','Agenda','fa-calendar',1,'agenda',''],
    'users'=>['gestao','Usuários','fa-users',0,'usuarios',''],
    'permissions'=>['gestao','Permissões','fa-lock',0,'permissoes',''],
    'preferences'=>['gestao','Preferências','fa-sliders',0,'preferencias',''],
    'reports'=>['gestao','Relatórios','fa-bar-chart',1,'relatorios',''],
    'registry_cad'=>['gestao','Cadastros','fa-address-book',1,'cadastros',''],
    'release'=>['gestao','Liberações','fa-unlock-alt',1,'liberacao',''],
];
foreach(arc_pf_modules() as $moduleKey=>$module){
 $key=$module['native'];$arcCatalog[$key]=['pentefino',$module['label'],$module['icon'],0,$module['file'],''];
}
$arcFavoriteKeyMap = [];
$arcFavAllowed = function_exists('arc_menu_favorites_allowed') ? arc_menu_favorites_allowed() : [];
$arcAvailable = [];
foreach ($arcNetworkLinks as $item) {
    $path = (string)parse_url((string)($item['url'] ?? ''), PHP_URL_PATH);
    if (!preg_match('~^redes/([a-z0-9_-]+)\.php$~iD', $path, $m)) continue;
    if ($arcAllowedMenu !== null && !in_array(str_starts_with($m[1],'pentefino-')?'pentefino':$m[1], $arcAllowedMenu, true)) continue;
    $arcAvailable[$m[1]] = true;
}
// Módulos herdam a visibilidade do Pente-Fino enquanto não houver configuração própria no menu administrativo.
// Uma configuração explícita de módulo conserva seu perfil, grupo e estado habilitado/desabilitado.
$arcPfConfigured=[];
$arcPfMenuRows=$pdo->query('SELECT target_url FROM '.arc_table('navigation_items')." WHERE context='network' AND placement='header'")->fetchAll(PDO::FETCH_COLUMN);
foreach($arcPfMenuRows as $url){$path=(string)parse_url((string)$url,PHP_URL_PATH);$arcPfConfigured[basename($path,'.php')]=true;}
foreach(arc_pf_modules() as $moduleKey=>$module){
 if(!isset($arcPfConfigured[$module['file']])&&!empty($arcAvailable['pentefino']))$arcAvailable[$module['file']]=true;
 if($moduleKey==='uninstall'&&$arcAllowedMenu!==null&&!in_array('programas',$arcAllowedMenu,true))unset($arcAvailable[$module['file']]);
}
// As duas faces da central compartilham o acesso já concedido a Servidor.
// A permissão é novamente validada na própria rota ao abrir a página.
if (!empty($arcAvailable['servidor'])) {
    $arcAvailable['atualizacoes'] = true;
    $arcAvailable['historico'] = true;
}
$arcGroupedLinks = [];
foreach ($arcCatalog as $key => [$groupKey, $label, $icon, $band, $section, $query]) {
    if (empty($arcAvailable[$section])) continue;
    $url = arc_workspace_base_url() . 'redes/' . $section . '.php?network=' . (int)$networkId
         . ($query !== '' ? '&' . $query : '') . (isset($_GET['gestor']) ? '&gestor=' . (int)$_GET['gestor'] : '');
    $arcGroupedLinks[$groupKey][] = ['section'=>$key,'label'=>$label,'icon'=>$icon,'band'=>$band,'href'=>$url];
    $arcFavoriteKeyMap[$key] = $key;
}
$arcGroupedLinks = array_intersect_key(array_replace(array_flip(array_keys($arcWorkspaceGroups)), $arcGroupedLinks), $arcGroupedLinks);
// Favoritos pertencem exclusivamente à categoria Início: saem da categoria de origem e só voltam ao desfavoritar.
$arcFavorites = [];
try {
    $arcAccess = arc_network_team_access($pdo, (int)$networkId, (int)arc_user_id());
    if ($arcAccess) $arcFavorites = (array)(arc_menu_favorites_read($pdo, (int)$arcAccess['account_id'], (int)$networkId)['favorites'] ?? []);
} catch (Throwable $e) { $arcFavorites = []; }
foreach ($arcGroupedLinks as $groupKey => $entries) {
    if ($groupKey === 'inicio') continue;
    foreach ($entries as $index => $entry) {
        if ($entry['section'] === 'panel' || !in_array($entry['section'], $arcFavorites, true)) continue;
        $entry['origin'] = $groupKey;
        $arcGroupedLinks['inicio'][] = $entry;
        unset($arcGroupedLinks[$groupKey][$index]);
    }
    $arcGroupedLinks[$groupKey] = array_values($arcGroupedLinks[$groupKey]);
}
// Seção atual -> chave do gestor.
$arcCurrentSection = (string)($arcSectionFile ?? '');
$arcSectionToKey = ['painel'=>'panel','servidor'=>'server','programas'=>'programs','velocidade'=>'speed','dispositivos'=>'devices','mapa'=>'map','arquivos'=>'files','suporte'=>'support','chamados'=>'tickets','remoto'=>'remote','atualizacoes'=>'updates','historico'=>'history','usuarios'=>'users','permissoes'=>'permissions','preferencias'=>'preferences','relatorios'=>'reports','cadastros'=>'registry_cad','liberacao'=>'release','agenda'=>'agenda'];
if ($arcCurrentSection === 'pentefino') {
    $arcCurrentSection = isset($arcPfModule) ? (arc_pf_modules()[$arcPfModule]['native']??'health') : 'health'; // Rota legada continua acessível; menu Configurações foi incorporado às ferramentas.
} elseif ($arcCurrentSection === 'backup') {
    $arcBackupTabs = ['cloud'=>'backup_cloud','local'=>'backup_local','restore'=>'backup_restore','history'=>'backup_history','settings'=>'backup_settings','drive'=>'backup_drive'];
    $arcCurrentSection = $arcBackupTabs[(string)($_GET['tab'] ?? 'overview')] ?? 'backup';
} else {
    $arcCurrentSection = $arcSectionToKey[$arcCurrentSection] ?? $arcCurrentSection;
}
$arcCurrentGroup = 'outros';
foreach ($arcGroupedLinks as $key => $entries) foreach ($entries as $entry) if ($entry['section'] === $arcCurrentSection) $arcCurrentGroup = $key; // favorito já está em 'inicio'
?>
<?php if ($arcGroupedLinks): ?>
<!-- Faixas nativas do gestor Windows: categorias na primeira linha e ferramentas na segunda. -->
<nav data-favorites-url="<?=arc_h(arc_workspace_base_url().'api/menu-favorites.php?network='.(int)$networkId)?>" data-favorites-csrf="<?=arc_h($csrf)?>" class="arc-gestor-ribbon" aria-label="Ferramentas da gestão da rede" data-active-group="<?=arc_h($arcCurrentGroup)?>">
  <div class="arc-gestor-ribbon-groups" role="tablist" aria-label="Categorias do gestor">
    <?php foreach($arcGroupedLinks as $groupKey=>$items):
      $group=$arcWorkspaceGroups[$groupKey]??['label'=>'Outros','icon'=>'fa-ellipsis-h'];
      $selected=$groupKey===$arcCurrentGroup || ($arcCurrentGroup==='outros' && $groupKey===array_key_first($arcGroupedLinks));
    ?>
    <button type="button" id="arc-ribbon-tab-<?=arc_h($groupKey)?>" class="arc-gestor-ribbon-tab <?=$selected?'active':''?>" <?=($items||$groupKey==='inicio')?'':'hidden'?> role="tab" aria-selected="<?=$selected?'true':'false'?>" aria-controls="arc-ribbon-panel-<?=arc_h($groupKey)?>" data-group="<?=arc_h($groupKey)?>">
      <i class="fa <?=arc_h($group['icon'])?>" aria-hidden="true"></i>
      <span><?=arc_h($group['label'])?></span>
    </button>
    <?php endforeach; ?>
  </div>
  <div class="arc-gestor-ribbon-viewport">
    <?php foreach($arcGroupedLinks as $groupKey=>$items):
      $selected=$groupKey===$arcCurrentGroup || ($arcCurrentGroup==='outros' && $groupKey===array_key_first($arcGroupedLinks));
    ?>
    <div id="arc-ribbon-panel-<?=arc_h($groupKey)?>" class="arc-gestor-ribbon-items" role="tabpanel" aria-labelledby="arc-ribbon-tab-<?=arc_h($groupKey)?>" data-group="<?=arc_h($groupKey)?>" <?=$selected?'':'hidden'?>>
      <?php foreach($items as $item): $favoriteKey=$item['section']; $active=$item['section']===$arcCurrentSection; ?>
      <div class="arc-favorite-cell" data-band="<?=(int)$item['band']?>" data-origin="<?=arc_h((string)($item['origin']??$groupKey))?>">
      <a href="<?=arc_h($item['href'])?>" data-favorite-key="<?=arc_h($favoriteKey)?>" class="arc-gestor-ribbon-link <?=$active?'active':''?>" <?=$active?'aria-current="page"':''?> title="<?=arc_h($item['label'])?>">
        <span class="arc-db-icon" aria-hidden="true"><i class="fa <?=arc_h($item['icon'])?>" aria-hidden="true"></i></span>
        <span><?=arc_h($item['label'])?></span>
      </a>
      <?php if ($favoriteKey!=='' && $favoriteKey!=='panel' && in_array($favoriteKey,$arcFavAllowed,true)): ?>
      <button type="button" class="arc-favorite-star" data-favorite-key="<?=arc_h($favoriteKey)?>" aria-label="Favoritar <?=arc_h($item['label'])?>" title="Favoritar"><i class="fa <?=isset($item['origin'])?'fa-star':'fa-star-o'?>" aria-hidden="true"></i></button>
      <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</nav>

<script>
/* Caminho: redes/_menu.php — troca de categoria sem navegação ou perda do estado da página. */
(function(){'use strict';
 const root=document.querySelector('.arc-gestor-ribbon');if(!root)return;
 const tabs=Array.from(root.querySelectorAll('[role="tab"]'));
 const panels=Array.from(root.querySelectorAll('[role="tabpanel"]'));
 function select(tab,focus){
   const key=tab.dataset.group;
   tabs.forEach(t=>{const active=t===tab;t.classList.toggle('active',active);t.setAttribute('aria-selected',active?'true':'false');t.tabIndex=active?0:-1;});
   panels.forEach(p=>{p.hidden=p.dataset.group!==key;});
   root.dataset.activeGroup=key;
   if(focus)tab.focus();
 }
 tabs.forEach((tab,index)=>{
   tab.addEventListener('click',()=>select(tab,false));
   tab.addEventListener('keydown',event=>{
     if(event.key!=='ArrowRight'&&event.key!=='ArrowLeft'&&event.key!=='Home'&&event.key!=='End')return;
     event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
     select(tabs[next],true);
   });
 });
 const endpoint=root.dataset.favoritesUrl, token=root.dataset.favoritesCsrf;
 const validLinks=[...root.querySelectorAll('.arc-gestor-ribbon-link[data-favorite-key]')];
 const cells=validLinks.map(a=>a.closest('.arc-favorite-cell'));
 const panelOf=g=>panels.find(p=>p.dataset.group===g)||null;
 const originals=new Map(cells.map((cell,index)=>[cell,{group:cell.dataset.origin,index}]));
 function layoutBands(){
   panels.forEach(p=>{
     p.querySelectorAll(':scope > .arc-ribbon-sep').forEach(x=>x.remove());
     if(p.dataset.group==='inicio')return;
     const list=Array.from(p.querySelectorAll(':scope > .arc-favorite-cell')).sort((x,y)=>(originals.get(x)?.index||0)-(originals.get(y)?.index||0));
     list.forEach(c=>p.appendChild(c));
     let band=null;
     list.forEach(c=>{const b=c.dataset.band;if(band!==null&&b!==band){const sep=document.createElement('span');sep.className='arc-ribbon-sep';sep.setAttribute('role','separator');sep.setAttribute('aria-orientation','vertical');p.insertBefore(sep,c);}band=b;});
   });
 }
 const favoritesHost=panels.find(p=>p.dataset.group==='inicio');
 let current=new Set(), pending=false;
 function renderFavorites(){
   cells.forEach(cell=>{
     const a=cell.querySelector('.arc-gestor-ribbon-link'),key=a?.dataset.favoriteKey,original=originals.get(cell);
     if(!original)return;
     if(key && key!=='panel'&&current.has(key)&&favoritesHost)favoritesHost.appendChild(cell);
     else{const home=panelOf(original.group);if(home)home.appendChild(cell);}
     const star=cell.querySelector('.arc-favorite-star');
     if(star){const selected=current.has(key);star.title=selected?'Desfavoritar':'Favoritar';star.setAttribute('aria-label',star.title+' '+(a?.textContent||'').trim());
       star.querySelector('i').className='fa '+(selected?'fa-star':'fa-star-o');}
   });
   // Painel fixo em primeiro lugar, sem estrela.
   const panel=validLinks.find(a=>a.dataset.favoriteKey==='panel');
   if(panel&&favoritesHost)favoritesHost.prepend(panel.closest('.arc-favorite-cell'));
   layoutBands();
   panels.forEach(p=>{
     const visible=p.querySelectorAll('.arc-gestor-ribbon-link').length>0;
     const tab=tabs.find(t=>t.dataset.group===p.dataset.group);
     if(tab)tab.hidden=!visible && p.dataset.group!=='inicio';
   });
 }
 async function request(operation){
    const isRead=!operation;
    const response=await fetch(endpoint,{method:isRead?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:isRead?{}:{'Content-Type':'application/json','X-CSRF-Token':token},body:isRead?undefined:JSON.stringify(operation)});
    const json=await response.json();if(!response.ok||!json.ok)throw Error(json.error||'Não foi possível sincronizar');return json;
 }
 async function sync(){if(pending||!document.visibilityState||document.visibilityState==='hidden')return;
   try{const data=await request(null);current=new Set(data.favorites||[]);renderFavorites();
   }catch(err){console.warn('Favoritos:',err.message);}
 }
 root.querySelectorAll('.arc-favorite-star').forEach(star=>star.addEventListener('click',async()=>{
   if(pending)return; pending=true; const key=star.dataset.favoriteKey, isFavorite=!current.has(key);
   star.disabled=true;
   try{const data=await request({operation:'set',key,favorite:isFavorite});current=new Set(data.favorites||[]);renderFavorites();
      const home=tabs.find(t=>t.dataset.group==='inicio');if(isFavorite&&home)select(home,false);
   }catch(err){console.warn('Favoritos:',err.message);alert('Não foi possível sincronizar o favorito. Tente novamente.');}
   finally{star.disabled=false;pending=false;}
 }));
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')sync();});
 window.setInterval(sync,30000);sync();

 // Ações fixas abrem páginas próprias, sem reutilizar o modal de instalação.
 const base=<?=json_encode(arc_workspace_base_url())?>;
 const network=<?= (int)$networkId ?>;
 function gestor(){return Number(document.getElementById('arc-gestor-target')?.value||<?= (int)$selectedGestorId ?>)}

 layoutBands();const active=tabs.find(t=>t.classList.contains('active'))||tabs[0];if(active)select(active,false);
})();
</script>
<?php endif; ?>
