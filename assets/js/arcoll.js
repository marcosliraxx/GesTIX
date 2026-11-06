/* ARQUIVO: /assets/js/arcoll.js */

(() => {
'use strict';
const root=document.documentElement,media=matchMedia('(prefers-color-scheme: dark)'),allowed=['auto','light','dark'];
const systemTheme=root.dataset.arcSystemTheme||'';
let choice=systemTheme||root.dataset.arcTheme||'auto';if(!systemTheme)try{choice=localStorage.getItem('arc_appearance')||choice}catch{}
function apply(value,persist=false){if(systemTheme)value=systemTheme;choice=allowed.includes(value)?value:'auto';root.dataset.arcTheme=choice;root.dataset.theme=choice==='auto'?(media.matches?'dark':'light'):choice;root.style.colorScheme=root.dataset.theme;if(persist&&!systemTheme)try{localStorage.setItem('arc_appearance',choice)}catch{};document.querySelectorAll('[data-arc-appearance]').forEach(select=>select.value=choice);window.dispatchEvent(new CustomEvent('arc:theme-change',{detail:{choice,theme:root.dataset.theme}}));window.dispatchEvent(new Event('resize'));}
window.arcTheme={set:value=>apply(value,true),get:()=>choice,color:name=>getComputedStyle(root).getPropertyValue('--'+name).trim()};
apply(choice);media.addEventListener('change',()=>{if(choice==='auto')apply(choice)});window.addEventListener('storage',e=>{if(!systemTheme&&e.key==='arc_appearance')apply(e.newValue||'auto')});document.querySelectorAll('[data-arc-appearance]').forEach(select=>select.addEventListener('change',e=>apply(e.target.value,true)));
})();


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

  /* Mantém a seção selecionada visível na faixa horizontal, inclusive pelo teclado. */
  var menu = page.querySelector('.arc-redes-menu');
  if (menu) {
    var active = menu.querySelector('[aria-current="page"]');
    if (active) menu.scrollLeft = Math.max(0, active.offsetLeft - (menu.clientWidth - active.offsetWidth) / 2);
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
  // Em Gestão de Redes, as ações operacionais ficam restritas às telas próprias da rede.
  if (!document.querySelector('.arc-redes-page')) {
  var fab = document.createElement('button');
  fab.type = 'button'; fab.className = 'arc-fab'; fab.title = 'Ações rápidas (Ctrl+K)'; fab.setAttribute('aria-label', 'Abrir ações rápidas');
  fab.innerHTML = '<i class="fa fa-bolt" aria-hidden="true"></i>';
  fab.addEventListener('click', open);
  document.body.appendChild(fab);
  }
  window.arcOpenPalette = open;
})();

(function(){
 'use strict';
 const labels={online:'Todos os agentes online',partial:'Atenção: alguns agentes offline',offline:'Alerta: todos os agentes offline',none:'Nenhum agente instalado'};
 const source=new URL('api/api.php?action=network-agent-states',document.baseURI);
 let inFlight=false, states={};
 function update(){
   document.querySelectorAll('[data-panel-network], [data-network-status-id]').forEach(el=>{
     const id=el.getAttribute('data-panel-network')||el.getAttribute('data-network-status-id');
     const s=states[id];if(!s)return;
     el.dataset.agentState=s.state;el.dataset.online=el.hasAttribute('data-panel-network')?(el.dataset.active==='1'&&Number(s.online)>0?'1':'0'):(s.state==='online'?'1':'0');
     const description=labels[s.state]+' ('+s.online+'/'+s.total+' online)';
     el.title=(el.getAttribute('data-name')||'Rede')+' — '+description;
     if(el.hasAttribute('data-panel-network')){el.setAttribute('aria-label',el.title);const label=el.querySelector('.arc-panel-network-state');if(label)label.textContent=el.dataset.active!=='1'?'Rede arquivada':({online:'Todos os agentes online',partial:'Parte dos agentes offline',offline:'Agentes offline',none:'Sem agentes'}[s.state]||description);}
   });
   const sync=document.getElementById('arc-panel-sync');if(sync){sync.classList.remove('is-stale');sync.replaceChildren(document.createElement('span'),document.createTextNode(' Atualizado agora'));}
   window.dispatchEvent(new Event('arc:network-status'));
   const selected=document.getElementById('arc-network-global-state');
   if(selected){const id=selected.dataset.networkStatusId, s=states[id];if(s){selected.dataset.agentState=s.state;selected.title=labels[s.state]+' — '+s.online+' de '+s.total+' agentes online';selected.setAttribute('aria-label',selected.title);/* Status exibido apenas pelo ícone; totais no título acessível. */}}
 }
 async function refresh(){
   if(document.hidden||inFlight)return;
   inFlight=true;
   try{const r=await fetch(source.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!r.ok||r.redirected||!(r.headers.get('content-type')||'').includes('application/json'))return;
     const d=await r.json();if(!d.ok||!d.networks)return;states=d.networks;update();
   }catch(_){/* Falha de conexão: não mostrar estado falso. */}
   finally{inFlight=false;}
 }
 window.arcNetworkAgentStates={refresh:refresh,get:function(id){return states[String(id)]||null;}};
 const alertButton=document.getElementById('arc-network-global-overview');
 const alertOverlay=document.getElementById('arc-network-alert-dialog');
 const alertList=document.getElementById('arc-network-alert-list');
 const alertSummary=document.getElementById('arc-network-alert-summary');
 let overview=null,lastFocus=null;
 const overviewURL=new URL('pages/painel.php?ajax=offline_overview',document.baseURI);
 function elapsed(ts,now){
   if(!ts)return 'Sem comunicação registrada';
   const seconds=Math.max(0,now-ts);
   if(seconds<60)return 'Última comunicação há '+seconds+' s';
   const minutes=Math.floor(seconds/60);if(minutes<60)return 'Última comunicação há '+minutes+' min';
   const hours=Math.floor(minutes/60);if(hours<24)return 'Última comunicação há '+hours+' h e '+(minutes%60)+' min';
   const days=Math.floor(hours/24);return 'Última comunicação há '+days+' dia(s) e '+(hours%24)+' h';
 }
 function renderOverview(){
   if(!alertButton||!overview)return;
   const {total,offline,now}=overview;
   const fraction=total?offline.length/total:0;
   const health=total===0?'none':fraction>0.30?'critical':offline.length?'warning':'healthy';
   alertButton.dataset.health=health;
   alertButton.dataset.agentState=health==='healthy'?'online':health==='warning'?'partial':health==='critical'?'offline':'none';
   alertButton.title=total===0?'Nenhuma rede cadastrada':(offline.length+' de '+total+' redes offline');
   alertButton.setAttribute('aria-label',alertButton.title+'. Clique para detalhes.');
   if(!alertOverlay||alertOverlay.hidden)return;
   alertSummary.textContent=total===0?'Nenhuma rede cadastrada.':offline.length+' rede(s) offline de '+total+' cadastrada(s).';
   alertList.replaceChildren();
   if(!offline.length){const p=document.createElement('p');p.textContent='Nenhuma rede offline no momento.';alertList.appendChild(p);return;}
   offline.forEach(n=>{
     const item=document.createElement('div');item.className='arc-network-alert-item';
     const name=document.createElement('strong');name.textContent=n.name;
     const detail=document.createElement('small');detail.textContent=n.inactive?'Rede arquivada/inativa':n.no_agent?'Sem agente aprovado — '+elapsed(n.last_seen,now):elapsed(n.last_seen,now);
     item.append(name,detail);alertList.appendChild(item);
   });
 }
 async function refreshOverview(){
   if(document.hidden)return;
   try{const r=await fetch(overviewURL.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
     if(!r.ok)throw Error('http '+r.status);const json=await r.json();if(!json.ok)throw Error('api');overview=json;renderOverview();
   }catch(_){if(alertButton){alertButton.dataset.health='unknown';alertButton.title='Não foi possível consultar as redes';}if(alertOverlay&&!alertOverlay.hidden)alertSummary.textContent='Não foi possível atualizar o estado das redes.';}
 }
 function closeAlert(){if(!alertOverlay)return;alertOverlay.hidden=true;lastFocus?.focus();}
 if(alertButton&&alertOverlay){
   alertButton.addEventListener('click',()=>{lastFocus=document.activeElement;alertOverlay.hidden=false;alertOverlay.querySelector('[data-network-alert-close]').focus();renderOverview();refreshOverview();});
   alertOverlay.querySelector('[data-network-alert-close]').addEventListener('click',closeAlert);
   alertOverlay.addEventListener('click',e=>{if(e.target===alertOverlay)closeAlert();});
   document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!alertOverlay.hidden)closeAlert();});
   refreshOverview();setInterval(refreshOverview,12000);
 }
 refresh();setInterval(refresh,2000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});
})();

/* Diálogos declarados nos componentes: detalhes sob demanda e retorno de foco. */
(()=>{const openers=new WeakMap();document.addEventListener('click',event=>{const opener=event.target.closest('[data-open-dialog]');if(opener){const dialog=document.getElementById(opener.dataset.openDialog);if(dialog instanceof HTMLDialogElement){openers.set(dialog,opener);dialog.showModal();}return;}const closer=event.target.closest('[data-close-dialog]');if(closer)closer.closest('dialog')?.close();});document.querySelectorAll('dialog').forEach(dialog=>{dialog.addEventListener('close',()=>openers.get(dialog)?.focus({preventScroll:true}));dialog.addEventListener('click',event=>{if(event.target!==dialog)return;const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();});});})();

window.arcPlainMessage=(value)=>{const text=String(value??'');if(/SQLSTATE|Traceback|Uncaught|stack trace|Unexpected token|not valid JSON|JSON.parse|[{}].*"(?:error|status|payload)"/i.test(text))return 'Não foi possível concluir. Tente novamente em instantes.';return text.replace(/\bremote_assistance\b/g,'acesso remoto').replace(/\bagent_update\b/g,'atualização do aplicativo').replace(/\bheadless_mode\b/g,'acesso sem confirmação local');};


/* Credenciais: alternância local de visibilidade para campos de senha e segredo. */
(()=>{
 'use strict';
 const selector='input[type="password"]';
 function install(input){
  if(input.dataset.arcRevealInstalled==='1' || input.closest('[data-arc-no-reveal]'))return;
  input.dataset.arcRevealInstalled='1';
  const wrapper=document.createElement('span');wrapper.className='arc-secret-control';
  input.parentNode.insertBefore(wrapper,input);wrapper.appendChild(input);
  const button=document.createElement('button');button.type='button';button.className='arc-secret-eye';
  button.setAttribute('aria-label','Mostrar senha');button.setAttribute('aria-pressed','false');button.title='Mostrar ou ocultar';
  const icon=document.createElement('i');icon.className='fa fa-eye';icon.setAttribute('aria-hidden','true');button.appendChild(icon);
  button.addEventListener('click',()=>{const visible=input.type==='password';input.type=visible?'text':'password';button.setAttribute('aria-label',visible?'Ocultar senha':'Mostrar senha');button.setAttribute('aria-pressed',String(visible));icon.className=visible?'fa fa-eye-slash':'fa fa-eye';});
  wrapper.appendChild(button);
 }
 function update(node=document){if(node.matches?.(selector))install(node);node.querySelectorAll?.(selector).forEach(install);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>update());else update();
 new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(n=>{if(n.nodeType===1)update(n);}))).observe(document.documentElement,{childList:true,subtree:true});
 const css=document.createElement('style');css.textContent='.arc-secret-control{display:inline-flex;position:relative;align-items:center;width:100%;max-width:100%;min-width:0}.arc-secret-control>input{width:100%!important;min-width:0;padding-right:42px!important}.arc-secret-eye{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:inherit;opacity:.82;width:32px;height:32px;display:grid;place-items:center;cursor:pointer;z-index:2}.arc-secret-eye:hover{opacity:1}.arc-secret-eye:focus-visible{outline:2px solid currentColor;outline-offset:0}';document.head.appendChild(css);
})();
