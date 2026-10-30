/* ARCOLL SPEED CONNECTION — Camada de interação moderna (v2.4)
   Integra-se aos botões [data-arc-command] já existentes (mesmo canal seguro de comandos
   Plataforma → Agente). Não cria nenhum canal novo e não executa nada por conta própria. */
(function () {
  'use strict';
  var page = document.querySelector('.arc-redes-page');
  if (!page) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- 1. Revelação em cascata ---------- */
  var revealSel = '.asc-metric,.asc-ops-panel,.asc-chart-panel,.cc-kpi,.cc-surface,.cc-board,.arc-tool-card,.arc-hist,.arc-tools-hero,.arc-tools-filter';
  var targets = [].slice.call(page.querySelectorAll(revealSel));
  targets.forEach(function (el, i) {
    if (el.hasAttribute('data-reveal')) return;
    el.setAttribute('data-reveal', '');
    el.style.setProperty('--rd', Math.min(i, 14) * 55 + 'ms');
  });
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { root: null, threshold: 0.05 });
    page.querySelectorAll('[data-reveal]').forEach(function (el) { io.observe(el); });
  } else {
    page.querySelectorAll('[data-reveal]').forEach(function (el) { el.classList.add('is-in'); });
  }

  /* ---------- 2. Brilho que segue o mouse ---------- */
  page.addEventListener('pointermove', function (e) {
    var card = e.target.closest && e.target.closest(revealSel);
    if (!card) return;
    var r = card.getBoundingClientRect();
    card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
    card.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });

  /* ---------- 3. Ripple nos botões ---------- */
  document.addEventListener('pointerdown', function (e) {
    var b = e.target.closest && e.target.closest('.arc-redes-page .asc-btn,.arc-redes-page .button,.arc-alert-dialog .asc-btn');
    if (!b || b.disabled || reduce) return;
    var r = b.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2;
    var d = document.createElement('span');
    d.className = 'arc-ripple';
    d.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + (e.clientX - r.left - s / 2) + 'px;top:' + (e.clientY - r.top - s / 2) + 'px';
    b.appendChild(d);
    setTimeout(function () { d.remove(); }, 700);
  }, { passive: true });

  /* ---------- 4. Indicador deslizante do menu ---------- */
  var menu = page.querySelector('.arc-redes-menu');
  if (menu) {
    var ind = document.createElement('span');
    ind.className = 'arc-menu-indicator';
    ind.setAttribute('aria-hidden', 'true');
    menu.insertBefore(ind, menu.firstChild);
    var moveTo = function (link, instant) {
      if (!link) { ind.style.opacity = 0; return; }
      var m = menu.getBoundingClientRect(), r = link.getBoundingClientRect();
      if (instant) ind.style.transition = 'none';
      ind.style.width = r.width + 'px';
      ind.style.transform = 'translate(' + (r.left - m.left + menu.scrollLeft) + 'px,-50%)';
      ind.style.opacity = 1;
      if (instant) { void ind.offsetWidth; ind.style.transition = ''; }
    };
    var active = function () { return menu.querySelector('.arc-redes-menu-link.active,.arc-redes-menu-link[aria-current="page"]'); };
    menu.addEventListener('mouseover', function (e) { var l = e.target.closest('.arc-redes-menu-link'); if (l) moveTo(l); });
    menu.addEventListener('mouseleave', function () { moveTo(active()); });
    window.addEventListener('resize', function () { moveTo(active(), true); });
    requestAnimationFrame(function () { moveTo(active(), true); });
    window.addEventListener('load', function () { moveTo(active(), true); });
    var act = active(); if (act && act.scrollIntoView) { try { act.scrollIntoView({ inline: 'center', block: 'nearest' }); } catch (e) {} }
  }

  /* ---------- 5. Contador animado: <strong data-count="123"> ---------- */
  window.arcCountUp = function (el, to, ms) {
    if (!el) return;
    to = Number(to) || 0; ms = ms || 900;
    if (reduce) { el.textContent = to; return; }
    var t0 = performance.now();
    (function tick(t) {
      var k = Math.min(1, (t - t0) / ms), v = Math.round(to * (1 - Math.pow(1 - k, 3)));
      el.textContent = v;
      if (k < 1) requestAnimationFrame(tick);
    })(t0);
  };
  page.querySelectorAll('[data-count]').forEach(function (el) { window.arcCountUp(el, el.getAttribute('data-count')); });

  /* ---------- 6. Paleta de comandos (Ctrl+K) ---------- */
  var base = (document.querySelector('.arc-redes-menu-link') || {}).href || '';
  var cmds = [
    { t: 'Executar saúde rápida da rede', i: 'fa-heartbeat', c: 'quick_health', h: 'Gateway, internet e DNS' },
    { t: 'Testar velocidade da internet', i: 'fa-tachometer', c: 'speed_test', h: 'Download, upload, latência' },
    { t: 'Examinar dispositivos da rede', i: 'fa-search', c: 'scan_devices', h: 'Varredura completa' },
    { t: 'Diagnóstico de conexão', i: 'fa-globe', c: 'diagnostic', h: 'Provedor, IP e rota' },
    { t: 'Testar DNS', i: 'fa-compass', c: 'dns_test', h: 'Tempo de resolução' },
    { t: 'Descobrir IP público', i: 'fa-map-marker', c: 'public_ip', h: 'IP de saída da rede' },
    { t: 'Ver sinal do Wi-Fi', i: 'fa-wifi', c: 'wifi_info', h: 'Sinal, canal, taxa' },
    { t: 'Recursos do computador (CPU/RAM/disco)', i: 'fa-microchip', c: 'system_snapshot', h: 'Instantâneo do agente' },
    { t: 'Limpar cache DNS do Windows', i: 'fa-eraser', c: 'flush_dns', h: 'Ação de manutenção' },
    { t: 'Iniciar monitoramento', i: 'fa-play', c: 'monitor_start', h: 'Retoma a coleta' },
    { t: 'Pausar monitoramento', i: 'fa-pause', c: 'monitor_pause', h: 'Interrompe a coleta' }
  ];
  var pages = [
    ['monitoramento', 'Ir para Monitoramento', 'fa-line-chart'], ['velocidade', 'Ir para Velocidade', 'fa-tachometer'],
    ['dispositivos', 'Ir para Dispositivos', 'fa-desktop'], ['mapa', 'Ir para Mapa', 'fa-sitemap'],
    ['conexao', 'Ir para Conexão', 'fa-wifi']
  ];
  pages.forEach(function (p) {
    var link = page.querySelector('.arc-redes-menu-link[href*="/' + p[0] + '.php"]');
    if (link) cmds.push({ t: p[1], i: p[2], u: link.href, h: 'Navegar' });
  });

  var pal = document.createElement('div');
  pal.className = 'arc-palette';
  pal.setAttribute('role', 'dialog'); pal.setAttribute('aria-modal', 'true'); pal.setAttribute('aria-label', 'Paleta de comandos');
  pal.innerHTML = '<div class="arc-palette-box"><input type="text" placeholder="Digite um comando… (ex.: velocidade, dns, wifi)" aria-label="Buscar comando" autocomplete="off"><ul role="listbox"></ul><div class="arc-palette-foot"><span>↑ ↓ navegar</span><span>Enter executar</span><span>Esc fechar</span></div></div>';
  document.body.appendChild(pal);
  var inp = pal.querySelector('input'), ul = pal.querySelector('ul'), sel = 0, shown = [];

  function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  function render() {
    var q = norm(inp.value.trim());
    shown = cmds.filter(function (c) { return !q || norm(c.t + ' ' + c.h).indexOf(q) > -1; });
    if (sel >= shown.length) sel = 0;
    ul.innerHTML = '';
    if (!shown.length) { ul.innerHTML = '<li class="arc-empty">Nenhum comando encontrado</li>'; return; }
    shown.forEach(function (c, idx) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      if (idx === sel) li.className = 'is-sel';
      li.innerHTML = '<i class="fa ' + c.i + '" aria-hidden="true"></i><span></span><small></small>';
      li.children[1].textContent = c.t; li.children[2].textContent = c.h;
      li.addEventListener('click', function () { run(c); });
      li.addEventListener('mousemove', function () { if (sel !== idx) { sel = idx; render(); } });
      ul.appendChild(li);
    });
    var cur = ul.querySelector('.is-sel'); if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
  }
  function open() { pal.classList.add('is-open'); inp.value = ''; sel = 0; render(); setTimeout(function () { inp.focus(); }, 20); }
  function close() { pal.classList.remove('is-open'); }
  function run(c) {
    close();
    if (c.u) { location.href = c.u; return; }
    var b = document.createElement('button');
    b.type = 'button'; b.hidden = true; b.setAttribute('data-arc-command', c.c);
    document.body.appendChild(b); b.click();
    setTimeout(function () { b.remove(); }, 500);
  }
  inp.addEventListener('input', function () { sel = 0; render(); });
  inp.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = (sel + 1) % Math.max(1, shown.length); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); sel = (sel - 1 + shown.length) % Math.max(1, shown.length); render(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (shown[sel]) run(shown[sel]); }
    else if (e.key === 'Escape') { e.preventDefault(); close(); }
  });
  pal.addEventListener('mousedown', function (e) { if (e.target === pal) close(); });
  document.addEventListener('keydown', function (e) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); pal.classList.contains('is-open') ? close() : open(); }
    else if (e.key === '/' && !typing && !pal.classList.contains('is-open')) { e.preventDefault(); open(); }
  });
  // Em Gestão de Redes, comandos ficam exclusivamente na aba Comandos.
  if (!document.querySelector('.arc-redes-page')) {
  var fab = document.createElement('button');
  fab.type = 'button'; fab.className = 'arc-fab'; fab.title = 'Comandos rápidos (Ctrl+K)'; fab.setAttribute('aria-label', 'Abrir comandos rápidos');
  fab.innerHTML = '<i class="fa fa-bolt" aria-hidden="true"></i>';
  fab.addEventListener('click', open);
  document.body.appendChild(fab);
  }
  window.arcOpenPalette = open;
})();
