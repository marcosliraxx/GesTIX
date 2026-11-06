<?php
/* Caminho: includes/cloud-backup.php
   Backup em Nuvem (Gestão de Redes > Clean > Backup em Nuvem).

   Princípios:
   - Nenhum arquivo do usuário é gravado na plataforma: os blocos enviados pelo agente são repassados ao provedor em memória
     (php://input -> cURL). Só ficam no banco metadados mínimos: conexão, configuração, estado do envio em andamento.
   - Tokens OAuth e chaves S3 ficam criptografados (arc_platform_encrypt) e nunca são enviados ao agente.
   - As credenciais de API de cada provedor são configuradas pelo administrador em admin/configuracoes.php (aba Drive).
   - Provedores: Google Drive, Microsoft OneDrive, Dropbox e Amazon S3 (ou compatível: Wasabi, Backblaze B2, Cloudflare R2, MinIO).
     Para incluir outro provedor basta registrá-lo em arc_cloud_providers() e implementar as funções arc_cloud_<chave>_*. */
declare(strict_types=1);

const ARC_CLOUD_MAX_FOLDERS = 20;
const ARC_CLOUD_MAX_EXCLUSIONS = 100;
const ARC_CLOUD_UPLOAD_TTL = 86400;
const ARC_CLOUD_DEFAULT_ROOT = 'Arcoll Backup';

/** Provedores suportados. "chunk" é o tamanho do bloco repassado por requisição (múltiplo exigido pelo provedor). */
function arc_cloud_providers(): array
{
    return [
        'gdrive'   => ['name' => 'Google Drive',        'icon' => 'google',  'oauth' => true,  'chunk' => 3932160],
        'onedrive' => ['name' => 'Microsoft OneDrive',  'icon' => 'windows', 'oauth' => true,  'chunk' => 3932160],
        'dropbox'  => ['name' => 'Dropbox',             'icon' => 'dropbox', 'oauth' => true,  'chunk' => 4194304],
        's3'       => ['name' => 'Amazon S3 / compatível', 'icon' => 'cloud', 'oauth' => false, 'chunk' => 5242880],
    ];
}

/* ============================================================
   Esquema (idempotente, sem apagar dados)
   ============================================================ */
function arc_cloud_ensure_schema(PDO $pdo): void
{
    static $done=null;$done??=new WeakMap();if(isset($done[$pdo]))return;
    $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('cloud_connections') . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        network_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(16) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT \'\',
        identity VARCHAR(190) NOT NULL DEFAULT \'\',
        secret_enc TEXT NOT NULL,
        token_enc TEXT NULL,
        token_expires_at DATETIME NULL DEFAULT NULL,
        status ENUM(\'active\',\'error\') NOT NULL DEFAULT \'active\',
        last_error VARCHAR(255) NOT NULL DEFAULT \'\',
        quota_used BIGINT UNSIGNED NULL DEFAULT NULL,
        quota_total BIGINT UNSIGNED NULL DEFAULT NULL,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cloud_connections_network (network_id,account_id)
    )' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('cloud_backup_jobs') . ' (
        agent_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NULL DEFAULT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        settings_json TEXT NOT NULL,
        run_request INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cloud_jobs_network (network_id,account_id),
        KEY idx_cloud_jobs_connection (connection_id)
    )' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('cloud_backup_status') . ' (
        agent_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(16) NOT NULL DEFAULT \'idle\',
        status_json MEDIUMTEXT NOT NULL,
        reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cloud_status_network (network_id,account_id)
    )' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('cloud_backup_uploads') . ' (
        id CHAR(32) NOT NULL PRIMARY KEY,
        agent_id BIGINT UNSIGNED NOT NULL,
        network_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        total_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        received BIGINT UNSIGNED NOT NULL DEFAULT 0,
        state_enc TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cloud_uploads_agent (agent_id),
        KEY idx_cloud_uploads_expires (expires_at),
        KEY idx_cloud_uploads_network (network_id,account_id)
    )' . $engine);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_table('cloud_backup_folders') . ' (
        connection_id BIGINT UNSIGNED NOT NULL,
        path_hash CHAR(40) NOT NULL,
        remote_id VARCHAR(190) NOT NULL,
        PRIMARY KEY (connection_id,path_hash)
    )' . $engine);
    $done[$pdo]=true;
}

/* ============================================================
   Configuração do administrador
   ============================================================ */
function arc_cloud_provider_config(PDO $pdo, string $provider): array
{
    if (!isset(arc_cloud_providers()[$provider])) return ['enabled' => false];
    $key = static fn(string $name): string => 'platform_cloud_' . $provider . '_' . $name;
    $config = [
        'enabled'       => arc_platform_setting($pdo, $key('enabled'), '0') === '1',
        'client_id'     => arc_platform_setting($pdo, $key('client_id')),
        'client_secret' => arc_platform_decrypt(arc_platform_setting($pdo, $key('client_secret'))),
        'tenant'        => arc_platform_setting($pdo, $key('tenant'), 'common'),
        'endpoint'      => arc_platform_setting($pdo, $key('endpoint')),
        'region'        => arc_platform_setting($pdo, $key('region'), 'us-east-1'),
    ];
    if ($config['tenant'] === '' || !preg_match('/^[A-Za-z0-9.-]{1,80}$/D', $config['tenant'])) $config['tenant'] = 'common';
    $config['ready'] = $config['enabled'] && (!arc_cloud_providers()[$provider]['oauth'] || ($config['client_id'] !== '' && $config['client_secret'] !== ''));
    return $config;
}

/** Provedores liberados pelo administrador (dados públicos, sem segredos). */
function arc_cloud_available_providers(PDO $pdo): array
{
    $result = [];
    foreach (arc_cloud_providers() as $key => $meta) {
        $config = arc_cloud_provider_config($pdo, $key);
        if (!$config['ready']) continue;
        $result[] = ['key' => $key, 'name' => $meta['name'], 'icon' => $meta['icon'], 'oauth' => $meta['oauth'],
            'region' => $key === 's3' ? $config['region'] : null, 'endpoint' => $key === 's3' ? $config['endpoint'] : null];
    }
    return $result;
}

/** Regras do administrador (Configurações > Manutenção): quem pode escolher conta própria ou Drive da plataforma. */
function arc_cloud_policy(PDO $pdo): array
{
    return ['own' => arc_platform_setting($pdo, 'platform_cloud_allow_own', '1') === '1',
        'platform' => arc_platform_setting($pdo, 'platform_cloud_allow_platform', '1') === '1',
        'vdrive' => arc_platform_setting($pdo, 'platform_cloud_allow_vdrive', '1') === '1',
        'folder_picker' => arc_platform_setting($pdo, 'platform_cloud_allow_folder_picker', '1') === '1',
        'max_file_mb' => (int)(arc_cloud_max_file_bytes($pdo) / 1048576)];
}

/** Impede selecionar um destino que a política do administrador não permite. */
function arc_cloud_assert_destination_allowed(PDO $pdo, array $connection): void
{
    $policy = arc_cloud_policy($pdo);
    $managed = (int)$connection['network_id'] === 0 && (int)$connection['account_id'] === 0;
    if ($managed && !$policy['platform']) throw new InvalidArgumentException('O Drive da plataforma não está liberado pelo administrador.');
    if (!$managed && !$policy['own']) throw new InvalidArgumentException('O administrador não permite contas de nuvem próprias. Use o Drive da plataforma.');
}

function arc_cloud_max_file_bytes(PDO $pdo): int
{
    $mb = (int)arc_platform_setting($pdo, 'platform_cloud_max_file_mb', '5120');
    return max(1, min(51200, $mb ?: 5120)) * 1048576;
}

function arc_cloud_redirect_uri(): string
{
    return 'https://' . (string)($_SERVER['HTTP_HOST'] ?? '') . arc_workspace_base_url() . 'api/cloud-callback.php';
}

/* ============================================================
   HTTP para os provedores
   ============================================================ */
/** Retorna ['status'=>int,'headers'=>array (minúsculas),'body'=>string]. Falhas de rede viram RuntimeException com código 0. */
function arc_cloud_http(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 120): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL indisponível no servidor.', 0);
    if (!str_starts_with($url, 'https://')) throw new RuntimeException('Endereço do provedor sem HTTPS.', 0);
    [$resolvedHost,$resolvedAddresses]=arc_cloud_validate_url($url);
    $responseHeaders = [];
    $responseBody = '';
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($handle,string $chunk) use (&$responseBody): int {if(strlen($responseBody)+strlen($chunk)>1048576)return 0;$responseBody.=$chunk;return strlen($chunk);},
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $pos = strpos($line, ':');
            if ($pos > 0) $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            return strlen($line);
        },
    ];
    if ($method === 'HEAD') $options[CURLOPT_NOBODY] = true;
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
    elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) $options[CURLOPT_POSTFIELDS] = '';
    $options[CURLOPT_RESOLVE]=[$resolvedHost.':443:'.implode(',',$resolvedAddresses)];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failure = curl_errno($ch) !== 0 ? curl_error($ch) : '';
    curl_close($ch);
    if ($response === false) {
        error_log('Arcoll nuvem: falha de transporte HTTPS.');
        throw new RuntimeException('Falha de rede com o provedor de nuvem.', 0);
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
}

function arc_cloud_json(array $response): array
{
    $decoded = json_decode($response['body'], true);
    return is_array($decoded) ? $decoded : [];
}

/** Converte respostas de erro do provedor em RuntimeException (código = status HTTP). */
function arc_cloud_expect(array $response, array $accepted, string $context): array
{
    if (in_array($response['status'], $accepted, true)) return $response;
    error_log('Arcoll nuvem: ' . $context . ' HTTP ' . (int)$response['status']);
    throw new RuntimeException($context . ' (HTTP ' . (int)$response['status'] . ').', (int)$response['status']>=400?(int)$response['status']:502);
}

function arc_cloud_b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }

/* ============================================================
   OAuth 2.0 (Google, Microsoft, Dropbox) com PKCE e estado assinado
   ============================================================ */
function arc_cloud_state_key(): string { return hash('sha256', 'cloud-oauth|' . (string)(arc_config()['app_secret'] ?? ''), true); }

function arc_cloud_state_sign(array $payload): string
{
    $data = arc_cloud_b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $data . '.' . arc_cloud_b64url(hash_hmac('sha256', $data, arc_cloud_state_key(), true));
}

function arc_cloud_state_verify(string $state): ?array
{
    $parts = explode('.', $state);
    if (count($parts) !== 2 || !hash_equals(arc_cloud_b64url(hash_hmac('sha256', $parts[0], arc_cloud_state_key(), true)), $parts[1])) return null;
    $payload = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/'), true), true);
    return is_array($payload) && (int)($payload['exp'] ?? 0) >= time() ? $payload : null;
}

/** URL de autorização do provedor. O verificador PKCE fica só na sessão do navegador que iniciou a conexão. */
function arc_cloud_oauth_start(PDO $pdo, string $provider, int $networkId, int $agentId, int $userId, bool $managed = false): string
{
    $meta = arc_cloud_providers()[$provider] ?? null;
    $config = arc_cloud_provider_config($pdo, $provider);
    if (!$meta || !$meta['oauth'] || !$config['ready']) throw new InvalidArgumentException('Provedor indisponível. Peça ao administrador da plataforma para configurá-lo.');
    if (!arc_is_https()) throw new RuntimeException('A conexão com a nuvem exige HTTPS.');
    $nonce = bin2hex(random_bytes(16));
    $verifier = arc_cloud_b64url(random_bytes(48));
    $_SESSION['arc_cloud_oauth'] = [$nonce => ['verifier' => $verifier, 'time' => time()]] + array_slice((array)($_SESSION['arc_cloud_oauth'] ?? []), 0, 3, true);
    $state = arc_cloud_state_sign(['n' => $nonce, 'p' => $provider, 'net' => $networkId, 'ag' => $agentId, 'u' => $userId, 'managed' => $managed ? 1 : 0, 'exp' => time() + 900]);
    $common = ['client_id' => $config['client_id'], 'redirect_uri' => arc_cloud_redirect_uri(), 'response_type' => 'code', 'state' => $state,
        'code_challenge' => arc_cloud_b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256'];
    return match ($provider) {
        'gdrive' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($common + ['scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent'], '', '&', PHP_QUERY_RFC3986),
        'onedrive' => 'https://login.microsoftonline.com/' . rawurlencode($config['tenant']) . '/oauth2/v2.0/authorize?' . http_build_query($common + ['scope' => 'offline_access Files.ReadWrite User.Read', 'response_mode' => 'query', 'prompt' => 'select_account'], '', '&', PHP_QUERY_RFC3986),
        'dropbox' => 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($common + ['token_access_type' => 'offline'], '', '&', PHP_QUERY_RFC3986),
    };
}

function arc_cloud_token_endpoint(string $provider, array $config): string
{
    return match ($provider) {
        'gdrive' => 'https://oauth2.googleapis.com/token',
        'onedrive' => 'https://login.microsoftonline.com/' . rawurlencode($config['tenant']) . '/oauth2/v2.0/token',
        'dropbox' => 'https://api.dropboxapi.com/oauth2/token',
    };
}

/** Troca o código pelos tokens. Retorna ['refresh'=>string,'access'=>string,'expires'=>int]. */
function arc_cloud_oauth_exchange(PDO $pdo, string $provider, string $code, string $verifier): array
{
    $config = arc_cloud_provider_config($pdo, $provider);
    $response = arc_cloud_expect(arc_cloud_http('POST', arc_cloud_token_endpoint($provider, $config), ['Accept: application/json'], [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => arc_cloud_redirect_uri(), 'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'], 'code_verifier' => $verifier,
    ], 30), [200], 'Não foi possível concluir a autorização');
    $tokens = arc_cloud_json($response);
    if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
        throw new RuntimeException('O provedor não devolveu a autorização permanente. Remova o acesso do aplicativo na conta e tente novamente.');
    }
    return ['refresh' => (string)$tokens['refresh_token'], 'access' => (string)$tokens['access_token'], 'expires' => max(60, (int)($tokens['expires_in'] ?? 3600))];
}

/* ============================================================
   Conexões (contas de nuvem por rede)
   ============================================================ */
function arc_cloud_connection_public(array $row): array
{
    $meta = arc_cloud_providers()[$row['provider']] ?? ['name' => (string)$row['provider'], 'icon' => 'cloud'];
    return ['id' => (int)$row['id'], 'provider' => (string)$row['provider'], 'provider_name' => $meta['name'], 'icon' => $meta['icon'],
        'label' => (string)$row['label'], 'identity' => (string)$row['identity'], 'status' => (string)$row['status'], 'error' => (string)$row['last_error'],
        'quota_used' => $row['quota_used'] === null ? null : (int)$row['quota_used'], 'quota_total' => $row['quota_total'] === null ? null : (int)$row['quota_total'],
        'created_at' => (string)$row['created_at'], 'managed'=>((int)$row['network_id']===0 && (int)$row['account_id']===0)];
}

function arc_cloud_connection_get(PDO $pdo, int $id, int $networkId, int $accountId): ?array
{
    $q = $pdo->prepare('SELECT * FROM ' . arc_table('cloud_connections') . ' WHERE id=? AND ((network_id=? AND account_id=?) OR (network_id=0 AND account_id=0))');
    $q->execute([$id, $networkId, $accountId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function arc_cloud_connections_list(PDO $pdo, int $networkId, int $accountId): array
{
    $q = $pdo->prepare('SELECT * FROM ' . arc_table('cloud_connections') . ' WHERE (network_id=? AND account_id=?) OR (network_id=0 AND account_id=0) ORDER BY network_id,id');
    $q->execute([$networkId, $accountId]);
    $policy = arc_cloud_policy($pdo);
    $rows = array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC), static function(array $row) use ($policy): bool {
        $managed = (int)$row['network_id'] === 0 && (int)$row['account_id'] === 0;
        return $managed ? $policy['platform'] : $policy['own'];
    }));
    return array_map(static function(array $row): array {
        $item=arc_cloud_connection_public($row);
        if($item['managed']){$item['label']='Drive da plataforma · '.$item['provider_name'];$item['identity']='Armazenamento administrado pela plataforma';$item['quota_used']=null;$item['quota_total']=null;$item['error']=$item['status']==='active'?'':'A conexão da plataforma precisa ser revisada pelo administrador.';}
        return $item;
    }, $rows);
}

function arc_cloud_secret_read(array $connection): array
{
    $data = json_decode(arc_platform_decrypt((string)$connection['secret_enc']), true);
    return is_array($data) ? $data : [];
}

function arc_cloud_connection_mark(PDO $pdo, int $id, string $status, string $error = ''): void
{
    $pdo->prepare('UPDATE ' . arc_table('cloud_connections') . ' SET status=?,last_error=? WHERE id=?')->execute([$status, mb_substr($error, 0, 255), $id]);
}

/** Token de acesso válido (renova com bloqueio para evitar duas renovações simultâneas). S3 não usa token. */
function arc_cloud_access_token(PDO $pdo, array $connection): string
{
    $provider = (string)$connection['provider'];
    if ($provider === 's3') return '';
    $fresh = static fn(array $row): bool => !empty($row['token_enc']) && !empty($row['token_expires_at']) && strtotime((string)$row['token_expires_at']) > time() + 90;
    if ($fresh($connection)) { $token = arc_platform_decrypt((string)$connection['token_enc']); if ($token !== '') return $token; }
    $lock = 'arc_cloud_refresh_' . (int)$connection['id'];
    $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ',20)')->fetchColumn() === 1;
    if (!$got) throw new RuntimeException('Renovação de acesso em andamento. Tente novamente.',503);
    try {
        $q = $pdo->prepare('SELECT * FROM ' . arc_table('cloud_connections') . ' WHERE id=?');
        $q->execute([(int)$connection['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Conexão de nuvem removida.', 404);
        if ($fresh($row)) { $token = arc_platform_decrypt((string)$row['token_enc']); if ($token !== '') return $token; }
        $secret = arc_cloud_secret_read($row);
        $config = arc_cloud_provider_config($pdo, $provider);
        if (empty($secret['refresh_token']) || !$config['ready']) {
            arc_cloud_connection_mark($pdo, (int)$row['id'], 'error', 'Provedor desativado ou sem credenciais. Reconecte a conta.');
            throw new RuntimeException('Conexão de nuvem indisponível. Reconecte a conta.', 401);
        }
        $response = arc_cloud_http('POST', arc_cloud_token_endpoint($provider, $config), ['Accept: application/json'], [
            'grant_type' => 'refresh_token', 'refresh_token' => $secret['refresh_token'], 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
        ], 30);
        $tokens = arc_cloud_json($response);
        if ($response['status'] !== 200 || empty($tokens['access_token'])) {
            $revoked = in_array((string)($tokens['error'] ?? ''), ['invalid_grant', 'invalid_request'], true) || $response['status'] === 400 || $response['status'] === 401;
            if ($revoked) arc_cloud_connection_mark($pdo, (int)$row['id'], 'error', 'Autorização revogada ou expirada. Reconecte a conta.');
            throw new RuntimeException($revoked ? 'Autorização da nuvem revogada. Reconecte a conta.' : 'Provedor de nuvem indisponível para renovar o acesso.', $revoked ? 401 : 503);
        }
        if (!empty($tokens['refresh_token']) && $tokens['refresh_token'] !== $secret['refresh_token']) $secret['refresh_token'] = (string)$tokens['refresh_token'];   // Microsoft renova o refresh token
        $expires = date('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 3600)));
        $pdo->prepare('UPDATE ' . arc_table('cloud_connections') . " SET secret_enc=?,token_enc=?,token_expires_at=?,status='active',last_error='' WHERE id=?")
            ->execute([arc_platform_encrypt(json_encode($secret)), arc_platform_encrypt((string)$tokens['access_token']), $expires, (int)$row['id']]);
        return (string)$tokens['access_token'];
    } finally {
        if ($got) $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}

/** Cria a conexão depois da autorização (OAuth) ou da validação das chaves (S3). */
function arc_cloud_connection_create(PDO $pdo, int $networkId, int $accountId, int $userId, string $provider, array $secret, string $label = ''): int
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . arc_table('cloud_connections') . ' WHERE network_id=? AND account_id=?');
    $count->execute([$networkId, $accountId]);
    if ((int)$count->fetchColumn() >= 10) throw new InvalidArgumentException('Limite de 10 contas de nuvem por rede.');
    $access = (string)($secret['access_token'] ?? '');
    $expires = (int)($secret['expires'] ?? 0);
    unset($secret['access_token'], $secret['expires']);
    $pdo->prepare('INSERT INTO ' . arc_table('cloud_connections') . ' (account_id,network_id,provider,label,secret_enc,token_enc,token_expires_at,created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$accountId, $networkId, $provider, mb_substr($label, 0, 120), arc_platform_encrypt(json_encode($secret)),
            $access !== '' ? arc_platform_encrypt($access) : null, $access !== '' ? date('Y-m-d H:i:s', time() + $expires) : null, $userId]);
    $id = (int)$pdo->lastInsertId();
    try { arc_cloud_connection_refresh_info($pdo, $id); }
    catch (Throwable $e) { $pdo->prepare('DELETE FROM ' . arc_table('cloud_connections') . ' WHERE id=?')->execute([$id]); throw $e; }
    return $id;
}

/** Atualiza identidade (e-mail/bucket) e cota exibidas na tela. */
function arc_cloud_connection_refresh_info(PDO $pdo, int $id): array
{
    $q = $pdo->prepare('SELECT * FROM ' . arc_table('cloud_connections') . ' WHERE id=?');
    $q->execute([$id]);
    $connection = $q->fetch(PDO::FETCH_ASSOC);
    if (!$connection) throw new RuntimeException('Conexão inexistente.', 404);
    $fn = 'arc_cloud_' . $connection['provider'] . '_identity';
    [$identity, $used, $total] = $fn($pdo, $connection, arc_cloud_access_token($pdo, $connection));
    $label = (string)$connection['label'] !== '' ? (string)$connection['label'] : (arc_cloud_providers()[$connection['provider']]['name'] ?? '') . ' · ' . $identity;
    $pdo->prepare('UPDATE ' . arc_table('cloud_connections') . " SET identity=?,label=?,quota_used=?,quota_total=?,status='active',last_error='' WHERE id=?")
        ->execute([mb_substr($identity, 0, 190), mb_substr($label, 0, 120), $used, $total, $id]);
    return ['identity' => $identity, 'used' => $used, 'total' => $total];
}

/** Remove a conexão: revoga o acesso quando o provedor permite e desliga os backups que a usavam. Nenhum arquivo da nuvem é apagado. */
function arc_cloud_connection_delete(PDO $pdo, array $connection): void
{
    try {
        $secret = arc_cloud_secret_read($connection);
        $provider = (string)$connection['provider'];
        if ($provider === 'gdrive' && !empty($secret['refresh_token'])) arc_cloud_http('POST', 'https://oauth2.googleapis.com/revoke', [], ['token' => $secret['refresh_token']], 10);
        if ($provider === 'dropbox') arc_cloud_dropbox_rpc(arc_cloud_access_token($pdo, $connection), 'auth/token/revoke', null);
    } catch (Throwable $e) { /* revogação é melhor esforço; a conexão local é removida de qualquer forma */ }
    $id = (int)$connection['id'];
    $pending=$pdo->prepare('SELECT state_enc FROM '.arc_table('cloud_backup_uploads').' WHERE connection_id=? AND received<total_size LIMIT 50');$pending->execute([$id]);
    $deadline=microtime(true)+10;
    foreach($pending->fetchAll(PDO::FETCH_ASSOC) as $upload){if(microtime(true)>$deadline)break;try{arc_cloud_abort($pdo,$connection,arc_cloud_state_read($upload));}catch(Throwable $e){/* A política de lifecycle do provedor cobre indisponibilidade externa. */}}
    $pdo->prepare('UPDATE ' . arc_table('cloud_backup_jobs') . ' SET connection_id=NULL,enabled=0 WHERE connection_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM ' . arc_table('cloud_backup_uploads') . ' WHERE connection_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM ' . arc_table('cloud_backup_folders') . ' WHERE connection_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM ' . arc_table('cloud_connections') . ' WHERE id=?')->execute([$id]);
}

/** Remove tudo que a plataforma guarda de backups de uma rede (usado na exclusão da rede). */
function arc_cloud_purge_network(PDO $pdo, int $networkId, int $accountId): void
{
    $q = $pdo->prepare('SELECT id FROM ' . arc_table('cloud_connections') . ' WHERE network_id=? AND account_id=?');
    $q->execute([$networkId, $accountId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $pdo->prepare('DELETE FROM ' . arc_table('cloud_backup_folders') . ' WHERE connection_id=?')->execute([(int)$id]);
    foreach (['cloud_backup_uploads', 'cloud_backup_status', 'cloud_backup_jobs', 'cloud_connections'] as $table) {
        $pdo->prepare('DELETE FROM ' . arc_table($table) . ' WHERE network_id=? AND account_id=?')->execute([$networkId, $accountId]);
    }
}

/* ============================================================
   Configuração do backup de cada computador
   ============================================================ */
function arc_cloud_default_settings(): array
{
    return ['folders' => [], 'schedule' => 'continuous', 'interval_minutes' => 60, 'daily_time' => '02:00', 'mirror_deletes' => false,
        'exclusions' => [], 'max_file_mb' => 2048, 'bandwidth_kbps' => 0, 'remote_root' => ARC_CLOUD_DEFAULT_ROOT,
        'vdrive' => false, 'vdrive_letter' => 'Z'];
}

function arc_cloud_clean_segment(string $name, int $max = 150): string
{
    $name = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]+/u', '_', $name) ?? '';
    $name = rtrim(trim($name), '. ');
    if ($name === '' || $name === '.' || $name === '..') $name = '_';
    return mb_substr($name, 0, $max);
}

/** Valida o que o gestor ou o agente enviou. Lança InvalidArgumentException com mensagem pronta para a tela. */
function arc_cloud_validate_settings(array $input): array
{
    $out = arc_cloud_default_settings();
    $folders = $input['folders'] ?? [];
    if (!is_array($folders) || count($folders) > ARC_CLOUD_MAX_FOLDERS) throw new InvalidArgumentException('Escolha até ' . ARC_CLOUD_MAX_FOLDERS . ' pastas.');
    $labels = [];
    foreach ($folders as $folder) {
        $path = trim(is_array($folder) ? (string)($folder['path'] ?? '') : (string)$folder);
        if ($path === '') continue;
        if (mb_strlen($path) > 260 || preg_match('/[\x00-\x1f"<>|*?]/', $path) || preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $path)) throw new InvalidArgumentException('Caminho de pasta inválido: ' . mb_substr($path, 0, 60));
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|%[A-Za-z_][A-Za-z0-9_]*%[\\\\/]|\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+)~', $path)) throw new InvalidArgumentException('Use um caminho completo do Windows (ex.: C:\\Dados ou %USERPROFILE%\\Documents): ' . mb_substr($path, 0, 60));
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) throw new InvalidArgumentException('Selecione pastas de discos locais no agente.');
        $plain = rtrim(str_replace('/', '\\', $path), '\\');
        if (preg_match('~^[A-Za-z]:$~', $plain) || preg_match('~^[A-Za-z]:\\\\(?:Windows|Program Files(?: \(x86\))?|ProgramData|\$Recycle\.Bin|System Volume Information)(?:\\\\|$)~i', $plain)) {
            throw new InvalidArgumentException('Esta pasta não pode ser incluída (raiz do disco ou pasta do sistema): ' . mb_substr($path, 0, 60));
        }
        $pieces = preg_split('~[\\\\/]~', $plain) ?: [];
        $custom = is_array($folder) ? trim((string)($folder['label'] ?? '')) : '';
        $label = arc_cloud_clean_segment($custom !== '' ? $custom : (string)end($pieces), 80);
        $base = $label;
        for ($n = 2; isset($labels[mb_strtolower($label)]); $n++) $label = mb_substr($base, 0, 70) . ' ' . $n;
        $labels[mb_strtolower($label)] = true;
        $out['folders'][] = ['path' => $plain, 'label' => $label];
    }
    $schedule = (string)($input['schedule'] ?? 'continuous');
    if (!in_array($schedule, ['continuous', 'interval', 'daily', 'manual'], true)) throw new InvalidArgumentException('Programação inválida.');
    $out['schedule'] = $schedule;
    $interval = (int)($input['interval_minutes'] ?? 60);
    if (!in_array($interval, [15, 30, 60, 180, 360, 720], true)) throw new InvalidArgumentException('Intervalo inválido.');
    $out['interval_minutes'] = $interval;
    $time = (string)($input['daily_time'] ?? '02:00');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) throw new InvalidArgumentException('Horário inválido.');
    $out['daily_time'] = $time;
    if (!empty($input['mirror_deletes'])) throw new InvalidArgumentException('O backup preserva os arquivos na nuvem quando removidos do computador.');
    $out['mirror_deletes'] = false;
    $exclusions = $input['exclusions'] ?? [];
    if (!is_array($exclusions) || count($exclusions) > ARC_CLOUD_MAX_EXCLUSIONS) throw new InvalidArgumentException('Limite de ' . ARC_CLOUD_MAX_EXCLUSIONS . ' exclusões.');
    foreach ($exclusions as $pattern) {
        $pattern = trim((string)$pattern);
        if ($pattern === '') continue;
        if (mb_strlen($pattern) > 200 || preg_match('/[\x00-\x1f]/', $pattern)) throw new InvalidArgumentException('Exclusão inválida: ' . mb_substr($pattern, 0, 40));
        $out['exclusions'][] = $pattern;
    }
    $maxMb = (int)($input['max_file_mb'] ?? 2048);
    if ($maxMb < 1 || $maxMb > 51200) throw new InvalidArgumentException('Tamanho máximo por arquivo fora do intervalo (1 a 51200 MB).');
    $out['max_file_mb'] = $maxMb;
    $bandwidth = (int)($input['bandwidth_kbps'] ?? 0);
    if ($bandwidth < 0 || $bandwidth > 1048576) throw new InvalidArgumentException('Limite de velocidade inválido.');
    $out['bandwidth_kbps'] = $bandwidth;
    $out['vdrive'] = !empty($input['vdrive']);
    $letter = strtoupper(trim((string)($input['vdrive_letter'] ?? 'Z')));
    if (!preg_match('/^[D-Z]$/D', $letter)) throw new InvalidArgumentException('Letra do disco virtual inválida (use de D a Z).');
    $out['vdrive_letter'] = $letter;
    $root = arc_cloud_clean_segment((string)($input['remote_root'] ?? ARC_CLOUD_DEFAULT_ROOT), 80);
    $out['remote_root'] = $root === '_' ? ARC_CLOUD_DEFAULT_ROOT : $root;
    return $out;
}

function arc_cloud_job_get(PDO $pdo, int $agentId, int $networkId, int $accountId): array
{
    $q = $pdo->prepare('SELECT connection_id,enabled,settings_json,run_request,updated_at FROM ' . arc_table('cloud_backup_jobs') . ' WHERE agent_id=? AND network_id=? AND account_id=?');
    $q->execute([$agentId, $networkId, $accountId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $stored = $row ? json_decode((string)$row['settings_json'], true) : null;
    return ['connection_id' => $row && $row['connection_id'] !== null ? (int)$row['connection_id'] : null, 'enabled' => $row ? (bool)$row['enabled'] : false,
        'settings' => array_merge(arc_cloud_default_settings(), is_array($stored) ? $stored : []), 'run_request' => $row ? (int)$row['run_request'] : 0,
        'updated_at' => $row ? (string)$row['updated_at'] : null];
}

function arc_cloud_job_save(PDO $pdo, int $agentId, int $networkId, int $accountId, ?int $connectionId, bool $enabled, array $settings, bool $keepConnection = false): void
{
    $current = arc_cloud_job_get($pdo, $agentId, $networkId, $accountId);
    if ($keepConnection) $connectionId = $current['connection_id'];
    if ($connectionId !== null) {
        $selected = arc_cloud_connection_get($pdo, $connectionId, $networkId, $accountId);
        if (!$selected) throw new InvalidArgumentException('Conta de nuvem inválida.');
        arc_cloud_assert_destination_allowed($pdo, $selected);
    }
    if ($enabled && $connectionId === null) throw new InvalidArgumentException('Conecte uma conta de nuvem antes de ativar o backup.');
    $pdo->prepare('INSERT INTO ' . arc_table('cloud_backup_jobs') . ' (agent_id,network_id,account_id,connection_id,enabled,settings_json) VALUES (?,?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),connection_id=VALUES(connection_id),enabled=VALUES(enabled),settings_json=VALUES(settings_json)')
        ->execute([$agentId, $networkId, $accountId, $connectionId, $enabled ? 1 : 0, json_encode($settings, JSON_UNESCAPED_UNICODE)]);
}

/** Pede ao agente uma execução imediata (o agente compara o contador com o último atendido). */
function arc_cloud_job_run_now(PDO $pdo, int $agentId, int $networkId, int $accountId): void
{
    $pdo->prepare('UPDATE ' . arc_table('cloud_backup_jobs') . ' SET run_request=run_request+1 WHERE agent_id=? AND network_id=? AND account_id=?')->execute([$agentId, $networkId, $accountId]);
}

const ARC_CLOUD_STATES = ['idle', 'waiting', 'scanning', 'uploading', 'paused', 'error', 'disabled'];

/** Sanitiza o estado informado pelo agente (nada além de contadores e textos curtos chega à tela). */
function arc_cloud_sanitize_status(array $in): array
{
    $int = static fn(mixed $v): int => max(0, min(PHP_INT_MAX >> 1, (int)$v));
    $text = static fn(mixed $v, int $n): string => mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)$v) ?? '', 0, $n);
    $state = in_array($in['state'] ?? '', ARC_CLOUD_STATES, true) ? (string)$in['state'] : 'idle';
    $out = ['state' => $state, 'message' => $text($in['message'] ?? '', 200), 'current' => $text($in['current'] ?? '', 260),
        'files_total' => $int($in['files_total'] ?? 0), 'files_done' => $int($in['files_done'] ?? 0), 'bytes_total' => $int($in['bytes_total'] ?? 0), 'bytes_done' => $int($in['bytes_done'] ?? 0),
        'errors' => $int($in['errors'] ?? 0), 'skipped' => $int($in['skipped'] ?? 0), 'vdrive' => $text($in['vdrive'] ?? '', 160), 'synced_files' => $int($in['synced_files'] ?? 0), 'synced_bytes' => $int($in['synced_bytes'] ?? 0),
        'speed_bps' => $int($in['speed_bps'] ?? 0), 'started_at' => $text($in['started_at'] ?? '', 25), 'last_run_at' => $text($in['last_run_at'] ?? '', 25),
        'last_success_at' => $text($in['last_success_at'] ?? '', 25), 'next_run_at' => $text($in['next_run_at'] ?? '', 25), 'agent_version' => $text($in['agent_version'] ?? '', 20),
        'run_ack' => $int($in['run_ack'] ?? 0), 'runs' => [], 'failures' => [], 'folders' => []];
    foreach (array_slice(is_array($in['runs'] ?? null) ? $in['runs'] : [], 0, 15) as $run) {
        if (!is_array($run)) continue;
        $out['runs'][] = ['at' => $text($run['at'] ?? '', 25), 'files' => $int($run['files'] ?? 0), 'bytes' => $int($run['bytes'] ?? 0), 'errors' => $int($run['errors'] ?? 0), 'seconds' => $int($run['seconds'] ?? 0), 'result' => $text($run['result'] ?? '', 40)];
    }
    foreach (array_slice(is_array($in['failures'] ?? null) ? $in['failures'] : [], 0, 12) as $failure) {
        if (!is_array($failure)) continue;
        $out['failures'][] = ['path' => $text($failure['path'] ?? '', 260), 'message' => $text($failure['message'] ?? '', 160)];
    }
    foreach (array_slice(is_array($in['folders'] ?? null) ? $in['folders'] : [], 0, ARC_CLOUD_MAX_FOLDERS) as $folder) {
        if (!is_array($folder)) continue;
        $out['folders'][] = ['label' => $text($folder['label'] ?? '', 80), 'files' => $int($folder['files'] ?? 0), 'bytes' => $int($folder['bytes'] ?? 0), 'pending' => $int($folder['pending'] ?? 0), 'missing' => !empty($folder['missing'])];
    }
    return $out;
}

function arc_cloud_status_save(PDO $pdo, int $agentId, int $networkId, int $accountId, array $status): void
{
    $pdo->prepare('INSERT INTO ' . arc_table('cloud_backup_status') . ' (agent_id,network_id,account_id,state,status_json) VALUES (?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),state=VALUES(state),status_json=VALUES(status_json),reported_at=CURRENT_TIMESTAMP')
        ->execute([$agentId, $networkId, $accountId, $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
}

function arc_cloud_status_get(PDO $pdo, int $agentId, int $networkId, int $accountId): ?array
{
    $q = $pdo->prepare('SELECT status_json,reported_at FROM ' . arc_table('cloud_backup_status') . ' WHERE agent_id=? AND network_id=? AND account_id=?');
    $q->execute([$agentId, $networkId, $accountId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $status = $row ? json_decode((string)$row['status_json'], true) : null;
    if (!is_array($status)) return null;
    $status['reported_at'] = (string)$row['reported_at'];
    $status['fresh'] = strtotime((string)$row['reported_at']) >= time() - 150;
    return $status;
}

/* ============================================================
   Envio: caminhos remotos e sessões de upload (repasse em memória)
   ============================================================ */
/** Segmentos do destino: raiz / computador / pasta / caminho relativo. Retorna null se o caminho enviado pelo agente não for aceitável. */
function arc_cloud_remote_segments(array $job, string $machine, int $folderIndex, string $relative): ?array
{
    $folder = $job['settings']['folders'][$folderIndex] ?? null;
    if (!is_array($folder) || $relative === '' || strlen($relative) > 700 || str_starts_with($relative, '/') || preg_match('/[\x00-\x1f]/', $relative)) return null;
    $segments = [arc_cloud_clean_segment((string)$job['settings']['remote_root'], 80), 'Rede-' . (int)$job['network_id'], 'Computador-' . (int)$job['agent_id'], arc_cloud_clean_segment((string)$folder['label'], 65) . '-' . substr(hash('sha256',strtolower((string)$folder['path'])),0,12)];
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return null;
        if (arc_cloud_clean_segment($part) !== $part) return null;
        $segments[] = $part;
    }
    return count($segments) <= 40 ? $segments : null;
}

function arc_cloud_state_read(array $row): array
{
    $state = json_decode(arc_platform_decrypt((string)$row['state_enc']), true);
    return is_array($state) ? $state : [];
}

function arc_cloud_state_write(PDO $pdo, string $id, array $state, int $received): void
{
    $pdo->prepare('UPDATE ' . arc_table('cloud_backup_uploads') . ' SET state_enc=?,received=?,expires_at=? WHERE id=?')
        ->execute([arc_platform_encrypt(json_encode($state, JSON_UNESCAPED_UNICODE)), $received, date('Y-m-d H:i:s', time() + ARC_CLOUD_UPLOAD_TTL), $id]);
}

/** Remove sessões vencidas e cancela, quando possível, envios multipart abandonados (evita cobrança por partes órfãs no S3). */
function arc_cloud_purge_uploads(PDO $pdo): void
{
    $pdo->exec('DELETE FROM '.arc_table('cloud_backup_uploads').' WHERE expires_at<NOW() AND received=total_size LIMIT 2000');
    $q = $pdo->query('SELECT u.id AS upload_id,u.state_enc,c.* FROM ' . arc_table('cloud_backup_uploads') . ' u LEFT JOIN ' . arc_table('cloud_connections') . ' c ON c.id=u.connection_id WHERE u.expires_at<NOW() LIMIT 25');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try { if (($row['provider'] ?? '') !== '') arc_cloud_abort($pdo, $row, arc_cloud_state_read($row)); } catch (Throwable $e) { /* melhor esforço */ }
        $pdo->prepare('DELETE FROM ' . arc_table('cloud_backup_uploads') . ' WHERE id=?')->execute([(string)$row['upload_id']]);
    }
}

/** Inicia o envio de um arquivo. Retorna ['upload_id'=>?,'chunk'=>int,'done'=>bool]. */
function arc_cloud_upload_begin(PDO $pdo, array $connection, int $agentId, array $segments, int $size, int $mtime, ?string $requestId = null, ?int $networkId = null, ?int $accountId = null): array
{
    $provider = (string)$connection['provider'];
    $meta = arc_cloud_providers()[$provider];
    $token = arc_cloud_access_token($pdo, $connection);
    $fn = 'arc_cloud_' . $provider . '_begin';
    try { $state = $fn($pdo, $connection, $token, $segments, $size, $mtime); }
    catch (RuntimeException $e) {
        if ($provider === 'gdrive' && $e->getCode() === 404) {   // pasta em cache removida na nuvem: reconstrói
            $pdo->prepare('DELETE FROM ' . arc_table('cloud_backup_folders') . ' WHERE connection_id=?')->execute([(int)$connection['id']]);
            $state = $fn($pdo, $connection, $token, $segments, $size, $mtime);
        } else throw $e;
    }
    $done = !empty($state['done']);
    $id = $requestId ?? bin2hex(random_bytes(16));
    if ($done) $state = ['done'=>true];
    $pdo->prepare('INSERT INTO ' . arc_table('cloud_backup_uploads') . ' (id,agent_id,network_id,account_id,connection_id,total_size,received,state_enc,expires_at) VALUES (?,?,?,?,?,?,0,?,?)')
        ->execute([$id, $agentId, $networkId ?? (int)$connection['network_id'], $accountId ?? (int)$connection['account_id'], (int)$connection['id'], $size, arc_platform_encrypt(json_encode($state, JSON_UNESCAPED_UNICODE)), date('Y-m-d H:i:s', time() + ARC_CLOUD_UPLOAD_TTL)]);
    if ($done) arc_cloud_state_write($pdo,$id,$state,$size);
    return ['upload_id' => $id, 'chunk' => $meta['chunk'], 'done' => $done, 'next_offset'=>$done?$size:0];
}

/** Repassa um bloco ao provedor. Retorna ['next_offset'=>int,'done'=>bool]. O bloco nunca é gravado em disco. */
function arc_cloud_upload_chunk(PDO $pdo, array $upload, array $connection, string $data, int $offset): array
{
    $total = (int)$upload['total_size'];
    $received = (int)$upload['received'];
    if (!empty(arc_cloud_state_read($upload)['done'])) return ['next_offset'=>$total,'done'=>true];
    if ($offset !== $received) return ['next_offset' => $received, 'done' => false, 'resync' => true];
    $length = strlen($data);
    if ($length < 1 || $offset + $length > $total) throw new InvalidArgumentException('Bloco fora do tamanho declarado.');
    $final = $offset + $length === $total;
    $meta = arc_cloud_providers()[(string)$connection['provider']];
    if (!$final && $length !== $meta['chunk'] && (string)$connection['provider'] !== 'dropbox') throw new InvalidArgumentException('Tamanho de bloco inválido.');
    $state = arc_cloud_state_read($upload);
    $fn = 'arc_cloud_' . $connection['provider'] . '_chunk';
    try { $result = $fn($pdo, $connection, arc_cloud_access_token($pdo, $connection), $state, $data, $offset, $total, $final); }
    catch (Throwable $e) {
        if (in_array((int)$e->getCode(),[404,410],true)) {
            if($connection['provider']==='dropbox')$pdo->prepare('DELETE FROM '.arc_table('cloud_backup_folders').' WHERE connection_id=?')->execute([(int)$connection['id']]);
            try{arc_cloud_abort($pdo,$connection,$state);}catch(Throwable $ignored){}
            $pdo->prepare('DELETE FROM '.arc_table('cloud_backup_uploads').' WHERE id=?')->execute([(string)$upload['id']]);
            throw new RuntimeException('Sessão do provedor expirada. O arquivo será reenviado no próximo ciclo.',410);
        }
        arc_cloud_state_write($pdo,(string)$upload['id'],$state,$received);throw $e;
    }
    if (!empty($result['done'])) {
        arc_cloud_state_write($pdo,(string)$upload['id'],['done'=>true],$total);
        return ['next_offset' => $total, 'done' => true];
    }
    $next = max(0, min($total - 1, (int)$result['next']));
    arc_cloud_state_write($pdo, (string)$upload['id'], $state, $next);
    return ['next_offset' => $next, 'done' => false];
}

function arc_cloud_abort(PDO $pdo, array $connection, array $state): void
{
    $provider = (string)$connection['provider'];
    if ($provider === 's3' && !empty($state['upload_id'])) {[$url,$headers]=arc_cloud_s3_prepare(arc_cloud_s3_config($connection),'DELETE',(string)$state['key'],['uploadId'=>(string)$state['upload_id']]);arc_cloud_http('DELETE',$url,$headers,null,10);}
    if ($provider === 'onedrive' && !empty($state['url'])) arc_cloud_http('DELETE', (string)$state['url'], [], null, 15);
}


/* ---------------- Google Drive ---------------- */
function arc_cloud_gdrive_headers(string $token, array $extra = []): array { return array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], $extra); }

function arc_cloud_gdrive_identity(PDO $pdo, array $connection, string $token): array
{
    $about = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET', 'https://www.googleapis.com/drive/v3/about?fields=' . rawurlencode('user(emailAddress),storageQuota(limit,usage)'), arc_cloud_gdrive_headers($token), null, 30), [200], 'Não foi possível consultar o Google Drive'));
    $quota = $about['storageQuota'] ?? [];
    return [(string)($about['user']['emailAddress'] ?? 'Conta Google'), isset($quota['usage']) ? (int)$quota['usage'] : null, isset($quota['limit']) ? (int)$quota['limit'] : null];
}

function arc_cloud_gdrive_q(string $value): string { return str_replace(['\\', "'"], ['\\\\', "\\'"], $value); }

/** Pesquisa um item pelo nome dentro de uma pasta (somente os criados pelo aplicativo: escopo drive.file). */
function arc_cloud_gdrive_find(string $token, string $parent, string $name, bool $folder): ?string
{
    $query = "name='" . arc_cloud_gdrive_q($name) . "' and '" . $parent . "' in parents and trashed=false" . ($folder ? " and mimeType='application/vnd.google-apps.folder'" : " and mimeType!='application/vnd.google-apps.folder'");
    $reply = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET', 'https://www.googleapis.com/drive/v3/files?pageSize=1&fields=' . rawurlencode('files(id)') . '&q=' . rawurlencode($query), arc_cloud_gdrive_headers($token), null, 30), [200], 'Consulta ao Google Drive falhou'));
    return isset($reply['files'][0]['id']) ? (string)$reply['files'][0]['id'] : null;
}

/** Resolve (e opcionalmente cria) a cadeia de pastas. O cache evita uma consulta por arquivo. */
function arc_cloud_gdrive_folder(PDO $pdo, array $connection, string $token, array $folders, bool $create): ?string
{
    $lock='arc_cloud_folders_'.(int)$connection['id'];
    if((int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lock).',20)')->fetchColumn()!==1)throw new RuntimeException('Criação de pastas em andamento.',503);
    try {
    $parent = 'root';
    $trail = '';
    foreach ($folders as $name) {
        $trail .= '/' . $name;
        $hash = sha1($trail);
        $q = $pdo->prepare('SELECT remote_id FROM ' . arc_table('cloud_backup_folders') . ' WHERE connection_id=? AND path_hash=?');
        $q->execute([(int)$connection['id'], $hash]);
        $id = $q->fetchColumn();
        if ($id === false) {
            $id = arc_cloud_gdrive_find($token, $parent, (string)$name, true);
            if ($id === null) {
                if (!$create) return null;
                $created = arc_cloud_json(arc_cloud_expect(arc_cloud_http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', arc_cloud_gdrive_headers($token, ['Content-Type: application/json; charset=UTF-8']),
                    json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent]], JSON_UNESCAPED_UNICODE), 30), [200], 'Não foi possível criar a pasta no Google Drive'));
                $id = (string)($created['id'] ?? '');
                if ($id === '') throw new RuntimeException('Resposta inválida do Google Drive.', 502);
            }
            $pdo->prepare('INSERT IGNORE INTO ' . arc_table('cloud_backup_folders') . ' (connection_id,path_hash,remote_id) VALUES (?,?,?)')->execute([(int)$connection['id'], $hash, (string)$id]);
        }
        $parent = (string)$id;
    }
    return $parent;
    } finally {$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')');}
}

function arc_cloud_gdrive_begin(PDO $pdo, array $connection, string $token, array $segments, int $size, int $mtime): array
{
    $name = (string)array_pop($segments);
    $parent = (string)arc_cloud_gdrive_folder($pdo, $connection, $token, $segments, true);
    $existing = arc_cloud_gdrive_find($token, $parent, $name, false);
    $modified = gmdate('Y-m-d\TH:i:s\Z', $mtime > 0 ? $mtime : time());
    if ($size === 0) {
        if ($existing !== null) arc_cloud_expect(arc_cloud_http('PATCH', 'https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode($existing) . '?uploadType=media', arc_cloud_gdrive_headers($token, ['Content-Type: application/octet-stream']), '', 30), [200], 'Falha ao atualizar arquivo vazio');
        else arc_cloud_expect(arc_cloud_http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', arc_cloud_gdrive_headers($token, ['Content-Type: application/json; charset=UTF-8']), json_encode(['name' => $name, 'parents' => [$parent], 'modifiedTime' => $modified], JSON_UNESCAPED_UNICODE), 30), [200], 'Falha ao criar arquivo vazio');
        return ['done' => true];
    }
    $headers = arc_cloud_gdrive_headers($token, ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/octet-stream', 'X-Upload-Content-Length: ' . $size]);
    $url = $existing !== null ? 'https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode($existing) . '?uploadType=resumable&fields=id,size' : 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,size';
    $body = $existing !== null ? ['modifiedTime' => $modified] : ['name' => $name, 'parents' => [$parent], 'modifiedTime' => $modified];
    $response = arc_cloud_expect(arc_cloud_http($existing !== null ? 'PATCH' : 'POST', $url, $headers, json_encode($body, JSON_UNESCAPED_UNICODE), 30), [200], 'Google Drive recusou o envio');
    $location = (string)($response['headers']['location'] ?? '');
    if (!str_starts_with($location, 'https://')) throw new RuntimeException('Google Drive não abriu a sessão de envio.', 502);
    if (!preg_match('/(?:^|\.)googleapis\.com$/D',(string)parse_url($location,PHP_URL_HOST))) throw new RuntimeException('Sessão Google Drive inválida.',502);
    return ['url' => $location];
}

function arc_cloud_gdrive_chunk(PDO $pdo, array $connection, string $token, array &$state, string $data, int $offset, int $total, bool $final): array
{
    $range = 'bytes ' . $offset . '-' . ($offset + strlen($data) - 1) . '/' . $total;
    $response = arc_cloud_http('PUT', (string)$state['url'], arc_cloud_gdrive_headers($token,['Content-Type: application/octet-stream', 'Content-Range: ' . $range]), $data, 180);
    if (in_array($response['status'], [200, 201], true)) { $item=arc_cloud_json($response);if(isset($item['size'])&&(int)$item['size']!==$total)throw new RuntimeException('Tamanho final não confirmado pelo provedor.',502);return ['done' => true]; }
    if ($response['status'] === 308) {
        $reported = (string)($response['headers']['range'] ?? '');
        return ['done' => false, 'next' => preg_match('/bytes=0-(\d+)/', $reported, $m) ? (int)$m[1] + 1 : 0];
    }
    arc_cloud_expect($response, [], 'Google Drive recusou o bloco');
    return ['done' => false, 'next' => $offset];
}


/* ---------------- Microsoft OneDrive (Graph, caminhos) ---------------- */
function arc_cloud_onedrive_path(array $segments): string { return 'https://graph.microsoft.com/v1.0/me/drive/root:/' . implode('/', array_map('rawurlencode', $segments)); }

function arc_cloud_onedrive_identity(PDO $pdo, array $connection, string $token): array
{
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $me = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET', 'https://graph.microsoft.com/v1.0/me?$select=userPrincipalName,mail,displayName', $headers, null, 30), [200], 'Não foi possível consultar a conta Microsoft'));
    $drive = arc_cloud_json(arc_cloud_http('GET', 'https://graph.microsoft.com/v1.0/me/drive?$select=quota', $headers, null, 30));
    $quota = $drive['quota'] ?? [];
    return [(string)($me['mail'] ?? $me['userPrincipalName'] ?? $me['displayName'] ?? 'Conta Microsoft'), isset($quota['used']) ? (int)$quota['used'] : null, isset($quota['total']) ? (int)$quota['total'] : null];
}

function arc_cloud_onedrive_begin(PDO $pdo, array $connection, string $token, array $segments, int $size, int $mtime): array
{
    arc_cloud_onedrive_folders($token,array_slice($segments,0,-1));
    $base = arc_cloud_onedrive_path($segments);
    if ($size === 0) {
        arc_cloud_expect(arc_cloud_http('PUT', $base . ':/content?@microsoft.graph.conflictBehavior=replace', ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream'], '', 60), [200, 201], 'OneDrive recusou o arquivo vazio');
        return ['done' => true];
    }
    $item = ['@microsoft.graph.conflictBehavior' => 'replace'];
    if ($mtime > 0) $item['fileSystemInfo'] = ['lastModifiedDateTime' => gmdate('Y-m-d\TH:i:s\Z', $mtime)];
    $response = arc_cloud_expect(arc_cloud_http('POST', $base . ':/createUploadSession', ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'], json_encode(['item' => $item]), 30), [200], 'OneDrive recusou o envio');
    $url = (string)(arc_cloud_json($response)['uploadUrl'] ?? '');
    if (!str_starts_with($url, 'https://')) throw new RuntimeException('OneDrive não abriu a sessão de envio.', 502);
    return ['url' => $url];
}

function arc_cloud_onedrive_chunk(PDO $pdo, array $connection, string $token, array &$state, string $data, int $offset, int $total, bool $final): array
{
    $range = 'bytes ' . $offset . '-' . ($offset + strlen($data) - 1) . '/' . $total;
    $response = arc_cloud_http('PUT', (string)$state['url'], ['Content-Type: application/octet-stream', 'Content-Range: ' . $range], $data, 180);
    if (in_array($response['status'], [200, 201], true)) { $item=arc_cloud_json($response);if(isset($item['size'])&&(int)$item['size']!==$total)throw new RuntimeException('Tamanho final não confirmado pelo provedor.',502);return ['done' => true]; }
    if ($response['status'] === 416) $response = arc_cloud_http('GET', (string)$state['url'], ['Accept: application/json'], null, 30);   // trecho já recebido: consulta o próximo esperado
    if (in_array($response['status'], [200, 202], true)) {
        $expected = (string)((arc_cloud_json($response)['nextExpectedRanges'][0] ?? '') ?: '');
        return ['done' => false, 'next' => preg_match('/^(\d+)-/', $expected, $m) ? (int)$m[1] : $offset];
    }
    arc_cloud_expect($response, [], 'OneDrive recusou o bloco');
    return ['done' => false, 'next' => $offset];
}


/* ---------------- Dropbox ---------------- */
function arc_cloud_dropbox_rpc(string $token, string $endpoint, ?array $body): array
{
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', $body === null ? 'Content-Type:' : 'Content-Type: application/json'];   // sem argumentos: o Dropbox exige corpo e Content-Type vazios
    return arc_cloud_expect(arc_cloud_http('POST', 'https://api.dropboxapi.com/2/' . $endpoint, $headers, $body === null ? '' : json_encode($body), 30), [200], 'Dropbox recusou a solicitação');
}

function arc_cloud_dropbox_content(string $token, string $endpoint, array $arg, string $data): array
{
    return arc_cloud_http('POST', 'https://content.dropboxapi.com/2/files/' . $endpoint, ['Authorization: Bearer ' . $token, 'Content-Type: application/octet-stream', 'Dropbox-API-Arg: ' . json_encode($arg)], $data, 180);
}

function arc_cloud_dropbox_identity(PDO $pdo, array $connection, string $token): array
{
    $account = arc_cloud_json(arc_cloud_dropbox_rpc($token, 'users/get_current_account', null));
    $space = arc_cloud_json(arc_cloud_dropbox_rpc($token, 'users/get_space_usage', null));
    return [(string)($account['email'] ?? 'Conta Dropbox'), isset($space['used']) ? (int)$space['used'] : null, isset($space['allocation']['allocated']) ? (int)$space['allocation']['allocated'] : null];
}

function arc_cloud_dropbox_begin(PDO $pdo, array $connection, string $token, array $segments, int $size, int $mtime): array
{
    arc_cloud_dropbox_folders($pdo,$connection,$token,array_slice($segments,0,-1));
    $path = '/' . implode('/', $segments);
    $commit = ['path' => $path, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true, 'client_modified' => gmdate('Y-m-d\TH:i:s\Z', $mtime > 0 ? $mtime : time())];
    if ($size === 0) {
        arc_cloud_expect(arc_cloud_dropbox_content($token, 'upload', $commit, ''), [200], 'Dropbox recusou o arquivo vazio');
        return ['done' => true];
    }
    return ['commit' => $commit, 'sid' => null];
}

function arc_cloud_dropbox_chunk(PDO $pdo, array $connection, string $token, array &$state, string $data, int $offset, int $total, bool $final): array
{
    if ($offset === 0 && $final) { arc_cloud_expect(arc_cloud_dropbox_content($token, 'upload', $state['commit'], $data), [200], 'Dropbox recusou o arquivo'); return ['done' => true]; }
    if (empty($state['sid'])) {
        $reply = arc_cloud_json(arc_cloud_expect(arc_cloud_dropbox_content($token, 'upload_session/start', ['close' => false], $data), [200], 'Dropbox não abriu a sessão de envio'));
        $state['sid'] = (string)($reply['session_id'] ?? '');
        if ($state['sid'] === '') throw new RuntimeException('Resposta inválida do Dropbox.', 502);
        return ['done' => false, 'next' => $offset + strlen($data)];
    }
    $cursor = ['session_id' => $state['sid'], 'offset' => $offset];
    if ($final) { $response=arc_cloud_dropbox_content($token, 'upload_session/finish', ['cursor' => $cursor, 'commit' => $state['commit']], $data);if($response['status']===409)throw new RuntimeException('O envio Dropbox precisa ser reiniciado.',410);arc_cloud_expect($response,[200],'Dropbox recusou a conclusão');return ['done'=>true]; }
    $response = arc_cloud_dropbox_content($token, 'upload_session/append_v2', ['cursor' => $cursor, 'close' => false], $data);
    if ($response['status'] === 409) {
        $error=arc_cloud_json($response)['error']??[];
        $correct=$error['correct_offset']??$error['incorrect_offset']['correct_offset']??$error['lookup_failed']['incorrect_offset']['correct_offset']??null;
        if (is_int($correct)) return ['done' => false, 'next' => $correct];
        throw new RuntimeException('O envio Dropbox precisa ser reiniciado.',410);
    }
    arc_cloud_expect($response, [200], 'Dropbox recusou o bloco');
    return ['done' => false, 'next' => $offset + strlen($data)];
}


/* ---------------- Amazon S3 e compatíveis (Assinatura v4) ---------------- */
function arc_cloud_s3_config(array $connection): array
{
    $s = arc_cloud_secret_read($connection);
    return ['access_key' => (string)($s['access_key'] ?? ''), 'secret_key' => (string)($s['secret_key'] ?? ''), 'bucket' => (string)($s['bucket'] ?? ''),
        'region' => (string)($s['region'] ?? 'us-east-1'), 'endpoint' => (string)($s['endpoint'] ?? ''), 'prefix' => trim((string)($s['prefix'] ?? ''), '/')];
}

/** Valida e normaliza o que o gestor digitou ao conectar um S3. */
function arc_cloud_s3_clean_input(array $in, array $defaults): array
{
    $bucket = trim((string)($in['bucket'] ?? ''));
    $region = trim((string)($in['region'] ?? '')) ?: ($defaults['region'] ?: 'us-east-1');
    $endpoint = trim((string)($in['endpoint'] ?? '')) ?: (string)$defaults['endpoint'];
    $prefix = trim(trim((string)($in['prefix'] ?? '')), '/');
    $access = trim((string)($in['access_key'] ?? ''));
    $secret = trim((string)($in['secret_key'] ?? ''));
    if ($endpoint !== (string)$defaults['endpoint']) throw new InvalidArgumentException('Use o endpoint autorizado pelo administrador.');
    if ($endpoint !== '') arc_cloud_validate_url($endpoint);
    if (str_contains($prefix,'..') || str_contains($prefix,'\\')) throw new InvalidArgumentException('Prefixo inválido.');
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket)) throw new InvalidArgumentException('Nome de bucket inválido.');
    if (!preg_match('/^[a-z0-9-]{2,30}$/D', $region)) throw new InvalidArgumentException('Região inválida.');
    if ($endpoint !== '' && !preg_match('~^https://[A-Za-z0-9.-]+(?::\d{2,5})?$~D', rtrim($endpoint, '/'))) throw new InvalidArgumentException('Endpoint inválido: use https://servidor.');
    if (!preg_match('/^[A-Za-z0-9+\/=_-]{8,128}$/D', $access) || !preg_match('/^[A-Za-z0-9+\/=_-]{16,128}$/D', $secret)) throw new InvalidArgumentException('Chave de acesso ou chave secreta inválida.');
    if (mb_strlen($prefix) > 200 || preg_match('/[\x00-\x1f]/', $prefix)) throw new InvalidArgumentException('Prefixo inválido.');
    return ['access_key' => $access, 'secret_key' => $secret, 'bucket' => $bucket, 'region' => $region, 'endpoint' => rtrim($endpoint, '/'), 'prefix' => $prefix];
}

/** Monta URL e cabeçalhos assinados (Assinatura v4). Separado do envio para permitir verificação com data fixa. */
function arc_cloud_s3_prepare(array $cfg, string $method, string $key, array $query = [], string $body = '', array $headers = [], ?string $now = null): array
{
    $encodeKey = static fn(string $k): string => implode('/', array_map('rawurlencode', explode('/', $k)));
    $objectKey = $key === '' ? '' : ($cfg['prefix'] !== '' ? $cfg['prefix'] . '/' : '') . $key;
    if ($cfg['endpoint'] === '') {
        $virtual = !str_contains($cfg['bucket'], '.');
        $host = $virtual ? $cfg['bucket'] . '.s3.' . $cfg['region'] . '.amazonaws.com' : 's3.' . $cfg['region'] . '.amazonaws.com';
        $path = ($virtual ? '' : '/' . $cfg['bucket']) . '/' . ($key === '' ? '' : $encodeKey($objectKey));
    } else {
        $host = (string)parse_url($cfg['endpoint'], PHP_URL_HOST) . (parse_url($cfg['endpoint'], PHP_URL_PORT) ? ':' . parse_url($cfg['endpoint'], PHP_URL_PORT) : '');
        $path = '/' . $cfg['bucket'] . '/' . ($key === '' ? '' : $encodeKey($objectKey));
    }
    ksort($query);
    $canonicalQuery = implode('&', array_map(static fn($k, $v) => rawurlencode((string)$k) . '=' . rawurlencode((string)$v), array_keys($query), $query));
    $now = $now ?? gmdate('Ymd\THis\Z');
    $day = substr($now, 0, 8);
    $payloadHash = hash('sha256', $body);
    $signed = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now];
    foreach ($headers as $name => $value) $signed[strtolower($name)] = trim((string)$value);
    ksort($signed);
    $canonicalHeaders = '';
    foreach ($signed as $name => $value) $canonicalHeaders .= $name . ':' . $value . "\n";
    $signedNames = implode(';', array_keys($signed));
    $canonical = $method . "\n" . $path . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signedNames . "\n" . $payloadHash;
    $scope = $day . '/' . $cfg['region'] . '/s3/aws4_request';
    $toSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonical);
    $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $cfg['region'], hash_hmac('sha256', $day, 'AWS4' . $cfg['secret_key'], true), true), true), true);
    $authorization = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $scope . ', SignedHeaders=' . $signedNames . ', Signature=' . hash_hmac('sha256', $toSign, $signingKey);
    $httpHeaders = ['Authorization: ' . $authorization];
    foreach ($signed as $name => $value) if ($name !== 'host') $httpHeaders[] = $name . ': ' . $value;
    $scheme = $cfg['endpoint'] === '' ? 'https://' . $host : $cfg['endpoint'];
    return [$scheme . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : ''), $httpHeaders];
}

function arc_cloud_s3_request(array $cfg, string $method, string $key, array $query = [], string $body = '', array $headers = []): array
{
    [$url, $httpHeaders] = arc_cloud_s3_prepare($cfg, $method, $key, $query, $body, $headers);
    return arc_cloud_http($method, $url, $httpHeaders, $body === '' && !in_array($method, ['PUT', 'POST'], true) ? null : $body, 180);
}

function arc_cloud_s3_identity(PDO $pdo, array $connection, string $token): array
{
    $cfg = arc_cloud_s3_config($connection);
    arc_cloud_expect(arc_cloud_s3_request($cfg, 'GET', '', ['list-type' => '2', 'max-keys' => '1']), [200], 'Não foi possível acessar o bucket S3');
    return [$cfg['bucket'] . ($cfg['endpoint'] !== '' ? ' @ ' . parse_url($cfg['endpoint'], PHP_URL_HOST) : ' @ ' . $cfg['region']), null, null];
}

function arc_cloud_s3_begin(PDO $pdo, array $connection, string $token, array $segments, int $size, int $mtime): array
{
    $cfg = arc_cloud_s3_config($connection);
    $key = implode('/', $segments);
    $meta = ['x-amz-meta-mtime' => (string)max(0, $mtime)];
    if ($size === 0) { arc_cloud_expect(arc_cloud_s3_request($cfg, 'PUT', $key, [], '', $meta), [200], 'S3 recusou o arquivo vazio'); return ['done' => true]; }
    return ['key' => $key, 'upload_id' => null, 'parts' => [], 'mtime' => max(0, $mtime)];
}

function arc_cloud_s3_chunk(PDO $pdo, array $connection, string $token, array &$state, string $data, int $offset, int $total, bool $final): array
{
    $cfg = arc_cloud_s3_config($connection);
    $meta = ['x-amz-meta-mtime' => (string)($state['mtime'] ?? 0)];
    if ($offset === 0 && $final) { arc_cloud_expect(arc_cloud_s3_request($cfg, 'PUT', (string)$state['key'], [], $data, $meta+['x-amz-checksum-sha256'=>base64_encode(hash('sha256',$data,true))]), [200], 'S3 recusou o arquivo'); return ['done' => true]; }
    if (empty($state['upload_id'])) {
        $reply = arc_cloud_expect(arc_cloud_s3_request($cfg, 'POST', (string)$state['key'], ['uploads' => ''], '', $meta), [200], 'S3 não abriu o envio em partes');
        if (!preg_match('~<UploadId>([^<]+)</UploadId>~', $reply['body'], $m)) throw new RuntimeException('Resposta inválida do S3.', 502);
        $state['upload_id'] = html_entity_decode($m[1]);
    }
    $number = intdiv($offset, arc_cloud_providers()['s3']['chunk']) + 1;
    $part = arc_cloud_expect(arc_cloud_s3_request($cfg, 'PUT', (string)$state['key'], ['partNumber' => (string)$number, 'uploadId' => (string)$state['upload_id']], $data, ['x-amz-checksum-sha256'=>base64_encode(hash('sha256',$data,true))]), [200], 'S3 recusou a parte ' . $number);
    $etag = trim((string)($part['headers']['etag'] ?? ''));
    if ($etag === '') throw new RuntimeException('S3 não devolveu a confirmação da parte.', 502);
    $state['parts'][(string)$number] = $etag;
    if (!$final) return ['done' => false, 'next' => $offset + strlen($data)];
    ksort($state['parts'], SORT_NUMERIC);
    $xml = '<CompleteMultipartUpload>';
    foreach ($state['parts'] as $n => $tag) $xml .= '<Part><PartNumber>' . (int)$n . '</PartNumber><ETag>' . htmlspecialchars((string)$tag, ENT_XML1) . '</ETag></Part>';
    $xml .= '</CompleteMultipartUpload>';
    $done = arc_cloud_expect(arc_cloud_s3_request($cfg, 'POST', (string)$state['key'], ['uploadId' => (string)$state['upload_id']], $xml), [200], 'S3 recusou a conclusão');
    if (preg_match('~<Error(?:\s|>)~',$done['body'])) throw new RuntimeException('S3 não concluiu o envio em partes.', 502);
    return ['done' => true];
}


/** Impede SSRF inclusive DNS rebinding: somente HTTPS público, sem credenciais na URL. */
function arc_cloud_validate_url(string $url): array
{
    $parts=parse_url($url);
    if (!$parts || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (($parts['port']??443)!==443)) throw new InvalidArgumentException('A nuvem exige um endpoint HTTPS público na porta 443.');
    $host=strtolower((string)($parts['host']??''));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host) || !str_contains($host,'.')) throw new InvalidArgumentException('Host de nuvem inválido.');
    $addresses=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]);
    if (!$addresses) throw new RuntimeException('Não foi possível resolver o provedor de nuvem.',503);
    foreach ($addresses as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('Endereços internos não são aceitos como provedor de nuvem.');
    return [$host,$addresses];
}

/** Controle de acesso único: mesmo papel e direito Clean da página original. */
function arc_cloud_access(PDO $pdo,int $network,int $user): array
{
    $q=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$user]);
    $access=arc_network_team_access($pdo,$network,$user);
    if ($q->fetchColumn()!=='active' || !$access || !in_array($access['role'],['owner','administrator'],true)) throw new RuntimeException('Acesso de gestão necessário.',403);
    $rights=arc_network_profile_rights($pdo,$network,$user);
    if ($access['role']!=='owner' && $rights!==null && !in_array('clean',$rights,true)) throw new RuntimeException('Perfil sem permissão Clean.',403);
    $q=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1');$q->execute([$network,$access['account_id']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Rede indisponível.',403);
    arc_billing_assert_active($pdo,(int)$access['account_id']);
    return $access;
}

function arc_cloud_agent_row(PDO $pdo,int $id,int $network,int $account): array
{
    $q=$pdo->prepare('SELECT id,network_id,account_id,machine_name FROM '.arc_table('network_agents')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");
    $q->execute([$id,$network,$account]);$a=$q->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new RuntimeException('Computador não autorizado nesta rede.',403);
    return $a;
}

function arc_cloud_onedrive_folders(string $token,array $folders): void
{
    $parent='https://graph.microsoft.com/v1.0/me/drive/root';
    foreach ($folders as $name) {
        $path=$parent.':/'.rawurlencode($name);
        // Use item IDs after the first level; root:/name addressing only for root.
        $lookup=str_contains($parent,'/items/')?$parent.':/'.rawurlencode($name):$path;
        $response=arc_cloud_http('GET',$lookup,['Authorization: Bearer '.$token,'Accept: application/json'],null,30);
        if ($response['status']===404) {
            $response=arc_cloud_expect(arc_cloud_http('POST',$parent.'/children',['Authorization: Bearer '.$token,'Content-Type: application/json'],json_encode(['name'=>$name,'folder'=>(object)[],'@microsoft.graph.conflictBehavior'=>'fail']),30),[200,201,409],'Não foi possível criar a pasta OneDrive');
            if ($response['status']===409) $response=arc_cloud_http('GET',$lookup,['Authorization: Bearer '.$token],null,30);
        }
        $item=arc_cloud_json(arc_cloud_expect($response,[200,201],'Pasta OneDrive indisponível'));
        if (empty($item['id']) || !isset($item['folder'])) throw new RuntimeException('O destino OneDrive não é uma pasta.',409);
        $parent='https://graph.microsoft.com/v1.0/me/drive/items/'.rawurlencode((string)$item['id']);
    }
}

/** Lista arquivos existentes no destino da conexão, sem conceder token ao navegador. Apenas o prefixo do backup gerenciado é exposto. */
function arc_cloud_browse(PDO $pdo, array $connection, string $relative = '', string $root = ARC_CLOUD_DEFAULT_ROOT, ?int $networkId = null, ?int $agentId = null, ?string $page = null): array
{
    if (strlen($relative) > 560 || str_contains($relative, "\\") || str_contains($relative, chr(0))) throw new InvalidArgumentException('Pasta remota inválida.');
    $parts = $relative === '' ? [] : explode('/', $relative);
    foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 180 || preg_match('/[\\x00-\\x1f]/', $part)) throw new InvalidArgumentException('Pasta remota inválida.');
    $provider = (string)$connection['provider'];
    $token = $provider === 's3' ? '' : arc_cloud_access_token($pdo, $connection);
    $root = arc_cloud_clean_segment($root,80);
    $scope=($connection['network_id']??null) !== null && (int)$connection['network_id']===0 && (int)$connection['account_id']===0;
    if($scope){if(!$networkId || !$agentId)throw new RuntimeException('Escopo do backup inválido.',403);}
    $rootSegments=arc_cloud_scope_segments($connection,$root,(int)$networkId,(int)$agentId);
    $result = [];
    $nextPage = '';
    if ($provider === 'gdrive') {
        $id = 'root';
        foreach (array_merge($rootSegments, $parts) as $part) {
            $id = arc_cloud_gdrive_find($token, $id, $part, true);
            if (!$id) return ['path'=>$relative, 'entries'=>[], 'missing'=>true];
        }
        $q = "'" . $id . "' in parents and trashed=false";
        $query = ['q'=>$q,'fields'=>'nextPageToken,files(id,name,mimeType,size,modifiedTime,webViewLink)','pageSize'=>200,'orderBy'=>'folder,name','supportsAllDrives'=>'false'];
        if ($page !== null && $page !== '' && preg_match('/^[A-Za-z0-9._~\-\/+=]{1,2000}$/D', $page)) $query['pageToken'] = $page;
        $data = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET','https://www.googleapis.com/drive/v3/files?' . http_build_query($query),arc_cloud_gdrive_headers($token)),[200],'Não foi possível listar o Google Drive'));
        foreach (($data['files']??[]) as $item) $result[]=['name'=>(string)$item['name'],'directory'=>($item['mimeType']??'')==='application/vnd.google-apps.folder','size'=>(int)($item['size']??0),'modified'=>(string)($item['modifiedTime']??''),'link'=>(string)($item['webViewLink']??'')];
        $nextPage = (string)($data['nextPageToken'] ?? '');
    } elseif ($provider === 'onedrive') {
        $segments=array_merge($rootSegments,$parts);
        $path='https://graph.microsoft.com/v1.0/me/drive/root:/'.implode('/',array_map('rawurlencode',$segments)).':/children?$top=200&$select=name,size,folder,lastModifiedDateTime';
        $response=arc_cloud_http('GET',$path,['Authorization: Bearer '.$token,'Accept: application/json']);
        if($response['status']===404)return ['path'=>$relative,'entries'=>[],'missing'=>true];
        $data=arc_cloud_json(arc_cloud_expect($response,[200],'Não foi possível listar o OneDrive'));
        foreach(($data['value']??[]) as $item)$result[]=['name'=>(string)$item['name'],'directory'=>isset($item['folder']),'size'=>(int)($item['size']??0),'modified'=>(string)($item['lastModifiedDateTime']??'')];
    } elseif ($provider === 'dropbox') {
        $path='/'.implode('/',$rootSegments).($relative!==''?'/'.$relative:'');
        try {$data=arc_cloud_json(arc_cloud_dropbox_rpc($token,'files/list_folder',['path'=>$path,'recursive'=>false,'limit'=>500]));}
        catch (RuntimeException $e) {if($e->getCode()===409)return ['path'=>$relative,'entries'=>[],'missing'=>true];throw $e;}
        foreach(($data['entries']??[]) as $item)$result[]=['name'=>(string)$item['name'],'directory'=>($item['.tag']??'')==='folder','size'=>(int)($item['size']??0),'modified'=>(string)($item['server_modified']??'')];
    } elseif ($provider === 's3') {
        $cfg=arc_cloud_s3_config($connection);
        $prefix=trim((string)($cfg['prefix']??''),'/');
        $prefix=($prefix!==''?$prefix.'/':'').implode('/',$rootSegments).'/'.($relative!==''?$relative.'/':'');
        $response=arc_cloud_s3_request($cfg,'GET','',['list-type'=>'2','prefix'=>$prefix,'delimiter'=>'/','max-keys'=>'500']);
        $xml=@simplexml_load_string((string)$response['body']);
        if(!$xml)throw new RuntimeException('Resposta S3 inválida.');
        foreach($xml->CommonPrefixes??[] as $node){$name=rtrim(substr((string)$node->Prefix,strlen($prefix)),'/');if($name!=='')$result[]=['name'=>$name,'directory'=>true,'size'=>0,'modified'=>''];}
        foreach($xml->Contents??[] as $node){$name=substr((string)$node->Key,strlen($prefix));if($name!=='')$result[]=['name'=>$name,'directory'=>false,'size'=>(int)$node->Size,'modified'=>(string)$node->LastModified];}
    }
    $result=array_values(array_filter($result,static fn($entry)=>$entry['name']!==''&&$entry['name']!=='.'&&$entry['name']!=='..'&&!str_contains($entry['name'],'/')));
    usort($result,static fn($a,$b)=>(int)$b['directory']<=>(int)$a['directory'] ?: strcasecmp($a['name'],$b['name']));
    return ['path'=>$relative,'entries'=>$result,'limited'=>count($result)>=($provider==='dropbox'||$provider==='s3'?500:200) && $nextPage === '','next'=>$nextPage];
}

/** Revalida autorização e configuração antes de cada bloco; não persiste conteúdo. */
function arc_cloud_agent_job(PDO $pdo,array $agent): array
{
    arc_billing_assert_active($pdo,(int)$agent['account_id']);
    $job=arc_cloud_job_get($pdo,(int)$agent['id'],(int)$agent['network_id'],(int)$agent['account_id']);
    $job['agent_id']=(int)$agent['id'];$job['network_id']=(int)$agent['network_id'];
    $connection=$job['connection_id']?arc_cloud_connection_get($pdo,$job['connection_id'],(int)$agent['network_id'],(int)$agent['account_id']):null;
    $allowed=true;if($connection){try{arc_cloud_assert_destination_allowed($pdo,$connection);}catch(InvalidArgumentException $e){$allowed=false;}}
    $job['available']=$job['enabled'] && $connection && $allowed && arc_cloud_provider_config($pdo,(string)$connection['provider'])['ready'];
    $policy=arc_cloud_policy($pdo);
    $job['vdrive_available']=!empty($job['settings']['vdrive']) && $policy['vdrive'] && $connection && $allowed && arc_cloud_provider_config($pdo,(string)$connection['provider'])['ready'];
    $job['revision']=hash('sha256',json_encode([$job['connection_id'],array_diff_key($job['settings'],['vdrive'=>1,'vdrive_letter'=>1])]));
    return [$job,$connection];
}

/** Dropbox cria cada pasta nativamente; cache é escopado à conexão, nunca ao usuário global. */
function arc_cloud_dropbox_folders(PDO $pdo,array $connection,string $token,array $segments): void
{
    $path='';
    foreach($segments as $name){
        $path.='/'.$name;$hash=sha1($path);
        $q=$pdo->prepare('SELECT remote_id FROM '.arc_table('cloud_backup_folders').' WHERE connection_id=? AND path_hash=?');$q->execute([(int)$connection['id'],$hash]);
        if($q->fetchColumn()!==false)continue;
        $response=arc_cloud_http('POST','https://api.dropboxapi.com/2/files/create_folder_v2',['Authorization: Bearer '.$token,'Content-Type: application/json'],json_encode(['path'=>$path,'autorename'=>false]),30);
        if($response['status']===409){
            $metadata=arc_cloud_json(arc_cloud_dropbox_rpc($token,'files/get_metadata',['path'=>$path]));
            if(($metadata['.tag']??'')!=='folder')throw new RuntimeException('O destino Dropbox não é uma pasta.',409);
        }else arc_cloud_expect($response,[200],'Não foi possível criar a pasta Dropbox');
        $pdo->prepare('INSERT IGNORE INTO '.arc_table('cloud_backup_folders').' (connection_id,path_hash,remote_id) VALUES (?,?,?)')->execute([(int)$connection['id'],$hash,'folder']);
    }
}


/** Nomes amigáveis para a navegação (estilo Google Drive): Rede-12 vira o nome da rede, Computador-5 o nome da máquina e o sufixo técnico das pastas some. */
function arc_cloud_browse_decorate(PDO $pdo, array $browser, int $accountId): array
{
    $networks = [];$agents = [];
    foreach ($browser['entries'] as &$entry) {
        $entry['display'] = $entry['name'];
        if (!$entry['directory']) { $entry['type'] = arc_cloud_file_type($entry['name']); continue; }
        $entry['type'] = 'folder';
        if (preg_match('/^Rede-(\d+)$/D', $entry['name'], $m)) {
            $id = (int)$m[1];
            if (!isset($networks[$id])) { $q = $pdo->prepare('SELECT name FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=?'); $q->execute([$id, $accountId]); $networks[$id] = (string)($q->fetchColumn() ?: ''); }
            if ($networks[$id] !== '') { $entry['display'] = $networks[$id]; $entry['type'] = 'network'; }
        } elseif (preg_match('/^Computador-(\d+)$/D', $entry['name'], $m)) {
            $id = (int)$m[1];
            if (!isset($agents[$id])) { $q = $pdo->prepare('SELECT machine_name FROM ' . arc_table('network_agents') . ' WHERE id=? AND account_id=?'); $q->execute([$id, $accountId]); $agents[$id] = (string)($q->fetchColumn() ?: ''); }
            if ($agents[$id] !== '') { $entry['display'] = $agents[$id]; $entry['type'] = 'computer'; }
        } elseif (preg_match('/^(.+)-[0-9a-f]{12}$/D', $entry['name'], $m)) {
            $entry['display'] = $m[1];
        }
    }
    unset($entry);
    return $browser;
}

function arc_cloud_file_type(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','bmp','webp','heic','tif','tiff','svg'], true) => 'image',
        in_array($ext, ['mp4','mkv','avi','mov','wmv','webm'], true) => 'video',
        in_array($ext, ['mp3','wav','flac','aac','ogg','m4a'], true) => 'audio',
        in_array($ext, ['pdf'], true) => 'pdf',
        in_array($ext, ['doc','docx','odt','rtf','txt'], true) => 'doc',
        in_array($ext, ['xls','xlsx','csv','ods'], true) => 'sheet',
        in_array($ext, ['ppt','pptx','odp'], true) => 'slide',
        in_array($ext, ['zip','rar','7z','tar','gz','bak'], true) => 'archive',
        in_array($ext, ['sql','db','mdb','accdb','sqlite','pst','ost'], true) => 'data',
        default => 'file',
    };
}


/* ============================================================
   Navegação, download e disco virtual (v3.10)
   ============================================================ */
/** Segmentos-base visíveis: Drive da plataforma = só o computador; conta própria = só a rede (nunca outras redes da mesma conta). */
function arc_cloud_scope_segments(array $connection, string $root, int $networkId, int $agentId): array
{
    $root = arc_cloud_clean_segment($root, 80);
    $managed = (int)$connection['network_id'] === 0 && (int)$connection['account_id'] === 0;
    if ($managed) return [$root, 'Rede-' . $networkId, 'Computador-' . $agentId];
    return $networkId > 0 ? [$root, 'Rede-' . $networkId] : [$root];
}

/** Valida um caminho relativo (a/b/c) vindo da tela ou do agente e devolve as partes. */
function arc_cloud_relative_parts(string $relative): array
{
    if ($relative === '') return [];
    if (strlen($relative) > 700 || str_contains($relative, '\\') || str_contains($relative, chr(0))) throw new InvalidArgumentException('Caminho remoto inválido.');
    $parts = explode('/', $relative);
    foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 190 || preg_match('/[\x00-\x1f]/', $part)) throw new InvalidArgumentException('Caminho remoto inválido.');
    return $parts;
}

/** Tipos que o navegador pode exibir sem baixar. Todo o resto sai como anexo binário. */
function arc_cloud_mime(string $name): array
{
    static $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/plain; charset=utf-8', 'log' => 'text/plain; charset=utf-8',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return isset($map[$ext]) ? [$map[$ext], true] : ['application/octet-stream', false];
}

/** Resolve o arquivo no provedor e devolve a requisição de leitura: [method,url,headers,body,size]. Nada é gravado em disco. */
function arc_cloud_read_plan(PDO $pdo, array $connection, array $segments): array
{
    $provider = (string)$connection['provider'];
    $token = $provider === 's3' ? '' : arc_cloud_access_token($pdo, $connection);
    $name = (string)end($segments);
    if ($provider === 'gdrive') {
        $parent = 'root';
        foreach (array_slice($segments, 0, -1) as $part) { $parent = arc_cloud_gdrive_find($token, $parent, $part, true); if (!$parent) throw new RuntimeException('Arquivo não encontrado no destino.', 404); }
        $id = arc_cloud_gdrive_find($token, $parent, $name, false);
        if (!$id) throw new RuntimeException('Arquivo não encontrado no destino.', 404);
        $meta = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=size,mimeType', arc_cloud_gdrive_headers($token), null, 30), [200], 'Não foi possível ler o arquivo no Google Drive'));
        if (str_starts_with((string)($meta['mimeType'] ?? ''), 'application/vnd.google-apps.')) throw new RuntimeException('Arquivos nativos do Google (Docs/Planilhas) não são baixáveis aqui.', 415);
        return ['GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media', ['Authorization: Bearer ' . $token], null, (int)($meta['size'] ?? 0)];
    }
    if ($provider === 'onedrive') {
        $meta = arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET', arc_cloud_onedrive_path($segments) . '?$select=size,file,@microsoft.graph.downloadUrl', ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, 30), [200], 'Não foi possível ler o arquivo no OneDrive'));
        $url = (string)($meta['@microsoft.graph.downloadUrl'] ?? '');
        if (!isset($meta['file']) || !str_starts_with($url, 'https://')) throw new RuntimeException('Arquivo indisponível no OneDrive.', 404);
        return ['GET', $url, [], null, (int)($meta['size'] ?? 0)];
    }
    if ($provider === 'dropbox') {
        $path = '/' . implode('/', $segments);
        $meta = arc_cloud_json(arc_cloud_dropbox_rpc($token, 'files/get_metadata', ['path' => $path]));
        if (($meta['.tag'] ?? '') !== 'file') throw new RuntimeException('Arquivo indisponível no Dropbox.', 404);
        return ['POST', 'https://content.dropboxapi.com/2/files/download', ['Authorization: Bearer ' . $token, 'Dropbox-API-Arg: ' . json_encode(['path' => $path]), 'Content-Type:'], '', (int)($meta['size'] ?? 0)];
    }
    if ($provider === 's3') {
        $cfg = arc_cloud_s3_config($connection);
        $key = implode('/', $segments);
        $head = arc_cloud_expect(arc_cloud_s3_request($cfg, 'HEAD', $key), [200], 'Arquivo indisponível no S3');
        $size = (int)($head['headers']['content-length'] ?? 0);
        return ['S3', $key, $cfg, null, $size];
    }
    throw new RuntimeException('Provedor sem suporte a download.', 400);
}

/** Envia o arquivo ao cliente em fluxo, com suporte a Range (retomar download e reprodução de vídeo/áudio). */
function arc_cloud_stream_file(PDO $pdo, array $connection, array $segments, bool $inline, ?string $rangeHeader = null): void
{
    $plan = arc_cloud_read_plan($pdo, $connection, $segments);
    [$method, $url, $headers, $body, $size] = $plan;
    $name = (string)end($segments);
    [$mime, $viewable] = arc_cloud_mime($name);
    $range = null;
    if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/D', trim($rangeHeader))) $range = trim($rangeHeader);
    if ($method === 'S3') {
        $cfg = $headers;
        $extra = $range !== null ? ['Range' => $range] : [];
        [$url, $httpHeaders] = arc_cloud_s3_prepare($cfg, 'GET', $url, [], '', $extra);
        $headers = $httpHeaders; $method = 'GET';
    } elseif ($range !== null) $headers[] = 'Range: ' . $range;
    [$host, $addresses] = arc_cloud_validate_url($url);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(0); ignore_user_abort(false);
    while (ob_get_level() > 0) @ob_end_clean();
    $started = false; $status = 0; $error = '';
    $disposition = ($inline && $viewable ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name);
    $send = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 0, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RESOLVE => [$host . ':443:' . implode(',', $addresses)],
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$status, &$send, &$started, $mime, $disposition, $inline, $viewable): int {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; $send = []; }
            elseif (($pos = strpos($line, ':')) > 0) { $send[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1)); }
            elseif (trim($line) === '' && in_array($status, [200, 206], true) && !$started) {
                $started = true;
                http_response_code($status);
                header('Content-Type: ' . $mime); header('Content-Disposition: ' . $disposition);
                header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Accept-Ranges: bytes');
                if ($inline && $viewable && $mime !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; sandbox");
                if (isset($send['content-length'])) header('Content-Length: ' . (int)$send['content-length']);
                if (isset($send['content-range']) && $status === 206) header('Content-Range: ' . preg_replace('/[^0-9a-z \/*-]/i', '', $send['content-range']));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($h, string $chunk) use (&$started, &$error): int {
            if (!$started) { if (strlen($error) < 300) $error .= $chunk; return strlen($chunk); }
            echo $chunk; flush();
            return connection_aborted() ? 0 : strlen($chunk);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_exec($ch);
    $failure = curl_errno($ch);
    curl_close($ch);
    if (!$started) {
        error_log('Arcoll nuvem: leitura recusada HTTP ' . $status . ' curl ' . $failure);
        throw new RuntimeException($status === 416 ? 'Trecho solicitado fora do arquivo.' : 'O provedor recusou a leitura do arquivo (HTTP ' . $status . ').', $status === 404 ? 404 : ($status === 416 ? 416 : 502));
    }
}
