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
echo "PASS: relatorios de restauracao, compatibilidade, IDs de drivers e origens de duplicados.\n";
