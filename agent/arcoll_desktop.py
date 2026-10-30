#!/usr/bin/env python3
"""Arcoll Speed Connection Desktop Agent: registration and approval gate."""
import argparse, hashlib, json, os, platform, socket, subprocess, sys, threading, time, urllib.error, urllib.request, shutil
import tkinter as tk
from tkinter import messagebox
from arcoll_toolkit import AppearanceManager, theme_color, bind_theme, bind_canvas

VERSION = '2.8.1'
parser = argparse.ArgumentParser()
parser.add_argument('--origin', default=os.environ.get('ARCOLL_ORIGIN', '').strip())
parser.add_argument('--site-code', default=os.environ.get('ARCOLL_SITE_CODE', '').strip().upper())
parser.add_argument('--download-key', default=os.environ.get('ARCOLL_DOWNLOAD_KEY', '').strip())
parser.add_argument('--background',action='store_true')
args = parser.parse_args()
ORIGIN = args.origin.rstrip('/')
BUNDLED_SITE_CODE = args.site_code
DOWNLOAD_KEY = args.download_key
try:
    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'agent-config.json'), 'r', encoding='utf-8') as _cfg_file:
        _cfg = json.load(_cfg_file)
    ORIGIN = str(_cfg.get('origin') or ORIGIN).rstrip('/')
    BUNDLED_SITE_CODE = str(_cfg.get('site_code') or BUNDLED_SITE_CODE).strip().upper()
    DOWNLOAD_KEY = str(_cfg.get('download_key') or DOWNLOAD_KEY).strip()
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
            sock.connect(('10.255.255.255', 1)); return sock.getsockname()[0]
    except Exception:
        return ''


def gateway_ip():
    if platform.system() == 'Windows':
        try:
            out = subprocess.run(['ipconfig'], capture_output=True, text=True, timeout=3, errors='ignore', creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0)).stdout
            import re
            m = re.search(r'(?:Gateway Padrão|Default Gateway)[ .:]*([0-9.]+)', out, re.I)
            return m.group(1) if m else ''
        except Exception: return ''
    try:
        out = subprocess.run(['ip','route','show','default'], capture_output=True, text=True, timeout=3).stdout
        import re
        m = re.search(r'default via (\S+)', out)
        return m.group(1) if m else ''
    except Exception: return ''


FINGERPRINT = machine_fingerprint()
INSTANCE_HOST = '127.0.0.1'
INSTANCE_PORT = 38000 + (int(FINGERPRINT[:6], 16) % 2000)

def focus_running_app():
    try:
        with socket.create_connection((INSTANCE_HOST, INSTANCE_PORT), timeout=0.7) as client:
            client.sendall(b'SHOW')
        return True
    except OSError:
        return False


def api(action, data, timeout=20):
    if not ORIGIN: raise RuntimeError('Endereço HTTPS do Arcoll não foi configurado no instalador.')
    url = ORIGIN + '/api/api.php?action=' + action
    req = urllib.request.Request(url, data=json.dumps(data).encode(), headers={'Content-Type':'application/json','User-Agent':'ArcollDesktopAgent/'+VERSION}, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=timeout) as res:
            return json.loads(res.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        try: body = json.loads(e.read().decode('utf-8'))
        except Exception: body = {}
        raise RuntimeError(body.get('error') or ('Servidor respondeu HTTP '+str(e.code)))
    except urllib.error.URLError as e:
        reason = getattr(e, 'reason', e)
        raise RuntimeError('Não foi possível conectar ao servidor HTTPS: '+str(reason))
    except TimeoutError:
        raise RuntimeError('Tempo limite excedido ao aguardar o servidor. Confira a internet e tente novamente.')


class DesktopAgent:
    def __init__(self):
        self.root = tk.Tk()
        self.root.withdraw()  # Nunca mostrar cadastro antes de a plataforma confirmar a autorização.
        self.root.title('Arcoll Speed Connection | Agente de Rede')
        self.root.geometry('560x430'); self.root.resizable(True, True); self.root.minsize(360,400)
        self._center_window()
        bind_theme(self.root, background='surface').configure(bg=theme_color('surface'))
        icon_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'favicon.ico')
        if os.path.isfile(icon_path):
            try: self.root.iconbitmap(default=icon_path)
            except Exception: pass
        self.proc = None; self.app_proc = None; self.code = BUNDLED_SITE_CODE; self.download_key = DOWNLOAD_KEY; self.closed = False; self.busy = False; self.progress_step = 0; self.request_started = 0.0; self.app_launch_pending = False
        self.app_closed_by_user = False
        bind_theme(tk.Label(self.root, text='ARCOLL', font=('Segoe UI', 11, 'bold'), fg=theme_color('accent'), bg=theme_color('surface')), foreground='accent', background='surface').place(x=28, y=22)
        bind_theme(tk.Label(self.root, text='Agente de Rede', font=('Segoe UI', 22, 'bold'), fg=theme_color('text'), bg=theme_color('surface')), foreground='text', background='surface').place(x=28, y=52)
        bind_theme(tk.Label(self.root, text='Cadastro e autorização deste computador', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface')), foreground='muted', background='surface').place(x=30, y=91)
        bind_theme(tk.Label(self.root, text='Código do local', font=('Segoe UI', 10, 'bold'), fg=theme_color('text'), bg=theme_color('surface')), foreground='text', background='surface').place(x=30, y=133)
        self.code_var = tk.StringVar()
        self.entry = bind_theme(tk.Entry(self.root, textvariable=self.code_var, font=('Segoe UI', 15), bg=theme_color('alt'), fg=theme_color('text'), insertbackground=theme_color('text'), relief='flat'), background='alt', foreground='text', insertbackground='text')
        self.entry.place(x=30,y=158,width=500,height=42)
        self.entry.bind('<Return>',lambda _e:self.register())
        self.button = bind_theme(tk.Button(self.root, text='Cadastrar este computador', command=self.register, font=('Segoe UI', 10, 'bold'), bg=theme_color('accent'), fg=theme_color('text'), relief='flat', cursor='hand2'), background='accent', foreground='text')
        self.button.place(x=30,y=215,width=230,height=38)
        self.status = bind_theme(tk.Label(self.root, text='Informe o código de local fornecido pelo gestor da rede.', font=('Segoe UI', 10), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w', wraplength=500, justify='left'), foreground='muted', background='surface')
        self.status.place(x=30,y=270,width=500,height=48)
        self.details = bind_theme(tk.Label(self.root, text='Computador: ' + platform.node() + '   |   IP local: ' + (local_ip() or 'indisponível'), font=('Segoe UI', 8), fg=theme_color('muted'), bg=theme_color('surface'), anchor='w'), foreground='muted', background='surface')
        self.details.place(x=30,y=335,width=500,height=24)
        self.root.protocol('WM_DELETE_WINDOW',self.close)
        if BUNDLED_SITE_CODE: self.code_var.set(BUNDLED_SITE_CODE)
        self.root.after(100,self.restore_saved_code)
        self.appearance=AppearanceManager(self.root,VERSION)
        self.root.bind('<Configure>',self._fit_registration,add='+')
        self.root.mainloop()

    def _fit_registration(self,event=None):
        if event is not None and event.widget is not self.root:return
        width=max(300,self.root.winfo_width()-60)
        for widget in self.root.winfo_children():
            if widget.winfo_manager()!='place':continue
            data=widget.place_info()
            if int(float(data.get('width',0) or 0))>=400 or widget.winfo_reqwidth()>width:
                widget.place_configure(width=width)
                if isinstance(widget,tk.Label):widget.configure(wraplength=width)

    def _center_window(self):
        self.root.update_idletasks()
        w,h=min(560,self.root.winfo_screenwidth()-32),min(430,self.root.winfo_screenheight()-48)
        x=max(0,(self.root.winfo_screenwidth()-w)//2)
        y=max(0,(self.root.winfo_screenheight()-h)//2)
        self.root.geometry(f'{w}x{h}+{x}+{y}')

    def _show_registration(self):
        self._center_window()
        self.root.deiconify()
        self.root.lift()

    def set_status(self, message, color=None):
        self.status.configure(text=message, fg=color or theme_color('muted'))

    def restore_saved_code(self):
        """Autoauthenticate using the fresh network installer and stable machine ID.

        The server is the only authority for approval; a local file never grants access.
        """
        if BUNDLED_SITE_CODE and DOWNLOAD_KEY:
            self.code = BUNDLED_SITE_CODE
            self.download_key = DOWNLOAD_KEY
            self.code_var.set(BUNDLED_SITE_CODE)
            self.busy = True
            self.button.configure(state='disabled')
            self.set_status('Verificando vínculo existente com a rede...', theme_color('accent'))
            self.root.withdraw()  # Do not flash the registration gate for approved installations.
            threading.Thread(target=self._auto_register_worker, daemon=True).start()
        else:
            self._show_registration()
            self.set_status('O pacote não contém credenciais válidas. Baixe o agente novamente pela plataforma.', theme_color('danger'))

    def _auto_register_worker(self):
        try:
            response = api('agent-register', self._payload())
            self.root.after(0, lambda r=response: self._auto_registration_result(r))
        except Exception as exc:
            reason = str(exc)
            self.root.after(0, lambda err=reason: self._auto_registration_error(err))

    def _auto_registration_result(self, response):
        if self.closed: return
        self.busy = False
        if not isinstance(response, dict) or not response.get('ok'):
            self._auto_registration_error(str(response.get('error', 'Não foi possível validar o vínculo.')) if isinstance(response, dict) else 'Resposta inválida da plataforma.')
            return
        status = str(response.get('status', 'pending'))
        self.save_code()
        self.begin_polling()
        if status == 'approved':
            self.set_status('Computador aprovado anteriormente. Abrindo o agente...', theme_color('success'))
            # Revalidate through agent-poll before launching; no implicit approval.
        else:
            self._show_registration()
            self.set_status(response.get('message', 'Aguardando aprovação do gestor.'), theme_color('warning') if status == 'pending' else theme_color('danger'))

    def _auto_registration_error(self, error):
        if self.closed: return
        self.busy = False
        self.button.configure(state='normal')
        # Falha temporária de comunicação não cancela uma aprovação já concedida.
        # A janela só é mostrada se for preciso corrigir o cadastro.
        if self.code and self.download_key:
            self.set_status('Aguardando comunicação com a plataforma para validar o vínculo.', theme_color('warning'))
            self.root.after(12000, self.restore_saved_code)
        else:
            self._show_registration()
            self.set_status('Não foi possível reconhecer automaticamente o computador: ' + error, theme_color('danger'))

    def save_code(self):
        path = os.path.join(os.environ.get('APPDATA',os.path.expanduser('~')), 'Arcoll')
        os.makedirs(path,exist_ok=True)
        with open(os.path.join(path,'agent.json'),'w',encoding='utf-8') as f:
            json.dump({'fingerprint':FINGERPRINT,'site_code':self.code,'download_key':self.download_key,'origin':ORIGIN,'agent_version':VERSION},f)

    def register(self):
        if self.busy: return
        code = self.code_var.get().strip().upper()
        if not code:
            self.set_status('O pacote não contém código local. Baixe novamente o agente pela página da rede.', theme_color('warning')); self.entry.focus_set(); return
        if not self.download_key:
            self.set_status('Chave de instalação ausente. Baixe um novo arquivo de agente pela página da rede.', theme_color('danger')); return
        if not __import__('re').fullmatch(r'[A-Z0-9]{5}', code):
            self.set_status('O código local deve conter exatamente 5 letras ou números maiúsculos.', theme_color('danger')); return
        self.busy=True; self.button.configure(state='disabled'); self.request_started=time.monotonic(); self.progress_step=0
        self.code=code
        self.set_status('Preparando solicitação segura…', theme_color('accent'))
        self._animate_registration_progress()
        threading.Thread(target=self._register_worker,daemon=True).start()

    def _animate_registration_progress(self):
        if self.closed or not self.busy: return
        messages = ('Preparando solicitação segura', 'Conectando ao gestor da rede', 'Validando chave exclusiva', 'Registrando este computador')
        elapsed = int(time.monotonic() - self.request_started)
        dots = '.' * (self.progress_step % 4)
        message = messages[self.progress_step % len(messages)] + dots + f'\nAguarde; tempo decorrido: {elapsed}s'
        if elapsed >= 12:
            message += '\nA conexão está demorando. O agente exibirá o erro se o servidor não responder.'
        self.set_status(message, theme_color('accent') if elapsed < 12 else theme_color('warning'))
        self.progress_step += 1
        self.root.after(650, self._animate_registration_progress)

    def _payload(self):
        disk = {}
        try:
            usage = shutil.disk_usage(os.path.expanduser('~'))
            disk = {'total_gb': round(usage.total / (1024**3), 2), 'free_gb': round(usage.free / (1024**3), 2)}
        except Exception: pass
        memory = {}
        if platform.system() == 'Windows':
            try:
                import ctypes
                class MEMORYSTATUSEX(ctypes.Structure):
                    _fields_=[('dwLength',ctypes.c_ulong),('dwMemoryLoad',ctypes.c_ulong),('ullTotalPhys',ctypes.c_ulonglong),('ullAvailPhys',ctypes.c_ulonglong),('ullTotalPageFile',ctypes.c_ulonglong),('ullAvailPageFile',ctypes.c_ulonglong),('ullTotalVirtual',ctypes.c_ulonglong),('ullAvailVirtual',ctypes.c_ulonglong),('ullAvailExtendedVirtual',ctypes.c_ulonglong)]
                m=MEMORYSTATUSEX(); m.dwLength=ctypes.sizeof(m)
                if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m)):
                    memory={'total_gb':round(m.ullTotalPhys/(1024**3),2),'available_gb':round(m.ullAvailPhys/(1024**3),2),'usage_percent':int(m.dwMemoryLoad)}
            except Exception: pass
        details={'architecture':platform.machine(),'processor':platform.processor()[:250],'python_version':platform.python_version(),'cpu_count':os.cpu_count(),'memory':memory,'system_disk':disk,'user_home_drive':os.path.splitdrive(os.path.expanduser('~'))[0]}
        return {'fingerprint':FINGERPRINT,'site_code':self.code,'download_key':self.download_key,'machine_name':platform.node(),'platform':platform.platform(),'local_ip':local_ip(),'gateway_ip':gateway_ip(),'version':VERSION,'machine_details':details}

    def _register_worker(self):
        try:
            result=api('agent-register',self._payload())
            self.root.after(0,lambda:self._registered(result))
        except Exception as e:
            self.root.after(0,lambda:self._registration_error(str(e)))

    def _registered(self,result):
        self.busy=False; self.button.configure(state='normal')
        if not result.get('ok'):
            self.set_status(result.get('error', 'Não foi possível cadastrar o agente.'), theme_color('danger')); return
        self.save_code()
        self.set_status(result.get('message', 'Solicitação enviada.'), theme_color('success') if result.get('status') == 'approved' else theme_color('warning'))
        self.begin_polling()

    def _registration_error(self,message):
        self.busy=False; self.button.configure(state='normal'); self.set_status('FALHA NO CADASTRO\n' + message + '\nConfira a conexão e tente novamente. Se persistir, envie esta mensagem ao suporte.', theme_color('danger'))

    def begin_polling(self,register_again=False):
        if not self.code: return
        self.entry.configure(state='disabled'); self.button.configure(state='disabled')
        if register_again:
            threading.Thread(target=self._register_saved_worker,daemon=True).start()
        else:
            self.root.after(200,self.poll_status)

    def _register_saved_worker(self):
        try:
            result=api('agent-register',self._payload())
            self.root.after(0,lambda:self._registered(result))
        except Exception as e:
            self.root.after(0, lambda: self.set_status('Não foi possível validar a autorização: ' + str(e), theme_color('danger')))
            self.root.after(15000,self.poll_status)

    def poll_status(self):
        if self.closed or not self.code: return
        threading.Thread(target=self._poll_worker,daemon=True).start()

    def _poll_worker(self):
        try: result=api('agent-poll',{'fingerprint':FINGERPRINT,'site_code':self.code})
        except Exception as e:
            self.root.after(0, lambda: self.set_status('Sem conexão com o servidor. Nova tentativa automática. ' + str(e), theme_color('warning')))
            self.root.after(15000,self.poll_status); return
        self.root.after(0,lambda:self._apply_status(result))

    def _apply_status(self,result):
        state=result.get('status','pending')
        if state=='approved':
            self.set_status('AGENTE AUTORIZADO · ' + result.get('network_name', 'Rede registrada') + '\nMonitoramento local ativo. O acesso permanece vinculado a este computador.', theme_color('success'))
            self.start_local_agent()
            if not self.app_closed_by_user and not self.app_launch_pending and not (self.app_proc and self.app_proc.poll() is None):
                self.app_launch_pending = True
                self.root.after(2500, self.launch_full_app)
            self.root.after(20000,self.poll_status)
        elif state=='pending':
            self._show_registration()
            self.set_status('CADASTRO PENDENTE\nO agente só será ativado depois que o gestor aprovar esta solicitação.', theme_color('warning'))
            self.root.after(10000,self.poll_status)
        elif state in ('blocked','rejected'):
            self.stop_full_app()
            self.stop_local_agent()
            try: self._show_registration()
            except Exception: pass
            self.set_status('ACESSO ' + ('BLOQUEADO' if state == 'blocked' else 'REJEITADO') + ' PELO GESTOR\nEste agente não pode ser utilizado. Entre em contato com o responsável pela rede.', theme_color('danger'))
            self.root.after(20000,self.poll_status)
        else:
            self._show_registration()
            self.set_status(result.get('error', 'Agente não cadastrado. Digite novamente o código do local.'), theme_color('danger'))
            self.entry.configure(state='normal'); self.button.configure(state='normal')

    def start_local_agent(self):
        if self.proc and self.proc.poll() is None:
            self.set_status('AGENTE AUTORIZADO E EM EXECUÇÃO\nMonitoramento local ativo para ' + platform.node() + '.', theme_color('success'))
            return
        script=os.path.join(os.path.dirname(os.path.abspath(__file__)),'arcoll_agent.py')
        py=sys.executable
        if platform.system() == 'Windows' and os.path.basename(py).lower() == 'pythonw.exe':
            candidate=os.path.join(os.path.dirname(py),'python.exe')
            if os.path.isfile(candidate): py=candidate
        log_dir=os.path.join(os.environ.get('LOCALAPPDATA',os.path.expanduser('~')),'Arcoll')
        os.makedirs(log_dir,exist_ok=True)
        log_path=os.path.join(log_dir,'agent-runtime.log')
        try:
            if not os.path.isfile(script): raise RuntimeError('O arquivo arcoll_agent.py não está no pacote instalado.')
            self._agent_log=open(log_path,'a',encoding='utf-8',buffering=1)
            self._agent_log.write('\n[%s] Iniciando serviço local: %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'),script))
            flags=subprocess.CREATE_NO_WINDOW if platform.system()=='Windows' else 0
            self.proc=subprocess.Popen([py,script,'--origin',ORIGIN],stdout=self._agent_log,stderr=subprocess.STDOUT,creationflags=flags,cwd=os.path.dirname(script))
            self.root.after(1800,lambda:self._verify_local_agent(log_path))
        except Exception as e:
            self.set_status('AGENTE AUTORIZADO, MAS O SERVIÇO NÃO INICIOU\n' + str(e) + '\nLog: ' + log_path, theme_color('danger'))

    def launch_full_app(self):
        self.app_launch_pending = False
        if self.closed:
            return
        if focus_running_app():
            self.set_status('APLICATIVO ARCOLL JÁ ESTÁ ABERTO\nA janela existente foi trazida para frente.', theme_color('success'))
            try: self.root.withdraw()
            except Exception: pass
            return
        if self.app_proc and self.app_proc.poll() is None:
            try: self.root.withdraw()
            except Exception: pass
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
                # O aplicativo possui sua própria interface Tk e lê a configuração vinculada salva em agent.json.
                self.app_proc = subprocess.Popen([py, script, '--origin', ORIGIN]+(['--background'] if args.background else []), stdout=log_file, stderr=subprocess.STDOUT,
                    creationflags=flags, cwd=os.path.dirname(script))
            self.root.after(1200, self._verify_full_app)
        except Exception as e:
            self.app_launch_pending = False
            self.set_status('AGENTE AUTORIZADO, MAS O APLICATIVO COMPLETO NÃO ABRIU\n' + str(e) + '\nLog: ' + log_path, theme_color('danger'))
            try: self._show_registration()
            except Exception: pass

    def _verify_full_app(self):
        if self.closed:
            return
        if self.app_proc and self.app_proc.poll() is None:
            self.set_status('APLICATIVO ARCOLL ABERTO\nMonitoramento local ativo para ' + platform.node() + '.', theme_color('success'))
            try: self.root.withdraw()
            except Exception: pass
            self.root.after(3000, self.check_full_app)
        else:
            self.app_launch_pending = False
            try: self._show_registration()
            except Exception: pass
            self.set_status('O aplicativo desktop encerrou durante a inicialização. Consulte %LOCALAPPDATA%\\Arcoll\\agent-runtime.log.', theme_color('danger'))

    def check_full_app(self):
        if self.closed:
            return
        if self.app_proc and self.app_proc.poll() is None:
            self.root.after(3000, self.check_full_app)
            return
        self.app_proc = None
        self.app_closed_by_user = True
        # Interface principal fechada não é motivo para reexibir o cadastro.
        self.set_status('Aplicativo principal encerrado; autorização permanece válida.', theme_color('warning'))

    def stop_full_app(self):
        if self.app_proc and self.app_proc.poll() is None:
            try: self.app_proc.terminate()
            except Exception: pass
        self.app_proc = None
        self.app_launch_pending = False

    def _verify_local_agent(self,log_path):
        if self.closed: return
        if self.proc and self.proc.poll() is None:
            self.set_status('AGENTE AUTORIZADO E EM EXECUÇÃO\nMonitoramento local ativo para ' + platform.node() + '.\nServiço local iniciado com sucesso.', theme_color('success'))
        else:
            try:
                with open(log_path,'r',encoding='utf-8',errors='replace') as f: tail=f.read()[-900:]
            except Exception: tail='Consulte o log do agente.'
            self.set_status('AUTORIZADO, MAS O SERVIÇO LOCAL FOI ENCERRADO\n' + tail + '\nLog: ' + log_path, theme_color('danger'))

    def stop_local_agent(self):
        if self.proc and self.proc.poll() is None:
            try: self.proc.terminate()
            except Exception: pass
        self.proc=None

    def close(self):
        self.closed=True; self.stop_full_app(); self.stop_local_agent(); self.root.destroy()

if __name__=='__main__':
    if not ORIGIN:
        tk.Tk().withdraw(); messagebox.showerror('Arcoll','O endereço HTTPS do Arcoll não foi configurado. Baixe o instalador novamente pelo sistema.'); sys.exit(2)
    DesktopAgent()
