/* Caminho: gestores/servidor/Arcoll.WinUI3/HybridReact/src/main.tsx */
import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';

type Tool = {id: string; label: string};
type Group = {id: string; label: string; items: Tool[]};
type HostState = {type?: string; title: string; network: string; connected: boolean; theme: 'light' | 'dark'; accent: string; groups: Group[]};
type HostMessage = {type: 'state'} & HostState;

declare global {
  interface Window { chrome?: {webview?: {
    postMessage: (value: unknown) => void;
    addEventListener: (type:'message', callback:(event:MessageEvent<HostMessage>)=>void)=>void;
    removeEventListener: (type:'message', callback:(event:MessageEvent<HostMessage>)=>void)=>void;
  }} }
}

// Espelho do catálogo de navegação nativo, somente para renderização sem host.
const FALLBACK: Group[] = [
  {id:'home',label:'Início',items:[['panel','Painel'],['monitor','Monitoramento'],['server','Servidor'],['speed','Internet'],['health','Saúde']].map(([id,label])=>({id,label}))},
  {id:'maintenance',label:'Manutenção',items:[['clean','Limpeza'],['registry','Registro'],['startup','Inicialização'],['performance','Desempenho'],['restore','Restauração'],['options','Configurações']].map(([id,label])=>({id,label}))},
  {id:'software',label:'Aplicativos',items:[['programs','Programas'],['uninstall','Desinstalar'],['updater','Atualizações'],['drivers','Drivers'],['windows_update','Windows Update']].map(([id,label])=>({id,label}))},
  {id:'storage',label:'Armazenamento',items:[['disk','Disco'],['duplicates','Duplicados'],['cloud','Sincronizados'],['backup','Backup']].map(([id,label])=>({id,label}))},
  {id:'network',label:'Rede',items:[['devices','Dispositivos'],['map','Mapa'],['files','Arquivos']].map(([id,label])=>({id,label}))},
  {id:'service',label:'Atendimento',items:[['support','Suporte'],['tickets','Chamados']].map(([id,label])=>({id,label}))},
];
const sanitize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const glyph: Record<string,string> = {home:'⌂',maintenance:'⚒',software:'▦',storage:'▤',network:'◎',service:'♧'};

function App() {
  const [host,setHost] = useState<HostState>({title:'T.I. Digital',network:'',connected:false,theme:'light',accent:'#2563eb',groups:FALLBACK});
  const [search,setSearch] = useState('');
  useEffect(() => {
    const handle=(event: MessageEvent<HostMessage>) => {
      const data=event.data;
      if(data?.type!=='state'||!Array.isArray(data.groups)) return;
      const allowed=new Set(FALLBACK.flatMap(group=>group.items.map(item=>item.id)));
      setHost({...data,groups:data.groups.map(group=>({...group,items:Array.isArray(group.items)?group.items.filter(item=>allowed.has(item.id)):[]}))});
    };
    window.chrome?.webview?.addEventListener('message',handle);
    return()=>window.chrome?.webview?.removeEventListener('message',handle);
  },[]);
  const groups=useMemo(()=>host.groups.map(group=>({...group,items:group.items.filter(item=>sanitize(`${item.label} ${group.label}`).includes(sanitize(search)))})).filter(group=>group.items.length>0),[host.groups,search]);
  const navigate=(section:string)=>{
    if(!host.groups.some(group=>group.items.some(item=>item.id===section))) return;
    window.chrome?.webview?.postMessage({type:'navigate',section});
  };
  return <main className="dashboard" data-theme={host.theme} style={{'--accent':/^#[0-9a-fA-F]{6}$/.test(host.accent)?host.accent:'#2563eb'} as React.CSSProperties}>
    <section className="welcome"><div><span className="eyebrow">● CENTRAL DE CONTROLE</span><h1>Bem-vindo ao T.I. Digital</h1><p>{host.title} · Escolha uma ferramenta para consultar ou administrar o computador.</p></div><div className="welcome-art" aria-hidden="true">✦</div></section>
    <section className="stats" aria-label="Visão geral">
      <article className="stat"><span className="stat-label">GRUPOS</span><strong>{host.groups.length}</strong><small>Categorias de trabalho</small></article>
      <article className="stat"><span className="stat-label">FERRAMENTAS</span><strong>{host.groups.reduce((sum,g)=>sum+g.items.length,0)}</strong><small>Páginas disponíveis</small></article>
      <article className="stat"><span className="stat-label">CONEXÃO</span><strong>{host.connected?'Conectado':'Aguardando'}</strong><small>{host.connected?host.network:'Confirmação do gestor pendente'}</small></article>
    </section>
    <section className="toolbox"><header className="section-heading"><div><span className="eyebrow">ACESSO RÁPIDO</span><h2>Suas ferramentas</h2><p>Os atalhos abrem páginas nativas e mantêm as permissões existentes.</p></div><div className="filter"><label className="sr-only" htmlFor="search">Buscar ferramenta</label><input id="search" placeholder="Buscar ferramenta..." value={search} onChange={e=>setSearch(e.target.value)}/></div></header>
    <div className="tool-groups">{groups.map(group=><section className="group" key={group.id}><div className="group-head"><div className="group-icon">{glyph[group.id]||'◈'}</div><div><h3>{group.label}</h3><small>{group.items.length} ferramenta(s)</small></div></div><div className="tiles">{group.items.map(item=><button className="tile" key={item.id} type="button" onClick={()=>navigate(item.id)}><span aria-hidden="true">◈</span><span>{item.label}</span></button>)}</div></section>)}</div>
    {!groups.length&&<p className="empty">Nenhuma ferramenta encontrada.</p>}</section><footer className="disclaimer">Interface híbrida local · Operações nativas preservadas</footer>
  </main>;
}

createRoot(document.getElementById('root')!).render(<React.StrictMode><App/></React.StrictMode>);
