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
<section id="network-panel" class="np-panel" data-transport="web" data-url="<?=arc_h($inventoryUrl)?>" aria-label="Painel de dispositivos da rede"><p role="status" style="padding:20px">Carregando o painel de dispositivos…</p></section>
<?php if (is_readable($panelJs)): ?>
<script><?php
$panelSource = (string)file_get_contents($panelJs);
// Mesmo no fallback, a plataforma usa a sessão HTTP. Nunca fica esperando uma bridge de aplicativo.
$panelSource = str_replace('const bridge = window.chrome?.webview;', 'const bridge = null;', $panelSource);
echo $panelSource;
?></script>
<?php else: ?><p role="alert">Recursos do Painel ausentes. Envie a pasta assets/network-panel completa à raiz do sistema.</p><?php endif; ?>
<?php require __DIR__ . '/_rodape.php'; ?>
