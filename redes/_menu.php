<?php
/* Caminho: redes/_menu.php | Versão: 2026.11.03.2
 * Exibe no topo apenas seções que não existem no footer da Gestão de Redes.
 * Footer: Servidor, Internet (velocidade), Painel,J Suporte e Mais.
 * Os subitens do Mais são acessíveis exclusivamente pelo botão do footer.
 * Mantém a checagem de permissões e a navegação de páginas complementares.
 */

require_once __DIR__ . '/../includes/error-logging.php';
$arcNetworkLinks = arc_navigation_items($pdo, 'header', (int)$networkId);
$arcAllowedMenu = in_array(($teamRole ?? ''), ['owner','system_admin'], true)
    ? null
    : arc_network_profile_rights($pdo, (int)$networkId, (int)arc_user_id());
$arcFooterSections = [
    'servidor', 'velocidade', 'painel', 'suporte',
    'liberacao', 'liberacoes', 'usuarios', 'permissoes',
    'preferencias', 'cadastros', 'cadastro', 'configuracao', 'configuracoes',
];
$arcTopItems = [];
$arcSeenTop = [];
// Fonte única dos atalhos: registros ativos e autorizados em arc_navigation_items.
// Não criar links fixos por arquivo, nome ou seção.
foreach ($arcNetworkLinks as $item) {
    $sectionName = basename((string)parse_url((string)$item['url'], PHP_URL_PATH), '.php');
    if (in_array($sectionName, $arcFooterSections, true)) continue;
    if ($arcAllowedMenu !== null && !in_array($sectionName, $arcAllowedMenu, true)) continue;
    if (isset($arcSeenTop[$sectionName])) continue;
    $arcSeenTop[$sectionName] = true;
    $arcTopItems[] = [
        'section' => $sectionName,
        'label' => $item['label'],
        'icon' => $item['icon'],
        'href' => arc_workspace_base_url().$item['url'],
        'fa' => false,
    ];
}
?>
<?php if ($arcTopItems): ?>
<nav class="arc-redes-menu" aria-label="Ferramentas adicionais da rede gerenciada">
<?php foreach ($arcTopItems as $item):
    $active = $item['section'] === ($arcSectionFile ?? '');
?>
<a class="arc-redes-menu-link <?=$active?'active':''?>" href="<?=arc_h($item['href'])?>" <?=$active?'aria-current="page"':''?> title="<?=arc_h($item['label'])?>">
    <span class="arc-db-icon" <?= $item['fa'] ? '' : 'data-icon="'.arc_h($item['icon']).'"' ?> aria-hidden="true"><?php if ($item['fa']): ?><i class="fa <?=arc_h($item['icon'])?>" aria-hidden="true"></i><?php endif; ?></span>
    <span><?=arc_h($item['label'])?></span>
</a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
