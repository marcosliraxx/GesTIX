<?php
/* Contrato da Análise geral compartilhada entre a plataforma e o gestor Windows. */
declare(strict_types=1);
function arc_general_defaults():array{return ['categories'=>array_keys(array_filter(arc_clean_catalog(),static fn($c)=>!empty($c['default']))),'registryChecks'=>array_keys(arc_clean_registry_checks()),'startupKinds'=>['run','folder','task','service','context'],'periodDays'=>0,'softwareUpdates'=>true,'windowsUpdates'=>true];}
function arc_general_preferences(mixed $p):array{
 if(!is_array($p))throw new InvalidArgumentException('Configurações inválidas');$out=[];
 foreach(['categories'=>array_keys(arc_clean_catalog()),'registryChecks'=>array_keys(arc_clean_registry_checks()),'startupKinds'=>['run','folder','task','service','context']] as $key=>$allowed){$value=$p[$key]??null;if(!is_array($value)||count($value)>count($allowed)||count(array_unique($value,SORT_REGULAR))!==count($value))throw new InvalidArgumentException('Seleção inválida');foreach($value as $id)if(!is_string($id)||!in_array($id,$allowed,true))throw new InvalidArgumentException('Verificação desconhecida');$out[$key]=array_values($value);}
 if(!is_int($p['periodDays']??null)||!in_array($p['periodDays'],[0,7,15,30,180,365],true)||!is_bool($p['softwareUpdates']??null)||!is_bool($p['windowsUpdates']??null))throw new InvalidArgumentException('Período ou opções inválidas');foreach(['periodDays','softwareUpdates','windowsUpdates'] as $key)$out[$key]=$p[$key];if(!$out['categories']&&!$out['registryChecks']&&!$out['startupKinds']&&!$out['softwareUpdates']&&!$out['windowsUpdates'])throw new InvalidArgumentException('Ative ao menos uma verificação');return $out;
}
function arc_general_request(array $body,?array $last):array{
 $action=(string)($body['action']??'');$out=['tool'=>'general','action'=>$action];
 if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',(string)($body['requestId']??'')))throw new InvalidArgumentException('Identificação da solicitação inválida');$out['requestId']=$body['requestId'];
 if($action==='scan'){$out['preferences']=arc_general_preferences($body['preferences']??null);return $out;}
 if($action!=='fix'||!$last||!empty($last['historical'])||!empty($last['report']['cancelled'])||($body['reportId']??'')!==($last['report']['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',(string)($body['reportId']??'')))throw new InvalidArgumentException('Faça nova análise antes de corrigir');
 $at=strtotime((string)($last['report']['at']??''));if(!$at||$at<time()-1200)throw new InvalidArgumentException('Análise expirada');$modules=$body['modules']??null;if(!is_array($modules)||!$modules||count($modules)>4||count(array_unique($modules,SORT_REGULAR))!==count($modules))throw new InvalidArgumentException('Selecione áreas válidas');
 $allowed=[];foreach((array)($last['report']['modules']??[]) as $module)if(($module['fixable']??0)>0)$allowed[]=$module['id'];foreach($modules as $id)if(!is_string($id)||!in_array($id,$allowed,true))throw new InvalidArgumentException('Área sem correção automática');
 $out+=['reportId'=>$body['reportId'],'modules'=>array_values($modules),'createRestorePoint'=>($body['createRestorePoint']??false)===true];return $out;
}
function arc_general_sanitize_report(array $r):array{
 $report=$r['report']??null;$out=['tool'=>'general','requestId'=>arc_clean_text($r['requestId']??'',64),'historical'=>!empty($r['historical']),'notice'=>arc_clean_text($r['notice']??'',600),'preferences'=>arc_general_preferences($r['preferences']??arc_general_defaults())];
 if(!is_array($report)||!preg_match('/^[a-f0-9]{32}$/D',(string)($report['id']??'')))throw new InvalidArgumentException('Resumo da análise inválido');
 $clean=['id'=>$report['id'],'at'=>arc_clean_text($report['at']??'',40),'mode'=>'detailed','cancelled'=>!empty($report['cancelled']),'complete'=>!empty($report['complete']),'seconds'=>max(0,min(7200,(float)($report['seconds']??0))),'modules'=>[]];
 foreach(array_slice((array)($report['modules']??[]),0,4) as $m){if(!is_array($m)||!in_array($m['id']??'',['clean','registry','startup','updates'],true))continue;$row=['id'=>$m['id'],'title'=>arc_clean_text($m['title']??'',80),'complete'=>!empty($m['complete']),'notes'=>array_map(static fn($n)=>arc_clean_text($n,600),array_slice((array)($m['notes']??[]),0,30)),'categories'=>[]];foreach(['count','fixable','review','informational','bytes'] as $key)$row[$key]=arc_clean_count($m[$key]??0);
 foreach(array_slice((array)($m['categories']??[]),0,100) as $c){if(!is_array($c))continue;$row['categories'][]=['kind'=>arc_clean_text($c['kind']??'',40),'title'=>isset($c['title'])?arc_clean_text($c['title'],180):null,'count'=>arc_clean_count($c['count']??0),'fixable'=>arc_clean_count($c['fixable']??0),'bytes'=>arc_clean_count($c['bytes']??0)];}$clean['modules'][]=$row;}
 foreach(['count','fixable','review','informational','bytes'] as $key)$clean[$key]=array_sum(array_column($clean['modules'],$key));$out['report']=$clean;
 if(is_array($r['outcome']??null)){$o=$r['outcome'];$out['outcome']=['kind'=>'fix','session'=>arc_clean_text($o['session']??'',40),'applied'=>arc_clean_count($o['applied']??0),'failed'=>arc_clean_count($o['failed']??0),'pending'=>arc_clean_count($o['pending']??0),'cancelled'=>!empty($o['cancelled']),'countsPartial'=>!empty($o['countsPartial']),'errors'=>array_map(static fn($v)=>arc_clean_text($v,600),array_slice((array)($o['errors']??[]),0,8))];}return $out;
}
function arc_general_invalidate(PDO $pdo,int $gestor,int $network,int $account):void{
 $last=arc_clean_report_get($pdo,$gestor,$network,$account,'general');if(!$last)return;$report=$last['report'];$report['historical']=true;$report['notice']='Operação interrompida. Faça nova análise antes de corrigir.';arc_clean_report_save($pdo,$gestor,$network,$account,'general',$report);
}
