<?php
/* Caminho: index.php */
declare(strict_types=1);
/** Caminho: index.php — autenticação pública. */
if (!is_file(__DIR__ . '/includes/config.php')) {
    http_response_code(503);
    exit('Instalação não configurada.');
}
require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pdo = arc_db();
$arcVisualTheme=arc_visual_theme($pdo);
$arcVisualMode=$arcVisualTheme['mode'];
$arcEffectiveTheme=$arcVisualMode==='theme'?($arcVisualTheme['light']?'light':'dark'):$arcVisualMode;
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
    $mContact=implode(' · ',array_filter([
        arc_platform_setting($pdo,'platform_support_email',''),
        arc_platform_setting($pdo,'platform_support_phone',''),
        arc_platform_setting($pdo,'platform_support_hours',''),
    ],static fn(string $v):bool=>$v!==''));
    $mColor=(string)($settingsForMaintenanceColor=arc_platform_setting($pdo,'accent_color','#2878df'));
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$mColor))$mColor='#102f49';
    if(!str_starts_with($mImage,'https://') && !preg_match('~^/[a-zA-Z0-9/_\\.-]+$~',$mImage))$mImage='';
    http_response_code(503);
    header('Retry-After: 600');
    ?>
    <!doctype html><html lang="pt-BR" data-arc-design="<?= $escape($arcVisualTheme['id']) ?>" data-arc-system-theme="<?= $escape($arcEffectiveTheme) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($mTitle) ?></title>
    <style>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:linear-gradient(135deg,<?= $escape($mColor) ?>,var(--surface-alt));font-family:Inter,system-ui,sans-serif;color:var(--text);padding:20px;box-sizing:border-box}.panel{width:min(100%,550px);padding:clamp(22px,5vw,50px);text-align:center;border-radius:22px;background:color-mix(in srgb, var(--surface) 9%, transparent);border:1px solid color-mix(in srgb, var(--border) 20%, transparent);backdrop-filter:blur(18px);box-shadow:0 25px 75px #0003}.panel img{max-width:150px;max-height:120px;object-fit:contain;margin-bottom:15px}.panel h1{font-size:clamp(24px,4vw,37px)}.panel p{line-height:1.8;color:var(--text)}.countdown{display:flex;justify-content:center;gap:12px;margin:24px 0}.countdown span{background:#ffffff16;border-radius:12px;padding:13px;min-width:60px;font-size:22px;font-weight:800}.countdown small{display:block;font-size:10px;font-weight:500;margin-top:6px}.note{font-size:11px;color:var(--text)}.arc-login-left .arc-auth-panel{order:-1}.arc-login-center .arc-auth-showcase{display:none}.arc-login-center .arc-auth-page{grid-template-columns:1fr}.arc-login-center .arc-auth-panel{min-height:100dvh}.arc-login-center .arc-auth-card{max-width:450px}

/* Identidade e composição sem sobrescrever as preferências do administrador */
.arc-auth-page{width:100%;max-width:none;grid-template-columns:minmax(0,1.12fr) minmax(380px,.88fr)}
.arc-auth-showcase{padding:clamp(32px,6vw,88px);isolation:isolate;background:linear-gradient(155deg,var(--surface-alt),var(--surface));min-height:100dvh}
.arc-auth-showcase::after{content:"";position:absolute;inset:auto -12% -40% 25%;height:80%;background:radial-gradient(ellipse,color-mix(in srgb,var(--primary) 16%,transparent),transparent 70%);pointer-events:none;z-index:-1}
.arc-auth-message{margin:auto 0;max-width:630px}.arc-auth-pill{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--primary) 35%,var(--border));background:color-mix(in srgb,var(--primary) 9%,transparent);border-radius:999px;padding:8px 12px;margin-bottom:18px}
.arc-auth-message h1{font-size:clamp(36px,4vw,66px);line-height:1.04;margin:0 0 22px}.arc-auth-message p{font-size:clamp(13px,1.1vw,16px);max-width:480px}
.arc-auth-showcase-footer{gap:14px 22px}.arc-auth-showcase-footer span{display:inline-flex;align-items:center;gap:8px}
.arc-auth-panel{padding:clamp(20px,4vw,65px)}.arc-auth-card{max-width:460px;width:100%;padding:clamp(23px,3vw,42px);border-radius:24px;box-shadow:0 26px 90px #00000013}
.arc-auth-brand{display:flex;justify-content:flex-start;gap:12px;margin-bottom:28px;line-height:1.3}.arc-auth-brand img{width:42px;height:42px;border-radius:12px}
.arc-auth-card h2{justify-self: center;font-size:clamp(25px,2.4vw,34px);font-weight:800}.arc-auth-subtitle{justify-self: center;margin:8px 0 20px;line-height:1.55}
.arc-auth-tabs{padding:5px;gap:5px;border:1px solid var(--border);border-radius:12px}.arc-auth-tab{padding:11px 8px;border-radius:9px;transition:background .2s,color .2s,box-shadow .2s}.arc-auth-tab[aria-current=page]{box-shadow:0 2px 12px #00000010}
.arc-auth-field{margin:15px 0;gap:7px}.arc-auth-field input{height:47px;padding:11px 14px;border-radius:11px;border-color:var(--border);transition:border-color .2s,box-shadow .2s}.arc-auth-field input:hover{border-color:color-mix(in srgb,var(--primary) 35%,var(--border))}
.arc-auth-submit{min-height:48px;border-radius:11px;margin-top:19px;font-size:14px;transition:transform .17s,filter .17s,box-shadow .17s}.arc-auth-submit:hover{box-shadow:0 8px 25px color-mix(in srgb,var(--primary) 22%,transparent)}
.arc-google-login{min-height:46px;border-radius:11px;transition:border-color .2s,background .2s}.arc-google-login:hover{border-color:var(--primary);background:var(--surface-alt)}
.arc-auth-note{border-top:1px solid var(--border);padding-top:16px;margin-top:20px}
.arc-auth-toggle:focus-visible,.arc-auth-tab:focus-visible,.arc-auth-submit:focus-visible,.arc-google-login:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
.arc-login-left .arc-auth-page{grid-template-columns:minmax(380px,.88fr) minmax(0,1.12fr)}.arc-login-left .arc-auth-panel{order:0}.arc-login-left .arc-auth-showcase{order:1;border-right:0;border-left:1px solid var(--border)}
.arc-login-center .arc-auth-page,.arc-login-no-showcase .arc-auth-page{grid-template-columns:minmax(0,1fr)}.arc-login-center .arc-auth-panel,.arc-login-no-showcase .arc-auth-panel{min-height:100dvh}
.arc-login-compact .arc-auth-card{padding:clamp(20px,2.5vw,30px)}
@media(max-width:860px){.arc-auth-page{display:flex;max-width:100%;padding:max(52px,env(safe-area-inset-top)) 13px max(18px,env(safe-area-inset-bottom));gap:4px}.arc-auth-showcase{min-height:auto;padding:8px 12px 6px;background:transparent}.arc-auth-showcase::before,.arc-auth-showcase::after{display:none}.arc-auth-panel,.arc-login-left .arc-auth-panel{padding:0;order:0;align-items:center}.arc-auth-card,.arc-login-compact .arc-auth-card{width:90%;max-width:440px;padding:clamp(18px,5vw,29px);border-radius:18px}.arc-auth-brand{justify-content:center;margin-bottom:16px;font-size:14px}.arc-auth-brand img{width:34px;height:34px}.arc-auth-message,.arc-auth-showcase-footer{display:none}.arc-auth-card h2{justify-self: center;font-size:25px}.arc-auth-field{margin:12px 0}.arc-auth-field input{height:44px;font-size:16px}.arc-auth-note{display:block}.arc-login-left .arc-auth-showcase{order:0;border:0}}
@media(max-width:370px){.arc-auth-card,.arc-login-compact .arc-auth-card{padding:16px}.arc-auth-brand{font-size:12px}.arc-auth-card h2{font-size:22px}}
@media(prefers-reduced-motion:reduce){.arc-auth-page *, .arc-auth-page *::before, .arc-auth-page *::after{transition:none!important;animation:none!important}}

/* Identidade do formulário: logotipo sobre o nome do sistema. */
.arc-auth-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:8px;margin-bottom:16px}
.arc-auth-brand img{width:48px;height:48px;object-fit:contain}
.arc-auth-brand span{display:block;max-width:100%;font-size:15px;font-weight:800;line-height:1.25;overflow-wrap:anywhere}
/* Mobile em tela cheia: um único fundo, sem moldura externa e sem altura artificial. */
@media(max-width:860px){
 html{min-height:100%;background:var(--bg)}
 body.arc-login-page{margin:0!important;width:100%!important;max-width:none!important;min-height:100dvh!important;height:auto!important;padding:0!important;background:var(--bg)!important;overflow-x:clip!important;overflow-y:auto!important}
 body.arc-login-page .arc-auth-page{display:flex!important;flex-direction:column!important;justify-content:center!important;align-items:stretch!important;box-sizing:border-box!important;width:100%!important;max-width:none!important;min-height:100dvh!important;height:auto!important;margin:0!important;padding:max(12px,env(safe-area-inset-top)) 16px max(12px,env(safe-area-inset-bottom))!important;background:var(--bg)!important;gap:0!important}
 body.arc-login-page .arc-auth-showcase{display:none!important}
 body.arc-login-page .arc-auth-panel{display:flex!important;flex:0 0 auto!important;min-height:0!important;height:auto!important;width:100%!important;max-width:400px!important;padding:0!important;margin:auto!important;align-items:center!important;justify-content:center!important;background:transparent!important}
 body.arc-login-page .arc-auth-card,body.arc-login-page.arc-login-compact .arc-auth-card{width:100%!important;max-width:none!important;min-height:0!important;margin:0!important;padding:18px 14px!important;border:0!important;border-radius:0!important;box-shadow:none!important;background:transparent!important}
 body.arc-login-page .arc-auth-brand{margin:0 0 15px;gap:7px}
 body.arc-login-page .arc-auth-brand img{width:44px;height:44px}
 body.arc-login-page .arc-auth-brand span{font-size:15px}
 body.arc-login-page .arc-auth-card h2{font-size:23px;margin:0 0 6px}
 body.arc-login-page .arc-auth-subtitle{margin:0 0 12px}
 body.arc-login-page .arc-auth-tabs{margin:10px 0}
 body.arc-login-page .arc-auth-field{margin:10px 0}
 body.arc-login-page .arc-auth-field input{height:43px;font-size:16px}
 body.arc-login-page .arc-auth-submit{min-height:44px;margin-top:13px}
 body.arc-login-page .arc-google-login{min-height:42px}
 body.arc-login-page .arc-auth-note{margin-top:13px;padding-top:12px}
 body.arc-login-page .arc-appearance-public{top:max(8px,env(safe-area-inset-top));right:12px}
}
@media(max-width:860px) and (max-height:540px){
 body.arc-login-page .arc-auth-page{justify-content:flex-start!important}
 body.arc-login-page .arc-auth-panel{margin:0 auto!important}
}
</style><script src="assets/js/arcoll.js?v=<?=arc_h(arc_app_version())?>" defer></script><script>try{const c=document.documentElement.dataset.arcSystemTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head><body><main class="panel">
    <?php if($mImage!==''):?><img src="<?= $escape($mImage) ?>" alt="Imagem de manutenção"><?php else:?><img src="api/brand-logo.php" alt="Logo da plataforma"><?php endif;?>
    <h1><?= $escape($mTitle) ?></h1><p><?= nl2br($escape($mText)) ?></p><div class="countdown" id="countdown" aria-live="off"></div><div class="note" id="maintenance-note">Acompanhe a previsão de retorno.</div><?php if($mContact!==''):?><div class="note" id="maintenance-contact"><?= $escape($mContact) ?></div><?php endif;?><a href="index.php?admin_login=1" style="display:inline-block;margin-top:25px;color:#d4ebf8;font-size:11px">Acesso administrativo</a></main>
    <script>const until=<?= json_encode($mUntil,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const target=until?new Date(until.replace(' ','T')).getTime():0;const node=document.getElementById('countdown');function tick(){if(!target||!Number.isFinite(target)){node.hidden=true;return}const delta=Math.max(0,target-Date.now());const units=[Math.floor(delta/86400000),Math.floor(delta/3600000)%24,Math.floor(delta/60000)%60,Math.floor(delta/1000)%60];node.innerHTML=units.map((v,i)=>'<span>'+String(v).padStart(2,'0')+'<small>'+['Dias','Horas','Min','Seg'][i]+'</small></span>').join('');if(delta===0)document.getElementById('maintenance-note').textContent='A previsão foi alcançada. Aguarde a liberação do serviço.';}tick();setInterval(tick,1000);</script></body></html>
    <?php exit;
}
$settings = arc_setting_values($pdo);
$registrationEnabled = arc_platform_setting($pdo,'platform_registration_enabled','1') === '1';
$googleEnabled = arc_is_https() && arc_platform_setting($pdo,'platform_google_enabled')==='1'
 && arc_platform_setting($pdo,'platform_google_client_id')!=='' && arc_platform_decrypt(arc_platform_setting($pdo,'platform_google_client_secret'))!=='';
$passwordPolicy=arc_platform_setting($pdo,'platform_registration_password_policy','medium');
if(!in_array($passwordPolicy,['none','basic','medium','advanced'],true))$passwordPolicy='medium';
$passwordMin=['none'=>1,'basic'=>6,'medium'=>10,'advanced'=>12][$passwordPolicy];
$loginFlag=static fn(string $name,string $default='0'):bool => arc_platform_setting($pdo,$name,$default)==='1';
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
    } elseif ($mode === 'register' && !arc_registration_domain_allowed($pdo, $postedEmail)) {
        $error = 'O cadastro está restrito a domínios de e-mail autorizados pela administração.';
    } elseif ($password === '') {
        $error = 'Informe sua senha.';
    } elseif ($mode === 'register' && (mb_strlen($postedName) < 2 || mb_strlen($postedName) > 120)) {
        $error = 'Informe seu nome (entre 2 e 120 caracteres).';
    } elseif ($mode === 'register' && (mb_strlen($password) < $passwordMin || ($passwordPolicy==='medium' && (!preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password))) || ($passwordPolicy==='advanced' && (!preg_match('/[a-z]/',$password) || !preg_match('/[A-Z]/',$password) || !preg_match('/[0-9]/',$password) || !preg_match('/[^a-zA-Z0-9]/',$password))))) {
        $error = 'A senha não atende à política de segurança definida para novos cadastros.';
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
<html lang="pt-BR" data-arc-design="<?= $escape($arcVisualTheme['id']) ?>" data-arc-system-theme="<?= $escape($arcEffectiveTheme) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b1424">
<link rel="icon" type="image/png" sizes="192x192" href="api/brand-logo.php?icon=192">
<link rel="shortcut icon" href="api/brand-logo.php?icon=192">
<link rel="apple-touch-icon" sizes="192x192" href="api/brand-logo.php?icon=192">
<title><?= $escape($siteName) ?> | Acesso</title>
<?php
// Estilos fundamentais do login: carregar a fonte local usada pelo restante da aplicação.
// Evita dependência de URL relativa, cache HTTP ou regras de reescrita para a folha principal.
$arcLoginStyles = __DIR__ . '/assets/css/arcoll.css';
if (is_file($arcLoginStyles) && is_readable($arcLoginStyles)) {
    echo '<style id="arc-login-core-css">';
    readfile($arcLoginStyles);
    echo '</style>';
} else {
    error_log('Login: folha de estilos principal indisponível em assets/css/arcoll.css');
}
?>
<link rel="stylesheet" href="assets/vendor/font-awesome/css/font-awesome.min.css">
<style>
body.arc-login-page{margin:0;display:block;width:100%;height:auto;min-height:100dvh;overflow-x:hidden;overflow-y:auto;background:var(--bg);color:var(--text);font:14px/1.45 var(--font)}
.arc-auth-page{box-sizing:border-box;min-height:100dvh;display:grid;grid-template-columns:minmax(0,1fr) minmax(340px,1fr);margin:auto}.arc-auth-page *{box-sizing:border-box}.arc-auth-showcase{display:flex;flex-direction:column;justify-content:space-between;gap:24px;min-width:0;padding:clamp(24px,5vw,64px);background:var(--surface-alt);border-right:1px solid var(--border)}.arc-auth-brand{display: grid;
    align-items: center;
    gap: 10px;
    font-size: 17px;
    font-weight: 750;
    min-width: 0;
    JUSTIFY-CONTENT: CENTER;}.arc-auth-brand img{width:42px;height:42px;object-fit:contain}.arc-auth-brand span{overflow-wrap:anywhere}.arc-auth-message h1{font-size:clamp(27px,3vw,44px);line-height:1.1;letter-spacing:-.04em}.arc-auth-message h1 em{color:var(--primary);font-style:normal}.arc-auth-message p{max-width:400px;color:var(--muted);font-size:13px;line-height:1.6}.arc-auth-pill,.arc-auth-topline{color:var(--primary);font-size:10px;font-weight:750;letter-spacing:.06em}.arc-auth-showcase-footer{display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:var(--muted)}.arc-auth-panel{min-width:0;display:flex;align-items:center;justify-content:center;padding:28px}.arc-auth-card{width:100%;max-width:380px;padding:24px;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 8px 35px #12243808}.arc-auth-card h2{justify-self: center;margin:5px 0 8px;font-size:25px;line-height:1.2;letter-spacing:-.03em}.arc-auth-subtitle{justify-self: center;font-size:12px;color:var(--muted);margin:0 0 16px}.arc-auth-tabs{display:flex;gap:4px;padding:3px;border-radius:9px;background:var(--surface-alt);margin:14px 0}.arc-auth-tab{flex:1;text-align:center;padding:8px 5px;border-radius:7px;text-decoration:none;font-size:12px;font-weight:700;color:var(--muted)}.arc-auth-tab[aria-current=page]{background:var(--surface);color:var(--text)}.arc-auth-field{display:grid;gap:5px;margin:10px 0;font-size:12px;font-weight:600}.arc-auth-field input{min-width:0;width:100%;height:40px;padding:8px 11px;border:1px solid var(--border);border-radius:8px;background:var(--surface-alt);color:var(--text);font:inherit}.arc-auth-field input:focus{outline:2px solid var(--primary);outline-offset:1px}.arc-auth-submit{display:block;min-height:42px;width:100%;border:0;border-radius:8px;background:#087eac;color:white;font:700 13px var(--font);cursor:pointer;margin-top:14px}.arc-auth-error{padding:9px 11px;border:1px solid #d16b76;border-radius:8px;font-size:12px;color:var(--text);margin:10px 0;background:var(--surface-alt)}.arc-google-divider{margin:12px 0;text-align:center;font-size:11px;color:var(--muted)}.arc-google-login{display:flex;justify-content:center;align-items:center;gap:8px;min-height:40px;padding:6px;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:16px;text-decoration:none}.arc-google-login i{color:var(--primary)}.arc-auth-note{font-size:11px;line-height:1.5;color:var(--muted);margin:14px 0 0}.arc-login-left .arc-auth-panel{order:-1}.arc-login-center .arc-auth-showcase,.arc-login-no-showcase .arc-auth-showcase{display:none}.arc-login-center .arc-auth-page,.arc-login-no-showcase .arc-auth-page{grid-template-columns:1fr}.arc-login-compact .arc-auth-card{padding:20px}.arc-login-page>.arc-appearance-public{z-index:5}
@media(max-width:860px){.arc-auth-page{display:flex;flex-direction:column;min-height:100dvh;padding:max(54px,env(safe-area-inset-top)) 12px max(12px,env(safe-area-inset-bottom))}.arc-auth-showcase{display:block;padding:0 12px 10px;border:0;background:transparent}.arc-auth-brand{font-size:13px;justify-content:center}.arc-auth-brand img{width:30px;height:30px}.arc-auth-message,.arc-auth-showcase-footer{display:none}.arc-auth-panel,.arc-login-left .arc-auth-panel{order:0;flex:1;padding:0;align-items:center}.arc-auth-card,.arc-login-compact .arc-auth-card{max-width:360px;padding:18px;border-radius:14px}.arc-auth-card h2{justify-self: center;font-size:22px}.arc-auth-topline{display:none}.arc-auth-subtitle{font-size:11px;margin-bottom:10px}.arc-auth-field{margin:9px 0;font-size:11px}.arc-auth-field input{font-size:16px;height:40px}.arc-auth-submit{min-height:42px;margin-top:12px}.arc-auth-note{display:none}.arc-auth-tabs{margin:11px 0}.arc-login-page .arc-appearance-public{top:max(8px,env(safe-area-inset-top));right:12px}}
/* Layout e autenticação visual: controles derivados de admin/configuracoes.php, aba Login. */
.arc-auth-card{max-width:420px;box-shadow:0 24px 75px #0002}.arc-auth-card .arc-auth-submit{background:var(--primary,#087eac);transition:transform .15s,filter .15s}.arc-auth-submit:hover{filter:brightness(1.08)}.arc-auth-submit:active{transform:scale(.99)}.arc-auth-toggle{background:none;border:0;color:var(--primary);cursor:pointer;font:600 11px var(--font);padding:0 2px;margin:0 0 8px auto;display:block}.arc-auth-strength{height:6px;background:var(--border);border-radius:6px;margin:2px 0 22px;position:relative}.arc-auth-strength>span{display:block;width:0;height:100%;border-radius:inherit;background:var(--primary);transition:width .2s}.arc-auth-strength small{display:block;color:var(--muted);font-size:10px;margin-top:5px}.arc-auth-showcase{background:linear-gradient(145deg,var(--surface-alt),var(--surface));position:relative;overflow:hidden}.arc-auth-showcase::before{content:"";position:absolute;width:50vw;aspect-ratio:1;left:-25%;bottom:-30%;border-radius:50%;background:var(--primary);opacity:.08;pointer-events:none}.arc-auth-message{position:relative}.arc-auth-message h1{max-width:580px;overflow-wrap:anywhere}.arc-auth-card .arc-auth-field input{transition:border-color .2s,box-shadow .2s}.arc-auth-card .arc-auth-field input:focus{box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 15%,transparent)}
@media(max-height:500px) and (max-width:860px){.arc-auth-page{padding-top:45px}.arc-auth-showcase{display:none}.arc-auth-panel{align-items:flex-start}.arc-auth-card{max-width:410px}.arc-auth-subtitle{display:none}}

/* Identidade e composição sem sobrescrever as preferências do administrador */
.arc-auth-page{width:100%;max-width:none;grid-template-columns:minmax(0,1.12fr) minmax(380px,.88fr)}
.arc-auth-showcase{padding:clamp(32px,6vw,88px);isolation:isolate;background:linear-gradient(155deg,var(--surface-alt),var(--surface));min-height:100dvh}
.arc-auth-showcase::after{content:"";position:absolute;inset:auto -12% -40% 25%;height:80%;background:radial-gradient(ellipse,color-mix(in srgb,var(--primary) 16%,transparent),transparent 70%);pointer-events:none;z-index:-1}
.arc-auth-message{margin:auto 0;max-width:630px}.arc-auth-pill{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--primary) 35%,var(--border));background:color-mix(in srgb,var(--primary) 9%,transparent);border-radius:999px;padding:8px 12px;margin-bottom:18px}
.arc-auth-message h1{font-size:clamp(36px,4vw,66px);line-height:1.04;margin:0 0 22px}.arc-auth-message p{font-size:clamp(13px,1.1vw,16px);max-width:480px}
.arc-auth-showcase-footer{gap:14px 22px}.arc-auth-showcase-footer span{display:inline-flex;align-items:center;gap:8px}
.arc-auth-panel{padding:clamp(20px,4vw,65px)}.arc-auth-card{max-width:460px;width:100%;padding:clamp(23px,3vw,42px);border-radius:24px;box-shadow:0 26px 90px #00000013}
.arc-auth-brand{display:flex;justify-content:flex-start;gap:12px;margin-bottom:28px;line-height:1.3}.arc-auth-brand img{width:42px;height:42px;border-radius:12px}
.arc-auth-card h2{font-size:clamp(25px,2.4vw,34px);font-weight:800}.arc-auth-subtitle{margin:8px 0 20px;line-height:1.55}
.arc-auth-tabs{padding:5px;gap:5px;border:1px solid var(--border);border-radius:12px}.arc-auth-tab{padding:11px 8px;border-radius:9px;transition:background .2s,color .2s,box-shadow .2s}.arc-auth-tab[aria-current=page]{box-shadow:0 2px 12px #00000010}
.arc-auth-field{margin:15px 0;gap:7px}.arc-auth-field input{height:47px;padding:11px 14px;border-radius:11px;border-color:var(--border);transition:border-color .2s,box-shadow .2s}.arc-auth-field input:hover{border-color:color-mix(in srgb,var(--primary) 35%,var(--border))}
.arc-auth-submit{min-height:48px;border-radius:11px;margin-top:19px;font-size:14px;transition:transform .17s,filter .17s,box-shadow .17s}.arc-auth-submit:hover{box-shadow:0 8px 25px color-mix(in srgb,var(--primary) 22%,transparent)}
.arc-google-login{min-height:46px;border-radius:11px;transition:border-color .2s,background .2s}.arc-google-login:hover{border-color:var(--primary);background:var(--surface-alt)}
.arc-auth-note{border-top:1px solid var(--border);padding-top:16px;margin-top:10px;justify-self: center;}
.arc-auth-toggle:focus-visible,.arc-auth-tab:focus-visible,.arc-auth-submit:focus-visible,.arc-google-login:focus-visible{outline:2px solid var(--primary);outline-offset:3px}
.arc-login-left .arc-auth-page{grid-template-columns:minmax(380px,.88fr) minmax(0,1.12fr)}.arc-login-left .arc-auth-panel{order:0}.arc-login-left .arc-auth-showcase{order:1;border-right:0;border-left:1px solid var(--border)}
.arc-login-center .arc-auth-page,.arc-login-no-showcase .arc-auth-page{grid-template-columns:minmax(0,1fr)}.arc-login-center .arc-auth-panel,.arc-login-no-showcase .arc-auth-panel{min-height:100dvh}
.arc-login-compact .arc-auth-card{padding:clamp(20px,2.5vw,30px)}
@media(max-width:860px){.arc-auth-page{display:flex;max-width:100%;padding:max(52px,env(safe-area-inset-top)) 13px max(18px,env(safe-area-inset-bottom));gap:4px}.arc-auth-showcase{min-height:auto;padding:8px 12px 6px;background:transparent}.arc-auth-showcase::before,.arc-auth-showcase::after{display:none}.arc-auth-panel,.arc-login-left .arc-auth-panel{padding:0;order:0;align-items:center}.arc-auth-card,.arc-login-compact .arc-auth-card{width:100%;max-width:440px;padding:clamp(18px,5vw,29px);border-radius:18px}.arc-auth-brand{justify-content:center;margin-bottom:16px;font-size:14px}.arc-auth-brand img{width:34px;height:34px}.arc-auth-message,.arc-auth-showcase-footer{display:none}.arc-auth-card h2{font-size:25px}.arc-auth-field{margin:12px 0}.arc-auth-field input{height:44px;font-size:16px}.arc-auth-note{display:block}.arc-login-left .arc-auth-showcase{order:0;border:0}}
@media(max-width:370px){.arc-auth-card,.arc-login-compact .arc-auth-card{padding:16px}.arc-auth-brand{font-size:12px}.arc-auth-card h2{font-size:22px}}
@media(prefers-reduced-motion:reduce){.arc-auth-page *, .arc-auth-page *::before, .arc-auth-page *::after{transition:none!important;animation:none!important}}

/* Ajuste final do login mobile e identidade visual */
.arc-auth-brand{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:10px;margin-bottom:1px}
.arc-auth-brand img{width:48px;height:48px;object-fit:contain}
.arc-auth-brand span{display:block;max-width:100%;font-size:15px;font-weight:800;line-height:1.25;overflow-wrap:anywhere}
.arc-auth-card{background:color-mix(in srgb,var(--surface) 94%, var(--surface-alt));}
.arc-auth-card h2{margin-top:0}
@media(max-width:860px){
    body.arc-login-page{min-height:100svh;background:var(--bg);overflow-x:hidden;overflow-y:auto}
    .arc-login-page .arc-appearance-public{top:max(8px,env(safe-area-inset-top));right:12px}
    .arc-auth-page{width:100%;max-width:440px;min-height:100svh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:max(0px,env(safe-area-inset-top)) 0px max(0px,env(safe-area-inset-bottom));gap:0;margin:0 auto}
    .arc-auth-showcase{display:none!important;min-height:0!important;padding:0!important;border:0!important}
    .arc-auth-panel,.arc-login-left .arc-auth-panel,.arc-login-center .arc-auth-panel,.arc-login-no-showcase .arc-auth-panel{width:100%;min-height:auto;flex:0 1 auto;padding:0;align-items:center;justify-content:center}
    .arc-auth-card,.arc-login-compact .arc-auth-card{width:90%;max-width:100%;margin:0 auto;padding:20px 18px 18px;border-radius:20px;box-shadow:0 18px 45px #0000001c}
    .arc-auth-brand{margin-bottom:14px;gap:8px}
    .arc-auth-brand img{width:44px;height:44px}
    .arc-auth-brand span{font-size:16px;line-height:1.2}
    .arc-auth-card h2{font-size:23px;margin:0 0 6px}
    .arc-auth-subtitle{margin:0 0 14px}
    .arc-auth-tabs{margin:12px 0}
    .arc-auth-field{margin:11px 0}
    .arc-auth-field input{height:44px}
    .arc-auth-submit{min-height:46px;margin-top:14px}
    .arc-auth-note{display:block;margin-top:16px;padding-top:14px}
}
@media(max-width:390px){
    .arc-auth-page{padding:0}
    .arc-auth-card,.arc-login-compact .arc-auth-card{padding:18px 16px 16px;border-radius:18px}
    .arc-auth-brand img{width:40px;height:40px}
    .arc-auth-brand span{font-size:15px}
}

</style><script src="assets/js/arcoll.js?v=<?=arc_h(arc_app_version())?>" defer></script><script>try{const c=document.documentElement.dataset.arcSystemTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head>
<?php $loginLayout=arc_platform_setting($pdo,'platform_login_layout','right');if(!in_array($loginLayout,['left','center','right'],true))$loginLayout='right'; ?>
<body class="arc-login-page arc-login-<?= $escape($loginLayout) ?> <?= $loginFlag('platform_login_compact','1') ? 'arc-login-compact' : '' ?> <?= !$loginFlag('platform_login_show_showcase') ? 'arc-login-no-showcase' : '' ?>"><main class="arc-auth-page">
<section class="arc-auth-showcase" aria-label="Apresentação da plataforma">
    
    
<div class="arc-auth-message"><span class="arc-auth-pill"><?= $escape(arc_platform_setting($pdo,'platform_login_hero_eyebrow','Gestão inteligente de redes')) ?></span><h1><?= $escape(arc_platform_setting($pdo,'platform_login_hero_title','Sua rede sob controle')) ?></h1><p><?= $escape(arc_platform_setting($pdo,'platform_login_hero_description','Monitore dispositivos, conexões e atendimentos em um único ambiente seguro.')) ?></p></div>
<div class="arc-auth-showcase-footer"><span><i class="fa fa-shield" aria-hidden="true"></i> Acesso protegido</span><span><i class="fa fa-desktop" aria-hidden="true"></i> Monitoramento integrado</span></div></section>
<section class="arc-auth-panel"><div class="arc-auth-card">
    
    <div class="arc-auth-brand"><?php if($loginFlag('platform_login_show_logo','1')): ?>
    <img src="api/brand-logo.php" alt="" onerror="this.onerror=null;this.src='assets/img/app-mark.svg'">
    
    <?php endif; ?>
    </div>
    
<h2><?= $escape($mode === 'register' ? 'Crie sua conta' : arc_platform_setting($pdo,'platform_login_heading','Bem-vindo')) ?></h2><?php if($loginFlag('platform_login_show_subtitle')): ?><p class="arc-auth-subtitle"><?= $escape($mode === 'register' ? 'Cadastre-se para começar a gerenciar suas redes.' : arc_platform_setting($pdo,'platform_login_subtitle','Entre para acompanhar suas redes.')) ?></p><?php endif; ?>
<nav class="arc-auth-tabs" aria-label="Autenticação"><a class="arc-auth-tab" href="index.php?mode=login" aria-current="<?= $mode === 'login' ? 'page' : 'false' ?>">Entrar</a><?php if($registrationEnabled):?><a class="arc-auth-tab" href="index.php?mode=register" aria-current="<?= $mode === 'register' ? 'page' : 'false' ?>">Criar conta</a><?php endif;?></nav>
<?php if ($googleError !== ''): ?><div class="arc-auth-error" role="alert"><?= $escape($googleError) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="arc-auth-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<form method="post" action="index.php<?= isset($_GET['admin_login']) ? '?admin_login=1' : '' ?>" autocomplete="on"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="mode" value="<?= $escape($mode) ?>">
<?php if ($mode === 'register'): ?><label class="arc-auth-field"><input name="display_name" type="text" maxlength="120" autocomplete="name" required value="<?= $escape($postedName) ?>" placeholder="Seu nome completo"></label><?php endif; ?>
<label class="arc-auth-field"><input name="email" type="email" maxlength="254" autocomplete="email" required value="<?= $escape($postedEmail) ?>" placeholder="voce@empresa.com.br"></label>
<label class="arc-auth-field"><input id="arc-login-password" name="password" type="password" <?= $mode === 'register' ? 'minlength="'.$passwordMin.'" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required placeholder="<?= $mode === 'register' ? 'Crie uma senha segura' : 'Sua senha de acesso' ?>"></label>
<?php if($loginFlag('platform_login_show_password_toggle','1')): ?><button type="button" class="arc-auth-toggle" data-auth-toggle="arc-login-password" aria-pressed="false"><i class="fa fa-eye" aria-hidden="true"></i> Mostrar senha</button><?php endif; ?>
<?php if ($mode === 'register' && $loginFlag('platform_login_show_strength','1')): ?><div class="arc-auth-strength" aria-live="polite"><span id="arc-password-meter"></span><small id="arc-password-strength-text">Informe uma senha de pelo menos <?= (int)$passwordMin ?> caracteres.</small></div><?php endif; ?>
<?php if ($mode === 'register'): ?><label class="arc-auth-field"><input name="confirm_password" type="password" minlength="<?= $passwordMin ?>" autocomplete="new-password" required placeholder="Repita sua senha"></label><?php endif; ?>
<button class="arc-auth-submit" type="submit"><i class="fa fa-lock" aria-hidden="true"></i> <?= $mode === 'register' ? 'Criar minha conta' : 'Entrar na plataforma' ?></button></form>
<?php if($googleEnabled):?><div class="arc-google-divider"></div><a class="arc-google-login" href="google-auth.php"><i class="fa fa-google" aria-hidden="true"></i> Entrar com Google</a><?php endif;?>
<?php if($loginFlag('platform_login_show_footer')): ?><p class="arc-auth-note"><?= $escape(arc_platform_setting($pdo,'platform_login_footer_text','Área restrita. Sua sessão é protegida e os acessos são registrados.')) ?></p><?php endif; ?>
</div></section></main><script>
/* Caminho: index.php — melhorias progressivas do formulário; validação real permanece no servidor */
(function(){
 document.querySelectorAll('[data-auth-toggle]').forEach(function(b){b.addEventListener('click',function(){var el=document.getElementById(b.dataset.authToggle);if(!el)return;var reveal=el.type==='password';el.type=reveal?'text':'password';b.setAttribute('aria-pressed',String(reveal));b.innerHTML='<i class="fa fa-eye'+(reveal?'-slash':'')+'" aria-hidden="true"></i> '+(reveal?'Ocultar senha':'Mostrar senha');});});
 var p=document.getElementById('arc-login-password'),m=document.getElementById('arc-password-meter'),t=document.getElementById('arc-password-strength-text');if(p&&m&&t){p.addEventListener('input',function(){var v=p.value,score=Number(v.length>=8)+Number(v.length>=12)+Number(/[a-z]/.test(v)&&/[A-Z]/.test(v))+Number(/\d/.test(v))+Number(/[^A-Za-z0-9]/.test(v));m.style.width=(score*20)+'%';t.textContent=!v?'Digite uma senha segura.':score<=2?'Senha fraca':score<=3?'Senha razoável':'Senha mais forte';});}
})();</script></body></html>
