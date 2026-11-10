<?php
/* Caminho: api/menu-favorites.php — favoritos na sessão web, com CSRF. */
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/menu-favorites.php';
$pdo=arc_db();
if(!arc_is_user()&&!arc_is_admin())arc_json(['ok'=>false,'error'=>'Autenticação necessária'],401);
$networkId=(int)($_GET['network']??0);
$access=arc_network_team_access($pdo,$networkId,(int)arc_user_id());
if(!$access)arc_json(['ok'=>false,'error'=>'Rede sem acesso'],403);
$accountId=(int)$access['account_id'];
header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']==='GET')arc_json(['ok'=>true]+arc_menu_favorites_read($pdo,$accountId,$networkId));
if($_SERVER['REQUEST_METHOD']!=='POST')arc_json(['ok'=>false,'error'=>'Método inválido'],405);
arc_verify_csrf();
$raw=file_get_contents('php://input');
if(!is_string($raw)||strlen($raw)>2048)arc_json(['ok'=>false,'error'=>'Pedido inválido'],413);
$body=json_decode($raw,true);
if(!is_array($body))arc_json(['ok'=>false,'error'=>'JSON inválido'],400);
try{$data=arc_menu_favorites_write($pdo,$accountId,$networkId,$body);}catch(InvalidArgumentException $e){arc_json(['ok'=>false,'error'=>$e->getMessage()],422);}
arc_json(['ok'=>true]+$data);
