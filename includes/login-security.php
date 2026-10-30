<?php
declare(strict_types=1);

/** Shared 15-minute authentication budget for browser, desktop and admin login. */
function arc_login_ip_hash(): string
{
    // Do not trust client-supplied forwarding headers for authentication limits.
    return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), (string)(arc_config()['app_secret'] ?? 'arcoll'));
}

/** Reserve a slot before checking a password. Null means the IP is throttled. */
function arc_login_attempt_begin(PDO $pdo, string $identifier): ?int
{
    $ipHash = arc_login_ip_hash();
    $table = arc_table('account_login_attempts');
    // Serialize only count + reservation, not password verification. Distinct
    // connections/sessions must not each observe the same final available slot.
    $lockName = 'arc-login:' . substr(hash('sha256', (string)(arc_config()['db_name'] ?? '') . $table . $ipHash), 0, 54);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 2)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Não foi possível reservar uma tentativa de autenticação.');
    }
    try {
        $limit = max(3, min(30, (int)arc_platform_setting($pdo, 'platform_login_max_attempts', '5')));
        $attempts = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ip_hash=? AND attempted_at >= (CURRENT_TIMESTAMP - INTERVAL 15 MINUTE)');
        $attempts->execute([$ipHash]);
        if ((int)$attempts->fetchColumn() >= $limit) {
            return null;
        }
        $identifierHash = hash_hmac('sha256', strtolower(trim($identifier)), (string)(arc_config()['app_secret'] ?? 'arcoll'));
        $pdo->prepare('INSERT INTO ' . $table . ' (ip_hash,identifier_hash) VALUES (?,?)')->execute([$ipHash, $identifierHash]);
        return (int)$pdo->lastInsertId();
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

/** A successful authentication must never erase other failures from this IP. */
function arc_login_attempt_succeeded(PDO $pdo, int $attemptId): void
{
    $pdo->prepare('DELETE FROM ' . arc_table('account_login_attempts') . ' WHERE id=? AND ip_hash=?')->execute([$attemptId, arc_login_ip_hash()]);
}
