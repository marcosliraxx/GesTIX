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
$arcMenu=arc_v15_menu_items($pdo,'sidebar');
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
        <a class="side-brand" href="pages/painel.php" title="Página inicial">
            <img src="<?= arc_h($arcLogo) ?>" alt="">
            <span class="side-brand-copy"><b><?= arc_h($arcSiteName) ?></b><small>GESTÃO DE REDES</small></span>
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
        <div class="arc-nav-group">
          <div class="arc-nav-entry">
            <?php if($arcSub):?><button type="button" class="arc-nav-parent <?= $arcExpanded?'active':'' ?>" data-nav-parent="arc-submenu-<?=(int)$arcItem['id']?>" aria-expanded="<?=$arcExpanded?'true':'false'?>" aria-controls="arc-submenu-<?=(int)$arcItem['id']?>" title="<?=arc_h($arcItem['label'])?>"><?php else:?><a href="<?=arc_h($arcItem['url']!==''?$arcItem['url']:'#')?>" class="<?=($ARCActiveNav??'')===$arcItem['key']?'active':''?>" title="<?=arc_h($arcItem['label'])?>"><?php endif;?><span class="arc-db-icon" data-icon="<?=arc_h($arcItem['icon'])?>" aria-hidden="true"></span><span class="side-label"><?=arc_h($arcItem['label'])?></span><?php if($arcSub):?><i class="fa fa-chevron-down arc-nav-chevron" aria-hidden="true"></i></button><?php else:?></a><?php endif;?>
          </div>
          <?php if($arcSub):?><div class="arc-nav-children" id="arc-submenu-<?=(int)$arcItem['id']?>" <?=$arcExpanded?'':'hidden'?>><?php foreach($arcSub as $sub):?><a href="<?=arc_h($sub['url'])?>" class="<?=($ARCActiveNav??'')===$sub['key']?'active':''?>"><span class="arc-db-icon" data-icon="<?=arc_h($sub['icon'])?>" aria-hidden="true"></span><span><?=arc_h($sub['label'])?></span></a><?php endforeach;?></div><?php endif;?>
        </div>
        <?php endforeach; ?>
      <div class="arc-nav-group" aria-label="Central de relatórios">
        <div class="arc-nav-entry"><a href="redes/relatorios.php" class="<?=($ARCActiveNav??'')==='reports_center'?'active':''?>" title="Central de relatórios"><i class="fa fa-bar-chart" aria-hidden="true"></i><span class="side-label">Relatórios</span></a></div>
      </div>
      <div class="arc-nav-group" aria-label="Suporte e assinaturas">
        <div class="arc-nav-entry"><a href="pages/atendimentos.php" class="<?=($ARCActiveNav??'')==='support_center'?'active':''?>"><i class="fa fa-life-ring" aria-hidden="true"></i><span class="side-label">Atendimentos e equipe</span></a></div>
        <?php if($arcIsAdmin):?><div class="arc-nav-entry"><a href="pages/comercial.php" class="<?=($ARCActiveNav??'')==='commercial'?'active':''?>"><i class="fa fa-credit-card" aria-hidden="true"></i><span class="side-label">Gestão comercial</span></a></div><?php else:?><div class="arc-nav-entry"><a href="pages/assinatura.php" class="<?=($ARCActiveNav??'')==='billing'?'active':''?>"><i class="fa fa-credit-card" aria-hidden="true"></i><span class="side-label">Planos e cobrança</span></a></div><div class="arc-nav-entry"><a href="pages/cobrancas.php" class="<?=($ARCActiveNav??'')==='billing_history'?'active':''?>"><i class="fa fa-history" aria-hidden="true"></i><span class="side-label">Cobranças</span></a></div><?php endif;?>
      </div>
      <div class="arc-nav-group" aria-label="Área pessoal">
        <div class="arc-nav-entry"><a href="pages/perfil.php" class="<?=($ARCActiveNav??'')==='profile'?'active':''?>"><i class="fa fa-user-circle-o" aria-hidden="true"></i><span class="side-label">Meu perfil</span></a></div>
        <div class="arc-nav-entry"><a href="pages/preferencias.php" class="<?=($ARCActiveNav??'')==='settings'?'active':''?>"><i class="fa fa-sliders" aria-hidden="true"></i><span class="side-label">Preferências</span></a></div>
        <div class="arc-nav-entry"><a href="pages/atividades.php" class="<?=($ARCActiveNav??'')==='activities'?'active':''?>"><i class="fa fa-history" aria-hidden="true"></i><span class="side-label">Minhas atividades</span></a></div>
      </div>
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

