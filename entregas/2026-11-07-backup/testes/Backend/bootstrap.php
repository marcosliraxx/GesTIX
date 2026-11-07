<?php
declare(strict_types=1);
// Fixture exclusiva de testes. Não distribuir sob includes/ em produção.
const ARC_CLOUD_DEFAULT_ROOT='TI Digital';
function arc_table(string $name):string{return '`t_'.$name.'`';}
function arc_db():PDO{static $p;return $p??=new PDO(getenv('BC_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=bc_test;charset=utf8mb4','root','root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function arc_ensure_user_schema(PDO $p):void{}
function arc_cloud_ensure_schema(PDO $p):void{$p->exec('CREATE TABLE IF NOT EXISTS t_cloud_backup_uploads(id CHAR(32) PRIMARY KEY,agent_id BIGINT,network_id BIGINT,account_id BIGINT,connection_id BIGINT,total_size BIGINT,received BIGINT,state_enc MEDIUMTEXT,expires_at DATETIME);CREATE TABLE IF NOT EXISTS t_provider_test_objects(path VARCHAR(700) PRIMARY KEY,content MEDIUMBLOB)');}
function arc_is_user():bool{return ($_SERVER['HTTP_X_TEST_USER']??'')==='valid';}
function arc_user_id():int{return (int)($_SERVER['HTTP_X_TEST_USER_ID']??1);}
function arc_csrf_token():string{return 'test-csrf';}
function arc_network_team_access(PDO $p,int $n,int $u):?array{return $n===$u*10?['role'=>$_SERVER['HTTP_X_TEST_ROLE']??'owner','account_id'=>$u]:null;}
function arc_network_profile_rights(PDO $p,int $n,int $u):?array{return isset($_SERVER['HTTP_X_TEST_RIGHTS'])?explode(',',$_SERVER['HTTP_X_TEST_RIGHTS']):null;}
function arc_billing_assert_active(PDO $p,int $a):void{}
function arc_cloud_agent_row(PDO $p,int $id,int $n,int $a):array{$q=$p->prepare("SELECT * FROM t_network_agents WHERE id=? AND network_id=? AND account_id=? AND status='approved'");$q->execute([$id,$n,$a]);return $q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Agente fora da conta/rede.',403);}
function arc_agent_authenticate(PDO $p,array $b):array{if(($b['fingerprint']??'')!=='test-fp'||($b['site_code']??'')!=='TEST1'||($b['download_key']??'')!=='test-key')throw new RuntimeException('Credencial revogada/inválida.',401);return arc_cloud_agent_row($p,100,10,1);}
function arc_cloud_connections_list(PDO $p,int $n,int $a):array{return [];}
function arc_cloud_available_providers(PDO $p):array{return [];}
function arc_cloud_policy(PDO $p):array{return ['own'=>false,'platform'=>true];}
function arc_cloud_job_get(PDO $p,int $id,int $n,int $a):array{return ['settings'=>['vdrive'=>false],'enabled'=>false,'connection_id'=>null];}
function arc_user_audit(PDO $p,string $a,string $b):void{}
function arc_cloud_max_file_bytes(PDO $p):int{return 104857600;}
function arc_cloud_state_sign(array $p):string{return base64_encode(json_encode($p));}
function arc_cloud_state_verify(string $v):?array{$p=json_decode(base64_decode($v),true);return is_array($p)&&($p['exp']??0)>time()?$p:null;}
function arc_json(array $v,int $status=200):never{http_response_code($status);header('Content-Type: application/json');echo json_encode($v);exit;}
function arc_cloud_connection_get(PDO $p,int $id,int $n,int $a):?array{return $id===1?['id'=>1,'provider'=>'s3','network_id'=>0,'account_id'=>0,'status'=>'active']:null;}
function arc_cloud_assert_destination_allowed(PDO $p,array $c):void{}
function arc_cloud_provider_config(PDO $p,string $provider):array{return ['ready'=>true];}
function arc_cloud_state_key():string{return 'synthetic-key-only';}
function arc_cloud_providers():array{return ['s3'=>['chunk'=>1024]];}
function arc_cloud_state_read(array $row):array{return json_decode($row['state_enc'],true)?:[];}
function arc_cloud_abort(PDO $p,array $c,array $state):void{}
function arc_cloud_upload_begin(PDO $p,array $c,int $a,array $segments,int $size,int $mtime,?string $id=null,?int $n=null,?int $account=null):array{$id??=bin2hex(random_bytes(16));$q=$p->prepare('INSERT INTO t_cloud_backup_uploads VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$id,$a,$n,$account,$c['id'],$size,0,json_encode(['done'=>$size===0,'segments'=>$segments,'data'=>'']),date('Y-m-d H:i:s',time()+86400)]);return ['upload_id'=>$id,'chunk'=>1024,'done'=>$size===0,'next_offset'=>0];}
function arc_cloud_upload_chunk(PDO $p,array $u,array $c,string $raw,int $offset):array{$state=arc_cloud_state_read($u);if($offset!==(int)$u['received'])return ['next_offset'=>(int)$u['received'],'done'=>false];$data=base64_decode($state['data']).$raw;$next=strlen($data);$state['data']=base64_encode($data);$done=$next===(int)$u['total_size'];$state['done']=$done;$p->prepare('UPDATE t_cloud_backup_uploads SET received=?,state_enc=? WHERE id=?')->execute([$next,json_encode($state),$u['id']]);if($done)$p->prepare('INSERT INTO t_provider_test_objects VALUES(?,?) ON DUPLICATE KEY UPDATE content=VALUES(content)')->execute([implode('/',$state['segments']),$data]);return ['next_offset'=>$next,'done'=>$done];}
function arc_cloud_stream_file(PDO $p,array $c,array $segments,bool $inline,?string $range=null,?string $name=null):void{$q=$p->prepare('SELECT content FROM t_provider_test_objects WHERE path=?');$q->execute([implode('/',$segments)]);$content=$q->fetchColumn();if($content===false)throw new RuntimeException('Objeto ausente.',404);header('Content-Type: application/octet-stream');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($name??end($segments)));echo $content;}

function arc_workspace_base_url():string{return 'http://127.0.0.1:8098/';}
