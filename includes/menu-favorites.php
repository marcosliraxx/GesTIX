<?php
/* Caminho: includes/menu-favorites.php — favoritos compartilhados por conta e rede. */
declare(strict_types=1);

function arc_menu_favorites_allowed(): array {
    return ['server','health','registry','startup','performance','restore','security','options','programs','uninstall','updater','drivers','windows_update','disk','duplicates','cloud','backup','backup_cloud','backup_local','backup_restore','backup_history','backup_settings','backup_drive','speed','devices','map','files','updates','history','support','tickets','remote','agenda','users','permissions','preferences','reports'];
}
function arc_menu_favorites_read(PDO $pdo,int $accountId,int $networkId): array {
    $prefix='menu_favorite_';
    $q=$pdo->prepare('SELECT preference_key,preference_value FROM '.arc_table('network_user_preferences').' WHERE account_id=? AND network_id=? AND preference_key LIKE ?');
    $q->execute([$accountId,$networkId,$prefix.'%']);
    $favorites=[];$initialized=false;
    $allowed=array_flip(arc_menu_favorites_allowed());
    foreach($q as $row){$key=substr((string)$row['preference_key'],strlen($prefix));if($key==='initialized'){$initialized=true;continue;}if(isset($allowed[$key])&&(string)$row['preference_value']==='1')$favorites[]=$key;}
    sort($favorites,SORT_STRING);
    return ['favorites'=>$favorites,'initialized'=>$initialized];
}
function arc_menu_favorites_write(PDO $pdo,int $accountId,int $networkId,array $request): array {
    $op=(string)($request['operation']??'');
    $allowed=array_flip(arc_menu_favorites_allowed());
    if($op==='set'){
        $key=(string)($request['key']??'');
        if(!isset($allowed[$key])||!is_bool($request['favorite']??null))throw new InvalidArgumentException('Favorito inválido');
        $q=$pdo->prepare('INSERT INTO '.arc_table('network_user_preferences').' (account_id,network_id,preference_key,preference_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE preference_value=VALUES(preference_value)');
        $q->execute([$accountId,$networkId,'menu_favorite_'.$key,$request['favorite']?'1':'0']);
    }elseif($op==='initialize'){
        $favorites=$request['favorites']??null;
        if(!is_array($favorites)||count($favorites)>36)throw new InvalidArgumentException('Lista inválida');
        foreach($favorites as $key)if(!is_string($key)||!isset($allowed[$key]))throw new InvalidArgumentException('Item inválido');
        // Migração inicial do arquivo local: só ocorre uma vez por rede.
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('INSERT IGNORE INTO '.arc_table('network_user_preferences').' (account_id,network_id,preference_key,preference_value) VALUES (?,?,?,?)');
            $q->execute([$accountId,$networkId,'menu_favorite_initialized','1']);
            if($q->rowCount()===1){foreach(array_unique($favorites) as $key)$q->execute([$accountId,$networkId,'menu_favorite_'.$key,'1']);}
            $pdo->commit();
        }catch(Throwable $e){$pdo->rollBack();throw $e;}
    }else throw new InvalidArgumentException('Operação inválida');
    // Após qualquer gravação, não permitir que outro cliente reinicialize a lista.
    $q=$pdo->prepare('INSERT IGNORE INTO '.arc_table('network_user_preferences').' (account_id,network_id,preference_key,preference_value) VALUES (?,?,?,?)');
    $q->execute([$accountId,$networkId,'menu_favorite_initialized','1']);
    return arc_menu_favorites_read($pdo,$accountId,$networkId);
}
