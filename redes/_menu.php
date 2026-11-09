<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/error-logging.php';
$gwGroups=['home'=>['Início','home'],'maintenance'=>['Manutenção','wrench'],'software'=>['Aplicativos','th'],'backup'=>['Backup','save'],'network'=>['Rede','globe'],'service'=>['Atendimento','question'],'settings'=>['Gestão','cog']];
$gwMap=['painel'=>'home','servidor'=>'maintenance','clean'=>'maintenance','programas'=>'software','backup'=>'backup','velocidade'=>'network','dispositivos'=>'network','mapa'=>'network','arquivos'=>'network','suporte'=>'service','remoto'=>'service','chamados'=>'service','agenda'=>'service'];
$gwItems=[];$gwSeen=[];$gwRights=in_array(($teamRole??''),['owner','system_admin'],true)?null:arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
foreach(arc_navigation_items($pdo,'header',(int)$networkId) as $item){
 $section=basename((string)parse_url((string)$item['url'],PHP_URL_PATH),'.php');
 if($section===''||isset($gwSeen[$section])||($gwRights!==null&&!in_array($section,$gwRights,true)&&!(in_array($section,['remoto','arquivos','chamados','agenda'],true)&&in_array('suporte',$gwRights,true))))continue;
 $gwSeen[$section]=true;$gwItems[]=['key'=>$section,'group'=>$gwMap[$section]??'settings','label'=>$section==='clean'?'Pente-Fino':(string)$item['label'],'icon'=>(string)$item['icon'],'href'=>arc_workspace_base_url().(string)$item['url']];
}
?>
<nav class="gw-ribbon" id="gw-ribbon" aria-label="Menus da gestão" data-current="<?=arc_h((string)($arcSectionFile??''))?>" data-scope="<?=arc_h((string)arc_user_id().':'.(string)$networkId)?>" data-items="<?=arc_h(json_encode($gwItems,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))?>" data-groups="<?=arc_h(json_encode($gwGroups,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))?>">
 <div class="gw-categories" id="gw-categories"></div><div class="gw-submenus" id="gw-submenus"></div>
</nav>
