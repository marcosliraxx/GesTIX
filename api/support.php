<?php
/* Caminho: api/support.php */
declare(strict_types=1);
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
 if(str_starts_with($action,'schedule-'))arc_support_schedule_api($pdo,$action,$v,$user);
 if(($_GET['action']??'')==='launch'){
  $command=(int)($v['command_id']??0);
  if(!$command)$command=arc_support_request_remote($pdo,(int)($v['network_id']??0),(int)($v['agent_id']??0),$user,(int)($v['ticket_id']??0));
  arc_json(['ok'=>true,'command_id'=>$command,'code'=>arc_support_issue_code($pdo,$command,$user),'expires_in'=>120]);
 }
 arc_json(['ok'=>false,'error'=>'Ação inválida'],404);
}catch(RuntimeException $e){arc_json(['ok'=>false,'error'=>$e instanceof PDOException?'Serviço indisponível.':$e->getMessage()],422);}catch(Throwable $e){error_log('Suporte: '.$e->getMessage());arc_json(['ok'=>false,'error'=>'Suporte indisponível.'],503);}
