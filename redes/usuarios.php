<?php
/** Caminho: /redes/usuarios.php — administração de acesso e trilha auditável da rede. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

$arcSectionFile='usuarios';
require __DIR__.'/_base.php';
$accessProfiles=[];
try { $qp=$pdo->prepare('SELECT id,name,base_role,permissions_json FROM '.arc_table('network_access_profiles').' WHERE account_id=? AND network_id=? ORDER BY name');$qp->execute([$account,$networkId]);$accessProfiles=$qp->fetchAll(PDO::FETCH_ASSOC); } catch(PDOException $e) {arc_log_error('Perfil rede: aplique migração SQL.');}
$historyQuery=$pdo->prepare('SELECT event_type,details,created_at FROM '.arc_table('account_audit').' WHERE account_id=? AND details LIKE ? AND details LIKE ? ORDER BY created_at DESC LIMIT 100');
$users=array_merge([['member_user_id'=>(int)$networkOwner['id'],'display_name'=>$networkOwner['display_name'],'email'=>$networkOwner['email'],'avatar_url'=>$networkOwner['avatar_url']??'','role'=>'owner','enabled'=>1]],$networkTeamMembers);
$roleNames=['owner'=>'Gestor','administrator'=>'Administrador','operator'=>'Operador','viewer'=>'Visualizador'];
$roleRights=[
 'owner'=>['Gestão integral da rede','Gerenciar integrantes e perfis','Alterar cadastro e preferências','Aprovar ou bloquear gestores'],
 'administrator'=>['Acessar recursos administrativos autorizados','Visualizar equipamentos e dados da rede'],
 'operator'=>['Consultar equipamentos e operar recursos permitidos'],
 'viewer'=>['Consultar informações disponíveis da rede'],
];
foreach($users as &$u){
 $u['history']=[];
 $uid=(int)$u['member_user_id'];
 $historyQuery->execute([$account,'%Rede ID '.$networkId.'%','%Membro ID '.$uid.'%']);
 $u['history']=$historyQuery->fetchAll(PDO::FETCH_ASSOC);
 $avatar=(string)($u['avatar_url']??'');
 $u['avatar_url']='';
 if(preg_match('~^uploads/avatars/[a-zA-Z0-9_.-]+\.(?:jpg|jpeg|png|webp|gif)$~',$avatar) && is_file(dirname(__DIR__).'/'.$avatar))$u['avatar_url']=rtrim(arc_workspace_base_url(),'/').'/'.$avatar;
 elseif(str_starts_with($avatar,'/') && !str_starts_with($avatar,'//'))$u['avatar_url']=$avatar;
 elseif(preg_match('~^https://[a-z0-9.-]+(?:/|$)~i',$avatar))$u['avatar_url']=$avatar;
 $u['role_label']=$roleNames[(string)$u['role']]??(string)$u['role'];
 foreach($accessProfiles as $ap){if((int)$ap['id']===(int)($u['profile_id']??0)){$u['role_label']=(string)$ap['name'];$u['permissions']=array_map(static fn($key)=>arc_network_profile_capabilities()[$key]??$key,json_decode((string)$ap['permissions_json'],true)?:[]);break;}}
 if(empty($u['permissions']))$u['permissions']=$roleRights[(string)$u['role']]??[];
}
unset($u);
require __DIR__.'/_topo_config.php';
?>
<style>
.au-page{height:100%;min-height:0;display:flex;flex-direction:column;gap:9px;color:var(--text)}
.au-toolbar{display:flex;gap:9px;align-items:center;min-height:48px;background:var(--surface-alt);border:1px solid var(--border);border-radius:9px;padding:7px 11px}
.au-toolbar h2{font-size:13px;margin:0;white-space:nowrap}.au-toolbar small{font-size:11px;color:var(--muted)}
.au-search{margin-left:auto;width:min(350px,40vw);background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 11px;font-size:12px}
.au-content{flex:1;overflow:auto;min-height:0;border:1px solid var(--border);border-radius:9px;background:var(--surface-alt);padding:10px}
.au-table{border-collapse:collapse;width:100%;font-size:12px}.au-table th,.au-table td{padding:11px 9px;border-bottom:1px solid var(--border);text-align:left}.au-table th{font-size:11px;color:var(--muted);white-space:nowrap}
.au-table tbody tr[data-user]{cursor:pointer}.au-table tbody tr[data-user]:hover{background:color-mix(in srgb,var(--primary) 8%,transparent)}
.au-person{display:flex;align-items:center;gap:10px}.au-photo{width:38px;height:38px;flex:0 0 38px;border-radius:10px;background:var(--surface);border:1px solid var(--border);display:grid;place-items:center;object-fit:cover;color:var(--primary)}
.au-chip{padding:5px 9px;border-radius:99px;background:var(--surface);border:1px solid var(--border);font-size:11px;display:inline-block}.au-ok{color:#77ddaf}.au-no{color:#f1acb8}
.au-buttons{display:flex;gap:6px;justify-content:flex-end}.au-icon{width:30px;height:30px;border:1px solid var(--border);border-radius:7px;background:var(--surface);color:var(--text);cursor:pointer}.au-icon:hover{border-color:var(--primary)}.au-icon:disabled{opacity:.4;cursor:default}
.au-modal{width:min(720px,96vw);max-height:90dvh;background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:13px;padding:0;box-shadow:0 22px 75px #0009;overflow:hidden}.au-modal::backdrop{background:#030c17cc}
.au-modal-head{display:flex;align-items:center;gap:11px;padding:13px 16px;border-bottom:1px solid var(--border)}.au-modal-head strong{font-size:15px}.au-modal-head small{display:block;color:var(--muted);font-size:11px}.au-modal-close{margin-left:auto}
.au-tabs{display:flex;border-bottom:1px solid var(--border);padding:0 13px}.au-tabs button{flex:1;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--muted);padding:12px 5px;cursor:pointer;font-size:12px}.au-tabs button[aria-selected=true]{border-bottom-color:var(--primary);color:var(--text)}
.au-pane{padding:18px;max-height:56dvh;overflow:auto}.au-pane[hidden]{display:none}.au-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.au-field{min-width:0;border:1px solid var(--border);background:var(--surface);border-radius:8px;padding:10px}.au-field small{color:var(--muted);font-size:10px;display:block;margin-bottom:5px}.au-field strong{font-size:12px;overflow-wrap:anywhere}
.au-form{display:grid;gap:12px}.au-form label{font-size:12px;display:grid;gap:5px}.au-form input,.au-form select{width:100%;min-width:0;padding:9px;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text)}.au-form footer{display:flex;justify-content:flex-end;gap:8px}
.au-event{padding:10px 0;border-bottom:1px solid var(--border);font-size:12px}.au-event small{display:block;color:var(--muted);margin:3px 0}.au-empty{font-size:12px;color:var(--muted)}.au-permissions{margin:14px 0;padding-left:20px;font-size:12px;line-height:2}
@media(max-width:640px){.au-toolbar{flex-wrap:wrap}.au-toolbar small{display:none}.au-search{order:3;flex:1 1 100%;width:100%;margin:0}.au-table th:nth-child(3),.au-table td:nth-child(3){display:none}.au-table th,.au-table td{padding:8px 4px}.au-grid{grid-template-columns:1fr}.au-person{gap:5px}.au-photo{width:30px;height:30px;flex-basis:30px}.au-modal-head{padding:9px}.au-pane{padding:12px}}
</style>
<section class="au-page" aria-label="Usuários da rede">
<header class="au-toolbar"><h2><i class="fa fa-users"></i> Usuários da rede</h2><small><?=count($users)?> cadastrado(s)</small><input class="au-search" id="au-search" type="search" placeholder="Buscar nome, e-mail, perfil ou status" aria-label="Buscar usuários"><button type="button" class="button" id="au-add" <?=!$isNetworkOwner?'disabled':''?>><i class="fa fa-plus"></i> Novo</button></header>
<div class="au-content"><table class="au-table"><thead><tr><th>Foto</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead><tbody>
<?php foreach($users as $u): $owner=(string)$u['role']==='owner'; $status=$owner||!empty($u['enabled']); ?>
<tr data-user="<?=(int)$u['member_user_id']?>" data-search="<?=arc_h(mb_strtolower((string)$u['display_name'].' '.$u['email'].' '.$u['role_label'].' '.($status?'ativo':'bloqueado'),'UTF-8'))?>" tabindex="0" role="button" aria-label="Visualizar <?=arc_h((string)$u['display_name'])?>">
<td><?php if($u['avatar_url']):?><img class="au-photo" src="<?=arc_h((string)$u['avatar_url'])?>" alt="Foto"><?php else:?><span class="au-photo"><i class="fa fa-user"></i></span><?php endif;?></td>
<td><strong><?=arc_h((string)$u['display_name'])?></strong></td><td><?=arc_h((string)$u['email'])?></td><td><span class="au-chip"><?=arc_h((string)$u['role_label'])?></span></td>
<td><span class="au-chip <?=$status?'au-ok':'au-no'?>"><?=$status?'Ativo':'Bloqueado'?></span></td>
<td><div class="au-buttons"><button type="button" class="au-icon au-view" title="Visualizar" aria-label="Visualizar"><i class="fa fa-eye"></i></button>
<?php if(!$owner && $isNetworkOwner):?><form method="post" class="au-toggle"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="team_toggle"><input type="hidden" name="member_user_id" value="<?=(int)$u['member_user_id']?>"><button type="submit" class="au-icon" aria-label="<?=$status?'Bloquear':'Desbloquear'?> acesso à rede" title="<?=$status?'Bloquear':'Desbloquear'?> acesso à rede"><i class="fa <?=$status?'fa-ban':'fa-unlock'?>"></i></button></form><?php endif;?></div></td></tr>
<?php endforeach;?>
<?php foreach($pendingTeamInvites as $invite): ?><tr data-search="<?=arc_h(mb_strtolower((string)$invite['email'].' convite pendente','UTF-8'))?>"><td><span class="au-photo"><i class="fa fa-envelope-o"></i></span></td><td>Convidado</td><td><?=arc_h((string)$invite['email'])?></td><td><?=arc_h((string)(array_column($accessProfiles,'name','id')[(int)($invite['profile_id']??0)]??($roleNames[(string)$invite['role']]??(string)$invite['role'])))?></td><td><span class="au-chip">Convite pendente</span></td><td>—</td></tr><?php endforeach;?></tbody></table>
</div></section>
<dialog id="au-detail" class="au-modal"><div class="au-modal-head"><span id="au-avatar" class="au-photo"><i class="fa fa-user"></i></span><div><strong id="au-title"></strong><small id="au-subtitle"></small></div><button class="au-icon au-modal-close" type="button" data-close aria-label="Fechar"><i class="fa fa-times"></i></button></div>
<div class="au-tabs" role="tablist"><button role="tab" type="button" data-tab="data" aria-selected="true">Dados do usuário</button><button role="tab" type="button" data-tab="history" aria-selected="false">Histórico</button><button role="tab" type="button" data-tab="role" aria-selected="false">Perfil e permissões</button></div>
<section class="au-pane" data-pane="data"><div class="au-grid" id="au-details"></div></section>
<section class="au-pane" data-pane="history"><div id="au-history"></div></section>
<section class="au-pane" data-pane="role"><p class="au-empty">O acesso é definido exclusivamente pelo perfil atribuído à rede.</p><div id="au-role-info"></div><ul class="au-permissions" id="au-permissions"></ul><form method="post" class="au-form" id="au-role-form"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="team_save"><input type="hidden" name="member_profile_id" value="0"><label>Alterar perfil de acesso<input name="member_email" id="au-role-email" type="email" readonly required><select name="member_role" id="au-role-select"><option value="viewer">Visualizador</option><option value="operator">Operador</option><option value="administrator">Administrador</option><?php foreach($accessProfiles as $ap):?><option value="<?=arc_h((string)$ap['base_role'])?>" data-profile-id="<?=(int)$ap['id']?>"><?=arc_h((string)$ap['name'])?> (perfil)</option><?php endforeach;?></select></label><footer><button class="button" type="submit">Salvar perfil</button></footer></form></section>
</dialog>
<dialog id="au-create" class="au-modal"><form method="post" class="au-form" style="padding:20px"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="team_save"><input type="hidden" name="member_profile_id" value="0"><h3 style="margin:0;font-size:15px">Adicionar usuário à rede</h3><p class="au-empty">Contas existentes são vinculadas; outros e-mails recebem convite.</p><label>E-mail<input type="email" name="member_email" required maxlength="254"></label><label>Perfil<select name="member_role"><option value="viewer">Visualizador</option><option value="operator">Operador</option><option value="administrator">Administrador</option><?php foreach($accessProfiles as $ap):?><option value="<?=arc_h((string)$ap['base_role'])?>" data-profile-id="<?=(int)$ap['id']?>"><?=arc_h((string)$ap['name'])?> (perfil)</option><?php endforeach;?></select></label><footer><button class="button secondary" type="button" data-close>Cancelar</button><button class="button" type="submit">Salvar</button></footer></form></dialog>
<script>
(function(){'use strict';
const users=<?=json_encode(array_column($users,null,'member_user_id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>;
const detail=document.getElementById('au-detail'),create=document.getElementById('au-create');let active=null;
function node(tag,cls,text){const e=document.createElement(tag);if(cls)e.className=cls;if(text!=null)e.textContent=String(text);return e}
function tab(name){detail.querySelectorAll('[data-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.tab===name)));detail.querySelectorAll('[data-pane]').forEach(p=>p.hidden=p.dataset.pane!==name)}
function field(title,value){const div=node('div','au-field');div.append(node('small','',title),node('strong','',value||'Não informado'));return div}
function view(id){const u=users[id];if(!u)return;active=u;document.getElementById('au-title').textContent=u.display_name;document.getElementById('au-subtitle').textContent=u.email;const avatar=document.getElementById('au-avatar');avatar.replaceChildren();if(u.avatar_url){const img=node('img','au-photo');img.src=u.avatar_url;img.alt='Foto';avatar.append(img)}else avatar.append(node('i','fa fa-user'));
const data=document.getElementById('au-details');data.replaceChildren(field('Nome',u.display_name),field('E-mail',u.email),field('Perfil',u.role_label),field('Status',u.enabled?'Ativo':'Bloqueado'),field('ID da conta',u.member_user_id),field('Rede',<?=json_encode((string)$network['name'],JSON_HEX_TAG|JSON_HEX_AMP)?>));
const hist=document.getElementById('au-history');hist.replaceChildren();if(!u.history.length)hist.append(node('p','au-empty','Nenhuma atividade individual identificada na auditoria disponível desta rede.'));else u.history.forEach(h=>{const e=node('div','au-event');e.append(node('strong','',h.event_type),node('small','',h.created_at),node('div','',h.details));hist.append(e)});
const perm=document.getElementById('au-permissions');perm.replaceChildren();u.permissions.forEach(p=>perm.append(node('li','',p)));document.getElementById('au-role-info').replaceChildren(field('Perfil atual',u.role_label));document.getElementById('au-role-form').hidden=u.role==='owner'||!<?=json_encode($isNetworkOwner)?>;document.getElementById('au-role-email').value=u.email;document.getElementById('au-role-select').value=u.role;const prof=Array.from(document.getElementById('au-role-select').options).find(o=>Number(o.dataset.profileId)===Number(u.profile_id));if(prof)prof.selected=true;tab('data');detail.showModal()}
document.querySelectorAll('tr[data-user]').forEach(tr=>{tr.addEventListener('click',e=>{if(e.target.closest('form,button'))return;view(tr.dataset.user)});tr.addEventListener('keydown',e=>{if(e.target===tr&&(e.key==='Enter'||e.key===' ')){e.preventDefault();view(tr.dataset.user)}});tr.querySelector('.au-view')?.addEventListener('click',()=>view(tr.dataset.user))});
document.querySelectorAll('.au-toggle').forEach(f=>f.addEventListener('submit',e=>{e.stopPropagation();if(!confirm('Confirmar a alteração do acesso deste usuário somente a esta rede?'))e.preventDefault()}));
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));detail.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>tab(b.dataset.tab)));
document.querySelectorAll('form.au-form').forEach(form=>form.addEventListener('submit',()=>{const sel=form.querySelector('select[name=member_role]'),hidden=form.querySelector('input[name=member_profile_id]');if(sel&&hidden)hidden.value=sel.selectedOptions[0]?.dataset.profileId||'0'}));document.getElementById('au-add').addEventListener('click',()=>create.showModal());document.getElementById('au-search').addEventListener('input',e=>{const q=e.target.value.toLocaleLowerCase('pt-BR');document.querySelectorAll('tr[data-search]').forEach(tr=>tr.hidden=!tr.dataset.search.includes(q))});
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
