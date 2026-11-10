<?php
/* Caminho: redes/painel.php — recursos locais embutidos, sem dependência de cache/URL do navegador. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

$arcSectionFile = 'painel';
require __DIR__ . '/_base.php';
$panelRoot = dirname(__DIR__);
$panelCss = $panelRoot . '/assets/network-panel/panel.css';
$panelJs = $panelRoot . '/assets/network-panel/panel.js';
if (!is_readable($panelCss)) $panelCss = $panelRoot . '/gestores/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/styles.css';
if (!is_readable($panelJs)) $panelJs = $panelRoot . '/gestores/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/app.js';
require __DIR__ . '/_topo.php';
$inventoryUrl = arc_workspace_base_url() . 'pages/painel.php?ajax=network_devices&network=' . (int)$networkId;
?>
<?php if (is_readable($panelCss)): ?><style><?php $cssSource=(string)file_get_contents($panelCss);$cssStart=strpos($cssSource,'.np-panel{');echo $cssStart===false?$cssSource:substr($cssSource,$cssStart); ?></style><?php endif; ?>
<style>.arc-redes-conteudo>#network-panel{height:auto;flex:1 1 auto;min-height:min(360px,55dvh)}.arc-redes-page:has(#network-panel) .arc-redes-conteudo{display:flex;flex-direction:column;min-height:0;overflow:auto}</style>
<section id="network-panel" class="np-panel" data-transport="web" data-preferences-url="<?=arc_h(arc_workspace_base_url().'api/tool-preferences.php')?>" data-csrf="<?=arc_h(arc_csrf_token())?>" data-url="<?=arc_h($inventoryUrl)?>" aria-label="Painel de dispositivos da rede"><p role="status" style="padding:20px">Carregando o painel de dispositivos…</p></section>
<style>
/* Caminho: redes/painel.php — painel Web com composição igual à tela inicial do WinUI 3. */
@media(min-width:861px){
 .arc-redes-page:has(#network-panel) .arc-redes-conteudo{padding:0!important;overflow:hidden!important;background:#172536!important}
 .arc-redes-page:has(#network-panel) #network-panel{height:100%!important;min-height:0!important;max-height:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;background:#172536!important;--np-bg:#172536;--np-surface:#172536;--np-alt:#1e2c3e;--np-text:#e9f1fd;--np-muted:#adbac9;--np-line:#38485b;--np-accent:#b98570;color-scheme:dark}
 #network-panel .np-heading{display:none!important}
 #network-panel .np-toolbar{box-sizing:border-box!important;display:flex!important;align-items:center!important;min-height:46px!important;padding:6px 10px!important;margin:0!important;background:#1e2c3e!important;border:0!important;border-bottom:1px solid #394658!important;border-radius:0!important;gap:6px!important;flex-wrap:wrap!important;width:100%!important}
 #network-panel .np-toolbar :is(select,input:not([type=checkbox])){min-height:32px!important;max-height:36px!important;border-radius:7px!important}
 #network-panel .np-toolbar .np-button{min-height:32px!important;border-radius:7px!important}
 #network-panel .np-filters{flex:0 0 auto;display:flex;gap:5px}
 #network-panel .np-filter-label{display:inline!important}
 #network-panel .np-search{order:0!important;flex:1 1 auto!important;min-width:110px!important;background:#182638!important;max-width:none!important}
 #network-panel .np-actions{display:contents!important}
 #network-panel .np-history-toggle{order:999!important;margin-left:auto!important;flex:0 0 32px!important;width:32px!important;height:32px!important;min-width:32px!important;padding:0!important;display:grid!important;place-items:center!important}
 #network-panel .np-body{display:flex!important;min-height:0;flex:1}
 #network-panel .np-devices{flex:1;min-width:0}
 #network-panel .np-activity{display:none!important;width:min(320px,30%);flex:0 0 auto}
 #network-panel.arc-history-open .np-activity{display:flex!important}
 #network-panel .np-result{padding:11px 12px 2px!important;color:#b7d5f6!important}
 #network-panel .np-scroll{padding:10px 12px 16px!important;overflow-y:auto!important}
 #network-panel .np-tiles{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:7px!important}
 #network-panel .np-tile{min-height:118px!important}
 #network-panel .np-footer{display:none!important}
 #network-panel .np-button[aria-pressed=true]{background:#b98570!important;border-color:#b98570!important;color:#fff!important}
 #network-panel .np-select{background:#182638!important;color:#e9f1fd!important;border-color:#394658!important}
}
@media(max-width:1100px) and (min-width:861px){#network-panel .np-filter-label{display:none!important}#network-panel .np-toolbar{gap:4px!important;flex-wrap:wrap!important}#network-panel .np-tiles{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}}
</style>
<?php if (is_readable($panelJs)): ?>
<script><?php
$panelSource = (string)file_get_contents($panelJs);
// Mesmo no fallback, a plataforma usa a sessão HTTP. Nunca fica esperando uma bridge de aplicativo.
$panelSource = str_replace('const bridge = window.chrome?.webview;', 'const bridge = null;', $panelSource);
echo $panelSource;
?></script>
<?php else: ?><p role="alert">Recursos do Painel ausentes. Envie a pasta assets/network-panel completa à raiz do sistema.</p><?php endif; ?>
<?php require __DIR__ . '/_rodape.php'; ?>
