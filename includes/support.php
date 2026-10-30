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
