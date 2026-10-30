<?php
declare(strict_types=1);

/* ARQUIVO: /includes/header.php */

/* ============================================================
   includes/header.php — CABEÇALHO PADRÃO DO WORKSPACE
   ------------------------------------------------------------
   Como usar em qualquer página:

       $ARCPageTitle   = 'Título da página';   // obrigatório
       $ARCActiveNav   = 'networks';           // item ativo do menu
       $ARCHeaderActions = '<a class="btn" ...>...</a>'; // opcional
       require __DIR__ . '/includes/header.php';          // <!doctype> + topo
       require __DIR__ . '/includes/sidebar.php';         // menu lateral
   --> conteúdo dentro de <main class="app-main"> ...
       $ARCPageJs = '<script>...</script>';     // JS da página (opcional)
       require __DIR__ . '/includes/footer.php';

   Para telas de login (sem sidebar/topo): $ARCMode = 'auth';
   ============================================================ */

$ARCMode    = $ARCMode ?? 'app';
$ARCActiveNav = $ARCActiveNav ?? '';
$ARCHeaderActions = $ARCHeaderActions ?? '';

/* Preferências do usuário (densidade, fonte, menu, formatos de data/hora). */
$arcPrefs = [];
if (function_exists('arc_is_user') && arc_is_user()) {
    try {
        $arcPrefs = arc_user_prefs(arc_db());
    } catch (Throwable $e) {
        error_log('Arcoll prefs: ' . $e->getMessage());
    }
}
$GLOBALS['arcPrefs'] = $arcPrefs;
$arcBodyClass = trim(
    (($arcPrefs['density'] ?? '') === 'compact' ? 'density-compact ' : '')
    . ($ARCMode === 'app' ? 'arc-workspace ' : '')
    . 'font-' . (string)($arcPrefs['font_size'] ?? 'normal')
    . (($arcPrefs['reduce_motion'] ?? '0') === '1' ? ' reduce-motion' : '')
);

/* Marca compartilhada por todas as páginas do Workspace. */
$arcBrand   = function_exists('arc_public_settings') ? arc_public_settings(arc_db()) : [];
$arcLogo    = trim((string)($arcBrand['logo_path'] ?? '')) !== ''
    ? 'api/brand-logo.php?v=' . substr(hash('sha256', (string)$arcBrand['logo_path']), 0, 12)
    : 'assets/img/app-mark.svg';
$arcAppIcon = trim((string)($arcBrand['logo_path'] ?? '')) !== '' ? 'api/brand-logo.php?icon=192' : 'assets/img/app-icon-192.png';
$arcSiteName = (string)($arcBrand['site_name'] ?? 'Arcoll Speed Connection');
$arcThemePref=arc_platform_setting(arc_db(),'platform_theme_mode',(string)($arcBrand['default_theme'] ?? 'auto'));
$arcDefaultTheme = in_array($arcThemePref,['auto','light','dark'],true)?$arcThemePref:'auto';
$arcAccent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($arcBrand['accent_color'] ?? '')) ? $arcBrand['accent_color'] : '#22d3ee';

$arcNotice = (string)($_SESSION['arc_flash'] ?? '');
$arcThemeSettings = [];
try {
 foreach(['platform_theme_mode','accent_color','platform_sidebar_color','platform_header_color','platform_footer_color','platform_border_radius','platform_font_family'] as $k)
  $arcThemeSettings[$k]=arc_platform_setting(arc_db(),$k,'');
} catch(Throwable $e){error_log('Tema Arcoll: '.$e->getMessage());}

unset($_SESSION['arc_flash']);
?><!doctype html>
<html lang="pt-BR" data-arc-theme="<?=arc_h($arcDefaultTheme)?>">
<head>
<meta charset="utf-8">
<base href="<?=arc_h(arc_workspace_base_url())?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#183249">
<link rel="icon" href="<?=arc_h($arcAppIcon)?>"><link rel="shortcut icon" href="<?=arc_h($arcAppIcon)?>"><link rel="apple-touch-icon" sizes="192x192" href="<?=arc_h($arcAppIcon)?>">


<title><?= arc_h(($ARCPageTitle ?? $arcSiteName) . ' · ' . $arcSiteName) ?></title><link rel="stylesheet" href="assets/vendor/font-awesome/css/font-awesome.min.css"><link rel="stylesheet" href="assets/css/arcoll.css?v=<?= rawurlencode((string)(@filemtime(dirname(__DIR__) . '/assets/css/arcoll.css') ?: 1)) ?>">

    
    <link rel="manifest" href="manifest.webmanifest">
<link rel="stylesheet" href="assets/css/mobile-app.css?v=<?=filemtime(dirname(__DIR__).'/assets/css/mobile-app.css')?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<style>
.arc-toast,.flash.arc-toast{background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 12%,rgba(255,255,255,.78))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 35%,transparent)!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 12px 35px #152a4830}
.erp-user-button .erp-avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit}
</style><link rel="stylesheet" href="assets/css/appearance.css?v=2.8.0"><script src="assets/js/appearance.js?v=2.8.0"></script></head>
<body class="<?= arc_h(trim($arcBodyClass . ' ' . ($ARCPageBodyClass ?? ''))) ?>" style="<?php
$vars=['accent_color'=>'--arc-brand-accent','platform_sidebar_color'=>'--arc-sidebar-bg','platform_header_color'=>'--arc-header-bg','platform_footer_color'=>'--arc-footer-bg'];
foreach($vars as $key=>$var){$v=$arcThemeSettings[$key]??'';if(preg_match('/^#[0-9a-fA-F]{6}$/',$v))echo $var.':'.arc_h($v).';';}
$radius=$arcThemeSettings['platform_border_radius']??'';if($radius!==''&&ctype_digit($radius))echo '--arc-card-radius:'.min(28,(int)$radius).'px;';
?>" data-platform-theme="<?=arc_h($arcThemeSettings['platform_theme_mode']?:'auto')?>">
<script>window.ARC_PREFS = <?= arc_escape_json($arcPrefs ?: new stdClass()) ?>;</script>

<?php
$arcFlashText = $arcNotice !== '' ? $arcNotice : (isset($arcError) ? (string)$arcError : '');
$arcFlashErr  = $arcNotice === '' && $arcFlashText !== '';
if ($arcFlashText !== ''): ?>
    <div class="flash arc-toast<?= $arcFlashErr ? ' error' : '' ?>" id="arc-flash" role="status"><?= arc_h($arcFlashText) ?></div>
<?php endif; ?>

<?php if ($ARCMode === 'app'): ?>
<header class="app-header">
<button class="arc-header-sidebar-toggle" type="button" id="sidebar-collapse" aria-controls="app-sidebar" aria-expanded="true" aria-label="Recolher menu lateral" title="Recolher / expandir menu lateral"><i class="fa fa-angle-double-left" aria-hidden="true" data-sidebar-toggle-icon></i></button>
<button class="arc-mobile-menu" type="button" id="arc-mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Abrir menu"><i class="fa fa-bars" aria-hidden="true"></i></button>
    <?php $arcNetworkHeader = ($ARCPageBodyClass ?? '') === 'arc-page-redes' && isset($network,$networkId) && is_array($network) && (int)($network['id']??0)===(int)$networkId; ?>
    <?php if ($arcNetworkHeader): ?>
    <div class="page-context arc-header-network">
        <span class="arc-header-network-logo"><?php if (!empty($network['logo_file'])): ?><img src="api/network-logo.php?network=<?= (int)$networkId ?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i><?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?></span>
        <span class="arc-header-network-info"><strong title="<?= arc_h((string)$network['name']) ?>"><?= arc_h((string)$network['name']) ?></strong><small><?= arc_h((string)($network['site_code']??'')) ?></small></span>
    </div>
    <?php else: ?>
    <div class="page-context"><div class="crumb"><?=arc_h($arcSiteName)?></div><div class="top-title"><?= arc_h($ARCPageTitle ?? '') ?></div></div>
    <?php endif; ?>

    <div class="header-actions">
        <?php if (function_exists('arc_is_user') && (arc_is_user() || arc_is_admin())): ?>
        <button type="button" id="arc-network-global-overview" class="arc-net-status-badge arc-network-alert-trigger" data-agent-state="<?= arc_h(isset($panelNetworks) && count($panelNetworks) ? (($panelOffline ?? 0) === 0 ? 'online' : (($panelOffline / count($panelNetworks)) > 0.30 ? 'offline' : 'partial')) : 'none') ?>" data-health="<?= arc_h(isset($panelNetworks) && count($panelNetworks) ? (($panelOffline ?? 0) === 0 ? 'healthy' : (($panelOffline / count($panelNetworks)) > 0.30 ? 'critical' : 'warning')) : 'unknown') ?>" aria-label="Consultar situação das redes" title="Consultar situação das redes" aria-haspopup="dialog" aria-controls="arc-network-alert-dialog"><i class="fa fa-desktop" aria-hidden="true"></i></button>
        <?php endif; ?>
        <nav class="arc-header-nav" aria-label="Menu principal"><?php foreach(arc_v15_menu_items($pdo,'header') as $arcTopLink): ?><a href="<?=arc_h($arcTopLink['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h($arcTopLink['icon'])?>" aria-hidden="true"><i class="fa fa-circle-o" aria-hidden="true"></i></span><?=arc_h($arcTopLink['label'])?></a><?php endforeach;?></nav>
        <?= $ARCHeaderActions ?>
        <?php if (function_exists('arc_is_user') && (arc_is_user() || arc_is_admin())): ?>
            <?php
            $arcHeaderUser = [];
            $arcHeaderNotices = [];
            try {
                if (arc_is_admin()) {
                    $arcHeaderUser=['display_name'=>'Administrador','email'=>(string)($_SESSION['arc_admin_email']??'')];
                    $arcAdminId=(int)arc_admin_id();
                    if($arcAdminId>0){
                        $arcHS=arc_db()->prepare('SELECT setting_key,setting_value FROM '.arc_table('settings').' WHERE setting_key IN (?,?)');
                        $arcHS->execute(['admin_name_'.$arcAdminId,'admin_avatar_'.$arcAdminId]);
                        $arcHP=$arcHS->fetchAll(PDO::FETCH_KEY_PAIR);
                        $arcHeaderUser['display_name']=(string)($arcHP['admin_name_'.$arcAdminId]??($_SESSION['arc_admin_name']??'Administrador'));
                        $arcAvatar=(string)($arcHP['admin_avatar_'.$arcAdminId]??'');
                        if(preg_match('~^uploads/avatars/admin_[0-9]+_[a-f0-9]{24}\.(?:jpg|png|webp)$~',$arcAvatar)){
                            $arcHeaderUser['avatar_url']=rtrim(arc_workspace_base_url(),'/').'/'.$arcAvatar;
                        }
                    }
                } else {
                $arcHU = arc_db()->prepare('SELECT display_name,email,avatar_url FROM ' . arc_table('user_accounts') . ' WHERE id=? LIMIT 1');
                $arcHU->execute([arc_user_id()]);
                $arcHeaderUser = $arcHU->fetch() ?: [];
                $arcHN = arc_db()->prepare('SELECT id,event_type,details,created_at FROM ' . arc_table('account_audit') . ' WHERE account_id=? ORDER BY created_at DESC LIMIT 12');
                $arcHN->execute([arc_user_id()]);
                $arcHeaderNotices = $arcHN->fetchAll() ?: [];
                }
            } catch (Throwable $e) { $arcHeaderNotices = []; }
            $arcHeaderName = (string)($arcHeaderUser['display_name'] ?? $arcHeaderUser['email'] ?? 'Usuário');
            $arcHeaderAvatar = trim((string)($arcHeaderUser['avatar_url'] ?? ''));
            $arcHeaderProfile = arc_is_admin() ? 'Administrador' : (arc_is_user() ? 'Usuário / Gestor' : 'Visitante');
            ?>
            <div class="arc-notify-wrap">
                <button class="arc-notify-button" type="button" data-arc-notify aria-label="Notificações" aria-expanded="false"><i class="fa fa-bell-o" aria-hidden="true"></i><span class="arc-notify-badge" data-arc-notify-count hidden></span></button>
                <div class="arc-notify-menu" data-arc-notify-menu>
                    <div class="arc-drop-title">Notificações <small>Atividades recentes da conta</small></div>
                    <div data-arc-notify-list>
                    <?php if (!$arcHeaderNotices): ?><div class="arc-drop-empty">Nenhuma notificação disponível.</div><?php else: foreach ($arcHeaderNotices as $arcN): ?>
                        <button type="button" class="arc-notify-item" data-notice-id="<?= (int)$arcN['id'] ?>" data-notice-title="<?= arc_h(ucwords(str_replace('_',' ',(string)$arcN['event_type']))) ?>" data-notice-detail="<?= arc_h((string)$arcN['details']) ?>" data-notice-date="<?= arc_h(arc_when($arcN['created_at'])) ?>">
                            <b><?= arc_h(ucwords(str_replace('_',' ',(string)$arcN['event_type']))) ?></b><span><?= arc_h((string)$arcN['details']) ?></span><small><?= arc_h(arc_when($arcN['created_at'])) ?></small>
                        </button>
                    <?php endforeach; endif; ?>
                    </div>
                    <a class="arc-drop-all" href="pages/painel.php">Ver atividades</a>
                </div>
            </div>
            <div class="erp-user-wrap" data-erp-user-wrap>
                <button class="erp-user-button" type="button" data-erp-user aria-expanded="false">
                    <span class="erp-avatar"><?php if ($arcHeaderAvatar !== ''): ?><img src="<?= arc_h($arcHeaderAvatar) ?>" alt=""><?php else: ?><?= arc_h(mb_strtoupper(mb_substr($arcHeaderName,0,1))) ?><?php endif; ?></span>
                    
                </button>
                <div class="erp-user-menu" data-erp-user-menu>
                    <div class="arc-user-menu-profile"><span class="erp-avatar"><?php if ($arcHeaderAvatar !== ''): ?><img src="<?= arc_h($arcHeaderAvatar) ?>" alt=""><?php else: ?><?= arc_h(mb_strtoupper(mb_substr($arcHeaderName,0,1))) ?><?php endif; ?></span><span><b><?=arc_h($arcHeaderName)?></b><small><?=arc_h($arcHeaderProfile)?></small></span></div>
                    <?php foreach(arc_v15_menu_items($pdo,'user') as $arcHeaderLink): ?>
                    <a href="<?=arc_h($arcHeaderLink['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h($arcHeaderLink['icon'])?>" aria-hidden="true"><i class="fa fa-circle-o" aria-hidden="true"></i></span><?=arc_h($arcHeaderLink['label'])?></a>
                    <?php endforeach; ?>
                    <div class="erp-menu-sep"></div>
                    <form method="post" action="<?='pages/painel.php'?>"><input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="erp-menu-danger" type="submit">Sair da conta</button></form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
<div id="arc-network-alert-dialog" class="arc-network-alert-overlay" hidden role="presentation"><div class="arc-network-alert-modal" role="dialog" aria-modal="true" aria-labelledby="arc-network-alert-title" tabindex="-1"><div class="arc-network-alert-head"><strong id="arc-network-alert-title"><i class="fa fa-desktop" aria-hidden="true"></i> Situação das redes</strong><button type="button" data-network-alert-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div><p id="arc-network-alert-summary" role="status">Consultando redes...</p><div id="arc-network-alert-list" class="arc-network-alert-list"></div></div></div>
<div class="arc-notice-modal" data-arc-notice-modal aria-hidden="true" role="presentation"><div class="arc-notice-card" role="dialog" aria-modal="true" aria-labelledby="arc-notice-title" tabindex="-1"><button type="button" data-arc-notice-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button><small data-arc-notice-date></small><h2 id="arc-notice-title"></h2><p data-arc-notice-detail></p><a href="pages/painel.php" class="btn secondary">Abrir atividades</a></div></div>
<div class="app-body">
<?php endif; ?>