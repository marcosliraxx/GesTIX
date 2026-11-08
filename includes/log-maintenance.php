<?php
/* Caminho: includes/log-maintenance.php | v1.0.0 | Política central de auditoria */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';


function arc_log_catalog(PDO $pdo): array {
    $known = [
        'account_audit'=>['Auditoria de contas','created_at'],
        'audit_logs'=>['Auditoria administrativa','created_at'],
        'account_login_attempts'=>['Tentativas de login (contas)','attempted_at'],
        'login_attempts'=>['Tentativas de login (admin)','attempted_at'],
    ];
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $result=[];
    foreach ($known as $key=>[$label,$preferred]) {
        $name=arc_prefix().$key;
        if(!in_array($name,$tables,true))continue;
        $cols=$pdo->query('SHOW COLUMNS FROM `'.$name.'`')->fetchAll(PDO::FETCH_COLUMN);
        $date=in_array($preferred,$cols,true)?$preferred:(in_array('created_at',$cols,true)?'created_at':null);
        $result[$key]=['table'=>$name,'name'=>$label,'date'=>$date,'columns'=>$cols];
    }
    return $result;
}
function arc_log_policies(PDO $pdo): array {
    $raw=arc_platform_setting($pdo,'platform_log_policies','');
    $data=json_decode($raw,true);
    return is_array($data)?$data:[];
}
function arc_log_policy(PDO $pdo,string $key): array {
    $all=arc_log_policies($pdo);
    $x=$all[$key]??[];
    return ['enabled'=>(bool)($x['enabled']??true),
        'days'=>max(0,min(3650,(int)($x['days']??90))),
        'max_mb'=>max(0,min(2048,(int)($x['max_mb']??0))),
        'details'=>(bool)($x['details']??true),'ip'=>(bool)($x['ip']??true)];
}
function arc_log_should_record(PDO $pdo,string $key): bool {
    try { return in_array($key,['login_attempts','account_login_attempts'],true) || arc_log_policy($pdo,$key)['enabled']; }catch(Throwable $e){return true;}
}
/** Remove por lotes registros antigos, mantendo no mínimo as últimas 24h e sem bloquear requisições. */
function arc_log_cleanup(PDO $pdo,?string $only=null,int $batch=400): array {
    $catalog=arc_log_catalog($pdo);$result=[];
    $batch=max(10,min(1000,$batch));
    foreach($catalog as $key=>$meta){
        if($only!==null && $only!==$key)continue;
        $policy=arc_log_policy($pdo,$key);$date=$meta['date'];
        if($date===null)continue;
        $table='`'.$meta['table'].'`';$col='`'.$date.'`';$removed=0;
        if($policy['days']>0){
            $cutoff=(new DateTimeImmutable('-'.$policy['days'].' days'))->format('Y-m-d H:i:s');
            $q=$pdo->prepare("DELETE FROM $table WHERE $col < ? ORDER BY $col ASC LIMIT $batch");$q->execute([$cutoff]);$removed+=$q->rowCount();
        }
        // O limite de MB usa tamanho físico da tabela inteira (aproximado) e remove só históricos >24h.
        if($policy['max_mb']>0){
            $s=$pdo->prepare('SELECT COALESCE(DATA_LENGTH+INDEX_LENGTH,0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $s->execute([$meta['table']]);$bytes=(int)$s->fetchColumn();
            if($bytes>$policy['max_mb']*1048576){
                $q=$pdo->prepare("DELETE FROM $table WHERE $col < DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY $col ASC LIMIT $batch");
                $q->execute();$removed+=$q->rowCount();
            }
        }
        $result[$key]=$removed;
    }
    return $result;
}
/** Nunca excluir automaticamente logs de segurança fora do cron configurado. */
