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
/**
 * Inventário de páginas navegáveis. Arquivos auxiliares, autenticação, instalação,
 * endpoints e rotinas automáticas nunca se tornam páginas de menu.
 */
function arc_navigation_catalog(): array {
    $root = dirname(__DIR__);
    $catalog = [];
    $skip = [
        'admin' => ['log-retention-cron.php'],
        'pages' => ['checkout.php','install.php','renovar-senha.php'],
        'redes' => [],
    ];
    $names = [
        'admin/agentwin.php'=>'Agentes', 'admin/clean.php'=>'Clean (admin)',
        'admin/versao.php'=>'Versões', 'admin/usuarios.php'=>'Administradores',
        'pages/assinatura.php'=>'Planos', 'pages/atendimentos.php'=>'Suporte',
        'pages/cobrancas.php'=>'Cobranças', 'pages/atividades.php'=>'Atividades',
        'pages/painel.php'=>'Painel', 'pages/perfil.php'=>'Minha conta',
        'pages/preferencias.php'=>'Preferências',
        'redes/relatorios.php'=>'Relatórios', 'redes/clean.php'=>'Clean',
        'redes/arquivos.php'=>'Arquivos', 'redes/remoto.php'=>'Acesso remoto',
        'redes/chamados.php'=>'Chamados', 'redes/agenda.php'=>'Agenda',
        'redes/cadastros.php'=>'Cadastros', 'redes/liberacao.php'=>'Liberações',
    ];
    $permissions = [
        'painel'=>'monitor','servidor'=>'monitor','velocidade'=>'connection',
        'dispositivos'=>'devices','mapa'=>'topology',
        'suporte'=>'tools','clean'=>'tools','remoto'=>'tools',
        'arquivos'=>'tools','chamados'=>'tools','agenda'=>'tools',
        'relatorios'=>'reports','cadastros'=>'settings',
        'liberacao'=>'settings','usuarios'=>'settings',
        'permissoes'=>'settings','preferencias'=>'settings',
    ];
    foreach (['admin','pages','redes'] as $folder) {
        foreach (glob($root.'/'.$folder.'/*.php') ?: [] as $file) {
            $base=basename($file);
            if (str_starts_with($base,'_') || in_array($base,$skip[$folder],true) || !is_file($file) || filesize($file)===0) continue;
            $target=$folder.'/'.$base;
            $section=basename($base,'.php');
            $catalog[$target]=[
                'target'=>$target,
                'label'=>$names[$target]??ucwords(str_replace(['-','_'],' ',$section)),
                'placement'=>$folder==='redes'?'header':'sidebar',
                'context'=>$folder==='redes'?'network':'global',
                'roles'=>$folder==='admin'?'admin':($folder==='redes'?'manager,user,viewer':'admin,manager,user,viewer'),
                'permission'=>$folder==='redes'?($permissions[$section]??'settings'):null,
            ];
        }
    }
    return $catalog;
}

/** Relatório não altera preferências nem reativa itens desativados pelo administrador. */
function arc_navigation_audit(PDO $pdo): array {
    $records=$pdo->query('SELECT id,label,target_url,enabled,placement,context,access_roles FROM '.arc_table('navigation_items').' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $mapped=[];$invalid=[];
    foreach ($records as $row) {
        $destination=(string)($row['target_url']??'');
        $path=(string)parse_url($destination,PHP_URL_PATH);
        if ($path!=='' && preg_match('~^(?:admin|pages|redes)/[a-z][a-z0-9_-]*\.php$~iD',$path)) {
            $mapped[$path][]=$row;
            if (!arc_navigation_target($destination)) $invalid[]=$row;
            if (str_starts_with($path,'admin/') && (string)$row['access_roles']!=='admin') $invalid[]=$row;
            if (str_starts_with($path,'redes/') && (string)$row['context']!=='network' && $path!=='redes/relatorios.php') $invalid[]=$row;
        }
    }
    $missing=[];$inactive=[];
    foreach (arc_navigation_catalog() as $target=>$spec) {
        if (empty($mapped[$target])) $missing[]=$spec;
        elseif (!array_filter($mapped[$target],static fn(array $item):bool=>(int)$item['enabled']===1)) $inactive[]=$spec;
    }
    return ['missing'=>$missing,'inactive'=>$inactive,'invalid'=>$invalid,'total'=>count($records)];
}

/**
 * Sincronização explícita: cadastra apenas páginas sem nenhum registro de menu.
 * Itens previamente desativados permanecem desativados (respeito ao administrador).
 * Novas páginas ficam visíveis somente sob as permissões do seu contexto.
 */
function arc_navigation_discover(PDO $pdo): array {
    $table=arc_table('navigation_items');
    $exists=$pdo->prepare('SELECT id FROM '.$table.' WHERE target_url=? LIMIT 1');
    $insert=$pdo->prepare("INSERT INTO ".$table." (label,route_key,placement,context,target_url,icon,access_roles,sort_order,enabled,agent_permission,devices) VALUES (?,'',?,?,?,?,?,?,1,?,'desktop,tablet,mobile')");
    $added=0;
    foreach (arc_navigation_catalog() as $spec) {
        $exists->execute([$spec['target']]);
        if ($exists->fetchColumn()) continue;
        $name=substr($spec['target'],0,strpos($spec['target'],'/'));
        $icon=$name==='admin'?'fa-cog':($name==='redes'?'fa-desktop':'fa-file-o');
        $insert->execute([$spec['label'],$spec['placement'],$spec['context'],$spec['target'],$icon,$spec['roles'],900+$added,$spec['permission']]);
        $added++;
    }
    return [$added,0,0];
}
