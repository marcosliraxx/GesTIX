<?php
/* Caminho: admin/permissoes.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();arc_ensure_user_schema($pdo);
if(!arc_is_admin()){http_response_code(403);exit('Acesso restrito.');}
arc_v16_group_schema($pdo);
arc_navigation_schema($pdo);
$accessProfiles=['admin'=>'Administrador','manager'=>'Gestor','user'=>'Usuário','viewer'=>'Visualizador'];
$activeMainTab=(string)($_GET['tab']??'grupos');if(!in_array($activeMainTab,['grupos','acessos','agentes','usuarios'],true))$activeMainTab='grupos';
$navTable=arc_table('navigation_items');
$groups=arc_table('permission_groups');$members=arc_table('permission_group_members');
$error='';$tabs=arc_v16_agent_tabs();
if($_SERVER['REQUEST_METHOD']==='POST'){
    arc_verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_profile_access'){
            $profile=(string)($_POST['profile']??'');
            if(!array_key_exists($profile,$accessProfiles))throw new RuntimeException('Perfil inválido.');
            $selected=array_unique(array_filter(array_map('intval',(array)($_POST['menu_ids']??[])),static fn($v)=>$v>0));
            $navRows=$pdo->query('SELECT id,access_roles FROM '.$navTable)->fetchAll(PDO::FETCH_ASSOC);
            $pdo->beginTransaction();
            $updateRole=$pdo->prepare('UPDATE '.$navTable.' SET access_roles=? WHERE id=?');
            foreach($navRows as $nav){
                $id=(int)$nav['id'];
                $existing=array_values(array_intersect(array_keys($accessProfiles),array_map('trim',explode(',',(string)$nav['access_roles']))));
                $existing=array_values(array_diff($existing,[$profile]));
                if(in_array($id,$selected,true))$existing[]=$profile;
                $existing=array_values(array_unique($existing));
                $updateRole->execute([implode(',',$existing),$id]);
            }
            $pdo->commit();
        }elseif($action==='save'){
            $id=(int)($_POST['id']??0);
            $name=mb_substr(trim((string)($_POST['name']??'')),0,90);
            $description=mb_substr(trim((string)($_POST['description']??'')),0,255);
            $selected=array_values(array_intersect(array_keys($tabs),(array)($_POST['tabs']??[])));
            if($name==='')throw new RuntimeException('Informe o nome do grupo.');
            if($id>0){$q=$pdo->prepare('UPDATE '.$groups.' SET name=?,description=?,agent_tabs=? WHERE id=?');$q->execute([$name,$description,implode(',',$selected),$id]);}
            else{$q=$pdo->prepare('INSERT INTO '.$groups.' (name,description,agent_tabs) VALUES (?,?,?)');$q->execute([$name,$description,implode(',',$selected)]);}
        }elseif($action==='bulk_delete'){
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])),static fn($n)=>$n>0)));
            if(!$ids)throw new RuntimeException('Selecione pelo menos um grupo.');
            $pdo->beginTransaction();
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare('DELETE FROM '.$members.' WHERE group_id IN ('.$marks.')')->execute($ids);
            $pdo->prepare('DELETE FROM '.$groups.' WHERE id IN ('.$marks.')')->execute($ids);
            $pdo->commit();
        }elseif($action==='assign'){
            $account=(int)($_POST['account_id']??0);$group=(int)($_POST['group_id']??0);
            $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$account]);
            if(!$q->fetchColumn())throw new RuntimeException('Conta inexistente.');
            if($group===0){$pdo->prepare('DELETE FROM '.$members.' WHERE account_id=?')->execute([$account]);}
            else{
                $q=$pdo->prepare('SELECT id FROM '.$groups.' WHERE id=?');$q->execute([$group]);
                if(!$q->fetchColumn())throw new RuntimeException('Grupo inexistente.');
                $pdo->prepare('INSERT INTO '.$members.' (account_id,group_id) VALUES (?,?) ON DUPLICATE KEY UPDATE group_id=VALUES(group_id)')->execute([$account,$group]);
            }
        }elseif($action==='delete'){
            $id=(int)($_POST['id']??0);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM '.$members.' WHERE group_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM '.$groups.' WHERE id=?')->execute([$id]);
            $pdo->commit();
        }else throw new RuntimeException('Operação inválida.');
        header('Location: '.arc_workspace_base_url().'admin/permissoes.php?saved=1&tab='.urlencode(in_array($action,['assign'],true)?'usuarios':($action==='save_profile_access'?'acessos':(string)($_POST['return_tab']??'grupos'))),true,303);exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$all=$pdo->query('SELECT * FROM '.$groups.' ORDER BY name')->fetchAll();
$navigation=$pdo->query('SELECT id,label,placement,target_url,access_roles,enabled FROM '.$navTable.' ORDER BY placement,sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
$profileMenuCounts=array_fill_keys(array_keys($accessProfiles),0);
foreach($navigation as $nav){foreach(array_keys($accessProfiles) as $profile){if(in_array($profile,array_map('trim',explode(',',(string)$nav['access_roles'])),true))$profileMenuCounts[$profile]++;}}
$accounts=$pdo->query('SELECT a.id,a.display_name,a.email,a.access_role,a.account_status,m.group_id FROM '.arc_table('user_accounts').' a LEFT JOIN '.$members.' m ON m.account_id=a.id ORDER BY a.id DESC LIMIT 500')->fetchAll();
function gh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

$counts=[];foreach($accounts as $account){$gid=(int)($account['group_id']??0);if($gid>0)$counts[$gid]=($counts[$gid]??0)+1;}
$ARCPageBodyClass='arc-page-grupos';$ARCMode='app';$ARCPageTitle='Permissões, grupos e perfis';$ARCActiveNav='groups';$ARCHeaderActions='';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
$base=arc_workspace_base_url();
?>
<style>
.gp-main{--gp-accent:var(--accent-color,var(--accent,#2373ce));height:calc(100dvh - 110px);min-height:480px;display:flex;flex-direction:column;gap:12px;overflow:hidden}.gp-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;min-width:0}.gp-toolbar input{min-height:35px;border:1px solid var(--border,#d9e1ea);border-radius:9px;background:var(--surface,#fff);padding:0 10px;color:inherit}.gp-toolbar select{min-height:35px;border:1px solid var(--border,#d9e1ea);border-radius:9px;background:var(--surface,#fff);padding:0 10px;color:inherit}.gp-search{flex:1;min-width:125px;max-width:320px}.gp-filter{max-width:170px}.gp-stat{display:flex;align-items:center;gap:6px;border:1px solid var(--border,#d9e1ea);background:var(--surface,#fff);border-radius:9px;padding:6px 9px;font-size:12px;white-space:nowrap}.gp-stat b{font-size:15px;color:var(--gp-accent)}.gp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 12px;border:0;border-radius:9px;cursor:pointer;background:var(--gp-accent);color:var(--text);font:inherit;font-size:12px;font-weight:650;white-space:nowrap}.gp-btn.secondary{background:var(--surface,#fff);color:inherit;border:1px solid var(--border,#d9e1ea)}.gp-btn.danger{background:#b93c50}.gp-spacer{flex:1}.gp-bulk{display:none;align-items:center;gap:7px}.gp-bulk.visible{display:flex}.gp-grid{flex:1;min-height:0;overflow:auto;border:1px solid var(--border,#d9e1ea);border-radius:12px;background:var(--surface,#fff)}.gp-table{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}.gp-table thead th{position:sticky;top:0;z-index:2;background:var(--surface,#fff);border-bottom:1px solid var(--border,#d9e1ea);padding:12px 10px;text-align:left;white-space:nowrap;cursor:pointer}.gp-table td{padding:10px;border-bottom:1px solid var(--border,#edf0f3);vertical-align:middle}.gp-table tbody tr{cursor:pointer}.gp-table tbody tr:hover{background:color-mix(in srgb, var(--surface-alt) 7%, transparent)}.gp-table .gp-check{width:35px;text-align:center;cursor:default}.gp-table .gp-muted{color:var(--text-muted,#7a8797);font-size:11px}.gp-table .gp-title{font-weight:700;font-size:13px}.gp-pill{display:inline-block;border-radius:30px;padding:4px 8px;background:color-mix(in srgb,var(--gp-accent) 12%,transparent);color:var(--gp-accent);font-weight:650}.gp-notice{padding:10px 14px;border-radius:12px;background:color-mix(in srgb,var(--gp-accent) 11%,transparent);border:1px solid color-mix(in srgb,var(--gp-accent) 30%,transparent);backdrop-filter:blur(14px);font-size:13px}.gp-notice.error{border-color:#c75465;color:#b83950}.gp-overlay{display:none;position:fixed;inset:0;z-index:10020;background:rgba(12,22,40,.52);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px}.gp-overlay.open{display:flex!important}.gp-modal{display:flex;flex-direction:column;width:min(860px,100%);height:min(690px,90dvh);background:var(--surface,#fff);color:inherit;border-radius:16px;box-shadow:0 22px 65px #0004;overflow:hidden}.gp-modal-head{display:flex;align-items:center;justify-content:space-between;padding:15px 19px;border-bottom:1px solid var(--border,#d9e1ea)}.gp-modal-head h2{font-size:17px;margin:0}.gp-tabs{display:flex;gap:4px;padding:9px 16px 0;border-bottom:1px solid var(--border,#d9e1ea)}.gp-tab{border:0;background:transparent;color:inherit;padding:11px 14px;cursor:pointer;border-bottom:2px solid transparent}.gp-tab.active{color:var(--gp-accent);border-color:var(--gp-accent);font-weight:700}.gp-modal-body{flex:1;min-height:0;overflow:auto;padding:18px}.gp-pane{display:none}.gp-pane.active{display:block}.gp-pane[hidden]{display:none!important}.gp-member[hidden]{display:none!important}.gp-tab[hidden]{display:none!important}.gp-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.gp-field{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:650}.gp-field.wide{grid-column:1/-1}.gp-field input{width:100%;border:1px solid var(--border,#d9e1ea);border-radius:9px;padding:10px;background:var(--surface,#fff);color:inherit;font:inherit;font-weight:400}.gp-field select{width:100%;border:1px solid var(--border,#d9e1ea);border-radius:9px;padding:10px;background:var(--surface,#fff);color:inherit;font:inherit;font-weight:400}.gp-field textarea{width:100%;border:1px solid var(--border,#d9e1ea);border-radius:9px;padding:10px;background:var(--surface,#fff);color:inherit;font:inherit;font-weight:400;min-height:85px;resize:vertical}.gp-perms{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:9px}.gp-perms label{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border,#d9e1ea);border-radius:9px;font-size:12px}.gp-modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--border,#d9e1ea)}.gp-member-list{display:grid;gap:7px}.gp-member{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid var(--border,#d9e1ea);padding:10px;border-radius:9px}.gp-member select{max-width:200px}.gp-empty{padding:28px;text-align:center;color:var(--text-muted,#7a8797)}@media(max-width:850px){.gp-toolbar{flex-wrap:wrap}.gp-main{height:calc(100dvh - 85px)}.gp-search{max-width:none}.gp-fields{grid-template-columns:1fr}.gp-stat{display:none}}
</style>
<style>
.gp-profile-intro{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;font-size:13px}.gp-section-heading{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;font-size:13px}.gp-profile-intro span{font-size:11px;color:var(--text-muted,#748194)}.gp-section-heading span{font-size:11px;color:var(--text-muted,#748194)}.gp-profiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.gp-profile-card{display:flex;align-items:center;text-align:left;gap:10px;padding:11px;border:1px solid var(--border,#d9e1ea);border-radius:12px;background:var(--surface,#fff);color:inherit;cursor:pointer}.gp-profile-card:hover{border-color:var(--gp-accent);box-shadow:0 2px 12px #0001}.gp-profile-icon{display:grid;place-items:center;width:32px;height:32px;flex-shrink:0;border-radius:9px;background:color-mix(in srgb,var(--gp-accent) 14%,transparent);color:var(--gp-accent);font-weight:800}.gp-profile-card strong{display:block;font-size:12px}.gp-profile-card small{display:block;font-size:10px;color:var(--text-muted,#748194);margin-top:2px}.gp-profile-arrow{margin-left:auto;color:var(--gp-accent);font-size:11px;white-space:nowrap}.gp-profile-search{width:100%;padding:10px;border:1px solid var(--border,#d9e1ea);border-radius:9px;margin-bottom:12px;background:var(--surface,#fff);color:inherit}.gp-profile-list{display:grid;gap:7px}.gp-profile-menu{display:flex;align-items:center;gap:12px;padding:11px;border:1px solid var(--border,#d9e1ea);border-radius:9px;cursor:pointer}.gp-profile-menu span{flex:1;min-width:0}.gp-profile-menu strong{display:block;font-size:12px}.gp-profile-menu small{display:block;font-size:10px;color:var(--text-muted,#748194);overflow-wrap:anywhere}.gp-profile-menu em{font-size:10px;color:var(--text-muted,#748194)}@media(max-width:900px){.gp-profiles{grid-template-columns:repeat(2,minmax(0,1fr))}.gp-profile-arrow{display:none}}@media(max-width:500px){.gp-profiles{grid-template-columns:1fr 1fr}.gp-profile-card{padding:8px}}
</style>
<main class="app-main"><div class="gp-main">
<?php if(isset($_GET['saved'])):?><div class="gp-notice" role="status">Alterações realizadas com sucesso.</div><?php endif;?>
<?php if($error):?><div class="gp-notice error" role="alert"><?=gh($error)?></div><?php endif;?>
<nav class="gp-main-tabs" role="tablist" aria-label="Áreas de permissões"><?php foreach(['grupos'=>'Grupos','acessos'=>'Acessos','agentes'=>'Agentes','usuarios'=>'Usuários'] as $key=>$label):?><button type="button" role="tab" aria-selected="<?= $activeMainTab===$key?'true':'false' ?>" class="gp-main-tab <?= $activeMainTab===$key?'active':'' ?>" data-main-tab="<?=gh($key)?>"><?=gh($label)?></button><?php endforeach;?></nav>
<section class="gp-main-panel <?= $activeMainTab==='acessos'?'active':'' ?>" data-main-panel="acessos" <?= $activeMainTab==='acessos'?'':'hidden' ?>>
<div class="gp-toolbar"><input type="search" id="gp-access-search" class="gp-search" placeholder="Buscar perfil de acesso..."><select id="gp-access-filter" class="gp-filter"><option value="all">Todos os perfis</option><option value="with">Com menus</option><option value="without">Sem menus</option></select><span class="gp-stat">Perfis <b><?=count($accessProfiles)?></b></span><span class="gp-stat">Menus <b><?=count($navigation)?></b></span><span class="gp-spacer"></span><a href="<?=gh($base)?>admin/menus.php" class="gp-btn secondary" aria-label="Gerenciar menus ↗" title="Gerenciar menus ↗"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Gerenciar menus ↗</span></a></div>
<div class="gp-grid"><table class="arc-responsive-table gp-table"><thead><tr><th>Perfil de acesso</th><th>Identificador</th><th>Menus autorizados</th><th>Integração</th><th>Ações</th></tr></thead><tbody id="gp-access-rows"><?php foreach($accessProfiles as $profileKey=>$profileName):?><tr class="gp-access-row" data-name="<?=gh(mb_strtolower($profileName.' '.$profileKey))?>" data-count="<?=(int)$profileMenuCounts[$profileKey]?>"><td data-label="Perfil de acesso"><div class="gp-title"><?=gh($profileName)?></div></td><td class="gp-muted" data-label="Identificador"><?=gh($profileKey)?></td><td data-label="Menus autorizados"><span class="gp-pill"><?=(int)$profileMenuCounts[$profileKey]?> de <?=count($navigation)?> menus</span></td><td data-label="Integração">Menus e navegação</td><td data-label="Ações"><button type="button" class="gp-btn secondary gp-profile-card" data-profile="<?=gh($profileKey)?>" aria-label="Gerenciar permissões" title="Gerenciar permissões"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Gerenciar permissões</span></button></td></tr><?php endforeach;?></tbody></table><div class="gp-empty gp-filter-empty" hidden>Nenhum perfil encontrado.</div></div>
</section>
<section class="gp-main-panel <?= $activeMainTab==='grupos'?'active':'' ?>" data-main-panel="grupos" <?= $activeMainTab==='grupos'?'':'hidden' ?>>
<div class="gp-toolbar">
<input class="gp-search" id="gp-search" placeholder="Buscar grupo ou descrição..." aria-label="Buscar grupos">
<select class="gp-filter" id="gp-filter"><option value="all">Todos os grupos</option><option value="with">Com usuários</option><option value="without">Sem usuários</option></select>
<span class="gp-stat">Grupos <b><?=count($all)?></b></span><span class="gp-stat">Com usuários <b><?=count($counts)?></b></span><span class="gp-stat">Vínculos <b><?=array_sum($counts)?></b></span>
<form method="post" action="<?=gh($base)?>admin/permissoes.php" class="gp-bulk" id="gp-bulk"><input type="hidden" name="_csrf" value="<?=gh(arc_csrf_token())?>"><input type="hidden" name="action" value="bulk_delete"><span id="gp-selected">0 selecionados</span><button type="button" class="gp-btn danger" id="gp-delete-selected" aria-label="Excluir selecionados" title="Excluir selecionados"><i class="fa fa-trash-o" aria-hidden="true"></i><span class="arc-action-label">Excluir selecionados</span></button></form>
<span class="gp-spacer"></span><button class="gp-btn" type="button" id="gp-new" aria-label="+ Novo grupo" title="+ Novo grupo"><i class="fa fa-plus" aria-hidden="true"></i><span class="arc-action-label">+ Novo grupo</span></button>
</div>
<div class="gp-grid"><table class="arc-responsive-table gp-table"><thead><tr><th class="gp-check"><input type="checkbox" id="gp-check-all" aria-label="Selecionar todos os grupos visíveis"></th><th data-sort="name">Grupo ↕</th><th data-sort="description">Descrição ↕</th><th data-sort="tabs">Permissões do agente ↕</th><th data-sort="members">Usuários vinculados ↕</th><th data-sort="id">Código ↕</th></tr></thead><tbody id="gp-rows">
<?php foreach($all as $g):$gid=(int)$g['id'];$allowed=array_filter(explode(',',(string)($g['agent_tabs']??'')));$n=$counts[$gid]??0;?><tr data-name="<?=gh(mb_strtolower((string)$g['name']))?>" data-description="<?=gh(mb_strtolower((string)($g['description']??'')))?>" data-tabs="<?=count($allowed)?>" data-members="<?=$n?>" data-id="<?=$gid?>" data-json="<?=gh(json_encode($g,JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_HEX_APOS))?>"><td class="gp-check"><input type="checkbox" class="gp-row-check" value="<?=$gid?>" aria-label="Selecionar <?=gh($g['name'])?>"></td><td data-label="Grupo ↕"><div class="gp-title"><?=gh($g['name'])?></div></td><td data-label="Descrição ↕"><?=gh($g['description']?:'Sem descrição')?></td><td data-label="Permissões do agente ↕"><span class="gp-pill"><?=count($allowed)?> de <?=count($tabs)?> abas</span></td><td data-label="Usuários vinculados ↕"><b><?=$n?></b> <span class="gp-muted">contas</span></td><td class="gp-muted" data-label="Código ↕"><?=$gid?></td></tr><?php endforeach;?>
</tbody></table><div class="gp-empty" id="gp-empty" style="display:none">Nenhum grupo corresponde aos filtros.</div></div></section>
<section class="gp-main-panel <?= $activeMainTab==='agentes'?'active':'' ?>" data-main-panel="agentes" <?= $activeMainTab==='agentes'?'':'hidden' ?>>
    
<div class="gp-toolbar"><input type="search" class="gp-search" id="gp-agent-search" placeholder="Buscar grupo ou módulo..."><select class="gp-filter" id="gp-agent-filter"><option value="all">Todos os grupos</option><option value="with">Com permissões</option><option value="without">Sem permissões</option></select><span class="gp-stat">Grupos <b><?=count($all)?></b></span><span class="gp-stat">Módulos <b><?=count($tabs)?></b></span><span class="gp-spacer"></span><button class="gp-btn" type="button" id="gp-agent-new" aria-label="+ Novo grupo" title="+ Novo grupo"><i class="fa fa-plus" aria-hidden="true"></i><span class="arc-action-label">+ Novo grupo</span></button></div>
<div class="gp-grid"><table class="arc-responsive-table gp-table"><thead><tr><th>Grupo</th><th>Descrição</th><th>Módulos autorizados</th><th>Usuários</th><th>Ações</th></tr></thead><tbody id="gp-agent-rows"><?php foreach($all as $g):$gid=(int)$g['id'];$allowed=array_values(array_intersect(array_keys($tabs),explode(',',(string)($g['agent_tabs']??''))));$labels=array_map(static fn($k)=>$tabs[$k],$allowed);?><tr class="gp-agent-row" data-name="<?=gh(mb_strtolower($g['name'].' '.($g['description']??'').' '.implode(' ',$labels)))?>" data-count="<?=count($allowed)?>"><td data-label="Grupo"><div class="gp-title"><?=gh($g['name'])?></div></td><td data-label="Descrição"><?=gh($g['description']?:'Sem descrição')?></td><td data-label="Módulos autorizados"><span class="gp-pill"><?=count($allowed)?> de <?=count($tabs)?> módulos</span><div class="gp-muted"><?=gh(implode(' · ',$labels)?:'Nenhum módulo autorizado')?></div></td><td data-label="Usuários"><?=(int)($counts[$gid]??0)?></td><td data-label="Ações"><button type="button" class="gp-btn secondary gp-edit-agent" data-id="<?=$gid?>" aria-label="Gerenciar permissões" title="Gerenciar permissões"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Gerenciar permissões</span></button></td></tr><?php endforeach;?></tbody></table><div class="gp-empty gp-filter-empty" hidden>Nenhum grupo encontrado.</div></div></section>
<section class="gp-main-panel <?= $activeMainTab==='usuarios'?'active':'' ?>" data-main-panel="usuarios" <?= $activeMainTab==='usuarios'?'':'hidden' ?>>
    
<div class="gp-toolbar"><input type="search" id="gp-users-search" class="gp-search" placeholder="Buscar nome ou e-mail..."><select id="gp-users-filter" class="gp-filter"><option value="all">Todos os usuários</option><option value="with">Com grupo</option><option value="without">Sem grupo</option></select><span class="gp-stat">Contas <b><?=count($accounts)?></b></span><span class="gp-stat">Com grupo <b><?=array_sum($counts)?></b></span><span class="gp-spacer"></span><a class="gp-btn secondary" href="<?=gh($base)?>admin/clientes.php" aria-label="Abrir Clientes ↗" title="Abrir Clientes ↗"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Abrir Clientes ↗</span></a></div>
<div class="gp-grid"><table class="arc-responsive-table gp-table"><thead><tr><th>Usuário</th><th>Perfil</th><th>Situação</th><th>Grupo atribuído</th><th>Ação</th></tr></thead><tbody id="gp-users-list"><?php foreach($accounts as $a):$gid=(int)($a['group_id']??0);?><tr class="gp-user-entry" data-search="<?=gh(mb_strtolower(($a['display_name']??'').' '.($a['email']??'').' '.($a['access_role']??'')))?>" data-group="<?=$gid?>"><td data-label="Usuário"><div class="gp-title"><?=gh($a['display_name']?:$a['email'])?></div><div class="gp-muted"><?=gh($a['email'])?></div></td><td data-label="Perfil"><?=gh($accessProfiles[$a['access_role']]??$a['access_role'])?></td><td data-label="Situação"><span class="gp-pill"><?=gh(['active'=>'Ativo','pending'=>'Pendente','suspended'=>'Suspenso'][$a['account_status']]??$a['account_status'])?></span></td><td data-label="Grupo atribuído"><form id="gp-user-<?=$a['id']?>" method="post" action="<?=gh($base)?>admin/permissoes.php"><input type="hidden" name="_csrf" value="<?=gh(arc_csrf_token())?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="account_id" value="<?=(int)$a['id']?>"><select name="group_id" class="gp-user-group"><option value="0">Sem grupo</option><?php foreach($all as $g):?><option value="<?=(int)$g['id']?>" <?=$gid===(int)$g['id']?'selected':''?>><?=gh($g['name'])?></option><?php endforeach;?></select></form></td><td data-label="Ação"><button type="submit" form="gp-user-<?=$a['id']?>" class="gp-btn secondary" aria-label="Salvar vínculo" title="Salvar vínculo"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Salvar vínculo</span></button></td></tr><?php endforeach;?></tbody></table><div class="gp-empty gp-filter-empty" hidden>Nenhum usuário encontrado.</div></div><div class="gp-muted">Exibindo até 500 contas recentes.</div></section>
</div></main>
<div class="gp-overlay" id="gp-overlay" role="dialog" aria-modal="true" aria-labelledby="gp-modal-title"><div class="gp-modal"><div class="gp-modal-head"><h2 id="gp-modal-title">Novo grupo</h2><button type="button" class="gp-btn secondary" id="gp-close" aria-label="Fechar" title="✕"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">✕</span></button></div><div class="gp-tabs"><button type="button" class="gp-tab active" data-pane="dados">Dados do grupo</button><button type="button" class="gp-tab" data-pane="permissoes">Permissões</button><button type="button" class="gp-tab" data-pane="usuarios">Usuários vinculados</button></div>
<form method="post" id="gp-form" action="<?=gh($base)?>admin/permissoes.php"><input type="hidden" name="_csrf" value="<?=gh(arc_csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="return_tab" id="gp-return-tab" value="grupos"><input type="hidden" name="id" id="gp-id" value="0">
<div class="gp-modal-body"><section class="gp-pane active" data-pane="dados"><div class="gp-fields"><label class="gp-field wide">Nome do grupo<input name="name" id="gp-name" maxlength="90" required placeholder="Ex.: Equipe de monitoramento"></label><label class="gp-field wide">Descrição e finalidade<textarea name="description" id="gp-description" maxlength="255" placeholder="Descreva as responsabilidades e o escopo deste grupo"></textarea></label><div class="gp-field wide"><span class="gp-muted">O perfil de acesso do usuário é definido pelo vínculo ao grupo. As permissões selecionadas controlam as abas do agente e os comandos relacionados.</span></div></div></section>
<section class="gp-pane" data-pane="permissoes"><p class="gp-muted">Selecione os módulos que os usuários deste grupo poderão acessar no agente.</p><div class="gp-perms"><?php foreach($tabs as $key=>$label):?><label><input type="checkbox" name="tabs[]" value="<?=gh($key)?>" class="gp-tab-check"> <?=gh($label)?></label><?php endforeach;?></div></section>
<section class="gp-pane" data-pane="usuarios"><p class="gp-muted">Os vínculos de usuários são gerenciados na aba principal <strong>Usuários</strong>. Feche este modal e selecione essa aba para associar contas aos grupos.</p></section></div></form><div class="gp-modal-foot"><button type="button" class="gp-btn secondary" id="gp-cancel" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button class="gp-btn" type="submit" form="gp-form" id="gp-save" aria-label="Salvar grupo" title="Salvar grupo"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Salvar grupo</span></button></div></div></div>
<div class="gp-overlay" id="gp-profile-overlay" role="dialog" aria-modal="true" aria-labelledby="gp-profile-title"><div class="gp-modal"><div class="gp-modal-head"><h2 id="gp-profile-title">Permissões do perfil</h2><button type="button" class="gp-btn secondary" id="gp-profile-close" aria-label="✕" title="✕"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">✕</span></button></div><form id="gp-profile-form" method="post" action="<?=gh($base)?>admin/permissoes.php" style="display:flex;flex-direction:column;min-height:0;flex:1"><input type="hidden" name="_csrf" value="<?=gh(arc_csrf_token())?>"><input type="hidden" name="action" value="save_profile_access"><input type="hidden" name="profile" id="gp-profile-key"><div class="gp-modal-body"><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Marque os menus disponíveis para este perfil. A alteração também será refletida no campo Perfis com acesso do Gerenciador de Menus.</p></dialog><input type="search" id="gp-profile-search" placeholder="Buscar menu, posição ou arquivo..." class="gp-profile-search"><div class="gp-profile-list"><?php foreach($navigation as $nav): ?><label class="gp-profile-menu" data-filter="<?=gh(mb_strtolower(($nav['label']??'').' '.($nav['placement']??'').' '.($nav['target_url']??'')))?>" data-roles="<?=gh($nav['access_roles'])?>"><input type="checkbox" name="menu_ids[]" value="<?=(int)$nav['id']?>"><span><strong><?=gh($nav['label'])?></strong><small><?=gh($nav['placement'])?> · <?=gh($nav['target_url']?:'Sem caminho')?></small></span><?php if(!(int)$nav['enabled']):?><em>Desativado</em><?php endif;?></label><?php endforeach;?></div></div><div class="gp-modal-foot"><button type="button" class="gp-btn secondary" id="gp-profile-cancel" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button type="submit" class="gp-btn" aria-label="Salvar permissões do perfil" title="Salvar permissões do perfil"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Salvar permissões do perfil</span></button></div></form></div></div>
<div class="gp-overlay" id="gp-confirm" role="dialog" aria-modal="true"><div class="gp-modal" style="height:auto;max-width:420px"><div class="gp-modal-head"><h2>Confirmar exclusão</h2></div><div class="gp-modal-body">Excluir os grupos selecionados? Os usuários vinculados serão desvinculados. Esta ação não pode ser desfeita.</div><div class="gp-modal-foot"><button class="gp-btn secondary" id="gp-confirm-no" type="button" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button class="gp-btn danger" id="gp-confirm-yes" type="button" aria-label="Excluir grupos" title="Excluir grupos"><i class="fa fa-trash-o" aria-hidden="true"></i><span class="arc-action-label">Excluir grupos</span></button></div></div></div>
<style>
.gp-main-tabs{display:flex;gap:3px;flex-shrink:0;border:1px solid var(--border);background:var(--surface,#fff);padding:5px;border-radius:12px;overflow-x:auto}.gp-main-tab{border:1px solid transparent;border-radius:8px;padding:9px 17px;background:transparent;color:inherit;font:inherit;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}.gp-main-tab.active{background:var(--surface);color:var(--primary);border-color:var(--border);box-shadow:inset 0 -2px #f28b22}.gp-main-panel{display:none;flex:1;min-height:0;flex-direction:column;gap:10px;overflow:hidden}.gp-main-panel.active{display:flex}.gp-main-panel[hidden]{display:none!important}.gp-user-entry[hidden]{display:none!important}.gp-access-row[hidden]{display:none!important}.gp-agent-row[hidden]{display:none!important}.gp-filter-empty[hidden]{display:none!important}.gp-toolbar{border:1px solid var(--border,#dce5f1);background:var(--surface,#fff);border-radius:12px;padding:9px;overflow-x:auto}.gp-grid{overflow:auto}.gp-table{min-width:730px}.gp-table thead th{background:var(--surface);color:var(--text)}.gp-table tbody tr{cursor:default}.gp-table tbody tr[data-json]{cursor:pointer}.gp-table td .gp-muted{margin-top:4px}.gp-user-group{min-width:180px;max-width:250px;width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface,#fff);color:inherit}.gp-section-heading{gap:7px}.gp-section-heading strong{font-size:13px}.gp-overlay .gp-modal-head{background:linear-gradient(110deg,#0c305c,#175c9e);color:var(--text)}.gp-overlay .gp-modal-head .gp-btn{background:#ffffff20;color:var(--text);border-color:#ffffff45}.gp-tabs{background:var(--surface)}.gp-tab.active{border-bottom:3px solid #f38b20}.gp-filter-empty{display:block}.gp-empty[hidden]{display:none}.gp-profile-menu[hidden]{display:none}.gp-main a.gp-btn{text-decoration:none}@media(max-width:850px){.gp-main{height:calc(100dvh - 110px)}.gp-toolbar{flex-wrap:nowrap}.gp-search{min-width:180px}.gp-stat{display:flex}.gp-modal{max-height:94dvh}}
</style>
<script>
(function(){'use strict';
const tabs=document.querySelectorAll('.gp-main-tab'), panels=document.querySelectorAll('.gp-main-panel');
function activate(name){tabs.forEach(b=>{let active=b.dataset.mainTab===name;b.classList.toggle('active',active);b.setAttribute('aria-selected',active?'true':'false')});panels.forEach(p=>{let active=p.dataset.mainPanel===name;p.classList.toggle('active',active);p.hidden=!active});const u=new URL(location.href);u.searchParams.set('tab',name);history.replaceState(null,'',u)}
tabs.forEach(b=>b.addEventListener('click',()=>activate(b.dataset.mainTab)));
function filter(prefix,selector,textKey,countKey){const input=document.getElementById(prefix+'-search'),select=document.getElementById(prefix+'-filter');const rows=Array.from(document.querySelectorAll(selector));const empty=input?.closest('.gp-main-panel')?.querySelector('.gp-filter-empty');function apply(){const q=(input.value||'').toLocaleLowerCase('pt-BR').trim();let shown=0;rows.forEach(row=>{const count=Number(row.dataset[countKey]||0);const match=(row.dataset[textKey]||'').includes(q)&&(select.value==='all'||(select.value==='with'?count>0:count===0));row.hidden=!match;if(match)shown++});if(empty)empty.hidden=shown>0}if(input&&select){input.addEventListener('input',apply);select.addEventListener('change',apply);apply()}}
filter('gp-access','.gp-access-row','name','count');filter('gp-agent','.gp-agent-row','name','count');filter('gp-users','.gp-user-entry','search','group');
document.querySelectorAll('.gp-edit-agent').forEach(b=>b.addEventListener('click',()=>{let row=document.querySelector('#gp-rows tr[data-id="'+b.dataset.id+'"]');if(row){row.click();document.querySelector('.gp-tab[data-pane="permissoes"]')?.click();document.getElementById('gp-return-tab').value='agentes'}}));
document.getElementById('gp-agent-new')?.addEventListener('click',()=>{document.getElementById('gp-new')?.click();document.getElementById('gp-return-tab').value='agentes'});
})();
</script>
<script>
(function(){
'use strict';
var overlay=document.getElementById('gp-profile-overlay'), form=document.getElementById('gp-profile-form');
if(!overlay||!form)return;
var names={admin:'Administrador',manager:'Gestor',user:'Usuário',viewer:'Visualizador'};
function close(){overlay.classList.remove('open');}
document.querySelectorAll('.gp-profile-card').forEach(function(button){button.addEventListener('click',function(){var profile=button.dataset.profile;document.getElementById('gp-profile-key').value=profile;document.getElementById('gp-profile-title').textContent='Perfil: '+names[profile];document.getElementById('gp-profile-search').value='';document.querySelectorAll('.gp-profile-menu').forEach(function(row){row.hidden=false;row.querySelector('input').checked=row.dataset.roles.split(',').map(function(s){return s.trim()}).includes(profile);});overlay.classList.add('open');});});
document.getElementById('gp-profile-close').addEventListener('click',close);
document.getElementById('gp-profile-cancel').addEventListener('click',close);
overlay.addEventListener('click',function(event){if(event.target===overlay)close();});
document.getElementById('gp-profile-search').addEventListener('input',function(){var term=this.value.trim().toLocaleLowerCase('pt-BR');document.querySelectorAll('.gp-profile-menu').forEach(function(row){row.hidden=!row.dataset.filter.includes(term);});});
})();
</script>
<script>
(function () {
  'use strict';
  function initializePermissions() {
    var root = document;
    function one(selector) { return root.querySelector(selector); }
    function many(selector) { return Array.prototype.slice.call(root.querySelectorAll(selector)); }
    var overlay = one('#gp-overlay');
    var confirmBox = one('#gp-confirm');
    var form = one('#gp-form');
    if (!overlay || !confirmBox || !form) return;
    var rows = many('#gp-rows tr');
    var checks = many('.gp-row-check');
    var activeSort = '', ascending = true;
    function showPane(name) {
      many('.gp-tab').forEach(function (button) { button.classList.toggle('active', button.dataset.pane === name); });
      many('.gp-pane').forEach(function (pane) { pane.classList.toggle('active', pane.dataset.pane === name); });
      one('#gp-save').hidden = name === 'usuarios';
    }
    function openModal(group) {
      var id = group ? Number(group.id) : 0;
      one('#gp-id').value = id;
      one('#gp-return-tab').value = document.querySelector('.gp-main-tab.active')?.dataset.mainTab === 'agentes' ? 'agentes' : 'grupos';
      one('#gp-name').value = group ? (group.name || '') : '';
      one('#gp-description').value = group ? (group.description || '') : '';
      var tabs = group ? String(group.agent_tabs || '').split(',') : [];
      many('.gp-tab-check').forEach(function (box) { box.checked = group ? tabs.indexOf(box.value) !== -1 : true; });
      one('#gp-modal-title').textContent = group ? 'Editar grupo · ' + group.name : 'Novo grupo';
      one('.gp-tab[data-pane="usuarios"]').hidden = !id;
      showPane('dados');
      overlay.classList.add('open');
      one('#gp-name').focus();
    }
    function closeModal() { overlay.classList.remove('open'); }
    function updateSelection() {
      var selected = checks.filter(function (check) { return check.checked; });
      var bulk = one('#gp-bulk');
      bulk.classList.toggle('visible', selected.length > 0);
      one('#gp-selected').textContent = selected.length + ' selecionados';
      many('#gp-bulk input[name="ids[]"]').forEach(function (input) { input.remove(); });
      selected.forEach(function (check) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'ids[]'; input.value = check.value;
        bulk.appendChild(input);
      });
      var visible = checks.filter(function (check) { return !check.closest('tr').hidden; });
      one('#gp-check-all').checked = visible.length > 0 && visible.every(function (check) { return check.checked; });
      one('#gp-check-all').indeterminate = visible.some(function (check) { return check.checked; }) && !one('#gp-check-all').checked;
    }
    function filterRows() {
      var search = one('#gp-search').value.trim().toLocaleLowerCase('pt-BR');
      var filter = one('#gp-filter').value;
      var visible = 0;
      rows.forEach(function (row) {
        var members = Number(row.dataset.members || 0);
        var matches = (row.dataset.name + ' ' + row.dataset.description).toLocaleLowerCase('pt-BR').indexOf(search) !== -1;
        var status = filter === 'all' || (filter === 'with' ? members > 0 : members === 0);
        row.hidden = !(matches && status);
        if (!row.hidden) visible++;
      });
      one('#gp-empty').style.display = visible ? 'none' : '';
      updateSelection();
    }
    // Event delegation survives table sorting and avoids conflicting click handlers.
    root.addEventListener('click', function (event) {
      var target = event.target;
      var button = target.closest('button');
      if (button && button.id === 'gp-new') { openModal(null); return; }
      if (button && (button.id === 'gp-close' || button.id === 'gp-cancel')) { closeModal(); return; }
      if (button && button.classList.contains('gp-tab')) { showPane(button.dataset.pane); return; }
      if (button && button.id === 'gp-delete-selected') { if(checks.some(check=>check.checked))confirmBox.classList.add('open'); return; }
      if (button && button.id === 'gp-confirm-no') { confirmBox.classList.remove('open'); return; }
      if (button && button.id === 'gp-confirm-yes') {
        if (one('#gp-bulk').requestSubmit) one('#gp-bulk').requestSubmit();
        else one('#gp-bulk').submit();
        return;
      }
      if (target === overlay) { closeModal(); return; }
      if (target === confirmBox) { confirmBox.classList.remove('open'); return; }
      var header = target.closest('#gp-rows') ? null : target.closest('th[data-sort]');
      if (header) {
        var key = header.dataset.sort;
        ascending = activeSort === key ? !ascending : true;
        activeSort = key;
        rows.sort(function (a, b) {
          var x = a.dataset[key] || '', y = b.dataset[key] || '';
          var result = ['tabs','members','id'].indexOf(key) !== -1 ? Number(x) - Number(y) : x.localeCompare(y, 'pt-BR');
          return ascending ? result : -result;
        }).forEach(function (row) { one('#gp-rows').appendChild(row); });
        return;
      }
      var row = target.closest('#gp-rows tr');
      if (row && !target.closest('input,button,a,select,label')) {
        try { openModal(JSON.parse(row.dataset.json)); }
        catch (error) { console.error('Não foi possível abrir o grupo', error); }
      }
    });
    root.addEventListener('change', function (event) {
      if (event.target.matches('.gp-row-check')) updateSelection();
      if (event.target.id === 'gp-check-all') {
        checks.forEach(function (check) { if (!check.closest('tr').hidden) check.checked = event.target.checked; });
        updateSelection();
      }
      if (event.target.id === 'gp-filter') filterRows();
    });
    one('#gp-search').addEventListener('input', filterRows);
    one('#gp-member-search')?.addEventListener('input', function (event) {
      var search = event.target.value.toLocaleLowerCase('pt-BR');
      many('.gp-member').forEach(function (member) { member.hidden = member.dataset.search.indexOf(search) === -1; });
    });
    root.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { closeModal(); confirmBox.classList.remove('open'); }
    });
    form.addEventListener('submit', function (event) {
      if (!one('#gp-name').value.trim()) {
        event.preventDefault(); showPane('dados'); one('#gp-name').focus();
      }
    });
    updateSelection();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializePermissions);
  else initializePermissions();
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
