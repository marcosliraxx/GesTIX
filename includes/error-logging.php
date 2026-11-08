<?php
/* Caminho: includes/error-logging.php */
declare(strict_types=1);
/** Log central: não intercepta respostas existentes nem publica detalhes ao visitante. */
if (!defined('ARC_ERROR_LOG_READY')) {
    define('ARC_ERROR_LOG_READY', true);
    $arcLogRoot = dirname(__DIR__).DIRECTORY_SEPARATOR.'logs';
    if (!is_dir($arcLogRoot)) @mkdir($arcLogRoot, 0700, true);
    if (is_dir($arcLogRoot) && is_writable($arcLogRoot)) {
        @ini_set('log_errors','1');
        @ini_set('error_log',$arcLogRoot.'/php-'.date('Y-m-d').'.log');
        // Não alterar error_reporting nem display_errors definidos pelo aplicativo.
        register_shutdown_function(static function () use ($arcLogRoot): void {
            $last = error_get_last();
            if (!is_array($last) || !in_array($last['type'] ?? 0, [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) return;
            $file = (string)($last['file'] ?? 'desconhecido');
            $msg = str_replace(["\r", "\n"], ' ', (string)($last['message'] ?? 'Erro fatal'));
            $msg = preg_replace('/(?i)(password|token|secret|api[_-]?key)\s*[=:]\s*[^\s,;]+/', '$1=[REDACTED]', $msg) ?? '[erro]';
            @error_log('['.date('c').'] FATAL '.($last['type'] ?? 0).' '.substr($file,0,280).':'.(int)($last['line'] ?? 0).' '.substr($msg,0,1200));
        });
    }
}
