<?php
/* Caminho: tests/cloud_integration.php */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
if(!str_ends_with((string)(arc_config()['db_name']??''),'_cloud_test'))throw new RuntimeException('Use somente um banco descartável com sufixo _cloud_test.');
$pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);$checks=0;
function verify(bool $condition,string $name):void{global $checks;if(!$condition)throw new RuntimeException($name);$checks++;echo 'PASS ',$name,PHP_EOL;}
function rejects(callable $fn,string $name):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){verify(true,$name);return;}throw new LogicException('Não rejeitou: '.$name);}
$before=(int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('user_accounts'))->fetchColumn();
$pdo->exec(str_replace(['`arc_',"'arc_"],['`'.arc_prefix(),"'".arc_prefix()],file_get_contents(dirname(__DIR__).'/database/schema.sql')));
arc_cloud_ensure_schema($pdo);verify((int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('user_accounts'))->fetchColumn()===$before,'Esquema reaplicável preserva contas');
rejects(fn()=>arc_cloud_validate_settings(['folders'=>['C:\\']]),'Raiz de disco bloqueada');
rejects(fn()=>arc_cloud_validate_settings(['folders'=>['C:\\Windows\\System32']]),'Diretório de sistema bloqueado');
rejects(fn()=>arc_cloud_validate_settings(['folders'=>['\\\\server\\share']]),'Compartilhamento remoto bloqueado');
rejects(fn()=>arc_cloud_validate_settings(['folders'=>['C:\\Dados\\..\\Secret']]),'Traversal local bloqueado');
rejects(fn()=>arc_cloud_validate_settings(['mirror_deletes'=>true]),'Backup não espelha exclusões');
rejects(fn()=>arc_cloud_validate_url('http://example.com'),'TLS obrigatório');
rejects(fn()=>arc_cloud_validate_url('https://127.0.0.1'),'Loopback bloqueado');
rejects(fn()=>arc_cloud_validate_url('https://10.0.0.1'),'Rede privada bloqueada');
rejects(fn()=>arc_cloud_validate_url('https://user:pass@example.com'),'Credenciais em URL bloqueadas');
$settings=arc_cloud_validate_settings(['folders'=>[['path'=>'C:\\Dados','label'=>'Dados'],['path'=>'D:\\Dados','label'=>'Dados']]]);
verify($settings['folders'][0]['label']!==$settings['folders'][1]['label'],'Pastas com mesmo rótulo não colidem');
$job=['agent_id'=>1,'network_id'=>9,'settings'=>$settings];$a=arc_cloud_remote_segments($job,'PC',0,'sub/file.txt');$job['agent_id']=2;$b=arc_cloud_remote_segments($job,'PC',0,'sub/file.txt');
verify($a!==$b,'Computadores com mesmo nome não colidem');
verify(arc_cloud_remote_segments($job,'PC',0,'sub/name?.txt')===null,'Nome não é alterado silenciosamente');
verify(arc_cloud_remote_segments($job,'PC',0,'../private')===null,'Traversal remoto bloqueado');
$state=arc_cloud_state_sign(['u'=>1,'exp'=>time()+60]);verify(arc_cloud_state_verify($state)['u']===1,'Estado OAuth assinado válido');
verify(arc_cloud_state_verify($state.'x')===null,'Adulteração do estado OAuth bloqueada');
verify(arc_cloud_state_verify(arc_cloud_state_sign(['exp'=>time()-1]))===null,'Estado OAuth expirado bloqueado');
$secret='synthetic-'.bin2hex(random_bytes(16));$encrypted=arc_platform_encrypt($secret);
verify($secret!==$encrypted&&arc_platform_decrypt($encrypted)===$secret,'Credenciais protegidas com AES-GCM');
$clean=arc_cloud_sanitize_status(['state'=>'invented','files_total'=>-1,'failures'=>array_fill(0,40,['path'=>'synthetic','message'=>'failure'])]);
verify($clean['state']==='idle'&&$clean['files_total']===0&&count($clean['failures'])===12,'Relatórios limitados e higienizados');
echo $checks,' verificações de integração passaram.',PHP_EOL;
