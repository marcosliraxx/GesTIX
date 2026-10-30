<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../includes/config.php')) {
    header('Location: ../install.php', true, 302);
    exit;
}
require __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    if (!arc_is_user()) { header('Location: ../index.php', true, 303); exit; }
    try { arc_require_user_network($pdo); }
    catch (Throwable $authError) {
        // Primeiro acesso: selecionar uma rede ativa pertencente ao usuário.
        $pick=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE account_id=? AND is_active=1 ORDER BY id DESC LIMIT 1');
        $pick->execute([arc_user_id()]);
        $networkId=(int)$pick->fetchColumn();
        if($networkId>0 && arc_select_network($pdo,$networkId))arc_require_user_network($pdo);
        else {
            header('Location: '.arc_workspace_base_url().'pages/painel.php',true,303);
            exit;
        }
    }
    $settings = arc_public_settings($pdo);
    $pages = arc_pages($pdo, false);
    $state = arc_app_state($pdo);
    $networkQ=$pdo->prepare('SELECT id,name,local_ip,subnet,gateway FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=?');
    $networkQ->execute([arc_current_network_id(),arc_user_id()]);
    $selectedNetwork=$networkQ->fetch() ?: [];
    $userQ=$pdo->prepare('SELECT email,display_name,avatar_url FROM ' . arc_table('user_accounts') . ' WHERE id=?');
    $userQ->execute([arc_user_id()]);
    $portalUser=$userQ->fetch() ?: [];
    arc_user_audit($pdo,'app_opened','Rede ID '.(int)arc_current_network_id());
} catch (Throwable $exception) {
    error_log('Arcoll app error: ' . $exception->getMessage());
    http_response_code(503);
    exit('O banco de dados está indisponível. Verifique a instalação no painel do cPanel.');
}

$templatePath = __DIR__ . '/legacy-dashboard.html';
$html = is_file($templatePath) ? file_get_contents($templatePath) : false;
if (!is_string($html)) {
    http_response_code(500);
    exit('O arquivo principal do aplicativo não está disponível.');
}
$accent = (string)($settings['accent_color'] ?? '#22d3ee');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
    $accent = '#22d3ee';
}
$siteName = trim((string)($settings['site_name'] ?? ($settings['name'] ?? '')));
if ($siteName === '') {
    $siteName = 'Arcoll';
}
$config = [
    'site' => $settings,
    'network' => $selectedNetwork,
    'networkId' => (int)($selectedNetwork['id'] ?? 0),
    'pages' => $pages,
    'apiUrl' => arc_workspace_base_url().'api/api.php',
    'baseUrl' => arc_base_url(),
    'csrf' => arc_csrf_token(),
    'measurementEndpoint' => (string)($settings['measurement_endpoint'] ?? 'cloudflare'),
    'defaultInterval' => (int)($settings['monitor_interval_ms'] ?? 1000),
    'accent' => $accent,
    'defaultTheme' => in_array(($settings['default_theme'] ?? 'auto'), ['auto','light','dark'], true) ? $settings['default_theme'] : 'auto',
    'logoUrl' => trim((string)($settings['logo_path'] ?? '')) !== '' ? 'api/brand-logo.php?v=' . substr(hash('sha256', (string)$settings['logo_path']), 0, 12) : 'assets/img/arcoll-mark.svg',
];
$bootScript = '<script>window.ARC_DB_CONFIG=' . arc_escape_json($config) . ';window.ARC_BASE=window.ARC_DB_CONFIG.baseUrl||"";window.ARC_BOOT_STATE=' . arc_escape_json($state) . ';window.ARC_SERVER_MANIFEST=true;try{var theme=window.ARC_DB_CONFIG.defaultTheme||"auto";document.documentElement.dataset.arcTheme=theme;if(theme==="light")document.documentElement.style.colorScheme="light";else if(theme==="dark")document.documentElement.style.colorScheme="dark";else document.documentElement.style.colorScheme="normal"}catch(e){}try{if(matchMedia("(display-mode: standalone)").matches||navigator.standalone===true)localStorage.setItem("arc_pwa_installed","1")}catch(e){}</script>';
$logoUrl = (string)$config['logoUrl'];
$identityLinks = '<base href="' . htmlspecialchars(arc_workspace_base_url(), ENT_QUOTES, 'UTF-8') . '"><link rel="manifest" href="manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg?v=20261025"><link rel="shortcut icon" href="assets/img/favicon.svg?v=20261025"><link rel="apple-touch-icon" href="assets/img/favicon.svg?v=20261025"><link rel="stylesheet" href="assets/css/arcoll.css">';
$html = preg_replace('/</head>/i', $identityLinks . $bootScript . '</head>', $html, 1) ?? $html;
$displayName = (string)($portalUser['display_name'] ?? $portalUser['email'] ?? 'Usuário');
$initial = htmlspecialchars(mb_strtoupper(mb_substr(trim($displayName), 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$avatar = trim((string)($portalUser['avatar_url'] ?? ''));
$avatarMarkup = $avatar !== '' ? '<img src="' . htmlspecialchars($avatar, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="">' : $initial;
$erpShell = '<header class="erp-topbar"><a class="erp-brand" href="pages/painel.php"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt=""><span><strong>' . htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><small>ERP · OPERAÇÃO DE REDES</small></span></a><div class="erp-top-actions"><a class="erp-top-link" href="pages/painel.php">Central do usuário</a><div class="erp-user-wrap" data-erp-user-wrap><button type="button" class="erp-user-button" data-erp-user><span class="erp-avatar">' . $avatarMarkup . '</span><span><b>' . htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><small>Conta conectada</small></span><span class="erp-chevron">⌄</span></button><div class="erp-user-menu" data-erp-user-menu><div class="erp-menu-caption">Acesso rápido</div><a href="pages/painel.php">▦ Central de gestão</a><a href="pages/perfil.php">◎ Meu perfil</a><a href="pages/painel.php">⌘ Minhas redes</a><a href="pages/painel.php">▣ Equipamentos</a><a href="pages/painel.php">Administração</a></div></div></div></header><nav class="erp-mobile-nav"><a class="active" href="pwa/app.php"><span>◉</span>Operação</a><a href="pages/painel.php"><span>⌘</span>Redes</a><a href="pages/painel.php"><span>▣</span>Equipamentos</a><a href="pages/painel.php"><span>▦</span>Central</a><button type="button" data-erp-more><span>＋</span>Mais</button></nav><div class="erp-more-drawer" data-erp-drawer><section class="erp-more-panel"><div class="erp-more-head"><b>Todos os menus</b><button class="erp-more-close" data-erp-close-more>×</button></div><div class="erp-more-grid"><a href="pages/perfil.php"><span>◎</span>Perfil</a><a href="pages/perfil.php"><span>⌁</span>Segurança</a><a href="pages/perfil.php"><span>☼</span>Preferências</a><a href="pages/painel.php"><span>▤</span>Instalações</a><a href="pages/painel.php"><span>◷</span>Atividade</a><a href="pages/painel.php?nova_rede=1"><span>＋</span>Nova rede</a><a href="pages/painel.php"><span>⚙</span>Administração</a></div></section></div>';
$html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $erpShell, $html, 1) ?? $html;
$dbScript = '<script src="assets/js/arcoll.js" defer></script>';
$html = preg_replace('/</body>/i', $dbScript . '</body>', $html, 1) ?? $html;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
echo $html;