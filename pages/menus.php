<?php
/* Caminho: pages/menus.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require dirname(__DIR__).'/includes/icones.php';
$pdo=arc_db(); arc_ensure_user_schema($pdo);arc_v19_navigation_schema($pdo);
if(!arc_is_admin()){http_response_code(403);exit('Acesso reservado ao administrador.');}
arc_v15_menu_schema($pdo);arc_v16_group_schema($pdo);
$groups=$pdo->query('SELECT id,name FROM '.arc_table('permission_groups').' ORDER BY name')->fetchAll();
$routes=arc_v15_routes();
$placements=['sidebar'=>'Barra lateral','header'=>'Header principal','user'=>'Dropdown do usuário','footer'=>'Rodapé','network'=>'Gestão de redes'];
$roles=['admin'=>'Administrador','manager'=>'Gestor','user'=>'Usuário','viewer'=>'Visualizador'];
/** Catálogo das telas reais deste backup. Nenhuma tabela adicional é criada. */
function arc_menu_file_catalog(): array {
 $a=[];
 $add=static function(string $label,string $route,string $icon,string $place,string $roles,int $order,?string $parent=null,?string $target=null)use(&$a):void {
   $file=$target;
   if($file===null && $route!=='')$file=arc_v15_routes()[$route][1]??null;
   $a[]=['label'=>$label,'route_key'=>$route,'icon'=>$icon,'placement'=>$place,'access_roles'=>$roles,'sort_order'=>$order,'parent'=>$parent,'target_url'=>$file];
 };
 $all='admin,manager,user,viewer';$admins='admin';
 $add('Painel administrativo','overview','fa-tachometer','sidebar',$admins,10);
 $add('Administração','','fa-cogs','sidebar',$admins,20);
 $add('Usuários administrativos','administrators','fa-user-secret','sidebar',$admins,21,'Administração');
 $add('Clientes e gestores','users','fa-users','sidebar',$admins,22,'Administração');
 $add('Permissões, grupos e perfis','groups','fa-lock','sidebar',$admins,23,'Administração');
 $add('Menus e submenus','menus','fa-list-alt','sidebar',$admins,24,'Administração');
 $add('Configurações da plataforma','platform_settings','fa-sliders','sidebar',$admins,25,'Administração');
 $add('Versões dos agentes','releases','fa-cubes','sidebar',$admins,26,'Administração');
 $add('Minhas redes','networks','fa-sitemap','sidebar','manager,user,viewer',30);
 $add('Painel de monitoramento','workspace_app','fa-area-chart','sidebar','manager,user,viewer',35);
 $add('Minha conta','profile','fa-user-circle','sidebar','manager,user,viewer',40);
 $add('Minhas redes','networks','fa-sitemap','header','manager,user,viewer',10);
 $add('Minha conta','profile','fa-user-circle','user','manager,user,viewer',10);
 $add('Minhas redes','networks','fa-sitemap','user','manager,user,viewer',20);
 $add('Configurações da plataforma','platform_settings','fa-sliders','user',$admins,30);
 $add('Minhas redes','networks','fa-sitemap','footer','manager,user,viewer',10);
 $network=[
 ['network_monitor','Monitoramento','fa-area-chart',10],
 ['network_speed','Velocidade','fa-tachometer',20],
 ['network_devices','Dispositivos','fa-desktop',30],
 ['network_topology','Mapa da rede','fa-sitemap',40],
 ['network_connection','Conexão','fa-wifi',50],
 ['network_installations','Instalações','fa-download',100],
 ['network_agents','Agentes','fa-cogs',110],
 ['network_preferences','Preferências da rede','fa-sliders',120],
 ['network_registration','Cadastro da rede','fa-pencil-square-o',130],
 ];
 foreach($network as [$route,$label,$icon,$order])$add($label,$route,$icon,'network','manager,user,viewer',$order);
 $routes=arc_v15_routes();
 return array_values(array_filter($a,static fn($x)=>$x['route_key']===''||isset($routes[$x['route_key']])));
}
/** Sincroniza somente menus de sistema; entradas personalizadas permanecem intactas. */
function arc_menu_sync_existing(PDO $pdo): array {
 $catalog=arc_menu_file_catalog();$routes=arc_v15_routes();$table=arc_table('navigation_items');
 $pdo->beginTransaction();$created=0;$updated=0;$disabled=0;
 try{
   $pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['navigation_initialized','1','text']);
   $existing=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
   $used=[];$parents=[];
   $insert=$pdo->prepare('INSERT INTO '.$table.' (label,route_key,placement,access_roles,sort_order,enabled,group_id,icon,parent_id,target_url) VALUES (?,?,?,?,?,1,NULL,?,?,?)');
   $update=$pdo->prepare('UPDATE '.$table.' SET label=?,route_key=?,access_roles=?,sort_order=?,enabled=1,icon=?,parent_id=?,target_url=? WHERE id=?');
   foreach($catalog as $entry){
     $parentId=$entry['parent']===null?null:($parents[$entry['placement'].'|'.$entry['parent']]??null);
     if($entry['parent']!==null && $parentId===null)continue;
     $candidate=null;
     foreach($existing as $old){
       $id=(int)$old['id'];if(isset($used[$id])||$old['placement']!==$entry['placement'])continue;
       if($entry['route_key']==='' && $old['label']===$entry['label'] && empty($old['parent_id'])){$candidate=$old;break;}
       if($entry['route_key']!=='' && $old['route_key']===$entry['route_key']){$candidate=$old;break;}
       if($entry['route_key']!=='' && $old['label']===$entry['label']){$candidate=$old;break;}
     }
     // Caminho físico existente, não um alias de rota interna.
     $target=$entry['target_url'];
     if($entry['route_key']==='')$target=null;
     if($candidate){
       $id=(int)$candidate['id'];$update->execute([$entry['label'],'',$entry['access_roles'],$entry['sort_order'],$entry['icon'],$parentId,$target,$id]);$updated++;
     }else{
       $insert->execute([$entry['label'],'',$entry['placement'],$entry['access_roles'],$entry['sort_order'],$entry['icon'],$parentId,$target]);$id=(int)$pdo->lastInsertId();$created++;
     }
     $used[$id]=true;
     if($entry['route_key']==='' && $entry['parent']===null)$parents[$entry['placement'].'|'.$entry['label']]=$id;
   }
   // Desabilitar apenas entradas legadas com rota interna inexistente ou destino PHP removido.
   // Preservar menus personalizados com URLs externas e sem chave de rota.
   $off=$pdo->prepare('UPDATE '.$table.' SET enabled=0 WHERE id=?');
   foreach($existing as $old){
     $id=(int)$old['id'];if(isset($used[$id])||!(int)$old['enabled'])continue;
     $route=(string)$old['route_key'];$target=trim((string)($old['target_url']??''));
     $stale=($route!=='' && !isset($routes[$route]));
     // Rotas padrão que foram substituídas na mesma posição não devem duplicar o menu.
     $canonical=array_filter($catalog,static fn($x)=>$x['placement']===$old['placement'] && $x['route_key']===$route && $route!=='');
     if($canonical && $target==='')$stale=true;
     // Entradas padrão do Header foram transferidas para a Sidebar / dropdown.
     if($old['placement']==='header' && in_array($route,['admin','platform_settings','overview','menus','groups','users','administrators'],true) && $target==='')$stale=true;
     if(!$stale && $target!=='' && !preg_match('~^https://~i',$target)){
       $file=explode('?',explode('#',$target,2)[0],2)[0];
       if(preg_match('~^(?:pages|redes)/[a-z][a-z0-9_-]*\.php$~i',$file))$stale=!is_file(dirname(__DIR__).'/'.$file);
     }
     if($stale){$off->execute([$id]);$disabled++;}
   }
   $pdo->commit();return [$created,$updated,$disabled];
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
$message=(string)($_SESSION['arc_menu_bulk_result']??'');unset($_SESSION['arc_menu_bulk_result']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??''))){http_response_code(403);exit('Sessão expirada.');}
 try{
  $action=(string)($_POST['action']??'');
  if($action==='sync_files'){
   [$created,$updated,$disabled]=arc_menu_sync_existing($pdo);
   $_SESSION['arc_menu_bulk_result']=sprintf('Sincronização concluída: %d menus criados, %d atualizados e %d destinos antigos desativados.',$created,$updated,$disabled);
  } elseif($action==='reorder'){
   $ids=array_map('intval',(array)($_POST['ordered_ids']??[]));
   $ids=array_values(array_unique(array_filter($ids,static fn($v)=>$v>0)));
   if(!$ids||count($ids)>500)throw new RuntimeException('Ordem inválida.');
   $place=(string)($_POST['placement']??'');
   if(!isset($placements[$place]))throw new RuntimeException('Posição inválida.');
   $q=$pdo->prepare('SELECT id,parent_id FROM '.arc_table('navigation_items').' WHERE placement=?');$q->execute([$place]);
   $valid=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$valid[(int)$r['id']]=(int)$r['parent_id'];
   $first=$ids[0];if(!array_key_exists($first,$valid))throw new RuntimeException('Menu inexistente.');
   $parent=$valid[$first];foreach($ids as $i)if(!array_key_exists($i,$valid)||$valid[$i]!==$parent)throw new RuntimeException('Só é permitido ordenar menus do mesmo nível e posição.');
   $pdo->beginTransaction();try{
    $q=$pdo->prepare('UPDATE '.arc_table('navigation_items').' SET sort_order=? WHERE id=?');
    foreach($ids as $n=>$i)$q->execute([($n+1)*10,$i]);
    $pdo->commit();
   }catch(Throwable $e){$pdo->rollBack();throw $e;}
   header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]);exit;
  } elseif($action==='normalize_network'){
   $q=$pdo->prepare('UPDATE '.arc_table('navigation_items').' SET parent_id=NULL WHERE placement=? AND parent_id IS NOT NULL');$q->execute(['network']);
   $_SESSION['arc_menu_bulk_result']=$q->rowCount().' menus de rede desvinculados de agrupadores.';
  } elseif($action==='bulk'){
   $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])),static fn($v)=>$v>0)));
   if(!$ids || count($ids)>500)throw new RuntimeException('Selecione de 1 a 500 menus.');
   $operation=(string)($_POST['bulk_operation']??'');
   if(!in_array($operation,['enable','disable','delete'],true))throw new RuntimeException('Selecione uma ação válida.');
   $placeholders=implode(',',array_fill(0,count($ids),'?'));
   $pdo->beginTransaction();
   try{
    // Marca o gerenciador como configurado para impedir a recriação automática dos menus padrão.
    $pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['navigation_initialized','1','text']);
    if($operation==='delete'){
     $pdo->prepare('UPDATE '.arc_table('navigation_items').' SET parent_id=NULL WHERE parent_id IN ('.$placeholders.')')->execute($ids);
     $stmt=$pdo->prepare('DELETE FROM '.arc_table('navigation_items').' WHERE id IN ('.$placeholders.')');$stmt->execute($ids);$affected=$stmt->rowCount();
    }else{
     $stmt=$pdo->prepare('UPDATE '.arc_table('navigation_items').' SET enabled=' .($operation==='enable'?'1':'0').' WHERE id IN ('.$placeholders.')');$stmt->execute($ids);$affected=$stmt->rowCount();
    }
    $pdo->commit();
    $_SESSION['arc_menu_bulk_result']=sprintf('%d registro(s) alterado(s) no banco.', $affected);
   }catch(Throwable $e){$pdo->rollBack();throw $e;}
  } elseif($action==='delete'){
   $id=(int)($_POST['id']??0);
   $pdo->prepare('UPDATE '.arc_table('navigation_items').' SET parent_id=NULL WHERE parent_id=?')->execute([$id]);
   $pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['navigation_initialized','1','text']);
   $pdo->prepare('DELETE FROM '.arc_table('navigation_items').' WHERE id=?')->execute([$id]);
  } elseif($action==='save'){
   $id=(int)($_POST['id']??0);
   $label=mb_substr(trim((string)($_POST['label']??'')),0,80);
   $route='';
   // Menus sem destino utilizam a chave de rota vazia; a navegação os trata como agrupadores.
   $selectedPlaces=array_values(array_intersect(array_keys($placements),(array)($_POST['placements']??[])));
   $place=$selectedPlaces[0]??'';
   $order=max(0,min(9999,(int)($_POST['sort_order']??100)));
   $icon=substr(preg_replace('/[^a-z0-9-]/','',(string)($_POST['icon']??'fa-circle')),0,48);
   if(!str_starts_with($icon,'fa-'))$icon='fa-'.$icon;
   if(!in_array(substr($icon,3),arc_fa_icons(),true))throw new RuntimeException('Selecione um ícone válido.');
   $target=trim((string)($_POST['target_url']??''));
   if(strlen($target)>1024)throw new RuntimeException('Destino muito longo.');
   if($target!=='' && !preg_match('~^https://[a-z0-9.-]+(?::[0-9]{2,5})?(?:[/?#][^\s<>\"\']*)?$~i',$target)){
     if(!preg_match('~^(?:pages|redes)/[a-z][a-z0-9_-]*\.php(?:\?[a-z0-9_=&%-]*)?(?:#[a-z0-9_-]+)?$~i',$target))throw new RuntimeException('Informe um caminho pages/arquivo.php, redes/arquivo.php ou uma URL HTTPS.');
     $file=explode('?',explode('#',$target,2)[0],2)[0];
     if(!is_file(dirname(__DIR__).'/'.$file))throw new RuntimeException('O arquivo de destino não existe: '.$file);
   }
   $parent=(int)($_POST['parent_id']??0);
   $enabled=isset($_POST['enabled'])?1:0;
   $group=(int)($_POST['group_id']??0);
   if($group>0){$check=$pdo->prepare('SELECT id FROM '.arc_table('permission_groups').' WHERE id=?');$check->execute([$group]);if(!$check->fetchColumn())throw new RuntimeException('Grupo não encontrado.');}
   $selected=array_values(array_intersect(array_keys($roles),(array)($_POST['roles']??[])));
   if($label===''||!$selectedPlaces||!$selected)throw new RuntimeException('Informe nome, posição e pelo menos um perfil de acesso.');
   if($parent===0 && $route==='' && $target===''){ /* Menu pai pode funcionar apenas como agrupador. */ }
   // Submenus também podem ser agrupadores sem destino.
   if($parent>0 && count($selectedPlaces)>1)throw new RuntimeException('Submenus vinculados a um menu pai devem ser cadastrados em uma posição por vez.');
   if($parent>0){$parentQ=$pdo->prepare('SELECT id,parent_id FROM '.arc_table('navigation_items').' WHERE id=? AND placement=?');$parentQ->execute([$parent,$place]);$parentRow=$parentQ->fetch(PDO::FETCH_ASSOC);if(!$parentRow||$parent===$id)throw new RuntimeException('Menu superior inválido.');$ancestor=(int)($parentRow['parent_id']??0);$seen=[];while($ancestor>0){if($ancestor===$id||isset($seen[$ancestor]))throw new RuntimeException('Vínculo circular de menus não permitido.');$seen[$ancestor]=1;$q=$pdo->prepare('SELECT parent_id FROM '.arc_table('navigation_items').' WHERE id=?');$q->execute([$ancestor]);$ancestor=(int)$q->fetchColumn();}}
   if($id>0){
    $pdo->prepare('UPDATE '.arc_table('navigation_items').' SET label=?,route_key=?,placement=?,access_roles=?,sort_order=?,enabled=?,group_id=?,icon=?,parent_id=?,target_url=? WHERE id=?')->execute([$label,$route,$place,implode(',',$selected),$order,$enabled,$group?:null,$icon,$parent?:null,$target?:null,$id]);
   }else{
    $pdo->prepare('INSERT INTO '.arc_table('navigation_items').' (label,route_key,placement,access_roles,sort_order,enabled,group_id,icon,parent_id,target_url) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$label,$route,$place,implode(',',$selected),$order,$enabled,$group?:null,$icon,$parent?:null,$target?:null]);
   }
   // Cada posição selecionada recebe seu próprio registro, mantendo a estrutura atual do banco.
   // Ao editar, a posição principal é atualizada; novas posições são criadas sem duplicar registros existentes.
   foreach(array_slice($selectedPlaces,1) as $extraPlace){
    $exists=$pdo->prepare('SELECT id FROM '.arc_table('navigation_items').' WHERE label=? AND placement=? AND parent_id IS NULL LIMIT 1');
    $exists->execute([$label,$extraPlace]);
    if($exists->fetchColumn())continue;
    $pdo->prepare('INSERT INTO '.arc_table('navigation_items').' (label,route_key,placement,access_roles,sort_order,enabled,group_id,icon,parent_id,target_url) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$label,$route,$extraPlace,implode(',',$selected),$order,$enabled,$group?:null,$icon,null,$target?:null]);
   }
  } else throw new RuntimeException('Ação inválida.');
  header('Location: menus.php?saved=1',true,303);exit;
 }catch(Throwable $e){$message='Não foi possível concluir a operação: '.$e->getMessage();}
}
$items=$pdo->query('SELECT * FROM '.arc_table('navigation_items').' ORDER BY placement,sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
// Ordem hierárquica preserva os filhos junto ao seu respectivo pai, sem perder registros órfãos.
$byParent=[];$known=[];foreach($items as $r)$known[(int)$r['id']]=true;
foreach($items as $r){$parent=(int)($r['parent_id']??0);if($parent && !isset($known[$parent]))$parent=0;$byParent[(string)$r['placement'].'|'.$parent][]=$r;}
$ordered=[];$visited=[];
$walk=function(string $place,int $parent,int $depth=0)use(&$walk,&$ordered,&$visited,$byParent):void{
 if($depth>25)return;
 foreach($byParent[$place.'|'.$parent]??[] as $r){$id=(int)$r['id'];if(isset($visited[$id]))continue;$visited[$id]=true;$r['_depth']=$depth;$ordered[]=$r;$walk($place,$id,$depth+1);}
};
foreach(array_keys($placements) as $place){$walk($place,0);foreach($items as $r)if($r['placement']===$place&&!isset($visited[(int)$r['id']])){$r['_depth']=0;$ordered[]=$r;$visited[(int)$r['id']]=true;}}
$items=$ordered;
function mh(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$ARCMode='app';$ARCPageTitle='Menus e submenus';$ARCActiveNav='menus';$ARCHeaderActions='';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';?>
<style>
.menu-bulk-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.menu-bulk-actions span{font-size:11px;color:var(--muted,#64748b);white-space:nowrap}.menu-bulk-actions button:disabled{opacity:.45;cursor:not-allowed}
.arc-menu-table-scroll{position:relative;overflow:auto;max-height:min(65vh,660px);min-height:180px;overscroll-behavior:contain;border:1px solid var(--border,#dbe5ed);border-radius:10px}.arc-menu-table-scroll table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0}.arc-menu-table-scroll thead{position:sticky;top:0;z-index:5}.arc-menu-table-scroll th{position:sticky;top:0;z-index:5;background:var(--surface,#f5f9fc)!important;box-shadow:0 2px 0 var(--border,#dbe5ed);white-space:nowrap}.arc-menu-table-scroll th .table-sort{display:flex;align-items:center;gap:6px;width:100%;padding:7px 4px;border:0;background:transparent;color:inherit;cursor:pointer;text-align:left;font-weight:700}.arc-menu-table-scroll th .table-sort:hover{color:var(--arc-brand-accent,#1595b5)}.arc-menu-table-scroll td{vertical-align:middle}.arc-menu-table-scroll .menu-select-cell{width:46px;min-width:46px;text-align:center}.arc-menu-table-scroll input[type=checkbox]{width:16px;height:16px;accent-color:#1785a8;cursor:pointer}.menu-icon-cell{width:55px;text-align:center;font-size:17px;color:var(--primary)}.menu-path-cell{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,monospace;font-size:11px}.menu-order-hint{display:block;font-size:10px;color:var(--muted,#768696)}.menu-status{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;white-space:nowrap}.menu-status-dot{width:9px;height:9px;border-radius:50%;background:var(--surface-alt);box-shadow:0 0 0 3px #a1aab420}.menu-status.is-active .menu-status-dot{background:#22b57b;box-shadow:0 0 0 3px #22b57b20}.arc-menu-table-scroll td.actions{white-space:nowrap}.arc-menu-table-scroll td.actions .button{min-width:32px;padding:8px}.menu-status-control{display:flex;align-items:center;justify-content:space-between;gap:15px}.menu-status-control>span:last-child{font-size:12px}

.menu-drag{cursor:grab;color:var(--muted);padding:5px;background:none;border:0}.menu-drag:active{cursor:grabbing}.menu-child td:nth-child(4){padding-left:calc(14px + var(--menu-depth, 1)*22px)}.menu-expand{border:0;background:transparent;color:var(--primary);cursor:pointer;margin-right:6px}.menu-root{background:#1a719e}.menu-dragging{opacity:.45}.menu-drop-target{outline:2px dashed #1484bc;outline-offset:-3px}.menu-toolbar .button{border-radius:9px}.menu-kpis{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px}.menu-kpis span{padding:12px 17px;border:1px solid var(--border,#dce5ef);border-radius:10px;background:var(--surface,#fff);font-size:13px}.menu-kpis strong{font-size:19px;color:var(--primary);margin-right:5px}
#menu-dialog{width:min(760px,96vw);max-height:92dvh;padding:0;border-radius:14px;overflow:hidden}
#menu-dialog .dialog-header{padding:12px 18px}
#menu-dialog #menu-form{display:flex;flex-direction:column;min-height:0;max-height:calc(92dvh - 58px)}
#menu-dialog .dialog-body{padding:12px 18px;gap:9px 12px;overflow-y:auto;min-height:0;align-content:start}
#menu-dialog .dialog-body>label{font-size:12px;gap:4px}
#menu-dialog .dialog-body input:not([type=checkbox]):not([type=hidden]){min-height:34px;height:34px;padding:5px 9px;font-size:12px}
#menu-dialog .dialog-body select{min-height:34px;height:34px;padding:5px 9px;font-size:12px}
#menu-dialog .dialog-body fieldset{padding:6px 10px;margin:0;border-radius:8px}
#menu-dialog .dialog-body .placement-options{display:flex;flex-wrap:wrap;gap:6px 15px}
#menu-dialog .dialog-body .roles{display:flex;flex-wrap:wrap;gap:6px 15px}
#menu-dialog .dialog-body fieldset label{font-size:11px;white-space:nowrap}
#menu-dialog .dialog-body .help-text{font-size:10px;margin-top:2px}
#menu-dialog .dialog-actions{padding:10px 18px}
.menu-icon-picker{display:flex;gap:8px;align-items:center}
.menu-icon-picker #arc-open-icons{width:42px;height:36px;display:grid;place-items:center;font-size:20px;flex:none}
.menu-icon-picker #menu-icon-choose{flex:1;height:36px}
 .menu-parent-note{font-size:10px;color:var(--muted,#738398);font-weight:400}
#menu-dialog .menu-parent-control{display:flex;gap:7px;align-items:center;min-width:0}
#menu-dialog .menu-parent-control select{flex:1;min-width:0;width:100%}
#menu-dialog #menu-remove-parent{flex:none;white-space:nowrap;min-height:34px;padding:6px 10px;background:var(--surface);color:var(--text);border:1px solid var(--border)}
#menu-dialog #menu-remove-parent:hover{background:var(--surface)}
#menu-dialog .menu-icon-picker{border:1px solid var(--border);border-radius:9px;padding:5px;background:var(--surface)}
#menu-dialog .menu-icon-picker #arc-open-icons{width:46px;height:40px;background:var(--surface);border:1px solid var(--border);border-radius:7px;color:var(--primary)}
#menu-dialog .menu-icon-picker #menu-icon-preview{font-size:24px;display:inline-block;min-width:24px;text-align:center;color:var(--primary)}
#menu-dialog .menu-icon-picker #menu-icon-choose{border:0;background:transparent;color:var(--muted);text-align:left;font-weight:600}
#menu-dialog .menu-icon-picker #menu-icon-choose:hover{text-decoration:underline}
#menu-table tr.menu-child td:nth-child(4){padding-left:calc(15px + var(--menu-depth, 1)*22px);position:relative}
#menu-table tr.menu-child td:nth-child(4):before{content:"";position:absolute;left:calc(-10px + (var(--menu-depth, 1) - 1)*22px);top:0;bottom:50%;width:12px;border-left:2px solid #9cc8da;border-bottom:2px solid #9cc8da;border-bottom-left-radius:6px}
#menu-table tr.menu-root td:nth-child(4)>strong{color:var(--muted)}
.menu-toolbar{display:flex;align-items:center!important;gap:8px!important;flex-wrap:wrap}
.menu-toolbar .search-field{flex:1 1 220px;min-width:180px;max-width:none;margin:0}
.menu-toolbar .search-field input{height:34px;min-height:34px;font-size:12px}
.menu-toolbar .menu-kpis{display:flex;align-items:center;gap:5px;flex-wrap:nowrap;margin:0;flex:0 0 auto}
.menu-toolbar .menu-kpis span{display:flex;align-items:center;gap:4px;white-space:nowrap;padding:5px 8px;border-radius:7px;font-size:10px;min-height:32px;line-height:1.1}
.menu-toolbar .menu-kpis strong{font-size:14px;margin:0}
.menu-toolbar>.button{min-height:32px;padding:5px 9px;font-size:11px}
.menu-toolbar>form>.button{min-height:32px;padding:5px 9px;font-size:11px}
.menu-bulk-actions[hidden]{display:none!important}
.menu-bulk-actions:not([hidden]){display:flex;gap:5px}
.menu-bulk-actions select{height:32px;padding:0 10px;border:1px solid var(--border,#d9e4ed);border-radius:8px;background:var(--surface,#fff);color:var(--text,#213449);font-size:11px}
.menu-bulk-actions .button{padding:5px 8px;font-size:11px}
#menu-table th{padding:5px 7px!important;font-size:11px;line-height:1.2}
#menu-table td{padding:5px 7px!important;font-size:11px;line-height:1.2}
#menu-table thead th .table-sort{padding:3px 2px;font-size:11px}
#menu-table .menu-select-cell{width:30px;min-width:30px}
#menu-table .menu-icon-cell{width:35px;font-size:14px}
#menu-table td.actions .button{min-width:27px;padding:5px!important}
#menu-table .menu-drag{padding:2px}
#menu-table .menu-expand{display:inline-grid;place-items:center;width:19px;height:19px;background:var(--surface);border-radius:6px;margin-right:5px}
#menu-table .menu-order-hint{font-size:9px}
#menu-table .permission-badges{gap:3px}
#menu-table .permission-badges .badge{padding:2px 5px;font-size:9px}
#menu-table tr.menu-child td{background:var(--surface)!important;border-bottom-color:#2679a7;border-bottom:1px solid var(--border)!important}
#menu-table tr.menu-child:nth-child(even) td{background:var(--surface)!important}
#menu-table tr.menu-child td:first-child{box-shadow:inset 3px 0 #4c9dc8}
#menu-table tr.menu-child:hover td{background:var(--surface)!important}
#menu-table tr.menu-child td:nth-child(4)>strong{font-weight:600;color:var(--primary)}
@media(max-width:900px){.menu-toolbar .menu-kpis{order:2;width:100%;justify-content:flex-start}.menu-toolbar .search-field{flex:1 1 100%}}@media(max-width:700px){.menu-toolbar{align-items:stretch}.menu-bulk-actions{width:100%}.arc-menu-table-scroll{max-height:60dvh}}
@media(max-width:600px){#menu-dialog .dialog-body{grid-template-columns:1fr}#menu-dialog{max-height:95dvh}}
</style>
<main class="app-main"><div class="main container">

<?php if(isset($_GET['saved'])):?><p class="alert" role="status">Alterações salvas.</p><?php endif;?>
<?php if($message!==''):?><p class="alert danger" role="alert"><?=mh($message)?></p><?php endif;?>
<section class="card">
  <div class="toolbar menu-toolbar">
    <label class="search-field"><i class="fa fa-search" aria-hidden="true"></i><input type="search" id="menu-search" placeholder="Buscar menu, posição ou destino" aria-label="Buscar menus"></label>
    <div class="menu-kpis"><span><strong><?=count($items)?></strong> Menus cadastrados</span><span><strong><?=count(array_filter($items,static fn($r)=>(int)$r['enabled']===1))?></strong> Ativos</span><span><strong><?=count(array_filter($items,static fn($r)=>(int)($r['parent_id']??0)>0))?></strong> Submenus</span></div>
    <form method="post" id="menu-bulk-form" class="menu-bulk-actions" hidden action="<?=arc_h(arc_workspace_base_url())?>pages/menus.php">
      <input type="hidden" name="_csrf" value="<?=mh(arc_csrf_token())?>"><input type="hidden" name="action" value="bulk">
      <span id="menu-selected-count" aria-live="polite">0 selecionados</span>
      <select name="bulk_operation" id="menu-bulk-operation" aria-label="Ação em massa" required><option value="">Ações em massa</option><option value="enable">Ativar</option><option value="disable">Desativar</option><option value="delete">Excluir</option></select>
      <button type="submit" class="button" id="menu-bulk-apply" disabled aria-label="Aplicar" title="Aplicar"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Aplicar</span></button>
    </form>
    <form method="post" action="<?=arc_h(arc_workspace_base_url())?>pages/menus.php" onsubmit="return confirm('Transformar os menus da gestão de redes em menus independentes?');"><input type="hidden" name="_csrf" value="<?=mh(arc_csrf_token())?>"><input type="hidden" name="action" value="normalize_network"><button type="submit" class="button"><i class="fa fa-sitemap"></i> Desagrupar menus de rede</button></form>
    <button type="button" class="button primary" id="menu-new"><i class="fa fa-plus" aria-hidden="true"></i> Criar novo</button>
  </div>
  <div class="table-wrap arc-menu-table-scroll"><table class="arc-responsive-table data-table" id="menu-table"><thead><tr>
    <th title="Arrastar para ordenar">Ordem</th><th class="menu-select-cell"><input type="checkbox" id="menu-select-all" aria-label="Selecionar todos os menus visíveis"></th>
    <th><button type="button" class="table-sort" data-sort="icon">Ícone <i class="fa fa-sort"></i></button></th>
    <th><button type="button" class="table-sort" data-sort="label">Nome <i class="fa fa-sort"></i></button></th>
    <th><button type="button" class="table-sort" data-sort="target">Caminho do arquivo <i class="fa fa-sort"></i></button></th>
    <th><button type="button" class="table-sort" data-sort="placement">Posição <i class="fa fa-sort"></i></button></th>
    <th><button type="button" class="table-sort" data-sort="roles">Permissões <i class="fa fa-sort"></i></button></th>
    <th><button type="button" class="table-sort" data-sort="status">Status <i class="fa fa-sort"></i></button></th>
    <th>Ações</th></tr></thead><tbody>
  <?php foreach($items as $i): $children=(array_filter($items,static fn($r)=>(int)($r['parent_id']??0)===(int)$i['id'] && $r['placement']===$i['placement'])); $destination=(string)($i['target_url']?:($routes[$i['route_key']][1]??'')); ?>
    <tr data-id="<?=(int)$i['id']?>" data-parent="<?=(int)($i['parent_id']??0)?>" data-depth="<?=(int)($i['_depth']??0)?>" class="<?=empty($i['parent_id'])?'menu-root':'menu-child'?>" style="--menu-depth:<?=(int)($i['_depth']??0)?>" data-icon="<?=mh((string)($i['icon']??'fa-circle'))?>" data-roles="<?=mh((string)$i['access_roles'])?>" data-placement="<?=mh((string)$i['placement'])?>" data-target="<?=mh(mb_strtolower($destination))?>" data-order="<?=(int)$i['sort_order']?>" data-status="<?=(int)$i['enabled']?>">
      <td data-label="Ordem"><button type="button" class="menu-drag" draggable="true" aria-label="Arrastar para ordenar" title="Arraste para mudar a posição"><i class="fa fa-bars"></i></button></td>
      <td class="menu-select-cell"><input type="checkbox" class="menu-row-select" value="<?=(int)$i['id']?>" aria-label="Selecionar <?=mh((string)$i['label'])?>"></td>
      <td class="menu-icon-cell" data-label="Ícone"><i class="fa <?=mh((string)($i['icon']??'fa-circle'))?>" aria-hidden="true"></i></td>
      <td data-label="Nome"><?php if($children):?><button type="button" class="menu-expand" data-expand="<?=(int)$i['id']?>" aria-expanded="true" title="Expandir ou recolher"><i class="fa fa-chevron-down"></i></button><?php endif;?><strong><?=mh((string)$i['label'])?></strong><?php if(!empty($i['parent_id'])):?><small class="text-muted"> · Submenu</small><?php endif;?></td>
      <td class="menu-path-cell" title="<?=mh($destination)?>" data-label="Caminho do arquivo"><?=mh($destination!==''?$destination:'Agrupador sem destino')?></td>
      <td data-label="Posição"><?=mh($placements[$i['placement']]??(string)$i['placement'])?><small class="menu-order-hint">Ordem <?=(int)$i['sort_order']?></small></td>
      <td data-label="Permissões"><div class="permission-badges"><?php foreach(explode(',',(string)$i['access_roles']) as $role): $role=trim($role); if($role!==''): ?><span class="badge"><?=mh($roles[$role]??$role)?></span><?php endif; endforeach; ?></div></td>
      <td data-label="Status"><span class="menu-status <?= $i['enabled']?'is-active':'is-inactive' ?>"><span class="menu-status-dot"></span><?= $i['enabled']?'Ativo':'Desativado' ?></span></td>
      <td class="actions" data-label="Ações"><button type="button" class="button edit" title="Editar menu" aria-label="Editar menu" data-record="<?=mh(json_encode($i,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT))?>"><i class="fa fa-pencil" aria-hidden="true"></i></button>
      <form method="post" onsubmit="return confirm('Excluir este menu?')" action="<?=arc_h(arc_workspace_base_url())?>pages/menus.php"><input type="hidden" name="_csrf" value="<?=mh(arc_csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$i['id']?>"><button class="button danger" type="submit" aria-label="Excluir <?=mh((string)$i['label'])?>"><i class="fa fa-trash" aria-hidden="true"></i></button></form></td>
    </tr>
  <?php endforeach;?></tbody></table><p id="menu-empty" class="empty-state" hidden>Nenhum menu corresponde à busca.</p></div>
</section></div></main>
<dialog id="menu-dialog" class="form-dialog" aria-labelledby="form-title"><div class="dialog-header"><h2 id="form-title">Novo menu</h2><button class="button ghost" type="button" data-close-menu aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div>
<form method="post" id="menu-form" action="<?=arc_h(arc_workspace_base_url())?>pages/menus.php"><input type="hidden" name="_csrf" value="<?=mh(arc_csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="menu-id" value="0">
<div class="dialog-body form-grid">
<label>Nome do menu<input name="label" id="menu-label" maxlength="80" required></label>
<fieldset class="form-span"><legend>Posições de exibição</legend><div class="placement-options" id="menu-placements"><?php foreach($placements as $key=>$title):?><label><input type="checkbox" name="placements[]" value="<?=mh($key)?>" <?= $key==='sidebar'?'checked':'' ?>> <?=mh($title)?></label><?php endforeach;?></div><small class="help-text">Selecione todas as posições desejadas. Cada posição terá seu próprio registro de navegação.</small></fieldset>
<label>Menu pai <small class="menu-parent-note">(opcional)</small><div class="menu-parent-control"><select name="parent_id" id="menu-parent"><option value="0" data-root="1">Nenhum — menu principal</option><?php foreach($items as $p):?><option value="<?=(int)$p['id']?>" data-placement="<?=mh((string)$p['placement'])?>"><?=mh((string)$p['label'])?></option><?php endforeach;?></select><button type="button" class="button" id="menu-remove-parent" title="Remover vínculo e transformar em menu principal"><i class="fa fa-unlink" aria-hidden="true"></i> Sem pai</button></div><small id="menu-parent-state" class="help-text" aria-live="polite">Menu independente, sem menu pai.</small></label>

<label class="form-span">Caminho do arquivo ou URL HTTPS<input name="target_url" id="menu-target" placeholder="pages/configuracoes.php ou redes/servidor.php" maxlength="1024"><small class="help-text">Um menu pai pode ficar sem rota e sem URL.</small></label>
<label>Ícone<div class="arc-icon-field menu-icon-picker"><input type="hidden" name="icon" id="menu-icon" value="fa-circle"><button class="button menu-icon-preview" type="button" id="arc-open-icons" title="Trocar ícone" aria-label="Escolher ícone"><i id="menu-icon-preview" class="fa fa-circle" aria-hidden="true"></i></button><button class="button" type="button" id="menu-icon-choose" aria-label="Alterar ícone" title="Alterar ícone"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Alterar ícone</span></button></div></label>
<label>Ordem de exibição<input type="number" name="sort_order" id="menu-order" min="0" max="9999" value="100"></label>
<label>Grupo específico (opcional)<select name="group_id" id="menu-group"><option value="0">Todos os grupos autorizados</option><?php foreach($groups as $g):?><option value="<?=(int)$g['id']?>"><?=mh((string)$g['name'])?></option><?php endforeach;?></select></label>
<fieldset class="form-span"><legend>Perfis com acesso</legend><div class="roles"><?php foreach($roles as $key=>$title):?><label><input type="checkbox" name="roles[]" value="<?=mh($key)?>" <?= $key==='admin'?'checked':''?>> <?=mh($title)?></label><?php endforeach;?></div></fieldset>
<label class="form-span menu-status-control"><span>Status</span><span><input type="checkbox" name="enabled" id="menu-enabled" checked> Ativo (desmarque para desativar)</span></label>
</div><div class="dialog-actions"><button class="button" type="button" data-close-menu aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button class="button primary" type="submit"><i class="fa fa-save" aria-hidden="true"></i> Salvar menu</button></div></form></dialog>
<dialog id="arc-icons-dialog" class="arc-icons-dialog" aria-label="Selecionar ícone"><div class="arc-icons-head"><h2>Ícones Font Awesome</h2><button type="button" class="button" id="arc-close-icons" aria-label="Fechar"><i class="fa fa-times"></i></button></div><input id="arc-icon-search" type="search" placeholder="Buscar ícone por nome" aria-label="Buscar ícone"><div id="arc-icons-grid" class="arc-icons-grid"><?php foreach(arc_fa_icons() as $name):?><button type="button" class="arc-icon-choice" data-icon="fa-<?=mh($name)?>" title="<?=mh($name)?>"><i class="fa fa-<?=mh($name)?>" aria-hidden="true"></i><span><?=mh($name)?></span></button><?php endforeach;?></div></dialog>
<script>
(()=>{'use strict';
const dialog=document.getElementById('menu-dialog'),form=document.getElementById('menu-form'),table=document.getElementById('menu-table'),search=document.getElementById('menu-search');
const menuRouteFiles=<?=json_encode(array_map(static fn($v)=>$v[1],$routes),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const defaults={id:0,label:'',placement:'sidebar',sort_order:100,icon:'fa-circle',target_url:'',parent_id:0,group_id:0,enabled:1,access_roles:'admin'};
function syncIconPreview(){
 const input=document.getElementById('menu-icon'),preview=document.getElementById('menu-icon-preview');
 let name=String(input.value||'fa-circle').trim();if(!/^fa-[a-z0-9-]+$/.test(name))name='fa-circle';
 preview.className='fa '+name;preview.setAttribute('aria-label',name.replace(/^fa-/,''));
 const btn=document.getElementById('arc-open-icons');btn.title='Ícone selecionado: '+name.replace(/^fa-/,'').replace(/-/g,' ');
}
function syncParentState(){
 const p=document.getElementById('menu-parent');const isRoot=p.value==='0'||p.value==='';
 document.getElementById('menu-parent-state').textContent=isRoot?'Menu independente, sem menu pai.':'Submenu vinculado a: '+p.selectedOptions[0].textContent;
 const clear=document.getElementById('menu-remove-parent');clear.disabled=isRoot;clear.setAttribute('aria-disabled',String(isRoot));
}
function openMenu(record){
 const x={...defaults,...record};if(!x.target_url&&x.route_key)x.target_url=menuRouteFiles[x.route_key]||'';
 form.reset();for(const [key,id] of Object.entries({id:'menu-id',label:'menu-label',sort_order:'menu-order',icon:'menu-icon',target_url:'menu-target',group_id:'menu-group'})){document.getElementById(id).value=x[key]??'';}
 document.querySelectorAll('input[name="placements[]"]').forEach(c=>c.checked=c.value===x.placement);
 document.getElementById('menu-enabled').checked=!!Number(x.enabled);
 document.querySelectorAll('input[name="roles[]"]').forEach(c=>c.checked=String(x.access_roles).split(',').includes(c.value));
 document.getElementById('form-title').textContent=Number(x.id)?'Editar menu':'Criar menu';
 filterParents();
 const parent=document.getElementById('menu-parent');const wanted=String(x.parent_id||0);
 parent.value=[...parent.options].some(o=>o.value===wanted&&!o.disabled)?wanted:'0';
 syncParentState();syncIconPreview();dialog.showModal();
}
function filterParents(){
 const checks=[...document.querySelectorAll('input[name="placements[]"]:checked')].map(x=>x.value);
 const id=Number(document.getElementById('menu-id').value||0),parent=document.getElementById('menu-parent');
 const prior=parent.value,allowed=checks.length===1?checks[0]:null;
 const ancestry=new Map([...table.tBodies[0].querySelectorAll('tr[data-id]')].map(r=>[Number(r.dataset.id),Number(r.dataset.parent||0)]));
 for(const option of parent.options){
  if(option.dataset.root==='1'||option.value==='0'){option.disabled=false;option.hidden=false;continue;}
  const candidate=Number(option.value);let n=candidate,seen=new Set(),circular=false;
  while(n&&!seen.has(n)){if(n===id&&id>0){circular=true;break;}seen.add(n);n=ancestry.get(n)||0;}
  option.disabled=!allowed||option.dataset.placement!==allowed||candidate===id||circular;
  option.hidden=option.disabled;
 }
 parent.value=prior;
 if(!parent.selectedOptions.length||parent.selectedOptions[0].disabled)parent.value='0';
 syncParentState();
}
document.getElementById('menu-remove-parent').addEventListener('click',()=>{
 const parent=document.getElementById('menu-parent');parent.value='0';syncParentState();parent.focus();
});
document.getElementById('menu-parent').addEventListener('change',e=>{if(e.target.selectedOptions[0]?.disabled)e.target.value='0';syncParentState();});
document.getElementById('menu-icon-choose').addEventListener('click',()=>document.getElementById('arc-open-icons').click());
const iconInput=document.getElementById('menu-icon');
// O seletor global altera a propriedade .value, não o atributo HTML.
// Capturamos a seleção no grid e também sincronizamos ao fechar o modal.
const iconDialog=document.getElementById('arc-icons-dialog');
iconDialog.addEventListener('close',syncIconPreview);
document.getElementById('arc-icons-grid').addEventListener('click',e=>{const choice=e.target.closest('[data-icon]');if(choice){iconInput.value=choice.dataset.icon;syncIconPreview();}},true);
document.getElementById('menu-new').addEventListener('click',()=>openMenu());document.querySelectorAll('.edit').forEach(b=>b.addEventListener('click',()=>openMenu(JSON.parse(b.dataset.record))));document.querySelectorAll('[data-close-menu]').forEach(b=>b.addEventListener('click',()=>dialog.close()));dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});document.getElementById('menu-placements').addEventListener('change',filterParents);form.addEventListener('submit',e=>{if(!form.querySelector('input[name="placements[]"]:checked')){e.preventDefault();alert('Selecione ao menos uma posição.');}});
function filter(){updateTree();}search.addEventListener('input',filter);
const bulkForm=document.getElementById('menu-bulk-form'),selectAll=document.getElementById('menu-select-all'),bulkButton=document.getElementById('menu-bulk-apply'),bulkOperation=document.getElementById('menu-bulk-operation'),count=document.getElementById('menu-selected-count');
function selectedRows(){return [...table.querySelectorAll('tbody .menu-row-select:checked')];}
function syncSelection(){const selected=selectedRows();bulkForm.hidden=selected.length<2;count.textContent=selected.length+' selecionado'+(selected.length===1?'':'s');bulkButton.disabled=selected.length<2||!bulkOperation.value;const visible=[...table.querySelectorAll('tbody tr:not([hidden]) .menu-row-select')];selectAll.checked=visible.length>0&&visible.every(c=>c.checked);selectAll.indeterminate=visible.some(c=>c.checked)&&!selectAll.checked;}
selectAll.addEventListener('change',()=>{table.querySelectorAll('tbody tr:not([hidden]) .menu-row-select').forEach(c=>c.checked=selectAll.checked);syncSelection();});
table.addEventListener('change',e=>{if(e.target.classList.contains('menu-row-select'))syncSelection();});bulkOperation.addEventListener('change',syncSelection);
bulkForm.addEventListener('submit',e=>{bulkForm.querySelectorAll('input[data-bulk-id]').forEach(el=>el.remove());selectedRows().forEach(cb=>{const hidden=document.createElement('input');hidden.type='hidden';hidden.name='ids[]';hidden.value=cb.value;hidden.dataset.bulkId='1';bulkForm.appendChild(hidden);});if(selectedRows().length<2){e.preventDefault();return;}if(bulkOperation.value==='delete'&&!confirm('Excluir definitivamente os '+selectedRows().length+' menus selecionados?'))e.preventDefault();});
search.addEventListener('input',syncSelection);syncSelection();

// Expansão hierárquica: não elimina filhos do DOM nem altera permissões.
const collapsed=new Set([...table.tBodies[0].querySelectorAll('[data-expand]')].map(b=>Number(b.dataset.expand)));
for(const b of table.tBodies[0].querySelectorAll('[data-expand]')){b.setAttribute('aria-expanded','false');b.querySelector('i').className='fa fa-chevron-right';}
function updateTree(){
 const q=search.value.toLocaleLowerCase('pt-BR').trim(),rows=[...table.tBodies[0].querySelectorAll('tr[data-id]')],byId=new Map(rows.map(r=>[Number(r.dataset.id),r]));let shown=0;
 const matches=new Set();if(q){for(const tr of rows){if(tr.textContent.toLocaleLowerCase('pt-BR').includes(q)){let cur=tr,seen=new Set();while(cur&&!seen.has(cur.dataset.id)){seen.add(cur.dataset.id);matches.add(Number(cur.dataset.id));cur=byId.get(Number(cur.dataset.parent));}}}}
 for(const tr of rows){let hide=false,parent=Number(tr.dataset.parent),seen=new Set();if(q){hide=!matches.has(Number(tr.dataset.id));}else{while(parent&&!seen.has(parent)){seen.add(parent);if(collapsed.has(parent)){hide=true;break;}parent=Number(byId.get(parent)?.dataset.parent||0);}}
 tr.hidden=hide;if(!hide)shown++;
 }document.getElementById('menu-empty').hidden=shown!==0;
}table.addEventListener('click',e=>{const btn=e.target.closest('[data-expand]');if(!btn)return;const id=Number(btn.dataset.expand);if(collapsed.has(id))collapsed.delete(id);else collapsed.add(id);btn.setAttribute('aria-expanded',String(!collapsed.has(id)));btn.querySelector('i').className=collapsed.has(id)?'fa fa-chevron-right':'fa fa-chevron-down';updateTree();syncSelection();});
search.addEventListener('input',updateTree);updateTree();
let dragged=null;table.addEventListener('dragstart',e=>{const handle=e.target.closest('.menu-drag');if(!handle){e.preventDefault();return;}dragged=handle.closest('tr');dragged.classList.add('menu-dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',dragged.dataset.id);});
table.addEventListener('dragover',e=>{const target=e.target.closest('tr[data-id]');if(dragged&&target&&target!==dragged&&target.dataset.parent===dragged.dataset.parent&&target.dataset.placement===dragged.dataset.placement){e.preventDefault();target.classList.add('menu-drop-target');}});
table.addEventListener('dragleave',e=>{e.target.closest('tr')?.classList.remove('menu-drop-target');});
table.addEventListener('dragend',()=>{table.querySelectorAll('.menu-drop-target,.menu-dragging').forEach(r=>r.classList.remove('menu-drop-target','menu-dragging'));dragged=null;});
table.addEventListener('drop',async e=>{const target=e.target.closest('tr[data-id]');if(!dragged||!target||target===dragged||dragged.dataset.parent!==target.dataset.parent||dragged.dataset.placement!==target.dataset.placement)return;e.preventDefault();const source=dragged;const siblings=[...table.tBodies[0].rows].filter(r=>r.dataset.parent===source.dataset.parent&&r.dataset.placement===source.dataset.placement);const ids=siblings.map(r=>Number(r.dataset.id));const from=ids.indexOf(Number(source.dataset.id)),to=ids.indexOf(Number(target.dataset.id));ids.splice(to,0,ids.splice(from,1)[0]);const body=new URLSearchParams();body.set('_csrf',<?=json_encode(arc_csrf_token())?>);body.set('action','reorder');body.set('placement',source.dataset.placement);ids.forEach(id=>body.append('ordered_ids[]',id));try{const response=await fetch(form.action,{method:'POST',body,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.ok)throw Error('Falha ao salvar a ordem.');location.reload();}catch(err){alert('Não foi possível salvar a nova posição. '+err.message);} });
let current='',ascending=true;
function reorderTreeForView(key,asc){
 const body=table.tBodies[0],rows=[...body.querySelectorAll('tr[data-id]')],byId=new Map(rows.map(r=>[Number(r.dataset.id),r]));
 const children=new Map();
 for(const row of rows){const original=Number(row.dataset.parent||0),pid=byId.has(original)&&byId.get(original).dataset.placement===row.dataset.placement?original:0,group=row.dataset.placement+'|'+pid; if(!children.has(group))children.set(group,[]);children.get(group).push(row);}
 const compare=(a,b)=>{const va=a.dataset[key]||'',vb=b.dataset[key]||'';const r=key==='status'?Number(va)-Number(vb):va.localeCompare(vb,'pt-BR',{numeric:true,sensitivity:'base'});return asc?r:-r;};
 const visited=new Set();function append(place,parent){const siblings=children.get(place+'|'+parent)||[];siblings.sort(compare);for(const row of siblings){const id=Number(row.dataset.id);if(visited.has(id))continue;visited.add(id);body.appendChild(row);append(place,id);}}
 for(const place of [...new Set(rows.map(r=>r.dataset.placement))])append(place,0);
 rows.forEach(r=>{if(!visited.has(Number(r.dataset.id)))body.appendChild(r);});updateTree();
}
table.querySelectorAll('[data-sort]').forEach(button=>button.addEventListener('click',()=>{const key=button.dataset.sort;if(current===key)ascending=!ascending;else{current=key;ascending=true;}reorderTreeForView(key,ascending);table.querySelectorAll('[data-sort]').forEach(b=>b.setAttribute('aria-sort',b===button?(ascending?'ascending':'descending'):'none'));}));
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
