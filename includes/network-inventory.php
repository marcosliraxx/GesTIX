<?php
/* Caminho: includes/network-inventory.php */
/** Inventário persistente por gestor e MAC. Não altera as tabelas legadas. */
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
        gestor_id BIGINT UNSIGNED NOT NULL,
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
        UNIQUE KEY uq_device_identity (network_id, gestor_id, identity_key),
        KEY ix_device_list (account_id,network_id,gestor_id,hidden,pinned),
        KEY ix_device_mac (network_id,mac)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS `'.arc_prefix().'network_device_events` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL,
        gestor_id BIGINT UNSIGNED NOT NULL, identity_key VARCHAR(90) NOT NULL,
        event_type VARCHAR(16) NOT NULL, occurred_at BIGINT NOT NULL,
        device_name VARCHAR(160) NOT NULL, ip VARCHAR(45) NOT NULL, mac VARCHAR(17) NOT NULL,
        KEY ix_network_time (account_id,network_id,occurred_at,id),
        KEY ix_device_time (account_id,network_id,identity_key,occurred_at)
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
/** Registra mudanças observadas por sensor, sem fabricar saídas por perda do gestor. */
function arc_inventory_sync(PDO $pdo, int $account, int $network, int $gestor, array $event): void {
    if (!isset($event['devices']) || !is_array($event['devices'])) return;
    arc_inventory_schema($pdo);
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        // Serializa lotes do mesmo sensor e impede que dois envios dupliquem transições.
        $lock=$pdo->prepare('SELECT id FROM '.arc_table('network_gestores').' WHERE id=? AND account_id=? AND network_id=? FOR UPDATE');
        $lock->execute([$gestor,$account,$network]);
        if (!$lock->fetchColumn()) throw new DomainException('Gestor não encontrado.',403);
        $snapshot=static function()use($pdo,$account,$network,$gestor):array{
            $q=$pdo->prepare('SELECT identity_key,mac,status,last_seen,last_change,custom_name,detected_name,ip FROM '.arc_inventory_table().' WHERE account_id=? AND network_id=? AND gestor_id=?');
            $q->execute([$account,$network,$gestor]);return $q->fetchAll(PDO::FETCH_ASSOC);
        };
        $before=[];
        foreach($snapshot() as $row) $before[$row['identity_key']]=$row;
        $now=time();$captured=(int)($event['captured_at']??$now);
        if($captured<=0 || $captured>$now+120) $captured=$now;
        $latest=0;foreach($before as $row)$latest=max($latest,(int)$row['last_seen'],(int)$row['last_change']);
        // Uma fotografia atrasada nunca desconecta equipamentos de uma fotografia mais recente.
        if(($event['event']??'')==='network_scan' && $captured<$latest){if($ownTransaction)$pdo->commit();return;}
        if(count($event['devices'])>=180)$event['truncated']=true;
        $event['devices']=array_values(array_filter($event['devices'],static function($device)use($before,$captured):bool{
            if(!is_array($device))return false;
            [$key]=arc_inventory_identity($device);
            $previous=$before[$key]??null;
            return !$previous || $captured>=max((int)$previous['last_seen'],(int)$previous['last_change']);
        }));
        $captured=min($now,$captured);
        $event['_observed_at']=$captured;
        arc_inventory_apply($pdo,$account,$network,$gestor,$event);
        $insert=$pdo->prepare('INSERT INTO `'.arc_prefix().'network_device_events` (account_id,network_id,gestor_id,identity_key,event_type,occurred_at,device_name,ip,mac) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach($snapshot() as $row){
            if($row['mac']==='' || !str_starts_with((string)$row['identity_key'],'mac:'))continue;
            $previous=$before[$row['identity_key']]??null;
            $kind=$previous===null?'detected':($previous['status']!==$row['status']?(string)$row['status']:'');
            if($kind==='')continue;
            $at=$kind==='detected'?$captured:min($now,max(1,(int)$row['last_change']));
            $name=trim((string)($row['custom_name']!==''?$row['custom_name']:$row['detected_name']));
            $insert->execute([$account,$network,$gestor,$row['identity_key'],$kind,$at,$name,(string)$row['ip'],(string)$row['mac']]);
        }
        if($ownTransaction)$pdo->commit();
    } catch(Throwable $error){if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function arc_inventory_apply(PDO $pdo, int $account, int $network, int $gestor, array $event): void {
    $devices = $event['devices'] ?? null;
    if (!is_array($devices)) return;
    arc_inventory_schema($pdo);
    $sql = 'INSERT INTO ' . arc_inventory_table() . ' (account_id,network_id,gestor_id,identity_key,mac,ip,detected_name,detected_type,status,first_seen,last_seen,last_change,source,payload_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
     ip=VALUES(ip),mac=VALUES(mac),detected_name=VALUES(detected_name),detected_type=VALUES(detected_type),
     last_change=IF(status<>VALUES(status),VALUES(last_change),last_change),status=VALUES(status),
     first_seen=IF(first_seen=0, VALUES(first_seen), LEAST(first_seen,VALUES(first_seen))),
     last_seen=GREATEST(last_seen,VALUES(last_seen)),source=VALUES(source),payload_json=VALUES(payload_json)';
    $statement = $pdo->prepare($sql);
    // Reaproveita consultas no lote e restringe a migração de dados à própria conta.
    $lookup = $pdo->prepare('SELECT id,custom_name,custom_type,group_name,pinned,hidden,managed FROM ' . arc_inventory_table() . ' WHERE account_id=? AND network_id=? AND gestor_id=? AND identity_key=? AND mac=\'\' LIMIT 1');
    $merge = $pdo->prepare('UPDATE ' . arc_inventory_table() . ' SET
                   custom_name=IF(custom_name=\'\',?,custom_name),
                   custom_type=IF(custom_type=\'\',?,custom_type),
                   group_name=IF(group_name=\'\',?,group_name),
                   pinned=GREATEST(pinned,?),hidden=GREATEST(hidden,?),managed=GREATEST(managed,?)
                   WHERE account_id=? AND network_id=? AND gestor_id=? AND identity_key=?');
    $removeLegacy = $pdo->prepare('DELETE FROM ' . arc_inventory_table() . ' WHERE id=? AND account_id=? AND network_id=? AND gestor_id=?');
    // Uma varredura completa é uma fotografia da rede: equipamentos ausentes passaram a offline.
    // Eventos incrementais 'device_event' NÃO removem nem desconectam os demais dispositivos.
    if (($event['event'] ?? '') === 'network_scan' && empty($event['incomplete']) && empty($event['partial']) && empty($event['truncated']) && count($devices)<180) {
        $present = [];
        foreach (array_slice($devices, 0, 180) as $item) {
            if (!is_array($item)) continue;
            [$identity] = arc_inventory_identity($item);
            if ($identity !== '') $present[$identity] = true;
        }
        // Um escaneamento totalmente vazio pode significar perda de rede ou falha do sensor.
        if ($present) {
            $offline = $pdo->prepare('UPDATE ' . arc_inventory_table() . " SET status='offline',last_change=? WHERE account_id=? AND network_id=? AND gestor_id=? AND status='online' AND identity_key=?");
            $previous = $pdo->prepare('SELECT identity_key FROM ' . arc_inventory_table() . " WHERE account_id=? AND network_id=? AND gestor_id=? AND status='online' AND mac<>''");
            $previous->execute([$account,$network,$gestor]);
            foreach ($previous->fetchAll(PDO::FETCH_COLUMN) as $identity) {
                if (!isset($present[$identity])) $offline->execute([(int)($event['_observed_at']??time()),$account,$network,$gestor,$identity]);
            }
        }
    }
    foreach (array_slice($devices, 0, 180) as $device) {
        if (!is_array($device)) continue;
        [$identity,$mac] = arc_inventory_identity($device);
        if ($identity === '') continue; // Sem MAC verificavel, nao registrar no inventario persistente.
        $now = time();
        $first = (int) ($device['first_seen'] ?? $now);
        $last = (int) ($device['last_seen'] ?? (($device['status']??'')==='online'?$now:0));
        $change = (int) ($device['last_change'] ?? ($event['_observed_at']??$now));
        $first = ($first > 0 && $first <= $now + 120) ? min($first,$now) : $now;
        $last = ($last > 0 && $last <= $now + 120) ? min($last,$now) : (($device['status']??'')==='online'?$now:0);
        $change = ($change > 0 && $change <= $now + 120) ? min($change,$now) : (int)($event['_observed_at']??$now);
        $ip = trim((string) ($device['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';
        $payload = json_encode($device, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $statement->execute([
            $account,$network,$gestor,$identity,$mac, $ip,
            mb_substr((string)($device['name'] ?? ''),0,160),
            mb_substr((string)($device['type'] ?? 'device'),0,50),
            (($device['status'] ?? '') === 'online' ? 'online' : 'offline'),
            $first,$last,$change,mb_substr((string)($device['source'] ?? 'gestor'),0,100),
            mb_substr((string)$payload,0,16000)
        ]);
        if ($mac !== '' && $ip !== '') {
            // Dispositivo identificado inicialmente por IP: migra personalizações
            // para a identidade permanente quando o gestor descobre o MAC.
            $lookup->execute([$account,$network,$gestor,'ip:'.$ip]);
            $old = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $merge->execute([$old['custom_name'],$old['custom_type'],$old['group_name'],(int)$old['pinned'],(int)$old['hidden'],(int)$old['managed'],$account,$network,$gestor,$identity]);
                $removeLegacy->execute([(int)$old['id'],$account,$network,$gestor]);
            }
        }
    }
}
function arc_inventory_list(PDO $pdo, int $account, int $network, int $gestor, bool $includeHidden = false): array {
    arc_inventory_schema($pdo);
    $where = $includeHidden ? '' : " AND hidden=0 AND mac<>'' AND identity_key LIKE 'mac:%'";
    $q = $pdo->prepare('SELECT * FROM ' . arc_inventory_table() . ' WHERE account_id=? AND network_id=? AND gestor_id=?' . $where . ' ORDER BY pinned DESC, group_name ASC, COALESCE(NULLIF(custom_name,\'\'), detected_name) ASC, id ASC LIMIT 500');
    $q->execute([$account,$network,$gestor]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function arc_inventory_overrides(PDO $pdo, int $account, int $network, int $gestor): array {
    $rows = arc_inventory_list($pdo,$account,$network,$gestor, true);
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

/** Mesmo período para web e gestor: tolera três heartbeats de 15 segundos. */
function arc_inventory_presence_seconds(): int {
    static $seconds = null;
    if ($seconds === null) {
        $seconds = 45;
        try { $seconds = max(15, min(600, (int)arc_platform_setting(arc_db(), 'platform_gestor_presence_seconds', '45'))); }
        catch (Throwable $e) { error_log('Arcoll presença: ' . $e->getMessage()); }
    }
    return $seconds;
}

/** Agregação testável e independente de PDO. Estado desconhecido não é saída. */
function arc_inventory_dashboard_rows(array $gestores,array $observations,int $now): array {
    $fresh=[];$gestorRows=[];$observed=[];
    foreach($observations as $observation){$sensor=(int)$observation['gestor_id'];$observed[$sensor]=max($observed[$sensor]??0,(int)$observation['last_seen'],(int)$observation['last_change']);}
    foreach($gestores as $gestor){
        $seen=(int)$gestor['seen'];$online=$seen>0&&$seen>=$now-arc_inventory_presence_seconds();
        $inventorySeen=(int)($gestor['inventory_seen']??($observed[(int)$gestor['id']]??0));
        $collecting=$online&&$inventorySeen>=$now-300; // coleta de 60 s, tolerância para lentidão/interrupção
        $fresh[(int)$gestor['id']]=$collecting;
        $gestorRows[]=['id'=>(int)$gestor['id'],'online'=>$online?1:0,'seen'=>$seen,'collecting'=>$collecting?1:0,'inventory_seen'=>$inventorySeen];
    }
    $byMac=[];
    foreach($observations as $d){
        if((int)($d['hidden']??0)===1)continue;
        $mac=arc_inventory_mac((string)$d['mac']);if($mac==='')continue;
        $key='mac:'.strtolower($mac);$isFresh=$fresh[(int)$d['gestor_id']]??false;
        $raw=(string)$d['status']==='online';
        $rank=$isFresh?($raw?3:2):1;
        $ls=max(0,min($now,(int)$d['last_seen']));$lc=max(0,min($now,(int)$d['last_change']));$fs=max(0,min($now,(int)$d['first_seen']));
        $name=trim((string)($d['custom_name']!==''?$d['custom_name']:$d['detected_name']));
        if($name==='')$name=trim((string)$d['ip']);if($name==='')$name='Dispositivo';
        $since=$isFresh?($lc?:($fs?:$ls)):0;
        $row=['id'=>$key,'n'=>$name,'t'=>(string)($d['custom_type']!==''?$d['custom_type']:$d['detected_type']),
            'o'=>$isFresh&&$raw?1:0,'x'=>$isFresh?0:1,'state'=>$isFresh?($raw?'online':'offline'):'unknown',
            'ip'=>(string)$d['ip'],'m'=>$mac,'g'=>trim((string)$d['group_name']),
            's'=>$since,'l'=>$ls,'f'=>$fs,'c'=>$lc,'gestor_id'=>(int)$d['gestor_id'],
            'source'=>(string)($d['source']??''),'_rank'=>$rank,'_contact'=>max($ls,$lc),'observers'=>1];
        if(!isset($byMac[$key])){$byMac[$key]=$row;continue;}
        $old=$byMac[$key];$first=$old['f']>0&&$fs>0?min($old['f'],$fs):max($old['f'],$fs);
        if($rank>$old['_rank']||($rank===$old['_rank']&&$row['_contact']>$old['_contact']))$byMac[$key]=$row;
        $byMac[$key]['f']=$first;$byMac[$key]['observers']=$old['observers']+1;
    }
    foreach($byMac as &$row){unset($row['_rank'],$row['_contact']);}unset($row);
    $rows=array_values($byMac);usort($rows,static fn($a,$b)=>strcmp($a['id'],$b['id']));
    return ['gestores'=>$gestorRows,'devices'=>$rows];
}

/** Inventário consolidado e últimas transições observadas, com isolamento da conta/rede. */
function arc_inventory_dashboard(PDO $pdo,int $account,int $network): array {
    arc_inventory_schema($pdo);$now=time();
    $q=$pdo->prepare('SELECT a.id,UNIX_TIMESTAMP(a.last_seen_at) AS seen,COALESCE((SELECT MAX(GREATEST(i.last_seen,i.last_change)) FROM '.arc_inventory_table().' i WHERE i.account_id=a.account_id AND i.network_id=a.network_id AND i.gestor_id=a.id),0) AS inventory_seen FROM '.arc_table('network_gestores')." a WHERE a.network_id=? AND a.account_id=? AND a.status='approved' ORDER BY a.id");
    $q->execute([$network,$account]);$gestores=$q->fetchAll(PDO::FETCH_ASSOC);
    // Uma consulta para todos os sensores; evita N consultas por gestor e o limite de 500 por sensor.
    $q=$pdo->prepare('SELECT i.gestor_id,i.identity_key,i.mac,i.ip,i.detected_name,i.detected_type,i.custom_name,i.custom_type,i.group_name,i.hidden,i.status,i.first_seen,i.last_seen,i.last_change,i.source FROM '.arc_inventory_table().' i JOIN '.arc_table('network_gestores')." a ON a.id=i.gestor_id AND a.account_id=i.account_id AND a.network_id=i.network_id WHERE i.account_id=? AND i.network_id=? AND a.status='approved' AND i.hidden=0 AND i.mac<>'' AND i.identity_key LIKE 'mac:%' ORDER BY i.id LIMIT 5001");
    $q->execute([$account,$network]);$observations=$q->fetchAll(PDO::FETCH_ASSOC);$limited=count($observations)>5000;
    $observations=array_slice($observations,0,5000);
    $result=arc_inventory_dashboard_rows($gestores,$observations,$now);
    $q=$pdo->prepare('SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM '.arc_inventory_table().' WHERE account_id=? AND network_id=?');
    $q->execute([$account,$network]);$updated=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT e.id,e.gestor_id,e.identity_key,e.event_type,e.occurred_at,e.device_name,e.ip,e.mac FROM `'.arc_prefix().'network_device_events` e JOIN '.arc_inventory_table().' i ON i.account_id=e.account_id AND i.network_id=e.network_id AND i.gestor_id=e.gestor_id AND i.identity_key=e.identity_key JOIN '.arc_table('network_gestores')." a ON a.id=e.gestor_id AND a.account_id=e.account_id AND a.network_id=e.network_id WHERE e.account_id=? AND e.network_id=? AND i.hidden=0 AND a.status='approved' ORDER BY e.occurred_at DESC,e.id DESC LIMIT 100");
    $q->execute([$account,$network]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
    return ['now'=>$now,'updated'=>$updated,'limited'=>$limited,'events'=>$events,'presence_seconds'=>arc_inventory_presence_seconds()]+$result;
}
