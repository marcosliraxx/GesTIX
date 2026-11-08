<?php
declare(strict_types=1);
require_once __DIR__.'/error-logging.php';
function arc_log_accept_batch(array $body,array $owner,string $kind='gestor'): array {
    $id=strtolower((string)($body['batch_id']??''));$events=$body['events']??null;
    if(!preg_match('/^[a-f0-9]{64}$/D',$id)||!is_array($events)||count($events)<1||count($events)>100)throw new InvalidArgumentException('Lote de diagnóstico inválido.');
    $account=(int)($owner['account_id']??0);$network=(int)($owner['network_id']??0);$gestor=(int)($owner['id']??0);
    if($account<1||$network<1||($kind==='gestor'&&$gestor<1))throw new InvalidArgumentException('Identidade inválida.');
    $scope='gestores/account-'.$account.'/network-'.$network.'/'.$kind.'-'.$gestor;
    if($kind==='installer')$scope='instaladores/account-'.$account.'/network-'.$network.'/key-'.substr((string)$owner['token_hash'],0,20);
    $dir=arc_log_directory($scope);if($dir===null)throw new RuntimeException('Armazenamento de diagnóstico indisponível.',503);
    $receipt=arc_log_directory($scope.'/receipts');if($receipt===null)throw new RuntimeException('Armazenamento indisponível.',503);
    $lock=@fopen($dir.'/.batch.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Diagnóstico temporariamente ocupado.',503);
    try{
        $done=$receipt.'/'.$id.'.ok';if(is_file($done))return ['ok'=>true,'batch_id'=>$id,'duplicate'=>true,'accepted'=>count($events)];
        $rateFile=$dir.'/.rate.json';$rate=is_file($rateFile)?json_decode((string)file_get_contents($rateFile),true):[];$window=intdiv(time(),60);
        if(($rate['window']??0)!==$window)$rate=['window'=>$window,'count'=>0];if(($rate['count']??0)>=30)throw new RuntimeException('Limite de diagnóstico por minuto atingido.',429);
        $clean=[];foreach($events as $event){
            if(!is_array($event)||!is_string($event['message']??null)||strlen($event['message'])>12000)throw new InvalidArgumentException('Evento inválido.');
            $source=(string)($event['source']??'runtime');if(strlen($source)>160||preg_match('/[\x00-\x1f]/',$source))throw new InvalidArgumentException('Origem inválida.');
            $clean[]=['source'=>arc_log_redact($source),'level'=>(string)($event['level']??'INFO'),'message'=>arc_log_redact($event['message'])];
        }
        foreach($clean as $event)if(!arc_log_write($event['source'],$event['level'],$event['message'],['batch_id'=>$id,'account_id'=>$account,'network_id'=>$network,'gestor_id'=>$gestor],$scope))throw new RuntimeException('Não foi possível preservar o diagnóstico.',503);
        // ACK somente depois da gravação. Identidade do arquivo é derivada pelo servidor.
        if(@file_put_contents($done,gmdate('c'),LOCK_EX)===false)throw new RuntimeException('Não foi possível confirmar o lote.',503);@chmod($done,0600);
        $rate['count']++;@file_put_contents($rateFile,json_encode($rate),LOCK_EX);@chmod($rateFile,0600);
        return ['ok'=>true,'batch_id'=>$id,'duplicate'=>false,'accepted'=>count($clean)];
    }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
function arc_log_cleanup_files(int $days=30,int $limit=200): int {
    $root=arc_log_directory();if($root===null)return 0;$removed=0;$cutoff=time()-max(7,min(365,$days))*86400;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){if($removed>=$limit)break;if($file->isLink()||!$file->isFile()||$file->getMTime()>=$cutoff||!preg_match('/\.(?:log|jsonl|ok)(?:\.[123])?$/D',$file->getFilename()))continue;if(@unlink($file->getPathname()))$removed++;}
    return $removed;
}
