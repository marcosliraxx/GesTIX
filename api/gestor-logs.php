<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/central-logs.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido.'],405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>262144)arc_json(['ok'=>false,'error'=>'Lote acima do limite.'],413);
    $raw=file_get_contents('php://input',false,null,0,262145);if(!is_string($raw)||strlen($raw)>262144)arc_json(['ok'=>false,'error'=>'Lote acima do limite.'],413);
    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($body))throw new InvalidArgumentException('JSON inválido.');
    $pdo=arc_db();
    if(isset($body['installation_token'])){
        $token=(string)$body['installation_token'];if(!preg_match('/^[a-f0-9]{64}$/D',$token))arc_json(['ok'=>false,'error'=>'Instalação não autorizada.'],403);
        $hash=hash('sha256',$token);$q=$pdo->prepare('SELECT k.account_id,k.network_id,k.token_hash FROM '.arc_table('gestor_download_keys').' k JOIN '.arc_table('managed_networks').' n ON n.id=k.network_id AND n.account_id=k.account_id WHERE k.token_hash=? AND k.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND n.is_active=1 AND n.monitoring_enabled=1 LIMIT 1');$q->execute([$hash]);$owner=$q->fetch(PDO::FETCH_ASSOC);
        if(!$owner)arc_json(['ok'=>false,'error'=>'Instalação não autorizada.'],403);
        arc_json(arc_log_accept_batch($body,$owner,'installer'));
    }
    $owner=arc_gestor_authenticate($pdo,$body);
    arc_json(arc_log_accept_batch($body,$owner));
}catch(JsonException|InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>'Lote de diagnóstico inválido.'],400);}
catch(Throwable $e){$code=in_array((int)$e->getCode(),[403,429,503],true)?(int)$e->getCode():503;arc_log_write('log-ingestion','ERROR',get_class($e).': '.$e->getMessage());arc_json(['ok'=>false,'error'=>$code===403?'Gestor não autorizado.':($code===429?'Aguarde para reenviar o lote.':'Diagnóstico temporariamente indisponível.')],$code);}
