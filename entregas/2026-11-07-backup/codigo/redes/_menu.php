<?php
/* Caminho: redes/_menu.php | Versão: 2026.11.03.2
 * Exibe no topo apenas seções que não existem no footer da Gestão de Redes.
 * Footer: Servidor, Internet (velocidade), Painel,J Suporte e Mais.
 * Os subitens do Mais são acessíveis exclusivamente pelo botão do footer.
 * Mantém a checagem de permissões e a navegação de páginas complementares.
 */
$arcNetworkLinks = arc_navigation_items($pdo, 'header', (int)$networkId);
$arcAllowedMenu = ($teamRole ?? '') === 'owner'
    ? null
    : arc_network_profile_rights($pdo, (int)$networkId, (int)arc_user_id());
$arcSupportPages = [
    'clean' => ['Clean', 'fa-magic'],'backup'=>['Backup','fa-cloud-upload'],
    'remoto'   => ['Remoto', 'fa-eye'],
    'arquivos' => ['Arquivos', 'fa-folder-open'],
    'chamados' => ['Chamados', 'fa-ticket'],
    'agenda'   => ['Agenda', 'fa-calendar'],
];
// Itens já presentes no footer, incluindo as páginas agrupadas em Mais.
$arcFooterSections = [
    'servidor', 'velocidade', 'painel', 'suporte',
    'liberacao', 'liberacoes', 'usuarios', 'permissoes',
    'preferencias', 'cadastros', 'cadastro', 'configuracao', 'configuracoes',
];
$arcTopItems = [];
// Ferramenta integrada mesmo quando o menu configurável ainda não tiver rota cadastrada.
foreach (['backup'=>['Backup','fa-cloud-upload'],'clean'=>['Clean','fa-magic'],'programas'=>['Programas','fa-download']] as $toolKey=>$toolMeta) {
if (($teamRole ?? '') === 'owner' || (($teamRole ?? '') === 'administrator' && ($arcAllowedMenu === null || in_array($toolKey,$arcAllowedMenu,true)))) {
    $params=['network'=>(int)$networkId];
    if(!empty($_GET['agent']))$params['agent']=(int)$_GET['agent'];
    $arcTopItems[]=['section'=>$toolKey,'label'=>$toolMeta[0],'icon'=>$toolMeta[1],'href'=>arc_workspace_base_url().'redes/'.$toolKey.'.php?'.http_build_query($params),'fa'=>true];
}
}

$arcSeenTop = ['programas'=>true,'clean'=>true,'backup'=>true];
foreach ($arcNetworkLinks as $item) {
    $sectionName = basename((string)parse_url((string)$item['url'], PHP_URL_PATH), '.php');
    if (in_array($sectionName, $arcFooterSections, true)) {
        if ($sectionName === 'suporte') {
            foreach ($arcSupportPages as $key => $meta) {
                if ($arcAllowedMenu !== null
                    && !in_array($key, $arcAllowedMenu, true)
                    && !in_array('suporte', $arcAllowedMenu, true)) continue;
                if (isset($arcSeenTop[$key])) continue;
                $arcSeenTop[$key] = true;
                $query = ['network' => (int)$networkId];
                if (!empty($_GET['agent'])) $query['agent'] = (int)$_GET['agent'];
                $arcTopItems[] = [
                    'section' => $key,
                    'label' => $meta[0],
                    'icon' => $meta[1],
                    'href' => arc_workspace_base_url().'redes/'.$key.'.php?'.http_build_query($query),
                    'fa' => true,
                ];
            }
        }
        continue;
    }
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
