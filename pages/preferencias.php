<?php
/** ARQUIVO: pages/preferencias.php — Preferências pessoais independentes. */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
$pdo=arc_db();
header('Cache-Control: no-store, private');
if (!arc_is_user() && !arc_is_admin()) {header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
$admin=arc_is_admin();
$ownerId=$admin?(int)arc_admin_id():(int)arc_user_id();
$ownerKey=$admin?'admin_'.$ownerId:'user_'.$ownerId;
$defs=arc_pref_defs();
$error='';$success='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST') {
 if(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??''))) $error='Sessão expirada. Atualize e tente novamente.';
 else {
  $new=[];
  foreach($defs as $key=>$def){$v=$def['type']==='bool'?(isset($_POST['pref_'.$key])?'1':'0'):(string)($_POST['pref_'.$key]??$def['default']);if($def['type']!=='bool'&&!array_key_exists($v,$def['options'])){$error='Preferência inválida: '.$def['label'];break;}$new[$key]=$v;}
  if($error==='')try{
   if($admin){$stmt=$pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach($new as $key=>$value)$stmt->execute(['admin_pref_'.$ownerId.'_'.$key,$value,'text']);arc_audit($pdo,'preferences_updated','Preferências administrativas alteradas');}
   else {arc_user_prefs_save($pdo,$ownerId,$new);arc_user_audit($pdo,'preferences_updated','Preferências atualizadas');}
   $_SESSION['arc_pref_updated']=true;header('Location: '.arc_workspace_base_url().'pages/preferencias.php?saved=1',true,303);exit;
  }catch(Throwable $e){error_log('Preferencias: '.$e->getMessage());$error='Não foi possível salvar as preferências.';}
 }
}
if($admin){$values=[];$stmt=$pdo->prepare('SELECT setting_key,setting_value FROM '.arc_table('settings').' WHERE setting_key LIKE ?');$stmt->execute(['admin_pref_'.$ownerId.'_%']);foreach($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $key=>$value){$values[substr((string)$key,strlen('admin_pref_'.$ownerId.'_'))]=$value;}}
else $values=arc_user_prefs($pdo,$ownerId,true);
$groups=[];foreach($defs as $key=>$def)$groups[$def['group']][$key]=$def;
$ARCMode='app';$ARCPageTitle='Minhas preferências';$ARCActiveNav='settings';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.account-module{width:100%;max-width:none;margin:0;padding:12px 16px;box-sizing:border-box}
.account-module .card{padding:14px 16px;margin:0 0 12px;border-radius:10px}
.account-module .pref-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 18px}
.account-module .pref-group h2{font-size:15px;margin:0 0 12px}
.account-module .pref-switch{display:flex;align-items:center;gap:9px}
.account-module .pref-switch small{display:block;opacity:.75}
.account-module .pref-appearance{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.account-module .pref-appearance select{min-width:180px}
@media(max-width:600px){.account-module{padding:10px}.account-module .card{padding:12px}}
</style>
<main class="app-main"><div class="account-module">
<?php if($error!==''):?><div class="arc-account-error" role="alert"><?=arc_h($error)?></div><?php endif;?>
<?php if(isset($_GET['saved'])):?><div class="card" role="status">Preferências salvas com sucesso.</div><?php endif;?>
<section class="card"><h2 style="font-size:15px;margin:0 0 12px"><i class="fa fa-adjust" aria-hidden="true"></i> Aparência do sistema</h2><label class="pref-appearance">Tema da interface <select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label></section>
<form method="post" action="pages/preferencias.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<?php foreach($groups as $group=>$items):?><section class="card pref-group"><h2><?=arc_h((string)$group)?></h2><div class="pref-grid">
<?php foreach($items as $key=>$def):$v=(string)($values[$key]??$def['default']);if($def['type']==='bool'):?>
<label class="pref-switch"><input type="checkbox" name="pref_<?=arc_h($key)?>" value="1" <?=$v==='1'?'checked':''?>><span><?=arc_h($def['label'])?><?php if(!empty($def['help'])):?><small><?=arc_h($def['help'])?></small><?php endif;?></span></label>
<?php else:?><label><?=arc_h($def['label'])?><select name="pref_<?=arc_h($key)?>"><?php foreach($def['options'] as $option=>$label):?><option value="<?=arc_h((string)$option)?>" <?=$v===(string)$option?'selected':''?>><?=arc_h($label)?></option><?php endforeach;?></select></label><?php endif;endforeach;?>
</div></section><?php endforeach;?><button class="button" type="submit"><i class="fa fa-check"></i> Salvar preferências</button></form></div></main>
<?php require dirname(__DIR__).'/includes/footer.php';
