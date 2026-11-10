<?php
/* Caminho: includes/pentefino-modules.php */
declare(strict_types=1);
function arc_pf_modules(): array {
 return [
  'health'=>['file'=>'pentefino-analise','label'=>'Análise geral','icon'=>'fa-filter','native'=>'health'],
  'registry'=>['file'=>'pentefino-registros','label'=>'Registros','icon'=>'fa-wrench','native'=>'registry'],
  'startup'=>['file'=>'pentefino-inicializar','label'=>'Inicializar','icon'=>'fa-play','native'=>'startup'],
  'perf'=>['file'=>'pentefino-desempenho','label'=>'Desempenho','icon'=>'fa-tachometer','native'=>'performance'],
  'restore'=>['file'=>'pentefino-restauracao','label'=>'Restauração','icon'=>'fa-undo','native'=>'restore'],
  'uninstall'=>['file'=>'pentefino-desinstalar','label'=>'Desinstalar','icon'=>'fa-minus','native'=>'uninstall'],
  'updater'=>['file'=>'pentefino-atualizacao','label'=>'Atualização de programas','icon'=>'fa-refresh','native'=>'updater'],
  'drivers'=>['file'=>'pentefino-drivers','label'=>'Drivers','icon'=>'fa-microchip','native'=>'drivers'],
  'windows_update'=>['file'=>'pentefino-windows-up','label'=>'Windows Update','icon'=>'fa-download','native'=>'windows_update'],
  'disk'=>['file'=>'pentefino-discos','label'=>'Discos','icon'=>'fa-hdd-o','native'=>'disk'],
  'duplicates'=>['file'=>'pentefino-duplicidades','label'=>'Duplicidades','icon'=>'fa-files-o','native'=>'duplicates'],
  'cloud'=>['file'=>'pentefino-sincronizados','label'=>'Sincronizados','icon'=>'fa-cloud','native'=>'cloud'],
  'custom'=>['file'=>'pentefino-personalizado','label'=>'Limpeza personalizada','icon'=>'fa-magic','native'=>'custom'],
  'options'=>['file'=>'pentefino-configuracoes','label'=>'Configurações','icon'=>'fa-cog','native'=>'options'],
 ];
}
