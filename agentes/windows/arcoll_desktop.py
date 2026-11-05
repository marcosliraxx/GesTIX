#!/usr/bin/env python
# Caminho: agentes/windows/arcoll_desktop.py
"""Desktop Agent: approval gate and launcher."""
import argparse, hashlib, json, os, platform, socket, subprocess, sys, threading, time, urllib.error, urllib.request, shutil
import tkinter as tk
from tkinter import messagebox, simpledialog
from arcoll_toolkit import AppearanceManager, theme_color, bind_theme, windows_version
from arcoll_release import VERSION

parser = argparse.ArgumentParser()
parser.add_argument('--origin', default=os.environ.get('ARCOLL_ORIGIN', '').strip())
parser.add_argument('--site-code', default=os.environ.get('ARCOLL_SITE_CODE', '').strip().upper())
parser.add_argument('--download-key', default=os.environ.get('ARCOLL_DOWNLOAD_KEY', '').strip())
parser.add_argument('--background', action='store_true')
args = parser.parse_args()

ORIGIN = args.origin.rstrip('/')
BUNDLED_SITE_CODE = args.site_code
DOWNLOAD_KEY = args.download_key
SYSTEM_NAME = 'Agente de Rede'
SYSTEM_DESCRIPTION = 'Agente de Rede'
try:
    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'agent-config.json'), 'r', encoding='utf-8') as _cfg_file:
        _cfg = json.load(_cfg_file)
    ORIGIN = str(_cfg.get('origin') or ORIGIN).rstrip('/')
    BUNDLED_SITE_CODE = str(_cfg.get('site_code') or BUNDLED_SITE_CODE).strip().upper()
    DOWNLOAD_KEY = str(_cfg.get('download_key') or DOWNLOAD_KEY).strip()
    SYSTEM_NAME = str(_cfg.get('system_name') or SYSTEM_NAME).strip() or SYSTEM_NAME
    SYSTEM_DESCRIPTION = str(_cfg.get('system_description') or SYSTEM_DESCRIPTION).strip() or SYSTEM_DESCRIPTION
except Exception:
    pass
if not ORIGIN.startswith('https://'):
    ORIGIN = ''


def machine_fingerprint():
    raw = ''
    if platform.system() == 'Windows':
        try:
            import winreg
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r'SOFTWARE\Microsoft\Cryptography') as key:
                raw = str(winreg.QueryValueEx(key, 'MachineGuid')[0])
        except Exception:
            pass
    if not raw:
        raw = '|'.join([platform.node(), platform.system(), platform.machine(), str(uuid_node())])
    return hashlib.sha256(('arcoll-speed-connection|' + raw).encode('utf-8')).hexdigest()


def uuid_node():
    import uuid
    return uuid.getnode()


def local_ip():
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.connect(('10.255.255.255', 1))
            return sock.getsockname()[0]
    except Exception:
        return ''


def gateway_ip():
    if platform.system() == 'Windows':
        try:
            out = subprocess.run(['ipconfig'], capture_output=True, text=True, timeout=3, errors='ignore', creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0)).stdout
            import re
            m = re.search(r'(?:Gateway Padrão|Default Gateway)[ .:]*([0-9.]+)', out, re.I)
            return m.group(1) if m else ''
        except Exception:
            return ''
    try:
        out = subprocess.run(['ip', 'route', 'show', 'default'], capture_output=True, text=True, timeout=3).stdout
        import re
        m = re.search(r'default via (\S+)', out)
        return m.group(1) if m else ''
    except Exception:
        return ''


FINGERPRINT = machine_fingerprint()
INSTANCE_HOST = '127.0.0.1'
INSTANCE_PORT = 38000 + (int(FINGERPRINT[:6], 16) % 2000)


def focus_running_app():
    try:
        with socket.create_connection((INSTANCE_HOST, INSTANCE_PORT), timeout=0.7) as client:
            client.sendall(b'PING')
        return True
    except OSError:
        return False


def api(action, data, timeout=20):
    if not ORIGIN:
        raise RuntimeError('Endereço HTTPS do sistema não foi configurado no instalador.')
    url = ORIGIN + '/api/api.php?action=' + action
    req = urllib.request.Request(
        url,
        data=json.dumps(data).encode(),
        headers={'Content-Type': 'application/json', 'User-Agent': 'ArcollDesktopAgent/' + VERSION},
        method='POST'
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            return json.loads(res.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        try:
            body = json.loads(e.read().decode('utf-8'))
        except Exception:
            body = {}
        raise RuntimeError(body.get('error') or ('Servidor respondeu HTTP ' + str(e.code)))
    except urllib.error.URLError as e:
        reason = getattr(e, 'reason', e)
        raise RuntimeError('Não foi possível conectar ao servidor HTTPS: ' + str(reason))
    except TimeoutError:
        raise RuntimeError('Tempo limite excedido ao aguardar o servidor. Confira a internet e tente novamente.')


class DesktopAgent:
    def __init__(self):
        self.root = tk.Tk()
        self.root.withdraw()
        self.root.title(SYSTEM_NAME + ' | Agente de Rede')
        self.root.geometry('560x380')
        self.root.resizable(True, True)
        self.root.minsize(360, 340)
        self._center_window()
        bind_theme(self.root, background='surface').configure(bg=theme_color('surface'))
        icon_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'favicon.ico')
        if os.path.isfile(icon_path):
            try:
                self.root.iconbitmap(default=icon_path)
            except Exception:
                pass
        self.proc = None
        self.app_proc = None
        self._agent_log = None
        self.code = BUNDLED_SITE_CODE
        self.download_key = DOWNLOAD_KEY
        self.closed = False
        self.busy = False
        self.progress_step = 0
        self.request_started = 0.0
        self.app_launch_pending = False
        self.app_closed_by_user = False
        self.loading_step = 0
        self.loading_active = True
        self.was_pending = False
        self.approval_countdown = False
        self.previous_installation = None

        bind_theme(tk.Label(self.root, text=SYSTEM_NAME.upper(), font=('Segoe UI', 11, 'bold'), fg=theme_color('accent'), bg=theme_color('surface')), foreground='accent', background='surface').place(x=28, y=22)
        bind_theme(tk.Label(self.root, text='Agente de Monitoramento', font=('Segoe UI', 22, 'bold'), fg=theme_color('text'), bg=theme_color('surface')), foreground='text', background='surface').place(x=28, y=52)
        bind_theme(tk.Label(self.root, text='Monitoramento e liberação automática deste computador', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface')), foreground='muted', background='surface').place(x=30, y=91)

        self.phase = bind_theme(tk.Label(self.root, text='AGUARDANDO LIBERAÇÃO', font=('Segoe UI', 12, 'bold'), fg=theme_color('warning'), bg=theme_color('surface'), anchor='w'), foreground='warning', background='surface')
        self.phase.place(x=30, y=136, width=500, height=26)
        self.loading = bind_theme(tk.Label(self.root, text='● ○ ○', font=('Segoe UI', 18, 'bold'), fg=theme_color('accent'), bg=theme_color('surface'), anchor='w'), foreground='accent', background='surface')
        self.loading.place(x=30, y=171, width=150, height=28)
        self.status = bind_theme(tk.Label(self.root, text='Aguardando liberação do gestor da rede.', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w', wraplength=500, justify='left'), foreground='muted', background='surface')
        self.status.place(x=30, y=210, width=500, height=62)
        self.note = bind_theme(tk.Label(self.root, text='Assim que o gestor liberar, o agente inicia automaticamente neste computador.', font=('Segoe UI', 9), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w', wraplength=500, justify='left'), foreground='muted', background='surface')
        self.note.place(x=30, y=280, width=500, height=36)
        self.details = bind_theme(tk.Label(self.root, text='Computador: ' + platform.node() + '   |   IP local: ' + (local_ip() or 'indisponível'), font=('Segoe UI', 8), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w'), foreground='muted', background='surface')
        self.details.place(x=30, y=324, width=500, height=24)

        self.code_var = tk.StringVar(value=BUNDLED_SITE_CODE or '')
        # Controles mantidos apenas para compatibilidade com a lógica existente, sem exibição na tela.
        self.entry = bind_theme(tk.Entry(self.root, textvariable=self.code_var, font=('Segoe UI', 15), bg=theme_color('alt'), fg=theme_color('text'), insertbackground=theme_color('text'), relief='flat'), background='alt', foreground='text', insertbackground='text')
        self.button = bind_theme(tk.Button(self.root, text='Cadastrar este computador', command=self.register, font=('Segoe UI', 10, 'bold'), bg=theme_color('accent'), fg=theme_color('text'), relief='flat', cursor='hand2'), background='accent', foreground='text')
        self.transfer_button = bind_theme(tk.Button(self.root, text='Desvincular e solicitar liberação', command=self.request_transfer, font=('Segoe UI', 10, 'bold'), bg=theme_color('accent'), fg=theme_color('text'), relief='flat', cursor='hand2'), background='accent', foreground='text')
        self.entry.bind('<Return>', lambda _e: self.register())

        try:
            old_path=os.path.join(os.environ.get('APPDATA',os.path.expanduser('~')),'Arcoll','agent.json')
            with open(old_path,'r',encoding='utf-8') as prior_file:
                prior=json.load(prior_file)
            if prior.get('fingerprint')==FINGERPRINT and prior.get('site_code')!=self.code and __import__('re').fullmatch(r'[a-f0-9]{64}',str(prior.get('download_key',''))):
                self.previous_installation=prior
        except (OSError,ValueError,TypeError):
            pass
        self.root.protocol('WM_DELETE_WINDOW', self.close)
        self.root.after(100, self.restore_saved_code)
        self.root.after(220, self._animate_waiting)
        self.appearance = AppearanceManager(self.root, VERSION, controls=False)
        self.root.bind('<Configure>', self._fit_registration, add='+')
        self.root.mainloop()

    def _fit_registration(self, event=None):
        if event is not None and event.widget is not self.root:
            return
        width = max(300, self.root.winfo_width() - 60)
        for widget in (self.phase, self.loading, self.status, self.note, self.details):
            try:
                widget.place_configure(width=width)
                if isinstance(widget, tk.Label):
                    widget.configure(wraplength=width)
            except Exception:
                pass

    def _center_window(self):
        self.root.update_idletasks()
        w = min(560, self.root.winfo_screenwidth() - 32)
        h = min(380, self.root.winfo_screenheight() - 48)
        x = max(0, (self.root.winfo_screenwidth() - w) // 2)
        y = max(0, (self.root.winfo_screenheight() - h) // 2)
        self.root.geometry(f'{w}x{h}+{x}+{y}')

    def _show_registration(self):
        self._center_window()
        self.root.deiconify()
        self.root.lift()

    def _set_phase(self, message, color=None, animate=True):
        self.phase.configure(text=message, fg=color or theme_color('muted'))
        self.loading_active = bool(animate)
        if not animate:
            self.loading.configure(text='✓' if message == 'AGENTE LIBERADO' else '●', fg=color or theme_color('success'))

    def _animate_waiting(self):
        if self.closed:
            return
        if self.loading_active:
            frames = ('● ○ ○', '○ ● ○', '○ ○ ●', '○ ● ○')
            self.loading.configure(text=frames[self.loading_step % len(frames)], fg=theme_color('accent'))
            self.loading_step += 1
        self.root.after(380, self._animate_waiting)

    def set_status(self, message, color=None):
        self.status.configure(text=message, fg=color or theme_color('muted'))

    def restore_saved_code(self):
        self._show_registration()
        if BUNDLED_SITE_CODE and DOWNLOAD_KEY:
            self.code = BUNDLED_SITE_CODE
            self.download_key = DOWNLOAD_KEY
            self.code_var.set(BUNDLED_SITE_CODE)
            self.busy = True
            self.button.configure(state='disabled')
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            self.set_status('Conferindo a liberação deste computador com a plataforma...', theme_color('accent'))
            threading.Thread(target=self._auto_register_worker, daemon=True).start()
        else:
            self._set_phase('INSTALAÇÃO INCOMPLETA', theme_color('danger'), animate=False)
            self.set_status('Este instalador não contém credenciais válidas. Baixe o agente novamente pela plataforma.', theme_color('danger'))

    def _auto_register_worker(self):
        try:
            response = api('agent-register', self._payload())
            self.root.after(0, lambda r=response: self._auto_registration_result(r))
        except Exception as exc:
            reason = str(exc)
            self.root.after(0, lambda err=reason: self._auto_registration_error(err))

    def _auto_registration_result(self, response):
        if self.closed:
            return
        self.busy = False
        if not isinstance(response, dict) or not response.get('ok'):
            self._auto_registration_error(str(response.get('error', 'Não foi possível validar o vínculo.')) if isinstance(response, dict) else 'Resposta inválida da plataforma.')
            return
        status = str(response.get('status', 'pending'))
        self.save_code()
        self.begin_polling()
        if status == 'approved':
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado. Iniciando o monitoramento deste computador...', theme_color('success'))
        elif status == 'pending':
            self.was_pending = True
            self._show_registration()
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            self.set_status('Aguardando liberação do gestor. Assim que a rede autorizar, o agente será iniciado automaticamente.', theme_color('warning'))
        else:
            self._show_registration()
            self._set_phase('LIBERAÇÃO INDISPONÍVEL', theme_color('danger'), animate=False)
            self.set_status(response.get('message', 'Não foi possível validar a instalação deste computador.'), theme_color('danger'))

    def request_transfer(self):
        """Solicita somente a senha do proprietário da rede anterior."""
        password = simpledialog.askstring('Transferir computador', 'Senha do gestor proprietário da rede anterior:', parent=self.root, show='*')
        if not password:
            return
        self.transfer_button.configure(state='disabled')
        def worker(owner_password):
            try:
                payload = self._payload()
                payload['previous_owner_password'] = owner_password
                result = api('agent-transfer', payload)
                self.root.after(0, lambda r=result: self._transfer_result(r))
            except Exception as exc:
                self.root.after(0, lambda msg=str(exc): self._transfer_result({'ok':False,'error':msg}))
        threading.Thread(target=worker, args=(password,), daemon=True).start()

    def _transfer_result(self, result):
        self.transfer_button.place_forget()
        self.transfer_button.configure(state='normal')
        if result.get('ok'):
            self._auto_registration_result(result)
        else:
            self._auto_registration_error(result.get('error','Não foi possível desvincular.'))

    def _auto_registration_error(self, error):
        if self.closed:
            return
        self.busy = False
        self.button.configure(state='normal')
        if self.code and self.download_key:
            self._show_registration()
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            conflict='já está vinculado a outro local' in str(error)
            if conflict:
                self._set_phase('COMPUTADOR VINCULADO',theme_color('warning'),animate=False)
                self.set_status('Este computador está associado a outra rede. Digite a senha do gestor anterior para solicitar a liberação na nova rede.',theme_color('warning'))
                self.transfer_button.place(relx=0.5,y=280,anchor='n',width=280,height=32)
                self.note.place_forget()
            else:
                self.set_status(('Falha no cadastro: ' + str(error))[:360] + ' — nova tentativa automática em instantes.', theme_color('danger'))
                self.root.after(12000, self.restore_saved_code)
        else:
            self._show_registration()
            self._set_phase('INSTALAÇÃO INCOMPLETA', theme_color('danger'), animate=False)
            self.set_status('Não foi possível reconhecer automaticamente o computador: ' + error, theme_color('danger'))

    def save_code(self):
        path = os.path.join(os.environ.get('APPDATA', os.path.expanduser('~')), 'Arcoll')
        os.makedirs(path, exist_ok=True)
        with open(os.path.join(path, 'agent.json'), 'w', encoding='utf-8') as f:
            json.dump({'fingerprint': FINGERPRINT, 'site_code': self.code, 'download_key': self.download_key, 'origin': ORIGIN, 'agent_version': VERSION}, f)

    def register(self):
        if self.busy:
            return
        code = self.code_var.get().strip().upper()
        if not code:
            self._set_phase('INSTALAÇÃO INCOMPLETA', theme_color('danger'), animate=False)
            self.set_status('O pacote não contém o vínculo desta rede. Baixe novamente o agente pela plataforma.', theme_color('danger'))
            return
        if not self.download_key:
            self._set_phase('INSTALAÇÃO INCOMPLETA', theme_color('danger'), animate=False)
            self.set_status('Chave de instalação ausente. Baixe um novo arquivo de agente pela plataforma.', theme_color('danger'))
            return
        if not __import__('re').fullmatch(r'[A-Z0-9]{5}', code):
            self._set_phase('ERRO DE INSTALAÇÃO', theme_color('danger'), animate=False)
            self.set_status('O código local deve conter exatamente 5 letras ou números maiúsculos.', theme_color('danger'))
            return
        self.busy = True
        self.button.configure(state='disabled')
        self.request_started = time.monotonic()
        self.progress_step = 0
        self.code = code
        self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
        self.set_status('Conferindo a liberação deste computador com a plataforma...', theme_color('accent'))
        self._animate_registration_progress()
        threading.Thread(target=self._register_worker, daemon=True).start()

    def _animate_registration_progress(self):
        if self.closed or not self.busy:
            return
        messages = (
            'Conferindo a instalação segura',
            'Conectando ao gestor da rede',
            'Validando a chave exclusiva',
            'Aguardando resposta da plataforma'
        )
        elapsed = int(time.monotonic() - self.request_started)
        dots = '.' * (self.progress_step % 4)
        message = messages[self.progress_step % len(messages)] + dots + f'\nTempo decorrido: {elapsed}s'
        if elapsed >= 12:
            message += '\nA conexão está demorando. O agente continuará tentando automaticamente.'
        self.set_status(message, theme_color('accent') if elapsed < 12 else theme_color('warning'))
        self.progress_step += 1
        self.root.after(650, self._animate_registration_progress)

    def _payload(self):
        disk = {}
        try:
            usage = shutil.disk_usage(os.path.expanduser('~'))
            disk = {'total_gb': round(usage.total / (1024 ** 3), 2), 'free_gb': round(usage.free / (1024 ** 3), 2)}
        except Exception:
            pass
        memory = {}
        if platform.system() == 'Windows':
            try:
                import ctypes
                class MEMORYSTATUSEX(ctypes.Structure):
                    _fields_ = [('dwLength', ctypes.c_ulong), ('dwMemoryLoad', ctypes.c_ulong), ('ullTotalPhys', ctypes.c_ulonglong), ('ullAvailPhys', ctypes.c_ulonglong), ('ullTotalPageFile', ctypes.c_ulonglong), ('ullAvailPageFile', ctypes.c_ulonglong), ('ullTotalVirtual', ctypes.c_ulonglong), ('ullAvailVirtual', ctypes.c_ulonglong), ('ullAvailExtendedVirtual', ctypes.c_ulonglong)]
                m = MEMORYSTATUSEX(); m.dwLength = ctypes.sizeof(m)
                if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m)):
                    memory = {'total_gb': round(m.ullTotalPhys / (1024 ** 3), 2), 'available_gb': round(m.ullAvailPhys / (1024 ** 3), 2), 'usage_percent': int(m.dwMemoryLoad)}
            except Exception:
                pass
        details = {'architecture': platform.machine(), 'processor': platform.processor()[:250], 'python_version': platform.python_version(), 'cpu_count': os.cpu_count(), 'memory': memory, 'system_disk': disk, 'user_home_drive': os.path.splitdrive(os.path.expanduser('~'))[0]}
        return {'fingerprint': FINGERPRINT, 'site_code': self.code, 'download_key': self.download_key, 'machine_name': platform.node(), 'platform': windows_version(), 'local_ip': local_ip(), 'gateway_ip': gateway_ip(), 'version': VERSION, 'machine_details': details}

    def _register_worker(self):
        try:
            result = api('agent-register', self._payload())
            self.root.after(0, lambda: self._registered(result))
        except Exception as e:
            self.root.after(0, lambda: self._registration_error(str(e)))

    def _registered(self, result):
        self.busy = False
        self.button.configure(state='normal')
        if not result.get('ok'):
            self._set_phase('ERRO DE INSTALAÇÃO', theme_color('danger'), animate=False)
            self.set_status(result.get('error', 'Não foi possível cadastrar o agente.'), theme_color('danger'))
            return
        self.save_code()
        if result.get('status') == 'approved':
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado. O monitoramento será iniciado agora.', theme_color('success'))
        else:
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            self.set_status('A instalação foi concluída e este computador está aguardando liberação do gestor.', theme_color('warning'))
        self.begin_polling()

    def _registration_error(self, message):
        self.busy = False
        self.button.configure(state='normal')
        self._set_phase('ERRO DE COMUNICAÇÃO', theme_color('danger'), animate=False)
        self.set_status('Não foi possível validar esta instalação agora. ' + message, theme_color('danger'))

    def begin_polling(self, register_again=False):
        if not self.code:
            return
        self.button.configure(state='disabled')
        if register_again:
            threading.Thread(target=self._register_saved_worker, daemon=True).start()
        else:
            self.root.after(200, self.poll_status)

    def _register_saved_worker(self):
        try:
            result = api('agent-register', self._payload())
            self.root.after(0, lambda: self._registered(result))
        except Exception:
            self.root.after(0, lambda: (self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True), self.set_status('Ainda estou aguardando a plataforma responder para validar a liberação.', theme_color('warning'))))
            self.root.after(2500, self.poll_status)

    def poll_status(self):
        if self.closed or not self.code:
            return
        threading.Thread(target=self._poll_worker, daemon=True).start()

    def _poll_worker(self):
        try:
            result = api('agent-poll', {'fingerprint': FINGERPRINT, 'site_code': self.code, 'download_key': self.download_key})
        except Exception:
            self.root.after(0, lambda: (self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True), self.set_status('Sem conexão com a plataforma agora. Vou tentar novamente automaticamente.', theme_color('warning'))))
            self.root.after(2500, self.poll_status)
            return
        self.root.after(0, lambda: self._apply_status(result))

    def _apply_status(self, result):
        state = result.get('status', 'pending')
        if state == 'approved':
            if self.was_pending and not self.approval_countdown:
                self.approval_countdown = True
                self._start_approval_countdown(3)
                return
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado. O monitoramento já está sendo iniciado neste computador.', theme_color('success'))
            self.start_local_agent()
            if not self.app_closed_by_user and not self.app_launch_pending and not (self.app_proc and self.app_proc.poll() is None):
                self.app_launch_pending = True
                self.root.after(1800, self.launch_full_app)
            self.root.after(20000, self.poll_status)
        elif state == 'pending':
            self.was_pending = True
            self._show_registration()
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            self.set_status('Aguardando liberação do gestor. Assim que for aprovado, o agente será ativado automaticamente.', theme_color('warning'))
            self.root.after(1500, self.poll_status)
        elif state in ('blocked', 'rejected'):
            self.stop_full_app()
            self.stop_local_agent()
            try:
                self._show_registration()
            except Exception:
                pass
            self._set_phase('ACESSO BLOQUEADO', theme_color('danger'), animate=False)
            self.set_status('Este computador está bloqueado pelo gestor da rede. Entre em contato com o responsável para liberar novamente.', theme_color('danger'))
            self.root.after(20000, self.poll_status)
        else:
            self._show_registration()
            self._set_phase('AGUARDANDO LIBERAÇÃO', theme_color('warning'), animate=True)
            self.set_status(result.get('error', 'A instalação foi concluída e o agente está aguardando liberação na plataforma.'), theme_color('warning'))
            self.button.configure(state='normal')

    def _start_approval_countdown(self, remaining):
        if self.closed:
            return
        if remaining > 0:
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status(f'Agente liberado. Abrindo em {remaining} segundos...', theme_color('success'))
            self.root.after(1000, lambda: self._start_approval_countdown(remaining-1))
            return
        self.was_pending = False
        self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
        self.start_local_agent()
        self.app_launch_pending = True
        self.root.after(250, self.launch_full_app)
        self.root.after(20000, self.poll_status)

    def start_local_agent(self):
        if self.proc and self.proc.poll() is None:
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado. O monitoramento deste computador está ativo.', theme_color('success'))
            return
        script = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'arcoll_agent.py')
        py = sys.executable
        if platform.system() == 'Windows' and os.path.basename(py).lower() == 'pythonw.exe':
            candidate = os.path.join(os.path.dirname(py), 'python.exe')
            if os.path.isfile(candidate):
                py = candidate
        log_dir = os.path.join(os.environ.get('LOCALAPPDATA', os.path.expanduser('~')), 'Arcoll')
        os.makedirs(log_dir, exist_ok=True)
        log_path = os.path.join(log_dir, 'agent-runtime.log')
        try:
            if not os.path.isfile(script):
                raise RuntimeError('O arquivo arcoll_agent.py não está no pacote instalado.')
            self._agent_log = open(log_path, 'a', encoding='utf-8', buffering=1)
            self._agent_log.write('\n[%s] Iniciando serviço local: %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), script))
            flags = subprocess.CREATE_NO_WINDOW if platform.system() == 'Windows' else 0
            self.proc = subprocess.Popen([py, script, '--origin', ORIGIN], stdout=self._agent_log, stderr=subprocess.STDOUT, creationflags=flags, cwd=os.path.dirname(script))
            self.root.after(1800, lambda: self._verify_local_agent(log_path))
        except Exception:
            self._set_phase('ERRO NA INICIALIZAÇÃO', theme_color('danger'), animate=False)
            self.set_status('O agente foi liberado, mas o monitoramento local não iniciou corretamente. Consulte o log em ' + log_path, theme_color('danger'))

    def launch_full_app(self):
        self.app_launch_pending = False
        if self.closed:
            return
        if focus_running_app():
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('O agente já está em execução. Você pode abrir a interface pelo ícone da bandeja do Windows.', theme_color('success'))
            try:
                self.root.withdraw()
            except Exception:
                pass
            return
        if self.app_proc and self.app_proc.poll() is None:
            try:
                self.root.withdraw()
            except Exception:
                pass
            return
        script = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'arcoll_app.py')
        log_dir = os.path.join(os.environ.get('LOCALAPPDATA', os.path.expanduser('~')), 'Arcoll')
        os.makedirs(log_dir, exist_ok=True)
        log_path = os.path.join(log_dir, 'agent-runtime.log')
        try:
            if not os.path.isfile(script):
                raise RuntimeError('O aplicativo desktop completo não está no pacote. Baixe novamente o agente.')
            py = sys.executable
            flags = subprocess.CREATE_NO_WINDOW if platform.system() == 'Windows' else 0
            with open(log_path, 'a', encoding='utf-8', buffering=1) as log_file:
                log_file.write('\n[%s] Abrindo aplicativo desktop completo: %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), script))
                self.app_proc = subprocess.Popen([py, script, '--origin', ORIGIN, '--background'], stdout=log_file, stderr=subprocess.STDOUT, creationflags=flags, cwd=os.path.dirname(script))
            self.root.after(1800, self._verify_full_app)
        except Exception:
            self.app_launch_pending = False
            self._set_phase('ERRO NA INTERFACE', theme_color('danger'), animate=False)
            self.set_status('Não foi possível iniciar a interface do agente. Consulte %LOCALAPPDATA%\\Arcoll\\agent-runtime.log.', theme_color('danger'))
            try:
                self._show_registration()
            except Exception:
                pass

    def _verify_full_app(self):
        if self.closed:
            return
        if (self.app_proc and self.app_proc.poll() is None) or focus_running_app():
            self.app_launch_pending = False
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado e em execução. A interface está disponível no ícone da bandeja do Windows.', theme_color('success'))
            try:
                self.root.withdraw()
            except Exception:
                pass
            if self.app_proc and self.app_proc.poll() is None:
                self.root.after(3000, self.check_full_app)
            return
        self.root.after(3500, self._confirm_full_app_failure)

    def _confirm_full_app_failure(self):
        if self.closed:
            return
        if (self.app_proc and self.app_proc.poll() is None) or focus_running_app():
            self.app_launch_pending = False
            try:
                self.root.withdraw()
            except Exception:
                pass
            if self.app_proc and self.app_proc.poll() is None:
                self.root.after(3000, self.check_full_app)
            return
        self.app_launch_pending = False
        try:
            self._show_registration()
        except Exception:
            pass
        self._set_phase('ERRO NA INTERFACE', theme_color('danger'), animate=False)
        self.set_status('Não foi possível iniciar a interface do agente. Consulte %LOCALAPPDATA%\\Arcoll\\agent-runtime.log.', theme_color('danger'))

    def check_full_app(self):
        if self.closed:
            return
        if self.app_proc and self.app_proc.poll() is None:
            self.root.after(3000, self.check_full_app)
            return
        self.app_proc = None
        self.app_closed_by_user = True
        self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
        self.set_status('O painel foi fechado, mas o agente continua liberado neste computador.', theme_color('warning'))

    def stop_full_app(self):
        if self.app_proc and self.app_proc.poll() is None:
            try:
                self.app_proc.terminate()
            except Exception:
                pass
        self.app_proc = None
        self.app_launch_pending = False

    def _verify_local_agent(self, log_path):
        if self.closed:
            return
        if self.proc and self.proc.poll() is None:
            self._set_phase('AGENTE LIBERADO', theme_color('success'), animate=False)
            self.set_status('Agente liberado. O monitoramento local já está ativo neste computador.', theme_color('success'))
        else:
            self._set_phase('ERRO NO MONITORAMENTO', theme_color('danger'), animate=False)
            self.set_status('O agente foi liberado, mas o monitoramento local foi encerrado. Consulte o log em ' + log_path, theme_color('danger'))

    def stop_local_agent(self):
        if self.proc and self.proc.poll() is None:
            try:
                self.proc.terminate()
            except Exception:
                pass
        self.proc = None

    def close(self):
        self.closed = True
        self.stop_full_app()
        self.stop_local_agent()
        try:
            if self._agent_log:
                self._agent_log.close()
        except Exception:
            pass
        self.root.destroy()


if __name__ == '__main__':
    if not ORIGIN:
        tk.Tk().withdraw()
        messagebox.showerror('Arcoll', 'O endereço HTTPS do Arcoll não foi configurado. Baixe o instalador novamente pelo sistema.')
        sys.exit(2)
    DesktopAgent()
