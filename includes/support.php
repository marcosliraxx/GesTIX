<?php
/* Caminho: includes/support.php */
declare(strict_types=1);
function arc_support_access(PDO $pdo,int $network,int $user,bool $write=false): array {
    $q=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$user]);
    if($q->fetchColumn()!=='active')throw new RuntimeException('Conta sem autorização.');
    $access=arc_network_team_access($pdo,$network,$user);
    if(!$access||($write&&!in_array($access['role'],['owner','administrator','operator'],true)))throw new RuntimeException('Acesso de suporte negado para esta rede.');return $access;
}
function arc_support_event(PDO $pdo,int $ticket,int $actor,string $type,string $message,int $minutes=0): void {
    $pdo->prepare('INSERT INTO '.arc_table('support_events').' (ticket_id,actor_id,event_type,message,minutes_spent) VALUES (?,?,?,?,?)')->execute([$ticket,$actor,$type,$message,max(0,min(1440,$minutes))]);
}
function arc_support_ticket_write(PDO $pdo,int $network,int $user,array $v): int {
    $access=arc_support_access($pdo,$network,$user,true);$action=(string)($v['action']??'');
    if($action==='ticket_create'){
        arc_billing_assert_active($pdo,(int)$access['account_id']);$title=trim((string)($v['title']??''));$description=trim((string)($v['description']??''));$priority=(string)($v['priority']??'normal');
        if($title===''||mb_strlen($title)>160||$description===''||mb_strlen($description)>12000||!in_array($priority,['low','normal','high','urgent'],true))throw new RuntimeException('Informe título, descrição e prioridade válidos.');
        $agent=(int)($v['agent_id']??0);if($agent){$q=$pdo->prepare('SELECT id FROM '.arc_table('network_agents').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$agent,$network,$access['account_id']]);if(!$q->fetchColumn())throw new RuntimeException('Computador inválido.');}
        $hours=['low'=>24,'normal'=>8,'high'=>4,'urgent'=>1][$priority];$hours=max(1,min(720,(int)arc_platform_setting($pdo,'platform_support_sla_'.$priority,(string)$hours)));
        $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO '.arc_table('support_tickets').' (account_id,network_id,agent_id,created_by,title,description,priority,due_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$access['account_id'],$network,$agent?:null,$user,$title,$description,$priority,date('Y-m-d H:i:s',time()+$hours*3600)]);$id=(int)$pdo->lastInsertId();arc_support_event($pdo,$id,$user,'created','Chamado aberto.');$pdo->commit();return $id;}catch(Throwable $e){$pdo->rollBack();throw $e;}
    }
    $id=(int)($v['ticket_id']??0);$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.arc_table('support_tickets').' WHERE id=? AND network_id=? AND account_id=? FOR UPDATE');$q->execute([$id,$network,$access['account_id']]);$t=$q->fetch();if(!$t)throw new RuntimeException('Chamado não encontrado.');
        $message=trim((string)($v['message']??''));$minutes=filter_var($v['minutes']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1440]]);if($minutes===false||mb_strlen($message)>12000)throw new RuntimeException('Anotação ou tempo inválido.');
        $status=(string)($v['status']??$t['status']);if(!in_array($status,['open','in_progress','waiting','resolved','cancelled'],true))throw new RuntimeException('Situação inválida.');
        $assignee=(int)($v['assigned_to']??$t['assigned_to']);
        if($assignee!==(int)$t['assigned_to']){
            if(!in_array($access['role'],['owner','administrator'],true)&&$assignee!==$user)throw new RuntimeException('Somente gestores podem designar outro técnico.');
            if($assignee)arc_support_access($pdo,$network,$assignee,true);
        }
        if($message===''&&$status===$t['status']&&$assignee===(int)$t['assigned_to'])throw new RuntimeException('Escreva uma anotação ou altere o atendimento.');
        if(in_array($status,['resolved','cancelled'],true)&&$message==='')throw new RuntimeException('Registre a solução ou o motivo do encerramento.');
        $closed=in_array($status,['resolved','cancelled'],true)?date('Y-m-d H:i:s'):null;
        $pdo->prepare('UPDATE '.arc_table('support_tickets').' SET status=?,assigned_to=?,closed_at=?,first_response_at=COALESCE(first_response_at,NOW()),updated_at=NOW() WHERE id=?')->execute([$status,$assignee?:null,$closed,$id]);
        arc_support_event($pdo,$id,$user,$status!==$t['status']&&$status==='resolved'?'resolved':'updated',($message!==''?$message.'\n':'').'Situação: '.$status.'; responsável: '.($assignee?:'não atribuído'),(int)$minutes);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** All browser and native requests use this same original command creation path. */
function arc_support_request_remote(PDO $pdo,int $network,int $agent,int $user,int $ticket=0): int {
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT a.*,n.is_active,n.monitoring_enabled FROM ".arc_table('network_agents').' a JOIN '.arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' FOR UPDATE");$q->execute([$agent,$network,$access['account_id']]);$a=$q->fetch();
        if(!$a||!(int)$a['is_active']||!(int)$a['monitoring_enabled']||strtotime((string)$a['last_seen_at'])<time()-20)throw new RuntimeException('Selecione um computador aprovado e online em uma rede ativa.');
        // Team operators must receive local consent even on servers configured as headless.
        if((int)$a['headless_mode']===1&&!in_array($access['role'],['owner','administrator'],true))throw new RuntimeException('Acesso não assistido restrito ao gestor da rede.');
        if($ticket){$q=$pdo->prepare('SELECT id FROM '.arc_table('support_tickets').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$ticket,$network,$access['account_id']]);if(!$q->fetchColumn())throw new RuntimeException('Chamado inválido para esta rede.');}
        $pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='expired',completed_at=NOW() WHERE agent_id=? AND command_name='remote_assistance' AND ((status='pending' AND created_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (status='running' AND created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)))")->execute([$agent]);
        $q=$pdo->prepare("SELECT id FROM ".arc_table('agent_commands')." WHERE agent_id=? AND command_name='remote_assistance' AND status IN ('pending','running') LIMIT 1");$q->execute([$agent]);if($q->fetchColumn())throw new RuntimeException('Já existe uma solicitação remota neste computador. Encerre-a antes de iniciar outra.');
        $pdo->prepare("INSERT INTO ".arc_table('agent_commands')." (account_id,network_id,agent_id,command_name) VALUES (?,?,?,'remote_assistance')")->execute([$access['account_id'],$network,$agent]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO '.arc_table('support_remote_sessions').' (command_id,ticket_id,technician_id,account_id,network_id) VALUES (?,?,?,?,?)')->execute([$id,$ticket?:null,$user,$access['account_id'],$network]);
        if($ticket)arc_support_event($pdo,$ticket,$user,'remote_requested','Sessão remota solicitada #'.$id);
        $pdo->commit();arc_user_audit($pdo,'remote_access_requested','Solicitante #'.$user.' · sessão #'.$id.' · rede #'.$network,(int)$access['account_id']);return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_support_remote_allowed(PDO $pdo,array $command,int $user): bool {
    try{$a=arc_support_access($pdo,(int)$command['network_id'],$user,true);}catch(Throwable $e){return false;}
    if((int)$a['account_id']!==(int)$command['account_id']||!arc_billing_policy($pdo,(int)$command['account_id'])['active'])return false;
    if(in_array($a['role'],['owner','administrator'],true))return true;
    $q=$pdo->prepare('SELECT technician_id FROM '.arc_table('support_remote_sessions').' WHERE command_id=?');$q->execute([$command['id']]);return (int)$q->fetchColumn()===$user;
}
function arc_support_issue_code(PDO $pdo,int $command,int $user): string {
    $q=$pdo->prepare("SELECT * FROM ".arc_table('agent_commands')." WHERE id=? AND command_name='remote_assistance' AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 30 MINUTE)");$q->execute([$command]);$c=$q->fetch();if(!$c||!arc_support_remote_allowed($pdo,$c,$user))throw new RuntimeException('Sessão sem autorização.');
    $code=strtoupper(bin2hex(random_bytes(10)));
    $pdo->prepare('INSERT INTO '.arc_table('support_remote_tokens').' (command_id,user_id,code_hash,redeem_until,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE),DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$command,$user,hash('sha256',$code)]);return $code;
}
function arc_support_redeem(PDO $pdo,string $code): array {
    if(!preg_match('/^[A-F0-9]{20}$/D',$code))throw new RuntimeException('Código inválido ou expirado.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM '.arc_table('support_remote_tokens').' WHERE code_hash=? AND redeemed_at IS NULL AND redeem_until>NOW() FOR UPDATE');$q->execute([hash('sha256',$code)]);$r=$q->fetch();if(!$r)throw new RuntimeException('Código inválido, utilizado ou expirado.');
        $q=$pdo->prepare("SELECT * FROM ".arc_table('agent_commands')." WHERE id=? AND status IN ('pending','running')");$q->execute([$r['command_id']]);$c=$q->fetch();if(!$c||!arc_support_remote_allowed($pdo,$c,(int)$r['user_id']))throw new RuntimeException('Autorização revogada.');
        $token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE '.arc_table('support_remote_tokens').' SET token_hash=?,redeemed_at=NOW() WHERE id=?')->execute([hash('sha256',$token),$r['id']]);$pdo->commit();return ['token'=>$token,'command_id'=>(int)$r['command_id'],'expires_at'=>$r['expires_at']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_support_native_user(PDO $pdo,int $command): int {
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m))return 0;
    $q=$pdo->prepare('SELECT user_id FROM '.arc_table('support_remote_tokens').' WHERE command_id=? AND token_hash=? AND redeemed_at IS NOT NULL AND expires_at>NOW()');$q->execute([$command,hash('sha256',$m[1])]);return (int)$q->fetchColumn();
}

/* Transferência assistida: arquivos privados, sem caminho remoto ou execução de conteúdo. */
const ARC_FILE_MAX_BYTES=8388608;
function arc_support_file_limit(): int {
    $parse=static function(string $value):int{$value=trim($value);$n=(int)$value;return $n*(['g'=>1073741824,'m'=>1048576,'k'=>1024][strtolower(substr($value,-1))]??1);};
    $limits=[ARC_FILE_MAX_BYTES];foreach(['upload_max_filesize','post_max_size'] as $key){$n=$parse((string)ini_get($key));if($n>0)$limits[]=$key==='post_max_size'?max(0,$n-65536):$n;}return min($limits);
}
function arc_support_file_name(string $name): string {
    if($name===''||strlen($name)>180||!mb_check_encoding($name,'UTF-8')||preg_match('~[\\\\/\x00-\x1f\x7f]~u',$name))throw new RuntimeException('Nome de arquivo inválido.');
    $name=rtrim(trim(preg_replace('/[<>:"|?*]/u','_',$name)),'. ');
    if($name===''||preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$name))throw new RuntimeException('Escolha um arquivo com outro nome.');return $name;
}
function arc_support_file_path(string $key): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Arquivo indisponível.');
    $dir=ARC_PRIVATE_DIR.'/storage/transfers';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Armazenamento de arquivos indisponível.');return $dir.'/'.$key;
}
function arc_support_files_cleanup(PDO $pdo): void {
    $q=$pdo->query('SELECT id,storage_key FROM '.arc_table('support_file_transfers').' WHERE expires_at<NOW() AND storage_key IS NOT NULL LIMIT 100');
    foreach($q->fetchAll() as $row){$path=arc_support_file_path((string)$row['storage_key']);if(is_file($path)&&!unlink($path))continue;$pdo->prepare("UPDATE ".arc_table('support_file_transfers')." SET storage_key=NULL,status='expired' WHERE id=? AND expires_at<NOW()")->execute([$row['id']]);}
    $pdo->exec("UPDATE ".arc_table('support_file_transfers')." SET status='expired' WHERE expires_at<NOW() AND storage_key IS NULL AND status IN ('pending','ready','completed')");
    $pdo->exec("UPDATE ".arc_table('agent_commands')." c JOIN ".arc_table('support_file_transfers')." f ON f.command_id=c.id SET c.status='expired',c.completed_at=NOW() WHERE f.expires_at<NOW() AND c.status IN ('pending','running')");
}
function arc_support_file_agent(PDO $pdo): array {
    $fp=(string)($_SERVER['HTTP_X_ARCOLL_FINGERPRINT']??'');$site=(string)($_SERVER['HTTP_X_ARCOLL_SITE']??'');$key=(string)($_SERVER['HTTP_X_ARCOLL_KEY']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$fp)||!preg_match('/^[A-Z0-9]{5}$/D',$site)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Computador não autorizado.');
    $q=$pdo->prepare("SELECT a.id,a.network_id,a.account_id FROM ".arc_table('network_agents')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id JOIN ".arc_table('agent_download_keys')." k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND a.site_code=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=?");
    $q->execute([$fp,$site,hash('sha256',$key),$fp]);$agent=$q->fetch();if(!$agent)throw new RuntimeException('Computador não autorizado.');return $agent;
}
function arc_support_file_record(PDO $pdo,int $id,?array $agent=null): array {
    $q=$pdo->prepare('SELECT f.*,c.status AS command_status,c.dispatched_at,u.display_name AS requester FROM '.arc_table('support_file_transfers').' f JOIN '.arc_table('agent_commands').' c ON c.id=f.command_id AND c.agent_id=f.agent_id AND c.account_id=f.account_id JOIN '.arc_table('user_accounts').' u ON u.id=f.requested_by WHERE f.id=?');$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new RuntimeException('Transferência não encontrada.');
    if($agent){if((int)$row['agent_id']!==(int)$agent['id']||(int)$row['account_id']!==(int)$agent['account_id']||(int)$row['network_id']!==(int)$agent['network_id']||$row['command_status']!=='running'||strtotime((string)$row['dispatched_at'])<time()-180)throw new RuntimeException('Solicitação expirada ou indisponível.');}
    elseif(!arc_is_user()||(int)$row['requested_by']!==(int)arc_user_id())throw new RuntimeException('Transferência não autorizada.');
    $access=arc_support_access($pdo,(int)$row['network_id'],(int)$row['requested_by'],true);
    if((int)$access['account_id']!==(int)$row['account_id'])throw new RuntimeException('Transferência não autorizada.');
    arc_billing_assert_active($pdo,(int)$row['account_id']);
    $q=$pdo->prepare('SELECT a.id FROM '.arc_table('network_agents')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1");$q->execute([$row['agent_id'],$row['network_id'],$row['account_id']]);
    if(!$q->fetchColumn()||strtotime($row['expires_at'])<=time())throw new RuntimeException('Transferência expirada ou sem autorização.');return $row;
}
function arc_support_file_create(PDO $pdo,int $network,int $agent,int $user,string $direction,string $name='',string $data=''): int {
    if(!in_array($direction,['to_agent','from_agent'],true))throw new RuntimeException('Operação inválida.');
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    if(strlen($data)>ARC_FILE_MAX_BYTES)throw new RuntimeException('O arquivo excede o limite de 8 MB.');
    if($direction==='to_agent')$name=arc_support_file_name($name);
    arc_support_files_cleanup($pdo);$key=null;
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$access['account_id']]);
        $q=$pdo->prepare("SELECT a.id,a.agent_version,a.last_seen_at FROM ".arc_table('network_agents')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1 FOR UPDATE");$q->execute([$agent,$network,$access['account_id']]);$a=$q->fetch();
        if(!$a||strtotime((string)$a['last_seen_at'])<time()-120)throw new RuntimeException('Selecione um computador aprovado e conectado.');
        if(version_compare((string)$a['agent_version'],'3.3.0','<'))throw new RuntimeException('Atualize o agente para usar a transferência de arquivos.');
        // The account lock serializes the private storage quota across all its computers.
        $q=$pdo->prepare('SELECT COUNT(*),COALESCE(SUM(file_size),0) FROM '.arc_table('support_file_transfers').' WHERE account_id=? AND expires_at>NOW()');$q->execute([$access['account_id']]);$quota=$q->fetch(PDO::FETCH_NUM);
        if((int)$quota[0]>=50||(int)$quota[1]+strlen($data)>67108864)throw new RuntimeException('Limite temporário de arquivos atingido. Tente novamente após a expiração.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM ".arc_table('agent_commands')." WHERE agent_id=? AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$agent]);if((int)$q->fetchColumn()>=3)throw new RuntimeException('Aguarde as operações anteriores.');
        $q=$pdo->prepare("SELECT id FROM ".arc_table('agent_commands')." WHERE agent_id=? AND command_name='file_transfer' AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$agent]);if($q->fetchColumn())throw new RuntimeException('Aguarde a transferência anterior.');
        if($direction==='to_agent'){$key=bin2hex(random_bytes(32));$path=arc_support_file_path($key);if(file_put_contents($path,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Não foi possível guardar o arquivo.');chmod($path,0600);}
        $pdo->prepare("INSERT INTO ".arc_table('agent_commands')." (account_id,network_id,agent_id,command_name) VALUES (?,?,?,'file_transfer')")->execute([$access['account_id'],$network,$agent]);$command=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO '.arc_table('support_file_transfers').' (command_id,account_id,network_id,agent_id,requested_by,direction,filename,file_size,sha256,storage_key,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$command,$access['account_id'],$network,$agent,$user,$direction,$name,strlen($data),$key?hash('sha256',$data):null,$key]);$id=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($key){$path=arc_support_file_path($key);if(is_file($path))unlink($path);}throw $e;}
    arc_user_audit($pdo,'support_file_requested','Transferência #'.$id.' · agente #'.$agent,(int)$access['account_id']);return $id;
}
function arc_support_file_stream(array $row): void {
    if(!$row['storage_key'])throw new RuntimeException('Arquivo ainda não disponível.');$path=arc_support_file_path($row['storage_key']);
    if(!is_file($path)||filesize($path)!==(int)$row['file_size']||!hash_equals((string)$row['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Arquivo indisponível ou incompleto.');
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="arquivo"; filename*=UTF-8\'\''.rawurlencode($row['filename']));header('Content-Length: '.(int)$row['file_size']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($path);exit;
}
function arc_support_file_api(PDO $pdo,string $action): void {
    $method=$_SERVER['REQUEST_METHOD'];$isAgent=str_starts_with($action,'file-agent-');
    if($isAgent){
        if($method!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);
        $agent=arc_support_file_agent($pdo);$row=arc_support_file_record($pdo,(int)($_GET['id']??0),$agent);
        if($row['status']!=='pending')throw new RuntimeException('Transferência já finalizada.');
        if($action==='file-agent-details')arc_json(['ok'=>true,'transfer'=>array_intersect_key($row,array_flip(['id','command_id','direction','filename','file_size','sha256','requester'])),'max_bytes'=>ARC_FILE_MAX_BYTES]);
        if($action==='file-agent-download'&&$row['direction']==='to_agent')arc_support_file_stream($row);
        if($action==='file-agent-upload'&&$row['direction']==='from_agent'){
            $name=arc_support_file_name(rawurldecode((string)($_SERVER['HTTP_X_FILE_NAME']??'')));$data=file_get_contents('php://input',false,null,0,ARC_FILE_MAX_BYTES+1);
            $hash=(string)($_SERVER['HTTP_X_FILE_SHA256']??'');if(!is_string($data)||strlen($data)>ARC_FILE_MAX_BYTES||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals(hash('sha256',$data),$hash))throw new RuntimeException('Arquivo inválido, incompleto ou maior que 8 MB.');
            $key=bin2hex(random_bytes(32));$path=arc_support_file_path($key);$pdo->beginTransaction();try{
                $pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE')->execute([$row['account_id']]);
                $q=$pdo->prepare('SELECT COALESCE(SUM(file_size),0) FROM '.arc_table('support_file_transfers').' WHERE account_id=? AND expires_at>NOW()');$q->execute([$row['account_id']]);if((int)$q->fetchColumn()+strlen($data)>67108864)throw new RuntimeException('Limite temporário de arquivos atingido.');
                if(file_put_contents($path,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Não foi possível guardar o arquivo.');chmod($path,0600);
                $q=$pdo->prepare("UPDATE ".arc_table('support_file_transfers')." SET filename=?,file_size=?,sha256=?,storage_key=?,status='ready',message='Arquivo disponível para baixar.' WHERE id=? AND status='pending'");$q->execute([$name,strlen($data),$hash,$key,$row['id']]);if(!$q->rowCount())throw new RuntimeException('Transferência já finalizada.');
                $pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='completed',result_text='Arquivo recebido na plataforma.',completed_at=NOW() WHERE id=? AND status='running'")->execute([$row['command_id']]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(is_file($path))unlink($path);throw $e;}arc_json(['ok'=>true]);
        }
        if($action==='file-agent-finish'){
            $v=json_decode((string)file_get_contents('php://input',false,null,0,1025),true);$state=(string)($v['status']??'');if(!in_array($state,['completed','declined','failed'],true)||($state==='completed'&&$row['direction']!=='to_agent'))throw new RuntimeException('Resultado inválido.');
            $message=['completed'=>'Arquivo salvo no computador.','declined'=>'A pessoa no computador cancelou.','failed'=>'Não foi possível concluir a transferência.'][$state];
            $pdo->prepare('UPDATE '.arc_table('support_file_transfers')." SET status=?,message=? WHERE id=? AND status='pending'")->execute([$state,$message,$row['id']]);
            $pdo->prepare('UPDATE '.arc_table('agent_commands')." SET status=?,result_text=?,completed_at=NOW() WHERE id=? AND status='running'")->execute([$state==='completed'?'completed':'failed',$message,$row['command_id']]);arc_json(['ok'=>true]);
        }
        throw new RuntimeException('Operação indisponível.');
    }
    if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Entre na plataforma.'],401);
    if($action==='file-download'&&$method==='GET'){$row=arc_support_file_record($pdo,(int)($_GET['id']??0));if($row['direction']!=='from_agent'||$row['status']!=='ready')throw new RuntimeException('Arquivo ainda não disponível.');arc_support_file_stream($row);}
    if($action==='file-list'&&$method==='GET'){
        $network=(int)($_GET['network']??0);$agent=(int)($_GET['agent']??0);arc_support_access($pdo,$network,(int)arc_user_id(),true);arc_support_files_cleanup($pdo);
        $q=$pdo->prepare('SELECT f.id,f.direction,f.filename,f.file_size,f.status,f.message,f.expires_at,c.status AS task_status FROM '.arc_table('support_file_transfers').' f JOIN '.arc_table('agent_commands').' c ON c.id=f.command_id WHERE f.requested_by=? AND f.network_id=? AND f.agent_id=? ORDER BY f.id DESC LIMIT 20');$q->execute([arc_user_id(),$network,$agent]);arc_json(['ok'=>true,'transfers'=>$q->fetchAll(),'max_bytes'=>arc_support_file_limit()]);
    }
    if($method!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);arc_verify_csrf();
    $network=(int)($_POST['network']??0);$agent=(int)($_POST['agent']??0);
    if($action==='file-send'){
        $file=$_FILES['file']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||(int)$file['size']>arc_support_file_limit()||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Escolha um arquivo dentro do limite permitido pela hospedagem.');
        $data=file_get_contents($file['tmp_name'],false,null,0,ARC_FILE_MAX_BYTES+1);if(!is_string($data))throw new RuntimeException('Arquivo indisponível.');$id=arc_support_file_create($pdo,$network,$agent,(int)arc_user_id(),'to_agent',(string)$file['name'],$data);
    }elseif($action==='file-receive')$id=arc_support_file_create($pdo,$network,$agent,(int)arc_user_id(),'from_agent');
    else throw new RuntimeException('Operação indisponível.');arc_json(['ok'=>true,'id'=>$id]);
}
