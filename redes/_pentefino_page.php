<?php
/* Caminho: redes/_pentefino_page.php — acesso, configuração e moldura comuns dos módulos. */
declare(strict_types=1);
require_once __DIR__.'/../includes/pentefino-modules.php';
$arcSectionFile='pentefino';
require __DIR__.'/_base.php';
if(!in_array((string)$teamRole,['owner','administrator','system_admin'],true)){http_response_code(403);exit('Permissão administrativa necessária.');}
$rights=arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
if(!in_array($teamRole,['owner','system_admin'],true)&&$rights!==null&&!in_array('pentefino',$rights,true)){http_response_code(403);exit('Permissão Pente-Fino indisponível.');}
$modules=arc_pf_modules();
if(!isset($modules[$arcPfModule??''])){http_response_code(404);exit('Módulo não encontrado.');}
if(!in_array($teamRole,['owner','system_admin'],true)&&$rights!==null&&!in_array('programas',$rights,true))unset($modules['uninstall']);
if(!isset($modules[$arcPfModule])){http_response_code(403);exit('Permissão de programas necessária.');}
$ccSections=array_keys($modules);$ccStart=$arcPfModule;$arcPfRoutes=[];
foreach($modules as $key=>$module)$arcPfRoutes[$key]=arc_workspace_base_url().'redes/'.$module['file'].'.php?'.http_build_query(['network'=>(int)$networkId,'gestor'=>(int)$selectedGestorId]);
require __DIR__.'/_topo.php';
require __DIR__.'/_pentefino_ui.php';
require __DIR__.'/_rodape.php';
