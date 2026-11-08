<?php
/** Caminho: /redes/permissoes.php — perfis de acesso vinculados à rede. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

$arcSectionFile='permissoes';
require __DIR__.'/_base.php';
$capabilities=arc_network_profile_capabilities();
$roles=['viewer'=>'Visualizador','operator'=>'Operador','administrator'=>'Administrador'];
$roleMax=['viewer'=>['painel','velocidade','dispositivos','mapa','relatorios','gestor_local_login'],
 'operator'=>['painel','servidor','velocidade','dispositivos','mapa','suporte','relatorios','gestor_local_login'],
 'administrator'=>['painel','servidor','velocidade','dispositivos','mapa','suporte','relatorios','liberacao','usuarios','preferencias','cadastros','permissoes','gestor_local_login']];
if($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['access_profile_save','access_profile_delete'],true)){
 if(!$isNetworkOwner){http_response_code(403);exit('Somente o gestor pode configurar perfis.');}
 if(!hash_equals($csrf,(string)($_POST['_csrf']??''))){http_response_code(403);exit('Token CSRF inválido.');}
 $id=(int)($_POST['profile_id']??0);
 try{
  if($action==='access_profile_delete'){
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE '.arc_table('network_team_members').' SET profile_id=NULL WHERE network_id=? AND account_id=? AND profile_id=?')->execute([$networkId,$account,$id]);
    $pdo->prepare('DELETE FROM '.arc_table('network_access_profiles').' WHERE id=? AND account_id=? AND network_id=?')->execute([$id,$account,$networkId]);
    $pdo->commit();
    arc_user_audit($pdo,'access_profile_deleted','Rede ID '.$networkId.' perfil '.$id.' removido');
    $_SESSION['arc_flash']='Perfil excluído. Integrantes vinculados voltaram ao perfil básico.';
  }else{
    $name=trim((string)($_POST['name']??''));$role=(string)($_POST['base_role']??'viewer');
    if(mb_strlen($name)<2||mb_strlen($name)>80||!isset($roles[$role]))throw new RuntimeException('Nome ou nível básico inválido.');
    $selected=array_keys(array_filter((array)($_POST['permissions']??[]),static fn($v)=>$v==='1'));
    $rights=array_values(array_intersect($selected,$roleMax[$role]));
    $json=json_encode($rights,JSON_UNESCAPED_UNICODE);
    if($id>0){$q=$pdo->prepare('UPDATE '.arc_table('network_access_profiles').' SET name=?,base_role=?,permissions_json=? WHERE id=? AND account_id=? AND network_id=?');$q->execute([$name,$role,$json,$id,$account,$networkId]);}
    else{$q=$pdo->prepare('INSERT INTO '.arc_table('network_access_profiles').' (account_id,network_id,name,base_role,permissions_json) VALUES (?,?,?,?,?)');$q->execute([$account,$networkId,$name,$role,$json]);}
    arc_user_audit($pdo,'access_profile_saved','Rede ID '.$networkId.' perfil '.$name.' salvo');
    $_SESSION['arc_flash']='Perfil de acesso salvo.';
  }
  $go('redes/permissoes.php?network='.$networkId);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$arcError='Não foi possível salvar o perfil: '.$e->getMessage();}
}
$profiles=[];
try{$q=$pdo->prepare('SELECT id,name,base_role,permissions_json FROM '.arc_table('network_access_profiles').' WHERE account_id=? AND network_id=? ORDER BY name');$q->execute([$account,$networkId]);$profiles=$q->fetchAll(PDO::FETCH_ASSOC);}
catch(PDOException $e){$arcError='Execute a migração _sql/migrations/20261102_rede_perfis.sql antes de utilizar perfis.';}
require __DIR__.'/_topo_config.php';
?>
<style>
.ap-main{display:grid;grid-template-columns:minmax(210px,300px) minmax(0,1fr);gap:10px;height:100%;min-height:0;color:var(--text)}.ap-box{background:var(--surface-alt);border:1px solid var(--border);border-radius:10px;padding:15px;overflow:auto;min-height:0}.ap-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:13px}.ap-head h2{font-size:14px;margin:0}.ap-row{width:100%;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:13px;text-align:left;cursor:pointer;margin-bottom:7px}.ap-row:hover,.ap-row.selected{border-color:var(--primary)}.ap-row small{display:block;color:var(--muted);padding-top:3px}.ap-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ap-form{display:grid;gap:14px}.ap-form label{font-size:12px;display:grid;gap:6px}.ap-form input[type=text],.ap-form select{background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:7px;padding:9px;min-width:0;width:100%}.ap-check{display:flex!important;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:7px;background:var(--surface)}.ap-check input{accent-color:var(--primary)}.ap-actions{display:flex;justify-content:flex-end;gap:8px}.ap-muted{color:var(--muted);font-size:12px}.ap-error{padding:12px;border:1px solid #cc5566;color:#ffacb8;border-radius:8px;margin-bottom:10px}@media(max-width:700px){.ap-main{display:flex;flex-direction:column}.ap-box{overflow:visible}.ap-grid{grid-template-columns:1fr}}
</style>
<?php if($arcError):?><div class="ap-error"><?=arc_h($arcError)?></div><?php endif;?>
<div class="ap-main"><section class="ap-box"><div class="ap-head"><h2><i class="fa fa-lock"></i> Perfis da rede</h2><button class="button" type="button" id="ap-new" <?=!$isNetworkOwner?'disabled':''?>><i class="fa fa-plus"></i> Novo</button></div><div id="ap-list">
<?php foreach($profiles as $profile):?><button class="ap-row" type="button" data-id="<?=(int)$profile['id']?>"><strong><?=arc_h((string)$profile['name'])?></strong><small><?=arc_h($roles[(string)$profile['base_role']]??'Perfil')?> · <?=(int)count(json_decode((string)$profile['permissions_json'],true)?:[])?> permissões</small></button><?php endforeach;?>
</div><?php if(!$profiles):?><p class="ap-muted">Crie um perfil para atribuir permissões específicas aos integrantes.</p><?php endif;?></section>
<section class="ap-box"><div class="ap-head"><h2>Configurar perfil</h2><span class="ap-muted">As opções não ampliam o nível básico</span></div>
<form class="ap-form" id="ap-form" method="post"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="access_profile_save"><input type="hidden" name="profile_id" id="ap-id" value="0"><div class="ap-grid"><label>Nome do perfil<input name="name" id="ap-name" type="text" maxlength="80" minlength="2" required placeholder="Ex.: Equipe de suporte" <?=!$isNetworkOwner?'disabled':''?>></label><label>Nível básico<select name="base_role" id="ap-role" <?=!$isNetworkOwner?'disabled':''?>><?php foreach($roles as $key=>$label):?><option value="<?=arc_h($key)?>"><?=arc_h($label)?></option><?php endforeach;?></select></label></div><div><strong style="font-size:13px">Permissões disponíveis</strong><p class="ap-muted">Marque os menus permitidos para este perfil. Em “Entrar no gestor bloqueado”, o integrante poderá usar o próprio e-mail e senha na tela de login do gestor quando ele estiver bloqueado. O proprietário mantém o controle total da rede.</p><div class="ap-grid" id="ap-checks">
<?php foreach($capabilities as $key=>$label):?><label class="ap-check"><input type="checkbox" name="permissions[<?=arc_h($key)?>]" value="1" data-permission="<?=arc_h($key)?>" <?=!$isNetworkOwner?'disabled':''?>> <?=arc_h($label)?></label><?php endforeach;?></div></div><div class="ap-actions"><button type="button" class="button secondary" id="ap-delete" style="display:none" <?=!$isNetworkOwner?'disabled':''?>>Excluir perfil</button><button class="button" type="submit" <?=!$isNetworkOwner?'disabled':''?>><i class="fa fa-save"></i> Salvar perfil</button></div></form>
<form id="ap-remove" method="post" hidden><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="access_profile_delete"><input type="hidden" name="profile_id" id="ap-remove-id"></form></section></div>
<script>
(()=>{'use strict';const profiles=<?=json_encode($profiles,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,max=<?=json_encode($roleMax)?>;
const form=document.getElementById('ap-form'),role=document.getElementById('ap-role'),del=document.getElementById('ap-delete');
function limits(){document.querySelectorAll('[data-permission]').forEach(x=>{const can=(max[role.value]||[]).includes(x.dataset.permission);x.disabled=!can||<?=json_encode(!$isNetworkOwner)?>;if(!can)x.checked=false;});}
function choose(id){const p=profiles.find(v=>Number(v.id)===Number(id));form.reset();document.getElementById('ap-id').value=p?p.id:0;document.getElementById('ap-name').value=p?p.name:'';role.value=p?p.base_role:'viewer';limits();(p?JSON.parse(p.permissions_json||'[]'):[]).forEach(key=>{const check=document.querySelector('[data-permission="'+CSS.escape(key)+'"]');if(check&&!check.disabled)check.checked=true});del.style.display=p?'inline-block':'none';document.querySelectorAll('.ap-row').forEach(b=>b.classList.toggle('selected',Number(b.dataset.id)===Number(id)));}
role.addEventListener('change',limits);document.querySelectorAll('.ap-row').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.id)));document.getElementById('ap-new').addEventListener('click',()=>choose(0));del.addEventListener('click',()=>{if(!confirm('Excluir este perfil? Os usuários vinculados voltarão ao perfil básico.'))return;document.getElementById('ap-remove-id').value=document.getElementById('ap-id').value;document.getElementById('ap-remove').submit()});choose(0);
})();</script>
<?php require __DIR__.'/_rodape.php'; ?>
