<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';


/* ============================================================
   pages/perfil.php — MINHA CONTA DO USUÁRIO (página do Workspace)
   Padrão: consome includes/header, sidebar e footer.
   Meu perfil: foto redonda, dados pessoais e botão
           "Alterar senha" ao lado do campo Cargo / função
           (abre modal com senha atual, nova e confirmação).
   O fuso horário é fixo (America/Belem) e definido no bootstrap.
   ============================================================ */

if (!is_file(dirname(__DIR__) . '/includes/config.php')) {
    header('Location: install.php', true, 302);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = arc_db();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/* Administradores e clientes têm sessões e tabelas independentes.
   Quando as duas sessões estiverem ativas, a sessão administrativa tem prioridade. */
if (arc_is_admin()) {
    arc_require_platform_schema($pdo);
    $adminId = (int)arc_admin_id();
    $q = $pdo->prepare('SELECT id,email,password_hash FROM '.arc_table('admins').' WHERE id=? LIMIT 1');
    $q->execute([$adminId]);
    $admin = $q->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        unset($_SESSION['arc_admin_id'], $_SESSION['arc_admin_email']);
        header('Location: '.arc_workspace_base_url().'index.php', true, 303);
        exit;
    }

    $settings = $pdo->prepare('SELECT setting_key,setting_value FROM '.arc_table('settings').' WHERE setting_key IN (?,?)');
    $settings->execute(['admin_name_'.$adminId,'admin_avatar_'.$adminId]);
    $adminSettings = $settings->fetchAll(PDO::FETCH_KEY_PAIR);
    $adminName = (string)($adminSettings['admin_name_'.$adminId] ?? 'Administrador');
    $adminAvatar = (string)($adminSettings['admin_avatar_'.$adminId] ?? '');
    $admError = '';
    $admSuccess = (string)($_SESSION['arc_profile_success'] ?? '');
    unset($_SESSION['arc_profile_success']);
    $admPasswordError = false;
    $admAction = (string)($_POST['action'] ?? '');
    $profileUrl = arc_workspace_base_url().'pages/perfil.php';
    $adminSet = static function (PDO $pdo, string $key, string $value): void {
        $stmt=$pdo->prepare('INSERT INTO '.arc_table('settings').' (setting_key,setting_value,value_type) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $stmt->execute([$key,$value,'text']);
    };

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals(arc_csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
            $admError = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            try {
                if ($admAction === 'profile_save') {
                    $name = trim((string)($_POST['display_name'] ?? ''));
                    $email = strtolower(trim((string)($_POST['email'] ?? '')));
                    if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Informe o nome (até 120 caracteres).');
                    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Informe um e-mail válido.');
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE '.arc_table('admins').' SET email=? WHERE id=?')->execute([$email,$adminId]);
                        $adminSet($pdo,'admin_name_'.$adminId,$name);
                        arc_audit($pdo,'profile_updated','Perfil administrativo atualizado');
                        $pdo->commit();
                    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
                    $_SESSION['arc_admin_email'] = $email;
                    $_SESSION['arc_admin_name'] = $name;
                    $_SESSION['arc_profile_success'] = 'Dados atualizados com sucesso.';
                    header('Location: '.$profileUrl.'#profile',true,303);exit;
                } elseif ($admAction === 'password_change') {
                    $admPasswordError = true;
                    $old=(string)($_POST['current_password']??'');
                    $new=(string)($_POST['new_password']??'');
                    $repeat=(string)($_POST['confirm_password']??'');
                    $stored=(string)$admin['password_hash'];
                    $oldMaterial = strlen($old)>72 ? base64_encode(hash('sha256',$old,true)) : $old;
                    if (!password_verify($oldMaterial,$stored)) throw new RuntimeException('Senha atual incorreta.');
                    if (strlen($new)<10 || strlen($new)>128) throw new RuntimeException('A nova senha deve ter entre 10 e 128 caracteres.');
                    if ($new!==$repeat) throw new RuntimeException('A confirmação não corresponde à nova senha.');
                    if ($new===$old) throw new RuntimeException('Escolha uma senha diferente da atual.');
                    $newMaterial = strlen($new)>72 ? base64_encode(hash('sha256',$new,true)) : $new;
                    $pdo->prepare('UPDATE '.arc_table('admins').' SET password_hash=? WHERE id=?')->execute([password_hash($newMaterial,PASSWORD_DEFAULT),$adminId]);
                    arc_audit($pdo,'password_changed','Senha administrativa alterada');
                    session_regenerate_id(true);
                    $_SESSION['arc_profile_success']='Senha alterada com sucesso.';
                    header('Location: '.$profileUrl.'#security',true,303);exit;
                } elseif ($admAction === 'avatar_upload') {
                    $file=$_FILES['avatar_file']??null;
                    if (!$file || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size']??0)<1 || $file['size']>3*1024*1024) throw new RuntimeException('Selecione uma imagem válida de até 3 MB.');
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
                    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
                    if (!$ext || !@getimagesize((string)$file['tmp_name'])) throw new RuntimeException('Formato inválido. Envie JPG, PNG ou WEBP.');
                    $dir=dirname(__DIR__).'/uploads/avatars';
                    if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta de fotos.');
                    $guard=dirname(__DIR__).'/uploads/.htaccess';
                    if (!is_file($guard)) @file_put_contents($guard,"Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|php[0-9]?)$\">\nRequire all denied\n</FilesMatch>\n");
                    $rel='uploads/avatars/admin_'.$adminId.'_'.bin2hex(random_bytes(12)).'.'.$ext;
                    if (!move_uploaded_file((string)$file['tmp_name'],dirname(__DIR__).'/'.$rel)) throw new RuntimeException('Não foi possível salvar a foto.');
                    require_once dirname(__DIR__).'/includes/media-optimizer.php';arc_optimize_uploaded_image(dirname(__DIR__).'/'.$rel,1024);
                    try { $adminSet($pdo,'admin_avatar_'.$adminId,$rel);arc_audit($pdo,'avatar_updated','Foto administrativa atualizada'); }
                    catch (Throwable $e) { @unlink(dirname(__DIR__).'/'.$rel);throw $e; }
                    if (preg_match('~^uploads/avatars/admin_'. $adminId .'_[a-f0-9]{24}\\.(jpg|png|webp)$~',$adminAvatar)) @unlink(dirname(__DIR__).'/'.$adminAvatar);
                    $_SESSION['arc_profile_success']='Foto atualizada.';
                    header('Location: '.$profileUrl.'#profile',true,303);exit;
                } elseif ($admAction === 'avatar_remove') {
                    $adminSet($pdo,'admin_avatar_'.$adminId,'');
                    arc_audit($pdo,'avatar_removed','Foto administrativa removida');
                    if (preg_match('~^uploads/avatars/admin_'. $adminId .'_[a-f0-9]{24}\\.(jpg|png|webp)$~',$adminAvatar)) @unlink(dirname(__DIR__).'/'.$adminAvatar);
                    $_SESSION['arc_profile_success']='Foto removida.';
                    header('Location: '.$profileUrl.'#profile',true,303);exit;
                }
            } catch (PDOException $e) {
                arc_log_error('Perfil admin: '.$e->getMessage());
                $admError=(string)$e->getCode()==='23000'?'E-mail já utilizado por outro administrador.':'Falha ao salvar. Verifique os dados e tente novamente.';
            } catch (Throwable $e) { $admError=$e->getMessage(); }
        }
    }
    $validAvatar=(bool)preg_match('~^uploads/avatars/admin_'.$adminId.'_[a-f0-9]{24}\\.(jpg|png|webp)$~',$adminAvatar);
    $avatarSrc=$validAvatar && is_file(dirname(__DIR__).'/'.$adminAvatar) ? arc_workspace_base_url().$adminAvatar : '';
    $ARCMode='app';$ARCPageTitle='Meu perfil';$ARCActiveNav='profile';$ARCHeaderActions='';
    require dirname(__DIR__).'/includes/header.php';
    require dirname(__DIR__).'/includes/sidebar.php';
    ?>
<style>

.arc-profile-v2{width:100%;max-width:none;margin:0}.arc-profile-v2 .card{margin:0 0 12px}.arc-profile-v2 .arc-account-tabs{gap:8px!important;padding:5px 0 12px!important}
.arc-profile-v2 .arc-account-tabs .tab-button{border:1px solid var(--border)!important;background:var(--surface,#fff)!important;padding:11px 17px!important;border-radius:9px!important}
.arc-profile-v2 .arc-account-tabs .tab-button.active{color:var(--text)!important;background:#174e83!important;border-color:#174e83!important;box-shadow:0 3px 10px #174e8326}
.arc-profile-v2 .arc-account-panels{padding-top:3px!important}
.arc-profile-v2 .card{border-radius:13px!important;padding:20px!important;box-shadow:0 4px 16px #102f5509!important}
.arc-profile-v2 .card-header h2{font-size:15px!important}
.arc-profile-v2 .profile-hero{background:linear-gradient(105deg,var(--surface),var(--surface))!important;border-color:var(--border)!important;padding:20px!important}
.arc-profile-v2 .avatar-round{border-color:var(--border)!important;box-shadow:0 0 0 3px #ccdeef;width:88px!important;height:88px!important}
.arc-profile-v2 .arc-account-facts>div{background:var(--surface)!important;border-color:var(--border)!important}
.arc-profile-v2 .arc-account-actions{gap:10px;flex-wrap:wrap}
.arc-profile-v2 .ap-success{background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:9px;padding:10px 14px;font-size:13px}
.arc-profile-v2 .form-field{align-items:end!important}
.arc-profile-v2 .form-field input{border-radius:8px!important}
@media(max-width:650px){.arc-profile-v2 .card{padding:14px!important}.arc-profile-v2 .form-field{grid-template-columns:1fr!important}.arc-profile-v2 .arc-account-facts{grid-template-columns:repeat(2,minmax(0,1fr))!important}.arc-profile-v2 .profile-hero{padding:13px!important}}
</style>
    <main class="app-main"><div class="page-content arc-account arc-profile-v2">
      <?php if ($admError!==''): ?><div class="arc-account-error" role="alert"><?=arc_h($admError)?></div><?php endif; ?>
      <?php if ($admSuccess!==''): ?><div class="ap-success" role="status"><i class="fa fa-check-circle"></i> <?=arc_h($admSuccess)?></div><?php endif; ?>
      <nav class="tab-list arc-account-tabs" role="tablist" aria-label="Navegação da conta">
        <button class="tab-button active" type="button" role="tab" data-tab="profile" id="arc-account-tab-profile" aria-controls="tab-profile" aria-selected="true"><i class="fa fa-id-card-o"></i> Meu perfil</button>
        <button class="tab-button" type="button" role="tab" data-tab="security" id="arc-account-tab-security" aria-controls="tab-security" aria-selected="false"><i class="fa fa-lock"></i> Segurança</button>
      </nav>
      <div class="arc-account-panels">
      <section class="tab-content active" id="tab-profile">
        <div class="card"><div class="card-header"><h2><i class="fa fa-id-card-o"></i> Dados pessoais</h2><span class="badge online">Conta administrativa</span></div>
          <div class="profile-hero profile-heading">
            <form method="post" enctype="multipart/form-data" action="<?=arc_h($profileUrl)?>" id="admin-avatar-form" class="display-contents">
              <input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="avatar_upload">
              <input type="file" id="admin-avatar-file" name="avatar_file" accept="image/jpeg,image/png,image/webp" hidden>
              <label class="avatar-round m-0" for="admin-avatar-file" title="Trocar foto">
                <?php if ($avatarSrc!==''): ?><img src="<?=arc_h($avatarSrc)?>" alt="Foto de perfil"><?php else: ?><?=arc_h(mb_strtoupper(mb_substr($adminName,0,1)))?><?php endif; ?>
                <span class="avatar-hint">TROCAR FOTO</span>
              </label>
            </form>
            <div class="min-w-0"><h3 class="heading-compact"><?=arc_h($adminName)?></h3><p class="text-muted text-sm m-0"><?=arc_h((string)$admin['email'])?><br>Conta administrativa · Acesso ao painel</p>
              <?php if ($avatarSrc!==''): ?><form method="post" action="<?=arc_h($profileUrl)?>" class="mt-2"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="avatar_remove"><button class="button ghost button-sm" type="submit"><i class="fa fa-trash-o"></i> Remover foto</button></form><?php endif; ?>
            </div>
          </div>
          <div class="arc-account-facts"><div><small>ID da conta</small><strong>#<?=$adminId?></strong></div><div><small>Tipo de acesso</small><strong>Administrador</strong></div><div><small>Fuso horário</small><strong>America/Belem</strong></div><div><small>Situação</small><strong>Ativa</strong></div></div>
          <form method="post" action="<?=arc_h($profileUrl)?>"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="profile_save">
            <div class="form-field"><label>Nome de exibição<input name="display_name" maxlength="120" required value="<?=arc_h($adminName)?>"></label><label>E-mail<input name="email" type="email" required maxlength="190" value="<?=arc_h((string)$admin['email'])?>"></label></div>
            <div class="arc-account-actions"><button type="button" class="button secondary" data-go-tab="security"><i class="fa fa-lock"></i> Alterar senha</button><button type="submit" class="button"><i class="fa fa-check"></i> Salvar alterações</button></div>
          </form>
        </div>
      </section>
      <section class="tab-content" id="tab-security" role="tabpanel" aria-labelledby="arc-account-tab-security">
        <div class="card"><div class="card-header"><h2><i class="fa fa-lock"></i> Segurança da conta</h2><span class="badge">Acesso protegido</span></div>
          <span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Para proteger sua conta, confirme a senha atual antes de definir uma nova senha.</p></dialog>
          <form method="post" action="<?=arc_h($profileUrl)?>" id="admin-password-form"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="password_change">
            <div class="form-field"><label>Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nova senha<input type="password" name="new_password" required minlength="10" maxlength="128" autocomplete="new-password"></label><label>Confirmar nova senha<input type="password" name="confirm_password" required minlength="10" maxlength="128" autocomplete="new-password"></label></div>
            <div class="arc-account-actions"><button type="submit" class="button"><i class="fa fa-lock"></i> Atualizar senha</button></div>
          </form>
        </div>
      </section>
      </div>
    </div></main>
    <?php $ARCPageJs=<<<'ARCJS'
<script>(function(){const tabs=[...document.querySelectorAll('.arc-account [data-tab]')];function activate(k){if(!['profile','security'].includes(k))k='profile';tabs.forEach(t=>{let on=t.dataset.tab===k;t.classList.toggle('active',on);t.setAttribute('aria-selected',on?'true':'false');});document.querySelectorAll('.arc-account .tab-content').forEach(p=>p.classList.toggle('active',p.id==='tab-'+k));}tabs.forEach(t=>t.addEventListener('click',()=>{activate(t.dataset.tab);history.replaceState(null,'','#'+t.dataset.tab);}));document.querySelectorAll('[data-go-tab]').forEach(b=>b.addEventListener('click',()=>{activate(b.dataset.goTab);history.replaceState(null,'','#'+b.dataset.goTab);}));activate(location.hash.slice(1));window.addEventListener('hashchange',()=>activate(location.hash.slice(1)));const f=document.getElementById('admin-avatar-file');f?.addEventListener('change',()=>{if(f.files?.length){if(f.files[0].size>3145728){alert('Selecione uma imagem de até 3 MB.');f.value='';return;}document.getElementById('admin-avatar-form').submit();}});})();</script>
ARCJS;
    require dirname(__DIR__).'/includes/footer.php';
    exit;
}

/* Perfil de clientes, gestores e visualizadores de redes. */
arc_ensure_user_schema($pdo);

$go = static function (string $url = 'pages/perfil.php'): never {
    header('Location: ' . (str_starts_with($url, '../') ? arc_workspace_base_url() . substr($url, 3) : (str_starts_with($url, 'pages/') ? arc_workspace_base_url() . $url : arc_workspace_base_url() . 'pages/' . ltrim($url, '/'))), true, 303);
    exit;
};

if (!arc_is_user()) {
    $_SESSION['arc_flash'] = 'Entre na sua conta para acessar sua área.';
    $go('../index.php');
}

$userId   = (int)arc_user_id();
$arcError = '';   /* erro geral (exibido no topo) */
$pwError  = '';   /* erro do modal de senha (exibido dentro do modal) */
$action   = (string)($_POST['action'] ?? '');
$csrf     = (string)($_POST['_csrf'] ?? '');
$pepper   = (string)(arc_config()['app_secret'] ?? 'arcoll');
$ipHash   = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $pepper);

/* Mesmo material de senha usado em admin/clientes.php (login/cadastro). */
$pwMaterial = static fn (string $password): string => base64_encode(hash('sha384', $password, true));



/* ============================================================
   1. AÇÕES POST
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($csrf === '' || !hash_equals(arc_csrf_token(), $csrf)) {
        $arcError = 'Sessão expirada. Atualize a página e tente novamente.';

    } elseif ($action === 'profile_save') {
        $name    = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 300);
        $email   = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone   = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40);
        $company = mb_substr(trim((string)($_POST['company_name'] ?? '')), 0, 160);
        $job     = mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 120);

        if ($name === '') {
            $arcError = 'Informe seu nome de exibição.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            $arcError = 'Informe um e-mail válido.';
        } else {
            try {
                $pdo->prepare(
                    'UPDATE ' . arc_table('user_accounts') .
                    ' SET display_name=?,email=?,email_hash=?,phone=?,company_name=?,job_title=?,timezone=? WHERE id=?'
                )->execute([$name, $email, hash('sha256', $email), $phone, $company, $job, ARC_TIMEZONE, $userId]);

                $_SESSION['arc_user_email'] = $email;
                arc_user_audit($pdo, 'profile_updated', 'Perfil atualizado');
                $_SESSION['arc_flash'] = 'Perfil atualizado com sucesso.';
                $go('pages/perfil.php#profile');
            } catch (PDOException $e) {
                $arcError = (string)$e->getCode() === '23000'
                    ? 'Este e-mail já está em uso por outra conta.'
                    : 'Não foi possível atualizar o perfil.';
            }
        }

    } elseif ($action === 'avatar_upload') {
        $file = $_FILES['avatar_file'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $arcError = 'Selecione um arquivo de imagem para enviar (máx. 4 MB).';
        } elseif ((int)$file['size'] > 4 * 1024 * 1024) {
            $arcError = 'A imagem deve ter no máximo 4 MB.';
        } else {
            /* Valida o conteúdo real do arquivo, não só a extensão. */
            $info    = @getimagesize((string)$file['tmp_name']);
            $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mime    = is_array($info) ? (string)($info['mime'] ?? '') : '';

            if (!isset($mimeExt[$mime])) {
                $arcError = 'Formato inválido. Use JPG, PNG, WEBP ou GIF.';
            } else {
                $dir = dirname(__DIR__) . '/uploads/avatars';
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $arcError = 'Não foi possível criar a pasta de fotos.';
                } else {
                    /* Impede execução de scripts dentro de /uploads. */
                    $guard = dirname(__DIR__) . '/uploads/.htaccess';
                    if (!is_file($guard)) {
                        @file_put_contents($guard, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|php[0-9]?)$\">\n  Require all denied\n</FilesMatch>\n");
                    }

                    /* Foto anterior: excluir somente após salvar a nova. */
                    $cur = $pdo->prepare('SELECT avatar_url FROM ' . arc_table('user_accounts') . ' WHERE id=?');
                    $cur->execute([$userId]);
                    $old = (string)$cur->fetchColumn();

                    $rel = 'uploads/avatars/u' . $userId . '-' . bin2hex(random_bytes(4)) . '.' . $mimeExt[$mime];
                    if (!move_uploaded_file((string)$file['tmp_name'], dirname(__DIR__) . '/' . $rel)) {
                        $arcError = 'Falha ao salvar a imagem. Verifique as permissões da pasta uploads.';
                    } else {
                        require_once dirname(__DIR__).'/includes/media-optimizer.php';arc_optimize_uploaded_image(dirname(__DIR__).'/'.$rel,1024);
                        $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET avatar_url=? WHERE id=?')->execute([$rel, $userId]);
                        if ($old !== '' && preg_match('~^uploads/avatars/u' . $userId . '-[a-f0-9]{8}\.(jpg|png|webp|gif)$~', $old)) @unlink(dirname(__DIR__) . '/' . $old);
                        arc_user_audit($pdo, 'avatar_updated', 'Foto de perfil alterada');
                        $_SESSION['arc_flash'] = 'Foto de perfil atualizada.';
                        $go('pages/perfil.php#profile');
                    }
                }
            }
        }

    } elseif ($action === 'avatar_remove') {
        $cur = $pdo->prepare('SELECT avatar_url FROM ' . arc_table('user_accounts') . ' WHERE id=?');
        $cur->execute([$userId]);
        $old = (string)$cur->fetchColumn();
        if ($old !== '' && preg_match('~^uploads/avatars/u' . $userId . '-[a-f0-9]{8}\.(jpg|png|webp|gif)$~', $old) && is_file(dirname(__DIR__) . '/' . $old)) {
            @unlink(dirname(__DIR__) . '/' . $old);
        }
        $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET avatar_url=\'\' WHERE id=?')->execute([$userId]);
        arc_user_audit($pdo, 'avatar_removed', 'Foto de perfil removida');
        $_SESSION['arc_flash'] = 'Foto de perfil removida.';
        $go('pages/perfil.php#profile');

    } elseif ($action === 'password_change') {
        $current = (string)($_POST['current_password'] ?? '');
        $next    = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $idHash  = hash_hmac('sha256', 'pwchange:' . $userId, $pepper);

        $tries = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . arc_table('account_login_attempts') .
            ' WHERE identifier_hash=? AND attempted_at >= (CURRENT_TIMESTAMP - INTERVAL 15 MINUTE)'
        );
        $tries->execute([$idHash]);

        if ((int)$tries->fetchColumn() >= 6) {
            $pwError = 'Muitas tentativas. Aguarde 15 minutos e tente novamente.';
        } elseif ($current === '') {
            $pwError = 'Informe a senha atual.';
        } elseif (mb_strlen($next) < 8) {
            $pwError = 'A nova senha deve ter pelo menos 8 caracteres.';
        } elseif ($next !== $confirm) {
            $pwError = 'A confirmação da nova senha não confere.';
        } elseif ($next === $current) {
            $pwError = 'A nova senha deve ser diferente da senha atual.';
        } else {
            $row = $pdo->prepare('SELECT password_hash FROM ' . arc_table('user_accounts') . ' WHERE id=?');
            $row->execute([$userId]);
            $stored = (string)$row->fetchColumn();

            if (!(password_verify($pwMaterial($current), $stored) || password_verify($current, $stored))) {
                $pdo->prepare('INSERT INTO ' . arc_table('account_login_attempts') . ' (ip_hash,identifier_hash) VALUES (?,?)')
                    ->execute([$ipHash, $idHash]);
                $pwError = 'A senha atual está incorreta.';
            } else {
                $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET password_hash=? WHERE id=?')
                    ->execute([password_hash($pwMaterial($next), PASSWORD_DEFAULT), $userId]);
                $pdo->prepare('DELETE FROM ' . arc_table('account_login_attempts') . ' WHERE identifier_hash=?')->execute([$idHash]);
                session_regenerate_id(true);
                arc_user_audit($pdo, 'password_changed', 'Senha alterada');
                $_SESSION['arc_flash'] = 'Senha alterada com sucesso.';
                $go('pages/perfil.php#profile');
            }
        }


    }
}

/* ============================================================
   2. CARREGAMENTO DO USUÁRIO, PREFERÊNCIAS E ATIVIDADE
   ============================================================ */

$u = $pdo->prepare('SELECT * FROM ' . arc_table('user_accounts') . ' WHERE id=?');
$u->execute([$userId]);
$user = $u->fetch();

if (!$user) {
    unset($_SESSION['arc_user_id']);
    $go('../index.php');
}

if ((string)($user['timezone'] ?? '') !== ARC_TIMEZONE) {
    $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET timezone=? WHERE id=?')->execute([ARC_TIMEZONE, $userId]);
    $user['timezone'] = ARC_TIMEZONE;
}



$initial = mb_strtoupper(mb_substr(trim((string)($user['display_name'] ?: $user['email'])), 0, 1));

$avatarUrl = trim((string)$user['avatar_url']);
$avatarSrc = '';
if ($avatarUrl !== '' && preg_match('~^uploads/avatars/[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|gif)$~', $avatarUrl) && is_file(dirname(__DIR__) . '/' . $avatarUrl)) {
    $avatarSrc = arc_workspace_base_url() . $avatarUrl . '?v=' . filemtime(dirname(__DIR__) . '/' . $avatarUrl);
}



$roleLabels=['admin'=>'Administrador','manager'=>'Gestor','user'=>'Usuário','viewer'=>'Visualizador'];
$roleLabel=$roleLabels[(string)($user['access_role']??'user')]??ucfirst((string)($user['access_role']??'user'));
$statusLabel=['active'=>'Ativa','pending'=>'Pendente','suspended'=>'Suspensa'][(string)($user['account_status']??'active')]??'Restrita';
$filled=0;foreach(['display_name','email','phone','company_name','job_title','avatar_url'] as $field){if(trim((string)($user[$field]??''))!=='')$filled++;}
$profilePercent=(int)round($filled/6*100);


/* ============================================================
   3. SAÍDA — WORKSPACE PADRÃO
   ============================================================ */

$ARCMode          = 'app';
$ARCPageTitle     = 'Meu perfil';
$ARCActiveNav     = 'profile';
$ARCHeaderActions = '';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
?>

<style>

.arc-profile-v2{width:100%;max-width:none;margin:0}.arc-profile-v2 .card{margin:0 0 12px}.arc-profile-v2 .arc-account-tabs{gap:8px!important;padding:5px 0 12px!important}
.arc-profile-v2 .arc-account-tabs .tab-button{border:1px solid var(--border)!important;background:var(--surface,#fff)!important;padding:11px 17px!important;border-radius:9px!important}
.arc-profile-v2 .arc-account-tabs .tab-button.active{color:var(--text)!important;background:#174e83!important;border-color:#174e83!important;box-shadow:0 3px 10px #174e8326}
.arc-profile-v2 .arc-account-panels{padding-top:3px!important}
.arc-profile-v2 .card{border-radius:13px!important;padding:20px!important;box-shadow:0 4px 16px #102f5509!important}
.arc-profile-v2 .card-header h2{font-size:15px!important}
.arc-profile-v2 .profile-hero{background:linear-gradient(105deg,var(--surface),var(--surface))!important;border-color:var(--border)!important;padding:20px!important}
.arc-profile-v2 .avatar-round{border-color:var(--border)!important;box-shadow:0 0 0 3px #ccdeef;width:88px!important;height:88px!important}
.arc-profile-v2 .arc-account-facts>div{background:var(--surface)!important;border-color:var(--border)!important}
.arc-profile-v2 .arc-account-actions{gap:10px;flex-wrap:wrap}
.arc-profile-v2 .ap-success{background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:9px;padding:10px 14px;font-size:13px}
.arc-profile-v2 .form-field{align-items:end!important}
.arc-profile-v2 .form-field input{border-radius:8px!important}
@media(max-width:650px){.arc-profile-v2 .card{padding:14px!important}.arc-profile-v2 .form-field{grid-template-columns:1fr!important}.arc-profile-v2 .arc-account-facts{grid-template-columns:repeat(2,minmax(0,1fr))!important}.arc-profile-v2 .profile-hero{padding:13px!important}}
</style>
<main class="app-main">
    <div class="page-content arc-account arc-profile-v2">
<?php if($arcError!==''): ?><div class="arc-account-error" role="alert"><?= arc_h($arcError) ?></div><?php endif; ?>
        <div class="arc-account-panels">

        <section class="tab-content active" id="tab-profile">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa fa-id-card-o" aria-hidden="true"></i> Dados pessoais</h2>
                    <span class="badge online"><?= arc_h($statusLabel) ?></span>
                </div>

                <div class="profile-hero profile-heading">
                    <form method="post" enctype="multipart/form-data" id="avatar-form" class="display-contents" action="<?=arc_h(arc_workspace_base_url())?>pages/perfil.php">
                        <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                        <input type="hidden" name="action" value="avatar_upload">
                        <input type="file" name="avatar_file" id="avatar-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">

                        <label class="avatar-round m-0" for="avatar-file" title="Clique para trocar a foto">
                            <?php if ($avatarSrc !== ''): ?>
                                <img src="<?= arc_h($avatarSrc) ?>" alt="Foto de perfil">
                            <?php else: ?>
                                <?= arc_h($initial) ?>
                            <?php endif; ?>
                            <span class="avatar-hint">TROCAR FOTO</span>
                        </label>
                    </form>

                    <div class="min-w-0">
                        <h3 class="heading-compact"><?= arc_h((string)($user['display_name'] ?: $user['email'])) ?></h3>
                        <p class="text-muted text-sm m-0">
                            <?= arc_h((string)$user['email']) ?><br>
                            Fuso horário: America/Belem · UTC-03:00<br>
                            Conta criada em <?= arc_h(arc_when($user['created_at'] ?? null)) ?>
                        </p>
                        <?php if ($avatarSrc !== ''): ?>
                            <form method="post" class="mt-2" action="<?=arc_h(arc_workspace_base_url())?>pages/perfil.php">
                                <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                                <input type="hidden" name="action" value="avatar_remove">
                                <button class="button ghost button-sm" type="submit" aria-label="Remover foto" title="Remover foto"><i class="fa fa-trash-o" aria-hidden="true"></i><span class="arc-action-label">Remover foto</span></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="arc-account-facts"><div><small>ID da conta</small><strong>#<?= (int)$user['id'] ?></strong></div><div><small>Última atualização</small><strong><?= arc_h(arc_when($user['updated_at']??null)) ?></strong></div><div><small>Fuso horário</small><strong><?= arc_h((string)$user['timezone']) ?></strong></div><div><small>Notificações por e-mail</small><strong><?= (int)($user['notifications_email']??0)===1?'Ativadas':'Desativadas' ?></strong></div></div>
                <form method="post" id="profile-form" action="<?=arc_h(arc_workspace_base_url())?>pages/perfil.php" >
                    <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                    <input type="hidden" name="action" value="profile_save">

                    <div class="form-field">
                        <label>Nome de exibição<input name="display_name" maxlength="300" value="<?= arc_h((string)$user['display_name']) ?>"></label>
                        <label>E-mail<input type="email" name="email" maxlength="254" value="<?= arc_h((string)$user['email']) ?>" required></label>
                        <label>Telefone<input name="phone" maxlength="40" inputmode="tel" value="<?= arc_h((string)$user['phone']) ?>"></label>
                        <label>Empresa<input name="company_name" maxlength="160" value="<?= arc_h((string)$user['company_name']) ?>"></label>

                        <!-- Cargo / função + botão Alterar senha ao lado -->
                        <div class="span2 field-with-action">
                            <label>Cargo / função<input name="job_title" maxlength="120" value="<?= arc_h((string)$user['job_title']) ?>"></label>
                            <button class="button secondary" type="button" id="btn-open-password"><i class="fa fa-lock" aria-hidden="true"></i> Alterar senha</button>
                        </div>
                    </div>

                    <div class="arc-account-actions"><button class="button" type="submit"><i class="fa fa-check" aria-hidden="true"></i> Salvar alterações</button></div>
                </form>
            </div>
        </section>

        </div>
    </div>
</main>

<!-- ==================== MODAL: ALTERAR SENHA ==================== -->
<div class="modal-overlay" id="modal-password" role="dialog" aria-modal="true" aria-labelledby="pw-title">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 id="pw-title">Alterar senha<small>Informe a senha atual e defina a nova</small></h2>
            <button class="modal-dismiss" type="button" data-close-modal aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="modal-content">
            <form method="post" id="password-form" autocomplete="off" action="<?=arc_h(arc_workspace_base_url())?>pages/perfil.php" >
                <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                <input type="hidden" name="action" value="password_change">

                <label>Senha atual
                    <input type="password" name="current_password" id="pw-current" autocomplete="current-password" required>
                </label>
                <label>Nova senha
                    <input type="password" name="new_password" id="pw-new" minlength="8" autocomplete="new-password" required>
                </label>
                <label>Confirmar nova senha
                    <input type="password" name="confirm_password" id="pw-confirm" minlength="8" autocomplete="new-password" required>
                </label>

                <label class="inline-flex my-2">
                    <input type="checkbox" id="pw-show"> Mostrar senhas
                </label>

                <p class="form-error" id="pw-error" role="alert"><?= arc_h($pwError) ?></p>

                <button class="button full" type="submit" aria-label="Atualizar senha" title="Atualizar senha"><i class="fa fa-arrow-right" aria-hidden="true"></i><span class="arc-action-label">Atualizar senha</span></button>
            </form>
        </div>
    </div>
</div>

<?php
$openPasswordModal = $pwError !== '' ? 'true' : 'false';
$resetSidebar      = !empty($_SESSION['arc_reset_sidebar']) ? 'true' : 'false';
unset($_SESSION['arc_reset_sidebar']);

$ARCPageJs = <<<ARCJS
<script>
(function () {
    'use strict';

    /* ---------- Modal de senha ---------- */
    var modal = document.getElementById('modal-password');
    var openBtn = document.getElementById('btn-open-password');
    var pwForm = document.getElementById('password-form');
    var pwError = document.getElementById('pw-error');
    var pwCurrent = document.getElementById('pw-current');

    function openPasswordModal() {
        modal.classList.add('open');
        setTimeout(function () { pwCurrent.focus(); }, 50);
    }
    function closePasswordModal() { modal.classList.remove('open'); }

    if (openBtn) { openBtn.addEventListener('click', openPasswordModal); }

    modal.querySelectorAll('[data-close-modal]').forEach(function (b) { b.addEventListener('click', closePasswordModal); });
    modal.addEventListener('click', function (e) { if (e.target === modal) { closePasswordModal(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePasswordModal(); } });

    document.getElementById('pw-show').addEventListener('change', function () {
        var type = this.checked ? 'text' : 'password';
        ['pw-current', 'pw-new', 'pw-confirm'].forEach(function (id) { document.getElementById(id).type = type; });
    });

    pwForm.addEventListener('submit', function (e) {
        var next = document.getElementById('pw-new').value;
        var conf = document.getElementById('pw-confirm').value;
        var msg = '';
        if (next.length < 8) { msg = 'A nova senha deve ter pelo menos 8 caracteres.'; }
        else if (next !== conf) { msg = 'A confirmação da nova senha não confere.'; }
        else if (next === pwCurrent.value) { msg = 'A nova senha deve ser diferente da senha atual.'; }
        if (msg) { e.preventDefault(); pwError.textContent = msg; }
    });

    if ($openPasswordModal) { openPasswordModal(); }

    /* ---------- Foto redonda: escolher o arquivo já envia ---------- */
    var fileInput = document.getElementById('avatar-file');
    fileInput.addEventListener('change', function () {
        if (!fileInput.files || fileInput.files.length === 0) { return; }
        if (fileInput.files[0].size > 4 * 1024 * 1024) { fileInput.setCustomValidity('A imagem deve ter no máximo 4 MB.'); fileInput.reportValidity(); fileInput.value = ''; return; }
        fileInput.setCustomValidity(''); document.getElementById('avatar-form').submit();
    });


})();
</script>
ARCJS;

require dirname(__DIR__) . '/includes/footer.php';
