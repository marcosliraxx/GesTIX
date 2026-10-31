<?php
/* Caminho: redes/_menu.php */
/**
 * Menu interno da rede gerenciada.
 * Fonte local fixa: não consulta registros ou permissões de menus do banco.
 * O acesso à rede selecionada continua validado em _base.php.
 */
$arcNetworkLinks = [
    ['servidor', 'Servidor', 'fa fa-server'],
    ['velocidade', 'Velocidade', 'fa fa-tachometer'],
    ['dispositivos', 'Dispositivos', 'fa fa-desktop'],
    ['mapa', 'Mapa', 'fa fa-sitemap'],
    ['conexao', 'Conexão', 'fa fa-wifi'],
    ['suporte', 'Suporte', 'fa fa-life-ring'],
    ['chamados', 'Chamados', 'fa fa-ticket'],
];
$arcNetworkBase = arc_workspace_base_url();
$arcNetworkQuery = ['network' => (int)$networkId];
if ((int)$selectedAgentId > 0) {
    $arcNetworkQuery['agent'] = (int)$selectedAgentId;
}
?>
<nav class="arc-redes-menu" aria-label="Seções da rede gerenciada">
<?php foreach ($arcNetworkLinks as [$section, $label, $icon]):
    $active = $arcSectionFile === $section;
    $href = $arcNetworkBase . 'redes/' . $section . '.php?' . http_build_query($arcNetworkQuery);
?>
    <a class="arc-redes-menu-link <?= $active ? 'active' : '' ?>"
       href="<?= arc_h($href) ?>"
       <?= $active ? 'aria-current="page"' : '' ?>
       title="<?= arc_h($label) ?>">
        <i class="<?= arc_h($icon) ?>" aria-hidden="true"></i>
        <span><?= arc_h($label) ?></span>
    </a>
<?php endforeach; ?>
</nav>
