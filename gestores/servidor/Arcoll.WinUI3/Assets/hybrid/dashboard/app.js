/* Caminho: gestores/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/app.js */
/* Caminho: assets/network-panel/panel.js — mesma interface distribuída ao gestor Windows. */
(() => {
  'use strict';
  const root = document.getElementById('network-panel');
  if (!root) return;
  const bridge = window.chrome?.webview;
  const paths = {
    device:'M4 4h16v12H4z M2 20h20 M9 16v4m6-4v4',
    computer:'M3 3h18v13H3z M8 21h8m-4-5v5',
    phone:'M7 2h10v20H7z M10 18h4',
    tablet:'M4 2h16v20H4z M10 18h4',
    router:'M2 8a16 16 0 0 1 20 0 M5 12a11 11 0 0 1 14 0 M9 16a5 5 0 0 1 6 0 M12 20h.01',
    server:'M4 3h16v7H4z M4 14h16v7H4z M7 6h.01 M7 17h.01 M11 6h6 M11 17h6',
    printer:'M6 9V2h12v7 M6 18H3V9h18v9h-3 M6 14h12v8H6z',
    camera:'M2 5h14v14H2z M16 9l6-4v14l-6-4',
    tv:'M2 5h20v14H2z M8 23l4-4 4 4 M8 1l4 4 4-4',
    iot:'M5 5h14v14H5z M9 9h6v6H9z M9 1v4m6-4v4M9 19v4m6-4v4M1 9h4m-4 6h4m14-6h4m-4 6h4',
    nas:'M3 3h18v18H3z M6 7h12 M6 12h12 M7 17h.01',
    switch:'M2 8h20v9H2z M5 11v3m4-3v3m4-3v3m4-3v3 M12 2v6m0 9v5',
    console:'M7 7h10l4 4 1 9-5-4H7l-5 4 1-9z M5 11h5m-2-2v5m7-3h.01m3 3h.01',
    speaker:'M5 2h14v20H5z M12 6h.01 M8 15a4 4 0 1 0 8 0a4 4 0 1 0-8 0',
    grid:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    list:'M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01',
    group:'M2 6h8l2 3h10v12H2z',
    refresh:'M20 8a8 8 0 1 0 0 9 M20 2v6h-6',
    fit:'M3 9V3h6m6 0h6v6M3 15v6h6m6 0h6v-6',
    clock:'M12 3a9 9 0 1 0 .01 0 M12 7v5l3 2',
    search:'M10 3a7 7 0 1 0 0 14a7 7 0 1 0 0-14 M16 16l6 6',
    close:'M5 5l14 14M19 5L5 19',
  };
  const aliases = {desktop:'computer',pc:'computer',computador:'computer',notebook:'device',laptop:'device',mobile:'phone',smartphone:'phone',celular:'phone',roteador:'router',ap:'router',impressora:'printer',servidor:'server',dispositivo:'device'};
  const typeNames = {device:'Dispositivo',computer:'Computador',phone:'Celular',tablet:'Tablet',router:'Roteador / Wi-Fi',server:'Servidor',printer:'Impressora',camera:'Câmera',tv:'TV',iot:'IoT',nas:'NAS',switch:'Switch',console:'Console',speaker:'Áudio'};
  const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
  const flag = value => value === true || value === 1 || value === '1';
  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.7'); svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS(svg.namespaceURI, 'path'); path.setAttribute('d', paths[name] || paths.device); svg.append(path); return svg;
  }
  function el(tag, className, text) { const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = text; return node; }
  root.innerHTML = `<header class="np-heading"><div><span class="np-eyebrow">PRESENÇA NA REDE</span><h1>Painel de dispositivos</h1><p class="np-network"></p></div><span class="np-live" role="status">Aguardando inventário</span></header>
  <div class="np-toolbar"><div class="np-filters" role="group" aria-label="Situação dos dispositivos"></div><label class="np-search"><span class="np-search-icon"></span><span class="np-sr">Buscar nome, IP, MAC ou grupo</span><input type="search" placeholder="Buscar dispositivo, IP ou MAC…" autocomplete="off" maxlength="160"></label><div class="np-actions" role="group" aria-label="Visualização"></div></div>
  <div class="np-notice" role="status" hidden></div><div class="np-body"><section class="np-devices" aria-label="Dispositivos"><div class="np-result"></div><div class="np-scroll"><div class="np-grid"></div><p class="np-empty" hidden></p></div></section>
  <aside class="np-activity"><header><span class="np-activity-icon"></span><div><h2>Entradas e saídas</h2><p>Últimas observações dos gestores</p></div></header><ol class="np-events"></ol><p class="np-history-note">O histórico começa com esta atualização. Horários indicam detecções do gestor.</p></aside></div>
  <footer class="np-footer"><span class="np-updated">Sem inventário registrado</span><span class="np-polled"></span><span class="np-sync">Consulta automática a cada 15 s</span></footer>
  <dialog class="np-dialog" aria-labelledby="np-detail-title"><header><span class="np-detail-icon"></span><div><h2 id="np-detail-title">Dispositivo</h2><p class="np-detail-state"></p></div><button type="button" class="np-close" aria-label="Fechar detalhes"></button></header><div class="np-detail-content"><dl class="np-detail-rows"></dl><h3>Últimas mudanças deste dispositivo</h3><ol class="np-detail-events"></ol><p class="np-detail-note">As detecções dependem da frequência de coleta e das respostas do dispositivo. A perda do gestor não comprova uma saída.</p></div></dialog>`;
  const find = selector => root.querySelector(selector);
  const grid = find('.np-grid'), notice = find('.np-notice'), search = find('input'), dialog = find('dialog');
  let devices = [], events = [], filter = 'all', view = 'grid', sort = 'recent', fit = false;
  let active = !bridge, connected = !bridge, busy = false, controller, timeout, requestId = 0, selected = null;
  let serverNow = Date.now() / 1000, receivedAt = performance.now(), received = false, failed = false, lastFingerprint = '', lastRequest = 0, limited = false, disposed = false;
  const now = () => serverNow + (performance.now() - receivedAt) / 1000;
  const status = d => d.state === 'unknown' || flag(d.x) ? 'unknown' : flag(d.o) ? 'online' : 'offline';
  const type = d => { const t = normalize(d.t || 'device').trim(); return typeNames[t] ? t : aliases[t] || 'device'; };
  const key = d => String(d.id || ('mac:' + String(d.m || '').toLowerCase()));
  function date(value) { const n = number(value); return n > 0 ? new Date(n * 1000).toLocaleString('pt-BR') : 'Não informado'; }
  function elapsed(value) { const seconds = Math.max(0, Math.floor(now() - number(value))); if (!number(value)) return 'tempo não informado'; if (seconds < 60) return `${seconds} s`; const minutes = Math.floor(seconds / 60); if (minutes < 60) return `${minutes} min`; const hours = Math.floor(minutes / 60); if (hours < 24) return `${hours} h ${minutes % 60} min`; return `${Math.floor(hours / 24)} d ${hours % 24} h`; }
  function duration(d) { const state = status(d); return state === 'unknown' ? 'Último contato há ' + elapsed(d.l) : (state === 'online' ? 'Online há ' : 'Offline há ') + elapsed(d.s || d.c); }
  const labels = {all:'Todos',online:'Online',offline:'Offline',unknown:'Sem confirmação'};
  function button(label, glyph, action) { const b = el('button', 'np-button'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); if (glyph) b.append(icon(glyph)); b.addEventListener('click', action); return b; }
  const filters = new Map();
  for (const value of ['all', 'online', 'offline', 'unknown']) {
    const b = button(labels[value], value === 'all' ? 'grid' : null, () => { filter = value; render(); });
    b.dataset.filter = value; if (value !== 'all') b.append(el('i', 'np-dot ' + value));
    b.append(el('span', 'np-filter-label', labels[value]), el('strong', '', '0')); filters.set(value, b); find('.np-filters').append(b);
  }
  const views = new Map();
  for (const [value, label, glyph] of [['grid', 'Grade', 'grid'], ['type', 'Por tipo', 'list'], ['group', 'Por grupo', 'group']]) {
    const b = button(label, glyph, () => { view = value; render(); }); b.dataset.view = value; views.set(value, b); find('.np-actions').append(b);
  }
  const sortButton = button('Ordenar por nome', 'list', () => { sort = sort === 'recent' ? 'name' : 'recent'; sortButton.title = sort === 'recent' ? 'Ordenar por nome' : 'Ordenar por mudanças recentes'; sortButton.setAttribute('aria-label', sortButton.title); sortButton.setAttribute('aria-pressed', String(sort === 'name')); render(); });
  const fitButton = button('Ajustar dispositivos ao espaço', 'fit', () => { fit = !fit; fitButton.setAttribute('aria-pressed', String(fit)); root.classList.toggle('np-fit', fit); layout(); });
  const refreshButton = button('Atualizar inventário', 'refresh', () => load());
  find('.np-actions').append(sortButton, fitButton, refreshButton);
  find('.np-search-icon').append(icon('search')); find('.np-activity-icon').append(icon('clock')); find('.np-close').append(icon('close'));
  find('.np-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); } });
  dialog.addEventListener('close', () => selected = null);
  search.addEventListener('input', render);
  function showNotice(text, error = false) { notice.textContent = text; notice.hidden = !text; notice.classList.toggle('np-error', error); }
  function live(text, error = false) { find('.np-live').textContent = text; find('.np-live').classList.toggle('np-error', error); }
  function eventRow(event) {
    const item = el('li', 'np-event ' + (['online','offline','detected'].includes(event.event_type) ? event.event_type : 'detected'));
    const names = {detected:'Primeira detecção',online:'Entrada observada',offline:'Saída observada'};
    const content = el('div'); content.append(el('strong', '', event.device_name || event.ip || 'Dispositivo'), el('span', '', names[event.event_type] || 'Mudança observada'));
    const when = el('time', '', date(event.occurred_at)); if (number(event.occurred_at)) when.dateTime = new Date(number(event.occurred_at) * 1000).toISOString();
    const meta = el('small', '', [event.ip, 'Gestor #' + event.gestor_id].filter(Boolean).join(' · ')); content.append(when, meta);
    const dot = el('i', 'np-event-dot'); item.append(dot, content); return item;
  }
  function renderEvents() {
    const timeline = find('.np-events'); timeline.replaceChildren();
    for (const event of events.slice(0, 30)) timeline.append(eventRow(event));
    if (!events.length) timeline.append(el('li', 'np-no-events', 'Nenhuma mudança registrada ainda. As novas detecções aparecerão aqui.'));
  }
  function renderDetails() {
    if (!selected) return;
    const d = devices.find(item => key(item) === selected); const rows = find('.np-detail-rows'); rows.replaceChildren();
    if (!d) { find('.np-detail-state').textContent = 'Dispositivo indisponível no inventário atual'; find('.np-detail-events').replaceChildren(); return; }
    find('#np-detail-title').textContent = d.n || 'Dispositivo'; find('.np-detail-icon').replaceChildren(icon(type(d)));
    const state = status(d); find('.np-detail-state').textContent = labels[state] + ' · ' + duration(d);
    for (const [label, value] of [['Nome', d.n], ['IP', d.ip], ['MAC', d.m], ['Tipo', typeNames[type(d)]], ['Grupo', d.g || 'Sem grupo'], ['Situação', labels[state]], ['Desde', state === 'unknown' ? 'Estado não confirmado' : date(d.s || d.c)], ['Último contato', date(d.l)], ['Primeira detecção', date(d.f)], ['Última mudança', date(d.c)], ['Sensor de referência', 'Gestor #' + d.gestor_id], ['Sensores que identificaram', d.observers || 1], ['Origem', d.source]]) {
      const row = el('div'); row.append(el('dt', '', label), el('dd', '', value || 'Não informado')); rows.append(row);
    }
    const history = find('.np-detail-events'); history.replaceChildren();
    const relevant = events.filter(event => String(event.identity_key).toLowerCase() === key(d).toLowerCase());
    relevant.slice(0, 15).forEach(event => history.append(eventRow(event)));
    if (!relevant.length) history.append(el('li', 'np-no-events', 'Sem eventos deste dispositivo entre as últimas 100 observações da rede.'));
  }
  function details(d) { selected = key(d); renderDetails(); if (!dialog.open) dialog.showModal(); }
  function render() {
    for (const [value, b] of filters) { b.querySelector('strong').textContent = value === 'all' ? devices.length : devices.filter(d => status(d) === value).length; b.setAttribute('aria-pressed', String(filter === value)); }
    for (const [value, b] of views) b.setAttribute('aria-pressed', String(view === value));
    const term = normalize(search.value.trim());
    const visible = devices.filter(d => (filter === 'all' || status(d) === filter) && normalize([d.n, d.ip, d.m, d.g, typeNames[type(d)]].join(' ')).includes(term));
    visible.sort((a, b) => (sort === 'name' ? String(a.n || '').localeCompare(String(b.n || ''), 'pt-BR', {numeric:true}) : number(b.c || b.s) - number(a.c || a.s)) || key(a).localeCompare(key(b)));
    const focus = root.contains(document.activeElement) ? document.activeElement?.dataset.key : null;
    grid.replaceChildren();
    const groups = new Map();
    for (const d of visible) { const name = view === 'type' ? typeNames[type(d)] : view === 'group' ? d.g || 'Sem grupo' : ''; if (!groups.has(name)) groups.set(name, []); groups.get(name).push(d); }
    for (const [name, items] of groups) {
      const section = el('section', 'np-section'); if (name) section.append(el('h2', '', name + ' · ' + items.length));
      const tiles = el('div', 'np-tiles');
      for (const d of items) {
        const state = status(d), b = el('button', 'np-tile ' + state); b.type = 'button'; b.dataset.key = key(d);
        b.setAttribute('aria-label', `${d.n || 'Dispositivo'} · ${labels[state]} · ${d.ip || 'IP não informado'}`);
        b.title = [d.n || 'Dispositivo', labels[state], d.ip, d.m, duration(d)].filter(Boolean).join(' · ');
        const tileTop = el('span', 'np-tile-top'); tileTop.append(icon(type(d)), el('span', 'np-tile-state', state === 'unknown' ? '?' : state === 'online' ? '●' : '○'));
        const clock = el('span', 'np-duration', duration(d)); clock.dataset.device = key(d);
        b.append(tileTop, el('strong', 'np-name', d.n || 'Dispositivo'), el('span', 'np-ip', d.ip || d.m || 'Sem IP'), clock);
        b.addEventListener('click', () => details(d)); tiles.append(b);
      }
      section.append(tiles); grid.append(section);
    }
    find('.np-result').textContent = `${visible.length} de ${devices.length} dispositivos` + (filter !== 'all' ? ' · ' + labels[filter] : '') + (limited ? ' · Inventário parcial' : '');
    const empty = find('.np-empty'); empty.hidden = visible.length > 0; empty.textContent = !received ? 'Aguardando inventário da rede…' : devices.length ? 'Nenhum dispositivo corresponde à busca ou ao filtro.' : 'Nenhum dispositivo registrado. Aguarde a coleta do gestor com o monitoramento habilitado.';
    layout(); renderDetails();
    if (focus) Array.from(grid.querySelectorAll('[data-key]')).find(b => b.dataset.key === focus)?.focus({preventScroll:true});
  }
  function layout() {
    if (!fit) { grid.style.removeProperty('--np-cols'); root.classList.remove('np-fit-limited'); return; }
    const sections = Array.from(grid.querySelectorAll('.np-section')), width = find('.np-scroll').clientWidth - 16, height = find('.np-scroll').clientHeight - 16;
    if (width < 1 || height < 1 || !sections.length) return;
    let best = {cols:1, score:0, height:0};
    const counts = sections.map(section => section.querySelectorAll('.np-tile').length);
    for (let cols = 1; cols <= Math.max(1, Math.floor(width / 72)); cols++) {
      const rows = counts.reduce((sum, n) => sum + Math.ceil(n / cols), 0), tileHeight = (height - sections.length * (view === 'grid' ? 0 : 27) - rows * 5) / rows;
      const score = Math.min((width - (cols - 1) * 5) / cols / 1.4, tileHeight);
      if (score > best.score) best = {cols, score, height:tileHeight};
    }
    grid.style.setProperty('--np-cols', best.cols); root.classList.toggle('np-fit-limited', best.height < 66);
    // Preserva alvos legíveis; inventários grandes continuam com rolagem.
    grid.style.setProperty('--np-tile-height', Math.max(66, Math.min(140, best.height)) + 'px');
  }
  function accept(data) {
    if (!data || data.ok !== true || !Array.isArray(data.devices)) throw Error('Inventário inválido recebido do servidor.');
    serverNow = number(data.now) || Date.now() / 1000; receivedAt = performance.now();
    const incoming = data.devices.filter(d => d && typeof d === 'object');
    const next = JSON.stringify(incoming); devices = incoming;
    events = Array.isArray(data.events) ? data.events.filter(event => event && typeof event === 'object') : [];
    limited = !!data.limited; received = true; failed = false;
    find('.np-network').textContent = data.name || data.network_name || find('.np-network').textContent;
    find('.np-updated').textContent = number(data.updated) > 0 ? 'Inventário: ' + date(data.updated) : 'Sem inventário registrado';
    find('.np-polled').textContent = 'Consultado: ' + date(data.now || serverNow);
    const observers = Array.isArray(data.gestores) ? data.gestores : [], onlineGestores = observers.filter(a => flag(a.online)).length;
    live(observers.length ? `${onlineGestores}/${observers.length} gestores comunicando` : 'Nenhum gestor aprovado', observers.length > 0 && !onlineGestores);
    showNotice(limited ? 'Exibindo inventário parcial: limite de 5.000 observações por consulta.' : observers.length && !onlineGestores ? 'Gestores sem comunicação. A situação dos dispositivos está sem confirmação; isso não comprova uma saída da rede.' : observers.some(a => a.collecting !== undefined && !flag(a.collecting)) ? 'Há sensores com coleta desatualizada. Dispositivos sem observação recente aparecem sem confirmação.' : '');
    if (next !== lastFingerprint) { lastFingerprint = next; render(); } else { renderDetails(); tick(); }
    renderEvents();
  }
  function finish() { clearTimeout(timeout); busy = false; refreshButton.disabled = !connected; refreshButton.classList.remove('np-busy'); }
  function failure(error) { failed = true; live('Falha na atualização', true); showNotice((error?.message || String(error)) + (received ? ' · Último inventário preservado.' : ''), true); finish(); }
  async function load() {
    if (disposed || busy || !active || !connected || document.hidden) return;
    busy = true; lastRequest = performance.now(); const id = ++requestId; refreshButton.disabled = true; refreshButton.classList.add('np-busy'); live('Atualizando…');
    if (bridge) {
      timeout = setTimeout(() => { if (id === requestId && busy) failure(Error('Tempo limite ao consultar a plataforma.')); }, 22000);
      bridge.postMessage({type:'network-refresh', requestId:id}); return;
    }
    try {
      controller = new AbortController(); timeout = setTimeout(() => controller.abort(), 18000);
      const url = new URL(root.dataset.url, location.href); url.searchParams.set('_', Date.now());
      const response = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{Accept:'application/json'}});
      if (response.status === 401 || response.status === 403) throw Error('Sessão ou permissão indisponível. Entre novamente na plataforma.');
      let data; try { data = await response.json(); } catch { throw Error('Resposta inválida. Verifique a sessão da plataforma.'); }
      if (!response.ok || !data.ok) throw Error(data.error || 'Não foi possível consultar o inventário.');
      if (!disposed) accept(data);
    } catch (error) { if (!disposed) failure(error.name === 'AbortError' ? Error('Tempo limite ao consultar a plataforma.') : error); }
    finally { finish(); }
  }
  function tick() {
    if (!active || document.hidden) return;
    if (received && !failed && (performance.now() - receivedAt) > 45000) { live('Dados sem atualização', true); showNotice('Inventário sem atualização há ' + elapsed(serverNow) + '. Os cartões exibem a última consulta recebida.', true); }
    const byKey = new Map(devices.map(d => [key(d), d]));
    for (const clock of grid.querySelectorAll('.np-duration')) { const d = byKey.get(clock.dataset.device); if (d) clock.textContent = duration(d); }
    if (dialog.open && selected) { const d = byKey.get(selected); if (d) find('.np-detail-state').textContent = labels[status(d)] + ' · ' + duration(d); }
  }
  if (bridge) {
    refreshButton.disabled = true;
    bridge.addEventListener('message', ({data}) => {
      if (data?.type === 'state') {
        const wasActive = active; active = data.section === 'panel'; connected = !!data.connected;
        root.dataset.theme = data.theme === 'dark' ? 'dark' : 'light';
        if (/^#[0-9a-f]{6}$/i.test(data.accent || '')) root.style.setProperty('--np-accent', data.accent);
        find('.np-network').textContent = data.network || '';
        if (!connected) { requestId++; finish(); devices = []; events = []; received = false; lastFingerprint = ''; render(); renderEvents(); live('Autorização necessária', true); showNotice('Autorize este computador na plataforma para consultar os dispositivos da rede.'); }
        else if (active && (!received || !wasActive || performance.now() - receivedAt > 15000)) load();
      } else if (data?.type === 'network-data' && number(data.requestId) === requestId && connected) {
        try { accept(data.payload); finish(); } catch (error) { failure(error); }
      } else if (data?.type === 'network-error' && number(data.requestId) === requestId) failure(Error(data.message || 'Consulta indisponível.'));
    });
    bridge.postMessage({type:'network-ready'});
  }
  const poll = setInterval(() => { if (performance.now() - lastRequest >= 14000) load(); }, 15000);
  const clock = setInterval(tick, 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { load(); tick(); } });
  window.addEventListener('pagehide', event => { controller?.abort(); if (!event.persisted) { disposed = true; clearInterval(poll); clearInterval(clock); clearTimeout(timeout); } });
  window.addEventListener('pageshow', event => { if (event.persisted) { finish(); load(); } });
  const observer = new ResizeObserver(layout); observer.observe(find('.np-scroll'));
  render(); renderEvents(); if (!bridge) load();
})();
