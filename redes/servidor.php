<?php
/* Caminho: redes/servidor.php */
declare(strict_types=1);
$arcSectionFile='servidor';
require __DIR__.'/_base.php';

function arc_server_fallback_components(array $m): array {
    $details = is_array($m['details'] ?? null) ? $m['details'] : [];
    $components = [];
    $push = static function(string $key, string $title, string $group, $value, string $unit, string $detail, string $subtitle = '', array $series = [], array $series_meta = [], string $accent = '#31beff', string $header = '') use (&$components): void {
        $components[] = [
            'key' => $key,
            'title' => $title,
            'group' => $group,
            'value' => is_numeric($value) ? (float)$value : null,
            'unit' => $unit,
            'detail' => $detail,
            'subtitle' => $subtitle,
            'series' => $series,
            'series_meta' => $series_meta,
            'accent' => $accent,
            'header' => $header ?: $subtitle,
        ];
    };
    $lat = is_numeric($m['Internet'] ?? null) ? (float)$m['Internet'] : null;
    $download = is_numeric($m['Internet_download'] ?? null) ? (float)$m['Internet_download'] : null;
    $upload = is_numeric($m['Internet_upload'] ?? null) ? (float)$m['Internet_upload'] : null;
    $loss = is_numeric($m['Internet_loss'] ?? null) ? (float)$m['Internet_loss'] : null;
    $push(
        'internet',
        'Internet',
        'internet',
        $lat,
        'ms',
        'Latência, download, upload e perdas acompanhados pelo agente.',
        'Latência • tráfego • perdas',
        ['latency_ms' => $lat, 'download_mbps' => $download, 'upload_mbps' => $upload, 'loss_percent' => $loss],
        [
            'latency_ms' => ['label' => 'Latência', 'unit' => 'ms', 'color' => '#36c5ff'],
            'download_mbps' => ['label' => 'Download', 'unit' => 'Mbps', 'color' => '#2dd4bf'],
            'upload_mbps' => ['label' => 'Upload', 'unit' => 'Mbps', 'color' => '#b388ff'],
            'loss_percent' => ['label' => 'Perdas', 'unit' => '%', 'color' => '#ffb84d'],
        ],
        '#36c5ff',
        'Monitoramento em tempo real'
    );
    $push('cpu','CPU','cpu',$m['CPU'] ?? null,'%',$details['CPU'] ?? 'Calculando uso da CPU...','Processador',[],[],'#24c8ff');
    $push('memory','Memória','memory',$m['Memória'] ?? null,'%',$details['Memória'] ?? 'Leitura de memória indisponível','Memória do sistema',[],[],'#3f8cff');
    $push('disks','Discos','disk',$m['Discos'] ?? null,'%',$details['Discos'] ?? 'Aguardando leitura dos discos','Armazenamento',[],[],'#8bcf26');
    $push('network_total','Rede','network',$m['Rede'] ?? null,'Mbps',$details['Rede'] ?? 'Calculando taxa de rede...','Adaptadores ativos',['download_mbps' => $m['Rede_download'] ?? null,'upload_mbps' => $m['Rede_upload'] ?? null],[
        'download_mbps' => ['label' => 'Receber', 'unit' => 'Mbps', 'color' => '#ff4f9a'],
        'upload_mbps' => ['label' => 'Enviar', 'unit' => 'Mbps', 'color' => '#ff9d7a'],
    ],'#ff4f9a','Adaptadores ativos');
    if (is_numeric($m['GPU'] ?? null)) {
        $push('gpu','GPU','gpu',$m['GPU'],'%',$details['GPU'] ?? 'Uso da GPU','Processador gráfico',[],[],'#c66bff');
    }
    return $components;
}

function arc_server_sanitize_component(array $component): array {
    $series = [];
    foreach ((array)($component['series'] ?? []) as $key => $value) {
        $series[(string)$key] = is_numeric($value) ? (float)$value : null;
    }
    $seriesMeta = [];
    foreach ((array)($component['series_meta'] ?? []) as $key => $meta) {
        if (!is_array($meta)) continue;
        $seriesMeta[(string)$key] = [
            'label' => (string)($meta['label'] ?? $key),
            'unit' => (string)($meta['unit'] ?? ''),
            'color' => (string)($meta['color'] ?? '#36c5ff'),
        ];
    }
    return [
        'key' => (string)($component['key'] ?? ''),
        'title' => (string)($component['title'] ?? 'Componente'),
        'group' => (string)($component['group'] ?? 'generic'),
        'value' => is_numeric($component['value'] ?? null) ? (float)$component['value'] : null,
        'unit' => (string)($component['unit'] ?? ''),
        'detail' => (string)($component['detail'] ?? ''),
        'subtitle' => (string)($component['subtitle'] ?? ''),
        'series' => $series,
        'series_meta' => $seriesMeta,
        'accent' => (string)($component['accent'] ?? '#36c5ff'),
        'header' => (string)($component['header'] ?? ''),
    ];
}

function arc_server_feed(PDO $pdo, int $networkId, int $account, int $agentId): array {
    $rows = [];
    if ($agentId > 0) {
        try {
            $q = $pdo->prepare('SELECT t.payload_json,t.captured_at,UNIX_TIMESTAMP(t.captured_at) AS captured_unix FROM '.arc_table('agent_telemetry').' t WHERE t.network_id=? AND t.account_id=? AND t.agent_id=? AND t.event_type=\'server_metrics\' ORDER BY t.id DESC LIMIT 180');
            $q->execute([$networkId, $account, $agentId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Arcoll servidor feed: '.$e->getMessage());
        }
    }
    $samples = [];
    foreach (array_reverse($rows) as $row) {
        $raw = json_decode((string)($row['payload_json'] ?? ''), true);
        $m = is_array($raw) ? ($raw['metrics'] ?? null) : null;
        if (!is_array($m)) continue;
        $received = (int)($row['captured_unix'] ?? 0);
        $componentsRaw = is_array($m['components'] ?? null) ? $m['components'] : arc_server_fallback_components($m);
        $components = [];
        foreach ($componentsRaw as $component) {
            if (!is_array($component)) continue;
            $sanitized = arc_server_sanitize_component($component);
            if ($sanitized['key'] !== '') {
                $components[] = $sanitized;
            }
        }
        // Compatibilidade: remove o agregado Rede se houver interfaces individuais.
        // O gráfico Rede continua visível para agentes antigos que não identificam adaptadores.
        $hasInterface = false;
        foreach ($components as $item) {
            if (preg_match('/^network_[0-9]+$/', $item['key'])) {
                $hasInterface = true;
                break;
            }
        }
        $components = array_values(array_filter($components, static function(array $item): bool {
            // Não mostrar GPU sem qualquer medição de utilização.
            return !(($item['group'] ?? '') === 'gpu' && !is_numeric($item['value'] ?? null));
        }));
        if ($hasInterface) {
            $components = array_values(array_filter($components, static function(array $item): bool {
                return $item['key'] !== 'network_total';
            }));
        }
        $samples[] = [
            'time' => $received > 0 ? $received : (int)($m['time'] ?? 0),
            'details' => is_array($m['details'] ?? null) ? $m['details'] : [],
            'components' => $components,
        ];
    }
    $last = $samples ? $samples[count($samples)-1]['time'] : null;
    return [
        'ok' => true,
        'samples' => $samples,
        'updated_at' => $rows[0]['captured_at'] ?? null,
        'updated_unix' => $last,
        'server_time' => time(),
        'agent_selected' => $agentId > 0,
    ];
}

$feed = arc_server_feed($pdo, (int)$networkId, (int)$account, (int)$selectedAgentId);
if (($_GET['feed'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($feed, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
require __DIR__.'/_topo.php';
?>
<style>
.arc-server{display:grid;grid-template-columns:255px minmax(0,1fr);gap:12px;height:clamp(440px,calc(100dvh - 175px),1000px);min-height:0;padding:12px;background:var(--surface);border-radius:14px;color:var(--text);box-shadow:inset 0 0 0 1px rgba(88,112,140,.25)}
.arc-server-sidebar{display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.arc-server-sidebar h3{margin:0;padding:12px 14px 8px;color:var(--text);font-size:12px;letter-spacing:.08em;text-transform:uppercase}
.arc-server-list{display:grid;grid-template-columns:repeat(var(--srv-columns,1),minmax(0,1fr));grid-template-rows:repeat(var(--srv-rows,1),minmax(0,1fr));gap:var(--srv-gap,5px);padding:0 6px 6px;overflow:hidden;min-height:0;flex:1}
.arc-server-item{display:flex;gap:10px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:var(--srv-padding,6px);min-height:0;min-width:0;overflow:hidden;color:var(--text);cursor:pointer;text-align:left;transition:.18s ease all}
.arc-server-item.active{background:var(--surface-alt);border-color:var(--accent,#36c5ff);box-shadow:0 0 0 1px color-mix(in srgb,var(--accent,#36c5ff) 32%, transparent)}
.arc-server-item svg{width:var(--srv-spark-width,90px);height:var(--srv-spark-height,42px);flex:0 0 var(--srv-spark-width,90px);border-radius:6px;background:var(--surface);border:1px solid var(--border)}
.arc-server-list.srv-minimal .arc-server-item svg{display:none}.arc-server-item-text{display:flex;flex-direction:column;min-width:0;gap:2px}
.arc-server-item strong{font-size:var(--srv-font,13px);color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.arc-server-item small{font-size:var(--srv-small,11px);color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.arc-server-main{display:flex;flex-direction:column;min-width:0;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px}
.arc-server-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start}.arc-server-title{font-size:24px;font-weight:800;line-height:1.1}.arc-server-value{font-size:22px;font-weight:700;color:var(--primary);white-space:nowrap}
.arc-server-subtitle{font-size:13px;color:var(--muted);margin-top:4px}.arc-server-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0 8px}.arc-server-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px}.arc-server-stat span{display:block;color:var(--muted);font-size:11px;letter-spacing:.02em;text-transform:uppercase}.arc-server-stat strong{display:block;color:var(--text);font-size:18px;margin-top:2px}
.arc-server-chartwrap{position:relative;flex:1;min-height:340px;margin-top:6px}.arc-server-chart{position:absolute;inset:0;width:100%;height:100%;background:var(--surface);border:1px solid var(--border);border-radius:12px}.arc-server-tip{position:absolute;display:none;pointer-events:none;background:var(--surface);border:1px solid #3a8cff;border-radius:8px;padding:8px 10px;max-width:340px;z-index:4;color:var(--text);font-size:12px;white-space:pre-line;box-shadow:0 12px 30px rgba(0,0,0,.3)}
.arc-server-note{font-size:13px;color:var(--muted);line-height:1.45;margin-top:10px}.arc-server-foot{font-size:11px;color:var(--muted);margin-top:8px}
@media (min-width:601px) and (max-width:920px){.arc-server{grid-template-columns:180px minmax(0,1fr)}.arc-server-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.arc-server-chartwrap{min-height:200px}}
@media (max-width:600px){
 .arc-server{box-sizing:border-box;display:grid;grid-template-columns:70px minmax(0,1fr);gap:6px;height:calc(100dvh - 178px);min-height:360px;max-height:calc(100dvh - 150px);padding:6px;border-radius:12px;overflow:hidden}
 .arc-server-sidebar{height:100%;min-width:0;min-height:0;border-radius:9px;overflow:hidden}
 .arc-server-sidebar h3{font-size:0;padding:5px 2px 0;height:5px;flex:none}
 .arc-server-list{display:flex;flex-direction:column;flex:1;min-height:0;gap:5px;padding:5px 3px;overflow-y:auto;overflow-x:hidden;scrollbar-width:none}
 .arc-server-list::-webkit-scrollbar{display:none}
 .arc-server-item{box-sizing:border-box;display:flex;flex-direction:column;flex:0 0 auto;justify-content:center;align-items:center;gap:3px;width:100%;height:58px;min-height:58px;padding:5px 2px;border-radius:9px;text-align:center}
 .arc-server-item svg{display:none!important}
 .arc-server-item-text{display:flex;align-items:center;width:100%;max-width:100%;min-width:0;gap:2px;overflow:hidden}
 .arc-server-item strong{display:block;width:100%;font-size:10px;line-height:1.15;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
 .arc-server-item small{display:block;width:100%;font-size:9px;line-height:1.1;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
 .arc-server-main{box-sizing:border-box;min-width:0;min-height:0;height:100%;padding:9px 7px 8px;border-radius:9px;overflow:hidden}
 .arc-server-head{gap:3px;flex-wrap:nowrap;align-items:flex-start}
 .arc-server-head>div:first-child{min-width:0;flex:1}
 .arc-server-title{font-size:17px;line-height:1.12;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
 .arc-server-value{font-size:13px;max-width:45%;overflow:hidden;text-overflow:ellipsis}
 .arc-server-subtitle{font-size:10px;line-height:1.2;margin-top:3px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
 .arc-server-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:4px;margin:7px 0 4px}
 .arc-server-stat{min-width:0;padding:5px 4px;border-radius:7px}
 .arc-server-stat span{font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
 .arc-server-stat strong{font-size:12px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
 .arc-server-chartwrap{flex:1;min-height:105px;margin-top:3px}
 .arc-server-chart{border-radius:8px}
 .arc-server-note{font-size:10px;line-height:1.25;margin-top:6px;max-height:3.75em;overflow:auto;overflow-wrap:anywhere;scrollbar-width:none}
 .arc-server-foot{font-size:9px;line-height:1.2;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
 .arc-server-tip{max-width:min(190px,75vw);font-size:10px;padding:5px}
}
@media (max-width:600px) and (max-height:700px){.arc-server{height:calc(100dvh - 153px);max-height:calc(100dvh - 135px)}.arc-server-item{height:49px;min-height:49px}.arc-server-main{padding:6px}.arc-server-stats{margin:4px 0}.arc-server-note{max-height:2.5em}}
</style>
<section class="arc-server" aria-label="Desempenho do computador com agente Windows">
  <aside class="arc-server-sidebar">
    <h3>Componentes</h3>
    <div class="arc-server-list" id="arc-server-list"></div>
  </aside>
  <div class="arc-server-main">
    <div class="arc-server-head">
      <div>
        <div class="arc-server-title" id="arc-server-title">Servidor</div>
        <div class="arc-server-subtitle" id="arc-server-subtitle">Monitoramento passivo dos componentes detectados automaticamente.</div>
      </div>
      <div style="display:flex;gap:7px;align-items:center"><button type="button" id="arc-server-refresh" class="button" title="Atualizar telemetria" style="padding:5px 9px;min-height:32px;font-size:11px"><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar</button><div class="arc-server-value" id="arc-server-value">Aguardando leitura</div></div>
    </div>
    <div class="arc-server-stats">
      <div class="arc-server-stat"><span id="arc-stat-1-label">—</span><strong id="arc-stat-1-value">--</strong></div>
      <div class="arc-server-stat"><span id="arc-stat-2-label">—</span><strong id="arc-stat-2-value">--</strong></div>
      <div class="arc-server-stat"><span id="arc-stat-3-label">—</span><strong id="arc-stat-3-value">--</strong></div>
      <div class="arc-server-stat"><span id="arc-stat-4-label">—</span><strong id="arc-stat-4-value">--</strong></div>
    </div>
    <div class="arc-server-chartwrap" id="arc-server-wrap">
      <svg class="arc-server-chart" id="arc-server-chart" role="img" aria-label="Gráfico histórico do componente selecionado"></svg>
      <div class="arc-server-tip" id="arc-server-tip"></div>
    </div>
    <div class="arc-server-note" id="arc-server-note">Aguardando telemetria do computador instalado.</div>
    <div class="arc-server-foot" id="arc-server-update">Telemetria salva · clique em Atualizar para consultar novamente</div>
  </div>
</section>
<script>
(()=>{'use strict';
const dataUrl = new URL(window.location.href); dataUrl.searchParams.set('feed','1');
const NS='http://www.w3.org/2000/svg';
const state={selected:null,order:[],components:{},histories:{},serverData:<?=json_encode($feed,JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,fetchedAt:Date.now(),points:[]};
const $=id=>document.getElementById(id), list=$('arc-server-list'), chart=$('arc-server-chart'), wrap=$('arc-server-wrap'), tip=$('arc-server-tip');
function fmt(unit,v){ if(typeof v!=='number' || !isFinite(v)) return 'Sem dados'; if(unit==='%') return `${v.toFixed(1)}%`; if(unit==='Mbps') return `${v.toFixed(2)} Mbps`; if(unit==='ms') return `${v.toFixed(1)} ms`; return `${v.toFixed(2)} ${unit||''}`.trim(); }
function niceMax(unit, values, fallback){ const clean=values.filter(v=>typeof v==='number' && isFinite(v)); if(!clean.length) return fallback||100; const peak=Math.max(...clean); if(unit==='%') return 100; const steps=unit==='ms'?[10,20,30,50,75,100,150,200,300,500,750,1000]:[1,2,5,10,20,50,100,200,500,1000,2000,5000]; for(const step of steps){ if(peak*1.15<=step) return step; } return Math.max(fallback||10, peak*1.15); }
function svg(tag,attrs,text){ const n=document.createElementNS(NS,tag); Object.entries(attrs||{}).forEach(([k,v])=>n.setAttribute(k,v)); if(text!==undefined) n.textContent=text; return n; }
function rebuildState(){ state.components={}; state.histories={}; state.order=[]; const samples=(state.serverData.samples||[]); for(const sample of samples){ for(const comp of (sample.components||[])){ const key=comp.key; if(!key) continue; if(!state.components[key]){ state.order.push(key); } state.components[key]=comp; (state.histories[key] ||= []).push({time:sample.time, value:comp.value, detail:comp.detail||'', series:comp.series||{}}); } } if(!state.selected || !state.components[state.selected]) state.selected=state.order[0]||null; renderSidebar(); render(); }
function fitSidebar(){
 const sidebar=list.closest('.arc-server-sidebar'); if(!sidebar)return;
 if(window.matchMedia('(max-width: 600px)').matches){list.style.removeProperty('--srv-columns');list.style.removeProperty('--srv-rows');list.classList.add('srv-minimal');return;}
 const count=Math.max(1,state.order.length),height=Math.max(180,list.clientHeight),width=Math.max(180,list.clientWidth);
 let best=null;
 for(let cols=1;cols<=Math.min(4,count);cols++){
   const rows=Math.ceil(count/cols),cellH=(height-10-(rows-1)*4)/rows,cellW=(width-12-(cols-1)*4)/cols;
   if(cellW<72)continue;
   const score=Math.min(cellH,62)+(cellW>=158?14:0)-(cols-1)*4;
   if(!best||((cellH>=32&&best.cellH<32)||((cellH>=32)===(best.cellH>=32)&&score>best.score))) best={cols,rows,cellH,cellW,score};
 }
 if(!best)best={cols:1,rows:count,cellH:height/count,cellW:width};
 const compact=best.cellH<53||best.cellW<170;
 list.style.setProperty('--srv-columns',best.cols);list.style.setProperty('--srv-rows',best.rows);
 list.style.setProperty('--srv-gap','4px');list.style.setProperty('--srv-padding',best.cellH<42?'2px':'5px');
 list.style.setProperty('--srv-spark-width',best.cellW<120?'0px':compact?'44px':'90px');
 list.style.setProperty('--srv-spark-height',Math.max(15,Math.min(42,best.cellH-12))+'px');
 list.style.setProperty('--srv-font',best.cellH<38?'10px':'13px');list.style.setProperty('--srv-small',best.cellH<38?'9px':'11px');
 list.classList.toggle('srv-minimal',best.cellW<120);
}
if(typeof ResizeObserver!=='undefined')new ResizeObserver(()=>fitSidebar()).observe(list);
window.addEventListener('resize',()=>{fitSidebar();if(state.selected)render();});
function renderSidebar(){ list.innerHTML=''; for(const key of state.order){ const comp=state.components[key]; const button=document.createElement('button'); button.type='button'; button.className='arc-server-item'+(key===state.selected?' active':''); button.style.setProperty('--accent', comp.accent||'#36c5ff'); button.innerHTML='<svg viewBox="0 0 96 54" preserveAspectRatio="none"></svg><span class="arc-server-item-text"><strong></strong><small></small></span>'; button.querySelector('strong').textContent=comp.title; const entries=state.histories[key]||[]; const last=entries[entries.length-1]||{}; button.querySelector('small').textContent=comp.subtitle || (comp.group==='internet' ? fmt('ms',(last.series||{}).latency_ms) : fmt(comp.unit,last.value)); button.title=comp.title + ' — ' + button.querySelector('small').textContent; drawSpark(button.querySelector('svg'), comp, entries); button.addEventListener('click',()=>{ state.selected=key; renderSidebar(); render(); }); list.appendChild(button); } fitSidebar(); }
function drawSpark(svgNode, comp, entries){ svgNode.innerHTML=''; const w=96,h=54; const values=(comp.group==='internet' ? entries.map(e=>(e.series||{}).latency_ms) : entries.map(e=>e.value)); const unit=(comp.group==='internet'?'ms':comp.unit||'%'); const maxv=niceMax(unit,values,unit==='%'?100:10); const pts=[]; entries.forEach((entry,idx)=>{ const value=comp.group==='internet'?(entry.series||{}).latency_ms:entry.value; if(typeof value!=='number' || !isFinite(value)) return; const x=2+((w-6)*(idx/Math.max(1,entries.length-1))); const y=(h-6)-((Math.max(0,Math.min(maxv,value))/maxv)*(h-10)); pts.push([x,y]); }); if(pts.length>1){ const poly=pts.map(([x,y])=>`${x.toFixed(1)},${y.toFixed(1)}`).join(' '); svgNode.appendChild(svg('polygon',{points:`${pts[0][0].toFixed(1)},${h-2} ${poly} ${pts[pts.length-1][0].toFixed(1)},${h-2}`,fill:'rgba(45,197,255,.15)'})); svgNode.appendChild(svg('polyline',{points:poly,fill:'none',stroke:comp.accent||'#36c5ff','stroke-width':1.3})); } else if(pts.length===1){ svgNode.appendChild(svg('circle',{cx:pts[0][0],cy:pts[0][1],r:2.2,fill:comp.accent||'#36c5ff'})); } }
function updateStats(comp, entries){ let pairs=[]; if(comp.group==='internet'){ const last=entries[entries.length-1]||{}; const s=last.series||{}; pairs=[['Latência',fmt('ms',s.latency_ms)],['Download',fmt('Mbps',s.download_mbps)],['Upload',fmt('Mbps',s.upload_mbps)],['Perdas',fmt('%',s.loss_percent)]]; }else{ const nums=entries.map(e=>e.value).filter(v=>typeof v==='number'&&isFinite(v)); const cur=nums.length?nums[nums.length-1]:null; pairs=[['Atual',fmt(comp.unit,cur)],['Mínimo',fmt(comp.unit,nums.length?Math.min(...nums):null)],['Máximo',fmt(comp.unit,nums.length?Math.max(...nums):null)],['Amostras',String(nums.length)]]; } pairs.forEach((pair,i)=>{ $(`arc-stat-${i+1}-label`).textContent=pair[0]; $(`arc-stat-${i+1}-value`).textContent=pair[1]; }); }
function drawGrid(maxv){ const mobile=window.matchMedia('(max-width:600px)').matches; const W=Math.max(mobile?160:320,wrap.clientWidth), H=Math.max(mobile?105:220,wrap.clientHeight), x0=mobile?34:70,y0=22,x1=W-(mobile?8:18),y1=H-(mobile?19:34); chart.setAttribute('viewBox',`0 0 ${W} ${H}`); chart.innerHTML=''; for(let i=0;i<=5;i++){ const y=y0+((y1-y0)*i/5); chart.appendChild(svg('line',{x1:x0,y1:y,x2:x1,y2:y,stroke:'#2a2a2a'})); chart.appendChild(svg('text',{x:x0-8,y:y+4,fill:'#9f9f9f','font-size':mobile?8:11,'text-anchor':'end'},String((maxv*(5-i)/5).toFixed(maxv<10?1:0)))); } for(let i=0;i<=10;i++){ const x=x0+((x1-x0)*i/10); chart.appendChild(svg('line',{x1:x,y1:y0,x2:x,y2:y1,stroke:'#1c1c1c'})); }
 chart.appendChild(svg('text',{x:x0,y:H-10,fill:'#9f9f9f','font-size':mobile?8:11,'text-anchor':'start'},'60 segundos'));
 chart.appendChild(svg('text',{x:x1,y:H-10,fill:'#9f9f9f','font-size':mobile?8:11,'text-anchor':'end'},'0'));
 return {W,H,x0,y0,x1,y1}; }
function render(){ const comp=state.components[state.selected]; if(!comp){ return; } const entries=state.histories[state.selected]||[]; $('arc-server-title').textContent=comp.title; $('arc-server-subtitle').textContent=comp.header || comp.subtitle || ''; $('arc-server-note').textContent=comp.detail || 'Aguardando medições da máquina.'; if(comp.group==='internet'){ const last=entries[entries.length-1]||{}; $('arc-server-value').textContent=fmt('ms',(last.series||{}).latency_ms); } else { $('arc-server-value').textContent=fmt(comp.unit,comp.value); } $('arc-server-value').style.color=comp.accent||'#36c5ff'; updateStats(comp,entries); state.points=[]; if(comp.group==='internet'){ renderInternet(comp,entries); } else { renderSingle(comp,entries); } $('arc-server-update').textContent='Última consulta da telemetria · ' + new Date().toLocaleTimeString('pt-BR'); }
function renderSingle(comp, entries){ const values=entries.map(e=>e.value); const maxv=niceMax(comp.unit,values,comp.unit==='%'?100:10); const g=drawGrid(maxv); const pts=[]; entries.forEach((e,idx)=>{ const value=e.value; if(typeof value!=='number' || !isFinite(value)) return; const x=g.x0+((g.x1-g.x0)*(idx/Math.max(1,entries.length-1))); const y=g.y1-((Math.max(0,Math.min(maxv,value))/maxv)*(g.y1-g.y0)); pts.push([x,y,e]); }); if(pts.length>1){ const poly=pts.map(([x,y])=>`${x.toFixed(1)},${y.toFixed(1)}`).join(' '); chart.appendChild(svg('polygon',{points:`${pts[0][0].toFixed(1)},${g.y1} ${poly} ${pts[pts.length-1][0].toFixed(1)},${g.y1}`,fill:'rgba(54,197,255,.16)'})); chart.appendChild(svg('polyline',{points:poly,fill:'none',stroke:comp.accent||'#36c5ff','stroke-width':2.1})); } else if(pts.length===1){ chart.appendChild(svg('circle',{cx:pts[0][0],cy:pts[0][1],r:3,fill:comp.accent||'#36c5ff'})); }
 state.points=pts.map(([x,y,e])=>({x,y,time:e.time,value:e.value,detail:e.detail})); }
function renderInternet(comp, entries){ const g=drawGrid(100); const legend=[]; state.points=[]; Object.entries(comp.series_meta||{}).forEach(([key,meta],idx)=>{ const vals=entries.map(e=>(e.series||{})[key]); const maxv=niceMax(meta.unit,vals,meta.unit==='%'?100:10); const pts=[]; entries.forEach((e,i)=>{ const value=(e.series||{})[key]; if(typeof value!=='number' || !isFinite(value)) return; const x=g.x0+((g.x1-g.x0)*(i/Math.max(1,entries.length-1))); const y=g.y1-((Math.max(0,Math.min(maxv,value))/maxv)*(g.y1-g.y0)); pts.push([x,y,e,value]); }); if(pts.length>1){ chart.appendChild(svg('polyline',{points:pts.map(([x,y])=>`${x.toFixed(1)},${y.toFixed(1)}`).join(' '),fill:'none',stroke:meta.color||'#36c5ff','stroke-width':2})); } else if(pts.length===1){ chart.appendChild(svg('circle',{cx:pts[0][0],cy:pts[0][1],r:2.5,fill:meta.color||'#36c5ff'})); }
 state.points.push(...pts.map(([x,y,e,value])=>({x,y,time:e.time,value,detail:`${meta.label}: ${fmt(meta.unit,value)}`}))); legend.push(meta); }); let lx=g.x0; legend.forEach(meta=>{ chart.appendChild(svg('rect',{x:lx,y:g.y0-14,width:10,height:10,fill:meta.color||'#36c5ff'})); chart.appendChild(svg('text',{x:lx+14,y:g.y0-5,fill:'#bfbfbf','font-size':8},meta.label)); lx+=mobile?59:90; }); }
wrap.addEventListener('mousemove',ev=>{ if(!state.points.length){ tip.style.display='none'; return; } const rect=wrap.getBoundingClientRect(); const x=ev.clientX-rect.left; const y=ev.clientY-rect.top; let best=null,bestDist=1e9; for(const point of state.points){ const dx=point.x-(x*(chart.viewBox.baseVal.width/rect.width)); const dy=point.y-(y*(chart.viewBox.baseVal.height/rect.height)); const dist=dx*dx+dy*dy; if(dist<bestDist){ bestDist=dist; best=point; } } if(!best || bestDist>1000){ tip.style.display='none'; return; } tip.textContent=`${new Date(best.time*1000).toLocaleString('pt-BR')}\n${best.detail}`; tip.style.display='block'; tip.style.left=Math.min(rect.width-260, ev.clientX-rect.left+14)+'px'; tip.style.top=Math.max(8, ev.clientY-rect.top+14)+'px'; });
wrap.addEventListener('mouseleave',()=>{ tip.style.display='none'; });
async function refreshFeed(){ try{ const res=await fetch(dataUrl.toString(),{cache:'no-store'}); const json=await res.json(); if(json && json.ok){ state.serverData=json; state.fetchedAt=Date.now(); rebuildState(); } }catch(_e){} }
rebuildState();$('arc-server-refresh').addEventListener('click',refreshFeed);
})();
</script>
<?php require __DIR__.'/_rodape.php'; ?>
