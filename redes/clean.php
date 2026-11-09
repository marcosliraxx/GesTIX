<?php
/* Pente-Fino: a Análise geral compartilha a interface e o motor do gestor Windows. */
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
if(($_GET['section']??'')==='backup'){header('Location: backup.php?'.http_build_query(['network'=>(int)($_GET['network']??0),'gestor'=>(int)($_GET['gestor']??0)]),true,303);exit;}
$arcSectionFile='clean';require __DIR__.'/_base.php';
if(!in_array((string)$teamRole,['owner','administrator','system_admin'],true)){http_response_code(403);exit('Permissão administrativa necessária.');}
$rights=arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());if(!in_array($teamRole,['owner','system_admin'],true)&&$rights!==null&&!in_array('clean',$rights,true)){http_response_code(403);exit('Permissão de manutenção indisponível.');}
$pfTools=['analysis'=>['Análise geral','filter'],'perf'=>['Desempenho','tachometer'],'restore'=>['Restauração','undo'],'startup'=>['Revisar inicialização','play'],'uninstall'=>['Desinstalar','trash-o'],'updater'=>['Atualizações','refresh'],'drivers'=>['Drivers','list'],'windows_update'=>['Windows Update','download'],'disk'=>['Discos','hdd-o'],'duplicates'=>['Duplicados','files-o'],'cloud'=>['Sincronizados','cloud'],'options'=>['Configurações','cog']];
if(!in_array($teamRole,['owner','system_admin'],true)&&$rights!==null&&!in_array('programas',$rights,true))unset($pfTools['uninstall']);
$pfSection=(string)($_GET['section']??'analysis');if(!isset($pfTools[$pfSection]))$pfSection='analysis';require __DIR__.'/_topo.php';
?>
<section class="pf-workspace" aria-label="Pente-Fino"><nav class="pf-sidebar" aria-label="Ferramentas do Pente-Fino"><h2>PENTE-FINO</h2><?php foreach($pfTools as $key=>[$label,$icon]):?><a href="<?=arc_h(arc_workspace_base_url().'redes/clean.php?'.http_build_query(['network'=>(int)$networkId,'gestor'=>(int)$selectedGestorId,'section'=>$key]))?>" <?=$pfSection===$key?'aria-current="page"':''?>><i class="fa fa-<?=arc_h($icon)?>" aria-hidden="true"></i><span><?=arc_h($label)?></span></a><?php endforeach;?></nav><div class="pf-content">
<?php
if($pfSection==='analysis')require __DIR__.'/_general_analysis.php';
else{$ccSections=[$pfSection];$ccStart=$pfSection;require __DIR__.'/_maintenance_tools.php';}
?></div></section>
<?php require __DIR__.'/_rodape.php';?>
