<?php
/** ARQUIVO: pages/atividades.php — Histórico independente do usuário. */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();header('Cache-Control: no-store, private');
if(!arc_is_admin()&&!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
$isAdmin=arc_is_admin();$id=$isAdmin?(int)arc_admin_id():(int)arc_user_id();
$events=[];$error='';$page=max(1,min(10000,(int)($_GET['p']??1)));$limit=30;$offset=($page-1)*$limit;
try {
 if($isAdmin){
  // A auditoria administrativa segue seu esquema próprio; não misturar com eventos de outras contas.
  $stmt=$pdo->prepare('SELECT event_type,description AS details,created_at FROM '.arc_table('audit_logs').' WHERE admin_id=? ORDER BY id DESC LIMIT 30 OFFSET '.(int)$offset);
  $stmt->execute([$id]);$events=$stmt->fetchAll(PDO::FETCH_ASSOC);
 }else{
  $stmt=$pdo->prepare('SELECT event_type,details,created_at FROM '.arc_table('account_audit').' WHERE account_id=? ORDER BY created_at DESC,id DESC LIMIT 30 OFFSET '.(int)$offset);
  $stmt->execute([$id]);$events=$stmt->fetchAll(PDO::FETCH_ASSOC);
 }
}catch(Throwable $e){error_log('Atividades conta: '.$e->getMessage());$error='Não foi possível carregar os eventos desta conta.';}
$labels=['account_login'=>'Login realizado','account_logout'=>'Saída da conta','account_registered'=>'Conta criada','profile_updated'=>'Perfil atualizado','avatar_updated'=>'Foto atualizada','avatar_removed'=>'Foto removida','password_changed'=>'Senha alterada','preferences_updated'=>'Preferências atualizadas','network_created'=>'Rede criada','network_updated'=>'Rede atualizada','network_deleted'=>'Rede removida'];
$ARCMode='app';$ARCPageTitle='Minhas atividades';$ARCActiveNav='activities';$ARCHeaderActions='';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.account-module{width:100%;max-width:none;margin:0;padding:12px 16px;box-sizing:border-box}
.account-log{border:1px solid var(--border);border-radius:10px;padding:12px 15px;margin-bottom:8px;background:var(--surface)}
.account-log small{display:block;margin-top:4px;opacity:.8}
.account-pages{display:flex;gap:8px;margin-top:12px}
@media(max-width:600px){.account-module{padding:10px}}
</style>
<main class="app-main"><div class="account-module">
<?php if($error!==''):?><div class="arc-account-error" role="alert"><?=arc_h($error)?></div><?php endif;?>
<?php if(!$events):?><div class="card" style="padding:26px">Nenhum evento encontrado nesta página.</div><?php else:foreach($events as $event):$type=(string)($event['event_type']??'');[$activityTitle,$activityDetail]=arc_notice_copy($type);?><article class="account-log"><strong><?=arc_h($activityTitle)?></strong><small><?=arc_h($activityDetail)?></small><small><?=arc_h(arc_when($event['created_at']??null))?></small></article><?php endforeach;endif;?>
<div class="account-pages"><?php if($page>1):?><a class="button secondary" href="pages/atividades.php?p=<?=$page-1?>" aria-label="Anterior" title="Anterior"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="arc-action-label">Anterior</span></a><?php endif;?><?php if(count($events)===$limit):?><a class="button secondary" href="pages/atividades.php?p=<?=$page+1?>" aria-label="Próxima página" title="Próxima página"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="arc-action-label">Próxima página</span></a><?php endif;?></div></div></main>
<?php require dirname(__DIR__).'/includes/footer.php';
