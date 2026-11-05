<?php
/* Caminho: api/cloud.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') arc_json(['ok'=>false,'error'=>'Use POST.'],405);
    $pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);
    $action=(string)($_GET['action']??'');
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
    if ($action==='state') arc_json(['ok'=>true,'providers'=>arc_cloud_available_providers($pdo),'connections'=>arc_cloud_connections_list($pdo,$network,$account),'job'=>arc_cloud_job_get($pdo,$agentId,$network,$account),'status'=>arc_cloud_status_get($pdo,$agentId,$network,$account)]);
    if ($action==='save') {
        $current=arc_cloud_job_get($pdo,$agentId,$network,$account);
        $settings=is_array($v['settings']??null)?$v['settings']:[];
        // A browser cannot authorize a local path; folders originate in the Windows picker.
        $settings['folders']=$current['settings']['folders'];
        $settings=arc_cloud_validate_settings($settings);
        if (!empty($v['enabled']) && !$settings['folders']) throw new InvalidArgumentException('Selecione e autorize as pastas no agente Windows.');
        arc_cloud_job_save($pdo,$agentId,$network,$account,($v['connection_id']??null)?(int)$v['connection_id']:null,!empty($v['enabled']),$settings);
        arc_user_audit($pdo,'cloud_settings','Configuração de backup alterada no computador '.$agentId);
        arc_json(['ok'=>true]);
    }
    if ($action==='run') {
        $job=arc_cloud_job_get($pdo,$agentId,$network,$account);
        if (!$job['enabled']||!$job['connection_id']) throw new InvalidArgumentException('Ative e configure o backup antes de executar.');
        arc_cloud_job_run_now($pdo,$agentId,$network,$account);arc_user_audit($pdo,'cloud_run','Backup solicitado no computador '.$agentId);arc_json(['ok'=>true]);
    }
    if ($action==='connect') {
        $provider=(string)($v['provider']??'');
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
            if (($v['confirmed']??false)!==true) throw new InvalidArgumentException('Confirme a desconexão.');
            arc_cloud_connection_delete($pdo,$connection);arc_user_audit($pdo,'cloud_disconnect','Conexão removida na rede '.$network);
        } else arc_cloud_connection_refresh_info($pdo,(int)$connection['id']);
        arc_json(['ok'=>true]);
    }
    if ($isAgent) {
        [$job,$connection]=arc_cloud_agent_job($pdo,$agent);
        if ($action==='agent-state') {
            if (isset($v['status'])&&is_array($v['status'])) arc_cloud_status_save($pdo,$agentId,$network,$account,arc_cloud_sanitize_status($v['status']));
            // Public identity only; no provider token, signed upload URL or cloud secret reaches the agent.
            arc_json(['ok'=>true,'job'=>$job,'connection'=>$connection?arc_cloud_connection_public($connection):null,'max_file_bytes'=>arc_cloud_max_file_bytes($pdo)]);
        }
        if ($action==='agent-folders') {
            $settings=$job['settings'];$settings['folders']=$v['folders']??[];$settings=arc_cloud_validate_settings($settings);
            arc_cloud_job_save($pdo,$agentId,$network,$account,$job['connection_id'],false,$settings);
            arc_user_audit($pdo,'cloud_local_folders','Pastas autorizadas localmente no computador '.$agentId,$account);arc_json(['ok'=>true]);
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
                $result=arc_cloud_upload_begin($pdo,$connection,$agentId,$segments,$size,(int)($v['mtime']??0),$uploadId);
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
