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
    $role=arc_v15_user_role($pdo);$group=null;$result=[];$visibleRoutes=[];
    foreach($q as $item){
        if(!in_array($role,explode(',',$item['access_roles']),true) && !(arc_is_admin() && (string)$item['context']==='network'))continue;
        $target=trim((string)$item['target_url']);
        if(!arc_navigation_target($target)||str_starts_with($target,'admin/')&&!arc_is_admin())continue;
        if($item['group_id'] && !arc_is_admin()){if($group===null)$group=arc_v16_user_group($pdo,(int)(arc_user_id()??0));if((int)$group!==(int)$item['group_id'])continue;}
        if($item['context']==='network'){
            if(!$networkId)continue;
            if(!arc_is_admin() && $item['gestor_permission']&&!in_array($item['gestor_permission'],arc_v16_allowed_tabs($pdo,(int)(arc_user_id()??0)),true))continue;
            $target.=(str_contains($target,'?')?'&':'?').'network='.$networkId;
            if(!empty($_GET['gestor']))$target.='&gestor='.(int)$_GET['gestor'];
        }
        // Uma rota visível por superfície/contexto para o perfil atual.
        // Não altera registros nem privilégios no banco.
        if ($target!=='') {
            $routeKey=strtolower(trim(str_replace('\\','/',$target)));
            // A URL de rede já inclui network/gestor; mantemos sua identidade.
            if (isset($visibleRoutes[$routeKey])) continue;
            $visibleRoutes[$routeKey]=true;
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
        'admin/gestorwin.php'=>'Gestores', 'admin/clean.php'=>'Clean (admin)',
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

/**
 * Diagnóstico de permissões sem alterar menus ou privilégios automaticamente.
 * Apenas guardas administrativos explícitos são considerados provas de exclusividade;
 * outras restrições dinâmicas (por rede, grupo ou titularidade) exigem revisão humana.
 */
function arc_navigation_route_admin_only(string $path): bool {
    if (str_starts_with($path, 'admin/')) return true;
    if (!preg_match('~^(?:pages|redes)/[a-z][a-z0-9_-]*\.php$~iD', $path)) return false;
    $file = dirname(__DIR__) . '/' . $path;
    if (!is_file($file) || filesize($file) === 0 || filesize($file) > 500000) return false;
    $source = file_get_contents($file);
    if (!is_string($source)) return false;
    // Reconhece apenas bloqueios diretos de toda a página, e não verificações
    // de administrador em botões, widgets ou trechos opcionais.
    $prefix = substr($source, 0, 2400);
    return (bool)preg_match('~\barc_require_admin\s*\(\s*\)~', $prefix)
        || (bool)preg_match('~\bif\s*\(\s*!\s*arc_is_admin\s*\(\s*\)\s*\)\s*\{[^}]{0,500}\b(?:exit|die)\s*\(~s', $prefix);
}

/** Padrões de ícones para rotas cadastradas. Nunca cria menus. */
function arc_navigation_icon_defaults(): array {
    return [
        'pages/painel.php'=>'fa-th-large', 'pages/perfil.php'=>'fa-user-circle-o',
        'pages/preferencias.php'=>'fa-sliders', 'pages/atividades.php'=>'fa-history',
        'pages/atendimentos.php'=>'fa-life-ring', 'pages/assinatura.php'=>'fa-credit-card',
        'pages/cobrancas.php'=>'fa-money', 'pages/comercial.php'=>'fa-line-chart',
        'pages/usuarios.php'=>'fa-users', 'pages/permissoes.php'=>'fa-lock',
        'admin/menus.php'=>'fa-list-alt', 'admin/usuarios.php'=>'fa-user-secret',
        'admin/permissoes.php'=>'fa-lock', 'admin/configuracoes.php'=>'fa-cogs',
        'admin/gestorwin.php'=>'fa-cubes', 'admin/versao.php'=>'fa-code-fork',
        'admin/clientes.php'=>'fa-users', 'admin/comercial.php'=>'fa-credit-card',
        'admin/clean.php'=>'fa-magic',
        'redes/painel.php'=>'fa-th-large', 'redes/servidor.php'=>'fa-server',
        'redes/velocidade.php'=>'fa-tachometer', 'redes/dispositivos.php'=>'fa-desktop',
        'redes/mapa.php'=>'fa-sitemap', 'redes/suporte.php'=>'fa-life-ring',
        'redes/usuarios.php'=>'fa-users', 'redes/permissoes.php'=>'fa-lock',
        'redes/preferencias.php'=>'fa-sliders', 'redes/programas.php'=>'fa-cubes',
        'redes/backup.php'=>'fa-cloud-upload', 'redes/clean.php'=>'fa-magic',
        'redes/remoto.php'=>'fa-eye', 'redes/arquivos.php'=>'fa-folder-open',
        'redes/chamados.php'=>'fa-ticket', 'redes/agenda.php'=>'fa-calendar',
        'redes/cadastros.php'=>'fa-address-book', 'redes/liberacao.php'=>'fa-unlock-alt',
        'redes/relatorios.php'=>'fa-bar-chart',
    ];
}
/** Atualiza somente ícones genéricos, vazios ou não existentes no catálogo instalado. */
function arc_navigation_sync_icons(PDO $pdo, array $availableIcons): int {
    $defaults=arc_navigation_icon_defaults();$generic=['','fa-file-o','fa-desktop','fa-circle','fa-circle-o','circle'];
    $rows=$pdo->query('SELECT id,target_url,icon FROM '.arc_table('navigation_items'))->fetchAll(PDO::FETCH_ASSOC);
    $update=$pdo->prepare('UPDATE '.arc_table('navigation_items').' SET icon=? WHERE id=?');$count=0;
    $pdo->beginTransaction();
    try {
        foreach($rows as $row){
            $path=strtolower((string)parse_url((string)$row['target_url'],PHP_URL_PATH));
            $target=$defaults[$path]??null;
            if(!$target||!in_array(substr($target,3),$availableIcons,true))continue;
            $current=(string)$row['icon'];
            if($current===$target||(!in_array($current,$generic,true)&&in_array(preg_replace('/^fa-/','',$current),$availableIcons,true)))continue;
            $update->execute([$target,(int)$row['id']]);$count+=$update->rowCount();
        }
        $pdo->commit();
    } catch(Throwable $e) {$pdo->rollBack();throw $e;}
    return $count;
}
/** Menus com mesmo destino em um mesmo local podem se sobrepor por perfil e dispositivo. */
function arc_navigation_overlap(array $previous,array $current): array {
    if ((string)$previous['placement']!==(string)$current['placement'] || (string)$previous['context']!==(string)$current['context'])return [];
    $a=strtolower(trim(str_replace('\\','/',(string)($previous['target_url']??''))));
    $b=strtolower(trim(str_replace('\\','/',(string)($current['target_url']??''))));
    if ($a===''||$a!==$b) return [];
    // Grupos diferentes só são mutuamente exclusivos quando ambos estão definidos.
    $ga=(int)($previous['group_id']??0);$gb=(int)($current['group_id']??0);
    if ($ga>0&&$gb>0&&$ga!==$gb)return [];
    $split=static function(string $value):array {
        return array_values(array_unique(array_filter(array_map('trim',explode(',',strtolower($value))))));
    };
    $roles=array_values(array_intersect($split((string)$previous['access_roles']),$split((string)$current['access_roles'])));
    // Administradores globais enxergam rotas de rede mesmo quando o cadastro
    // atribui perfis operacionais diferentes; isto também é uma sobreposição.
    if ((string)$previous['context']==='network' && !in_array('admin',$roles,true)) $roles[]='admin';
    $devices=array_values(array_intersect($split((string)$previous['devices']),$split((string)$current['devices'])));
    return ($roles&&$devices)?['roles'=>$roles,'devices'=>$devices]:[];
}

/**
 * Auditoria de navegação somente leitura. "invalid" significa configuração
 * divergente, não prova de acesso indevido: o controle efetivo continua no backend.
 */
function arc_navigation_audit(PDO $pdo): array {
    $records=$pdo->query('SELECT id,label,target_url,enabled,placement,context,access_roles,gestor_permission,group_id,parent_id,devices FROM '.arc_table('navigation_items').' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $mapped=[];$invalid=[];$orphans=[];$inactive=[];$prior=[];$duplicates=[];
    $catalog=arc_navigation_catalog();
    $knownIds=array_fill_keys(array_map(static fn(array $r):int=>(int)$r['id'],$records),true);
    foreach ($records as $row) {
        $destination=trim((string)($row['target_url']??''));
        $path=(string)parse_url($destination,PHP_URL_PATH);
        $id=(int)$row['id'];$enabled=(int)$row['enabled']===1;
        if (!$enabled) $inactive[]=['id'=>$id,'label'=>$row['label'],'target'=>$destination,'placement'=>$row['placement']];
        // Detecta sobreposição real (não exige conjuntos idênticos de perfis).
        // Registramos o par por ID para que a decisão de consolidar seja consciente.
        if ($enabled && $destination!=='') {
            foreach($prior as $before){
                $overlap=arc_navigation_overlap($before,$row);
                if(!$overlap)continue;
                $dup=$row;$dup['target']=$destination;
                $dup['canonical_id']=(int)$before['id'];
                $dup['canonical_label']=(string)$before['label'];
                $dup['overlap_roles']=$overlap['roles'];
                $dup['overlap_devices']=$overlap['devices'];
                $dup['safe_disable']=((int)($before['parent_id']??0)===(int)($row['parent_id']??0))
                    && ((int)($before['group_id']??0)===(int)($row['group_id']??0))
                    && ((string)($before['gestor_permission']??'')===(string)($row['gestor_permission']??''))
                    && !array_diff(explode(',',strtolower((string)$row['access_roles'])),explode(',',strtolower((string)$before['access_roles'])))
                    && !array_diff(explode(',',strtolower((string)$row['devices'])),explode(',',strtolower((string)$before['devices'])));
                $duplicates[]=$dup;
            }
            $prior[]=$row;
        }
        $issues=[];
        $roles=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($row['access_roles']??''))))));
        foreach ($roles as $role) if (!in_array($role,['admin','manager','user','viewer'],true)) $issues[]='Perfil desconhecido: '.$role;
        if (!$roles) $issues[]='Nenhum perfil autorizado';
        $parent=(int)($row['parent_id']??0);
        if ($parent>0 && !isset($knownIds[$parent])) $issues[]='Menu superior inexistente';
        if ($parent===$id) $issues[]='Menu não pode ser seu próprio superior';
        if ($path!=='' && preg_match('~^(?:admin|pages|redes)/[a-z][a-z0-9_-]*\.php$~iD',$path)) {
            $mapped[$path][]=$row;
            if (!arc_navigation_target($destination)) {
                $row['target']=$destination;
                $orphans[]=$row;
            } else {
                if (arc_navigation_route_admin_only($path) && array_diff($roles,['admin'])) $issues[]='Página exclusiva de administrador vinculada a outros perfis';
                if (str_starts_with($path,'redes/') && (string)$row['context']!=='network' && $path!=='redes/relatorios.php') $issues[]='Página de rede fora do contexto de rede';
                if (str_starts_with($path,'redes/') && empty($row['gestor_permission'])) $issues[]='Menu de rede sem permissão de aba especificada';
                if (isset($catalog[$path]) && $catalog[$path]['context']==='global' && (string)$row['context']!=='global') $issues[]='Página global cadastrada no contexto de rede';
            }
        } elseif ($destination!=='' && !arc_navigation_target($destination)) {
            $issues[]='Endereço do menu inválido';
        }
        if ($issues) {
            $row['target']=$destination;
            $row['issues']=array_values(array_unique($issues));
            $invalid[]=$row;
        }
    }
    // Arquivos PHP não são menus. Só registros do banco participam da conferência.
    $missing=[];
    return [
        'missing'=>$missing,'orphans'=>$orphans,'inactive'=>$inactive,'duplicates'=>$duplicates,
        'invalid'=>$invalid,
        'alerts'=>count($missing)+count($orphans)+count($duplicates)+count($invalid),
        'total'=>count($records),
    ];
}

