<?php
/* Caminho: admin/usuarios.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db(); arc_require_platform_schema($pdo);
arc_require_admin();
$csrf=arc_csrf_token();
$err='';
$avatarSettings=$pdo->query("SELECT setting_key,setting_value FROM ".arc_table('settings')." WHERE setting_key LIKE 'admin_avatar_%' OR setting_key LIKE 'admin_name_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
// Nome inicial da conta administrativa principal, sem sobrescrever alterações futuras.
if (!isset($avatarSettings['admin_name_1'])) {
    $pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value')->execute(['admin_name_1','Marco Lira','text']);
    $avatarSettings['admin_name_1']='Marco Lira';
}

function au_avatar(array $settings,int $id):string {
    $path=(string)($settings['admin_avatar_'.$id]??'');
    return preg_match('~^uploads/avatars/admin_[0-9]+_[a-f0-9]{24}\.(?:jpg|png|webp)$~',$path)?$path:'';
}
function au_upload_avatar(PDO $pdo,int $id):void {
    if(!isset($_FILES['avatar'])||($_FILES['avatar']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return;
    $f=$_FILES['avatar'];
    if($f['error']!==UPLOAD_ERR_OK||$f['size']>3145728||$f['size']<1)throw new RuntimeException('Foto inválida ou maior que 3 MB.');
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$type]??null;
    if(!$ext||!getimagesize($f['tmp_name']))throw new RuntimeException('Envie uma imagem JPG, PNG ou WEBP válida.');
    $folder=dirname(__DIR__).'/uploads/avatars';
    if(!is_dir($folder)&&!mkdir($folder,0755,true))throw new RuntimeException('Pasta de fotos indisponível.');
    $name='admin_'.$id.'_'.bin2hex(random_bytes(12)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],$folder.'/'.$name))throw new RuntimeException('Falha ao salvar a foto.');
    require_once dirname(__DIR__).'/includes/media-optimizer.php';arc_optimize_uploaded_image($folder.'/'.$name,1024);
    $path='uploads/avatars/'.$name;
    // Recupera somente a foto anterior DESTE administrador; sem varredura indiscriminada.
    $previous=$pdo->prepare('SELECT setting_value FROM '.arc_table('settings').' WHERE setting_key=? LIMIT 1');
    $previous->execute(['admin_avatar_'.$id]);$old=(string)$previous->fetchColumn();
    $q=$pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    try{$q->execute(['admin_avatar_'.$id,$path,'text']);}
    catch(Throwable $e){@unlink($folder.'/'.$name);throw $e;}
    if($old!==$path && preg_match('~^uploads/avatars/admin_'.preg_quote((string)$id,'~').'_[a-f0-9]{24}\.(?:jpg|png|webp)$~',$old)) {
        $oldPath=dirname(__DIR__).'/'.$old;
        if(is_file($oldPath)&&!is_link($oldPath))@unlink($oldPath);
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($csrf,(string)($_POST['_csrf']??''))){http_response_code(403);exit('Sessão expirada.');}
    try{
        $action=(string)($_POST['action']??'');
        $id=(int)($_POST['id']??0);
        $email=strtolower(trim((string)($_POST['email']??'')));
        $name=trim((string)($_POST['name']??''));
        $password=(string)($_POST['password']??'');
        if($action==='save'){
            if($name==='' || mb_strlen($name)>120)throw new RuntimeException('Informe o nome do usuário (até 120 caracteres).');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Informe um e-mail válido.');
            if($id===0){
                if($password==='')throw new RuntimeException('Informe a senha inicial do administrador.');
                $pdo->prepare('INSERT INTO '.arc_table('admins').' (email,password_hash) VALUES (?,?)')->execute([$email,password_hash(strlen($password)>72?base64_encode(hash('sha256',$password,true)):$password,PASSWORD_DEFAULT)]);
                $id=(int)$pdo->lastInsertId();
            }else{
                $q=$pdo->prepare('SELECT id FROM '.arc_table('admins').' WHERE id=?');$q->execute([$id]);
                if(!$q->fetchColumn())throw new RuntimeException('Administrador não encontrado.');
                if($password!==''){
                    $hash=password_hash(strlen($password)>72?base64_encode(hash('sha256',$password,true)):$password,PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE '.arc_table('admins').' SET email=?,password_hash=? WHERE id=?')->execute([$email,$hash,$id]);
                }else{
                    $pdo->prepare('UPDATE '.arc_table('admins').' SET email=? WHERE id=?')->execute([$email,$id]);
                }
                if($id===arc_admin_id())$_SESSION['arc_admin_email']=$email;
            }
            $pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['admin_name_'.$id,$name,'text']);
            if($id===arc_admin_id())$_SESSION['arc_admin_name']=$name;
            au_upload_avatar($pdo,$id);
        }elseif($action==='delete'){
            if($id===arc_admin_id())throw new RuntimeException('Você não pode excluir sua própria conta.');
            $pdo->beginTransaction();
            $count=(int)$pdo->query('SELECT COUNT(*) FROM '.arc_table('admins').' FOR UPDATE')->fetchColumn();
            if($count<=1)throw new RuntimeException('O último administrador não pode ser excluído.');
            $pdo->prepare('DELETE FROM '.arc_table('admins').' WHERE id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM '.arc_table('settings').' WHERE setting_key IN (?,?)')->execute(['admin_avatar_'.$id,'admin_name_'.$id]);
            $pdo->commit();
        }else throw new RuntimeException('Ação inválida.');
        header('Location: usuarios.php?ok=1',true,303);exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$err=$e instanceof PDOException?'Não foi possível salvar. Verifique se o e-mail já está cadastrado.':$e->getMessage();}
}
$admins=$pdo->query('SELECT id,email,created_at,updated_at FROM '.arc_table('admins').' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$audit=$pdo->query('SELECT admin_id,event_type,description,created_at FROM '.arc_table('audit_logs').' ORDER BY created_at DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
$auditBy=[];foreach($audit as $entry){$auditBy[(int)($entry['admin_id']??0)][]=$entry;}
foreach($admins as &$admin){$admin['avatar_url']=au_avatar($avatarSettings,(int)$admin['id']);$admin['display_name']=(string)($avatarSettings['admin_name_'.$admin['id']]??'Administrador');}unset($admin);

$ARCMode='app';$ARCPageTitle='Usuários administrativos';$ARCActiveNav='administrators';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.au-page{height:calc(100dvh - 145px);min-height:380px;display:flex;flex-direction:column;gap:10px}.au-toolbar{display:flex;align-items:center;gap:8px;padding:9px;background:var(--surface,#fff);border:1px solid var(--border,#dce3ee);border-radius:12px;overflow-x:auto;flex-shrink:0}.au-toolbar input{width:240px;min-width:140px;border:1px solid var(--border);border-radius:8px;padding:9px 10px;background:var(--surface,#fff);color:inherit}.au-toolbar select{border:1px solid var(--border);border-radius:8px;padding:9px 10px;background:var(--surface,#fff);color:inherit}.au-kpi{border:1px solid var(--border);border-radius:9px;padding:5px 12px;display:flex;align-items:center;gap:7px;white-space:nowrap;font-size:12px;background:var(--surface,#fff)}.au-kpi strong{font-size:16px}.au-new{margin-left:auto;white-space:nowrap;background:var(--arc-brand-accent,var(--primary,#ed7d23));color:var(--text);border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer}.au-grid{flex:1;min-height:0;overflow:auto;border:1px solid var(--border,#dce3ee);border-radius:12px;background:var(--surface,#fff)}.au-grid table{border-collapse:separate;border-spacing:0;width:100%;min-width:700px;font-size:12px}.au-grid th{position:sticky;top:0;z-index:2;background:var(--surface,#f3f6fb);border-bottom:1px solid var(--border);padding:12px;text-align:left;white-space:nowrap;cursor:pointer}.au-grid td{padding:11px 12px;border-bottom:1px solid var(--border)}.au-grid tbody tr{cursor:pointer}.au-grid tbody tr:hover{background:#5084be}.au-status{display:inline-flex;gap:7px;align-items:center}.au-dot{width:8px;height:8px;border-radius:50%;background:#22a06b}.au-muted{color:var(--muted);font-size:11px}.au-icon{width:32px;height:32px;border-radius:9px;background:var(--surface);color:var(--primary);display:inline-grid;place-items:center}.au-actions{display:flex;gap:6px}.au-btn{padding:7px 10px;border:1px solid var(--border);background:var(--surface,#fff);color:inherit;border-radius:8px;cursor:pointer}.au-danger{color:#c23747}.au-overlay{position:fixed;inset:0;background:rgba(15,27,46,.55);z-index:12000;display:none;align-items:center;justify-content:center;padding:16px}.au-overlay.open{display:flex}.au-dialog{background:var(--surface,#fff);color:inherit;width:min(880px,96vw);height:min(680px,92dvh);border-radius:16px;box-shadow:0 25px 90px #0004;display:flex;flex-direction:column;overflow:hidden}.au-head{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}.au-head h3{margin:0;font-size:17px}.au-body{padding:20px;overflow-y:auto;flex:1}.au-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.au-field{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:650}.au-field input{border:1px solid var(--border);border-radius:8px;padding:9px 10px;background:var(--surface,#fff);color:inherit;width:100%;box-sizing:border-box}.au-full{grid-column:1/-1}.au-foot{padding:13px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}.au-message{padding:10px 14px;border-radius:10px;background:var(--surface);color:var(--muted)}.au-error{background:var(--surface);color:#af2b36}.au-confirm{height:auto;max-width:420px}.au-confirm .au-body{font-size:13px;line-height:1.6}

.au-avatar{width:38px;height:38px;border-radius:10px;object-fit:cover;border:1px solid var(--border);background:var(--surface);vertical-align:middle}.au-avatar-empty{display:inline-grid;place-items:center;color:var(--muted)}.au-photo-panel{display:flex;align-items:center;gap:14px;padding:13px;border:1px solid var(--border);border-radius:12px;background:var(--surface,#f8fbff);margin-bottom:15px}.au-photo-panel .au-avatar{width:76px;height:76px;border-radius:15px;cursor:pointer}.au-photo-panel .au-avatar:hover{outline:2px solid var(--arc-brand-accent,var(--primary,#ed7d23))}.au-photo-info{font-size:12px;color:var(--muted)}.au-photo-info small{display:block;margin-top:5px}.au-tabs{display:flex;gap:8px;padding:0 18px;background:var(--surface,#f8fafc);border-bottom:1px solid var(--border)}.au-tabs button{border:0;border-bottom:3px solid transparent;background:none;padding:12px 15px;cursor:pointer;color:inherit}.au-tabs button.active{border-bottom-color:var(--arc-brand-accent,var(--primary,#ed7d23));font-weight:700}.au-pane{display:none}.au-pane.active{display:block}.au-history-controls{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}.au-history-controls input{border:1px solid var(--border);border-radius:8px;padding:9px;background:var(--surface,#fff);color:inherit}.au-history-controls select{border:1px solid var(--border);border-radius:8px;padding:9px;background:var(--surface,#fff);color:inherit}.au-history-entry{padding:10px;border-left:3px solid var(--arc-brand-accent,var(--primary,#ed7d23));background:color-mix(in srgb, var(--surface-alt) 6%, transparent);margin-bottom:9px;border-radius:7px;font-size:12px}.au-history-entry small{display:block;color:var(--muted);margin-bottom:4px}.au-note{padding:12px;border:1px solid var(--border);border-radius:10px;font-size:12px;color:var(--muted)}.au-toolbar .au-new{background:var(--arc-brand-accent,var(--primary,#ed7d23));border-color:var(--arc-brand-accent,var(--primary,#ed7d23))}.au-primary{background:var(--arc-brand-accent,var(--primary,#ed7d23));color:var(--text);border:0;border-color:var(--arc-brand-accent,var(--primary,#ed7d23))}.au-field input:focus{outline:2px solid var(--arc-brand-accent,var(--primary,#ed7d23))}@media(max-width:650px){.au-page{height:calc(100dvh - 95px)}.au-fields{grid-template-columns:1fr}.au-toolbar input{min-width:120px}.au-dialog{height:min(540px,94dvh)}}
</style>
<style>
.au-overlay .au-confirm {
 background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 10%,rgba(255,255,255,.78)) !important;
 border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 30%,rgba(255,255,255,.65)) !important;
 backdrop-filter:blur(18px) saturate(150%);-webkit-backdrop-filter:blur(18px) saturate(150%);
 box-shadow:0 14px 40px rgba(18,35,60,.15);
}
.au-message {
 background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 10%,rgba(255,255,255,.78)) !important;
 border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 30%,rgba(255,255,255,.65)) !important;
 backdrop-filter:blur(18px) saturate(150%);-webkit-backdrop-filter:blur(18px) saturate(150%);
 box-shadow:0 14px 40px rgba(18,35,60,.15);
}
.au-error {
 background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 10%,rgba(255,255,255,.78)) !important;
 border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 30%,rgba(255,255,255,.65)) !important;
 backdrop-filter:blur(18px) saturate(150%);-webkit-backdrop-filter:blur(18px) saturate(150%);
 box-shadow:0 14px 40px rgba(18,35,60,.15);
}
.arc-toast {
 background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 10%,rgba(255,255,255,.78)) !important;
 border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 30%,rgba(255,255,255,.65)) !important;
 backdrop-filter:blur(18px) saturate(150%);-webkit-backdrop-filter:blur(18px) saturate(150%);
 box-shadow:0 14px 40px rgba(18,35,60,.15);
}
.flash.arc-toast {
 background:color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 10%,rgba(255,255,255,.78)) !important;
 border:1px solid color-mix(in srgb,var(--arc-brand-accent,#ed7d23) 30%,rgba(255,255,255,.65)) !important;
 backdrop-filter:blur(18px) saturate(150%);-webkit-backdrop-filter:blur(18px) saturate(150%);
 box-shadow:0 14px 40px rgba(18,35,60,.15);
}
.arc-notice-glass{position:relative;display:flex;align-items:center;gap:10px;padding:12px 16px;margin:0 0 10px;border-radius:12px;color:inherit!important;background:color-mix(in srgb,var(--arc-brand-accent,var(--primary,#ed7d23)) 12%,rgba(255,255,255,.72))!important;border:1px solid color-mix(in srgb,var(--arc-brand-accent,var(--primary,#ed7d23)) 32%,rgba(255,255,255,.5))!important;backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);box-shadow:0 10px 30px rgba(15,30,50,.12);font-size:13px;font-weight:600}
.arc-notice-glass.is-error{border-left:4px solid #c93d52!important}
.arc-notice-glass.is-success{border-left:4px solid var(--arc-brand-accent,var(--primary,#ed7d23))!important}
.au-grid th:first-child{width:38px;text-align:center;cursor:default}
.au-grid td:first-child{width:38px;text-align:center;cursor:default}.au-grid input.au-check{accent-color:var(--arc-brand-accent,var(--primary,#ed7d23));cursor:pointer}.au-grid #auAll{accent-color:var(--arc-brand-accent,var(--primary,#ed7d23));cursor:pointer}.au-grid th[data-sort=""]{cursor:default}
</style>
<main class="app-main"><div class="page-content au-page">
<?php if($err!==''):?><div class="au-message au-error arc-notice-glass is-error" role="alert"><?=arc_h($err)?></div><?php endif;?>
<?php if(isset($_GET['ok'])):?><div class="au-message arc-notice-glass is-success">Alterações salvas com sucesso.</div><?php endif;?>
<div class="au-toolbar"><input id="auSearch" type="search" placeholder="Buscar nome ou e-mail…" aria-label="Buscar usuário"><select id="auFilter"><option value="">Todos os usuários</option><option value="mine">Minha conta</option><option value="others">Outros administradores</option></select><div class="au-kpi"><strong><?=count($admins)?></strong> Usuários</div><div class="au-kpi" style="color:#15803d"><strong><?=count($audit)?></strong> Eventos recentes</div><div class="au-kpi" style="color:#2563eb"><strong><?=max(0,count($admins)-1)?></strong> Outros usuários</div><button class="au-new" type="button" id="auCreate"><i class="fa fa-plus"></i> Novo usuário</button></div>
<div class="au-grid"><table id="auTable" class="arc-responsive-table"><thead><tr><th style="width:38px" data-sort=""><input type="checkbox" id="auAll" aria-label="Selecionar usuários visíveis"></th><th style="width:55px">Foto</th><th data-sort="name">Nome / E-mail ↕</th><th data-sort="created">Cadastro ↕</th><th data-sort="updated">Atualização ↕</th><th data-sort="activity">Atividades ↕</th><th data-sort="profile">Perfil ↕</th></tr></thead><tbody>
<?php foreach($admins as $a):$mine=(int)$a['id']===arc_admin_id();?><tr data-id="<?=(int)$a['id']?>" data-email="<?=arc_h(mb_strtolower((string)$a['email']))?>" data-name="<?=arc_h(mb_strtolower((string)$a['display_name']))?>" data-created="<?=arc_h((string)$a['created_at'])?>" data-updated="<?=arc_h((string)($a['updated_at']??''))?>" data-activity="<?=count($auditBy[(int)$a['id']]??[])?>" data-profile="Administrador" data-type="admin" data-mine="<?=$mine?'1':'0'?>"><td><input type="checkbox" class="au-check" value="<?=(int)$a['id']?>" aria-label="Selecionar usuário"></td><td data-label="Foto"><?php if($a['avatar_url']):?><img class="au-avatar" src="../<?=arc_h($a['avatar_url'])?>" alt="Foto" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-grid'"><span class="au-avatar au-avatar-empty" style="display:none"><i class="fa fa-user"></i></span><?php else:?><span class="au-avatar au-avatar-empty"><i class="fa fa-user"></i></span><?php endif;?></td><td data-label="Nome / E-mail ↕"><strong><?=arc_h((string)$a['display_name'])?></strong><div class="au-muted"><?=arc_h((string)$a['email'])?></div></td><td data-label="Cadastro ↕"><?=arc_h((string)$a['created_at'])?></td><td data-label="Atualização ↕"><?=arc_h((string)($a['updated_at']??'—'))?></td><td data-label="Atividades ↕"><strong><?=count($auditBy[(int)$a['id']]??[])?></strong> <span class="au-muted">eventos recentes</span></td><td data-label="Perfil ↕"><span class="au-status"><i class="fa fa-user-shield" aria-hidden="true"></i> Administrador</span></td></tr><?php endforeach;?></tbody></table></div>
</div></main>
<div class="au-overlay" id="auModal" role="dialog" aria-modal="true" aria-labelledby="auTitle"><div class="au-dialog">
<div class="au-head"><h3 id="auTitle">Novo usuário</h3><button class="au-btn" type="button" data-close="auModal" aria-label="✕" title="✕"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">✕</span></button></div>
<div class="au-tabs"><button type="button" class="active" data-au-tab="data">Dados</button><button type="button" data-au-tab="security">Segurança</button><button type="button" data-au-tab="history">Histórico de ações</button></div>
<form method="post" enctype="multipart/form-data" action="<?=arc_h(arc_workspace_base_url())?>admin/usuarios.php" style="display:flex;flex-direction:column;min-height:0;flex:1"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="auId" value="0">
<div class="au-body"><section class="au-pane active" data-au-pane="data"><div class="au-photo-panel"><img class="au-avatar" id="auPhoto" alt="Foto do administrador" style="display:none"><span class="au-avatar au-avatar-empty" id="auPhotoEmpty" title="Selecionar foto" style="width:76px;height:76px;cursor:pointer"><i class="fa fa-camera"></i></span><input type="file" name="avatar" id="auAvatarInput" accept="image/jpeg,image/png,image/webp" hidden><div class="au-photo-info">Clique na foto para importar ou substituir<small>JPG, PNG ou WEBP · até 3 MB</small></div></div><div class="au-fields"><label class="au-field au-full">Nome completo<input type="text" id="auName" name="name" maxlength="120" required></label><label class="au-field au-full">E-mail de acesso<input type="email" id="auEmail" name="email" maxlength="190" autocomplete="off" required></label><label class="au-field">Perfil<input value="Administrador" disabled></label><label class="au-field">Data do cadastro<input id="auCreated" disabled></label><label class="au-field">Última atualização<input id="auUpdated" disabled></label></div></section>
<section class="au-pane" data-au-pane="security"><div class="au-note">O administrador pode definir uma senha nova sem informar a anterior. A senha é armazenada de forma protegida.</div><div class="au-fields" style="margin-top:14px"><label class="au-field au-full"><span id="auPassLabel">Senha inicial</span><input type="password" id="auPassword" name="password" minlength="10" autocomplete="new-password"></label><label class="au-field au-full">Confirmar senha<input type="password" id="auConfirmPassword" autocomplete="new-password"></label></div></section>
<section class="au-pane" data-au-pane="history"><div class="au-history-controls"><input id="auHistorySearch" type="search" placeholder="Pesquisar evento"><select id="auHistoryType"><option value="">Todos os eventos</option></select><input id="auHistoryFrom" type="date" aria-label="Data inicial"><input id="auHistoryTo" type="date" aria-label="Data final"><button class="au-btn" type="button" id="auHistoryClear" aria-label="Limpar" title="Limpar"><i class="fa fa-eraser" aria-hidden="true"></i><span class="arc-action-label">Limpar</span></button></div><div id="auHistoryCount" class="au-muted"></div><div id="auHistoryList"></div></section></div>
<div class="au-foot"><button type="button" class="au-btn" data-close="auModal" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button type="submit" class="au-btn au-primary" aria-label="Salvar usuário" title="Salvar usuário"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Salvar usuário</span></button></div></form></div></div>
<div class="au-overlay" id="auConfirm" role="dialog" aria-modal="true" aria-labelledby="auConfirmTitle"><div class="au-dialog au-confirm"><div class="au-head"><h3 id="auConfirmTitle">Confirmar exclusão</h3><button class="au-btn" type="button" data-close="auConfirm" aria-label="✕" title="✕"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">✕</span></button></div><div class="au-body" id="auConfirmText"></div><form method="post" action="<?=arc_h(arc_workspace_base_url())?>admin/usuarios.php"><input type="hidden" name="_csrf" value="<?=arc_h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="auDeleteId"><div class="au-foot"><button class="au-btn" type="button" data-close="auConfirm" aria-label="Cancelar" title="Cancelar"><i class="fa fa-times" aria-hidden="true"></i><span class="arc-action-label">Cancelar</span></button><button class="au-btn au-primary" type="submit" aria-label="Confirmar exclusão" title="Confirmar exclusão"><i class="fa fa-check" aria-hidden="true"></i><span class="arc-action-label">Confirmar exclusão</span></button></div></form></div></div>
<script>
(()=>{
'use strict';
const users=<?=json_encode(array_column($admins,null,'id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const history=<?=json_encode($auditBy,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const $=id=>document.getElementById(id);
const modal=$('auModal'),confirmBox=$('auConfirm'),rows=[...document.querySelectorAll('#auTable tbody tr')];
const auAll=$('auAll');if(auAll){auAll.addEventListener('change',()=>{rows.filter(r=>!r.hidden).forEach(r=>r.querySelector('.au-check').checked=auAll.checked)});document.querySelectorAll('.au-check').forEach(ch=>ch.addEventListener('change',()=>{const visible=rows.filter(r=>!r.hidden);auAll.checked=visible.length>0&&visible.every(r=>r.querySelector('.au-check').checked)}));}
let selectedId=0;
const open=m=>m.classList.add('open');
const close=m=>m.classList.remove('open');
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>close($(b.dataset.close))));
[modal,confirmBox].forEach(m=>m.addEventListener('click',e=>{if(e.target===m)close(m)}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){close(modal);close(confirmBox)}});
function tab(name){document.querySelectorAll('[data-au-tab]').forEach(b=>b.classList.toggle('active',b.dataset.auTab===name));document.querySelectorAll('[data-au-pane]').forEach(p=>p.classList.toggle('active',p.dataset.auPane===name));}
document.querySelectorAll('[data-au-tab]').forEach(b=>b.addEventListener('click',()=>tab(b.dataset.auTab)));
function renderHistory(){
 const q=$('auHistorySearch').value.toLowerCase(),type=$('auHistoryType').value,from=$('auHistoryFrom').value,to=$('auHistoryTo').value;
 const data=(history[selectedId]||[]).filter(h=>{let date=(h.created_at||'').slice(0,10);return (!q||((h.event_type||'')+' '+(h.description||'')).toLowerCase().includes(q))&&(!type||h.event_type===type)&&(!from||date>=from)&&(!to||date<=to)});
 $('auHistoryCount').textContent=data.length+' evento(s)';$('auHistoryList').replaceChildren();
 for(const h of data){let div=document.createElement('div');div.className='au-history-entry';let time=document.createElement('small');time.textContent=h.created_at||'';let title=document.createElement('strong');title.textContent=h.event_type||'Evento';let desc=document.createElement('div');desc.textContent=h.description||'';div.append(time,title,desc);$('auHistoryList').append(div)}
 if(!data.length){let div=document.createElement('div');div.className='au-note';div.textContent='Nenhum registro encontrado para este usuário e período.';$('auHistoryList').append(div)}
}
['auHistorySearch','auHistoryType','auHistoryFrom','auHistoryTo'].forEach(id=>$(id).addEventListener('input',renderHistory));
$('auHistoryClear').onclick=()=>{['auHistorySearch','auHistoryType','auHistoryFrom','auHistoryTo'].forEach(id=>$(id).value='');renderHistory()};
function edit(id){
 selectedId=Number(id)||0;const u=users[selectedId]||{};
 $('auId').value=selectedId;$('auName').value=u.display_name||'';$('auEmail').value=u.email||'';$('auPassword').value='';$('auConfirmPassword').value='';$('auAvatarInput').value='';
 $('auCreated').value=u.created_at||'—';$('auUpdated').value=u.updated_at||'—';
 $('auTitle').textContent=selectedId?'Editar usuário administrativo':'Novo usuário administrativo';$('auPassLabel').textContent=selectedId?'Nova senha (opcional)':'Senha inicial';$('auPassword').required=!selectedId;
 $('auPhoto').style.display=u.avatar_url?'block':'none';$('auPhotoEmpty').style.display=u.avatar_url?'none':'inline-grid';if(u.avatar_url)$('auPhoto').src='../'+u.avatar_url;
 $('auHistorySearch').value='';$('auHistoryFrom').value='';$('auHistoryTo').value='';$('auHistoryType').replaceChildren(new Option('Todos os eventos',''));
 [...new Set((history[selectedId]||[]).map(h=>h.event_type))].sort().forEach(t=>$('auHistoryType').add(new Option(t,t)));
 renderHistory();tab('data');open(modal);
}
$('auCreate').addEventListener('click',()=>edit(0));
rows.forEach(r=>r.addEventListener('click',e=>{if(!e.target.closest('input, label, button, a'))edit(r.dataset.id)}));
$('auPhoto').onclick=$('auPhotoEmpty').onclick=()=>$('auAvatarInput').click();
$('auAvatarInput').onchange=e=>{const f=e.target.files?.[0];if(!f)return;if(f.size>3145728||!['image/jpeg','image/png','image/webp'].includes(f.type)){window.arcToast?.('Escolha JPG, PNG ou WEBP com até 3 MB.');e.target.value='';return;}const url=URL.createObjectURL(f);$('auPhoto').src=url;$('auPhoto').style.display='block';$('auPhotoEmpty').style.display='none';$('auPhoto').onload=()=>URL.revokeObjectURL(url)};
modal.querySelector('form').addEventListener('submit',e=>{const a=$('auPassword').value,b=$('auConfirmPassword').value;if(a!==b){e.preventDefault();tab('security');$('auConfirmPassword').setCustomValidity('As senhas não coincidem.');$('auConfirmPassword').reportValidity();return;}if(!selectedId&&!a){e.preventDefault();tab('security');$('auPassword').focus();}});
$('auConfirmPassword').addEventListener('input',()=> $('auConfirmPassword').setCustomValidity(''));
function filter(){const q=$('auSearch').value.toLowerCase(),f=$('auFilter').value;rows.forEach(r=>r.hidden=!(r.dataset.email.includes(q)||r.dataset.name.includes(q))||(f==='mine'&&r.dataset.mine!=='1')||(f==='others'&&r.dataset.mine==='1'))}
$('auSearch').addEventListener('input',filter);$('auFilter').addEventListener('change',filter);
let dir=1,last='';document.querySelectorAll('#auTable th[data-sort]').forEach(th=>th.addEventListener('click',()=>{const key=th.dataset.sort;dir=last===key?-dir:1;last=key;rows.sort((a,b)=>{let x=key==='created'?a.dataset.created:key==='updated'?a.dataset.updated:key==='activity'?a.dataset.activity:key==='profile'?a.dataset.profile:key==='name'?a.dataset.name:a.dataset.id;let y=key==='created'?b.dataset.created:key==='updated'?b.dataset.updated:key==='activity'?b.dataset.activity:key==='profile'?b.dataset.profile:key==='name'?b.dataset.name:b.dataset.id;return (key==='activity'?Number(x)-Number(y):x.localeCompare(y,'pt-BR'))*dir}).forEach(r=>r.parentNode.appendChild(r))}));
})();
</script>
<?php require dirname(__DIR__).'/includes/footer.php'; ?>
