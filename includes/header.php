<?php
/* Caminho: includes/header.php */
declare(strict_types=1);


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

    
    <?php if (($ARCPageBodyClass ?? '') === 'arc-page-redes'): ?>
<link rel="stylesheet" href="assets/css/redes.css?v=<?= rawurlencode((string)(@filemtime(dirname(__DIR__) . '/assets/css/redes.css') ?: 1)) ?>">
<?php endif; ?>
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<style>
.arc-toast{background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 12%,rgba(255,255,255,.78))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 35%,transparent)!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 12px 35px #152a4830}
.flash.arc-toast{background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 12%,rgba(255,255,255,.78))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 35%,transparent)!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 12px 35px #152a4830}
.erp-user-button .erp-avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit}
</style><script src="assets/js/arcoll.js?v=3.3.3" defer></script><script>try{const c=localStorage.getItem('arc_appearance')||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head>
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

    <?php $arcNetworkHeader = ($ARCPageBodyClass ?? '') === 'arc-page-redes' && isset($network,$networkId) && is_array($network) && (int)($network['id']??0)===(int)$networkId; ?>
    <a class="arc-mobile-brand" href="<?=$arcNetworkHeader?'redes/servidor.php?network='.(int)$networkId:'pages/painel.php'?>" aria-label="<?=arc_h($arcNetworkHeader?$network['name']:$arcSiteName)?>"><?php if($arcNetworkHeader):?><?php if(!empty($network['logo_file'])):?><img src="api/network-logo.php?network=<?=(int)$networkId?>" alt="<?=arc_h($network['name'])?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i><?php else:?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif;?><?php else:?><img src="<?=arc_h($arcLogo)?>" alt="<?=arc_h($arcSiteName)?>"><?php endif;?></a>
    <?php if ($arcNetworkHeader): ?>
    <div class="page-context arc-header-network">
        <span class="arc-header-network-logo"><?php if (!empty($network['logo_file'])): ?><img src="api/network-logo.php?network=<?= (int)$networkId ?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i><?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?></span>
        <span class="arc-header-network-info"><strong title="<?= arc_h((string)$network['name']) ?>"><?= arc_h((string)$network['name']) ?></strong><small><?= arc_h((string)($network['site_code']??'')) ?></small></span>
    </div>
    <?php else: ?>
    <div class="page-context"><div class="crumb"><?=arc_h($arcSiteName)?></div><div class="top-title"><?= arc_h($ARCPageTitle ?? '') ?></div></div>
    <?php endif; ?>

    <div class="header-actions">
<?php if ($arcNetworkHeader): ?>
<form class="arc-redes-header-controls" id="arc-agent-form" method="get" action="" aria-label="Controle do agente">
  <input type="hidden" name="network" value="<?= (int)$networkId ?>">
  <div class="arc-redes-header-left">
   <?php
    $arcAgentOnline=false;
    foreach($networkAgents as $a){if((int)$a['id']===$selectedAgentId){$arcAgentOnline=($a['status']??'')==='approved' && !empty($a['last_seen_at']) && strtotime((string)$a['last_seen_at'])>=time()-12;break;}}
   ?>
   <div class="arc-agent-selector-group">
   <button type="button" class="arc-net-status-badge" id="arc-network-global-state" data-network-status-id="<?= (int)$networkId ?>" data-agent-state="none" title="Atualizar situação do agente" aria-label="Atualizar situação do agente"><i class="fa fa-desktop" aria-hidden="true"></i></button>
   <label class="arc-agent-select-wrap <?= $arcAgentOnline?'is-online':'is-offline' ?>" for="arc-agent-target" title="Escolher agente Windows">
    <span id="arc-connection-status" class="arc-agent-state <?= $arcAgentOnline?'is-online':'is-offline' ?>" role="status" title="<?= $arcAgentOnline?'Agente online':'Agente offline' ?>" aria-label="<?= $arcAgentOnline?'Agente online':'Agente offline' ?>"></span>
    <select id="arc-agent-target" name="agent" aria-label="Selecionar agente Windows">
     <option value="">Selecionar agente</option>
     <?php foreach($networkAgents as $a): if(($a['status']??'')!=='approved')continue;
      $arcOptionOnline=!empty($a['last_seen_at']) && strtotime((string)$a['last_seen_at'])>=time()-12;
     ?>
      <option value="<?= (int)$a['id'] ?>" class="<?= $arcOptionOnline?'arc-option-online':'arc-option-offline' ?>" data-headless="<?= (int)($a['headless_mode']??0)===1?'1':'0' ?>" <?= $selectedAgentId===(int)$a['id']?'selected':'' ?>><?= arc_h((string)($a['machine_name']??'Agente')) ?></option>
     <?php endforeach; ?>
    </select>
   </label>
   </div>
  </div>
  <div class="arc-redes-header-right">
   <span id="arc-agent-version-info" class="arc-agent-version" title="Versão instalada do agente">v. --</span>
   <button type="button" id="arc-agent-update-remote" class="arc-identity-icon" data-arc-command="agent_update" disabled title="Reinstalar arquivos do agente Windows (inclusive na mesma versão)" aria-label="Atualizar agente Windows remotamente"><i class="fa fa-refresh" aria-hidden="true"></i></button>
   <?php if ($isNetworkOwner): ?><button type="button" class="arc-identity-icon arc-identity-manage" data-network-modal-open aria-label="Gerenciar redes" title="Gerenciar redes"><i class="fa fa-sliders" aria-hidden="true"></i><?php if ($pendingAgentCount): ?><span class="arc-identity-count"><?= (int)$pendingAgentCount ?></span><?php endif; ?></button><?php endif; ?>
  </div>
 </form>
<?php endif; ?>
        <?php if (!$arcNetworkHeader && function_exists('arc_is_user') && (arc_is_user() || arc_is_admin())): ?>
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
                        $arcHA=arc_db()->prepare("SELECT CONCAT('admin-',id) AS id,event_type,description AS details,created_at FROM ".arc_table('audit_logs').' WHERE admin_id=? ORDER BY id DESC LIMIT 12');$arcHA->execute([$arcAdminId]);$arcHeaderNotices=$arcHA->fetchAll(PDO::FETCH_ASSOC);
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
            /* Alertas reais dos agentes da rede atual, integrados ao sino padrão. */
            if (!empty($remoteEvents) && is_array($remoteEvents) && isset($networkId)) {
                $arcAlertTitles = [
                    'gateway_unreachable'=>['Roteador não respondeu','O computador não conseguiu falar com o roteador. Confira a conexão.'],
                    'high_latency'=>['Internet lenta','A rede demorou mais que o normal para responder.'],
                    'packet_loss'=>['Conexão instável','Algumas tentativas de conexão falharam.'],
                    'high_packet_loss'=>['Conexão instável','Algumas tentativas de conexão falharam.'],
                    'agent_offline'=>['Computador desconectado','O computador parou de enviar informações.'],
                    'agent_online'=>['Computador conectado','O computador voltou a se comunicar com a plataforma.'],
                    'connection_restored'=>['Conexão normalizada','A conexão voltou a funcionar.']
                ];
                foreach (array_slice($remoteEvents, 0, 75) as $arcEvent) {
                    if (($arcEvent['event_type'] ?? '') !== 'alert') continue;
                    $arcData = json_decode((string)($arcEvent['payload_json'] ?? ''), true);
                    if (!is_array($arcData)) $arcData = [];
                    $arcKind = strtolower((string)($arcData['kind'] ?? $arcData['type'] ?? $arcData['event'] ?? ''));
                    [$arcTitle, $arcDesc] = $arcAlertTitles[$arcKind] ?? ['Aviso da rede','O agente enviou um aviso. Verifique o computador.'];
                    $arcMachine = trim((string)($arcEvent['machine_name'] ?? ''));
                    $arcWhen = (string)($arcEvent['captured_at'] ?? '');
                    $arcHeaderNotices[] = [
                        'id'=>'rede-'.(int)$networkId.'-'.(int)($arcEvent['id']??0),
                        'title'=>$arcTitle,
                        'details'=>($arcMachine!==''?$arcMachine.'. ':'').$arcDesc,
                        'created_at'=>$arcWhen,
                        'network_alert'=>true
                    ];
                }
            }
            $arcHeaderName = (string)($arcHeaderUser['display_name'] ?? $arcHeaderUser['email'] ?? 'Usuário');
            $arcHeaderAvatar = trim((string)($arcHeaderUser['avatar_url'] ?? ''));
            $arcHeaderProfile = arc_is_admin() ? 'Administrador' : (arc_is_user() ? 'Usuário / Gestor' : 'Visitante');
            ?>
            <div class="arc-notify-wrap">
                <button class="arc-notify-button" type="button" data-arc-notify aria-label="Notificações" aria-expanded="false"><i class="fa fa-bell-o" aria-hidden="true"></i><span class="arc-notify-badge" data-arc-notify-count hidden></span></button>
                <div class="arc-notify-menu" data-arc-notify-menu>
                    <div class="arc-drop-title">Notificações <small>Avisos e atividades recentes</small></div>
                    <div data-arc-notify-list>
                    <?php if (!$arcHeaderNotices): ?><div class="arc-drop-empty">Nenhuma notificação disponível.</div><?php else: foreach ($arcHeaderNotices as $arcN):
[$arcNoticeTitle,$arcNoticeDetail]=isset($arcN['title'])?[(string)$arcN['title'],(string)$arcN['details']]:arc_notice_copy((string)($arcN['event_type']??'')); ?>
                        <button type="button" class="arc-notify-item" data-notice-id="<?= arc_h((string)$arcN['id']) ?>" data-notice-title="<?= arc_h($arcNoticeTitle) ?>" data-notice-detail="<?= arc_h($arcNoticeDetail) ?>" data-notice-date="<?= arc_h(arc_when($arcN['created_at'])) ?>">
                            <b><?= arc_h($arcNoticeTitle) ?></b><span><?= arc_h($arcNoticeDetail) ?></span><small><?= arc_h(arc_when($arcN['created_at'])) ?></small>
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
                    <label class="arc-appearance-control"><i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label>
                    <div class="erp-menu-sep"></div>
                    <form method="post" action="<?='pages/painel.php'?>"><input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="erp-menu-danger" type="submit">Sair da conta</button></form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
<div id="arc-network-alert-dialog" class="arc-network-alert-overlay" hidden role="presentation"><div class="arc-network-alert-modal" role="dialog" aria-modal="true" aria-labelledby="arc-network-alert-title" tabindex="-1"><div class="arc-network-alert-head"><strong id="arc-network-alert-title"><i class="fa fa-desktop" aria-hidden="true"></i> Situação das redes</strong><button type="button" data-network-alert-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div><p id="arc-network-alert-summary" role="status">Consultando redes...</p><div id="arc-network-alert-list" class="arc-network-alert-list"></div></div></div>
<div class="arc-notice-modal" data-arc-notice-modal aria-hidden="true" role="presentation"><div class="arc-notice-card" role="dialog" aria-modal="true" aria-labelledby="arc-notice-title" tabindex="-1"><button type="button" data-arc-notice-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button><small data-arc-notice-date></small><h2 id="arc-notice-title"></h2><p data-arc-notice-detail></p><a href="pages/painel.php" class="btn secondary">Ver painel</a></div></div>
<div class="app-body">
<?php endif; ?>