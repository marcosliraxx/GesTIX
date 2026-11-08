<?php
/* Caminho: includes/gestor-workspace.php */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';

/** Dados do gestor autenticado: nunca aceita conta/rede/computador vindos do corpo. */
function arc_gestor_workspace(PDO $pdo, array $gestor, array $body): array {
    $account=(int)$gestor['account_id'];$network=(int)$gestor['network_id'];$id=(int)$gestor['id'];
    $q=$pdo->prepare('SELECT name,logo_file,address FROM '.arc_table('managed_networks').' WHERE id=? AND account_id=?');
    $q->execute([$network,$account]);$net=$q->fetch(PDO::FETCH_ASSOC);
    if(!$net)throw new DomainException('Rede indisponível.',403);
    $op=(string)($body['op']??'info');
    if($op==='info'){
        $logo='';$file=(string)($net['logo_file']??'');
        if(preg_match('/^[a-f0-9]{40}\.(png|jpg|webp)$/D',$file)){
            $path=ARC_PRIVATE_DIR.'/storage/network-logos/'.$file;
            if(is_file($path)&&!is_link($path)&&filesize($path)<=2097152){
                $bytes=file_get_contents($path);$image=@getimagesizefromstring($bytes===false?'':$bytes);
                if($bytes!==false&&$image&&in_array($image[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true))$logo=base64_encode($bytes);
            }
        }
        return ['ok'=>true,'network_id'=>$network,'gestor_id'=>$id,'network_name'=>(string)$net['name'],'logo_base64'=>$logo];
    }
    if($op==='dashboard'){
        require_once __DIR__.'/network-inventory.php';
        return ['ok'=>true,'name'=>(string)$net['name']]+arc_inventory_dashboard($pdo,$account,$network);
    }
    if($op==='connection-map'){
        $points=[];
        try{$q=$pdo->prepare('SELECT kind,gestor_id,latitude,longitude,accuracy_m,source,updated_at FROM '.arc_table('network_geo_points').' WHERE network_id=? AND account_id=? AND (gestor_id=0 OR gestor_id=?) ORDER BY updated_at DESC');$q->execute([$network,$account,$id]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $point)if(!isset($points[$point['kind']]))$points[$point['kind']]=$point;}catch(PDOException $e){/* Sem ponto confirmado: a interface informa a ausência. */}
        return ['ok'=>true,'network_name'=>(string)$net['name'],'address'=>(string)($net['address']??''),'points'=>$points];
    }
    if($op==='ticket-create'){
        $title=trim((string)($body['title']??''));$description=trim((string)($body['description']??''));$priority=(string)($body['priority']??'normal');
        if($title===''||mb_strlen($title)>160||$description===''||mb_strlen($description)>12000||!in_array($priority,['low','normal','high','urgent'],true))throw new InvalidArgumentException('Informe título, descrição e prioridade válidos.');
        arc_billing_assert_active($pdo,$account);
        $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('support_tickets').' WHERE account_id=? AND network_id=? AND gestor_id=? AND created_at>?');
        $q->execute([$account,$network,$id,date('Y-m-d H:i:s',time()-600)]);
        if((int)$q->fetchColumn()>=5)throw new DomainException('Aguarde antes de abrir outro chamado neste computador.',429);
        $hours=['low'=>24,'normal'=>8,'high'=>4,'urgent'=>1][$priority];
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('INSERT INTO '.arc_table('support_tickets').' (account_id,network_id,gestor_id,created_by,title,description,priority,due_at) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$account,$network,$id,$account,$title,"Origem: gestor Windows #".$id."\n\n".$description,$priority,date('Y-m-d H:i:s',time()+$hours*3600)]);
            $ticket=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO '.arc_table('support_events').' (ticket_id,actor_id,event_type,message,minutes_spent) VALUES (?,?,?,?,?)')->execute([$ticket,$account,'gestor_created','Chamado aberto no gestor Windows #'.$id,0]);
            $pdo->commit();
            return ['ok'=>true,'ticket_id'=>$ticket];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($op==='tickets'){
        $q=$pdo->prepare('SELECT id,title,description,priority,status,created_at,updated_at,due_at FROM '.arc_table('support_tickets').' WHERE account_id=? AND network_id=? AND gestor_id=? ORDER BY id DESC LIMIT 100');
        $q->execute([$account,$network,$id]);return ['ok'=>true,'tickets'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }
    throw new InvalidArgumentException('Ação não permitida.');
}
