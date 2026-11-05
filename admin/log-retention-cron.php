<?php
/* Caminho: admin/log-retention-cron.php | v1.0.0 */
declare(strict_types=1);
// Executável somente pela linha de comando. Não expõe token ou endpoint público.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
try {
 $pdo=arc_db();
 // Em execução recorrente, processa lotes sem degradar as demais consultas.
 $result=arc_log_cleanup($pdo,null,1000);
 fwrite(STDOUT,gmdate('c').' '.json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL);
}catch(Throwable $e){fwrite(STDERR,'Erro na retenção: '.$e->getMessage().PHP_EOL);exit(1);}
