<?php
/* Caminho: includes/sidebar.php */
declare(strict_types=1);

/* ARQUIVO: /includes/sidebar.php */

/* ============================================================
   includes/sidebar.php — MENU LATERAL PADRÃO (recolhível)
   ------------------------------------------------------------
   Requer: sessão iniciada, usuário logado, $ARCActiveNav.
   O botão de recolher alterna a classe .sidebar-collapsed no
   <body> (persistida em localStorage) — nesse modo só os ícones
   do menu ficam visíveis, um embaixo do outro.
   ============================================================ */

$arcIsAdmin=arc_is_admin();
$arcUser=[];
if(!$arcIsAdmin){
  if(!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
  $stmt=$pdo->prepare('SELECT display_name,email FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
  $stmt->execute([arc_user_id()]);$arcUser=$stmt->fetch()?:[];
  if(!$arcUser){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
}
$arcMenu=arc_navigation_items($pdo,'sidebar');
$arcCurrentPath=ltrim(str_replace('\\','/',(string)parse_url($_SERVER['SCRIPT_NAME']??'',PHP_URL_PATH)),'/');
$arcRootPath=trim((string)parse_url(arc_workspace_base_url(),PHP_URL_PATH),'/');
if($arcRootPath!=='' && str_starts_with($arcCurrentPath,$arcRootPath.'/'))$arcCurrentPath=substr($arcCurrentPath,strlen($arcRootPath)+1);
foreach($arcMenu as &$arcMenuEntry){
    $arcMenuTarget=(string)parse_url((string)($arcMenuEntry['url']??''),PHP_URL_PATH);
    if(ltrim($arcMenuTarget,'/')===$arcCurrentPath && $arcCurrentPath!==''){
        if((string)$arcMenuEntry['key']==='')$arcMenuEntry['key']='menu_file_'.(int)$arcMenuEntry['id'];
        $ARCActiveNav=$arcMenuEntry['key'];
        break;
    }
}
unset($arcMenuEntry);
$arcMenuIds=array_column($arcMenu,'id');
?>
<aside class="app-sidebar" id="app-sidebar">
    <div class="side-top">
        <a class="side-brand" href="<?=arc_h(arc_navigation_home($pdo))?>" title="Página inicial">
            <img src="<?= arc_h($arcLogo) ?>" alt="">
            <span class="side-brand-copy"><b><?= arc_h($arcSiteName) ?></b><small><?= arc_h($arcSiteDesc) ?></small></span>
        </a>
    </div>

    <nav class="side-nav" aria-label="Navegação principal">
        <?php
        $arcChildren=[];$arcParents=[];
        foreach($arcMenu as $item){$parent=(int)($item['parent_id']??0);if($parent>0 && in_array($parent,$arcMenuIds,true))$arcChildren[$parent][]=$item;else $arcParents[]=$item;}
        foreach($arcParents as $arcItem):
            $arcSub=$arcChildren[$arcItem['id']]??[];
            $arcExpanded=false;
            foreach($arcSub as $sub){if(($ARCActiveNav??'')===$sub['key']){$arcExpanded=true;break;}}
        ?>
        <div class="arc-nav-group <?=arc_h($arcItem['device_class'])?>">
          <div class="arc-nav-entry">
            <?php if($arcSub):?><button type="button" class="arc-nav-parent <?= $arcExpanded?'active':'' ?>" data-nav-parent="arc-submenu-<?=(int)$arcItem['id']?>" aria-expanded="<?=$arcExpanded?'true':'false'?>" aria-controls="arc-submenu-<?=(int)$arcItem['id']?>" title="<?=arc_h($arcItem['label'])?>"><?php else:?><a href="<?=arc_h($arcItem['url']!==''?$arcItem['url']:'#')?>" class="<?=($ARCActiveNav??'')===$arcItem['key']?'active':''?>" title="<?=arc_h($arcItem['label'])?>"><?php endif;?><span class="arc-db-icon" data-icon="<?=arc_h($arcItem['icon'])?>" aria-hidden="true"></span><span class="side-label"><?=arc_h($arcItem['label'])?></span><?php if($arcSub):?><i class="fa fa-chevron-down arc-nav-chevron" aria-hidden="true"></i></button><?php else:?></a><?php endif;?>
          </div>
          <?php if($arcSub):?><div class="arc-nav-children" id="arc-submenu-<?=(int)$arcItem['id']?>" <?=$arcExpanded?'':'hidden'?>><?php foreach($arcSub as $sub):?><a href="<?=arc_h($sub['url'])?>" class="<?=arc_h($sub['device_class'])?> <?=($ARCActiveNav??'')===$sub['key']?'active':''?>"><span class="arc-db-icon" data-icon="<?=arc_h($sub['icon'])?>" aria-hidden="true"></span><span><?=arc_h($sub['label'])?></span></a><?php endforeach;?></div><?php endif;?>
        </div>
        <?php endforeach; ?>
    </nav>

    <div class="side-bottom">
        <form method="post" action="<?= 'pages/painel.php' ?>">
            <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="button ghost side-logout" type="submit" title="Sair da conta">
                <span class="side-icon"><i class="fa fa-sign-out" aria-hidden="true"></i></span>
                <span class="side-label">Sair da conta</span>
            </button>
        </form>
    </div>
</aside>


<div class="arc-sidebar-backdrop" id="arc-sidebar-backdrop" hidden></div>

