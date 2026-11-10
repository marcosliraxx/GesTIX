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
    // Suporte, Chamados e Agenda: mesmas regras, tabelas e funções da plataforma (includes/support.php), limitadas a este computador.
    if(str_starts_with($op,'ticket')||str_starts_with($op,'schedule-')||str_starts_with($op,'support-')||str_starts_with($op,'service-')){
        require_once __DIR__.'/support.php';
        $states=arc_support_ticket_states();$priorities=arc_support_ticket_priorities();
        if($op==='support-services')return ['ok'=>true,'services'=>arc_support_schedule_actions()];
        if($op==='service-run'){
            $command=(string)($body['command']??'');if(!isset(arc_support_schedule_actions()[$command]))throw new InvalidArgumentException('Escolha um serviço da lista.');
            arc_billing_assert_active($pdo,$account);
            if(str_starts_with($command,'power_')&&!arc_support_confirm_password($pdo,$account,$body))throw new InvalidArgumentException('Confirme a ação e informe a senha da sua conta.');
            $q=$pdo->prepare('SELECT gestor_version FROM '.arc_table('network_gestores').' WHERE id=? AND network_id=? AND account_id=?');$q->execute([$id,$network,$account]);$version=(string)$q->fetchColumn();
            if($command==='windows_update'&&version_compare($version,'3.7.1','<'))throw new DomainException('Atualize o gestor antes de verificar o Windows Update.',422);
            if(str_starts_with($command,'power_')&&version_compare($version,'3.2.0','<'))throw new DomainException('Atualize o gestor antes de usar o controle de energia.',422);
            $q=$pdo->prepare('SELECT id FROM '.arc_table('gestor_commands').' WHERE gestor_id=? AND command_name=? AND ((status=\'pending\' AND created_at>DATE_SUB(NOW(),INTERVAL 3 MINUTE)) OR (status=\'running\' AND dispatched_at>DATE_SUB(NOW(),INTERVAL 45 MINUTE))) ORDER BY id DESC LIMIT 1');$q->execute([$id,$command]);$open=$q->fetchColumn();
            if($open)return ['ok'=>true,'command_id'=>(int)$open,'already_queued'=>true];
            $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('gestor_commands').' WHERE gestor_id=? AND status IN (\'pending\',\'running\')');$q->execute([$id]);if((int)$q->fetchColumn()>=100)throw new DomainException('Limite de 100 comandos pendentes neste gestor.',429);
            $options=str_starts_with($command,'power_')?['delay_seconds'=>5]:($command==='gestor_update'?['mode'=>'normal']:[]);
            $pdo->beginTransaction();try{$command_id=arc_support_queue_service($pdo,$account,$network,$id,$command,$account,$options);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            arc_user_audit($pdo,'gestor_command_queued','Comando '.$command.' solicitado no gestor '.$id.' da rede '.$network,$account);
            return ['ok'=>true,'command_id'=>$command_id,'status'=>'pending'];
        }
        if(str_starts_with($op,'schedule-')){
            $v=array_diff_key($body,array_flip(['site_code','machine_hash','op','action']));$v['network_id']=$network;$v['gestor_id']=$id;
            try{return arc_support_schedule_run($pdo,$op,$v,$account);}catch(RuntimeException $e){throw new DomainException($e->getMessage(),422);}
        }
        if($op==='ticket-create'){
            $title=trim((string)($body['title']??''));$description=trim((string)($body['description']??''));$priority=(string)($body['priority']??'normal');
            if($title===''||mb_strlen($title)>160||$description===''||mb_strlen($description)>12000||!isset($priorities[$priority]))throw new InvalidArgumentException('Informe título, descrição e prioridade válidos.');
            arc_billing_assert_active($pdo,$account);
            $q=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('support_tickets').' WHERE account_id=? AND network_id=? AND gestor_id=? AND created_at>?');
            $q->execute([$account,$network,$id,date('Y-m-d H:i:s',time()-600)]);
            if((int)$q->fetchColumn()>=5)throw new DomainException('Aguarde antes de abrir outro chamado neste computador.',429);
            $ticket=arc_support_ticket_create($pdo,$account,$network,$id,$account,$title,"Origem: gestor Windows #".$id."\n\n".$description,$priority,'gestor_created','Chamado aberto no gestor Windows #'.$id);
            return ['ok'=>true,'ticket_id'=>$ticket];
        }
        if($op==='tickets'){
            $list=arc_support_ticket_list($pdo,$account,$network,(string)($body['status']??'active'),(string)($body['q']??''),(int)($body['page']??1),$id);
            foreach($list['tickets'] as &$row){$row['status_label']=$states[$row['status']]??$row['status'];$row['priority_label']=$priorities[$row['priority']]??$row['priority'];$row['overdue']=strtotime((string)$row['due_at'])<time()&&!in_array($row['status'],['resolved','cancelled'],true);}unset($row);
            return ['ok'=>true]+$list+['states'=>$states,'priorities'=>$priorities];
        }
        if($op==='ticket-detail'){
            $detail=arc_support_ticket_detail($pdo,$account,$network,(int)($body['ticket_id']??0),$id);if(!$detail)throw new DomainException('Chamado não encontrado neste computador.',404);
            $detail['ticket']['status_label']=$states[$detail['ticket']['status']]??$detail['ticket']['status'];$detail['ticket']['priority_label']=$priorities[$detail['ticket']['priority']]??$detail['ticket']['priority'];
            return ['ok'=>true]+$detail;
        }
        if($op==='ticket-note'){
            $ticket=(int)($body['ticket_id']??0);$message=trim((string)($body['message']??''));if($message===''||mb_strlen($message)>12000)throw new InvalidArgumentException('Escreva uma anotação válida.');
            $detail=arc_support_ticket_detail($pdo,$account,$network,$ticket,$id);if(!$detail)throw new DomainException('Chamado não encontrado neste computador.',404);
            if(in_array($detail['ticket']['status'],['resolved','cancelled'],true))throw new InvalidArgumentException('Este chamado está encerrado. Abra um novo chamado.');
            $pdo->beginTransaction();try{arc_support_event($pdo,$ticket,$account,'gestor_note',$message);$pdo->prepare('UPDATE '.arc_table('support_tickets').' SET updated_at=NOW() WHERE id=?')->execute([$ticket]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            return ['ok'=>true];
        }
    }
    throw new InvalidArgumentException('Ação não permitida.');
}
