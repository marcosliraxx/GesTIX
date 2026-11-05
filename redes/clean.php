<?php
/* Caminho: redes/clean.php | Clean: equivalente remoto do CCleaner (Health Check, Limpeza personalizada, Desinstalador, Duplicados,
   Nuvem, Otimização, Inicialização, Atualizador, Drivers, Disco, Restauração e Configurações). Interface única em _clean_ui.php. */
declare(strict_types=1);
$arcSectionFile='clean';
require __DIR__.'/_base.php';
if (!in_array((string)$teamRole,['owner','administrator'],true)) { http_response_code(403); exit('Permissão administrativa necessária.'); }
$rights=arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
if ($teamRole!=='owner' && $rights!==null && !in_array('clean',$rights,true)) { http_response_code(403); exit('Permissão Clean indisponível.'); }
$ccSections=['health','custom','uninstall','duplicates','cloud','backup','perf','startup','updater','drivers','disk','restore','windows_update','options'];
if ($teamRole!=='owner' && $rights!==null && !in_array('programas',$rights,true)) $ccSections=array_values(array_diff($ccSections,['uninstall']));
$ccStart=($_GET['section']??'')==='backup'?'backup':'health';
require __DIR__.'/_topo.php';
require __DIR__.'/_clean_ui.php';
require __DIR__.'/_rodape.php';
