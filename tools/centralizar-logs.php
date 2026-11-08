<?php
/* Uso opcional: php tools/centralizar-logs.php. Só move logs técnicos, sem seguir links. */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$moved=0;
foreach(['api','admin','redes','pages','includes','maintenance','gestores'] as $directory){
    $path=$root.'/'.$directory;if(!is_dir($path))continue;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if(!$file->isFile()||$file->isLink()||str_contains($file->getPathname(),'/storage/')||!($file->getFilename()==='error_log'||str_ends_with($file->getFilename(),'.log')))continue;
        $relative=substr($file->getPathname(),strlen($root)+1);$source=@fopen($file->getPathname(),'rb');if(!$source)continue;$ok=true;
        while(($line=fgets($source,16384))!==false){if(!arc_log_write('legacy','INFO',$line,['source'=>$relative],'legacy')){$ok=false;break;}}fclose($source);
        if($ok&&@unlink($file->getPathname()))$moved++;
    }
}
echo $moved." arquivos de diagnóstico centralizados em logs/legacy.\n";
