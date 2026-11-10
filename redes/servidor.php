<?php
/* Caminho: redes/servidor.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

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
        'Latência, download, upload e perdas acompanhados pelo gestor.',
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
    // A telemetria do agente já contém a descoberta física: expandir componentes reais
    // na apresentação em vez de repetir agregados sintéticos de disco/rede.
    $meta = is_array($m['meta'] ?? null) ? $m['meta'] : [];
    $cpuName = trim((string)($meta['cpu_name'] ?? ''));
    $push('cpu','CPU','cpu',$m['CPU'] ?? null,'%',$details['CPU'] ?? 'Calculando uso da CPU...',$cpuName ?: 'Processador',[],[],'#24c8ff',$cpuName);
    $mem = is_array($meta['memory'] ?? null) ? $meta['memory'] : [];
    $memTotal = is_numeric($mem['total'] ?? null) ? (float)$mem['total'] : 0;
    $memUsed = is_numeric($mem['used'] ?? null) ? (float)$mem['used'] : null;
    $push('memory','Memória','memory',$m['Memória'] ?? null,'%',$details['Memória'] ?? 'Leitura de memória indisponível',$memTotal > 0 ? number_format($memTotal / 1073741824, 1, ',', '.') . ' GB' : 'Memória do sistema',
        $memTotal > 0 ? ['used_gb'=>$memUsed === null ? null : $memUsed / 1073741824,'available_gb'=>is_numeric($mem['available'] ?? null) ? (float)$mem['available']/1073741824 : null] : [],
        $memTotal > 0 ? ['used_gb'=>['label'=>'Em uso','unit'=>'GB','color'=>'#818cf8'],'available_gb'=>['label'=>'Disponível','unit'=>'GB','color'=>'#38bdf8']] : [], '#818cf8');
    $drives = is_array($meta['drives'] ?? null) ? $meta['drives'] : [];
    if ($drives) {
        foreach (array_slice($drives, 0, 64) as $index => $drive) {
            if (!is_array($drive)) continue;
            $name = trim((string)($drive['name'] ?? ''));
            $driveIndex = is_numeric($drive['index'] ?? null) ? (int)$drive['index'] : $index;
            $size = is_numeric($drive['total'] ?? null) ? (float)$drive['total']/1073741824 : null;
            $used = is_numeric($drive['used'] ?? null) ? (float)$drive['used']/1073741824 : null;
            $push('disk_'.$driveIndex, 'Disco '.$driveIndex,'disk',$drive['percent'] ?? null,'%',
                $used !== null && $size !== null ? sprintf('Ocupação: %.1f GB de %.1f GB', $used, $size) : 'Unidade detectada',
                $name ?: 'Unidade '.$driveIndex, [], [], '#a3be36', $name);
        }
    } else {
        $push('disks','Discos','disk',$m['Discos'] ?? null,'%',$details['Discos'] ?? 'Aguardando leitura dos discos','Armazenamento',[],[],'#8bcf26');
    }
    $interfaces = is_array($meta['interfaces'] ?? null) ? $meta['interfaces'] : [];
    if ($interfaces) {
        foreach (array_slice($interfaces, 0, 64) as $index=>$nic) {
            if (!is_array($nic)) continue;
            $name = trim((string)($nic['name'] ?? ''));
            $down = $nic['download_mbps'] ?? null;
            $up = $nic['upload_mbps'] ?? null;
            $push('network_'.$index, $name ?: 'Rede '.($index+1), 'network',is_numeric($down) && is_numeric($up) ? (float)$down+(float)$up : null,'Mbps',
                $name ?: 'Adaptador de rede', 'Receber / Enviar',
                ['download_mbps'=>$down, 'upload_mbps'=>$up],
                ['download_mbps'=>['label'=>'Receber','unit'=>'Mbps','color'=>'#e879a6'],'upload_mbps'=>['label'=>'Enviar','unit'=>'Mbps','color'=>'#fb9b78']], '#e879a6',$name);
        }
    } else {
        $push('network_total','Rede','network',$m['Rede'] ?? null,'Mbps',$details['Rede'] ?? 'Calculando taxa de rede...','Adaptadores ativos',
            ['download_mbps'=>$m['Rede_download'] ?? null,'upload_mbps'=>$m['Rede_upload'] ?? null],
            ['download_mbps'=>['label'=>'Receber','unit'=>'Mbps','color'=>'#e879a6'],'upload_mbps'=>['label'=>'Enviar','unit'=>'Mbps','color'=>'#fb9b78']], '#e879a6');
    }
    foreach (array_slice(is_array($meta['gpus'] ?? null) ? $meta['gpus'] : [], 0, 16) as $index=>$gpu) {
        if (!is_array($gpu) || !is_numeric($gpu['percent'] ?? null)) continue;
        $gpuName = trim((string)($gpu['name'] ?? ''));
        $push('gpu_'.$index, 'GPU '.$index,'gpu',$gpu['percent'],'%',$gpuName ?: 'Processador gráfico',$gpuName,[],[],'#c66bff',$gpuName);
    }
    if (empty($meta['gpus']) && is_numeric($m['GPU'] ?? null)) {
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

function arc_server_feed(PDO $pdo, int $networkId, int $account, int $gestorId): array {
    $rows = [];
    if ($gestorId > 0) {
        try {
            $q = $pdo->prepare('SELECT t.payload_json,t.captured_at,UNIX_TIMESTAMP(t.captured_at) AS captured_unix FROM '.arc_table('gestor_telemetry').' t WHERE t.network_id=? AND t.account_id=? AND t.gestor_id=? AND t.event_type=\'server_metrics\' ORDER BY t.id DESC LIMIT 60');
            $q->execute([$networkId, $account, $gestorId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            arc_log_error('Arcoll servidor feed: '.$e->getMessage());
        }
    }
    $samples = [];
    foreach (array_reverse($rows) as $row) {
        $raw = json_decode((string)($row['payload_json'] ?? ''), true);
        $m = is_array($raw) ? ($raw['metrics'] ?? null) : null;
        if (!is_array($m)) continue;
        $received = (int)($row['captured_unix'] ?? 0);
        $componentsRaw = is_array($m['components'] ?? null) && $m['components'] ? $m['components'] : arc_server_fallback_components($m);
        $components = [];
        foreach ($componentsRaw as $component) {
            if (!is_array($component)) continue;
            $sanitized = arc_server_sanitize_component($component);
            if ($sanitized['key'] !== '') {
                $components[] = $sanitized;
            }
        }
        // Compatibilidade: remove o agregado Rede se houver interfaces individuais.
        // O gráfico Rede continua visível para gestores antigos que não identificam adaptadores.
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
            'performance' => is_array($m['performance'] ?? null) ? $m['performance'] : null,
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
        'gestor_selected' => $gestorId > 0,
    ];
}

$feed = arc_server_feed($pdo, (int)$networkId, (int)$account, (int)$selectedGestorId);
if (($_GET['feed'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($feed, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
require __DIR__.'/_topo.php';
?>
<style>
/* Layout compartilhado com o monitor nativo do AGestor Windows. */
/* Caminho: gestores/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/server.css */
#performance.arc-platform-performance{font:12px 'Segoe UI',sans-serif;color:var(--text,#e7f7ff);background:var(--surface,#112b44);color-scheme:dark}#performance.arc-platform-performance, #performance.arc-platform-performance *{box-sizing:border-box}#performance [hidden]{display:none!important}button,input,select{font:inherit;color:inherit}button,select{background:var(--alt,#0c2238);border:1px solid var(--line,#2b455f);border-radius:6px;padding:7px;cursor:pointer}button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--accent,#60a5fa);outline-offset:2px}#performance{height:100dvh;display:flex;flex-direction:column;min-height:0}.perf-toolbar{display:flex;align-items:center;gap:6px;padding:7px 10px;border-bottom:1px solid var(--line,#2b455f);background:var(--alt,#0c2238);flex-shrink:0;white-space:nowrap;overflow-x:auto}.perf-search{display:flex;align-items:center;gap:8px;height:33px;border:1px solid var(--line,#2b455f);border-radius:8px;flex:1;min-width:140px;padding:4px 10px}.perf-search input{border:0;background:transparent;outline:none;min-width:0;width:100%}.cadence{display:flex;align-items:center;gap:7px;color:var(--muted,#adc5d7)}#sample-state{font-size:10px;color:var(--muted,#adc5d7)}.perf-layout{flex:1;min-height:0;display:grid;grid-template-columns:210px minmax(0,1fr)}#components{overflow:auto;min-height:0;border-right:1px solid var(--line,#2b455f);padding:7px}.resource{display:flex;width:100%;text-align:left;gap:10px;align-items:center;border:1px solid transparent;background:transparent;border-radius:4px;margin:2px 0;padding:10px 7px;min-width:0}.resource[aria-pressed=true]{border-color:var(--line,#2b455f);background:var(--alt,#0c2238)}.resource:hover{border-color:var(--accent,#60a5fa)}.resource svg{width:61px;height:34px;border:1px solid var(--line,#2b455f);flex-shrink:0}.resource>span{display:flex;flex-direction:column;gap:4px;min-width:0}.resource strong,.resource small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.resource small{color:var(--muted,#adc5d7);font-size:10px}.resource strong{font-size:12px}.perf-detail{overflow:auto;min-width:0;padding:20px 24px 14px}.perf-detail>header{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:16px}.perf-detail h1{margin:3px 0 0;font-size:27px;font-weight:500}#component-kind{font-size:9px;letter-spacing:2px;color:var(--accent,#60a5fa);text-transform:uppercase}#component-model{max-width:60%;font-size:13px;text-align:right;overflow-wrap:anywhere}#series-controls{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;color:var(--muted,#adc5d7);font-size:11px}#series-controls label{display:flex;gap:5px;align-items:center}#charts{display:grid;grid-template-columns:minmax(0,1fr);gap:15px}#charts.gpu{grid-template-columns:repeat(2,minmax(0,1fr))}.chart{margin:0;min-width:0}.chart.wide{grid-column:1/-1}.chart figcaption{display:flex;justify-content:space-between;margin-bottom:5px;color:var(--muted,#adc5d7);font-size:11px}.chart svg{display:block;width:100%;height:230px;border:1px solid var(--line,#2b455f);background:var(--alt,#0c2238)}.chart.compact svg,#charts.gpu svg{height:120px}.chart .scale{display:flex;justify-content:space-between;font-size:9px;color:var(--muted,#adc5d7);padding-top:4px}.chart .grid{stroke:var(--line,#2b455f);stroke-width:1;vector-effect:non-scaling-stroke}.chart-empty{font-size:11px;color:var(--muted,#adc5d7);padding:22px;border:1px dashed var(--line,#2b455f)}#component-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:18px 25px;margin-top:24px}#component-stats>div{min-width:0}#component-stats dt{font-size:11px;color:var(--muted,#adc5d7);margin-bottom:5px}#component-stats dd{margin:0;font-size:18px;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}#component-stats dd.long{font-size:12px}#component-note{font-size:11px;color:var(--muted,#adc5d7);line-height:1.6;margin-top:22px}#perf-warning{padding:10px;border:1px solid #b78e37;color:var(--text,#e7f7ff);font-size:11px}#composition{margin-top:18px}#composition h2{font-size:11px;font-weight:400;color:var(--muted,#adc5d7)}.composition{height:35px;border:1px solid var(--accent,#60a5fa);display:flex;background:var(--alt,#0c2238)}.composition>span{background:color-mix(in srgb,var(--accent,#60a5fa) 30%,transparent);border-right:1px solid var(--accent,#60a5fa)}#composition p{font-size:10px;color:var(--muted,#adc5d7)}.perf-footer{display:none}#components:empty::after{content:'Descobrindo hardware…';display:block;padding:15px;color:var(--muted,#adc5d7)}@media(max-width:800px){.perf-layout{grid-template-columns:165px minmax(0,1fr)}.resource{gap:6px}.resource svg{width:42px}.perf-detail{padding:14px}.perf-detail h1{font-size:23px}.perf-detail>header{display:block}#component-model{max-width:none;text-align:left;font-size:11px}#sample-state{display:none}#charts.gpu{grid-template-columns:1fr}}@media(prefers-reduced-motion:no-preference){.resource{transition:background .2s,border-color .2s}}

#charts.internet{grid-template-columns:repeat(2,minmax(0,1fr))}#charts.internet .chart svg{height:150px}.perf-detail[data-kind=internet] #component-stats{margin:0 0 18px;gap:12px 18px;grid-template-columns:repeat(auto-fit,minmax(115px,1fr))}.perf-detail[data-kind=internet] #component-stats dd{font-size:17px}.perf-detail[data-kind=internet] #component-stats dd.long{font-size:11px}@media(max-width:800px){#charts.internet{grid-template-columns:minmax(0,1fr)}}


#performance.arc-platform-performance{--text:var(--text,#e7f7ff);--alt:var(--surface-alt,#0c2238);--line:var(--border,#2b455f);--accent:var(--primary,#60a5fa);--muted:var(--muted,#adc5d7);color:var(--text);background:var(--surface);height:calc(100dvh - 155px);min-height:380px;max-width:100%;font:12px 'Segoe UI',sans-serif}
#performance.arc-platform-performance button,#performance.arc-platform-performance input,#performance.arc-platform-performance select{font:inherit;color:inherit}
#performance.arc-platform-performance .perf-toolbar{background:var(--surface-alt)}
#performance.arc-platform-performance .resource[aria-pressed=true]{background:var(--surface-alt)}
#performance.arc-platform-performance #component-stats{margin-top:18px}
#performance.arc-platform-performance .perf-detail{padding:14px 18px}
#performance.arc-platform-performance .chart svg{height:175px}
#performance.arc-platform-performance #charts.internet .chart svg{height:145px}
#performance.arc-platform-performance .perf-footer{display:block;flex:none;padding:4px 10px;font-size:10px;color:var(--muted)}
@media(max-width:700px){#performance.arc-platform-performance{height:calc(100dvh - 140px)}#performance.arc-platform-performance .perf-layout{grid-template-columns:135px minmax(0,1fr)}#performance.arc-platform-performance .resource svg{width:32px}#performance.arc-platform-performance .perf-detail{padding:10px}}


/* Barra única de ferramentas — padrão visual do Painel GesTI/AGestor. */
.arc-redes-page:has(#performance) .arc-redes-conteudo{padding:0!important;display:flex;flex-direction:column;min-height:0;overflow:hidden}
#performance.arc-platform-performance{flex:1 1 auto;height:auto;min-height:0;max-height:none;background:#172536;--surface:#172536;--surface-alt:#1e2c3e;--text:#e9f1fd;--border:#394658}
#performance.arc-platform-performance .perf-toolbar{width:100%;min-width:0;min-height:46px;display:flex;align-items:center;gap:6px;padding:7px 10px;flex-wrap:nowrap;overflow:visible;background:#1e2c3e;border-bottom:1px solid #394658}
#performance.arc-platform-performance .perf-search{order:0;flex:1 1 auto;width:auto;min-width:110px;max-width:none;height:32px;padding:4px 10px;border:1px solid #394658;border-radius:8px;background:#182638;color:#dce9fa}
#performance.arc-platform-performance .perf-search input{width:100%;min-width:0;height:100%;padding:0;border:0!important;background:transparent!important;box-shadow:none!important;color:#dce9fa;outline:0}
#performance.arc-platform-performance .cadence{order:1;flex:0 0 auto;gap:6px;min-width:0}
#performance.arc-platform-performance .perf-toolbar select{flex:0 0 auto;width:auto;max-width:132px;height:32px;min-height:32px;padding:4px 8px;border:1px solid #394658;border-radius:7px;background:#182638;color:#e9f1fd;box-shadow:none}
#performance.arc-platform-performance #chart-style{order:2;max-width:105px}
#performance.arc-platform-performance .arc-tool-center-inline{order:3;margin-left:0;flex:0 0 auto}
#performance.arc-platform-performance .arc-tool-center-trigger{width:32px;height:32px;min-width:32px;min-height:32px;border:1px solid #394658;border-radius:7px;background:#182638;box-shadow:none;color:#e9f1fd}
@media(max-width:660px){#performance.arc-platform-performance .perf-toolbar{flex-wrap:wrap}#performance.arc-platform-performance .perf-search{flex-basis:100%;order:0}#performance.arc-platform-performance .cadence{order:1}#performance.arc-platform-performance #chart-style{order:2}#performance.arc-platform-performance .arc-tool-center-inline{order:3;margin-left:auto}.perf-filter-label{display:none}}
</style>
<main id="performance" class="arc-platform-performance" aria-label="Servidor do computador">
<div class="perf-toolbar"><label class="perf-search">⌕ <input id="component-search" type="search" placeholder="Buscar componente…" aria-label="Buscar componente"></label><label class="cadence"><span class="perf-filter-label">Coleta</span><select id="cadence" aria-label="Intervalo do monitoramento"><option value="1">Contínuo</option><option value="5">5 segundos</option><option value="10">10 segundos</option><option value="30">30 segundos</option><option value="60">60 segundos</option><option value="120">120 segundos</option><option value="0">Pausar</option></select></label><select id="chart-style" aria-label="Estilo do gráfico"><option value="area">Área</option><option value="line">Linhas</option><option value="bar">Barras</option></select></div><div class="perf-layout"><nav id="components" aria-label="Componentes detectados"></nav><section class="perf-detail" tabindex="0"><header><div><span id="component-kind">DESEMPENHO</span><h1 id="component-title">Preparando monitoramento</h1></div><p id="component-model">Lendo os provedores do Windows…</p></header><p id="perf-warning" role="status" hidden></p><div id="series-controls" role="group" aria-label="Séries do componente"></div><div id="charts"></div><div id="composition" hidden><h2>Composição da memória física</h2><div class="composition"><span></span></div><p>Em uso / disponível</p></div><dl id="component-stats"></dl><p id="component-note"></p></section></div><footer class="perf-footer" aria-hidden="true"></footer>
</main>
<script>
/* Caminho: gestores/servidor/Arcoll.WinUI3/Assets/hybrid/dashboard/server.js */
(()=>{'use strict';const $=id=>document.getElementById(id),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));let state=null,selected='internet',sequence=-1,seconds=1;const history=new Map(),excluded=new Set(),events=[];const colors={internet:'#38bdf8',cpu:'#38bdf8',memory:'#818cf8',disk:'#a3be36',gpu:'#d879e8',network:'#ea7aa2'};const send=value=>{ if(value?.action==='cadence'){configureRefresh(value.seconds);} };const latest=c=>c.kind==='internet'?c.series[0]:c.series.find(s=>s.unit==='%')||c.series[0];const fmt=(v,u)=>Number.isFinite(v)?v.toLocaleString('pt-BR',{maximumFractionDigits:2})+' '+u:'Indisponível';
function addActivity(text){events.unshift({at:new Date().toISOString(),text});events.splice(100);window.dispatchEvent(new CustomEvent('arc-tool-activity',{detail:{message:text}}));window.dispatchEvent(new CustomEvent('ti-activity',{detail:{text,at:events[0].at}}));}
function footer(){const text=state?`Servidor · ${seconds===0?'Pausado':seconds===1?'Contínuo':seconds+' s'} · Descoberta: ${state.discoveryAt?new Date(state.discoveryAt).toLocaleTimeString('pt-BR'):'em andamento'}`:'Servidor · Consultando componentes';document.querySelector('.perf-footer').textContent=text;}
function receive(message){if(message?.type==='performance-error'){addActivity(message.message);$('perf-warning').textContent=message.message;$('perf-warning').hidden=false;return;}if(message?.type!=='performance')return;seconds=Number(message.seconds)||15;if(message.snapshot){const incoming=message.snapshot;if(incoming.sequence>sequence){const previous=state;sequence=incoming.sequence;state=incoming;const identities=new Set(state.components.map(c=>c.key));for(const k of history.keys())if(!identities.has(k.split('|')[0]))history.delete(k);for(const c of state.components)for(const s of c.series){const key=c.key+'|'+s.key;const rows=history.get(key)||[];rows.push({t:Date.parse(state.at),v:Number.isFinite(s.value)?s.value:null});if(rows.length>120)rows.shift();history.set(key,rows);}if(!previous)addActivity('Componentes detectados: '+state.components.length);else{for(const c of state.components)if(!previous.components.some(p=>p.key===c.key))addActivity(c.title+' conectado');for(const c of previous.components)if(!identities.has(c.key))addActivity(c.title+' removido');}for(const warning of state.warnings||[])if(!events.some(e=>e.text===warning))addActivity(warning);}}render();}
function render(){footer();if(!state)return;const q=$('component-search').value.toLocaleLowerCase('pt-BR');if(!state.components.some(c=>c.key===selected))selected=state.components[0]?.key;const components=$('components');components.replaceChildren();for(const c of state.components.filter(c=>(c.title+' '+c.model).toLocaleLowerCase('pt-BR').includes(q))){const s=latest(c),b=document.createElement('button');b.className='resource';b.dataset.key=c.key;b.setAttribute('aria-pressed',String(c.key===selected));b.innerHTML=`${spark(c,s)}<span><strong>${esc(c.title)}</strong><small title="${esc(c.model)}">${esc(c.model)}</small><small>${esc(s?fmt(s.value,s.unit):'Sem contadores')}</small></span>`;components.append(b);}if(!components.children.length){const p=document.createElement('p');p.textContent='Nenhum componente encontrado.';components.append(p);}detail();}
function spark(c,s){if(!s)return '<svg viewBox="0 0 100 40" aria-hidden="true"></svg>';const rows=history.get(c.key+'|'+s.key)||[];const scale=s.maximum||Math.max(1,...rows.map(p=>p.v||0));let points='';rows.forEach((p,i)=>{if(p.v!==null)points+=`${i/119*100},${40-Math.min(1,p.v/scale)*38} `;});return `<svg viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true"><polyline points="${points}" fill="none" stroke="${colors[c.kind]||'#38bdf8'}" stroke-width="1"/></svg>`;}
function detail(){const c=state.components.find(c=>c.key===selected);if(!c)return;$('component-title').textContent=c.title;$('component-kind').textContent=c.kind==='network'?'Rede':c.kind;$('component-model').textContent=c.model;$('component-note').textContent=c.note||'';$('component-stats').innerHTML=c.stats.map(s=>`<div><dt>${esc(s.label)}</dt><dd class="${s.value.length>24?'long':''}">${esc(s.value)}</dd></div>`).join('');$('perf-warning').hidden=!(state.warnings||[]).length;$('perf-warning').textContent=(state.warnings||[]).join(' · ');$('series-controls').innerHTML=c.series.map(s=>`<label><input type="checkbox" data-series="${esc(c.key+'|'+s.key)}" ${excluded.has(c.key+'|'+s.key)?'':'checked'}>${esc(s.label)}</label>`).join('');const shown=c.series.filter(s=>!excluded.has(c.key+'|'+s.key));$('charts').className=c.kind==='gpu'?'gpu':c.kind==='internet'?'internet':'';$('component-stats').parentElement.dataset.kind=c.kind;if(c.kind==='internet')$('charts').before($('component-stats'));else $('composition').after($('component-stats'));$('charts').innerHTML=shown.length?shown.map((s,i)=>chart(c,s,i)).join(''):'<p class="chart-empty">Nenhuma série selecionada ou contadores não disponibilizados pelo Windows.</p>';$('composition').hidden=c.kind!=='memory';if(c.kind==='memory'){const value=c.series[0]?.value;$('composition').querySelector('span').style.width=Number.isFinite(value)?Math.max(0,Math.min(100,value))+'%':'0';$('composition').querySelector('p').textContent=Number.isFinite(value)?`Em uso ${fmt(value,'%')} · Disponível ${fmt(100-value,'%')}`:'Aguardando leitura da memória física';}}
function chart(c,s,index){const rows=history.get(c.key+'|'+s.key)||[],finite=rows.filter(p=>p.v!==null),max=s.maximum||Math.max(1,...finite.map(p=>p.v))*1.1;const end=Date.parse(state.at);const start=Math.min(end-Math.max(seconds||1,1)*60000,rows[0]?.t||end);const width=700,height=200;const xy=p=>({x:Math.max(0,Math.min(width,(p.t-start)/(end-start)*width)),y:Math.max(0,Math.min(height,height-p.v/max*height))});const color=colors[c.kind]||'#38bdf8';let grid='';for(let i=1;i<10;i++)grid+=`<path d="M${i*70},0V200 M0,${i*20}H700" class="grid"/>`;let paths='',segment=[];const flush=()=>{if(!segment.length)return;const points=segment.map(p=>xy(p));const line=points.map((p,i)=>(i?'L':'M')+p.x.toFixed(2)+','+p.y.toFixed(2)).join(' ');if($('chart-style').value==='bar')paths+=points.map(p=>`<path d="M${p.x},${height}V${p.y}" stroke="${color}" stroke-width="4"/>`).join('');else{if($('chart-style').value==='area')paths+=`<path d="${line} L${points.at(-1).x},200 L${points[0].x},200 Z" fill="${color}" fill-opacity=".18"/>`;paths+=`<path d="${line}" stroke="${color}" stroke-width="1.5" fill="none" vector-effect="non-scaling-stroke"/>`;if(points.length===1)paths+=`<circle cx="${points[0].x}" cy="${points[0].y}" r="2" fill="${color}"/>`;}segment=[];};let prev=null;for(const p of rows){if(p.v===null||(prev!==null&&p.t-prev>Math.max(seconds||1,state.seconds||1)*2500)){flush();}if(p.v!==null)segment.push(p);prev=p.t;}flush();const elapsed=Math.round((end-start)/1000);return `<figure class="chart ${index>0?'compact':''} ${s.unit==='GB'?'wide':''}"><figcaption><span>${esc(s.label)}</span><span>${esc(fmt(s.value,s.unit))} · escala ${esc(fmt(max,s.unit))}</span></figcaption><svg viewBox="0 0 700 200" preserveAspectRatio="none" role="img" aria-label="${esc(s.label)}: ${esc(fmt(s.value,s.unit))}">${grid}${paths}</svg><div class="scale"><span>${elapsed} segundos</span><span>${finite.length?'Agora':'Aguardando amostras válidas'}</span></div></figure>`;}
$('components').addEventListener('click',e=>{const b=e.target.closest('[data-key]');if(b){selected=b.dataset.key;render();}});$('component-search').addEventListener('input',render);$('chart-style').addEventListener('change',()=>state&&detail());$('series-controls').addEventListener('change',e=>{if(!e.target.dataset.series)return;if(e.target.checked)excluded.delete(e.target.dataset.series);else excluded.add(e.target.dataset.series);detail();});$('cadence').addEventListener('change',e=>{const n=Number(e.target.value);if(![0,1,5,10,30,60,120].includes(n))return;seconds=n;addActivity(n===0?'Coleta e descoberta pausadas':'Intervalo de coleta: '+(n===1?'contínuo':n+' segundos'));footer();send({action:'cadence',seconds:n});});let interval=null;
const feedUrl=new URL(location.href);feedUrl.searchParams.set('feed','1');
let lastTime=0;let shownMessage=false;
window.addEventListener('arc-tool-refresh',e=>{if(e.detail?.tool==='servidor')refreshFeed();});
async function refreshFeed(){
 try{
  const response=await fetch(feedUrl,{cache:'no-store',credentials:'same-origin'});
  if(!response.ok)throw Error('HTTP '+response.status);
  const feed=await response.json();
  if(!feed.ok)return;
  const samples=Array.isArray(feed.samples)?feed.samples:[];
  let incoming=samples.filter(s=>s.performance&&s.time>lastTime).sort((a,b)=>a.time-b.time);
  // O banco contém histórico de amostras; o WebView usa a mesma série temporal.
  for(const sample of incoming){
   lastTime=sample.time;
   const snap={...sample.performance,sequence:sample.time};
   if(!Array.isArray(snap.components))continue;
   receive({type:'performance',seconds:Number(snap.seconds)||15,snapshot:snap});
  }
  if(!state){
   window.dispatchEvent(new CustomEvent('arc-tool-activity',{detail:{message:'Aguardando telemetria nativa do AGestor'}}));
   $('component-title').textContent='Aguardando componentes';
   $('component-model').textContent='É necessário que o agente Windows envie sua próxima coleta de hardware.';
  }
  if(state&&feed.updated_at)document.querySelector('.perf-footer').textContent='Servidor · Telemetria: '+feed.updated_at;
 }catch(e){window.dispatchEvent(new CustomEvent('arc-tool-activity',{detail:{message:'Não foi possível consultar a telemetria: '+String(e.message||e)}}));}
}
function configureRefresh(seconds){if(interval)clearInterval(interval);if(seconds>0)interval=setInterval(()=>{if(!document.hidden)refreshFeed();},Math.max(5,seconds)*1000);}
// A coleta nativa e a consulta visual são ciclos distintos. A central da ferramenta
// controla a consulta visual; o seletor Coleta continua controlando o agente.
$('cadence').value='10';
configureRefresh(10); // Fallback apenas enquanto as preferências da central carregam.
window.addEventListener('arc-tool-sync-settings', event=>{
 if(event.detail?.tool!=='servidor')return;
 configureRefresh(0); // Evita dois timers simultâneos para a mesma telemetria.
});

refreshFeed();
})();

</script>
<?php require __DIR__.'/_rodape.php'; ?>
