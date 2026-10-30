<?php
/* Caminho: pages/cobrancas.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();arc_ensure_user_schema($pdo);
if(!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
header('Cache-Control: no-store, private');
header('X-Arcoll-Release: '.ARC_APP_VERSION);
$uid=(int)arc_user_id();$status=(string)($_GET['status']??'');
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$page=max(1,min(100000,(int)($_GET['page']??1)));
$from=(string)($_GET['from']??date('Y-m-d',strtotime('-1 year')));
$to=(string)($_GET['to']??date('Y-m-d'));
$error='';$items=[];$states=[];$total=0;$pages=1;
$a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$a>$b){
 $error='Escolha um período válido.';$from=date('Y-m-d',strtotime('-1 year'));$to=date('Y-m-d');
}
try{
 arc_require_billing_release();
 // Prepare every billing-dependent value before outputting the document.
 foreach(['pending','processing','review','paid','reversed','cancelled'] as $key)$states[$key]=arc_billing_status_label($key);
 if(!isset($states[$status]))$status='';
 $where='i.account_id=? AND i.created_at>=? AND i.created_at<?';
 $args=[$uid,$from.' 00:00:00',(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
 if($status!==''){$where.=' AND i.status=?';$args[]=$status;}
 if($search!==''){
  $where.=" AND (p.name LIKE ? ESCAPE '!' OR CAST(i.id AS CHAR)=? OR i.reference=?)";
  $args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$args[]=$search;$args[]=$search;
 }
 $join=' FROM '.arc_table('billing_invoices').' i JOIN '.arc_table('billing_plans').' p ON p.id=i.plan_id WHERE '.$where;
 $q=$pdo->prepare('SELECT COUNT(*)'.$join);$q->execute($args);$total=(int)$q->fetchColumn();
 $pages=max(1,(int)ceil($total/30));$page=min($page,$pages);
 $q=$pdo->prepare('SELECT i.*,p.name'.$join.' ORDER BY i.id DESC LIMIT 30 OFFSET '.(($page-1)*30));$q->execute($args);
 foreach($q->fetchAll() as $row){
  $items[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'amount'=>arc_money((int)$row['amount_cents']),
   'created'=>arc_when($row['created_at']),'provider'=>['pix'=>'Pix manual','asaas'=>'Asaas','mercadopago'=>'Mercado Pago'][$row['provider']]??'Pagamento',
   'state'=>$states[$row['status']]??'Em análise','paid'=>$row['paid_at']?arc_when($row['paid_at']):'',
   'receipt'=>(string)($row['receipt_note']??'')];
 }
}catch(Throwable $e){
 error_log('Histórico de cobranças: '.$e->getMessage());http_response_code(503);
 $states=[];$items=[];$total=0;$pages=1;
 $error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível consultar as cobranças. Tente novamente mais tarde.';
}
$filters=['q'=>$search,'status'=>$status,'from'=>$from,'to'=>$to];
$ARCPageTitle='Histórico de cobranças';$ARCActiveNav='billing_history';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<main class="app-main"><div class="page-content arc-service">
<header class="arc-service-heading"><h1>Cobranças</h1><a class="arc-icon-action" href="pages/assinatura.php" title="Planos" aria-label="Ver planos"><i class="fa fa-th-large" aria-hidden="true"></i></a></header>
<form method="get" class="arc-filter-bar">
 <input name="q" value="<?=arc_h($search)?>" placeholder="Plano ou cobrança" aria-label="Buscar cobrança">
 <?php foreach(['status'=>$status,'from'=>$from,'to'=>$to] as $name=>$value):?><input type="hidden" name="<?=$name?>" value="<?=arc_h($value)?>"><?php endforeach;?>
 <button class="arc-icon-action" aria-label="Buscar" title="Buscar"><i class="fa fa-search" aria-hidden="true"></i></button>
 <button type="button" class="arc-icon-action" data-open-dialog="billing-filters" aria-label="Filtrar cobranças" title="Filtros"><i class="fa fa-sliders" aria-hidden="true"></i></button>
</form>
<dialog id="billing-filters" class="arc-service-dialog"><header class="arc-service-heading"><h2>Filtrar cobranças</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header>
 <form method="get" class="arc-service-form"><input type="hidden" name="q" value="<?=arc_h($search)?>">
 <label>Situação<select name="status"><option value="">Todas</option><?php foreach($states as $key=>$label):?><option value="<?=$key?>" <?=$status===$key?'selected':''?>><?=arc_h($label)?></option><?php endforeach;?></select></label>
 <label>De<input type="date" name="from" value="<?=arc_h($from)?>" required></label><label>Até<input type="date" name="to" value="<?=arc_h($to)?>" required></label>
 <button class="button primary">Aplicar filtros</button><a class="button" href="pages/cobrancas.php">Limpar filtros</a></form>
</dialog>
<?php if($error!==''):?><p class="arc-service-error" role="alert"><?=arc_h($error)?></p><?php else:?>
 <p><?=number_format($total,0,',','.')?> cobranças · página <?=$page?> de <?=$pages?></p>
 <div class="arc-service-list"><?php foreach($items as $item):?><article><div><strong><?=arc_h($item['name'])?> · <?=arc_h($item['amount'])?></strong><p>#<?=$item['id']?> · <?=arc_h($item['created'])?> · <?=arc_h($item['provider'])?></p><small><?=arc_h($item['state'])?><?=$item['paid']!==''?' · pago em '.arc_h($item['paid']):''?></small><?php if($item['receipt']!==''):?><details><summary>Referência enviada</summary><p><?=arc_h($item['receipt'])?></p></details><?php endif;?></div><a class="arc-icon-action" href="pages/checkout.php?invoice=<?=$item['id']?>" aria-label="Abrir cobrança <?=$item['id']?>" title="Abrir cobrança"><i class="fa fa-angle-right" aria-hidden="true"></i></a></article><?php endforeach;?><?php if(!$items):?><p>Nenhuma cobrança neste filtro.</p><?php endif;?></div>
 <nav class="arc-service-tabs" aria-label="Páginas das cobranças"><?php if($page>1):?><a href="<?=arc_h('pages/cobrancas.php?'.http_build_query($filters+['page'=>$page-1]))?>" aria-label="Página anterior"><i class="fa fa-angle-left" aria-hidden="true"></i></a><?php endif;?><?php if($page<$pages):?><a href="<?=arc_h('pages/cobrancas.php?'.http_build_query($filters+['page'=>$page+1]))?>" aria-label="Próxima página"><i class="fa fa-angle-right" aria-hidden="true"></i></a><?php endif;?></nav>
<?php endif;?>
</div></main>
<?php require dirname(__DIR__).'/includes/footer.php';?>
