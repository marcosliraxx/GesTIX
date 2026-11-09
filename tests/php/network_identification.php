<?php
declare(strict_types=1);
require __DIR__.'/../../includes/network-identification.php';
$count=0;
function check_identity(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$evidence=arc_identity_evidence(['name'=>'Dispositivo 246','hostname'=>'PRINT-FINANCEIRO','mac'=>'AA:BB:CC:11:22:33','vendor'=>'Fabricante','ports'=>[9100,631,9100]]);
check_identity($evidence['names']===['PRINT-FINANCEIRO'],'generic names excluded');
check_identity($evidence['oui']==='AA:BB:CC'&&!isset($evidence['ip']),'full MAC and private IP omitted from model evidence');
check_identity($evidence['ports']===[631,9100],'ports bounded and deduplicated');
$r=arc_identity_validate(['name'=>'PRINT-FINANCEIRO','type'=>'printer','confidence'=>0.95],$evidence);
check_identity($r['name']==='PRINT-FINANCEIRO'&&$r['type']==='printer','observed name accepted');
$r=arc_identity_validate(['name'=>'Nome Inventado','type'=>'printer','confidence'=>0.99],$evidence);
check_identity($r['name']==='','invented hostname rejected');
check_identity(arc_identity_validate(['name'=>'PRINT-FINANCEIRO','type'=>'printer','confidence'=>0.3],$evidence)===[],'uncertain inference ignored');
check_identity(arc_identity_validate(['name'=>'PRINT-FINANCEIRO','type'=>'execute shell','confidence'=>1],$evidence)===[],'unknown type rejected');
check_identity(arc_identity_generic('192.168.1.2')&&arc_identity_generic('Dispositivo 8')&&!arc_identity_generic('SERVIDOR-FISCAL'),'confirmed network names preserved');
echo "PASS $count network identification evidence assertions.\n";
