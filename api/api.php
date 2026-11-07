<?php
// Caminho: api/api.php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/network-inventory.php';
require __DIR__ . '/../includes/command-diagnostics.php';

try {
    $pdo = arc_db();
    arc_ensure_user_schema($pdo);
    $action = (string)($_GET['action'] ?? '');
    if($_SERVER['REQUEST_METHOD']==='GET'&&$action==='release-info')arc_json(['ok'=>true,'version'=>arc_app_version()]);
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
            || !preg_match('/^[a-f0-9]{64}$/',$key) || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))
            || strlen($email)>254 || strlen($password)>512 || $password==='') {
            arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        }
        $loginAttemptId = arc_login_attempt_begin($pdo, 'agent-owner:'.$fp);
        if ($loginAttemptId === null) {
            header('Retry-After: 900');
            arc_json(['ok'=>false,'error'=>'Muitas tentativas. Tente novamente em 15 minutos'],429);
        }
        $q=$pdo->prepare('SELECT u.id,u.password_hash,u.account_status,n.account_id AS owner_id,n.id AS network_id FROM '
            .arc_table('network_agents').' a '
            .'JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id AND n.is_active=1 '
            .'JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id '
            .'JOIN '.arc_table('user_accounts').' u ON (u.id=n.account_id OR EXISTS ('
            .'SELECT 1 FROM '.arc_table('network_team_members').' m WHERE m.network_id=n.id AND m.account_id=n.account_id AND m.member_user_id=u.id AND m.enabled=1)) '
            .'WHERE a.status=\'approved\' AND a.fingerprint=? AND UPPER(a.site_code)=? AND k.token_hash=? AND k.fingerprint=? '
            .'AND ((?=\'\' AND u.id=n.account_id) OR (?<>\'\' AND u.email_hash=?)) LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp,$email,$email,$email!==''?hash('sha256',$email):'']);
        $user=$q->fetch(PDO::FETCH_ASSOC);
        if ($user && (int)$user['id']!==(int)$user['owner_id']) {
            $rights=arc_network_profile_rights($pdo,(int)$user['network_id'],(int)$user['id']);
            if ($rights===null || !in_array('agent_local_login',$rights,true)) $user=false;
        }
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
    // Revogação definitiva de uma instalação, autorizada pelo gestor da própria rede.
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='agent-uninstall-authorize') {
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
            || !preg_match('/^[a-f0-9]{64}$/',$key) || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))
            || strlen($email)>254 || $password==='' || strlen($password)>512)
            arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
        $attempt=arc_login_attempt_begin($pdo,'agent-owner:'.$fp);
        if ($attempt===null) arc_json(['ok'=>false,'error'=>'Muitas tentativas. Tente novamente mais tarde'],429);
        $q=$pdo->prepare('SELECT u.id,u.password_hash,u.account_status,a.id AS agent_id,a.network_id '
            .'FROM '.arc_table('user_accounts').' u '
            .'INNER JOIN '.arc_table('managed_networks').' n ON n.account_id=u.id AND n.is_active=1 '
            .'INNER JOIN '.arc_table('network_agents').' a ON a.network_id=n.id AND a.account_id=u.id AND a.status=\'approved\' '
            .'INNER JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id '
            .'WHERE (?=0 OR u.email_hash=?) AND a.fingerprint=? AND UPPER(a.site_code)=? AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$email===''?0:1,$email!==''?hash('sha256',$email):'', $fp,$code,hash('sha256',$key),$fp]);
        $user=$q->fetch(PDO::FETCH_ASSOC);
        $material=base64_encode(hash('sha384',$password,true));
        if (!$user || (string)$user['account_status']!=='active' ||
            !(password_verify($material,(string)$user['password_hash']) || password_verify($password,(string)$user['password_hash'])))
            arc_json(['ok'=>false,'error'=>'Gestor não autorizado'],403);
        arc_login_attempt_succeeded($pdo,$attempt);
        $pdo->beginTransaction();
        try {
            $update=$pdo->prepare('UPDATE '.arc_table('network_agents').' SET status=\'blocked\',ui_locked=0,uninstall_requested=0,notes=? WHERE id=? AND account_id=? AND network_id=? AND status=\'approved\'');
            $uninstallInfo='DESINSTALADO|'.date('Y-m-d H:i:s').'|'.(int)$user['id'];
            $update->execute([$uninstallInfo,(int)$user['agent_id'],(int)$user['id'],(int)$user['network_id']]);
            if ($update->rowCount()!==1) throw new RuntimeException('Agente não está mais autorizado');
            $del=$pdo->prepare('DELETE FROM '.arc_table('agent_download_keys').' WHERE account_id=? AND network_id=? AND fingerprint=?');
            $del->execute([(int)$user['id'],(int)$user['network_id'],$fp]);
            $pdo->commit();
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            arc_json(['ok'=>false,'error'=>'Não foi possível revogar o agente'],409);
        }
        arc_user_audit($pdo,'agent_uninstall','Agente revogado para desinstalação: '.$code.' / '.$fp,(int)$user['id']);
        header('Cache-Control: no-store, private');
        arc_json(['ok'=>true,'status'=>'revoked']);
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
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['agent-update-package','agent-installer-package'],true)) {
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
        try{arc_require_aligned_agent_release();}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e->getMessage(),'error_code'=>'agent_release_incomplete'],503);}
        if($action==='agent-installer-package')arc_json(arc_agent_installer_package());
        // Hash and content are generated from the same validated bytes as the installer.
        try{$sources=arc_agent_release_sources();}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e->getMessage(),'error_code'=>'agent_release_incomplete'],503);}
        $package=[];
        foreach($sources as $name=>$contents)$package[]=['name'=>$name,'sha256'=>hash('sha256',$contents),'content'=>base64_encode($contents)];
        $publishedVersion=arc_app_version();
        $releases=$pdo->prepare('SELECT notes FROM '.arc_table('platform_release_history').' WHERE version=?');$releases->execute([$publishedVersion]);$releaseNotes=(string)($releases->fetchColumn()?:'');
        header('Cache-Control: no-store, private');
        arc_json(['ok'=>true,'version'=>$publishedVersion,'notes'=>mb_substr($releaseNotes,0,3000),'files'=>$package]);
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
        // A presenca do worker protegido nao substitui a versao do aplicativo atualizado.
        if (($v['mode']??'')==='boot_presence') $reportedVersion='';
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
        $access=arc_network_team_access($pdo,$networkId,(int)arc_user_id());
        if(!$access)arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403);
        $release=arc_agent_release_status();$latest=arc_app_version();
        $query=$pdo->prepare('SELECT id,machine_name,agent_version,last_seen_at,status FROM '.arc_table('network_agents').' WHERE network_id=? AND account_id=?');
        $query->execute([$networkId,$access['account_id']]);$agents=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $version=(string)($row['agent_version']??'');
            $online=$row['status']==='approved' && !empty($row['last_seen_at']) && strtotime((string)$row['last_seen_at'])>=time()-15;
            $policy=arc_agent_upgrade_policy($version,(string)$latest);
            $agents[]=['requires_reinstall'=>$policy['requires_reinstall'],'can_remote_update'=>$policy['can_remote_update'],'migrate_native'=>$policy['migrate_native'],'update_message'=>$policy['message'],'id'=>(int)$row['id'],'name'=>(string)$row['machine_name'],'version'=>$version,'online'=>$online,'approved'=>$row['status']==='approved','outdated'=>$latest!==null&&preg_match('/^\d+\.\d+\.\d+$/',(string)$latest)&&($version===''||!preg_match('/^\d+\.\d+\.\d+$/',$version)||version_compare($version,$latest,'<'))];
        }
        // Comentário publicado na área administrativa; dados restritos à rede autorizada.
        $notesQuery=$pdo->prepare('SELECT notes FROM '.arc_table('platform_release_history').' WHERE version=? LIMIT 1');
        $notesQuery->execute([$latest]);$releaseNotes=(string)($notesQuery->fetchColumn()?:'');
        arc_json(['ok'=>true,'published_version'=>$latest,'release_notes'=>$releaseNotes,'can_update'=>in_array($access['role'],['owner','administrator'],true),'display_agent_version'=>arc_app_version(),'support_version'=>arc_app_version(),'access_version'=>arc_app_version(),'agents'=>$agents,'release'=>$release]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'health') {
        arc_json(['ok' => true, 'application' => (string)(arc_setting_values($pdo)['site_name'] ?? 'Agente de Rede'), 'version' => arc_app_version()]);
    }

    /* Regra única de expiração dos comandos em execução, compartilhada entre
     * a consulta do agente e o envio de comandos pela plataforma. */


    /* Terminal: fila existente + resultados parciais por tarefa. Sem acesso a shell irrestrito. */
    $validTerminalRequest = static function (string $raw): bool {
        if ($raw==='' || strlen($raw)>180 || preg_match('/[\x00-\x1f\x7f]/', $raw)) return false;
        $host='(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,90}[A-Za-z0-9])?|(?:[0-9]{1,3}\.){3}[0-9]{1,3})';
        return (bool)(preg_match('~^ping(?:\s+(?:-n\s+(?:[1-9]|10)|-t))?\s+'.$host.'$~iD',$raw)
            || preg_match('~^tracert(?:\s+-d)?\s+'.$host.'$~iD',$raw)
            || preg_match('~^nslookup\s+'.$host.'$~iD',$raw)
            || preg_match('~^(?:ipconfig(?:\s+/all|\s+/flushdns)?|arp\s+-a|route\s+print|netsh\s+wlan\s+show\s+interfaces|hostname)$~iD',$raw));
    };

    /* Pastas v3.6.0: mensagens autenticadas por vínculo aprovado e perfil do gestor. */
    if($action==='network-file-browser-start' && $_SERVER['REQUEST_METHOD']==='POST'){
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        arc_verify_csrf();$data=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($data))arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $network=(int)($data['network_id']??0);$agentId=(int)($data['agent_id']??0);$path=(string)($data['path']??'');
        if($network<1||$agentId<1||strlen($path)>1024||preg_match('/[\x00-\x1f]/',$path))arc_json(['ok'=>false,'error'=>'Diretório inválido'],422);
        try{$access=arc_support_access($pdo,$network,(int)arc_user_id(),true);}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Sem acesso'],403);}
        if(!in_array($access['role'],['owner','administrator'],true)&&$path!=='')arc_json(['ok'=>false,'error'=>'Seu perfil só pode consultar discos'],403);
        $account=(int)$access['account_id'];
        $q=$pdo->prepare('SELECT id,headless_mode,last_seen_at FROM '.arc_table('network_agents')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$agentId,$network,$account]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if(!$a||(int)$a['headless_mode']!==1)arc_json(['ok'=>false,'error'=>'Ative o modo sem monitor no agente autorizado'],403);
        if(!$a['last_seen_at']||strtotime($a['last_seen_at'])<time()-120)arc_json(['ok'=>false,'error'=>'Agente desconectado'],409);
        
        $count=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands')." WHERE agent_id=? AND status IN ('pending','running')");$count->execute([$agentId]);if((int)$count->fetchColumn()>=3)arc_json(['ok'=>false,'error'=>'Há outros comandos em andamento'],429);
        $pdo->beginTransaction();try{
            $pdo->prepare('INSERT INTO '.arc_table('agent_commands')." (account_id,network_id,agent_id,command_name) VALUES (?,?,?,'file_browser')")->execute([$account,$network,$agentId]);$cmd=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$cmd,(int)arc_user_id()]);
            $pdo->prepare('INSERT INTO '.arc_table('file_browser_requests').' (command_id,network_id,account_id,agent_id,requested_by,path_text) VALUES (?,?,?,?,?,?)')->execute([$cmd,$network,$account,$agentId,(int)arc_user_id(),$path]);$request=(int)$pdo->lastInsertId();$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        arc_user_audit($pdo,'file_browser_start','Listagem de diretório solicitada no agente '.$agentId);
        arc_json(['ok'=>true,'request_id'=>$request]);
    }
    if($action==='network-file-browser-status' && $_SERVER['REQUEST_METHOD']==='GET'){
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        $network=(int)($_GET['network_id']??0);$id=(int)($_GET['request_id']??0);
        try{$access=arc_support_access($pdo,$network,(int)arc_user_id(),true);}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Sem acesso'],403);}
        // A listagem de discos para outros perfis é somente leitura; requisições de diretórios continuam restritas na criação.
        
        $q=$pdo->prepare('SELECT r.status,r.result_json,c.status AS command_status,c.result_text FROM '.arc_table('file_browser_requests').' r JOIN '.arc_table('agent_commands').' c ON c.id=r.command_id WHERE r.id=? AND r.network_id=? AND r.account_id=? AND r.requested_by=?');
        $q->execute([$id,$network,(int)$access['account_id'],(int)arc_user_id()]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)arc_json(['ok'=>false,'error'=>'Listagem não encontrada'],404);
        $result=json_decode((string)($row['result_json']??''),true);
        arc_json(['ok'=>true,'status'=>$row['status']==='pending'?$row['command_status']:$row['status'],'data'=>is_array($result)?$result:null,'message'=>(string)$row['result_text']]);
    }
    /* Programas e Clean: consultas e solicitações pela fila autenticada. Categorias, prazos e limites
       vêm de includes/network-clean.php (fonte única, também usada pelas telas e pelo agente). */
    if (in_array($action,['network-program-list','network-program-start','network-program-status','network-clean-list','network-clean-start','network-clean-status','network-clean-report','network-clean-tool','network-clean-settings','network-clean-settings-save'],true)) {
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        if($_SERVER['REQUEST_METHOD']==='POST')arc_verify_csrf();
        $body=$_SERVER['REQUEST_METHOD']==='POST'?json_decode((string)file_get_contents('php://input'),true):$_GET;
        if(!is_array($body))arc_json(['ok'=>false,'error'=>'Requisição inválida'],400);
        $network=(int)($body['network_id']??0);$agentId=(int)($body['agent_id']??0);
        $isClean=str_starts_with($action,'network-clean-');
        $names=arc_network_operation_commands()[$isClean?'clean':'program'];
        $access=arc_network_team_access($pdo,$network,(int)arc_user_id());
        if(!$access||!in_array($access['role'],['owner','administrator'],true))arc_json(['ok'=>false,'error'=>'Permissão administrativa necessária'],403);
        $rights=arc_network_profile_rights($pdo,$network,(int)arc_user_id());
        if($access['role']!=='owner'&&$rights!==null&&!in_array($isClean?'clean':'programas',$rights,true))arc_json(['ok'=>false,'error'=>$isClean?'Permissão Clean indisponível':'Permissão de programas indisponível'],403);
        $account=(int)$access['account_id'];
        $q=$pdo->prepare('SELECT id,headless_mode,last_seen_at FROM '.arc_table('network_agents')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");
        $q->execute([$agentId,$network,$account]);$machine=$q->fetch(PDO::FETCH_ASSOC);
        if(!$machine)arc_json(['ok'=>false,'error'=>'Agente não aprovado'],404);
        $online=!empty($machine['last_seen_at'])&&strtotime((string)$machine['last_seen_at'])>=time()-120;
        if($action==='network-clean-list'){
            $row=arc_clean_report_get($pdo,$agentId,$network,$account,'clean');
            arc_json(['ok'=>true,'report'=>$row['report']??null,'collected_at'=>$row['collected_at']??null,'online'=>$online,'headless'=>(int)$machine['headless_mode']===1,'history'=>arc_clean_history($pdo,$agentId,$network,$account),'active'=>arc_network_active_operation($pdo,$agentId,$network,$account,$names),'settings'=>arc_clean_settings_get($pdo,$agentId,$network,$account),'index'=>arc_clean_report_index($pdo,$agentId,$network,$account)]);
        }
        if($action==='network-clean-report'){
            $kind=(string)($body['kind']??'');
            if(!isset(arc_clean_tools()[$kind]))arc_json(['ok'=>false,'error'=>'Ferramenta inválida'],422);
            $row=arc_clean_report_get($pdo,$agentId,$network,$account,$kind);
            arc_json(['ok'=>true,'report'=>$row['report']??null,'collected_at'=>$row['collected_at']??null]);
        }
        if($action==='network-clean-settings'){
            arc_json(['ok'=>true,'settings'=>arc_clean_settings_get($pdo,$agentId,$network,$account)]);
        }
        if($action==='network-clean-settings-save'){
            if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
            try{$settings=arc_clean_validate_settings($body);}catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
            arc_clean_settings_save($pdo,$agentId,$network,$account,$settings);
            arc_user_audit($pdo,'clean_settings','Configurações do Clean da máquina '.$agentId.' ('.count($settings['exclusions']).' exclusões, '.count($settings['custom_folders']).' pastas)');
            arc_json(['ok'=>true,'settings'=>$settings]);
        }
        if($action==='network-program-list'){
            /* Expire comandos abandonados antes de reabrir a tela; não bloqueie novas operações com estado pendente antigo. */
            arc_expire_running_commands($pdo,$agentId);
            /* Programas aguardam a sua vez na fila persistente. */
            $q=$pdo->prepare('SELECT programs_json,collected_at FROM '.arc_table('agent_software_inventory').' WHERE agent_id=? AND network_id=? AND account_id=?');
            $q->execute([$agentId,$network,$account]);$row=$q->fetch(PDO::FETCH_ASSOC);
            $programs=$row?json_decode((string)$row['programs_json'],true):[];
            arc_json(['ok'=>true,'programs'=>is_array($programs)?$programs:[],'collected_at'=>$row['collected_at']??null,'online'=>$online,'headless'=>(int)$machine['headless_mode']===1,'batch_max'=>ARC_PROGRAM_BATCH_MAX,'install_catalog'=>arc_program_install_catalog(),'active'=>arc_network_active_operation($pdo,$agentId,$network,$account,$names)]);
        }
        if(in_array($action,['network-program-status','network-clean-status'],true)){
            arc_expire_running_commands($pdo,$agentId);
            $id=(int)($body['command_id']??0);
            $q=$pdo->prepare('SELECT status,result_text,command_name FROM '.arc_table('agent_commands').' WHERE id=? AND agent_id=? AND network_id=? AND account_id=?');
            $q->execute([$id,$agentId,$network,$account]);$cmd=$q->fetch(PDO::FETCH_ASSOC);
            if(!$cmd||!in_array($cmd['command_name'],$names,true))arc_json(['ok'=>false,'error'=>'Solicitação inexistente'],404);
            arc_json(['ok'=>true,'status'=>$cmd['status'],'message'=>$cmd['result_text']]);
        }
        if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
        if((int)$machine['headless_mode']!==1)arc_json(['ok'=>false,'error'=>'Ative o modo sem monitor neste agente'],403);
        if(!$online)arc_json(['ok'=>false,'error'=>'Agente desconectado'],409);
        $mode=(string)($body['mode']??'');
        if($action==='network-clean-tool')$mode='tool';
        if(!in_array($mode,$isClean?['scan','execute','tool']:['refresh','uninstall','install'],true))arc_json(['ok'=>false,'error'=>'Operação inválida'],422);
        $request=['ids'=>[],'categories'=>[],'age_days'=>7,'restore'=>'off','residual'=>false,'interactive'=>false];
        try{
            if($isClean&&$mode==='tool'){
                $toolName=(string)($body['tool']??'');
                $last=isset(arc_clean_tools()[$toolName])?(arc_clean_report_get($pdo,$agentId,$network,$account,$toolName)['report']??null):null;
                $request=arc_clean_validate_tool($body,$last);
                if(($request['tool']??'')==='health'){$healthSettings=arc_clean_settings_get($pdo,$agentId,$network,$account);$request['exclusions']=$healthSettings['exclusions'];$request['custom_folders']=$healthSettings['custom_folders'];}
            }elseif($isClean){
                [$request['categories'],$request['age_days']]=arc_clean_validate_request($body['categories']??null,$body['age_days']??7);
                $settings=arc_clean_settings_get($pdo,$agentId,$network,$account);
                $request['exclusions']=$settings['exclusions'];$request['custom_folders']=$settings['custom_folders'];
            }elseif($mode==='install'){
                $selected=$body['package_ids']??null;
                if(!is_array($selected)||count($selected)<1||count($selected)>20)throw new InvalidArgumentException('Selecione entre 1 e 20 aplicativos.');
                $permitted=array_column(arc_program_install_catalog(), 'id');
                if(count(array_unique($selected))!==count($selected))throw new InvalidArgumentException('Pacotes duplicados.');
                foreach($selected as $pkg){if(!is_string($pkg)||!in_array($pkg,$permitted,true))throw new InvalidArgumentException('Aplicativo não autorizado no catálogo.');}
                $request['ids']=array_values($selected);
            }elseif($mode==='uninstall'){
                $q=$pdo->prepare('SELECT programs_json,collected_at FROM '.arc_table('agent_software_inventory').' WHERE agent_id=? AND network_id=? AND account_id=?');
                $q->execute([$agentId,$network,$account]);$inventory=$q->fetch(PDO::FETCH_ASSOC);
                if(!$inventory||strtotime((string)$inventory['collected_at'])<time()-ARC_PROGRAM_INVENTORY_MAX_AGE)arc_json(['ok'=>false,'error'=>'Atualize a lista de programas antes de desinstalar'],409);
                $rows=json_decode((string)$inventory['programs_json'],true);
                [$request['ids'],$needsWindow]=arc_program_validate_uninstall($body['program_ids']??null,is_array($rows)?$rows:[]);
                $request['interactive']=!empty($body['interactive']);
                if($needsWindow&&!$request['interactive'])arc_json(['ok'=>false,'error'=>'Há programas cujo desinstalador abre janela no computador. Autorize essa opção ou desmarque-os.'],422);
                $request['restore']=arc_program_restore_mode($body['restore']??'try');
                $request['residual']=!empty($body['residual']);
            }
        }catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
        $payload=json_encode($request,JSON_UNESCAPED_UNICODE);
        /* Pendências mantidas para despacho sequencial. */
        arc_expire_running_commands($pdo,$agentId);
        $busy=arc_network_active_operation($pdo,$agentId,$network,$account,array_merge(arc_network_operation_commands()['program'],arc_network_operation_commands()['clean']));
        /* Operações elegíveis são enfileiradas em vez de rejeitadas por ocupação. */
        $command=$isClean?($mode==='tool'?'clean_tool':($mode==='scan'?'clean_scan':'clean_execute')):($mode==='refresh'?'program_inventory':($mode==='install'?'program_install':'program_uninstall'));
        $pdo->beginTransaction();try{
            $pdo->prepare('INSERT INTO '.arc_table('agent_commands').' (account_id,network_id,agent_id,command_name) VALUES (?,?,?,?)')->execute([$account,$network,$agentId,$command]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$id,$agentId,$network,$payload]);
            $pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$id,(int)arc_user_id()]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        arc_user_audit($pdo,($isClean?'clean_':'program_').$mode,'Solicitação '.$id.' ao agente '.$agentId.($mode==='tool'?' (ferramenta '.$request['tool'].': '.$request['action'].')':' ('.count($request['ids']).' programas, '.count($request['categories']).' categorias)'));
        arc_json(['ok'=>true,'command_id'=>$id,'status'=>'pending']);
    }
    /* Operação ativa e cancelamento geral da Gestão de Rede: uma única regra para Programas, Clean, Diagnóstico e demais comandos longos. */
    if (in_array($action,['network-operation-active','network-operation-cancel'],true)) {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        $post=$_SERVER['REQUEST_METHOD']==='POST';
        if ($post) arc_verify_csrf();
        $body=$post?json_decode((string)file_get_contents('php://input'),true):$_GET;
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'Requisição inválida'],400);
        $network=(int)($body['network_id']??0);$agentId=(int)($body['agent_id']??0);
        $access=arc_network_team_access($pdo,$network,(int)arc_user_id());
        if (!$access || !in_array($access['role'],['owner','administrator'],true)) arc_json(['ok'=>false,'error'=>'Sem permissão'],403);
        $account=(int)$access['account_id'];
        /* Controle de energia e assistência remota têm botões próprios e não entram no "cancelar tudo". */
        $guard="command_name NOT IN ('power_shutdown','power_restart','power_cancel','remote_assistance','screen_lock')";
        if ($action==='network-operation-active') {
            arc_expire_running_commands($pdo,$agentId);
            $q=$pdo->prepare('SELECT id,command_name,status,result_text FROM '.arc_table('agent_commands')." WHERE agent_id=? AND network_id=? AND account_id=? AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 3 HOUR) AND $guard AND command_name NOT IN ('monitor_start','monitor_pause') ORDER BY id DESC LIMIT 20");
            $q->execute([$agentId,$network,$account]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            arc_json(['ok'=>true,'active'=>array_map(static fn($r)=>['command_id'=>(int)$r['id'],'command_name'=>$r['command_name'],'status'=>$r['status'],'message'=>mb_substr((string)$r['result_text'],0,200)],$rows)]);
        }
        if (!$post) arc_json(['ok'=>false,'error'=>'Método inválido'],405);
        $q=$pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Cancelado pelo operador',completed_at=NOW() WHERE agent_id=? AND network_id=? AND account_id=? AND status IN ('pending','running') AND $guard AND command_name NOT IN ('monitor_start','monitor_pause')");
        $q->execute([$agentId,$network,$account]);
        if ($q->rowCount()) arc_user_audit($pdo,'operation_cancel_all',$q->rowCount().' operação(ões) canceladas no agente '.$agentId);
        arc_json(['ok'=>true,'cancelled'=>$q->rowCount()]);
    }
    /* Cancelamento cooperativo de operação Clean/Programas: não marca êxito de exclusões já iniciadas. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='network-clean-cancel') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        arc_verify_csrf();
        $body=json_decode((string)file_get_contents('php://input'),true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $network=(int)($body['network_id']??0);$agentId=(int)($body['agent_id']??0);$id=(int)($body['command_id']??0);
        $access=arc_network_team_access($pdo,$network,(int)arc_user_id());
        if (!$access || !in_array($access['role'],['owner','administrator'],true)) arc_json(['ok'=>false,'error'=>'Sem permissão'],403);
        $rights=arc_network_profile_rights($pdo,$network,(int)arc_user_id());
        if ($access['role']!=='owner' && $rights!==null && !array_intersect(['clean','programas'],$rights)) arc_json(['ok'=>false,'error'=>'Sem permissão'],403);
        $q=$pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Cancelamento solicitado pelo operador',completed_at=NOW() WHERE (id=? OR ?=0) AND agent_id=? AND network_id=? AND account_id=? AND command_name IN ('clean_scan','clean_execute','clean_tool','program_inventory','program_uninstall','program_install') AND status IN ('pending','running')");
        $q->execute([$id,$id,$agentId,$network,(int)$access['account_id']]);
        if ($q->rowCount())arc_user_audit($pdo,'clean_cancel','Cancelamento solicitado para operação '.$id.' no agente '.$agentId);
        arc_json(['ok'=>true,'cancelled'=>$q->rowCount()>0]);
    }
    /* Canal de comandos: ações limitadas, agente aprovado, token da instalação. */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['agent-command-poll','agent-command-ack','agent-command-output','agent-config','agent-terminal-local-start','agent-power-authorize','agent-command-authorize','agent-remote-authorize','agent-file-transfer','agent-file-browser-result','agent-support-code','agent-workspace','agent-program-inventory','agent-clean-result','agent-clean-local-result','agent-clean-local-settings','agent-program-local-inventory','agent-devices','agent-device-save'],true)) {
        $raw = file_get_contents('php://input');
        if ($action==='agent-workspace' && (!is_string($raw)||strlen($raw)>131072)) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        if ($action!=='agent-workspace' && (!is_string($raw) || strlen($raw)>(in_array($action,['agent-program-inventory','agent-clean-result','agent-clean-local-result','agent-clean-local-settings','agent-program-local-inventory'],true)?6000000:($action==='agent-file-browser-result'?90000:($action==='agent-command-poll'?32768:($action==='agent-command-ack'?16384:8192)))))) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $body=json_decode($raw,true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        try {$agent=arc_agent_authenticate($pdo,$body);} catch (RuntimeException $e) {if($e->getCode()===403)arc_json(['ok'=>false,'error'=>'Agente sem autorização'],403);throw $e;}
        $agentId=(int)$agent['id'];
        if($action==='agent-command-poll'&&in_array($body['desktop_state']??'', ['locked','unlocked'],true)){
            $users=[];foreach(array_slice(is_array($body['windows_users']??null)?$body['windows_users']:[],0,50) as $entry){
                if(!is_array($entry))continue;$name=(string)($entry['username']??'');$label=(string)($entry['label']??'');
                if($name!==''&&$label!==''&&strlen($name)<=768&&strlen($label)<=768&&!preg_match('/[\x00-\x1f]/',$name.$label))$users[]=['username'=>$name,'label'=>$label];
            }
            $directory=ARC_PRIVATE_DIR.'/storage/remote-screen';if(!is_dir($directory))@mkdir($directory,0700,true);
            $path=$directory.'/agent-'.$agentId.'.json';$temp=tempnam($directory,'state-');
            if($temp!==false){chmod($temp,0600);file_put_contents($temp,json_encode(['desktop_state'=>$body['desktop_state'],'windows_users'=>$users,'windows_users_error'=>!empty($body['windows_users_error']),'updated'=>time()]));rename($temp,$path);}
        }

        if($action==='agent-clean-result'){
            $id=(int)($body['command_id']??0);$report=$body['report']??null;
            if(!is_array($report))arc_json(['ok'=>false,'error'=>'Relatório de limpeza inválido'],422);
            $q=$pdo->prepare('SELECT c.command_name,COALESCE(t.request_text,\'\') AS request_text FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('agent_terminal_jobs')." t ON t.command_id=c.id WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name IN ('clean_scan','clean_execute','clean_tool') AND c.status='running'");
            $q->execute([$id,$agentId,$agent['network_id'],$agent['account_id']]);$job=$q->fetch(PDO::FETCH_ASSOC);
            if(!$job)arc_json(['ok'=>false,'error'=>'Varredura não solicitada'],403);
            if($job['command_name']==='clean_tool'){
                /* O tipo vem do pedido gravado pela plataforma, nunca do corpo enviado pelo agente. */
                $requested=json_decode((string)$job['request_text'],true);
                try{[$kind,$clean]=arc_clean_sanitize_tool_report((string)($requested['tool']??''),$report);}catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
                arc_clean_report_save($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],$kind,$clean);
            }else{
                $clean=arc_clean_sanitize_report($report,$job['command_name']==='clean_execute');
                arc_clean_report_save($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],'clean',$clean);
                arc_clean_record_history($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],$clean);
            }
            arc_json(['ok'=>true,'saved'=>true]);
        }
        if($action==='agent-program-inventory'){
            $id=(int)($body['command_id']??0);$programs=$body['programs']??null;
            if(!is_array($programs)||count($programs)>1500)arc_json(['ok'=>false,'error'=>'Inventário inválido'],422);
            $q=$pdo->prepare('SELECT id FROM '.arc_table('agent_commands')." WHERE id=? AND agent_id=? AND network_id=? AND account_id=? AND command_name='program_inventory' AND status='running'");
            $q->execute([$id,$agentId,$agent['network_id'],$agent['account_id']]);if(!$q->fetchColumn())arc_json(['ok'=>false,'error'=>'Inventário não solicitado'],403);
            $clean=arc_program_sanitize_inventory($programs);
            $json=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $pdo->prepare('INSERT INTO '.arc_table('agent_software_inventory').' (agent_id,network_id,account_id,programs_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),programs_json=VALUES(programs_json),collected_at=CURRENT_TIMESTAMP')->execute([$agentId,$agent['network_id'],$agent['account_id'],$json]);
            arc_json(['ok'=>true,'saved'=>count($clean)]);
        }
        /* Execuções feitas na própria tela do agente (abas Clean/Programas do Windows): mesmo saneamento das execuções remotas,
           sem comando pendente. O resultado fica disponível na plataforma (sincronia nos dois sentidos). */
        if($action==='agent-clean-local-settings'){
            $acc=(int)$agent['account_id'];$net=(int)$agent['network_id'];
            if(!empty($body['save'])){
                try{$settings=arc_clean_validate_settings($body);}catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
                arc_clean_settings_save($pdo,$agentId,$net,$acc,$settings);
            }
            arc_json(['ok'=>true,'network_id'=>$net,'agent_id'=>$agentId,'settings'=>arc_clean_settings_get($pdo,$agentId,$net,$acc)]);
        }
        if($action==='agent-clean-local-result'){
            $report=$body['report']??null;if(!is_array($report))arc_json(['ok'=>false,'error'=>'Relatório inválido'],422);
            $mode=(string)($body['mode']??'tool');
            try{
                if($mode==='tool'){[$kind,$clean]=arc_clean_sanitize_tool_report((string)($body['tool']??''),$report);arc_clean_report_save($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],$kind,$clean);}
                else{$clean=arc_clean_sanitize_report($report,$mode==='execute');arc_clean_report_save($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],'clean',$clean);arc_clean_record_history($pdo,$agentId,(int)$agent['network_id'],(int)$agent['account_id'],$clean);}
            }catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
            arc_json(['ok'=>true,'saved'=>true]);
        }
        if($action==='agent-program-local-inventory'){
            $programs=$body['programs']??null;
            if(!is_array($programs)||count($programs)>1500)arc_json(['ok'=>false,'error'=>'Inventário inválido'],422);
            $clean=arc_program_sanitize_inventory($programs);
            $json=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $pdo->prepare('INSERT INTO '.arc_table('agent_software_inventory').' (agent_id,network_id,account_id,programs_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE network_id=VALUES(network_id),account_id=VALUES(account_id),programs_json=VALUES(programs_json),collected_at=CURRENT_TIMESTAMP')->execute([$agentId,$agent['network_id'],$agent['account_id'],$json]);
            arc_json(['ok'=>true,'saved'=>count($clean)]);
        }
        /* Inventário de dispositivos para a tela nativa do agente: mesmas regras e limites do painel web (network-devices / network-device-save). */
        if($action==='agent-devices'||$action==='agent-device-save'){
            require_once dirname(__DIR__).'/includes/network-inventory.php';
            arc_inventory_schema($pdo);
            $acc=(int)$agent['account_id'];$net=(int)$agent['network_id'];
            if($action==='agent-devices'){
                $rows=arc_inventory_list($pdo,$acc,$net,$agentId);
                foreach($rows as &$r){$r['payload']=json_decode((string)($r['payload_json']??''),true)?:[];unset($r['payload_json']);}
                unset($r);
                arc_json(['ok'=>true,'devices'=>$rows,'server_time'=>time()]);
            }
            $id=(int)($body['id']??0);
            if($id<1)arc_json(['ok'=>false,'error'=>'Identificação inválida'],422);
            $allowed=['computer','phone','tablet','tv','router','printer','server','camera','iot','console','speaker','switch','ap','nas','device'];
            $type=strtolower(trim((string)($body['custom_type']??'')));
            if($type!==''&&!in_array($type,$allowed,true))arc_json(['ok'=>false,'error'=>'Tipo de equipamento não reconhecido'],422);
            $name=mb_substr(trim((string)($body['custom_name']??'')),0,160);
            $group=mb_substr(trim((string)($body['group_name']??'')),0,90);
            if(preg_match('/[\x00-\x1f]/',$name.$group))arc_json(['ok'=>false,'error'=>'Texto inválido'],422);
            $q=$pdo->prepare('UPDATE '.arc_inventory_table().' SET custom_name=?,custom_type=?,group_name=?,pinned=?,hidden=?,managed=1 WHERE id=? AND account_id=? AND network_id=? AND agent_id=?');
            $q->execute([$name,$type,$group,!empty($body['pinned'])?1:0,!empty($body['hidden'])?1:0,$id,$acc,$net,$agentId]);
            if(!$q->rowCount()){
                $test=$pdo->prepare('SELECT id FROM '.arc_inventory_table().' WHERE id=? AND account_id=? AND network_id=? AND agent_id=?');
                $test->execute([$id,$acc,$net,$agentId]);
                if(!$test->fetchColumn())arc_json(['ok'=>false,'error'=>'Equipamento não encontrado'],404);
            }
            arc_json(['ok'=>true,'saved'=>true]);
        }
        /* Autorização da sessão sem monitor: revalida identidade, perfil administrativo,
           configuração atual do equipamento e vínculo da solicitação. Não confia
           em requester_role/headless_mode enviados no JSON pelo navegador. */
        if($action==='agent-remote-authorize'){
            $id=(int)($body['command_id']??0);
            if($id<1)arc_json(['ok'=>false,'error'=>'Sessão remota inválida.'],422);
            $q=$pdo->prepare('SELECT c.*,s.technician_id,s.unattended,a.headless_mode,n.is_active,n.monitoring_enabled FROM '.arc_table('agent_commands')." c JOIN ".arc_table('support_remote_sessions')." s ON s.command_id=c.id AND s.account_id=c.account_id AND s.network_id=c.network_id JOIN ".arc_table('network_agents')." a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id JOIN ".arc_table('managed_networks')." n ON n.id=c.network_id AND n.account_id=c.account_id WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name='remote_assistance' AND c.status='running' AND c.dispatched_at>DATE_SUB(NOW(), INTERVAL 3 MINUTE) AND a.status='approved' LIMIT 1");
            $q->execute([$id,$agentId,(int)$agent['network_id'],(int)$agent['account_id']]);
            $session=$q->fetch(PDO::FETCH_ASSOC);
            if(!$session||(int)$session['unattended']!==1||(int)$session['headless_mode']!==1||(int)$session['is_active']!==1||(int)$session['monitoring_enabled']!==1)
                arc_json(['ok'=>false,'error'=>'O acesso automático não está habilitado ou foi revogado.'],403);
            $requester=(int)$session['technician_id'];
            try{$access=arc_support_access($pdo,(int)$session['network_id'],$requester,true);}
            catch(Throwable $e){arc_json(['ok'=>false,'error'=>'Gestor sem permissão atual para esta rede.'],403);}
            if((int)$access['account_id']!==(int)$session['account_id']||!in_array((string)$access['role'],['owner','administrator'],true)||!arc_support_remote_allowed($pdo,$session,$requester))
                arc_json(['ok'=>false,'error'=>'Autorização administrativa revogada.'],403);
            arc_json(['ok'=>true,'unattended'=>true,'command'=>'remote_assistance','expires_in'=>1800]);
        }
        if($action==='agent-command-authorize'){
            $authorized=arc_support_unattended_command($pdo,$agent,(int)($body['command_id']??0));
            if(!$authorized)arc_json(['ok'=>false,'error'=>'Autorização sem monitor revogada ou expirada.'],403);
            arc_json(['ok'=>true,'command'=>$authorized['command_name'],'request_text'=>(string)($authorized['request_text']??''),'headless'=>true]);
        }
        if($action==='agent-file-browser-result'){
            $id=(int)($body['command_id']??0);$entries=$body['entries']??null;$error=substr((string)($body['error']??''),0,250);
            if($id<1||!is_array($entries)||count($entries)>180)arc_json(['ok'=>false,'error'=>'Listagem inválida'],422);
            $clean=[];foreach($entries as $entry){if(!is_array($entry))continue;$name=mb_substr((string)($entry['name']??''),0,200);$entryPath=mb_substr((string)($entry['path']??''),0,1024);if($name===''||$entryPath==='')continue;$clean[]=['name'=>$name,'path'=>$entryPath,'directory'=>!empty($entry['directory']),'size'=>max(0,(int)($entry['size']??0)),'modified'=>max(0,(int)($entry['modified']??0)),'folder_size'=>array_key_exists('folder_size',$entry)?max(0,(int)$entry['folder_size']):null,'folder_size_complete'=>!empty($entry['folder_size_complete']),'total'=>max(0,(int)($entry['total']??0)),'free'=>max(0,(int)($entry['free']??0))];}
            $json=json_encode(['entries'=>$clean,'truncated'=>!empty($body['truncated']),'path'=>mb_substr((string)($body['path']??''),0,1024),'error'=>$error],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            $q=$pdo->prepare('UPDATE '.arc_table('file_browser_requests').' r JOIN '.arc_table('agent_commands')." c ON c.id=r.command_id SET r.result_json=?,r.status=?,r.updated_at=NOW() WHERE r.command_id=? AND r.agent_id=? AND r.network_id=? AND r.account_id=? AND c.status='running' AND r.status='pending'");
            $q->execute([$json,$error!==''?'failed':'completed',$id,$agentId,(int)$agent['network_id'],(int)$agent['account_id']]);arc_json(['ok'=>true,'saved'=>$q->rowCount()>0]);
        }
        if($action==='agent-workspace'){
            require_once __DIR__.'/../includes/agent-workspace.php';
            try{arc_json(arc_agent_workspace($pdo,$agent,$body));}
            catch(DomainException|InvalidArgumentException $e){$code=(int)$e->getCode();arc_json(['ok'=>false,'error'=>$e->getMessage()],in_array($code,[403,404,429],true)?$code:422);}
        }
        if($action==='agent-support-code')arc_json(['ok'=>true,'code'=>arc_support_guest_code($pdo,$agentId),'expires_in'=>180]);
        if($action==='agent-file-transfer'){
            $q=$pdo->prepare('SELECT id FROM '.arc_table('support_file_transfers').' WHERE command_id=? AND agent_id=? AND account_id=? AND network_id=?');$q->execute([(int)($body['command_id']??0),$agentId,$agent['account_id'],$agent['network_id']]);$transfer=$q->fetchColumn();
            if(!$transfer)arc_json(['ok'=>false,'error'=>'Transferência não encontrada.'],404);
            arc_support_file_record($pdo,(int)$transfer,$agent);arc_json(['ok'=>true,'transfer_id'=>(int)$transfer]);
        }
        if($action==='agent-power-authorize'){
            $id=(int)($body['command_id']??0);
            $q=$pdo->prepare("SELECT c.command_name,a.headless_mode FROM ".arc_table('agent_commands')." c JOIN ".arc_table('network_agents')." a ON a.id=c.agent_id AND a.account_id=c.account_id JOIN ".arc_table('managed_networks')." n ON n.id=c.network_id AND n.account_id=c.account_id JOIN ".arc_table('user_accounts')." u ON u.id=c.account_id WHERE c.id=? AND c.agent_id=? AND c.account_id=? AND c.network_id=? AND c.status='running' AND c.command_name IN ('power_shutdown','power_restart','power_cancel') AND c.dispatched_at>=DATE_SUB(NOW(),INTERVAL 3 MINUTE) AND u.account_status='active'");
            $q->execute([$id,$agentId,$agent['account_id'],$agent['network_id']]);$power=$q->fetch();
            if($power&&!arc_support_command_request_allowed($pdo,$id,(int)$agent['network_id'],(int)$agent['account_id']))$power=false;
            if($power){$q=$pdo->prepare('SELECT s.enabled FROM '.arc_table('support_schedule_runs').' r JOIN '.arc_table('support_schedules').' s ON s.id=r.schedule_id WHERE r.command_id=?');$q->execute([$id]);$enabled=$q->fetchColumn();if($enabled!==false&&!(int)$enabled)$power=false;arc_billing_assert_active($pdo,(int)$agent['account_id']);}
            if(!$power)arc_json(['ok'=>false,'error'=>'Autorização expirada. Solicite novamente.'],403);
            arc_json(['ok'=>true,'command'=>$power['command_name'],'headless'=>(bool)$power['headless_mode']]);
        }
        if ($action==='agent-config') {
            $tabs=array_keys(arc_v16_agent_tabs());
            arc_v16_release_schema($pdo);
            $query=$pdo->prepare('SELECT id,version,fingerprint AS sha256,created_at,notes FROM '.arc_table('platform_release_history').' WHERE version=?');$query->execute([arc_app_version()]);$release=$query->fetch();
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
            arc_json(['ok'=>true,'design'=>arc_support_design($pdo,'agent'),'visual_theme'=>arc_visual_theme($pdo),'system_name'=>(string)(arc_setting_values($pdo)['site_name']??'Agente de Rede'),'system_description'=>(string)(arc_setting_values($pdo)['tagline']??''),'allowed_tabs'=>$tabs,'latest_release'=>$release?:null,'poll_interval_seconds'=>30,'monitor_interval_seconds'=>$interval,'alert_latency_ms'=>$latency,'last_connection'=>$lastConnection,'device_overrides'=>arc_inventory_overrides($pdo,(int)$agent['account_id'],(int)$agent['network_id'],$agentId)]);
        }
        if ($action==='agent-terminal-local-start') {
            $rawCommand=trim((string)($body['request_text']??''));
            if (!$validTerminalRequest($rawCommand)) arc_json(['ok'=>false,'error'=>'Comando local não autorizado'],422);
            arc_expire_running_commands($pdo,$agentId);
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
            // O deslocamento em bytes impede que uma retransmissão duplique linhas.
            // Agentes antigos (sem offset) continuam aceitos durante a atualização.
            $sql='UPDATE '.arc_table('agent_terminal_jobs').' t INNER JOIN '.arc_table('agent_commands')." c ON c.id=t.command_id SET t.output_text=CONCAT(COALESCE(t.output_text,''),?),t.updated_at=NOW() WHERE t.command_id=? AND t.agent_id=? AND c.agent_id=? AND c.status='running' AND OCTET_LENGTH(COALESCE(t.output_text,'')) + ? <= 60000";
            if($hasOffset) $sql.=" AND OCTET_LENGTH(COALESCE(t.output_text,''))=?";
            $params=[$chunk,$id,$agentId,$agentId,strlen($chunk)];
            if($hasOffset) $params[]=$offset;
            $q=$pdo->prepare($sql);
            $q->execute($params);
            $received=$q->rowCount()>0;
            $check=$pdo->prepare('SELECT c.status,OCTET_LENGTH(COALESCE(t.output_text,\'\')) AS next_offset FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('agent_terminal_jobs')." t ON t.command_id=c.id WHERE c.id=? AND c.agent_id=? AND c.command_name IN ('terminal_diagnostic','windows_update') LIMIT 1");
            $check->execute([$id,$agentId]);
            $task=$check->fetch(PDO::FETCH_ASSOC);
            arc_json(['ok'=>true,'received'=>$received,'next_offset'=>(int)($task['next_offset']??0),'status'=>(string)($task['status']??'unknown')]);
        }
        if ($action==='agent-command-ack') {
            $id=(int)($body['command_id']??0);
            $status=(string)($body['status']??'');
            if ($id<1 || !in_array($status,['running','completed','failed'],true)) arc_json(['ok'=>false,'error'=>'Confirmação inválida'],422);
            $result=mb_substr($status==='failed'?arc_command_error_scrub((string)($body['result']??''),500):(string)($body['result']??''),0,500);
            $q=$pdo->prepare('UPDATE '.arc_table('agent_commands').' SET status=?,result_text=?,completed_at=IF(?=\'running\',NULL,CURRENT_TIMESTAMP) WHERE id=? AND agent_id=? AND status=\'running\'');
            $q->execute([$status,$result,$status,$id,$agentId]);
            $acknowledged=$q->rowCount()>0;
            if ($acknowledged && $status==='failed') {
                $find=$pdo->prepare('SELECT id,account_id,network_id,agent_id FROM '.arc_table('agent_commands').' WHERE id=? AND agent_id=? AND account_id=? AND network_id=?');
                $find->execute([$id,$agentId,(int)$agent['account_id'],(int)$agent['network_id']]);
                $command=$find->fetch(PDO::FETCH_ASSOC);
                if ($command) {
                    $detail=arc_command_error_scrub((string)($body['error_detail']??$result));
                    $code=arc_command_error_code('failed',(string)($body['error_code']??''),$result);
                    arc_command_error_store($pdo,$command,$code,$detail!==''?$detail:$result);
                }
            }
            arc_json(['ok'=>true,'acknowledged'=>$acknowledged]);
        }
        // Uma operação vencida em OUTRO agente da mesma conta não pode bloquear
        // indefinidamente todo o despacho. Reaplique a política central de prazos
        // por conta, inclusive para agentes que pararam de consultar o servidor.
        arc_expire_running_commands($pdo,null,null,(int)$agent['account_id']);
        /* Pendências persistem até despacho ou cancelamento explícito. */
        arc_support_schedules_dispatch($pdo,$agent);
        // DDL provoca commit implícito em MySQL 5.7/8.x.
        // Garanta a tabela antes de iniciar a transação de retirada da fila.
        $pdo->beginTransaction();
        try {
            /* Uma linha da conta serializa a retirada da fila entre agentes distintos. */
            $queueGuard=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');
            $queueGuard->execute([(int)$agent['account_id']]);
            $q=$pdo->prepare('SELECT c.id,c.account_id,c.network_id,c.command_name,COALESCE(t.request_text,\'\') AS request_text,COALESCE(a.headless_mode,0) AS headless_mode,COALESCE(rs.technician_id,cr.user_id,c.account_id) AS requester_user_id,CASE WHEN COALESCE(rs.technician_id,cr.user_id,c.account_id)=a.account_id THEN \'administrator\' ELSE COALESCE(tm.role,\'unverified\') END AS requester_role,COALESCE(u.display_name,\'\') AS requester_name FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('agent_terminal_jobs').' t ON t.command_id=c.id LEFT JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id LEFT JOIN '.arc_table('support_remote_sessions').' rs ON rs.command_id=c.id LEFT JOIN '.arc_table('support_command_requests').' cr ON cr.command_id=c.id LEFT JOIN '.arc_table('network_team_members').' tm ON tm.network_id=c.network_id AND tm.account_id=c.account_id AND tm.member_user_id=COALESCE(rs.technician_id,cr.user_id) AND tm.enabled=1 LEFT JOIN '.arc_table('user_accounts').' u ON u.id=COALESCE(rs.technician_id,cr.user_id,c.account_id) WHERE c.agent_id=? AND c.status=\'pending\' ORDER BY c.id ASC LIMIT 1 FOR UPDATE');
            $q->execute([$agentId]);$cmd=$q->fetch();
            if($cmd){
                // Ordem FIFO para agentes conectados. Pendências pertencentes a
                // computadores desconectados continuam armazenadas, mas não
                // bloqueiam indefinidamente os computadores que estão online.
                // Execuções em andamento seguem os prazos próprios acima.
                $order=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands').' queued LEFT JOIN '.arc_table('network_agents')." target ON target.id=queued.agent_id AND target.account_id=queued.account_id WHERE queued.account_id=? AND queued.id<? AND queued.command_name NOT IN ('monitor_start','monitor_pause') AND (queued.status='running' OR (queued.status='pending' AND target.status='approved' AND target.last_seen_at>=DATE_SUB(NOW(),INTERVAL 120 SECOND)))");
                $order->execute([(int)$cmd['account_id'],(int)$cmd['id']]);
                $active=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands')." WHERE account_id=? AND status='running' AND command_name NOT IN ('monitor_start','monitor_pause')");
                $active->execute([(int)$cmd['account_id']]);
                if((int)$order->fetchColumn()>0||(int)$active->fetchColumn()>0)$cmd=false;
            }
            if($cmd&&in_array($cmd['command_name'],['program_inventory','program_uninstall','program_install','clean_scan','clean_execute','clean_tool','cloud_folders'],true)){
                $q=$pdo->prepare('SELECT user_id FROM '.arc_table('support_command_requests').' WHERE command_id=?');$q->execute([(int)$cmd['id']]);$requester=(int)$q->fetchColumn();
                $access=$requester?arc_network_team_access($pdo,(int)$cmd['network_id'],$requester):null;
                $rights=$requester?arc_network_profile_rights($pdo,(int)$cmd['network_id'],$requester):[];
                if(!$access||!in_array($access['role'],['owner','administrator'],true)||($access['role']!=='owner'&&$rights!==null&&!in_array(in_array($cmd['command_name'],['clean_scan','clean_execute','clean_tool','cloud_folders'],true)?'clean':'programas',$rights,true))||!arc_support_command_request_allowed($pdo,(int)$cmd['id'],(int)$cmd['network_id'],(int)$cmd['account_id'])){
                    $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Permissão de programas revogada',completed_at=NOW() WHERE id=?")->execute([(int)$cmd['id']]);$cmd=false;
                }
            }
            if($cmd&&in_array($cmd['command_name'],['power_shutdown','power_restart','power_cancel'],true)&&!arc_support_command_request_allowed($pdo,(int)$cmd['id'],(int)$cmd['network_id'],(int)$cmd['account_id'])){$pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='failed',result_text='Autorização administrativa revogada',completed_at=NOW() WHERE id=?")->execute([$cmd['id']]);$cmd=false;}
            if($cmd&&$cmd['command_name']==='cloud_folders'){
                $verify=$pdo->prepare('SELECT path_text FROM '.arc_table('file_browser_requests').' WHERE command_id=?');$verify->execute([(int)$cmd['id']]);
                $cmd['request_text']=(string)($verify->fetchColumn()?:'');
            }
            if($cmd&&$cmd['command_name']==='file_browser'){
                $verify=$pdo->prepare('SELECT r.path_text,r.requested_by,a.headless_mode FROM '.arc_table('file_browser_requests').' r JOIN '.arc_table('network_agents').' a ON a.id=r.agent_id AND a.network_id=r.network_id AND a.account_id=r.account_id WHERE r.command_id=?');
                $verify->execute([(int)$cmd['id']]);$b=$verify->fetch(PDO::FETCH_ASSOC);
                $browsePath=(string)($b['path_text']??'');
                $allowRootOnly=$browsePath==='';
                if(!$b||!(int)$b['headless_mode']||!arc_support_command_request_allowed($pdo,(int)$cmd['id'],(int)$cmd['network_id'],(int)$cmd['account_id'])||(!$allowRootOnly&&!in_array($cmd['requester_role'],['administrator','owner'],true))){
                    $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Permissão para navegar revogada' WHERE id=?")->execute([(int)$cmd['id']]);$cmd=false;
                }else{$cmd['request_text']=$browsePath;}
            }
            if($cmd&&in_array($cmd['command_name'],['file_bundle','file_execute','file_operation'],true)){
                if(!in_array($cmd['requester_role'],['administrator','owner'],true)||!(int)$cmd['headless_mode']||!arc_support_command_request_allowed($pdo,(int)$cmd['id'],(int)$cmd['network_id'],(int)$cmd['account_id'])){
                    $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Permissão para usar o explorador remoto revogada',completed_at=NOW() WHERE id=?")->execute([(int)$cmd['id']]);$cmd=false;
                }
            }
            if($cmd&&$cmd['command_name']==='agent_update'){
                // A autorização administrativa deve continuar válida no instante do despacho.
                $stillAllowed=arc_support_command_request_allowed($pdo,(int)$cmd['id'],(int)$cmd['network_id'],(int)$cmd['account_id']);
                if($stillAllowed){
                    try{$updateAccess=arc_support_access($pdo,(int)$cmd['network_id'],(int)$cmd['requester_user_id'],true);
                        $stillAllowed=(int)$updateAccess['account_id']===(int)$cmd['account_id']&&in_array((string)$updateAccess['role'],['owner','administrator'],true);
                    }catch(RuntimeException $e){$stillAllowed=false;}
                }
                if(!$stillAllowed){
                    $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Permissão administrativa para atualizar revogada',completed_at=NOW() WHERE id=?")->execute([(int)$cmd['id']]);$cmd=false;
                }
            }
            if($cmd&&!in_array($cmd['requester_role'],['administrator','owner'],true))$cmd['headless_mode']=0;
            if($cmd){$q=$pdo->prepare('SELECT id FROM '.arc_table('support_guest_codes').' WHERE command_id=?');$q->execute([$cmd['id']]);if($q->fetchColumn()){$cmd['headless_mode']=0;$cmd['requester_role']='operator';$cmd['requester_name']='Visitante com código de uso único';}}
            if ($cmd && $cmd['command_name']==='remote_assistance' && !arc_support_remote_allowed($pdo,$cmd,(int)$cmd['requester_user_id'])) {
                $pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='failed',result_text='Autorização revogada',completed_at=NOW() WHERE id=?")->execute([$cmd['id']]);$cmd=false;
            }
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
    /* Encerramento administrativo de sessões remotas presas: só proprietário/administrador. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='network-remote-force-stop') {
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        arc_verify_csrf();
        $raw=file_get_contents('php://input',false,null,0,4097);
        if(!is_string($raw)||strlen($raw)>4096)arc_json(['ok'=>false,'error'=>'Requisição inválida'],413);
        $body=json_decode($raw,true);
        if(!is_array($body))arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $networkId=(int)($body['network_id']??0);$agentId=(int)($body['agent_id']??0);
        if($networkId<1||$agentId<1)arc_json(['ok'=>false,'error'=>'Rede ou agente inválido'],422);
        try{$access=arc_support_access($pdo,$networkId,(int)arc_user_id(),true);}
        catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403);}
        if(!in_array((string)$access['role'],['owner','administrator'],true))arc_json(['ok'=>false,'error'=>'Somente proprietário ou administrador pode encerrar outra sessão'],403);
        $accountId=(int)$access['account_id'];$ids=[];
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id FROM '.arc_table('network_agents')." WHERE id=? AND network_id=? AND account_id=? AND status='approved' FOR UPDATE");
            $q->execute([$agentId,$networkId,$accountId]);
            if(!$q->fetchColumn())throw new RuntimeException('Agente não autorizado');
            $q=$pdo->prepare('SELECT id FROM '.arc_table('agent_commands')." WHERE agent_id=? AND network_id=? AND account_id=? AND command_name='remote_assistance' AND status IN ('pending','running') FOR UPDATE");
            $q->execute([$agentId,$networkId,$accountId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            if($ids){
                $idsSql=implode(',',array_fill(0,count($ids),'?'));
                $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text='Sessão encerrada pelo administrador da rede',completed_at=NOW() WHERE id IN ($idsSql)")->execute($ids);
                $pdo->prepare('DELETE FROM '.arc_table('support_remote_tokens')." WHERE command_id IN ($idsSql)")->execute($ids);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof RuntimeException)arc_json(['ok'=>false,'error'=>$e->getMessage()],403);throw $e;}
        $folder=__DIR__.'/../includes/storage/remote-screen';
        foreach($ids as $id){
            if(!is_dir($folder))continue;
            @file_put_contents($folder.'/stop-'.$id,'1',LOCK_EX);
            foreach(['control-','input-','screen-','meta-','view-','viewer-'] as $prefix){
                $suffix=$prefix==='input-'?'.jsonl':($prefix==='screen-'?'.jpg':(in_array($prefix,['meta-','view-'],true)?'.json':''));
                $path=$folder.'/'.$prefix.$id.$suffix;
                if(is_file($path))@unlink($path);
            }
        }
        arc_user_audit($pdo,'remote_access_forced_stop','Usuário #'.arc_user_id().' encerrou '.count($ids).' sessão(ões) remota(s) no agente #'.$agentId.' da rede #'.$networkId,$accountId);
        arc_json(['ok'=>true,'closed'=>count($ids)]);
    }
    // BLOCO 37 — RECUPERAÇÃO DE SESSÃO REMOTA EXISTENTE (NÃO CRIA OUTRA SESSÃO).
    if($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-remote-existing'){
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $network=(int)($_GET['network_id']??0);$agent=(int)($_GET['agent_id']??0);
        if($network<1||$agent<1)arc_json(['ok'=>false,'error'=>'Selecione um computador válido'],422);
        try{$access=arc_support_access($pdo,$network,(int)arc_user_id(),true);}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403);}
        $q=$pdo->prepare('SELECT c.id,c.agent_id,c.account_id,c.network_id,c.command_name,c.status,c.created_at FROM '.arc_table('agent_commands')." c WHERE c.network_id=? AND c.agent_id=? AND c.account_id=? AND c.command_name='remote_assistance' AND c.status IN ('pending','running') AND c.created_at>DATE_SUB(NOW(),INTERVAL 30 MINUTE) ORDER BY c.id DESC LIMIT 10");
        $q->execute([$network,$agent,(int)$access['account_id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $command){
            if(arc_support_remote_allowed($pdo,$command,(int)arc_user_id()))arc_json(['ok'=>true,'command_id'=>(int)$command['id'],'agent_id'=>$agent,'status'=>$command['status']]);
        }
        arc_json(['ok'=>true,'command_id'=>0,'agent_id'=>$agent]);
    }
    if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='network-command') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        arc_verify_csrf();
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>4096) arc_json(['ok'=>false,'error'=>'Payload inválido'],413);
        $body=json_decode($raw,true);
        if (!is_array($body)) arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
        $networkId=(int)($body['network_id']??0);$command=(string)($body['command']??'');
        $allowed=['monitor_start','monitor_pause','speed_test','scan_devices','scan_map','diagnostic','toolkit','toolkit_traceroute','toolkit_services','toolkit_health','toolkit_troubleshooter','quick_health','storage_health','service_health','restart_readiness','dns_test','public_ip','system_snapshot','flush_dns','wifi_info','terminal_diagnostic','agent_update','remote_assistance','power_shutdown','power_restart','power_cancel','screen_lock','windows_update'];
        if (!in_array($command,$allowed,true)) arc_json(['ok'=>false,'error'=>'Comando não permitido'],422);
        if ($command==='remote_assistance') {
            try {$id=arc_support_request_remote($pdo,$networkId,(int)($body['agent_id']??0),(int)arc_user_id(),(int)($body['ticket_id']??0));}
            catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e instanceof PDOException?'Serviço indisponível.':$e->getMessage()],403);}
            $q=$pdo->prepare('SELECT unattended FROM '.arc_table('support_remote_sessions').' WHERE command_id=?');
            $q->execute([$id]);
            arc_json(['ok'=>true,'command_id'=>$id,'status'=>'pending','unattended'=>(int)$q->fetchColumn()===1]);
        }
        try{$commandAccess=arc_support_access($pdo,$networkId,(int)arc_user_id(),true);}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Sem permissão para este comando.'],403);}
        if(in_array($command,['power_shutdown','power_restart','power_cancel','restart_readiness','screen_lock','windows_update','agent_update'],true)&&!in_array($commandAccess['role'],['owner','administrator'],true))arc_json(['ok'=>false,'error'=>'Permissão administrativa necessária.'],403);
        $commandAccount=(int)$commandAccess['account_id'];
        $delaySeconds=filter_var($body['delay_seconds']??60,FILTER_VALIDATE_INT);if(in_array($command,['power_restart','power_shutdown','screen_lock'],true)&&($delaySeconds===false||$delaySeconds<5||$delaySeconds>3600))arc_json(['ok'=>false,'error'=>'O prazo deve ser entre 5 e 3600 segundos.'],422);
        $terminalInput=trim((string)($body['terminal_input']??''));
        if ($command==='terminal_diagnostic' && !$validTerminalRequest($terminalInput)) arc_json(['ok'=>false,'error'=>'Comando de diagnóstico inválido. Digite ajuda para ver os comandos autorizados.'],422);
        $q=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1 AND monitoring_enabled=1');
        $q->execute([$networkId,$commandAccount]);if (!$q->fetchColumn()) arc_json(['ok'=>false,'error'=>'Rede indisponível'],403);
        $agentId=(int)($body['agent_id']??0);
        $q=$pdo->prepare('SELECT id,machine_name,headless_mode FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=? AND status=\'approved\'');
        $q->execute([$agentId,$networkId,$commandAccount]);$targetAgent=$q->fetch();
        if (!$targetAgent) arc_json(['ok'=>false,'error'=>'Selecione um agente aprovado'],403);
        $windowsRequest=null;
        if($command==='windows_update'){
            $q=$pdo->prepare('SELECT agent_version FROM '.arc_table('network_agents').' WHERE id=?');$q->execute([$agentId]);if(version_compare((string)$q->fetchColumn(),'3.7.1','<'))arc_json(['ok'=>false,'error'=>'Atualize o agente para usar a verificação detalhada do Windows Update.'],409);
            $mode=(string)($body['update_mode']??'scan');if(!in_array($mode,['scan','install','history','pause','resume','settings','advanced','insider','latest_on','latest_off'],true))arc_json(['ok'=>false,'error'=>'Operação inválida'],422);
            $windowsRequest=['mode'=>$mode];
            if($mode==='install'){
                if(($body['confirmed']??false)!==true)arc_json(['ok'=>false,'error'=>'Confirme o download e a instalação.'],422);
                $scan=$pdo->prepare('SELECT t.output_text FROM '.arc_table('agent_terminal_jobs').' t JOIN '.arc_table('agent_commands')." c ON c.id=t.command_id WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name='windows_update' AND c.status='completed' AND c.completed_at>DATE_SUB(NOW(),INTERVAL 60 MINUTE)");$scan->execute([(int)($body['scan_id']??0),$agentId,$networkId,$commandAccount]);$report=json_decode((string)$scan->fetchColumn(),true);
                if(!is_array($report)||($report['mode']??'')!=='scan'||empty($report['updates'])||count($report['updates'])>50)arc_json(['ok'=>false,'error'=>'Verifique novamente as atualizações disponíveis.'],409);
                $windowsRequest['updates']=array_map(static fn($u)=>['id'=>$u['id'],'revision'=>$u['revision']],$report['updates']);
            }
            $busy=$pdo->prepare('SELECT id FROM '.arc_table('agent_commands')." WHERE agent_id=? AND command_name='windows_update' AND ((status='pending' AND created_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (status='running' AND dispatched_at>DATE_SUB(NOW(),INTERVAL 120 MINUTE))) LIMIT 1");$busy->execute([$agentId]);if($busy->fetchColumn())arc_json(['ok'=>false,'error'=>'Uma verificação ou instalação do Windows já está em andamento.'],409);
        }
        if($command==='agent_update'){
            // Uma fila remota só é executável com o cliente de comandos em funcionamento.
            // Presença antiga não é prova de execução: não declarar sucesso nem criar
            // comandos órfãos para computadores desligados ou sem comunicação.
            $v=$pdo->prepare('SELECT agent_version,last_seen_at FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=? LIMIT 1');
            $v->execute([$agentId,$networkId,$commandAccount]);
            $updateAgent=$v->fetch(PDO::FETCH_ASSOC);
            $lastSeen=!empty($updateAgent['last_seen_at'])?strtotime((string)$updateAgent['last_seen_at']):false;
            if(!$lastSeen || $lastSeen<time()-120){
                arc_json(['ok'=>false,'error'=>'O agente está sem comunicação recente. A atualização remota, inclusive forçada, precisa de um componente de manutenção previamente instalado e ativo. Inicie o agente ou use o instalador local autorizado.','error_code'=>'agent_offline','requires_local_action'=>true],409);
            }
            $policy=arc_agent_upgrade_policy(trim((string)($updateAgent['agent_version']??'')),arc_app_version());
            // A reinstalação forçada pode recuperar um agente aprovado cuja versão
            // não foi publicada corretamente; a identidade e a presença seguem obrigatórias.
            if($policy['requires_reinstall'] && empty($body['force_update']))arc_json(['ok'=>false,'error'=>$policy['message'],'error_code'=>'agent_reinstall_required','requires_reinstall'=>true,'download_url'=>arc_workspace_base_url().'api/download-agent.php?network='.$networkId],409);
            // O pacote legado é usado exclusivamente pelo cliente Python antigo.
            // O WinUI 3 recebe as fontes nativas por download-agent.php.
            $installedVersion=trim((string)($updateAgent['agent_version']??''));
            if(preg_match('/^[34]\.\d+\.\d+$/D',$installedVersion)){
                try{arc_require_aligned_agent_release();}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e->getMessage(),'error_code'=>'agent_release_incomplete','release'=>arc_agent_release_status()],503);}
            }
        }
        if (in_array($command,['power_shutdown','power_restart','power_cancel'],true)) {
            if (($body['confirmed']??false)!==true) arc_json(['ok'=>false,'error'=>'Confirme a operação no computador selecionado.'],422);
            $q=$pdo->prepare('SELECT agent_version,last_seen_at FROM '.arc_table('network_agents').' WHERE id=?');$q->execute([$agentId]);$powerTarget=$q->fetch();
            if(version_compare((string)$powerTarget['agent_version'],'3.2.0','<'))arc_json(['ok'=>false,'error'=>'Atualize o agente deste computador antes de usar o controle de energia.'],409);
            if(!$powerTarget['last_seen_at']||strtotime($powerTarget['last_seen_at'])<time()-120)arc_json(['ok'=>false,'error'=>'O computador precisa estar conectado.'],409);
        }
        arc_expire_running_commands($pdo, $agentId);
        /* Pendências mantidas para despacho sequencial. */
        // A fila persistente substituiu o limite legado de três operações.
        // Um teto amplo impede abuso sem bloquear atualizações atrás de outras tarefas.
        $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands').' WHERE agent_id=? AND status IN (\'pending\',\'running\')');
        $q->execute([$agentId]);if ((int)$q->fetchColumn()>=100) arc_json(['ok'=>false,'error'=>'Limite de 100 comandos pendentes neste agente. Cancele operações antigas para continuar.'],429);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO '.arc_table('agent_commands').' (account_id,network_id,agent_id,command_name) VALUES (?,?,?,?)')->execute([$commandAccount,$networkId,$agentId,$command]);
            $insertedId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$insertedId,(int)arc_user_id()]);
            if(in_array($command,['power_restart','power_shutdown','screen_lock'],true)){$pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$insertedId,$agentId,$networkId,json_encode(['delay_seconds'=>$delaySeconds])]);}
            if($windowsRequest!==null)$pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$insertedId,$agentId,$networkId,json_encode($windowsRequest)]);
            if($command==='agent_update')$pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$insertedId,$agentId,$networkId,json_encode(['mode'=>!empty($body['force_update'])?'force':'normal'])]);
            if($command==='terminal_diagnostic'){
                $pdo->prepare('INSERT INTO '.arc_table('agent_terminal_jobs').' (command_id,agent_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$insertedId,$agentId,$networkId,$terminalInput]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        arc_user_audit($pdo,'agent_command_queued','Comando '.$command.' para agente '.$agentId.' na rede '.$networkId);
        arc_json(['ok'=>true,'command_id'=>$insertedId,'status'=>'pending']);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-tool-result') {
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Entre na plataforma.'],401);
        $network=(int)($_GET['network_id']??0);$agent=(int)($_GET['agent_id']??0);$id=(int)($_GET['command_id']??0);
        $q=$pdo->prepare("SELECT c.id,c.status,c.result_text,c.command_name FROM ".arc_table('agent_commands')." c JOIN ".arc_table('managed_networks')." n ON n.id=c.network_id AND n.account_id=c.account_id JOIN ".arc_table('network_agents')." a ON a.id=c.agent_id AND a.network_id=n.id AND a.account_id=n.account_id WHERE c.id=? AND c.network_id=? AND c.agent_id=? AND c.account_id=? AND c.command_name IN ('quick_health','dns_test','system_snapshot','storage_health','service_health','restart_readiness','power_shutdown','power_restart','power_cancel')");
        $q->execute([$id,$network,$agent,(int)arc_user_id()]);$task=$q->fetch();if(!$task)arc_json(['ok'=>false,'error'=>'Verificação não encontrada.'],404);
        if(preg_match('/Comando não permitido|indisponível nesta versão/i',(string)$task['result_text']))$task['result_text']='Atualize o aplicativo deste computador para usar esta verificação.';
        $q=$pdo->prepare("SELECT payload_json FROM ".arc_table('agent_telemetry')." WHERE account_id=? AND network_id=? AND agent_id=? AND event_type='quick_command' ORDER BY id DESC LIMIT 100");$q->execute([(int)arc_user_id(),$network,$agent]);$result=null;
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $raw){$data=json_decode($raw,true);if(is_array($data)&&(int)($data['command_id']??0)===$id){$result=['summary'=>mb_substr((string)($data['summary']??''),0,480),'details'=>is_array($data['details']??null)?$data['details']:[]];break;}}
        arc_json(['ok'=>true,'task'=>$task,'result'=>$result]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-terminal-latest') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        $agentId=(int)($_GET['agent_id']??0);
        if($networkId<1 || $agentId<1) arc_json(['ok'=>false,'error'=>'Selecione um agente'],422);
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
        $q=$pdo->prepare('SELECT c.id,c.status,c.result_text,c.completed_at,COALESCE(t.output_text,\'\') AS output_text FROM '.arc_table('agent_commands').' c INNER JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id LEFT JOIN '.arc_table('agent_terminal_jobs').' t ON t.command_id=c.id WHERE c.id=? AND c.agent_id=? AND c.network_id=? AND c.account_id=? AND c.command_name=\'terminal_diagnostic\' LIMIT 1');
        $q->execute([$commandId,$agentId,$networkId,arc_user_id()]);
        $cmd=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cmd) arc_json(['ok'=>false,'error'=>'Diagnóstico não encontrado ou sem autorização'],404);
        arc_expire_running_commands($pdo,$agentId);
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
    /* Diagnóstico sob demanda: nunca expose todo o log do computador, apenas a falha autenticada do comando. */
    if ($_SERVER['REQUEST_METHOD']==='GET' && in_array($action,['network-command-diagnostic','network-command-errors'],true)) {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        try { $access=arc_support_access($pdo,$networkId,(int)arc_user_id()); }
        catch(RuntimeException $e) { arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403); }
        $account=(int)$access['account_id'];
        $sql='SELECT c.id,c.agent_id,c.command_name,c.status,c.result_text,c.created_at,c.dispatched_at,c.completed_at,a.machine_name,a.agent_version'
             .' FROM '.arc_table('agent_commands').' c JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id'
             .' WHERE c.network_id=? AND c.account_id=? AND c.status IN (\'failed\',\'expired\')';
        $params=[$networkId,$account];
        if (!in_array((string)$access['role'],['owner','administrator'],true)) {
            $sql.=' AND c.command_name=\'remote_assistance\' AND c.id IN (SELECT command_id FROM '.arc_table('support_remote_sessions').' WHERE technician_id=?)';
            $params[]=(int)arc_user_id();
        }
        if($action==='network-command-errors') {
            $agentId=max(0,(int)($_GET['agent_id']??0));
            if ($agentId>0){$sql.=' AND c.agent_id=?';$params[]=$agentId;}
            $q=$pdo->prepare($sql.' ORDER BY c.id DESC LIMIT 20');$q->execute($params);
            arc_json(['ok'=>true,'failures'=>array_map(static fn($r)=>[
              'command_id'=>(int)$r['id'],'agent_id'=>(int)$r['agent_id'],'command_name'=>$r['command_name'],
              'status'=>$r['status'],'summary'=>arc_command_error_scrub((string)$r['result_text'],500),
              'created_at'=>$r['created_at'],'completed_at'=>$r['completed_at']
            ],$q->fetchAll(PDO::FETCH_ASSOC))]);
        }
        $id=(int)($_GET['command_id']??0);
        if ($id<1) arc_json(['ok'=>false,'error'=>'Identificador inválido'],422);
        $q=$pdo->prepare($sql.' AND c.id=? LIMIT 1');$params[]=$id;$q->execute($params);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) arc_json(['ok'=>false,'error'=>'Diagnóstico indisponível ou sem permissão'],404);
        $saved=arc_command_error_read($pdo,$id,$networkId,$account);
        $summary=arc_command_error_scrub((string)$row['result_text'],500);
        $code=arc_command_error_code((string)$row['status'],(string)($saved['error_code']??''),$summary);
        $detail=arc_command_error_scrub((string)($saved['diagnostic_text']??$summary));
        if($detail==='')$detail=$row['status']==='expired'?'O agente não confirmou a execução no prazo esperado.':'O agente sinalizou falha, mas não enviou diagnóstico detalhado.';
        arc_json(['ok'=>true,'diagnostic'=>[
          'command_id'=>(int)$row['id'],'agent_id'=>(int)$row['agent_id'],'machine_name'=>mb_substr((string)$row['machine_name'],0,100),
          'agent_version'=>(string)$row['agent_version'],'command_name'=>(string)$row['command_name'],
          'status'=>(string)$row['status'],'error_code'=>$code,'summary'=>$summary,
          'details'=>$detail,'created_at'=>$row['created_at'],'dispatched_at'=>$row['dispatched_at'],'completed_at'=>$row['completed_at'],
          'source'=>$saved?'agent_report':'platform_status',
        ]]);
    }
    /* Fila visual persistente compartilhada por todas as páginas da plataforma. */
    if ($action==='network-queue') {
        if (!arc_is_user()) arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            arc_verify_csrf();
            $data=json_decode((string)file_get_contents('php://input'),true);
            if (is_array($data) && array_key_exists('view_state',$data)) {
                $view=(string)$data['view_state'];
                if (!in_array($view,['open','closed'],true))arc_json(['ok'=>false,'error'=>'Preferência inválida'],422);
                $q=$pdo->prepare('INSERT INTO '.arc_table('user_preferences').' (user_id,pref_key,pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)');
                $q->execute([(int)arc_user_id(),'command_queue_view',$view]);
                arc_json(['ok'=>true,'view_state'=>$view]);
            }
            $id=(int)($data['command_id']??0);
            if ($id<1) arc_json(['ok'=>false,'error'=>'Comando inválido'],422);
            $q=$pdo->prepare('SELECT id,network_id,account_id,command_name,status FROM '.arc_table('agent_commands').' WHERE id=? LIMIT 1');
            $q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if (!$c)arc_json(['ok'=>false,'error'=>'Comando não encontrado'],404);
            $access=arc_network_team_access($pdo,(int)$c['network_id'],(int)arc_user_id());
            if (!$access || (int)$access['account_id']!==(int)$c['account_id'] || !in_array($access['role'],['owner','administrator'],true))arc_json(['ok'=>false,'error'=>'Sem permissão'],403);
            // Cancelamento forçado encerra apenas o registro da fila; NÃO encerra o processo no Windows.
            // A máquina deve confirmar separadamente a interrupção de qualquer execução iniciada.
            $force=isset($data['force']) && $data['force']===true;
            if(!in_array($c['status'],['pending','running'],true))
                arc_json(['ok'=>true,'cancelled'=>false,'already_finished'=>true]);
            if($c['status']==='running' && !$force)
                arc_json(['ok'=>false,'error'=>'Comando iniciado. Use Forçar cancelamento para liberar a fila; a execução no Windows poderá continuar.'],409);
            $wasRunning=$c['status']==='running';
            $newResult=$wasRunning?'Cancelamento forçado na plataforma; interrupção no Windows não confirmada':'Cancelado antes da execução';
            $q=$pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status='failed',result_text=?,completed_at=NOW() WHERE id=? AND status=?");
            $q->execute([$newResult,$id,$c['status']]);
            if(!$q->rowCount())arc_json(['ok'=>false,'error'=>'Estado alterado. Atualize a fila.'],409);
            arc_user_audit($pdo,$wasRunning?'queue_force_cancel':'queue_cancel','Comando '.$id.' encerrado na fila'.($wasRunning?' durante execução':''));
            arc_json(['ok'=>true,'cancelled'=>true,'execution_stop_confirmed'=>!$wasRunning]);
        }
        if ($_SERVER['REQUEST_METHOD']!=='GET')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
        $q=$pdo->prepare('SELECT c.id,c.network_id,c.agent_id,c.account_id,c.command_name,c.status,c.result_text,c.created_at,c.dispatched_at,c.completed_at,a.machine_name FROM '.arc_table('agent_commands').' c LEFT JOIN '.arc_table('network_agents').' a ON a.id=c.agent_id AND a.network_id=c.network_id AND a.account_id=c.account_id WHERE (c.status IN (\'pending\',\'running\') OR c.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) AND (c.account_id=? OR EXISTS (SELECT 1 FROM '.arc_table('network_team_members').' tm WHERE tm.network_id=c.network_id AND tm.account_id=c.account_id AND tm.member_user_id=? AND tm.enabled=1)) ORDER BY c.id DESC LIMIT 300');
        $uid=(int)arc_user_id();$q->execute([$uid,$uid]);$out=[];$permissions=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c){
            $nid=(int)$c['network_id'];if(!array_key_exists($nid,$permissions)){
                $a=arc_network_team_access($pdo,$nid,$uid);
                $permissions[$nid]=$a&&in_array($a['role'],['owner','administrator'],true)?(int)$a['account_id']:0;
            }
            if(!$permissions[$nid]||(int)$c['account_id']!==$permissions[$nid])continue;
            $out[]=['id'=>(int)$c['id'],'network_id'=>$nid,'agent_id'=>(int)$c['agent_id'],'agent'=>(string)($c['machine_name']??''),'name'=>(string)$c['command_name'],'status'=>(string)$c['status'],'message'=>mb_substr((string)$c['result_text'],0,200),'created_at'=>(string)$c['created_at'],'completed_at'=>$c['completed_at']];
        }
        $pref=$pdo->prepare('SELECT pref_value FROM '.arc_table('user_preferences').' WHERE user_id=? AND pref_key=? LIMIT 1');
        $pref->execute([$uid,'command_queue_view']);
        $view=$pref->fetchColumn();
        arc_json(['ok'=>true,'commands'=>$out,'view_state'=>in_array($view,['open','closed'],true)?$view:null]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='network-command-status') {
        if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Acesso não autorizado'],401);
        $networkId=(int)($_GET['network_id']??0);
        try{$access=arc_support_access($pdo,$networkId,(int)arc_user_id());}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>'Rede não autorizada'],403);}
        $account=(int)$access['account_id'];
        arc_expire_running_commands($pdo,null,$networkId,$account);
        $sql='SELECT id,agent_id,command_name,status,result_text,created_at,completed_at FROM '.arc_table('agent_commands').' WHERE network_id=? AND account_id=?';$params=[$networkId,$account];
        if(!in_array($access['role'],['owner','administrator'],true)){$sql.=" AND command_name='remote_assistance' AND id IN (SELECT command_id FROM ".arc_table('support_remote_sessions').' WHERE technician_id=?)';$params[]=(int)arc_user_id();}
        $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 20');$q->execute($params);$commands=$q->fetchAll(PDO::FETCH_ASSOC);
        $agents=$pdo->prepare('SELECT id,UNIX_TIMESTAMP(last_seen_at) AS last_seen_unix,status FROM '.arc_table('network_agents').' WHERE network_id=? AND account_id=?');$agents->execute([$networkId,$account]);
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

    /* Recuperação de credencial: código local apenas solicita aprovação, nunca libera acesso. */
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['agent-rebind-request','agent-rebind-poll'],true)) {
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw)>4096) arc_json(['ok'=>false,'error'=>'Requisição inválida.'],413);
        $v=json_decode($raw,true);
        if (!is_array($v)) arc_json(['ok'=>false,'error'=>'Dados inválidos.'],400);
        $fp=strtolower(trim((string)($v['fingerprint']??'')));
        $code=strtoupper(trim((string)($v['site_code']??'')));
        $secret=strtolower(trim((string)($v['enrollment_secret']??'')));
        if (!preg_match('/^[a-f0-9]{64}$/D',$fp) || !preg_match('/^[A-Z0-9]{5}$/D',$code) || !preg_match('/^[a-f0-9]{64}$/D',$secret)) {
            arc_json(['ok'=>false,'error'=>'Identificação ou código local inválido.'],422);
        }
        $tokenHash=hash('sha256',$secret);
        if ($action==='agent-rebind-poll') {
            $q=$pdo->prepare('SELECT r.status,n.is_active FROM '.arc_table('agent_rebind_requests').' r JOIN '.arc_table('managed_networks').' n ON n.id=r.network_id AND n.account_id=r.account_id WHERE r.fingerprint=? AND r.site_code=? AND r.token_hash=? LIMIT 1');
            $q->execute([$fp,$code,$tokenHash]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if (!$r) arc_json(['ok'=>false,'error'=>'Solicitação não encontrada.'],404);
            if (!(int)$r['is_active']) arc_json(['ok'=>false,'error'=>'Rede inativa.'],403);
            arc_json(['ok'=>true,'status'=>(string)$r['status']]);
        }
        $attempt=arc_login_attempt_begin($pdo,'agent-rebind:'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').$fp));
        if ($attempt===null) {header('Retry-After: 900');arc_json(['ok'=>false,'error'=>'Limite de solicitações. Aguarde 15 minutos.'],429);}
        $q=$pdo->prepare('SELECT id,account_id,monitoring_enabled FROM '.arc_table('managed_networks').' WHERE UPPER(site_code)=? AND is_active=1 LIMIT 1');
        $q->execute([$code]);$network=$q->fetch(PDO::FETCH_ASSOC);
        if (!$network || !(int)$network['monitoring_enabled']) arc_json(['ok'=>false,'error'=>'Código local inválido ou rede indisponível.'],404);
        // Nunca permitir que o código local transfira equipamento de outra rede.
        $prior=$pdo->prepare('SELECT id,network_id,account_id FROM '.arc_table('network_agents').' WHERE fingerprint=? LIMIT 1');
        $prior->execute([$fp]);$previous=$prior->fetch(PDO::FETCH_ASSOC);
        // Redes da mesma conta (mesmo gestor) podem receber o computador: o pedido aparece em
        // Redes / Liberação da rede de destino e só vale após a aprovação expressa do gestor.
        // Redes de contas diferentes continuam exigindo a transferência autenticada.
        if ($previous && (int)$previous['account_id']!==(int)$network['account_id']) {
            arc_json(['ok'=>false,'error'=>'Este computador está vinculado a outra conta. Peça ao gestor anterior para desinstalar o agente ou autorizar a transferência.'],409);
        }
        $machine=mb_substr(trim((string)($v['machine_name']??'Computador')),0,190);
        if ($machine==='') $machine='Computador';
        // Evitar substituição repetida de uma solicitação ainda pendente.
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT id,token_hash,status FROM '.arc_table('agent_rebind_requests').' WHERE fingerprint=? FOR UPDATE');
            $q->execute([$fp]);$pending=$q->fetch(PDO::FETCH_ASSOC);
            if ($pending && $pending['status']==='pending' && !hash_equals((string)$pending['token_hash'],$tokenHash)) {
                throw new RuntimeException('Existe solicitação pendente para este computador. Consulte o gestor.');
            }
            if ($pending) {
                $q=$pdo->prepare("UPDATE ".arc_table('agent_rebind_requests')." SET site_code=?,network_id=?,account_id=?,machine_name=?,token_hash=?,status='pending',requested_at=NOW() WHERE id=?");
                $q->execute([$code,(int)$network['id'],(int)$network['account_id'],$machine,$tokenHash,(int)$pending['id']]);
            } else {
                $q=$pdo->prepare("INSERT INTO ".arc_table("agent_rebind_requests")." (fingerprint,site_code,network_id,account_id,machine_name,token_hash,status) VALUES (?,?,?,?,?,?,?)");
                $q->execute([$fp,$code,(int)$network['id'],(int)$network['account_id'],$machine,$tokenHash,'pending']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())$pdo->rollBack();
            arc_json(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Não foi possível solicitar liberação.'],409);
        }
        arc_login_attempt_succeeded($pdo,$attempt);
        arc_user_audit($pdo,'agent_rebind_requested','Solicitação de credencial: '.$machine.' ('.$code.')',(int)$network['account_id']);
        header('Cache-Control: no-store, private');
        arc_json(['ok'=>true,'status'=>'pending','message'=>'Solicitação enviada para Redes / Liberação.']);
    }

    /* API pública restrita do agente desktop: nenhum login web é exigido no primeiro cadastro. */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['agent-register','agent-poll','agent-data','agent-transfer','agent-uninstall-confirm'], true)) {
        $rawAgent = file_get_contents('php://input');
        if (!is_string($rawAgent) || strlen($rawAgent) > 240000) arc_json(['ok'=>false,'error'=>'Solicitação inválida.'],413);
        $agentData = json_decode($rawAgent, true);
        if (!is_array($agentData)) arc_json(['ok'=>false,'error'=>'Dados do agente inválidos.'],400);
        $fingerprint = strtolower(trim((string)($agentData['fingerprint'] ?? '')));
        $siteCode = strtoupper(trim((string)($agentData['site_code'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !preg_match('/^[A-Z0-9]{5}$/', $siteCode)) arc_json(['ok'=>false,'error'=>'Informe um código de local e uma identificação válida deste computador.'],422);

        if ($action === 'agent-transfer') {
            // A troca de rede exige a senha do proprietário da rede anterior.
            // A chave de instalação de destino ainda precisa estar válida.
            $password=(string)($agentData['previous_owner_password'] ?? '');
            $newKey=strtolower(trim((string)($agentData['download_key'] ?? '')));
            if ($password==='' || strlen($password)>512 || !preg_match('/^[a-f0-9]{64}$/',$newKey)) {
                arc_json(['ok'=>false,'error'=>'Informe a senha do proprietário anterior e use um instalador válido.'],403);
            }
            $attemptId=arc_login_attempt_begin($pdo,'agent-transfer:'.$fingerprint);
            if ($attemptId===null) {
                header('Retry-After: 900');
                arc_json(['ok'=>false,'error'=>'Muitas tentativas. Tente novamente em 15 minutos.'],429);
            }
            // Autenticar antes de bloquear registros; nunca aceitar só um identificador de máquina.
            $oldQ=$pdo->prepare('SELECT a.id,a.network_id,a.account_id,u.password_hash,u.account_status FROM '
                .arc_table('network_agents').' a JOIN '.arc_table('user_accounts').' u ON u.id=a.account_id '
                .'WHERE a.fingerprint=? ORDER BY a.id DESC LIMIT 1');
            $oldQ->execute([$fingerprint]);
            $old=$oldQ->fetch(PDO::FETCH_ASSOC);
            $material=base64_encode(hash('sha384',$password,true));
            if (!$old || (string)($old['account_status']??'active')!=='active' ||
                !(password_verify($material,(string)$old['password_hash']) || password_verify($password,(string)$old['password_hash']))) {
                arc_json(['ok'=>false,'error'=>'Senha do proprietário da rede anterior incorreta.'],403);
            }
            unset($password,$material);
            $pdo->beginTransaction();
            try {
                $getOld=$pdo->prepare('SELECT id,network_id,account_id FROM '.arc_table('network_agents').' WHERE id=? AND fingerprint=? FOR UPDATE');
                $getOld->execute([(int)$old['id'],$fingerprint]);
                $previous=$getOld->fetch(PDO::FETCH_ASSOC);
                $getNew=$pdo->prepare('SELECT k.id,k.network_id,k.account_id,k.site_code,k.fingerprint,n.is_active FROM '.arc_table('agent_download_keys').' k JOIN '.arc_table('managed_networks').' n ON n.id=k.network_id AND n.account_id=k.account_id WHERE k.token_hash=? FOR UPDATE');
                $getNew->execute([hash('sha256',$newKey)]);
                $target=$getNew->fetch(PDO::FETCH_ASSOC);
                if (!$previous || !$target || (int)$target['is_active']!==1 || strtoupper((string)$target['site_code'])!==$siteCode ||
                    ((string)($target['fingerprint']??'')!=='' && !hash_equals((string)$target['fingerprint'],$fingerprint)) ||
                    (int)$previous['network_id']===(int)$target['network_id']) {
                    throw new RuntimeException('Vínculo anterior ou instalador de destino inválido.');
                }
                // Preserva o histórico e libera o identificador único para o novo pedido.
                $historic=hash('sha256','historico:'.$fingerprint.':'.$previous['id']);
                $pdo->prepare('UPDATE '.arc_table('network_agents')." SET fingerprint=?,status='blocked',ui_locked=1,notes=? WHERE id=? AND fingerprint=?")
                    ->execute([$historic,mb_substr('TRANSFERIDO|'.date('Y-m-d H:i:s').'|Novo local: '.$siteCode,0,500),(int)$previous['id'],$fingerprint]);
                $pdo->prepare('UPDATE '.arc_table('agent_download_keys').' SET fingerprint=?,used_at=CURRENT_TIMESTAMP WHERE id=? AND (fingerprint IS NULL OR fingerprint=?)')
                    ->execute([$fingerprint,(int)$target['id'],$fingerprint]);
                $pdo->prepare('INSERT INTO '.arc_table('network_agents').' (fingerprint,account_id,network_id,site_code,machine_name,platform,status,notes) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$fingerprint,(int)$target['account_id'],(int)$target['network_id'],$siteCode,mb_substr((string)($agentData['machine_name']??'Computador'),0,190),mb_substr((string)($agentData['platform']??'Windows'),0,190),'pending','Solicitação após transferência autorizada pelo proprietário anterior']);
                $newId=(int)$pdo->lastInsertId();
                $pdo->commit();
                arc_login_attempt_succeeded($pdo,$attemptId);
                arc_user_audit($pdo,'agent_transfer_requested','Transferência autenticada do agente '.$previous['id'].'; solicitação '.$newId.' para rede '.$target['network_id'],(int)$target['account_id']);
                arc_json(['ok'=>true,'status'=>'pending','agent_id'=>$newId,'message'=>'Transferência autorizada. Aguardando liberação na nova rede.']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                arc_json(['ok'=>false,'error'=>$e instanceof RuntimeException?$e->getMessage():'Não foi possível concluir a transferência.'],409);
            }
        }

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

            /* Computador que já pertence a OUTRA rede (reinstalação para um novo local).
               A chave NÃO é consumida aqui: o pedido é registrado como solicitação pendente em
               Redes / Liberação da rede de destino e a chave só é vinculada na aprovação do gestor. */
            $belongs = $pdo->prepare('SELECT id,network_id,account_id FROM ' . arc_table('network_agents') . ' WHERE fingerprint=? LIMIT 1');
            $belongs->execute([$fingerprint]);
            $belongsRow = $belongs->fetch(PDO::FETCH_ASSOC);
            if ($belongsRow && ((int)$belongsRow['network_id'] !== (int)$networkRow['id'] || (int)$belongsRow['account_id'] !== (int)$networkRow['account_id'])) {
                if ((int)$belongsRow['account_id'] !== (int)$networkRow['account_id']) {
                    arc_json(['ok'=>false,'error'=>'Este computador está vinculado a outra conta. Peça ao gestor anterior para desinstalar o agente ou autorizar a transferência.'],409);
                }
                $claimFp = strtolower(trim((string)($downloadKeyRow['fingerprint'] ?? '')));
                if ($claimFp !== '' && !hash_equals($claimFp, $fingerprint)) {
                    arc_json(['ok'=>false,'error'=>'ESTA CHAVE JÁ FOI UTILIZADA EM OUTRO COMPUTADOR. Baixe um novo arquivo de agente para instalar em outra máquina.'],409);
                }
                $moveHash = hash('sha256', $downloadKey);
                $moveMachine = mb_substr(trim((string)($agentData['machine_name'] ?? 'Computador')),0,190);
                if ($moveMachine === '') $moveMachine = 'Computador';
                $pdo->beginTransaction();
                try {
                    $rq = $pdo->prepare('SELECT id,token_hash,status FROM ' . arc_table('agent_rebind_requests') . ' WHERE fingerprint=? FOR UPDATE');
                    $rq->execute([$fingerprint]);
                    $moveReq = $rq->fetch(PDO::FETCH_ASSOC);
                    $moveStatus = 'pending';
                    if ($moveReq && hash_equals((string)$moveReq['token_hash'], $moveHash) && in_array((string)$moveReq['status'], ['rejected','approved'], true)) {
                        // Decisão já tomada para esta mesma chave: não reabrir a solicitação a cada tentativa do agente.
                        $moveStatus = (string)$moveReq['status'];
                    } elseif ($moveReq) {
                        $pdo->prepare('UPDATE ' . arc_table('agent_rebind_requests') . " SET site_code=?,network_id=?,account_id=?,machine_name=?,token_hash=?,status='pending',requested_at=NOW(),reviewed_at=NULL WHERE id=?")
                            ->execute([$siteCode,(int)$networkRow['id'],(int)$networkRow['account_id'],$moveMachine,$moveHash,(int)$moveReq['id']]);
                    } else {
                        $pdo->prepare('INSERT INTO ' . arc_table('agent_rebind_requests') . ' (fingerprint,site_code,network_id,account_id,machine_name,token_hash,status) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$fingerprint,$siteCode,(int)$networkRow['id'],(int)$networkRow['account_id'],$moveMachine,$moveHash,'pending']);
                        arc_user_audit($pdo,'agent_move_requested','Computador já vinculado a outra rede solicitou liberação: '.$moveMachine.' ('.$siteCode.')',(int)$networkRow['account_id']);
                    }
                    $pdo->commit();
                } catch (Throwable $moveError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $moveError;
                }
                header('Cache-Control: no-store, private');
                arc_json(['ok'=>true,'status'=>$moveStatus,'message'=>'Solicitação enviada para Redes / Liberação.']);
            }
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
                // Uma instalação revogada só volta a pendente após autenticação de uma NOVA chave de instalação.
                $isRemoved = $agentStatus==='blocked' && (str_starts_with((string)($agentRow['notes']??''),'DESINSTALADO|') || (string)($agentRow['notes']??'')==='Instalação revogada pelo gestor');
                if ($isRemoved) {
                    $pdo->prepare('UPDATE '.arc_table('network_agents')." SET status='pending',ui_locked=0,uninstall_requested=0,notes='' WHERE id=? AND network_id=? AND account_id=?")->execute([$agentId,(int)$networkRow['id'],(int)$networkRow['account_id']]);
                    $agentStatus = 'pending';
                }
                $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET machine_name=?,platform=?,local_ip=?,gateway_ip=?,agent_version=?,machine_details=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$machine,$platform,$localIp,$gatewayIp,$version,$machineDetails,$agentId]);
            }
            arc_json(['ok'=>true,'status'=>$agentStatus,'agent_id'=>$agentId,'message'=>match($agentStatus){'approved'=>'Agente autorizado.','blocked'=>'Agente bloqueado pelo gestor.','rejected'=>'Cadastro rejeitado pelo gestor.',default=>'Solicitação enviada. Aguarde a aprovação do gestor da rede.'}]);
        }

        if ($action === 'agent-uninstall-confirm') {
            // O agente (ou o componente SYSTEM) confirma que vai se remover. Só a chave da instalação autoriza.
            $downloadKey = trim((string)($agentData['download_key'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $downloadKey)) arc_json(['ok'=>false,'error'=>'Chave da instalação inválida.'],403);
            $tokenHash = hash('sha256',$downloadKey);
            $confirmed = false;
            $pdo->beginTransaction();
            try {
                $drop = $pdo->prepare('DELETE FROM '.arc_table('agent_removal_queue').' WHERE fingerprint=? AND token_hash=?');
                $drop->execute([$fingerprint,$tokenHash]);
                if ($drop->rowCount() > 0) $confirmed = true;
                $find = $pdo->prepare('SELECT a.id,a.account_id,a.network_id FROM '.arc_table('network_agents').' a JOIN '.arc_table('agent_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.uninstall_requested=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1 FOR UPDATE');
                $find->execute([$fingerprint,$siteCode,$tokenHash,$fingerprint]);
                $target = $find->fetch(PDO::FETCH_ASSOC);
                if ($target) {
                    $pdo->prepare('UPDATE '.arc_table('network_agents').' SET status=\'blocked\',ui_locked=0,uninstall_requested=0,notes=? WHERE id=? AND account_id=? AND network_id=?')
                        ->execute(['DESINSTALADO|'.date('Y-m-d H:i:s').'|plataforma',(int)$target['id'],(int)$target['account_id'],(int)$target['network_id']]);
                    $pdo->prepare('DELETE FROM '.arc_table('agent_download_keys').' WHERE account_id=? AND network_id=? AND fingerprint=?')
                        ->execute([(int)$target['account_id'],(int)$target['network_id'],$fingerprint]);
                    $confirmed = true;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                arc_json(['ok'=>false,'error'=>'Não foi possível confirmar a desinstalação.'],409);
            }
            if (isset($target) && $target) arc_user_audit($pdo,'network_agent_uninstalled','Agente desinstalado pela plataforma: '.$siteCode.' / agente '.(int)$target['id'],(int)$target['account_id']);
            header('Cache-Control: no-store, private');
            arc_json(['ok'=>true,'confirmed'=>$confirmed]);
        }

        if ($action === 'agent-poll') {
        $downloadKey = trim((string)($agentData['download_key'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $downloadKey)) arc_json(['ok'=>false,'error'=>'Chave da instalação inválida.'],403);
        $tokenHash = hash('sha256',$downloadKey);
        // Rede excluída pelo gestor: a máquina ainda recebe a ordem de se desinstalar.
        try {
            $removed = $pdo->prepare('SELECT id FROM '.arc_table('agent_removal_queue').' WHERE fingerprint=? AND token_hash=? LIMIT 1');
            $removed->execute([$fingerprint,$tokenHash]);
            if ($removed->fetchColumn()) arc_json(['ok'=>true,'status'=>'removed','uninstall'=>true,'ui_locked'=>false,'message'=>'Esta rede foi excluída. O agente será desinstalado.']);
        } catch (PDOException $e) { /* fila ainda não criada: segue o fluxo normal */ }
        $keyCheck=$pdo->prepare('SELECT id FROM '.arc_table('agent_download_keys').' WHERE token_hash=? AND UPPER(site_code)=? AND fingerprint=? LIMIT 1');
        $keyCheck->execute([$tokenHash,$siteCode,$fingerprint]);
        if (!$keyCheck->fetchColumn()) arc_json(['ok'=>false,'error'=>'Instalação não autorizada.'],403);
        $poll = $pdo->prepare('SELECT a.status,a.ui_locked,a.uninstall_requested,a.id,a.network_id,n.name AS network_name,n.is_active,n.monitoring_enabled FROM ' . arc_table('network_agents') . ' a JOIN ' . arc_table('managed_networks') . ' n ON n.id=a.network_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? LIMIT 1');
        $poll->execute([$fingerprint,$siteCode]);
        $agentRow = $poll->fetch();
        if (!$agentRow) arc_json(['ok'=>false,'status'=>'unregistered','error'=>'Agente ainda não cadastrado para este local.'],404);
        $pdo->prepare('UPDATE ' . arc_table('network_agents') . ' SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$agentRow['id']]);
        $status = (string)$agentRow['status'];
        // Desinstalação solicitada pelo gestor: vale para qualquer situação em que o agente ainda consiga consultar.
        if ((int)$agentRow['uninstall_requested'] === 1) arc_json(['ok'=>true,'status'=>$status,'uninstall'=>true,'ui_locked'=>false,'network_name'=>(string)$agentRow['network_name'],'message'=>'Desinstalação solicitada pelo gestor.']);
        if (((int)$agentRow['is_active'] !== 1 || (int)$agentRow['monitoring_enabled'] !== 1) && $status === 'approved') arc_json(['ok'=>true,'status'=>'blocked','uninstall'=>false,'message'=>'A rede está inativa ou com monitoramento desativado.']);
        arc_json(['ok'=>true,'status'=>$status,'uninstall'=>false,'network_name'=>(string)$agentRow['network_name'],'ui_locked'=>(bool)$agentRow['ui_locked'],'message'=>match($status){'approved'=>((int)$agentRow['ui_locked']===1?'Agente autorizado. Tela protegida por login.':'Agente autorizado.'),'blocked'=>'Acesso bloqueado pelo gestor.','rejected'=>'Cadastro rejeitado pelo gestor.',default=>'Aguardando aprovação do gestor da rede.'}]);
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
        // Evita inflar o SQL com 40-50 digitos de casas decimais de metricas.
        // Nao modifica credenciais, eventos de seguranca, dispositivos nem resultados de comandos.
        if (in_array($eventType,['server_metrics','telemetry','dashboard','speed_progress'],true)) {
            $compactMetrics = static function($value, int $depth=0) use (&$compactMetrics) {
                if (is_float($value)) return round($value,4);
                if (!is_array($value) || $depth>=12) return $value;
                foreach ($value as $key=>$child) $value[$key]=$compactMetrics($child,$depth+1);
                return $value;
            };
            $event=$compactMetrics($event);
        }
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
        } elseif ($eventType === 'server_metrics') {
            // Quando o administrador ativa a politica de armazenamento, reduz amostras
            // repetidas de metricas. Atualiza last_seen_at mesmo sem gravar a amostra.
            require_once dirname(__DIR__).'/includes/storage-policy.php';
            $storePolicy=arc_storage_policy($pdo);
            if($storePolicy['enabled']){
                $interval=(int)$storePolicy['metrics_interval'];
                $recent=$pdo->prepare('SELECT id FROM '.arc_table('agent_telemetry')." WHERE agent_id=? AND event_type='server_metrics' AND captured_at >= DATE_SUB(NOW(), INTERVAL $interval SECOND) ORDER BY id DESC LIMIT 1");
                $recent->execute([$agentId]);
                $storeEvent=!$recent->fetchColumn();
            }
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
    $reference=bin2hex(random_bytes(6));
    error_log('Arcoll API ['.$reference.'] action='.preg_replace('/[^A-Za-z0-9_-]/','',(string)($_GET['action']??'')).' '.get_class($exception).': '.$exception->getMessage());
    arc_json(['ok'=>false,'error'=>'Não foi possível concluir a solicitação. Tente novamente e, se continuar, informe a referência ao suporte.','error_ref'=>$reference],500);
}
