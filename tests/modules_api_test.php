<?php
// Caminho: tests/modules_api_test.php (validacao temporaria, nao distribuida)
declare(strict_types=1);
require dirname(__DIR__).'/includes/network-clean.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
[, $r]=arc_clean_sanitize_tool_report('restore',['points'=>[],'complete'=>false,'message'=>'Consulta falhou']);check($r['complete']===false,'Perdeu falha de consulta');
[, $r]=arc_clean_sanitize_tool_report('restore',['points'=>[]]);check($r['complete']===true,'Quebrou compatibilidade do relatorio antigo');
try{arc_clean_validate_tool(['tool'=>'drivers','action'=>'install','ids'=>['aaaaaaaaaaaaaaaa']],['outdated'=>[]]);throw new RuntimeException('Aceitou driver desconhecido');}catch(InvalidArgumentException $e){}
try{arc_clean_validate_tool(['tool'=>'duplicates','action'=>'scan','roots'=>['../../etc']],null);throw new RuntimeException('Aceitou origem arbitraria');}catch(InvalidArgumentException $e){}
$p=arc_clean_validate_tool(['tool'=>'duplicates','action'=>'scan','roots'=>['users'],'min_size_kb'=>100],null);check($p['min_size_kb']===100,'Perdeu tamanho minimo');
check(count(arc_clean_tools())===10,'Contrato de motores alterado');
$sets=arc_clean_health_sets();check(in_array('clipboard',$sets['privacy'],true)&&in_array('chkdsk_fragments',$sets['space'],true),'Categorias do PDF ausentes');
check(!array_filter($sets['privacy'],static fn($x)=>str_contains($x,'password')),'Senha incluida na automacao');
[, $health]=arc_clean_sanitize_tool_report('health',['privacy'=>[],'space'=>[],'speed'=>['startup_total'=>3,'startup_enabled'=>2],'registry'=>['issues'=>4,'complete'=>false],'optimization'=>true,'removed'=>8,'freed_bytes'=>512,'errors'=>1,'incomplete'=>true,'steps'=>['space','registry'],'free_space_delta'=>-2]);
check($health['registry']['complete']===false&&$health['removed']===8&&$health['freed_bytes']===512&&$health['steps']===['space'],'Relatorio final perdeu informacao ou aceitou etapa indevida');
echo "PASS: relatorios de restauracao, compatibilidade, IDs de drivers e origens de duplicados.\n";
