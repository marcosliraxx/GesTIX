<?php
/* Caminho: pages/checkout.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';$pdo=arc_db();arc_ensure_user_schema($pdo);
if(!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
header('Cache-Control: no-store, private');$uid=(int)arc_user_id();$id=(int)($_GET['invoice']??$_POST['invoice_id']??0);$error='';$payload='';$invoice=null;
try{
 arc_require_billing_release();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  arc_verify_csrf();$action=(string)($_POST['action']??'');
  if($action==='start'){$invoice=arc_billing_invoice($pdo,$uid,(int)($_POST['plan_id']??0),(string)($_POST['provider']??''));header('Location: '.arc_workspace_base_url().'pages/checkout.php?invoice='.(int)$invoice['id'],true,303);exit;}
 }
 $q=$pdo->prepare('SELECT i.*,p.name FROM '.arc_table('billing_invoices').' i JOIN '.arc_table('billing_plans').' p ON p.id=i.plan_id WHERE i.id=? AND i.account_id=?');$q->execute([$id,$uid]);$invoice=$q->fetch();
 if(!$invoice){http_response_code(404);throw new RuntimeException('Cobrança não encontrada. Escolha um plano ou consulte seu histórico.');}
 if(($_GET['status']??'')==='1')arc_json(['ok'=>true,'status'=>$invoice['status'],'label'=>arc_billing_status_label($invoice['status'])]);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if($action==='pay'){
   if(!in_array($invoice['status'],['pending','processing'],true))throw new RuntimeException('Esta cobrança não está disponível para pagamento.');
   $url=arc_billing_checkout($pdo,$invoice,(string)($_POST['document']??''));
   if($url!==''&&arc_billing_safe_checkout($url,$invoice['provider'])){header('Location: '.$url,true,303);exit;}
  }elseif($action==='receipt'){
   $note=trim((string)($_POST['receipt_note']??''));if(mb_strlen($note)<8||mb_strlen($note)>255)throw new RuntimeException('Informe seu nome e a referência do pagamento (8 a 255 caracteres).');
   $pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET receipt_note=?,status='review' WHERE id=? AND account_id=? AND provider='pix' AND status='pending'")->execute([$note,$id,$uid]);
  }elseif($action==='cancel'){
   // External charges need provider reconciliation: never cancel locally while a payment may settle.
   $q=$pdo->prepare("UPDATE ".arc_table('billing_invoices')." SET status='cancelled' WHERE id=? AND account_id=? AND status='pending' AND (provider='pix' OR (external_id IS NULL AND checkout_url IS NULL))");$q->execute([$id,$uid]);if(!$q->rowCount())throw new RuntimeException('Esta cobrança precisa ser conferida pelo administrador antes de cancelar.');
  }else throw new RuntimeException('Operação indisponível.');
  header('Location: '.arc_workspace_base_url().'pages/checkout.php?invoice='.$id,true,303);exit;
 }
 if($invoice['provider']==='pix'&&in_array($invoice['status'],['pending','review'],true))$payload=arc_billing_pix_snapshot($pdo,$invoice);
}catch(Throwable $e){if($e->getCode()===503||$e instanceof PDOException||!($e instanceof RuntimeException))http_response_code(503);arc_log_error('Checkout: '.$e->getMessage());$error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível preparar o pagamento. Tente novamente mais tarde.';}
if($error!==''&&($_GET['status']??'')==='1')arc_json(['ok'=>false,'error'=>$error],503);
$ARCPageTitle='Pagamento';$ARCActiveNav='billing';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<main class="app-main"><div class="page-content arc-service arc-checkout">
<header class="arc-service-heading"><h1>Pagamento</h1><a class="arc-icon-action" href="pages/cobrancas.php" aria-label="Voltar às cobranças" title="Cobranças"><i class="fa fa-history" aria-hidden="true"></i></a></header>
<?php if($error):?><p class="arc-service-error" role="alert"><?=arc_h($error)?></p><?php endif;?>
<?php if(!empty($invoice)&&$error===''):?>
<section class="arc-service-card"><header class="arc-service-heading"><div><h2><?=arc_h($invoice['name'])?></h2><small>Cobrança #<?=(int)$id?> · 30 dias</small></div><strong class="arc-plan-price"><?=arc_money((int)$invoice['amount_cents'])?></strong></header><p id="payment-status" role="status"><?=arc_h(arc_billing_status_label($invoice['status']))?></p>
<?php if($invoice['status']==='paid'):?><p>Pagamento confirmado. Seu plano foi liberado.</p><a class="button primary" href="pages/painel.php">Ir ao painel</a>
<?php elseif($invoice['status']==='review'):?><p>Recebemos sua referência. O administrador vai conferir o Pix na conta bancária.</p>
<?php elseif($invoice['status']==='pending'||$invoice['status']==='processing'):?>
<?php if($invoice['provider']==='pix'&&$payload!==''):?><div class="arc-pix-checkout"><div id="pix-qr" role="img" aria-label="QR Code Pix desta cobrança"></div><div><h3>Pague pelo aplicativo do banco</h3><p>Confira o beneficiário e o valor antes de confirmar.</p><label for="pix-copy">Pix copia e cola</label><textarea id="pix-copy" readonly rows="3"><?=arc_h($payload)?></textarea><button type="button" class="button primary" id="pix-copy-button"><i class="fa fa-copy" aria-hidden="true"></i> Copiar Pix</button><p id="copy-feedback" role="status"></p><p><?=arc_h(arc_billing_setting($pdo,'pix_instructions'))?></p></div></div><button type="button" class="button" data-open-dialog="pix-receipt"><i class="fa fa-check" aria-hidden="true"></i> Já paguei</button><dialog id="pix-receipt" class="arc-service-dialog"><header class="arc-service-heading"><h2>Informar pagamento</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Pix manual é liberado após conferência. Informar o pagamento não confirma o recebimento.</p><form method="post" class="arc-service-form"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="invoice_id" value="<?=$id?>"><input type="hidden" name="action" value="receipt"><label>Seu nome e referência da transação<input name="receipt_note" minlength="8" maxlength="255" required></label><button class="button primary">Enviar para conferência</button></form></dialog>
<?php elseif($invoice['provider']!=='pix'):?><p>Finalize o pagamento no ambiente seguro do <?=arc_h($invoice['provider']==='asaas'?'Asaas':'Mercado Pago')?>. Seu plano será liberado após a confirmação do gateway.</p><form method="post" class="arc-service-form"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="invoice_id" value="<?=$id?>"><input type="hidden" name="action" value="pay"><?php if($invoice['provider']==='asaas'&&!$invoice['external_id']):?><label>CPF ou CNPJ<input name="document" inputmode="numeric" autocomplete="off" maxlength="18" required></label><?php endif;?><button class="button primary"><i class="fa fa-lock" aria-hidden="true"></i> Continuar pagamento</button></form><?php endif;?>
<?php if($invoice['status']==='pending'&&($invoice['provider']==='pix'||(!$invoice['external_id']&&!$invoice['checkout_url']))):?><form method="post"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="invoice_id" value="<?=$id?>"><button name="action" value="cancel" class="button">Cancelar cobrança</button></form><?php endif;?>
<?php else:?><a class="button" href="pages/assinatura.php">Ver planos</a><?php endif;?></section>
<?php endif;?></div></main>
<?php if($error===''&&!empty($invoice)&&$invoice['status']==='pending'&&$payload!==''):?><script src="assets/vendor/qrcode-generator/qrcode.js?v=1.4.4"></script><script>
(()=>{'use strict';try{const qr=qrcode(0,'M');qr.addData(<?=json_encode($payload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);qr.make();document.getElementById('pix-qr').innerHTML=qr.createSvgTag({cellSize:5,margin:20,scalable:true});}catch(e){document.getElementById('pix-qr').textContent='Use o código copia e cola abaixo.';}document.getElementById('pix-copy-button')?.addEventListener('click',async()=>{const field=document.getElementById('pix-copy'),message=document.getElementById('copy-feedback');try{await navigator.clipboard.writeText(field.value);message.textContent='Pix copiado. Abra o aplicativo do banco.';}catch(e){field.focus();field.select();message.textContent='Selecione e copie o código Pix.';}});})();
</script><?php endif;?>
<?php if($error===''&&!empty($invoice)&&in_array($invoice['status'],['pending','processing','review'],true)):?><script>
(()=>{'use strict';const initial=<?=json_encode($invoice['status'])?>;let busy=false,tries=0;const timer=setInterval(async()=>{if(document.hidden||busy)return;if(++tries>180){clearInterval(timer);return;}busy=true;try{const r=await fetch(<?=json_encode(arc_workspace_base_url().'pages/checkout.php?invoice='.$id.'&status=1')?>,{credentials:'same-origin',cache:'no-store'});if(!r.ok)return;const j=await r.json();if(j.ok){document.getElementById('payment-status').textContent=j.label;if(j.status!==initial)location.reload();}}catch(e){}finally{busy=false;}},10000);})();
</script><?php endif;?>
<?php require dirname(__DIR__).'/includes/footer.php';?>
