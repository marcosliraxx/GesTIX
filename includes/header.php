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

/* Preferências globais: mesmas opções para toda a plataforma. */
$arcPrefs=[];
try{$arcPrefs=arc_system_prefs(arc_db());}
catch(Throwable $e){error_log('Arcoll preferências globais: '.$e->getMessage());
    foreach(arc_pref_defs() as $key=>$def)$arcPrefs[$key]=(string)$def['default'];
}
$GLOBALS['arcPrefs']=$arcPrefs;
$arcBodyClass=trim(
    (($arcPrefs['density']??'')==='compact'?'density-compact ':'')
    .($ARCMode==='app'?'arc-workspace ':'')
    .'font-'.(string)($arcPrefs['font_size']??'normal')
    .(($arcPrefs['reduce_motion']??'0')==='1'?' reduce-motion':'')
);
if(($arcPrefs['sidebar_default']??'expanded')==='hidden'&&$ARCMode==='app')
    $arcBodyClass=trim($arcBodyClass.' sidebar-hidden');

/* Marca compartilhada por todas as páginas do Workspace. */
$arcBrand   = function_exists('arc_public_settings') ? arc_public_settings(arc_db()) : [];
$arcLogo    = trim((string)($arcBrand['logo_path'] ?? '')) !== ''
    ? 'api/brand-logo.php?v=' . substr(hash('sha256', (string)$arcBrand['logo_path']), 0, 12)
    : 'assets/img/app-mark.svg';
$arcAppIcon = trim((string)($arcBrand['logo_path'] ?? '')) !== '' ? 'api/brand-logo.php?icon=192' : 'assets/img/app-icon-192.png';
$arcSiteName = (string)($arcBrand['site_name'] ?? 'Arcoll Speed Connection');
$arcSiteDesc = (string)($arcBrand['tagline'] ?? '');
$arcThemePref=arc_platform_setting(arc_db(),'platform_system_pref_theme',arc_platform_setting(arc_db(),'platform_theme_mode',(string)($arcBrand['default_theme'] ?? 'auto')));
$arcDefaultTheme = in_array($arcThemePref,['auto','light','dark'],true)?$arcThemePref:'auto';
$arcAccent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($arcBrand['accent_color'] ?? '')) ? $arcBrand['accent_color'] : '#22d3ee';

$arcNotice = (string)($_SESSION['arc_flash'] ?? '');
$arcThemeSettings = [];
try {
 foreach(['platform_theme_mode','accent_color','platform_sidebar_color','platform_header_color','platform_footer_color','platform_border_radius','platform_font_family'] as $k)
  $arcThemeSettings[$k]=arc_platform_setting(arc_db(),$k,'');
} catch(Throwable $e){error_log('Tema Arcoll: '.$e->getMessage());}

unset($_SESSION['arc_flash']);
/* Caminhos estáveis e versões de assets baseadas no conteúdo real dos arquivos. */
$arcAssetBase = rtrim(arc_workspace_base_url(), '/') . '/';
$arcAssetVersion = static function (string $relativePath): string {
    $absolutePath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    return arc_app_version().'-'.(is_file($absolutePath)?substr((string)hash_file('sha256',$absolutePath),0,12):'missing');
};
?><!doctype html>
<html lang="pt-BR" data-arc-theme="<?=arc_h($arcDefaultTheme)?>" data-arc-system-theme="<?=arc_h($arcDefaultTheme)?>">
<head>
<meta charset="utf-8">
<base href="<?=arc_h(arc_workspace_base_url())?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#183249">
<link rel="icon" href="<?=arc_h($arcAppIcon)?>"><link rel="shortcut icon" href="<?=arc_h($arcAppIcon)?>"><link rel="apple-touch-icon" sizes="192x192" href="<?=arc_h($arcAppIcon)?>">


<title><?= arc_h(($ARCPageTitle ?? $arcSiteName) . ' · ' . $arcSiteName) ?></title>
<link rel="stylesheet" href="<?=arc_h($arcAssetBase)?>assets/vendor/font-awesome/css/font-awesome.min.css">
<?php
/* Servir o CSS original, diretamente do arquivo-fonte local, sem depender de uma resposta estática HTTP.
   O mesmo arquivo continua como fonte única, sem cópia de regras ou CSS adicional. */
$arcCssPaths = ['assets/css/arcoll.css'];
if (($ARCPageBodyClass ?? '') === 'arc-page-redes') $arcCssPaths[] = 'assets/css/redes.css';
foreach ($arcCssPaths as $arcCssRelative) {
    $arcCssFile = dirname(__DIR__) . '/' . $arcCssRelative;
    if (is_file($arcCssFile) && is_readable($arcCssFile)) {
        echo '<style data-arc-src="', arc_h($arcCssRelative), '">';
        readfile($arcCssFile);
        echo '</style>';
    } else {
        error_log('CSS principal indisponível: ' . $arcCssFile);
    }
}
?>
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<style>
.arc-toast{background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 12%,rgba(255,255,255,.78))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 35%,transparent)!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 12px 35px #152a4830}
.flash.arc-toast{background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 12%,rgba(255,255,255,.78))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 35%,transparent)!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 12px 35px #152a4830}
.erp-user-button .erp-avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit}
</style><script src="<?=arc_h($arcAssetBase)?>assets/js/arcoll.js?v=<?=arc_h($arcAssetVersion('assets/js/arcoll.js'))?>" defer></script><script>try{const c=document.documentElement.dataset.arcSystemTheme||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head>
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
    <?php /* A identidade já aparece no seletor: uma única logo também no celular. */ ?>
    <?php
    /* Redes autorizadas: próprias e compartilhadas ativas. Sem expor dados de outras contas. */
    $arcHeaderNetworks = [];
    try {
        $arcNetworkSelectorSql = 'SELECT n.id,n.name,n.site_code,n.logo_file,0 AS shared FROM '.arc_table('managed_networks').' n WHERE n.account_id=? AND n.is_active=1 '
            .'UNION ALL SELECT n.id,n.name,n.site_code,n.logo_file,1 AS shared FROM '.arc_table('network_team_members').' m '
            .'JOIN '.arc_table('managed_networks').' n ON n.id=m.network_id AND n.account_id=m.account_id '
            .'WHERE m.member_user_id=? AND m.enabled=1 AND n.is_active=1 AND n.account_id<>? '
            .'ORDER BY name,id';
        $arcNetworkSelectorStmt = arc_db()->prepare($arcNetworkSelectorSql);
        $arcNetworkSelectorStmt->execute([(int)arc_user_id(),(int)arc_user_id(),(int)arc_user_id()]);
        $arcHeaderNetworks = $arcNetworkSelectorStmt->fetchAll(PDO::FETCH_ASSOC);
        /* Mesmo critério de presença do painel: agente aprovado visto nos últimos 12 segundos. */
        $arcStatusSql = 'SELECT n.id, COUNT(a.id) AS agent_total, COALESCE(SUM(CASE WHEN a.last_seen_at >= DATE_SUB(NOW(),INTERVAL 12 SECOND) THEN 1 ELSE 0 END),0) AS agent_online '
            .'FROM '.arc_table('managed_networks').' n LEFT JOIN '.arc_table('network_agents').' a ON a.network_id=n.id AND a.account_id=n.account_id AND a.status=\'approved\' '
            .'WHERE n.id IN ('.implode(',', array_fill(0,count($arcHeaderNetworks),'?')).') GROUP BY n.id';
        if ($arcHeaderNetworks) {
            $arcStatusStmt=arc_db()->prepare($arcStatusSql);
            $arcStatusStmt->execute(array_map('intval',array_column($arcHeaderNetworks,'id')));
            $arcStatuses=[];
            foreach($arcStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $arcStateRow) $arcStatuses[(int)$arcStateRow['id']]=$arcStateRow;
            foreach($arcHeaderNetworks as &$arcSwitchEntry){
                $arcCounts=$arcStatuses[(int)$arcSwitchEntry['id']]??[];
                $arcSwitchEntry['is_online']=(int)($arcCounts['agent_online']??0)>0;
            }
            unset($arcSwitchEntry);
        }
    } catch (Throwable $arcNetSelectorError) {
        error_log('Seletor de redes: '.$arcNetSelectorError->getMessage());
    }
    ?>
    <div class="page-context arc-header-network arc-network-switch-wrap <?=$arcNetworkHeader?'arc-switch-network-mode':'arc-switch-system-mode'?>" id="arc-network-switch-wrap">
        <?php
        $arcSelectedNetworkId = $arcNetworkHeader ? (int)$networkId : 0;
        $arcActiveNetOnline=false;
        foreach($arcHeaderNetworks as $arcSwitchEntry) if ((int)$arcSwitchEntry['id']===(int)$arcSelectedNetworkId) { $arcActiveNetOnline=!empty($arcSwitchEntry['is_online']); break; }
        $arcCurrentAgentVersion = '--';
        if (isset($networkAgents) && is_array($networkAgents)) {
            foreach ($networkAgents as $arcHeaderAgent) {
                if ((int)($arcHeaderAgent['id'] ?? 0) === (int)($selectedAgentId ?? 0)) {
                    $arcCurrentAgentVersion = (string)($arcHeaderAgent['agent_version'] ?? '--');
                    break;
                }
            }
            if ($arcCurrentAgentVersion === '--' && !empty($networkAgents)) {
                $arcCurrentAgentVersion = (string)($networkAgents[0]['agent_version'] ?? '--');
            }
        }
        $arcCurrentAgentVersion = trim($arcCurrentAgentVersion) ?: '--';
        ?>
        <?php if ($arcNetworkHeader): ?>
        <button type="button" class="arc-network-switch-trigger" id="arc-network-switch-trigger" aria-haspopup="true" aria-expanded="false" aria-controls="arc-network-switch-menu" title="Selecionar outra rede">
            <span class="arc-header-network-logo <?=$arcActiveNetOnline?'is-online':'is-offline'?>">
                <?php if (!empty($network['logo_file'])): ?>
                <img src="<?=arc_h(arc_workspace_base_url().'api/network-logo.php?network='.(int)$networkId)?>" alt="Logo da rede ativa" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i>
                <?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?>
            </span>
            <span class="arc-header-network-info"><strong title="<?=arc_h((string)$network['name'])?>"><?=arc_h((string)$network['name'])?></strong><small><span class="arc-header-network-code"><?=arc_h((string)($network['site_code']??''))?></span><span id="arc-agent-version-info" class="arc-header-network-version" title="Versão instalada do agente">v. <?=arc_h($arcCurrentAgentVersion)?></span></small></span>
            <i class="fa fa-chevron-down arc-network-switch-chevron" aria-hidden="true"></i>
        </button>
        <?php else: ?>
        <button type="button" class="arc-network-switch-trigger arc-system-switch-trigger" id="arc-network-switch-trigger" aria-haspopup="true" aria-expanded="false" aria-controls="arc-network-switch-menu" title="Selecionar rede cadastrada">
          <img class="arc-global-header-logo" src="<?=arc_h($arcLogo)?>" alt="Logo do sistema">
          <span class="arc-global-page-titles"><span class="crumb"><?=arc_h($arcSiteName)?></span><strong class="top-title"><?=arc_h($ARCPageTitle ?? '')?></strong></span>
          <i class="fa fa-chevron-down arc-network-switch-chevron" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
        <div class="arc-network-switch-menu" id="arc-network-switch-menu" hidden>
            <a class="arc-network-switch-head" href="pages/painel.php" title="Abrir painel principal do sistema">
                <img src="<?=arc_h($arcLogo)?>" alt=""><span><strong><?=arc_h($arcSiteName)?></strong><small>Ir para o painel do sistema</small></span><i class="fa fa-external-link" aria-hidden="true"></i>
            </a>
            <div class="arc-network-switch-scroll" role="navigation" aria-label="Redes cadastradas">
                <?php if (!$arcHeaderNetworks): ?><p class="arc-network-switch-empty">Nenhuma outra rede disponível.</p><?php endif; ?>
                <?php foreach ($arcHeaderNetworks as $arcSwitchNet): $arcSwitchId=(int)$arcSwitchNet['id']; ?>
                <a href="<?=arc_h(arc_workspace_base_url().'redes/painel.php?network='.$arcSwitchId)?>" class="arc-network-switch-item <?=$arcSwitchId===$arcSelectedNetworkId?'is-selected':''?> <?=!empty($arcSwitchNet['is_online'])?'is-online':'is-offline'?>" <?=$arcSwitchId===$arcSelectedNetworkId?'aria-current="page"':''?>>
                    <span class="arc-network-switch-logo">
                        <?php if (!empty($arcSwitchNet['logo_file'])): ?><img src="<?=arc_h(arc_workspace_base_url().'api/network-logo.php?network='.$arcSwitchId)?>" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i><?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?>
                    </span>
                    <span class="arc-network-switch-desc"><strong><?=arc_h((string)$arcSwitchNet['name'])?></strong><small><span class="arc-net-availability <?=!empty($arcSwitchNet['is_online'])?'is-online':'is-offline'?>"><?=!empty($arcSwitchNet['is_online'])?'Online':'Offline'?></span> · <?=arc_h((string)$arcSwitchNet['site_code'])?><?=!empty($arcSwitchNet['shared'])?' · Compartilhada':''?></small></span>
                    <?php if ($arcSwitchId===$arcSelectedNetworkId): ?><i class="fa fa-check-circle arc-network-switch-check" aria-hidden="true"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if (arc_is_user()): ?><button class="arc-network-switch-add" type="button" id="arc-network-switch-add"><i class="fa fa-plus-circle" aria-hidden="true"></i> Adicionar nova rede</button><?php endif; ?>
        </div>
    </div>
    <script>
    (function(){
      'use strict';
      var wrap=document.getElementById('arc-network-switch-wrap'),btn=document.getElementById('arc-network-switch-trigger'),menu=document.getElementById('arc-network-switch-menu');
      if(!wrap||!btn||!menu)return;
      function toggle(open){menu.hidden=!open;btn.setAttribute('aria-expanded',String(open));wrap.classList.toggle('is-open',open);}
      btn.addEventListener('click',function(e){e.stopPropagation();toggle(menu.hidden);});
      var add=document.getElementById('arc-network-switch-add');
      if(add)add.addEventListener('click',function(){toggle(false);var trigger=document.querySelector('[data-open-register]');
          if(trigger)trigger.click();else document.getElementById('modal-register')?.classList.add('open');});
      document.addEventListener('click',function(e){if(!wrap.contains(e.target))toggle(false);});
      document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!menu.hidden){toggle(false);btn.focus();}});
    })();
    </script>


    <div class="header-actions"><button type="button" data-mobile-more class="arc-tablet-more" aria-label="Mais opções"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></button>

        <?php if (!$arcNetworkHeader && function_exists('arc_is_user') && (arc_is_user() || arc_is_admin())): ?>
        <button type="button" id="arc-network-global-overview" class="arc-net-status-badge arc-network-alert-trigger" data-agent-state="<?= arc_h(isset($panelNetworks) && count($panelNetworks) ? (($panelOffline ?? 0) === 0 ? 'online' : (($panelOffline / count($panelNetworks)) > 0.30 ? 'offline' : 'partial')) : 'none') ?>" data-health="<?= arc_h(isset($panelNetworks) && count($panelNetworks) ? (($panelOffline ?? 0) === 0 ? 'healthy' : (($panelOffline / count($panelNetworks)) > 0.30 ? 'critical' : 'warning')) : 'unknown') ?>" aria-label="Consultar situação das redes" title="Consultar situação das redes" aria-haspopup="dialog" aria-controls="arc-network-alert-dialog"><i class="fa fa-desktop" aria-hidden="true"></i></button>
        <?php endif; ?>
        <nav class="arc-header-nav" aria-label="Menu principal"><?php foreach(arc_navigation_items($pdo,'header') as $arcTopLink): ?><a class="<?=arc_h($arcTopLink['device_class'])?>" href="<?=arc_h($arcTopLink['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h($arcTopLink['icon'])?>" aria-hidden="true"><i class="fa fa-circle-o" aria-hidden="true"></i></span><?=arc_h($arcTopLink['label'])?></a><?php endforeach;?></nav>
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
<?php if ($arcNetworkHeader): ?>
<form class="arc-redes-header-controls" id="arc-agent-form" method="get" action="" aria-label="Controle do agente">
  <input type="hidden" name="network" value="<?= (int)$networkId ?>">
  <div class="arc-redes-header-left">
   <?php
    $arcAgentOnline=false;
    foreach($networkAgents as $a){if((int)$a['id']===$selectedAgentId){$arcAgentOnline=($a['status']??'')==='approved' && !empty($a['last_seen_at']) && strtotime((string)$a['last_seen_at'])>=time()-12;break;}}
   ?>
   <div class="arc-agent-selector-group">
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
   <button type="button" class="arc-net-status-badge" id="arc-network-global-state" data-network-status-id="<?= (int)$networkId ?>" data-agent-state="none" title="Atualizar situação do agente" aria-label="Atualizar situação do agente"><i class="fa fa-desktop" aria-hidden="true"></i></button>
   </div>
  </div>

 </form>
<?php endif; ?>
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
                    <?php
                    /* Dropdown do usuário reflete o menu lateral em tempo real, sem duplicar registros no banco.
                       Mantém permissões, grupo e estado enabled do mecanismo de navegação. */
                    $arcUserMenuItems = array_merge(arc_navigation_items($pdo,'sidebar'), arc_navigation_items($pdo,'user'));
                    $arcUserMenuSeen = [];
                    $arcUserMenuIds = array_map(static fn(array $it): int => (int)$it['id'], $arcUserMenuItems);
                    ?>
                    <div class="arc-user-menu-links" role="navigation" aria-label="Menus disponíveis">
                    <?php foreach ($arcUserMenuItems as $arcHeaderLink):
                        $arcUserHref = (string)($arcHeaderLink['url'] ?? '');
                        if ($arcUserHref === '' || $arcUserHref === '#') {
                            /* Grupos sem URL permanecem identificados no dropdown. */
                            $arcUserGroupHasChildren = false;
                            foreach ($arcUserMenuItems as $arcCandidate) {
                                if ((int)($arcCandidate['parent_id'] ?? 0) === (int)$arcHeaderLink['id']) { $arcUserGroupHasChildren = true; break; }
                            }
                            if ($arcUserGroupHasChildren): ?>
                            <div class="arc-user-menu-group-title"><span class="arc-db-icon" data-icon="<?=arc_h($arcHeaderLink['icon'])?>" aria-hidden="true"></span><?=arc_h($arcHeaderLink['label'])?></div>
                            <?php endif;
                            continue;
                        }
                        $arcUserKey = $arcUserHref;
                        if (isset($arcUserMenuSeen[$arcUserKey])) continue;
                        $arcUserMenuSeen[$arcUserKey] = true;
                        $arcUserParent = (int)($arcHeaderLink['parent_id'] ?? 0);
                        $arcUserIsChild = $arcUserParent > 0 && in_array($arcUserParent, $arcUserMenuIds, true);
                    ?>
                      <a class="arc-user-menu-link <?= $arcUserIsChild?'arc-user-menu-child':'' ?>" href="<?=arc_h($arcUserHref)?>"><span class="arc-db-icon" data-icon="<?=arc_h($arcHeaderLink['icon'])?>" aria-hidden="true"><i class="fa fa-circle-o" aria-hidden="true"></i></span><span><?=arc_h($arcHeaderLink['label'])?></span></a>
                    <?php endforeach; ?>
                    </div>
                    <div class="erp-menu-sep"></div>
                    <form method="post" action="<?='pages/painel.php'?>"><input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="erp-menu-danger" type="submit">Sair da conta</button></form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
<?php if (arc_is_user()): ?>
<button type="button" data-open-register hidden aria-hidden="true" tabindex="-1"></button>
<?php if (strpos(str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME'] ?? '')), '/pages/painel.php') === false): ?>
<?php require __DIR__.'/network-register-modal.php'; ?>
<?php endif; ?>
<?php endif; ?>
<div id="arc-network-alert-dialog" class="arc-network-alert-overlay" hidden role="presentation"><div class="arc-network-alert-modal" role="dialog" aria-modal="true" aria-labelledby="arc-network-alert-title" tabindex="-1"><div class="arc-network-alert-head"><strong id="arc-network-alert-title"><i class="fa fa-desktop" aria-hidden="true"></i> Situação das redes</strong><button type="button" data-network-alert-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div><p id="arc-network-alert-summary" role="status">Consultando redes...</p><div id="arc-network-alert-list" class="arc-network-alert-list"></div></div></div>
<div class="arc-notice-modal" data-arc-notice-modal aria-hidden="true" role="presentation"><div class="arc-notice-card" role="dialog" aria-modal="true" aria-labelledby="arc-notice-title" tabindex="-1"><button type="button" data-arc-notice-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button><small data-arc-notice-date></small><h2 id="arc-notice-title"></h2><p data-arc-notice-detail></p><a href="pages/painel.php" class="btn secondary">Ver painel</a></div></div>
<div class="app-body">
<?php endif; ?>