<?php
declare(strict_types=1);

/* ARQUIVO: /includes/footer.php */

/* ============================================================
   includes/footer.php — RODAPÉ PADRÃO + JS COMPARTILHADO
   ------------------------------------------------------------
   Fecha a estrutura aberta por header.php/sidebar.php,
   exibe o rodapé (sempre visível) e injeta o JS da página
   quando a variável $ARCPageJs estiver definida.
   ============================================================ */
?><?php if (($ARCMode ?? 'app') === 'app'): ?></div><!-- /.app-body --><?php endif; ?>

<footer class="app-footer arc-compact-footer" aria-label="Rodapé do sistema">
    <span class="arc-footer-system-name" title="<?=arc_h((string)$arcSiteName)?>">&copy; <?=date('Y')?> · <?=arc_h((string)$arcSiteName)?> · v<?=arc_h(ARC_APP_VERSION)?></span>
<?php if (($ARCMode ?? 'app') === 'app'): ?>
<nav class="arc-mobile-dock" aria-label="Navegação do aplicativo">
<a href="pages/painel.php" <?=($ARCPageBodyClass??'')==='arc-panel-page'?'aria-current="page"':''?>><i class="fa fa-th-large" aria-hidden="true"></i><span>Painel</span></a>
<a href="pages/painel.php?nova_rede=1"><i class="fa fa-plus-square-o" aria-hidden="true"></i><span>Nova rede</span></a>
<a href="pages/perfil.php" <?=basename((string)($_SERVER['SCRIPT_NAME']??''))==='perfil.php'?'aria-current="page"':''?>><i class="fa fa-user-o" aria-hidden="true"></i><span>Perfil</span></a>
<button type="button" data-mobile-menu aria-label="Abrir menu do aplicativo" aria-controls="app-sidebar" aria-expanded="false"><i class="fa fa-bars" aria-hidden="true"></i><span>Menu</span></button>
</nav>
<?php endif; ?>
</footer>

<script>
(function () {
    'use strict';

    /* Navegação mobile */
    var mobileMenu=document.getElementById('arc-mobile-menu');
    var backdrop=document.getElementById('arc-sidebar-backdrop');
    var sidebar=document.getElementById('app-sidebar');
    function setMobileMenu(open){
        document.body.classList.toggle('arc-menu-open',!!open);
        if(sidebar)sidebar.classList.toggle('is-open',!!open);
        if(backdrop)backdrop.hidden=!open;
        if(mobileMenu)mobileMenu.setAttribute('aria-expanded',open?'true':'false');
        document.querySelectorAll('[data-mobile-menu]').forEach(function(button){button.setAttribute('aria-expanded',open?'true':'false');});
        if(open&&sidebar){var first=sidebar.querySelector('.side-nav a');if(first)first.focus({preventScroll:true});}
        else if(mobileMenu)mobileMenu.focus({preventScroll:true});
    }
    if(mobileMenu)mobileMenu.addEventListener('click',function(){setMobileMenu(!document.body.classList.contains('arc-menu-open'));});
    document.querySelectorAll('[data-mobile-menu]').forEach(function(button){button.addEventListener('click',function(){setMobileMenu(!document.body.classList.contains('arc-menu-open'));});});
    if(backdrop)backdrop.addEventListener('click',function(){setMobileMenu(false);});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&document.body.classList.contains('arc-menu-open'))setMobileMenu(false);});
    window.addEventListener('resize',function(){if(window.innerWidth>860&&document.body.classList.contains('arc-menu-open'))setMobileMenu(false);});

    /* ---------- Sidebar recolhível (persistente; respeita a preferência da conta) ---------- */
    var toggle = document.getElementById('sidebar-collapse');
    var stored = null;
    try { stored = localStorage.getItem('arc_sidebar'); } catch (e) {}
    var prefDefault = (window.ARC_PREFS && window.ARC_PREFS.sidebar_default) || 'expanded';

    if (stored === 'collapsed' || (stored === null && prefDefault === 'collapsed')) {
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
            try { localStorage.setItem('arc_sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
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

<?php if (!empty($ARCPageJs)) { echo $ARCPageJs; } ?>
</body>
</html>