<?php
/* Caminho: redes/pentefino.php — compatibilidade de links antigos com as páginas independentes. */
declare(strict_types=1);
require_once __DIR__.'/../includes/pentefino-modules.php';
$section=(string)($_GET['section']??'health');
if($section==='backup')$file='backup';
else {$key=$section==='custom'&&($_GET['tab']??'')==='reg'?'registry':$section;$file=arc_pf_modules()[$key]['file']??'pentefino-analise';}
header('Location: '.$file.'.php?'.http_build_query(['network'=>(int)($_GET['network']??0),'gestor'=>(int)($_GET['gestor']??0)]),true,303);exit;
