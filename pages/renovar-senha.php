<?php
/* Caminho: pages/renovar-senha.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();
$id=(int)($_GET['uid']??$_POST['uid']??0);
$token=(string)($_GET['token']??$_POST['token']??'');
$error='';$success=false;
function arc_password_reset_valid(PDO $pdo,int $id,string $token):bool{
 if($id<1||!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
 $stmt=$pdo->prepare('SELECT pref_value FROM '.arc_table('user_preferences').' WHERE user_id=? AND pref_key=? LIMIT 1');$stmt->execute([$id,'password_reset']);$value=(string)$stmt->fetchColumn();
 $parts=explode(':',$value,2);return count($parts)===2&&ctype_digit($parts[1])&&(int)$parts[1]>=time()&&hash_equals($parts[0],hash('sha256',$token));
}
$valid=arc_password_reset_valid($pdo,$id,$token);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!$valid)throw new RuntimeException('Link inválido ou expirado. Solicite outro link ao administrador.');
  if(!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??'')))throw new RuntimeException('Sessão expirada. Atualize a página.');
  $password=(string)($_POST['password']??'');
  if(strlen($password)<10||strlen($password)>128||$password!==(string)($_POST['confirm']??''))throw new RuntimeException('Informe uma senha de 10 a 128 caracteres e confirme corretamente.');
  $pdo->beginTransaction();
  $stmt=$pdo->prepare('SELECT pref_value FROM '.arc_table('user_preferences').' WHERE user_id=? AND pref_key=? FOR UPDATE');$stmt->execute([$id,'password_reset']);$row=(string)$stmt->fetchColumn();$parts=explode(':',$row,2);
  if(count($parts)!==2||!hash_equals($parts[0],hash('sha256',$token))||(int)$parts[1]<time())throw new RuntimeException('Link expirado ou já utilizado.');
  $pdo->prepare('UPDATE '.arc_table('user_accounts').' SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
  $pdo->prepare('DELETE FROM '.arc_table('user_preferences').' WHERE user_id=? AND pref_key=?')->execute([$id,'password_reset']);
  $pdo->commit();$success=true;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Renovar senha · Arcoll</title><style>body{margin:0;background:var(--surface);color:var(--text);font:15px system-ui,sans-serif;display:grid;place-items:center;min-height:100vh}.card{width:min(420px,calc(100vw - 48px));background:var(--surface);border-radius:16px;box-shadow:0 14px 45px #193a6022;padding:30px}h1{font-size:22px;margin:0 0 12px}p{color:var(--muted)}label{display:block;margin:14px 0;font-weight:600;font-size:13px}input{display:block;width:100%;box-sizing:border-box;padding:12px;margin-top:6px;border:1px solid var(--border);border-radius:9px}button{width:100%;border:0;background:#11558d;color:var(--text);padding:12px;border-radius:9px;font-weight:700;cursor:pointer}.error{color:#b42318}a{color:var(--primary)}</style><script src="<?=arc_h(arc_workspace_base_url())?>assets/js/arcoll.js?v=3.4.0" defer></script><link rel="stylesheet" href="<?=arc_h(arc_workspace_base_url())?>assets/css/arcoll.css?v=3.4.0"><script>try{const c=localStorage.getItem('arc_appearance')||document.documentElement.dataset.arcTheme||'auto';document.documentElement.dataset.theme=c==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):c}catch{document.documentElement.dataset.theme='light'}</script></head><body><label class="arc-appearance-control arc-appearance-public"><i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select></label><main class="card"><h1>Renovação de senha</h1><?php if($success):?><p>Senha atualizada. Você já pode acessar sua conta.</p><a href="../index.php">Ir para o site</a><?php elseif(!$valid):?><p class="error">Link inválido, expirado ou já utilizado. Solicite um novo link ao administrador.</p><a href="../index.php">Voltar ao site</a><?php else:?><?php if($error):?><p class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></p><?php endif;?><p>Defina uma nova senha para sua conta.</p><form method="post"><input type="hidden" name="uid" value="<?=$id?>"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(arc_csrf_token(),ENT_QUOTES,'UTF-8')?>"><label>Nova senha<input type="password" name="password" minlength="10" maxlength="128" autocomplete="new-password" required></label><label>Confirmar senha<input type="password" name="confirm" minlength="10" maxlength="128" autocomplete="new-password" required></label><button>Atualizar senha</button></form><?php endif;?></main></body></html>
