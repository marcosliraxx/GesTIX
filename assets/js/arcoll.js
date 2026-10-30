
/* MÓDULO: site.js */
(() => {
  "use strict";

  const base = document.querySelector('meta[name="arc-base"]')?.content || "";
  const appAction = document.getElementById("mainAction");
  const actionLabel = document.getElementById("mainActionLabel");
  const installPath = appAction?.dataset.appUrl || new URL("pages/painel.php", document.baseURI).href;

  // App instalado: abre direto o sistema, sem mostrar o portal.
  if (appAction && (window.matchMedia?.("(display-mode: standalone)").matches || window.navigator.standalone === true)) {
    window.location.replace(installPath);
    return;
  }
  const installNote = document.getElementById("installNote");
  let deferredInstall = null;
  let detectedInstalled = false;

  const isStandalone = () =>
    window.matchMedia?.("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  const currentPlatform = () => {
    const ua = navigator.userAgent || "";
    if (/Android/i.test(ua)) return "android";
    if (/iPhone|iPad|iPod/i.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1)) return "ios";
    if (/Windows/i.test(ua)) return "windows";
    if (/Macintosh|Mac OS/i.test(ua)) return "macos";
    if (/Linux/i.test(ua)) return "linux";
    return "web";
  };

  const platformLabels = {
    windows: "Windows",
    android: "Android",
    ios: "iOS",
    macos: "macOS",
    linux: "Linux",
    web: "Web",
  };

  const updateMainAction = () => {
    if (!appAction || !actionLabel) return;
    const platform = currentPlatform();
    const nativeUrl = (() => {
      try {
        const releases = JSON.parse(appAction.dataset.platforms || "{}");
        return releases[platform] || "";
      } catch {
        return "";
      }
    })();

    if (isStandalone() || detectedInstalled) {
      actionLabel.textContent = "Abrir app";
      appAction.href = installPath;
      appAction.removeAttribute("download");
      appAction.dataset.action = "open";
      return;
    }

    if (deferredInstall) {
      actionLabel.textContent = "Instalar app";
      appAction.href = "#instalar";
      appAction.removeAttribute("download");
      appAction.dataset.action = "install";
      return;
    }

    if (nativeUrl) {
      actionLabel.textContent = `Baixar para ${platformLabels[platform] || "seu dispositivo"}`;
      appAction.href = nativeUrl;
      appAction.setAttribute("download", "");
      appAction.dataset.action = "download";
      return;
    }

    appAction.href = installPath;
    appAction.removeAttribute("download");
    appAction.dataset.action = "open";
    if (platform === "ios") {
      actionLabel.textContent = "Abrir app no Safari";
      if (installNote) installNote.innerHTML = '<span class="shield-icon">◇</span> No iPhone ou iPad, abra no Safari e use Compartilhar → Adicionar à Tela de Início.';
    } else {
      actionLabel.textContent = "Abrir app web";
    }
  };

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredInstall = event;
    updateMainAction();
  });

  window.addEventListener("appinstalled", () => {
    deferredInstall = null;
    detectedInstalled = true;
    updateMainAction();
  });

  appAction?.addEventListener("click", async (event) => {
    if (appAction.dataset.action === "install" && deferredInstall) {
      event.preventDefault();
      const prompt = deferredInstall;
      deferredInstall = null;
      try {
        await prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice?.outcome === "accepted") {
          actionLabel.textContent = "Instalando…";
        } else {
          updateMainAction();
        }
      } catch {
        updateMainAction();
      }
    }
  });

  document.querySelectorAll(".confirm-delete").forEach((form) => {
    form.addEventListener("submit", (event) => {
      if (!window.confirm("Remover esta versão publicada?")) event.preventDefault();
    });
  });

  if ("serviceWorker" in navigator && window.isSecureContext) {
    navigator.serviceWorker.register(new URL("sw.js", document.baseURI).href).catch(() => undefined);
  }

  updateMainAction();
  if (typeof navigator.getInstalledRelatedApps === "function") {
    navigator.getInstalledRelatedApps()
      .then((apps) => {
        if (Array.isArray(apps) && apps.length > 0) {
          detectedInstalled = true;
          updateMainAction();
        }
      })
      .catch(() => undefined);
  }
})();

;

/* MÓDULO: erp-shell.js */
(() => {
  'use strict';
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const userButton = q('[data-erp-user]');
  const userMenu = q('[data-erp-user-menu]');
  const notifyButton = q('[data-arc-notify]');
  const notifyMenu = q('[data-arc-notify-menu]');
  const notifyModal = q('[data-arc-notice-modal]');
  const moreButton = q('[data-erp-more]');
  const drawer = q('[data-erp-drawer]');
  const closeDrawer = q('[data-erp-close-more]');
  const readKey = 'arc_read_notifications_v2';

  const readIds = () => {
    try { return JSON.parse(localStorage.getItem(readKey) || '[]'); }
    catch (_) { return []; }
  };
  const saveRead = id => {
    const ids = readIds();
    if (!ids.includes(String(id))) ids.push(String(id));
    try { localStorage.setItem(readKey, JSON.stringify(ids.slice(-500))); } catch (_) {}
  };
  const closeUser = () => {
    userMenu?.classList.remove('open');
    userButton?.setAttribute('aria-expanded', 'false');
  };
  const closeNotifications = () => {
    notifyMenu?.classList.remove('open');
    notifyButton?.setAttribute('aria-expanded', 'false');
  };
  const closeNoticeModal = () => {
    notifyModal?.classList.remove('open');
    notifyModal?.setAttribute('aria-hidden', 'true');
  };
  const updateNotifications = () => {
    if (!notifyMenu) return;
    const ids = readIds();
    qa('[data-notice-id]', notifyMenu).forEach(item => {
      item.classList.toggle('is-read', ids.includes(String(item.dataset.noticeId)));
    });
    const unread = qa('[data-notice-id]:not(.is-read)', notifyMenu).length;
    const badge = q('[data-arc-notify-count]');
    if (badge) {
      badge.hidden = unread === 0;
      badge.textContent = unread > 99 ? '99+' : String(unread);
      badge.title = unread + (unread === 1 ? ' notificação não aberta' : ' notificações não abertas');
      badge.setAttribute('aria-label', badge.title);
    }
    const list = q('[data-arc-notify-list]', notifyMenu);
    const empty = q('.arc-drop-empty', notifyMenu);
    if (unread === 0 && list && !empty) {
      list.insertAdjacentHTML('beforeend', '<div class="arc-drop-empty">Você não tem notificações não abertas.</div>');
    } else if (unread > 0 && empty) {
      empty.remove();
    }
  };

  updateNotifications();
  userButton?.addEventListener('click', event => {
    event.stopPropagation();
    userMenu?.classList.toggle('open');
    userButton.setAttribute('aria-expanded', userMenu?.classList.contains('open') ? 'true' : 'false');
    closeNotifications();
  });
  notifyButton?.addEventListener('click', event => {
    event.stopPropagation();
    notifyMenu?.classList.toggle('open');
    notifyButton.setAttribute('aria-expanded', notifyMenu?.classList.contains('open') ? 'true' : 'false');
    closeUser();
  });

  qa('[data-notice-id]', notifyMenu || document).forEach(item => {
    item.addEventListener('click', () => {
      saveRead(item.dataset.noticeId);
      updateNotifications();
      if (notifyModal) {
        q('#arc-notice-title', notifyModal).textContent = item.dataset.noticeTitle || 'Notificação';
        q('[data-arc-notice-detail]', notifyModal).textContent = item.dataset.noticeDetail || '';
        q('[data-arc-notice-date]', notifyModal).textContent = item.dataset.noticeDate || '';
        notifyModal.classList.add('open');
        notifyModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('arc-notice-open');
        q('.arc-notice-card', notifyModal)?.focus({preventScroll: true});
      }
      closeNotifications();
    });
  });
  q('[data-arc-notice-close]')?.addEventListener('click', () => {
    closeNoticeModal();
    document.body.classList.remove('arc-notice-open');
  });
  notifyModal?.addEventListener('click', event => {
    if (event.target === notifyModal) {
      closeNoticeModal();
      document.body.classList.remove('arc-notice-open');
    }
  });
  moreButton?.addEventListener('click', () => drawer?.classList.add('open'));
  closeDrawer?.addEventListener('click', () => drawer?.classList.remove('open'));
  drawer?.addEventListener('click', event => { if (event.target === drawer) drawer.classList.remove('open'); });
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-erp-user-wrap]')) closeUser();
    if (!event.target.closest('.arc-notify-wrap')) closeNotifications();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeUser(); closeNotifications(); closeNoticeModal();
      document.body.classList.remove('arc-notice-open');
      drawer?.classList.remove('open');
    }
  });
  qa('[data-erp-drawer] a').forEach(link => link.addEventListener('click', () => drawer?.classList.remove('open')));
})();

;

/* MÓDULO: db-ui.js */
(() => {
  const config = window.ARC_DB_CONFIG;
  if (!config) return;
  const boot = window.ARC_BOOT_STATE || {};
  const pages = Array.isArray(config.pages) ? config.pages : [];
  const fields = boot.fieldValues || {};
  const root = document.documentElement;
  if (config.accent) {
    root.style.setProperty("--accent", config.accent);
    root.style.setProperty("--brand-accent", config.accent);
  }
  const siteName = String((config.site || {}).site_name || "Arcoll Speed Connection");
  document.title = `${siteName} · Network Intelligence`;
  if (config.logoUrl) {
    document.querySelectorAll(".brand-mark, .device-brand img").forEach((image) => {
      image.src = config.logoUrl;
    });
  }
  const textNodes = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const replacements = [];
  while (textNodes.nextNode()) {
    if (textNodes.currentNode.nodeValue.includes("Arcoll Speed Connection")) replacements.push(textNodes.currentNode);
  }
  replacements.forEach((node) => {
    node.nodeValue = node.nodeValue.replaceAll("Arcoll Speed Connection", siteName);
  });

  const navIcons = {
    panel: "panel", devices: "devices", map: "grid", conn: "globe", diag: "stethoscope",
    alerts: "bell", history: "list", reports: "table", nettools: "pulse", tools: "layers",
    settings: "settings", setup: "download",
  };
  const existingScreen = (id) => document.getElementById(id);
  const allNav = (id) => document.querySelectorAll(`[data-s="${CSS.escape(id)}"]`);
  const makeNavButton = (page, target, targetId) => {
    const button = document.createElement("button");
    button.type = "button";
    button.dataset.s = targetId;
    button.dataset.dbPage = page.slug;
    button.className = page.slug === "panel" ? "tab active" : "tab";
    button.textContent = page.title;
    const tabs = document.getElementById("tabs");
    if (tabs) tabs.appendChild(button);
    const sheet = document.getElementById("sheetList");
    if (sheet) {
      const item = document.createElement("button");
      item.type = "button";
      item.className = "sitem";
      item.dataset.s = targetId;
      item.dataset.dbPage = page.slug;
      item.textContent = page.title;
      sheet.appendChild(item);
    }
    button.addEventListener("click", () => window.go && window.go(target));
    const sheetItem = sheet?.lastElementChild;
    if (sheetItem?.dataset.dbPage === page.slug) {
      sheetItem.addEventListener("click", () => window.go && window.go(target));
    }
  };

  function addCopy(screen, page) {
    if (!page.content) return;
    const body = screen.querySelector(".card-body") || screen;
    const copy = document.createElement("p");
    copy.className = "database-copy";
    copy.textContent = page.content;
    body.appendChild(copy);
  }

  function addFields(screen, page) {
    if (!Array.isArray(page.fields) || !page.fields.length) return;
    const active = page.fields.filter((field) => field && field.id && field.enabled !== false);
    if (!active.length) return;
    const section = document.createElement("section");
    section.className = "panel database-fields";
    const title = document.createElement("div");
    title.className = "phead";
    const heading = document.createElement("h2");
    heading.textContent = "Campos da página";
    const badge = document.createElement("span");
    badge.className = "push tiny muted";
    badge.textContent = "valores salvos no banco";
    title.append(heading, badge);
    const body = document.createElement("div");
    body.className = "pbody";
    const form = document.createElement("form");
    form.className = "db-field-form";
    active.forEach((field) => {
      const label = document.createElement("label");
      label.className = "db-field";
      const caption = document.createElement("span");
      caption.textContent = `${field.label || field.key}${field.required ? " *" : ""}`;
      const initial = Object.prototype.hasOwnProperty.call(fields, String(field.id))
        ? fields[String(field.id)]
        : (field.default ?? "");
      let input;
      if (field.type === "textarea") {
        input = document.createElement("textarea");
        input.value = initial == null ? "" : String(initial);
      } else if (field.type === "choice") {
        input = document.createElement("select");
        const blank = document.createElement("option");
        blank.value = "";
        blank.textContent = "Selecione";
        input.appendChild(blank);
        (Array.isArray(field.options) ? field.options : []).forEach((option) => {
          const item = document.createElement("option");
          item.value = String(option);
          item.textContent = String(option);
          input.appendChild(item);
        });
        input.value = initial == null ? "" : String(initial);
      } else {
        input = document.createElement("input");
        input.type = field.type === "toggle" ? "checkbox" : (["number", "email", "url", "date"].includes(field.type) ? field.type : "text");
        if (field.type === "toggle") input.checked = initial === true || initial === 1 || initial === "1" || initial === "true";
        else input.value = initial == null ? "" : String(initial);
      }
      input.name = String(field.id);
      input.dataset.fieldId = String(field.id);
      input.required = Boolean(field.required);
      label.append(caption, input);
      form.appendChild(label);
    });
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn primary";
    submit.textContent = "Salvar campos";
    const feedback = document.createElement("span");
    feedback.className = "db-field-feedback";
    feedback.setAttribute("role", "status");
    form.append(submit, feedback);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      feedback.textContent = "Salvando…";
      const values = {};
      form.querySelectorAll("[data-field-id]").forEach((input) => {
        values[input.dataset.fieldId] = input.type === "checkbox" ? input.checked : input.value;
      });
      try {
        const response = await fetch(`${config.apiUrl}?action=save-fields`, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": config.csrf },
          body: JSON.stringify({ values, networkId: Number(config.networkId || 0) }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || "Não foi possível salvar.");
        Object.assign(fields, values);
        feedback.textContent = "Salvo no banco.";
      } catch (error) {
        feedback.textContent = error instanceof Error ? error.message : "Falha ao salvar.";
      }
    });
    body.appendChild(form);
    section.append(title, body);
    const headingNode = screen.querySelector(".card-header");
    if (headingNode) headingNode.insertAdjacentElement("afterend", section);
    else screen.appendChild(section);
  }

  pages.forEach((page) => {
    if (!page || !page.enabled) {
      if (page?.screenId) {
        const screen = existingScreen(page.screenId);
        if (screen) screen.hidden = true;
        allNav(page.screenId).forEach((button) => button.remove());
      }
      return;
    }
    if (!page.enabled) return;
    if (page.type === "content") {
      const screenId = `db-page-${Number(page.id)}`;
      const screen = document.createElement("section");
      screen.className = "screen";
      screen.id = screenId;
      screen.dataset.dbPage = page.slug;
      const head = document.createElement("div");
      head.className = "shead";
      const copy = document.createElement("div");
      const title = document.createElement("h1");
      title.textContent = page.title || page.slug;
      const subtitle = document.createElement("p");
      subtitle.textContent = page.subtitle || "";
      copy.append(title, subtitle);
      head.appendChild(copy);
      screen.appendChild(head);
      if (page.content) {
        const text = document.createElement("div");
        text.className = "panel database-copy db-custom-content";
        text.textContent = page.content;
        screen.appendChild(text);
      }
      document.querySelector(".workspace")?.appendChild(screen);
      makeNavButton(page, screenId, screenId);
      addFields(screen, page);
      return;
    }
    const screenId = String(page.screenId || page.slug);
    const screen = existingScreen(screenId);
    if (!screen) return;
    screen.hidden = false;
    screen.dataset.dbPage = page.slug;
    const title = screen.querySelector(".shead h1");
    const subtitle = screen.querySelector(".shead p");
    if (title && page.title) title.textContent = page.title;
    if (subtitle && page.subtitle) subtitle.textContent = page.subtitle;
    addCopy(screen, page);
    addFields(screen, page);
    if (!document.querySelector(`#tabs [data-s="${CSS.escape(screenId)}"]`)) {
      makeNavButton(page, screenId, screenId);
    } else {
      document.querySelectorAll(`#tabs [data-s="${CSS.escape(screenId)}"], #sheetList [data-s="${CSS.escape(screenId)}"]`).forEach((button) => {
        button.setAttribute("aria-label", page.title || "");
        if (button.classList.contains("tab") || button.classList.contains("sitem")) {
          const icon = button.querySelector("svg");
          button.textContent = "";
          if (icon) button.appendChild(icon);
          button.append(document.createTextNode(page.title || page.slug));
        }
      });
    }
  });

  const style = document.createElement("style");
  style.textContent = `
    .database-copy{white-space:pre-line;line-height:1.65;color:var(--muted);padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--panel);margin:8px 0}
    .db-custom-content{padding:18px;margin:6px 0}
    .database-fields{flex-shrink:0}
    .db-field-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
    .db-field{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:650}
    .db-field input,.db-field select,.db-field textarea{width:100%;min-height:40px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--bg-soft);color:var(--text)}
    .db-field input[type=checkbox]{width:20px;min-height:20px}
    .db-field-form>button{align-self:end}
    .db-field-feedback{align-self:center;color:var(--muted);font-size:12px}
  `;
  document.head.appendChild(style);
})();

;

/* MÓDULO: v18-admin.js */
(function(){
"use strict";
const btn=document.getElementById("adm-mobile-toggle");
const nav=document.getElementById("adm-sidebar");
const backdrop=document.getElementById("adm-mobile-backdrop");
if(!btn||!nav||!backdrop)return;
let previous=null;
function setOpen(open){
 document.body.classList.toggle("adm-menu-open",open);
 btn.setAttribute("aria-expanded",String(open));
 backdrop.hidden=!open;
 if(open){previous=document.activeElement;const link=nav.querySelector("a");if(link)link.focus({preventScroll:true});}
 else if(previous&&typeof previous.focus==="function"){previous.focus({preventScroll:true});}
}
btn.addEventListener("click",()=>setOpen(!document.body.classList.contains("adm-menu-open")));
backdrop.addEventListener("click",()=>setOpen(false));
document.addEventListener("keydown",event=>{
 if(event.key==="Escape"&&document.body.classList.contains("adm-menu-open"))setOpen(false);
 if(event.key!=="Tab"||!document.body.classList.contains("adm-menu-open"))return;
 const focusable=Array.from(nav.querySelectorAll('a,button,input,select,[tabindex]:not([tabindex="-1"])')).filter(x=>!x.disabled&&x.getClientRects().length);
 if(!focusable.length)return;
 const first=focusable[0],last=focusable[focusable.length-1];
 if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
 else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
});
window.addEventListener("resize",()=>{if(window.innerWidth>800&&document.body.classList.contains("adm-menu-open"))setOpen(false);});
})();
;

/* Ícones da navegação: Font Awesome, com compatibilidade com registros anteriores. */
(()=>{'use strict';
const legacy={'layout-dashboard':'fa-dashboard','network':'fa-sitemap','activity':'fa-line-chart','monitor':'fa-desktop','cpu':'fa-microchip','settings-2':'fa-sliders','users':'fa-users','shield-check':'fa-shield','list-tree':'fa-list-ul','palette':'fa-paint-brush','file-text':'fa-file-text-o','form-input':'fa-list-alt','package':'fa-cube','database':'fa-database','lock':'fa-lock','sliders-horizontal':'fa-sliders','house':'fa-home','circle-user':'fa-user-circle','gauge':'fa-tachometer','wifi':'fa-wifi','wrench':'fa-wrench','stethoscope':'fa-stethoscope','bell':'fa-bell','chart-no-axes-column':'fa-bar-chart','download':'fa-download','clipboard-list':'fa-clipboard','circle-help':'fa-question-circle','waypoints':'fa-share-alt','settings':'fa-cog','shield':'fa-shield','sliders':'fa-sliders','shield-user':'fa-user-secret'};
function render(){document.querySelectorAll('.arc-db-icon[data-icon]').forEach(el=>{const key=el.dataset.icon||'fa-circle';const cls=key.startsWith('fa-')?key:(legacy[key]||'fa-circle-o');const i=document.createElement('i');i.className='fa '+cls;i.setAttribute('aria-hidden','true');el.replaceChildren(i);});}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render);else render();
})();


/* Controles compartilhados: navegação hierárquica, ícones e formulários modais. */
document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  document.querySelectorAll('[data-nav-parent]').forEach(button => {
    const panel = document.getElementById(button.dataset.navParent);
    if (!panel) return;
    button.addEventListener('click', () => {
      if (document.body.classList.contains('sidebar-collapsed')) {
        document.body.classList.remove('sidebar-collapsed');
        try { localStorage.setItem('arc_sidebar', 'expanded'); } catch (_) {}
      }
      const expanded = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', String(expanded));
      button.classList.toggle('active', expanded);
      panel.hidden = !expanded;
    });
  });

  const icons = document.getElementById('arc-icons-dialog');
  if (icons && typeof icons.showModal === 'function') {
    const field = document.getElementById('menu-icon');
    const search = document.getElementById('arc-icon-search');
    document.getElementById('arc-open-icons')?.addEventListener('click', () => {
      icons.showModal();
      if (search) { search.value = ''; search.dispatchEvent(new Event('input')); search.focus(); }
    });
    document.getElementById('arc-close-icons')?.addEventListener('click', () => icons.close());
    icons.addEventListener('click', event => {
      if (event.target === icons) { icons.close(); return; }
      const choice = event.target.closest('.arc-icon-choice[data-icon]');
      if (choice && field) { field.value = choice.dataset.icon; icons.close(); }
    });
    search?.addEventListener('input', () => {
      const q = search.value.trim().toLocaleLowerCase('pt-BR');
      icons.querySelectorAll('.arc-icon-choice').forEach(choice => {
        choice.hidden = !choice.dataset.icon.toLocaleLowerCase('pt-BR').includes(q);
      });
    });
  }

  // Progressive enhancement: forms retain their original action, CSRF and handlers.
  document.querySelectorAll('form[data-modal-form]').forEach((form, index) => {
    if (form.closest('dialog') || typeof HTMLDialogElement === 'undefined') return;
    const originalParent = form.parentElement;
    if (!originalParent) return;
    const label = form.dataset.modalForm || 'Editar informações';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'button primary form-modal-trigger';
    trigger.innerHTML = '<i class="fa fa-pencil-square-o" aria-hidden="true"></i> <span></span>';
    trigger.querySelector('span').textContent = label;
    const modal = document.createElement('dialog');
    modal.className = 'form-dialog standard-form-dialog';
    modal.setAttribute('aria-label', label);
    const heading = document.createElement('div');
    heading.className = 'dialog-header';
    const title = document.createElement('h2');
    title.textContent = label;
    const close = document.createElement('button');
    close.type = 'button'; close.className = 'button ghost';
    close.setAttribute('aria-label', 'Fechar');
    close.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
    close.addEventListener('click', () => modal.close());
    heading.append(title, close);
    const content = document.createElement('div');
    content.className = 'dialog-body';
    originalParent.insertBefore(trigger, form);
    content.appendChild(form);
    modal.append(heading, content);
    document.body.appendChild(modal);
    trigger.addEventListener('click', () => modal.showModal());
    modal.addEventListener('click', event => { if (event.target === modal) modal.close(); });
    // Existing edit controls may populate this form; open its modal after those handlers run.
    if (form.id === 'arc-admin-form') {
      document.querySelectorAll('.arc-edit').forEach(b => b.addEventListener('click', () => {
        if (!modal.open) modal.showModal();
      }));
    }
  });
});
