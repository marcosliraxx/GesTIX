/* Caminho: assets/js/gestor-update.js
 * Gestor Windows no cabeçalho da rede.
 * - O botão do cabeçalho só abre o modal (Instalar / Atualizar); o estado do ícone vem de data-gestor-state.
 * - Nova versão: o aviso chega como notificação no sino, com o botão Atualizar (atualização remota do gestor).
 * - Autônomo: funciona em qualquer página com o cabeçalho de rede. Sem animações de alerta.
 */
(function () {
  'use strict';
  var dialog = document.getElementById('arc-gestor-update-confirm');
  var header = document.getElementById('arc-header-gestor-update');
  if (!dialog) return;

  var cfg = {
    network: Number(dialog.dataset.network || 0),
    base: String(dialog.dataset.base || '/'),
    csrf: String(dialog.dataset.csrf || ''),
    canManage: dialog.dataset.canManage === '1'
  };
  var POLL_MS = 60000;
  var state = { loaded: false, gestor: null, published: '', loading: null };
  var sending = false, notice = '';
  var el = {
    version: dialog.querySelector('[data-update-modal-version]'),
    status: dialog.querySelector('[data-update-status]'),
    cancel: dialog.querySelector('[data-update-cancel]'),
    update: dialog.querySelector('[data-update-confirm]')
  };

  function target() { return document.getElementById('arc-gestor-target'); }
  function gestorId() { var t = target(); return t ? Number(t.value || 0) : 0; }

  /* fetch com tempo limite e mensagens legíveis. */
  function request(url, options, timeoutMs) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
    var init = Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {});
    if (controller) init.signal = controller.signal;
    return fetch(url, init).then(function (response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* resposta não-JSON */ }
        if (!data) throw new Error(response.ok ? 'Resposta inesperada do servidor.' : 'O servidor respondeu HTTP ' + response.status + '.');
        if (!response.ok || data.ok === false) {
          var failure = new Error(data.error || ('Falha (HTTP ' + response.status + ').'));
          failure.code = data.error_code || ''; throw failure;
        }
        return data;
      });
    }, function (e) {
      if (e && e.name === 'AbortError') throw new Error('Tempo esgotado ao falar com o servidor. Tente novamente.');
      throw new Error('Sem conexão com o servidor.');
    }).finally(function () { if (timer) clearTimeout(timer); });
  }

  function outdated() { return !!(state.gestor && state.gestor.outdated); }
  function noticeId() { return 'gestor-update-' + gestorId() + '-' + state.published; }

  /* Notificação de nova versão no sino, com o botão Atualizar. */
  function paintNotification() {
    var list = document.querySelector('[data-arc-notify-list]');
    if (!list) return;
    var id = noticeId(), current = list.querySelector('[data-update-notice]');
    if (!outdated()) {
      if (current) { current.remove(); if (window.arcNotifications) window.arcNotifications.refresh(); }
      return;
    }
    var installed = String(state.gestor.version || '').replace(/^v\.?\s*/i, '') || 'desconhecida';
    var detail = 'Instalada: ' + installed + ' · Nova: ' + String(state.published).replace(/^v\.?\s*/i, '') + '.';
    if (current && current.dataset.noticeId === id) { current.querySelector('.button').disabled = !cfg.canManage || sending; return; }
    if (current) current.remove();
    var item = document.createElement('div');
    item.className = 'arc-notify-item arc-update-notice-item';
    item.dataset.updateNotice = '1'; item.dataset.noticeId = id;
    item.dataset.noticeTitle = 'Nova versão do gestor'; item.dataset.noticeDetail = detail;
    var title = document.createElement('b'); title.textContent = 'Nova versão do gestor';
    var text = document.createElement('span'); text.textContent = detail;
    var button = document.createElement('button');
    button.type = 'button'; button.className = 'button'; button.disabled = !cfg.canManage || sending;
    button.title = cfg.canManage ? 'Atualizar o gestor remotamente' : 'A atualização remota exige permissão administrativa';
    button.innerHTML = '<i class="fa fa-refresh" aria-hidden="true"></i> Atualizar';
    button.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (window.arcNotifications) window.arcNotifications.read(id);
      queue();
    });
    item.addEventListener('click', function () { if (window.arcNotifications) { window.arcNotifications.read(id); window.arcNotifications.refresh(); } });
    item.append(title, text, button);
    list.prepend(item);
    if (window.arcNotifications) window.arcNotifications.refresh();
  }

  function paintOutside() {
    var installed = state.gestor && state.gestor.version ? String(state.gestor.version) : '';
    var versionInfo = document.getElementById('arc-gestor-version-info');
    if (versionInfo) versionInfo.textContent = installed ? 'v. ' + installed.replace(/^v\.?\s*/i, '') : 'v. --';
    document.querySelectorAll('[data-tile-version]').forEach(function (n) {
      var type = n.dataset.tileVersion;
      if (type === 'gestor' || type === 'update') n.textContent = 'v' + (state.published || '--');
    });
    document.querySelectorAll('[data-arc-command="gestor_update"]').forEach(function (btn) {
      btn.title = outdated() ? 'Atualizar o gestor: ' + installed + ' → ' + state.published : 'Atualizar os arquivos do gestor';
      btn.disabled = !cfg.canManage || !gestorId();
    });
    paintNotification();
  }

  function render() {
    var selected = gestorId() > 0;
    if (el.version) el.version.textContent = state.published ? 'Versão publicada: ' + state.published : '';
    var text = notice;
    if (el.status) { el.status.textContent = text; el.status.hidden = !text; }
    if (el.update) {
      el.update.disabled = !cfg.canManage || !selected || sending;
      el.update.title = !cfg.canManage ? 'Disponível apenas para administradores' : !selected ? 'Nenhum gestor instalado neste computador' : 'Atualizar o gestor remotamente';
    }
  }

  /* Consulta de versões: deduplicada, com tempo limite e repetida enquanto a página estiver aberta. */
  function refresh() {
    if (state.loading) return state.loading;
    state.loading = request(cfg.base + 'api/api.php?action=network-gestor-versions&network_id=' + cfg.network, {}, 15000).then(function (r) {
      state.loaded = true;
      cfg.canManage = r.can_update === true;
      state.published = String(r.published_version || '');
      var id = gestorId();
      state.gestor = (r.gestores || []).find(function (a) { return Number(a.id) === id; }) || null;
    }).catch(function () {
      state.loaded = true; state.gestor = null;
    }).then(function () {
      state.loading = null; paintOutside(); render();
    });
    return state.loading;
  }

  function open() {
    notice = '';
    if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
    render(); refresh();
  }
  function close() { if (dialog.open) dialog.close(); }
  function setStatus(text) { notice = text; render(); }

  /* Atualização remota: o servidor valida permissão, gestor online e versão. */
  function queue() {
    if (sending) return Promise.resolve(false);
    var id = gestorId();
    if (!id) { setStatus('Nenhum gestor instalado neste computador. Use Instalar.'); if (!dialog.open) open(); return Promise.resolve(false); }
    sending = true; notice = ''; render(); paintNotification();
    return request(cfg.base + 'api/api.php?action=network-command', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify({ network_id: cfg.network, gestor_id: id, command: 'gestor_update', force_update: false, _csrf: cfg.csrf })
    }, 25000).then(function (data) {
      var commandId = Number(data.command_id || 0);
      document.dispatchEvent(new CustomEvent('arc:gestor-update-queued', { detail: { command_id: commandId, gestor_id: id, mode: 'normal' } }));
      document.dispatchEvent(new CustomEvent('arc:command-queued', { detail: { command: 'gestor_update', command_id: commandId, gestor_id: id } }));
      sending = false;
      if (dialog.open) close();
      render(); paintNotification();
      return true;
    }).catch(function (e) {
      sending = false;
      var message = 'Não foi possível solicitar a atualização: ' + e.message;
      if (e.code === 'gestor_reinstall_required') message = 'A versão do gestor não foi identificada. Reinstale pelo botão Instalar.';
      setStatus(message); paintNotification();
      if (!dialog.open) open();
      return false;
    });
  }

  /* Ouvintes únicos. */
  if (header) header.addEventListener('click', function (e) { e.preventDefault(); open(); });
  if (el.cancel) el.cancel.addEventListener('click', function (e) { e.preventDefault(); close(); });
  if (el.update) el.update.addEventListener('click', function (e) { e.preventDefault(); queue(); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) close(); });
  var installLink = dialog.querySelector('[data-update-install]');
  if (installLink) installLink.addEventListener('click', function () { setStatus('Gerando o instalador. O download começa em instantes; execute-o no computador de destino.'); });
  var t = target();
  if (t) t.addEventListener('change', function () { state.gestor = null; refresh(); });

  /* Botões do painel (mosaicos) que também pedem atualização. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-arc-command="gestor_update"]') : null;
    if (!b || b.disabled || e.defaultPrevented) return;
    if (!gestorId()) return;
    e.preventDefault(); e.stopImmediatePropagation();
    queue();
  }, true);

  window.arcGestorUpdate = { open: open, refresh: refresh, queue: queue };
  refresh();
  setInterval(function () { if (!document.hidden && !sending) refresh(); }, POLL_MS);
})();
