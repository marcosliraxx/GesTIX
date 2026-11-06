<?php
/* Caminho: api/cloud.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    $action=(string)($_GET['action']??'');
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='download') {
        $pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);
        if (!arc_is_user()) throw new RuntimeException('Entre novamente na plataforma.',401);
        $t=arc_cloud_state_verify((string)($_GET['token']??''));
        if (!$t || ($t['k']??'')!=='dl' || (int)($t['u']??0)!==(int)arc_user_id()) throw new RuntimeException('Link de download inválido ou expirado.',403);
        $access=arc_cloud_access($pdo,(int)$t['net'],(int)arc_user_id());$account=(int)$access['account_id'];
        arc_cloud_agent_row($pdo,(int)$t['ag'],(int)$t['net'],$account);
        $connection=arc_cloud_connection_get($pdo,(int)$t['c'],(int)$t['net'],$account);
        if(!$connection)throw new RuntimeException('Conexão não encontrada.',404);
        arc_cloud_assert_destination_allowed($pdo,$connection);
        $job=arc_cloud_job_get($pdo,(int)$t['ag'],(int)$t['net'],$account);
        $parts=arc_cloud_relative_parts((string)$t['p']);
        if(!$parts)throw new InvalidArgumentException('Escolha um arquivo.');
        $segments=array_merge(arc_cloud_scope_segments($connection,(string)$job['settings']['remote_root'],(int)$t['net'],(int)$t['ag']),$parts);
        arc_user_audit($pdo,'cloud_download','Arquivo baixado do backup do computador '.(int)$t['ag']);
        arc_cloud_stream_file($pdo,$connection,$segments,!empty($t['i']),isset($_SERVER['HTTP_RANGE'])?(string)$_SERVER['HTTP_RANGE']:null);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') arc_json(['ok'=>false,'error'=>'Use POST.'],405);
    $pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);
    if (str_starts_with($action,'platform-')) {
        if (!arc_is_admin() || !hash_equals(arc_csrf_token(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new RuntimeException('Acesso administrativo necessário.',403);
        $payload=json_decode((string)file_get_contents('php://input'),true);
        if(!is_array($payload))throw new InvalidArgumentException('Dados inválidos.');
        if($action==='platform-connections'){
            $q=$pdo->query('SELECT * FROM '.arc_table('cloud_connections').' WHERE network_id=0 AND account_id=0 ORDER BY provider,id');
            $connections=array_map('arc_cloud_connection_public',$q->fetchAll(PDO::FETCH_ASSOC));
            arc_json(['ok'=>true,'connections'=>$connections]);
        }
        if($action==='platform-connect'){
            $provider=(string)($payload['provider']??'');
            if(!isset(arc_cloud_providers()[$provider]))throw new InvalidArgumentException('Provedor inválido.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('cloud_connections').' WHERE network_id=0 AND account_id=0 AND provider=?');$q->execute([$provider]);
            if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Já existe um Drive da plataforma para este provedor. Desconecte-o antes de substituí-lo.');
            if($provider==='s3'){
                $config=arc_cloud_provider_config($pdo,'s3');if(!$config['ready'])throw new InvalidArgumentException('Habilite o Amazon S3 na aba Drive antes de conectar.');
                $secret=arc_cloud_s3_clean_input((array)($payload['credentials']??[]),$config);
                $id=arc_cloud_connection_create($pdo,0,0,(int)arc_admin_id(),'s3',$secret,'Drive da plataforma');
                arc_json(['ok'=>true,'connection_id'=>$id]);
            }
            $url=arc_cloud_oauth_start($pdo,$provider,0,0,(int)arc_admin_id(),true);
            arc_json(['ok'=>true,'url'=>$url]);
        }
        if($action==='platform-disconnect'){
            $id=(int)($payload['connection_id']??0);
            $q=$pdo->prepare('SELECT * FROM '.arc_table('cloud_connections').' WHERE id=? AND network_id=0 AND account_id=0');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Conexão não encontrada.',404);
            if(($payload['confirmed']??false)!==true)throw new InvalidArgumentException('Confirme a desconexão.');
            arc_cloud_connection_delete($pdo,$row);arc_json(['ok'=>true]);
        }
        throw new RuntimeException('Ação inválida.',404);
    }
    $isAgent=str_starts_with($action,'agent-');
    $isChunk=$action==='agent-chunk';
    if ($isAgent) {
        $agent=arc_agent_authenticate($pdo,['fingerprint'=>$_SERVER['HTTP_X_ARCOLL_FINGERPRINT']??'', 'site_code'=>$_SERVER['HTTP_X_ARCOLL_SITE_CODE']??'', 'download_key'=>$_SERVER['HTTP_X_ARCOLL_KEY']??'']);
        $network=(int)$agent['network_id'];$account=(int)$agent['account_id'];$agentId=(int)$agent['id'];
    } else {
        if (!arc_is_user()) throw new RuntimeException('Entre novamente na plataforma.',401);
        if (!hash_equals(arc_csrf_token(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new RuntimeException('Sessão expirada.',403);
    }
    $max=$isChunk?5242880:65536;
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>$max) throw new RuntimeException('Requisição muito grande.',413);
    $raw=file_get_contents('php://input',false,null,0,$max+1);
    if (!is_string($raw)||strlen($raw)>$max) throw new RuntimeException('Requisição muito grande.',413);
    $v=$isChunk?[]:json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($v)) throw new InvalidArgumentException('Dados inválidos.');
    if (!$isAgent) {
        $network=(int)($v['network_id']??0);$agentId=(int)($v['agent_id']??0);
        $access=arc_cloud_access($pdo,$network,(int)arc_user_id());$account=(int)$access['account_id'];
        arc_cloud_agent_row($pdo,$agentId,$network,$account);
    }
    if ($action==='state') {
        $agentRow=$pdo->prepare('SELECT machine_name,agent_version,last_seen_at,headless_mode FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=?');
        $agentRow->execute([$agentId,$network,$account]);$info=$agentRow->fetch(PDO::FETCH_ASSOC)?:[];
        $platformVersion='';try{$platformVersion=arc_app_version();}catch(Throwable $e){}
        $seen=(string)($info['last_seen_at']??'');
        arc_json(['ok'=>true,'providers'=>arc_cloud_available_providers($pdo),'connections'=>arc_cloud_connections_list($pdo,$network,$account),
            'job'=>arc_cloud_job_get($pdo,$agentId,$network,$account),'status'=>arc_cloud_status_get($pdo,$agentId,$network,$account),
            'policy'=>arc_cloud_policy($pdo),
            'agent'=>['name'=>(string)($info['machine_name']??''),'version'=>(string)($info['agent_version']??''),'platform_version'=>$platformVersion,
                'online'=>$seen!==''&&strtotime($seen)>=time()-120,'last_seen'=>$seen,'headless'=>(int)($info['headless_mode']??0)===1,
                'outdated'=>$platformVersion!==''&&(string)($info['agent_version']??'')!==''&&version_compare((string)$info['agent_version'],$platformVersion,'<')]]);
    }
    if ($action==='save') {
        $settings=is_array($v['settings']??null)?$v['settings']:[];
        // As pastas são escolhidas por gestores autenticados na plataforma.
        $settings=arc_cloud_validate_settings($settings);
        if (!empty($v['enabled']) && !$settings['folders']) throw new InvalidArgumentException('Configure ao menos uma pasta do computador na plataforma.');
        arc_cloud_job_save($pdo,$agentId,$network,$account,($v['connection_id']??null)?(int)$v['connection_id']:null,!empty($v['enabled']),$settings);
        arc_user_audit($pdo,'cloud_settings','Configuração de backup alterada no computador '.$agentId);
        arc_json(['ok'=>true]);
    }
    if ($action==='browse') {
        $job=arc_cloud_job_get($pdo,$agentId,$network,$account);
        $connectionId=(int)($v['connection_id']??$job['connection_id']??0);
        $connection=arc_cloud_connection_get($pdo,$connectionId,$network,$account);
        if(!$connection)throw new RuntimeException('Conecte uma conta para visualizar os arquivos.',404);
        $path=(string)($v['path']??'');
        arc_cloud_assert_destination_allowed($pdo,$connection);
        $page=isset($v['page'])?(string)$v['page']:null;
        $browser=arc_cloud_browse($pdo,$connection,$path,(string)$job['settings']['remote_root'],$network,$agentId,$page);
        arc_json(['ok'=>true,'browser'=>arc_cloud_browse_decorate($pdo,$browser,$account),'connection'=>arc_cloud_connection_public($connection)]);
    }
    if ($action==='download-link') {
        $job=arc_cloud_job_get($pdo,$agentId,$network,$account);
        $connectionId=(int)($v['connection_id']??$job['connection_id']??0);
        $connection=arc_cloud_connection_get($pdo,$connectionId,$network,$account);
        if(!$connection)throw new RuntimeException('Conecte uma conta para baixar arquivos.',404);
        arc_cloud_assert_destination_allowed($pdo,$connection);
        $path=(string)($v['path']??'');if(!arc_cloud_relative_parts($path))throw new InvalidArgumentException('Escolha um arquivo.');
        $token=arc_cloud_state_sign(['k'=>'dl','u'=>(int)arc_user_id(),'net'=>$network,'ag'=>$agentId,'c'=>$connectionId,'p'=>$path,'i'=>!empty($v['inline'])?1:0,'exp'=>time()+120]);
        arc_json(['ok'=>true,'url'=>arc_workspace_base_url().'api/cloud.php?action=download&token='.rawurlencode($token)]);
    }
    if ($action==='folders-start') {
        if (!arc_cloud_policy($pdo)['folder_picker']) throw new InvalidArgumentException('O administrador desativou a escolha de pastas pela plataforma. Digite o caminho da pasta.');
        $path=(string)($v['path']??'');
        if (strlen($path)>1024 || preg_match('/[\x00-\x1f]/',$path) || ($path!=='' && !preg_match('~^[A-Za-z]:\\\\~',$path))) throw new InvalidArgumentException('Caminho inválido.');
        $a=$pdo->prepare('SELECT last_seen_at FROM '.arc_table('network_agents')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$a->execute([$agentId,$network,$account]);$seen=(string)$a->fetchColumn();
        if($seen===''||strtotime($seen)<time()-120)throw new RuntimeException('O agente deste computador está desconectado.',409);
        $count=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('agent_commands')." WHERE agent_id=? AND status IN ('pending','running')");$count->execute([$agentId]);
        if((int)$count->fetchColumn()>=3)throw new RuntimeException('Há outros comandos em andamento neste computador. Aguarde alguns segundos.',429);
        $pdo->beginTransaction();
        try{
            $pdo->prepare('INSERT INTO '.arc_table('agent_commands')." (account_id,network_id,agent_id,command_name) VALUES (?,?,?,'cloud_folders')")->execute([$account,$network,$agentId]);$cmd=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$cmd,(int)arc_user_id()]);
            $pdo->prepare('INSERT INTO '.arc_table('file_browser_requests').' (command_id,network_id,account_id,agent_id,requested_by,path_text) VALUES (?,?,?,?,?,?)')->execute([$cmd,$network,$account,$agentId,(int)arc_user_id(),$path]);$request=(int)$pdo->lastInsertId();
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        arc_json(['ok'=>true,'request_id'=>$request]);
    }
    if ($action==='folders-status') {
        $q=$pdo->prepare('SELECT r.status,r.result_json,c.status AS command_status,c.result_text FROM '.arc_table('file_browser_requests').' r JOIN '.arc_table('agent_commands').' c ON c.id=r.command_id WHERE r.id=? AND r.network_id=? AND r.account_id=? AND r.agent_id=? AND r.requested_by=? AND c.command_name=\'cloud_folders\'');
        $q->execute([(int)($v['request_id']??0),$network,$account,$agentId,(int)arc_user_id()]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Consulta não encontrada.',404);
        $result=json_decode((string)($row['result_json']??''),true);
        arc_json(['ok'=>true,'status'=>$row['status']==='pending'?$row['command_status']:$row['status'],'data'=>is_array($result)?$result:null,'message'=>(string)$row['result_text']]);
    }
    if ($action==='run') {
        $job=arc_cloud_job_get($pdo,$agentId,$network,$account);
        if (!$job['enabled']||!$job['connection_id']) throw new InvalidArgumentException('Ative e configure o backup antes de executar.');
        arc_cloud_job_run_now($pdo,$agentId,$network,$account);arc_user_audit($pdo,'cloud_run','Backup solicitado no computador '.$agentId);arc_json(['ok'=>true]);
    }
    if ($action==='connect') {
        $provider=(string)($v['provider']??'');
        if (!arc_cloud_policy($pdo)['own']) throw new InvalidArgumentException('O administrador não permite contas de nuvem próprias. Use o Drive da plataforma.');
        if ($provider==='s3') {
            $config=arc_cloud_provider_config($pdo,'s3');if (!$config['ready']) throw new InvalidArgumentException('S3 não está habilitado pelo administrador.');
            $secret=arc_cloud_s3_clean_input((array)($v['credentials']??[]),$config);
            $id=arc_cloud_connection_create($pdo,$network,$account,(int)arc_user_id(),'s3',$secret,(string)($v['label']??''));
            arc_user_audit($pdo,'cloud_connect','Conexão S3 cadastrada na rede '.$network);arc_json(['ok'=>true,'connection_id'=>$id]);
        }
        $url=arc_cloud_oauth_start($pdo,$provider,$network,$agentId,(int)arc_user_id());arc_json(['ok'=>true,'url'=>$url]);
    }
    if (in_array($action,['disconnect','refresh'],true)) {
        $connection=arc_cloud_connection_get($pdo,(int)($v['connection_id']??0),$network,$account);
        if (!$connection) throw new RuntimeException('Conexão não encontrada.',404);
        if ($action==='disconnect') {
            if((int)$connection['network_id']===0)throw new RuntimeException('Somente o administrador pode desconectar o Drive da plataforma.',403);
            if (($v['confirmed']??false)!==true) throw new InvalidArgumentException('Confirme a desconexão.');
            arc_cloud_connection_delete($pdo,$connection);arc_user_audit($pdo,'cloud_disconnect','Conexão removida na rede '.$network);
        } else {if((int)$connection['network_id']===0)throw new RuntimeException('A verificação da conta da plataforma é administrada pelo servidor.',403);arc_cloud_connection_refresh_info($pdo,(int)$connection['id']);}
        arc_json(['ok'=>true]);
    }
    if ($isAgent) {
        [$job,$connection]=arc_cloud_agent_job($pdo,$agent);
        if ($action==='agent-state') {
            if (isset($v['status'])&&is_array($v['status'])) arc_cloud_status_save($pdo,$agentId,$network,$account,arc_cloud_sanitize_status($v['status']));
            // Public identity only; no provider token, signed upload URL or cloud secret reaches the agent.
            $brand=arc_public_settings($pdo);
            $label=trim((string)($brand['site_name'] ?? ''));
            if ($label==='') $label='Arcoll Nuvem';
            $label=preg_replace('~[\x00-\x1F\x7F\\\\/:*?"<>|]+~u',' ',$label) ?? 'Arcoll Nuvem';
            $label=trim(preg_replace('/\s+/u',' ',$label) ?? 'Arcoll Nuvem');
            if ($label==='') $label='Arcoll Nuvem';
            if (mb_strlen($label,'UTF-8')>60) $label=trim(mb_substr($label,0,60,'UTF-8'));
            arc_json(['ok'=>true,'job'=>$job,'connection'=>$connection?arc_cloud_connection_public($connection):null,'max_file_bytes'=>arc_cloud_max_file_bytes($pdo),'vdrive'=>['available'=>!empty($job['vdrive_available']),'letter'=>(string)$job['settings']['vdrive_letter'],'label'=>$label]]);
        }
        if ($action==='agent-list'||$action==='agent-download') {
            if (empty($job['vdrive_available'])||!$connection) throw new RuntimeException('O disco virtual não está liberado para este computador.',409);
            $parts=arc_cloud_relative_parts((string)($v['path']??''));
            if ($action==='agent-list') {
                $browser=arc_cloud_browse($pdo,$connection,implode('/',$parts),(string)$job['settings']['remote_root'],$network,$agentId,isset($v['page'])?(string)$v['page']:null);
                arc_json(['ok'=>true,'browser'=>arc_cloud_browse_decorate($pdo,$browser,$account)]);
            }
            if(!$parts)throw new InvalidArgumentException('Escolha um arquivo.');
            $segments=array_merge(arc_cloud_scope_segments($connection,(string)$job['settings']['remote_root'],$network,$agentId),$parts);
            arc_cloud_stream_file($pdo,$connection,$segments,false,isset($v['range'])?(string)$v['range']:null);
            exit;
        }
        if (!$job['available']||!$connection) throw new RuntimeException('Backup pausado ou provedor indisponível.',409);
        $revision=(string)($isChunk?($_SERVER['HTTP_X_ARCOLL_REVISION']??''):($v['revision']??''));
        if (!hash_equals($job['revision'],$revision)) throw new RuntimeException('Configuração alterada. Atualize o agente.',409);
        $uploadId=$isChunk?(string)($_SERVER['HTTP_X_ARCOLL_UPLOAD']??''):(string)($v['upload_id']??'');
        if ($action==='agent-begin') {
            $size=filter_var($v['size']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>min(arc_cloud_max_file_bytes($pdo),(int)$job['settings']['max_file_mb']*1048576)]]);
            if ($connection['provider']==='s3' && $size!==false && $size>5242880*10000) throw new InvalidArgumentException('O S3 aceita até 10.000 partes por arquivo.');
            if ($size===false) throw new InvalidArgumentException('Tamanho de arquivo inválido.');
            $relative=(string)($v['relative']??'');$index=(int)($v['folder']??-1);
            $segments=arc_cloud_remote_segments($job,'',$index,$relative);
            if (!$segments) throw new InvalidArgumentException('Caminho de arquivo inválido.');
            $version=(string)($v['file_version']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$version)) throw new InvalidArgumentException('Versão do arquivo inválida.');
            $uploadId=substr(hash_hmac('sha256',json_encode([$agentId,$revision,$segments,$size,$version]),arc_cloud_state_key()),0,32);
        }
        if (!preg_match('/^[a-f0-9]{32}$/D',$uploadId)) throw new InvalidArgumentException('Sessão inválida.');
        $lock='arc_cloud_upload_'.$uploadId;
        if ((int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lock).',10)')->fetchColumn()!==1) throw new RuntimeException('Envio em andamento. Tente novamente.',503);
        try {
            $q=$pdo->prepare('SELECT * FROM '.arc_table('cloud_backup_uploads').' WHERE id=? AND agent_id=? AND network_id=? AND account_id=? AND connection_id=? AND expires_at>NOW()');
            $q->execute([$uploadId,$agentId,$network,$account,(int)$connection['id']]);$upload=$q->fetch(PDO::FETCH_ASSOC);
            if ($action==='agent-begin') {
                if ($upload) arc_json(['ok'=>true,'upload_id'=>$uploadId,'chunk'=>arc_cloud_providers()[$connection['provider']]['chunk'],'next_offset'=>(int)$upload['received'],'done'=>!empty(arc_cloud_state_read($upload)['done'])]);
                // Expired same-ID sessions must be aborted before replacement.
                $old=$pdo->prepare('SELECT * FROM '.arc_table('cloud_backup_uploads').' WHERE id=?');$old->execute([$uploadId]);$expired=$old->fetch(PDO::FETCH_ASSOC);
                if($expired){arc_cloud_abort($pdo,$connection,arc_cloud_state_read($expired));$pdo->prepare('DELETE FROM '.arc_table('cloud_backup_uploads').' WHERE id=?')->execute([$uploadId]);}
                $pending=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('cloud_backup_uploads').' WHERE agent_id=? AND expires_at>NOW() AND received<total_size');$pending->execute([$agentId]);
                if((int)$pending->fetchColumn()>=50)throw new RuntimeException('Muitos envios incompletos. Aguarde a manutenção das sessões.',429);
                $result=arc_cloud_upload_begin($pdo,$connection,$agentId,$segments,$size,(int)($v['mtime']??0),$uploadId,$network,$account);
                arc_json(['ok'=>true]+$result);
            }
            if ($action!=='agent-chunk') throw new RuntimeException('Ação inválida.',404);
            if (!$upload) throw new RuntimeException('Sessão expirada. Reinicie o envio.',410);
            if (!hash_equals(hash('sha256',$raw),(string)($_SERVER['HTTP_X_ARCOLL_SHA256']??''))) throw new InvalidArgumentException('Integridade do bloco inválida.');
            $offset=filter_var($_SERVER['HTTP_X_ARCOLL_OFFSET']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($offset===false) throw new InvalidArgumentException('Posição inválida.');
            $result=arc_cloud_upload_chunk($pdo,$upload,$connection,$raw,$offset);arc_json(['ok'=>true]+$result);
        } finally { $pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')'); }
    }
    throw new RuntimeException('Ação inválida.',404);
} catch (JsonException|InvalidArgumentException $e) {
    arc_json(['ok'=>false,'error'=>$e instanceof JsonException?'JSON inválido.':$e->getMessage()],422);
} catch (Throwable $e) {
    $code=(int)$e->getCode();$safe=$e instanceof RuntimeException && !$e instanceof PDOException;
    if (!$safe) error_log('Arcoll backup: falha interna '.get_class($e));
    arc_json(['ok'=>false,'error'=>$safe?$e->getMessage():'Serviço de backup indisponível.'],in_array($code,[401,403,404,409,410,413,429,502,503],true)?$code:503);
}
