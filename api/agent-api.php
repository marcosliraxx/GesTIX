<?php
/* Caminho: api/agent-api.php */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') arc_json(['ok'=>false,'error'=>'Método não permitido.'],405);
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 20000) arc_json(['ok'=>false,'error'=>'Requisição inválida.'],413);
    $data = json_decode($raw, true);
    if (!is_array($data)) arc_json(['ok'=>false,'error'=>'Dados inválidos.'],400);
    $action = (string)($data['action'] ?? '');
    $code = strtoupper(trim((string)($data['site_code'] ?? '')));
    $fingerprint = strtolower(trim((string)($data['machine_hash'] ?? '')));
    if (!preg_match('/^[A-Z0-9-]{4,80}$/', $code) || !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) arc_json(['ok'=>false,'error'=>'Código do local ou identificador da máquina inválido.'],422);
    $find = $pdo->prepare('SELECT id,account_id,name,site_code,is_active FROM ' . arc_table('managed_networks') . ' WHERE UPPER(site_code)=? LIMIT 1');
    $find->execute([$code]); $network = $find->fetch();
    if (!$network || (int)$network['is_active'] !== 1) arc_json(['ok'=>false,'status'=>'invalid','error'=>'Código do local inválido ou rede inativa.'],404);
    $networkId=(int)$network['id']; $accountId=(int)$network['account_id'];
    $label=mb_substr(trim((string)($data['machine_label'] ?? 'Computador')),0,160); if ($label==='') $label='Computador';
    $os=mb_substr(trim((string)($data['operating_system'] ?? 'Windows')),0,160);
    $ip=filter_var((string)($data['local_ip'] ?? ''),FILTER_VALIDATE_IP) ? (string)$data['local_ip'] : '';
    $gateway=filter_var((string)($data['gateway'] ?? ''),FILTER_VALIDATE_IP) ? (string)$data['gateway'] : '';
    if ($action === 'enroll') {
        $q=$pdo->prepare('SELECT id,status FROM ' . arc_table('network_agents') . ' WHERE network_id=? AND machine_hash=? LIMIT 1');
        $q->execute([$networkId,$fingerprint]); $existing=$q->fetch();
        if ($existing) {
            if ($existing['status']==='deleted') {
                $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET account_id=?,machine_label=?,operating_system=?,local_ip=?,gateway=?,status=\'pending\',last_seen_at=CURRENT_TIMESTAMP,approved_at=NULL WHERE id=?')->execute([$accountId,$label,$os,$ip,$gateway,(int)$existing['id']]);
            } else {
                $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET machine_label=?,operating_system=?,local_ip=?,gateway=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$label,$os,$ip,$gateway,(int)$existing['id']]);
            }
        } else {
            $pdo->prepare('INSERT INTO ' . arc_table('network_agents') . ' (account_id,network_id,machine_hash,machine_label,operating_system,local_ip,gateway,status,last_seen_at) VALUES (?,?,?,?,?,?,?,\'pending\',CURRENT_TIMESTAMP)')->execute([$accountId,$networkId,$fingerprint,$label,$os,$ip,$gateway]);
        }
    } elseif ($action !== 'status') arc_json(['ok'=>false,'error'=>'Ação inválida.'],400);
    $q=$pdo->prepare('SELECT status,machine_label FROM ' . arc_table('network_agents') . ' WHERE network_id=? AND machine_hash=? LIMIT 1');
    $q->execute([$networkId,$fingerprint]); $agent=$q->fetch();
    if (!$agent) arc_json(['ok'=>false,'status'=>'not_registered','error'=>'Agente não cadastrado.'],404);
    if (in_array($agent['status'],['approved','pending'],true)) {
        $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET last_seen_at=CURRENT_TIMESTAMP,local_ip=?,gateway=? WHERE network_id=? AND machine_hash=?')->execute([$ip,$gateway,$networkId,$fingerprint]);
    }
    arc_json(['ok'=>true,'status'=>$agent['status'],'network'=>$network['name'],'machine_label'=>$agent['machine_label'],'message'=>match($agent['status']){'approved'=>'Agente aprovado.','pending'=>'Aguardando aprovação do gestor de redes.','blocked'=>'Este agente foi bloqueado pelo gestor.','deleted'=>'Este agente foi excluído e precisa ser cadastrado novamente.',default=>'Status desconhecido.'}]);
} catch (Throwable $e) {
    error_log('Arcoll agent API: '.$e->getMessage());
    arc_json(['ok'=>false,'error'=>'Não foi possível comunicar com o gestor de redes.'],500);
}
