<?php
/** ARQUIVO: pages/preferencias.php — Preferências globais do sistema. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__) . '/includes/bootstrap.php';
$pdo=arc_db();
header('Cache-Control: no-store, private');
if (!arc_is_user() && !arc_is_admin()) {header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
$admin=arc_is_admin();
$defs=arc_pref_defs();
$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 if(!$admin){http_response_code(403);$error='Somente administradores podem alterar as preferências globais.';}
 elseif(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??'')))$error='Sessão expirada. Atualize e tente novamente.';
 else{
  $new=[];
  foreach($defs as $key=>$def){
   $v=$def['type']==='bool'?(isset($_POST['pref_'.$key])?'1':'0'):(string)($_POST['pref_'.$key]??$def['default']);
   if($def['type']!=='bool'&&!array_key_exists($v,$def['options'])){$error='Preferência inválida: '.$def['label'];break;}
   $new[$key]=$v;
  }
  if($error==='')try{
   arc_system_prefs_save($pdo,$new);
   arc_audit($pdo,'system_preferences_updated','Preferências globais do sistema alteradas');
   header('Location: '.arc_workspace_base_url().'pages/preferencias.php?saved=1',true,303);exit;
  }catch(Throwable $e){arc_log_error('Preferências globais: '.$e->getMessage());$error='Não foi possível salvar as preferências do sistema.';}
 }
}
try{$values=arc_system_prefs($pdo);}
catch(Throwable $e){arc_log_error('Preferências globais: '.$e->getMessage());$values=[];foreach($defs as $key=>$def)$values[$key]=(string)$def['default'];}
$groups=[];foreach($defs as $key=>$def)$groups[$def['group']][$key]=$def;
$ARCMode='app';$ARCPageTitle='Preferências do sistema';$ARCActiveNav='settings';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.account-module{width:100%;max-width:none;margin:0;box-sizing:border-box}
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
<?php if(isset($_GET['saved'])):?><div class="card" role="status">Preferências globais atualizadas para todos os usuários.</div><?php endif;?>
<section class="card"><h2 style="font-size:15px;margin:0 0 12px"><i class="fa fa-cogs" aria-hidden="true"></i> Configurações globais do sistema</h2><p>Estas opções são aplicadas igualmente a clientes e administradores, em todas as sessões. <?= $admin?'':'Somente administradores podem alterar estas configurações.' ?></p></section>
<form method="post" action="pages/preferencias.php"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>">
<section class="card"><h2>Tema global da plataforma</h2><p>A aparência é administrada centralmente nas Configurações Administrativas. <?php if($admin):?><a href="admin/configuracoes.php?tab=aparencia">Gerenciar temas globais</a><?php endif;?></p></section>
<?php foreach($groups as $group=>$items):?><section class="card pref-group"><h2><?=arc_h((string)$group)?></h2><div class="pref-grid">
<?php foreach($items as $key=>$def):$v=(string)($values[$key]??$def['default']);if($def['type']==='bool'):?>
<label class="pref-switch"><input type="checkbox" name="pref_<?=arc_h($key)?>" value="1" <?=$v==='1'?'checked':''?> <?=$admin?'':'disabled'?>><span><?=arc_h($def['label'])?><?php if(!empty($def['help'])):?><small><?=arc_h($def['help'])?></small><?php endif;?></span></label>
<?php else:?><label><?=arc_h($def['label'])?><select name="pref_<?=arc_h($key)?>" <?=$admin?'':'disabled'?>><?php foreach($def['options'] as $option=>$label):?><option value="<?=arc_h((string)$option)?>" <?=$v===(string)$option?'selected':''?>><?=arc_h($label)?></option><?php endforeach;?></select></label><?php endif;endforeach;?>
</div></section><?php endforeach;?><?php if($admin):?><button class="button" type="submit"><i class="fa fa-check"></i> Salvar preferências globais</button><?php endif;?></form></div></main>
<?php require dirname(__DIR__).'/includes/footer.php';
