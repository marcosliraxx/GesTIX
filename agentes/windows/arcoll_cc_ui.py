# Caminho: agentes/windows/arcoll_cc_ui.py
"""Telas Programas e Clean do agente Windows, no mesmo estilo/estrutura da plataforma (redes/_clean_ui.php) e do CCleaner.

Sincronia com a plataforma:
  * Execuções feitas aqui são enviadas à plataforma (cc_push_tool / cc_push_clean / cc_push_programs em arcoll_app.py) e
    aparecem na página Clean/Programas sem recarregar.
  * Execuções pedidas pela plataforma chamam CleanerFrame.receive() (via arcoll_app.cc_notify) e esta tela é atualizada.
Os dados vêm de arcoll_clean e arcoll_programs (mesmas funções usadas pelos comandos remotos)."""
import json
import os
import queue
import re
import threading
import time
import tkinter as tk
import tkinter.font as tkfont
from pathlib import Path
from tkinter import ttk, messagebox

F = 'Segoe UI'
P = dict(side='#10151f', main='#1f2533', panel='#2a2f3c', row='#3a3f4b', foot='#313643', act='#28344d', line='#4c515c', blue='#2f6bff',
         blue2='#4f86ff', yel='#ffbf02', ok='#37c871', warn='#ff7a45', txt='#eef1f7', mut='#a7afbf', bad='#ffa3a3', list='#222836', deep='#1b202b')
SETTINGS_FILE = Path(os.environ.get('ProgramData', r'C:\ProgramData')) / 'Arcoll' / 'Clean' / 'ui-settings.json'


# ------------------------------------------------------------------------------------------------ utilidades
def mix(a, b, t):
    a, b = [int(a[i:i + 2], 16) for i in (1, 3, 5)], [int(b[i:i + 2], 16) for i in (1, 3, 5)]
    return '#%02x%02x%02x' % tuple(int(x + (y - x) * t) for x, y in zip(a, b))


def human(n):
    n = float(n or 0)
    for unit, step in (('GB', 1 << 30), ('MB', 1 << 20), ('KB', 1 << 10)):
        if n >= step:
            return ('%.1f %s' % (n / step, unit)).replace('.', ',')
    return '%d B' % n


def load_settings():
    try:
        data = json.loads(SETTINGS_FILE.read_text(encoding='utf-8'))
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def remember_settings(payload):
    """Guarda exclusões/pastas enviadas pela plataforma para que a limpeza local siga as mesmas regras."""
    try:
        keep = {k: payload[k] for k in ('exclusions', 'custom_folders') if isinstance(payload.get(k), list)}
        if keep:
            SETTINGS_FILE.parent.mkdir(parents=True, exist_ok=True)
            SETTINGS_FILE.write_text(json.dumps(dict(load_settings(), **keep)), encoding='utf-8')
    except OSError:
        pass


def rrect(c, x1, y1, x2, y2, r, **kw):
    pts = [x1 + r, y1, x2 - r, y1, x2, y1, x2, y1 + r, x2, y2 - r, x2, y2, x2 - r, y2, x1 + r, y2, x1, y2, x1, y2 - r, x1, y1 + r, x1, y1]
    return c.create_polygon(pts, smooth=True, **kw)


# ------------------------------------------------------------------------------------------------ widgets
class Pill(tk.Canvas):
    """Botão arredondado com transição de cor ao passar o mouse (ghost / blue / yel)."""
    def __init__(self, master, text, command, kind='ghost', small=False, bg=None):
        font = (F, 9 if small else 10, 'bold')
        w, h = tkfont.Font(font=font).measure(text) + (26 if small else 40), 26 if small else 32
        super().__init__(master, width=w, height=h, bg=bg or P['main'], highlightthickness=0, cursor='hand2')
        self.kind, self.command, self.enabled, self.t, self.hover = kind, command, True, 0.0, False
        self.base = {'ghost': (P['main'] if bg is None else bg, '#cfd6e4', P['txt']), 'blue': (P['blue'], P['blue'], '#ffffff'),
                     'yel': (P['yel'], P['yel'], '#1c1700')}[kind]
        self.hov = {'ghost': ('#3a4152', '#ffffff', P['txt']), 'blue': (P['blue2'], P['blue2'], '#ffffff'), 'yel': ('#ffd24a', '#ffd24a', '#1c1700')}[kind]
        self.shape = rrect(self, 2, 2, w - 2, h - 2, h // 2 - 2, fill=self.base[0], outline=self.base[1], width=2)
        self.label = self.create_text(w // 2, h // 2, text=text, font=font, fill=self.base[2])
        self.bind('<Enter>', lambda _e: self._go(True))
        self.bind('<Leave>', lambda _e: self._go(False))
        self.bind('<Button-1>', lambda _e: self.command() if self.enabled else None)

    def _go(self, on):
        self.hover = on
        self._step()

    def _step(self):
        target = 1.0 if self.hover and self.enabled else 0.0
        self.t += (target - self.t) * 0.5
        if abs(target - self.t) < 0.05:
            self.t = target
        self.itemconfigure(self.shape, fill=mix(self.base[0], self.hov[0], self.t), outline=mix(self.base[1], self.hov[1], self.t))
        if self.t != target:
            self.after(25, self._step)

    def set_enabled(self, flag):
        self.enabled = bool(flag)
        self.configure(cursor='hand2' if flag else 'arrow')
        self.itemconfigure(self.label, fill=self.base[2] if flag else mix(self.base[2], P['main'], .6))
        return self


class Ring(tk.Canvas):
    """Anel de progresso como no CCleaner: porcentagem no centro; sem valor, gira."""
    def __init__(self, master, size=130, bg=None):
        super().__init__(master, width=size, height=size, bg=bg or P['main'], highlightthickness=0)
        self.size, self.value, self.angle = size, None, 90
        self.create_oval(6, 6, size - 6, size - 6, outline='#3a4152', width=3)
        self.arc = self.create_arc(6, 6, size - 6, size - 6, start=90, extent=-20, style='arc', outline='#e6ebf7', width=3)
        self.text = self.create_text(size // 2, size // 2, text='', font=(F, 26), fill=P['txt'])
        self._tick()

    def set(self, pct):
        self.value = pct

    def _tick(self):
        try:
            if self.value is None:
                self.angle = (self.angle - 9) % 360
                self.itemconfigure(self.arc, start=self.angle, extent=-90)
                self.itemconfigure(self.text, text='')
            else:
                self.itemconfigure(self.arc, start=90, extent=-3.59 * max(1, min(100, self.value)))
                self.itemconfigure(self.text, text='%d%%' % self.value)
            self.after(33, self._tick)
        except tk.TclError:
            pass


# ------------------------------------------------------------------------------------------------ catálogo local (espelha includes/network-clean.php)
LABELS = {
    'recent_docs': 'Documentos recentes', 'jump_lists': 'Listas de atalhos da barra de tarefas', 'thumbnails': 'Cache de miniaturas e ícones',
    'run_history': 'Histórico do Executar', 'search_history': 'Histórico de pesquisa e caminhos', 'user_assist': 'Programas usados (UserAssist)',
    'recycle_bin': 'Lixeira', 'user_temp': 'Arquivos temporários dos usuários', 'windows_temp': 'Arquivos temporários do Windows',
    'clipboard': 'Área de transferência', 'memory_dumps': 'Despejos de memória', 'chkdsk_fragments': 'Fragmentos do Chkdsk',
    'system_logs': 'Logs do Windows', 'error_reports': 'Relatórios de erro', 'windows_update': 'Cache do Windows Update',
    'delivery_optimization': 'Otimização de entrega', 'defender_history': 'Histórico do Defender', 'gpu_shader_cache': 'Cache de shaders da GPU',
    'dns_cache': 'Cache DNS', 'event_logs': 'Logs de eventos', 'office_recent': 'Documentos recentes do Office', 'office_cache': 'Cache do Office',
    'teams_cache': 'Microsoft Teams: cache', 'discord_cache': 'Discord: cache', 'spotify_cache': 'Spotify: cache', 'vscode_cache': 'VS Code: cache e logs',
    'adobe_cache': 'Adobe: cache de mídia', 'java_cache': 'Java: cache', 'mspaint_recent': 'Paint/WordPad: recentes', 'winrar_history': 'WinRAR: histórico',
    'store_cache': 'Cache da Microsoft Store', 'ai_cache': 'Ferramentas de IA: cache', 'prefetch': 'Prefetch', 'custom_folders': 'Pastas personalizadas',
}
JUNK = [('Windows', ['recent_docs', 'jump_lists', 'thumbnails', 'run_history', 'search_history', 'user_assist']),
        ('Sistema', ['recycle_bin', 'user_temp', 'windows_temp', 'clipboard', 'memory_dumps', 'chkdsk_fragments', 'system_logs', 'error_reports',
                     'windows_update', 'delivery_optimization', 'defender_history', 'gpu_shader_cache', 'dns_cache', 'event_logs']),
        ('Programas', ['office_recent', 'office_cache', 'teams_cache', 'discord_cache', 'spotify_cache', 'vscode_cache', 'adobe_cache', 'java_cache',
                       'mspaint_recent', 'winrar_history']),
        ('Windows Store', ['store_cache']), ('Ferramentas de IA', ['ai_cache']), ('Avançado', ['prefetch', 'custom_folders'])]
BROWSERS = [('Google Chrome', 'chrome'), ('Microsoft Edge', 'edge'), ('Brave', 'brave'), ('Opera', 'opera'), ('Vivaldi', 'vivaldi'), ('Firefox', 'firefox')]
FACETS = [('cache', 'Cache'), ('history', 'Histórico'), ('cookies', 'Cookies'), ('session', 'Sessão'), ('forms', 'Formulários'), ('passwords', 'Senhas salvas')]
REG = [('shared_dlls', 'DLLs compartilhadas ausentes'), ('app_paths', 'Caminhos de aplicativos'), ('startup_missing', 'Inicialização com arquivo ausente'),
       ('fonts', 'Fontes'), ('help_files', 'Arquivos de ajuda'), ('uninstall_orphans', 'Programa obsoleto'), ('com_activex', 'Componentes COM/ActiveX'),
       ('mui_cache', 'Cache da MUI')]
DEFAULT_ON = {'recent_docs', 'run_history', 'search_history', 'thumbnails', 'jump_lists', 'user_temp', 'windows_temp', 'clipboard', 'chkdsk_fragments',
              'store_cache', 'chrome_cache', 'edge_cache', 'brave_cache', 'opera_cache', 'vivaldi_cache', 'firefox_cache'}
HEALTH_SPACE = ['user_temp', 'windows_temp', 'thumbnails', 'system_logs', 'error_reports', 'windows_update', 'gpu_shader_cache', 'chkdsk_fragments']
HEALTH_PRIVACY = ['recent_docs', 'jump_lists', 'run_history', 'search_history', 'clipboard'] + ['%s_%s' % (b, f) for _n, b in BROWSERS for f in ('history', 'cookies')]

NAV_CLEAN = [('health', 'Health Check', '♡'), ('custom', 'Limpeza personalizada', '✦'), ('duplicates', 'Localizador de arquivos duplicados', '⎘'),
             ('cloud', 'Limpador de Disco na Nuvem', '☁'), ('backup', 'Backup em Nuvem', '⇧'), ('perf', 'Otimização de Desempenho', '🚀'), ('startup', 'Gerenciador de inicialização', '🖥'),
             ('updater', 'Atualizador de Software', '⟳'), ('windows_update', 'Windows Update', '↻'), ('drivers', 'Driver Updater', '⚙')]
NAV_PROGRAMS = [('uninstall', 'Desinstalador', '🗑')]


class CleanerFrame(tk.Frame):
    """Uma tela completa (menu lateral + conteúdo). `sections` define Programas (desinstalador) ou Clean (demais)."""

    def __init__(self, master, app, sections, start):
        super().__init__(master, bg=P['main'])
        self.app, self.sections, self.section = app, sections, start
        self.rep, self.stamp, self.sel, self.tab = {}, {}, {}, {'custom': 'lixo', 'perf': 'active', 'startup': 'apps', 'drivers': 'out'}
        self.busy, self.pct, self.msg, self.cancelled, self.q = False, None, '', False, queue.Queue()
        self.cancel_event = threading.Event()
        self.checked = set(DEFAULT_ON)
        self.programs, self.prog_stamp, self.mini, self.ring = None, None, False, None
        self.dup = {'stage': 'intro', 'drives': {'users'}}
        self._style()
        self.side = tk.Frame(self, bg=P['side'], width=252)
        self.side.pack(side='left', fill='y')
        self.side.pack_propagate(False)
        self.content = tk.Frame(self, bg=P['main'])
        self.content.pack(side='left', fill='both', expand=True)
        self.statusbar = tk.Label(self.content, text='', anchor='w', bg='#1b212d', fg=P['mut'], font=(F, 9), padx=16, pady=6)
        self.statusbar.pack(side='bottom', fill='x')
        self.view = tk.Frame(self.content, bg=P['main'])
        self.view.pack(fill='both', expand=True)
        self._nav()
        self.show()
        self.after(100, self._pump)
        # Barra compacta inicial deixa espaço para conteúdo em notebooks pequenos.
        self.mini = True
        self.side.configure(width=56)
        for _row, _ic, text in self.rows.values():
            text.pack_forget()

    # ---------------------------------------------------------------- base
    def _style(self):
        st = ttk.Style(self)
        st.configure('CC.Treeview', background=P['main'], fieldbackground=P['main'], foreground=P['txt'], rowheight=30, borderwidth=0, font=(F, 9))
        st.configure('CC.Treeview.Heading', background=P['main'], foreground=P['mut'], font=(F, 9), padding=6, borderwidth=0, relief='flat')
        st.map('CC.Treeview', background=[('selected', P['act'])], foreground=[('selected', '#ffffff')])
        st.map('CC.Treeview.Heading', background=[('active', P['main'])], foreground=[('active', '#ffffff')])
        st.configure('CC.Vertical.TScrollbar', background=P['row'], troughcolor=P['main'], bordercolor=P['main'], arrowcolor=P['mut'], relief='flat')

    def _pump(self):
        try:
            while True:
                self.q.get_nowait()()
        except queue.Empty:
            pass
        try:
            self.after(100, self._pump)
        except tk.TclError:
            pass

    def post(self, fn):
        self.q.put(fn)

    def status(self, text, err=False):
        self.statusbar.configure(text=text, fg=P['bad'] if err else P['mut'])

    def _nav(self):
        for w in self.side.winfo_children():
            w.destroy()
        top = tk.Label(self.side, text='☰', bg=P['side'], fg=P['mut'], font=(F, 15), cursor='hand2', anchor='w', padx=14, pady=8)
        top.pack(fill='x')
        top.bind('<Button-1>', lambda _e: self._collapse())
        self.rows = {}
        for key, label, icon in self.sections_nav():
            row = tk.Frame(self.side, bg=P['side'], cursor='hand2')
            row.pack(fill='x', padx=8, pady=1)
            ic = tk.Label(row, text=icon, bg=P['side'], fg='#b9c3d6', font=('Segoe UI Emoji', 12), width=2)
            ic.pack(side='left', padx=(6, 6), pady=8)
            tx = tk.Label(row, text=label, bg=P['side'], fg='#d3d9e6', font=(F, 9, 'bold'), anchor='w')
            tx.pack(side='left', fill='x', expand=True)
            self.rows[key] = (row, ic, tx)
            for widget in (row, ic, tx):
                widget.arc_tooltip = label
            for w in (row, ic, tx):
                w.bind('<Button-1>', lambda _e, k=key: self.go(k))
                w.bind('<Enter>', lambda _e, k=key: self._paint(k, True))
                w.bind('<Leave>', lambda _e, k=key: self._paint(k, False))
        self.agent = tk.Label(self.side, text='● Agente conectado', bg='#171e2b', fg=P['ok'], font=(F, 9), anchor='w', padx=12, pady=6)
        self.agent.pack(side='bottom', fill='x', padx=8, pady=10)
        self._paint_all()

    def sections_nav(self):
        pool = NAV_CLEAN + NAV_PROGRAMS
        return [n for n in pool if n[0] in self.sections]

    def _paint(self, key, hover):
        row, ic, tx = self.rows[key]
        on = key == self.section
        color = P['act'] if on else '#192030' if hover else P['side']
        for w in (row, ic, tx):
            w.configure(bg=color)
        ic.configure(fg='#7ea6ff' if on else '#b9c3d6')
        tx.configure(fg='#ffffff' if on else '#d3d9e6')

    def _paint_all(self):
        for k in self.rows:
            self._paint(k, False)

    def _collapse(self):
        self.mini = not self.mini
        goal = 56 if self.mini else 252

        def step():
            cur = int(self.side.cget('width'))
            nxt = cur + (goal - cur) // 3 or (1 if goal > cur else -1)
            if abs(goal - cur) <= 2:
                nxt = goal
            self.side.configure(width=nxt)
            if nxt != goal:
                self.after(16, step)
            else:
                for _row, _ic, tx in self.rows.values():
                    tx.pack_forget() if self.mini else tx.pack(side='left', fill='x', expand=True)
        step()

    def go(self, key):
        if self.busy or key == self.section:
            return
        self.section = key
        self._paint_all()
        self.show(animate=True)

    def set_online(self, online):
        self.agent.configure(text='● Agente conectado' if online else '● Sem conexão', fg=P['ok'] if online else P['bad'])

    # ---------------------------------------------------------------- renderização
    def show(self, animate=False):
        for w in self.view.winfo_children():
            w.destroy()
        self.ring = None
        v = self.view
        if self.busy:
            self._busy_view(v)
        else:
            getattr(self, 'page_' + self.section)(v)
        if animate:
            self._wipe()

    def _wipe(self):
        cover = tk.Frame(self.view, bg=P['main'])
        cover.place(x=0, y=0, relwidth=1, relheight=1)

        def step(h=1.0):
            if h <= 0.02 or not cover.winfo_exists():
                cover.destroy()
                return
            cover.place_configure(relheight=h, rely=1 - h)
            self.after(14, lambda: step(h * 0.62))
        step()

    def _busy_view(self, v):
        box = tk.Frame(v, bg=P['main'])
        box.place(relx=.5, rely=.46, anchor='center')
        self.ring = Ring(box)
        self.ring.pack()
        self.ring.set(self.pct)
        self.busy_title = tk.Label(box, text=self.msg, bg=P['main'], fg=P['txt'], font=(F, 14, 'bold'), pady=14)
        self.busy_title.pack()
        self.busy_sub = tk.Label(box, text='', bg=P['main'], fg=P['mut'], font=(F, 9))
        self.busy_sub.pack(pady=(0, 14))
        Pill(box, 'Interromper verificação', self.cancel).pack()

    def cancel(self):
        self.cancel_event.set()
        self.cancelled = True
        self.status('Cancelamento solicitado. A operação será encerrada no próximo ponto seguro.')

    def _set_prog(self, pct, text):
        if not self.busy:
            return
        if pct is not None:
            self.pct = pct
            if self.ring:
                self.ring.set(pct)
        if text:
            self.status(text)
            if getattr(self, 'busy_sub', None) and self.busy_sub.winfo_exists():
                self.busy_sub.configure(text=text[:90])

    def _prog(self, text):
        if self.cancel_event.is_set():
            raise RuntimeError('Execução interrompida pelo operador.')
        m = re.match(r'^\[(\d{1,3})%\]\s*(.*)$', str(text))
        pct, msg = (int(m.group(1)), m.group(2)) if m else (None, str(text))
        self.post(lambda: self._set_prog(pct, msg))

    def run(self, label, work, done=None):
        """Executa `work(progress)` em segundo plano; `done(resultado)` roda na tela."""
        if self.busy:
            return
        self.cancel_event.clear()
        self.busy, self.pct, self.msg, self.cancelled = True, None, label, False
        self.status(label + '...')
        self.show()

        def target():
            try:
                res, err = work(self._prog), None
            except Exception as exc:
                res, err = None, str(exc)[:300] or exc.__class__.__name__
            self.post(lambda: self._finish(res, err, done))
        threading.Thread(target=target, daemon=True).start()

    def _finish(self, res, err, done):
        if self.cancelled:
            self.busy = False
            self.status('Operação cancelada.' if err else 'Cancelamento solicitado após a etapa final.')
            self.show()
            return
        self.busy = False
        if err:
            self.status(err, True)
            self.show()
            messagebox.showerror('Arcoll', err, parent=self.winfo_toplevel())
            return
        if done:
            done(res)
        self.show()

    # ---------------------------------------------------------------- operações
    def tool(self, kind, payload, label, extra=None):
        def work(prog):
            import arcoll_clean
            return arcoll_clean.execute_tool(payload, progress=prog)

        def done(res):
            self.rep[kind] = res
            self.stamp[kind] = time.strftime('%d/%m/%Y %H:%M')
            self.app.cc_push_tool(payload['tool'], res)
            self.status('Concluído · ' + self.stamp[kind] + ' · enviado à plataforma')
            if extra:
                extra(res)
        self.run(label, work, done)

    def clean(self, execute, cats, label, age=7):
        cfg = load_settings()

        def work(prog):
            import arcoll_clean
            return arcoll_clean.execute_clean(cats, execute=execute, age_days=age, progress=prog, exclusions=cfg.get('exclusions'), custom_folders=cfg.get('custom_folders'))

        def done(res):
            self.rep['clean'] = res
            self.stamp['clean'] = time.strftime('%d/%m/%Y %H:%M')
            self.app.cc_push_clean('execute' if execute else 'scan', res)
            self.status(('Limpeza concluída: ' if execute else 'Análise concluída: ') + '%d itens · %s' % (res.get('file_count', 0), human(res.get('bytes', 0))))
        self.run(label, work, done)

    def receive(self, kind, report):
        """Resultado de uma operação pedida pela plataforma: atualiza esta tela sem interromper o que está aberto."""
        key = 'clean' if kind == 'clean' else kind
        self.rep[key] = report
        self.stamp[key] = time.strftime('%d/%m/%Y %H:%M')
        if kind == 'programs':
            self.programs, self.prog_stamp = report, self.stamp[key]
        if not self.busy:
            self.show()
        self.status('Atualizado pela plataforma · ' + self.stamp[key])

    # ---------------------------------------------------------------- blocos de interface
    def _head(self, v, title, right=None):
        bar = tk.Frame(v, bg=P['main'])
        bar.pack(fill='x', padx=18, pady=(12, 6))
        tk.Label(bar, text=title + '  ⓘ', bg=P['main'], fg=P['txt'], font=(F, 10, 'bold')).pack(side='left')
        if right:
            tk.Label(bar, text=right, bg=P['main'], fg=P['mut'], font=(F, 9)).pack(side='right')

    def _tabs(self, v, items, key, cb=None):
        bar = tk.Frame(v, bg=P['main'])
        bar.pack(fill='x', padx=18)
        tk.Frame(v, bg=P['line'], height=1).pack(fill='x', padx=0)
        for k, name in items:
            on = self.tab[key] == k
            b = tk.Label(bar, text=name, bg=P['main'] if on else P['foot'], fg='#fff' if on else '#cfd6e4', font=(F, 9, 'bold'), padx=18, pady=8, cursor='hand2',
                         highlightthickness=1, highlightbackground=P['line'])
            b.pack(side='left', padx=(0, 6))
            b.bind('<Button-1>', lambda _e, kk=k: (self.tab.__setitem__(key, kk), cb() if cb else None, self.show()))

    def _foot(self, v):
        f = tk.Frame(v, bg=P['foot'], highlightthickness=1, highlightbackground=P['line'])
        f.pack(side='bottom', fill='x')
        inner = tk.Frame(f, bg=P['foot'])
        inner.pack(fill='x', padx=18, pady=10)
        return inner

    def _foot_btn(self, foot, text, cmd, kind='ghost', enabled=True):
        b = Pill(foot, text, cmd, kind, bg=P['foot']).set_enabled(enabled)
        b.pack(side='right', padx=(10, 0))
        return b

    def _foot_text(self, foot, text, sub=''):
        box = tk.Frame(foot, bg=P['foot'])
        box.pack(side='left')
        tk.Label(box, text=text, bg=P['foot'], fg=P['txt'], font=(F, 9, 'bold')).pack(anchor='w')
        if sub:
            tk.Label(box, text=sub, bg=P['foot'], fg=P['mut'], font=(F, 8)).pack(anchor='w')

    def _intro(self, v, title, lead, feats, label, cmd, kind='blue', glyph='✦'):
        wrap = tk.Frame(v, bg=P['main'])
        wrap.place(relx=.5, rely=.45, anchor='center')
        left = tk.Frame(wrap, bg=P['main'])
        left.pack(side='left', padx=30)
        tk.Label(left, text=title, bg=P['main'], fg='#fff', font=(F, 14, 'bold'), anchor='w', wraplength=430, justify='left').pack(anchor='w')
        if lead:
            tk.Label(left, text=lead, bg=P['main'], fg=P['mut'], font=(F, 9), anchor='w', wraplength=430, justify='left').pack(anchor='w', pady=(4, 8))
        for t, s in feats:
            tk.Label(left, text=t, bg=P['main'], fg='#fff', font=(F, 9, 'bold'), anchor='w').pack(anchor='w', pady=(10, 0))
            tk.Label(left, text=s, bg=P['main'], fg=P['mut'], font=(F, 9), anchor='w', wraplength=430, justify='left').pack(anchor='w')
        Pill(left, label, cmd, kind).pack(anchor='w', pady=(18, 0))
        art = tk.Canvas(wrap, width=250, height=190, bg=P['main'], highlightthickness=0)
        art.pack(side='left', padx=20)
        self._art(art, glyph)

    def _art(self, c, glyph, y=0):
        """Ilustração simples (notebook com ícone) que flutua suavemente."""
        c.delete('all')
        c.create_oval(30, 170 + y, 220, 186 + y, fill='#171c28', outline='')
        c.create_oval(205, 40 + y, 225, 60 + y, fill='#3f78ff', outline='')
        rrect(c, 52, 34 + y, 198, 128 + y, 9, fill='#9ebfff', outline='')
        rrect(c, 60, 42 + y, 190, 120 + y, 5, fill='#2c4fa6', outline='')
        c.create_polygon(30, 134 + y, 220, 134 + y, 206, 152 + y, 44, 152 + y, fill='#d6e4ff', outline='')
        c.create_text(125, 82 + y, text=glyph, fill='#fff', font=('Segoe UI Symbol', 34))
        try:
            import math
            n = time.time() * 2
            c.after(60, lambda: self._art(c, glyph, int(math.sin(n) * 4)) if c.winfo_exists() else None)
        except tk.TclError:
            pass

    def table(self, parent, cols, rows, checks=False, widths=None, on_click=None):
        """Tabela de dados: cols=[(id, título, largura, âncora)], rows=[(iid, valores...)]. Marcação ☐/☑ na 1ª coluna quando `checks`."""
        frame = tk.Frame(parent, bg=P['main'])
        ids = (['chk'] if checks else []) + [c[0] for c in cols]
        tv = ttk.Treeview(frame, columns=ids, show='headings', style='CC.Treeview', selectmode='extended')
        sb = ttk.Scrollbar(frame, orient='vertical', command=tv.yview, style='CC.Vertical.TScrollbar')
        tv.configure(yscrollcommand=sb.set)
        if checks:
            tv.heading('chk', text='☐', command=lambda: self._toggle_all(tv))
            tv.column('chk', width=34, stretch=False, anchor='center')
        for cid, title, width, anchor in cols:
            tv.heading(cid, text=title, command=lambda c=cid: self._sort(tv, c))
            tv.column(cid, width=width, anchor=anchor, stretch=width >= 200)
        tv.tag_configure('odd', background='#232a38')
        tv._checked, tv._cols = set(), ids
        for n, row in enumerate(rows):
            tv.insert('', 'end', iid=str(row[0]), values=(['☐'] if checks else []) + [str(x) for x in row[1:]], tags=('odd',) if n % 2 else ())
        if checks:
            tv.bind('<Button-1>', lambda e: self._click(tv, e, on_click), add='+')
        elif on_click:
            tv.bind('<Button-1>', lambda e: on_click(tv, e), add='+')
        sb.pack(side='right', fill='y')
        tv.pack(side='left', fill='both', expand=True)
        return frame, tv

    def _click(self, tv, e, extra):
        if tv.identify('region', e.x, e.y) == 'cell' and tv.identify_column(e.x) == '#1':
            iid = tv.identify_row(e.y)
            if iid:
                self._flip(tv, iid)
                if extra:
                    extra(tv, e)
                return 'break'
        if extra:
            extra(tv, e)

    def _flip(self, tv, iid):
        tv._checked.symmetric_difference_update({iid})
        tv.set(iid, 'chk', '☑' if iid in tv._checked else '☐')
        if getattr(tv, '_changed', None):
            tv._changed()

    def _toggle_all(self, tv):
        every = set(tv.get_children())
        tv._checked = set() if tv._checked == every else every
        for iid in every:
            tv.set(iid, 'chk', '☑' if iid in tv._checked else '☐')
        if getattr(tv, '_changed', None):
            tv._changed()

    def _sort(self, tv, col):
        items = [(tv.set(i, col), i) for i in tv.get_children('')]
        num = lambda s: float(re.sub(r'[^\d,.-]', '', s).replace('.', '').replace(',', '.') or 0) if re.search(r'\d', s) else -1
        rev = getattr(tv, '_rev', {}).get(col, False)
        try:
            items.sort(key=lambda x: num(x[0]), reverse=rev) if all(re.fullmatch(r'[\d.,]+( [KMG]?B)?', x[0]) for x in items[:20]) else items.sort(key=lambda x: x[0].casefold(), reverse=rev)
        except ValueError:
            items.sort(key=lambda x: x[0].casefold(), reverse=rev)
        for pos, (_v, iid) in enumerate(items):
            tv.move(iid, '', pos)
        tv._rev = dict(getattr(tv, '_rev', {}), **{col: not rev})

    def _confirm(self, title, text):
        return messagebox.askyesno(title, text, parent=self.winfo_toplevel())

    def _empty(self, v, title, sub=''):
        box = tk.Frame(v, bg=P['main'])
        box.place(relx=.5, rely=.45, anchor='center')
        tk.Label(box, text=title, bg=P['main'], fg='#fff', font=(F, 13, 'bold')).pack()
        if sub:
            tk.Label(box, text=sub, bg=P['main'], fg=P['mut'], font=(F, 9), wraplength=520, justify='center').pack(pady=6)

    def _stamp(self, k):
        return ('Última verificação: ' + self.stamp[k]) if k in self.stamp else ''

    # ================================================================ páginas
    def page_health(self, v):
        r = self.rep.get('health')
        go = lambda: self.tool('health', {'tool': 'health', 'action': 'scan', 'space': self._valid(HEALTH_SPACE), 'privacy': self._valid(HEALTH_PRIVACY)}, 'Coletando e analisando arquivos')
        if not r:
            return self._intro(v, 'Bom retorno ao Health Check', 'Continue escaneando seu PC para aplicar atualizações e mantê-lo funcionando sem problemas.', [], 'Verificar agora', go, glyph='♡')
        self._head(v, 'Health Check', self._stamp('health'))
        cards = tk.Frame(v, bg=P['main'])
        cards.pack(fill='x', padx=18, pady=10)
        info = [('Espaço', human(r['space']['bytes']), '%d arquivos desnecessários' % r['space']['file_count'], r['space']['bytes'] > 1 << 30),
                ('Privacidade', str(r['privacy']['file_count']), 'rastros de navegação e uso', r['privacy']['file_count'] > 0),
                ('Velocidade', str(r['speed']['startup_enabled']), 'itens na inicialização (de %d)' % r['speed']['startup_total'], r['speed']['startup_enabled'] > 15),
                ('Registro', str(r['registry']['issues']), 'problemas encontrados', r['registry']['issues'] > 0)]
        for i, (t, big, sub, warn) in enumerate(info):
            c = tk.Frame(cards, bg=P['panel'], highlightthickness=1, highlightbackground=P['line'], padx=14, pady=12)
            c.grid(row=0, column=i, padx=6, sticky='nsew')
            cards.columnconfigure(i, weight=1)
            tk.Label(c, text=t, bg=P['panel'], fg='#fff', font=(F, 10, 'bold')).pack(anchor='w')
            tk.Label(c, text=big, bg=P['panel'], fg=P['warn'] if warn else '#7fd29c', font=(F, 22)).pack(anchor='w')
            tk.Label(c, text=sub, bg=P['panel'], fg=P['mut'], font=(F, 8), wraplength=190, justify='left').pack(anchor='w')
        f = self._foot(v)
        self._foot_text(f, 'Otimizar executa a limpeza de espaço e de privacidade', 'Registro e inicialização são tratados nas respectivas telas.')
        self._foot_btn(f, 'Otimizar agora', self._optimize, 'blue')
        self._foot_btn(f, 'Escanear novamente', go)

    @staticmethod
    def _valid(keys):
        try:
            import arcoll_clean
            return [k for k in keys if k in arcoll_clean.CATEGORIES]
        except ImportError:
            return keys

    def _optimize(self):
        if not self._confirm('Otimizar agora', 'Serão excluídos permanentemente temporários, caches, logs (com mais de 7 dias) e os rastros de privacidade. Arquivos em uso são ignorados. Continuar?'):
            return
        space, privacy = self._valid(HEALTH_SPACE), self._valid(HEALTH_PRIVACY)

        def work(prog):
            import arcoll_clean
            a = arcoll_clean.execute_clean(space, True, 7, progress=prog)
            b = arcoll_clean.execute_clean(privacy, True, 0, progress=prog)
            return {'clean': b, 'bytes': a['bytes'] + b['bytes'], 'files': a['file_count'] + b['file_count'], 'last': a}

        def done(res):
            self.app.cc_push_clean('execute', res['clean'])
            self.status('Otimização concluída: %d itens · %s liberados' % (res['files'], human(res['bytes'])))
            self.rep.pop('health', None)
        self.run('Otimizando o computador', work, done)

    # ---------------------------------------------------------------- Limpeza personalizada
    def page_custom(self, v):
        self._head(v, 'Limpeza personalizada')
        self._tabs(v, [('lixo', '🗑 Lixo'), ('nav', '🌐 Navegador'), ('reg', '▦ Registro')], 'custom')
        body = tk.Frame(v, bg=P['main'])
        body.pack(fill='both', expand=True)
        left = tk.Frame(body, bg=P['list'], width=300)
        left.pack(side='left', fill='y')
        left.pack_propagate(False)
        right = tk.Frame(body, bg=P['main'])
        right.pack(side='left', fill='both', expand=True)
        tab = self.tab['custom']
        tv = ttk.Treeview(left, show='tree', style='CC.Treeview', selectmode='none')
        tv.pack(fill='both', expand=True)
        tv.tag_configure('grp', background=P['row'], font=(F, 9, 'bold'))
        self.cust_tv = tv
        if tab == 'reg':
            for k, name in REG:
                tv.insert('', 'end', iid='r:' + k, text=('☑ ' if k in self.sel.setdefault('reg', {k for k, _ in REG}) else '☐ ') + name)
        else:
            groups = self._groups(tab)
            for g, keys in groups:
                tv.insert('', 'end', iid='g:' + g, text=self._gmark(keys) + ' ' + g, open=g in getattr(self, '_open', {'Windows'}), tags=('grp',))
                for k, name in keys:
                    tv.insert('g:' + g, 'end', iid='c:' + k, text=('☑ ' if k in self.checked else '☐ ') + name)
        tv.bind('<Button-1>', lambda e: self._cust_click(tv, e, tab))
        self._results(right, tab)
        f = self._foot(v)
        n = len(self.sel.get('reg', set())) + len(self.checked) if tab == 'reg' else len(self.checked)
        self._foot_text(f, '%d selecionado(s)' % n)
        self._foot_btn(f, 'Limpar e corrigir', self._cust_execute, 'blue')
        self._foot_btn(f, 'Verificar agora', self._cust_scan)

    def _groups(self, tab):
        try:
            import arcoll_clean
            known = set(arcoll_clean.CATEGORIES)
        except ImportError:
            known = None
        ok = lambda k: known is None or k in known
        if tab == 'lixo':
            return [(g, [(k, LABELS[k]) for k in keys if ok(k)]) for g, keys in JUNK if any(ok(k) for k in keys)]
        out = []
        for name, b in BROWSERS:
            items = [(b + '_' + f, label) for f, label in FACETS if ok(b + '_' + f)]
            if items:
                out.append((name, items))
        return out

    def _gmark(self, keys):
        n = sum(1 for k, _ in keys if k in self.checked)
        return '☑' if n == len(keys) else '▣' if n else '☐'

    def _cust_click(self, tv, e, tab):
        iid = tv.identify_row(e.y)
        if not iid:
            return
        kind, _, key = iid.partition(':')
        if kind == 'r':
            s = self.sel['reg']
            s.symmetric_difference_update({key})
        elif kind == 'c':
            self.checked.symmetric_difference_update({key})
        elif kind == 'g':
            if e.x < 70:
                keys = [k for g, ks in self._groups(tab) if g == key for k, _ in ks]
                if all(k in self.checked for k in keys):
                    self.checked.difference_update(keys)
                else:
                    self.checked.update(keys)
            else:
                self._open = getattr(self, '_open', {'Windows'})
                self._open ^= {key}
                tv.item(iid, open=key in self._open)
                return
        self.show()

    def _results(self, right, tab):
        rep = self.rep.get('registry') if tab == 'reg' else self.rep.get('clean')
        if tab == 'reg':
            issues = (rep or {}).get('issues')
            if rep is None:
                return self._empty(right, 'Corrigir problemas no registro do Windows', 'Aumente a estabilidade do sistema e reduza os erros. Cada chave é salva em backup .reg antes de qualquer correção.')
            rows = [(i['id'], dict(REG).get(i['check'], i['check']), i.get('detail', ''), '%s\\%s' % (i.get('hive', ''), i.get('key', ''))) for i in issues]
            frame, tv = self.table(right, [('p', 'Problema', 190, 'w'), ('d', 'Dados', 250, 'w'), ('k', 'Chave', 300, 'w')], rows)
            frame.pack(fill='both', expand=True, padx=14, pady=8)
            return
        if not rep or not rep.get('categories'):
            return self._empty(right, 'Limpar arquivos indesejados do sistema' if tab == 'lixo' else 'Limpe seus dados de navegação',
                               'Marque o que deseja limpar e clique em Verificar agora. O resultado detalhado de cada item aparece aqui.')
        tk.Label(right, text='Resultados da verificação (%d itens)' % rep.get('file_count', 0), bg=P['main'], fg='#fff', font=(F, 10, 'bold')).pack(pady=(10, 0))
        rows = []
        for k, c in rep['categories'].items():
            rows.append((k, LABELS.get(k) or k.replace('_', ' '), '%d' % c.get('file_count', 0), human(c.get('bytes', 0))))
        frame, tv = self.table(right, [('n', 'Nome', 260, 'w'), ('i', 'Número de itens', 130, 'e'), ('s', 'Tamanho', 110, 'e')], rows)
        frame.pack(fill='both', expand=True, padx=14, pady=8)

    def _cust_cats(self):
        return [k for k in self.checked if k in self._valid(list(self.checked))]

    def _cust_scan(self):
        cats = self._cust_cats()
        if self.tab['custom'] == 'reg':
            return self.tool('registry', {'tool': 'registry', 'action': 'scan', 'checks': sorted(self.sel['reg'])}, 'Procurando problemas no registro')
        if not cats:
            return self.status('Marque ao menos um item.', True)
        self.clean(False, cats, 'Coletando e analisando arquivos')

    def _cust_execute(self):
        if self.tab['custom'] == 'reg':
            rep = self.rep.get('registry')
            ids = [i['id'] for i in (rep or {}).get('issues', [])]
            if not ids:
                return self.status('Verifique o registro antes de corrigir.', True)
            if self._confirm('Corrigir registro', '%d entradas serão removidas após backup .reg. Continuar?' % len(ids)):
                self.tool('registry', {'tool': 'registry', 'action': 'fix', 'checks': sorted(self.sel['reg']), 'ids': ids[:1500]}, 'Corrigindo registro')
            return
        cats = self._cust_cats()
        if not cats:
            return self.status('Marque ao menos um item.', True)
        if self._confirm('Limpar e corrigir', 'Os itens marcados (%d categorias) serão excluídos permanentemente. Arquivos em uso são ignorados. Continuar?' % len(cats)):
            self.clean(True, cats, 'Limpando')

    # ---------------------------------------------------------------- Desinstalador (aba Programas)
    def page_uninstall(self, v):
        rows = self.programs
        load = lambda: self.load_programs()
        if rows is None:
            return self._intro(v, 'Excluir aplicativos que você não utiliza', 'Escaneie o PC para encontrar apps que você pode remover.',
                               [('Libere espaço de armazenamento', 'Remove apps antigos ou raramente utilizados, um por um ou todos de uma vez.'),
                                ('Tentar desinstalar temporariamente', 'O agente tenta criar um ponto de restauração antes de remover.'),
                                ('Reduza os riscos de segurança', 'Remova os apps que você não utiliza.')], 'Verificar agora', load, glyph='🗑')
        self._head(v, 'Desinstalador', ('Inventário: %s · %d programas' % (self.prog_stamp or '—', len(rows))))
        top = tk.Frame(v, bg=P['main'])
        top.pack(fill='x', padx=18, pady=(0, 6))
        self.psearch = tk.StringVar(value=getattr(self, '_ps', ''))
        ent = tk.Entry(top, textvariable=self.psearch, bg='#1a1f2b', fg=P['txt'], insertbackground='#fff', relief='flat', font=(F, 10), highlightthickness=1, highlightbackground='#444b5a', highlightcolor=P['blue2'])
        ent.pack(fill='x', ipady=5)
        data = [(p['id'], p.get('name', ''), p.get('version', ''), p.get('publisher', ''), human(int(p.get('size') or 0) * 1024) if p.get('size') else '—',
                 p.get('date') or '—', {'silent': 'Silenciosa', 'interactive': 'Com janela'}.get(p.get('method') or '', 'Indisponível')) for p in rows]
        cols = [('n', 'Programa', 260, 'w'), ('v', 'Versão', 100, 'w'), ('f', 'Fabricante', 170, 'w'), ('s', 'Tamanho', 90, 'e'), ('d', 'Instalado em', 100, 'w'), ('m', 'Desinstalação', 110, 'w')]
        frame, tv = self.table(v, cols, data, checks=True)
        frame.pack(fill='both', expand=True, padx=14)
        self.ptv = tv
        f = self._foot(v)
        lbl = tk.Label(f, text='0 selecionado(s)', bg=P['foot'], fg='#fff', font=(F, 9, 'bold'))
        lbl.pack(side='left')
        tv._changed = lambda: lbl.configure(text='%d selecionado(s)' % len(tv._checked))
        self._foot_btn(f, 'Desinstalar', lambda: self._uninstall(tv), 'blue')
        self._foot_btn(f, 'Atualizar lista', load)

        def filt(*_a):
            q, self._ps = self.psearch.get().casefold(), self.psearch.get()
            shown = [r for r in data if q in ' '.join(map(str, r[1:4])).casefold()]
            tv.delete(*tv.get_children(''))
            for n, r in enumerate(shown):
                tv.insert('', 'end', iid=str(r[0]), values=['☑' if str(r[0]) in tv._checked else '☐'] + [str(x) for x in r[1:]], tags=('odd',) if n % 2 else ())
        self.psearch.trace_add('write', filt)
        if self.psearch.get():
            filt()

    def load_programs(self):
        def work(prog):
            from arcoll_programs import installed_programs
            prog('[10%] Localizando apps instalados')
            return installed_programs()

        def done(rows):
            self.programs, self.prog_stamp = rows, time.strftime('%d/%m/%Y %H:%M')
            self.app.cc_push_programs(rows)
            self.status('Inventário atualizado: %d programas · enviado à plataforma' % len(rows))
        self.run('Localizando apps instalados', work, done)

    def _uninstall(self, tv):
        ids = list(tv._checked)
        names = [p['name'] for p in (self.programs or []) if p['id'] in tv._checked][:12]
        if not ids:
            return self.status('Selecione ao menos um programa.', True)
        if not self._confirm('Desinstalar programas', 'Desinstalar %d programa(s)?\n\n%s\n\nO agente tenta criar um ponto de restauração antes e pode abrir janelas dos desinstaladores.' % (len(ids), '\n'.join('• ' + n for n in names))):
            return

        def work(prog):
            from arcoll_programs import uninstall_programs, installed_programs
            summary = uninstall_programs(ids[:100], 'try', True, True, prog)
            return {'summary': summary, 'rows': installed_programs()}

        def done(res):
            self.programs, self.prog_stamp = res['rows'], time.strftime('%d/%m/%Y %H:%M')
            self.app.cc_push_programs(res['rows'])
            self.status(str(res['summary'])[:200])
        self.run('Desinstalando programas', work, done)

    # ---------------------------------------------------------------- páginas por ferramenta (genéricas)
    def page_duplicates(self, v):
        r = self.rep.get('duplicates')
        if r is None:
            if self.dup['stage'] == 'pick':
                return self._dup_pick(v)
            return self._intro(v, 'Localizador de arquivos duplicados', 'Libere espaço valioso em disco excluindo arquivos indesejados e duplicados.',
                               [('Organize o espaço do seu disco', 'Verifique se há arquivos ocultos e resíduos que se acumulam ao longo do tempo.'),
                                ('Limpe de forma mais completa e segura', 'Pelo menos uma cópia de cada grupo é mantida.')], 'Comece agora',
                               lambda: (self.dup.__setitem__('stage', 'pick'), self.show()), glyph='⎘')
        self._head(v, 'Localizador de arquivos duplicados', self._stamp('duplicates'))
        groups = r.get('groups', [])
        if not groups:
            return self._empty(v, 'Nenhum duplicado encontrado', 'Seus arquivos estão organizados.')
        rows = [(f['id'], g['files'][0]['path'].rsplit('\\', 1)[-1], f['path'], human(g['size'])) for g in groups for f in g['files']]
        frame, tv = self.table(v, [('n', 'Arquivo', 200, 'w'), ('p', 'Caminho', 420, 'w'), ('s', 'Tamanho', 90, 'e')], rows, checks=True)
        frame.pack(fill='both', expand=True, padx=14)
        first = {g['files'][0]['id'] for g in groups}

        def mark():
            tv._checked = {i for i in tv.get_children() if i not in first}
            for i in tv.get_children():
                tv.set(i, 'chk', '☑' if i in tv._checked else '☐')
        f = self._foot(v)
        self._foot_text(f, '%d grupos · %s desperdiçados' % (len(groups), human(r.get('wasted', 0))), 'As cópias vão para a Lixeira')
        self._foot_btn(f, 'Excluir selecionados', lambda: self._dup_delete(tv), 'yel')
        self._foot_btn(f, 'Marcar cópias (manter a 1ª)', mark)
        self._foot_btn(f, 'Nova verificação', lambda: (self.rep.pop('duplicates', None), self.dup.__setitem__('stage', 'pick'), self.show()))

    def _dup_pick(self, v):
        tk.Label(v, text='Localizador de arquivos duplicados  ⓘ', bg='#2a2f3c', fg='#fff', font=(F, 9, 'bold'), anchor='w', padx=160, pady=8).pack(fill='x')
        box = tk.Frame(v, bg=P['main'])
        box.pack(fill='both', expand=True, padx=160, pady=30)
        tk.Label(box, text='Selecione uma unidade ou pasta para verificação', bg=P['main'], fg='#fff', font=(F, 14, 'bold')).pack(anchor='w')
        tk.Label(box, text='Verifique arquivos ocultos e duplicados para descobrir o que está ocupando mais espaço.', bg=P['main'], fg=P['mut'], font=(F, 9)).pack(anchor='w', pady=(2, 14))
        panel = tk.Frame(box, bg='#262c3b', highlightthickness=1, highlightbackground=P['line'], padx=16, pady=12)
        panel.pack(fill='both', expand=True)
        drives = ['%s:' % c for c in 'CDEFGH' if os.path.exists('%s:\\' % c)] or ['C:']
        choices = [('users', 'Pastas dos usuários (Documentos, Downloads, Imagens...)')] + [(d, 'Unidade ' + d) for d in drives]
        self._dvar = tk.StringVar(value='users')
        for val, text in choices:
            tk.Radiobutton(panel, text=text, variable=self._dvar, value=val, bg='#262c3b', fg='#fff', selectcolor='#262c3b', activebackground='#262c3b', activeforeground='#fff', font=(F, 10), anchor='w').pack(fill='x', pady=3)
        f = self._foot(v)
        self._foot_btn(f, 'Verificar tudo', lambda: self.tool('duplicates', {'tool': 'duplicates', 'action': 'scan', 'roots': [self._dvar.get()], 'min_size_kb': 1024}, 'Verificando unidades'), 'blue')

    def _dup_delete(self, tv):
        ids = list(tv._checked)
        if ids and self._confirm('Excluir duplicados', '%d arquivos serão enviados para a Lixeira. O conteúdo é conferido novamente antes. Continuar?' % len(ids)):
            self.tool('duplicates', {'tool': 'duplicates', 'action': 'delete', 'ids': ids, 'permanent': False}, 'Excluindo duplicados')

    def page_backup(self, v):
        self._head(v, 'Backup em Nuvem')
        worker = getattr(self.app, 'cloud_backup', None)
        if worker is None:
            return self._empty(v, 'Conecte este agente à plataforma', 'O backup usa a autorização da instalação e as configurações da sua rede.')
        state = worker.snapshot()
        viewport = tk.Canvas(v, bg=P['main'], highlightthickness=0)
        scrollbar = ttk.Scrollbar(v, orient='vertical', command=viewport.yview)
        scrollbar.pack(side='right', fill='y')
        viewport.configure(yscrollcommand=scrollbar.set)
        viewport.pack(fill='both', expand=True, padx=12, pady=8)
        box = tk.Frame(viewport, bg=P['main'])
        window = viewport.create_window((0, 0), window=box, anchor='nw')
        box.bind('<Configure>', lambda _e: viewport.configure(scrollregion=viewport.bbox('all')))
        viewport.bind('<Configure>', lambda e: viewport.itemconfigure(window, width=max(1, e.width)))
        viewport.bind('<MouseWheel>', lambda e: viewport.yview_scroll(-1 if e.delta > 0 else 1, 'units'))
        message = tk.StringVar(value=state.get('message', 'Aguardando configuração.'))
        tk.Label(box, textvariable=message, bg=P['main'], fg=P['txt'], font=(F, 12, 'bold'), anchor='w', wraplength=440).pack(fill='x', pady=8)
        progress = ttk.Progressbar(box, maximum=100)
        progress.pack(fill='x', pady=8)
        counters = tk.StringVar()
        tk.Label(box, textvariable=counters, bg=P['main'], fg=P['mut'], font=(F, 10), anchor='w').pack(fill='x')
        actions = tk.Frame(box, bg=P['main']); actions.pack(fill='x', pady=12)
        ttk.Button(actions, text='Autorizar pasta', command=self.app.cloud_choose_folder).pack(side='left', padx=(0, 8))
        pause = ttk.Button(actions, text='Retomar' if worker.local_paused else 'Pausar')
        def toggle():
            worker.pause(not worker.local_paused)
            pause.configure(text='Retomar' if worker.local_paused else 'Pausar')
        pause.configure(command=toggle); pause.pack(side='left', padx=(0, 8))
        ttk.Button(actions, text='Sincronizar agora', command=worker.request_run).pack(side='left')
        tk.Label(box, text='Pastas autorizadas neste perfil', bg=P['main'], fg=P['txt'], font=(F, 10, 'bold'), anchor='w').pack(fill='x', pady=(8, 4))
        listing = ttk.Treeview(box, columns=('path',), show='headings', height=4)
        listing.heading('path', text='Pasta local'); listing.column('path', width=350)
        listing.pack(fill='both', expand=True)
        for i, folder in enumerate(worker.preference('roots', [])):
            listing.insert('', 'end', iid=str(i), values=(folder['path'],))
        def remove():
            selected = listing.selection()
            if selected:
                self.app.cloud_remove_folder(listing.item(selected[0], 'values')[0])
        footer = tk.Frame(box, bg=P['main']); footer.pack(fill='x', pady=8)
        ttk.Button(footer, text='Remover autorização', command=remove).pack(side='left')
        ttk.Button(footer, text='Publicar pastas novamente', command=lambda:self.app._cloud_local_task(worker.publish_roots)).pack(side='right')
        def full_backup():
            def confirm():
                if messagebox.askyesno('Reenviar backup completo', 'Reenviar todos os arquivos autorizados, inclusive os que não mudaram? Isso usa a banda de envio e substitui as cópias atuais no provedor.', parent=self.winfo_toplevel()):
                    worker.request_run(full=True)
            self.app._show_manager_auth('Reenviar backup completo', confirm)
        ttk.Button(box, text='Reenviar backup completo', command=full_backup).pack(anchor='w', pady=6)
        tk.Label(box, text='Conta, horário e exclusões: abra Redes → Clean → Backup em Nuvem na plataforma. Arquivos excluídos no PC permanecem na nuvem. Recuperação e versões: use o provedor.', bg=P['main'], fg=P['mut'], font=(F, 10), wraplength=500, justify='left').pack(fill='x', pady=8)
        def refresh():
            if not box.winfo_exists():
                return
            current = worker.snapshot()
            message.set(current.get('message', 'Aguardando configuração.'))
            counters.set('%s de %s arquivos · %s enviados · último sucesso: %s' % (current.get('files_done', 0), current.get('files_total', 0), human(current.get('bytes_done', 0)), current.get('last_success_at', 'ainda não concluído')))
            progress['value'] = min(100, 100 * current.get('bytes_done', 0) / max(1, current.get('bytes_total', 0)))
            box.after(1500, refresh)
        refresh()

    def page_cloud(self, v):
        r = self.rep.get('cloud')
        scan = lambda: self.tool('cloud', {'tool': 'cloud', 'action': 'scan'}, 'Analisando pastas de nuvem')
        if r is None:
            return self._intro(v, 'Limpador de Disco na Nuvem', 'Remova arquivos indesejados do seu armazenamento na nuvem e evite pagar por espaço extra.',
                               [('Liberar espaço', 'Remove arquivos grandes, duplicados ou desatualizados.'), ('Mantenha seu armazenamento organizado', 'Limpe arquivos esquecidos nas pastas compartilhadas.'),
                                ('Conecte suas contas', 'Funciona com Google Drive, OneDrive, Dropbox, iCloud e Box.')], 'Começar', scan, 'yel', '☁')
        self._head(v, 'Limpador de Disco na Nuvem', ' · '.join('%s %s' % (p['name'], human(p['bytes'])) for p in r.get('providers', [])))
        if not r.get('providers'):
            return self._empty(v, 'Nenhuma pasta de nuvem encontrada', 'Entre no OneDrive, Google Drive, Dropbox, iCloud ou Box neste computador.')
        cat = {'junk': 'Inútil', 'duplicate': 'Duplicado', 'large': 'Grande', 'old': 'Antigo'}
        rows = [(i['id'], cat.get(i['cat'], i['cat']), i['path'], human(i['bytes'])) for i in r.get('items', [])]
        frame, tv = self.table(v, [('c', 'Tipo', 90, 'w'), ('p', 'Arquivo', 520, 'w'), ('s', 'Tamanho', 90, 'e')], rows, checks=True)
        frame.pack(fill='both', expand=True, padx=14)
        f = self._foot(v)
        self._foot_text(f, '%d itens encontrados' % len(rows), self._stamp('cloud'))
        self._foot_btn(f, 'Limpar selecionados', lambda: self._cloud_delete(tv), 'yel')
        self._foot_btn(f, 'Escanear novamente', scan)

    def _cloud_delete(self, tv):
        ids = list(tv._checked)[:300]
        if ids and self._confirm('Enviar para a Lixeira', '%d arquivo(s) das pastas sincronizadas irão para a Lixeira. A exclusão pode ser sincronizada com a nuvem. Continuar?' % len(ids)):
            self.tool('cloud', {'tool': 'cloud', 'action': 'delete', 'ids': ids}, 'Enviando para a Lixeira')

    def page_perf(self, v):
        r = self.rep.get('performance')
        scan = lambda: self.tool('performance', {'tool': 'performance', 'action': 'list'}, 'Lendo aplicativos em execução')
        if r is None:
            return self._intro(v, 'Otimização de Desempenho', 'Aumente o desempenho do PC colocando aplicativos não essenciais em suspensão.',
                               [('Suspensão sem perda', 'Apps suspensos param de consumir processador e voltam ao ativar.'), ('Mais velocidade', 'Libere memória e recursos.')], 'Verificar agora', scan, glyph='🚀')
        ex = set(load_settings().get('perf_excluded', []))
        act, sus = [i for i in r['active'] if i['id'] not in ex], [i for i in r['suspended'] if i['id'] not in ex]
        self._head(v, 'Otimização de Desempenho')
        self._tabs(v, [('active', '⚙ Aplicativos ativos (%d)' % len(act)), ('sus', '☾ Aplicativos em suspensão (%d)' % len(sus))], 'perf')
        rows = act if self.tab['perf'] == 'active' else sus
        if not rows:
            self._empty(v, 'Nenhum app aqui ainda.')
        else:
            tk.Label(v, text=('Colocar %d apps no modo de suspensão' if self.tab['perf'] == 'active' else '%d apps em modo de suspensão') % len(rows), bg=P['main'],
                     fg='#fff', font=(F, 13, 'bold'), anchor='w', padx=18, pady=10).pack(fill='x')
            data = [(i['id'], i['name'], '●' * i['impact'] + '○' * (3 - i['impact']) + ' ' + ['', 'Baixo', 'Médio', 'Alto'][i['impact']], human(i['memory'])) for i in rows]
            frame, tv = self.table(v, [('n', 'Todos os apps', 320, 'w'), ('i', 'Impacto no desempenho do PC', 220, 'w'), ('m', 'Memória', 100, 'e')], data, checks=True)
            frame.pack(fill='both', expand=True, padx=14)
            self._perf_tv = tv
        f = self._foot(v)
        self._foot_text(f, self._stamp('performance'))
        suspend = self.tab['perf'] == 'active'
        op = lambda: self._perf_op('suspend' if suspend else 'resume')
        self._foot_btn(f, 'Suspender selecionados' if suspend else 'Ativar selecionados', op, 'yel', bool(rows))
        self._foot_btn(f, 'Escanear novamente', scan)

    def _perf_op(self, action):
        ids = list(getattr(self, '_perf_tv', None)._checked)[:40] if getattr(self, '_perf_tv', None) else []
        if not ids:
            return self.status('Selecione ao menos um aplicativo.', True)
        self.tool('performance', {'tool': 'performance', 'action': action, 'ids': ids}, 'Suspendendo aplicativos' if action == 'suspend' else 'Ativando aplicativos')

    def page_startup(self, v):
        r = self.rep.get('startup')
        scan = lambda: self.tool('startup', {'tool': 'startup', 'action': 'list'}, 'Lendo itens de inicialização')
        if r is None:
            return self._intro(v, 'Gerenciar aplicativos de inicialização', 'Escolha facilmente quais programas são iniciados automaticamente no Windows.',
                               [('Reduza a carga em segundo plano', 'Alguns itens podem causar lentidão ao ligar.'), ('Sem perda de dados', 'Desativar mantém o item.')], 'Verificar agora', scan, glyph='🖥')
        by = {'apps': [i for i in r['items'] if i['kind'] in ('run', 'folder')], 'tasks': [i for i in r['items'] if i['kind'] == 'task'],
              'context': [i for i in r['items'] if i['kind'] == 'context'], 'services': [i for i in r['items'] if i['kind'] == 'service']}
        self._head(v, 'Gerenciador de inicialização', self._stamp('startup'))
        self._tabs(v, [('apps', '🖥 Apps de inicialização (%d)' % len(by['apps'])), ('tasks', '⏱ Tarefas agendadas (%d)' % len(by['tasks'])),
                       ('context', '🖱 Menu de contexto (%d)' % len(by['context'])), ('services', '⚙ Serviços do Windows (%d)' % len(by['services']))], 'startup')
        titles = {'apps': 'Gerenciar aplicativos de inicialização', 'tasks': 'Reduza a carga em segundo plano', 'context': 'Simplifique o menu de contexto', 'services': 'Controle serviços essenciais em segundo plano'}
        tk.Label(v, text=titles[self.tab['startup']], bg=P['main'], fg='#fff', font=(F, 13, 'bold'), anchor='w', padx=18, pady=10).pack(fill='x')
        rows = by[self.tab['startup']]
        data = [(i['id'], i['name'], i.get('detail') or i.get('command', ''), i.get('publisher') or i.get('source', ''), '● Ativado' if i['enabled'] else '○ Desativado') for i in rows]
        frame, tv = self.table(v, [('n', 'Programa', 220, 'w'), ('d', 'Nome / comando', 360, 'w'), ('p', 'Editor', 160, 'w'), ('a', 'Ativar', 110, 'w')], data, on_click=self._startup_click)
        frame.pack(fill='both', expand=True, padx=14)
        self._st_items = {i['id']: i for i in rows}
        f = self._foot(v)
        self._foot_text(f, 'Clique em "Ativar" para alternar o estado', 'Excluir remove o item (registro com backup)')
        self._foot_btn(f, 'Excluir selecionado', lambda: self._startup_delete(tv), 'ghost', self.tab['startup'] in ('apps', 'tasks'))
        self._foot_btn(f, 'Atualizar', scan)

    def _startup_click(self, tv, e):
        if tv.identify('region', e.x, e.y) == 'cell' and tv.identify_column(e.x) == '#4':
            iid = tv.identify_row(e.y)
            item = getattr(self, '_st_items', {}).get(iid)
            if item:
                op = 'disable' if item['enabled'] else 'enable'
                self.tool('startup', {'tool': 'startup', 'action': 'apply', 'operations': [{'id': iid, 'op': op}]}, 'Aplicando alteração')

    def _startup_delete(self, tv):
        sel = tv.selection()
        if sel and self._confirm('Excluir item de inicialização', 'Remover %d item(ns) da inicialização?' % len(sel)):
            self.tool('startup', {'tool': 'startup', 'action': 'apply', 'operations': [{'id': i, 'op': 'delete'} for i in sel[:100]]}, 'Aplicando alteração')

    def page_updater(self, v):
        r = self.rep.get('updater')
        scan = lambda: self.tool('updater', {'tool': 'updater', 'action': 'list'}, 'Verificando softwares desatualizados')
        if r is None:
            return self._intro(v, 'Atualizador de Software', 'Mantenha seus softwares atualizados para aumentar a velocidade, o desempenho e a segurança.',
                               [('Verificar', 'Encontre e instale as atualizações mais recentes em um só lugar.'), ('Privacidade aprimorada', 'Corrija falhas e reforce a segurança.')], 'Escanear agora', scan, glyph='⟳')
        self._head(v, 'Atualizador de Software', self._stamp('updater'))
        if not r['items']:
            self._empty(v, 'Tudo atualizado', 'Todos os programas gerenciados estão atualizados.')
            tv = None
        else:
            data = [(i['id'], i['name'], i['version'], i['available']) for i in r['items']]
            frame, tv = self.table(v, [('n', 'Programa', 320, 'w'), ('v', 'Versão', 120, 'w'), ('a', 'Disponível', 120, 'w')], data, checks=True)
            frame.pack(fill='both', expand=True, padx=14)
        f = self._foot(v)
        self._foot_btn(f, 'Atualizar selecionados', lambda: self._upd_apply(tv), 'yel', bool(tv))
        self._foot_btn(f, 'Escanear novamente', scan)

    def _upd_apply(self, tv):
        ids = list(tv._checked)[:30]
        if ids and self._confirm('Atualizar programas', '%d programa(s) serão atualizados em modo silencioso. Pode demorar.' % len(ids)):
            self.tool('updater', {'tool': 'updater', 'action': 'apply', 'ids': ids}, 'Atualizando programas')

    def page_windows_update(self, v):
        self._head(v, 'Windows Update', 'Microsoft Update Agent')
        if getattr(self, 'remote_activity', ''):
            tk.Label(v, text=self.remote_activity, bg=P['act'], fg=P['txt'], wraplength=600, justify='left', padx=12, pady=10).pack(fill='x', padx=18, pady=(5, 8))
        tk.Label(v, text='Verifique as atualizações disponíveis neste computador.', bg=P['main'], fg=P['mut'], font=(F, 10)).pack(anchor='w', padx=20, pady=12)
        report = self.rep.get('windows_update') or {}
        if report:
            tk.Label(v, text=str(report.get('windows', 'Windows')), bg=P['main'], fg=P['txt'], font=(F, 11, 'bold')).pack(anchor='w', padx=20)
            for u in report.get('updates', [])[:50]:
                tk.Label(v, text='• '+str(u.get('title','Atualização'))[:100], bg=P['main'], fg=P['mut'], anchor='w', justify='left', wraplength=540).pack(fill='x', padx=22, pady=3)
            if not report.get('updates'):
                tk.Label(v, text='Nenhuma atualização pendente identificada.', bg=P['main'], fg=P['ok']).pack(anchor='w', padx=20, pady=8)
        bar=tk.Frame(v,bg=P['main']);bar.pack(anchor='w',padx=18,pady=16)
        def scan():
            def work(prog):
                prog('Verificando atualizações oficiais do Windows...')
                from arcoll_toolkit import windows_update
                return windows_update({'mode':'scan'})
            def done(data):
                self.rep['windows_update']=data
                self.status('Windows Update consultado. Atualizações: '+str(data.get('total',0)))
            self.run('Verificando Windows Update', work, done)
        Pill(bar,'Verificar atualizações',scan,'blue').pack(side='left',padx=4)
        if report.get('mode')=='scan' and report.get('updates'):
            def install():
                if not messagebox.askyesno('Windows Update','Baixar e instalar todas as atualizações exibidas? O Windows poderá exigir reinicialização.',parent=self.winfo_toplevel()):return
                updates=[{'id':u['id'],'revision':u['revision']} for u in report['updates']]
                def work(prog):
                    prog('Baixando e instalando atualizações do Windows...')
                    from arcoll_toolkit import windows_update
                    return windows_update({'mode':'install','updates':updates})
                def done(data):
                    self.rep['windows_update']=data
                    self.status('Instalação finalizada. Confira as atualizações no Windows.')
                self.run('Instalando atualizações',work,done)
            Pill(bar,'Baixar e instalar',install,'yel').pack(side='left',padx=6)

        def open_settings(section):
            try:
                os.startfile('ms-settings:' + section)
                self.status('Configurações do Windows abertas.')
            except OSError as exc:self.status('Não foi possível abrir configurações: ' + str(exc), True)
        options=tk.Frame(v,bg=P['main']);options.pack(fill='x',padx=18,pady=(8,10))
        tk.Label(options,text='Mais opções',font=(F,10,'bold'),bg=P['main'],fg=P['txt']).pack(anchor='w',pady=(4,8))
        details=[
            ('◁','Obtenha as atualizações mais recentes assim que elas estiverem disponíveis','Correções e melhorias de segurança disponíveis primeiro.','windowsupdate'),
            ('Ⅱ','Pausar atualizações','Escolha a data da pausa diretamente nas Configurações do Windows.','windowsupdate'),
            ('↶','Histórico de atualização','Confira as atualizações instaladas e seus resultados.','windowsupdate-history'),
            ('⚙','Opções avançadas','Atualizações opcionais, otimização da entrega e horas ativas.','windowsupdate-options'),
            ('♧','Programa Windows Insider','Gerencie sua inscrição nas versões de prévia do Windows.','windowsinsider')]
        for glyph,title,description,location in details:
            row=tk.Frame(options,bg='#2e2e2e',height=65,cursor='hand2');row.pack(fill='x',pady=3)
            row.pack_propagate(False)
            ico=tk.Label(row,text=glyph,width=3,bg='#2e2e2e',fg='#f0f0f0',font=('Segoe UI Symbol',17));ico.pack(side='left',padx=(7,5))
            area=tk.Frame(row,bg='#2e2e2e');area.pack(side='left',fill='both',expand=True,pady=9)
            title_label=tk.Label(area,text=title,bg='#2e2e2e',fg='#f7f7f7',font=(F,9),anchor='w',justify='left',wraplength=550)
            title_label.pack(fill='x')
            detail_label=tk.Label(area,text=description,bg='#2e2e2e',fg='#b9becb',font=(F,8),anchor='w',justify='left',wraplength=550)
            detail_label.pack(fill='x')
            arrow=tk.Label(row,text='›',bg='#2e2e2e',fg='#f5f5f5',font=(F,17));arrow.pack(side='right',padx=12)
            for widget in (row,ico,area,title_label,detail_label,arrow):
                widget.bind('<Button-1>',lambda _event,loc=location:open_settings(loc))

    def page_drivers(self, v):
        r = self.rep.get('drivers')
        scan = lambda: self.tool('drivers', {'tool': 'drivers', 'action': 'scan'}, 'Verificando drivers')
        if r is None:
            return self._intro(v, 'Driver Updater', 'Mantenha os drivers atualizados para melhorar estabilidade, desempenho e segurança.',
                               [('Verificar', 'Procura no Windows Update novas versões de drivers.'), ('Com ponto de restauração', 'O agente tenta criar um antes de instalar.')], 'Escanear agora', scan, glyph='⚙')
        ig = set(load_settings().get('drivers_ignored', []))
        out, cur = [d for d in r['outdated'] if d['id'] not in ig], [d for d in r['current'] if d['id'] not in ig]
        self._head(v, 'Driver Updater', self._stamp('drivers'))
        self._tabs(v, [('out', '⬇ Drivers desatualizados (%d)' % len(out)), ('cur', '✔ Drivers atualizados (%d)' % len(cur))], 'drivers')
        rows = out if self.tab['drivers'] == 'out' else cur
        tk.Label(v, text=('%d drivers para atualizar' % len(out)) if self.tab['drivers'] == 'out' else 'Estes drivers já estão atualizados', bg=P['main'], fg='#fff', font=(F, 13, 'bold'), anchor='w', padx=18, pady=10).pack(fill='x')
        data = [(d['id'], d['name'], d.get('group', ''), d.get('version', '—'), d.get('date') or '—') for d in rows]
        frame, tv = self.table(v, [('n', 'Drivers', 300, 'w'), ('g', 'Categoria', 130, 'w'), ('v', 'Versão', 120, 'w'), ('d', 'Lançamento', 100, 'w')], data, checks=self.tab['drivers'] == 'out')
        frame.pack(fill='both', expand=True, padx=14)
        f = self._foot(v)
        if r.get('reboot'):
            self._foot_text(f, 'Reinicie o computador para concluir a instalação.')
        self._foot_btn(f, 'Atualizar selecionados', lambda: self._drv_install(tv), 'yel', self.tab['drivers'] == 'out')
        self._foot_btn(f, 'Escanear novamente', scan)

    def _drv_install(self, tv):
        ids = list(tv._checked)[:20]
        if ids and self._confirm('Atualizar drivers', '%d driver(s) serão baixados do Windows Update e instalados. Pode ser necessário reiniciar. Continuar?' % len(ids)):
            self.tool('drivers', {'tool': 'drivers', 'action': 'install', 'ids': ids}, 'Instalando drivers')
