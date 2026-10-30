<?php
declare(strict_types=1);
/** Caminho: google-auth.php — OAuth Google. */
require __DIR__.'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$pdo=arc_db();
$base=arc_workspace_base_url();
$back=$base.'index.php';
function arc_google_fail(string $message): never {
 if (arc_is_user()) {
  $_SESSION['arc_flash']=$message;
  header('Location: '.arc_workspace_base_url().'pages/useredit.php',true,303);exit;
 }
 $_SESSION['arc_google_error']=$message;
 header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;
}
function arc_google_http(string $url, ?array $post=null): array {
 if (!function_exists('curl_init')) throw new RuntimeException('Extensão cURL indisponível.');
 $ch=curl_init($url);
 $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']];
 if($post!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($post);$options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';}
 curl_setopt_array($ch,$options);
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if(!is_string($body)||$status!==200||strlen($body)>50000) throw new RuntimeException('Falha na validação da identidade.');
 $decoded=json_decode($body,true);
 if(!is_array($decoded))throw new RuntimeException('Resposta inválida do provedor.');
 return $decoded;
}
try {
 if(!arc_is_https())throw new RuntimeException('O login Google exige HTTPS.');
 if(arc_platform_setting($pdo,'platform_google_enabled')!=='1')throw new RuntimeException('Login Google desativado.');
 $clientId=arc_platform_setting($pdo,'platform_google_client_id');
 $clientSecret=arc_platform_decrypt(arc_platform_setting($pdo,'platform_google_client_secret'));
 if($clientId===''||$clientSecret==='')throw new RuntimeException('Login Google não configurado.');
 $redirect='https://'.(string)($_SERVER['HTTP_HOST']??'').$base.'google-auth.php';
 if(isset($_GET['error']))throw new RuntimeException('Autorização Google cancelada.');
 if(isset($_GET['code'])) {
  $saved=$_SESSION['arc_google_oauth']??null;
  unset($_SESSION['arc_google_oauth']);
  if(!is_array($saved)||time()-(int)($saved['time']??0)>600||!hash_equals((string)($saved['state']??''),(string)($_GET['state']??'')))throw new RuntimeException('Solicitação Google expirada ou inválida.');
  $tokens=arc_google_http('https://oauth2.googleapis.com/token',[
   'code'=>(string)$_GET['code'],'client_id'=>$clientId,'client_secret'=>$clientSecret,
   'redirect_uri'=>$redirect,'grant_type'=>'authorization_code','code_verifier'=>(string)$saved['verifier']
  ]);
  if(empty($tokens['id_token']))throw new RuntimeException('Token de identidade ausente.');
  $identity=arc_google_http('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode((string)$tokens['id_token']));
  if(!hash_equals($clientId,(string)($identity['aud']??''))||!in_array((string)($identity['iss']??''),['https://accounts.google.com','accounts.google.com'],true)||!hash_equals((string)$saved['nonce'],(string)($identity['nonce']??''))||(int)($identity['exp']??0)<time()||!in_array((string)($identity['email_verified']??''),['true','1'],true))throw new RuntimeException('Identidade Google não verificada.');
  $sub=(string)($identity['sub']??'');$email=strtolower(trim((string)($identity['email']??'')));
  if($sub===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Identidade Google incompleta.');
  // Identidades administrativas são autenticadas na própria conta administrativa.
  // A correspondência ocorre somente após validação do token, público OAuth, nonce e e-mail verificado.
  if(($saved['intent']??'')!=='link'){
   $adminStmt=$pdo->prepare('SELECT id,email FROM '.arc_table('admins').' WHERE email=? LIMIT 1');
   $adminStmt->execute([$email]);
   $googleAdmin=$adminStmt->fetch(PDO::FETCH_ASSOC);
   if($googleAdmin){
    session_regenerate_id(true);
    unset($_SESSION['arc_user_id'],$_SESSION['arc_user_email'],$_SESSION['arc_current_network_id']);
    $_SESSION['arc_admin_id']=(int)$googleAdmin['id'];
    $_SESSION['arc_admin_email']=(string)$googleAdmin['email'];
    header('Location: '.$base.'pages/painel.php',true,303);exit;
   }
  }
  // Vinculação utiliza arc_user_preferences existente; nenhuma tabela nova.
  $prefs=arc_table('user_preferences');
  if(($saved['intent']??'')==='link'){
   if(!arc_is_user()||(int)($saved['account_id']??0)!==arc_user_id())throw new RuntimeException('Entre na sua conta para vincular Google.');
   $exists=$pdo->prepare('SELECT user_id FROM '.$prefs.' WHERE pref_key=? AND pref_value=? LIMIT 1');
   $exists->execute(['google_sub',$sub]);$owner=$exists->fetchColumn();
   if($owner!==false&&(int)$owner!==arc_user_id())throw new RuntimeException('Esta conta Google já está vinculada.');
   $account=$pdo->prepare('SELECT email FROM '.arc_table('user_accounts').' WHERE id=? AND account_status=?');
   $account->execute([arc_user_id(),'active']);
   if(strtolower((string)$account->fetchColumn())!==$email)throw new RuntimeException('O e-mail Google precisa corresponder ao e-mail da conta.');
   $pdo->prepare('INSERT INTO '.$prefs.' (user_id,pref_key,pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)')->execute([arc_user_id(),'google_sub',$sub]);
   $_SESSION['arc_flash']='Conta Google vinculada com sucesso.';
   header('Location: '.$base.'pages/useredit.php',true,303);exit;
  }
  $q=$pdo->prepare('SELECT u.id,u.email,u.account_status FROM '.$prefs.' g JOIN '.arc_table('user_accounts').' u ON u.id=g.user_id WHERE g.pref_key=? AND g.pref_value=? LIMIT 1');$q->execute(['google_sub',$sub]);$account=$q->fetch(PDO::FETCH_ASSOC);
  if(!$account){
   $q=$pdo->prepare('SELECT id FROM '.arc_table('user_accounts').' WHERE email_hash=? LIMIT 1');$q->execute([hash('sha256',$email)]);
   $existingId=$q->fetchColumn();
   if($existingId!==false){
    $existing=$pdo->prepare('SELECT id,email,account_status FROM '.arc_table('user_accounts').' WHERE id=? LIMIT 1');
    $existing->execute([(int)$existingId]);$account=$existing->fetch(PDO::FETCH_ASSOC);
    if(!$account || strtolower((string)$account['email'])!==$email)throw new RuntimeException('Não foi possível confirmar a conta existente.');
    if((string)$account['account_status']!=='active')throw new RuntimeException('Conta indisponível.');
    $pdo->prepare('INSERT INTO '.$prefs.' (user_id,pref_key,pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)')->execute([$account['id'],'google_sub',$sub]);
   }else{ 
   $adminCheck=$pdo->prepare('SELECT id FROM '.arc_table('admins').' WHERE email=? LIMIT 1');$adminCheck->execute([$email]);
   if($adminCheck->fetchColumn()!==false)throw new RuntimeException('A conta administrativa não pôde ser validada.');
   if(arc_platform_setting($pdo,'platform_registration_enabled','1')!=='1')throw new RuntimeException('Cadastro de novas contas desativado.');
   $name=mb_substr(trim((string)($identity['name']??'Usuário Google')),0,120);
   $randomPassword=base64_encode(random_bytes(48));
   $pdo->prepare('INSERT INTO '.arc_table('user_accounts').' (email,email_hash,display_name,password_hash,access_notes) VALUES (?,?,?,?,?)')->execute([$email,hash('sha256',$email),$name,password_hash($randomPassword,PASSWORD_DEFAULT),'']);
   $account=['id'=>(int)$pdo->lastInsertId(),'email'=>$email,'account_status'=>'active'];
   $pdo->prepare('INSERT INTO '.$prefs.' (user_id,pref_key,pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)')->execute([$account['id'],'google_sub',$sub]);
   }
  }
  if((string)$account['account_status']!=='active')throw new RuntimeException('Conta indisponível.');
  // Captura a foto pública validada da identidade Google apenas se o perfil ainda não possui foto.
  $picture=(string)($identity['picture']??'');
  $imageHost=strtolower((string)parse_url($picture,PHP_URL_HOST));
  if($imageHost==='lh3.googleusercontent.com' && str_starts_with($picture,'https://') && strlen($picture)<1500){
   try{
    $avatarCheck=$pdo->prepare('SELECT avatar_url FROM '.arc_table('user_accounts').' WHERE id=?');
    $avatarCheck->execute([(int)$account['id']]);
    if(trim((string)$avatarCheck->fetchColumn())===''){
     $ch=curl_init($picture);
     curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_MAXFILESIZE=>2097152]);
     $bytes=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
     if(is_string($bytes)&&$status===200&&strlen($bytes)>0&&strlen($bytes)<=2097152){
      $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
      $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
      if($ext && @getimagesizefromstring($bytes)){
       $directory=__DIR__.'/uploads/avatars';
       if(is_dir($directory) && is_writable($directory)){
        $filename='u'.(int)$account['id'].'-'.bin2hex(random_bytes(4)).'.'.$ext;
        $path=$directory.'/'.$filename;
        if(file_put_contents($path,$bytes,LOCK_EX)!==false){
         $pdo->prepare('UPDATE '.arc_table('user_accounts')." SET avatar_url=? WHERE id=? AND avatar_url='' ")->execute(['uploads/avatars/'.$filename,(int)$account['id']]);
        }
       }
      }
     }
    }
   }catch(Throwable $photoError){error_log('Google avatar: '.$photoError->getMessage());}
  }
  // Aceita convites somente após o Google comprovar a posse deste e-mail.
  $invitations=$pdo->prepare('SELECT account_id,network_id,role FROM '.arc_table('network_team_invitations').' WHERE email_hash=? AND email=?');
  $invitations->execute([hash('sha256',$email),$email]);
  foreach($invitations->fetchAll(PDO::FETCH_ASSOC) as $invitation){
   $ownerCheck=$pdo->prepare('SELECT id FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=? AND is_active=1');
   $ownerCheck->execute([(int)$invitation['network_id'],(int)$invitation['account_id']]);
   if(!$ownerCheck->fetchColumn())continue;
   $pdo->prepare('INSERT INTO '.arc_table('network_team_members').' (account_id,network_id,member_user_id,role,enabled) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE role=VALUES(role),enabled=1')->execute([(int)$invitation['account_id'],(int)$invitation['network_id'],(int)$account['id'],(string)$invitation['role']]);
   $pdo->prepare('DELETE FROM '.arc_table('network_team_invitations').' WHERE network_id=? AND email_hash=?')->execute([(int)$invitation['network_id'],hash('sha256',$email)]);
  }
  session_regenerate_id(true);
  unset($_SESSION['arc_admin_id'],$_SESSION['arc_admin_email']);
  $_SESSION['arc_user_id']=(int)$account['id'];$_SESSION['arc_user_email']=(string)$account['email'];
  header('Location: '.$base.(arc_authenticated_home($pdo)??'pages/index.php'),true,303);exit;
 }
 $intent=arc_is_user() && (string)($_GET['intent']??'')==='link'?'link':'login';
 $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
 $state=bin2hex(random_bytes(24));$nonce=bin2hex(random_bytes(24));
 $_SESSION['arc_google_oauth']=['state'=>$state,'nonce'=>$nonce,'verifier'=>$verifier,'time'=>time(),'intent'=>$intent,'account_id'=>arc_user_id()];
 $query=http_build_query(['client_id'=>$clientId,'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','prompt'=>'select_account']);
 header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.$query,true,302);exit;
} catch(Throwable $e) {error_log('Arcoll Google OAuth: '.$e->getMessage());arc_google_fail($e->getMessage());}
