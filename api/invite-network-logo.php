<?php
/** Caminho: /api/invite-network-logo.php — logo pública temporária, acesso por URL assinada. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require dirname(__DIR__).'/includes/bootstrap.php';
$id=(int)($_GET['network']??0);$expires=(int)($_GET['expires']??0);$sig=(string)($_GET['sig']??'');
$secret=(string)(arc_config()['app_secret']??'');
if($id<=0||$expires<time()||$expires>time()+15*86400||$secret===''||!preg_match('/^[a-f0-9]{64}$/',$sig)||!hash_equals(hash_hmac('sha256',$id.'|'.$expires,$secret),$sig)){http_response_code(403);exit;}
try{
 $db=arc_db();$q=$db->prepare('SELECT logo_file FROM '.arc_table('managed_networks').' WHERE id=? AND is_active=1 LIMIT 1');$q->execute([$id]);$file=$q->fetchColumn();
 if(!is_string($file)||!preg_match('/^[a-f0-9]{40}\.(png|jpg|webp)$/',$file)){http_response_code(404);exit;}
 $path=ARC_PRIVATE_DIR.'/storage/network-logos/'.$file;if(!is_file($path)){http_response_code(404);exit;}
 header('Content-Type: '.(['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));
 header('Content-Length: '.filesize($path));header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');readfile($path);
}catch(Throwable $e){http_response_code(404);}
