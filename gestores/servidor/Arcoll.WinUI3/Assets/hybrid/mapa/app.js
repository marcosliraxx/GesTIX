/* Caminho: gestores/servidor/Arcoll.WinUI3/Assets/hybrid/mapa/app.js — mesma topologia da plataforma (redes/mapa.php). */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const canvas = $('canvas'), content = $('content'), svg = $('lines'), paths = $('paths'), branches = $('branches'), mode = $('mode');
  const NS = 'http://www.w3.org/2000/svg';
  const post = message => { try { window.chrome.webview.postMessage(message); } catch (_) { } };
  const ICONS = {
    computer: 'M3 3h18v13H3z M8 21h8m-4-5v5', phone: 'M7 2h10v20H7z M10 18h4', tablet: 'M4 2h16v20H4z M10 18h4',
    router: 'M2 8a16 16 0 0 1 20 0 M5 12a11 11 0 0 1 14 0 M9 16a5 5 0 0 1 6 0 M12 20h.01', tv: 'M2 5h20v14H2z M8 23l4-4 4 4 M8 1l4 4 4-4',
    printer: 'M6 9V2h12v7 M6 18H3V9h18v9h-3 M6 14h12v8H6z', camera: 'M2 5h14v14H2z M16 9l6-4v14l-6-4', server: 'M4 3h16v7H4z M4 14h16v7H4z M7 6h.01 M7 17h.01',
    switch: 'M2 8h20v9H2z M5 11v3m4-3v3m4-3v3m4-3v3 M12 2v6m0 9v5', iot: 'M5 5h14v14H5z M9 9h6v6H9z', nas: 'M3 3h18v18H3z M6 7h12 M6 12h12 M7 17h.01',
    device: 'M4 4h16v12H4z M2 20h20 M9 16v4m6-4v4', folder: 'M2 6h8l2 3h10v12H2z', sitemap: 'M12 3v5M5 21v-5h14v5M12 8v8M10 3h4v5h-4z', cube: 'M12 2l9 5v10l-9 5-9-5V7zM12 12l9-5M12 12v10M12 12L3 7'
  };
  ICONS.ap = ICONS.router;
  const labels = { computer: 'Computadores', router: 'Roteadores', phone: 'Celulares', tablet: 'Tablets', printer: 'Impressoras', server: 'Servidores', ap: 'Pontos de acesso', switch: 'Switches', nas: 'Armazenamento', camera: 'Câmeras', tv: 'Televisores', iot: 'IoT', device: 'Outros dispositivos' };
  const icon = (name, cls) => { const s = document.createElementNS(NS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); if (cls) s.setAttribute('class', cls); const p = document.createElementNS(NS, 'path'); p.setAttribute('d', ICONS[name] || ICONS.device); s.append(p); return s; };
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };

  let devices = [], network = 'Rede', scanning = false, W = 2200, H = 1300, zoom = 1, positions = {}, groups = new Map(), root = null;
  let drag = null, moved = false, touchEdit = false, fitAxis = 'width', fitX = 0, fitY = 0, original = [], activities = [];
  const storeKey = 'arc-topology-layout-v2:gestor', modeKey = storeKey + ':mode';
  try { positions = JSON.parse(localStorage.getItem(storeKey) || '{}'); if (!positions || typeof positions !== 'object' || Array.isArray(positions)) positions = {}; } catch (_) { positions = {}; }
  try { const last = localStorage.getItem(modeKey); if (['group', 'type', 'infrastructure'].includes(last)) mode.value = last; } catch (_) { }
  const save = () => { try { localStorage.setItem(storeKey, JSON.stringify(positions)); } catch (_) { } };

  function addActivity(message) {
    activities.unshift(new Date().toLocaleTimeString('pt-BR') + ' · ' + message); activities = activities.slice(0, 40);
    const list = $('activities'); list.replaceChildren(); for (const text of activities) list.append(el('li', '', text));
  }
  const put = (node, x, y) => { node.style.left = Math.max(0, Math.min(W - 170, x)) + 'px'; node.style.top = Math.max(0, Math.min(H - 90, y)) + 'px'; };
  const where = node => ({ x: parseFloat(node.style.left) || 0, y: parseFloat(node.style.top) || 0 });
  const center = node => { const p = where(node); return { x: p.x + node.offsetWidth / 2, y: p.y + node.offsetHeight / 2, top: p.y, bottom: p.y + node.offsetHeight }; };
  function line(a, b, kind) { const p = document.createElementNS(NS, 'path'); const mid = (a.bottom + b.top) / 2; p.setAttribute('d', `M ${a.x} ${a.bottom} C ${a.x} ${mid}, ${b.x} ${mid}, ${b.x} ${b.top}`); p.setAttribute('class', kind); paths.append(p); }
  function redraw() {
    svg.setAttribute('width', W); svg.setAttribute('height', H); svg.style.width = W + 'px'; svg.style.height = H + 'px'; paths.replaceChildren();
    if (!root) return; const a = center(root), infra = mode.value === 'infrastructure';
    for (const [, g] of groups) { const b = center(g.head); line(a, b, infra ? 'unverified' : 'trunk'); for (const item of g.items) line(b, center(item), infra ? 'unverified' : 'leaf'); }
  }
  function applyZoom() {
    const transform = `translate(${fitX}px,${fitY}px) scale(${zoom})`; content.style.transform = transform; svg.style.transform = transform;
    canvas.querySelector('#spacer')?.remove(); const spacer = el('div'); spacer.id = 'spacer';
    spacer.style.cssText = `position:absolute;left:0;top:0;pointer-events:none;width:${Math.ceil(W * zoom + fitX)}px;height:${Math.ceil(H * zoom + fitY)}px`;
    canvas.append(spacer); $('zoom-label').textContent = Math.round(zoom * 100) + '%';
  }
  function bounds() {
    const nodes = [root, ...branches.querySelectorAll('.group,.device')].filter(Boolean); if (!nodes.length) return null;
    return nodes.reduce((r, n) => { const p = where(n); return { minX: Math.min(r.minX, p.x), minY: Math.min(r.minY, p.y), maxX: Math.max(r.maxX, p.x + n.offsetWidth), maxY: Math.max(r.maxY, p.y + n.offsetHeight) }; }, { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity });
  }
  function fit(axis = 'width') {
    fitAxis = axis; const b = bounds(); if (!b) return; const margin = 12, cw = canvas.clientWidth, ch = canvas.clientHeight; if (!cw || !ch) return;
    const w = Math.max(1, b.maxX - b.minX), h = Math.max(1, b.maxY - b.minY);
    if (axis === 'width') { zoom = Math.min(1.4, (cw - margin * 2) / w); fitX = (cw - w * zoom) / 2 - b.minX * zoom; fitY = margin - b.minY * zoom; canvas.dataset.fit = 'width'; }
    else if (axis === 'all') { zoom = Math.min(1.4, (cw - margin * 2) / w, (ch - margin * 2) / h); fitX = (cw - w * zoom) / 2 - b.minX * zoom; fitY = (ch - h * zoom) / 2 - b.minY * zoom; canvas.dataset.fit = 'true'; }
    else { canvas.dataset.fit = 'false'; fitY = Math.max(0, ch / 2 - (b.minY + b.maxY) * zoom / 2); }
    applyZoom(); canvas.scrollLeft = 0; canvas.scrollTop = axis === 'height' ? Math.max(0, (b.minY + b.maxY) * zoom / 2 + fitY - ch / 2) : 0;
  }
  function attachDrag(node, id) {
    if (node.dataset.dragReady) return; node.dataset.dragReady = '1';
    node.addEventListener('pointerdown', e => { if (e.button !== 0 || (e.pointerType === 'touch' && !touchEdit)) return; e.preventDefault(); drag = { node, id, pid: e.pointerId, sx: e.clientX, sy: e.clientY, ...where(node) }; moved = false; node.setPointerCapture(e.pointerId); node.classList.add('is-dragging'); });
    node.addEventListener('pointermove', e => { if (!drag || drag.node !== node || drag.pid !== e.pointerId) return; const dx = (e.clientX - drag.sx) / zoom, dy = (e.clientY - drag.sy) / zoom; if (Math.abs(dx) + Math.abs(dy) > 4) moved = true; if (moved) { put(node, drag.x + dx, drag.y + dy); redraw(); } });
    node.addEventListener('pointerup', () => { if (!drag || drag.node !== node) return; node.classList.remove('is-dragging'); const was = moved; drag = null; if (!was) return; positions[id] = where(node); save(); if (id.startsWith('d:') && mode.value === 'group') transfer(node); else redraw(); });
    node.addEventListener('click', e => { if (moved) { e.stopImmediatePropagation(); e.preventDefault(); moved = false; } }, true);
  }
  function transfer(node) {
    const p = center(node); let target = null;
    for (const [name, g] of groups) { const q = center(g.head); if (Math.hypot(p.x - q.x, p.y - q.y) < 115 && name !== node.dataset.group) { target = name; break; } }
    redraw(); if (!target) return;
    post({ action: 'move', id: Number(node.dataset.id), group: target === 'Sem grupo' ? '' : target }); addActivity('Equipamento movido para o grupo ' + target);
  }
  function build() {
    original = devices.map(d => {
      const node = el('button', 'node device ' + (d.online ? 'online' : 'offline') + (d.gestor ? ' gestor' : '')); node.type = 'button'; node.dataset.id = d.id; node.dataset.type = d.type; node.title = d.name + ' · ' + d.ip;
      node.append(icon(d.type), el('span', '', d.name), el('small', '', d.ip)); if (d.gestor) { const c = icon('cube', 'cube'); c.setAttribute('aria-label', 'Gestor Windows instalado'); node.append(c); }
      node.addEventListener('click', () => post({ action: 'device', id: d.id })); return { node, group: d.group || 'Sem grupo', type: d.type || 'device' };
    });
  }
  function ensureRoot() {
    if (!root) { root = el('button', 'node hub'); root.type = 'button'; root.title = 'Executar varredura de dispositivos'; content.append(root); root.addEventListener('click', () => { if (!scanning) { post({ action: 'scan' }); addActivity('Varredura solicitada'); } }); }
    root.replaceChildren(icon('sitemap'), el('strong', '', network), el('small', '', scanning ? 'Examinando…' : 'Examinar rede')); root.disabled = scanning; root.title = 'Executar varredura de dispositivos';
  }
  function regroup() {
    ensureRoot(); const sets = new Map();
    for (const o of original) { const name = mode.value === 'infrastructure' ? (o.type === 'router' ? 'Roteadores' : o.type === 'switch' ? 'Switches' : o.type === 'ap' ? 'Pontos de acesso' : 'Outros dispositivos') : mode.value === 'type' ? (labels[o.type] || 'Outros') : o.group; if (!sets.has(name)) sets.set(name, []); sets.get(name).push(o.node); }
    branches.replaceChildren(); groups.clear(); const entries = [...sets].sort((a, b) => a[0].localeCompare(b[0], 'pt-BR'));
    const cols = Math.max(1, Math.min(innerWidth <= 860 ? 2 : 4, entries.length)); W = Math.max(320, cols * 270 + 50); let rowY = 175, rowH = 0;
    entries.forEach(([name, nodes], n) => {
      if (n && n % cols === 0) { rowY += rowH + 45; rowH = 0; } const x = 35 + (n % cols) * 270; rowH = Math.max(rowH, 100 + Math.ceil(nodes.length / 2) * 112);
      const head = el('div', 'node group'); head.append(icon(mode.value === 'infrastructure' ? 'sitemap' : 'folder'), el('strong', '', name), el('span', '', nodes.length + ' equipamentos')); head.title = name; head.dataset.group = name; branches.append(head); groups.set(name, { head, items: nodes });
      const saved = positions['g:' + mode.value + ':' + name]; head.style.left = (saved?.x ?? x) + 'px'; head.style.top = (saved?.y ?? rowY) + 'px'; attachDrag(head, 'g:' + mode.value + ':' + name);
      nodes.forEach((node, i) => { branches.append(node); node.dataset.group = name; const id = 'd:' + mode.value + ':' + node.dataset.id; const pos = positions[id] || (mode.value === 'group' ? positions['d:' + node.dataset.id] : null); node.style.left = (pos?.x ?? (x + (i % 2) * 118)) + 'px'; node.style.top = (pos?.y ?? (rowY + 90 + Math.floor(i / 2) * 112)) + 'px'; attachDrag(node, id); });
    });
    root.style.left = (positions.root?.x ?? Math.max(20, W / 2 - 93)) + 'px'; root.style.top = (positions.root?.y ?? 20) + 'px'; attachDrag(root, 'root');
    let maxX = W, maxY = rowY + rowH + 40; for (const n of [root, ...branches.children]) { maxX = Math.max(maxX, (parseFloat(n.style.left) || 0) + n.offsetWidth + 30); maxY = Math.max(maxY, (parseFloat(n.style.top) || 0) + n.offsetHeight + 30); }
    W = maxX; H = Math.max(380, maxY); content.style.width = W + 'px'; content.style.height = H + 'px'; $('empty').hidden = devices.length > 0; redraw(); requestAnimationFrame(() => fit(fitAxis || 'width'));
  }
  function receive(m) {
    if (!m || m.type !== 'data') return; devices = Array.isArray(m.devices) ? m.devices : []; network = m.network || 'Rede'; scanning = !!m.scanning;
    $('count').textContent = devices.length + ' dispositivos · ' + new Set(devices.map(d => d.group || 'Sem grupo')).size + ' grupos'; $('message').textContent = m.message || '';
    if (m.message && m.message !== receive.last) { addActivity(m.message); receive.last = m.message; } build(); regroup();
  }
  window.chrome?.webview?.addEventListener('message', event => receive(event.data));
  const step = delta => { zoom = Math.max(.3, Math.min(2.5, zoom + delta)); canvas.dataset.fit = 'false'; applyZoom(); };
  $('zoom-in').addEventListener('click', () => step(.1)); $('zoom-out').addEventListener('click', () => step(-.1));
  $('fit-width').addEventListener('click', () => fit('width')); $('fit-height').addEventListener('click', () => fit('height')); $('fit-all').addEventListener('click', () => fit('all'));
  $('print').addEventListener('click', () => { fit('all'); setTimeout(() => window.print(), 150); });
  $('fullscreen').addEventListener('click', async () => { const on = !document.fullscreenElement; try { if (on) await document.documentElement.requestFullscreen(); else await document.exitFullscreen(); } catch (_) { } $('fullscreen').setAttribute('aria-pressed', String(on)); setTimeout(() => fit(fitAxis), 120); });
  document.addEventListener('fullscreenchange', () => { $('fullscreen').setAttribute('aria-pressed', String(!!document.fullscreenElement)); setTimeout(() => fit(fitAxis), 120); });
  mode.addEventListener('change', () => { try { localStorage.setItem(modeKey, mode.value); } catch (_) { } regroup(); });
  $('touch-edit').addEventListener('change', e => { touchEdit = e.target.checked; });
  $('reset').addEventListener('click', () => { positions = {}; save(); regroup(); addActivity('Organização restaurada'); });
  const center_ = $('center'), settings = $('settings');
  function tab(name) { for (const b of center_.querySelectorAll('[data-tab]')) b.setAttribute('aria-selected', String(b.dataset.tab === name)); for (const s of center_.querySelectorAll('[data-view]')) s.hidden = s.dataset.view !== name; }
  function toggle(open) { center_.hidden = !open; settings.setAttribute('aria-expanded', String(open)); setTimeout(() => fit(fitAxis), 80); }
  settings.addEventListener('click', () => toggle(center_.hidden)); $('center-close').addEventListener('click', () => toggle(false));
  for (const b of center_.querySelectorAll('[data-tab]')) b.addEventListener('click', () => tab(b.dataset.tab));
  addEventListener('resize', () => fit(fitAxis));
  addActivity('Ferramenta aberta'); post({ action: 'ready' });
})();
