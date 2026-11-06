<?php
/* Caminho: api/remote-screen.php */
/** Arcoll: acesso remoto temporário e consentido ao computador do agente (tela + controle opcional). */
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/command-diagnostics.php';
/* A sessão só é lida; liberar o lock evita que imagem, status e entrada se bloqueiem entre si. */
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$pdo=arc_db();arc_ensure_user_schema($pdo);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$action=(string)($_GET['action']??'');
$storage=__DIR__.'/../includes/storage/remote-screen';
if(!is_dir($storage) && !@mkdir($storage,0700,true) && !is_dir($storage)) arc_json(['ok'=>false,'error'=>'Armazenamento indisponível'],503);
$cmd=(int)($_GET['command_id']??0);
if($cmd<1)arc_json(['ok'=>false,'error'=>'Sessão inválida'],422);
$q=$pdo->prepare('SELECT c.id,c.status,c.agent_id,c.account_id,c.network_id,c.command_name,c.created_at,c.result_text FROM '.arc_table('agent_commands').' c WHERE c.id=? AND c.command_name=\'remote_assistance\' LIMIT 1');
$q->execute([$cmd]);$command=$q->fetch(PDO::FETCH_ASSOC);
if(!$command)arc_json(['ok'=>false,'error'=>'Sessão inexistente'],404);
if(strtotime((string)$command['created_at'])<time()-1800){
 $pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='expired',completed_at=NOW() WHERE id=? AND status IN ('pending','running')")->execute([$cmd]);$command['status']='expired';
}
$file=$storage.'/screen-'.$cmd.'.jpg';
$stop=$storage.'/stop-'.$cmd;
$inputQueue=$storage.'/input-'.$cmd.'.jsonl';
$controlFlag=$storage.'/control-'.$cmd;
$aliveFile=$storage.'/alive-'.$cmd;
$metaFile=$storage.'/meta-'.$cmd.'.json';
$viewFile=$storage.'/view-'.$cmd.'.json';
$viewerFile=$storage.'/viewer-'.$cmd;
$unlockUsed=$storage.'/unlock-'.$cmd.'.json';
/* Instante do último sinal do agente: quadro novo ou keepalive (tela parada). O ETag só muda com quadro novo. */
$frameFreshness=static function() use($file,$aliveFile):int{clearstatcache(true,$file);clearstatcache(true,$aliveFile);return max(is_file($file)?(int)filemtime($file):0,is_file($aliveFile)?(int)filemtime($aliveFile):0);};
$frameEtag=static function() use($file):string{clearstatcache(true,$file);return '"'.dechex((int)filemtime($file)).'-'.dechex((int)filesize($file)).'-'.dechex((int)fileinode($file)).'"';};
/* O controle só vale enquanto o agente renovar o sinal (a cada requisição de quadro/consulta). */
$controlActive=static function() use($controlFlag):bool{clearstatcache(true,$controlFlag);return is_file($controlFlag)&&time()-(int)filemtime($controlFlag)<=8;};
$readJsonState=static function(string $path,array $fallback=[]):array{
 clearstatcache(true,$path);
 if(!is_file($path))return $fallback;
 $json=@file_get_contents($path);
 if(!is_string($json)||$json==='')return $fallback;
 $value=json_decode($json,true);
 return is_array($value)?$value:$fallback;
};
$writeJsonState=static function(string $path,array $payload):void{
 $tmp=tempnam(dirname($path),'arc');
 if($tmp===false)return;
 @chmod($tmp,0600);
 file_put_contents($tmp,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
 @rename($tmp,$path);
};
$sanitizeMonitors=static function($items):array{
 if(!is_array($items))return [];
 $clean=[];
 foreach(array_slice($items,0,8) as $item){
  if(!is_array($item))continue;
  $id=trim((string)($item['id']??''));
  if($id==='')continue;
  $label=trim((string)($item['label']??('Tela '.(count($clean)+1))));
  $width=max(1,min(100000,(int)($item['width']??0)));
  $height=max(1,min(100000,(int)($item['height']??0)));
  $primary=!empty($item['primary']);
  $clean[]=['id'=>$id,'label'=>(function_exists('mb_substr')?mb_substr($label,0,50):substr($label,0,50)),'width'=>$width,'height'=>$height,'primary'=>$primary];
 }
 return $clean;
};
$resolveSelectedMonitor=static function(array $monitors,string $wanted=''):string{
 if(!$monitors)return '';
 foreach($monitors as $monitor){if((string)($monitor['id']??'')!=='' && (string)$monitor['id']===$wanted)return (string)$monitor['id'];}
 foreach($monitors as $monitor){if(!empty($monitor['primary']) && (string)($monitor['id']??'')!=='')return (string)$monitor['id'];}
 return (string)($monitors[0]['id']??'');
};
$cleanup=static function() use($file,$aliveFile,$inputQueue,$controlFlag,$metaFile,$viewFile,$viewerFile,$unlockUsed):void{foreach([$file,$aliveFile,$inputQueue,$controlFlag,$metaFile,$viewFile,$viewerFile,$unlockUsed] as $f){if(is_file($f))@unlink($f);}};

/** Autentica o agente (mesmas regras dos quadros) e devolve o corpo JSON. */
$authAgent=static function(int $maxBytes) use($pdo,$command,$viewerFile,$stop):array{
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $raw=file_get_contents('php://input',false,null,0,$maxBytes+10);
 if(!is_string($raw)||strlen($raw)>$maxBytes)arc_json(['ok'=>false,'error'=>'Requisição muito grande'],413);
 $body=json_decode($raw,true);
 if(!is_array($body))arc_json(['ok'=>false,'error'=>'Dados inválidos'],400);
 $fp=strtolower((string)($body['fingerprint']??''));$code=strtoupper((string)($body['site_code']??''));$key=(string)($body['download_key']??'');
 if(!preg_match('/^[a-f0-9]{64}$/D',$fp)||!preg_match('/^[A-Z0-9]{5}$/D',$code)||!preg_match('/^[a-f0-9]{64}$/D',$key))arc_json(['ok'=>false,'error'=>'Credenciais inválidas'],403);
 $q=$pdo->prepare('SELECT a.id FROM '.arc_table('network_agents').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id JOIN '.arc_table('agent_download_keys').' k ON k.account_id=a.account_id AND k.network_id=a.network_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND n.monitoring_enabled=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
 $q->execute([(int)$command['agent_id'],(int)$command['network_id'],(int)$command['account_id'],$fp,$code,hash('sha256',$key),$fp]);
 if(!$q->fetchColumn())arc_json(['ok'=>false,'error'=>'Agente não autorizado'],403);
 $q=$pdo->prepare('SELECT technician_id FROM '.arc_table('support_remote_sessions').' WHERE command_id=?');$q->execute([$command['id']]);$technician=(int)$q->fetchColumn();
 if($technician&&!arc_support_remote_allowed($pdo,$command,$technician))arc_json(['ok'=>false,'stop'=>true,'error'=>'Autorização do técnico revogada'],403);
 clearstatcache(true,$viewerFile);
 if(strtotime((string)$command['created_at'])<time()-1800){
  file_put_contents($stop,'1',LOCK_EX);$pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='expired',completed_at=NOW() WHERE id=? AND status IN ('pending','running')")->execute([$command['id']]);
  arc_json(['ok'=>false,'stop'=>true,'error'=>'Visualizador desconectado'],409);
 }

 return $body;
};
/** Atualiza (ou remove) o sinal de que o usuário local liberou o controle de mouse/teclado. */
$syncControl=static function(array $body) use($controlFlag,$inputQueue):void{
 if(!empty($body['control'])){
  /* Controle recém-liberado: descarta eventos antigos que ficaram na fila enquanto estava pausado. */
  if(!is_file($controlFlag)&&is_file($inputQueue))@file_put_contents($inputQueue,'',LOCK_EX);
  @file_put_contents($controlFlag,(string)time(),LOCK_EX);@touch($controlFlag);
 }
 else{
  if(is_file($controlFlag))@unlink($controlFlag);
  if(is_file($inputQueue))@unlink($inputQueue);
 }
};

if($action==='unlock-authorize'){
 $body=$authAgent(2048);$q=$pdo->prepare('SELECT technician_id FROM '.arc_table('support_remote_sessions').' WHERE command_id=?');$q->execute([$cmd]);$user=(int)$q->fetchColumn();
 $used=$readJsonState($unlockUsed);
 if($command['status']!=='running'||is_file($stop)||!$controlActive()||!arc_support_unlock_allowed($pdo,$command,$user)||($used['request_id']??null)!==($body['request_id']??null)||($used['key_id']??null)!==($body['key_id']??null)||time()>(int)($used['expires']??0))arc_json(['ok'=>false,'error'=>'Desbloqueio revogado ou expirado.'],403);
 arc_json(['ok'=>true]);
}
if($action==='frame'){
 $body=$authAgent(650000);
 if($command['status']!=='running'||is_file($stop))arc_json(['ok'=>false,'stop'=>true,'error'=>'Sessão encerrada'],409);
 $syncControl($body);
 /* Persiste monitores recebidos, inclusive quando a tela permanece inalterada. */
  $oldMeta=$readJsonState($metaFile,[]);
  if(array_key_exists('monitors',$body)){
   $reported=$sanitizeMonitors($body['monitors']);
   if($reported){
    $oldMeta=array_merge($oldMeta,['monitors'=>$reported,'selected_monitor'=>$resolveSelectedMonitor($reported,(string)($body['selected_monitor']??'')),'updated_at'=>time()]);
    $writeJsonState($metaFile,$oldMeta);
   }
  }
  if(isset($body['desktop_state'])&&in_array($body['desktop_state'],['locked','unlocked'],true)){
   $oldMeta['desktop_state']=$body['desktop_state'];if($body['desktop_state']==='unlocked'||array_key_exists('unlock_key',$body))$oldMeta['unlock_key']=null;
   $key=$body['unlock_key']??null;
   if($body['desktop_state']==='locked'&&is_array($key)&&preg_match('/^[a-f0-9]{32}$/D',(string)($key['id']??''))&&preg_match('/^[A-Za-z0-9_-]{342}$/D',(string)($key['jwk']['n']??''))&&($key['jwk']['e']??'')==='AQAB'&&is_numeric($key['expires']??null)){
    $oldMeta['unlock_key']=['id'=>$key['id'],'expires'=>min(time()+90,(int)$key['expires']),'jwk'=>['kty'=>'RSA','n'=>$key['jwk']['n'],'e'=>'AQAB','alg'=>'RSA-OAEP-256','ext'=>true]];
   }
   if(array_key_exists('windows_users',$body)){
    $oldMeta['windows_users']=[];
    foreach(array_slice(is_array($body['windows_users'])?$body['windows_users']:[],0,100) as $entry){
     if(!is_array($entry))continue;$username=(string)($entry['username']??'');$label=(string)($entry['label']??'');
     if($username===''||$label===''||strlen($username)>768||strlen($label)>768||preg_match('/[\x00-\x1f]/',$username.$label))continue;
     $oldMeta['windows_users'][]=['username'=>$username,'label'=>$label];
    }
    $oldMeta['windows_users_error']=!empty($body['windows_users_error']);
   }
   $result=$body['unlock_result']??[];if(array_key_exists('unlock_result',$body))$oldMeta['unlock_result']=[];
   if(is_array($result)&&in_array($result['status']??'', ['pending','unlocked','failed','unavailable'],true))$oldMeta['unlock_result']=['status'=>$result['status'],'request_id'=>preg_match('/^[a-f0-9]{32}$/D',(string)($result['request_id']??''))?$result['request_id']:null];
   $writeJsonState($metaFile,$oldMeta);
  }
  $knownMonitors=is_array($oldMeta['monitors']??null)?$oldMeta['monitors']:[];
  $selected=$resolveSelectedMonitor($knownMonitors,(string)($readJsonState($viewFile,[])['selected_monitor']??($body['selected_monitor']??'')));
  if(!empty($body['keepalive'])&&is_file($file)){@touch($aliveFile);arc_json(['ok'=>true,'stop'=>false,'selected_monitor'=>$selected,'quality'=>$readJsonState($viewFile)['quality']??'balanced','cursor_overlay'=>!empty($readJsonState($viewFile)['cursor_overlay'])]);}
 $image=base64_decode((string)($body['image']??''),true);
 if(!is_string($image)||strlen($image)<100||strlen($image)>390000||substr($image,0,3)!=="\xff\xd8\xff")arc_json(['ok'=>false,'error'=>'JPEG inválido'],422);
 $tmp=tempnam($storage,'frame');if($tmp===false)arc_json(['ok'=>false,'error'=>'Armazenamento indisponível'],503);
 @chmod($tmp,0600);file_put_contents($tmp,$image,LOCK_EX);rename($tmp,$file);
 arc_json(['ok'=>true,'stop'=>false,'selected_monitor'=>$selected,'quality'=>$readJsonState($viewFile)['quality']??'balanced','cursor_overlay'=>!empty($readJsonState($viewFile)['cursor_overlay'])]);
}
if($action==='poll'){
 /* O agente busca os eventos de mouse/teclado enfileirados pelo gestor (long-poll curto). */
 $body=$authAgent(2000);
 if($command['status']!=='running'||is_file($stop))arc_json(['ok'=>false,'stop'=>true,'events'=>[],'error'=>'Sessão encerrada'],409);
 $syncControl($body);
 $events=[];
 if(!empty($body['control'])){
  $deadline=microtime(true)+1.5;
  do{
   clearstatcache(true,$inputQueue);
   if(is_file($inputQueue)&&filesize($inputQueue)>0){
    $fh=@fopen($inputQueue,'c+');
    if($fh){
     if(flock($fh,LOCK_EX)){
      $data=(string)stream_get_contents($fh);ftruncate($fh,0);flock($fh,LOCK_UN);
      foreach(preg_split('/\R/',$data,-1,PREG_SPLIT_NO_EMPTY)?:[] as $line){$ev=json_decode($line,true);if(is_array($ev))$events[]=$ev;}
     }
     fclose($fh);
    }
    if($events)break;
   }
   if(is_file($stop)||connection_aborted())break;
   usleep(20000);
  }while(microtime(true)<$deadline);
 }
 $meta=$readJsonState($metaFile,[]);
 $monitors=is_array($meta['monitors']??null)?$meta['monitors']:[];
 $selectedMonitor=$resolveSelectedMonitor($monitors,(string)($readJsonState($viewFile,[])['selected_monitor']??($meta['selected_monitor']??'')));
 arc_json(['ok'=>true,'stop'=>false,'events'=>array_slice($events,0,400),'selected_monitor'=>$selectedMonitor,'monitors'=>$monitors]);
}
$nativeUser=arc_support_native_user($pdo,$cmd);
$viewerUser=$nativeUser ?: (int)(arc_user_id()??0);
if(!$viewerUser||!arc_support_remote_allowed($pdo,$command,$viewerUser))arc_json(['ok'=>false,'error'=>'Acesso negado'],403);
$q=$pdo->prepare("SELECT a.id FROM ".arc_table('network_agents')." a JOIN ".arc_table('managed_networks')." n ON n.id=a.network_id AND n.account_id=a.account_id WHERE a.id=? AND a.network_id=? AND a.account_id=? AND a.status='approved' AND n.is_active=1 AND n.monitoring_enabled=1");
$q->execute([$command['agent_id'],$command['network_id'],$command['account_id']]);if(!$q->fetchColumn())arc_json(['ok'=>false,'error'=>'Equipamento revogado'],403);
if(in_array($action,['image','status','input','select-monitor','preferences'],true)){@touch($viewerFile);@chmod($viewerFile,0600);}
$viewerCsrf=static function() use($nativeUser):void{if(!$nativeUser)arc_verify_csrf();};
if($action==='preferences'){
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $viewerCsrf();$raw=file_get_contents('php://input',false,null,0,4096);$v=json_decode((string)$raw,true);
 $quality=(string)($v['quality']??'balanced');if(!in_array($quality,['low','balanced','high'],true))arc_json(['ok'=>false,'error'=>'Qualidade inválida'],422);
 $state=$readJsonState($viewFile);$state['quality']=$quality;$state['cursor_overlay']=($v['cursor_overlay']??false)===true;$writeJsonState($viewFile,$state);arc_json(['ok'=>true]);
}
if($action==='stop'){
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $viewerCsrf();file_put_contents($stop,'1',LOCK_EX);$cleanup();
 $pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='completed',completed_at=NOW() WHERE id=? AND status IN ('pending','running')")->execute([$cmd]);
 $pdo->prepare('DELETE FROM '.arc_table('support_remote_tokens').' WHERE command_id=?')->execute([$cmd]);
 arc_json(['ok'=>true]);
}
if($action==='unlock-submit'){
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $viewerCsrf();
 if($command['status']!=='running'||is_file($stop)||!$controlActive()||!arc_support_unlock_allowed($pdo,$command,$viewerUser))arc_json(['ok'=>false,'error'=>'Desbloqueio exige administrador/proprietário e sem monitor ativo.'],403);
 $raw=file_get_contents('php://input',false,null,0,16001);if(!is_string($raw)||strlen($raw)>16000)arc_json(['ok'=>false,'error'=>'Pedido inválido.'],413);
 $v=json_decode($raw,true);$meta=$readJsonState($metaFile);$key=$meta['unlock_key']??null;
 if(!is_array($v)||array_diff(array_keys($v),['key_id','blocks','_csrf'])||!is_array($key)||($v['key_id']??'')!==$key['id']||time()>=(int)$key['expires'])arc_json(['ok'=>false,'error'=>'Chave expirada. Solicite novamente.'],409);
 $blocks=$v['blocks']??null;if(!is_array($blocks)||count($blocks)<1||count($blocks)>32)arc_json(['ok'=>false,'error'=>'Pedido inválido.'],422);
 foreach($blocks as $block)if(!is_string($block)||strlen($block)!==344||strlen((string)base64_decode($block,true))!==256)arc_json(['ok'=>false,'error'=>'Pedido cifrado inválido.'],422);
 $fh=fopen($unlockUsed,'c+');if(!$fh||!flock($fh,LOCK_EX))arc_json(['ok'=>false,'error'=>'Serviço indisponível.'],503);
 $used=json_decode((string)stream_get_contents($fh),true)??[];
 if(($used['key_id']??'')===$v['key_id']||time()-(int)($used['created_at']??0)<15){flock($fh,LOCK_UN);fclose($fh);arc_json(['ok'=>false,'error'=>'Aguarde o pedido anterior.'],429);}
 $request=bin2hex(random_bytes(16));$expires=time()+20;$record=['key_id'=>$v['key_id'],'request_id'=>$request,'created_at'=>time(),'expires'=>$expires];rewind($fh);ftruncate($fh,0);fwrite($fh,json_encode($record));fflush($fh);flock($fh,LOCK_UN);fclose($fh);chmod($unlockUsed,0600);
 $event=['t'=>'unlock','key_id'=>$v['key_id'],'request_id'=>$request,'expires'=>$expires,'blocks'=>$blocks];
 clearstatcache(true,$inputQueue);if(is_file($inputQueue)&&filesize($inputQueue)>200000)arc_json(['ok'=>false,'error'=>'Fila ocupada. Aguarde.'],429);
 $line=json_encode($event)."\n";if(file_put_contents($inputQueue,$line,FILE_APPEND|LOCK_EX)!==strlen($line))arc_json(['ok'=>false,'error'=>'Não foi possível enviar o pedido ao componente seguro.'],503);chmod($inputQueue,0600);
 arc_user_audit($pdo,'remote_unlock_requested','Solicitado desbloqueio do agente #'.$command['agent_id'],(int)$command['account_id']);
 arc_json(['ok'=>true,'request_id'=>$request]);
}
if($action==='input'){
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $viewerCsrf();
 if($command['status']!=='running'||is_file($stop))arc_json(['ok'=>false,'error'=>'Sessão encerrada'],409);
 if(!$controlActive())arc_json(['ok'=>false,'error'=>'O controle não foi autorizado no computador remoto'],409);
 $raw=file_get_contents('php://input',false,null,0,60001);
 if(!is_string($raw)||strlen($raw)>60000)arc_json(['ok'=>false,'error'=>'Requisição muito grande'],413);
 $body=json_decode($raw,true);
 if(!is_array($body)||!is_array($body['events']??null))arc_json(['ok'=>false,'error'=>'Eventos inválidos'],400);
 $combos=['win','win_r','win_d','win_e','alt_tab','ctrl_alt_del','ctrl_shift_esc','alt_f4','ctrl_c','ctrl_v','ctrl_a','ctrl_z','f5','esc'];
 $clean=[];
 foreach(array_slice($body['events'],0,400) as $ev){
  if(!is_array($ev))continue;
  $t=(string)($ev['t']??'');
  $nx=max(0.0,min(1.0,(float)($ev['x']??0)));$ny=max(0.0,min(1.0,(float)($ev['y']??0)));
  if($t==='m'){$clean[]=['t'=>'m','x'=>round($nx,5),'y'=>round($ny,5)];}
  elseif($t==='d'||$t==='u'){$clean[]=['t'=>$t,'x'=>round($nx,5),'y'=>round($ny,5),'b'=>max(0,min(2,(int)($ev['b']??0)))];}
  elseif($t==='w'){$clean[]=['t'=>'w','dx'=>max(-20,min(20,(int)($ev['dx']??0))),'dy'=>max(-20,min(20,(int)($ev['dy']??0)))];}
  elseif($t==='k'){$c=(string)($ev['c']??'');if(preg_match('/^[A-Za-z0-9]{1,20}$/D',$c))$clean[]=['t'=>'k','d'=>empty($ev['d'])?0:1,'c'=>$c];}
  elseif($t==='x'){$s=(string)($ev['s']??'');if($s!==''&&mb_strlen($s)<=400)$clean[]=['t'=>'x','s'=>$s];}
  elseif($t==='c'){$n=(string)($ev['n']??'');if(in_array($n,$combos,true))$clean[]=['t'=>'c','n'=>$n];}
 }
 if(!$clean)arc_json(['ok'=>true,'queued'=>0]);
 clearstatcache(true,$inputQueue);
 if(is_file($inputQueue)&&filesize($inputQueue)>250000)arc_json(['ok'=>false,'error'=>'Fila de entrada cheia'],429);
 $lines='';foreach($clean as $ev)$lines.=json_encode($ev,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
 $fh=@fopen($inputQueue,'ab');
 if(!$fh)arc_json(['ok'=>false,'error'=>'Armazenamento indisponível'],503);
 if(flock($fh,LOCK_EX)){fwrite($fh,$lines);fflush($fh);flock($fh,LOCK_UN);}
 fclose($fh);@chmod($inputQueue,0600);
 arc_json(['ok'=>true,'queued'=>count($clean)]);
}
if($action==='select-monitor'){
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
 $viewerCsrf();
 $raw=file_get_contents('php://input',false,null,0,8000);
 $body=json_decode(is_string($raw)?$raw:'',true);
 if(!is_array($body))arc_json(['ok'=>false,'error'=>'Dados inválidos'],400);
 $meta=$readJsonState($metaFile,[]);
 $monitors=is_array($meta['monitors']??null)?$meta['monitors']:[];
 $selectedMonitor=$resolveSelectedMonitor($monitors,(string)($body['monitor_id']??''));
 if($selectedMonitor==='')arc_json(['ok'=>false,'error'=>'Nenhuma tela disponível no agente remoto.'],422);
 $state=$readJsonState($viewFile);$state['selected_monitor']=$selectedMonitor;$state['updated_at']=time();$writeJsonState($viewFile,$state);
 arc_json(['ok'=>true,'selected_monitor'=>$selectedMonitor,'monitor_count'=>count($monitors)]);
}
if($action==='status'){
 $ended=in_array($command['status'],['completed','failed','expired'],true);
 if($ended)$cleanup();
 $meta=$readJsonState($metaFile,[]);
 $monitors=is_array($meta['monitors']??null)?$meta['monitors']:[];
 $selectedMonitor=$resolveSelectedMonitor($monitors,(string)($readJsonState($viewFile,[])['selected_monitor']??($meta['selected_monitor']??'')));
 $mtime=$frameFreshness();$unlockAllowed=arc_support_unlock_allowed($pdo,$command,$viewerUser);
 arc_json(['ok'=>true,'status'=>$command['status'],'state_message'=>arc_command_error_scrub((string)($command['result_text']??''),500),'elapsed_seconds'=>max(0,time()-strtotime((string)$command['created_at'])),'stopped'=>is_file($stop),'has_frame'=>$mtime>0&&time()-$mtime<8,'control'=>!$ended&&!is_file($stop)&&$controlActive(),'monitors'=>$monitors,'selected_monitor'=>$selectedMonitor,'monitor_count'=>count($monitors),'desktop_state'=>$meta['desktop_state']??null,'unlock_key'=>$unlockAllowed?($meta['unlock_key']??null):null,'windows_users'=>$unlockAllowed?($meta['windows_users']??[]):[],'windows_users_error'=>$unlockAllowed&&!empty($meta['windows_users_error']),'unlock_result'=>$meta['unlock_result']??null]);
}
if($action==='image'){
 if($command['status']!=='running'||is_file($stop)){http_response_code(404);exit;}
 /* Espera curta por um quadro mais novo que o do navegador: uma requisição em vez de dezenas por segundo. */
 $known=(string)($_SERVER['HTTP_IF_NONE_MATCH']??'');$deadline=microtime(true)+1.5;
 do{
  $fresh=$frameFreshness();
  if(is_file($file)&&time()-$fresh<=8){
   $etag=$frameEtag();
   if($etag!==$known){header('ETag: '.$etag);header('Content-Type: image/jpeg');readfile($file);exit;}
  }
  if(is_file($stop)||connection_aborted())break;
  usleep(30000);
 }while(microtime(true)<$deadline);
 http_response_code($known!==''?304:404);exit;
}
arc_json(['ok'=>false,'error'=>'Ação inválida'],404);
