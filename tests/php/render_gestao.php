<?php
declare(strict_types=1);
function arc_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function arc_workspace_base_url():string{return 'https://app.fixture.test/';}
function arc_user_id():int{return 1;}
function arc_app_version():string{return 'automatica';}
function arc_table(string $name):string{return $name;}
function arc_navigation_items($pdo,$position,$network):array{return array_map(static fn($v)=>['url'=>'redes/'.$v[0].'.php?network=1&gestor=2','label'=>$v[1],'icon'=>'fa-'.$v[2]],[['painel','Painel','dashboard'],['servidor','Servidor','cog'],['clean','Clean','magic'],['programas','Programas','th'],['backup','Backup','cloud'],['velocidade','Internet','globe'],['dispositivos','Dispositivos','list'],['mapa','Mapa','sitemap']]);}
require __DIR__.'/../../includes/network-clean.php';
$pdo=new PDO('sqlite::memory:');$networkId=1;$selectedGestorId=2;$account=3;$csrf='csrf-fixture';$network=['name'=>'Rede de testes'];$teamRole='owner';$arcSectionFile='clean';$networkGestores=[['id'=>2,'machine_name'=>'COMPUTADOR-TESTE','status'=>'approved','last_seen_at'=>date('Y-m-d H:i:s')]];
$tool=$argv[1]??'analysis';
?><!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="https://app.fixture.test/assets/vendor/font-awesome/css/font-awesome.min.css"><link rel="stylesheet" href="https://app.fixture.test/assets/gestao/workspace.css"><style>:root{--primary:#9db4f4;--surface:#112a40;--surface-alt:#0c2134;--bg:#112a40;--text:#e8f4ff;--muted:#adc4db;--border:#30475e;--warning:#f3ca78;--header-h:0px;--onprimary:#071529}*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;font-family:Segoe UI,sans-serif}button,input{font:inherit}button{cursor:pointer}.app-main{height:100dvh!important}.gw-body{min-height:0}</style></head><body class="arc-page-redes"><main class="app-main arc-redes-page"><div class="arc-redes-shell">
<?php require __DIR__.'/../../redes/_menu.php';?>
<div id="arc-command-log" hidden></div><div class="gw-toolbar" id="gw-toolbar"><span id="gw-page-title"></span><div class="gw-page-actions" id="gw-page-actions"></div><button type="button" class="gw-icon" id="gw-activities-toggle" aria-label="Atividades" aria-pressed="false">!</button></div><div class="gw-body" id="gw-body"><div class="arc-redes-conteudo" id="arc-tab-scroll"><section class="pf-workspace"><nav class="pf-sidebar"><h2>PENTE-FINO</h2><a aria-current="page">Análise geral</a><a>Desempenho</a><a>Restauração</a></nav><div class="pf-content">
<?php if($tool==='analysis')require __DIR__.'/../../redes/_general_analysis.php';else{$ccSections=[$tool];$ccStart=$tool;require __DIR__.'/../../redes/_maintenance_tools.php';}?></div></section>
<?php require __DIR__.'/../../redes/_workspace_end.php';?></body></html>
