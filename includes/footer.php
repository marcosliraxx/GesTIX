<?php
/* Caminho: includes/footer.php */
declare(strict_types=1);
require_once __DIR__ . '/error-logging.php';



/* ============================================================
   includes/footer.php — RODAPÉ PADRÃO + JS COMPARTILHADO
   ------------------------------------------------------------
   Fecha a estrutura aberta por header.php/sidebar.php,
   exibe o rodapé (sempre visível) e injeta o JS da página
   quando a variável $ARCPageJs estiver definida.
   ============================================================ */
?><?php if (($ARCMode ?? 'app') === 'app'): ?></div><!-- /.app-body --><?php endif; ?>

<footer class="app-footer arc-compact-footer" aria-label="Rodapé do sistema">
    <nav aria-label="Links do rodapé"><?php if(isset($pdo))foreach(arc_navigation_items($pdo,'footer') as $entry):if($entry['url']==='')continue;?><a class="<?=arc_h($entry['device_class'])?>" href="<?=arc_h($entry['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h($entry['icon'])?>" aria-hidden="true"></span><?=arc_h($entry['label'])?></a><?php endforeach;?></nav>
    <span class="arc-footer-system-name" title="<?=arc_h((string)$arcSiteName)?>">&copy; <?=date('Y')?> · <?=arc_h((string)$arcSiteName)?> · v<?=arc_h(arc_app_version())?></span>
<?php if (($ARCMode ?? 'app') === 'app'): ?>
<?php
$arcNetworkDock = !empty($arcNetworkHeader) && isset($networkId,$network) && (int)$networkId>0;
if ($arcNetworkDock):
    $arcDockBase = arc_workspace_base_url().'redes/';
    $arcDockQuery = http_build_query(array_filter(['network'=>(int)$networkId,'gestor'=>(int)($_GET['gestor']??0)]));
    $arcDockCurrent = (string)($arcSectionFile ?? basename((string)($_SERVER['SCRIPT_NAME']??''),'.php'));
    $arcDockRights = in_array(($teamRole??''),['owner','system_admin'],true) ? null : arc_network_profile_rights($pdo,(int)$networkId,(int)arc_user_id());
    // Todos os rótulos, ícones e destinos são lidos do banco.
    $arcDockBySection=[];
    foreach (arc_navigation_items($pdo,'header',(int)$networkId) as $arcDockEntry) {
        $section=basename((string)parse_url((string)$arcDockEntry['url'],PHP_URL_PATH),'.php');
        if($section!=='' && !isset($arcDockBySection[$section]))$arcDockBySection[$section]=$arcDockEntry;
    }
    $arcDockLinks=[];
    foreach(['servidor','velocidade','painel','suporte'] as $section){
        if(!isset($arcDockBySection[$section]))continue;
        $entry=$arcDockBySection[$section];
        $arcDockLinks[]=[$section,(string)$entry['label'],(string)$entry['icon'],(string)$entry['url']];
    }
    $arcConfigDockItems=[];
    foreach(['liberacao','usuarios','permissoes','preferencias','cadastros'] as $section){
        if(!isset($arcDockBySection[$section]))continue;
        $entry=$arcDockBySection[$section];
        $arcConfigDockItems[]=[$section,(string)$entry['label'],(string)$entry['icon'],(string)$entry['url']];
    }
    // "Mais" é um controle do layout, não uma rota de menu cadastrada.
    $arcDockLinks[]=['liberacao','Mais','fa-ellipsis-h',''];
    $arcDockAllowed = static function(string $section) use ($arcDockRights): bool {
        return $arcDockRights === null || in_array($section,$arcDockRights,true);
    };
?>
<!-- Navegação completa da rede no desktop, alinhada aos cinco atalhos do celular. -->
<nav class="arc-network-desktop-dock" aria-label="Navegação da rede no computador">
<?php foreach (['servidor'=>1,'velocidade'=>2,'suporte'=>4] as $section=>$position):
    if (!isset($arcDockBySection[$section]) || !$arcDockAllowed($section)) continue;
    $arcDeskEntry=$arcDockBySection[$section];
    $label=(string)$arcDeskEntry['label'];$icon=(string)$arcDeskEntry['icon'];
    $arcDesktopUrl = arc_workspace_base_url().(string)$arcDeskEntry['url'];
?>
<a style="grid-column:<?= $position ?>" href="<?=arc_h($arcDesktopUrl)?>" <?= $arcDockCurrent===$section?'aria-current="page"':'' ?>><i class="fa <?=arc_h($icon)?>" aria-hidden="true"></i><span><?=arc_h($label)?></span></a>
<?php endforeach; ?>
<button type="button" class="arc-network-desktop-more-trigger" style="grid-column:5" data-mobile-more aria-controls="arc-mobile-more" aria-expanded="false" aria-label="Mais opções da rede"><i class="fa fa-ellipsis-h" aria-hidden="true"></i><span>Mais</span></button>
</nav>
<!-- Painel da Rede no footer de computadores — mesmo destino e logo do atalho móvel. -->
<?php if(isset($arcDockBySection['painel']) && $arcDockAllowed('painel')): ?><a class="arc-network-desktop-panel" href="<?=arc_h(arc_workspace_base_url().(string)$arcDockBySection['painel']['url'])?>" aria-label="Abrir Painel da Rede" <?= $arcDockCurrent==='painel'?'aria-current="page"':'' ?>>
  <span class="arc-network-desktop-bubble">
  <?php if(!empty($network['logo_file'])): ?>
    <img src="<?=arc_h(arc_workspace_base_url().'api/network-logo.php?network='.(int)$networkId)?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
    <i class="fa fa-sitemap" hidden aria-hidden="true"></i>
  <?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?>
  </span>
  <span class="arc-network-desktop-panel-label"><?=arc_h((string)$arcDockBySection['painel']['label'])?></span>
</a><?php endif; ?>
<style>
/* Caminho: includes/footer.php — Painel da Rede no rodapé do desktop (v2026.11.03.2). */
.arc-network-desktop-panel{display:none!important}
@media(min-width:861px){
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel){position:relative!important;overflow:visible!important;height:52px!important;min-height:52px!important;max-height:52px!important;padding:0 16px!important}
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel)>.arc-footer-system-name{position:absolute;right:15px;max-width:calc(50% - 90px);text-align:right;font-size:10px}
 .arc-workspace .app-footer.arc-compact-footer>.arc-network-desktop-panel{box-sizing:border-box;display:flex!important;position:absolute;left:50%;top:0;transform:translateX(-50%);width:120px;height:52px;align-items:center;justify-content:flex-end;flex-direction:column;gap:0;padding:0 4px 6px!important;text-decoration:none;color:var(--primary);background:transparent;border-radius:10px;z-index:40;white-space:nowrap}
 .arc-workspace .app-footer.arc-compact-footer>.arc-network-desktop-panel:hover,.arc-workspace .app-footer.arc-compact-footer>.arc-network-desktop-panel[aria-current="page"]{background:color-mix(in srgb,var(--primary) 9%,transparent)}
 .arc-workspace .app-footer.arc-compact-footer .arc-network-desktop-bubble{box-sizing:border-box;position:absolute;left:50%;top:-21px;transform:translateX(-50%);display:flex;align-items:center;justify-content:center;width:48px;height:48px;min-width:48px;overflow:hidden;padding:2px;border-radius:50%;border:3px solid var(--surface);background:var(--primary);box-shadow:0 4px 12px #0005}
 .arc-workspace .arc-network-desktop-bubble img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain;border-radius:50%}
 .arc-workspace .arc-network-desktop-bubble img[hidden],.arc-workspace .arc-network-desktop-bubble i[hidden]{display:none!important}
 .arc-workspace .arc-network-desktop-bubble i{font-size:20px;color:white}
 .arc-workspace .app-footer.arc-compact-footer .arc-network-desktop-panel-label{font-size:11px;line-height:14px;font-weight:700;white-space:nowrap}
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel)>nav:first-child{position:absolute;left:15px;max-width:calc(50% - 90px);overflow:hidden;white-space:nowrap}
}

/* Caminho: includes/footer.php — Footer da rede idêntico no desktop e no celular (v2026.11.03.3). */
.arc-network-desktop-dock{display:none!important}
@media(min-width:861px){
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel){height:64px!important;min-height:64px!important;max-height:64px!important;padding:0 12px!important;overflow:visible!important}
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel)>nav:first-child,
 .arc-workspace .app-footer.arc-compact-footer:has(>.arc-network-desktop-panel)>.arc-footer-system-name{display:none!important}
 .arc-workspace .app-footer.arc-compact-footer>.arc-network-desktop-dock{display:grid!important;grid-template-columns:repeat(5,minmax(0,1fr));width:min(100%,820px);height:64px;margin:0 auto;align-items:stretch;justify-items:stretch;overflow:visible;gap:0;isolation:isolate}
 .arc-workspace .arc-network-desktop-dock>a,
 .arc-workspace .arc-network-desktop-dock>button{box-sizing:border-box;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px;min-width:0;height:64px;padding:6px 4px;border:0;border-radius:10px;background:transparent;color:var(--muted);font:600 11px/1.2 var(--font);text-decoration:none;cursor:pointer;transition:background .15s,color .15s}
 .arc-workspace .arc-network-desktop-dock>a>i,.arc-workspace .arc-network-desktop-dock>button>i{font-size:20px;line-height:22px}
 .arc-workspace .arc-network-desktop-dock>a:hover,.arc-workspace .arc-network-desktop-dock>button:hover,
 .arc-workspace .arc-network-desktop-dock>a[aria-current="page"],.arc-workspace .arc-network-desktop-dock>button[aria-expanded="true"]{background:color-mix(in srgb,var(--primary) 10%,transparent);color:var(--primary)}
 .arc-workspace .arc-network-desktop-dock>a:focus-visible,.arc-workspace .arc-network-desktop-dock>button:focus-visible{outline:2px solid var(--primary);outline-offset:-3px}
 .arc-workspace .app-footer.arc-compact-footer>.arc-network-desktop-panel{top:0;height:64px;width:min(164px,19%);padding-bottom:8px!important;z-index:42}
 .arc-workspace .app-footer.arc-compact-footer .arc-network-desktop-bubble{top:-17px}
 .arc-workspace .app-footer.arc-compact-footer .arc-network-desktop-panel-label{font-size:11px}
}
@media print{.arc-network-desktop-dock{display:none!important}}

@media print{.arc-network-desktop-panel{display:none!important}}
</style>
<nav class="arc-mobile-dock arc-mobile-dock-five arc-network-mobile-dock" aria-label="Navegação da rede">
<?php foreach($arcDockLinks as $i=>$entry):
  if ($entry[0]!=='liberacao' && !$arcDockAllowed($entry[0])) continue;
  $isCurrent = $arcDockCurrent===$entry[0] || ($entry[0]==='liberacao' && in_array($arcDockCurrent,['preferencias','cadastros','usuarios','permissoes'],true));
  $url=arc_workspace_base_url().(string)($entry[3]??'');
  $isCenter=$entry[0]==='painel';
?>
<?php if ($entry[0]==='liberacao'): ?>
<button type="button" style="grid-column:5" class="arc-network-more-trigger" data-mobile-more aria-controls="arc-mobile-more" aria-expanded="false" aria-label="Mais opções da rede"><i class="fa fa-ellipsis-h" aria-hidden="true"></i><span>Mais</span></button>
<?php else: ?>
<a style="grid-column:<?= (int)(['servidor'=>1,'velocidade'=>2,'painel'=>3,'suporte'=>4][$entry[0]]??($i+1)) ?>" class="<?= $isCenter?'arc-mobile-start arc-network-mobile-center':'' ?>" href="<?=arc_h($url)?>" aria-label="<?=arc_h($entry[1])?>" <?=$isCurrent?'aria-current="page"':''?>>
<?php if($isCenter):?><span class="arc-network-mobile-bubble">
<?php if(!empty($network['logo_file'])):?><img src="<?=arc_h(arc_workspace_base_url().'api/network-logo.php?network='.(int)$networkId)?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa fa-sitemap" hidden aria-hidden="true"></i><?php else:?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif;?>
</span><?php else:?><i class="fa <?=arc_h($entry[2])?>" aria-hidden="true"></i><?php endif;?><span><?=arc_h($entry[1])?></span></a>
<?php endif; ?>
<?php endforeach;?></nav>
<?php else: ?>
<?php
/* Caminho: includes/footer.php — footer global uniforme com a navegação de redes.
   Usa permissões do menu nativo, sem criar rotas ou conceder acesso novo. */
$arcGlobalMenu = [];
$arcGlobalSeen = [];
foreach (['sidebar','user','header','mobile','footer','more'] as $place) {
    foreach (arc_navigation_items($pdo, $place) as $item) {
        $url = (string)($item['url'] ?? '');
        if ($url === '' || $url === '#' || isset($arcGlobalSeen[$url])) continue;
        $arcGlobalSeen[$url] = true;
        $arcGlobalMenu[] = $item;
    }
}
$arcGlobalChosen = [];
$arcGlobalTargets = [
    'pages/painel.php',
    'pages/atendimentos.php',
    'pages/perfil.php',
];
foreach ($arcGlobalTargets as $target) {
    foreach ($arcGlobalMenu as $item) {
        if (parse_url((string)$item['url'], PHP_URL_PATH) !== $target) continue;
        $arcGlobalChosen[$target] = ['url'=>$item['url'],'label'=>$item['label'],'icon'=>$item['icon']];
        break;
    }
}
/* Caso o administrador não tenha um desses destinos, ocupa a vaga com uma rota
   efetivamente autorizada. Não repete páginas nem exibe ações indisponíveis. */
foreach ($arcGlobalMenu as $item) {
    if (count($arcGlobalChosen) >= 3) break;
    $path = (string)parse_url((string)$item['url'], PHP_URL_PATH);
    if (isset($arcGlobalChosen[$path]) || str_starts_with($path, 'redes/')) continue;
    $arcGlobalChosen[$path] = ['url'=>$item['url'],'label'=>$item['label'],'icon'=>$item['icon']];
}
$arcGlobalSlots = array_values($arcGlobalChosen);
$arcGlobalUsed = array_column($arcGlobalSlots, 'url');
$arcGlobalMore = array_values(array_filter($arcGlobalMenu, static fn(array $it): bool => !in_array((string)$it['url'], $arcGlobalUsed, true)));
$arcCanRegisterNetwork = arc_is_user();
?>
<nav class="arc-system-dock" aria-label="Atalhos do sistema">
<?php foreach ($arcGlobalSlots as $i=>$item): $col=$i===0?1:($i===1?2:4); ?>
<a style="grid-column:<?= $col ?>" href="<?=arc_h((string)$item['url'])?>" <?=str_contains((string)($_SERVER['SCRIPT_NAME']??''),(string)parse_url((string)$item['url'],PHP_URL_PATH))?'aria-current="page"':''?>><i class="fa <?=arc_h((string)$item['icon'])?>" aria-hidden="true"></i><span><?=arc_h((string)$item['label'])?></span></a>
<?php endforeach; ?>
<button type="button" style="grid-column:5" data-mobile-more aria-controls="arc-mobile-more" aria-expanded="false" aria-label="Mais páginas do sistema"><i class="fa fa-ellipsis-h" aria-hidden="true"></i><span>Mais</span></button>
</nav>
<?php if ($arcCanRegisterNetwork): ?>
<button type="button" class="arc-system-dock-create" data-open-register aria-label="Cadastrar nova rede"><span class="arc-system-dock-bubble"><i class="fa fa-plus" aria-hidden="true"></i></span><span class="arc-system-dock-label">Nova Rede</span></button>
<?php else: ?>
<a class="arc-system-dock-create" href="<?=arc_h(arc_workspace_base_url().'pages/painel.php')?>" aria-label="Abrir painel do sistema"><span class="arc-system-dock-bubble"><i class="fa fa-th-large" aria-hidden="true"></i></span><span class="arc-system-dock-label">Painel</span></a>
<?php endif; ?>
<?php endif; // dock global ?>
<?php endif; // dock de rede ?>
</footer>

<?php /* Dropdown fora do footer para não ser recortado pelo dock móvel. */ ?>
<?php if ($arcNetworkDock): ?>
<dialog id="arc-mobile-more" class="arc-mobile-more arc-network-more erp-user-menu-style" aria-labelledby="arc-mobile-more-title"><header><h2 id="arc-mobile-more-title">Configurações da rede</h2><button type="button" data-close-more aria-label="Fechar menu"><i class="fa fa-times" aria-hidden="true"></i></button></header><div class="erp-menu-sep"></div><nav aria-label="Páginas de configurações da rede"><?php $arcDockVisibleCount=0; foreach ($arcConfigDockItems as [$section,$label,$icon,$url]): if (!$arcDockAllowed($section)) continue; $arcDockVisibleCount++; $link=arc_workspace_base_url().$url; ?><a href="<?=arc_h($link)?>" <?= $arcDockCurrent===$section?'aria-current="page"':'' ?>><i class="fa <?=arc_h($icon)?>" aria-hidden="true"></i><span><?=arc_h($label)?></span></a><?php endforeach; if($arcDockVisibleCount===0): ?><p role="status" style="padding:12px;color:var(--muted)">Seu perfil não possui páginas de configuração liberadas.</p><?php endif; ?></nav></dialog>
<?php else: ?>
<dialog id="arc-mobile-more" class="arc-mobile-more arc-global-more erp-user-menu-style" aria-labelledby="arc-mobile-more-title"><header><h2 id="arc-mobile-more-title">Mais páginas</h2><button type="button" data-close-more aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><div class="erp-menu-sep"></div><nav aria-label="Outras páginas do sistema"><?php foreach($arcGlobalMore as $item): ?><a href="<?=arc_h((string)$item['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h((string)$item['icon'])?>" aria-hidden="true"></span><span><?=arc_h((string)$item['label'])?></span></a><?php endforeach; ?></nav></dialog>
<?php endif; ?>

<style>
/* Caminho: includes/footer.php — Mais fora do footer, acima da barra e do conteúdo. */
body.arc-workspace > #arc-mobile-more.arc-network-more[open],
body.arc-workspace > #arc-mobile-more.arc-global-more[open]{z-index:3010!important;visibility:visible!important}
body.arc-workspace > #arc-mobile-more.arc-network-more[open]{display:flex!important;flex-direction:column!important;height:max-content!important;overflow:hidden!important}
body.arc-workspace > #arc-mobile-more.arc-network-more[open] nav{display:flex!important;flex-direction:column!important;max-height:min(330px,calc(100dvh - var(--arc-more-bottom,78px) - 70px))!important;overflow-y:auto!important;min-height:0!important;flex:0 1 auto!important}
body.arc-workspace > #arc-mobile-more.arc-network-more[open] nav>a{display:flex!important;min-height:42px!important;flex:0 0 auto!important;visibility:visible!important}
/* Caminho: includes/footer.php — dock global responsivo, cinco posições, divisórias e botão central. */
.arc-system-dock{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));width:min(100%,820px);height:64px;margin:0 auto;gap:0;align-items:stretch;isolation:isolate;background-image:linear-gradient(var(--border),var(--border)),linear-gradient(var(--border),var(--border)),linear-gradient(var(--border),var(--border)),linear-gradient(var(--border),var(--border));background-size:1px calc(100% - 24px);background-position:20% center,40% center,60% center,80% center;background-repeat:no-repeat}
.arc-system-dock>a,.arc-system-dock>button{display:flex;flex-direction:column;justify-content:center;align-items:center;gap:3px;min-width:0;min-height:64px;padding:5px 2px;box-sizing:border-box;background:transparent;border:0;border-radius:10px;color:var(--muted);font:600 11px/1.2 var(--font);text-decoration:none;cursor:pointer}
.arc-system-dock i{font-size:19px}.arc-system-dock span{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-system-dock>a:hover,.arc-system-dock>button:hover,.arc-system-dock>[aria-current="page"],.arc-system-dock>button[aria-expanded="true"]{background:color-mix(in srgb,var(--primary) 10%,transparent);color:var(--primary)}
.arc-workspace .app-footer.arc-compact-footer:has(.arc-system-dock){height:64px!important;max-height:none!important;min-height:64px!important;overflow:visible!important;position:relative!important;padding:0 10px!important}
.arc-workspace .app-footer.arc-compact-footer:has(.arc-system-dock)>nav:first-child,.arc-workspace .app-footer.arc-compact-footer:has(.arc-system-dock)>.arc-footer-system-name{display:none!important}
.arc-system-dock-create{position:absolute;left:50%;top:0;transform:translateX(-50%);z-index:41;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:0;width:min(164px,19%);height:64px;box-sizing:border-box;padding:0 2px 7px!important;background:transparent;border:0;border-radius:12px;color:var(--primary);cursor:pointer;text-decoration:none;font:700 11px/14px var(--font)}
.arc-system-dock-create:hover,.arc-system-dock-create:focus-visible{background:color-mix(in srgb,var(--primary) 10%,transparent);outline-offset:-2px}
.arc-system-dock-bubble{position:absolute;left:50%;top:-17px;transform:translateX(-50%);display:grid;place-items:center;width:48px;height:48px;box-sizing:border-box;border:3px solid var(--surface);border-radius:50%;color:#fff;background:var(--primary);box-shadow:0 5px 12px #0004}
.arc-system-dock-bubble i{font-size:20px}.arc-system-dock-label{white-space:nowrap}
.arc-global-more.erp-user-menu-style[open]{position:fixed!important;inset:auto auto var(--arc-more-bottom,78px) var(--arc-more-left,10px)!important;width:min(320px,calc(100vw - 20px))!important;max-height:min(440px,calc(100dvh - var(--arc-more-bottom,78px) - 12px))!important;margin:0!important;overflow:hidden!important;padding:10px!important;border:1px solid var(--border)!important;border-radius:14px!important;background:var(--surface)!important;color:var(--text)!important;box-shadow:0 16px 45px #0006!important;z-index:3000!important}
.arc-global-more::backdrop{background:transparent!important;backdrop-filter:none!important}.arc-global-more nav{display:block!important;overflow:auto!important;max-height:315px!important;padding:0!important}.arc-global-more nav>a{display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:flex-start!important;min-height:42px!important;gap:11px!important;text-align:left!important;border:0!important;border-bottom:1px solid var(--border)!important;background:transparent!important;border-radius:0!important;padding:10px!important}.arc-global-more nav>a:last-child{border-bottom:0!important}.arc-global-more nav>a:hover{background:var(--surface-alt)!important}.arc-global-more .arc-db-icon{font-size:17px!important}
@media(max-width:860px){.arc-workspace .app-footer.arc-compact-footer:has(.arc-system-dock){height:calc(64px + env(safe-area-inset-bottom,0px))!important;min-height:calc(64px + env(safe-area-inset-bottom,0px))!important;padding:0 6px env(safe-area-inset-bottom,0px)!important}.arc-system-dock{width:100%}.arc-system-dock>a,.arc-system-dock>button{font-size:10px;padding-inline:1px}.arc-system-dock-create{height:64px}}
@media print{.arc-system-dock,.arc-system-dock-create{display:none!important}}
</style>

<script>
(function () {
    'use strict';

    var more=document.getElementById('arc-mobile-more');
    /* Dropdown fixo no viewport, separado da altura e do overflow do footer. */
    var moreTrigger=null;
    function placeMore(button) {
        if (!more || !button) return;
        var rect=button.getBoundingClientRect();
        var viewport=window.visualViewport;
        var screenWidth=viewport ? viewport.width : window.innerWidth;
        var screenHeight=viewport ? viewport.height + viewport.offsetTop : window.innerHeight;
        var menuWidth=Math.min(320,Math.max(180,screenWidth-20));
        var left=Math.max(10,Math.min(screenWidth-menuWidth-10,rect.right-menuWidth));
        var bottom=Math.max(10,screenHeight-rect.top+8);
        more.style.setProperty('--arc-more-left',left+'px');
        more.style.setProperty('--arc-more-bottom',bottom+'px');
    }
    function syncMoreButtons() {
        document.querySelectorAll('[data-mobile-more]').forEach(function(button) {
            button.setAttribute('aria-expanded',more && more.open && button===moreTrigger?'true':'false');
        });
    }
    function hideMore() {
        if (!more || !more.open) return;
        if (typeof more.close==='function') more.close();
        else {more.removeAttribute('open');moreTrigger=null;syncMoreButtons();}
    }
    document.querySelectorAll('[data-mobile-more]').forEach(function(button) {
        button.setAttribute('aria-haspopup','dialog');
        button.addEventListener('click',function() {
            if (!more) return;
            if (more.open && moreTrigger===button) {hideMore();return;}
            hideMore();
            moreTrigger=button;
            placeMore(button);
            if (typeof more.show==='function') more.show();
            else more.setAttribute('open','');
            syncMoreButtons();
        });
    });
    if (more) {
        var closeMore=more.querySelector('[data-close-more]');
        if (closeMore) closeMore.addEventListener('click',hideMore);
        more.addEventListener('close',function(){moreTrigger=null;syncMoreButtons();});
        document.addEventListener('pointerdown',function(event) {
            if (more.open && !more.contains(event.target) && !event.target.closest('[data-mobile-more]')) hideMore();
        });
        document.addEventListener('keydown',function(event) {
            if (event.key==='Escape' && more.open) {hideMore();event.preventDefault();}
        });
        window.addEventListener('resize',function(){if(more.open && moreTrigger)placeMore(moreTrigger);});
        if(window.visualViewport)window.visualViewport.addEventListener('resize',function(){if(more.open && moreTrigger)placeMore(moreTrigger);});
        window.addEventListener('scroll',function(){if(more.open && moreTrigger)placeMore(moreTrigger);},true);
    }
    /* ---------- Sidebar recolhível (persistente; respeita a preferência da conta) ---------- */
    var toggle = document.getElementById('sidebar-collapse');
    var prefDefault = (window.ARC_PREFS && window.ARC_PREFS.sidebar_default) || 'expanded';

    if (prefDefault === 'hidden') {
        document.body.classList.add('sidebar-hidden');
    } else if (prefDefault === 'collapsed') {
        document.body.classList.add('sidebar-collapsed');
    }

    function syncSidebarToggle() {
        if (!toggle) return;
        var collapsed = document.body.classList.contains('sidebar-collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
        toggle.title = collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral';
        var icon = toggle.querySelector('[data-sidebar-toggle-icon]');
        if (icon) {
            icon.classList.toggle('fa-angle-double-right', collapsed);
            icon.classList.toggle('fa-angle-double-left', !collapsed);
        }
    }
    syncSidebarToggle();
    if (toggle) {
        toggle.addEventListener('click', function () {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            syncSidebarToggle();
        });
    }

    /* Avisos translúcidos: entram pela direita, permanecem por 3 segundos. */
    (function () {
        var toasts = Array.prototype.slice.call(document.querySelectorAll('.flash, .arc-toast'));
        toasts.forEach(function (toast) {
            if (toast.closest('.arc-toast-host')) return;
            var host = document.getElementById('arc-toast-host');
            if (!host) { host = document.createElement('div'); host.id = 'arc-toast-host'; host.setAttribute('aria-live','polite'); document.body.appendChild(host); }
            toast.classList.add('arc-toast');
            var close = document.createElement('button');
            close.type = 'button'; close.className = 'arc-toast-close'; close.setAttribute('aria-label','Fechar aviso');
            close.textContent = '×'; toast.appendChild(close);
            host.appendChild(toast);
            var timer;
            function dismiss() { clearTimeout(timer); toast.classList.add('arc-toast-leaving'); setTimeout(function(){toast.remove();},280); }
            close.addEventListener('click',dismiss);
            requestAnimationFrame(function(){toast.classList.add('arc-toast-visible');});
            timer=setTimeout(dismiss,3000);
        });
    })();

    /* ---------- Abas padrão (padrão .tab-btn / .tab-panel) ---------- */
    var tabButtons = document.querySelectorAll('.tab-btn');
    var tabPanels = document.querySelectorAll('.tab-panel');

    if (tabButtons.length && tabPanels.length) {
        var activateTab = function (name) {
            var found = false;
            tabButtons.forEach(function (b) {
                var on = b.getAttribute('data-tab') === name;
                b.classList.toggle('active', on);
                if (on) { found = true; }
            });
            tabPanels.forEach(function (p) {
                p.classList.toggle('active', p.id === 'tab-' + name);
            });
            if (found) {
                try { history.replaceState(null, '', '#' + name); } catch (e) {}
            }
        };

        tabButtons.forEach(function (b) {
            b.addEventListener('click', function () { activateTab(b.getAttribute('data-tab')); });
        });
        window.addEventListener('hashchange', function () {
            var h = (location.hash || '').replace('#', '');
            if (h && document.getElementById('tab-' + h)) { activateTab(h); }
        });

        var initialTab = (location.hash || '').replace('#', '');
        if (!initialTab || !document.getElementById('tab-' + initialTab)) {
            initialTab = tabButtons[0].getAttribute('data-tab');
        }
        activateTab(initialTab);
    }
})();
</script>

<?php if (function_exists('arc_is_user') && arc_is_user()): ?>
<link rel="stylesheet" href="<?=arc_h($arcAssetBase)?>assets/css/command-queue.css?v=<?=arc_h($arcAssetVersion('assets/css/command-queue.css'))?>">
<script>window.arcQueueConfig={base:<?=json_encode(arc_workspace_base_url())?>,csrf:<?=json_encode(arc_csrf_token())?>};</script>
<script defer src="<?=arc_h($arcAssetBase)?>assets/js/command-queue.js?v=<?=arc_h($arcAssetVersion('assets/js/command-queue.js'))?>"></script>
<?php endif; ?>
<?php if (!empty($ARCPageJs)) { echo $ARCPageJs; } ?>
</body>
</html>