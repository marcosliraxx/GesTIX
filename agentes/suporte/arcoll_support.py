#!/usr/bin/env python3
# Caminho: agentes/suporte/arcoll_support.py
"""Native technician viewer. Session tokens stay in memory and are scoped server-side."""
import http.client
import io
import json
import queue
import ssl
import threading
import time
import tkinter as tk
from tkinter import ttk, messagebox
from urllib.parse import urlsplit
from PIL import Image, ImageTk
from arcoll_toolkit import AppearanceManager, bind_theme, theme_color, friendly_error, apply_platform_design, apply_platform_icon

import sys
from pathlib import Path
if not (Path(__file__).resolve().parent/'arcoll_release.py').is_file():sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'windows'))
from arcoll_release import VERSION

class RemoteAPI:
    def __init__(self, origin):
        url=urlsplit(origin.strip().rstrip('/'))
        if url.scheme!='https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.port not in (None,443):
            raise ValueError('Informe a URL HTTPS da plataforma, sem usuário, senha ou parâmetros.')
        self.host=url.hostname;self.prefix=url.path.rstrip('/');self.local=threading.local();self.token='';self.command=0
    def request(self, path, data=None, etag=''):
        connection=getattr(self.local,'connection',None)
        if connection is None:
            connection=http.client.HTTPSConnection(self.host,443,context=ssl.create_default_context(),timeout=5)
            self.local.connection=connection
        headers={'Accept':'application/json','User-Agent':'Arcoll-Suporte/'+VERSION}
        if self.token:headers['Authorization']='Bearer '+self.token
        if etag:headers['If-None-Match']=etag
        raw=json.dumps(data).encode() if data is not None else None
        if raw is not None:headers['Content-Type']='application/json'
        try:
            connection.request('POST' if raw is not None else 'GET',self.prefix+path,body=raw,headers=headers)
            response=connection.getresponse();body=response.read(700001)
            if len(body)>700000:raise RuntimeError('Resposta acima do limite.')
            if response.status==304:return None,etag
            if response.status>=400:
                if response.status==404 and 'action=image' in path:return None,etag
                try:message=json.loads(body).get('error','Conexão indisponível.')
                except (ValueError,AttributeError):message='Resposta HTTP '+str(response.status)
                raise RuntimeError(message)
            if response.status!=200:raise RuntimeError('Resposta inesperada do servidor.')
            if 'image/jpeg' in response.getheader('Content-Type',''):return body,response.getheader('ETag','')
            value=json.loads(body)
            if not value.get('ok'):raise RuntimeError(value.get('error','Solicitação recusada.'))
            return value,''
        except Exception:
            connection.close();self.local.connection=None;raise
    def remote(self, action, data=None, etag=''):
        return self.request('/api/remote-screen.php?command_id='+str(self.command)+'&action='+action,data,etag)

class SupportApp:
    def __init__(self, root):
        self.account_api=None;self.selected_computer=None;self.zoom=1.0;self.design={}
        try:
            from pathlib import Path
            self.design=json.loads(Path(__file__).with_name('platform.json').read_text(encoding='utf-8')).get('design',{})
        except (OSError,ValueError,AttributeError):pass
        apply_platform_design(self.design)
        self.root=root;self.api=None;self.stop=threading.Event();self.results=queue.Queue(maxsize=3);self.input_queue=queue.Queue(maxsize=150)
        self.control=False;self.frame=None;self.photo=None;self.image_box=(0,0,1,1);self.pending_move=None;self.closed=False;self.pressed=set();self.buttons=set()
        root.title('Arcoll Suporte · '+VERSION);root.geometry('1100x740');root.minsize(820,520)
        bind_theme(root,background='bg').configure(bg=theme_color('bg'))
        top=bind_theme(tk.Frame(root,bg=theme_color('surface')),background='surface');top.pack(fill='x',padx=10,pady=8)
        self.origin=tk.StringVar();self.code=tk.StringVar();self.status=tk.StringVar(value='Use o código de 5 caracteres mostrado pelo agente, ou entre para acessar suas redes.')
        try:
            from pathlib import Path
            config=json.loads(Path(__file__).with_name('platform.json').read_text(encoding='utf-8'));self.origin.set(config.get('origin',''))
        except (OSError,ValueError,AttributeError):pass
        for text,var,width in [('Plataforma HTTPS',self.origin,42),('Código do agente',self.code,10)]:
            block=tk.Frame(top);block.pack(side='left',fill='x',expand=True,padx=5,pady=5)
            bind_theme(block,background='surface').configure(bg=theme_color('surface'))
            bind_theme(tk.Label(block,text=text,bg=theme_color('surface'),fg=theme_color('muted')),background='surface',foreground='muted').pack(anchor='w')
            ttk.Entry(block,textvariable=var,width=width).pack(fill='x')
        self.code.trace_add('write',lambda *_:setattr(self,'selected_computer',None) if self.code.get() else None)
        self.connect=ttk.Button(top,text='Conectar',command=self.start);self.connect.pack(side='left',padx=6)
        ttk.Button(top,text='Encerrar',command=self.disconnect).pack(side='left',padx=6)
        account=ttk.Frame(root);account.pack(fill='x',padx=15,pady=3);self.account_button=ttk.Button(account,text='Entrar na conta (opcional)',command=self.account_login);self.account_button.pack(side='left');ttk.Button(account,text='Redes e computadores',command=self.account_computers).pack(side='left',padx=8);ttk.Button(account,text='Sair da conta',command=self.account_logout).pack(side='left')
        apply_platform_icon(root,self.origin.get(),self.design)
        bar=ttk.Frame(root);bar.pack(fill='x',padx=10,pady=3)
        self.quality=tk.StringVar(value='balanced');quality=ttk.Combobox(bar,textvariable=self.quality,values=['low','balanced','high'],state='readonly',width=11);quality.pack(side='left');quality.bind('<<ComboboxSelected>>',lambda e:self.preference())
        self.monitor=tk.StringVar();self.monitors=ttk.Combobox(bar,textvariable=self.monitor,state='readonly',width=24);self.monitors.pack(side='left',padx=7);self.monitors.bind('<<ComboboxSelected>>',self.select_monitor);self.monitor_ids=[]
        self.theme=tk.StringVar(value='auto');themes=ttk.Combobox(bar,textvariable=self.theme,values=['auto','light','dark'],state='readonly',width=9);themes.pack(side='right')
        self.appearance=AppearanceManager(root,VERSION);self.theme.set(self.appearance.read_choice());themes.bind('<<ComboboxSelected>>',lambda e:self.appearance.set_choice(self.theme.get()))
        ttk.Button(bar,text='Tela inteira',command=self.fullscreen).pack(side='right',padx=7)
        shortcuts=ttk.Frame(root);shortcuts.pack(fill='x',padx=10,pady=3)
        combos=[('Iniciar','win'),('Executar','win_r'),('Área de trabalho','win_d'),('Explorador','win_e'),('Alternar','alt_tab'),('Gerenciador','ctrl_shift_esc'),('Fechar janela','alt_f4'),('Copiar','ctrl_c'),('Colar','ctrl_v'),('Selecionar tudo','ctrl_a'),('Desfazer','ctrl_z'),('Atualizar','f5'),('Esc','esc')]
        for i,(label,combo) in enumerate(combos):ttk.Button(shortcuts,text=label,command=lambda c=combo:self.emit({'t':'c','n':c})).grid(row=i//7,column=i%7,sticky='ew',padx=2,pady=2)
        for i in range(7):shortcuts.columnconfigure(i,weight=1)
        for label,factor in [('−',-.2),('Ajustar',0),('+',.2)]:ttk.Button(bar,text=label,width=7,command=lambda f=factor:self.change_zoom(f)).pack(side='left',padx=2)
        self.canvas=bind_theme(tk.Canvas(root,bg=theme_color('bg'),highlightthickness=0,takefocus=True),background='bg');self.canvas.pack(fill='both',expand=True,padx=10,pady=5)
        ttk.Scrollbar(root,orient='horizontal',command=self.canvas.xview).pack(fill='x',padx=10)
        self.canvas.bind('<Shift-MouseWheel>',lambda e:self.canvas.xview_scroll(-int(e.delta/120),'units'))
        self.canvas.bind('<Control-MouseWheel>',lambda e:self.canvas.yview_scroll(-int(e.delta/120),'units'))
        self.canvas.bind('<<Paste>>',self.paste_text)
        self.canvas.bind('<Configure>',lambda e:self.draw());self.canvas.bind('<Motion>',self.motion)
        for n in (1,2,3):
            self.canvas.bind('<ButtonPress-'+str(n)+'>',lambda e,n=n:self.mouse(e,'d',n));self.canvas.bind('<ButtonRelease-'+str(n)+'>',lambda e,n=n:self.mouse(e,'u',n))
        self.canvas.bind('<MouseWheel>',lambda e:self.emit({'t':'w','dx':0,'dy':max(-20,min(20,-int(e.delta/120)))}))
        self.canvas.bind('<KeyPress>',lambda e:self.key(e,True));self.canvas.bind('<KeyRelease>',lambda e:self.key(e,False));self.canvas.bind('<FocusOut>',lambda e:self.release_keys())
        self.text=tk.StringVar();bottom=ttk.Frame(root);bottom.pack(fill='x',padx=10,pady=5);ttk.Entry(bottom,textvariable=self.text).pack(side='left',fill='x',expand=True);ttk.Button(bottom,text='Enviar texto',command=self.send_text).pack(side='left',padx=6)
        ttk.Button(bottom,text='Ctrl+Alt não disponível; Gerenciador',command=lambda:self.emit({'t':'c','n':'ctrl_shift_esc'})).pack(side='left')
        bind_theme(tk.Label(root,textvariable=self.status,wraplength=1000,anchor='w',bg=theme_color('bg'),fg=theme_color('muted')),background='bg',foreground='muted').pack(fill='x',padx=10,pady=5)
        self.appearance.apply()
        design_origin=self.origin.get()
        if design_origin:
            def refresh_design():
                try:
                    result,_=RemoteAPI(design_origin).request('/api/support.php?action=design&component=support')
                    def apply():
                        self.design=result['design'];apply_platform_design(self.design);self.appearance.apply();apply_platform_icon(self.root,self.origin.get(),self.design)
                    self.notify('ui',apply)
                except Exception:pass
            threading.Thread(target=refresh_design,daemon=True).start()
        root.protocol('WM_DELETE_WINDOW',self.close);root.bind('<Escape>',lambda e:root.attributes('-fullscreen',False));root.after(50,self.tick)
    def change_zoom(self,delta):
        self.zoom=1.0 if delta==0 else max(.5,min(3,self.zoom+delta));self.draw()
    def paste_text(self,_event=None):
        try:self.emit({'t':'x','s':self.root.clipboard_get()[:400]})
        except tk.TclError:pass
        return 'break'
    def background(self,work):
        def run():
            try:work()
            except Exception as exc:self.notify('error',str(exc))
        threading.Thread(target=run,daemon=True).start()
    def account_login(self):
        if self.api:return
        window=tk.Toplevel(self.root);window.title('Entrar na conta');window.geometry('380x250');email=tk.StringVar();password=tk.StringVar()
        ttk.Label(window,text='E-mail').pack();ttk.Entry(window,textvariable=email).pack(fill='x',padx=20);ttk.Label(window,text='Senha').pack();entry=ttk.Entry(window,textvariable=password,show='•');entry.pack(fill='x',padx=20)
        def submit():
            try:api=RemoteAPI(self.origin.get())
            except ValueError as exc:messagebox.showerror('Conta',str(exc));return
            values={'email':email.get(),'password':password.get()};password.set('');button.configure(state='disabled')
            def work():
                try:
                    result,_=api.request('/api/support.php?action=native-login',values);api.token=result['token'];self.account_api=api
                    self.notify('ui',lambda:(window.destroy(),self.account_button.configure(text='Conta conectada'),self.account_computers()))
                except Exception as exc:
                    self.notify('error',str(exc));self.notify('ui',lambda:button.configure(state='normal') if window.winfo_exists() else None)
                finally:values['password']=''
            self.background(work)
        button=ttk.Button(window,text='Entrar',command=submit);button.pack(pady=12)
    def account_logout(self):
        self.disconnect();api=self.account_api;self.account_api=None;self.selected_computer=None;self.account_button.configure(text='Entrar na conta (opcional)')
        if api:self.background(lambda:api.request('/api/support.php?action=native-logout',{}))
    def account_computers(self):
        if not self.account_api:self.account_login();return
        if self.api:return
        api=self.account_api
        def work():
            result,_=api.request('/api/support.php?action=native-networks',{})
            self.notify('ui',lambda:self.show_computers(result['computers']))
        self.background(work)
    def show_computers(self,rows):
        window=tk.Toplevel(self.root);window.title('Redes e computadores');window.geometry('700x400');tree=ttk.Treeview(window,columns=('network','machine','role','online'),show='headings',selectmode='browse')
        for col,label in [('network','Rede'),('machine','Computador'),('role','Permissão'),('online','Última conexão')]:tree.heading(col,text=label);tree.column(col,width=160)
        tree.pack(fill='both',expand=True,padx=8,pady=8)
        for i,row in enumerate(rows):tree.insert('',tk.END,iid=str(i),values=(row['name'],row['machine_name'],{'owner':'Proprietário','administrator':'Administrador','operator':'Técnico'}.get(row['role'],row['role']),row.get('last_seen_at') or 'Sem conexão'))
        def connect():
            selection=tree.selection()
            if not selection:return
            self.selected_computer=rows[int(selection[0])];window.destroy();self.start()
        ttk.Button(window,text='Acessar computador selecionado',command=connect).pack(pady=8)
    def notify(self, kind, data):
        try:self.results.put_nowait((kind,data))
        except queue.Full:
            try:self.results.get_nowait()
            except queue.Empty:pass
            try:self.results.put_nowait((kind,data))
            except queue.Full:pass
    def start(self):
        if self.api:return
        try:api=RemoteAPI(self.origin.get());code=self.code.get().strip().upper()
        except ValueError as e:messagebox.showerror('Conexão',str(e));return
        self.api=api;self.stop=threading.Event();stop=self.stop;self.connect.configure(state='disabled');self.status.set('Validando código de sessão…')
        def run():
            try:
                if self.selected_computer and self.account_api:
                    result,_=self.account_api.request('/api/support.php?action=native-connect',{'network_id':self.selected_computer['id'],'agent_id':self.selected_computer['agent_id']})
                elif len(code)==5:result,_=api.request('/api/support.php?action=guest-connect',{'code':code})
                else:result,_=api.request('/api/support.php?action=redeem',{'code':code})
                api.token=result['token'];api.command=int(result['command_id']);self.notify('connected',None)
                etag='';last_status=0;errors=0
                while not stop.is_set():
                    start=time.monotonic()
                    if start-last_status>1:
                        state,_=api.remote('status');last_status=start;self.notify('status',state)
                        if state.get('stopped') or state['status'] in ('failed','completed','expired'):break
                    frame,newtag=api.remote('image',etag=etag)
                    if frame is not None:etag=newtag;self.notify('frame',frame)
                    errors=0;stop.wait(max(0.04,0.16-(time.monotonic()-start)))
            except Exception as e:self.notify('error',str(e))
            finally:stop.set();self.notify('ended',None)
        threading.Thread(target=run,daemon=True).start()
        def inputs():
            while not stop.wait(0.035):
                events=[]
                while len(events)<40:
                    try:events.append(self.input_queue.get_nowait())
                    except queue.Empty:break
                if events and self.control and api.token:
                    try:api.remote('input',{'events':events})
                    except Exception as e:self.notify('input_error',str(e))
        threading.Thread(target=inputs,daemon=True).start()
    def preference(self):
        api=self.api;quality=self.quality.get()
        if api and api.token:
            def send():
                try:api.remote('preferences',{'quality':quality})
                except Exception as e:self.notify('input_error',str(e))
            threading.Thread(target=send,daemon=True).start()
    def select_monitor(self,_event=None):
        index=self.monitors.current();api=self.api
        if api and 0<=index<len(self.monitor_ids):
            ident=self.monitor_ids[index]
            def send():
                try:api.remote('select-monitor',{'monitor_id':ident})
                except Exception as e:self.notify('input_error',str(e))
            threading.Thread(target=send,daemon=True).start()
    def tick(self):
        if self.closed:return
        latest=None
        while True:
            try:kind,data=self.results.get_nowait()
            except queue.Empty:break
            if kind=='ui':
                try:data()
                except tk.TclError:pass
            elif kind=='frame':latest=data
            elif kind=='connected':self.code.set('');self.preference()
            elif kind=='status':
                self.control=bool(data.get('control'));self.status.set('Controle autorizado · sessão visível no computador remoto' if self.control else 'Somente visualização / aguardando autorização local · '+data['status'])
                ids=[m['id'] for m in data.get('monitors',[])]
                if ids!=self.monitor_ids:self.monitor_ids=ids;self.monitors['values']=[m['label'] for m in data.get('monitors',[])]
                if data.get('selected_monitor') in ids:self.monitors.current(ids.index(data['selected_monitor']))
            elif kind in ('error','input_error'):
                self.status.set(friendly_error(data));self.control=False;self.pending_move=None;self.pressed.clear();self.buttons.clear()
                while not self.input_queue.empty():
                    try:self.input_queue.get_nowait()
                    except queue.Empty:break
            elif kind=='ended':
                self.api=None;self.control=False;self.pending_move=None
                while not self.input_queue.empty():
                    try:self.input_queue.get_nowait()
                    except queue.Empty:break
                self.connect.configure(state='normal');self.status.set('Sessão encerrada. Use um novo código do agente ou selecione um computador da sua conta.');self.pressed.clear()
        if latest:
            try:
                image=Image.open(io.BytesIO(latest));image.load();self.frame=image.copy();self.draw()
            except (OSError,ValueError):self.status.set('Quadro recebido inválido.')
        if self.pending_move:self.emit(self.pending_move);self.pending_move=None
        self.root.after(40,self.tick)
    def draw(self):
        if self.frame is None:return
        w=max(1,self.canvas.winfo_width());h=max(1,self.canvas.winfo_height());scale=min(w/self.frame.width,h/self.frame.height)*self.zoom;image=self.frame.resize((max(1,round(self.frame.width*scale)),max(1,round(self.frame.height*scale))),Image.Resampling.BILINEAR)
        x=max(0,(w-image.width)//2);y=max(0,(h-image.height)//2);self.image_box=(x,y,image.width,image.height);self.photo=ImageTk.PhotoImage(image);self.canvas.configure(scrollregion=(0,0,max(w,image.width),max(h,image.height)));self.canvas.delete('screen');self.canvas.create_image(x,y,image=self.photo,anchor='nw',tags='screen')
    def coordinates(self,e):
        x,y,w,h=self.image_box
        px=self.canvas.canvasx(e.x);py=self.canvas.canvasy(e.y)
        if not(x<=px<=x+w and y<=py<=y+h):return None
        return {'x':min(1,max(0,(self.canvas.canvasx(e.x)-x)/w)),'y':min(1,max(0,(self.canvas.canvasy(e.y)-y)/h))}
    def motion(self,e):
        point=self.coordinates(e)
        if point:self.pending_move={'t':'m',**point}
    def mouse(self,e,kind,button):
        self.canvas.focus_set();point=self.coordinates(e)
        if point is None and kind=='u':
            x,y,w,h=self.image_box;point={'x':min(1,max(0,(self.canvas.canvasx(e.x)-x)/w)),'y':min(1,max(0,(self.canvas.canvasy(e.y)-y)/h))}
        if point:
            b={1:0,2:1,3:2}[button]
            if kind=='d':self.buttons.add(b)
            else:self.buttons.discard(b)
            self.emit({'t':kind,'b':b,**point})
    def emit(self,event):
        if not self.control:return
        try:self.input_queue.put_nowait(event)
        except queue.Full:self.status.set('Conexão ocupada; aguarde.');self.control=False
    def key(self,event,down):
        if event.keysym=='Escape' and self.root.attributes('-fullscreen'):
            self.root.attributes('-fullscreen',False);return 'break'
        special={'Return':'Enter','BackSpace':'Backspace','Escape':'Escape','Tab':'Tab','space':'Space','Control_L':'ControlLeft','Control_R':'ControlRight','Shift_L':'ShiftLeft','Shift_R':'ShiftRight','Alt_L':'AltLeft','Alt_R':'AltRight','Super_L':'MetaLeft','Super_R':'MetaRight','Left':'ArrowLeft','Right':'ArrowRight','Up':'ArrowUp','Down':'ArrowDown','Delete':'Delete','Home':'Home','End':'End','Prior':'PageUp','Next':'PageDown'}
        name=special.get(event.keysym)
        if not name and len(event.keysym)==1:name=('Key'+event.keysym.upper()) if event.keysym.isalpha() else ('Digit'+event.keysym if event.keysym.isdigit() else None)
        if not name and event.keysym.startswith('F') and event.keysym[1:].isdigit():name=event.keysym
        if name:
            if down:self.pressed.add(name)
            else:self.pressed.discard(name)
            self.emit({'t':'k','d':1 if down else 0,'c':name})
        return 'break'
    def release_keys(self):
        for name in self.pressed:self.emit({'t':'k','d':0,'c':name})
        self.pressed.clear()
        for button in self.buttons:self.emit({'t':'u','b':button,'x':.5,'y':.5})
        self.buttons.clear()
    def send_text(self):
        text=self.text.get()
        if text:self.emit({'t':'x','s':text[:400]});self.text.set('')
    def fullscreen(self):self.root.attributes('-fullscreen',not self.root.attributes('-fullscreen'))
    def disconnect(self):
        self.release_keys();api=self.api;self.stop.set();self.control=False
        if api and api.token:
            def end():
                try:api.remote('stop',{})
                except Exception:pass
            threading.Thread(target=end,daemon=False).start()
    def close(self):self.disconnect();self.closed=True;self.root.destroy()

if __name__=='__main__':
    window=tk.Tk();SupportApp(window);window.mainloop()
