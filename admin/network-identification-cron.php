<?php
/* Complemento CLI para hospedagens sem PHP-FPM. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/network-inventory.php';
try{arc_identity_process(arc_db());fwrite(STDOUT,"Identificação de rede processada.\n");}
catch(Throwable $error){arc_log_error('Identificação de rede: '.$error->getMessage());fwrite(STDERR,"Não foi possível processar a identificação. Consulte os logs.\n");exit(1);}
