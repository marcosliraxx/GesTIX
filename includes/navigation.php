<?php
/* Caminho: includes/navigation.php */
declare(strict_types=1);

function arc_navigation_schema(PDO $pdo): void {
    // Schema is applied once by arc_ensure_user_schema, never during menu rendering.
}
function arc_navigation_routes(): array {
    $rows=arc_db()->query('SELECT route_key,label,target_url FROM '.arc_table('navigation_items')." WHERE route_key<>''")->fetchAll();$result=[];
    foreach($rows as $row)$result[$row['route_key']]=[$row['label'],$row['target_url']];return $result;
}
function arc_navigation_target(string $target): bool {
    if($target==='')return true;
    if(preg_match('~^https://[a-z0-9.-]+(?::[0-9]{2,5})?(?:[/?#][^\s<>"\']*)?$~iD',$target))return true;
    if(!preg_match('~^(?:admin|pages|redes)/[a-z][a-z0-9_-]*\.php(?:\?[a-z0-9_=&%-]*)?(?:#[a-z0-9_-]+)?$~iD',$target))return false;
    $path=(string)parse_url($target,PHP_URL_PATH);return is_file(dirname(__DIR__).'/'.$path)&&filesize(dirname(__DIR__).'/'.$path)>0;
}
function arc_navigation_items(PDO $pdo,string $placement,?int $networkId=null): array {
    if(!in_array($placement,['sidebar','header','user','footer','more','mobile'],true))return [];
    $where=$placement==='mobile'?'mobile_footer=1':'placement=?';$params=$placement==='mobile'?[]:[$placement];
    if($placement!=='mobile'){$where.=' AND context=?';$params[]=$networkId?'network':'global';}
    $q=$pdo->prepare('SELECT * FROM '.arc_table('navigation_items').' WHERE enabled=1 AND '.$where.' ORDER BY sort_order,id');$q->execute($params);
    $role=arc_v15_user_role($pdo);$group=null;$result=[];
    foreach($q as $item){
        if(!in_array($role,explode(',',$item['access_roles']),true))continue;
        $target=trim((string)$item['target_url']);
        if(!arc_navigation_target($target)||str_starts_with($target,'admin/')&&!arc_is_admin())continue;
        if($item['group_id']){if($group===null)$group=arc_v16_user_group($pdo,(int)(arc_user_id()??0));if((int)$group!==(int)$item['group_id'])continue;}
        if($item['context']==='network'){
            if(!$networkId)continue;
            if($item['agent_permission']&&!in_array($item['agent_permission'],arc_v16_allowed_tabs($pdo,(int)(arc_user_id()??0)),true))continue;
            $target.=(str_contains($target,'?')?'&':'?').'network='.$networkId;
            if(!empty($_GET['agent']))$target.='&agent='.(int)$_GET['agent'];
        }
        $item['id']=(int)$item['id'];$item['parent_id']=(int)($item['parent_id']??0);$item['url']=$target;$item['key']=$item['route_key']?:'menu_file_'.$item['id'];
        $item['device_class']=arc_navigation_device_class((string)$item['devices']);$result[]=$item;
    }
    return $result;
}
function arc_navigation_device_class(string $devices): string {
    $items=explode(',',$devices);return implode(' ',array_map(static fn($d)=>in_array($d,$items,true)?'arc-nav-'.$d:'arc-nav-no-'.$d,['desktop','tablet','mobile']));
}
function arc_navigation_home(PDO $pdo): string {
    $items=arc_navigation_items($pdo,'mobile');foreach($items as $item)if($item['mobile_primary'])return $item['url'];
    foreach(arc_navigation_items($pdo,'sidebar') as $item)if($item['url']!=='')return $item['url'];return '#';
}
function arc_navigation_more(PDO $pdo,array $dockIds=[]): array {
    $items=[];$seen=[];
    foreach(['more','sidebar','header','user','footer'] as $place)foreach(arc_navigation_items($pdo,$place) as $item){
        if($item['url']===''||isset($seen[$item['url']]))continue;
        $seen[$item['url']]=1;if(in_array($item['id'],$dockIds,true))$item['device_class'].=' arc-more-docked';$items[]=$item;
    }return $items;
}
function arc_navigation_discover(PDO $pdo): array {
    $added=0;$table=arc_table('navigation_items');
    $find=$pdo->prepare('SELECT id FROM '.$table.' WHERE target_url=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO '.$table.' (label,route_key,placement,context,target_url,icon,access_roles,sort_order,enabled) VALUES (?,\'\',\'sidebar\',\'global\',?,\'fa-file-o\',?,999,0)');
    foreach(['admin','pages'] as $folder)foreach(glob(dirname(__DIR__).'/'.$folder.'/*.php')?:[] as $path){
        $name=basename($path);if(in_array($name,['index.php','install.php','checkout.php','renovar-senha.php'],true))continue;
        $target=$folder.'/'.$name;$find->execute([$target]);if($find->fetchColumn())continue;
        $label=ucwords(str_replace(['-','_'], ' ',basename($name,'.php')));$source=file_get_contents($path);if(preg_match('/\$ARCPageTitle\s*=\s*[\'\"]([^\'\"]+)/',$source,$m))$label=$m[1];
        $insert->execute([mb_substr($label,0,80),$target,$folder==='admin'?'admin':'admin,manager,user,viewer']);$added++;
    }return [$added,0,0];
}
