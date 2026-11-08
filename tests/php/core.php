<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/ti-logs-'.bin2hex(random_bytes(8));define('ARC_LOG_ROOT',$root);
require __DIR__.'/../../includes/error-logging.php';require __DIR__.'/../../includes/central-logs.php';require __DIR__.'/../../includes/visual-themes.php';
$count=0;function check(bool $ok,string $name):void{global $count;$count++;if(!$ok)throw new RuntimeException($name);}
$secret=str_repeat('a',64);$text=arc_log_redact('password="many secret words" token='.$secret.' https://fixture.test/?t='.$secret.' Bearer abc.def');check(!str_contains($text,$secret)&&!str_contains($text,'secret words')&&!str_contains($text,'abc.def'),'redaction before storage');
check(arc_log_directory('../elsewhere')===null,'no path traversal');check(arc_log_directory('/bad..name')===null,'no embedded traversal');
$owner=['account_id'=>1,'network_id'=>2,'id'=>3];$batch=['batch_id'=>str_repeat('b',64),'events'=>[['source'=>'runtime','level'=>'ERROR','message'=>'download_key='.$secret]]];
$a=arc_log_accept_batch($batch,$owner);check($a['ok']&&$a['accepted']===1&&!$a['duplicate'],'initial durable acceptance');$b=arc_log_accept_batch($batch,$owner);check($b['duplicate'],'idempotent retry');
$path=$root.'/gestores/account-1/network-2/gestor-3/'.gmdate('Y-m-d').'/'.$batch['batch_id'].'.jsonl';$stored=file_get_contents($path);check(substr_count($stored,"\n")===1,'retry does not duplicate lines');check(!str_contains($stored,$secret),'no raw credential on disk');check(json_decode(trim($stored),true)['events'][0]['message']==='download_key=[oculto]','structured JSON remains valid');
$c=arc_log_accept_batch($batch,['account_id'=>9,'network_id'=>8,'id'=>7]);check(!$c['duplicate'],'different tenant has isolated receipts');check(is_file($root.'/gestores/account-9/network-8/gestor-7/'.gmdate('Y-m-d').'/'.$batch['batch_id'].'.jsonl'),'tenant directories isolated');
try{arc_log_accept_batch(['batch_id'=>'../unsafe','events'=>$batch['events']],$owner);check(false,'bad id rejected');}catch(InvalidArgumentException){check(true,'bad id rejected');}
try{arc_log_accept_batch(['batch_id'=>str_repeat('c',64),'events'=>array_fill(0,101,$batch['events'][0])],$owner);check(false,'batch bound');}catch(InvalidArgumentException){check(true,'batch bound');}
check(count(arc_theme_catalog())===10,'ten themes retained');foreach(arc_theme_catalog()as $id=>$theme){foreach(['light','dark']as $mode){foreach(['text','surface','accent','onaccent','muted']as $key)check(preg_match('/^#[a-f0-9]{6}$/i',$theme['variants'][$mode][$key])===1,'valid role '.$id);}}
$old=$root.'/old.log';file_put_contents($old,'old');touch($old,time()-40*86400);check(arc_log_cleanup_files(30)>0&&!file_exists($old),'retention bounded to diagnostic files');
echo "PASS $count PHP catalog, protected storage, redaction and retry assertions.\n";
