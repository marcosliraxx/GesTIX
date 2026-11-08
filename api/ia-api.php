<?php
/* Caminho: api/ia-api.php */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try{
 require dirname(__DIR__).'/includes/bootstrap.php';require dirname(__DIR__).'/includes/ai-providers.php';
 if(!arc_is_admin()){http_response_code(403);throw new RuntimeException('Acesso administrativo necessário.');}
 $pdo=arc_db();$catalog=arc_ai_catalog();$action=(string)($_POST['action']??$_GET['action']??'list');
 if($action!=='list'&&($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new RuntimeException('Método inválido.');}
 if($action!=='list'&&!hash_equals(arc_csrf_token(),(string)($_POST['_csrf']??''))){http_response_code(403);throw new RuntimeException('Token CSRF inválido.');}
 $entries=arc_ai_saved($pdo);
 $safe=static fn(array $r):array=>['id'=>$r['id']??'','provider'=>$r['provider']??'','model'=>$r['model']??'','enabled'=>!empty($r['enabled']),'key_set'=>!empty($r['secret']),'status'=>(($r['status']??'')==='ok' && time()-(int)($r['checked_at']??0)<600)?'ok':'untested'];
 if($action==='list'){$out=['ok'=>true,'catalog'=>$catalog,'items'=>array_map($safe,$entries)];}
 elseif($action==='models'){
  $provider=(string)($_POST['provider']??'');$id=(string)($_POST['id']??'');
  if(!isset($catalog[$provider]))throw new RuntimeException('Provedor inválido.');
  $entry=null;foreach($entries as $r)if(($r['id']??'')===$id&&($r['provider']??'')===$provider)$entry=$r;
  $key=trim((string)($_POST['api_key']??''));if($key==='')$key=$entry?arc_platform_decrypt((string)($entry['secret']??'')):'';
  $out=['ok'=>true,'models'=>arc_ai_models($provider,$key)];
 }
 elseif($action==='save'||$action==='test'){
  $provider=(string)($_POST['provider']??'');$model=trim((string)($_POST['model']??''));$id=(string)($_POST['id']??'');$key=trim((string)($_POST['api_key']??''));
  if(!isset($catalog[$provider])||$model===''||strlen($model)>180||($key!==''&&strlen($key)>512))throw new RuntimeException('Selecione provedor, modelo e chave válidos.');
  $existing=null;$index=null;foreach($entries as $i=>$r)if(($r['id']??'')===$id){$existing=$r;$index=$i;break;}
  if($existing!==null&&$existing['provider']!==$provider)throw new RuntimeException('Para trocar de provedor, crie outro registro.');
  if($key==='')$key=$existing?arc_platform_decrypt((string)($existing['secret']??'')):'';
  if($key==='')throw new RuntimeException('Informe a chave da API.');
  if($action==='test'){
   try{$response=arc_ai_request($provider,$key,'POST','/chat/completions',['model'=>$model,'messages'=>[['role'=>'user','content'=>'Responda apenas OK.']],'max_tokens'=>12]);}
   catch(Throwable $testError){if($index!==null){$entries[$index]['status']='error';$entries[$index]['checked_at']=time();arc_ai_store($pdo,$entries);}throw $testError;}
   if(!isset($response['choices'][0]['message']))throw new RuntimeException('Modelo não confirmou disponibilidade.');
   if($index!==null){$entries[$index]['status']='ok';$entries[$index]['checked_at']=time();arc_ai_store($pdo,$entries);}
   $out=['ok'=>true,'message'=>'Conexão e geração de resposta validadas.'];
  }else{
   $item=['id'=>$existing['id']??bin2hex(random_bytes(8)),'provider'=>$provider,'model'=>$model,'enabled'=>$existing['enabled']??true,'status'=>($existing&&$existing['model']===$model&&empty($_POST['api_key']))?($existing['status']??'untested'):'untested','checked_at'=>($existing&&$existing['model']===$model&&empty($_POST['api_key']))?($existing['checked_at']??0):0,'secret'=>$existing&&empty($_POST['api_key'])?$existing['secret']:arc_platform_encrypt($key)];
   if($index!==null)$entries[$index]=$item;else $entries[]=$item;
   arc_ai_store($pdo,$entries);$out=['ok'=>true,'message'=>'Provedor salvo.','item'=>$safe($item)];
  }
 }
 elseif($action==='toggle'||$action==='delete'){
  $id=(string)($_POST['id']??'');$found=false;
  foreach($entries as $i=>&$r){if(($r['id']??'')!==$id)continue;$found=true;if($action==='delete')unset($entries[$i]);else $r['enabled']=empty($r['enabled']);break;}unset($r);
  if(!$found)throw new RuntimeException('Registro não encontrado.');arc_ai_store($pdo,$entries);$out=['ok'=>true,'message'=>'Alteração salva.'];
 }else throw new RuntimeException('Ação desconhecida.');
 echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
