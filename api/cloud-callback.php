<?php
/* Caminho: api/cloud-callback.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
$network=0;$gestorId=0;$ok=false;$managed=false;$backupCenter=false;
try {
    if (!arc_is_https()) throw new RuntimeException('Sessão segura necessária.');
    $state=arc_cloud_state_verify((string)($_GET['state']??''));
    $managed=!empty($state['managed']);
    if (!$state || ($managed ? (!arc_is_admin() || (int)($state['u']??0)!==(int)arc_admin_id()) : (!arc_is_user() || (int)($state['u']??0)!==(int)arc_user_id()))) throw new RuntimeException('Autorização inválida ou expirada.');
    $nonce=(string)($state['n']??'');$pending=$_SESSION['arc_cloud_oauth'][$nonce]??null;
    unset($_SESSION['arc_cloud_oauth'][$nonce]);
    if (!is_array($pending) || (int)$pending['time']<time()-900) throw new RuntimeException('Esta autorização expirou ou já foi utilizada.');
    $backupCenter=!empty($pending['backup_center']);
    $pdo=arc_db();arc_ensure_user_schema($pdo);arc_cloud_ensure_schema($pdo);
    $network=(int)$state['net'];$gestorId=(int)$state['ag'];
    $account=0;
    if(!$managed){$access=arc_cloud_access($pdo,$network,(int)arc_user_id());$account=(int)$access['account_id'];arc_cloud_gestor_row($pdo,$gestorId,$network,$account);}
    if (isset($_GET['error']) || empty($_GET['code'])) throw new RuntimeException('Conexão cancelada na conta de nuvem.');
    $tokens=arc_cloud_oauth_exchange($pdo,(string)$state['p'],(string)$_GET['code'],(string)$pending['verifier']);
    if(!$managed&&!arc_cloud_policy($pdo)['own'])throw new RuntimeException('Contas próprias não liberadas pelo administrador.');
    $newConnection=arc_cloud_connection_create($pdo,$network,$account,$managed?(int)arc_admin_id():(int)arc_user_id(),(string)$state['p'],['refresh_token'=>$tokens['refresh'],'access_token'=>$tokens['access'],'expires'=>$tokens['expires']],$managed?'Drive da plataforma':'');
    if(!$managed&&!$backupCenter&&$gestorId>0){   // a conta recém-autorizada já vira o destino deste computador (o backup continua desativado até o gestor ativar)
        $job=arc_cloud_job_get($pdo,$gestorId,$network,$account);
        if($job['connection_id']===null)arc_cloud_job_save($pdo,$gestorId,$network,$account,$newConnection,false,$job['settings']);
    }
    if(!$managed)arc_user_audit($pdo,'cloud_connect','Conta de nuvem conectada na rede '.$network);$ok=true;
} catch (Throwable $e) { arc_log_error('Arcoll cloud OAuth: autorização não concluída ('.get_class($e).').'); }
$_SESSION['arc_flash']=$ok?'Conta de nuvem conectada. Selecione-a no Backup em Nuvem.':'A conexão de nuvem não foi concluída. Verifique as credenciais do aplicativo e tente novamente.';
header('Location: '.arc_workspace_base_url().($managed?'admin/configuracoes.php?tab=apis#apis':($network>0?'redes/backup.php?network='.$network.'&gestor='.$gestorId.($backupCenter?'&tab=settings':''):'pages/painel.php')),true,303);
exit;
