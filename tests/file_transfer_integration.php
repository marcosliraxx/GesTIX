<?php
/* Caminho: tests/file_transfer_integration.php */
declare(strict_types=1);
$root=getenv('GESTI_TEST_ROOT');$fixture=getenv('GESTI_TEST_FIXTURE_PATH');if(!$root||!$fixture)throw new RuntimeException('Isolated test paths required.');
require $root.'/includes/bootstrap.php';$pdo=arc_db();if(!str_ends_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'_services_test'))throw new RuntimeException('Synthetic database required.');arc_ensure_user_schema($pdo);
$f=json_decode(file_get_contents($fixture),true);$checks=0;
function fileCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function fileReject(callable $fn,string $message):void{try{$fn();}catch(RuntimeException $e){fileCheck(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$owner=(int)$f['owner'];$operator=(int)$f['operator'];$agent=(int)$f['agent'];$network=(int)$f['network'];
$pdo->prepare('UPDATE '.arc_table('network_agents').' SET agent_version=?,last_seen_at=NOW() WHERE id=?')->execute([ARC_APP_VERSION,$agent]);
fileReject(fn()=>arc_support_file_create($pdo,$network,$agent,(int)$f['viewer'],'from_agent'),'Viewer cannot create transfer');
fileReject(fn()=>arc_support_file_create($pdo,$network,$agent,(int)$f['other'],'from_agent'),'Cross-account transfer denied');
fileReject(fn()=>arc_support_file_create($pdo,$network,$agent,$owner,'to_agent','../outside.txt','test'),'Traversal filename denied');
fileReject(fn()=>arc_support_file_create($pdo,$network,$agent,$owner,'to_agent','large.txt',str_repeat('x',ARC_FILE_MAX_BYTES+1)),'Oversized file denied');
fileReject(fn()=>arc_support_file_name('CON.txt'),'Windows reserved filename denied');
$id=arc_support_file_create($pdo,$network,$agent,$operator,'from_agent');$_SESSION['arc_user_id']=$operator;
fileCheck(arc_support_file_record($pdo,$id)['direction']==='from_agent','Authorized requester can consult its transfer');
$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET enabled=0 WHERE network_id=? AND member_user_id=?')->execute([$network,$operator]);
fileReject(fn()=>arc_support_file_record($pdo,$id),'Team revocation prevents access immediately');
$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET enabled=1 WHERE network_id=? AND member_user_id=?')->execute([$network,$operator]);
$pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='failed' WHERE id=(SELECT command_id FROM ".arc_table('support_file_transfers')." WHERE id=?)")->execute([$id]);
$id=arc_support_file_create($pdo,$network,$agent,$owner,'to_agent','expiry.txt','Temporary file');$_SESSION['arc_user_id']=$owner;
$row=arc_support_file_record($pdo,$id);$path=arc_support_file_path($row['storage_key']);fileCheck(is_file($path),'Private storage holds uploaded bytes');
$pdo->prepare('UPDATE '.arc_table('support_file_transfers').' SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$id]);
fileReject(fn()=>arc_support_file_record($pdo,$id),'Expired transfer access denied');arc_support_files_cleanup($pdo);fileCheck(!is_file($path),'Expired bytes are deleted');
$q=$pdo->prepare('SELECT status,storage_key FROM '.arc_table('support_file_transfers').' WHERE id=?');$q->execute([$id]);$after=$q->fetch();fileCheck($after&&$after['status']==='expired'&&$after['storage_key']===null,'Metadata survives cleanup');
$before=(int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('support_file_transfers'))->fetchColumn();$sql=file_get_contents($root.'/database/migrations/3.3.0.sql');$sql=str_replace('`arc_','`'.arc_prefix(),$sql);$pdo->exec($sql);$pdo->exec($sql);fileCheck((int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('support_file_transfers'))->fetchColumn()===$before,'Migration replay preserves transfers');
echo $checks." file storage, permission, expiry and migration checks passed.\n";
