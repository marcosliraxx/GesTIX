#!/usr/bin/env python3
# Caminho: support/arcoll_support.py
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
from arcoll_toolkit import AppearanceManager, bind_theme, theme_color

VERSION = '3.0.0'

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
        self.root=root;self.api=None;self.stop=threading.Event();self.results=queue.Queue(maxsize=3);self.input_queue=queue.Queue(maxsize=150)
        self.control=False;self.frame=None;self.photo=None;self.image_box=(0,0,1,1);self.pending_move=None;self.closed=False;self.pressed=set();self.buttons=set()
        root.title('Arcoll Suporte · '+VERSION);root.geometry('1100x740');root.minsize(820,520)
        bind_theme(root,background='bg').configure(bg=theme_color('bg'))
        top=bind_theme(tk.Frame(root,bg=theme_color('surface')),background='surface');top.pack(fill='x',padx=10,pady=8)
        self.origin=tk.StringVar();self.code=tk.StringVar();self.status=tk.StringVar(value='Abra Suporte na plataforma, solicite uma sessão Windows e cole o código de uso único.')
        try:
            from pathlib import Path
            config=json.loads(Path(__file__).with_name('platform.json').read_text(encoding='utf-8'));self.origin.set(config.get('origin',''))
        except (OSError,ValueError,AttributeError):pass
        for text,var,width in [('Plataforma HTTPS',self.origin,42),('Código da sessão',self.code,22)]:
            block=tk.Frame(top);block.pack(side='left',fill='x',expand=True,padx=5,pady=5)
            bind_theme(block,background='surface').configure(bg=theme_color('surface'))
            bind_theme(tk.Label(block,text=text,bg=theme_color('surface'),fg=theme_color('muted')),background='surface',foreground='muted').pack(anchor='w')
            ttk.Entry(block,textvariable=var,width=width).pack(fill='x')
        self.connect=ttk.Button(top,text='Conectar',command=self.start);self.connect.pack(side='left',padx=6)
        ttk.Button(top,text='Encerrar',command=self.disconnect).pack(side='left',padx=6)
        bar=ttk.Frame(root);bar.pack(fill='x',padx=10,pady=3)
        self.quality=tk.StringVar(value='balanced');quality=ttk.Combobox(bar,textvariable=self.quality,values=['low','balanced','high'],state='readonly',width=11);quality.pack(side='left');quality.bind('<<ComboboxSelected>>',lambda e:self.preference())
        self.monitor=tk.StringVar();self.monitors=ttk.Combobox(bar,textvariable=self.monitor,state='readonly',width=24);self.monitors.pack(side='left',padx=7);self.monitors.bind('<<ComboboxSelected>>',self.select_monitor);self.monitor_ids=[]
        self.theme=tk.StringVar(value='auto');themes=ttk.Combobox(bar,textvariable=self.theme,values=['auto','light','dark'],state='readonly',width=9);themes.pack(side='right')
        self.appearance=AppearanceManager(root,VERSION);self.theme.set(self.appearance.read_choice());themes.bind('<<ComboboxSelected>>',lambda e:self.appearance.set_choice(self.theme.get()))
        ttk.Button(bar,text='Tela inteira',command=self.fullscreen).pack(side='right',padx=7)
        self.canvas=bind_theme(tk.Canvas(root,bg=theme_color('bg'),highlightthickness=0,takefocus=True),background='bg');self.canvas.pack(fill='both',expand=True,padx=10,pady=5)
        self.canvas.bind('<Configure>',lambda e:self.draw());self.canvas.bind('<Motion>',self.motion)
        for n in (1,2,3):
            self.canvas.bind('<ButtonPress-'+str(n)+'>',lambda e,n=n:self.mouse(e,'d',n));self.canvas.bind('<ButtonRelease-'+str(n)+'>',lambda e,n=n:self.mouse(e,'u',n))
        self.canvas.bind('<MouseWheel>',lambda e:self.emit({'t':'w','dx':0,'dy':max(-20,min(20,-int(e.delta/120)))}))
        self.canvas.bind('<KeyPress>',lambda e:self.key(e,True));self.canvas.bind('<KeyRelease>',lambda e:self.key(e,False));self.canvas.bind('<FocusOut>',lambda e:self.release_keys())
        self.text=tk.StringVar();bottom=ttk.Frame(root);bottom.pack(fill='x',padx=10,pady=5);ttk.Entry(bottom,textvariable=self.text).pack(side='left',fill='x',expand=True);ttk.Button(bottom,text='Enviar texto',command=self.send_text).pack(side='left',padx=6)
        ttk.Button(bottom,text='Ctrl+Alt não disponível; Gerenciador',command=lambda:self.emit({'t':'c','n':'ctrl_shift_esc'})).pack(side='left')
        bind_theme(tk.Label(root,textvariable=self.status,wraplength=1000,anchor='w',bg=theme_color('bg'),fg=theme_color('muted')),background='bg',foreground='muted').pack(fill='x',padx=10,pady=5)
        root.protocol('WM_DELETE_WINDOW',self.close);root.bind('<Escape>',lambda e:root.attributes('-fullscreen',False));root.after(50,self.tick)
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
                result,_=api.request('/api/support.php?action=redeem',{'code':code});api.token=result['token'];api.command=int(result['command_id']);self.notify('connected',None)
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
            if kind=='frame':latest=data
            elif kind=='connected':self.code.set('');self.preference()
            elif kind=='status':
                self.control=bool(data.get('control'));self.status.set('Controle autorizado · sessão visível no computador remoto' if self.control else 'Somente visualização / aguardando autorização local · '+data['status'])
                ids=[m['id'] for m in data.get('monitors',[])]
                if ids!=self.monitor_ids:self.monitor_ids=ids;self.monitors['values']=[m['label'] for m in data.get('monitors',[])]
                if data.get('selected_monitor') in ids:self.monitors.current(ids.index(data['selected_monitor']))
            elif kind in ('error','input_error'):
                self.status.set(data);self.control=False;self.pending_move=None;self.pressed.clear();self.buttons.clear()
                while not self.input_queue.empty():
                    try:self.input_queue.get_nowait()
                    except queue.Empty:break
            elif kind=='ended':
                self.api=None;self.control=False;self.pending_move=None
                while not self.input_queue.empty():
                    try:self.input_queue.get_nowait()
                    except queue.Empty:break
                self.connect.configure(state='normal');self.status.set('Sessão encerrada. Gere outro código na plataforma para reconectar.');self.pressed.clear()
        if latest:
            try:
                image=Image.open(io.BytesIO(latest));image.load();self.frame=image.copy();self.draw()
            except (OSError,ValueError):self.status.set('Quadro recebido inválido.')
        if self.pending_move:self.emit(self.pending_move);self.pending_move=None
        self.root.after(40,self.tick)
    def draw(self):
        if self.frame is None:return
        w=max(1,self.canvas.winfo_width());h=max(1,self.canvas.winfo_height());image=self.frame.copy();image.thumbnail((w,h),Image.Resampling.BILINEAR)
        x=(w-image.width)//2;y=(h-image.height)//2;self.image_box=(x,y,image.width,image.height);self.photo=ImageTk.PhotoImage(image);self.canvas.delete('screen');self.canvas.create_image(x,y,image=self.photo,anchor='nw',tags='screen')
    def coordinates(self,e):
        x,y,w,h=self.image_box
        if not(x<=e.x<=x+w and y<=e.y<=y+h):return None
        return {'x':min(1,max(0,(e.x-x)/w)),'y':min(1,max(0,(e.y-y)/h))}
    def motion(self,e):
        point=self.coordinates(e)
        if point:self.pending_move={'t':'m',**point}
    def mouse(self,e,kind,button):
        self.canvas.focus_set();point=self.coordinates(e)
        if point is None and kind=='u':
            x,y,w,h=self.image_box;point={'x':min(1,max(0,(e.x-x)/w)),'y':min(1,max(0,(e.y-y)/h))}
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
