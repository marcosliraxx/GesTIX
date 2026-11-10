<?php
/* Caminho: api/tool-preferences.php — preferências individuais, respeitando limites administrativos. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!arc_is_user()) {http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sessão expirada']);exit;}
$pdo=arc_db();
$tools=['painel','servidor','pentefino','backup','velocidade','programas','remoto','suporte','chamados','arquivos','mapa','dispositivos','preferencias','usuarios','permissoes','cadastros','agenda','atualizacoes','relatorios','historico','clean'];
$tool=(string)($_POST['tool']??$_GET['tool']??'');
if (!in_array($tool,$tools,true)) {http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Ferramenta inválida']);exit;}
$min=max(1,min(60,(int)arc_platform_setting($pdo,'platform_tool_min_interval','5')));
$continuous=arc_platform_setting($pdo,'platform_tool_continuous_enabled','1')==='1';
$default=(string)arc_platform_setting($pdo,'platform_tool_refresh_default','15');
$allowed=['auto'];
if($continuous)$allowed[]='continuous';
foreach([1,2,3,5,10,15,30,60,120] as $v)if($v >= $min)$allowed[]=(string)$v;
if(!in_array($default,$allowed,true))$default=in_array('auto',$allowed,true)?'auto':(string)end($allowed);
$key='tool_refresh_'.$tool;
try {
  if($_SERVER['REQUEST_METHOD']==='POST') {
    $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['_csrf']??'');
    if (!hash_equals(arc_csrf_token(),$token)) {http_response_code(403);throw new RuntimeException('Token inválido');}
    $interval=(string)($_POST['interval']??'');
    if (!in_array($interval,$allowed,true)) {http_response_code(422);throw new RuntimeException('Intervalo não permitido pela administração');}
    $notify=((string)($_POST['notify']??'1')==='1')?'1':'0';$background=((string)($_POST['background']??'0')==='1')?'1':'0';
    arc_user_prefs_save($pdo,(int)arc_user_id(),[$key=>$interval,'tool_notify_'.$tool=>$notify,'tool_background_'.$tool=>$background]);
  }
  $prefs=arc_user_prefs($pdo,(int)arc_user_id(),true);
  $interval=$prefs[$key]??$default;
  if (!in_array($interval,$allowed,true))$interval=$default;
  echo json_encode(['ok'=>true,'tool'=>$tool,'interval'=>$interval,'allowed'=>$allowed,'notify'=>($prefs['tool_notify_'.$tool]??'1')==='1','background'=>($prefs['tool_background_'.$tool]??'0')==='1','default'=>$default,'effective_seconds'=>$interval==='continuous'?3:($interval==='auto'?15:(int)$interval)],JSON_THROW_ON_ERROR);
} catch(Throwable $e) { if(http_response_code()<400)http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
