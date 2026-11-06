<?php
/* Caminho: includes/network-inventory.php */
/** Inventário persistente por agente e MAC. Não altera as tabelas legadas. */
declare(strict_types=1);

function arc_inventory_table(): string {
    return '`' . arc_prefix() . 'network_device_inventory`';
}
function arc_inventory_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . arc_inventory_table() . ' (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        network_id BIGINT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        identity_key VARCHAR(90) NOT NULL,
        mac VARCHAR(17) NOT NULL DEFAULT \'\',
        ip VARCHAR(45) NOT NULL DEFAULT \'\',
        detected_name VARCHAR(160) NOT NULL DEFAULT \'\',
        detected_type VARCHAR(50) NOT NULL DEFAULT \'device\',
        custom_name VARCHAR(160) NOT NULL DEFAULT \'\',
        custom_type VARCHAR(50) NOT NULL DEFAULT \'\',
        group_name VARCHAR(90) NOT NULL DEFAULT \'\',
        pinned TINYINT(1) NOT NULL DEFAULT 0,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        managed TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT \'offline\',
        first_seen BIGINT NOT NULL DEFAULT 0,
        last_seen BIGINT NOT NULL DEFAULT 0,
        last_change BIGINT NOT NULL DEFAULT 0,
        source VARCHAR(100) NOT NULL DEFAULT \'\',
        payload_json TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_device_identity (network_id, agent_id, identity_key),
        KEY ix_device_list (account_id,network_id,agent_id,hidden,pinned),
        KEY ix_device_mac (network_id,mac)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}
function arc_inventory_mac(string $value): string {
    $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $value));
    if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'FFFFFFFFFFFF') return '';
    // O bit menos significativo do primeiro octeto indica endereços de grupo.
    // MAC multicast não identifica unicamente um dispositivo; MAC local/unicast é válido.
    if ((hexdec(substr($hex, 0, 2)) & 1) !== 0) return '';
    return implode(':', str_split($hex, 2));
}
function arc_inventory_identity(array $device): array {
    $mac = arc_inventory_mac((string)($device['mac'] ?? ''));
    if ($mac !== '') return ['mac:' . strtolower($mac), $mac];
    // Um MAC desconhecido nao deve criar identidade provisoria por IP.
    return ['', ''];
}
function arc_inventory_sync(PDO $pdo, int $account, int $network, int $agent, array $event): void {
    $devices = $event['devices'] ?? null;
    if (!is_array($devices)) return;
    arc_inventory_schema($pdo);
    $sql = 'INSERT INTO ' . arc_inventory_table() . ' (account_id,network_id,agent_id,identity_key,mac,ip,detected_name,detected_type,status,first_seen,last_seen,last_change,source,payload_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
     ip=VALUES(ip),mac=VALUES(mac),detected_name=VALUES(detected_name),detected_type=VALUES(detected_type),
     last_change=IF(status<>VALUES(status),VALUES(last_change),last_change),status=VALUES(status),
     first_seen=IF(first_seen=0, VALUES(first_seen), LEAST(first_seen,VALUES(first_seen))),
     last_seen=GREATEST(last_seen,VALUES(last_seen)),source=VALUES(source),payload_json=VALUES(payload_json)';
    $statement = $pdo->prepare($sql);
    // Reaproveita consultas no lote e restringe a migração de dados à própria conta.
    $lookup = $pdo->prepare('SELECT id,custom_name,custom_type,group_name,pinned,hidden,managed FROM ' . arc_inventory_table() . ' WHERE account_id=? AND network_id=? AND agent_id=? AND identity_key=? AND mac=\'\' LIMIT 1');
    $merge = $pdo->prepare('UPDATE ' . arc_inventory_table() . ' SET
                   custom_name=IF(custom_name=\'\',?,custom_name),
                   custom_type=IF(custom_type=\'\',?,custom_type),
                   group_name=IF(group_name=\'\',?,group_name),
                   pinned=GREATEST(pinned,?),hidden=GREATEST(hidden,?),managed=GREATEST(managed,?)
                   WHERE account_id=? AND network_id=? AND agent_id=? AND identity_key=?');
    $removeLegacy = $pdo->prepare('DELETE FROM ' . arc_inventory_table() . ' WHERE id=? AND account_id=? AND network_id=? AND agent_id=?');
    // Uma varredura completa é uma fotografia da rede: equipamentos ausentes passaram a offline.
    // Eventos incrementais 'device_event' NÃO removem nem desconectam os demais dispositivos.
    if (($event['event'] ?? '') === 'network_scan') {
        $present = [];
        foreach (array_slice($devices, 0, 180) as $item) {
            if (!is_array($item)) continue;
            [$identity] = arc_inventory_identity($item);
            if ($identity !== '') $present[$identity] = true;
        }
        // Um escaneamento totalmente vazio pode significar perda de rede ou falha do sensor.
        if ($present) {
            $offline = $pdo->prepare('UPDATE ' . arc_inventory_table() . " SET status='offline',last_change=UNIX_TIMESTAMP() WHERE account_id=? AND network_id=? AND agent_id=? AND status='online' AND identity_key=?");
            $previous = $pdo->prepare('SELECT identity_key FROM ' . arc_inventory_table() . " WHERE account_id=? AND network_id=? AND agent_id=? AND status='online' AND mac<>''");
            $previous->execute([$account,$network,$agent]);
            foreach ($previous->fetchAll(PDO::FETCH_COLUMN) as $identity) {
                if (!isset($present[$identity])) $offline->execute([$account,$network,$agent,$identity]);
            }
        }
    }
    foreach (array_slice($devices, 0, 180) as $device) {
        if (!is_array($device)) continue;
        [$identity,$mac] = arc_inventory_identity($device);
        if ($identity === '') continue; // Sem MAC verificavel, nao registrar no inventario persistente.
        $now = time();
        $first = (int) ($device['first_seen'] ?? $now);
        $last = (int) ($device['last_seen'] ?? $now);
        $change = (int) ($device['last_change'] ?? $last);
        $first = ($first > 0 && $first <= $now + 120) ? $first : $now;
        $last = ($last > 0 && $last <= $now + 120) ? $last : $now;
        $change = ($change > 0 && $change <= $now + 120) ? $change : $last;
        $ip = trim((string) ($device['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';
        $payload = json_encode($device, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $statement->execute([
            $account,$network,$agent,$identity,$mac, $ip,
            mb_substr((string)($device['name'] ?? ''),0,160),
            mb_substr((string)($device['type'] ?? 'device'),0,50),
            (($device['status'] ?? '') === 'online' ? 'online' : 'offline'),
            $first,$last,$change,mb_substr((string)($device['source'] ?? 'agent'),0,100),
            mb_substr((string)$payload,0,16000)
        ]);
        if ($mac !== '' && $ip !== '') {
            // Dispositivo identificado inicialmente por IP: migra personalizações
            // para a identidade permanente quando o agente descobre o MAC.
            $lookup->execute([$account,$network,$agent,'ip:'.$ip]);
            $old = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $merge->execute([$old['custom_name'],$old['custom_type'],$old['group_name'],(int)$old['pinned'],(int)$old['hidden'],(int)$old['managed'],$account,$network,$agent,$identity]);
                $removeLegacy->execute([(int)$old['id'],$account,$network,$agent]);
            }
        }
    }
}
function arc_inventory_list(PDO $pdo, int $account, int $network, int $agent, bool $includeHidden = false): array {
    arc_inventory_schema($pdo);
    $where = $includeHidden ? '' : " AND hidden=0 AND mac<>'' AND identity_key LIKE 'mac:%'";
    $q = $pdo->prepare('SELECT * FROM ' . arc_inventory_table() . ' WHERE account_id=? AND network_id=? AND agent_id=?' . $where . ' ORDER BY pinned DESC, group_name ASC, COALESCE(NULLIF(custom_name,\'\'), detected_name) ASC, id ASC LIMIT 500');
    $q->execute([$account,$network,$agent]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function arc_inventory_overrides(PDO $pdo, int $account, int $network, int $agent): array {
    $rows = arc_inventory_list($pdo,$account,$network,$agent, true);
    $overrides = [];
    foreach ($rows as $row) {
        if ($row['mac'] === '' || !(int)($row['managed']??0)) continue;
        $overrides[] = [
            'mac' => $row['mac'], 'custom_name' => $row['custom_name'],
            'custom_type' => $row['custom_type'], 'group' => $row['group_name'],
            'pinned' => (bool)$row['pinned'], 'hidden' => (bool)$row['hidden']
        ];
    }
    return $overrides;
}
