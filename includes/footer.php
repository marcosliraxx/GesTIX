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
<?php /* Desktop: navegação exclusivamente no ribbon superior (redes/_menu.php).
   O dock inferior permanece apenas no mobile. */ ?>
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
<?php if ($arcNetworkDock): ?>
<div class="arc-gestor-statusbar" aria-label="Estado da rede">
  <span class="arc-gestor-statusbrand">
    <?php if (!empty($network['logo_file'])): ?><img src="<?=arc_h(arc_workspace_base_url().'api/network-logo.php?network='.(int)$networkId)?>" alt="" onerror="this.hidden=true"><?php else: ?><i class="fa fa-sitemap" aria-hidden="true"></i><?php endif; ?>
    <strong><?=arc_h((string)($network['name'] ?? $arcSiteName))?></strong>
  </span>
  <span class="arc-gestor-statuscenter" id="arc-gestor-inventory-status" aria-live="off">Sincronizando em 15 s</span>
  <span class="arc-gestor-statusright" title="Rede selecionada e versão da plataforma"><?=arc_h((string)($network['name'] ?? 'Rede'))?> · v<?=arc_h(arc_app_version())?></span>
</div>
<?php endif; ?>
</footer>

<?php /* Dropdown fora do footer para não ser recortado pelo dock móvel. */ ?>
<?php if ($arcNetworkDock): ?>
<dialog id="arc-mobile-more" class="arc-mobile-more arc-network-more erp-user-menu-style" aria-labelledby="arc-mobile-more-title"><header><h2 id="arc-mobile-more-title">Configurações da rede</h2><button type="button" data-close-more aria-label="Fechar menu"><i class="fa fa-times" aria-hidden="true"></i></button></header><div class="erp-menu-sep"></div><nav aria-label="Páginas de configurações da rede"><?php $arcDockVisibleCount=0; foreach ($arcConfigDockItems as [$section,$label,$icon,$url]): if (!$arcDockAllowed($section)) continue; $arcDockVisibleCount++; $link=arc_workspace_base_url().$url; ?><a href="<?=arc_h($link)?>" <?= $arcDockCurrent===$section?'aria-current="page"':'' ?>><i class="fa <?=arc_h($icon)?>" aria-hidden="true"></i><span><?=arc_h($label)?></span></a><?php endforeach; if($arcDockVisibleCount===0): ?><p role="status" style="padding:12px;color:var(--muted)">Seu perfil não possui páginas de configuração liberadas.</p><?php endif; ?></nav></dialog>
<?php else: ?>
<dialog id="arc-mobile-more" class="arc-mobile-more arc-global-more erp-user-menu-style" aria-labelledby="arc-mobile-more-title"><header><h2 id="arc-mobile-more-title">Mais páginas</h2><button type="button" data-close-more aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><div class="erp-menu-sep"></div><nav aria-label="Outras páginas do sistema"><?php foreach($arcGlobalMore as $item): ?><a href="<?=arc_h((string)$item['url'])?>"><span class="arc-db-icon" data-icon="<?=arc_h((string)$item['icon'])?>" aria-hidden="true"></span><span><?=arc_h((string)$item['label'])?></span></a><?php endforeach; ?></nav></dialog>
<?php endif; ?>

<style>
/* Caminho: includes/footer.php — barra de estado do gestor no desktop da rede. */
.arc-gestor-statusbar{display:none}
@media(min-width:861px){
 body.arc-workspace:has(.arc-redes-page){--footer-h:32px}
 body.arc-workspace:has(.arc-redes-page) .app-footer.arc-compact-footer{box-sizing:border-box!important;display:flex!important;align-items:center!important;justify-content:stretch!important;flex:none!important;height:32px!important;min-height:32px!important;max-height:32px!important;padding:0 10px!important;overflow:hidden!important;background:#936d5c!important;color:#fff!important;border-top:1px solid #a78370!important;box-shadow:none!important}
 body.arc-workspace:has(.arc-redes-page) .app-footer.arc-compact-footer>nav,
 body.arc-workspace:has(.arc-redes-page) .app-footer.arc-compact-footer>.arc-footer-system-name,
 body.arc-workspace:has(.arc-redes-page) .app-footer.arc-compact-footer>.arc-network-desktop-panel,
 body.arc-workspace:has(.arc-redes-page) .app-footer.arc-compact-footer>.arc-network-desktop-dock{display:none!important}
 body.arc-workspace:has(.arc-redes-page) .arc-gestor-statusbar{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(200px,1.5fr) minmax(0,1fr);align-items:center;gap:12px;width:100%;height:100%;font:10px/1.2 'Segoe UI',Arial,sans-serif;min-width:0}
 .arc-gestor-statusbrand{display:flex;align-items:center;min-width:0;gap:7px}.arc-gestor-statusbrand img{width:20px;height:20px;object-fit:contain;border-radius:4px;background:#fff}.arc-gestor-statusbrand i{display:grid;place-items:center;width:20px;height:20px;border-radius:4px;background:#19346b}.arc-gestor-statusbrand strong{font-size:10px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
 .arc-gestor-statuscenter{text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:9px}.arc-gestor-statusright{text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:10px}
}
@media print{.arc-gestor-statusbar{display:none!important}}
</style>
<script>
/* Caminho: includes/footer.php — espelha indicadores reais do painel, sem novos pedidos ao servidor. */
(()=>{'use strict';
const target=document.getElementById('arc-gestor-inventory-status');if(!target)return;
const panel=document.getElementById('network-panel');
let nextAt=Date.now()+15000;
function render(){
 const panelSync=panel?.querySelector('.np-sync')?.textContent?.trim();
 if(panelSync){target.textContent=panelSync;return;}
 const seconds=Math.max(0,Math.ceil((nextAt-Date.now())/1000));
 target.textContent='Sincronizando em '+seconds+' s';
 if(seconds===0)nextAt=Date.now()+15000;
}
if(panel){const observer=new MutationObserver(render);observer.observe(panel,{childList:true,subtree:true,characterData:true});}
const timer=setInterval(render,1000);render();window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();
</script>
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