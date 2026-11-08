<?php
/* Caminho: api/billing-webhook.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store');
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false],405);
 $pdo=arc_db();arc_ensure_user_schema($pdo);$provider=(string)($_GET['provider']??'');
 if(!in_array($provider,['mercadopago','asaas'],true))arc_json(['ok'=>false],404);
 $raw=file_get_contents('php://input',false,null,0,131073);if(!is_string($raw)||strlen($raw)>131072)arc_json(['ok'=>false],413);
 $v=json_decode($raw,true);if(!is_array($v))arc_json(['ok'=>false],400);
 $secret=arc_platform_decrypt(arc_billing_setting($pdo,$provider.'_hook'));if(strlen($secret)<16)arc_json(['ok'=>false],403);
 if($provider==='mercadopago'){
  $id=(string)($v['data']['id']??$_GET['data_id']??'');$request=(string)($_SERVER['HTTP_X_REQUEST_ID']??'');
  if(!arc_billing_verify_mercadopago($id,$request,(string)($_SERVER['HTTP_X_SIGNATURE']??''),$secret,time()))arc_json(['ok'=>false],403);
  if(!preg_match('/^\d{1,30}$/D',$id))arc_json(['ok'=>false],422);
  $payment=arc_billing_http($pdo,$provider,'GET','/v1/payments/'.$id);
 }else{
  if(!hash_equals($secret,(string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??'')))arc_json(['ok'=>false],403);
  $id=(string)($v['payment']['id']??'');if(!preg_match('/^pay_[A-Za-z0-9_]{1,90}$/D',$id))arc_json(['ok'=>true,'ignored'=>true]);
  $payment=arc_billing_http($pdo,$provider,'GET','/payments/'.$id);
 }
 $ref=(string)($payment['external_reference']??$payment['externalReference']??'');
 $q=$pdo->prepare('SELECT * FROM '.arc_table('billing_invoices').' WHERE reference=? AND provider=?');$q->execute([$ref,$provider]);$i=$q->fetch();if(!$i)arc_json(['ok'=>true,'ignored'=>true]);
 if($provider==='asaas' && $i['external_id']!==null && $i['external_id']!==$id)throw new RuntimeException('ID de cobrança divergente.');
 $state=arc_billing_payment_state($i,$payment);
 arc_billing_apply($pdo,(int)$i['id'],$state,$id.':'.$state,$provider);
 arc_json(['ok'=>true]);
}catch(Throwable $e){arc_log_error('Webhook cobrança: '.$e->getMessage());arc_json(['ok'=>false,'error'=>'Não foi possível conciliar.'],503);}
