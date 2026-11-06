<?php
/* Caminho: includes/command-diagnostics.php */
declare(strict_types=1);

/** Never export credentials or personal paths in an error report. */
function arc_command_error_scrub(string $raw, int $limit = 5500): string
{
    $raw = str_replace("\0", '', $raw);
    $raw = preg_replace('/[\x01-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $raw) ?? '';
    $patterns = [
        '#(?i)(bearer\s+)[a-z0-9._~+/-]+=*#' => '$1[REDACTED]',
        '~(?i)(\b(?:password|passwd|senha|secret|token|api[_-]?key|download_key|authorization|enrollment_secret|previous_owner_password)\b[\s"\']*[:=][\s"\']*)[^\s"\',;}{&]+~' => '$1[REDACTED]',
        '~(?i)([?&](?:key|token|secret|password|senha|download_key|authorization)=)[^&\s#]+~' => '$1[REDACTED]',
        '#(?i)([A-Z]:[\\\\]Users[\\\\])[^\\\\\r\n]+#' => '$1[usuario]',
        '~(?i)(/home/|/Users/)[^/\s\r\n]+~' => '$1[usuario]',
    ];
    foreach ($patterns as $pattern => $replacement) $raw = preg_replace($pattern, $replacement, $raw) ?? $raw;
    return mb_substr(trim($raw), 0, $limit, 'UTF-8');
}

function arc_command_error_code(string $status, string $provided, string $summary): string
{
    if (preg_match('/^[A-Z][A-Z0-9_]{1,47}$/D', $provided)) return $provided;
    if ($status === 'expired') return 'AGENT_TIMEOUT';
    if (preg_match('/\bHTTP\s*(\d{3})\b/i', $summary, $m)) return 'HTTP_'.$m[1];
    if (preg_match('/(permiss|acesso negado|n[aã]o autorizado)/iu', $summary)) return 'ACCESS_DENIED';
    if (preg_match('/(conex|rede|timeout|tempo limite|tempo esgotado)/iu', $summary)) return 'COMMUNICATION_FAILED';
    return 'REMOTE_COMMAND_FAILED';
}

/** Executed on failures only. No SQL changes are made during healthy agent polling. */
function arc_command_error_store(PDO $pdo, array $command, string $code, string $technical): void
{
    static $ready = false;
    try {
        if (!$ready) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('agent_command_errors').' (
              command_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
              account_id BIGINT UNSIGNED NOT NULL,
              network_id BIGINT UNSIGNED NOT NULL,
              agent_id BIGINT UNSIGNED NOT NULL,
              error_code VARCHAR(48) NOT NULL,
              diagnostic_text TEXT NOT NULL,
              recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY idx_command_errors_network (network_id, account_id, recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $ready = true;
        }
        $save = $pdo->prepare('INSERT INTO '.arc_table('agent_command_errors').' (command_id,account_id,network_id,agent_id,error_code,diagnostic_text) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE error_code=VALUES(error_code),diagnostic_text=VALUES(diagnostic_text),recorded_at=CURRENT_TIMESTAMP');
        $save->execute([(int)$command['id'],(int)$command['account_id'],(int)$command['network_id'],(int)$command['agent_id'],$code,arc_command_error_scrub($technical)]);
        // Retain 30 days; avoid a cleanup query on every failed command.
        if (random_int(1,32)===1) $pdo->exec('DELETE FROM '.arc_table('agent_command_errors').' WHERE recorded_at<DATE_SUB(NOW(),INTERVAL 30 DAY)');
    } catch (PDOException $e) {
        // The command status itself must still be acknowledged on hosts without CREATE TABLE privilege.
        error_log('remote command diagnostics unavailable: '.$e->getCode());
    }
}

/** Authorized caller must validate network access before invoking this helper. */
function arc_command_error_read(PDO $pdo, int $commandId, int $networkId, int $accountId): ?array
{
    try {
        $q=$pdo->prepare('SELECT error_code,diagnostic_text,recorded_at FROM '.arc_table('agent_command_errors').' WHERE command_id=? AND network_id=? AND account_id=? LIMIT 1');
        $q->execute([$commandId,$networkId,$accountId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') error_log('remote diagnostic read unavailable: '.$e->getCode());
        return null;
    }
}
