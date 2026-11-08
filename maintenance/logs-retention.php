<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/central-logs.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
echo arc_log_cleanup_files(30,1000)." arquivos antigos de diagnóstico removidos.\n";
