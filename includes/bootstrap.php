<?php
/* Caminho: includes/bootstrap.php */
declare(strict_types=1);

const ARC_PRIVATE_DIR = __DIR__;
const ARC_PUBLIC_DIR = __DIR__ . '/../';
require_once __DIR__.'/releases.php';
const ARC_SCHEMA_VERSION = '2026.11.05-41';

require_once __DIR__.'/billing.php';
require_once __DIR__.'/support.php';
require_once __DIR__.'/network-clean.php';
require_once __DIR__.'/cloud-backup.php';

/* Fuso horário fixo do Workspace: America/Belem (UTC-03:00). */
const ARC_TIMEZONE = 'America/Belem';
date_default_timezone_set(ARC_TIMEZONE);

$arcConfigPath = ARC_PRIVATE_DIR . '/config.php';
if (!is_file($arcConfigPath)) {
    http_response_code(503);
    exit('Sistema ainda não instalado. Acesse install.php para começar.');
}
$arcLoadedConfig = require $arcConfigPath;
if (!is_array($arcLoadedConfig)) {
    http_response_code(503);
    exit('Configuração inválida. Revise a instalação.');
}

function arc_config(): array
{
    global $arcLoadedConfig;
    return $arcLoadedConfig;
}

require_once __DIR__ . '/login-security.php';
require_once __DIR__.'/navigation.php';

/** Validate the loaded billing component before rendering or issuing a payment. */
function arc_require_billing_release(): void
{
    $required=['arc_billing_status_label','arc_billing_pix_snapshot','arc_billing_pix_payload'];
    if(array_filter($required,static fn($name)=>!function_exists($name))){
        error_log('Arcoll: componente includes/billing.php ausente ou com versão diferente da plataforma.');
        throw new RuntimeException('A atualização da área de pagamentos está incompleta. Avise o administrador para concluir a atualização do sistema.',503);
    }
}

/** Canonical code inventory shared by installation and authenticated remote updates. */
function arc_agent_release_sources(?string $directory=null): array
{
    $directory=$directory??dirname(__DIR__).'/agentes/windows';
    $required=['arcoll_release.py','arcoll_agent.py','arcoll_app.py','arcoll_programs.py','arcoll_clean.py','arcoll_cc_ui.py','arcoll_cloud.py','arcoll_vdrive.py','arcoll_toolkit.py','rt_engine.py','connection_diagnostics.py','arcoll_updater.py','arcoll_remote.py','arcoll_secure_helper.py','Instalar_Arcoll.ps1','Preparar_Instalacao.ps1','Desinstalar_Arcoll.ps1'];
    $retired=['Instalar_Arcoll.py','arcoll_session.py'];$files=[];
    $paths=glob($directory.'/*');
    if($paths===false)throw new RuntimeException('Inventário do agente indisponível.');
    foreach($paths as $path){
        $name=basename($path);
        if(in_array($name,$retired,true)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]*\\.(?:py|ps1)$/D',$name)||!is_file($path))continue;
        if(!is_readable($path))throw new RuntimeException('Arquivo do agente sem leitura: '.$name);
        $contents=file_get_contents($path);
        if(!is_string($contents)||strlen($contents)>1500000)throw new RuntimeException('Arquivo do agente indisponível: '.$name);
        if($contents===''){
            if(in_array($name,$required,true))throw new RuntimeException('Arquivo essencial do agente vazio: '.$name);
            continue; // Empty optional files are deletion markers, never executable release content.
        }
        $files[$name]=$contents;
    }
    foreach($required as $name)if(!isset($files[$name]))throw new RuntimeException('Arquivo essencial do agente ausente: '.$name);
    if(count($files)>80)throw new RuntimeException('Inventário do agente excede o limite.');
    $files['arcoll_release.py']=arc_release_metadata_source();
    ksort($files,SORT_STRING);return $files;
}

/** Inspect only shipped code metadata. Never read installation credentials or user files. */
function arc_agent_release_status(): array {
    try{$sources=arc_agent_release_sources();$ready=true;}catch(RuntimeException $e){$sources=[];$ready=false;}
    $version=arc_app_version();$components=[];foreach($sources as $name=>$data)$components[]=['name'=>$name,'file'=>'agentes/windows/'.$name,'version'=>$version,'aligned'=>true];
    return ['platform_version'=>$version,'ready'=>$ready,'components'=>$components,'tools_version'=>$version,'tools_aligned'=>$ready];
}
/** The installed old agent can update only after its server-side package is complete. */
/** One timeout policy for agent polling and platform command status. */
function arc_expire_running_commands(PDO $pdo,?int $agentId=null,?int $networkId=null,?int $accountId=null): void {
    if($agentId!==null){$where='agent_id=?';$params=[$agentId];}
    elseif($networkId!==null&&$accountId!==null){$where='network_id=? AND account_id=?';$params=[$networkId,$accountId];}
    elseif($accountId!==null&&$accountId>0){$where='account_id=?';$params=[$accountId];}
    else throw new InvalidArgumentException('Escopo do comando ausente.');
    /* Política única de tempo limite (minutos, contados do despacho). Comandos fora da tabela usam 4 minutos.
       Programas e Clean têm prazos próprios em includes/network-clean.php: uma desinstalação ou limpeza
       longa não pode ser marcada como falha enquanto o agente ainda trabalha. */
    $limits=['windows_update'=>120,'agent_update'=>45,'screen_lock'=>65]+arc_network_operation_timeouts();
    $names="'remote_assistance'";$rules=[];
    foreach($limits as $name=>$minutes){$names.=",'".$name."'";$rules[]="(command_name='".$name."' AND dispatched_at<DATE_SUB(NOW(),INTERVAL ".(int)$minutes." MINUTE))";}
    $sql="UPDATE ".arc_table('agent_commands')." SET status='failed',result_text='Tempo limite do agente',completed_at=NOW() WHERE ".$where." AND status='running' AND ((command_name NOT IN (".$names.") AND dispatched_at<DATE_SUB(NOW(),INTERVAL 4 MINUTE)) OR ".implode(' OR ',$rules)." OR (command_name='remote_assistance' AND created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)))";
    $pdo->prepare($sql)->execute($params);
}

function arc_require_aligned_agent_release(): void
{
    arc_agent_release_sources();
    if(!arc_agent_release_status()['ready'])throw new RuntimeException('O pacote do agente no servidor está incompleto ou tem versões diferentes. Reenvie a pasta agent do pacote completo antes de atualizar o computador.',503);
}

function arc_workspace_base_url(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/pages/index.php'));
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (str_ends_with($dir, '/admin')) $dir=substr($dir,0,-6);
    if (str_ends_with($dir, '/pages')) $dir = substr($dir, 0, -6);
    if (str_ends_with($dir, '/api')) $dir = substr($dir, 0, -4);
    // Páginas modulares em /redes/ compartilham a mesma raiz do Workspace.
    if (str_ends_with($dir, '/redes')) $dir = substr($dir, 0, -6);
    if (str_ends_with($dir, '/pwa')) $dir = substr($dir, 0, -4);
    return ($dir === '' ? '/' : $dir . '/');
}

function arc_prefix(): string
{
    $prefix = (string)(arc_config()['table_prefix'] ?? 'arcv2_');
    return preg_match('/^[A-Za-z0-9_]{1,18}$/', $prefix) ? $prefix : 'arcv2_';
}

function arc_table(string $name): string
{
    static $allowed = [
        'admins', 'settings', 'pages', 'page_fields', 'sessions', 'preferences',
        'samples', 'devices', 'alerts', 'releases', 'login_attempts', 'audit_logs',
        'field_values', 'user_accounts', 'managed_networks', 'network_sessions', 'account_login_attempts', 'account_audit', 'pwa_installations',
        'user_preferences', 'network_user_preferences', 'network_agents', 'agent_download_keys', 'agent_telemetry', 'agent_commands', 'agent_terminal_jobs', 'file_browser_requests', 'network_device_inventory', 'navigation_items', 'permission_groups', 'permission_group_members', 'agent_release_metadata', 'network_geo_points', 'network_team_members', 'network_team_invitations', 'network_access_profiles', 'billing_plans','billing_subscriptions','billing_invoices','billing_customers','billing_events','support_tickets','support_events','support_remote_sessions','support_remote_tokens','support_file_transfers','support_schedules','support_schedule_runs','support_guest_codes','support_native_accounts','support_command_requests','support_schedule_managers','platform_release_history','platform_release_state','navigation_backup',
        'cloud_connections','cloud_backup_jobs','cloud_backup_status','cloud_backup_uploads','cloud_backup_folders',
        'agent_removal_queue', 'agent_rebind_requests', 'agent_software_inventory', 'agent_clean_inventory', 'clean_history', 'clean_settings', 'agent_command_errors',
    ];
    if (!in_array($name, $allowed, true)) {
        throw new InvalidArgumentException('Tabela não permitida.');
    }
    return '`' . arc_prefix() . $name . '`';
}

function arc_schema_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!in_array($table, ['user_accounts', 'managed_networks', 'network_agents'], true) || !preg_match('/^[a-z_][a-z0-9_]*$/', $column)) return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $check->execute([arc_prefix() . $table, $column]);
    if ((int)$check->fetchColumn() === 0) $pdo->exec('ALTER TABLE ' . arc_table($table) . ' ADD COLUMN `' . $column . '` ' . $definition);
}

/** Fila mínima de desinstalação: sobrevive à exclusão da rede só para o agente saber que deve se remover.
    Guarda apenas impressão digital, código do local e hash da chave; nenhum dado da rede. */
function arc_ensure_removal_queue(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('agent_removal_queue') . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fingerprint CHAR(64) NOT NULL,
        site_code CHAR(5) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_agent_removal (fingerprint,token_hash),
        KEY idx_agent_removal_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function arc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = arc_config();
    $host = trim((string)($config['db_host'] ?? ''));
    $database = trim((string)($config['db_name'] ?? ''));
    $port = max(1, min(65535, (int)($config['db_port'] ?? 3306)));
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)($config['db_user'] ?? ''), (string)($config['db_password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ]);
    // DATETIME values and NOW() must use the same zone as PHP (including cPanel hosts using UTC).
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

/** Ensure account tables exist after a fresh install or database restore. */
function arc_require_platform_schema(PDO $pdo): void
{
    arc_ensure_user_schema($pdo);
    arc_navigation_schema($pdo);
}

function arc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function arc_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function arc_csrf_token(): string
{
    if (!isset($_SESSION['arc_csrf'])) {
        $_SESSION['arc_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['arc_csrf'];
}

function arc_verify_csrf(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals(arc_csrf_token(), $token)) {
        arc_json(['ok' => false, 'error' => 'Sessão expirada. Atualize a página e tente novamente.'], 403);
    }
}

/** Marcador local (versão do esquema + validade de 5 min) que dispensa reverificar o banco a cada requisição. */
function arc_schema_stamp_file(): string
{
    return ARC_PRIVATE_DIR.'/storage/releases/schema-'.preg_replace('/[^0-9A-Za-z.-]/','',ARC_SCHEMA_VERSION).'-'.substr(hash('sha256',json_encode([arc_config()['db_host']??'',arc_config()['db_port']??3306,arc_config()['db_name']??'',arc_prefix()])),0,16).'.ok';
}
function arc_schema_stamp_fresh(): bool
{
    $stamp=arc_schema_stamp_file();clearstatcache(true,$stamp);
    return is_file($stamp)&&time()-(int)@filemtime($stamp)<300;
}
function arc_schema_stamp_write(): void
{
    $directory=dirname(arc_schema_stamp_file());
    if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))return;
    foreach(glob($directory.'/schema-*.ok')?:[] as $old)if($old!==arc_schema_stamp_file())@unlink($old);
    @file_put_contents(arc_schema_stamp_file(),(string)time(),LOCK_EX);
}
function arc_ensure_user_schema(PDO $pdo): void
{
    // Clientes nativos e webhooks não têm cookie e chegam várias vezes por segundo: o marcador evita consultar o information_schema a cada requisição.
    if(arc_schema_stamp_fresh()){arc_release_sync($pdo);return;}
    $required=['cloud_connections','cloud_backup_jobs','cloud_backup_status','cloud_backup_uploads','cloud_backup_folders','agent_software_inventory','agent_clean_inventory','clean_history','clean_settings','navigation_items','navigation_backup','file_browser_requests','agent_terminal_jobs','user_accounts','managed_networks','account_login_attempts','network_agents','network_team_members','network_team_invitations','billing_plans','billing_subscriptions','billing_invoices','billing_customers','billing_events','support_tickets','support_events','support_remote_sessions','support_remote_tokens','support_file_transfers','support_schedules','support_schedule_runs','support_guest_codes','support_native_accounts','support_command_requests','support_schedule_managers','platform_release_history','platform_release_state'];
    try{
        $version=arc_platform_setting($pdo,'platform_schema_version');
        if($version===ARC_SCHEMA_VERSION){
            $placeholders=implode(',',array_fill(0,count($required),'?'));
            $present=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')');
            $present->execute(array_map(static fn($name)=>arc_prefix().$name,$required));
            if((int)$present->fetchColumn()===count($required)){
                // Confirma a coluna introduzida depois da versão anterior do esquema.
                arc_schema_column($pdo, 'network_agents', 'ui_locked', 'TINYINT(1) NOT NULL DEFAULT 0');
                arc_schema_column($pdo, 'network_agents', 'uninstall_requested', 'TINYINT(1) NOT NULL DEFAULT 0');
                arc_ensure_removal_queue($pdo);
                $_SESSION['arc_schema_ok']=ARC_SCHEMA_VERSION;arc_schema_stamp_write();arc_release_sync($pdo);return;
            }
        }
    }catch(PDOException $e){/* Fresh schema: settings may not exist yet. */}
    $schemaPath=dirname(__DIR__).'/database/schema.sql';
    $schema=is_readable($schemaPath)?file_get_contents($schemaPath):false;
    if (!is_string($schema) || $schema==='') throw new RuntimeException('Estrutura consolidada ausente. Envie database/schema.sql da atualização.',503);
    $pdo->exec(str_replace(['`arc_', "'arc_"],['`'.arc_prefix(),"'".arc_prefix()],$schema));


    $accountTable = arc_table('user_accounts');
    $accountColumns = $pdo->query('SHOW COLUMNS FROM ' . $accountTable)->fetchAll(PDO::FETCH_ASSOC);
    $columnMap = [];
    foreach ($accountColumns as $column) $columnMap[(string)($column['Field'] ?? '')] = strtolower((string)($column['Type'] ?? ''));
    if (!isset($columnMap['email_hash'])) {
        $pdo->exec('ALTER TABLE ' . $accountTable . ' ADD COLUMN email_hash CHAR(64) NOT NULL DEFAULT \'\'');
    }
    $pdo->exec('UPDATE ' . $accountTable . ' SET email_hash=SHA2(LOWER(email),256) WHERE email_hash=\'\'');
    $indexes = $pdo->query('SHOW INDEX FROM ' . $accountTable)->fetchAll(PDO::FETCH_ASSOC);
    $hasEmailHashIndex = false;
    foreach ($indexes as $index) {
        if ((string)($index['Column_name'] ?? '') === 'email_hash' && (int)($index['Non_unique'] ?? 1) === 0) $hasEmailHashIndex = true;
        if ((string)($index['Column_name'] ?? '') === 'email' && (int)($index['Non_unique'] ?? 1) === 0) {
            $indexName = (string)($index['Key_name'] ?? '');
            if ($indexName !== '' && $indexName !== 'PRIMARY' && preg_match('/^[A-Za-z0-9_]+$/', $indexName)) {
                $pdo->exec('ALTER TABLE ' . $accountTable . ' DROP INDEX `' . $indexName . '`');
            }
        }
    }
    if (!$hasEmailHashIndex) $pdo->exec('ALTER TABLE ' . $accountTable . ' ADD UNIQUE KEY uq_account_email_hash (email_hash)');
    foreach ($accountColumns as $column) {
        $field = (string)($column['Field'] ?? '');
        $type = strtolower((string)($column['Type'] ?? ''));
        if ($field === 'email' && preg_match('/^varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 254) {
            $pdo->exec('ALTER TABLE ' . $accountTable . ' MODIFY COLUMN email VARCHAR(254) NOT NULL');
        }
        if ($field === 'display_name' && str_starts_with($type, 'varchar')) {
            $pdo->exec('ALTER TABLE ' . $accountTable . ' MODIFY COLUMN display_name TEXT NOT NULL');
        }
    }

    foreach ([
        'phone' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'company_name' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'job_title' => "VARCHAR(120) NOT NULL DEFAULT ''",
        'timezone' => "VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo'",
        'avatar_url' => "VARCHAR(500) NOT NULL DEFAULT ''",
        'notifications_email' => "TINYINT(1) NOT NULL DEFAULT 1",
        'account_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'access_role' => "VARCHAR(32) NOT NULL DEFAULT 'user'",
        'access_notes' => "TEXT NOT NULL",
        'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ] as $column => $definition) arc_schema_column($pdo, 'user_accounts', $column, $definition);
    foreach ([
        'client_name' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'site_code' => "VARCHAR(80) NOT NULL DEFAULT ''",
        'contact_name' => "VARCHAR(160) NOT NULL DEFAULT ''",
        'contact_phone' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'address' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'notes' => "TEXT NOT NULL",
        'vlan' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'dns_primary' => "VARCHAR(45) NOT NULL DEFAULT ''",
        'dns_secondary' => "VARCHAR(45) NOT NULL DEFAULT ''",
        'public_ip' => "VARCHAR(45) NOT NULL DEFAULT ''",
        'monitoring_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
        'logo_file' => "VARCHAR(90) NOT NULL DEFAULT ''",
        'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ] as $column => $definition) arc_schema_column($pdo, 'managed_networks', $column, $definition);



    /* Migração compatível com instalações existentes: a API grava o inventário resumido da máquina. */
    arc_schema_column($pdo, 'network_agents', 'machine_details', 'LONGTEXT NULL');
    arc_schema_column($pdo, 'network_agents', 'ui_locked', 'TINYINT(1) NOT NULL DEFAULT 0');
    arc_schema_column($pdo, 'network_agents', 'uninstall_requested', 'TINYINT(1) NOT NULL DEFAULT 0');
    arc_ensure_removal_queue($pdo);

    /* Um inventário por máquina; substituído integralmente a cada consulta (sem histórico acumulativo). */

    /* Clean: relatórios por ferramenta, histórico e configurações (includes/network-clean.php). */
    arc_clean_ensure_schema($pdo);


    /* Converte códigos antigos para 5 caracteres alfanuméricos maiúsculos. */
    $codeRows = $pdo->query('SELECT id,site_code FROM ' . arc_table('managed_networks') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $seenCodes = [];
    foreach ($codeRows as $codeRow) {
        $oldCode = strtoupper(trim((string)($codeRow['site_code'] ?? '')));
        if (preg_match('/^[A-Z0-9]{5}$/', $oldCode) && !isset($seenCodes[$oldCode])) {
            $seenCodes[$oldCode] = true;
            continue;
        }
        do {
            $newCode = '';
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            for ($i=0; $i<5; $i++) $newCode .= $alphabet[random_int(0, strlen($alphabet)-1)];
            $checkCode = $pdo->prepare('SELECT COUNT(*) FROM ' . arc_table('managed_networks') . ' WHERE UPPER(site_code)=? AND id<>?');
            $checkCode->execute([$newCode, (int)$codeRow['id']]);
        } while ((int)$checkCode->fetchColumn() > 0 || isset($seenCodes[$newCode]));
        $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET site_code=? WHERE id=?')->execute([$newCode, (int)$codeRow['id']]);
        $seenCodes[$newCode] = true;
    }
    $networkIndexes = $pdo->query('SHOW INDEX FROM ' . arc_table('managed_networks'))->fetchAll(PDO::FETCH_ASSOC);
    $hasUniqueSiteCode = false;
    foreach ($networkIndexes as $idx) {
        if ((string)($idx['Column_name'] ?? '') === 'site_code' && (int)($idx['Non_unique'] ?? 1) === 0) $hasUniqueSiteCode = true;
    }
    if (!$hasUniqueSiteCode) $pdo->exec('ALTER TABLE ' . arc_table('managed_networks') . ' ADD UNIQUE KEY uq_managed_network_site_code (site_code)');






    arc_cloud_ensure_schema($pdo);
    arc_billing_seed_plans($pdo);
    arc_platform_save_setting($pdo,'platform_schema_version',ARC_SCHEMA_VERSION);
    $_SESSION['arc_schema_ok'] = ARC_SCHEMA_VERSION;
    arc_schema_stamp_write();
    arc_release_sync($pdo);
}

/* ============================================================
   PREFERÊNCIAS DO USUÁRIO (tabela user_preferences)
   ============================================================ */

function arc_pref_defs(): array
{
    return [
        'notifications_email'   => ['group' => 'Notificações', 'label' => 'Notificações por e-mail', 'help' => 'Receber avisos importantes da conta e das redes.', 'type' => 'bool', 'default' => '1'],
        'alert_new_device'      => ['group' => 'Notificações', 'label' => 'Alertar dispositivos novos', 'help' => 'Avisa quando um equipamento desconhecido entrar na rede.', 'type' => 'bool', 'default' => '1'],
        'alert_connection_drop' => ['group' => 'Notificações', 'label' => 'Alertar queda de conexão', 'help' => 'Avisa quando uma rede ficar offline.', 'type' => 'bool', 'default' => '1'],
        'alert_speed_low'       => ['group' => 'Notificações', 'label' => 'Alertar velocidade abaixo do esperado', 'help' => 'Avisa quando download/upload cair de forma significativa.', 'type' => 'bool', 'default' => '0'],
        'alert_sound'           => ['group' => 'Notificações', 'label' => 'Som nos alertas do painel', 'help' => 'Toca um aviso sonoro quando um alerta crítico aparecer.', 'type' => 'bool', 'default' => '0'],
        'weekly_report'         => ['group' => 'Notificações', 'label' => 'Relatório semanal por e-mail', 'help' => 'Resumo semanal de desempenho das redes.', 'type' => 'bool', 'default' => '0'],

        'auto_refresh'          => ['group' => 'Monitoramento', 'label' => 'Atualização automática do monitor', 'type' => 'select', 'default' => '30',
            'options' => ['15' => 'A cada 15 segundos', '30' => 'A cada 30 segundos', '60' => 'A cada 60 segundos', '0' => 'Desativada']],
        'alert_latency_ms'      => ['group' => 'Monitoramento', 'label' => 'Limite de latência para alerta', 'type' => 'select', 'default' => '150',
            'options' => ['50' => '50 ms', '100' => '100 ms', '150' => '150 ms', '200' => '200 ms', '300' => '300 ms']],
        'alert_loss_percent'    => ['group' => 'Monitoramento', 'label' => 'Limite de perda de pacotes para alerta', 'type' => 'select', 'default' => '5',
            'options' => ['1' => '1%', '2' => '2%', '5' => '5%', '10' => '10%']],
        'items_per_page'        => ['group' => 'Monitoramento', 'label' => 'Itens por página nas tabelas', 'type' => 'select', 'default' => '50',
            'options' => ['25' => '25 itens', '50' => '50 itens', '100' => '100 itens']],

        'density'               => ['group' => 'Aparência', 'label' => 'Densidade dos painéis', 'type' => 'select', 'default' => 'comfortable',
            'options' => ['comfortable' => 'Confortável', 'compact' => 'Compacto']],
        'font_size'             => ['group' => 'Aparência', 'label' => 'Tamanho do texto', 'type' => 'select', 'default' => 'normal',
            'options' => ['small' => 'Pequeno', 'normal' => 'Normal', 'large' => 'Grande']],
        'sidebar_default'       => ['group' => 'Aparência', 'label' => 'Menu lateral ao abrir', 'type' => 'select', 'default' => 'expanded',
            'options' => ['expanded' => 'Expandido', 'collapsed' => 'Recolhido (ícones e nomes)', 'hidden' => 'Ocultado (sem sidebar)']],
        'reduce_motion'         => ['group' => 'Aparência', 'label' => 'Reduzir animações', 'help' => 'Desativa transições e efeitos de movimento.', 'type' => 'bool', 'default' => '0'],

        'language'              => ['group' => 'Região', 'label' => 'Idioma do Workspace', 'type' => 'select', 'default' => 'pt-BR',
            'options' => ['pt-BR' => 'Português (Brasil)']],
        'date_format'           => ['group' => 'Região', 'label' => 'Formato de data', 'type' => 'select', 'default' => 'd/m/Y',
            'options' => ['d/m/Y' => 'DD/MM/AAAA', 'Y-m-d' => 'AAAA-MM-DD', 'm/d/Y' => 'MM/DD/AAAA']],
        'time_format'           => ['group' => 'Região', 'label' => 'Formato de hora', 'type' => 'select', 'default' => '24',
            'options' => ['24' => '24 horas', '12' => '12 horas (AM/PM)']],
    ];
}

function arc_pref_sanitize(string $key, mixed $value): string
{
    $defs = arc_pref_defs();
    if (!isset($defs[$key])) return '';
    $def = $defs[$key];
    if ($def['type'] === 'bool') return in_array((string)$value, ['1', 'on', 'true'], true) ? '1' : '0';
    $value = (string)$value;
    return isset($def['options'][$value]) ? $value : (string)$def['default'];
}

function arc_user_prefs(PDO $pdo, ?int $userId = null, bool $refresh = false): array
{
    static $cache = [];
    $userId = $userId ?? arc_user_id();
    $defs = arc_pref_defs();
    $defaults = [];
    foreach ($defs as $key => $def) $defaults[$key] = (string)$def['default'];
    if ($userId === null) return $defaults;
    if (!$refresh && isset($cache[$userId])) return $cache[$userId];

    $stmt = $pdo->prepare('SELECT pref_key,pref_value FROM ' . arc_table('user_preferences') . ' WHERE user_id=?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    /* Migração única: preferências antigas gravadas em includes/prefs-u{ID}.json */
    $legacy = ARC_PRIVATE_DIR . '/prefs-u' . $userId . '.json';
    if (!$rows && is_file($legacy)) {
        $old = json_decode((string)file_get_contents($legacy), true);
        if (is_array($old)) {
            $clean = [];
            foreach ($old as $k => $v) if (isset($defs[(string)$k])) $clean[(string)$k] = arc_pref_sanitize((string)$k, $v);
            if ($clean) {
                arc_user_prefs_save($pdo, $userId, $clean);
                @rename($legacy, $legacy . '.migrated');
                return arc_user_prefs($pdo, $userId, true);
            }
        }
    }

    $prefs = $defaults;
    foreach ($rows as $row) {
        $k = (string)$row['pref_key'];
        if (isset($defs[$k])) $prefs[$k] = arc_pref_sanitize($k, $row['pref_value']);
    }
    return $cache[$userId] = $prefs;
}

function arc_user_prefs_save(PDO $pdo, int $userId, array $values): void
{
    $stmt = $pdo->prepare('INSERT INTO ' . arc_table('user_preferences') . ' (user_id,pref_key,pref_value) VALUES (?,?,?) '
        . 'ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)');
    foreach ($values as $key => $value) {
        $key = (string)$key;
        if (!isset(arc_pref_defs()[$key])) continue;
        $stmt->execute([$userId, $key, arc_pref_sanitize($key, $value)]);
    }
}

/** Preferências globais do sistema, comuns a clientes e administradores. */
function arc_system_prefs(PDO $pdo): array
{
    $defs=arc_pref_defs();
    $prefs=[];
    foreach($defs as $key=>$def) $prefs[$key]=(string)$def['default'];
    $stmt=$pdo->prepare('SELECT setting_key,setting_value FROM '.arc_table('settings').' WHERE setting_key LIKE ?');
    $stmt->execute(['platform_system_pref_%']);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $key=substr((string)$row['setting_key'],strlen('platform_system_pref_'));
        if(isset($defs[$key]))$prefs[$key]=arc_pref_sanitize($key,$row['setting_value']);
    }
    return $prefs;
}

/** Grava todas as preferências de forma transacional e sem afetar registros individuais legados. */
function arc_system_prefs_save(PDO $pdo,array $values): void
{
    $defs=arc_pref_defs();
    $pdo->beginTransaction();
    try {
        foreach($values as $key=>$value){
            if(!isset($defs[$key]))continue;
            arc_platform_save_setting($pdo,'platform_system_pref_'.$key,arc_pref_sanitize($key,$value));
        }
        $pdo->commit();
    } catch(Throwable $e){$pdo->rollBack();throw $e;}
}

/* Data/hora amigável respeitando as preferências do usuário (formato de data e hora). */
function arc_when(mixed $v): string
{
    if (!$v) return '—';
    $t = is_numeric($v) ? (int)floor((float)$v / 1000) : strtotime((string)$v);
    if (!$t) return '—';
    $prefs = $GLOBALS['arcPrefs'] ?? [];
    $date = (string)($prefs['date_format'] ?? 'd/m/Y');
    $time = ((string)($prefs['time_format'] ?? '24')) === '12' ? 'h:i A' : 'H:i';
    return date($date . ' ' . $time, $t);
}

function arc_user_id(): ?int
{
    return isset($_SESSION['arc_user_id']) && is_int($_SESSION['arc_user_id']) ? $_SESSION['arc_user_id'] : null;
}

function arc_is_user(): bool { return arc_user_id() !== null; }

/** Permissões de rede por usuário, verificadas no banco a cada requisição. */
/** Única gravação de membros: vínculo validado, cota e concorrência no proprietário. */
function arc_team_save(PDO $pdo,int $account,int $network,int $user,string $role): void {
    if(!in_array($role,['viewer','operator','administrator'],true)||$account===$user)throw new RuntimeException('Membro ou perfil inválido.');
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$account]);
        $q=$pdo->prepare("SELECT id FROM ".arc_table('user_accounts')." WHERE id=? AND account_status='active'");$q->execute([$user]);if(!$q->fetchColumn())throw new RuntimeException('Conta de equipe indisponível.');
        $q=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1');$q->execute([$network,$account]);if(!$q->fetchColumn())throw new RuntimeException('Rede indisponível.');
        $q=$pdo->prepare('SELECT id FROM '.arc_table('network_team_members').' WHERE account_id=? AND member_user_id=? AND enabled=1 LIMIT 1');$q->execute([$account,$user]);
        if(!$q->fetchColumn())arc_billing_enforce($pdo,$account,'seats');
        $pdo->prepare('INSERT INTO '.arc_table('network_team_members').' (account_id,network_id,member_user_id,role,enabled) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE role=VALUES(role)')->execute([$account,$network,$user,$role]);
        if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Perfis personalizáveis limitados à rede e aos recursos efetivamente autorizados. */
function arc_network_profile_capabilities(): array {return [
 'programas'=>'Programas instalados','clean'=>'Limpeza do Windows','painel'=>'Painel da rede','servidor'=>'Servidor','velocidade'=>'Internet e velocidade','dispositivos'=>'Dispositivos',
 'mapa'=>'Mapa','remoto'=>'Remoto','suporte'=>'Suporte','arquivos'=>'Arquivos','chamados'=>'Chamados','agenda'=>'Agenda','relatorios'=>'Relatórios','liberacao'=>'Liberações',
 'usuarios'=>'Usuários','preferencias'=>'Preferências','cadastros'=>'Cadastro da rede',
 'permissoes'=>'Perfis de acesso','agent_local_login'=>'Entrar no agente bloqueado (tela de login do agente)'];}
function arc_network_profile_rights(PDO $pdo,int $network,int $member): ?array {
 try {$q=$pdo->prepare('SELECT p.permissions_json FROM '.arc_table('network_team_members').' m JOIN '.arc_table('network_access_profiles').' p ON p.id=m.profile_id AND p.network_id=m.network_id AND p.account_id=m.account_id WHERE m.network_id=? AND m.member_user_id=? AND m.enabled=1 LIMIT 1');$q->execute([$network,$member]);$raw=$q->fetchColumn();} catch(PDOException $e) {error_log('Perfis de rede: migração SQL necessária.');return null;} if($raw===false)return null;
 $data=json_decode((string)$raw,true);return is_array($data)?array_values(array_intersect(array_keys(arc_network_profile_capabilities()),$data)):[];
}
function arc_network_team_access(PDO $pdo, int $networkId, int $userId): ?array
{
    if ($networkId <= 0 || $userId <= 0) return null;
    $q = $pdo->prepare('SELECT n.account_id, CASE WHEN n.account_id=? THEN \'owner\' ELSE m.role END AS team_role FROM '.arc_table('managed_networks').' n LEFT JOIN '.arc_table('network_team_members').' m ON m.network_id=n.id AND m.account_id=n.account_id AND m.member_user_id=? AND m.enabled=1 WHERE n.id=? AND (n.account_id=? OR (n.is_active=1 AND m.member_user_id IS NOT NULL)) LIMIT 1');
    $q->execute([$userId,$userId,$networkId,$userId]);
    $access = $q->fetch(PDO::FETCH_ASSOC);
    if (!$access || !in_array((string)$access['team_role'], ['owner','administrator','operator','viewer'], true)) return null;
    return ['account_id'=>(int)$access['account_id'],'role'=>(string)$access['team_role']];
}

function arc_current_network_id(): ?int
{
    return isset($_SESSION['arc_current_network_id']) && is_int($_SESSION['arc_current_network_id']) ? $_SESSION['arc_current_network_id'] : null;
}

function arc_select_network(PDO $pdo, int $networkId): bool
{
    $userId = arc_user_id();
    if ($userId === null) return false;
    $stmt = $pdo->prepare('SELECT id FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1');
    $stmt->execute([$networkId, $userId]);
    if (!$stmt->fetchColumn()) return false;
    $_SESSION['arc_current_network_id'] = $networkId;
    if (!isset($_SESSION['arc_network_monitor_sessions']) || !is_array($_SESSION['arc_network_monitor_sessions'])) $_SESSION['arc_network_monitor_sessions'] = [];
    return true;
}

function arc_require_user_network(PDO $pdo): int
{
    $userId = arc_user_id(); $networkId = arc_current_network_id();
    if ($userId === null || $networkId === null) throw new RuntimeException('Faça login e selecione uma rede gerenciada.');
    $stmt = $pdo->prepare('SELECT id FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1');
    $stmt->execute([$networkId, $userId]);
    if (!$stmt->fetchColumn()) { unset($_SESSION['arc_current_network_id']); throw new RuntimeException('A rede selecionada não está disponível. Selecione outra rede.'); }
    return $networkId;
}

/* Exclui somente registros pertencentes a esta rede. Nunca apaga conta/usuário compartilhado.
   Transactional; falha no banco interrompe a operação e reverte todas as alterações. */
function arc_delete_network(PDO $pdo, int $networkId, int $accountId): bool
{
    if ($networkId <= 0 || $accountId <= 0) return false;
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $owner = $pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? FOR UPDATE');
        $owner->execute([$networkId,$accountId]);
        if (!$owner->fetchColumn()) { if ($started) $pdo->rollBack(); return false; }

        // Optional installations from older revisions may have different table sets.
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_fill_keys(array_map('strval',$tables),true);
        $colsCache=[];
        $hasCols = static function(string $table, array $wanted) use($pdo,&$colsCache,$existing): bool {
            if (!isset($existing[$table])) return false;
            if (!isset($colsCache[$table])) {
                $query=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY ?');
                $query->execute([$table]);
                $colsCache[$table]=array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN),true);
            }
            foreach($wanted as $col) if (!isset($colsCache[$table][$col])) return false;
            return true;
        };
        $fetchIds = static function(string $logical, string $where, array $args) use($pdo,$hasCols): array {
            $table=arc_table($logical);
            if (!$hasCols($table,['id'])) return [];
            $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE '.$where);
            $q->execute($args);
            return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        };
        $deleteForIds = static function(string $logical, string $column, array $ids) use($pdo,$hasCols): void {
            if (!$ids) return;
            $table=arc_table($logical);
            if (!$hasCols($table,[$column])) return;
            foreach(array_chunk($ids,150) as $chunk) {
                $pdo->prepare('DELETE FROM '.$table.' WHERE '.$column.' IN ('.implode(',',array_fill(0,count($chunk),'?')).')')->execute($chunk);
            }
        };
        $commandIds=$fetchIds('agent_commands','network_id=? AND account_id=?',[$networkId,$accountId]);
        $ticketIds=$fetchIds('support_tickets','network_id=? AND account_id=?',[$networkId,$accountId]);
        $agentIds=$fetchIds('network_agents','network_id=? AND account_id=?',[$networkId,$accountId]);
        $scheduleIds=$fetchIds('support_schedules','network_id=? AND account_id=?',[$networkId,$accountId]);
        // 1) Garante a desinstalação dos agentes nas máquinas: o agente consulta esta fila quando a rede deixa de existir.
        if (isset($existing[arc_table('agent_removal_queue')]) && $hasCols(arc_table('network_agents'),['fingerprint','site_code','notes']) && $hasCols(arc_table('agent_download_keys'),['token_hash','fingerprint'])) {
            $queue=$pdo->prepare('INSERT IGNORE INTO '.arc_table('agent_removal_queue').' (fingerprint,site_code,token_hash) '
                .'SELECT a.fingerprint,UPPER(LEFT(a.site_code,5)),k.token_hash FROM '.arc_table('network_agents').' a '
                .'JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id AND k.fingerprint=a.fingerprint '
                .'WHERE a.network_id=? AND a.account_id=? AND a.notes NOT LIKE \'DESINSTALADO|%\' AND a.fingerprint REGEXP \'^[a-f0-9]{64}$\'');
            $queue->execute([$networkId,$accountId]);
            // Limpeza de pedidos antigos que nunca foram atendidos (máquina descartada).
            $pdo->exec('DELETE FROM '.arc_table('agent_removal_queue').' WHERE created_at < (NOW() - INTERVAL 90 DAY)');
        }
        // 2) Arquivos em disco que pertencem apenas a esta rede (removidos só depois do commit).
        $filesToRemove=[];
        $logoQ=$pdo->prepare('SELECT logo_file FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=?');
        $logoQ->execute([$networkId,$accountId]);$logoFile=(string)$logoQ->fetchColumn();
        if (preg_match('/^[a-f0-9]{40}\.(png|jpg|webp)$/',$logoFile)) $filesToRemove[]=ARC_PRIVATE_DIR.'/storage/network-logos/'.$logoFile;
        if ($hasCols(arc_table('support_file_transfers'),['storage_key','network_id'])) {
            $tq=$pdo->prepare('SELECT storage_key FROM '.arc_table('support_file_transfers').' WHERE network_id=? AND account_id=? AND storage_key IS NOT NULL');
            $tq->execute([$networkId,$accountId]);
            foreach($tq->fetchAll(PDO::FETCH_COLUMN) as $key) if (preg_match('/^[A-Za-z0-9_.-]{16,80}$/',(string)$key)) $filesToRemove[]=ARC_PRIVATE_DIR.'/storage/transfers/'.$key;
        }
        foreach($agentIds as $aid) $filesToRemove[]=ARC_PRIVATE_DIR.'/storage/remote-screen/agent-'.$aid.'.json';
        foreach($commandIds as $cid) foreach(['meta-'.$cid.'.json','view-'.$cid.'.json','stop-'.$cid,'alive-'.$cid,'screen-'.$cid.'.jpg'] as $name) $filesToRemove[]=ARC_PRIVATE_DIR.'/storage/remote-screen/'.$name;
        $sessionIds=[];
        if ($hasCols(arc_table('network_sessions'),['network_id','session_id'])) {
            $q=$pdo->prepare('SELECT session_id FROM '.arc_table('network_sessions').' WHERE network_id=?');
            $q->execute([$networkId]);$sessionIds=$q->fetchAll(PDO::FETCH_COLUMN);
        }
        // Children referencing IDs: delete by the scoped parents, never by a shared user ID.
        foreach(['support_command_requests','support_remote_tokens','support_guest_codes','support_schedule_runs'] as $table) $deleteForIds($table,'command_id',$commandIds);
        $deleteForIds('support_events','ticket_id',$ticketIds);
        $deleteForIds('support_remote_sessions','ticket_id',$ticketIds);
        $deleteForIds('support_schedule_runs','schedule_id',$scheduleIds);
        $deleteForIds('support_schedule_managers','schedule_id',$scheduleIds);
        $deleteForIds('support_guest_codes','agent_id',$agentIds);
        foreach(['agent_terminal_jobs','file_browser_requests','support_file_transfers','support_remote_sessions','agent_command_errors'] as $table) $deleteForIds($table,'command_id',$commandIds);
        foreach(['samples','devices','alerts','preferences','field_values'] as $table) $deleteForIds($table,'session_id',$sessionIds);
        $deleteForIds('sessions','id',$sessionIds);

        // Only network-scoped tables (avoid user_accounts and audit logs shared with other networks).
        $scoped=['support_file_transfers','support_remote_sessions','support_tickets','support_schedules','agent_terminal_jobs','file_browser_requests','agent_command_errors','agent_software_inventory','agent_clean_inventory','clean_history','clean_settings','agent_telemetry','network_device_inventory','network_geo_points','network_team_invitations','network_team_members','network_access_profiles','network_user_preferences','pwa_installations','agent_download_keys','agent_rebind_requests','agent_commands','network_sessions','network_agents'];
        foreach($scoped as $logical){
            $table=arc_table($logical);
            if (!$hasCols($table,['network_id'])) continue;
            $withAccount=$hasCols($table,['account_id']);
            $sql='DELETE FROM '.$table.' WHERE network_id=?'.($withAccount?' AND account_id=?':'');
            $pdo->prepare($sql)->execute($withAccount?[$networkId,$accountId]:[$networkId]);
        }
        if ($hasCols(arc_table('cloud_connections'),['network_id','account_id'])) arc_cloud_purge_network($pdo,$networkId,$accountId);
        $del=$pdo->prepare('DELETE FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=?');
        $del->execute([$networkId,$accountId]);
        if($del->rowCount()!==1) throw new RuntimeException('A rede não foi excluída');
        if($started) $pdo->commit();
        foreach($filesToRemove as $file) { if (is_file($file)) @unlink($file); }
        return true;
    } catch(Throwable $e){
        if($started && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Arcoll delete network (rollback): '.$e->getMessage());
        return false;
    }
}

/** Textos públicos: códigos e detalhes de auditoria continuam somente no registro técnico. */
function arc_notice_copy(string $event): array {
    $messages=[
      'account_login'=>['Acesso realizado','Sua conta foi acessada.'],
      'account_logout'=>['Você saiu da conta','Sua sessão foi encerrada.'],
      'account_registered'=>['Sua conta está pronta','Agora você pode cadastrar suas redes.'],
      'profile_updated'=>['Perfil atualizado','Suas informações foram salvas.'],
      'avatar_updated'=>['Foto atualizada','Sua nova foto já está no perfil.'],
      'avatar_removed'=>['Foto removida','Sua foto foi retirada do perfil.'],
      'password_changed'=>['Senha alterada','Use a nova senha no próximo acesso.'],
      'preferences_updated'=>['Preferências salvas','Suas escolhas foram atualizadas.'],
      'network_created'=>['Rede cadastrada','Sua rede já está disponível no painel.'],
      'network_updated'=>['Rede atualizada','As informações da rede foram salvas.'],
      'network_deleted'=>['Rede removida','A rede foi retirada da sua lista.'],
      'network_archived'=>['Rede arquivada','A rede está fora da lista de redes ativas.'],
      'network_team_saved'=>['Equipe atualizada','O acesso de um integrante foi atualizado.'],
      'network_team_removed'=>['Acesso da equipe removido','Um integrante deixou de ter acesso à rede.'],
      'network_agent_approved'=>['Computador liberado','O computador já pode enviar informações para a plataforma.'],
      'network_agent_blocked'=>['Computador bloqueado','O acesso desse computador foi interrompido.'],
      'network_agent_locked'=>['Agente com login obrigatório','A tela do agente só abre com usuário e senha.'],
      'network_agent_unlocked'=>['Agente liberado sem login','A tela do agente voltou a abrir sem pedir senha.'],
      'network_agent_uninstall_requested'=>['Desinstalação solicitada','O agente será removido do computador assim que ele estiver online.'],
      'network_agent_uninstall_cancelled'=>['Desinstalação cancelada','A solicitação de remoção do agente foi cancelada.'],
      'network_agent_uninstalled'=>['Agente desinstalado','O agente foi removido do computador.'],
      'network_agent_rejected'=>['Cadastro não aprovado','O computador precisa de uma nova autorização.'],
      'network_agent_deleted'=>['Computador removido','O computador foi retirado da rede.'],
      'support_file_requested'=>['Transferência solicitada','O computador recebeu uma solicitação de arquivo. Acompanhe em Ferramentas.'],
      'agent_command_queued'=>['Operação solicitada','Sua solicitação foi enviada ao computador. Acompanhe o resultado na página da rede.'],
      'remote_access_requested'=>['Acesso remoto solicitado','A solicitação foi enviada ao computador.'],
      'remote_access_auto_requested'=>['Acesso remoto iniciado','Um gestor iniciou o acesso ao servidor autorizado.'],
      'subscription_invoice_created'=>['Sua renovação está disponível','Abra Planos e cobrança para conferir e pagar.'],
      'billing_paid'=>['Pagamento confirmado','Seu plano foi liberado. Confira a validade em Planos e cobrança.'],
      'commercial_admin'=>['Configurações comerciais atualizadas','As configurações de planos ou pagamentos foram salvas.'],
      'subscription_pause'=>['Assinatura pausada','Novas operações foram pausadas pelo administrador.'],
      'subscription_resume'=>['Assinatura retomada','O período pago voltou a ficar disponível.'],
      'subscription_cancel'=>['Renovação cancelada','O período já pago continua disponível até o vencimento.'],
      'billing_reversed'=>['Pagamento em revisão','Um pagamento foi devolvido ou contestado. Confira sua assinatura.'],
      'agent_settings_login'=>['Configurações acessadas','Um gestor abriu as configurações do aplicativo no computador.'],
    ];
    return $messages[$event]??['Atualização na sua conta','Há uma nova atividade registrada na sua conta.'];
}

function arc_user_audit(PDO $pdo, string $event, string $details = '', ?int $userId = null): void
{
    if (function_exists('arc_log_should_record') && !arc_log_should_record($pdo,'account_audit')) return;
    $logPolicy=function_exists('arc_log_policy')?arc_log_policy($pdo,'account_audit'):['details'=>true,'ip'=>true];
    if(!$logPolicy['details']) $details='';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $pepper = (string)(arc_config()['app_secret'] ?? 'arcoll');
    $pdo->prepare('INSERT INTO ' . arc_table('account_audit') . ' (account_id,event_type,details,ip_hash) VALUES (?,?,?,?)')
        ->execute([$userId ?? arc_user_id(), mb_substr($event,0,64), mb_substr($details,0,255), hash_hmac('sha256',$ip,$pepper)]);
}

function arc_session_key(): string
{
    $networkId = arc_current_network_id();
    if (arc_user_id() !== null && $networkId !== null) {
        if (!isset($_SESSION['arc_network_monitor_sessions']) || !is_array($_SESSION['arc_network_monitor_sessions'])) $_SESSION['arc_network_monitor_sessions'] = [];
        $key = (string)$networkId;
        if (!isset($_SESSION['arc_network_monitor_sessions'][$key]) || !preg_match('/^[a-f0-9]{64}$/', (string)$_SESSION['arc_network_monitor_sessions'][$key])) {
            $_SESSION['arc_network_monitor_sessions'][$key] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['arc_network_monitor_sessions'][$key];
    }
    if (!isset($_SESSION['arc_monitor_session'])) $_SESSION['arc_monitor_session'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['arc_monitor_session'];
}

function arc_network_session_ids(PDO $pdo, int $networkId, string $current): array
{
    $stmt = $pdo->prepare('SELECT session_id FROM ' . arc_table('network_sessions') . ' WHERE network_id=? ORDER BY last_seen_at DESC LIMIT 50');
    $stmt->execute([$networkId]);
    $ids = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    array_unshift($ids, $current);
    return array_values(array_unique(array_filter($ids, static fn($x) => preg_match('/^[a-f0-9]{64}$/', $x))));
}

function arc_attach_network_session(PDO $pdo, string $sessionKey, ?string $label = null): void
{
    $networkId = arc_current_network_id();
    $userId = arc_user_id();
    if ($networkId === null || $userId === null) return;
    $owner = $pdo->prepare('SELECT id FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1');
    $owner->execute([$networkId, $userId]);
    if (!$owner->fetchColumn()) throw new RuntimeException('A rede selecionada não pertence à conta conectada.');
    $pdo->prepare('INSERT INTO ' . arc_table('network_sessions') . ' (session_id,network_id,client_label,last_seen_at) VALUES (?,?,?,CURRENT_TIMESTAMP) '
        . 'ON DUPLICATE KEY UPDATE network_id=VALUES(network_id), client_label=VALUES(client_label), last_seen_at=CURRENT_TIMESTAMP')
        ->execute([$sessionKey,$networkId,mb_substr($label ?: 'Dispositivo conectado',0,120)]);
    $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$networkId]);
}

function arc_network_overview(PDO $pdo, int $networkId): array
{
    $stmt = $pdo->prepare('SELECT id,name,local_ip,subnet,gateway,is_active,created_at,last_seen_at FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=?');
    $stmt->execute([$networkId, arc_user_id()]);
    $network = $stmt->fetch();
    if (!$network) throw new RuntimeException('Rede inexistente ou sem permissão.');

    $sessions = $pdo->prepare('SELECT COUNT(*) AS installation_count, MAX(last_seen_at) AS last_seen_at, '
        . 'SUM(last_seen_at >= (CURRENT_TIMESTAMP - INTERVAL 90 SECOND)) AS online_count '
        . 'FROM ' . arc_table('network_sessions') . ' WHERE network_id=?');
    $sessions->execute([$networkId]);
    $stats = $sessions->fetch() ?: [];

    $samplesQuery = $pdo->prepare('SELECT s.captured_at_ms AS time,s.is_success AS ok,s.latency_ms AS latency,s.jitter_ms AS jitter,'
        . 's.download_mbps AS download,s.upload_mbps AS upload,s.loss_percent AS loss '
        . 'FROM ' . arc_table('samples') . ' s JOIN ' . arc_table('network_sessions') . ' ns ON ns.session_id=s.session_id '
        . 'WHERE ns.network_id=? ORDER BY s.captured_at_ms DESC LIMIT 40');
    $samplesQuery->execute([$networkId]);
    $samples = array_reverse($samplesQuery->fetchAll());
    foreach ($samples as &$row) {
        $row['time'] = (int)$row['time']; $row['ok'] = (bool)$row['ok'];
        foreach (['latency','jitter','download','upload','loss'] as $key) $row[$key] = $row[$key] === null ? null : (float)$row[$key];
    }
    unset($row);

    $devicesQuery = $pdo->prepare('SELECT d.device_key,d.device_name,d.alias,d.ip_address,d.mac_address,d.device_type,d.status,d.ping_ms,d.link_mbps,d.last_seen_ms,d.extra_json '
        . 'FROM ' . arc_table('devices') . ' d JOIN ' . arc_table('network_sessions') . ' ns ON ns.session_id=d.session_id '
        . 'WHERE ns.network_id=? ORDER BY d.last_seen_ms DESC LIMIT 500');
    $devicesQuery->execute([$networkId]);
    $devices = [];
    foreach ($devicesQuery->fetchAll() as $device) {
        $key = (string)$device['device_key'];
        if (isset($devices[$key])) continue;
        $lastSeen = $device['last_seen_ms'] === null ? null : (int)$device['last_seen_ms'];
        $status = (string)$device['status'];
        if ($lastSeen !== null && $lastSeen > 0 && (int)(microtime(true) * 1000) - $lastSeen > 120000) $status = 'offline';
        $extra = $device['extra_json'] ? json_decode((string)$device['extra_json'], true) : [];
        $devices[$key] = [
            'key'=>$key, 'name'=>(string)($device['alias'] ?: $device['device_name']),
            'ip'=>(string)$device['ip_address'], 'mac'=>(string)$device['mac_address'],
            'type'=>(string)$device['device_type'], 'status'=>$status,
            'ping'=>$device['ping_ms'] === null ? null : (float)$device['ping_ms'],
            'link'=>$device['link_mbps'] === null ? null : (float)$device['link_mbps'],
            'last_seen'=>$lastSeen, 'extra'=>is_array($extra) ? $extra : [],
        ];
    }
    $network['id'] = (int)$network['id'];
    $network['installation_count'] = (int)($stats['installation_count'] ?? 0);
    $network['online_count'] = (int)($stats['online_count'] ?? 0);
    $network['last_seen_at'] = $stats['last_seen_at'] ?? $network['last_seen_at'];
    return ['network'=>$network, 'samples'=>$samples, 'devices'=>array_values($devices)];
}

function arc_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value, value_type FROM ' . arc_table('settings') . ' ORDER BY setting_key')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[(string)$row['setting_key']] = [
            'value' => (string)$row['setting_value'],
            'type' => (string)$row['value_type'],
        ];
    }
    return $result;
}

function arc_setting_values(PDO $pdo): array
{
    $values = [];
    foreach (arc_settings($pdo) as $key => $setting) {
        $values[$key] = $setting['value'];
    }
    return $values;
}

function arc_pages(PDO $pdo, bool $enabledOnly = false): array
{
    $sql = 'SELECT p.id, p.slug, p.title, p.subtitle, p.content, p.icon, p.screen_id, p.page_type, p.nav_order, p.is_enabled, '
        . 'f.id AS field_id, f.field_key, f.label AS field_label, f.field_type, f.is_required, f.options_json, f.default_value, f.sort_order AS field_order, f.is_enabled AS field_enabled '
        . 'FROM ' . arc_table('pages') . ' p LEFT JOIN ' . arc_table('page_fields') . ' f ON f.page_id = p.id'
        . ($enabledOnly ? ' WHERE p.is_enabled = 1 AND (f.id IS NULL OR f.is_enabled = 1)' : '')
        . ' ORDER BY p.nav_order, p.id, f.sort_order, f.id';
    $pages = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $slug = (string)$row['slug'];
        if (!isset($pages[$slug])) {
            $pages[$slug] = [
                'id' => (int)$row['id'],
                'slug' => $slug,
                'title' => (string)$row['title'],
                'subtitle' => (string)$row['subtitle'],
                'content' => (string)$row['content'],
                'icon' => (string)$row['icon'],
                'screenId' => (string)$row['screen_id'],
                'type' => (string)$row['page_type'],
                'order' => (int)$row['nav_order'],
                'enabled' => (bool)$row['is_enabled'],
                'fields' => [],
            ];
        }
        if ($row['field_id'] !== null) {
            $options = $row['options_json'] ? json_decode((string)$row['options_json'], true) : [];
            $pages[$slug]['fields'][] = [
                'id' => (int)$row['field_id'],
                'key' => (string)$row['field_key'],
                'label' => (string)$row['field_label'],
                'type' => (string)$row['field_type'],
                'required' => (bool)$row['is_required'],
                'options' => is_array($options) ? array_values($options) : [],
                'default' => $row['default_value'],
                'order' => (int)$row['field_order'],
                'enabled' => (bool)$row['field_enabled'],
            ];
        }
    }
    return array_values($pages);
}

function arc_public_settings(PDO $pdo): array
{
    $allowed = [
        'site_name', 'tagline', 'domain_label', 'accent_color', 'logo_path', 'default_theme',
        'portal_heading_start', 'portal_heading_emphasis',
        'feature_monitor_title', 'feature_monitor_text',
        'feature_devices_title', 'feature_devices_text',
        'feature_reports_title', 'feature_reports_text',
        'measurement_endpoint', 'monitor_interval_ms',
    ];
    return array_intersect_key(arc_setting_values($pdo), array_flip($allowed));
}

function arc_app_state(PDO $pdo): array
{
    $sessionKey=arc_session_key();
    $pdo->prepare('INSERT INTO ' . arc_table('sessions') . ' (id,last_seen_at) VALUES (?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_seen_at=CURRENT_TIMESTAMP')->execute([$sessionKey]);
    arc_attach_network_session($pdo,$sessionKey,(string)($_SERVER['HTTP_USER_AGENT']??'Dispositivo conectado'));
    $networkId=arc_user_id()!==null?arc_current_network_id():null;
    $sessionKeys=[$sessionKey]; // Estado da instalação atual; o painel remoto agrega instalações somente em leitura.
    $ph=implode(',',array_fill(0,count($sessionKeys),'?'));
    $meta=$pdo->prepare('SELECT MIN(started_at_ms) FROM ' . arc_table('sessions') . ' WHERE id IN (' . $ph . ')'); $meta->execute($sessionKeys); $startedAt=$meta->fetchColumn();
    $pref=$pdo->prepare('SELECT p.preference_key,p.preference_value FROM ' . arc_table('preferences') . ' p LEFT JOIN ' . arc_table('network_sessions') . ' ns ON ns.session_id=p.session_id WHERE p.session_id IN (' . $ph . ') ORDER BY ns.last_seen_at ASC');
    $pref->execute($sessionKeys); $settings=[]; foreach($pref->fetchAll() as $r)$settings[(string)$r['preference_key']]=json_decode((string)$r['preference_value'],true);
    $samplesQuery=$pdo->prepare('SELECT client_id,captured_at_ms AS time,is_success AS ok,latency_ms AS latency,jitter_ms AS jit,download_mbps AS down,upload_mbps AS up,loss_percent AS loss,quality_percent AS quality,detail FROM ' . arc_table('samples') . ' WHERE session_id IN (' . $ph . ') ORDER BY captured_at_ms DESC LIMIT 2000');
    $samplesQuery->execute($sessionKeys); $samples=array_reverse($samplesQuery->fetchAll());
    foreach($samples as &$r){$r['time']=(int)$r['time'];$r['ok']=(bool)$r['ok'];foreach(['latency','jit','down','up','loss','quality'] as $k)$r[$k]=$r[$k]===null?null:(float)$r[$k];}unset($r);
    $alertsQuery=$pdo->prepare('SELECT client_id,created_at_ms AS time,level,message AS msg FROM ' . arc_table('alerts') . ' WHERE session_id IN (' . $ph . ') ORDER BY created_at_ms DESC LIMIT 500');
    $alertsQuery->execute($sessionKeys); $alerts=array_reverse($alertsQuery->fetchAll()); foreach($alerts as &$r)$r['time']=(int)$r['time'];unset($r);
    $devicesQuery=$pdo->prepare('SELECT d.device_key,d.device_name AS name,d.alias,d.ip_address AS ip,d.mac_address AS mac,d.device_type AS type,d.source,d.status,d.ping_ms AS ping,d.link_mbps AS link,d.is_trusted AS trusted,d.first_seen_ms AS firstSeen,d.last_seen_ms AS lastSeen,d.extra_json FROM ' . arc_table('devices') . ' d WHERE d.session_id IN (' . $ph . ') ORDER BY d.last_seen_ms DESC');
    $devicesQuery->execute($sessionKeys);$knownDevices=[];foreach($devicesQuery->fetchAll() as $d){$key=(string)$d['device_key'];if(isset($knownDevices[$key]))continue;$extra=$d['extra_json']?json_decode((string)$d['extra_json'],true):[];$knownDevices[$key]=array_merge(is_array($extra)?$extra:[],['name'=>(string)$d['name'],'alias'=>(string)$d['alias'],'ip'=>(string)$d['ip'],'mac'=>(string)$d['mac'],'type'=>(string)$d['type'],'source'=>(string)$d['source'],'status'=>(string)$d['status'],'ping'=>$d['ping']===null?null:(float)$d['ping'],'link'=>$d['link']===null?null:(float)$d['link'],'trusted'=>(bool)$d['trusted'],'firstSeen'=>$d['firstSeen']===null?null:(int)$d['firstSeen'],'lastSeen'=>$d['lastSeen']===null?null:(int)$d['lastSeen']]);}
    $fieldQuery=$pdo->prepare('SELECT field_id,value_json FROM ' . arc_table('field_values') . ' WHERE session_id IN (' . $ph . ')');$fieldQuery->execute($sessionKeys);$fieldValues=[];foreach($fieldQuery->fetchAll() as $r)$fieldValues[(string)$r['field_id']]=json_decode((string)$r['value_json'],true);
    return ['settings'=>$settings,'samples'=>$samples,'alerts'=>$alerts,'knownDevices'=>$knownDevices,'fieldValues'=>$fieldValues,'sessionStartedAt'=>$startedAt===false||$startedAt===null?null:(int)$startedAt,'managedNetworkId'=>$networkId];
}

function arc_nullable_number(mixed $value, float $min, float $max): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return max($min, min($max, (float)$value));
}

function arc_persist_state(PDO $pdo, array $payload): void
{
    $sessionKey = arc_session_key();
    arc_attach_network_session($pdo, $sessionKey, (string)($payload['clientLabel'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Dispositivo conectado')));
    if (arc_current_network_id() !== null && isset($payload['networkIdentity']) && is_array($payload['networkIdentity'])) {
        $ni=$payload['networkIdentity']; $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET local_ip=COALESCE(NULLIF(?,\'\'),local_ip),subnet=COALESCE(NULLIF(?,\'\'),subnet),gateway=COALESCE(NULLIF(?,\'\'),gateway),last_seen_at=CURRENT_TIMESTAMP WHERE id=? AND account_id=?')->execute([mb_substr((string)($ni['ip']??''),0,45),mb_substr((string)($ni['sub']??''),0,80),mb_substr((string)($ni['gw']??''),0,45),arc_current_network_id(),arc_user_id()]);
    }
    $startedAt = isset($payload['startedAt']) && is_numeric($payload['startedAt'])
        ? max(0, (int)$payload['startedAt']) : null;
    $pdo->prepare(
        'INSERT INTO ' . arc_table('sessions') . ' (id, started_at_ms, last_seen_at) VALUES (?, ?, CURRENT_TIMESTAMP) '
        . 'ON DUPLICATE KEY UPDATE started_at_ms = COALESCE(VALUES(started_at_ms), started_at_ms), last_seen_at = CURRENT_TIMESTAMP'
    )->execute([$sessionKey, $startedAt]);

    if (isset($payload['settings']) && is_array($payload['settings'])) {
        $savePref = $pdo->prepare(
            'INSERT INTO ' . arc_table('preferences') . ' (session_id, preference_key, preference_value) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)'
        );
        foreach (array_slice($payload['settings'], 0, 80, true) as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/', $key)) {
                continue;
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json) && strlen($json) <= 20000) {
                $savePref->execute([$sessionKey, $key, $json]);
            }
        }
    }

    if (isset($payload['samples']) && is_array($payload['samples'])) {
        if ($payload['samples'] === []) {
            $pdo->prepare('DELETE FROM ' . arc_table('samples') . ' WHERE session_id = ?')->execute([$sessionKey]);
        } else {
            $times = [];
            foreach ($payload['samples'] as $candidate) {
                if (is_array($candidate) && isset($candidate['time']) && is_numeric($candidate['time'])) {
                    $times[] = (int)$candidate['time'];
                }
            }
            if ($times !== []) {
                $pdo->prepare('DELETE FROM ' . arc_table('samples') . ' WHERE session_id = ? AND captured_at_ms < ?')
                    ->execute([$sessionKey, min($times)]);
            }
        }
        $saveSample = $pdo->prepare(
            'INSERT INTO ' . arc_table('samples') . ' (session_id, client_id, captured_at_ms, is_success, latency_ms, jitter_ms, '
            . 'download_mbps, upload_mbps, loss_percent, quality_percent, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE is_success = VALUES(is_success), latency_ms = VALUES(latency_ms), jitter_ms = VALUES(jitter_ms), '
            . 'download_mbps = VALUES(download_mbps), upload_mbps = VALUES(upload_mbps), loss_percent = VALUES(loss_percent), '
            . 'quality_percent = VALUES(quality_percent), detail = VALUES(detail)'
        );
        foreach (array_slice($payload['samples'], -2000) as $sample) {
            if (!is_array($sample) || !isset($sample['time']) || !is_numeric($sample['time'])) {
                continue;
            }
            $time = max(0, (int)$sample['time']);
            $clientId = substr(hash('sha256', $sessionKey . '|' . $time), 0, 64);
            $saveSample->execute([
                $sessionKey, $clientId, $time, !empty($sample['ok']) ? 1 : 0,
                arc_nullable_number($sample['latency'] ?? null, 0, 1000000),
                arc_nullable_number($sample['jit'] ?? null, 0, 1000000),
                arc_nullable_number($sample['down'] ?? null, 0, 1000000),
                arc_nullable_number($sample['up'] ?? null, 0, 1000000),
                arc_nullable_number($sample['loss'] ?? 0, 0, 100) ?? 0,
                arc_nullable_number($sample['quality'] ?? 0, 0, 100) ?? 0,
                mb_substr((string)($sample['detail'] ?? ''), 0, 500),
            ]);
        }
    }

    if (isset($payload['alerts']) && is_array($payload['alerts'])) {
        if ($payload['alerts'] === []) {
            $pdo->prepare('DELETE FROM ' . arc_table('alerts') . ' WHERE session_id = ?')->execute([$sessionKey]);
        } else {
            $times = [];
            foreach ($payload['alerts'] as $candidate) {
                if (is_array($candidate) && isset($candidate['time']) && is_numeric($candidate['time'])) {
                    $times[] = (int)$candidate['time'];
                }
            }
            if ($times !== []) {
                $pdo->prepare('DELETE FROM ' . arc_table('alerts') . ' WHERE session_id = ? AND created_at_ms < ?')
                    ->execute([$sessionKey, min($times)]);
            }
        }
        $saveAlert = $pdo->prepare(
            'INSERT IGNORE INTO ' . arc_table('alerts') . ' (session_id, client_id, created_at_ms, level, message) VALUES (?, ?, ?, ?, ?)'
        );
        foreach (array_slice($payload['alerts'], -500) as $alert) {
            if (!is_array($alert) || !isset($alert['time']) || !is_numeric($alert['time'])) {
                continue;
            }
            $time = max(0, (int)$alert['time']);
            $message = mb_substr((string)($alert['msg'] ?? ''), 0, 500);
            $clientId = substr(hash('sha256', $sessionKey . '|' . $time . '|' . $message), 0, 64);
            $saveAlert->execute([
                $sessionKey, $clientId, $time,
                mb_substr((string)($alert['level'] ?? 'info'), 0, 24),
                $message,
            ]);
        }
    }

    if (isset($payload['knownDevices']) && is_array($payload['knownDevices'])) {
        $deviceKeys = array_slice(array_map(static fn($key) => mb_substr((string)$key, 0, 120), array_keys($payload['knownDevices'])), 0, 1000);
        if ($deviceKeys === []) {
            $pdo->prepare('DELETE FROM ' . arc_table('devices') . ' WHERE session_id = ?')->execute([$sessionKey]);
        } else {
            $placeholders = implode(',', array_fill(0, count($deviceKeys), '?'));
            $pdo->prepare('DELETE FROM ' . arc_table('devices') . ' WHERE session_id = ? AND device_key NOT IN (' . $placeholders . ')')
                ->execute(array_merge([$sessionKey], $deviceKeys));
        }
        $saveDevice = $pdo->prepare(
            'INSERT INTO ' . arc_table('devices') . ' (session_id, device_key, device_name, alias, ip_address, mac_address, device_type, source, status, ping_ms, link_mbps, is_trusted, first_seen_ms, last_seen_ms, extra_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
            . 'device_name=VALUES(device_name), alias=VALUES(alias), ip_address=VALUES(ip_address), mac_address=VALUES(mac_address), '
            . 'device_type=VALUES(device_type), source=VALUES(source), status=VALUES(status), ping_ms=VALUES(ping_ms), '
            . 'link_mbps=VALUES(link_mbps), is_trusted=VALUES(is_trusted), first_seen_ms=VALUES(first_seen_ms), '
            . 'last_seen_ms=VALUES(last_seen_ms), extra_json=VALUES(extra_json)'
        );
        foreach (array_slice($payload['knownDevices'], 0, 1000, true) as $key => $device) {
            if (!is_array($device)) {
                continue;
            }
            $key = mb_substr((string)$key, 0, 120);
            if ($key === '') {
                continue;
            }
            $known = ['name', 'alias', 'ip', 'mac', 'type', 'source', 'status', 'ping', 'link', 'trusted', 'firstSeen', 'lastSeen'];
            $extra = array_diff_key($device, array_flip($known));
            $saveDevice->execute([
                $sessionKey, $key,
                mb_substr((string)($device['name'] ?? ''), 0, 190),
                mb_substr((string)($device['alias'] ?? ''), 0, 190),
                mb_substr((string)($device['ip'] ?? ''), 0, 45),
                mb_substr((string)($device['mac'] ?? ''), 0, 32),
                mb_substr((string)($device['type'] ?? 'device'), 0, 48),
                mb_substr((string)($device['source'] ?? ''), 0, 48),
                mb_substr((string)($device['status'] ?? 'unknown'), 0, 24),
                arc_nullable_number($device['ping'] ?? null, 0, 1000000),
                arc_nullable_number($device['link'] ?? null, 0, 1000000),
                !empty($device['trusted']) ? 1 : 0,
                isset($device['firstSeen']) && is_numeric($device['firstSeen']) ? (int)$device['firstSeen'] : null,
                isset($device['lastSeen']) && is_numeric($device['lastSeen']) ? (int)$device['lastSeen'] : null,
                json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
    }

    $retention = max(1, min(3650, (int)(arc_setting_values($pdo)['retention_days'] ?? 90)));
    $cutoff = (int)(microtime(true) * 1000) - ($retention * 86400000);
    $pdo->prepare('DELETE FROM ' . arc_table('samples') . ' WHERE session_id = ? AND captured_at_ms < ?')->execute([$sessionKey, $cutoff]);
    $pdo->prepare('DELETE FROM ' . arc_table('alerts') . ' WHERE session_id = ? AND created_at_ms < ?')->execute([$sessionKey, $cutoff]);
}

function arc_save_field_values(PDO $pdo, array $values): void
{
    $sessionKey = arc_session_key();
    $save = $pdo->prepare(
        'INSERT INTO ' . arc_table('field_values') . ' (session_id, field_id, value_json) '
        . 'SELECT ?, f.id, ? FROM ' . arc_table('page_fields') . ' f WHERE f.id = ? AND f.is_enabled = 1 '
        . 'ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)'
    );
    foreach (array_slice($values, 0, 200, true) as $fieldId => $value) {
        $fieldId = (int)$fieldId;
        if ($fieldId < 1) {
            continue;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json) && strlen($json) <= 20000) {
            $save->execute([$sessionKey, $json, $fieldId]);
        }
    }
}


/* Metadados de publicação sem modificar a tabela legada de releases. */
function arc_v16_release_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('agent_release_metadata').' (
      release_id CHAR(24) NOT NULL PRIMARY KEY,
      notes TEXT NOT NULL,
      channel VARCHAR(16) NOT NULL DEFAULT \'stable\',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
/* V16 - grupos de permissão e autorização de módulos do agente. */
function arc_v16_group_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('permission_groups') . ' (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(90) NOT NULL,
      description VARCHAR(255) NOT NULL DEFAULT \'\',
      agent_tabs VARCHAR(400) NOT NULL DEFAULT \'monitor,speed_test,devices,topology,connection,tools,diagnostic,alerts,reports,settings\',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_group_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('permission_group_members') . ' (
      account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      group_id INT UNSIGNED NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_group_members (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}
function arc_v16_agent_tabs(): array {
    return ['monitor'=>'Monitoramento','speed_test'=>'Velocidade','devices'=>'Dispositivos',
       'topology'=>'Mapa da rede','connection'=>'Conexão','tools'=>'Ferramentas',
       'diagnostic'=>'Diagnóstico','alerts'=>'Alertas e histórico','reports'=>'Relatórios','settings'=>'Configurações'];
}
function arc_v16_allowed_tabs(PDO $pdo, int $accountId): array {
    arc_v16_group_schema($pdo);
    $stmt=$pdo->prepare('SELECT g.agent_tabs FROM '.arc_table('permission_group_members').' m
        INNER JOIN '.arc_table('permission_groups').' g ON g.id=m.group_id WHERE m.account_id=? LIMIT 1');
    $stmt->execute([$accountId]);
    $tabs=$stmt->fetchColumn();
    $all=array_keys(arc_v16_agent_tabs());
    // Legacy users without an assigned group keep their existing access until explicitly assigned.
    if($tabs===false) return $all;
    return array_values(array_intersect($all,array_map('trim',explode(',',(string)$tabs))));
}
function arc_v16_allowed_command(PDO $pdo, int $accountId, string $command): bool {
    $map=['monitor_start'=>'monitor','monitor_pause'=>'monitor','speed_test'=>'speed_test',
          'scan_devices'=>'devices','diagnostic'=>'diagnostic'];
    return isset($map[$command]) && in_array($map[$command],arc_v16_allowed_tabs($pdo,$accountId),true);
}
function arc_v16_user_group(PDO $pdo, int $accountId): ?int {
    arc_v16_group_schema($pdo);
    $q=$pdo->prepare('SELECT group_id FROM '.arc_table('permission_group_members').' WHERE account_id=?');
    $q->execute([$accountId]); $id=$q->fetchColumn(); return $id===false?null:(int)$id;
}
function arc_v15_user_role(PDO $pdo): string {
    if (arc_is_admin()) return 'admin';
    $id=arc_user_id();
    if (!$id) return 'guest';
    $q=$pdo->prepare('SELECT access_role FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $role=(string)$q->fetchColumn();
    return in_array($role,['user','manager','viewer'],true)?$role:'viewer';
}
function arc_is_admin(): bool
{
    return isset($_SESSION['arc_admin_id']) && is_int($_SESSION['arc_admin_id']);
}

/**
 * Destino inicial de uma sessão autenticada, compartilhado entre a raiz e /pages/.
 * Retorna um caminho relativo à raiz da aplicação ou null para visitantes.
 * A autenticação administrativa e a de clientes permanecem independentes.
 */
function arc_authenticated_home(PDO $pdo): ?string
{
    if (arc_is_admin()) {
        $check=$pdo->prepare('SELECT id FROM '.arc_table('admins').' WHERE id=? LIMIT 1');
        $check->execute([arc_admin_id()]);
        if($check->fetchColumn()!==false)return 'pages/painel.php';
        unset($_SESSION['arc_admin_id'],$_SESSION['arc_admin_email']);
    }
    $uid=arc_user_id();
    if($uid===null)return null;
    $check=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
    $check->execute([$uid]);
    if($check->fetchColumn()==='active')return 'pages/painel.php';
    unset($_SESSION['arc_user_id'],$_SESSION['arc_user_email'],$_SESSION['arc_current_network_id']);
    return null;
}

function arc_require_admin(): void
{
    if (!arc_is_admin()) {
        header('Location: ' . arc_workspace_base_url() . 'index.php', true, 303);
        exit;
    }
}

function arc_admin_id(): ?int
{
    return arc_is_admin() ? (int)$_SESSION['arc_admin_id'] : null;
}

function arc_audit(PDO $pdo, string $event, string $description = ''): void
{
    if (function_exists('arc_log_should_record') && !arc_log_should_record($pdo,'audit_logs')) return;
    $logPolicy=function_exists('arc_log_policy')?arc_log_policy($pdo,'audit_logs'):['details'=>true,'ip'=>true];
    if(!$logPolicy['details']) $description='';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $pepper = (string)(arc_config()['app_secret'] ?? 'arcoll');
    $stmt = $pdo->prepare(
        'INSERT INTO ' . arc_table('audit_logs') . ' (admin_id, event_type, description, ip_hash) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        arc_admin_id(), mb_substr($event, 0, 80),
        mb_substr($description, 0, 500), ($logPolicy['ip']?($logPolicy['ip']?hash_hmac('sha256', $ip, $pepper):''):''),
    ]);
}

function arc_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function arc_base_url(): string
{
    $script = (string)(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? '/'), PHP_URL_PATH) ?: '/');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    if (str_ends_with($base, '/pages')) $base = substr($base, 0, -6);
    if (str_ends_with($base, '/admin')) $base = substr($base, 0, -6);
    if (str_ends_with($base, '/api')) $base = substr($base, 0, -4);
    if (str_ends_with($base, '/redes')) $base = substr($base, 0, -6);
    if (str_ends_with($base, '/pwa')) $base = substr($base, 0, -4);
    return $base === '/' ? '' : $base;
}

function arc_url(string $path = ''): string
{
    $base = arc_base_url();
    return $base . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}

function arc_escape_json(mixed $value): string
{
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '{}';
}

/**
 * Salva a identidade visual de uma rede de propriedade do usuário autenticado.
 * Retorna false quando nenhum arquivo foi enviado. Imagens ficam em pasta privada.
 */
function arc_save_network_logo(PDO $pdo, int $networkId, int $accountId, string $input = 'network_logo'): bool
{
    if (!isset($_FILES[$input]) || !is_array($_FILES[$input])) return false;
    $file = $_FILES[$input];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return false;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem.');
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024 || (int)($file['size'] ?? 0) < 1)
        throw new RuntimeException('A logo deve ter no máximo 2 MB.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Arquivo enviado inválido.');
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]]) || $info[0] > 2400 || $info[1] > 2400)
        throw new RuntimeException('Envie uma imagem PNG, JPG ou WEBP de até 2400 pixels.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $expected = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
    if ($mime !== $expected[$info[2]]) throw new RuntimeException('Formato da imagem inválido.');
    $lookup = $pdo->prepare('SELECT logo_file FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=?');
    $lookup->execute([$networkId, $accountId]);
    $previous = $lookup->fetchColumn();
    if ($previous === false) throw new RuntimeException('Rede não encontrada.');
    $dir = ARC_PRIVATE_DIR . '/storage/network-logos';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir))
        throw new RuntimeException('Não foi possível preparar o armazenamento.');
    $filename = bin2hex(random_bytes(20)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($tmp, $dir . '/' . $filename))
        throw new RuntimeException('Não foi possível salvar a logo.');
    require_once __DIR__.'/media-optimizer.php';
    arc_optimize_uploaded_image($dir.'/'.$filename,1200);
    try {
        $pdo->prepare('UPDATE ' . arc_table('managed_networks') . ' SET logo_file=? WHERE id=? AND account_id=?')
            ->execute([$filename, $networkId, $accountId]);
    } catch (Throwable $e) {
        @unlink($dir . '/' . $filename);
        throw $e;
    }
    if (is_string($previous) && preg_match('/^[a-f0-9]{40}\\.(png|jpg|webp)$/', $previous))
        @unlink($dir . '/' . $previous);
    return true;
}

function arc_upload_dir(string $kind): string
{
    if (!in_array($kind, ['releases', 'brand'], true)) {
        throw new InvalidArgumentException('Destino de arquivo inválido.');
    }
    $path = ARC_PRIVATE_DIR . '/storage/' . $kind;
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Não foi possível criar a pasta privada de arquivos.');
    }
    return $path;
}

function arc_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    /* Agentes e app de suporte nativos não guardam cookie: cada requisição criaria um arquivo de sessão vazio no servidor. */
    if (!isset($_COOKIE['ARCOLLSESSID']) && preg_match('~^Arcoll(?:Desktop|Agent|-Suporte)/~', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        $_SESSION = [];
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ARCOLLSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => arc_base_url() . '/',
        'secure' => arc_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

arc_start_session();
/* Acesso privado: uma única barreira para todas as rotas PHP em /pages e /redes.
   Não interfere nas APIs do agente nem nas rotas públicas da raiz. */
$arcEntryPath = str_replace('\\\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$arcPrivateRoute = (bool)preg_match('~/(?:admin|pages|redes|pwa)/[^/]+\\.php$~i', $arcEntryPath);
$arcPublicEntry = (bool)preg_match('~/pages/(?:index|install)\\.php$~i', $arcEntryPath);
if ($arcPrivateRoute && !$arcPublicEntry && !isset($_SESSION['arc_admin_id'])) {
    try {
        if (arc_platform_setting(arc_db(),'platform_maintenance_enabled','0')==='1') {
            header('Cache-Control: no-store, private');
            header('Location: '.arc_workspace_base_url().'index.php',true,302);
            exit;
        }
    } catch (Throwable $e) { error_log('Arcoll manutenção: '.$e->getMessage()); }
}
if ($arcPrivateRoute && !$arcPublicEntry && (isset($_SESSION['arc_admin_id']) || isset($_SESSION['arc_user_id']))) {
    $now=time();
    $idleLimit=120;
    try {
        if (!isset($_SESSION['arc_idle_policy_checked']) || $now-(int)$_SESSION['arc_idle_policy_checked']>300) {
            $_SESSION['arc_idle_policy_minutes']=max(5,min(1440,(int)arc_platform_setting(arc_db(),'platform_session_idle_minutes','120')));
            $_SESSION['arc_idle_policy_checked']=$now;
        }
        $idleLimit=(int)($_SESSION['arc_idle_policy_minutes']??120);
    } catch (Throwable $e) { error_log('Arcoll sessão: '.$e->getMessage()); }
    if (isset($_SESSION['arc_last_activity']) && $now-(int)$_SESSION['arc_last_activity']>$idleLimit*60) {
        unset($_SESSION['arc_admin_id'],$_SESSION['arc_admin_email'],$_SESSION['arc_user_id'],$_SESSION['arc_user_email'],$_SESSION['arc_current_network_id']);
        session_regenerate_id(true);
    } else {
        $_SESSION['arc_last_activity']=$now;
        if (!isset($_SESSION['arc_account_checked']) || $now-(int)$_SESSION['arc_account_checked']>60) {
            try {
                $checkPdo=arc_db();
                if(isset($_SESSION['arc_user_id'])) {
                    $check=$checkPdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
                    $check->execute([(int)$_SESSION['arc_user_id']]);
                    if($check->fetchColumn()!=='active') {
                        unset($_SESSION['arc_user_id'],$_SESSION['arc_user_email'],$_SESSION['arc_current_network_id']);
                        session_regenerate_id(true);
                    }
                } elseif(isset($_SESSION['arc_admin_id'])) {
                    $check=$checkPdo->prepare('SELECT id FROM '.arc_table('admins').' WHERE id=? LIMIT 1');
                    $check->execute([(int)$_SESSION['arc_admin_id']]);
                    if($check->fetchColumn()===false) {
                        unset($_SESSION['arc_admin_id'],$_SESSION['arc_admin_email']);
                        session_regenerate_id(true);
                    }
                }
                $_SESSION['arc_account_checked']=$now;
            } catch (Throwable $e) { error_log('Arcoll verificação de acesso: '.$e->getMessage()); }
        }
    }
}
if ($arcPrivateRoute && !$arcPublicEntry && !isset($_SESSION['arc_admin_id']) && !isset($_SESSION['arc_user_id'])) {
    header('Cache-Control: no-store, private');
    header('Location: ' . arc_workspace_base_url() . 'index.php', true, 302);
    exit;
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

/** Catálogo compartilhado de identidade visual; a escolha é exclusiva da administração. */
function arc_visual_themes(): array {
    return [
      'windows'=>['name'=>'Windows Fluent','light'=>true,'accent'=>'#2563eb','bg'=>'#edf3fa','surface'=>'#ffffff','alt'=>'#e3ebf5','text'=>'#172b44','muted'=>'#536982','border'=>'#d0ddea'],
      'ocean'=>['name'=>'Digital Ocean','light'=>false,'accent'=>'#26bae9','bg'=>'#081c30','surface'=>'#112b44','alt'=>'#193851','text'=>'#e7f7ff','muted'=>'#a7c1d5','border'=>'#315169'],
      'crystal'=>['name'=>'Crystal Light','light'=>true,'accent'=>'#6366f1','bg'=>'#f2f5fa','surface'=>'#ffffff','alt'=>'#e9edf7','text'=>'#222842','muted'=>'#64718c','border'=>'#dce2ee'],
      'graphite'=>['name'=>'Graphite Pro','light'=>false,'accent'=>'#9aaee5','bg'=>'#16191f','surface'=>'#242a34','alt'=>'#303744','text'=>'#f1f4fa','muted'=>'#b0bbc9','border'=>'#485364'],
      'neon'=>['name'=>'Neon Tech','light'=>false,'accent'=>'#a879ff','bg'=>'#110e27','surface'=>'#221a40','alt'=>'#302551','text'=>'#f5f0ff','muted'=>'#c5b4e3','border'=>'#534474'],
      'emerald'=>['name'=>'Emerald Secure','light'=>false,'accent'=>'#32d4a1','bg'=>'#0b211d','surface'=>'#15352d','alt'=>'#20463a','text'=>'#e6fcf3','muted'=>'#acd5c5','border'=>'#386352'],
    ];
}
function arc_visual_theme(PDO $pdo): array {
    $themes=arc_visual_themes();
    $id=arc_platform_setting($pdo,'platform_visual_theme','windows');
    if(!isset($themes[$id]))$id='windows';
    $v=$themes[$id];$v['id']=$id;
    $mode=arc_platform_setting($pdo,'platform_visual_mode','theme');
    $v['mode']=in_array($mode,['theme','light','dark','auto'],true)?$mode:'theme';
    return $v;
}

/** Configurações administrativas sem gravar segredos em texto simples. */
function arc_platform_setting(PDO $pdo, string $key, string $default = ''): string {
    $q = $pdo->prepare('SELECT setting_value FROM ' . arc_table('settings') . ' WHERE setting_key=? LIMIT 1');
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function arc_platform_save_setting(PDO $pdo, string $key, string $value): void {
    if (!preg_match('/^platform_[a-z0-9_]{1,70}$/', $key)) throw new InvalidArgumentException('Chave inválida.');
    $q = $pdo->prepare('SELECT COUNT(*) FROM ' . arc_table('settings') . ' WHERE setting_key=?');
    $q->execute([$key]);
    if ((int)$q->fetchColumn() > 0) {
        $q = $pdo->prepare('UPDATE ' . arc_table('settings') . ' SET setting_value=? WHERE setting_key=?');
        $q->execute([$value,$key]);
    } else {
        $q = $pdo->prepare('INSERT INTO ' . arc_table('settings') . ' (setting_key,setting_value,value_type) VALUES (?, ?, ?)');
        $q->execute([$key,$value,'text']);
    }
}
function arc_platform_encrypt(string $plaintext): string {
    $key = hash('sha256', (string)(arc_config()['app_secret'] ?? ''), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if ($cipher === false) throw new RuntimeException('Falha ao proteger credencial.');
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}
function arc_platform_decrypt(string $stored): string {
    if (!str_starts_with($stored,'gcm:')) return '';
    $raw = base64_decode(substr($stored,4),true);
    if ($raw === false || strlen($raw) < 29) return '';
    $key = hash('sha256',(string)(arc_config()['app_secret'] ?? ''),true);
    return openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)) ?: '';
}

/** SMTP compartilhado: conexão autenticada STARTTLS ou TLS implícito. */
function arc_platform_smtp_send(PDO $pdo, string $to, string $subject, string $message, bool $html=false): void {
    $get=static fn(string $k,string $d='')=>arc_platform_setting($pdo,'platform_smtp_'.$k,$d);
    $host=trim($get('host'));$port=(int)$get('port','587');
    $mode=$get('encryption','starttls');$user=$get('username');
    $pass=arc_platform_decrypt($get('password'));$from=$get('from_email');
    if(!preg_match('/^[a-z0-9.-]+$/i',$host)||$host===''||$port<1||$port>65535||!in_array($mode,['starttls','tls'],true)||!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($user,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Parâmetros SMTP inválidos.');
    if($pass==='')throw new RuntimeException('Senha SMTP não configurada.');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
    $socket=@stream_socket_client(($mode==='tls'?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);
    if(!$socket)throw new RuntimeException('Não foi possível conectar ao servidor SMTP.');
    stream_set_timeout($socket,10);
    $read=function(array $codes)use($socket):string{
        $text='';
        do{$line=fgets($socket,4096);if($line===false)throw new RuntimeException('Conexão SMTP interrompida.');$text.=$line;
        }while(isset($line[3])&&$line[3]==='-');
        if(!in_array((int)substr($text,0,3),$codes,true))throw new RuntimeException('O servidor SMTP rejeitou a operação ('.substr($text,0,3).').');
        return $text;
    };
    $send=function(string $cmd,array $codes)use($socket,$read):string{
        if(fwrite($socket,$cmd."\r\n")===false)throw new RuntimeException('Falha de envio SMTP.');
        return $read($codes);
    };
    try{
        $read([220]);$send('EHLO arcoll.local',[250]);
        if($mode==='starttls'){
            $send('STARTTLS',[220]);
            if(stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)throw new RuntimeException('Falha na negociação TLS.');
            $send('EHLO arcoll.local',[250]);
        }
        $send('AUTH LOGIN',[334]);$send(base64_encode($user),[334]);$send(base64_encode($pass),[235]);
        $send('MAIL FROM:<'.$from.'>',[250]);$send('RCPT TO:<'.$to.'>',[250,251]);$send('DATA',[354]);
        $clean=static fn(string $v)=>str_replace(["\r","\n"],' ',trim($v));
        $body=$html ? chunk_split(base64_encode($message),76,"\r\n") : wordwrap(str_replace(["\r\n","\r"],"\n",$message),72,"\r\n",true);
        $body=preg_replace('/(?m)^\./','..',$body);
        $payload='From: '.$clean($get('from_name','Arcoll')).' <'.$from.">\r\nTo: <".$to.">\r\nSubject: =?UTF-8?B?".base64_encode($clean($subject))."?=\r\nMIME-Version: 1.0\r\nContent-Type: ".($html?'text/html':'text/plain')."; charset=UTF-8\r\nContent-Transfer-Encoding: ".($html?'base64':'8bit')."\r\n\r\n".$body."\r\n.";
        $send($payload,[250]);$send('QUIT',[221]);
    }finally{fclose($socket);}
}

// Política de retenção de logs disponível para auditorias e rotinas administrativas.
require_once __DIR__.'/log-maintenance.php';

/** Credencial revogável compartilhada pelos canais originais de comandos e backup. */
function arc_agent_authenticate(PDO $pdo, array $body): array
{
    $fp=strtolower(trim((string)($body['fingerprint']??'')));
    $code=strtoupper(trim((string)($body['site_code']??'')));
    $key=trim((string)($body['download_key']??''));
    if (!preg_match('/^[a-f0-9]{64}$/D',$fp) || !preg_match('/^[A-Z0-9]{5}$/D',$code) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new RuntimeException('Agente sem autorização.',403);
        $q=$pdo->prepare('SELECT a.id,a.network_id,a.account_id FROM '.arc_table('network_agents').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp]);$agent=$q->fetch();

    if (!$agent) throw new RuntimeException('Agente sem autorização.',403);
    $q=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?');
    $q->execute([(int)$agent['account_id']]);
    if ($q->fetchColumn()!=='active') throw new RuntimeException('Conta sem autorização.',403);
    return $agent;
}
