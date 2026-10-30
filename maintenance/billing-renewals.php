<?php
/* Caminho: maintenance/billing-renewals.php */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';$pdo=arc_db();arc_ensure_user_schema($pdo);$count=0;$errors=0;
// A single CLI worker per database prevents duplicate renewal processing.
$lock='arcoll-billing-'.substr(hash('sha256',arc_prefix().(string)arc_config()['db_name']),0,24);$q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if(!(int)$q->fetchColumn())exit(0);
try{
 $pdo->exec("UPDATE ".arc_table('billing_subscriptions')." SET status=CASE WHEN cancel_at_period_end=1 THEN 'cancelled' ELSE 'past_due' END WHERE status IN ('active','past_due') AND period_end<NOW()");
 $rows=$pdo->query("SELECT * FROM ".arc_table('billing_subscriptions')." WHERE status IN ('active','past_due') AND cancel_at_period_end=0 AND period_end<DATE_ADD(NOW(),INTERVAL 7 DAY) ORDER BY period_end LIMIT 500")->fetchAll();
 foreach($rows as $s){
  try{
   $i=arc_billing_invoice($pdo,(int)$s['account_id'],(int)$s['plan_id'],$s['provider']);
   if($i['provider']!=='pix' && $i['status']==='pending' && !$i['checkout_url'])arc_billing_checkout($pdo,$i);
   $event='invoice-notice-'.$i['id'];$q=$pdo->prepare("SELECT id FROM ".arc_table('billing_events')." WHERE provider='system' AND event_key=?");$q->execute([hash('sha256',$event)]);
   if(!$q->fetchColumn()){
    $q=$pdo->prepare('SELECT email FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$s['account_id']]);$email=(string)$q->fetchColumn();
    if(arc_platform_setting($pdo,'platform_smtp_enabled','0')==='1')arc_platform_smtp_send($pdo,$email,'Sua renovação Arcoll está disponível','Cobrança #'.$i['id'].' de '.arc_money((int)$i['amount_cents']).".\nConsulte sua conta para pagar: ".arc_billing_public_origin($pdo).'/pages/assinatura.php');
    arc_user_audit($pdo,'subscription_invoice_created','Renovação disponível: cobrança #'.$i['id'],(int)$s['account_id']);
    $pdo->prepare("INSERT INTO ".arc_table('billing_events')." (provider,event_key,invoice_id,event_type) VALUES ('system',?,?,'invoice_notice')")->execute([hash('sha256',$event),$i['id']]);
   }
   $count++;
  }catch(Throwable $e){$errors++;error_log('Renovação conta #'.(int)$s['account_id'].': '.$e->getMessage());}
 }
 arc_support_files_cleanup($pdo);
 // Tokens expire independently of cleanup; no ticket, invoice or audit history is erased.
 $pdo->exec('DELETE FROM '.arc_table('support_remote_tokens').' WHERE expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)');
 echo 'Renovações verificadas: '.$count.'; falhas: '.$errors.PHP_EOL;
}finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
exit($errors?1:0);
