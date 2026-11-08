<?php
/* Caminho: api/support.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
try{
 $pdo=arc_db();arc_ensure_user_schema($pdo);
 $action=(string)($_GET['action']??'');if($action==='design'&&$_SERVER['REQUEST_METHOD']==='GET')arc_json(['ok'=>true,'design'=>arc_support_design($pdo,(string)($_GET['component']??'support'))]);if(str_starts_with($action,'file-')){arc_support_file_api($pdo,$action);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $raw=file_get_contents('php://input',false,null,0,8193);if(!is_string($raw)||strlen($raw)>8192)arc_json(['ok'=>false,'error'=>'Dados inválidos'],413);
 $v=json_decode($raw,true);if(!is_array($v))arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
 if(str_starts_with($action,'native-')||$action==='guest-connect')arc_support_native_api($pdo,$action,$v);
 if(($_GET['action']??'')==='redeem'){
  // Codes contain 80 random bits, expire in two minutes and are consumed atomically.
  arc_json(['ok'=>true]+arc_support_redeem($pdo,strtoupper(trim((string)($v['code']??'')))));
 }
 if(!arc_is_user())arc_json(['ok'=>false,'error'=>'Entre na plataforma.'],401);
 arc_verify_csrf();$user=(int)arc_user_id();
 if($action==='windows-update-cancel'){
  $network=(int)($v['network_id']??0);$gestor=(int)($v['gestor_id']??0);$id=(int)($v['command_id']??0);
  $access=arc_network_team_access($pdo,$network,$user);
  if(!$access||!in_array($access['role'],['owner','administrator'],true))arc_json(['ok'=>false,'error'=>'Permissão administrativa necessária.'],403);
  if($id<=0||$gestor<=0)arc_json(['ok'=>false,'error'=>'Comando inválido.'],422);
  $q=$pdo->prepare('UPDATE '.arc_table('gestor_commands')." SET status='failed',result_text='Cancelado antes do início',completed_at=NOW() WHERE id=? AND gestor_id=? AND network_id=? AND account_id=? AND command_name='windows_update' AND status='pending'");
  $q->execute([$id,$gestor,$network,(int)$access['account_id']]);
  if(!$q->rowCount())arc_json(['ok'=>false,'error'=>'Esta etapa já foi iniciada pelo Windows e não pode ser interrompida com segurança.'],409);
  arc_user_audit($pdo,'windows_update_cancel','Windows Update cancelado antes da execução: '.$id);
  arc_json(['ok'=>true,'cancelled'=>true]);
 }
 if($action==='windows-update-status'){
  $network=(int)($v['network_id']??0);$gestor=(int)($v['gestor_id']??0);$access=arc_support_schedule_owner($pdo,$network,$user);
  $q=$pdo->prepare('SELECT platform FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$gestor,$network,$access['account_id']]);$platform=$q->fetchColumn();if($platform===false)throw new RuntimeException('Computador não autorizado.');
  arc_expire_running_commands($pdo,$gestor);$pdo->prepare('UPDATE '.arc_table('gestor_commands')." SET status='expired',completed_at=NOW(),result_text='O computador não respondeu a tempo.' WHERE gestor_id=? AND network_id=? AND account_id=? AND command_name='windows_update' AND status='pending' AND created_at<DATE_SUB(NOW(),INTERVAL 3 MINUTE)")->execute([$gestor,$network,$access['account_id']]);
  $q=$pdo->prepare('SELECT c.id,c.status,c.result_text,c.completed_at,t.output_text FROM '.arc_table('gestor_commands').' c LEFT JOIN '.arc_table('gestor_terminal_jobs')." t ON t.command_id=c.id WHERE c.gestor_id=? AND c.network_id=? AND c.account_id=? AND c.command_name='windows_update' ORDER BY c.id DESC LIMIT 1");$q->execute([$gestor,$network,$access['account_id']]);$latest=$q->fetch();$report=$latest&&$latest['status']==='completed'?json_decode((string)$latest['output_text'],true):null;
  $previous=$pdo->prepare('SELECT c.id,t.output_text FROM '.arc_table('gestor_commands').' c JOIN '.arc_table('gestor_terminal_jobs')." t ON t.command_id=c.id WHERE c.gestor_id=? AND c.network_id=? AND c.account_id=? AND c.command_name='windows_update' AND c.status='completed' AND t.output_text LIKE ? ORDER BY c.id DESC LIMIT 1");
  $previous->execute([$gestor,$network,$access['account_id'],'%"mode":"scan"%']);$scanRow=$previous->fetch();
  $scanReport=$scanRow?json_decode((string)$scanRow['output_text'],true):null;
  arc_json(['ok'=>true,'windows'=>$platform,'command'=>$latest?['id'=>(int)$latest['id'],'status'=>$latest['status'],'message'=>$latest['result_text']]:null,'report'=>$report,'scan_report'=>$scanReport,'scan_id'=>$scanRow?(int)$scanRow['id']:0]);
 }
 if($action==='unlock-status'){
  $network=(int)($v['network_id']??0);$gestor=(int)($v['gestor_id']??0);$access=arc_support_schedule_owner($pdo,$network,$user);
  $q=$pdo->prepare('SELECT id,headless_mode,gestor_version FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$gestor,$network,$access['account_id']]);$target=$q->fetch();
  if(!$target)throw new RuntimeException('Computador indisponível.');
  $state=json_decode((string)@file_get_contents(ARC_PRIVATE_DIR.'/storage/remote-screen/gestor-'.$gestor.'.json'),true);
  $fresh=is_array($state)&&time()-(int)($state['updated']??0)<=15;
  arc_json(['ok'=>true,'desktop_state'=>$fresh?($state['desktop_state']??'unknown'):'unknown','windows_users'=>$fresh?($state['windows_users']??[]):[],'windows_users_error'=>$fresh&&!empty($state['windows_users_error']),'available'=>$fresh&&(int)$target['headless_mode']===1,'online'=>$fresh,'gestor_version'=>$target['gestor_version']]);
 }
 if($action==='unlock-session'){
  if(array_diff(array_keys($v),['network_id','gestor_id','_csrf']))throw new RuntimeException('Pedido de sessão inválido.');
  $network=(int)($v['network_id']??0);$gestor=(int)($v['gestor_id']??0);$access=arc_support_schedule_owner($pdo,$network,$user);
  $q=$pdo->prepare('SELECT headless_mode,gestor_version FROM '.arc_table('network_gestores')." WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$gestor,$network,$access['account_id']]);$target=$q->fetch();
  if(!$target||!(int)$target['headless_mode'])throw new RuntimeException('Ative sem monitor no computador aprovado para desbloquear remotamente.');
  $state=json_decode((string)@file_get_contents(ARC_PRIVATE_DIR.'/storage/remote-screen/gestor-'.$gestor.'.json'),true);if(!is_array($state)||time()-(int)($state['updated']??0)>15||($state['desktop_state']??'')!=='locked')throw new RuntimeException('O Windows não confirmou que o computador está bloqueado.');
  if(version_compare((string)$target['gestor_version'],'3.6.25','<'))throw new RuntimeException('Atualize o gestor para 3.6.25 antes de usar o desbloqueio.');
  $q=$pdo->prepare('SELECT c.* FROM '.arc_table('gestor_commands')." c WHERE c.gestor_id=? AND c.network_id=? AND c.account_id=? AND c.command_name='remote_assistance' AND c.status IN ('pending','running') AND c.created_at>DATE_SUB(NOW(),INTERVAL 30 MINUTE) ORDER BY c.id DESC LIMIT 1");$q->execute([$gestor,$network,$access['account_id']]);$existing=$q->fetch();
  if($existing&&!arc_support_unlock_allowed($pdo,$existing,$user))throw new RuntimeException('Encerre a sessão assistida anterior e abra um acesso administrativo sem monitor.');
  $id=$existing?(int)$existing['id']:arc_support_request_remote($pdo,$network,$gestor,$user);
  arc_json(['ok'=>true,'command_id'=>$id,'created'=>!$existing]);
 }
 if(str_starts_with($action,'schedule-'))arc_support_schedule_api($pdo,$action,$v,$user);
 if(($_GET['action']??'')==='launch'){
  $command=(int)($v['command_id']??0);
  if(!$command)$command=arc_support_request_remote($pdo,(int)($v['network_id']??0),(int)($v['gestor_id']??0),$user,(int)($v['ticket_id']??0));
  arc_json(['ok'=>true,'command_id'=>$command,'code'=>arc_support_issue_code($pdo,$command,$user),'expires_in'=>120]);
 }
 arc_json(['ok'=>false,'error'=>'Ação inválida'],404);
}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e instanceof PDOException?'Serviço indisponível.':$e->getMessage()],422);}catch(Throwable $e){arc_log_error('Suporte: '.$e->getMessage());arc_json(['ok'=>false,'error'=>'Suporte indisponível.'],503);}
