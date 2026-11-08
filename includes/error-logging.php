<?php
/* Captura comum a todas as entradas PHP; arquivos privados e separados por origem. */
declare(strict_types=1);
if (defined('ARC_ERROR_LOG_READY')) return;
define('ARC_ERROR_LOG_READY',true);
if (!defined('ARC_LOG_ROOT')) define('ARC_LOG_ROOT',dirname(__DIR__).'/logs');

function arc_log_redact(string $text): string {
    $text=preg_replace('/(?i)([?&](?:t|token|key|download_key|access_token|refresh_token)=)[^\s&"\'<>]+/','$1[oculto]',$text)??'';
    $text=preg_replace('/(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\s*["\']?\s*[:=]\s*)(["\'])(.*?)\2/','$1$2[oculto]$2',$text)??'';
    $text=preg_replace('/(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\s*["\']?\s*[:=]\s*["\']?)[^\s,"\'\r\n}]+/','$1[oculto]',$text)??'';
    $text=preg_replace('/(?i)\bBearer\s+[A-Za-z0-9._~+\/-]+/','Bearer [oculto]',$text)??'';
    $text=preg_replace('/\b[a-f0-9]{64}\b/i','[hash/chave oculto]',$text)??'';
    $text=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$text)??'';
    return function_exists('mb_strcut')?mb_strcut($text,0,6000,'UTF-8'):substr($text,0,6000);
}
function arc_log_directory(string $relative=''): ?string {
    if(str_contains($relative,'..')||!preg_match('~^[a-zA-Z0-9/_-]*$~D',$relative)||is_link(ARC_LOG_ROOT))return null;
    $path=rtrim(ARC_LOG_ROOT,'/').($relative!==''?'/'.$relative:'');
    if(!is_dir($path)&&!@mkdir($path,0700,true)&&!is_dir($path))return null;
    if(is_link($path))return null;
    return $path;
}
function arc_log_write(string $component,string $level,string $message,array $context=[],string $directory='platform'): bool {
    static $writing=false;if($writing)return false;$writing=true;
    try{
        $root=arc_log_directory($directory);if($root===null)return false;
        $file=$root.'/'.gmdate('Y-m-d').'.jsonl';$lock=@fopen($root.'/.write.lock','c');if(!$lock)return false;
        try{if(!flock($lock,LOCK_EX))return false;
            clearstatcache(true,$file);
            if(is_file($file)&&filesize($file)>5242880){for($n=3;$n>=1;$n--){$old=$file.'.'.$n;if(is_file($old)){if($n===3)@unlink($old);else @rename($old,$file.'.'.($n+1));}}@rename($file,$file.'.1');}
            $record=['received_at'=>gmdate('c'),'component'=>preg_replace('/[^a-zA-Z0-9_.-]/','',substr($component,0,80)),'level'=>in_array(strtoupper($level),['DEBUG','INFO','WARNING','ERROR','CRITICAL'],true)?strtoupper($level):'INFO','message'=>arc_log_redact($message)];
            if($context)$record['context']=json_decode(arc_log_redact((string)json_encode($context,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)),true)??['note'=>'Contexto omitido'];
            $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
            $ok=@file_put_contents($file,$line,FILE_APPEND)!==false;@chmod($file,0600);return $ok;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }finally{$writing=false;}
}
/** Mantém a assinatura de error_log, mas não permite desviar o destino para outras pastas. */
function arc_log_error(string $message,int $type=0,?string $destination=null,?string $headers=null): bool {
    return arc_log_write('php','ERROR',$message);
}
function arc_log_prepare_php(): void {
    $root=arc_log_directory();if($root===null)return;
    ini_set('log_errors','1');ini_set('error_log',$root.'/php-native-'.gmdate('Y-m-d').'.log');ini_set('display_errors','0');
    // PHP-FPM/cPanel leem este bloco antes de analisar a entrada PHP. Não altera outras diretivas.
    if(PHP_SAPI==='cli')return;
    $file=dirname(__DIR__).'/.user.ini';if(is_link($file))return;
    $begin='; BEGIN TI-DIGITAL CENTRAL LOGS';$end='; END TI-DIGITAL CENTRAL LOGS';
    $existing=is_file($file)?@file_get_contents($file):'';if(!is_string($existing))return;
    $base=preg_replace('/; BEGIN TI-DIGITAL CENTRAL LOGS.*?; END TI-DIGITAL CENTRAL LOGS\s*/s','',$existing)??$existing;
    $prepend=str_replace(['\\','"'],['/','\\"'],__FILE__);$target=str_replace(['\\','"'],['/','\\"'],$root.'/php-startup.log');
    $next=rtrim($base)."\n".$begin."\nauto_prepend_file=\"$prepend\"\nlog_errors=On\ndisplay_errors=Off\nerror_log=\"$target\"\n".$end."\n";
    if($next!==$existing){$tmp=@tempnam(dirname($file),'.ini-');if($tmp!==false){if(@file_put_contents($tmp,$next)!==false){@chmod($tmp,0600);@rename($tmp,$file);}else @unlink($tmp);}}
}
arc_log_prepare_php();
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
    if(!(error_reporting()&$severity))return false;
    arc_log_write('php',in_array($severity,[E_WARNING,E_USER_WARNING],true)?'WARNING':'ERROR',$message,['file'=>str_replace(dirname(__DIR__).'/','',$file),'line'=>$line,'severity'=>$severity]);
    return true;
});
register_shutdown_function(static function(): void {
    $last=error_get_last();if(!is_array($last)||!in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
    arc_log_write('php','CRITICAL',(string)$last['message'],['file'=>str_replace(dirname(__DIR__).'/','',(string)$last['file']),'line'=>(int)$last['line']]);
});
