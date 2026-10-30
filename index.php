<?php
declare(strict_types=1);
/** Arcoll: autenticação e cadastro no próprio index.php da raiz. */
if (!is_file(__DIR__ . '/includes/config.php')) {
    http_response_code(503);
    exit('Instalação não configurada.');
}
require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pdo = arc_db();
arc_ensure_user_schema($pdo);

// Quem já está autenticado não deve voltar à tela pública de login/cadastro.
$home = arc_authenticated_home($pdo);
if ($home !== null) {
    header('Location: ' . arc_workspace_base_url() . $home, true, 302);
    exit;
}

$maintenanceOn=arc_platform_setting($pdo,'platform_maintenance_enabled','0')==='1';
if($maintenanceOn && !arc_is_admin() && (string)($_GET['admin_login']??'')!=='1'){
    $mTitle=arc_platform_setting($pdo,'platform_maintenance_title','Plataforma em manutenção');
    $mText=arc_platform_setting($pdo,'platform_maintenance_message','Estamos aprimorando nossos serviços.');
    $mUntil=arc_platform_setting($pdo,'platform_maintenance_until','');
    $mImage=arc_platform_setting($pdo,'platform_maintenance_image','');
    $mColor=(string)($settingsForMaintenanceColor=arc_platform_setting($pdo,'accent_color','#2878df'));
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$mColor))$mColor='#102f49';
    if(!str_starts_with($mImage,'https://') && !preg_match('~^/[a-zA-Z0-9/_\\.-]+$~',$mImage))$mImage='';
    http_response_code(503);
    header('Retry-After: 600');
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($mTitle) ?></title>
    <style>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:linear-gradient(135deg,<?= $escape($mColor) ?>,var(--surface-alt));font-family:Inter,system-ui,sans-serif;color:var(--text);padding:20px;box-sizing:border-box}.panel{width:min(100%,550px);padding:clamp(22px,5vw,50px);text-align:center;border-radius:22px;background:color-mix(in srgb, var(--surface) 9%, transparent);border:1px solid color-mix(in srgb, var(--border) 20%, transparent);backdrop-filter:blur(18px);box-shadow:0 25px 75px #0003}.panel img{max-width:150px;max-height:120px;object-fit:contain;margin-bottom:15px}.panel h1{font-size:clamp(24px,4vw,37px)}.panel p{line-height:1.8;color:var(--text)}.countdown{display:flex;justify-content:center;gap:12px;margin:24px 0}.countdown span{background:#ffffff16;border-radius:12px;padding:13px;min-width:60px;font-size:22px;font-weight:800}.countdown small{display:block;font-size:10px;font-weight:500;margin-top:6px}.note{font-size:11px;color:var(--text)}.arc-login-left .arc-auth-panel{order:-1}.arc-login-center .arc-auth-showcase{display:none}.arc-login-center .arc-auth-page{grid-template-columns:1fr}.arc-login-center .arc-auth-panel{min-height:100dvh}.arc-login-center .arc-auth-card{max-width:450px}
</style><script src="assets/js/arcoll.js?v=2.8.1" defer></script><script>try{const c=localStorage.getItem('arc_appearance')||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head><body><label class="arc-appearance-control arc-appearance-public"><i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label><main class="panel">
    <?php if($mImage!==''):?><img src="<?= $escape($mImage) ?>" alt="Imagem de manutenção"><?php else:?><img src="api/brand-logo.php" alt="Logo da plataforma"><?php endif;?>
    <h1><?= $escape($mTitle) ?></h1><p><?= nl2br($escape($mText)) ?></p><div class="countdown" id="countdown" aria-live="off"></div><div class="note" id="maintenance-note">Acompanhe a previsão de retorno.</div><a href="index.php?admin_login=1" style="display:inline-block;margin-top:25px;color:#d4ebf8;font-size:11px">Acesso administrativo</a></main>
    <script>const until=<?= json_encode($mUntil,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const target=until?new Date(until.replace(' ','T')).getTime():0;const node=document.getElementById('countdown');function tick(){if(!target||!Number.isFinite(target)){node.hidden=true;return}const delta=Math.max(0,target-Date.now());const units=[Math.floor(delta/86400000),Math.floor(delta/3600000)%24,Math.floor(delta/60000)%60,Math.floor(delta/1000)%60];node.innerHTML=units.map((v,i)=>'<span>'+String(v).padStart(2,'0')+'<small>'+['Dias','Horas','Min','Seg'][i]+'</small></span>').join('');if(delta===0)document.getElementById('maintenance-note').textContent='A previsão foi alcançada. Aguarde a liberação do serviço.';}tick();setInterval(tick,1000);</script></body></html>
    <?php exit;
}
$settings = arc_setting_values($pdo);
$registrationEnabled = arc_platform_setting($pdo,'platform_registration_enabled','1') === '1';
$googleEnabled = arc_is_https() && arc_platform_setting($pdo,'platform_google_enabled')==='1'
 && arc_platform_setting($pdo,'platform_google_client_id')!=='' && arc_platform_setting($pdo,'platform_google_client_secret')!=='';
$googleError=(string)($_SESSION['arc_google_error']??'');unset($_SESSION['arc_google_error']);

$siteName = trim((string)($settings['site_name'] ?? 'Arcoll Speed Connection')) ?: 'Arcoll Speed Connection';
$error = '';
$mode = $registrationEnabled && ($_POST['mode'] ?? $_GET['mode'] ?? 'login') === 'register' ? 'register' : 'login';
$postedName = '';
$postedEmail = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedName = trim((string)($_POST['display_name'] ?? ''));
    $postedEmail = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $csrf = (string)($_POST['_csrf'] ?? '');
    if ($mode === 'register' && !$registrationEnabled) {
        $error='Cadastro público desativado.';
    } elseif (!hash_equals(arc_csrf_token(), $csrf)) {
        $error = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } elseif (!filter_var($postedEmail, FILTER_VALIDATE_EMAIL) || strlen($postedEmail) > 254) {
        $error = 'Informe um e-mail válido.';
    } elseif ($password === '') {
        $error = 'Informe sua senha.';
    } elseif ($mode === 'register' && (mb_strlen($postedName) < 2 || mb_strlen($postedName) > 120)) {
        $error = 'Informe seu nome (entre 2 e 120 caracteres).';
    } elseif ($mode === 'register' && (strlen($password) < 10 || false)) {
        $error = 'Use uma senha com pelo menos 10 caracteres.';
    } elseif ($mode === 'register' && !hash_equals($password, $confirm)) {
        $error = 'As senhas não conferem.';
    } else {
        try {
            $emailHash = hash('sha256', $postedEmail);
            $loginAttemptId = arc_login_attempt_begin($pdo, $postedEmail);
            if ($loginAttemptId === null) {
                http_response_code(429);
                header('Retry-After: 900');
                $error = 'Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.';
            } else {
                $passwordMaterial = base64_encode(hash('sha384', $password, true));
                if ($mode === 'register') {
                    // Cadastro público cria somente cliente. Administradores são cadastrados na área administrativa.
                    $checkAdmin = $pdo->prepare('SELECT id FROM ' . arc_table('admins') . ' WHERE email=? LIMIT 1');
                    $checkAdmin->execute([$postedEmail]);
                    if ($checkAdmin->fetchColumn()) {
                        $error = 'Não foi possível criar uma conta com esse e-mail.';
                    } else {
                        $insert = $pdo->prepare('INSERT INTO ' . arc_table('user_accounts') . ' (email,email_hash,display_name,password_hash,access_notes) VALUES (?,?,?,?,?)');
                        $insert->execute([$postedEmail, $emailHash, $postedName, password_hash($passwordMaterial, PASSWORD_DEFAULT), '']);
                        $createdUserId = (int)$pdo->lastInsertId();
                        arc_login_attempt_succeeded($pdo, $loginAttemptId);
                        session_regenerate_id(true);
                        unset($_SESSION['arc_admin_id'], $_SESSION['arc_admin_email']);
                        $_SESSION['arc_user_id'] = $createdUserId;
                        $_SESSION['arc_user_email'] = $postedEmail;
                        header('Location: ' . arc_workspace_base_url() . (arc_authenticated_home($pdo) ?? 'pages/painel.php'), true, 303);
                        exit;
                    }
                } else {
                    $adminQ = $pdo->prepare('SELECT id,email,password_hash FROM ' . arc_table('admins') . ' WHERE email=? LIMIT 1');
                    $adminQ->execute([$postedEmail]);
                    $admin = $adminQ->fetch(PDO::FETCH_ASSOC);
                    $adminHash = (string)($admin['password_hash'] ?? '');
                    $adminMaterial = strlen($password) > 72 ? base64_encode(hash('sha256', $password, true)) : $password;
                    if ($admin && $adminHash !== '' && (password_verify($adminMaterial, $adminHash) || (strlen($password) > 72 && password_verify($password, $adminHash)))) {
                        arc_login_attempt_succeeded($pdo, $loginAttemptId);
                        session_regenerate_id(true);
                        unset($_SESSION['arc_user_id'], $_SESSION['arc_user_email']);
                        $_SESSION['arc_admin_id'] = (int)$admin['id'];
                        $_SESSION['arc_admin_email'] = (string)$admin['email'];
                        header('Location: ' . arc_workspace_base_url() . (arc_authenticated_home($pdo) ?? 'pages/painel.php'), true, 303);
                        exit;
                    }
                    $userQ = $pdo->prepare('SELECT id,email,password_hash,account_status FROM ' . arc_table('user_accounts') . ' WHERE email_hash=? LIMIT 1');
                    $userQ->execute([$emailHash]);
                    $user = $userQ->fetch(PDO::FETCH_ASSOC);
                    $hash = (string)($user['password_hash'] ?? '');
                    if ($user && (string)($user['account_status'] ?? 'active') === 'active' && (password_verify($passwordMaterial, $hash) || password_verify($password, $hash))) {
                        arc_login_attempt_succeeded($pdo, $loginAttemptId);
                        session_regenerate_id(true);
                        unset($_SESSION['arc_admin_id'], $_SESSION['arc_admin_email']);
                        $_SESSION['arc_user_id'] = (int)$user['id'];
                        $_SESSION['arc_user_email'] = (string)$user['email'];
                        $pdo->prepare('UPDATE ' . arc_table('user_accounts') . ' SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$user['id']]);
                        header('Location: ' . arc_workspace_base_url() . (arc_authenticated_home($pdo) ?? 'pages/painel.php'), true, 303);
                        exit;
                    }
                    $error = 'E-mail ou senha incorretos.';
                }
            }
        } catch (PDOException | RuntimeException $exception) {
            error_log('Arcoll index auth: ' . $exception->getMessage());
            $error = $mode === 'register' && (string)$exception->getCode() === '23000'
                ? 'Já existe uma conta com este e-mail.'
                : 'Não foi possível concluir a operação. Tente novamente.';
        }
    }
}
$csrfToken = arc_csrf_token();
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1424">
<link rel="icon" type="image/png" sizes="192x192" href="api/brand-logo.php?icon=192">
<link rel="shortcut icon" href="api/brand-logo.php?icon=192">
<link rel="apple-touch-icon" sizes="192x192" href="api/brand-logo.php?icon=192">
<title><?= $escape($siteName) ?> | Acesso</title><link rel="stylesheet" href="assets/css/arcoll.css">
<link rel="stylesheet" href="assets/vendor/font-awesome/css/font-awesome.min.css">
<style>
.arc-auth-page *{box-sizing:border-box}.arc-auth-page a{color:inherit}.arc-auth-showcase:before{content:"";position:absolute;border:1px solid #68d6ff35;border-radius:50%;pointer-events:none;z-index:-1;width:620px;height:620px;right:-280px;top:10%}.arc-auth-showcase:after{content:"";position:absolute;border:1px solid #68d6ff35;border-radius:50%;pointer-events:none;z-index:-1;width:440px;height:440px;right:-120px;top:23%}
.arc-auth-brand{display:flex;align-items:center;gap:14px;font-weight:760;font-size:19px;letter-spacing:-.02em}.arc-auth-brand img{width:52px;height:52px;object-fit:contain;border-radius:13px;background:#ffffff12;padding:6px}.arc-auth-pill{display:inline-flex;width:max-content;border:1px solid #87dfff5e;border-radius:999px;padding:9px 14px;color:var(--text);font-size:11px;font-weight:750;letter-spacing:.14em;text-transform:uppercase}.arc-auth-message{max-width:600px}.arc-auth-message h1 em{font-style:normal;color:var(--primary)}.arc-auth-showcase-footer{display:flex;gap:22px;flex-wrap:wrap;color:var(--text);font-size:12px}.arc-auth-topline{color:var(--primary);font-size:12px;letter-spacing:.15em;font-weight:800;text-transform:uppercase}.arc-auth-tab{display:flex;justify-content:center;align-items:center;min-height:44px;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;color:var(--muted)!important}.arc-auth-tab[aria-current="page"]{background:var(--surface);box-shadow:0 3px 12px #12263c13;color:var(--muted)!important}
.arc-auth-field{display:block;margin:0 0 17px}.arc-auth-field>span{display:block;margin-bottom:8px;font-weight:680;font-size:13px;color:var(--text)}.arc-auth-field input{width:100%;height:49px;padding:0 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--text);outline:none;font-size:14px}.arc-auth-field input:focus{border-color:#0888b8;box-shadow:0 0 0 3px #098bc01c}.arc-auth-submit{width:100%;height:51px;border:0;border-radius:11px;background:linear-gradient(100deg,#075c91,#0797b8);color:var(--text);font-size:14px;font-weight:800;cursor:pointer;margin-top:7px}.arc-auth-submit:hover{filter:brightness(1.08)}.arc-auth-error{background:var(--surface);color:#a12e39;padding:13px 15px;border-radius:11px;border:1px solid var(--border);margin-bottom:20px;font-size:13px}.arc-auth-note{margin:22px 0 0;color:var(--muted);text-align:center;font-size:12px;line-height:1.7}.arc-auth-note strong{color:var(--muted)}
@media(max-width:940px){.arc-auth-page{grid-template-columns:1fr}.arc-auth-showcase{min-height:260px;padding:26px 30px}.arc-auth-message h1{font-size:35px;margin:15px 0}.arc-auth-message p{font-size:13px;margin:0}.arc-auth-showcase-footer{display:none}.arc-auth-panel{padding:34px 22px 65px}}@media(max-width:520px){.arc-auth-showcase{min-height:220px;padding:20px}.arc-auth-brand{font-size:16px}.arc-auth-brand img{width:42px;height:42px}.arc-auth-message h1{font-size:29px}.arc-auth-message p{display:none}.arc-auth-pill{font-size:9px}.arc-auth-panel{padding:25px 18px 50px}.arc-auth-card h2{font-size:28px}}
.arc-auth-page{min-height:100dvh;display:grid;grid-template-columns:minmax(0,.9fr) minmax(360px,.8fr);background:var(--surface);color:var(--text);font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif}
.arc-auth-showcase{position:relative;isolation:isolate;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;min-height:100dvh;padding:clamp(24px,3.5vw,52px);background:linear-gradient(140deg,var(--surface-alt) 0%,var(--surface-alt) 58%,var(--surface-alt) 100%);color:var(--text)}
.arc-auth-message h1{font-size:clamp(30px,3.6vw,54px);letter-spacing:-.055em;line-height:1.08;margin:15px 0}
.arc-auth-message p{max-width:480px;font-size:14px;line-height:1.65;color:var(--text)}
.arc-auth-panel{display:flex;align-items:center;justify-content:center;padding:22px clamp(16px,3vw,48px)}
.arc-auth-card{width:min(100%,410px)}
.arc-auth-card h2{margin:8px 0;font-size:28px;letter-spacing:-.045em;color:var(--text)}
.arc-auth-subtitle{margin:0 0 28px;color:var(--muted);line-height:1.6;font-size:14px;margin-bottom:16px}
.arc-auth-tabs{display:grid;grid-template-columns:1fr 1fr;padding:5px;gap:4px;background:var(--surface);border-radius:13px;margin-bottom:16px}
.arc-google-divider{position:relative;text-align:center;margin:15px 0 12px;color:var(--muted);font-size:11px}
.arc-google-divider:before{content:"";position:absolute;left:0;right:0;top:50%;border-top:1px solid var(--border)}
.arc-google-divider span{position:relative;background:var(--surface);padding:0 12px}
.arc-google-login{display:flex;align-items:center;justify-content:center;gap:12px;min-height:44px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text)!important;text-decoration:none!important;font-size:13px;font-weight:750}
.arc-google-login:hover{border-color:#4a9abb;background:var(--surface)}
.arc-google-login i{font-size:17px;color:var(--primary)}
@media(max-width:820px){.arc-auth-page{display:flex;flex-direction:column;min-height:100dvh}.arc-auth-showcase{min-height:auto;padding:18px 20px;gap:0}.arc-auth-showcase .arc-auth-message{display:none}.arc-auth-showcase-footer{display:none}.arc-auth-brand{font-size:15px}.arc-auth-brand img{width:37px;height:37px}.arc-auth-panel{flex:1;min-height:0;align-items:flex-start;padding:24px 18px}.arc-auth-card{width:min(100%,430px);margin:auto}.arc-auth-card h2{font-size:26px}}
@media(max-width:420px){.arc-auth-panel{padding:18px 14px}.arc-auth-card h2{font-size:24px}}
.arc-login-left .arc-auth-panel{order:-1}.arc-login-center .arc-auth-showcase{display:none}.arc-login-center .arc-auth-page{grid-template-columns:1fr}.arc-login-center .arc-auth-panel{min-height:100dvh}.arc-login-center .arc-auth-card{max-width:450px}
</style><script src="assets/js/arcoll.js?v=2.8.1" defer></script><script>try{const c=localStorage.getItem('arc_appearance')||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head>
<?php $loginLayout=arc_platform_setting($pdo,'platform_login_layout','right');if(!in_array($loginLayout,['left','center','right'],true))$loginLayout='right'; ?>
<body class="arc-login-<?= $escape($loginLayout) ?>"><label class="arc-appearance-control arc-appearance-public"><i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label><main class="arc-auth-page">
<section class="arc-auth-showcase" aria-label="Apresentação da plataforma"><div class="arc-auth-brand"><img src="api/brand-logo.php" alt="" onerror="this.style.display='none'"><span><?= $escape($siteName) ?></span></div>
<div class="arc-auth-message"><span class="arc-auth-pill">Plataforma de monitoramento</span><h1>Controle sua rede.<br><em>Tenha visão de tudo.</em></h1><p>Uma plataforma para acompanhar conexões, dispositivos, agentes e a gestão de suas redes com segurança e organização.</p></div>
<div class="arc-auth-showcase-footer"><span>Monitoramento de redes</span><span>Gestão de dispositivos</span><span>Administração centralizada</span></div></section>
<section class="arc-auth-panel"><div class="arc-auth-card"><span class="arc-auth-topline">Área de acesso</span><h2><?= $escape($mode === 'register' ? 'Crie sua conta' : arc_platform_setting($pdo,'platform_login_heading','Bem-vindo de volta')) ?></h2><p class="arc-auth-subtitle"><?= $escape($mode === 'register' ? 'Cadastre-se para começar a gerenciar suas redes.' : arc_platform_setting($pdo,'platform_login_subtitle','Entre com suas credenciais para acessar a plataforma.')) ?></p>
<nav class="arc-auth-tabs" aria-label="Autenticação"><a class="arc-auth-tab" href="index.php?mode=login" aria-current="<?= $mode === 'login' ? 'page' : 'false' ?>">Entrar</a><?php if($registrationEnabled):?><a class="arc-auth-tab" href="index.php?mode=register" aria-current="<?= $mode === 'register' ? 'page' : 'false' ?>">Criar conta</a><?php endif;?></nav>
<?php if ($googleError !== ''): ?><div class="arc-auth-error" role="alert"><?= $escape($googleError) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="arc-auth-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<form method="post" action="index.php<?= isset($_GET['admin_login']) ? '?admin_login=1' : '' ?>" autocomplete="on"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="mode" value="<?= $escape($mode) ?>">
<?php if ($mode === 'register'): ?><label class="arc-auth-field"><span>Nome completo</span><input name="display_name" type="text" maxlength="120" autocomplete="name" required value="<?= $escape($postedName) ?>" placeholder="Seu nome completo"></label><?php endif; ?>
<label class="arc-auth-field"><span>E-mail</span><input name="email" type="email" maxlength="254" autocomplete="email" required value="<?= $escape($postedEmail) ?>" placeholder="voce@empresa.com.br"></label>
<label class="arc-auth-field"><span>Senha</span><input name="password" type="password" <?= $mode === 'register' ? 'minlength="10" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required placeholder="<?= $mode === 'register' ? 'Mínimo de 10 caracteres' : 'Sua senha de acesso' ?>"></label>
<?php if ($mode === 'register'): ?><label class="arc-auth-field"><span>Confirmar senha</span><input name="confirm_password" type="password" minlength="10" autocomplete="new-password" required placeholder="Repita sua senha"></label><?php endif; ?>
<button class="arc-auth-submit" type="submit"><?= $mode === 'register' ? 'Criar minha conta' : 'Entrar na plataforma' ?></button></form>
<?php if($googleEnabled):?><div class="arc-google-divider"><span>ou continue com</span></div><a class="arc-google-login" href="google-auth.php"><i class="fa fa-google" aria-hidden="true"></i> Entrar com Google</a><?php endif;?>
<p class="arc-auth-note"><?= $mode === 'register' ? 'O cadastro público cria uma conta de cliente. Contas administrativas são gerenciadas por administradores autorizados.' : 'Acesso unificado para administradores e clientes, com permissões individuais.' ?></p>
</div></section></main></body></html>
