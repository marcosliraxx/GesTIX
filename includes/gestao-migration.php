<?php
/* Migração limitada a arquivos substituídos: nunca remove dados, configurações ou arquivos modificados pelo cliente. */
declare(strict_types=1);
function arc_gestao_remove_obsolete(string $root):array{
 $allow=['redes/_clean_ui.php'=>'bc05968e93a56b04b793a3f2d7a38c6aeec7aaf77e4635bb36b278d95d35bb25','redes/_clean_ui_js.php'=>'b97c57e423db7df9b01621d2a673e335fa4491ad914a36bd8f1152158506bc10','redes/_clean_ui_js_extra.php'=>'3c5d5aaa8e3ea3ada2fb84fb4b9e18d200e243254762599f216a92c8de4e26a1'];$result=['removed'=>[],'preserved'=>[]];$base=realpath($root);if($base===false||!is_file($base.'/redes/_general_analysis.php')||!is_file($base.'/redes/_maintenance_tools.php'))return $result;
 foreach($allow as $relative=>$expected){$path=$base.DIRECTORY_SEPARATOR.$relative;if(!is_file($path))continue;if(is_link($path)||realpath(dirname($path))!==$base.DIRECTORY_SEPARATOR.'redes'||!hash_equals($expected,(string)hash_file('sha256',$path))){$result['preserved'][]=$relative;continue;}if(@unlink($path))$result['removed'][]=$relative;else $result['preserved'][]=$relative;}
 return $result;
}
