<?php
/* Caminho: maintenance/storage-retention.php | v1.0.0
 * Executar no cron do cPanel com PHP CLI: php /CAMINHO/maintenance/storage-retention.php --execute
 * --dry-run e a opcao padrao. --execute somente quando ativado no painel.
 */
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
require dirname(__DIR__).'/includes/storage-policy.php';
$execute=in_array('--execute',$argv,true);$force=false;
try {
 $pdo=arc_db();arc_ensure_user_schema($pdo);
 $lock='arc-storage-'.substr(hash('sha256',arc_prefix()),0,24);
 $q=$pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
 if((int)$q->fetchColumn()!==1){fwrite(STDERR,"Manutencao em execucao.\n");exit(0);}
 try{$result=arc_storage_run($pdo,$execute,$force);
  // Arquivos das transferencias expiradas sao gerenciados pela rotina nativa, que respeita status.
  if($execute&&!empty($result['enabled'])&&function_exists('arc_support_files_cleanup')) arc_support_files_cleanup($pdo);
  if($execute&&!empty($result['enabled'])) arc_cloud_purge_uploads($pdo);
  if($execute&&!empty($result['enabled'])) arc_platform_save_setting($pdo,'platform_storage_cron_last_run',gmdate('Y-m-d H:i:s').' UTC');
  fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
 }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}catch(Throwable $e){fwrite(STDERR,'Falha na manutencao: '.$e->getMessage().PHP_EOL);exit(1);}
