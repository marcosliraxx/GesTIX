/* One appearance contract for public pages, management screens and PWA. */
(() => {
  'use strict';
  const root = document.documentElement;
  const media = matchMedia('(prefers-color-scheme: dark)');
  const allowed = ['auto','light','dark'];
  const key = 'arc_appearance';
  let choice = root.dataset.arcTheme || 'auto';
  try { choice = localStorage.getItem(key) || choice; } catch {}
  if (!allowed.includes(choice)) choice = 'auto';
  function apply(value, persist = false) {
    choice = allowed.includes(value) ? value : 'auto';
    root.dataset.arcTheme = choice;
    root.dataset.theme = choice === 'auto' ? (media.matches ? 'dark' : 'light') : choice;
    root.style.colorScheme = root.dataset.theme;
    if (persist) try { localStorage.setItem(key, choice); } catch {}
    document.querySelectorAll('[data-arc-appearance]').forEach(select => { select.value = choice; });
    const meta = document.querySelector('meta[name=theme-color]');
    if (meta) meta.content = root.dataset.theme === 'dark' ? '#0b1420' : '#edf2f7';
    window.dispatchEvent(new CustomEvent('arc:theme-change', {detail:{choice,theme:root.dataset.theme}}));
    window.dispatchEvent(new Event('resize'));
  }
  window.arcTheme = {set:value=>apply(value,true),get:()=>choice,color:name=>getComputedStyle(root).getPropertyValue('--'+name).trim()};
  apply(choice);
  media.addEventListener('change', () => { if(choice==='auto') apply(choice); });
  window.addEventListener('storage', event => { if(event.key===key) apply(event.newValue||'auto'); });
  // Translate old fixed palettes into semantic colors. Existing CSS variables,
  // brand colors, status accents, opacity and layout rules remain intact.
  function token(hex, role) {
    let color = hex.slice(1); if(color.length===3) color=color.split('').map(c=>c+c).join('');
    if(color.length!==6) return hex;
    const rgb=[0,2,4].map(i=>parseInt(color.slice(i,i+2),16)/255);
    const hi=Math.max(...rgb),lo=Math.min(...rgb),light=(hi+lo)/2;
    const sat=hi===lo?0:(hi-lo)/(1-Math.abs(2*light-1));
    if(role==='background') {
      if(sat>.4 && light>.28 && light<.78) return hex;
      return light>.90 || light<.10 ? 'var(--surface)' : 'var(--surface-alt)';
    }
    if(sat>.42 && light>.3 && light<.75) { const hue=hi===rgb[2]?240+60*(rgb[0]-rgb[1])/(hi-lo):hi===rgb[1]?120+60*(rgb[2]-rgb[0])/(hi-lo):60*((rgb[1]-rgb[2])/(hi-lo)); return role==='text'&&hue>170&&hue<260?'var(--primary)':hex; }
    return role==='border'?'var(--border)':(light>.28 && light<.78?'var(--muted)':'var(--text)');
  }
  // Canvas colors do not inherit CSS. Keep chart labels and grids readable.
  if (window.CanvasRenderingContext2D) {
    const canvasColor=(value,role)=>{
      if(typeof value!=='string'||!value.startsWith('#'))return value;
      const mapped=token(value,role);
      const variable=mapped.match(/^var\(--([^)]+)\)$/);
      return variable?getComputedStyle(root).getPropertyValue('--'+variable[1]).trim():mapped;
    };
    for(const [method,property,role] of [['fillText','fillStyle','text'],['strokeText','strokeStyle','text'],['fillRect','fillStyle','background'],['stroke','strokeStyle','border']]) {
      const original=CanvasRenderingContext2D.prototype[method];
      CanvasRenderingContext2D.prototype[method]=function(...args){
        const color=this[property];this[property]=canvasColor(color,role);
        try{return original.apply(this,args);}finally{this[property]=color;}
      };
    }
  }
  function normalize(style, selector='') {
    for(const property of Array.from(style)) {
      if(property.startsWith('--') || !/^(background(?:-color)?|color|border(?:-(?:top|right|bottom|left))?(?:-color)?|outline-color)$/.test(property))continue;
      const value=style.getPropertyValue(property);
      if(value.includes('var(') || !/(#[a-f\d]{3,8}\b|rgba?\(|\b(?:white|black|gray|grey|silver|whitesmoke|gainsboro|blue)\b)/i.test(value))continue;
      if(property.startsWith('background') && /backdrop|overlay/.test(selector))continue;
      if(property==='color' && /primary|\.btn\b|\.button\b|badge|pill/.test(selector))continue;
      const role=property.startsWith('background')?'background':property==='color'?'text':'border';
      const mapped=value.replace(/#[a-f\d]{3,8}\b|rgba?\([^)]*\)|\b(?:white|black|gray|grey|silver|whitesmoke|gainsboro|blue)\b/gi,color=>{
        const named={white:'#ffffff',black:'#000000',gray:'#808080',grey:'#808080',silver:'#c0c0c0',whitesmoke:'#f5f5f5',gainsboro:'#dcdcdc',blue:'#0000ff'};
        if(named[color.toLowerCase()])return token(named[color.toLowerCase()],role);
        if(color.startsWith('#'))return token(color,role);
        const parts=color.match(/[\d.]+/g).map(Number);
        if(parts.length<3)return color;
        const hex='#'+parts.slice(0,3).map(value=>Math.max(0,Math.min(255,Math.round(value))).toString(16).padStart(2,'0')).join('');
        const replacement=token(hex,role);
        const alpha=parts.length>3?parts[3]:1;
        return alpha<1 && replacement!==hex?`color-mix(in srgb, ${replacement} ${Math.round(alpha*100)}%, transparent)`:replacement;
      });
      if(mapped!==value)style.setProperty(property,mapped,style.getPropertyPriority(property));
    }
  }
  function ready() {
    const walk=rules=>{ for(const rule of Array.from(rules)){if(rule.style)normalize(rule.style,rule.selectorText||'');if(rule.cssRules&&!/\bprint\b/.test(rule.conditionText||''))walk(rule.cssRules);} };
    for(const sheet of Array.from(document.styleSheets))try{walk(sheet.cssRules);}catch{/* external vendor sheets cannot be read */}
    document.querySelectorAll('[style]').forEach(element=>normalize(element.style));
    // Wide administrative tables become labeled records on narrow screens.
    document.querySelectorAll('table').forEach(table=>{
      const headings=Array.from(table.querySelectorAll('thead th')).map(th=>th.textContent.replace(/\s+/g,' ').trim());
      if(!headings.length)return;
      table.classList.add('arc-responsive-table');
      const labelRows=()=>table.querySelectorAll('tbody tr').forEach(row=>Array.from(row.cells).forEach((cell,index)=>{
        if(cell.colSpan===1 && headings[index])cell.dataset.label=headings[index];
      }));
      labelRows();
      const body=table.querySelector('tbody');
      if(body)new MutationObserver(labelRows).observe(body,{childList:true});
    });
    const label=document.createElement('label');label.className='arc-appearance-control';
    label.innerHTML='<i class="fa fa-adjust" aria-hidden="true"></i><select data-arc-appearance aria-label="Tema do aplicativo"><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Escuro</option></select>';
    const header=document.querySelector('.app-header');
    if(header)header.insertBefore(label,header.querySelector('.header-actions')||header.lastElementChild);
    else {label.classList.add('arc-appearance-public');document.body.append(label);}
    label.querySelector('select').addEventListener('change',event=>apply(event.target.value,true));
    apply(choice);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
})();
