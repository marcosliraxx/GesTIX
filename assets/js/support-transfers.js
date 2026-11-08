/* Caminho: assets/js/support-transfers.js */
/* Envio direto: o arquivo sai do navegador imediatamente. Sem fila local, sem IndexedDB e sem service worker. */
(() => {
  'use strict';
  const CHUNK = 262144, LIMIT = 8388608, MAX_FILES = 300, RETRIES = 4;
  const hex = bytes => [...new Uint8Array(bytes)].map(n => n.toString(16).padStart(2, '0')).join('');
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

  async function sha256(file) {
    if (!window.crypto?.subtle) throw Error('O envio exige uma conexão HTTPS segura.');
    return hex(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()));
  }

  /* Uma chamada ao servidor; erros de rede e 5xx são repetidos, regras de negócio não. */
  async function call(config, action, body, extra, signal) {
    const url = new URL('api/support.php', new URL(config.base, location.href));
    url.search = new URLSearchParams({action, user: config.user, network: config.network, gestor: config.gestor, token: config.token, ...extra});
    for (let attempt = 0; ; attempt++) {
      try {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal,
          headers: {'X-CSRF-Token': config.csrf, 'Content-Type': body instanceof Blob ? 'application/octet-stream' : 'application/json'},
          body: body instanceof Blob ? body : JSON.stringify(body || {})});
        const result = await response.json().catch(() => null);
        if (response.ok && result && result.ok) return result;
        const error = Error(result?.error || 'Resposta inválida da hospedagem.');
        error.retry = !result || response.status >= 500 || response.status === 429;
        throw error;
      } catch (error) {
        if (error.name === 'AbortError') throw error;
        const transient = error.retry !== undefined ? error.retry : true;
        if (!transient || attempt >= RETRIES) throw error;
        await sleep(400 * (attempt + 1));
      }
    }
  }

  /* Envia arquivos (ou uma pasta) e devolve o ID da transferência criada no servidor. */
  async function send(config, files, folder, options = {}) {
    const {signal, onProgress} = options;
    files = [...files];
    const total = files.reduce((n, f) => n + f.size, 0);
    if (!files.length || files.length > MAX_FILES || total > LIMIT) throw Error('Selecione até 300 arquivos e 8 MB por envio.');
    const root = folder ? files[0].webkitRelativePath.split('/')[0] : '';
    if (folder && (!root || files.some(f => f.webkitRelativePath.split('/')[0] !== root))) throw Error('Selecione uma única pasta.');
    const manifest = [];
    for (const file of files) manifest.push({name: folder ? file.webkitRelativePath : file.name, size: file.size, sha256: await sha256(file)});
    if (JSON.stringify(manifest).length > 120000) throw Error('Os caminhos da pasta ultrapassam o limite permitido.');
    const job = {...config, token: hex(crypto.getRandomValues(new Uint8Array(16)))};
    try {
      const begin = await call(job, 'file-upload-begin', {files: manifest, folder: !!folder, destination: config.destination}, {}, signal);
      if (begin.completed) return begin.id;
      let loaded = begin.offsets.reduce((n, offset) => n + offset, 0);
      if (onProgress) onProgress(loaded, total);
      for (let i = 0; i < files.length; i++) {
        let offset = begin.offsets[i];
        while (offset < files[i].size) {
          const result = await call(job, 'file-upload-chunk', files[i].slice(offset, offset + CHUNK), {index: i, offset}, signal);
          if (!Number.isInteger(result.offset) || result.offset < 0 || result.offset > files[i].size) throw Error('Bloco não confirmado pelo servidor.');
          loaded += result.offset - offset; offset = result.offset;
          if (onProgress) onProgress(Math.min(loaded, total), total);
        }
      }
      return (await call(job, 'file-upload-finish', {}, {}, signal)).id;
    } catch (error) {
      if (error.name === 'AbortError') call(job, 'file-upload-cancel', {}, {}).catch(() => {});
      throw error;
    }
  }

  /* Remove a antiga fila persistente que mantinha envios parados em "Na fila". */
  function purgeLegacyQueue() {
    try { indexedDB.deleteDatabase('arcoll-support-transfers'); } catch { /* navegador sem IndexedDB */ }
  }

  globalThis.ArcDirectUpload = {send, purgeLegacyQueue};
})();
