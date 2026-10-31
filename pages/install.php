<?php
/* Caminho: pages/install.php */
declare(strict_types=1);

$privateDir = __DIR__ . '/../includes';
$configPath = $privateDir . '/config.php';
if (is_file($configPath)) {
    http_response_code(409);
    ?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><base href="<?=htmlspecialchars((function(){ $path=(string)($_SERVER["SCRIPT_NAME"]??"/install.php"); $dir=dirname($path); if(basename($dir)==="pages")$dir=dirname($dir); return rtrim($dir,"/")."/";})(),ENT_QUOTES)?><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação concluída</title><link rel="stylesheet" href="assets/css/arcoll.css"><script src="assets/js/arcoll.js?v=3.3.3" defer></script><body class="arc-page-install"><main class="admin-shell auth-shell"><section class="auth-card"><span class="eyebrow">INSTALAÇÃO PROTEGIDA</span><h1>O sistema já está instalado</h1><p class="text-muted">Por segurança, este instalador não pode ser executado novamente. Acesse o sistema ou a administração.</p><a class="button primary" href="pages/painel.php">Abrir administração</a> <a class="button secondary" href="index.php">Abrir portal</a></section></main></body></html><?php
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ARCOLL_INSTALL');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_start();
}
if (empty($_SESSION['arc_install_csrf'])) {
    $_SESSION['arc_install_csrf'] = bin2hex(random_bytes(32));
}
$token = (string)$_SESSION['arc_install_csrf'];
$error = '';
$success = false;

function install_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_schema(PDO $pdo, string $prefix): void
{
    $tables = [
        'admins' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'settings' => "setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value MEDIUMTEXT NOT NULL, value_type VARCHAR(24) NOT NULL DEFAULT 'text', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'pages' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(64) NOT NULL UNIQUE, title VARCHAR(120) NOT NULL, subtitle VARCHAR(255) NOT NULL DEFAULT '', content MEDIUMTEXT NOT NULL, icon VARCHAR(32) NOT NULL DEFAULT '', screen_id VARCHAR(48) NOT NULL DEFAULT '', page_type VARCHAR(24) NOT NULL DEFAULT 'system', nav_order INT NOT NULL DEFAULT 0, is_enabled TINYINT(1) NOT NULL DEFAULT 1, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'page_fields' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, page_id BIGINT UNSIGNED NOT NULL, field_key VARCHAR(80) NOT NULL, label VARCHAR(160) NOT NULL, field_type VARCHAR(32) NOT NULL DEFAULT 'text', is_required TINYINT(1) NOT NULL DEFAULT 0, options_json TEXT NULL, default_value TEXT NULL, sort_order INT NOT NULL DEFAULT 0, is_enabled TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_page_field (page_id, field_key), KEY idx_fields_order (page_id, sort_order), FOREIGN KEY (page_id) REFERENCES `{$prefix}pages` (id) ON DELETE CASCADE",
        'sessions' => "id CHAR(64) NOT NULL PRIMARY KEY, started_at_ms BIGINT NULL, last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'preferences' => "session_id CHAR(64) NOT NULL, preference_key VARCHAR(80) NOT NULL, preference_value MEDIUMTEXT NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (session_id, preference_key), FOREIGN KEY (session_id) REFERENCES `{$prefix}sessions` (id) ON DELETE CASCADE",
        'samples' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id CHAR(64) NOT NULL, client_id CHAR(64) NOT NULL, captured_at_ms BIGINT NOT NULL, is_success TINYINT(1) NOT NULL DEFAULT 0, latency_ms DECIMAL(12,3) NULL, jitter_ms DECIMAL(12,3) NULL, download_mbps DECIMAL(12,3) NULL, upload_mbps DECIMAL(12,3) NULL, loss_percent DECIMAL(6,2) NOT NULL DEFAULT 0, quality_percent DECIMAL(6,2) NOT NULL DEFAULT 0, detail VARCHAR(500) NOT NULL DEFAULT '', UNIQUE KEY uq_sample (session_id, client_id), KEY idx_samples_time (session_id, captured_at_ms), FOREIGN KEY (session_id) REFERENCES `{$prefix}sessions` (id) ON DELETE CASCADE",
        'devices' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id CHAR(64) NOT NULL, device_key VARCHAR(120) NOT NULL, device_name VARCHAR(190) NOT NULL DEFAULT '', alias VARCHAR(190) NOT NULL DEFAULT '', ip_address VARCHAR(45) NOT NULL DEFAULT '', mac_address VARCHAR(32) NOT NULL DEFAULT '', device_type VARCHAR(48) NOT NULL DEFAULT 'device', source VARCHAR(48) NOT NULL DEFAULT '', status VARCHAR(24) NOT NULL DEFAULT 'unknown', ping_ms DECIMAL(12,3) NULL, link_mbps DECIMAL(12,3) NULL, is_trusted TINYINT(1) NOT NULL DEFAULT 0, first_seen_ms BIGINT NULL, last_seen_ms BIGINT NULL, extra_json MEDIUMTEXT NULL, UNIQUE KEY uq_device (session_id, device_key), KEY idx_devices_ip (session_id, ip_address), FOREIGN KEY (session_id) REFERENCES `{$prefix}sessions` (id) ON DELETE CASCADE",
        'alerts' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id CHAR(64) NOT NULL, client_id CHAR(64) NOT NULL, created_at_ms BIGINT NOT NULL, level VARCHAR(24) NOT NULL DEFAULT 'info', message VARCHAR(500) NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, UNIQUE KEY uq_alert (session_id, client_id), KEY idx_alerts_time (session_id, created_at_ms), FOREIGN KEY (session_id) REFERENCES `{$prefix}sessions` (id) ON DELETE CASCADE",
        'field_values' => "session_id CHAR(64) NOT NULL, field_id BIGINT UNSIGNED NOT NULL, value_json MEDIUMTEXT NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (session_id, field_id), KEY idx_field_values_field (field_id), FOREIGN KEY (session_id) REFERENCES `{$prefix}sessions` (id) ON DELETE CASCADE, FOREIGN KEY (field_id) REFERENCES `{$prefix}page_fields` (id) ON DELETE CASCADE",
        'releases' => "id CHAR(24) NOT NULL PRIMARY KEY, version VARCHAR(32) NOT NULL, platform VARCHAR(24) NOT NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(64) NOT NULL, mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream', file_size BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, is_active TINYINT(1) NOT NULL DEFAULT 1, KEY idx_releases_platform (platform, is_active, created_at)",
        'login_attempts' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_login_attempts (ip_hash, attempted_at)",
        'audit_logs' => "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NULL, event_type VARCHAR(80) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '', ip_hash CHAR(64) NOT NULL DEFAULT '', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_audit_time (created_at), FOREIGN KEY (admin_id) REFERENCES `{$prefix}admins` (id) ON DELETE SET NULL",
    ];
    foreach ($tables as $name => $columns) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}{$name}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $settings = [
        'site_name' => ['Arcoll Speed Connection', 'text'],
        'tagline' => ['Conexão, diagnóstico e monitoramento em um só lugar.', 'text'],
        'domain_label' => ['', 'text'],
        'accent_color' => ['#22d3ee', 'color'],
        'logo_path' => ['', 'text'],
        'default_theme' => ['auto', 'choice'],
        'portal_heading_start' => ['Conexão sob', 'text'],
        'portal_heading_emphasis' => ['controle.', 'text'],
        'feature_monitor_title' => ['Medição contínua', 'text'],
        'feature_monitor_text' => ['Latência, variação, qualidade e histórico de amostras.', 'text'],
        'feature_devices_title' => ['Inventário de rede', 'text'],
        'feature_devices_text' => ['Identifique este aparelho e, com o agente local, outros dispositivos autorizados.', 'text'],
        'feature_reports_title' => ['Relatórios e alertas', 'text'],
        'feature_reports_text' => ['Registros, alertas e exportação para apoiar diagnósticos.', 'text'],
        'measurement_endpoint' => ['cloudflare', 'choice'],
        'monitor_interval_ms' => ['1000', 'number'],
        'retention_days' => ['90', 'number'],
    ];
    $settingInsert = $pdo->prepare('INSERT IGNORE INTO `' . $prefix . 'settings` (setting_key, setting_value, value_type) VALUES (?, ?, ?)');
    foreach ($settings as $key => [$value, $type]) {
        $settingInsert->execute([$key, $value, $type]);
    }

    $pages = [
        ['panel', 'Painel', 'Medição e telemetria da conexão.', 'panel', 'panel', 'system', 1],
        ['devices', 'Dispositivos', 'Busca e inventário autorizado da rede local.', 'devices', 'devices', 'system', 2],
        ['map', 'Mapa da rede', 'Topologia lógica dos dispositivos encontrados.', 'grid', 'map', 'system', 3],
        ['conn', 'Conexão', 'Informações da rede local e acesso à internet.', 'globe', 'conn', 'system', 4],
        ['diag', 'Diagnóstico', 'Verificação de conectividade e desempenho.', 'stethoscope', 'diag', 'system', 5],
        ['alerts', 'Alertas', 'Sensibilidade e histórico de ocorrências.', 'bell', 'alerts', 'system', 6],
        ['history', 'Histórico', 'Amostras registradas do monitoramento.', 'list', 'history', 'system', 7],
        ['reports', 'Relatórios', 'Consolidação de dados de conectividade.', 'table', 'reports', 'system', 8],
        ['nettools', 'Utilitários de rede', 'Ferramentas locais para diagnóstico autorizado.', 'pulse', 'nettools', 'system', 9],
        ['tools', 'Ferramentas', 'Atalhos operacionais de análise e exportação.', 'layers', 'tools', 'system', 10],
        ['settings', 'Ajustes', 'Preferências do aplicativo.', 'settings', 'settings', 'system', 11],
        ['install', 'Instalar e conectar', 'Instalação do PWA e agente local.', 'download', 'setup', 'system', 12],
    ];
    $pageInsert = $pdo->prepare(
        'INSERT IGNORE INTO `' . $prefix . 'pages` (slug, title, subtitle, content, icon, screen_id, page_type, nav_order, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($pages as [$slug, $title, $subtitle, $icon, $screen, $type, $order]) {
        $pageInsert->execute([$slug, $title, $subtitle, '', $icon, $screen, $type, $order]);
    }

    $fields = [
        ['panel', 'theme', 'Tema visual', 'choice', '["dark","light","auto"]', 0],
        ['panel', 'interval', 'Intervalo de medição', 'number', null, 1],
        ['devices', 'device_name', 'Nome do dispositivo', 'text', null, 0],
        ['devices', 'ip_address', 'Endereço IP', 'text', null, 1],
        ['devices', 'mac_address', 'Endereço MAC', 'text', null, 2],
        ['alerts', 'sensitivity', 'Sensibilidade', 'choice', '["low","mid","high"]', 0],
        ['settings', 'site_name', 'Nome do sistema', 'text', null, 0],
        ['settings', 'tagline', 'Descrição curta', 'text', null, 1],
    ];
    $findPage = $pdo->prepare('SELECT id FROM `' . $prefix . 'pages` WHERE slug = ?');
    $fieldInsert = $pdo->prepare(
        'INSERT IGNORE INTO `' . $prefix . 'page_fields` (page_id, field_key, label, field_type, options_json, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($fields as [$slug, $key, $label, $type, $options, $order]) {
        $findPage->execute([$slug]);
        $pageId = (int)$findPage->fetchColumn();
        if ($pageId > 0) {
            $fieldInsert->execute([$pageId, $key, $label, $type, $options, $order]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sentToken = (string)($_POST['_csrf'] ?? '');
    if ($sentToken === '' || !hash_equals($token, $sentToken)) {
        $error = 'Sessão expirada. Atualize a página e tente novamente.';
    } else {
        $host = trim((string)($_POST['db_host'] ?? 'localhost'));
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim((string)($_POST['db_name'] ?? ''));
        $user = trim((string)($_POST['db_user'] ?? ''));
        $dbPassword = (string)($_POST['db_password'] ?? '');
        $prefix = trim((string)($_POST['table_prefix'] ?? 'arcv2_'));
        $siteName = trim((string)($_POST['site_name'] ?? 'Arcoll Speed Connection'));
        $tagline = trim((string)($_POST['tagline'] ?? 'Conexão, diagnóstico e monitoramento em um só lugar.'));
        $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $confirm = (string)($_POST['admin_password_confirm'] ?? '');
        $defaultTheme = (string)($_POST['default_theme'] ?? 'auto');
        $accentColor = (string)($_POST['accent_color'] ?? '#22d3ee');
        $logoUpload = $_FILES['system_logo'] ?? null;
        $logoMime = '';
        $logoExtension = '';
        if (is_array($logoUpload) && (int)($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int)$logoUpload['error'] !== UPLOAD_ERR_OK || (int)($logoUpload['size'] ?? 0) > 1572864 || (int)($logoUpload['size'] ?? 0) < 1 || !is_uploaded_file((string)($logoUpload['tmp_name'] ?? ''))) {
                $error = 'O logo deve ser PNG, JPG ou WebP, com até 1,5 MB.';
            } else {
                $logoMime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$logoUpload['tmp_name']);
                $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
                $logoExtension = $types[$logoMime] ?? '';
                $dimensions = @getimagesize((string)$logoUpload['tmp_name']);
                if ($logoExtension === '' || !$dimensions || $dimensions[0] > 2400 || $dimensions[1] > 2400 || $dimensions[0] < 1 || $dimensions[1] < 1) {
                    $error = 'Logo inválida. Envie PNG, JPG ou WebP com dimensões de até 2400 × 2400 pixels.';
                }
            }
        }

        if ($error !== '') {
            // A validação do upload falhou; não criar tabelas nem gravar configuração.
        } elseif (!in_array($defaultTheme, ['auto', 'light', 'dark'], true) || !preg_match('/^#[0-9a-fA-F]{6}$/', $accentColor)) {
            $error = 'Selecione um tema e uma cor principal válidos.';
        } elseif ($host === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $host)
            || $name === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)
            || $user === '' || !preg_match('/^[A-Za-z0-9_@.-]{1,190}$/', $user)
            || $siteName === '' || $tagline === '') {
            $error = 'Preencha os campos obrigatórios.';
        } elseif ($port < 1 || $port > 65535) {
            $error = 'A porta MySQL deve estar entre 1 e 65535.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,18}$/', $prefix)) {
            $error = 'O prefixo aceita apenas letras, números e _ (até 18 caracteres).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail administrativo válido.';
        } elseif ($adminPassword === '' || !hash_equals($adminPassword, $confirm)) {
            $error = 'Informe uma senha administrativa e confirme-a corretamente.';
        } else {
            $config = [
                'db_host' => $host,
                'db_port' => $port,
                'db_name' => $name,
                'db_user' => $user,
                'db_password' => $dbPassword,
                'table_prefix' => $prefix,
                'app_secret' => bin2hex(random_bytes(32)),
            ];
            try {
                if (!extension_loaded('pdo_mysql')) {
                    throw new RuntimeException('O driver PDO MySQL não está habilitado na hospedagem.');
                }
                $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $user, $dbPassword, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                if (!is_dir($privateDir) && !mkdir($privateDir, 0750, true) && !is_dir($privateDir)) {
                    throw new RuntimeException('Não foi possível criar a pasta privada. Verifique as permissões no cPanel.');
                }
                install_schema($pdo, $prefix);
                foreach (['brand', 'releases'] as $dir) {
                    $path = $privateDir . '/storage/' . $dir;
                    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                        throw new RuntimeException('Não foi possível preparar o armazenamento privado.');
                    }
                }
                $pdo->beginTransaction();
                $set = $pdo->prepare('UPDATE `' . $prefix . 'settings` SET setting_value = ? WHERE setting_key = ?');
                $set->execute([mb_substr($siteName, 0, 80), 'site_name']);
                $set->execute([mb_substr($tagline, 0, 180), 'tagline']);
                $set->execute([$defaultTheme, 'default_theme']);
                $set->execute([$accentColor, 'accent_color']);
                $savedLogoPath = null;
                if ($logoExtension !== '') {
                    $savedLogoName = bin2hex(random_bytes(16)) . '.' . $logoExtension;
                    $savedLogoPath = $privateDir . '/storage/brand/' . $savedLogoName;
                    if (!move_uploaded_file((string)$logoUpload['tmp_name'], $savedLogoPath)) {
                        throw new RuntimeException('Não foi possível salvar a logo no armazenamento privado.');
                    }
                    @chmod($savedLogoPath, 0640);
                    $set->execute([$savedLogoName, 'logo_path']);
                }
                $admin = $pdo->prepare('INSERT INTO `' . $prefix . 'admins` (email, password_hash) VALUES (?, ?)');
                $admin->execute([$email, password_hash(strlen($adminPassword) > 72 ? base64_encode(hash('sha256', $adminPassword, true)) : $adminPassword, PASSWORD_DEFAULT)]);
                $configPhp = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
                $tempPath = $configPath . '.tmp-' . bin2hex(random_bytes(5));
                if (file_put_contents($tempPath, $configPhp, LOCK_EX) === false || !rename($tempPath, $configPath)) {
                    @unlink($tempPath);
                    throw new RuntimeException('Não foi possível gravar a configuração privada do banco.');
                }
                @chmod($configPath, 0600);
                $pdo->commit();
                // The core schema alone is not enough for the account, network and menu pages.
                require_once $privateDir . '/bootstrap.php';
                arc_require_platform_schema($pdo);
                arc_v19_seed_navigation($pdo);
                $success = true;
                $_SESSION = [];
                session_regenerate_id(true);
            } catch (Throwable $exception) {
                if (isset($savedLogoPath) && is_string($savedLogoPath) && is_file($savedLogoPath)) {
                    @unlink($savedLogoPath);
                }
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (is_file($configPath)) {
                    @unlink($configPath);
                }
                error_log('Arcoll install error: ' . $exception->getMessage());
                if (str_contains(strtolower($exception->getMessage()), 'pdo mysql')) {
                    $error = $exception->getMessage();
                } elseif (str_contains(strtolower($exception->getMessage()), 'could not find driver')) {
                    $error = 'Ative a extensão PDO MySQL no cPanel ou peça à hospedagem para habilitá-la.';
                } else {
                    $error = 'Não foi possível concluir. Confira os dados do banco, os privilégios do usuário e as permissões das pastas.';
                }
            }
        }
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<link rel="icon" type="image/svg+xml" href="assets/img/app-mark.svg?v=20261025"><link rel="shortcut icon" href="assets/img/app-mark.svg?v=20261025"><link rel="apple-touch-icon" href="assets/img/app-mark.svg?v=20261025">
  <meta charset="utf-8"><base href="<?=htmlspecialchars((function(){ $path=(string)($_SERVER["SCRIPT_NAME"]??"/install.php"); $dir=dirname($path); if(basename($dir)==="pages")$dir=dirname($dir); return rtrim($dir,"/")."/";})(),ENT_QUOTES)?><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="dark"><title>Instalação · Arcoll Speed Connection</title>
  <link rel="stylesheet" href="assets/css/arcoll.css"><script src="assets/js/arcoll.js?v=3.3.3" defer></script>
  
<script>try{const c=localStorage.getItem('arc_appearance')||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head>
<body><label class="arc-appearance-control arc-appearance-public"><i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label>
<main class="install-shell">
  <header class="topbar frame"><div class="brand"><img class="brand-mark" src="assets/img/app-mark.svg" alt=""><span><b>Arcoll Speed Connection</b><small>INSTALADOR PARA CPANEL</small></span></div><span class="badge"><i></i> Instalação segura</span></header>
  <section class="card install-card mt-4">
  <?php if ($success): ?>
    <span class="eyebrow">INSTALAÇÃO CONCLUÍDA</span><h1>Banco e páginas configurados</h1>
    <p class="text-muted">As tabelas relacionais foram criadas. O sistema já está pronto para acesso e configuração.</p>
    <div class="install-note">Guarde as credenciais do banco fora da pasta pública. O arquivo de configuração foi gravado na pasta privada e o instalador agora está bloqueado.</div>
    <div class="toolbar mt-4"><a class="button primary" href="pages/painel.php">Abrir administração</a><a class="button secondary" href="index.php">Abrir portal</a></div>
  <?php else: ?>
    <span class="eyebrow">ASSISTENTE DE PRIMEIRO ACESSO</span><h1>Instale seu sistema</h1>
    <p class="text-muted">Informe o banco MySQL/MariaDB do cPanel e crie a conta administrativa.</p>
    <div class="install-steps"><span>1 · Banco cPanel</span><span>2 · Dados do sistema</span><span>3 · Conta administradora</span></div>
    <?php if ($error !== ''): ?><div class="alert notice-error" role="alert"><?=install_h($error)?></div><?php endif; ?>
    <div class="install-note mb-4"><b>Antes de continuar:</b> crie o banco MySQL e o usuário no cPanel, associe o usuário ao banco com os privilégios necessários e use os nomes completos com o prefixo da hospedagem.</div>
    <form method="post" enctype="multipart/form-data" class="form-stack" autocomplete="off" action="<?=install_h((string)($_SERVER['SCRIPT_NAME'] ?? '/pages/install.php'))?>">
      <input type="hidden" name="_csrf" value="<?=install_h($token)?>">
      <div class="install-grid">
        <label>Servidor MySQL<input name="db_host" value="<?=install_h((string)($_POST['db_host'] ?? 'localhost'))?>" required></label>
        <label>Porta<input name="db_port" type="number" min="1" max="65535" value="<?=install_h((string)($_POST['db_port'] ?? '3306'))?>" required></label>
        <label>Nome completo do banco<input name="db_name" value="<?=install_h((string)($_POST['db_name'] ?? ''))?>" required></label>
        <label>Usuário completo do banco<input name="db_user" value="<?=install_h((string)($_POST['db_user'] ?? ''))?>" required></label>
        <label class="wide">Senha do banco<input name="db_password" type="password" autocomplete="new-password"></label>
        <label>Prefixo das tabelas<input name="table_prefix" maxlength="18" pattern="[A-Za-z0-9_]{1,18}" value="<?=install_h((string)($_POST['table_prefix'] ?? 'arcv2_'))?>" required></label>
        <label>Nome do sistema<input name="site_name" maxlength="80" value="<?=install_h((string)($_POST['site_name'] ?? 'Arcoll Speed Connection'))?>" required></label>
        <label class="wide">Descrição curta<input name="tagline" maxlength="180" value="<?=install_h((string)($_POST['tagline'] ?? 'Conexão, diagnóstico e monitoramento em um só lugar.'))?>" required></label>
        <label class="wide">Logo do sistema (opcional)
          <input type="file" name="system_logo" id="system-logo" accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp">
          <small>PNG, JPG ou WebP, até 1,5 MB. Será usada no portal, administração, PWA e demais telas integradas.</small>
          <img id="logo-preview" alt="Prévia do logo selecionado" hidden class="image-preview">
        </label>
        <label>Tema padrão
          <select name="default_theme" id="default-theme" required>
          <?php foreach (['auto'=>'Automático (sistema)', 'light'=>'Claro', 'dark'=>'Escuro'] as $themeKey=>$themeTitle): ?>
            <option value="<?=install_h($themeKey)?>" <?=((string)($_POST['default_theme'] ?? 'auto') === $themeKey) ? 'selected' : ''?>><?=install_h($themeTitle)?></option>
          <?php endforeach; ?>
          </select>
        </label>
        <label>Cor principal
          <input type="color" name="accent_color" value="<?=install_h((string)($_POST['accent_color'] ?? '#22d3ee'))?>" required>
        </label>
        <label>E-mail administrador<input name="admin_email" type="email" maxlength="190" value="<?=install_h((string)($_POST['admin_email'] ?? ''))?>" required></label>
        <label>Senha administrativa<input name="admin_password" type="password" autocomplete="new-password" required><small>Escolha uma senha exclusiva. Não há limite mínimo ou máximo de caracteres definido pelo formulário.</small></label>
        <label class="wide">Confirme a senha administrativa<input name="admin_password_confirm" type="password" autocomplete="new-password" required></label>
      </div>
      <button class="button primary full" type="submit">Testar o banco e instalar</button>
    </form>
    <p class="help-text">O instalador cria as tabelas no banco informado. A criação do banco e do usuário é feita no cPanel.</p>
  <?php endif; ?>
  </section>
</main>
<script>
  (()=>{const file=document.getElementById('system-logo'),preview=document.getElementById('logo-preview');
    file?.addEventListener('change',()=>{if(preview.dataset.url)URL.revokeObjectURL(preview.dataset.url);
      const f=file.files?.[0];if(!f){preview.hidden=true;return;}
      preview.dataset.url=URL.createObjectURL(f);preview.src=preview.dataset.url;preview.hidden=false;});})();
</script>

</body></html>
