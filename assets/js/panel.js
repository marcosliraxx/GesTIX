/* Adaptive network pages keep every card readable and reachable. */
(() => {
  'use strict';
  const grid = document.getElementById('arc-panel-grid');
  if (!grid) return;
  const cards = Array.from(grid.querySelectorAll('[data-panel-network]'));
  const search = document.getElementById('arc-panel-search');
  const empty = document.getElementById('arc-panel-search-empty');
  const prev = document.getElementById('arc-panel-prev');
  const next = document.getElementById('arc-panel-next');
  const filters = Array.from(document.querySelectorAll('[data-panel-filter]'));
  let page = 0, capacity = 1, filter = 'all', frame = 0;
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const matches = () => {
    const term = normalize(search.value || '');
    return cards.filter(card => normalize(card.dataset.name || '').includes(term) &&
      (filter === 'all' || (filter === 'online' ? card.dataset.online === '1' : card.dataset.online !== '1')));
  };
  function render(reset = false) {
    if (reset) page = 0;
    document.getElementById('arc-panel-total').textContent = cards.length;
    const connected = cards.filter(card => card.dataset.online === '1').length;
    document.getElementById('arc-panel-online').textContent = connected;
    document.getElementById('arc-panel-offline').textContent = cards.length - connected;
    const matching = matches();
    const block = grid.parentElement;
    const blockStyle = getComputedStyle(block);
    const short = window.innerHeight <= 460;
    const gap = parseFloat(getComputedStyle(grid).columnGap) || 10;
    const width = Math.max(1, block.clientWidth - 4);
    const availableHeight = Math.max(1, block.clientHeight - block.querySelector('.arc-panel-section-heading').offsetHeight - block.querySelector('.arc-panel-pagination').offsetHeight - 2 * (parseFloat(blockStyle.rowGap) || 8) - 4);
    grid.dataset.compact = String(availableHeight < 150);
    const minCardWidth = window.innerWidth <= 860 ? 140 : 180;
    const minCardHeight = short ? 92 : (window.innerWidth <= 860 ? 160 : 174);
    const columns = Math.max(1, Math.min(Math.floor((width + gap) / (minCardWidth + gap)), matching.length || 1));
    const rows = Math.max(1, Math.floor((availableHeight + gap) / (minCardHeight + gap)));
    capacity = columns * rows;
    const pages = Math.max(1, Math.ceil(matching.length / capacity));
    page = Math.max(0, Math.min(page, pages - 1));
    const shown = matching.slice(page * capacity, (page + 1) * capacity);
    const visible = new Set(shown);
    cards.forEach(card => { card.hidden = !visible.has(card); });
    grid.hidden = !matching.length;
    empty.hidden = matching.length > 0;
    if (matching.length) {
      grid.style.setProperty('--panel-cols', columns);
      grid.style.setProperty('--panel-rows', Math.ceil(shown.length / columns));
    }
    document.getElementById('arc-panel-result-count').textContent = matching.length;
    const range = matching.length ? `${page * capacity + 1}–${page * capacity + shown.length} de ${matching.length} redes` : '0 redes';
    const rangeElement = document.getElementById('arc-panel-range');
    if (rangeElement.textContent !== range) rangeElement.textContent = range;
    document.getElementById('arc-panel-page').textContent = `${page + 1} / ${pages}`;
    prev.disabled = page === 0;
    next.disabled = page >= pages - 1;
    filters.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.panelFilter === filter)));
    if (!cards.length) {
      document.getElementById('arc-panel-empty-title').textContent = 'Sua primeira rede começa aqui';
      document.getElementById('arc-panel-empty-text').textContent = 'Registre um ambiente para começar a acompanhar suas conexões.';
      document.getElementById('arc-panel-clear').hidden = true;
      document.getElementById('arc-panel-empty-create').hidden = false;
    }
  }
  // Hidden empty states must not collapse the area used to calculate capacity.
  function schedule() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => render());
  }
  search.addEventListener('input', () => render(true));
  filters.forEach(button => button.addEventListener('click', () => { filter = button.dataset.panelFilter; render(true); }));
  document.getElementById('arc-panel-clear').addEventListener('click', () => { search.value = ''; filter = 'all'; render(true); search.focus(); });
  prev.addEventListener('click', () => { page--; render(); });
  next.addEventListener('click', () => { page++; render(); });
  // Arrow navigation is limited to the grid so text input editing remains native.
  grid.addEventListener('keydown', event => {
    if (event.key === 'ArrowRight' && !next.disabled) { event.preventDefault(); page++; render(); grid.querySelector('[data-panel-network]:not([hidden])')?.focus(); }
    if (event.key === 'ArrowLeft' && !prev.disabled) { event.preventDefault(); page--; render(); grid.querySelector('[data-panel-network]:not([hidden])')?.focus(); }
  });
  window.addEventListener('arc:network-status', schedule);
  window.addEventListener('resize', schedule);
  window.visualViewport?.addEventListener('resize', schedule);
  if ('ResizeObserver' in window) new ResizeObserver(schedule).observe(grid.parentElement);
  render();
})();
