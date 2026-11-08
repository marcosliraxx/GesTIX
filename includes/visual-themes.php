<?php
declare(strict_types=1);
require_once __DIR__.'/error-logging.php';
/** Catálogo único, distribuído também ao gestor e ao instalador. */
function arc_theme_catalog(): array {
 static $themes=null;if($themes!==null)return $themes;
 $path=dirname(__DIR__).'/assets/themes/catalog.json';$json=file_get_contents($path);$catalog=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($catalog['themes']??null)||count($catalog['themes'])<10)throw new RuntimeException('Catálogo de temas incompleto.');
 foreach($catalog['themes'] as $id=>$theme){if(!preg_match('/^[a-z0-9_-]{1,40}$/D',(string)$id))throw new RuntimeException('Tema inválido.');foreach(['light','dark'] as $mode)foreach(['bg','surface','alt','text','muted','accent','border','onaccent'] as $role)if(!preg_match('/^#[a-f0-9]{6}$/Di',(string)($theme['variants'][$mode][$role]??'')))throw new RuntimeException('Paleta inválida.');}
 return $themes=$catalog['themes'];
}
