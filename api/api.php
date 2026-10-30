<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/network-inventory.php';

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    $action = (string)($_GET['action'] ?? '');
    /* Validação do gestor para abrir configurações locais do agente.
       Nenhuma senha ou token de sessão é armazenado na máquina. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='agent-settings-auth') {
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>4096) arc_json(['ok'=>false,'error'=>'Requisição inválida'],413);
        $v=json_decode($raw,true);
        if (!is_array($v)) arc_json(['ok'=>false,'error'=>'Requisição inválida'],400);
        $fp=strtolower(trim((string)($v['fingerprint']??'')));
        $code=strtoupper(trim((string)($v['site_code']??'')));
        $key=trim((string)($v['download_key']??''));
        $email=strtolower(trim((string)($v['email']??'')));
        $password=(string)($v['password']??'');
        if (!preg_match('/^[a-f0-9]{64}$/',$fp) || !preg_match('/^[A-Z0-9]{5}$/',$code)
            || !preg_match('/^[a-f0-9]{64}$/',$key) || !filter_var($email,FILTER_VALIDATE_EMAIL)
            || strlen($email)>254 || strlen($password)>512 || $password==='') {
            arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        }
        $loginAttemptId = arc_login_attempt_begin($pdo, $email);
        if ($loginAttemptId === null) {
            header('Retry-After: 900');
            arc_json(['ok'=>false,'error'=>'Muitas tentativas. Tente novamente em 15 minutos'],429);
        }
        $q=$pdo->prepare('SELECT u.id,u.password_hash,u.account_status FROM '.arc_table('user_accounts').' u '
            .'INNER JOIN '.arc_table('managed_networks').' n ON n.account_id=u.id AND n.is_active=1 '
            .'INNER JOIN '.arc_table('network_agents').' a ON a.network_id=n.id AND a.account_id=n.account_id AND a.status=\'approved\' '
            .'INNER JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id '
            .'WHERE u.email_hash=? AND a.fingerprint=? AND UPPER(a.site_code)=? AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([hash('sha256',$email),$fp,$code,hash('sha256',$key),$fp]);
        $user=$q->fetch(PDO::FETCH_ASSOC);
        $material=base64_encode(hash('sha384',$password,true));
        if (!$user || (string)$user['account_status']!=='active'
            || !(password_verify($material,(string)$user['password_hash']) || password_verify($password,(string)$user['password_hash']))) {
            arc_json(['ok'=>false,'error'=>'Gestor não autorizado ou senha inválida'],403);
        }
        arc_login_attempt_succeeded($pdo, $loginAttemptId);
        arc_user_audit($pdo,'agent_settings_login','Configurações locais autenticadas no agente '.$code,(int)$user['id']);
        header('Cache-Control: no-store, private');
        arc_json(['ok'=>true]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'network-live') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Faça login para acessar esta rede.'],401);
        $networkId=(int)($_GET['network_id']??0);
        try { $overview=arc_network_overview($pdo,$networkId); } catch (Throwable $e) { arc_json(['ok'=>false,'error'=>'Rede não encontrada ou sem permissão.'],404); }
        arc_json(['ok'=>true,'data'=>$overview]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bootstrap') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Faça login para continuar.'],401);
        try { arc_require_user_network($pdo); } catch (Throwable $e) { arc_json(['ok'=>false,'error'=>'Selecione uma rede gerenciada.'],403); }
        arc_json([
            'ok' => true,
            'csrf' => arc_csrf_token(),
            'settings' => arc_public_settings($pdo),
            'pages' => arc_pages($pdo, true),
            'state' => arc_app_state($pdo),
        ]);
    }
    /* Pacote de atualização limitado, autenticado pelo mesmo vínculo revogável do agente. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='agent-update-package') {
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>2048) arc_json(['ok'=>false,'error'=>'Requisição inválida'],413);
        $v=json_decode($raw,true);
        if (!is_array($v)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $fp=strtolower(trim((string)($v['fingerprint']??'')));
        $code=strtoupper(trim((string)($v['site_code']??'')));
        $key=trim((string)($v['download_key']??''));
        if (!preg_match('/^[a-f0-9]{64}$/',$fp) || !preg_match('/^[A-Z0-9]{5}$/',$code) || !preg_match('/^[a-f0-9]{64}$/',$key)) arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        $q=$pdo->prepare('SELECT a.id FROM '.arc_table('network_agents').' a INNER JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id INNER JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp]);
        if (!$q->fetchColumn()) arc_json(['ok'=>false,'error'=>'Agente não autorizado'],403);
        $files=['arcoll_app.py','arcoll_desktop.py','arcoll_agent.py','arcoll_toolkit.py','rt_engine.py','connection_diagnostics.py'];
        $package=[];
        foreach ($files as $name) {
            $path=__DIR__.'/../agent/'.$name;
            if (!is_file($path)) continue;
            $contents=file_get_contents($path);
            if (!is_string($contents) || strlen($contents)>1500000) arc_json(['ok'=>false,'error'=>'Pacote indisponível'],503);
            $package[]=['name'=>$name,'sha256'=>hash('sha256',$contents),'content'=>base64_encode($contents)];
        }
        if (count($package)<5) arc_json(['ok'=>false,'error'=>'Arquivos do agente incompletos'],503);
        $publishedVersion = '2.6.2';
        $agentSource = @file_get_contents(__DIR__.'/../agent/arcoll_app.py', false, null, 0, 4096);
        if (is_string($agentSource) && preg_match('/AGENT_VERSION\s*=\s*[\"\'](\d+\.\d+\.\d+)[\"\']/', $agentSource, $vm)) {
            $publishedVersion = $vm[1];
        }
        header('Cache-Control: no-store, private');
        arc_json(['ok'=>true,'version'=>$publishedVersion,'files'=>$package]);
    }
    /* Presença rápida: autenticação revogável da instalação. Sem registro de telemetria. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='agent-presence') {
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>2048) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $v=json_decode($raw,true);
        if (!is_array($v)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $fp=strtolower(trim((string)($v['fingerprint']??'')));
        $code=strtoupper(trim((string)($v['site_code']??'')));
        $key=trim((string)($v['download_key']??''));
        if (!preg_match('/^[a-f0-9]{64}$/',$fp) || !preg_match('/^[A-Z0-9]{5}$/',$code) || !preg_match('/^[a-f0-9]{64}$/',$key)) arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        $q=$pdo->prepare('SELECT a.id FROM '.arc_table('network_agents').' a INNER JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id INNER JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp]);
        $id=$q->fetchColumn();
        if (!$id) arc_json(['ok'=>false,'error'=>'Agente não autorizado'],403);
        $st=$v['state']??'online';
        if ($st!=='online' && $st!=='offline') arc_json(['ok'=>false,'error'=>'Estado inválido'],400);
        $reportedVersion=trim((string)($v['version']??''));
        if ($reportedVersion!=='' && !preg_match('/^\d+\.\d+\.\d+(?:[-.][A-Za-z0-9]+)?$/D',$reportedVersion)) arc_json(['ok'=>false,'error'=>'Versão inválida'],422);
        if ($st==='offline') {
            $pdo->prepare('UPDATE '.arc_table('network_agents')." SET last_seen_at='2000-01-01 00:00:00' WHERE id=?")->execute([(int)$id]);
        } else {
            $pdo->prepare('UPDATE '.arc_table('network_agents')." SET last_seen_at=CURRENT_TIMESTAMP,agent_version=CASE WHEN ?<>'' THEN ? ELSE agent_version END WHERE id=?")->execute([$reportedVersion,$reportedVersion,(int)$id]);
        }
        arc_json(['ok'=>true,'server_time'=>time(),'state'=>$st]);
    }
    /* Status consolidado de todas as redes do usuário, sem gravar telemetria. */
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'network-agent-states') {
        if (!arc_is_user() && !arc_is_admin()) arc_json(['ok'=>false,'error'=>'Não autorizado'],401);
        $sql='SELECT n.id, n.is_active, COUNT(a.id) AS total, '
            .'COALESCE(SUM(CASE WHEN a.last_seen_at >= DATE_SUB(NOW(), INTERVAL 12 SECOND) THEN 1 ELSE 0 END),0) AS online_count '
            .'FROM '.arc_table('managed_networks').' n LEFT JOIN '.arc_table('network_agents').' a '
            .'ON a.network_id=n.id AND a.account_id=n.account_id AND a.status=\'approved\'';
        $params=[];
        if (!arc_is_admin()) { $sql.=' WHERE n.account_id=?'; $params[]=(int)arc_user_id(); }
        $sql.=' GROUP BY n.id,n.is_active';
        $q=$pdo->prepare($sql);$q->execute($params);
        $states=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $total=(int)$r['total'];$online=(int)$r['online_count'];
            $state=$total===0?'none':($online===0?'offline':($online===$total?'online':'partial'));
            $states[(string)$r['id']]=['state'=>$state,'total'=>$total,'online'=>$online,'offline'=>$total-$online,'active'=>(int)$r['is_active']===1];
        }
        arc_json(['ok'=>true,'networks'=>$states,'checked_at'=>time(),'timeout_seconds'=>12]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-agent-versions') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        $check=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=?');
        $check->execute([$networkId,arc_user_id()]);if (!$check->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403);
        $source=@file_get_contents(__DIR__.'/../agent/arcoll_app.py');
        $latest=(is_string($source)&&preg_match('/^AGENT_VERSION\s*=\s*[\"\'](\d+\.\d+\.\d+)[\"\']/m',$source,$found))?$found[1]:null;
        $query=$pdo->prepare('SELECT id,machine_name,agent_version,last_seen_at,status FROM '.arc_table('network_agents').' WHERE network_id=? AND account_id=?');
        $query->execute([$networkId,arc_user_id()]);$agents=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $version=(string)($row['agent_version']??'');
            $online=$row['status']==='approved' && !empty($row['last_seen_at']) && strtotime((string)$row['last_seen_at'])>=time()-15;
            $agents[]=['id'=>(int)$row['id'],'name'=>(string)$row['machine_name'],'version'=>$version,'online'=>$online,'approved'=>$row['status']==='approved','outdated'=>$latest!==null&&preg_match('/^\d+\.\d+\.\d+$/',$version)&&version_compare($version,$latest,'<')];
        }
        arc_json(['ok'=>true,'published_version'=>$latest,'agents'=>$agents]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'health') {
        arc_json(['ok' => true, 'application' => 'Arcoll Speed Connection', 'version' => ARC_APP_VERSION]);
    }

    /* Regra única de expiração dos comandos em execução, compartilhada entre
     * a consulta do agente e o envio de comandos pela plataforma. */
    $expireRunningAgentCommands = static function (PDO $db, int $agentId): void {
        $db->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Tempo limite do agente',completed_at=NOW() WHERE agent_id=? AND status='running' AND dispatched_at < DATE_SUB(NOW(), INTERVAL 4 MINUTE)")->execute([$agentId]);
    };

    /* Terminal: fila existente + resultados parciais por tarefa. Sem acesso a shell irrestrito. */
    $ensureTerminalSchema = static function (PDO $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS '.arc_table('agent_terminal_jobs').' (
            command_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            agent_id BIGINT UNSIGNED NOT NULL,
            network_id BIGINT UNSIGNED NOT NULL,
            request_text VARCHAR(180) NOT NULL,
            output_text MEDIUMTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_terminal_agent (agent_id,command_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    };
    $validTerminalRequest = static function (string $raw): bool {
        if ($raw==='' || strlen($raw)>180 || preg_match('/[\x00-\x1f\x7f]/', $raw)) return false;
        $host='(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,90}[A-Za-z0-9])?|(?:[0-9]{1,3}\.){3}[0-9]{1,3})';
        return (bool)(preg_match('~^ping(?:\s+(?:-n\s+(?:[1-9]|10)|-t))?\s+'.$host.'$~iD',$raw)
            || preg_match('~^tracert(?:\s+-d)?\s+'.$host.'$~iD',$raw)
            || preg_match('~^nslookup\s+'.$host.'$~iD',$raw)
            || preg_match('~^(?:ipconfig(?:\s+/all|\s+/flushdns)?|arp\s+-a|route\s+print|netsh\s+wlan\s+show\s+interfaces|hostname)$~iD',$raw));
    };

    /* Canal de comandos: ações limitadas, agente aprovado, token da instalação. */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['agent-command-poll','agent-command-ack','agent-command-output','agent-config','agent-terminal-local-start'],true)) {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>8192) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $body=json_decode($raw,true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $fp=strtolower(trim((string)($body['fingerprint']??'')));
        $code=strtoupper(trim((string)($body['site_code']??'')));
        $key=trim((string)($body['download_key']??''));
        if (!preg_match('/^[a-f0-9]{64}$/',$fp) || !preg_match('/^[A-Z0-9]{5}$/',$code) || !preg_match('/^[a-f0-9]{64}$/',$key)) arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        $q=$pdo->prepare('SELECT a.id,a.network_id,a.account_id FROM '.arc_table('network_agents').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp]);$agent=$q->fetch();
        if (!$agent) arc_json(['ok'=>false,'error'=>'Agente sem autorização'],403);
        $agentId=(int)$agent['id'];
        if ($action==='agent-config') {
            $tabs=array_keys(arc_v16_agent_tabs());
            arc_v16_release_schema($pdo);
            $release=$pdo->query('SELECT r.id,r.version,r.sha256,r.file_size,r.created_at,m.notes FROM '.arc_table('releases').' r LEFT JOIN '.arc_table('agent_release_metadata')." m ON m.release_id=r.id WHERE r.platform='windows' AND r.is_active=1 AND (m.channel='stable' OR m.channel IS NULL) ORDER BY r.created_at DESC LIMIT 1")->fetch();
            $prefs=['monitor_refresh'=>'30','alert_latency'=>'150'];
            $prefQuery=$pdo->prepare('SELECT preference_key,preference_value FROM '.arc_table('network_user_preferences')." WHERE account_id=? AND network_id=? AND preference_key IN ('monitor_refresh','alert_latency')");
            $prefQuery->execute([(int)$agent['account_id'],(int)$agent['network_id']]);
            foreach($prefQuery->fetchAll(PDO::FETCH_ASSOC) as $pref){$prefs[(string)$pref['preference_key']]=(string)$pref['preference_value'];}
            $interval=$prefs['monitor_refresh']==='instant'?1:(int)$prefs['monitor_refresh'];
            if(!in_array($interval,[1,5,10,15,30,60],true))$interval=30;
            $latency=(int)$prefs['alert_latency'];
            if($latency<10||$latency>2000)$latency=150;
            // Último diagnóstico confirmado deste agente, isolado por conta e rede.
            $lastConnection=null;
            $diagQuery=$pdo->prepare('SELECT payload_json,UNIX_TIMESTAMP(captured_at) AS collected_at FROM '.arc_table('agent_telemetry')." WHERE account_id=? AND network_id=? AND agent_id=? AND event_type='diagnostic' ORDER BY id DESC LIMIT 30");
            $diagQuery->execute([(int)$agent['account_id'],(int)$agent['network_id'],$agentId]);
            foreach($diagQuery->fetchAll(PDO::FETCH_ASSOC) as $diagRow){
                $diag=json_decode((string)$diagRow['payload_json'],true);
                if(!is_array($diag))continue;
                $publicIp=trim((string)($diag['public_ip']??''));
                $provider=$diag['provider']??null;
                if(!filter_var($publicIp,FILTER_VALIDATE_IP)||!is_array($provider)||trim((string)($provider['isp']??''))==='')continue;
                $lastConnection=['public_ip'=>$publicIp,'provider'=>$provider,'interface'=>is_array($diag['interface']??null)?$diag['interface']:[], 'machine'=>(string)($diag['machine']??''),'os'=>(string)($diag['os']??''),'route'=>is_array($diag['route']??null)?array_slice($diag['route'],0,16):[],'captured_at'=>(int)($diag['captured_at']??$diagRow['collected_at']),'source'=>'platform_saved_diagnostic'];
                break;
            }
            arc_json(['ok'=>true,'allowed_tabs'=>$tabs,'latest_release'=>$release?:null,'poll_interval_seconds'=>30,'monitor_interval_seconds'=>$interval,'alert_latency_ms'=>$latency,'last_connection'=>$lastConnection,'device_overrides'=>arc_inventory_overrides($pdo,(int)$agent['account_id'],(int)$agent['network_id'],$agentId)]);
        }
        if ($action==='agent-terminal-local-start') {
            $rawCommand=trim((string)($body['request_text']??''));
            if (!$validTerminalRequest($rawCommand)) arc_json(['ok'=>false,'error'=>'Comando local não autorizado'],422);
            $expireRunningAgentCommands($pdo,$agentId);
            $ensureTerminalSchema($pdo);
            $active=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands')." WHERE agent_id=? AND status IN ('pending','running')");
            $active->execute([$agentId]);
            if((int)$active->fetchColumn()>=3) arc_json(['ok'=>false,'error'=>'Aguarde os diagnósticos anteriores'],429);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO '.arc_table('agent_commands')." (account_id,network_id,agent_id,command_name,status,dispatched_at) VALUES (?,?,?,'terminal_diagnostic','running',NOW())")
                    ->execute([(int)$agent['account_id'],(int)$agent['network_id'],$agentId]);
                $commandId=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')
                    ->execute([$commandId,$agentId,(int)$agent['network_id'],$rawCommand]);
                $pdo->commit();
            } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            arc_json(['ok'=>true,'command_id'=>$commandId]);
        }
        if ($action==='agent-command-output') {
            $id=(int)($body['command_id']??0);
            $chunk=(string)($body['chunk']??'');
            $hasOffset=array_key_exists('offset',$body);
            $offset=$hasOffset ? filter_var($body['offset'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>65536]]) : false;
            if($id<1 || $chunk==='' || strlen($chunk)>3500 || ($hasOffset && $offset===false)) arc_json(['ok'=>false,'error'=>'Trecho de saída inválido'],422);
            $ensureTerminalSchema($pdo);
            // O deslocamento em bytes impede que uma retransmissão duplique linhas.
            // Agentes antigos (sem offset) continuam aceitos durante a atualização.
            $sql='UPDATE '.arc_table('agent_terminal_jobs').' t INNER JOIN '.arc_table('agent_commands')." c ON c.id=t.command_id SET t.output_text=CONCAT(COALESCE(t.output_text,''),?),t.updated_at=NOW() WHERE t.command_id=? AND t.agent_id=? AND c.agent_id=? AND c.status='running' AND OCTET_LENGTH(COALESCE(t.output_text,'')) + ? <= 60000";
            if($hasOffset) $sql.=" AND OCTET_LENGTH(COALESCE(t.output_text,''))=?";
            $params=[$chunk,$id,$agentId,$agentId,strlen($chunk)];
            if($hasOffset) $params[]=$offset;
            $q=$pdo->prepare($sql);
            $q->execute($params);
            $received=$q->rowCount()>0;
            $check=$pdo->prepare('SELECT c.status,OCTET_LENGTH(COALESCE(t.output_text,\'\')) AS next_offset FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('agent_terminal_jobs')." t ON t.command_id=c.id WHERE c.id=? AND c.agent_id=? AND c.command_name='terminal_diagnostic' LIMIT 1");
            $check->execute([$id,$agentId]);
            $task=$check->fetch(PDO::FETCH_ASSOC);
            arc_json(['ok'=>true,'received'=>$received,'next_offset'=>(int)($task['next_offset']??0),'status'=>(string)($task['status']??'unknown')]);
        }
        if ($action==='agent-command-ack') {
            $id=(int)($body['command_id']??0);
            $status=(string)($body['status']??'');
            if ($id<1 || !in_array($status,['completed','failed'],true)) arc_json(['ok'=>false,'error'=>'Confirmação inválida'],422);
            $result=mb_substr((string)($body['result']??''),0,500);
            $q=$pdo->prepare('UPDATE '.arc_table('agent_commands').' SET status=?,result_text=?,completed_at=CURRENT_TIMESTAMP WHERE id=? AND agent_id=? AND status=\'running\'');
            $q->execute([$status,$result,$id,$agentId]);
            arc_json(['ok'=>true,'acknowledged'=>$q->rowCount()>0]);
        }
        $expireRunningAgentCommands($pdo, $agentId);
        $pdo->prepare('UPDATE '.arc_table('agent_commands').' SET status=\'expired\' WHERE agent_id=? AND status=\'pending\' AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)')->execute([$agentId]);
        // DDL provoca commit implícito em MySQL 5.7/8.x.
        // Garanta a tabela antes de iniciar a transação de retirada da fila.
        $ensureTerminalSchema($pdo);
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT c.id,c.command_name,COALESCE(t.request_text,\'\') AS request_text,COALESCE(a.headless_mode,0) AS headless_mode,c.account_id AS requester_user_id,CASE WHEN c.account_id=a.account_id THEN \'administrator\' ELSE \'unverified\' END AS requester_role,COALESCE(u.display_name,\'\') AS requester_name FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('agent_terminal_jobs').' t ON t.command_id=c.id LEFT JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id LEFT JOIN '.arc_table('user_accounts').' u ON u.id=c.account_id WHERE c.agent_id=? AND c.status=\'pending\' ORDER BY c.id ASC LIMIT 1 FOR UPDATE');
            $q->execute([$agentId]);$cmd=$q->fetch();
            if ($cmd) $pdo->prepare('UPDATE '.arc_table('agent_commands').' SET status=\'running\',dispatched_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$cmd['id']]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack();throw $e; }
        arc_json(['ok'=>true,'command'=>$cmd?:null]);
    }
    /* Interromper diagnóstico de forma autenticada e restrita ao agente selecionado. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='network-terminal-cancel') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        arc_verify_csrf();
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>2048) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $body=json_decode($raw,true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $networkId=(int)($body['network_id']??0);
        $agentId=(int)($body['agent_id']??0);
        $commandId=(int)($body['command_id']??0);
        if ($networkId<1 || $agentId<1 || $commandId<1) arc_json(['ok'=>false,'error'=>'Identificação inválida'],422);
        $q=$pdo->prepare('UPDATE '.arc_table('agent_commands').' c INNER JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id SET c.status=\'failed\',c.result_text=\'Interrompido pelo operador (Ctrl+C)\',c.completed_at=NOW() WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name=\'terminal_diagnostic\' AND c.status IN (\'pending\',\'running\')');
        $q->execute([$commandId,$agentId,$networkId,arc_user_id()]);
        if ($q->rowCount()>0) arc_user_audit($pdo,'agent_terminal_cancel','Interrupção do diagnóstico '.$commandId.' no agente '.$agentId);
        arc_json(['ok'=>true,'cancelled'=>$q->rowCount()>0]);
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='network-command') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        arc_verify_csrf();
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>4096) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $body=json_decode($raw,true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $networkId=(int)($body['network_id']??0);$command=(string)($body['command']??'');
        $allowed=['monitor_start','monitor_pause','speed_test','scan_devices','scan_map','diagnostic','toolkit','toolkit_traceroute','toolkit_services','toolkit_health','toolkit_troubleshooter','quick_health','dns_test','public_ip','system_snapshot','flush_dns','wifi_info','terminal_diagnostic','agent_update','remote_assistance'];
        if (!in_array($command,$allowed,true)) arc_json(['ok'=>false,'error'=>'Comando não permitido'],422);
        $terminalInput=trim((string)($body['terminal_input']??''));
        if ($command==='terminal_diagnostic' && !$validTerminalRequest($terminalInput)) arc_json(['ok'=>false,'error'=>'Comando de diagnóstico inválido. Digite ajuda para ver os comandos autorizados.'],422);
        $q=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1 AND monitoring_enabled=1');
        $q->execute([$networkId,arc_user_id()]);if (!$q->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede indisponível'],403);
        $agentId=(int)($body['agent_id']??0);
        $q=$pdo->prepare('SELECT id,machine_name,headless_mode FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=? AND status=\'approved\'');
        $q->execute([$agentId,$networkId,arc_user_id()]);$targetAgent=$q->fetch();
        if (!$targetAgent) arc_json(['ok'=>false,'error'=>'Selecione um agente aprovado'],403);
        if ($command==='agent_update') {
            $check=$pdo->prepare('SELECT last_seen_at FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=?');
            $check->execute([$agentId,$networkId,arc_user_id()]);
            $lastSeen=$check->fetchColumn();
            if (!$lastSeen || strtotime((string)$lastSeen)<time()-15) arc_json(['ok'=>false,'error'=>'Agente offline; atualização remota indisponível'],409);
        }
        $agentIsHeadless = (int)($targetAgent['headless_mode'] ?? 0) === 1;
        /* O proprietário já está autenticado na sessão e a ação exige token CSRF.
         * O acesso não assistido permanece restrito ao agente explicitamente habilitado.
         * Não aceitar perfil ou autorização enviados pelo cliente como prova de privilégio. */
        $expireRunningAgentCommands($pdo, $agentId);
        $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='expired',completed_at=NOW() WHERE agent_id=? AND status='pending' AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)")->execute([$agentId]);
        $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands').' WHERE agent_id=? AND status IN (\'pending\',\'running\')');
        $q->execute([$agentId]);if ((int)$q->fetchColumn()>=3) arc_json(['ok'=>false,'error'=>'Aguarde os comandos anteriores'],429);
        if ($command==='terminal_diagnostic') $ensureTerminalSchema($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO '.arc_table('agent_commands').' (account_id,network_id,agent_id,command_name) VALUES (?,?,?,?)')->execute([arc_user_id(),$networkId,$agentId,$command]);
            $insertedId=(int)$pdo->lastInsertId();
            if($command==='terminal_diagnostic'){
                $pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$insertedId,$agentId,$networkId,$terminalInput]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        if ($command==='remote_assistance') {
            arc_user_audit($pdo, $agentIsHeadless ? 'remote_access_auto_requested' : 'remote_access_requested',
                ($agentIsHeadless ? 'Acesso remoto automático (equipamento autorizado e sessão autenticada)' : 'Solicitação de acesso remoto com autorização local')
                .' ao agente '.(string)$targetAgent['machine_name'].' (ID '.$agentId.', comando '.$insertedId.') na rede '.$networkId);
        } else {
            arc_user_audit($pdo,'agent_command_queued','Comando '.$command.' para agente '.$agentId.' na rede '.$networkId.($command==='terminal_diagnostic'?' · '.mb_substr($terminalInput,0,100):''));
        }
        arc_json(['ok'=>true,'command_id'=>$insertedId,'status'=>'pending']);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-terminal-latest') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        $agentId=(int)($_GET['agent_id']??0);
        if($networkId<1 || $agentId<1) arc_json(['ok'=>false,'error'=>'Selecione um agente'],422);
        $ensureTerminalSchema($pdo);
        $q=$pdo->prepare('SELECT c.id,c.status,c.result_text,t.request_text FROM '.arc_table('agent_commands').' c
            JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id
            JOIN '.arc_table('agent_terminal_jobs')." t ON t.command_id=c.id
            WHERE c.agent_id=? AND c.network_id=? AND c.account_id=?
              AND c.command_name='terminal_diagnostic' AND c.created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY c.id DESC LIMIT 1");
        $q->execute([$agentId,$networkId,arc_user_id()]);
        arc_json(['ok'=>true,'command'=>$q->fetch(PDO::FETCH_ASSOC)?:null]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-terminal-status') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        $agentId=(int)($_GET['agent_id']??0);
        $commandId=(int)($_GET['command_id']??0);
        if($networkId<1 || $agentId<1 || $commandId<1) arc_json(['ok'=>false,'error'=>'Solicitação inválida'],422);
        $ensureTerminalSchema($pdo);
        $q=$pdo->prepare('SELECT c.id,c.status,c.result_text,c.completed_at,COALESCE(t.output_text,\'\') AS output_text FROM '.arc_table('agent_commands').' c INNER JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id LEFT JOIN '.arc_table('agent_terminal_jobs').' t ON t.command_id=c.id WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name=\'terminal_diagnostic\' LIMIT 1');
        $q->execute([$commandId,$agentId,$networkId,arc_user_id()]);
        $cmd=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cmd) arc_json(['ok'=>false,'error'=>'Diagnóstico não encontrado ou sem autorização'],404);
        $expireRunningAgentCommands($pdo,$agentId);
        if($cmd['status']==='running'){
           $fresh=$pdo->prepare('SELECT status,result_text FROM '.arc_table('agent_commands').' WHERE id=?');
           $fresh->execute([$commandId]);$c2=$fresh->fetch(PDO::FETCH_ASSOC);
           if($c2){$cmd['status']=$c2['status'];$cmd['result_text']=$c2['result_text'];}
        }
        // O navegador recebe somente os bytes novos, mantendo a ordem exata.
        $output=(string)($cmd['output_text']??'');
        $offset=max(0,min(strlen($output),(int)($_GET['offset']??0)));
        $cmd['output_chunk']=substr($output,$offset);
        $cmd['output_offset']=strlen($output);
        unset($cmd['output_text']);
        arc_json(['ok'=>true,'command'=>$cmd]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-command-status') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        $q=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=?');
        $q->execute([$networkId,arc_user_id()]);if (!$q->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede não encontrada'],403);
        $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Tempo limite do agente',completed_at=NOW() WHERE network_id=? AND account_id=? AND status='running' AND dispatched_at < DATE_SUB(NOW(), INTERVAL 4 MINUTE)")->execute([$networkId,arc_user_id()]);
        $q=$pdo->prepare('SELECT id,agent_id,command_name,status,result_text,created_at,completed_at FROM '.arc_table('agent_commands').' WHERE network_id=? AND account_id=? ORDER BY id DESC LIMIT 20');
        $q->execute([$networkId,arc_user_id()]);
        $commands=$q->fetchAll(PDO::FETCH_ASSOC);
        $agents=$pdo->prepare('SELECT id,UNIX_TIMESTAMP(last_seen_at) AS last_seen_unix,status FROM '.arc_table('network_agents').' WHERE network_id=? AND account_id=?');
        $agents->execute([$networkId,arc_user_id()]);
        arc_json(['ok'=>true,'commands'=>$commands,'agents'=>$agents->fetchAll(PDO::FETCH_ASSOC),'server_time'=>time()]);
    }

    /* Inventário editável, sempre restrito à titularidade da rede e ao agente aprovado. */
    if (in_array($action,['network-devices','network-device-save'],true)) {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        if ($action==='network-device-save' && $_SERVER['REQUEST_METHOD']!=='POST') arc_json(['ok'=>false,'error'=>'Método inválido'],405);
        $body=[];
        if ($action==='network-device-save') {
            arc_verify_csrf();
            $raw=file_get_contents('php://input');
            if (!is_string($raw) || strlen($raw)>5000) arc_json(['ok'=>false,'error'=>'Conteúdo inválido'],413);
            $body=json_decode($raw,true);
            if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        }
        $netId=(int)($body['network_id']??$_GET['network_id']??0);
        $agentId=(int)($body['agent_id']??$_GET['agent_id']??0);
        $owner=(int)arc_user_id();
        $check=$pdo->prepare('SELECT a.id FROM '.arc_table('network_agents').' a INNER JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id WHERE n.id=? AND a.id=? AND n.account_id=? AND a.status=\'approved\' AND n.is_active=1');
        $check->execute([$netId,$agentId,$owner]);
        if (!$check->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede ou agente não autorizado'],403);
        arc_inventory_schema($pdo);
        if ($action==='network-devices') {
            $rows=arc_inventory_list($pdo,$owner,$netId,$agentId);
            foreach ($rows as &$r) {
                $r['payload']=json_decode((string)($r['payload_json']??''),true) ?: [];
                unset($r['payload_json']);
            }
            unset($r);
            arc_json(['ok'=>true,'devices'=>$rows,'server_time'=>time()]);
        }
        $id=(int)($body['id']??0);
        if ($id<1) arc_json(['ok'=>false,'error'=>'Identificação inválida'],422);
        $allowed=['computer','phone','tablet','tv','router','printer','server','camera','iot','console','speaker','switch','ap','nas','device'];
        $type=strtolower(trim((string)($body['custom_type']??'')));
        if ($type!=='' && !in_array($type,$allowed,true)) arc_json(['ok'=>false,'error'=>'Tipo de equipamento não reconhecido'],422);
        $name=mb_substr(trim((string)($body['custom_name']??'')),0,160);
        $group=mb_substr(trim((string)($body['group_name']??'')),0,90);
        if (preg_match('/[\x00-\x1f]/',$name.$group)) arc_json(['ok'=>false,'error'=>'Texto inválido'],422);
        $pinned=!empty($body['pinned'])?1:0;
        $hidden=!empty($body['hidden'])?1:0;
        $q=$pdo->prepare('UPDATE '.arc_inventory_table().' SET custom_name=?,custom_type=?,group_name=?,pinned=?,hidden=?,managed=1 WHERE id=? AND account_id=? AND network_id=? AND agent_id=?');
        $q->execute([$name,$type,$group,$pinned,$hidden,$id,$owner,$netId,$agentId]);
        if (!$q->rowCount()) {
            $test=$pdo->prepare('SELECT id FROM '.arc_inventory_table().' WHERE id=? AND account_id=? AND network_id=? AND agent_id=?');
            $test->execute([$id,$owner,$netId,$agentId]);
            if (!$test->fetchColumn()) arc_json(['ok'=>false,'error'=>'Equipamento não encontrado'],404);
        }
        arc_json(['ok'=>true,'saved'=>true]);
    }

    /* API pública restrita do agente desktop: nenhum login web é exigido no primeiro cadastro. */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['agent-register','agent-poll','agent-data'], true)) {
        $rawAgent = file_get_contents('php://input');
        if (!is_string($rawAgent) || strlen($rawAgent) > 240000) arc_json(['ok'=>false,'error'=>'Solicitação inválida.'],413);
        $agentData = json_decode($rawAgent, true);
        if (!is_array($agentData)) arc_json(['ok'=>false,'error'=>'Dados do agente inválidos.'],400);
        $fingerprint = strtolower(trim((string)($agentData['fingerprint'] ?? '')));
        $siteCode = strtoupper(trim((string)($agentData['site_code'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !preg_match('/^[A-Z0-9]{5}$/', $siteCode)) arc_json(['ok'=>false,'error'=>'Informe um código de local e uma identificação válida deste computador.'],422);

        if ($action === 'agent-register') {
            $downloadKey = trim((string)($agentData['download_key'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $downloadKey)) arc_json(['ok'=>false,'error'=>'Chave de instalação ausente ou inválida. Baixe um novo pacote do agente pela página da rede.'],403);
            $keyLookup = $pdo->prepare('SELECT * FROM ' . arc_table('agent_download_keys') . ' WHERE token_hash=? LIMIT 1');
            $keyLookup->execute([hash('sha256', $downloadKey)]);
            $downloadKeyRow = $keyLookup->fetch();
            if (!$downloadKeyRow || (int)$downloadKeyRow['network_id'] < 1 || strtoupper((string)$downloadKeyRow['site_code']) !== $siteCode) arc_json(['ok'=>false,'error'=>'Esta chave não corresponde ao código local informado. Baixe um novo agente para esta rede.'],403);
            $findNetwork = $pdo->prepare('SELECT id,account_id,site_code,is_active,monitoring_enabled FROM ' . arc_table('managed_networks') . ' WHERE UPPER(site_code)=? LIMIT 1');
            $findNetwork->execute([$siteCode]);
            $networkRow = $findNetwork->fetch();
            if (!$networkRow || (int)$networkRow['is_active'] !== 1) arc_json(['ok'=>false,'error'=>'Código de local inválido ou rede inativa. Confira o código com o gestor.'],404);
            if (isset($networkRow['monitoring_enabled']) && (int)$networkRow['monitoring_enabled'] !== 1) arc_json(['ok'=>false,'error'=>'O monitoramento desta rede está desativado pelo gestor.'],403);
            if ((int)$downloadKeyRow['network_id'] !== (int)$networkRow['id'] || (int)$downloadKeyRow['account_id'] !== (int)$networkRow['account_id']) arc_json(['ok'=>false,'error'=>'A chave de instalação pertence a outra rede.'],403);
            $pdo->beginTransaction();
            try {
                $claim = $pdo->prepare('SELECT fingerprint,used_at FROM ' . arc_table('agent_download_keys') . ' WHERE id=? FOR UPDATE');
                $claim->execute([(int)$downloadKeyRow['id']]);
                $claimRow = $claim->fetch();
                if (!$claimRow) { $pdo->rollBack(); arc_json(['ok'=>false,'error'=>'Chave de instalação inválida.'],403); }
                $claimedFingerprint = strtolower(trim((string)($claimRow['fingerprint'] ?? '')));
                if ($claimedFingerprint !== '' && !hash_equals($claimedFingerprint, $fingerprint)) {
                    $pdo->rollBack(); arc_json(['ok'=>false,'error'=>'ESTA CHAVE JÁ FOI UTILIZADA EM OUTRO COMPUTADOR. Baixe um novo arquivo de agente para instalar em outra máquina.'],409);
                }
                if ($claimedFingerprint === '') {
                    $pdo->prepare('UPDATE ' . arc_table('agent_download_keys') . ' SET fingerprint=?,used_at=CURRENT_TIMESTAMP WHERE id=? AND fingerprint IS NULL')->execute([$fingerprint,(int)$downloadKeyRow['id']]);
                }
                $pdo->commit();
            } catch (Throwable $keyError) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $keyError;
            }
            $existing = $pdo->prepare('SELECT * FROM ' . arc_table('network_agents') . ' WHERE fingerprint=? LIMIT 1');
            $existing->execute([$fingerprint]);
            $agentRow = $existing->fetch();
            if ($agentRow && ((int)$agentRow['network_id'] !== (int)$networkRow['id'] || (int)$agentRow['account_id'] !== (int)$networkRow['account_id'])) {
                arc_json(['ok'=>false,'error'=>'Este computador já está vinculado a outro local. Solicite a revisão do gestor.'],409);
            }
            $machine = mb_substr(trim((string)($agentData['machine_name'] ?? 'Computador')),0,190);
            $platform = mb_substr(trim((string)($agentData['platform'] ?? 'Windows')),0,190);
            $localIp = filter_var((string)($agentData['local_ip'] ?? ''), FILTER_VALIDATE_IP) ? (string)$agentData['local_ip'] : '';
            $gatewayIp = filter_var((string)($agentData['gateway_ip'] ?? ''), FILTER_VALIDATE_IP) ? (string)$agentData['gateway_ip'] : '';
            $version = mb_substr(trim((string)($agentData['version'] ?? '')),0,32);
            $machineDetails = $agentData['machine_details'] ?? [];
            if (!is_array($machineDetails)) $machineDetails = [];
            $machineDetails = json_encode(array_slice($machineDetails, 0, 30, true), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($machineDetails)) $machineDetails = '{}';
            $machineDetails = mb_substr($machineDetails, 0, 12000);
            if (!$agentRow) {
                $pdo->prepare('INSERT INTO ' . arc_table('network_agents') . ' (fingerprint,account_id,network_id,site_code,machine_name,platform,local_ip,gateway_ip,agent_version,machine_details,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$fingerprint,(int)$networkRow['account_id'],(int)$networkRow['id'],$siteCode,$machine,$platform,$localIp,$gatewayIp,$version,$machineDetails,'pending']);
                $agentId = (int)$pdo->lastInsertId();
                arc_user_audit($pdo,'network_agent_requested','Novo agente aguardando aprovação: '.$machine.' ('.$siteCode.')',(int)$networkRow['account_id']);
                $agentStatus = 'pending';
            } else {
                $agentId = (int)$agentRow['id'];
                $agentStatus = (string)$agentRow['status'];
                $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET machine_name=?,platform=?,local_ip=?,gateway_ip=?,agent_version=?,machine_details=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$machine,$platform,$localIp,$gatewayIp,$version,$machineDetails,$agentId]);
            }
            arc_json(['ok'=>true,'status'=>$agentStatus,'agent_id'=>$agentId,'message'=>match($agentStatus){'approved'=>'Agente autorizado.','blocked'=>'Agente bloqueado pelo gestor.','rejected'=>'Cadastro rejeitado pelo gestor.',default=>'Solicitação enviada. Aguarde a aprovação do gestor da rede.'}]);
        }

        if ($action === 'agent-poll') {
        $poll = $pdo->prepare('SELECT a.status,a.id,a.network_id,n.name AS network_name,n.is_active,n.monitoring_enabled FROM ' . arc_table('network_agents') . ' a JOIN ' . arc_table('managed_networks') . ' n ON n.id=a.network_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? LIMIT 1');
        $poll->execute([$fingerprint,$siteCode]);
        $agentRow = $poll->fetch();
        if (!$agentRow) arc_json(['ok'=>false,'status'=>'unregistered','error'=>'Agente ainda não cadastrado para este local.'],404);
        if (((int)$agentRow['is_active'] !== 1 || (int)$agentRow['monitoring_enabled'] !== 1) && $agentRow['status'] === 'approved') arc_json(['ok'=>true,'status'=>'blocked','message'=>'A rede está inativa ou com monitoramento desativado.']);
        $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$agentRow['id']]);
        $status = (string)$agentRow['status'];
        arc_json(['ok'=>true,'status'=>$status,'network_name'=>(string)$agentRow['network_name'],'message'=>match($status){'approved'=>'Agente autorizado.','blocked'=>'Acesso bloqueado pelo gestor.','rejected'=>'Cadastro rejeitado pelo gestor.',default=>'Aguardando aprovação do gestor da rede.'}]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'agent-data') {
        $downloadKey = trim((string)($agentData['download_key'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $downloadKey)) arc_json(['ok'=>false,'error'=>'Chave do agente inválida.'],403);
        $auth = $pdo->prepare('SELECT a.id,a.account_id,a.network_id,a.site_code,a.status,n.is_active,n.monitoring_enabled,k.fingerprint AS key_fingerprint FROM ' . arc_table('network_agents') . ' a JOIN ' . arc_table('managed_networks') . ' n ON n.id=a.network_id JOIN ' . arc_table('agent_download_keys') . ' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND k.token_hash=? AND k.fingerprint=? ORDER BY k.id DESC LIMIT 1');
        $auth->execute([$fingerprint,$siteCode,hash('sha256',$downloadKey),$fingerprint]);
        $authorized = $auth->fetch();
        if (!$authorized || $authorized['status'] !== 'approved' || (int)$authorized['is_active'] !== 1 || (int)$authorized['monitoring_enabled'] !== 1) arc_json(['ok'=>false,'error'=>'Agente não autorizado ou monitoramento desativado.'],403);
        $event = $agentData['event'] ?? [];
        if (!is_array($event)) arc_json(['ok'=>false,'error'=>'Evento inválido.'],422);
        $eventType = mb_substr(trim((string)($event['event'] ?? 'telemetry')),0,60);
        $payload = json_encode(array_slice($event,0,100,true),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) $payload='{}';
        if (strlen($payload)>210000) arc_json(['ok'=>false,'error'=>'Dados de monitoramento excedem o limite.'],413);
        // Reduz a escrita no histórico sem atrasar a sincronização do inventário.
        // Alertas, comandos, testes e mudanças importantes continuam registrados integralmente.
        $storeEvent = true;
        $agentId = (int)$authorized['id'];
        if ($eventType === 'device_event') {
            // Somente os dispositivos alterados; nunca substitui o inventário inteiro.
            // O token já foi verificado e os IDs vêm da autorização, não do payload.
            if (!isset($event['devices']) || !is_array($event['devices']) || count($event['devices']) > 40) {
                arc_json(['ok'=>false,'error'=>'Lote de dispositivos inválido.'],422);
            }
            arc_inventory_sync($pdo,(int)$authorized['account_id'],(int)$authorized['network_id'],$agentId,$event);
            // O inventário é a visão corrente; não duplica cada atualização na telemetria.
            $storeEvent = false;
        } elseif ($eventType === 'network_scan') {
            // O inventário operacional é atualizado em TODAS as sincronizações.
            arc_inventory_sync($pdo,(int)$authorized['account_id'],(int)$authorized['network_id'],$agentId,$event);
            // Ignore os relógios de detecção/last_seen: não representam mudança de topologia.
            $normalized = [];
            foreach ((array)($event['devices'] ?? []) as $device) {
                if (!is_array($device)) continue;
                $key = strtolower((string)(($device['mac'] ?? '') ?: (($device['id'] ?? '') ?: ($device['ip'] ?? ''))));
                if ($key === '') continue;
                $normalized[$key] = [
                    (string)($device['ip'] ?? ''),
                    strtolower((string)($device['mac'] ?? '')),
                    (string)($device['status'] ?? ''),
                    (string)($device['name'] ?? ''),
                    (string)($device['type'] ?? '')
                ];
            }
            ksort($normalized);
            $stateHash = hash('sha256',json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            // Só compara com a última fotografia do próprio agente; nenhum dado de outra conta.
            $last = $pdo->prepare('SELECT payload_json,captured_at FROM '.arc_table('agent_telemetry')." WHERE agent_id=? AND event_type='network_scan' ORDER BY id DESC LIMIT 1");
            $last->execute([$agentId]);
            $previous = $last->fetch(PDO::FETCH_ASSOC);
            if ($previous && strtotime((string)$previous['captured_at']) > time()-3600) {
                $prior = json_decode((string)$previous['payload_json'],true);
                $priorHash = (string)($prior['_inventory_state_hash'] ?? '');
                if ($priorHash !== '' && hash_equals($priorHash,$stateHash)) $storeEvent = false;
            }
            $event['_inventory_state_hash'] = $stateHash;
            $payload = json_encode(array_slice($event,0,100,true),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($payload)) $payload = '{}';
        } elseif ($eventType === 'remote_access_monitor_detected') {
            /* O agente só envia isto quando o acesso automático (sem confirmação local) foi usado
             * e, mesmo assim, encontrou um monitor físico ligado na máquina marcada como "sem monitor".
             * Fica bem visível na auditoria para o gestor conferir quem ligou um monitor nesse servidor. */
            arc_user_audit($pdo,'remote_access_monitor_detected',
                'Monitor físico detectado durante acesso remoto automático no agente '.$agentId.' (comando '.(int)($event['command_id']??0).'). Verifique se alguém está no local sem autorização.',
                (int)$authorized['account_id']);
        } elseif ($eventType === 'telemetry' || $eventType === 'dashboard') {
            // Grava até uma amostra a cada 3 minutos por agente/tipo; alertas não são limitados.
            $recent = $pdo->prepare('SELECT id FROM '.arc_table('agent_telemetry')." WHERE agent_id=? AND event_type=? AND captured_at >= (CURRENT_TIMESTAMP - INTERVAL 180 SECOND) ORDER BY id DESC LIMIT 1");
            $recent->execute([$agentId,$eventType]);
            $storeEvent = !$recent->fetchColumn();
        }
        if ($storeEvent) {
            $save = $pdo->prepare('INSERT INTO '.arc_table('agent_telemetry').' (account_id,network_id,agent_id,site_code,event_type,payload_json) VALUES (?,?,?,?,?,?)');
            $save->execute([(int)$authorized['account_id'],(int)$authorized['network_id'],$agentId,$siteCode,$eventType,$payload]);
        }
        // Limpeza antiga de 3 horas para server_metrics mantida, mas em lotes pequenos.
        // A rotina cron de manutenção cuida da limpeza controlada e das demais classes.
        if ($eventType === 'server_metrics' && random_int(1,25) === 1) {
            try {
                $prune=$pdo->prepare('DELETE FROM '.arc_table('agent_telemetry')." WHERE agent_id=? AND event_type='server_metrics' AND captured_at < (CURRENT_TIMESTAMP - INTERVAL 3 HOUR) ORDER BY id LIMIT 200");
                $prune->execute([$agentId]);
            } catch (Throwable $pruneError) { error_log('Arcoll server_metrics prune: '.$pruneError->getMessage()); }
        }
        $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$authorized['id']]);
        arc_json(['ok'=>true,'stored'=>$storeEvent,'message'=>'Dados sincronizados com o banco de dados.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        arc_json(['ok' => false, 'error' => 'Operação não encontrada.'], 404);
    }
    if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Faça login para continuar.'],401);
    try { arc_require_user_network($pdo); } catch (Throwable $e) { arc_json(['ok'=>false,'error'=>'Selecione uma rede gerenciada.'],403); }
    arc_verify_csrf();
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 2_000_000) {
        arc_json(['ok' => false, 'error' => 'O envio excede o limite permitido.'], 413);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        arc_json(['ok' => false, 'error' => 'O conteúdo enviado não é válido.'], 400);
    }
    if (!isset($payload['networkId']) || (int)$payload['networkId'] !== (int)arc_current_network_id()) {
        arc_json(['ok'=>false,'error'=>'A rede selecionada mudou. Reabra o painel da rede correta.'],409);
    }

    if ($action === 'pwa-install') {
        $installationId = strtolower(trim((string)($payload['installationId'] ?? '')));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $installationId)) {
            arc_json(['ok' => false, 'error' => 'Identificador da instalação inválido.'], 422);
        }
        $platform = mb_substr(trim((string)($payload['platform'] ?? 'web')), 0, 32);
        if (!preg_match('/^[a-zA-Z0-9 _.-]+$/', $platform)) $platform = 'web';
        $label = mb_substr(trim((string)($payload['deviceLabel'] ?? 'Dispositivo PWA')), 0, 160);
        if ($label === '') $label = 'Dispositivo PWA';
        $networkId = (int)arc_current_network_id();
        $check = $pdo->prepare('SELECT id FROM ' . arc_table('managed_networks') . ' WHERE id=? AND account_id=? AND is_active=1');
        $check->execute([$networkId, arc_user_id()]);
        if (!$check->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede não autorizada.'],403);
        $save = $pdo->prepare('INSERT INTO ' . arc_table('pwa_installations') . ' (installation_id,account_id,network_id,device_label,platform,user_agent,installed_at,last_seen_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),network_id=VALUES(network_id),device_label=VALUES(device_label),platform=VALUES(platform),user_agent=VALUES(user_agent),last_seen_at=CURRENT_TIMESTAMP');
        $save->execute([$installationId, arc_user_id(), $networkId, $label, $platform, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
        arc_user_audit($pdo, 'pwa_install_registered', 'Instalação PWA vinculada à rede ID ' . $networkId);
        arc_json(['ok'=>true,'registered'=>true]);
    }

    if ($action === 'save-state') {
        $pdo->beginTransaction();
        arc_persist_state($pdo, $payload);
        $pdo->commit();
        arc_json(['ok' => true]);
    }

    if ($action === 'save-fields') {
        $values = $payload['values'] ?? null;
        if (!is_array($values) || count($values) > 200) {
            arc_json(['ok' => false, 'error' => 'Lista de campos inválida.'], 400);
        }
        $pdo->beginTransaction();
        $lookup = $pdo->prepare(
            'SELECT field_type, is_required, options_json FROM ' . arc_table('page_fields') . ' WHERE id=? AND is_enabled=1'
        );
        foreach ($values as $id => $value) {
            $id = (int)$id;
            if ($id < 1) {
                throw new InvalidArgumentException('Identificador de campo inválido.');
            }
            $lookup->execute([$id]);
            $field = $lookup->fetch();
            if (!$field) {
                throw new InvalidArgumentException('Um dos campos não está mais disponível.');
            }
            $kind = (string)$field['field_type'];
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Informe um valor simples para cada campo.');
            }
            $value = is_bool($value) ? $value : trim((string)$value);
            if (!empty($field['is_required']) && ($value === '' || $value === false || $value === null)) {
                throw new InvalidArgumentException('Preencha todos os campos obrigatórios.');
            }
            if ($kind === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Um endereço de e-mail está inválido.');
            }
            if ($kind === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Uma URL informada está inválida.');
            }
            if ($kind === 'number' && $value !== '' && !is_numeric($value)) {
                throw new InvalidArgumentException('Informe um número válido.');
            }
            if ($kind === 'choice') {
                $choices = json_decode((string)($field['options_json'] ?? '[]'), true);
                if (is_array($choices) && $value !== '' && !in_array((string)$value, array_map('strval', $choices), true)) {
                    throw new InvalidArgumentException('Uma opção selecionada não é válida.');
                }
            }
            if (is_string($value) && mb_strlen($value) > 10000) {
                throw new InvalidArgumentException('Um campo excede o tamanho permitido.');
            }
        }
        arc_save_field_values($pdo, $values);
        $pdo->commit();
        arc_json(['ok' => true]);
    }

    arc_json(['ok' => false, 'error' => 'Operação não encontrada.'], 404);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    arc_json(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Arcoll API error: ' . $exception->getMessage());
    arc_json(['ok' => false, 'error' => 'Não foi possível carregar ou salvar os dados. Revise a conexão com o banco.'], 500);
}