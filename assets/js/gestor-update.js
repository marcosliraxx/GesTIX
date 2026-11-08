/* Caminho: assets/js/gestor-update.js
 * Modal "Gestor Windows": instalar, atualizar e forçar atualização.
 * Autônomo: funciona em qualquer página com o cabeçalho de rede (inclusive as que não carregam _rodape.php).
 * - O modal abre imediatamente; a consulta de versões roda em segundo plano (com tempo limite).
 * - Botões com ouvintes únicos (sem acúmulo a cada abertura) e proteção contra clique duplo.
 * - O envio não espera nova consulta de versões: o servidor valida permissão, presença e versão.
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
  var state = { loaded: false, failed: false, gestor: null, published: '', notes: '', mode: 'source', loading: null };
  var sending = false, notice = '';
  var el = {
    name: dialog.querySelector('[data-update-gestor-name]'),
    version: dialog.querySelector('[data-update-modal-version]'),
    details: dialog.querySelector('[data-update-gestor-details]'),
    status: dialog.querySelector('[data-update-status]'),
    release: dialog.querySelector('[data-update-release]'),
    cancel: dialog.querySelector('[data-update-cancel]'),
    normal: dialog.querySelector('[data-update-confirm]'),
    force: dialog.querySelector('[data-update-force-confirm]')
  };

  function target() { return document.getElementById('arc-gestor-target'); }
  function gestorId() { var t = target(); return t ? Number(t.value || 0) : 0; }
  function gestorLabel() {
    var t = target();
    var opt = t && t.selectedOptions && t.selectedOptions[0];
    return opt && t.value ? String(opt.textContent || '').trim() : 'Nenhum gestor selecionado';
  }
  function tiles() { return document.querySelectorAll('[data-arc-command="gestor_update"]'); }

  /* fetch com tempo limite e mensagens legíveis (nunca fica esperando indefinidamente). */
  function request(url, options, timeoutMs) {
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
    var init = Object.assign({ credentials: 'same-origin', cache: 'no-store' }, options || {});
    if (controller) init.signal = controller.signal;
    return fetch(url, init).then(function (response) {
      return response.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* resposta não-JSON */ }
        if (!data) {
          var err = new Error(response.ok ? 'Resposta inesperada do servidor.' : 'O servidor respondeu HTTP ' + response.status + '.');
          err.http = response.status; throw err;
        }
        if (!response.ok || data.ok === false) {
          var failure = new Error(data.error || ('Falha (HTTP ' + response.status + ').'));
          failure.http = response.status; failure.code = data.error_code || ''; failure.payload = data; throw failure;
        }
        return data;
      });
    }, function (e) {
      if (e && e.name === 'AbortError') throw new Error('Tempo esgotado ao falar com o servidor. Tente novamente.');
      throw new Error('Sem conexão com o servidor.');
    }).finally(function () { if (timer) clearTimeout(timer); });
  }

  function paintOutside() {
    var a = state.gestor, outdated = !!(a && a.outdated), needsInstall = !!(a && a.requires_reinstall);
    var installed = a && a.version ? String(a.version) : '';
    if (header) {
      header.classList.toggle('arc-update-available', outdated);
      header.dataset.updateAvailable = outdated ? '1' : '0';
      header.title = outdated
        ? 'Atualização Windows disponível: ' + (installed || 'versão desconhecida') + ' → ' + state.published
        : 'Gestor Windows: instalar ou atualizar';
      header.setAttribute('aria-label', header.title);
    }
    var versionInfo = document.getElementById('arc-gestor-version-info');
    if (versionInfo) versionInfo.textContent = installed ? 'v. ' + installed.replace(/^v\.?\s*/i, '') : 'v. --';
    document.querySelectorAll('[data-tile-version]').forEach(function (n) {
      var type = n.dataset.tileVersion;
      if (type === 'gestor' || type === 'update') n.textContent = 'v' + (state.published || '--');
    });
    tiles().forEach(function (btn) {
      btn.classList.toggle('arc-update-available', outdated);
      btn.dataset.updateAvailable = outdated ? '1' : '0';
      btn.dataset.requiresReinstall = needsInstall ? '1' : '0';
      btn.title = needsInstall ? 'Versão do gestor não identificada' : outdated ? 'Atualização disponível: ' + installed + ' → ' + state.published : 'Atualizar os arquivos do gestor';
      btn.disabled = !cfg.canManage || !gestorId();
      var small = btn.querySelector('small');
      if (needsInstall && small) small.textContent = 'Versão não identificada';
    });
  }

  function render() {
    var selected = gestorId() > 0, updatable = selected && cfg.canManage && !sending;
    var a = state.gestor;
    var installed = a && a.version ? String(a.version) : (state.loaded ? 'não identificada' : 'consultando…');
    var published = state.published || (state.loaded ? 'indisponível' : 'consultando…');
    if (el.name) el.name.textContent = gestorLabel();
    if (el.version) el.version.textContent = 'Versão publicada: ' + published;
    if (el.details) el.details.textContent = 'Instalada: ' + installed + ' · Publicada: ' + published + '.';
    var text;
    if (sending) text = 'Enviando o pedido ao servidor…';
    else if (notice) text = notice;
    else if (!state.loaded && !state.failed) text = 'Consultando a versão instalada…';
    else if (state.failed) text = 'Não foi possível consultar as versões agora. Você ainda pode tentar atualizar: o servidor confere as condições no envio.';
    else if (!selected) text = 'Selecione um gestor para atualizar remotamente, ou use "Instalar gestor" para um novo computador.';
    else if (!cfg.canManage) text = 'Seu perfil pode consultar a versão. A atualização remota exige permissão administrativa.';
    else if (a && a.requires_reinstall) text = 'Versão não identificada: a atualização normal pode exigir reinstalação; a forçada tenta recuperar o gestor conectado.';
    else if (a && a.migrate_native) text = 'O gestor antigo será migrado para WinUI 3 pelo canal remoto autorizado.';
    else if (a && a.outdated) text = 'Há uma nova versão disponível para este computador.';
    else text = 'Nenhuma atualização nova detectada. Você ainda pode solicitar uma atualização ou reinstalação forçada.';
    if (el.status) el.status.textContent = text;
    if (el.release) el.release.textContent = (state.notes || '').trim() || 'Nenhuma nota de versão foi publicada para esta atualização.';
    [el.normal, el.force].forEach(function (b) { if (b) b.disabled = !updatable; });
    if (el.normal) el.normal.title = !cfg.canManage ? 'Disponível apenas para administradores' : !selected ? 'Selecione um gestor' : 'Solicitar atualização remota';
    if (el.force) el.force.title = !cfg.canManage ? 'Disponível apenas para administradores' : !selected ? 'Selecione um gestor' : 'Reinstalar o gestor a partir do pacote publicado';
  }

  /* Consulta de versões: deduplicada e com tempo limite. */
  function refresh() {
    if (state.loading) return state.loading;
    state.loading = request(cfg.base + 'api/api.php?action=network-gestor-versions&network_id=' + cfg.network, {}, 15000).then(function (r) {
      state.failed = false; state.loaded = true;
      cfg.canManage = r.can_update === true;
      state.published = String(r.published_version || '');
      state.notes = String(r.release_notes || '');
      state.mode = String(r.release_mode || 'source');
      var id = gestorId();
      state.gestor = (r.gestores || []).find(function (a) { return Number(a.id) === id; }) || null;
      document.dispatchEvent(new CustomEvent('arc:release-status', { detail: r.release || null }));
    }).catch(function () {
      state.failed = true; state.loaded = true; state.gestor = null;
    }).then(function () {
      state.loading = null; paintOutside(); render();
    });
    return state.loading;
  }

  function open(keepNotice) {
    if (keepNotice !== true) notice = '';
    if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
    render();      // mostra imediatamente o que já se sabe
    refresh();     // completa em segundo plano
  }
  function close() { if (dialog.open) dialog.close(); }

  /* Envia o pedido. Sem consulta prévia: o servidor valida permissão, gestor online e versão. */
  function queue(mode) {
    if (sending) return Promise.resolve(false);
    var id = gestorId();
    if (!id) { setStatus('Selecione um gestor aprovado.'); if (!dialog.open) open(true); return Promise.resolve(false); }
    sending = true; notice = ''; render();
    var force = mode === 'force';
    return request(cfg.base + 'api/api.php?action=network-command', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify({ network_id: cfg.network, gestor_id: id, command: 'gestor_update', force_update: force, _csrf: cfg.csrf })
    }, 25000).then(function (data) {
      var commandId = Number(data.command_id || 0);
      document.dispatchEvent(new CustomEvent('arc:gestor-update-queued', { detail: { command_id: commandId, gestor_id: id, mode: force ? 'force' : 'normal' } }));
      document.dispatchEvent(new CustomEvent('arc:command-queued', { detail: { command: 'gestor_update', command_id: commandId, gestor_id: id } }));
      sending = false;
      var message = data.already_queued
        ? 'Já existe uma atualização em andamento para este computador (#' + commandId + '). Acompanhe pela fila de operações.'
        : 'Pedido enviado (#' + commandId + '). O computador executa assim que responder; acompanhe pela fila de operações.';
      if (typeof window.arcRemoteFailure === 'function' && !data.already_queued) { notice = ''; close(); } else { setStatus(message); }
      return true;
    }).catch(function (e) {
      sending = false;
      var message = 'Não foi possível solicitar a atualização: ' + e.message;
      if (e.code === 'gestor_reinstall_required') message = 'A versão do gestor não foi identificada. Use "Forçar atualização" ou reinstale pelo botão "Instalar gestor".';
      setStatus(message);
      if (!dialog.open) open(true); // falha vinda de um mosaico: mostra o motivo no modal
      return false;
    });
  }
  function setStatus(text) { notice = text; render(); }

  /* Ouvintes únicos. */
  if (header) header.addEventListener('click', function (e) { e.preventDefault(); open(); });
  if (el.cancel) el.cancel.addEventListener('click', function (e) { e.preventDefault(); close(); });
  if (el.normal) el.normal.addEventListener('click', function (e) { e.preventDefault(); queue('normal'); });
  if (el.force) el.force.addEventListener('click', function (e) { e.preventDefault(); queue('force'); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) close(); }); // clique no fundo
  var installLink = dialog.querySelector('[data-update-install]');
  if (installLink) installLink.addEventListener('click', function () { setStatus('Gerando o instalador. O download começa em instantes; execute-o no computador de destino.'); });
  var t = target();
  if (t) t.addEventListener('change', function () { state.gestor = null; state.loaded = false; });

  /* Botões do painel (mosaicos) que também pedem atualização. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-arc-command="gestor_update"]') : null;
    if (!b || b.disabled || e.defaultPrevented) return;
    if (!gestorId()) return;
    e.preventDefault(); e.stopImmediatePropagation();
    if (b.dataset.updateForce === '1') open(); else queue('normal');
  }, true);

  window.arcGestorUpdate = { open: open, refresh: refresh, queue: queue };
  refresh(); // ao abrir a página: só indicadores (ícone/mosaicos)
})();
