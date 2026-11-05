<?php
/* Caminho: api/cloud-callback.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
$network=0;$agentId=0;$ok=false;
try {
    if (!arc_is_user() || !arc_is_https()) throw new RuntimeException('Sessão segura necessária.');
    $state=arc_cloud_state_verify((string)($_GET['state']??''));
    if (!$state || (int)$state['u']!==(int)arc_user_id()) throw new RuntimeException('Autorização inválida ou expirada.');
    $nonce=(string)($state['n']??'');$pending=$_SESSION['arc_cloud_oauth'][$nonce]??null;
    unset($_SESSION['arc_cloud_oauth'][$nonce]);
    if (!is_array($pending) || (int)$pending['time']<time()-900) throw new RuntimeException('Esta autorização expirou ou já foi utilizada.');
    $pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);
    $network=(int)$state['net'];$agentId=(int)$state['ag'];$access=arc_cloud_access($pdo,$network,(int)arc_user_id());
    arc_cloud_agent_row($pdo,$agentId,$network,(int)$access['account_id']);
    if (isset($_GET['error']) || empty($_GET['code'])) throw new RuntimeException('Conexão cancelada na conta de nuvem.');
    $tokens=arc_cloud_oauth_exchange($pdo,(string)$state['p'],(string)$_GET['code'],(string)$pending['verifier']);
    arc_cloud_connection_create($pdo,$network,(int)$access['account_id'],(int)arc_user_id(),(string)$state['p'],['refresh_token'=>$tokens['refresh'],'access_token'=>$tokens['access'],'expires'=>$tokens['expires']]);
    arc_user_audit($pdo,'cloud_connect','Conta de nuvem conectada na rede '.$network);$ok=true;
} catch (Throwable $e) { error_log('Arcoll cloud OAuth: autorização não concluída ('.get_class($e).').'); }
$_SESSION['arc_flash']=$ok?'Conta de nuvem conectada. Selecione-a no Backup em Nuvem.':'A conexão de nuvem não foi concluída. Verifique as credenciais do aplicativo e tente novamente.';
header('Location: '.arc_workspace_base_url().($network>0?'redes/clean.php?network='.$network.'&agent='.$agentId.'&section=backup':'pages/painel.php'),true,303);
exit;
