<?php
declare(strict_types=1);
// Rota JSON independente da renderização HTML do workspace.
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function arc_report_json(array $data, int $code=200): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
try {
    require dirname(__DIR__).'/includes/bootstrap.php';
    if (!arc_is_user()) arc_report_json(['ok'=>false,'error'=>'Sua sessão expirou. Faça login novamente.'],401);
    $pdo=arc_db();
    $account=(int)arc_user_id();
    $networkId=filter_input(INPUT_GET,'network',FILTER_VALIDATE_INT) ?: 0;
    $agentId=filter_input(INPUT_GET,'agent',FILTER_VALIDATE_INT) ?: 0;
    if ($networkId < 1 || $agentId < 1) arc_report_json(['ok'=>false,'error'=>'Selecione uma rede e um agente válidos.'],422);
    $stmt=$pdo->prepare('SELECT id,name FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? LIMIT 1');
    $stmt->execute([$networkId,$account]);
    $network=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$network) arc_report_json(['ok'=>false,'error'=>'Rede não autorizada ou inexistente.'],403);
    $stmt=$pdo->prepare('SELECT id,machine_name FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=? AND status=\'approved\' LIMIT 1');
    $stmt->execute([$agentId,$networkId,$account]);
    $agent=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agent) arc_report_json(['ok'=>false,'error'=>'Agente não autorizado ou inexistente.'],403);
    $from=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
    if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to||$start>$end||$start->diff($end)->days>365 || $end>new DateTimeImmutable('today'))
        arc_report_json(['ok'=>false,'error'=>'Informe um período válido, de até 366 dias, sem datas futuras.'],422);
    $report=(string)($_GET['report']??'monitoramento');
    if(!in_array($report,['monitoramento','velocidade','dispositivos','mapa'],true))arc_report_json(['ok'=>false,'error'=>'Tipo de relatório inválido.'],422);
    $where=' FROM '.arc_table('agent_telemetry').' t WHERE t.network_id=? AND t.account_id=? AND t.agent_id=? AND t.captured_at>=? AND t.captured_at<?';
    $args=[$networkId,$account,$agentId,$from.' 00:00:00',$end->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    $where.=" AND t.event_type<>'server_metrics'";
    // O filtro atua no servidor para não misturar tipos de medição.
    if ($report==='velocidade'){$where.=' AND (t.event_type LIKE ? OR t.event_type LIKE ?)';$args[]='%speed%';$args[]='%veloc%';}
    elseif($report==='monitoramento'){$where.=' AND (t.event_type LIKE ? OR t.event_type LIKE ? OR t.event_type LIKE ? OR t.event_type LIKE ?)';array_push($args,'%monitor%','%metric%','%network_status%','%heartbeat%');}
    else {$where.=' AND (t.event_type LIKE ? OR t.event_type LIKE ?)';array_push($args,'%network_scan%','%inventor%');}
    $devices=[];
    if($report==='mapa'||$report==='dispositivos'){
      require_once dirname(__DIR__).'/includes/network-inventory.php';
      foreach(arc_inventory_list($pdo,$account,$networkId,$agentId) as $d){
        if(!empty($d['hidden']))continue;
        $payload=json_decode((string)($d['payload_json']??''),true);
        $devices[]=['name'=>(string)($d['custom_name']?:$d['detected_name']?:$d['ip']?:'Dispositivo'),'ip'=>(string)($d['ip']??''),'mac'=>(string)($d['mac']??''),'status'=>(string)($d['status']??''),'agent_installed'=>(bool)($payload['agent_installed']??false)];
        if(count($devices)>=300)break;
      }
    }

    $stmt=$pdo->prepare('SELECT t.event_type AS type,COUNT(*) AS cnt'.$where.' GROUP BY t.event_type ORDER BY cnt DESC');
    $stmt->execute($args);
    $types=array_map(static fn(array $r): array => ['type'=>(string)$r['type'],'count'=>(int)$r['cnt']],$stmt->fetchAll(PDO::FETCH_ASSOC));
    $stmt=$pdo->prepare('SELECT t.event_type AS type,t.captured_at AS at'.$where.' ORDER BY t.captured_at DESC LIMIT 60');
    $stmt->execute($args);
    $events=array_map(static fn(array $row): array => ['type'=>(string)$row['type'],'at'=>(string)$row['at'],'machine'=>(string)$agent['machine_name']],$stmt->fetchAll(PDO::FETCH_ASSOC));
    arc_report_json(['ok'=>true,'report'=>$report,'devices'=>$devices,'network'=>['name'=>(string)$network['name']],'agent'=>(string)$agent['machine_name'],'from'=>$from,'to'=>$to,'total'=>array_sum(array_column($types,'count')),'types'=>$types,'events'=>$events,'last_activity'=>$events[0]['at']??null]);
} catch(Throwable $e) {
    error_log('network-report: '.$e->getMessage());
    arc_report_json(['ok'=>false,'error'=>'Falha ao consultar os dados do relatório. Verifique os logs do servidor.'],500);
}
