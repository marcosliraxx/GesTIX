<?php
/* Caminho: includes/support.php */
declare(strict_types=1);
function arc_support_access(PDO $pdo,int $network,int $user,bool $write=false): array {
    if (arc_is_admin() && !arc_is_user()) {
        $access=arc_network_team_access($pdo,$network,0);
        if (!$access) throw new RuntimeException('Rede não autorizada para o administrador global.');
        return $access;
    }
    $q=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$user]);
    if($q->fetchColumn()!=='active')throw new RuntimeException('Conta sem autorização.');
    $access=arc_network_team_access($pdo,$network,$user);
    if($access && $access['role']!=='owner'){ $rights=arc_network_profile_rights($pdo,$network,$user);if($rights!==null&&!in_array('suporte',$rights,true))throw new RuntimeException('Perfil sem permissão para suporte.'); }
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
        $gestor=(int)($v['gestor_id']??0);if($gestor){$q=$pdo->prepare('SELECT id FROM '.arc_table('network_gestores').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$gestor,$network,$access['account_id']]);if(!$q->fetchColumn())throw new RuntimeException('Computador inválido.');}
        $hours=['low'=>24,'normal'=>8,'high'=>4,'urgent'=>1][$priority];$hours=max(1,min(720,(int)arc_platform_setting($pdo,'platform_support_sla_'.$priority,(string)$hours)));
        $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO '.arc_table('support_tickets').' (account_id,network_id,gestor_id,created_by,title,description,priority,due_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$access['account_id'],$network,$gestor?:null,$user,$title,$description,$priority,date('Y-m-d H:i:s',time()+$hours*3600)]);$id=(int)$pdo->lastInsertId();arc_support_event($pdo,$id,$user,'created','Chamado aberto.');$pdo->commit();return $id;}catch(Throwable $e){$pdo->rollBack();throw $e;}
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
function arc_support_request_remote(PDO $pdo,int $network,int $gestor,int $user,int $ticket=0,int $guestCodeId=0): int {
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT a.*,n.is_active,n.monitoring_enabled FROM ".arc_table('network_gestores').' a JOIN '.arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' FOR UPDATE");$q->execute([$gestor,$network,$access['account_id']]);$a=$q->fetch();
        if(!$a||!(int)$a['is_active']||!(int)$a['monitoring_enabled']||strtotime((string)$a['last_seen_at'])<time()-120)throw new RuntimeException('Selecione um computador aprovado e online em uma rede ativa.');
        // Team operators must receive local consent even on servers configured as headless.

        if($guestCodeId){$q=$pdo->prepare('SELECT id FROM '.arc_table('support_guest_codes').' WHERE id=? AND gestor_id=? AND command_id IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([$guestCodeId,$gestor]);if(!$q->fetchColumn())throw new RuntimeException('Código inválido, utilizado ou expirado.');}
        if($ticket){$q=$pdo->prepare('SELECT id FROM '.arc_table('support_tickets').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$ticket,$network,$access['account_id']]);if(!$q->fetchColumn())throw new RuntimeException('Chamado inválido para esta rede.');}
        $pdo->prepare("UPDATE ".arc_table('gestor_commands')." SET status='expired',completed_at=NOW() WHERE gestor_id=? AND command_name='remote_assistance' AND ((status='pending' AND created_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (status='running' AND created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)))")->execute([$gestor]);
        $q=$pdo->prepare("SELECT id FROM ".arc_table('gestor_commands')." WHERE gestor_id=? AND command_name='remote_assistance' AND status IN ('pending','running') LIMIT 1");$q->execute([$gestor]);if($q->fetchColumn())throw new RuntimeException('Já existe uma solicitação remota neste computador. Encerre-a antes de iniciar outra.');
        $pdo->prepare("INSERT INTO ".arc_table('gestor_commands')." (account_id,network_id,gestor_id,command_name) VALUES (?,?,?,'remote_assistance')")->execute([$access['account_id'],$network,$gestor]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO '.arc_table('support_remote_sessions').' (command_id,ticket_id,technician_id,account_id,network_id,unattended,admin_id) VALUES (?,?,?,?,?,?,?)')->execute([$id,$ticket?:null,$user,$access['account_id'],$network,(!$guestCodeId&&(int)$a['headless_mode']===1&&in_array($access['role'],['owner','administrator','system_admin'],true))?1:0,arc_is_admin()?arc_admin_id():null]);
        if($guestCodeId)$pdo->prepare('UPDATE '.arc_table('support_guest_codes').' SET command_id=? WHERE id=?')->execute([$id,$guestCodeId]);
        if($ticket && !arc_is_admin())arc_support_event($pdo,$ticket,$user,'remote_requested','Sessão remota solicitada #'.$id);
        $pdo->commit();arc_user_audit($pdo,'remote_access_requested',($guestCodeId?'Visitante por código · solicitante responsável #':'Solicitante #').$user.' · sessão #'.$id.' · rede #'.$network,(int)$access['account_id']);return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_support_remote_allowed(PDO $pdo,array $command,int $user): bool {
    if (arc_is_admin()) {
        // O administrador usa sua identidade própria; nenhum ID de usuário da rede é atribuído a ele.
        $access=arc_network_team_access($pdo,(int)$command['network_id'],0);
        if (!$access || (int)$access['account_id']!==(int)$command['account_id']) return false;
        $check=$pdo->prepare('SELECT admin_id FROM '.arc_table('support_remote_sessions').' WHERE command_id=? AND network_id=? AND account_id=?');
        $check->execute([(int)$command['id'],(int)$command['network_id'],(int)$command['account_id']]);
        return (int)$check->fetchColumn()===(int)arc_admin_id() && arc_billing_policy($pdo,(int)$command['account_id'])['active'];
    }
    try{$a=arc_support_access($pdo,(int)$command['network_id'],$user,true);}catch(Throwable $e){return false;}
    if((int)$a['account_id']!==(int)$command['account_id']||!arc_billing_policy($pdo,(int)$command['account_id'])['active'])return false;
    $q=$pdo->prepare('SELECT s.unattended,a.headless_mode FROM '.arc_table('support_remote_sessions').' s JOIN '.arc_table('network_gestores').' a ON a.id=? WHERE s.command_id=?');$q->execute([$command['gestor_id']??0,$command['id']]);$policy=$q->fetch();if($policy&&(int)$policy['unattended']&&(!(int)$policy['headless_mode']||!in_array($a['role'],['owner','administrator'],true)))return false;
    if(in_array($a['role'],['owner','administrator'],true))return true;
    $q=$pdo->prepare('SELECT technician_id FROM '.arc_table('support_remote_sessions').' WHERE command_id=?');$q->execute([$command['id']]);return (int)$q->fetchColumn()===$user;
}
function arc_support_issue_code(PDO $pdo,int $command,int $user): string {
    $q=$pdo->prepare("SELECT * FROM ".arc_table('gestor_commands')." WHERE id=? AND command_name='remote_assistance' AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 30 MINUTE)");$q->execute([$command]);$c=$q->fetch();if(!$c||!arc_support_remote_allowed($pdo,$c,$user))throw new RuntimeException('Sessão sem autorização.');
    $code=strtoupper(bin2hex(random_bytes(10)));
    $pdo->prepare('INSERT INTO '.arc_table('support_remote_tokens').' (command_id,user_id,code_hash,redeem_until,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE),DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$command,$user,hash('sha256',$code)]);return $code;
}
function arc_support_redeem(PDO $pdo,string $code): array {
    if(!preg_match('/^[A-F0-9]{20}$/D',$code))throw new RuntimeException('Código inválido ou expirado.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM '.arc_table('support_remote_tokens').' WHERE code_hash=? AND redeemed_at IS NULL AND redeem_until>NOW() FOR UPDATE');$q->execute([hash('sha256',$code)]);$r=$q->fetch();if(!$r)throw new RuntimeException('Código inválido, utilizado ou expirado.');
        $q=$pdo->prepare("SELECT * FROM ".arc_table('gestor_commands')." WHERE id=? AND status IN ('pending','running')");$q->execute([$r['command_id']]);$c=$q->fetch();if(!$c||!arc_support_remote_allowed($pdo,$c,(int)$r['user_id']))throw new RuntimeException('Autorização revogada.');
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
    $t=arc_table('support_file_transfers');$c=arc_table('gestor_commands');
    // Comando que o computador nunca executou (offline, tempo esgotado, cancelado) não pode ocupar a cota diária.
    $pdo->exec("UPDATE ".$t." f JOIN ".$c." c ON c.id=f.command_id SET f.status='expired',f.message='O computador não concluiu a solicitação.' WHERE f.status IN ('pending','ready') AND c.status IN ('expired','failed')");
    // O arquivo guardado só existe enquanto alguém ainda precisa dele: concluído, cancelado, recusado ou expirado é apagado na hora.
    $q=$pdo->query('SELECT id,storage_key FROM '.$t." WHERE storage_key IS NOT NULL AND (expires_at<NOW() OR status IN ('completed','cancelled','declined','failed','expired')) LIMIT 100");
    foreach($q->fetchAll() as $row){$path=arc_support_file_path((string)$row['storage_key']);if(is_file($path)&&!unlink($path))continue;$pdo->prepare("UPDATE ".$t." SET storage_key=NULL,status=IF(status IN ('pending','ready'),'expired',status) WHERE id=?")->execute([$row['id']]);}
    $pdo->exec("UPDATE ".$t." SET status='expired' WHERE expires_at<NOW() AND storage_key IS NULL AND status IN ('pending','ready','completed')");
    $pdo->exec("UPDATE ".$c." c JOIN ".$t." f ON f.command_id=c.id SET c.status='expired',c.completed_at=NOW() WHERE f.expires_at<NOW() AND c.status IN ('pending','running')");
}
function arc_support_file_gestor(PDO $pdo): array {
    $fp=(string)($_SERVER['HTTP_X_ARCOLL_FINGERPRINT']??'');$site=(string)($_SERVER['HTTP_X_ARCOLL_SITE']??'');$key=(string)($_SERVER['HTTP_X_ARCOLL_KEY']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$fp)||!preg_match('/^[A-Z0-9]{5}$/D',$site)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Computador não autorizado.');
    $q=$pdo->prepare("SELECT a.id,a.network_id,a.account_id FROM ".arc_table('network_gestores')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id JOIN ".arc_table('gestor_download_keys')." k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND a.site_code=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=?");
    $q->execute([$fp,$site,hash('sha256',$key),$fp]);$gestor=$q->fetch();if(!$gestor)throw new RuntimeException('Computador não autorizado.');return $gestor;
}
function arc_support_file_record(PDO $pdo,int $id,?array $gestor=null,bool $allowReplay=false): array {
    $q=$pdo->prepare('SELECT f.*,c.status AS command_status,c.command_name,c.dispatched_at,u.display_name AS requester FROM '.arc_table('support_file_transfers').' f JOIN '.arc_table('gestor_commands').' c ON c.id=f.command_id AND c.gestor_id=f.gestor_id AND c.account_id=f.account_id JOIN '.arc_table('user_accounts').' u ON u.id=f.requested_by WHERE f.id=?');$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new RuntimeException('Transferência não encontrada.');
    if($gestor){if((int)$row['gestor_id']!==(int)$gestor['id']||(int)$row['account_id']!==(int)$gestor['account_id']||(int)$row['network_id']!==(int)$gestor['network_id']||(!($allowReplay&&$row['command_status']==='completed'&&in_array($row['status'],['ready','completed'],true))&&($row['command_status']!=='running'||strtotime((string)$row['dispatched_at'])<time()-180)))throw new RuntimeException('Solicitação expirada ou indisponível.');}
    elseif(!arc_is_user()||(int)$row['requested_by']!==(int)arc_user_id())throw new RuntimeException('Transferência não autorizada.');
    $access=arc_support_access($pdo,(int)$row['network_id'],(int)$row['requested_by'],true);
    if((int)$access['account_id']!==(int)$row['account_id'])throw new RuntimeException('Transferência não autorizada.');
    if(in_array($row['command_name'],['file_operation','file_bundle','file_execute'],true)){
        $q=$pdo->prepare('SELECT headless_mode FROM '.arc_table('network_gestores').' WHERE id=?');$q->execute([$row['gestor_id']]);
        if(!(int)$q->fetchColumn()||!in_array($access['role'],['owner','administrator'],true))throw new RuntimeException('Autorização do explorador revogada.');
    }
    arc_billing_assert_active($pdo,(int)$row['account_id']);
    $q=$pdo->prepare('SELECT a.id FROM '.arc_table('network_gestores')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1");$q->execute([$row['gestor_id'],$row['network_id'],$row['account_id']]);
    if(!$q->fetchColumn()||strtotime($row['expires_at'])<=time())throw new RuntimeException('Transferência expirada ou sem autorização.');return $row;
}
function arc_support_file_create(PDO $pdo,int $network,int $gestor,int $user,string $direction,string $name='',string $data='',string $destination='',?string $clientToken=null): int {
    if(!in_array($direction,['to_gestor','from_gestor'],true))throw new RuntimeException('Operação inválida.');
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    if(strlen($data)>ARC_FILE_MAX_BYTES)throw new RuntimeException('O arquivo excede o limite de 8 MB.');
    if($direction==='to_gestor')$name=arc_support_file_name($name);
    arc_support_files_cleanup($pdo);$key=null;
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$access['account_id']]);
        if($clientToken!==null){
            if(!preg_match('/^[a-f0-9]{32}$/D',$clientToken))throw new RuntimeException('Identificador inválido.');
            $q=$pdo->prepare('SELECT id,network_id,gestor_id FROM '.arc_table('support_file_transfers').' WHERE requested_by=? AND client_token=?');$q->execute([$user,$clientToken]);$existing=$q->fetch();
            if($existing){if((int)$existing['network_id']!==$network||(int)$existing['gestor_id']!==$gestor)throw new RuntimeException('Identificador inválido.');$pdo->commit();return (int)$existing['id'];}
        }

        $q=$pdo->prepare("SELECT a.id,a.gestor_version,a.last_seen_at,a.headless_mode FROM ".arc_table('network_gestores')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1 FOR UPDATE");$q->execute([$gestor,$network,$access['account_id']]);$a=$q->fetch();
        if(!$a||strtotime((string)$a['last_seen_at'])<time()-120)throw new RuntimeException('Selecione um computador aprovado e conectado.');
        if(version_compare((string)$a['gestor_version'],'3.3.0','<'))throw new RuntimeException('Atualize o gestor para usar a transferência de arquivos.');
        if(str_starts_with($name,'__arcoll_folder__')&&version_compare((string)$a['gestor_version'],'3.8.1','<'))throw new RuntimeException('Atualize o gestor para a versão 3.8.1 ou superior para receber pastas.');
        if($destination!==''&&((int)$a['headless_mode']!==1||!in_array($access['role'],['owner','administrator'],true)||version_compare((string)$a['gestor_version'],'3.6.23','<')))throw new RuntimeException('Destino remoto exige administrador, modo sem monitor e gestor atualizado.');
        // The account lock serializes the private storage quota across all its computers.
        $q=$pdo->prepare('SELECT COUNT(*),COALESCE(SUM(file_size),0) FROM '.arc_table('support_file_transfers')." WHERE account_id=? AND expires_at>NOW() AND status IN ('pending','ready')");$q->execute([$access['account_id']]);$quota=$q->fetch(PDO::FETCH_NUM);
        if((int)$quota[0]>=50||(int)$quota[1]+strlen($data)>67108864)throw new RuntimeException('Limite temporário de arquivos atingido. Tente novamente após a expiração.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM ".arc_table('gestor_commands')." WHERE gestor_id=? AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$gestor]);if((int)$q->fetchColumn()>=3)throw new RuntimeException('Aguarde as operações anteriores.');
        $q=$pdo->prepare("SELECT id FROM ".arc_table('gestor_commands')." WHERE gestor_id=? AND command_name='file_transfer' AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$gestor]);if($q->fetchColumn())throw new RuntimeException('Aguarde a transferência anterior.');
        if($direction==='to_gestor'){$key=bin2hex(random_bytes(32));$path=arc_support_file_path($key);if(file_put_contents($path,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Não foi possível guardar o arquivo.');chmod($path,0600);}
        $pdo->prepare("INSERT INTO ".arc_table('gestor_commands')." (account_id,network_id,gestor_id,command_name) VALUES (?,?,?,'file_transfer')")->execute([$access['account_id'],$network,$gestor]);$command=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$command,$user]);
        if($destination!=='')$pdo->prepare('INSERT INTO '.arc_table('gestor_terminal_jobs').' (command_id,gestor_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$command,$gestor,$network,json_encode(['destination'=>$destination])]);
        $pdo->prepare('INSERT INTO '.arc_table('support_file_transfers').' (command_id,account_id,network_id,gestor_id,requested_by,direction,filename,file_size,sha256,storage_key,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$command,$access['account_id'],$network,$gestor,$user,$direction,$name,strlen($data),$key?hash('sha256',$data):null,$key]);$id=(int)$pdo->lastInsertId();if($clientToken!==null)$pdo->prepare('UPDATE '.arc_table('support_file_transfers').' SET client_token=? WHERE id=?')->execute([$clientToken,$id]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($key){$path=arc_support_file_path($key);if(is_file($path))unlink($path);}throw $e;}
    arc_user_audit($pdo,'support_file_requested','Transferência #'.$id.' · gestor #'.$gestor,(int)$access['account_id']);return $id;
}
function arc_support_file_remote_command(PDO $pdo,int $network,int $gestor,int $user,string $command,array $payload=[],bool $createTransfer=false): array {
    if(!in_array($command,['file_bundle','file_execute','file_operation'],true))throw new RuntimeException('Operação inválida.');
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    if(!in_array((string)($access['role']??''),['owner','administrator'],true))throw new RuntimeException('Somente administrador ou proprietário pode usar este recurso.');
    arc_support_files_cleanup($pdo);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$access['account_id']]);
        $q=$pdo->prepare("SELECT a.id,a.gestor_version,a.last_seen_at,a.headless_mode FROM ".arc_table('network_gestores')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1 FOR UPDATE");
        $q->execute([$gestor,$network,$access['account_id']]);$a=$q->fetch();
        if(!$a||strtotime((string)$a['last_seen_at'])<time()-120)throw new RuntimeException('Selecione um computador aprovado e conectado.');
        if((int)($a['headless_mode']??0)!==1)throw new RuntimeException('Ative o modo sem monitor no gestor autorizado.');
        if(version_compare((string)$a['gestor_version'],$command==='file_operation'?'3.6.23':'3.6.0','<'))throw new RuntimeException('Atualize o gestor deste computador para usar o explorador avançado.');
        if(($payload['operation']??'')==='thumbnails'&&version_compare((string)$a['gestor_version'],'3.8.6','<'))throw new RuntimeException('Atualize o gestor para 3.8.6 para carregar miniaturas.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM ".arc_table('gestor_commands')." WHERE gestor_id=? AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$gestor]);if((int)$q->fetchColumn()>=3)throw new RuntimeException('Aguarde as operações anteriores.');
        $q=$pdo->prepare("SELECT id FROM ".arc_table('gestor_commands')." WHERE gestor_id=? AND command_name IN ('file_bundle','file_execute','file_operation') AND status IN ('pending','running') AND created_at>DATE_SUB(NOW(),INTERVAL 4 MINUTE)");$q->execute([$gestor]);if($q->fetchColumn())throw new RuntimeException('Aguarde a operação anterior deste explorador.');
        $pdo->prepare('INSERT INTO '.arc_table('gestor_commands')." (account_id,network_id,gestor_id,command_name) VALUES (?,?,?,?)")->execute([(int)$access['account_id'],$network,$gestor,$command]);$commandId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$commandId,$user]);
        $pdo->prepare('INSERT INTO '.arc_table('gestor_terminal_jobs').' (command_id,gestor_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$commandId,$gestor,$network,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $transferId=0;
        if($createTransfer){
            $pdo->prepare('INSERT INTO '.arc_table('support_file_transfers')." (command_id,account_id,network_id,gestor_id,requested_by,direction,filename,file_size,expires_at,status,message) VALUES (?,?,?,?,?,'from_gestor','',0,DATE_ADD(NOW(),INTERVAL 1 DAY),'pending','Preparando arquivo para download.')")->execute([$commandId,(int)$access['account_id'],$network,$gestor,$user]);
            $transferId=(int)$pdo->lastInsertId();
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    arc_user_audit($pdo,'support_file_remote_'.$command,'Explorador remoto · comando '.$command.' no gestor '.$gestor,(int)$access['account_id']);
    return ['command_id'=>$commandId,'transfer_id'=>$transferId,'account_id'=>(int)$access['account_id']];
}

function arc_support_file_stream(array $row): void {
    if(!$row['storage_key'])throw new RuntimeException('Arquivo ainda não disponível.');$path=arc_support_file_path($row['storage_key']);
    if(!is_file($path)||filesize($path)!==(int)$row['file_size']||!hash_equals((string)$row['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Arquivo indisponível ou incompleto.');
    $size=(int)$row['file_size'];$start=0;$end=max(0,$size-1);$etag='"'.$row['sha256'].'"';
    header('Cache-Control: no-store, private');header('Accept-Ranges: bytes');header('ETag: '.$etag);
    $range=(string)($_SERVER['HTTP_RANGE']??'');
    if($range!==''&&(!isset($_SERVER['HTTP_IF_RANGE'])||$_SERVER['HTTP_IF_RANGE']===$etag)){
        if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]==='')||$size===0){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        if($m[1]===''){$suffix=(int)$m[2];if($suffix===0){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}$start=max(0,$size-$suffix);}
        else{$start=(int)$m[1];$end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);}
        if($start>=$size||$start>$end){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    $length=$size===0?0:$end-$start+1;
    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="arquivo"; filename*=UTF-8\'\''.rawurlencode($row['filename']));header('Content-Length: '.$length);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $stream=fopen($path,'rb');if(!$stream)throw new RuntimeException('Arquivo indisponível.');
    try{fseek($stream,$start);while($length>0&&!connection_aborted()){$chunk=fread($stream,min(65536,$length));if($chunk===false||$chunk==='')break;echo $chunk;$length-=strlen($chunk);}}finally{fclose($stream);}exit;
}
function arc_support_upload_api(PDO $pdo,string $action): void {
    if(!in_array($action,['file-upload-begin','file-upload-chunk','file-upload-finish','file-upload-cancel'],true))throw new RuntimeException('Operação inválida.');
    if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);
    arc_verify_csrf();$user=(int)arc_user_id();
    $network=(int)($_GET['network']??0);$gestor=(int)($_GET['gestor']??0);
    if((int)($_GET['user']??0)!==$user)arc_json(['ok'=>false,'error'=>'Entre novamente na conta que iniciou o envio.'],401);
    $access=arc_support_access($pdo,$network,$user,true);arc_billing_assert_active($pdo,(int)$access['account_id']);
    if(!in_array($access['role'],['owner','administrator'],true))throw new RuntimeException('O explorador exige proprietário ou administrador.');
    $q=$pdo->prepare('SELECT id,headless_mode FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$gestor,$network,$access['account_id']]);$target=$q->fetch();
    if(!$target||!(int)$target['headless_mode'])throw new RuntimeException('Ative o modo sem monitor no computador aprovado.');
    $token=(string)($_GET['token']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new RuntimeException('Identificador de envio inválido.');
    // Nullable idempotency token preserves all existing transfer records.
    $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'client_token\'');$check->execute([arc_prefix().'support_file_transfers']);
    if(!(int)$check->fetchColumn()){
        $lock='arc-upload-schema-'.substr(hash('sha256',arc_prefix()),0,24);$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('Migração ocupada. Tente novamente.');
        try{$check->execute([arc_prefix().'support_file_transfers']);if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE '.arc_table('support_file_transfers').' ADD COLUMN client_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY uq_file_client_token (requested_by,client_token)');}
        finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }
    $prefix=dirname(arc_support_file_path(hash('sha256',$token))).'/upload-'.hash('sha256',$user.':'.$token);
    if(!is_file($prefix.'.json')&&$action==='file-upload-cancel')arc_json(['ok'=>true]);
    if(!is_file($prefix.'.json')&&$action!=='file-upload-begin')throw new RuntimeException('Inicie o envio antes de transferir os dados.');
    $fh=fopen($prefix.'.json','c+');if(!$fh||!flock($fh,LOCK_EX))throw new RuntimeException('Envio ocupado.');chmod($prefix.'.json',0600);
    try{
        $raw=stream_get_contents($fh);$state=$raw!==''?json_decode($raw,true):null;
        if($raw!==''&&!is_array($state))throw new RuntimeException('Registro de envio inválido.');
        $save=static function(array $value)use($fh):void{rewind($fh);ftruncate($fh,0);$json=json_encode($value,JSON_THROW_ON_ERROR);if(fwrite($fh,$json)!==strlen($json)||!fflush($fh))throw new RuntimeException('Não foi possível salvar o progresso.');};
        $q=$pdo->prepare('SELECT id,network_id,gestor_id FROM '.arc_table('support_file_transfers').' WHERE requested_by=? AND client_token=?');$q->execute([$user,$token]);$existing=$q->fetch();
        if($existing){if((int)$existing['network_id']!==$network||(int)$existing['gestor_id']!==$gestor)throw new RuntimeException('Identificador já utilizado em outro computador.');arc_json(['ok'=>true,'id'=>(int)$existing['id'],'completed'=>true]);}
        if($state&&((int)$state['network']!==$network||(int)$state['gestor']!==$gestor))throw new RuntimeException('O computador do envio não corresponde ao pedido.');
        if($action==='file-upload-cancel'){
            if($state){foreach(array_keys($state['files']) as $i){$path=$prefix.'.'.$i;if(is_file($path))unlink($path);}$state['cancelled']=true;$save($state);}
            arc_json(['ok'=>true]);
        }
        if($state&&(!empty($state['cancelled'])||time()-(int)$state['created']>86400))throw new RuntimeException('Envio cancelado ou expirado.');
        if($action==='file-upload-begin'){
            $body=json_decode((string)file_get_contents('php://input',false,null,0,131073),true);
            if(!is_array($body)||!is_array($body['files']??null)||count($body['files'])<1||count($body['files'])>300)throw new RuntimeException('Escolha entre 1 e 300 arquivos.');
            $folder=!empty($body['folder']);if(!$folder&&count($body['files'])!==1)throw new RuntimeException('Escolha um arquivo ou uma pasta.');
            $files=[];$seen=[];$total=0;$root='';
            foreach($body['files'] as $file){
                if(!is_array($file)||!is_int($file['size']??null)||$file['size']<0||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256']??'')))throw new RuntimeException('Arquivo inválido.');
                $parts=explode('/',str_replace('\\','/',(string)($file['name']??'')));$safe=[];foreach($parts as $part)$safe[]=arc_support_file_name($part);
                if(!$folder&&count($safe)!==1)throw new RuntimeException('Nome de arquivo inválido.');
                if($folder){if(count($safe)<2)throw new RuntimeException('Caminho de pasta inválido.');if($root==='')$root=$safe[0];elseif($root!==$safe[0])throw new RuntimeException('Selecione uma única pasta.');}
                $name=implode('/',$safe);$folded=mb_strtolower($name,'UTF-8');if(isset($seen[$folded]))throw new RuntimeException('Há arquivos com caminhos duplicados.');$seen[$folded]=true;
                $total+=$file['size'];if($total>ARC_FILE_MAX_BYTES)throw new RuntimeException('A seleção ultrapassa 8 MB.');
                $files[]=['name'=>$name,'size'=>$file['size'],'sha256'=>$file['sha256']];
            }
            $destination=(string)($body['destination']??'');if(strlen($destination)>1024||!preg_match('~^[A-Za-z]:[\\\\/]~',$destination)||preg_match('/[\x00-\x1f]/',$destination))throw new RuntimeException('Abra a pasta de destino antes de enviar.');
            if($state){if($state['files']!==$files||$state['destination']!==$destination||$state['folder']!==$folder)throw new RuntimeException('O conteúdo do envio foi alterado.');}
            else{
                // Bound disk use even for abandoned browser uploads.
                $directory=dirname($prefix);$staged=0;$active=0;
                foreach(glob($directory.'/upload-*.json')?:[] as $manifest){
                    if($manifest===$prefix.'.json')continue;
                    $old=json_decode((string)@file_get_contents($manifest),true);
                    if(!is_array($old))continue;
                    if(time()-(int)($old['created']??0)>86400){$handle=@fopen($manifest,'r+');if($handle&&flock($handle,LOCK_EX|LOCK_NB)){foreach(array_keys($old['files']??[]) as $i){$part=substr($manifest,0,-5).'.'.$i;if(is_file($part))@unlink($part);}@unlink($manifest);flock($handle,LOCK_UN);}if($handle)fclose($handle);continue;}
                    if((int)($old['account']??0)===(int)$access['account_id']&&empty($old['cancelled'])&&empty($old['id'])){$active++;foreach($old['files']??[] as $f)$staged+=(int)$f['size'];}
                }
                if($active>=8||$staged+$total>67108864)throw new RuntimeException('Limite de envios em preparação atingido.');
                $state=['user'=>$user,'account'=>(int)$access['account_id'],'network'=>$network,'gestor'=>$gestor,'created'=>time(),'folder'=>$folder,'destination'=>$destination,'files'=>$files];$save($state);
            }
            $offsets=[];foreach($files as $i=>$file){clearstatcache(true,$prefix.'.'.$i);$offsets[]=is_file($prefix.'.'.$i)?filesize($prefix.'.'.$i):0;}
            arc_json(['ok'=>true,'offsets'=>$offsets]);
        }
        if(!$state)throw new RuntimeException('Inicie o envio antes de transferir os dados.');
        if($action==='file-upload-chunk'){
            $index=(int)($_GET['index']??-1);$offset=(int)($_GET['offset']??-1);$file=$state['files'][$index]??null;
            $data=file_get_contents('php://input',false,null,0,262145);
            if(!$file||$offset<0||!is_string($data)||strlen($data)>262144||$offset+strlen($data)>$file['size'])throw new RuntimeException('Bloco de envio inválido.');
            $path=$prefix.'.'.$index;$part=fopen($path,'c+b');if(!$part)throw new RuntimeException('Não foi possível guardar o arquivo.');chmod($path,0600);
            try{$size=fstat($part)['size'];if($offset!==$size)arc_json(['ok'=>true,'offset'=>$size]);fseek($part,0,SEEK_END);if(fwrite($part,$data)!==strlen($data)||!fflush($part))throw new RuntimeException('Bloco incompleto.');$size+=strlen($data);}finally{fclose($part);}
            arc_json(['ok'=>true,'offset'=>$size]);
        }
        if($action!=='file-upload-finish')throw new RuntimeException('Operação inválida.');
        foreach($state['files'] as $i=>$file){$path=$prefix.'.'.$i;if(!is_file($path)&&$file['size']===0){file_put_contents($path,'');chmod($path,0600);}if(!is_file($path)||filesize($path)!==$file['size']||!hash_equals($file['sha256'],hash_file('sha256',$path)))throw new RuntimeException('Envio incompleto ou integridade inválida.');}
        if($state['folder']){
            if(!class_exists('ZipArchive'))throw new RuntimeException('Ative a extensão ZIP da hospedagem.');
            $temp=$prefix.'.zip';$zip=new ZipArchive();if($zip->open($temp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível preparar a pasta.');
            try{foreach($state['files'] as $i=>$file){$parts=explode('/',$file['name']);$root=array_shift($parts);if(!$zip->addFile($prefix.'.'.$i,implode('/',$parts)))throw new RuntimeException('Falha ao preparar a pasta.');}}finally{$zip->close();}
            try{$data=file_get_contents($temp);$name='__arcoll_folder__'.$root.'.zip';}finally{if(is_file($temp))unlink($temp);}
        }else{$data=file_get_contents($prefix.'.0');$name=$state['files'][0]['name'];}
        if(!is_string($data)||strlen($data)>ARC_FILE_MAX_BYTES)throw new RuntimeException('O pacote ultrapassa 8 MB.');
        $id=arc_support_file_create($pdo,$network,$gestor,$user,'to_gestor',$name,$data,$state['destination'],$token);$state['id']=$id;$save($state);
        foreach(array_keys($state['files']) as $i){$path=$prefix.'.'.$i;if(is_file($path))unlink($path);}
        arc_json(['ok'=>true,'id'=>$id,'completed'=>true]);
    }finally{$empty=fstat($fh)['size']===0;flock($fh,LOCK_UN);fclose($fh);if($empty&&is_file($prefix.'.json'))@unlink($prefix.'.json');}
}


function arc_support_file_api(PDO $pdo,string $action): void {
    $method=$_SERVER['REQUEST_METHOD'];$isGestor=str_starts_with($action,'file-gestor-');
    if($isGestor){
        if($method!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);
        $gestor=arc_support_file_gestor($pdo);$row=arc_support_file_record($pdo,(int)($_GET['id']??0),$gestor,in_array($action,['file-gestor-upload','file-gestor-finish'],true));
        if($row['status']!=='pending'){
            if($action==='file-gestor-upload'&&$row['direction']==='from_gestor'&&$row['status']==='ready'){
                $data=file_get_contents('php://input',false,null,0,ARC_FILE_MAX_BYTES+1);$hash=(string)($_SERVER['HTTP_X_FILE_SHA256']??'');
                if(is_string($data)&&strlen($data)===(int)$row['file_size']&&hash_equals((string)$row['sha256'],$hash)&&hash_equals($hash,hash('sha256',$data)))arc_json(['ok'=>true,'replayed'=>true]);
            }
            if($action==='file-gestor-finish'&&$row['direction']==='to_gestor'&&$row['status']==='completed'){
                $body=json_decode((string)file_get_contents('php://input',false,null,0,1025),true);if(($body['status']??'')==='completed')arc_json(['ok'=>true,'replayed'=>true]);
            }
            throw new RuntimeException('Transferência já finalizada.');
        }
        if($action==='file-gestor-details'){$access=arc_support_access($pdo,(int)$row['network_id'],(int)$row['requested_by'],true);$q=$pdo->prepare('SELECT headless_mode FROM '.arc_table('network_gestores').' WHERE id=?');$q->execute([$row['gestor_id']]);$row['headless']=(bool)$q->fetchColumn()&&in_array($access['role'],['owner','administrator'],true);$q=$pdo->prepare('SELECT request_text FROM '.arc_table('gestor_terminal_jobs').' WHERE command_id=?');$q->execute([$row['command_id']]);$payload=json_decode((string)$q->fetchColumn(),true);$row['destination']=(string)($payload['destination']??'');}
        if($action==='file-gestor-details')arc_json(['ok'=>true,'transfer'=>array_intersect_key($row,array_flip(['id','command_id','direction','filename','file_size','sha256','requester','headless','destination'])),'max_bytes'=>ARC_FILE_MAX_BYTES]);
        if($action==='file-gestor-download'&&$row['direction']==='to_gestor')arc_support_file_stream($row);
        if($action==='file-gestor-upload'&&$row['direction']==='from_gestor'){
            $name=arc_support_file_name(rawurldecode((string)($_SERVER['HTTP_X_FILE_NAME']??'')));$data=file_get_contents('php://input',false,null,0,ARC_FILE_MAX_BYTES+1);
            $hash=(string)($_SERVER['HTTP_X_FILE_SHA256']??'');if(!is_string($data)||strlen($data)>ARC_FILE_MAX_BYTES||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals(hash('sha256',$data),$hash))throw new RuntimeException('Arquivo inválido, incompleto ou maior que 8 MB.');
            $key=bin2hex(random_bytes(32));$path=arc_support_file_path($key);$pdo->beginTransaction();try{
                $pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE')->execute([$row['account_id']]);
                $q=$pdo->prepare('SELECT COALESCE(SUM(file_size),0) FROM '.arc_table('support_file_transfers').' WHERE account_id=? AND expires_at>NOW() AND status IN (\'pending\',\'ready\')');$q->execute([$row['account_id']]);if((int)$q->fetchColumn()+strlen($data)>67108864)throw new RuntimeException('Limite temporário de arquivos atingido.');
                if(file_put_contents($path,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('Não foi possível guardar o arquivo.');chmod($path,0600);
                $q=$pdo->prepare("UPDATE ".arc_table('support_file_transfers')." SET filename=?,file_size=?,sha256=?,storage_key=?,status='ready',expires_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE),message='Arquivo disponível para baixar.' WHERE id=? AND status='pending'");$q->execute([$name,strlen($data),$hash,$key,$row['id']]);if(!$q->rowCount())throw new RuntimeException('Transferência já finalizada.');
                $pdo->prepare("UPDATE ".arc_table('gestor_commands')." SET status='completed',result_text='Arquivo recebido na plataforma.',completed_at=NOW() WHERE id=? AND status='running'")->execute([$row['command_id']]);$pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(is_file($path))unlink($path);throw $e;}arc_json(['ok'=>true]);
        }
        if($action==='file-gestor-finish'){
            $v=json_decode((string)file_get_contents('php://input',false,null,0,1025),true);$state=(string)($v['status']??'');if(!in_array($state,['completed','declined','failed'],true)||($state==='completed'&&$row['direction']!=='to_gestor'))throw new RuntimeException('Resultado inválido.');
            $message=['completed'=>'Arquivo salvo no computador.','declined'=>'A pessoa no computador cancelou.','failed'=>'Não foi possível concluir a transferência.'][$state];if($state==='failed'){$reason=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',(string)($v['message']??'')));if($reason!=='')$message=mb_substr($reason,0,160);}
            $pdo->prepare('UPDATE '.arc_table('support_file_transfers')." SET status=?,message=? WHERE id=? AND status='pending'")->execute([$state,$message,$row['id']]);arc_support_files_cleanup($pdo);
            $pdo->prepare('UPDATE '.arc_table('gestor_commands')." SET status=?,result_text=?,completed_at=NOW() WHERE id=? AND status='running'")->execute([$state==='completed'?'completed':'failed',$message,$row['command_id']]);arc_json(['ok'=>true]);
        }
        throw new RuntimeException('Operação indisponível.');
    }
    if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Entre na plataforma.'],401);
    if(str_starts_with($action,'file-upload-')){arc_support_upload_api($pdo,$action);exit;}
    if($action==='file-download'&&$method==='GET'){$row=arc_support_file_record($pdo,(int)($_GET['id']??0));if($row['direction']!=='from_gestor'||$row['status']!=='ready')throw new RuntimeException('Arquivo ainda não disponível.');arc_support_file_stream($row);}
    if($action==='file-status'&&$method==='GET'){
        $q=$pdo->prepare('SELECT f.id,f.network_id,f.direction,f.filename,f.file_size,f.status,f.message,c.status AS task_status,c.result_text FROM '.arc_table('support_file_transfers').' f JOIN '.arc_table('gestor_commands').' c ON c.id=f.command_id WHERE f.id=? AND f.requested_by=?');$q->execute([(int)($_GET['id']??0),(int)arc_user_id()]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Transferência não encontrada.');arc_support_access($pdo,(int)$row['network_id'],(int)arc_user_id(),true);unset($row['network_id']);
        if(in_array($row['status'],['pending','ready'],true)&&in_array($row['task_status'],['failed','expired'],true))$row['status']='failed';
        if($row['status']==='failed'&&trim((string)$row['result_text'])!=='')$row['message']=(string)$row['result_text'];
        unset($row['result_text']);arc_json(['ok'=>true,'transfer'=>$row]);
    }
    if($action==='file-list'&&$method==='GET'){
        $network=(int)($_GET['network']??0);$gestor=(int)($_GET['gestor']??0);arc_support_access($pdo,$network,(int)arc_user_id(),true);arc_support_files_cleanup($pdo);
        $q=$pdo->prepare('SELECT f.id,f.direction,f.filename,f.file_size,f.status,f.message,f.expires_at,c.status AS task_status FROM '.arc_table('support_file_transfers').' f JOIN '.arc_table('gestor_commands').' c ON c.id=f.command_id WHERE f.requested_by=? AND f.network_id=? AND f.gestor_id=? ORDER BY f.id DESC LIMIT 20');$q->execute([arc_user_id(),$network,$gestor]);arc_json(['ok'=>true,'transfers'=>$q->fetchAll(),'max_bytes'=>arc_support_file_limit()]);
    }
    if($method!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);arc_verify_csrf();
    $network=(int)($_POST['network']??0);$gestor=(int)($_POST['gestor']??0);
    if($action==='file-operation-request'){
        $operation=(string)($_POST['operation']??'');
        if(!in_array($operation,['rename','delete','copy','move','extract','open','execute','download','preview','thumbnails'],true))throw new RuntimeException('Operação inválida.');
        $paths=json_decode((string)($_POST['paths']??'[]'),true);
        if(!is_array($paths)||count($paths)<1||count($paths)>($operation==='thumbnails'?24:25))throw new RuntimeException('Selecione entre 1 e 25 itens.');
        foreach($paths as $path)if(!is_string($path)||strlen($path)>1024||!preg_match('~^[A-Za-z]:[\\\\/]~',$path)||preg_match('/[\x00-\x1f]/',$path))throw new RuntimeException('Caminho inválido.');
        if(in_array($operation,['delete','execute'],true)&&($_POST['confirmed']??'')!=='1')throw new RuntimeException('Confirme a operação.');
        $payload=['operation'=>$operation,'paths'=>$paths,'destination'=>mb_substr((string)($_POST['destination']??''),0,1024),'name'=>mb_substr((string)($_POST['name']??''),0,180)];
        $result=arc_support_file_remote_command($pdo,$network,$gestor,(int)arc_user_id(),'file_operation',$payload,in_array($operation,['preview','download','thumbnails'],true));
        arc_json(['ok'=>true,'command_id'=>$result['command_id'],'id'=>$result['transfer_id']]);
    }
    if($action==='file-bundle-request'){
        $items=json_decode((string)($_POST['items']??'[]'),true);
        if(!is_array($items)||!$items)throw new RuntimeException('Marque pelo menos um arquivo ou pasta para baixar.');
        $paths=[];
        foreach(array_slice($items,0,25) as $value){$value=trim((string)$value);if($value!==''&&strlen($value)<=1024)$paths[]=$value;}
        if(!$paths)throw new RuntimeException('Os caminhos selecionados são inválidos.');
        $payload=['paths'=>$paths,'current_path'=>mb_substr((string)($_POST['current_path']??''),0,1024)];
        $result=arc_support_file_remote_command($pdo,$network,$gestor,(int)arc_user_id(),'file_bundle',$payload,true);
        arc_json(['ok'=>true,'id'=>$result['transfer_id'],'command_id'=>$result['command_id']]);
    }
    if($action==='file-execute-request'){
        $path=trim((string)($_POST['path']??''));
        if($path===''||strlen($path)>1024)throw new RuntimeException('Selecione um executável válido.');
        $result=arc_support_file_remote_command($pdo,$network,$gestor,(int)arc_user_id(),'file_execute',['path'=>$path],false);
        arc_json(['ok'=>true,'command_id'=>$result['command_id']]);
    }
    if($action==='file-cancel'){
        $id=(int)($_POST['id']??0);if($id<1)throw new RuntimeException('Transferência inválida.');$access=arc_support_access($pdo,$network,(int)arc_user_id(),true);
        $q=$pdo->prepare('SELECT f.id,f.command_id,f.storage_key FROM '.arc_table('support_file_transfers').' f WHERE f.id=? AND f.requested_by=? AND f.network_id=? AND f.gestor_id=? AND f.account_id=? AND f.status IN (\'pending\',\'ready\')');
        $q->execute([$id,(int)arc_user_id(),$network,$gestor,(int)$access['account_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Transferência concluída ou não encontrada.');
        $pdo->beginTransaction();try{$q=$pdo->prepare('UPDATE '.arc_table('support_file_transfers')." SET status='cancelled',file_size=0,storage_key=NULL,sha256=NULL,message='Cancelada pelo usuário' WHERE id=? AND requested_by=? AND status IN ('pending','ready')");$q->execute([$id,(int)arc_user_id()]);if(!$q->rowCount())throw new RuntimeException('A transferência já foi concluída ou cancelada.');$pdo->prepare('UPDATE '.arc_table('gestor_commands')." SET status='failed',result_text='Transferência cancelada pelo usuário',completed_at=NOW() WHERE id=? AND status IN ('pending','running')")->execute([(int)$row['command_id']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        if($row['storage_key']){$path=arc_support_file_path((string)$row['storage_key']);if(is_file($path))@unlink($path);}
        arc_user_audit($pdo,'support_file_cancelled','Transferência #'.$id.' cancelada',(int)arc_user_id());arc_json(['ok'=>true,'cancelled'=>true]);
    }
    if($action==='file-send-folder'){
        $paths=json_decode((string)($_POST['relative_paths']??''),true);$files=$_FILES['files']??null;$maxFiles=min(300,max(1,(int)ini_get('max_file_uploads')));
        if(!class_exists('ZipArchive')||!is_array($paths)||!is_array($files)||!isset($files['tmp_name'])||count($paths)<1||count($paths)>$maxFiles||count($files['tmp_name'])!==count($paths))throw new RuntimeException('Selecione uma pasta com até '.$maxFiles.' arquivos; este é o limite configurado no servidor.');
        $root='';$total=0;$seen=[];$temp=tempnam(sys_get_temp_dir(),'arc-folder-');if($temp===false)throw new RuntimeException('Não foi possível preparar a pasta.');$zip=new ZipArchive();
        try{
            if($zip->open($temp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Não foi possível criar o pacote da pasta.');
            foreach($paths as $i=>$rel){if(!is_string($rel)||strlen($rel)>1024||str_contains($rel,"\0"))throw new RuntimeException('Caminho relativo inválido.');$parts=explode('/',str_replace('\\','/',$rel));$parts=array_values(array_filter($parts,static fn($v)=>$v!==''));if(count($parts)<2)throw new RuntimeException('Escolha uma pasta, não apenas arquivos avulsos.');$safe=[];foreach($parts as $part)$safe[]=arc_support_file_name($part);if($root==='' )$root=$safe[0];elseif($root!==$safe[0])throw new RuntimeException('Os arquivos selecionados não pertencem à mesma pasta.');$name=implode('/',array_slice($safe,1));$folded=mb_strtolower($name,'UTF-8');if(isset($seen[$folded]))throw new RuntimeException('A pasta contém nomes duplicados.');$seen[$folded]=true;if((int)($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)$files['tmp_name'][$i]))throw new RuntimeException('Um arquivo da pasta não foi recebido corretamente.');$size=(int)($files['size'][$i]??0);$total+=$size;if($total>ARC_FILE_MAX_BYTES)throw new RuntimeException('Pasta acima do limite de 8 MB descompactados.');if(!$zip->addFile((string)$files['tmp_name'][$i],$name))throw new RuntimeException('Não foi possível empacotar um arquivo.');}
            if(!$zip->close())throw new RuntimeException('Falha ao concluir o pacote da pasta.');$bytes=file_get_contents($temp);if(!is_string($bytes)||strlen($bytes)>ARC_FILE_MAX_BYTES)throw new RuntimeException('Pasta compactada acima do limite de 8 MB.');
            $safeRoot=arc_support_file_name($root);$id=arc_support_file_create($pdo,$network,$gestor,(int)arc_user_id(),'to_gestor','__arcoll_folder__'.$safeRoot.'.zip',$bytes,mb_substr((string)($_POST['destination']??''),0,1024));
        }finally{if(is_file($temp))@unlink($temp);}
        arc_json(['ok'=>true,'id'=>$id]);
    }
    if($action==='file-send'){
        $file=$_FILES['file']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||(int)$file['size']>arc_support_file_limit()||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Escolha um arquivo dentro do limite permitido pela hospedagem.');
        $data=file_get_contents($file['tmp_name'],false,null,0,ARC_FILE_MAX_BYTES+1);if(!is_string($data))throw new RuntimeException('Arquivo indisponível.');$id=arc_support_file_create($pdo,$network,$gestor,(int)arc_user_id(),'to_gestor',(string)$file['name'],$data,mb_substr((string)($_POST['destination']??''),0,1024));
    }elseif($action==='file-receive')$id=arc_support_file_create($pdo,$network,$gestor,(int)arc_user_id(),'from_gestor');
    else throw new RuntimeException('Operação indisponível.');arc_json(['ok'=>true,'id'=>$id]);
}

/** Predefined scheduled maintenance uses the existing authenticated command queue. */
function arc_support_schedule_actions(): array {
 return ['gestor_update'=>'Atualizar gestor','power_restart'=>'Reiniciar PC','screen_lock'=>'Bloquear PC','windows_update'=>'Verificar atualizações do Windows','power_shutdown'=>'Desligar PC'];
}
function arc_support_schedule_date(string $value): DateTimeImmutable {
 $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone(ARC_TIMEZONE));
 if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Informe uma data e um horário válidos.');return $date;
}
/** Next occurrence strictly after $after; anchored intervals never drift or backfill. */
function arc_support_schedule_next(array $s,DateTimeImmutable $after): ?string {
 $zone=new DateTimeZone(ARC_TIMEZONE);$start=new DateTimeImmutable($s['starts_at'],$zone);$end=empty($s['ends_at'])?null:new DateTimeImmutable($s['ends_at'],$zone);$next=null;
 if($s['recurrence']==='once')$next=$start>$after?$start:null;
 elseif($s['recurrence']==='interval'){
  $step=max(15,(int)$s['interval_minutes'])*60;$n=max(0,(int)floor(($after->getTimestamp()-$start->getTimestamp())/$step)+1);$next=$start->modify('+'.($n*$step).' seconds');
 }elseif($s['recurrence']==='weekdays'){
  $days=explode(',',(string)$s['weekdays']);$day=($after>$start?$after:$start)->setTime((int)$start->format('H'),(int)$start->format('i'));
  for($i=0;$i<8;$i++,$day=$day->modify('+1 day'))if($day>$after&&$day>=$start&&in_array($day->format('N'),$days,true)){$next=$day;break;}
 }
 return $next&&(!$end||$next<=$end)?$next->format('Y-m-d H:i:s'):null;
}
function arc_support_schedule_owner(PDO $pdo,int $network,int $user): array {
 $access=arc_support_access($pdo,$network,$user,true);if(!in_array($access['role'],['owner','administrator'],true))throw new RuntimeException('Somente proprietário ou administrador pode gerenciar agendamentos.');return $access;
}
function arc_support_schedule_api(PDO $pdo,string $action,array $v,int $user): never {
 $network=(int)($v['network_id']??0);$access=arc_support_schedule_owner($pdo,$network,$user);$account=(int)$access['account_id'];$gestor=(int)($v['gestor_id']??0);
 $q=$pdo->prepare('SELECT id,gestor_version FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$gestor,$network,$account]);$target=$q->fetch();if(!$target)throw new RuntimeException('Selecione um computador aprovado.');
 if($action==='schedule-list'){
  $q=$pdo->prepare('SELECT * FROM '.arc_table('support_schedules').' WHERE account_id=? AND network_id=? AND gestor_id=? ORDER BY id DESC LIMIT 100');$q->execute([$account,$network,$gestor]);$rows=$q->fetchAll();
  $q=$pdo->prepare('SELECT r.schedule_id,r.scheduled_for,r.outcome,r.message,c.status AS task_status,c.result_text,s.command_name FROM '.arc_table('support_schedule_runs').' r JOIN '.arc_table('support_schedules').' s ON s.id=r.schedule_id LEFT JOIN '.arc_table('gestor_commands').' c ON c.id=r.command_id WHERE s.account_id=? AND s.network_id=? AND s.gestor_id=? ORDER BY r.id DESC LIMIT 300');$q->execute([$account,$network,$gestor]);arc_json(['ok'=>true,'schedules'=>$rows,'runs'=>$q->fetchAll(),'timezone'=>ARC_TIMEZONE]);
 }
 if($action==='schedule-cancel'){
  $id=(int)($v['id']??0);$pdo->beginTransaction();try{
   $q=$pdo->prepare('SELECT id FROM '.arc_table('support_schedules').' WHERE id=? AND account_id=? AND network_id=? AND gestor_id=? FOR UPDATE');$q->execute([$id,$account,$network,$gestor]);if(!$q->fetchColumn())throw new RuntimeException('Agendamento não encontrado.');
   $pdo->prepare('UPDATE '.arc_table('support_schedules').' SET enabled=0,next_run_at=NULL WHERE id=?')->execute([$id]);
   $pdo->prepare('UPDATE '.arc_table('gestor_commands')." c JOIN ".arc_table('support_schedule_runs')." r ON r.command_id=c.id SET c.status='expired',c.result_text='Agendamento cancelado pelo gestor',c.completed_at=NOW() WHERE r.schedule_id=? AND c.status IN ('pending','running')")->execute([$id]);$pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  arc_user_audit($pdo,'support_schedule_cancelled','Agendamento #'.$id.' cancelado',$user);arc_json(['ok'=>true]);
 }
 if($action!=='schedule-save')throw new RuntimeException('Ação inválida.');
 arc_billing_assert_active($pdo,$account);$command=(string)($v['command']??'');if(!isset(arc_support_schedule_actions()[$command]))throw new RuntimeException('Escolha um serviço da lista.');
 if(version_compare((string)$target['gestor_version'],'3.4.0','<'))throw new RuntimeException('Atualize o gestor para 3.4.0 ou superior antes de agendar serviços.');
 if($command==='windows_update'&&version_compare((string)$target['gestor_version'],'3.7.1','<'))throw new RuntimeException('Atualize o gestor antes de agendar a verificação detalhada do Windows.');
 if(str_starts_with($command,'power_')){
  $q=$pdo->prepare('SELECT password_hash FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$user]);if(($v['confirmed']??false)!==true||!((($hash=(string)$q->fetchColumn())!=='' )&&(password_verify((string)($v['password']??''),$hash)||password_verify(base64_encode(hash('sha384',(string)($v['password']??''),true)),$hash))))throw new RuntimeException('Confirme a ação e informe a senha da sua conta.');
 }
 $now=new DateTimeImmutable('now',new DateTimeZone(ARC_TIMEZONE));$start=arc_support_schedule_date((string)($v['starts_at']??''));$end=empty($v['ends_at'])?null:arc_support_schedule_date((string)$v['ends_at']);
 if($start<=$now||$start>$now->modify('+1 year')||($end&&($end<$start||$end>$start->modify('+1 year'))))throw new RuntimeException('Escolha um início futuro e um período de até um ano.');
 $recurrence=(string)($v['recurrence']??'once');if(!in_array($recurrence,['once','weekdays','interval'],true))throw new RuntimeException('Escolha uma repetição válida.');
 $days=$v['weekdays']??[];if(!is_array($days))throw new RuntimeException('Escolha os dias da semana.');$days=array_unique(array_map('intval',$days));sort($days);if($recurrence==='weekdays'&&(!$days||min($days)<1||max($days)>7))throw new RuntimeException('Escolha ao menos um dia da semana.');
 $interval=filter_var($v['interval_minutes']??60,FILTER_VALIDATE_INT,['options'=>['min_range'=>15,'max_range'=>10080]]);if($interval===false)throw new RuntimeException('O intervalo deve ser de 15 minutos a 7 dias.');
 $s=['starts_at'=>$start->format('Y-m-d H:i:s'),'ends_at'=>$end?->format('Y-m-d H:i:s'),'recurrence'=>$recurrence,'weekdays'=>implode(',',$days),'interval_minutes'=>$interval];$next=arc_support_schedule_next($s,$now);if(!$next)throw new RuntimeException('Nenhuma execução neste período. Confira as datas e os dias.');
 $pdo->beginTransaction();try{
  $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$account]);
  $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('support_schedules').' WHERE account_id=? AND enabled=1 AND next_run_at IS NOT NULL');$q->execute([$account]);if((int)$q->fetchColumn()>=50)throw new RuntimeException('Limite de 50 agendamentos ativos. Cancele um antes de criar outro.');
  $pdo->prepare('INSERT INTO '.arc_table('support_schedules').' (account_id,network_id,gestor_id,command_name,recurrence,starts_at,ends_at,weekdays,interval_minutes,next_run_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$account,$network,$gestor,$command,$recurrence,$s['starts_at'],$s['ends_at'],$s['weekdays'],$interval,$next]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO '.arc_table('support_schedule_managers').' (schedule_id,user_id) VALUES (?,?)')->execute([$id,$user]);$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 arc_user_audit($pdo,'support_schedule_created','Agendamento #'.$id.' · '.arc_support_schedule_actions()[$command],$user);arc_json(['ok'=>true,'id'=>$id]);
}
/** Called by the approved gestor's normal poll. No cron or additional Windows worker. */
function arc_support_schedules_dispatch(PDO $pdo,array $gestor): void {
 $pdo->beginTransaction();try{
  $q=$pdo->prepare('SELECT s.*,COALESCE(sm.user_id,s.account_id) AS requested_by,a.gestor_version,u.account_status FROM '.arc_table('support_schedules').' s LEFT JOIN '.arc_table('support_schedule_managers').' sm ON sm.schedule_id=s.id JOIN '.arc_table('network_gestores').' a ON a.id=s.gestor_id AND a.account_id=s.account_id AND a.network_id=s.network_id JOIN '.arc_table('user_accounts').' u ON u.id=s.account_id WHERE s.gestor_id=? AND s.account_id=? AND s.network_id=? AND s.enabled=1 AND s.next_run_at<=NOW() ORDER BY s.next_run_at,s.id LIMIT 20 FOR UPDATE');$q->execute([$gestor['id'],$gestor['account_id'],$gestor['network_id']]);$rows=$q->fetchAll();$now=new DateTimeImmutable('now',new DateTimeZone(ARC_TIMEZONE));
  foreach($rows as $s){
   $slot=$s['next_run_at'];$outcome='skipped';$message='Horário perdido: o computador não respondeu a tempo.';$command=null;
   if(strtotime($slot)>=$now->getTimestamp()-180){
    $message='Computador ocupado; serviço não executado.';
    $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('gestor_commands')." WHERE gestor_id=? AND status IN ('pending','running')");$q->execute([$gestor['id']]);$busy=(int)$q->fetchColumn()>0;
    $valid=$s['account_status']==='active'&&isset(arc_support_schedule_actions()[$s['command_name']])&&version_compare((string)$s['gestor_version'],'3.4.0','>=');
    try{$manager=arc_support_schedule_owner($pdo,(int)$s['network_id'],(int)$s['requested_by']);if((int)$manager['account_id']!==(int)$s['account_id'])$valid=false;arc_billing_assert_active($pdo,(int)$s['account_id']);}catch(PDOException $e){throw $e;}catch(RuntimeException $e){$valid=false;}
    if($s['command_name']==='windows_update'&&version_compare((string)$s['gestor_version'],'3.7.1','<'))$valid=false;
    if(!$valid)$message='Autorização ou versão do gestor indisponível.';
    if(!$busy&&$valid){$pdo->prepare('INSERT INTO '.arc_table('gestor_commands').' (account_id,network_id,gestor_id,command_name) VALUES (?,?,?,?)')->execute([$s['account_id'],$s['network_id'],$s['gestor_id'],$s['command_name']]);$command=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO '.arc_table('support_command_requests').' (command_id,user_id) VALUES (?,?)')->execute([$command,$s['requested_by']]);if($s['command_name']==='windows_update')$pdo->prepare('INSERT INTO '.arc_table('gestor_terminal_jobs').' (command_id,gestor_id,network_id,request_text) VALUES (?,?,?,?)')->execute([$command,$s['gestor_id'],$s['network_id'],json_encode(['mode'=>'scan'])]);$outcome='queued';$message='Enviado ao computador.';}
   }
   $pdo->prepare('INSERT INTO '.arc_table('support_schedule_runs').' (schedule_id,scheduled_for,command_id,outcome,message) VALUES (?,?,?,?,?)')->execute([$s['id'],$slot,$command,$outcome,$message]);
   $pdo->prepare('UPDATE '.arc_table('support_schedules').' SET next_run_at=? WHERE id=?')->execute([arc_support_schedule_next($s,$now),$s['id']]);
  }$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Short guest codes never grant unattended access; their session is marked before queue commit. */
function arc_support_guest_code(PDO $pdo,int $gestor): string {
 $pdo->beginTransaction();try{
  $q=$pdo->prepare('SELECT id FROM '.arc_table('network_gestores').' WHERE id=? FOR UPDATE');$q->execute([$gestor]);
  $pdo->prepare('UPDATE '.arc_table('support_guest_codes').' SET expires_at=NOW() WHERE gestor_id=? AND command_id IS NULL')->execute([$gestor]);
  $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('support_guest_codes').' WHERE gestor_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE)');$q->execute([$gestor]);if((int)$q->fetchColumn()>=5)throw new RuntimeException('Aguarde um minuto antes de gerar outro código.');
  $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for($attempt=0;$attempt<10;$attempt++){$code='';for($i=0;$i<5;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];$q=$pdo->prepare('INSERT IGNORE INTO '.arc_table('support_guest_codes').' (gestor_id,code_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 3 MINUTE))');$q->execute([$gestor,hash('sha256',$code)]);if($q->rowCount()){$pdo->commit();return $code;}}
  throw new RuntimeException('Não foi possível gerar o código. Tente novamente.');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_support_native_account(PDO $pdo): int {
 $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m))return 0;
 $q=$pdo->prepare('SELECT t.user_id FROM '.arc_table('support_native_accounts')." t JOIN ".arc_table('user_accounts')." u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>NOW() AND u.account_status='active'");$q->execute([hash('sha256',$m[1])]);return (int)$q->fetchColumn();
}
function arc_support_native_api(PDO $pdo,string $action,array $v): never {
 if($action==='native-login'){
  $email=strtolower(trim((string)($v['email']??'')));$attempt=arc_login_attempt_begin($pdo,$email);if(!$attempt)throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos.');
  $q=$pdo->prepare('SELECT id,password_hash,account_status FROM '.arc_table('user_accounts').' WHERE email_hash=?');$q->execute([hash('sha256',$email)]);$u=$q->fetch();$password=(string)($v['password']??'');$material=base64_encode(hash('sha384',$password,true));
  if(!$u||$u['account_status']!=='active'||!(password_verify($material,$u['password_hash'])||password_verify($password,$u['password_hash'])))throw new RuntimeException('E-mail ou senha incorretos.');
  arc_login_attempt_succeeded($pdo,$attempt);$token=bin2hex(random_bytes(32));$pdo->prepare('INSERT INTO '.arc_table('support_native_accounts').' (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 8 HOUR))')->execute([$u['id'],hash('sha256',$token)]);arc_user_audit($pdo,'support_native_login','Entrada no aplicativo de suporte',(int)$u['id']);arc_json(['ok'=>true,'token'=>$token]);
 }
 if($action==='guest-connect'){
  $attempt=arc_login_attempt_begin($pdo,'support-guest');if(!$attempt)throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos.');$code=strtoupper(trim((string)($v['code']??'')));if(!preg_match('/^[A-Z2-9]{5}$/D',$code))throw new RuntimeException('Código inválido ou expirado.');
  $q=$pdo->prepare('SELECT g.id,a.network_id,a.id AS gestor,a.account_id FROM '.arc_table('support_guest_codes').' g JOIN '.arc_table('network_gestores').' a ON a.id=g.gestor_id WHERE g.code_hash=? AND g.command_id IS NULL AND g.expires_at>NOW()');$q->execute([hash('sha256',$code)]);$g=$q->fetch();if(!$g)throw new RuntimeException('Código inválido ou expirado.');
  $id=arc_support_request_remote($pdo,(int)$g['network_id'],(int)$g['gestor'],(int)$g['account_id'],0,(int)$g['id']);$result=arc_support_redeem($pdo,arc_support_issue_code($pdo,$id,(int)$g['account_id']));arc_login_attempt_succeeded($pdo,$attempt);arc_json(['ok'=>true]+$result);
 }
 $user=arc_support_native_account($pdo);if(!$user)throw new RuntimeException('Entre novamente na sua conta.');
 if($action==='native-logout'){$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');$pdo->prepare('DELETE FROM '.arc_table('support_native_accounts').' WHERE token_hash=?')->execute([hash('sha256',substr($auth,7))]);arc_json(['ok'=>true]);}
 if($action==='native-networks'){
  $q=$pdo->prepare('SELECT n.id,n.name,a.id AS gestor_id,a.machine_name,a.last_seen_at,a.headless_mode FROM '.arc_table('managed_networks').' n JOIN '.arc_table('network_gestores')." a ON a.network_id=n.id AND a.account_id=n.account_id AND a.status='approved' WHERE n.is_active=1 AND (n.account_id=? OR EXISTS(SELECT 1 FROM ".arc_table('network_team_members')." tm WHERE tm.network_id=n.id AND tm.account_id=n.account_id AND tm.member_user_id=? AND tm.enabled=1)) ORDER BY n.name,a.machine_name LIMIT 500");$q->execute([$user,$user]);$rows=[];foreach($q->fetchAll() as $r){try{$access=arc_support_access($pdo,(int)$r['id'],$user,true);$r['role']=$access['role'];$rows[]=$r;}catch(RuntimeException $e){}}arc_json(['ok'=>true,'computers'=>$rows]);
 }
 if($action==='native-connect'){$id=arc_support_request_remote($pdo,(int)($v['network_id']??0),(int)($v['gestor_id']??0),$user);arc_json(['ok'=>true]+arc_support_redeem($pdo,arc_support_issue_code($pdo,$id,$user)));}
 throw new RuntimeException('Ação inválida.');
}
/** Public designer metadata contains only validated colors and public icon URLs. */
function arc_support_design(PDO $pdo,string $component): array {
 $component=$component==='support'?'support':'gestor';$color=arc_platform_setting($pdo,'platform_'.$component.'_accent',$component==='support'?'#8b5cf6':'#087eac');if(!preg_match('/^#[a-f0-9]{6}$/iD',$color))$color='#087eac';
 return ['palette'=>arc_platform_setting($pdo,'platform_'.$component.'_palette',$component==='support'?'violeta':'azul'),'accent'=>$color,'icon'=>arc_platform_setting($pdo,'platform_'.$component.'_icon','')];
}

function arc_support_icon(PDO $pdo,string $component): string {
 $design=arc_support_design($pdo,$component);$name=basename((string)(parse_url($design['icon'],PHP_URL_PATH)?:''));$path='';
 if(preg_match('/^[a-f0-9]{32,40}\.(png|jpg|webp)$/D',$name))$path=arc_upload_dir('brand').'/'.$name;
 if(!$path||!is_file($path)){$name=(string)(arc_setting_values($pdo)['logo_path']??'');if(preg_match('/^[a-f0-9]{32,40}\.(png|jpg|webp)$/D',$name))$path=arc_upload_dir('brand').'/'.$name;}
 if(!$path||!is_file($path))$path=dirname(__DIR__).'/assets/img/app-icon-192.png';
 if(!function_exists('imagecreatefromstring'))throw new RuntimeException('Ative GD para gerar os ícones Windows.');$image=imagecreatefromstring((string)file_get_contents($path));if(!$image)throw new RuntimeException('Ícone inválido.');
 // Recursos ICO em tamanhos nativos: preserva proporção e transparência em DPI alto.
 $entries='';$images='';$sizes=[16,24,32,48,64,128,256];$offset=6+16*count($sizes);
 foreach($sizes as $size){
  $canvas=imagecreatetruecolor($size,$size);imagealphablending($canvas,false);imagesavealpha($canvas,true);
  imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));
  $scale=min($size/imagesx($image),$size/imagesy($image));$w=max(1,(int)(imagesx($image)*$scale));$h=max(1,(int)(imagesy($image)*$scale));
  imagecopyresampled($canvas,$image,(int)(($size-$w)/2),(int)(($size-$h)/2),0,0,$w,$h,imagesx($image),imagesy($image));
  ob_start();imagepng($canvas);$png=(string)ob_get_clean();imagedestroy($canvas);
  $entries.=pack('CCCCvvVV',$size===256?0:$size,$size===256?0:$size,0,0,1,32,strlen($png),$offset);$images.=$png;$offset+=strlen($png);
 }
 imagedestroy($image);return pack('vvv',0,1,count($sizes)).$entries.$images;
}

function arc_support_command_request_allowed(PDO $pdo,int $command,int $network,int $account): bool {
    $q=$pdo->prepare('SELECT user_id,admin_id FROM '.arc_table('support_command_requests').' WHERE command_id=?');
    $q->execute([$command]);$record=$q->fetch(PDO::FETCH_ASSOC);
    if ($record && (int)($record['admin_id']??0)>0) {
        $adm=$pdo->prepare('SELECT id FROM '.arc_table('admins').' WHERE id=? LIMIT 1');
        $adm->execute([(int)$record['admin_id']]);
        if (!$adm->fetchColumn()) return false;
        $net=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1 LIMIT 1');
        $net->execute([$network,$account]);return (bool)$net->fetchColumn();
    }
    $user=(int)($record['user_id']??$account);
    try{$a=arc_support_schedule_owner($pdo,$network,$user);return (int)$a['account_id']===$account;}catch(RuntimeException $e){return false;}
}

/** One machine-scoped revalidation for all unattended support operations. */
function arc_support_unattended_command(PDO $pdo,array $gestor,int $id): ?array {
    $q=$pdo->prepare('SELECT c.*,a.headless_mode,t.request_text FROM '.arc_table('gestor_commands')." c JOIN ".arc_table('network_gestores')." a ON a.id=c.gestor_id AND a.account_id=c.account_id AND a.network_id=c.network_id LEFT JOIN ".arc_table('gestor_terminal_jobs')." t ON t.command_id=c.id WHERE c.id=? AND c.gestor_id=? AND c.account_id=? AND c.network_id=? AND a.status='approved' AND a.headless_mode=1 AND c.status='running' AND ((c.command_name='screen_lock' AND c.dispatched_at>DATE_SUB(NOW(),INTERVAL 65 MINUTE)) OR (c.command_name='program_install' AND c.dispatched_at>DATE_SUB(NOW(),INTERVAL 120 MINUTE)) OR (c.command_name NOT IN ('screen_lock','program_install') AND c.dispatched_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)))");
    $q->execute([$id,$gestor['id'],$gestor['account_id'],$gestor['network_id']]);$row=$q->fetch();
    if(!$row||!arc_support_command_request_allowed($pdo,$id,(int)$gestor['network_id'],(int)$gestor['account_id']))return null;
    arc_billing_assert_active($pdo,(int)$gestor['account_id']);return $row;
}

/** Unlock is available only inside an explicitly unattended administrative remote session. */
function arc_support_unlock_allowed(PDO $pdo,array $command,int $user): bool {
    try{$access=arc_support_access($pdo,(int)$command['network_id'],$user,true);}catch(RuntimeException $e){return false;}
    if(!in_array($access['role'],['owner','administrator','system_admin'],true)||(int)$access['account_id']!==(int)$command['account_id']||!arc_support_remote_allowed($pdo,$command,$user))return false;
    $q=$pdo->prepare('SELECT s.unattended,a.headless_mode FROM '.arc_table('support_remote_sessions').' s JOIN '.arc_table('network_gestores').' a ON a.id=? AND a.network_id=s.network_id AND a.account_id=s.account_id WHERE s.command_id=?');$q->execute([$command['gestor_id'],$command['id']]);$row=$q->fetch();
    return $row&&(int)$row['unattended']===1&&(int)$row['headless_mode']===1;
}
