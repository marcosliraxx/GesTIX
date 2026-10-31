<?php
/* Caminho: includes/billing.php */
declare(strict_types=1);

const ARC_BILLING_VERSION = '3.3.3';

function arc_billing_seed_plans(PDO $pdo): void {
    if ((int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('billing_plans'))->fetchColumn()) return;
    foreach ([['Essencial',5990,3,15,2],['Profissional',14990,15,75,5],['Empresa',39990,50,250,15]] as $plan) {
        $pdo->prepare('INSERT IGNORE INTO '.arc_table('billing_plans').' (name,price_cents,networks_limit,agents_limit,seats_limit) SELECT ?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM '.arc_table('billing_plans').' WHERE name=?)')->execute([...$plan,$plan[0]]);
    }
}
function arc_money(int $cents): string { return 'R$ '.number_format($cents/100,2,',','.'); }
function arc_billing_setting(PDO $pdo,string $key,string $default=''): string { return arc_platform_setting($pdo,'platform_billing_'.$key,$default); }
function arc_billing_subscription(PDO $pdo,int $account): ?array {
    $q=$pdo->prepare('SELECT s.*,p.name,p.price_cents,p.networks_limit,p.agents_limit,p.seats_limit FROM '.arc_table('billing_subscriptions').' s JOIN '.arc_table('billing_plans').' p ON p.id=s.plan_id WHERE s.account_id=?');$q->execute([$account]);return $q->fetch()?:null;
}
function arc_billing_policy(PDO $pdo,int $account): array {
    $sub=arc_billing_subscription($pdo,$account);
    if ($sub) return $sub+['active'=>in_array($sub['status'],['active','past_due'],true)&&strtotime((string)$sub['period_end'])>=time()];
    $launch=arc_billing_setting($pdo,'launch_at');
    $q=$pdo->prepare('SELECT created_at FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$account]);$created=(string)$q->fetchColumn();
    if ($launch!=='' && $created!=='' && strtotime($created)>=strtotime($launch)) return ['name'=>'Avaliação de 14 dias','active'=>strtotime($created)+14*86400>=time(),'networks_limit'=>1,'agents_limit'=>3,'seats_limit'=>1];
    return ['name'=>'Conta existente preservada','active'=>true,'networks_limit'=>0,'agents_limit'=>0,'seats_limit'=>0];
}
function arc_billing_usage(PDO $pdo,int $account): array {
    $queries=['networks'=>'SELECT COUNT(*) FROM '.arc_table('managed_networks').' WHERE account_id=? AND is_active=1','agents'=>"SELECT COUNT(*) FROM ".arc_table('network_agents')." WHERE account_id=? AND status='approved'",'seats'=>'SELECT COUNT(DISTINCT member_user_id) FROM '.arc_table('network_team_members').' WHERE account_id=? AND enabled=1'];$out=[];
    foreach($queries as $k=>$sql){$q=$pdo->prepare($sql);$q->execute([$account]);$out[$k]=(int)$q->fetchColumn();}return $out;
}
function arc_billing_assert_active(PDO $pdo,int $account): void {
    if (!arc_billing_policy($pdo,$account)['active']) throw new RuntimeException('Assinatura vencida. Consulte Planos e cobrança; seus dados continuam preservados.');
}
/** The caller holds the account row lock when allocating quota. Zero means unlimited. */
function arc_billing_enforce(PDO $pdo,int $account,string $resource,int $extra=1): void {
    arc_billing_assert_active($pdo,$account);$p=arc_billing_policy($pdo,$account);$limit=(int)($p[$resource.'_limit']??0);
    if($limit>0 && (arc_billing_usage($pdo,$account)[$resource]??0)+$extra>$limit)throw new RuntimeException('Limite do plano atingido. Consulte Planos e cobrança.');
}
/** Fixed provider hosts, verified TLS, no redirects and no URLs supplied by customers. */
function arc_billing_http(PDO $pdo,string $provider,string $method,string $path,?array $data=null,string $idempotency=''): array {
    if(!function_exists('curl_init'))throw new RuntimeException('Ative a extensão PHP cURL para pagamentos.');
    if(!in_array($provider,['mercadopago','asaas'],true)||!preg_match('~^/[A-Za-z0-9_/?=&.-]+$~D',$path))throw new RuntimeException('Provedor inválido.');
    $key=arc_platform_decrypt(arc_billing_setting($pdo,$provider.'_key'));
    if($key==='')throw new RuntimeException('Configure a chave do gateway na administração.');
    $base=$provider==='mercadopago'?'https://api.mercadopago.com':(arc_billing_setting($pdo,'asaas_mode','sandbox')==='production'?'https://api.asaas.com/v3':'https://api-sandbox.asaas.com/v3');
    $headers=['Content-Type: application/json','Accept: application/json','User-Agent: Arcoll/'.ARC_APP_VERSION];
    $headers[]=$provider==='mercadopago'?'Authorization: Bearer '.$key:'access_token: '.$key;
    if($idempotency!=='' && $provider==='mercadopago')$headers[]='X-Idempotency-Key: '.$idempotency;
    $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $result=is_string($raw)?json_decode($raw,true):null;
    if($status<200||$status>=300||!is_array($result))throw new RuntimeException('Gateway indisponível ou configuração rejeitada (HTTP '.$status.'). Nenhum pagamento foi confirmado.');
    return $result;
}
function arc_billing_public_origin(PDO $pdo): string {
    $origin=rtrim(arc_billing_setting($pdo,'public_url'),'/');
    if(!preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[a-zA-Z0-9_/-]*)?$~D',$origin))throw new RuntimeException('Configure a URL HTTPS pública da plataforma na administração.');return $origin;
}
function arc_billing_verify_mercadopago(string $id,string $request,string $signature,string $secret,int $now): bool {
    if(!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id)||!preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$request)||strlen($secret)<16)return false;
    preg_match('/(?:^|,)\s*ts=(\d+)/',$signature,$ts);preg_match('/(?:^|,)\s*v1=([a-f0-9]{64})/',$signature,$mac);
    $stamp=(int)($ts[1]??0);$seconds=$stamp>9999999999?(int)($stamp/1000):$stamp;
    $manifest='id:'.strtolower($id).';request-id:'.$request.';ts:'.($ts[1]??'').';';
    return abs($now-$seconds)<=300 && hash_equals(hash_hmac('sha256',$manifest,$secret),$mac[1]??'');
}

function arc_billing_invoice(PDO $pdo,int $account,int $plan,string $provider): array {
    if(!in_array($provider,['mercadopago','asaas','pix'],true)||arc_billing_setting($pdo,$provider.'_enabled')!=='1')throw new RuntimeException('Forma de pagamento indisponível.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$account]);if(!$q->fetchColumn())throw new RuntimeException('Conta inválida.');
        $q=$pdo->prepare('SELECT * FROM '.arc_table('billing_plans').' WHERE id=? AND enabled=1');$q->execute([$plan]);$p=$q->fetch();if(!$p||(int)$p['price_cents']<100)throw new RuntimeException('Plano indisponível.');
        // Other plans and payment methods remain independent; repeated identical choices reuse their open charge.
        $q=$pdo->prepare("SELECT * FROM ".arc_table('billing_invoices')." WHERE account_id=? AND plan_id=? AND provider=? AND status IN ('pending','processing','review') ORDER BY id DESC LIMIT 1 FOR UPDATE");$q->execute([$account,$plan,$provider]);$existing=$q->fetch();
        if($existing){$pdo->commit();return $existing;}
        $sub=arc_billing_subscription($pdo,$account);$cycle=($sub?substr((string)$sub['period_end'],0,19):'initial').'-'.$plan.'-'.$provider;
        $q=$pdo->prepare('SELECT * FROM '.arc_table('billing_invoices').' WHERE account_id=? AND period_key=? FOR UPDATE');$q->execute([$account,$cycle]);$existing=$q->fetch();
        if($existing && $existing['status']!=='cancelled'){$pdo->commit();return $existing;}
        if($existing)$pdo->prepare('UPDATE '.arc_table('billing_invoices').' SET period_key=CONCAT(period_key,\'-x\',id) WHERE id=?')->execute([$existing['id']]);
        $ref=bin2hex(random_bytes(16));$pdo->prepare('INSERT INTO '.arc_table('billing_invoices').' (account_id,plan_id,amount_cents,provider,reference,period_key) VALUES (?,?,?,?,?,?)')->execute([$account,$plan,(int)$p['price_cents'],$provider,$ref,$cycle]);$id=(int)$pdo->lastInsertId();
        $pdo->commit();$q=$pdo->prepare('SELECT * FROM '.arc_table('billing_invoices').' WHERE id=?');$q->execute([$id]);return $q->fetch();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function arc_billing_checkout(PDO $pdo,array $invoice,string $document=''): string {
    if($invoice['status']==='paid')throw new RuntimeException('Cobrança já paga.');
    if($invoice['checkout_url']){
        if(!arc_billing_safe_checkout((string)$invoice['checkout_url'],(string)$invoice['provider']))throw new RuntimeException('Endereço de pagamento inválido. Solicite conferência administrativa.');
        return (string)$invoice['checkout_url'];
    }
    if($invoice['provider']==='pix')return '';
    $origin=arc_billing_public_origin($pdo);$provider=$invoice['provider'];
    if($provider==='asaas'){
        $q=$pdo->prepare('SELECT external_id FROM '.arc_table('billing_customers').' WHERE account_id=? AND provider=?');$q->execute([$invoice['account_id'],$provider]);
        if(!$q->fetchColumn()&&!in_array(strlen(preg_replace('/\D/','',$document)),[11,14],true))throw new RuntimeException('Informe CPF/CNPJ para continuar com Asaas.');
    }
    // A compare-and-set lease prevents parallel checkout creation; uncertain failures require reconciliation.
    $claim=$pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET status='processing' WHERE id=? AND status='pending'");$claim->execute([$invoice['id']]);
    if(!$claim->rowCount())throw new RuntimeException('Cobrança em processamento. Atualize a página; se persistir, solicite conciliação administrativa.');
    try{
        if($provider==='mercadopago'){
            $secret=arc_platform_decrypt(arc_billing_setting($pdo,'mercadopago_hook'));
            if(strlen($secret)<16)throw new RuntimeException('Configure o segredo do webhook.');
            $data=arc_billing_http($pdo,$provider,'POST','/checkout/preferences',['items'=>[['id'=>$invoice['reference'],'title'=>'Assinatura Arcoll · mensal','quantity'=>1,'currency_id'=>'BRL','unit_price'=>(int)$invoice['amount_cents']/100]],'external_reference'=>$invoice['reference'],'notification_url'=>$origin.'/api/billing-webhook.php?provider=mercadopago','back_urls'=>['success'=>$origin.'/pages/checkout.php?invoice='.(int)$invoice['id'],'pending'=>$origin.'/pages/checkout.php?invoice='.(int)$invoice['id'],'failure'=>$origin.'/pages/checkout.php?invoice='.(int)$invoice['id']],'auto_return'=>'approved'],$invoice['reference']);
            $url=arc_billing_setting($pdo,'mercadopago_mode','sandbox')==='production'?($data['init_point']??''):($data['sandbox_init_point']??'');$external=(string)($data['id']??'');
        }else{
            $q=$pdo->prepare('SELECT external_id FROM '.arc_table('billing_customers').' WHERE account_id=? AND provider=?');$q->execute([$invoice['account_id'],$provider]);$customer=(string)$q->fetchColumn();
            if($customer===''){
                $doc=preg_replace('/\D/','',$document);if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Informe CPF/CNPJ para o checkout Asaas.');
                $q=$pdo->prepare('SELECT display_name,email FROM '.arc_table('user_accounts').' WHERE id=?');$q->execute([$invoice['account_id']]);$u=$q->fetch();
                $c=arc_billing_http($pdo,$provider,'POST','/customers',['name'=>$u['display_name'],'email'=>$u['email'],'cpfCnpj'=>$doc,'externalReference'=>'arcoll-'.$invoice['account_id']]);$customer=(string)($c['id']??'');if($customer==='')throw new RuntimeException('Cliente não retornado pelo gateway.');
                $pdo->prepare('INSERT INTO '.arc_table('billing_customers').' (account_id,provider,external_id) VALUES (?,?,?)')->execute([$invoice['account_id'],$provider,$customer]);
            }
            $data=arc_billing_http($pdo,$provider,'POST','/payments',['customer'=>$customer,'billingType'=>'UNDEFINED','value'=>(int)$invoice['amount_cents']/100,'dueDate'=>date('Y-m-d',time()+3*86400),'description'=>'Assinatura Arcoll mensal','externalReference'=>$invoice['reference']]);$external=(string)($data['id']??'');$url=$data['invoiceUrl']??'';
        }
        if($external===''||!arc_billing_safe_checkout((string)$url,$provider))throw new RuntimeException('Gateway retornou checkout inválido. Solicite conciliação.');
        $pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET external_id=?,checkout_url=?,status='pending' WHERE id=? AND status='processing'")->execute([$external,$url,$invoice['id']]);return $url;
    }catch(Throwable $e){/* Keep processing to prevent charging twice after an ambiguous remote timeout. */throw $e;}
}
function arc_billing_safe_checkout(string $url,string $provider): bool {
    $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass']))return false;
    $host=strtolower($p['host']??'');$domains=$provider==='mercadopago'?['mercadopago.com.br','mercadopago.com']:['asaas.com'];
    foreach($domains as $d)if($host===$d||str_ends_with($host,'.'.$d))return true;return false;
}
/** Authoritative provider response; event payload alone can never activate a plan. */
function arc_billing_payment_state(array $invoice,array $payment): string {
    $ref=(string)($payment['external_reference']??$payment['externalReference']??'');
    $amount=$payment['transaction_amount']??$payment['value']??null;
    if($ref!==$invoice['reference']||!is_numeric($amount)||(int)round((float)$amount*100)!==(int)$invoice['amount_cents'])throw new RuntimeException('Referência ou valor do pagamento divergente.');
    if(isset($payment['currency_id'])&&$payment['currency_id']!=='BRL')throw new RuntimeException('Moeda inválida.');
    $state=strtoupper((string)($payment['status']??''));
    if(in_array($state,['APPROVED','RECEIVED','CONFIRMED','RECEIVED_IN_CASH'],true))return 'paid';
    if(in_array($state,['REFUNDED','CHARGED_BACK','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE','REFUND_REQUESTED'],true))return 'reversed';
    return 'pending';
}
function arc_billing_apply(PDO $pdo,int $id,string $state,string $eventKey,string $provider,int $actor=0): void {
    if(!in_array($state,['paid','reversed','pending'],true))throw new RuntimeException('Estado inválido.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM '.arc_table('billing_invoices').' WHERE id=? FOR UPDATE');$q->execute([$id]);$i=$q->fetch();if(!$i||$i['provider']!==$provider)throw new RuntimeException('Cobrança inválida.');
        $q=$pdo->prepare('SELECT id FROM '.arc_table('billing_events').' WHERE provider=? AND event_key=?');$q->execute([$provider,hash('sha256',$eventKey)]);if($q->fetchColumn()){$pdo->commit();return;}
        if($state==='paid'&&!in_array($i['status'],['paid','reversed','cancelled'],true)){
            $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE id=? FOR UPDATE');$q->execute([$i['account_id']]);
            $sub=arc_billing_subscription($pdo,(int)$i['account_id']);$start=max(time(),$sub?strtotime((string)$sub['period_end']):0);$end=date('Y-m-d H:i:s',$start+30*86400);
            $pdo->prepare("INSERT INTO ".arc_table('billing_subscriptions')." (account_id,plan_id,status,period_end,provider) VALUES (?,?,'active',?,?) ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id),status='active',period_end=VALUES(period_end),provider=VALUES(provider),cancel_at_period_end=0")->execute([$i['account_id'],$i['plan_id'],$end,$provider]);
            $pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET status='paid',paid_at=NOW() WHERE id=?")->execute([$id]);
        }elseif($state==='reversed'&&$i['status']==='paid'){
            $pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET status='reversed' WHERE id=?")->execute([$id]);
            // Suspend new operations pending manual financial review; never erase tenant data.
            $pdo->prepare("UPDATE ".arc_table('billing_subscriptions')." SET status='review' WHERE account_id=?")->execute([$i['account_id']]);
        }
        $pdo->prepare('INSERT INTO '.arc_table('billing_events').' (provider,event_key,invoice_id,event_type) VALUES (?,?,?,?)')->execute([$provider,hash('sha256',$eventKey),$id,$state]);
        $pdo->commit();
        if($actor)arc_user_audit($pdo,'billing_'.$state,'Cobrança #'.$id.' conferida pelo administrador #'.$actor,(int)$i['account_id']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Plain Portuguese payment states, shared by checkout and history. */
function arc_billing_status_label(string $status): string {
    return ['pending'=>'Aguardando pagamento','processing'=>'Preparando pagamento','review'=>'Em conferência','paid'=>'Pago','reversed'=>'Estornado ou contestado','cancelled'=>'Cancelado'][$status]??'Em análise';
}
function arc_pix_field(string $id,string $value): string {
    if(strlen($value)>99)throw new RuntimeException('Dados Pix excedem o tamanho permitido.');
    return $id.str_pad((string)strlen($value),2,'0',STR_PAD_LEFT).$value;
}
function arc_pix_name(string $value,int $limit): string {
    $value=strtr(mb_strtoupper($value,'UTF-8'),['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
    return substr(trim(preg_replace('/[^A-Z0-9 ]/','',$value)),0,$limit);
}
/** EMV BR Code Pix estático com valor e txid. Nenhum serviço externo recebe a chave. */
function arc_billing_pix_payload(string $key,string $name,string $city,int $cents,string $txid): string {
    $key=trim($key);$name=arc_pix_name($name,25);$city=arc_pix_name($city,15);
    if($key===''||strlen($key)>77||preg_match('/[\x00-\x20\x7f]/',$key)||$name===''||$city===''||$cents<100||!preg_match('/^[A-Za-z0-9]{1,25}$/D',$txid))throw new RuntimeException('Configure chave, beneficiário e cidade válidos para o Pix.');
    $data=arc_pix_field('00','01').arc_pix_field('26',arc_pix_field('00','br.gov.bcb.pix').arc_pix_field('01',$key)).arc_pix_field('52','0000').arc_pix_field('53','986').arc_pix_field('54',number_format($cents/100,2,'.','')).arc_pix_field('58','BR').arc_pix_field('59',$name).arc_pix_field('60',$city).arc_pix_field('62',arc_pix_field('05',$txid)).'6304';
    $crc=0xFFFF;for($i=0;$i<strlen($data);$i++){$crc^=ord($data[$i])<<8;for($b=0;$b<8;$b++)$crc=(($crc<<1)^(($crc&0x8000)?0x1021:0))&0xFFFF;}
    return $data.strtoupper(str_pad(dechex($crc),4,'0',STR_PAD_LEFT));
}
function arc_billing_pix_snapshot(PDO $pdo,array $invoice): string {
    if(!empty($invoice['payment_payload']))return (string)$invoice['payment_payload'];
    if($invoice['provider']!=='pix'||$invoice['status']!=='pending')return '';
    $payload=arc_billing_pix_payload(arc_billing_setting($pdo,'pix_key'),arc_billing_setting($pdo,'pix_name','ARCOLL'),arc_billing_setting($pdo,'pix_city','BELEM'),(int)$invoice['amount_cents'],'ARC'.$invoice['id']);
    $pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET payment_payload=? WHERE id=? AND account_id=? AND payment_payload IS NULL AND provider='pix' AND status='pending'")->execute([$payload,$invoice['id'],$invoice['account_id']]);
    $q=$pdo->prepare('SELECT payment_payload FROM '.arc_table('billing_invoices').' WHERE id=? AND account_id=?');$q->execute([$invoice['id'],$invoice['account_id']]);return (string)$q->fetchColumn();
}
