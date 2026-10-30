<?php
/* Caminho: tests/services_integration.php */
declare(strict_types=1);
$root=getenv('GESTI_TEST_ROOT');if(!$root)throw new RuntimeException('Explicit isolated test root required.');
require $root.'/includes/bootstrap.php';$pdo=arc_db();
if(!str_ends_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'_services_test'))throw new RuntimeException('Dedicated synthetic database required.');
arc_ensure_user_schema($pdo);$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function account(PDO $pdo,string $name,string $password):int{$email=$name.'-'.bin2hex(random_bytes(3)).'@example.test';$pdo->prepare("INSERT INTO ".arc_table('user_accounts')." (email,email_hash,display_name,password_hash,account_status,access_notes) VALUES (?,?,?,?,'active','')")->execute([$email,hash('sha256',$email),$name,password_hash($password,PASSWORD_DEFAULT)]);return(int)$pdo->lastInsertId();}
$before=(int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('billing_invoices'))->fetchColumn();$migration=file_get_contents($root.'/database/migrations/3.2.0.sql');$migration=str_replace(['`arc_',"'arc_"],['`'.arc_prefix(),"'".arc_prefix()],$migration);$pdo->exec($migration);$pdo->exec($migration);check((int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('billing_invoices'))->fetchColumn()===$before,'Migration replay preserves existing invoices');
$site=strtoupper(substr(bin2hex(random_bytes(8)),0,5));$password=bin2hex(random_bytes(16));$owner=account($pdo,'owner',$password);$other=account($pdo,'other',$password);$operator=account($pdo,'operator',$password);$viewer=account($pdo,'viewer',$password);
$pdo->prepare("INSERT INTO ".arc_table('managed_networks')." (account_id,name,site_code,notes,monitoring_enabled) VALUES (?,'Synthetic support network',?,'',1)")->execute([$owner,$site]);$network=(int)$pdo->lastInsertId();
arc_team_save($pdo,$owner,$network,$operator,'operator');arc_team_save($pdo,$owner,$network,$viewer,'viewer');
$fp=hash('sha256',random_bytes(32));$download=bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO ".arc_table('network_agents')." (account_id,network_id,site_code,fingerprint,machine_name,status,last_seen_at) VALUES (?,?,?,?,'Synthetic Windows','approved',NOW())")->execute([$owner,$network,$site,$fp]);$agent=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ".arc_table('agent_download_keys')." (token_hash,account_id,network_id,site_code,fingerprint) VALUES (?,?,?,?,?)")->execute([hash('sha256',$download),$owner,$network,$site,$fp]);
$ticket=arc_support_ticket_write($pdo,$network,$operator,['action'=>'ticket_create','title'=>'Synthetic ticket','description'=>'Long enough problem description','priority'=>'high','agent_id'=>$agent]);check($ticket>0,'Operator creates ticket');
rejects(fn()=>arc_support_ticket_write($pdo,$network,$viewer,['action'=>'ticket_create','title'=>'No','description'=>'No','priority'=>'normal']),'Viewer cannot mutate');
rejects(fn()=>arc_support_access($pdo,$network,$other),'Tenant isolation');
arc_support_ticket_write($pdo,$network,$operator,['action'=>'ticket_update','ticket_id'=>$ticket,'status'=>'in_progress','assigned_to'=>$operator,'message'=>'Started','minutes'=>12]);
rejects(fn()=>arc_support_ticket_write($pdo,$network,$operator,['action'=>'ticket_update','ticket_id'=>$ticket,'assigned_to'=>$owner,'message'=>'Reassign']),'Operator cannot assign another technician');
rejects(fn()=>arc_support_ticket_write($pdo,$network,$operator,['action'=>'ticket_update','ticket_id'=>$ticket,'status'=>'resolved','message'=>'']),'Resolution requires note');
arc_support_ticket_write($pdo,$network,$operator,['action'=>'ticket_update','ticket_id'=>$ticket,'status'=>'resolved','message'=>'Fixed','minutes'=>18]);
$q=$pdo->prepare('SELECT SUM(minutes_spent) FROM '.arc_table('support_events').' WHERE ticket_id=?');$q->execute([$ticket]);check((int)$q->fetchColumn()===30,'Work logs preserved');
$command=arc_support_request_remote($pdo,$network,$agent,$operator,$ticket);check($command>0,'Operator may request attended remote');
rejects(fn()=>arc_support_request_remote($pdo,$network,$agent,$owner),'Single remote session per computer');
$code=arc_support_issue_code($pdo,$command,$operator);$session=arc_support_redeem($pdo,$code);check(strlen($session['token'])===64,'Scoped session issued');rejects(fn()=>arc_support_redeem($pdo,$code),'One-time redemption');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$session['token'];check(arc_support_native_user($pdo,$command)===$operator,'Native bearer authenticated');check(arc_support_native_user($pdo,$command+999)===0,'Bearer cannot change command scope');unset($_SERVER['HTTP_AUTHORIZATION']);
$q=$pdo->prepare('SELECT * FROM '.arc_table('agent_commands').' WHERE id=?');$q->execute([$command]);$c=$q->fetch();check(arc_support_remote_allowed($pdo,$c,$operator),'Assigned operator authorized');check(!arc_support_remote_allowed($pdo,$c,$viewer),'Viewer cannot view remote session');
$pdo->prepare('UPDATE '.arc_table('network_team_members').' SET enabled=0 WHERE account_id=? AND member_user_id=?')->execute([$owner,$operator]);check(!arc_support_remote_allowed($pdo,$c,$operator),'Revocation immediate');arc_team_save($pdo,$owner,$network,$operator,'operator');
$pdo->prepare('UPDATE '.arc_table('support_remote_tokens').' SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE command_id=?')->execute([$command]);$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$session['token'];check(arc_support_native_user($pdo,$command)===0,'Expired bearer rejected');unset($_SERVER['HTTP_AUTHORIZATION']);
$pdo->prepare("UPDATE ".arc_table('agent_commands')." SET status='completed' WHERE id=?")->execute([$command]);
$pdo->prepare('UPDATE '.arc_table('network_agents').' SET headless_mode=1 WHERE id=?')->execute([$agent]);rejects(fn()=>arc_support_request_remote($pdo,$network,$agent,$operator),'Headless access requires gestor');$pdo->prepare('UPDATE '.arc_table('network_agents').' SET headless_mode=0 WHERE id=?')->execute([$agent]);
arc_platform_save_setting($pdo,'platform_billing_public_url','https://app.example.test');arc_platform_save_setting($pdo,'platform_billing_pix_enabled','1');arc_platform_save_setting($pdo,'platform_billing_pix_key','synthetic@example.test');
$plan=(int)$pdo->query('SELECT MIN(id) FROM '.arc_table('billing_plans'))->fetchColumn();$invoice=arc_billing_invoice($pdo,$owner,$plan,'pix');$again=arc_billing_invoice($pdo,$owner,$plan,'pix');check($invoice['id']===$again['id'],'Checkout is locally idempotent');
$payload=arc_billing_pix_snapshot($pdo,$invoice);check(str_contains($payload,'synthetic@example.test'),'Pix contains configured beneficiary key');check(str_contains($payload,'5405'.number_format((int)$invoice['amount_cents']/100,2,'.','')),'Pix contains invoice amount');
$checkCrc=0xFFFF;$part=substr($payload,0,-4);for($j=0;$j<strlen($part);$j++){$checkCrc^=ord($part[$j])<<8;for($bit=0;$bit<8;$bit++)$checkCrc=(($checkCrc<<1)^(($checkCrc&0x8000)?0x1021:0))&0xFFFF;}check(strtoupper(str_pad(dechex($checkCrc),4,'0',STR_PAD_LEFT))===substr($payload,-4),'Pix checksum valid');
arc_platform_save_setting($pdo,'platform_billing_pix_key','changed@example.test');check(arc_billing_pix_snapshot($pdo,$invoice)===$payload,'Issued Pix snapshot survives key change');arc_platform_save_setting($pdo,'platform_billing_pix_key','synthetic@example.test');
rejects(fn()=>arc_billing_pix_payload('','TEST','BELEM',100,'ARC1'),'Blank Pix key rejected');rejects(fn()=>arc_billing_pix_payload('test@example.test','TEST','BELEM',100,'INVALID-REF'),'Nonalphanumeric Pix txid rejected');
rejects(fn()=>arc_billing_payment_state($invoice,['external_reference'=>$invoice['reference'],'transaction_amount'=>0.01,'status'=>'approved']),'Wrong amount rejected');
rejects(fn()=>arc_billing_payment_state($invoice,['external_reference'=>'other','transaction_amount'=>$invoice['amount_cents']/100,'status'=>'approved']),'Wrong reference rejected');
check(arc_billing_payment_state($invoice,['external_reference'=>$invoice['reference'],'transaction_amount'=>$invoice['amount_cents']/100,'currency_id'=>'BRL','status'=>'approved'])==='paid','Authoritative paid mapping');
arc_billing_apply($pdo,(int)$invoice['id'],'paid','synthetic-'.$invoice['id'],'pix');$end=arc_billing_subscription($pdo,$owner)['period_end'];arc_billing_apply($pdo,(int)$invoice['id'],'paid','synthetic-'.$invoice['id'],'pix');arc_billing_apply($pdo,(int)$invoice['id'],'paid','duplicate-'.$invoice['id'],'pix');check(arc_billing_subscription($pdo,$owner)['period_end']===$end,'Duplicate payments do not extend entitlement');
$pdo->prepare('UPDATE '.arc_table('billing_plans').' SET networks_limit=1,seats_limit=2 WHERE id=?')->execute([$plan]);rejects(fn()=>arc_billing_enforce($pdo,$owner,'networks'),'Network quota enforced');
rejects(fn()=>arc_team_save($pdo,$owner,$network,$other,'viewer'),'Team quota enforced');
$pdo->prepare('UPDATE '.arc_table('billing_subscriptions').' SET period_end=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE account_id=?')->execute([$owner]);rejects(fn()=>arc_billing_assert_active($pdo,$owner),'Expired subscription blocks new operations');
check(!arc_support_remote_allowed($pdo,$c,$operator),'Expired account remote denied');
$pdo->prepare('UPDATE '.arc_table('billing_subscriptions').' SET period_end=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE account_id=?')->execute([$owner]);arc_billing_apply($pdo,(int)$invoice['id'],'reversed','refund-'.$invoice['id'],'pix');check(arc_billing_subscription($pdo,$owner)['status']==='review','Refund enters review');
check(!arc_billing_safe_checkout('https://mercadopago.com.br.evil.test/pay','mercadopago'),'Checkout host suffix protected');check(!arc_billing_safe_checkout('http://www.mercadopago.com.br/pay','mercadopago'),'Checkout TLS required');check(arc_billing_safe_checkout('https://www.mercadopago.com.br/checkout','mercadopago'),'Official checkout allowed');
$secret='synthetic-webhook-signature-key';$now=time();$signature='ts='.$now.',v1='.hash_hmac('sha256','id:123;request-id:req-test;ts:'.$now.';',$secret);
check(arc_billing_verify_mercadopago('123','req-test',$signature,$secret,$now),'Authentic MP HMAC accepted');
check(!arc_billing_verify_mercadopago('124','req-test',$signature,$secret,$now),'Changed payment ID signature rejected');
check(!arc_billing_verify_mercadopago('123','req-test',$signature,$secret,$now+600),'Stale webhook rejected');
check(!arc_billing_verify_mercadopago('123','req-test',$signature,'wrong-webhook-key-123',$now),'Wrong webhook key rejected');
// Restore this synthetic account to a useful state for HTTP tests.
$pdo->prepare("UPDATE ".arc_table('billing_subscriptions')." SET status='active',period_end=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE account_id=?")->execute([$owner]);
$pdo->prepare('UPDATE '.arc_table('network_agents').' SET last_seen_at=NOW() WHERE id=?')->execute([$agent]);
$adminEmail='admin-'.bin2hex(random_bytes(4)).'@example.test';$pdo->prepare('INSERT INTO '.arc_table('admins').' (email,password_hash) VALUES (?,?)')->execute([$adminEmail,password_hash($password,PASSWORD_DEFAULT)]);
$emails=[];foreach(['owner'=>$owner,'operator'=>$operator,'viewer'=>$viewer,'other'=>$other]as$k=>$id){$q=$pdo->prepare('SELECT email FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$id]);$emails[$k]=$q->fetchColumn();}
$fixture=['password'=>$password,'adminEmail'=>$adminEmail,'users'=>$emails,'owner'=>$owner,'operator'=>$operator,'viewer'=>$viewer,'other'=>$other,'network'=>$network,'agent'=>$agent,'fingerprint'=>$fp,'site_code'=>$site,'download_key'=>$download,'ticket'=>$ticket];
$im=imagecreatetruecolor(2,2);ob_start();imagejpeg($im,null,70);$fixture['jpeg']=base64_encode(ob_get_clean());imagedestroy($im);
if(getenv('GESTI_TEST_FIXTURE_PATH'))file_put_contents(getenv('GESTI_TEST_FIXTURE_PATH'),json_encode($fixture));
echo $checks." service integration assertions passed.\n";
