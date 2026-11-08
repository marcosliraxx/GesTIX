<?php
/* Caminho: includes/storage-policy.php | v1.0.0 - Retencao seletiva do armazenamento */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';


/** Somente origens previamente auditadas; nunca limpar contas, redes, credenciais ou inventario corrente. */
function arc_storage_catalog(): array {
 return [
  'browser'=>['label'=>'Historico do explorador de arquivos','table'=>'file_browser_requests','date'=>'created_at','days'=>7,'cap'=>25],
  'metrics'=>['label'=>'Historico de metricas de servidor','table'=>'gestor_telemetry','date'=>'captured_at','days'=>2,'cap'=>15],
  'telemetry'=>['label'=>'Telemetria bruta','table'=>'gestor_telemetry','date'=>'captured_at','days'=>14,'cap'=>30],
  'commands'=>['label'=>'Comandos finalizados','table'=>'gestor_commands','date'=>'created_at','days'=>45,'cap'=>30],
  'terminal'=>['label'=>'Saidas antigas do terminal','table'=>'gestor_terminal_jobs','date'=>'updated_at','days'=>14,'cap'=>15],
  'schedule'=>['label'=>'Execucoes de agendamentos','table'=>'support_schedule_runs','date'=>'created_at','days'=>60,'cap'=>20],
  'transfer'=>['label'=>'Metadados de transferencias encerradas','table'=>'support_file_transfers','date'=>'updated_at','days'=>30,'cap'=>20],
 ];
}
function arc_storage_policy(PDO $pdo): array {
 $base=['enabled'=>false,'batch'=>250,'files'=>true,'file_days'=>2,'max_files'=>100,'global_mb'=>0,'metrics_interval'=>20,'browser_payload_days'=>2,'categories'=>[]];
 foreach(arc_storage_catalog() as $key=>$item) $base['categories'][$key]=['enabled'=>true,'days'=>$item['days'],'max_mb'=>$item['cap']];
 $v=json_decode(arc_platform_setting($pdo,'platform_storage_policy',''),true);
 if(!is_array($v)) return $base;
 $base['enabled']=!empty($v['enabled']);
 $base['batch']=max(50,min(1000,(int)($v['batch']??250)));
 $base['files']=!empty($v['files']);
 $base['file_days']=max(1,min(30,(int)($v['file_days']??2)));
 $base['max_files']=max(10,min(500,(int)($v['max_files']??100)));
 $base['global_mb']=max(0,min(102400,(int)($v['global_mb']??0)));
 $base['metrics_interval']=max(10,min(300,(int)($v['metrics_interval']??20)));
 $base['browser_payload_days']=max(1,min(30,(int)($v['browser_payload_days']??2)));
 foreach($base['categories'] as $key=>$item){
  $entry=is_array($v['categories'][$key]??null)?$v['categories'][$key]:[];
  $base['categories'][$key]=['enabled'=>!array_key_exists('enabled',$entry)||!empty($entry['enabled']),
    'days'=>max(2,min(3650,(int)($entry['days']??$item['days']))),
    'max_mb'=>max(0,min(102400,(int)($entry['max_mb']??$item['max_mb'])))];
 }
 return $base;
}
function arc_storage_validate_post(array $v): array {
 $catalog=arc_storage_catalog();$out=['enabled'=>!empty($v['enabled']),'batch'=>250,'files'=>!empty($v['files']),
   'file_days'=>max(1,min(30,(int)($v['file_days']??2))),'max_files'=>100,'global_mb'=>max(0,min(102400,(int)($v['global_mb']??0))),'metrics_interval'=>max(10,min(300,(int)($v['metrics_interval']??20))),'browser_payload_days'=>max(1,min(30,(int)($v['browser_payload_days']??2))),'categories'=>[]];
 foreach($catalog as $key=>$entry){$out['categories'][$key]=[
  'enabled'=>isset($v['category_enabled'][$key]),'days'=>max(2,min(3650,(int)($v['days'][$key]??$entry['days']))),
  'max_mb'=>max(0,min(102400,(int)($v['max_mb'][$key]??$entry['cap'])))];}
 return $out;
}
function arc_storage_inventory(PDO $pdo): array {
 $stmt=$pdo->prepare('SELECT TABLE_NAME,COALESCE(DATA_LENGTH+INDEX_LENGTH,0) bytes,COALESCE(TABLE_ROWS,0) rows_est FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?');
 $stmt->execute([arc_prefix().'%']);$result=[];
 foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[$row['TABLE_NAME']]=$row;
 return $result;
}
/** Retorna a lista de arquivos descartaveis conhecidos. Nunca varre uploads, configuracoes, imagens nem backups. */
function arc_storage_old_files(int $olderThan,int $max): array {
 $result=[];
 $root=__DIR__.'/storage';
 $dirs=[['path'=>$root.'/remote-screen','regex'=>'/^(?:screen|alive|meta|view|viewer|input|input-queue|control|unlock|stop)-[0-9]+(?:\.(?:jpg|jpeg|json|jsonl|txt))?$/D'],
        ['path'=>$root.'/transfers','regex'=>'/^upload-[a-f0-9]{64}(?:\.[0-9]+|\.json)$/D']];
 foreach($dirs as $config){
  if(!is_dir($config['path'])||is_link($config['path']))continue;
  try{$it=new FilesystemIterator($config['path'],FilesystemIterator::SKIP_DOTS);}catch(Throwable $e){continue;}
  foreach($it as $file){
   if(count($result)>=$max)return $result;
   if(!$file->isFile()||$file->isLink()||!preg_match($config['regex'],$file->getFilename()))continue;
   if($file->getMTime()>=$olderThan)continue;
   $result[]=['path'=>$file->getPathname(),'bytes'=>$file->getSize()];
  }
 }
 return $result;
}
/** Transfere apenas registros ja encerrados e vencidos em lotes pequenos, simulacao por padrao. */
function arc_storage_run(PDO $pdo,bool $execute=false,bool $force=false): array {
 $policy=arc_storage_policy($pdo);
 if(!$policy['enabled']&&!$force) return ['enabled'=>false,'executed'=>false,'message'=>'Retencao automatica desativada. Ative na Central de manutencao.','tables'=>[],'files'=>['count'=>0,'bytes'=>0]];
 $catalog=arc_storage_catalog();$inventory=arc_storage_inventory($pdo);$report=[];
 $batch=$policy['batch'];
 // Tabelas com dependencias: terminal primeiro, requests antes de comandos.
 $order=['terminal','browser','transfer','schedule','metrics','telemetry','commands'];
 foreach($order as $key){
  $meta=$catalog[$key];$cfg=$policy['categories'][$key];if(!$cfg['enabled'])continue;
  $tableName=arc_prefix().$meta['table'];if(!isset($inventory[$tableName]))continue;
  $days=$cfg['days'];$cutoff=(new DateTimeImmutable('-'.$days.' days'))->format('Y-m-d H:i:s');
  $table=arc_table($meta['table']);$date='t.`'.$meta['date'].'`';
  $extra='';
  if($key==='metrics')$extra=" AND t.event_type='server_metrics'";
  if($key==='telemetry')$extra=" AND t.event_type IN ('speed_progress','telemetry','dashboard','network_scan')";
  if($key==='browser')$extra=" AND t.status NOT IN ('pending','running','processing','queued')";
  if($key==='terminal')$extra=' AND EXISTS (SELECT 1 FROM '.arc_table('gestor_commands')." c WHERE c.id=t.command_id AND c.status IN ('completed','failed','expired') AND c.created_at < ? )";
  if($key==='commands'){
   $extra=" AND t.status IN ('completed','failed','expired')";
   foreach(['file_browser_requests','support_file_transfers','gestor_terminal_jobs','support_schedule_runs','support_command_requests','support_remote_sessions'] as $related){
    $n=arc_prefix().$related;if(!isset($inventory[$n]))continue;
    $extra.=' AND NOT EXISTS (SELECT 1 FROM '.arc_table($related).' r WHERE r.command_id=t.id)';
   }
  }
  if($key==='transfer')$extra=" AND t.status NOT IN ('pending','ready','running','processing') AND t.expires_at < NOW() AND t.storage_key IS NULL";
  $args=[$cutoff];if($key==='terminal')$args[]=$cutoff;
  $where="$date < ?$extra";
  // Limite de MB e alerta de capacidade sao apenas indicadores; nunca violam o prazo minimo de retencao.
  $sql='SELECT COUNT(*) FROM '.$table.' t WHERE '.$where;
  $stmt=$pdo->prepare($sql);$stmt->execute($args);$eligible=(int)$stmt->fetchColumn();
  $removed=0;
  if($execute&&$eligible>0){
   $primary=$key==='terminal'?'command_id':'id';
   $find=$pdo->prepare('SELECT t.`'.$primary.'` FROM '.$table.' t WHERE '.$where.' ORDER BY t.`'.$meta['date'].'` ASC LIMIT '.$batch);
   $find->execute($args);$ids=array_map('intval',$find->fetchAll(PDO::FETCH_COLUMN));
   if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
    $del=$pdo->prepare('DELETE FROM '.$table.' WHERE `'.$primary.'` IN ('.$marks.')');$del->execute($ids);$removed=$del->rowCount();}
  }
  $report[$key]=['label'=>$meta['label'],'eligible'=>$eligible,'deleted'=>$removed,'days'=>$days,
    'size_bytes'=>(int)$inventory[$tableName]['bytes'],'max_mb'=>$cfg['max_mb']];
 }
 // Reduz o maior payload do backup: resultados antigos de navegação.
 // Mantem ID, comando, status, datas e caminho; somente o JSON de listagens encerradas expira.
 if(!empty($policy['categories']['browser']['enabled']) && isset($inventory[arc_prefix().'file_browser_requests'])){
   $old=(new DateTimeImmutable('-'.(int)$policy['browser_payload_days'].' days'))->format('Y-m-d H:i:s');
   $bt=arc_table('file_browser_requests');
   $q=$pdo->prepare('SELECT COUNT(*) FROM '.$bt." WHERE status IN ('completed','failed','expired','cancelled') AND created_at < ? AND result_json IS NOT NULL");
   $q->execute([$old]);$pending=(int)$q->fetchColumn();$compacted=0;
   if($execute && $pending){
      $q=$pdo->prepare('SELECT id FROM '.$bt." WHERE status IN ('completed','failed','expired','cancelled') AND created_at < ? AND result_json IS NOT NULL ORDER BY created_at,id LIMIT ".$batch);
      $q->execute([$old]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
      if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));
       $q=$pdo->prepare('UPDATE '.$bt.' SET result_json=NULL WHERE id IN ('.$marks.')');$q->execute($ids);$compacted=$q->rowCount();}
   }
   $report['browser_payload']=['label'=>'Resultados antigos do explorador (compactacao)','eligible'=>$pending,'deleted'=>$compacted,'days'=>$policy['browser_payload_days'],'size_bytes'=>(int)$inventory[arc_prefix().'file_browser_requests']['bytes'],'max_mb'=>0];
 }
 $files=['count'=>0,'bytes'=>0,'deleted'=>0];
 if($policy['files']){
  // Arquivos de sessoes remotas com ate 2 h podem ainda estar em uso, independentemente da politica.
  $older=time()-max(7200,$policy['file_days']*86400);
  foreach(arc_storage_old_files($older,$policy['max_files']) as $file){
   // Gestores ainda em execucao podem renovar arquivos: nao tocar em sessoes vivas.
   if(preg_match('~/(?:screen|alive|meta|view|viewer|input|input-queue|control|unlock|stop)-([0-9]+)(?:\.|$)~',$file['path'],$m)) {
     $s=$pdo->prepare('SELECT status,created_at FROM '.arc_table('gestor_commands').' WHERE id=? LIMIT 1');
     $s->execute([(int)$m[1]]);$command=$s->fetch(PDO::FETCH_ASSOC);
     // Sessao em execucao so e protegida enquanto ainda esta dentro da vida maxima.
     if(is_array($command) && in_array($command['status'],['pending','running'],true)
       && strtotime((string)$command['created_at'])>=time()-7200)continue;
   }
   $files['count']++;$files['bytes']+=$file['bytes'];
   if($execute&&@unlink($file['path']))$files['deleted']++;
  }
 }
 return ['enabled'=>$policy['enabled'],'executed'=>$execute,'tables'=>$report,'files'=>$files,'global_mb'=>$policy['global_mb']];
}
