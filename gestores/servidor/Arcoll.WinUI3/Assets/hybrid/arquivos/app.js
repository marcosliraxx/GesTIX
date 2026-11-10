/* Caminho: gestores/servidor/Arcoll.WinUI3/Assets/hybrid/arquivos/app.js */
'use strict';
(()=>{
 const $=id=>document.getElementById(id),bridge=window.chrome?.webview;
 const thumbnails=new Map(),pending=new Set();let thumbnailTimer=null,thumbnailRequest=0;
 let path='',rows=[],view='details',loading=false,history=[],truncated=false;
 try{const v=localStorage.getItem('arc_support_file_view');if(['list','grid','details'].includes(v))view=v}catch{}
 const bytes=n=>{n=Number(n)||0;if(!n)return '0 B';const u=['B','KB','MB','GB','TB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++}return n.toLocaleString('pt-BR',{maximumFractionDigits:i<2?0:1})+' '+u[i]};
 const ext=name=>{const p=String(name||'').lastIndexOf('.');return p>-1?String(name).slice(p+1).toLowerCase():''};
 const icons={zip:'🗜️',rar:'🗜️','7z':'🗜️',gz:'🗜️',png:'🖼️',jpg:'🖼️',jpeg:'🖼️',gif:'🖼️',bmp:'🖼️',webp:'🖼️',svg:'🖼️',mp4:'🎞️',avi:'🎞️',mkv:'🎞️',mov:'🎞️',mp3:'🎵',wav:'🎵',flac:'🎵',pdf:'📕',doc:'📄',docx:'📄',txt:'📄',log:'📄',xls:'📊',xlsx:'📊',csv:'📊',exe:'⚙️',msi:'⚙️'};
 const iconOf=i=>i.drive?'💽':i.directory?'📁':(icons[ext(i.name)]||'📄');
 const rowLabel=i=>{if(i.drive){const t=Number(i.total||i.size||0),f=Number(i.free||0);return(i.name||'Disco')+' · '+(t?bytes(f)+' livres de '+bytes(t):'Unidade local')}return i.directory?'Pasta':(ext(i.name).toUpperCase()||'Sem extensão')+' · Arquivo'};
 function node(tag,text,css){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(css)n.className=css;return n}
 function send(action,extra={}){bridge?.postMessage({action,...extra})}
 function state(text,err){const s=$('state');s.textContent=text;s.classList.toggle('err',!!err)}
 function crumbs(){const box=$('crumbs');box.replaceChildren();const root=node('button','🖥️','arc-fs-crumb');root.type='button';root.title='Dispositivos e unidades';root.onclick=()=>open('');box.append(root);if(!path)return;const parts=path.replace(/[\\/]+$/,'').split(/[\\/]/).filter(Boolean);let acc='';parts.forEach((part,i)=>{acc+=(i?'\\':'')+part;const target=i===0?acc+'\\':acc;box.append(node('span','›','arc-fs-sep'));const b=node('button',part,'arc-fs-crumb');b.type='button';b.onclick=()=>open(target);box.append(b)});box.scrollLeft=box.scrollWidth}
 function thumbnailKey(item){return path+'|'+item.name+'|'+item.size+'|'+item.modified}
 function loadThumbnails(){const names=[...pending].slice(0,24);if(!names.length)return;for(const name of names)pending.delete(name);send('thumbnails',{requestId:String(++thumbnailRequest),names});}
 function render(){const box=$('entries');box.dataset.view=view;box.classList.toggle('has-drive',rows.some(r=>r.drive));box.replaceChildren();document.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));
  if(!rows.length){const p=node('div',undefined,'arc-fs-empty-panel');p.append(node('span',loading?'⏳':path?'📂':'💽','ico'),node('span',loading?'Carregando arquivos…':path?'Esta pasta está vazia.':'Nenhum disco local encontrado.'));box.append(p);return}
  const query=$('search').value.toLocaleLowerCase('pt-BR');const sort=$('sort').value;
  const visible=rows.filter(i=>String(i.name+' '+(i.label||'')).toLocaleLowerCase('pt-BR').includes(query)).sort((a,b)=>Number(b.directory)-Number(a.directory)||(sort==='name'?String(a.name).localeCompare(String(b.name),'pt-BR',{numeric:true}):Number(b[sort]||0)-Number(a[sort]||0)));
  for(const item of visible){const b=node('button',undefined,'arc-fs-item'+(item.drive?' arc-fs-drive':''));b.type='button';b.setAttribute('role','listitem');
   const label=node('span',undefined,'arc-fs-label');label.append(node('strong',item.drive?(item.label||({'Removable':'Unidade USB','Network':'Unidade de rede','CDRom':'Unidade de CD/DVD'}[item.drive_type])||'Unidade')+' ('+String(item.name||'').replace(/[\\/]+$/,'')+')':item.name),node('small',item.drive?rowLabel(item):item.directory?'Pasta':rowLabel(item)+' · '+bytes(item.size)));
   if(item.drive&&item.total){const bar=node('span',undefined,'bar'),fill=document.createElement('i');fill.style.width=Math.min(100,Math.round(100*(item.used||0)/item.total))+'%';bar.append(fill);label.append(bar)}
   const icon=node('span',iconOf(item),'ico');b.append(icon,label);
   if(!item.directory){const key=thumbnailKey(item),cached=thumbnails.get(key);b.dataset.thumbnail=key;if(cached){const img=node('img',undefined,'arc-fs-thumb');img.src=cached;img.alt='Prévia de '+item.name;icon.replaceWith(img);}else if(!thumbnails.has(key)){thumbnails.set(key,null);pending.add(item.name);clearTimeout(thumbnailTimer);thumbnailTimer=setTimeout(loadThumbnails,100);}}
   if(view==='details'&&!item.drive)b.append(node('small',item.modified?new Date(item.modified*1000).toLocaleString('pt-BR'):'—','arc-fs-modified'));
   b.onclick=()=>{box.querySelectorAll('.is-selected').forEach(n=>n.classList.remove('is-selected'));b.classList.add('is-selected')};b.ondblclick=()=>item.directory?open(item.path):send('open',{name:item.name});b.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();item.directory?open(item.path):send('open',{name:item.name})}};b.title=item.directory?item.name:'Duplo clique para abrir com o aplicativo padrão do Windows.';box.append(b)}}
 function open(target,remember=true){if(loading)return;if(remember&&(history.length===0||history[history.length-1]!==path))history.push(path);loading=true;state('Consultando discos e pastas…');render();send('browse',{path:target})}
 function receive(m){if(m.type==='thumbnails'){if(m.path!==path)return;for(const entry of m.entries||[]){if(!/^image\/(jpeg|png|webp|gif|bmp|x-icon)$/.test(entry.type)||!entry.data||entry.data.length>400000)continue;const item=rows.find(i=>i.name===entry.name);if(!item)continue;const key=thumbnailKey(item);thumbnails.set(key,'data:'+entry.type+';base64,'+entry.data);}render();if(pending.size)loadThumbnails();return;}if(m.type==='theme')return;if(m.type==='notice'){state(m.error,true);return;}if(m.type==='browse'){loading=false;if(m.error){state(m.error,true);render();return}pending.clear();clearTimeout(thumbnailTimer);path=m.path||'';rows=m.entries||[];truncated=!!m.truncated;crumbs();render();const folders=rows.filter(r=>r.directory&&!r.drive).length,files=rows.filter(r=>!r.directory).length;state(path?folders+' pasta(s) · '+files+' arquivo(s). Duplo clique para navegar. Abra no Explorer para todos os recursos do Windows.'+(truncated?' Listagem parcial: entre na pasta desejada para continuar.':''):rows.length+' unidade(s). Duplo clique para navegar. Abra no Explorer para todos os recursos do Windows.');}else if(m.type==='machine'&&m.name)$('machine').textContent=m.name}
 document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>{view=b.dataset.view;try{localStorage.setItem('arc_support_file_view',view)}catch{}render()});
 const parent=()=>{const trimmed=path.replace(/[\\/]+$/,'');return /^[A-Za-z]:$/.test(trimmed)?'':trimmed.slice(0,trimmed.lastIndexOf('\\')+1)};
 $('explorer').onclick=()=>send('explorer');$('up').onclick=()=>open(parent());
 $('search').oninput=render;$('sort').onchange=render;
 const menu=$('native-menu');$('entries').oncontextmenu=e=>{e.preventDefault();menu.hidden=false;menu.style.left=Math.max(0,Math.min(e.clientX,innerWidth-270))+'px';menu.style.top=Math.max(0,Math.min(e.clientY,innerHeight-100))+'px'};
 $('native-open').onclick=()=>{menu.hidden=true;send('explorer')};$('native-refresh').onclick=()=>{menu.hidden=true;open(path,false)};
 document.addEventListener('pointerdown',e=>{if(!menu.contains(e.target))menu.hidden=true});document.addEventListener('keydown',e=>{if(e.key==='Escape')menu.hidden=true;if(e.key==='F5'){e.preventDefault();open(path,false)}if(e.altKey&&e.key==='ArrowUp'){e.preventDefault();open(parent())}});
 $('back').onclick=()=>{if(loading)return;const previous=history.pop();open(previous===undefined?'':previous,false)};
 $('reload').onclick=()=>open(path,false);
 if(bridge){bridge.addEventListener('message',e=>receive(e.data));send('ready')}else state('Abra o Arquivos no gestor Windows.',true);
 crumbs();render();
})();
