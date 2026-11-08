<?php
/* Caminho: includes/backup-center.php */
/* Central de Backup: catálogo versionado separado do Clean. Tokens continuam no adaptador de nuvem existente. */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';

const ARC_BC_MAX_FILES=20000;
function arc_bc_q(PDO $pdo,string $sql,array $args=[]):PDOStatement{$q=$pdo->prepare($sql);$q->execute($args);return $q;}
function arc_bc_schema(PDO $pdo):void{
 static $ready;$ready??=new WeakMap();if(isset($ready[$pdo]))return;arc_cloud_ensure_schema($pdo);
 $tail=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
 $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('backup_center_plans')." (id CHAR(32) PRIMARY KEY,account_id BIGINT NOT NULL,network_id BIGINT NOT NULL,gestor_id BIGINT NOT NULL,name VARCHAR(120) NOT NULL,target VARCHAR(12) NOT NULL,connection_id BIGINT NULL,enabled TINYINT NOT NULL DEFAULT 0,requested TINYINT NOT NULL DEFAULT 0,settings_json TEXT NOT NULL,revision CHAR(64) NOT NULL,updated BIGINT NOT NULL,KEY ix_gestor(account_id,network_id,gestor_id))".$tail);
 $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('backup_center_runs')." (id CHAR(32) PRIMARY KEY,plan_id CHAR(32) NOT NULL,account_id BIGINT NOT NULL,network_id BIGINT NOT NULL,gestor_id BIGINT NOT NULL,kind VARCHAR(12) NOT NULL DEFAULT 'backup',runner VARCHAR(16) NOT NULL DEFAULT 'gestor',source_run CHAR(32) NULL,selection_json MEDIUMTEXT NULL,state VARCHAR(16) NOT NULL,attempt INT NOT NULL DEFAULT 0,lease_hash CHAR(64) NOT NULL DEFAULT '',heartbeat BIGINT NOT NULL DEFAULT 0,started BIGINT NOT NULL DEFAULT 0,ended BIGINT NOT NULL DEFAULT 0,plan_json TEXT NOT NULL,summary_json MEDIUMTEXT NULL,manifest_json MEDIUMTEXT NULL,manifest_hash CHAR(64) NOT NULL DEFAULT '',verified BIGINT NOT NULL DEFAULT 0,pinned TINYINT NOT NULL DEFAULT 0,KEY ix_gestor_state(account_id,network_id,gestor_id,state),KEY ix_plan_time(plan_id,started))".$tail);
 $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('backup_center_objects')." (plan_id CHAR(32) NOT NULL,store_key CHAR(64) NOT NULL,digest CHAR(64) NOT NULL,size BIGINT NOT NULL,md5 CHAR(32) NOT NULL DEFAULT '',confirmed BIGINT NOT NULL,PRIMARY KEY(plan_id,store_key,digest))".$tail);
 $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('backup_center_files')." (run_id CHAR(32) NOT NULL,path_hash CHAR(64) NOT NULL,folder_index INT NOT NULL,relative_path VARCHAR(2048) NOT NULL,digest CHAR(64) NOT NULL,size BIGINT NOT NULL,mtime BIGINT NOT NULL,PRIMARY KEY(run_id,path_hash),KEY ix_run(run_id))".$tail);
 $pdo->exec('CREATE TABLE IF NOT EXISTS '.arc_table('backup_center_workers')." (gestor_id BIGINT PRIMARY KEY,account_id BIGINT NOT NULL,network_id BIGINT NOT NULL,seen BIGINT NOT NULL,runner VARCHAR(16) NOT NULL,service_seen BIGINT NOT NULL DEFAULT 0,gestor_seen BIGINT NOT NULL DEFAULT 0)".$tail);
 $ready[$pdo]=true;
}
function arc_bc_id(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Identificador inválido.');return $id;}
function arc_bc_json(mixed $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function arc_bc_path(string $path):string{
 if($path===''||strlen($path)>2048||preg_match('/[\x00-\x1f\\\\:]/',$path)||str_starts_with($path,'/'))throw new InvalidArgumentException('Caminho relativo inválido.');
 foreach(explode('/',$path)as $part)if($part===''||$part==='.'||$part==='..'||preg_match('/[. ]$/D',$part)||preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$part)||preg_match('/[<>"|?*]/',$part))throw new InvalidArgumentException('Nome de arquivo não permitido.');
 return $path;
}
function arc_bc_settings(array $v):array{
 $folders=$v['folders']??[];if(!is_array($folders)||count($folders)<1||count($folders)>20)throw new InvalidArgumentException('Defina de 1 a 20 pastas de origem.');
 $roots=[];foreach($folders as $path){if(!is_string($path)||strlen($path)>1024||preg_match('/[\x00-\x1f]/',$path)||!preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)~',$path))throw new InvalidArgumentException('Use caminhos absolutos do Windows ou UNC.');$roots[]=rtrim(str_replace('/','\\',$path),'\\');}
 if(count(array_unique(array_map('mb_strtolower',$roots)))!==count($roots))throw new InvalidArgumentException('Pastas repetidas.');
 $schedule=(string)($v['schedule']??'manual');if(!in_array($schedule,['manual','interval','daily'],true))throw new InvalidArgumentException('Agendamento inválido.');
 $interval=filter_var($v['interval_minutes']??60,FILTER_VALIDATE_INT,['options'=>['min_range'=>15,'max_range'=>10080]]);$hour=(string)($v['daily_time']??'22:00');$tz=(string)($v['timezone']??'America/Belem');
 if($interval===false||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$hour)||!in_array($tz,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Intervalo, horário ou fuso inválido.');
 $exclusions=$v['exclusions']??[];if(!is_array($exclusions)||count($exclusions)>100)throw new InvalidArgumentException('Máximo de 100 exclusões.');foreach($exclusions as $pattern)if(!is_string($pattern)||strlen($pattern)>200||preg_match('/[\x00-\x1f]/',$pattern))throw new InvalidArgumentException('Exclusão inválida.');
 $destination=trim((string)($v['destination']??''));if(strlen($destination)>1024||preg_match('/[\x00-\x1f]/',$destination))throw new InvalidArgumentException('Destino inválido.');
 $max=filter_var($v['max_file_mb']??1024,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1048576]]);$band=filter_var($v['bandwidth_kbps']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1048576]]);$keep=filter_var($v['keep_last']??30,FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>1000]]);$rpo=filter_var($v['rpo_minutes']??1440,FILTER_VALIDATE_INT,['options'=>['min_range'=>15,'max_range'=>43200]]);
 if($max===false||$band===false||$keep===false||$rpo===false)throw new InvalidArgumentException('Limites do plano inválidos.');
 return ['folders'=>$roots,'destination'=>$destination,'schedule'=>$schedule,'interval_minutes'=>$interval,'daily_time'=>$hour,'timezone'=>$tz,'exclusions'=>array_values($exclusions),'max_file_mb'=>$max,'bandwidth_kbps'=>$band,'keep_last'=>$keep,'rpo_minutes'=>$rpo,'max_retries'=>max(0,min(5,(int)($v['max_retries']??3))),'retry_minutes'=>max(5,min(1440,(int)($v['retry_minutes']??15)))];
}
function arc_bc_public_plan(array $row):array{
 $settings=json_decode((string)$row['settings_json'],true,32,JSON_THROW_ON_ERROR);$plan=['id'=>$row['id'],'name'=>$row['name'],'target'=>$row['target'],'connection_id'=>$row['connection_id']===null?null:(int)$row['connection_id'],'enabled'=>(bool)$row['enabled'],'requested'=>(bool)$row['requested'],'settings'=>$settings,'revision'=>$row['revision'],'updated'=>(int)$row['updated'],'account_id'=>(int)$row['account_id'],'network_id'=>(int)$row['network_id'],'gestor_id'=>(int)$row['gestor_id']];$plan['store_key']=arc_bc_store_key($plan);return $plan;
}
function arc_bc_store_key(array $plan):string{return hash('sha256',arc_bc_json([$plan['target'],$plan['connection_id'],mb_strtolower($plan['settings']['destination'])]));}
function arc_bc_segments(array $plan,string $digest):array{if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new InvalidArgumentException('Hash inválido.');return [ARC_CLOUD_DEFAULT_ROOT,'Backup Center','Conta '.$plan['account_id'],'Rede '.$plan['network_id'],'Computador '.$plan['gestor_id'],'Plano '.$plan['id'],arc_bc_store_key($plan),'objects',substr($digest,0,2),$digest];}
function arc_bc_plan(PDO $pdo,string $id,array $scope):array{$row=arc_bc_q($pdo,'SELECT * FROM '.arc_table('backup_center_plans').' WHERE id=? AND account_id=? AND network_id=? AND gestor_id=?',[arc_bc_id($id),$scope['account'],$scope['network'],$scope['gestor']])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Plano não encontrado.',404);return $row;}
function arc_bc_manager(PDO $pdo,int $network,int $gestor):array{
 if(!arc_is_user() && !arc_is_admin())throw new RuntimeException('Entre novamente na plataforma.',401);if(!arc_is_admin() && arc_bc_q($pdo,'SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?',[(int)arc_user_id()])->fetchColumn()!=='active')throw new RuntimeException('Conta indisponível.',403);$access=arc_network_team_access($pdo,$network,(int)arc_user_id());
 if(!$access||!in_array($access['role'],['owner','administrator','system_admin'],true))throw new RuntimeException('Gestão de backup exige proprietário ou administrador.',403);
 $rights=arc_network_profile_rights($pdo,$network,(int)arc_user_id());if(!in_array($access['role'],['owner','system_admin'],true)&&$rights!==null&&!in_array('backup',$rights,true))throw new RuntimeException('Perfil sem permissão Backup.',403);
 $account=(int)$access['account_id'];$net=arc_bc_q($pdo,'SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1',[$network,$account])->fetchColumn();if(!$net)throw new RuntimeException('Rede indisponível.',403);arc_billing_assert_active($pdo,$account);arc_cloud_gestor_row($pdo,$gestor,$network,$account);return ['account'=>$account,'network'=>$network,'gestor'=>$gestor];
}
function arc_bc_due(array $plan,?array $last,int $now):bool{
 if(!$plan['enabled'])return false;if($plan['requested'])return true;$s=$plan['settings'];if($last&&in_array($last['state']??'',['failed','partial','interrupted'],true)&&(int)($last['attempt']??0)<$s['max_retries']&&$now-max((int)($last['ended']??0),(int)$last['started'])>=$s['retry_minutes']*60)return true;if($s['schedule']==='manual')return false;
 $started=(int)($last['started']??0);if($s['schedule']==='interval')return $now-$started>=$s['interval_minutes']*60;
 $tz=new DateTimeZone($s['timezone']);$current=(new DateTimeImmutable('@'.$now))->setTimezone($tz);$previous=$started?(new DateTimeImmutable('@'.$started))->setTimezone($tz):null;
 return $current->format('H:i')>=$s['daily_time']&&(!$previous||$previous->format('Y-m-d')<$current->format('Y-m-d'));
}
function arc_bc_connection(PDO $pdo,array $plan):array{
 $c=arc_cloud_connection_get($pdo,(int)$plan['connection_id'],$plan['network_id'],$plan['account_id']);if(!$c)throw new RuntimeException('Conexão de nuvem indisponível.',409);arc_cloud_assert_destination_allowed($pdo,$c);if(!arc_cloud_provider_config($pdo,$c['provider'])['ready'])throw new RuntimeException('Provedor não habilitado.',409);return $c;
}
function arc_bc_run(PDO $pdo,string $id,array $scope):array{$row=arc_bc_q($pdo,'SELECT * FROM '.arc_table('backup_center_runs').' WHERE id=? AND account_id=? AND network_id=? AND gestor_id=?',[arc_bc_id($id),$scope['account'],$scope['network'],$scope['gestor']])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Execução não encontrada.',404);return $row;}
function arc_bc_lease(PDO $pdo,string $id,string $lease,array $scope):array{
 $run=arc_bc_run($pdo,$id,$scope);if($run['state']!=='running'||(int)$run['heartbeat']<time()-180||!hash_equals($run['lease_hash'],hash('sha256',$lease)))throw new RuntimeException('Execução pausada, cancelada ou expirada.',409);
 if($run['kind']==='backup'){$plan=arc_bc_plan($pdo,$run['plan_id'],$scope);if(!(int)$plan['enabled']||!hash_equals($plan['revision'],json_decode($run['plan_json'],true)['revision']))throw new RuntimeException('Plano alterado ou pausado.',409);}
 arc_bc_q($pdo,'UPDATE '.arc_table('backup_center_runs').' SET heartbeat=? WHERE id=? AND state=?',[time(),$id,'running']);$field=$run['runner']==='service'?'service_seen':'gestor_seen';arc_bc_q($pdo,'UPDATE '.arc_table('backup_center_workers').' SET seen=?,'.$field.'=? WHERE gestor_id=? AND account_id=? AND network_id=?',[time(),time(),$scope['gestor'],$scope['account'],$scope['network']]);return $run;
}
function arc_bc_manifest(array $files):string{
 $rows=[];foreach($files as $file)$rows[]=['folder'=>(int)$file['folder_index'],'relative'=>$file['relative_path'],'digest'=>$file['digest'],'size'=>(int)$file['size'],'mtime'=>(int)$file['mtime']];usort($rows,static fn($a,$b)=>$a['folder']<=>$b['folder']?:strcmp($a['relative'],$b['relative']));return arc_bc_json(['schema'=>'tidigital.backup.v1','files'=>$rows]);
}
function arc_bc_state(PDO $pdo,array $scope):array{
 $args=[$scope['account'],$scope['network'],$scope['gestor']];$plans=array_map('arc_bc_public_plan',arc_bc_q($pdo,'SELECT * FROM '.arc_table('backup_center_plans').' WHERE account_id=? AND network_id=? AND gestor_id=? ORDER BY name,id',$args)->fetchAll(PDO::FETCH_ASSOC));
 $rows=arc_bc_q($pdo,'SELECT id,plan_id,kind,source_run,state,heartbeat,started,ended,summary_json,manifest_hash,verified,pinned FROM '.arc_table('backup_center_runs').' WHERE account_id=? AND network_id=? AND gestor_id=? ORDER BY started DESC,id DESC LIMIT 100',$args)->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as &$row){$row['summary']=json_decode((string)$row['summary_json'],true)?:[];unset($row['summary_json']);foreach(['heartbeat','started','ended','verified','pinned']as $field)$row[$field]=(int)$row[$field];}unset($row);
 $stats=arc_bc_q($pdo,"SELECT plan_id,MAX(CASE WHEN state='complete' AND kind='backup' THEN ended ELSE 0 END) AS last_success FROM ".arc_table('backup_center_runs').' WHERE account_id=? AND network_id=? AND gestor_id=? GROUP BY plan_id',$args)->fetchAll(PDO::FETCH_ASSOC);$last=[];foreach($stats as $stat)$last[$stat['plan_id']]=(int)$stat['last_success'];foreach($plans as &$plan)$plan['last_success']=$last[$plan['id']]??0;unset($plan);
 $connections=arc_cloud_connections_list($pdo,$scope['network'],$scope['account']);foreach($connections as &$c){if(!empty($c['managed'])){$c['identity']='';$c['quota_used']=null;$c['quota_total']=null;$c['label']='Nuvem gerenciada T.I. Digital';}}unset($c);
 $worker=arc_bc_q($pdo,'SELECT seen,runner,service_seen,gestor_seen FROM '.arc_table('backup_center_workers').' WHERE gestor_id=? AND account_id=? AND network_id=?',[$scope['gestor'],$scope['account'],$scope['network']])->fetch(PDO::FETCH_ASSOC)?:null;
 if($worker)$worker['runner']=(int)$worker['service_seen']>=time()-100?'service':'gestor';
 $legacy=arc_cloud_job_get($pdo,$scope['gestor'],$scope['network'],$scope['account']);
 return ['ok'=>true,'now'=>time(),'scope'=>$scope,'plans'=>$plans,'runs'=>$rows,'connections'=>$connections,'providers'=>arc_cloud_available_providers($pdo),'policy'=>arc_cloud_policy($pdo),'worker'=>$worker,'legacy'=>$legacy,'capabilities'=>['file_backup'=>true,'versioned'=>true,'sha256_restore'=>true,'vss'=>false,'image_backup'=>false,'immutable'=>false,'client_encryption'=>false,'catalog_retention_only'=>true,'max_files'=>ARC_BC_MAX_FILES]];
}
function arc_bc_queue(PDO $pdo,array $scope,array $body,int $user):array{
 $pdo->beginTransaction();try{arc_bc_q($pdo,'SELECT id FROM '.arc_table('backup_center_runs').' WHERE id=? AND account_id=? AND network_id=? AND gestor_id=? FOR UPDATE',[arc_bc_id((string)($body['source_run']??'')),$scope['account'],$scope['network'],$scope['gestor']])->fetchColumn();
 $source=arc_bc_run($pdo,(string)($body['source_run']??''),$scope);if($source['kind']!=='backup'||!in_array($source['state'],['complete','partial'],true)||!$source['manifest_json'])throw new RuntimeException('Ponto de recuperação indisponível.',409);
 $kind=(string)($body['kind']??'verify');if(!in_array($kind,['restore','verify'],true))throw new InvalidArgumentException('Operação inválida.');$selection=$body['selection']??[];if(!is_array($selection)||count($selection)>1000)throw new InvalidArgumentException('Seleção fora do limite.');foreach($selection as $hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Item selecionado inválido.');
 $pending=(int)arc_bc_q($pdo,'SELECT COUNT(*) FROM '.arc_table('backup_center_runs')." WHERE gestor_id=? AND account_id=? AND network_id=? AND state IN ('queued','running')",[$scope['gestor'],$scope['account'],$scope['network']])->fetchColumn();if($pending>=5)throw new RuntimeException('Há operações em andamento. Aguarde.',429);
 $id=bin2hex(random_bytes(16));arc_bc_q($pdo,'INSERT INTO '.arc_table('backup_center_runs')." (id,plan_id,account_id,network_id,gestor_id,kind,source_run,selection_json,state,started,plan_json,summary_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",[$id,$source['plan_id'],$scope['account'],$scope['network'],$scope['gestor'],$kind,$source['id'],arc_bc_json($selection),'queued',time(),$source['plan_json'],arc_bc_json(['requested_by'=>$user])]);$pdo->commit();return ['ok'=>true,'id'=>$id];
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_bc_confirm_object(PDO $pdo,array $plan,array $connection,string $digest,int $size,string $md5):void{
 if($connection['provider']==='gdrive'){
  $token=arc_cloud_access_token($pdo,$connection);$segments=arc_bc_segments($plan,$digest);$name=array_pop($segments);$parent=arc_cloud_gdrive_folder($pdo,$connection,$token,$segments,false);$id=$parent?arc_cloud_gdrive_find($token,$parent,$name,false):null;if(!$id)throw new RuntimeException('Objeto não encontrado após envio.',502);
  $reply=arc_cloud_json(arc_cloud_expect(arc_cloud_http('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=size,md5Checksum',arc_cloud_gdrive_headers($token),null,30),[200],'Verificação do objeto falhou'));
  if((int)($reply['size']??-1)!==$size||!hash_equals($md5,(string)($reply['md5Checksum']??'')))throw new RuntimeException('O destino não confirmou o tamanho/checksum enviado.',502);
 }
 arc_bc_q($pdo,'INSERT INTO '.arc_table('backup_center_objects').' VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE confirmed=VALUES(confirmed)',[$plan['id'],arc_bc_store_key($plan),$digest,$size,$md5,time()]);
}
function arc_bc_upload(PDO $pdo,array $scope,array $plan,string $runId,string $action,array $v,string $raw):never{
 if($plan['target']!=='cloud')throw new RuntimeException('Plano não usa nuvem.',409);$connection=arc_bc_connection($pdo,$plan);$chunk=$action==='gestor-chunk';$digest=(string)($chunk?($_SERVER['HTTP_X_BACKUP_DIGEST']??''):($v['digest']??''));$md5=(string)($chunk?($_SERVER['HTTP_X_BACKUP_MD5']??''):($v['md5']??''));
 if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||!preg_match('/^[a-f0-9]{32}$/D',$md5))throw new InvalidArgumentException('Checksum inválido.');
 $object=arc_bc_q($pdo,'SELECT size FROM '.arc_table('backup_center_objects').' WHERE plan_id=? AND store_key=? AND digest=?',[$plan['id'],arc_bc_store_key($plan),$digest])->fetchColumn();if($object!==false)arc_json(['ok'=>true,'done'=>true,'next_offset'=>(int)$object,'chunk'=>arc_cloud_providers()[$connection['provider']]['chunk']]);
 $uploadId=$chunk?(string)($_SERVER['HTTP_X_BACKUP_UPLOAD']??''):'';
 if(!$chunk){$size=filter_var($v['size']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>min(arc_cloud_max_file_bytes($pdo),$plan['settings']['max_file_mb']*1048576)]]);if($size===false)throw new InvalidArgumentException('Arquivo acima do limite do plano/provedor.');$uploadId=substr(hash_hmac('sha256','bc|'.$plan['id'].'|'.arc_bc_store_key($plan).'|'.$digest.'|'.$size,arc_cloud_state_key()),0,32);}
 if(!preg_match('/^[a-f0-9]{32}$/D',$uploadId))throw new InvalidArgumentException('Sessão inválida.');$lock='arc_bc_upload_'.$uploadId;if((int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lock).',10)')->fetchColumn()!==1)throw new RuntimeException('Objeto em transferência.',503);
 try{
  $upload=arc_bc_q($pdo,'SELECT * FROM '.arc_table('cloud_backup_uploads').' WHERE id=? AND gestor_id=? AND network_id=? AND account_id=? AND connection_id=?',[$uploadId,$scope['gestor'],$scope['network'],$scope['account'],$connection['id']])->fetch(PDO::FETCH_ASSOC);
  if($upload&&strtotime($upload['expires_at'])<time()){arc_cloud_abort($pdo,$connection,arc_cloud_state_read($upload));arc_bc_q($pdo,'DELETE FROM '.arc_table('cloud_backup_uploads').' WHERE id=?',[$uploadId]);$upload=null;}
  if($chunk){if(!$upload)throw new RuntimeException('Transferência expirou. Recomece o objeto.',409);$size=(int)$upload['total_size'];$expected=substr(hash_hmac('sha256','bc|'.$plan['id'].'|'.arc_bc_store_key($plan).'|'.$digest.'|'.$size,arc_cloud_state_key()),0,32);if(!hash_equals($expected,$uploadId))throw new RuntimeException('Sessão não pertence ao objeto/plano.',403);if(!hash_equals(hash('sha256',$raw),(string)($_SERVER['HTTP_X_BACKUP_SHA256']??'')))throw new InvalidArgumentException('Bloco corrompido.');$offset=filter_var($_SERVER['HTTP_X_BACKUP_OFFSET']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($offset===false)throw new InvalidArgumentException('Offset inválido.');$result=arc_cloud_upload_chunk($pdo,$upload,$connection,$raw,$offset);}
  elseif($upload)$result=['upload_id'=>$uploadId,'chunk'=>arc_cloud_providers()[$connection['provider']]['chunk'],'next_offset'=>(int)$upload['received'],'done'=>!empty(arc_cloud_state_read($upload)['done'])];
  else{$count=(int)arc_bc_q($pdo,'SELECT COUNT(*) FROM '.arc_table('cloud_backup_uploads').' WHERE gestor_id=? AND received<total_size AND expires_at>NOW()',[$scope['gestor']])->fetchColumn();if($count>=50)throw new RuntimeException('Há muitos envios incompletos; aguarde a manutenção das sessões.',429);$result=arc_cloud_upload_begin($pdo,$connection,$scope['gestor'],arc_bc_segments($plan,$digest),$size,time(),$uploadId,$scope['network'],$scope['account']);}
  if(!empty($result['done']))arc_bc_confirm_object($pdo,$plan,$connection,$digest,$size,$md5);
 }finally{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')');}arc_json(['ok'=>true]+$result);
}
function arc_bc_download(PDO $pdo,array $source,string $pathHash):never{
 if($source['kind']!=='backup'||!in_array($source['state'],['complete','partial'],true)||!preg_match('/^[a-f0-9]{64}$/D',$pathHash))throw new RuntimeException('Ponto ou arquivo indisponível.',409);
 $file=arc_bc_q($pdo,'SELECT digest,size,relative_path FROM '.arc_table('backup_center_files').' WHERE run_id=? AND path_hash=?',[$source['id'],$pathHash])->fetch(PDO::FETCH_ASSOC);if(!$file)throw new RuntimeException('Arquivo não pertence ao ponto.',404);$plan=json_decode($source['plan_json'],true,32,JSON_THROW_ON_ERROR);if($plan['target']!=='cloud')throw new RuntimeException('Backup local: solicite a restauração pelo executor do computador.',409);$connection=arc_bc_connection($pdo,$plan);arc_cloud_stream_file($pdo,$connection,arc_bc_segments($plan,$file['digest']),false,null,basename($file['relative_path']));exit;
}

/** Backup independe da coleta de métricas; mantém revogação, aprovação, rede ativa e conta ativa. */
function arc_bc_gestor_authenticate(PDO $pdo, array $body): array
{
    $fp=strtolower(trim((string)($body['fingerprint']??'')));
    $code=strtoupper(trim((string)($body['site_code']??'')));
    $key=trim((string)($body['download_key']??''));
    if (!preg_match('/^[a-f0-9]{64}$/D',$fp) || !preg_match('/^[A-Z0-9]{5}$/D',$code) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new RuntimeException('Gestor sem autorização.',403);
        $q=$pdo->prepare('SELECT a.id,a.network_id,a.account_id FROM '.arc_table('network_gestores').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id AND n.account_id=a.account_id JOIN '.arc_table('gestor_download_keys').' k ON k.network_id=a.network_id AND k.account_id=a.account_id WHERE a.fingerprint=? AND UPPER(a.site_code)=? AND a.status=\'approved\' AND n.is_active=1 AND k.token_hash=? AND k.fingerprint=? LIMIT 1');
        $q->execute([$fp,$code,hash('sha256',$key),$fp]);$gestor=$q->fetch();

    if (!$gestor) throw new RuntimeException('Gestor sem autorização.',403);
    $q=$pdo->prepare('SELECT account_status FROM '.arc_table('user_accounts').' WHERE id=?');
    $q->execute([(int)$gestor['account_id']]);
    if ($q->fetchColumn()!=='active') throw new RuntimeException('Conta sem autorização.',403);
    return $gestor;
}

/** Manifesto independente na nuvem antes de marcar o ponto como concluído. Conteúdo somente em memória. */
function arc_bc_cloud_manifest(PDO $pdo,array $plan,string $runId,string $canonical,string $digest):void{
 $connection=arc_bc_connection($pdo,$plan);$segments=arc_bc_segments($plan,$digest);$segments=array_slice($segments,0,-3);$segments[]='points';$segments[]=$runId.'.json';
 $payload=arc_bc_json(['schema'=>'tidigital.backup.point.v1','run_id'=>$runId,'plan'=>$plan,'canonical'=>$canonical,'digest'=>$digest]);$size=strlen($payload);$uploadId=substr(hash_hmac('sha256','bcmanifest|'.$runId.'|'.hash('sha256',$payload),arc_cloud_state_key()),0,32);$lock='arc_bc_manifest_'.$runId;
 if((int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lock).',5)')->fetchColumn()!==1)throw new RuntimeException('Manifesto em confirmação. Aguarde.',503);
 try{
  $get=static fn()=>arc_bc_q($pdo,'SELECT * FROM '.arc_table('cloud_backup_uploads').' WHERE id=? AND gestor_id=? AND network_id=? AND account_id=? AND connection_id=?',[$uploadId,$plan['gestor_id'],$plan['network_id'],$plan['account_id'],$connection['id']])->fetch(PDO::FETCH_ASSOC);
  $upload=$get();if($upload&&strtotime($upload['expires_at'])<time()){arc_cloud_abort($pdo,$connection,arc_cloud_state_read($upload));arc_bc_q($pdo,'DELETE FROM '.arc_table('cloud_backup_uploads').' WHERE id=?',[$uploadId]);$upload=null;}
  if(!$upload){arc_cloud_upload_begin($pdo,$connection,$plan['gestor_id'],$segments,$size,time(),$uploadId,$plan['network_id'],$plan['account_id']);$upload=$get();}
  if(!$upload)throw new RuntimeException('Manifesto não iniciou no destino.',502);$chunk=arc_cloud_providers()[$connection['provider']]['chunk'];$stalls=0;
  while(empty(arc_cloud_state_read($upload)['done'])){$offset=(int)$upload['received'];if($offset<0||$offset>=$size)throw new RuntimeException('Offset do manifesto inválido.',502);$result=arc_cloud_upload_chunk($pdo,$upload,$connection,substr($payload,$offset,$chunk),$offset);if((int)$result['next_offset']===$offset&&++$stalls>3)throw new RuntimeException('Manifesto não avançou.',502);if((int)$result['next_offset']!==$offset)$stalls=0;$upload=$get();if(!$upload)throw new RuntimeException('Sessão do manifesto expirou.',502);}
 }finally{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lock).')');}
}
