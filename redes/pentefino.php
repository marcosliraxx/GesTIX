<?php
/* Caminho: redes/pentefino.php | Pente-Fino Web: mesma interface e permissões do Pente-Fino do gestor Windows. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

if(($_GET['section']??'')==='backup'){header('Location: backup.php?'.http_build_query(['network'=>(int)($_GET['network']??0),'gestor'=>(int)($_GET['gestor']??0)]),true,303);exit;}
$arcSectionFile='pentefino';
require __DIR__.'/_base.php';
if (!in_array((string)$teamRole,['owner','administrator','system_admin'],true)) { http_response_code(403); exit('Permissão administrativa necessária.'); }
$rights=arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
if (!in_array($teamRole,['owner','system_admin'],true) && $rights!==null && !in_array('pentefino',$rights,true)) { http_response_code(403); exit('Permissão Pente-Fino indisponível.'); }
$ccSections=['health','custom','uninstall','duplicates','cloud','perf','startup','updater','drivers','disk','restore','windows_update','options'];
if (!in_array($teamRole,['owner','system_admin'],true) && $rights!==null && !in_array('programas',$rights,true)) $ccSections=array_values(array_diff($ccSections,['uninstall']));
$ccRequested=(string)($_GET['section']??'health');
$ccStart=in_array($ccRequested,$ccSections,true)?$ccRequested:'health';
require __DIR__.'/_topo.php';
require __DIR__.'/_pentefino_ui.php';
require __DIR__.'/_rodape.php';
