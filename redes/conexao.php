<?php
declare(strict_types=1);
$arcSectionFile = 'conexao';
require __DIR__.'/_base.php';
// Localização confirmada: ação explícita do gestor, por rede e agente.
// A tabela mantém apenas o último ponto autorizado por origem/destino.
$geoTable = arc_table('network_geo_points');
$geoAvailable = true;
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS '.$geoTable.' (network_id BIGINT NOT NULL,account_id BIGINT NOT NULL,agent_id BIGINT NOT NULL DEFAULT 0,kind VARCHAR(12) NOT NULL,latitude DECIMAL(10,7) NOT NULL,longitude DECIMAL(11,7) NOT NULL,accuracy_m FLOAT NULL,source VARCHAR(40) NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(network_id,account_id,agent_id,kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) { $geoAvailable = false; error_log('Conexao GPS storage: '.$e->getMessage()); }
if (($_GET['gps_action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $fail = static function(string $error,int $status=400): never { http_response_code($status); echo json_encode(['ok'=>false,'error'=>$error],JSON_UNESCAPED_UNICODE); exit; };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Método não permitido.',405);
    if (!$geoAvailable) $fail('Armazenamento GPS indisponível. Verifique a permissão de criação de tabelas.',503);
    $posted = json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($posted) || !hash_equals((string)$csrf,(string)($posted['csrf'] ?? ''))) $fail('Sessão de segurança inválida.',403);
    $kind=(string)($posted['kind'] ?? '');
    if (!in_array($kind,['origin','dest'],true)) $fail('Ponto inválido.');
    $lat=$posted['latitude'] ?? null; $lon=$posted['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat)>90 || abs((float)$lon)>180) $fail('Coordenadas inválidas.');
    $acc=$posted['accuracy_m'] ?? null;
    if ($acc !== null && (!is_numeric($acc) || (float)$acc<0 || (float)$acc>100000)) $fail('Precisão inválida.');
    $src=in_array(($posted['source'] ?? ''),['browser_geolocation','manual_verified'],true) ? $posted['source'] : 'manual_verified';
    $pointAgent=$kind==='origin' ? 0 : (int)$selectedAgentId;
    $q=$pdo->prepare('INSERT INTO '.$geoTable.' (network_id,account_id,agent_id,kind,latitude,longitude,accuracy_m,source) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_m=VALUES(accuracy_m),source=VALUES(source),updated_at=CURRENT_TIMESTAMP');
    try {$q->execute([(int)$networkId,(int)$account,$pointAgent,$kind,(float)$lat,(float)$lon,$acc===null?null:(float)$acc,$src]);}catch(Throwable $e){error_log('Conexao GPS save: '.$e->getMessage());$fail('Não foi possível salvar localização.',500);}
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}
$geoPoints=[];
if ($geoAvailable) {
    try {
        $q=$pdo->prepare('SELECT kind,agent_id,latitude,longitude,accuracy_m,source,updated_at FROM '.$geoTable.' WHERE network_id=? AND account_id=? AND (agent_id=0 OR agent_id=?) ORDER BY updated_at DESC');
        $q->execute([(int)$networkId,(int)$account,(int)$selectedAgentId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $pt){$key=(string)$pt['kind'];if(!isset($geoPoints[$key]))$geoPoints[$key]=$pt;}
    } catch(Throwable $e){error_log('Conexao GPS read: '.$e->getMessage());}
}
$dashboardEvents = $remoteByType['dashboard'] ?? [];
$diagnosticEvents = $remoteByType['diagnostic'] ?? [];
$latestEvent = $dashboardEvents[0] ?? null;
$payload = $latestEvent ? json_decode((string)$latestEvent['payload_json'], true) : [];
if (!is_array($payload)) $payload = [];
$latestDiagnostic = $diagnosticEvents[0] ?? null;
$diagPayload = $latestDiagnostic ? json_decode((string)$latestDiagnostic['payload_json'], true) : [];
if (!is_array($diagPayload)) $diagPayload = [];
$diagText = (string)($diagPayload['text'] ?? '');
if (isset($diagPayload['schema']) && (int)$diagPayload['schema'] === 1) {
  $diagText = (string)json_encode(array_intersect_key($diagPayload, array_flip(['captured_at','machine','os','public_ip','provider','interface','latency','route','errors'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
$diagFacts = [];
foreach (preg_split('/\r\n|\r|\n/', $diagText) ?: [] as $line) {
    $parts = explode(': ', $line, 2);
    if (count($parts) === 2) $diagFacts[trim($parts[0])] = trim($parts[1]);
}
$publicIp = trim((string)($diagPayload['public_ip'] ?? $payload['public_ip'] ?? $network['public_ip'] ?? ''));
if (!filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) $publicIp = '';
$localIp = (string)($diagPayload['interface']['ip'] ?? $payload['local_ip'] ?? $diagFacts['IP local'] ?? '');
$gateway = (string)($diagPayload['interface']['gateway'] ?? $payload['gateway'] ?? $diagFacts['Gateway'] ?? '');
$dns = $diagPayload['interface']['dns'] ?? $payload['dns'] ?? $diagFacts['DNS'] ?? '';
if (is_array($dns)) $dns = implode(', ', array_map('strval', $dns));
/* Destino: cidade da rede, extraída do endereço cadastrado (formato "..., CIDADE/UF, CEP ..."). */
$destCity = ''; $destUf = ''; $destCep = '';
$destAddress = trim((string)($network['address'] ?? ''));
if ($destAddress !== '') {
    foreach (array_reverse(array_map('trim', explode(',', $destAddress))) as $part) {
        if (preg_match('/^(.+?)\s*(?:\/|\s-\s|\s–\s)\s*([A-Za-z]{2})$/u', $part, $m)) { $destCity = trim($m[1]); $destUf = strtoupper($m[2]); break; }
    }
    if (preg_match('/\b(\d{5})-?(\d{3})\b/', $destAddress, $m)) $destCep = $m[1].'-'.$m[2];
}
if ($destCity !== '') {
    $destCity = mb_convert_case(mb_strtolower($destCity, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $destCity = preg_replace_callback('/\s(De|Da|Do|Das|Dos|E)\s/u', static fn($m) => ' '.mb_strtolower($m[1], 'UTF-8').' ', $destCity) ?? $destCity;
}
$agentGps=null;
if ((int)$selectedAgentId>0) {
    try {
        $q=$pdo->prepare('SELECT payload_json,captured_at FROM '.arc_table('agent_telemetry')." WHERE account_id=? AND network_id=? AND agent_id=? AND event_type='connection_gps' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$account,(int)$networkId,(int)$selectedAgentId]);
        $r=$q->fetch(PDO::FETCH_ASSOC);
        if($r){$g=json_decode((string)$r['payload_json'],true);if(is_array($g)&&is_numeric($g['latitude']??null)&&is_numeric($g['longitude']??null)&&abs((float)$g['latitude'])<=90&&abs((float)$g['longitude'])<=180){$agentGps=['latitude'=>(float)$g['latitude'],'longitude'=>(float)$g['longitude'],'accuracy_m'=>is_numeric($g['accuracy_m']??null)?(float)$g['accuracy_m']:null,'source'=>'windows_location','updated_at'=>$r['captured_at']];}}
    } catch(Throwable $e){error_log('Conexao agent GPS: '.$e->getMessage());}
}
$initial = [
 'geoPoints'=>$geoPoints,'agentGps'=>$agentGps,'geoAvailable'=>$geoAvailable,'gpsCsrf'=>$csrf,
 'publicIp'=>$publicIp, 'localIp'=>$localIp, 'gateway'=>$gateway,'dns'=>(string)$dns,
 'agentId'=>(int)$selectedAgentId,'networkId'=>(int)$networkId,
 'latestAt'=>$latestEvent ? arc_when($latestEvent['captured_at']) : null,
 'diagnosticAt'=>$latestDiagnostic ? arc_when($latestDiagnostic['captured_at']) : null,
 'diagnostic'=>$diagText,
 'agentName'=>(string)($latestEvent['machine_name'] ?? $latestDiagnostic['machine_name'] ?? ''),
 'networkName'=>(string)($network['name'] ?? ''),
 'measured'=>$diagPayload['schema'] ?? null,
 'provider'=>isset($diagPayload['provider']) && is_array($diagPayload['provider']) ? $diagPayload['provider'] : [],
 'interface'=>isset($diagPayload['interface']) && is_array($diagPayload['interface']) ? $diagPayload['interface'] : [],
 'latency'=>isset($diagPayload['latency']) && is_array($diagPayload['latency']) ? $diagPayload['latency'] : [],
 'route'=>isset($diagPayload['route']) && is_array($diagPayload['route']) ? $diagPayload['route'] : [],
 'errors'=>isset($diagPayload['errors']) && is_array($diagPayload['errors']) ? $diagPayload['errors'] : [],
 'dest'=>['name'=>(string)($network['name'] ?? ''),'client'=>(string)($network['client_name'] ?? ''),'site'=>(string)($network['site_code'] ?? ''),'address'=>$destAddress,'city'=>$destCity,'uf'=>$destUf,'cep'=>$destCep],
 'assets'=>arc_workspace_base_url().'assets/vendor/leaflet/',
];
require __DIR__.'/_topo.php';
?>
<style>
.arc-connect{--line:#29445a;--text:#eaf7ff;--muted:#8fabc0;min-width:0;color:var(--text);width:100%;height:100%;min-height:0;display:flex;font-family:inherit}
.arc-connect *{box-sizing:border-box}.arc-connect button{cursor:pointer}
.arc-connect .cc-board{width:100%;min-height:0;display:flex;flex-direction:column;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#0e1d2e}
.arc-connect .cc-head{height:46px;min-height:46px;display:flex;align-items:center;justify-content:space-between;padding:5px 12px;border-bottom:1px solid var(--line);gap:10px}
.arc-connect .cc-heading{display:flex;align-items:center;gap:9px;min-width:0}.arc-connect .cc-heading small{font-size:10px;letter-spacing:.08em;color:#52d0ef;font-weight:800;white-space:nowrap}.arc-connect .cc-heading h2{font-size:13px;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.arc-connect .cc-actions{display:flex;gap:7px;align-items:center}.arc-connect .cc-status{font-size:10px;color:#9fbfd2;white-space:nowrap}.arc-connect .cc-run,.arc-connect .cc-refresh{font-size:11px;border:1px solid #34708a;border-radius:7px;color:#e9faff;padding:6px 9px;background:#13546e;white-space:nowrap}.arc-connect .cc-refresh{background:#142d41;padding:6px 9px}.arc-connect button:focus-visible{outline:2px solid #88e7ff;outline-offset:2px}.arc-connect .cc-run:disabled{opacity:.5}
.arc-connect .cc-surface{flex:1;min-height:0;display:flex;flex-direction:column;position:relative;overflow:hidden;background:#0a1c2c}
.arc-connect .cc-metrics{position:relative;z-index:6;display:grid;grid-template-columns:minmax(140px,1.5fr) repeat(5,minmax(80px,1fr));align-items:stretch;background:#0a1b2cf5;border-bottom:1px solid #30536a;min-height:72px}
.arc-connect .cc-provider,.arc-connect .cc-kpi{padding:10px 12px;min-width:0;border-right:1px solid #29445a;display:flex;flex-direction:column;justify-content:center;gap:4px}.arc-connect .cc-provider small,.arc-connect .cc-kpi small{color:#88a9be;font-size:10px;white-space:nowrap}.arc-connect .cc-isp{font-size:13px;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.arc-connect .cc-org{font-size:10px;color:#a5c4d6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.arc-connect .cc-kpi strong{font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-connect .cc-stage{position:relative;flex:1;min-height:240px;background:radial-gradient(ellipse at center,#15485d,#0b1e30 74%);overflow:hidden;isolation:isolate}
.arc-connect .cc-map{position:absolute;inset:0;z-index:0;background:#0b1e30;font-family:inherit}
.arc-connect .leaflet-container{background:#0b1e30;font-family:inherit;outline:0}
/* Escuro: cartografia Esri WorldTopoMap, distinta de Ruas/OSM. */
.arc-connect .leaflet-tile.cc-dark-tile{filter:invert(.91) hue-rotate(182deg) brightness(.7) contrast(1.15) saturate(.58)}
.arc-connect .leaflet-control-attribution{background:#071b2bcc;color:#7fa3b8;font-size:9px;border-radius:6px 0 0 0}.arc-connect .leaflet-control-attribution a{color:#9fdcf0}
.arc-connect .cc-ui{position:absolute;z-index:500;pointer-events:none}.arc-connect .cc-ui>*{pointer-events:auto}
/* janelinha fixa: resumo da rota */
.arc-connect .cc-route{top:10px;left:10px;width:min(240px,58%);padding:10px 12px;border:1px solid #3f89a6;border-radius:14px;background:linear-gradient(160deg,#0a2236f2,#07182af2);box-shadow:0 12px 34px #000a;backdrop-filter:blur(8px);animation:ccslide .7s .2s cubic-bezier(.2,.9,.3,1) both}
.arc-connect .cc-route[hidden]{display:none}
.arc-connect .cc-route-kicker{display:flex;align-items:center;gap:6px;font-size:9px;letter-spacing:.1em;font-weight:800;color:#52d0ef;margin-bottom:8px}
.arc-connect .cc-route-kicker i{font-size:11px}
.arc-connect .cc-stop{display:flex;align-items:center;gap:9px;min-width:0}
.arc-connect .cc-stop-ico{flex:0 0 26px;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-size:12px;color:#06202e;background:linear-gradient(145deg,#9bf2ff,#2bc4ea);box-shadow:0 0 12px #2bc4ea88}
.arc-connect .cc-stop.dest .cc-stop-ico{background:linear-gradient(145deg,#b9ffd8,#33d489);box-shadow:0 0 12px #33d48988}
.arc-connect .cc-stop div{min-width:0}.arc-connect .cc-stop small{display:block;font-size:9px;letter-spacing:.06em;color:#86a9bd}
.arc-connect .cc-stop strong{display:block;font-size:13px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.arc-connect .cc-link{display:flex;align-items:center;gap:8px;margin:5px 0 5px 12px;padding-left:1px;color:#8fd6ea;font-size:10px}
.arc-connect .cc-link:before{content:"";width:2px;height:20px;border-radius:2px;background:repeating-linear-gradient(#4fdcff 0 4px,transparent 4px 8px);background-size:2px 16px;animation:ccdown 1s linear infinite}
.arc-connect .cc-link b{font-weight:700;color:#d7f6ff}
/* ferramentas */
.arc-connect .cc-tools{top:10px;right:10px;display:flex;flex-direction:column;gap:6px}
.arc-connect .cc-tool{width:34px;height:34px;display:grid;place-items:center;border:1px solid #3f89a6;border-radius:10px;background:#0a2236ee;color:#dff7ff;font-size:16px;line-height:1;padding:0;transition:background .2s,transform .2s}
.arc-connect .cc-tool:hover{background:#15506b;transform:scale(1.06)}
.arc-connect .cc-gps{position:absolute;z-index:500;right:54px;bottom:24px;display:flex;gap:4px;flex-wrap:wrap;max-width:60%;pointer-events:auto}.arc-connect .cc-gps button{background:#092d43;color:#dffaff;border:1px solid #3f89a6;border-radius:7px;padding:7px 8px;font-size:10px;font-weight:700}.arc-connect .cc-layers{left:10px;bottom:24px;display:flex;border:1px solid #3f89a6;border-radius:10px;overflow:hidden;background:#0a2236ee}
.arc-connect .cc-layers button{border:0;border-right:1px solid #2c5a72;background:transparent;color:#a9cde0;font-size:10px;font-weight:700;padding:7px 10px;transition:background .2s,color .2s}
.arc-connect .cc-layers button:last-child{border-right:0}.arc-connect .cc-layers button[aria-pressed=true]{background:#1b6d8d;color:#fff}
.arc-connect .cc-state{left:50%;bottom:26px;transform:translateX(-50%);display:flex;align-items:center;gap:8px;max-width:calc(100% - 40px);padding:7px 13px;border:1px solid #3f89a6;border-radius:20px;background:#0a2236ee;color:#cfeefa;font-size:11px;box-shadow:0 8px 24px #0008}
.arc-connect .cc-state[hidden]{display:none}
.arc-connect .cc-empty{inset:0;display:grid;place-items:center;pointer-events:none!important}
.arc-connect .cc-empty[hidden]{display:none}
.arc-connect .cc-empty div{max-width:min(320px,86%);text-align:center;padding:18px 20px;border:1px solid #3f89a6;border-radius:16px;background:#0a2236ee;color:#cfeefa;font-size:12px;line-height:1.5;box-shadow:0 16px 40px #000a;pointer-events:auto}
.arc-connect .cc-empty i{display:block;font-size:26px;color:#52d0ef;margin-bottom:8px}
/* pinos animados */
.arc-connect .cc-mk-wrap{background:transparent!important;border:0!important}
.arc-connect .cc-mk{position:relative;width:0;height:0;cursor:pointer}
.arc-connect .cc-dot{position:absolute;left:-15px;top:-15px;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-size:14px;color:#06202e;background:linear-gradient(145deg,#9bf2ff,#2bc4ea);border:2px solid #e6fbff;box-shadow:0 0 18px #2bc4eacc;animation:ccdrop .8s cubic-bezier(.2,1.5,.4,1) backwards;transition:transform .2s}
.arc-connect .cc-mk:hover .cc-dot{transform:scale(1.15)}
.arc-connect .cc-ring{position:absolute;left:-15px;top:-15px;width:30px;height:30px;border-radius:50%;border:2px solid #3bd0f2;opacity:0;animation:ccpulse 2.6s ease-out infinite}.arc-connect .cc-ring.r2{animation-delay:1.3s}
.arc-connect .cc-mk-dest .cc-dot{background:linear-gradient(145deg,#b9ffd8,#33d489);box-shadow:0 0 18px #33d489cc;animation-delay:.35s}.arc-connect .cc-mk-dest .cc-ring{border-color:#47e39a}
.arc-connect .cc-tag{position:absolute;top:-20px;display:flex;flex-direction:column;gap:1px;padding:5px 10px;max-width:176px;border:1px solid #4aa6c4;border-radius:10px;background:#071d2de8;backdrop-filter:blur(6px);box-shadow:0 8px 22px #000a;white-space:nowrap;animation:ccfade .6s .5s backwards}
.arc-connect .cc-mk[data-side=n] .cc-tag{left:50%;top:auto;bottom:25px;transform:translateX(-50%);text-align:center}.arc-connect .cc-mk[data-side=s] .cc-tag{left:50%;top:25px;transform:translateX(-50%);text-align:center}
.arc-connect .cc-tag strong{font-size:12px;color:#effbff;overflow:hidden;text-overflow:ellipsis}.arc-connect .cc-tag small{font-size:9px;letter-spacing:.05em;color:#8fd6ea;overflow:hidden;text-overflow:ellipsis}
.arc-connect .cc-mk-dest .cc-tag{border-color:#3fb883}.arc-connect .cc-mk-dest .cc-tag small{color:#8fe8bd}
.arc-connect .cc-chip-wrap{background:transparent!important;border:0!important}
.arc-connect .cc-chip{position:absolute;left:0;top:0;transform:translate(-50%,-50%);display:flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid #4aa6c4;border-radius:18px;background:#071d2df0;color:#e9faff;font-size:10px;font-weight:700;white-space:nowrap;box-shadow:0 6px 18px #000a;animation:ccfade .6s 1.6s backwards;cursor:pointer}
.arc-connect .cc-chip i{color:#52d0ef}.arc-connect .cc-chip:hover{background:#12496a}
.arc-connect path.cc-flow{animation:ccflow 1.1s linear infinite}
.arc-connect path.cc-area{animation:ccbreath 3.4s ease-in-out infinite}
/* janelas (popups) */
.arc-connect .cc-popup .leaflet-popup-content-wrapper{padding:0;overflow:hidden;color:#eaf7ff;border:1px solid #4aa6c4;border-radius:16px;background:linear-gradient(170deg,#0b2438f7,#071a2cf7);box-shadow:0 18px 50px #000b;backdrop-filter:blur(10px);animation:ccpop .3s cubic-bezier(.2,1.2,.4,1) backwards}
.arc-connect .cc-popup .leaflet-popup-content{margin:0;font-size:11px;line-height:1.45}
.arc-connect .cc-popup .leaflet-popup-tip{background:#0a2236;border:1px solid #4aa6c4}
.arc-connect .cc-popup a.leaflet-popup-close-button{top:9px;right:9px;width:24px;height:24px;border-radius:50%;padding:0;display:grid;place-items:center;font:700 16px/1 inherit;color:#cfeaf6;background:#ffffff14;transition:background .2s}
.arc-connect .cc-popup a.leaflet-popup-close-button:hover{background:#ffffff2e;color:#fff}
.arc-connect .cc-card-head{display:flex;align-items:center;gap:11px;padding:13px 40px 12px 14px;border-bottom:1px solid #27506a;background:linear-gradient(135deg,#12506d66,#0a243800)}
.arc-connect .cc-card-ico{flex:0 0 36px;width:36px;height:36px;border-radius:12px;display:grid;place-items:center;font-size:17px;color:#06202e;background:linear-gradient(145deg,#9bf2ff,#2bc4ea);box-shadow:0 0 16px #2bc4ea77}
.arc-connect .cc-card.dest .cc-card-ico{background:linear-gradient(145deg,#b9ffd8,#33d489);box-shadow:0 0 16px #33d48977}.arc-connect .cc-card.route .cc-card-ico{background:linear-gradient(145deg,#ffe29a,#f0a93e);box-shadow:0 0 16px #f0a93e66}
.arc-connect .cc-card-head div{min-width:0}.arc-connect .cc-card-head small{display:block;font-size:9px;letter-spacing:.1em;font-weight:800;color:#52d0ef}.arc-connect .cc-card.dest .cc-card-head small{color:#52e0a0}.arc-connect .cc-card.route .cc-card-head small{color:#f3b95a}
.arc-connect .cc-card-head strong{display:block;font-size:14px;line-height:1.25;overflow-wrap:anywhere}.arc-connect .cc-card-head span{display:block;font-size:10px;color:#9fc3d6}
.arc-connect .cc-rows{margin:0;padding:8px 14px 4px;display:grid;gap:0;max-height:min(46vh,300px);overflow:auto}
.arc-connect .cc-rows div{display:grid;grid-template-columns:92px minmax(0,1fr);gap:8px;padding:6px 0;border-bottom:1px dashed #23465c}.arc-connect .cc-rows div:last-child{border-bottom:0}
.arc-connect .cc-rows dt{margin:0;color:#86a9bd;font-size:10px;letter-spacing:.03em}.arc-connect .cc-rows dd{margin:0;color:#eaf7ff;font-weight:600;overflow-wrap:anywhere}
.arc-connect .cc-rows dd em{font-style:normal;font-weight:800}
.arc-connect .cc-card-note{margin:0;padding:9px 14px 11px;border-top:1px solid #1f4259;color:#8fb2c6;font-size:10px;line-height:1.5;background:#05121f66}
.arc-connect .cc-card-note a{color:#9fe6ff;font-weight:700}
.arc-connect .cc-bottom{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 11px;background:#0b1c2b;border-top:1px solid var(--line);color:#9fbbcd;font-size:10px;min-height:30px}.arc-connect .cc-bottom #cc-message{color:#86e2fa}.arc-connect .cc-bottom #cc-checked{white-space:nowrap}
@keyframes ccflow{to{stroke-dashoffset:-13}}
@keyframes ccpulse{0%{transform:scale(.8);opacity:.9}100%{transform:scale(3.6);opacity:0}}
@keyframes ccdrop{0%{transform:translateY(-30px) scale(.3);opacity:0}100%{transform:none;opacity:1}}
@keyframes ccfade{from{opacity:0}to{opacity:1}}
@keyframes ccslide{from{opacity:0;transform:translateX(-14px)}to{opacity:1;transform:none}}
@keyframes ccpop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}
@keyframes ccbreath{0%,100%{fill-opacity:.05;stroke-opacity:.45}50%{fill-opacity:.14;stroke-opacity:.9}}
@keyframes ccdown{to{background-position:0 16px}}
.arc-redes-page:has(.arc-connect) .arc-redes-conteudo{display:flex;min-height:0;overflow:hidden;padding:6px 10px}.arc-connect{flex:1}
@media(max-width:1050px){.arc-connect .cc-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.arc-connect .cc-provider,.arc-connect .cc-kpi{padding:6px 9px}.arc-connect .cc-metrics{min-height:0}}
@media(max-width:620px){.arc-redes-page:has(.arc-connect) .arc-redes-conteudo{overflow:auto}.arc-connect{min-height:600px}.arc-connect .cc-head{height:auto}.arc-connect .cc-heading h2{font-size:11px}.arc-connect .cc-status{display:none}.arc-connect .cc-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.arc-connect .cc-stage{min-height:380px}.arc-connect .cc-route{width:min(200px,60%);padding:8px 10px}.arc-connect .cc-stop strong{font-size:12px}.arc-connect .cc-tag{max-width:132px;padding:4px 8px}.arc-connect .cc-tag strong{font-size:11px}.arc-connect .cc-layers button{padding:7px 8px}.arc-connect .cc-rows div{grid-template-columns:80px minmax(0,1fr)}}
@media(prefers-reduced-motion:reduce){.arc-connect *,.arc-connect path.cc-flow{animation:none!important}}
</style>
<section class="asc-ops arc-connect" aria-label="Conexão e provedora">
 <div class="cc-board">
  <header class="cc-head"><div class="cc-heading"><small>CONEXÃO</small></div><div class="cc-actions"><span id="cc-status" class="cc-status">Dados do agente Windows</span><button type="button" id="cc-update" class="cc-refresh" title="Atualizar dados" aria-label="Atualizar dados"><i class="fa fa-refresh" aria-hidden="true"></i></button><button type="button" id="cc-run" class="cc-run" data-arc-command="diagnostic" <?= $selectedAgentId ? '' : 'disabled' ?>><i class="fa fa-bolt" aria-hidden="true"></i> Diagnosticar</button></div></header>
  <div class="cc-surface">
   <div class="cc-metrics" aria-label="Informações da conexão">
    <div class="cc-provider"><small>PROVEDORA DE INTERNET</small><strong id="cc-isp" class="cc-isp">Aguardando diagnóstico</strong><span id="cc-org" class="cc-org">Dados do agente Windows</span></div>
    <div class="cc-kpi"><small>IP público</small><strong id="cc-public-ip">Não disponível</strong></div>
    <div class="cc-kpi"><small>ASN</small><strong id="cc-asn">Não disponível</strong></div>
    <div class="cc-kpi"><small>Origem · cidade do provedor</small><strong id="cc-origin-city">Não disponível</strong></div>
    <div class="cc-kpi"><small>Destino · cidade da rede</small><strong id="cc-dest-city">Não informado</strong></div>
    <div class="cc-kpi"><small>Latência até a internet</small><strong id="cc-latency">Não disponível</strong></div>
   </div>
   <div class="cc-stage" aria-label="Mapa da região: cidade de origem do provedor e cidade de destino da rede">
    <div class="cc-map" id="cc-map" role="application" aria-label="Mapa interativo da região da conexão"></div>
    <div class="cc-ui cc-route" id="cc-route" aria-live="polite">
     <div class="cc-route-kicker"><i class="fa fa-random" aria-hidden="true"></i> ROTA DA CONEXÃO</div>
     <div class="cc-stop origin"><span class="cc-stop-ico"><i class="fa fa-globe" aria-hidden="true"></i></span><div><small>DE ONDE VEM · PROVEDOR</small><strong id="cc-r-from">—</strong></div></div>
     <div class="cc-link"><span id="cc-r-dist">&nbsp;</span></div>
     <div class="cc-stop dest"><span class="cc-stop-ico"><i class="fa fa-home" aria-hidden="true"></i></span><div><small>PARA ONDE VAI · REDE</small><strong id="cc-r-to">—</strong></div></div>
    </div>
    <div class="cc-ui cc-tools" role="group" aria-label="Controles do mapa">
     <button type="button" class="cc-tool" id="cc-zin" title="Aproximar" aria-label="Aproximar"><i class="fa fa-plus" aria-hidden="true"></i></button>
     <button type="button" class="cc-tool" id="cc-zout" title="Afastar" aria-label="Afastar"><i class="fa fa-minus" aria-hidden="true"></i></button>
     <button type="button" class="cc-tool" id="cc-fit" title="Centralizar na rota" aria-label="Centralizar na rota"><i class="fa fa-crosshairs" aria-hidden="true"></i></button>
    </div>
    <div class="cc-ui cc-gps" role="group" aria-label="Coordenadas verificadas"><button type="button" id="cc-gps-origin" title="Informar coordenadas GPS verificadas do provedor">GPS origem</button><button type="button" id="cc-gps-dest" title="Informar coordenadas GPS verificadas do local da rede">GPS destino</button><button type="button" id="cc-gps-here" title="Usar localização autorizada deste navegador quando ele estiver no local da rede">GPS deste dispositivo</button><button type="button" id="cc-gps-agent" title="Usar última localização autorizada enviada pelo agente Windows">GPS do agente</button></div>
    <div class="cc-ui cc-layers" role="group" aria-label="Estilo do mapa"><button type="button" data-layer="dark" aria-pressed="true">Escuro</button><button type="button" data-layer="streets" aria-pressed="false">Ruas</button><button type="button" data-layer="sat" aria-pressed="false">Satélite</button></div>
    <div class="cc-ui cc-state" id="cc-state" role="status" hidden></div>
    <div class="cc-ui cc-empty" id="cc-empty" hidden><div><i class="fa fa-map-o" aria-hidden="true"></i><span id="cc-empty-text"></span></div></div>
   </div>
   <div class="cc-bottom"><span id="cc-message" role="status"></span><span id="cc-checked"><?= $latestDiagnostic ? 'Último diagnóstico: '.arc_h(arc_when($latestDiagnostic['captured_at'])) : 'Sem diagnóstico anterior' ?></span></div>
  </div>
 </div>
</section>
<script>
(()=>{'use strict';
const state=<?=json_encode($initial,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').trim()||'Não disponível';
const norm=s=>String(s??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
const UF={'acre':'AC','alagoas':'AL','amapa':'AP','amazonas':'AM','bahia':'BA','ceara':'CE','distrito federal':'DF','espirito santo':'ES','goias':'GO','maranhao':'MA','mato grosso':'MT','mato grosso do sul':'MS','minas gerais':'MG','para':'PA','paraiba':'PB','parana':'PR','pernambuco':'PE','piaui':'PI','rio de janeiro':'RJ','rio grande do norte':'RN','rio grande do sul':'RS','rondonia':'RO','roraima':'RR','santa catarina':'SC','sao paulo':'SP','sergipe':'SE','tocantins':'TO'};
const UFN={AC:'Acre',AL:'Alagoas',AP:'Amapá',AM:'Amazonas',BA:'Bahia',CE:'Ceará',DF:'Distrito Federal',ES:'Espírito Santo',GO:'Goiás',MA:'Maranhão',MT:'Mato Grosso',MS:'Mato Grosso do Sul',MG:'Minas Gerais',PA:'Pará',PB:'Paraíba',PR:'Paraná',PE:'Pernambuco',PI:'Piauí',RJ:'Rio de Janeiro',RN:'Rio Grande do Norte',RS:'Rio Grande do Sul',RO:'Rondônia',RR:'Roraima',SC:'Santa Catarina',SP:'São Paulo',SE:'Sergipe',TO:'Tocantins'};
const ufOf=r=>{const n=norm(r);return UF[n]||(/^[a-z]{2}$/.test(n)?n.toUpperCase():'')};
const num=v=>{if(v===null||v===undefined||v==='')return null;const n=Number(v);return Number.isFinite(n)?n:null};
const validLL=(a,b)=>a!==null&&b!==null&&a>=-90&&a<=90&&b>=-180&&b<=180;
const label=(c,u)=>[c,u].filter(Boolean).join(' · ');
const reduce=!!(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches);
const p=state.provider||{},measured=Number(state.measured)===1,D=state.dest||{};

/* ---------- Origem (cidade do provedor, estimada pelo IP público) e destino (cidade da rede) ---------- */
const origin={city:measured?String(p.city||'').trim():'',region:measured?String(p.region||'').trim():'',country:measured?String(p.country||'').trim():'',cc:measured?String(p.country_code||'').toLowerCase():'',ll:null};
origin.uf=ufOf(origin.region);
const oLat=measured?num(p.latitude):null,oLon=measured?num(p.longitude):null;
if(validLL(oLat,oLon)){origin.ll=[oLat,oLon];origin.fromIp=true}
const dest={city:String(D.city||'').trim(),uf:String(D.uf||'').trim(),ll:null};
const verifiedCoord=v=>Array.isArray(v)&&v.length===2&&validLL(num(v[0]),num(v[1]))?[Number(v[0]),Number(v[1])]:null;
const points=state.geoPoints||{};
const pointCoords=pt=>pt&&validLL(num(pt.latitude),num(pt.longitude))?[Number(pt.latitude),Number(pt.longitude)]:null;
const savedOrigin=pointCoords(points.origin),savedDest=pointCoords(points.dest);
const agentPoint=state.agentGps||null,agentCoords=pointCoords(agentPoint);
if(savedOrigin){origin.ll=savedOrigin;origin.fromIp=false;origin.gpsVerified=true;origin.geoSource=String(points.origin.source||'manual_verified');origin.accuracy_m=points.origin.accuracy_m;}
if(savedDest){dest.ll=savedDest;dest.gpsVerified=true;dest.geocodeSource='localização confirmada da rede';dest.accuracy_m=points.dest.accuracy_m;}
else if(agentCoords){dest.ll=agentCoords;dest.gpsVerified=true;dest.geocodeSource='localização autorizada do Windows';dest.accuracy_m=agentPoint.accuracy_m;}
async function setGps(kind,coords,accuracy,source){
 if(!verifiedCoord(coords))return;
 if(!state.geoAvailable){alert('Armazenamento de GPS indisponível no servidor.');return;}
 const url=new URL(window.location.href);url.searchParams.set('gps_action','save');
 try{
 const response=await fetch(url.toString(),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:state.gpsCsrf,kind,latitude:coords[0],longitude:coords[1],accuracy_m:accuracy,source})});
 const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Falha ao salvar');
 location.reload();
 }catch(error){alert('Não foi possível salvar a localização: '+error.message);}
}
function requestGps(kind){
 const point=points[kind]||{};
 const raw=window.prompt('Informe latitude e longitude obtidas de uma medição verificada (ex.: -1.4558, -48.4902). IP não é GPS exato.',point.latitude!=null?point.latitude+', '+point.longitude:'');
 if(raw===null)return;
 const parts=raw.trim().split(/[,; ]+/).map(Number);
 if(parts.length!==2||!validLL(parts[0],parts[1])){alert('Coordenadas inválidas.');return;}
 if(confirm('Salvar essas coordenadas como ponto confirmado de '+(kind==='origin'?'origem':'destino')+'?'))setGps(kind,parts,null,'manual_verified');
}
$('cc-gps-origin').onclick=()=>requestGps('origin');
$('cc-gps-dest').onclick=()=>requestGps('dest');
$('cc-gps-here').onclick=()=>{
 if(!confirm('Você está fisicamente no local da REDE de destino e autoriza salvar a localização informada por este dispositivo?'))return;
 if(!navigator.geolocation){alert('Geolocalização indisponível no navegador.');return;}
 navigator.geolocation.getCurrentPosition(pos=>{
  const accuracy=Math.round(pos.coords.accuracy||0);
  if(confirm('Precisão estimada: ±'+accuracy+' m. Salvar este ponto para o destino da rede?'))setGps('dest',[pos.coords.latitude,pos.coords.longitude],accuracy,'browser_geolocation');
 },err=>alert('Localização indisponível ou sem permissão: '+err.message),{enableHighAccuracy:true,timeout:20000,maximumAge:0});
};
$('cc-gps-agent').onclick=()=>{
 if(!agentCoords){alert('O agente ainda não enviou uma localização autorizada. No Windows, abra Conexão e use "Localização do Windows".');return;}
 const accuracy=agentPoint.accuracy_m;
 const a=accuracy!=null?'Precisão informada: ±'+Math.round(accuracy)+' m. ':'Precisão não informada. ';
 if(confirm(a+'Usar a última posição autorizada do agente como destino da rede?'))setGps('dest',agentCoords,accuracy,'manual_verified');
};

const originLabel=label(origin.city,origin.uf||origin.region);
const destLabel=label(dest.city,dest.uf);

/* ---------- Métricas ---------- */
const lat=state.latency||{};
const ms=h=>{const v=h?lat[h]:null;return v&&v.responded&&v.avg_ms!=null?Number(v.avg_ms):null};
const inet=[ms('1.1.1.1'),ms('8.8.8.8')].filter(v=>v!=null);
const inetAvg=inet.length?inet.reduce((a,b)=>a+b,0)/inet.length:null;
const quality=v=>v==null?null:v<40?['Excelente','#3ddc97']:v<80?['Boa','#8be28b']:v<150?['Regular','#ffc857']:['Alta','#ff7b7b'];
$('cc-isp').textContent=measured?esc(p.isp):'Aguardando diagnóstico';
$('cc-org').textContent=measured?esc(p.org):'Aguardando dados do agente';
$('cc-asn').textContent=measured&&p.asn?'AS'+String(p.asn).replace(/^AS/i,''):'Não disponível';
$('cc-public-ip').textContent=measured?esc(state.publicIp):'Não disponível';
$('cc-origin-city').textContent=originLabel||'Não disponível';
$('cc-dest-city').textContent=destLabel||'Não informado';
$('cc-latency').textContent=inetAvg!=null?Math.round(inetAvg)+' ms':'Não disponível';
$('cc-status').textContent=measured?'Diagnóstico recebido':'Aguardando agente';
$('cc-r-from').textContent=originLabel||(measured?'Cidade não identificada':'Aguardando diagnóstico');
$('cc-r-to').textContent=destLabel||'Cidade não informada';

/* ---------- Utilitários de mapa ---------- */
const el=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!=null)e.textContent=text;return e};
const km=(a,b)=>{const R=6371,t=x=>x*Math.PI/180,dLa=t(b[0]-a[0]),dLo=t(b[1]-a[1]);const h=Math.sin(dLa/2)**2+Math.cos(t(a[0]))*Math.cos(t(b[0]))*Math.sin(dLo/2)**2;return 2*R*Math.asin(Math.sqrt(h))};
const fmtKm=k=>k<1?'menos de 1 km':k<10?k.toFixed(1).replace('.',',')+' km':Math.round(k).toLocaleString('pt-BR')+' km';
function arc(a,b,n){
 n=n||72;const cos=Math.cos(((a[0]+b[0])/2)*Math.PI/180)||1;
 const ax=a[1]*cos,ay=a[0],bx=b[1]*cos,by=b[0],dx=bx-ax,dy=by-ay,s=dx>=0?1:-1,k=.26;
 const cx=(ax+bx)/2-dy*k*s,cy=(ay+by)/2+dx*k*s,pts=[];
 for(let i=0;i<=n;i++){const t=i/n,u=1-t;pts.push([u*u*ay+2*u*t*cy+t*t*by,(u*u*ax+2*u*t*cx+t*t*bx)/cos])}
 return pts;
}
function geoCache(k,v){try{if(v===undefined){const r=JSON.parse(localStorage.getItem(k)||'null');return r&&Date.now()-r.t<2592e6?r:undefined}localStorage.setItem(k,JSON.stringify({t:Date.now(),v}))}catch(e){return undefined}}
async function geocode(q,cc){
 const key='arc-geo:'+(cc||'')+':'+norm(q),hit=geoCache(key);if(hit)return hit.v;
 const ctl=new AbortController(),to=setTimeout(()=>ctl.abort(),8000);
 try{
  const r=await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&accept-language=pt-BR'+(cc?'&countrycodes='+encodeURIComponent(cc):'')+'&q='+encodeURIComponent(q),{signal:ctl.signal,headers:{Accept:'application/json'}});
  if(!r.ok)return null;const j=await r.json(),h=Array.isArray(j)?j[0]:null;
  const v=h&&validLL(num(h.lat),num(h.lon))?[Number(h.lat),Number(h.lon)]:null;geoCache(key,v);return v;
 }catch(e){return null}finally{clearTimeout(to)}
}
function loadLeaflet(){return new Promise((res,rej)=>{if(window.L)return res();const css=document.createElement('link');css.rel='stylesheet';css.href=state.assets+'leaflet.css?v=1.9.4';document.head.append(css);const s=document.createElement('script');s.src=state.assets+'leaflet.js?v=1.9.4';s.onload=()=>window.L?res():rej(new Error('leaflet'));s.onerror=()=>rej(new Error('leaflet'));document.head.append(s)})}
function latNode(v){const f=document.createDocumentFragment();if(v==null){f.append('Sem resposta');return f}const q=quality(v);f.append(Math.round(v*10)/10+' ms ');const e=el('em',null,'· '+q[0]);e.style.color=q[1];f.append(e);return f}
function card(o){
 const c=el('div','cc-card '+o.tone),h=el('div','cc-card-head'),ico=el('span','cc-card-ico'),i=el('i','fa '+o.icon);i.setAttribute('aria-hidden','true');ico.append(i);
 const t=el('div');t.append(el('small',null,o.kicker),el('strong',null,o.title));if(o.sub)t.append(el('span',null,o.sub));h.append(ico,t);c.append(h);
 const dl=el('dl','cc-rows');
 for(const [k,v] of o.rows){if(v==null||(typeof v==='string'&&!v.trim()))continue;const r=el('div');r.append(el('dt',null,k));const dd=el('dd');dd.append(v instanceof Node?v:String(v));r.append(dd);dl.append(r)}
 c.append(dl);
 if(o.note){const n=el('p','cc-card-note',o.note);if(o.link){n.append(document.createElement('br'));const a=el('a',null,o.link.text);a.href=o.link.href;a.target='_blank';a.rel='noopener noreferrer';n.append(a)}c.append(n)}
 return c;
}
const setState=(txt,spin)=>{const s=$('cc-state');s.replaceChildren();if(!txt){s.hidden=true;return}if(spin){const i=el('i','fa fa-circle-o-notch fa-spin');i.setAttribute('aria-hidden','true');s.append(i)}s.append(el('span',null,txt));s.hidden=false};
const setEmpty=txt=>{$('cc-empty-text').textContent=txt||'';$('cc-empty').hidden=!txt};

/* ---------- Mapa ---------- */
let map=null,raf=0;
const markers=[];
function pin(kind,ll,title,sub,side,content){
 const root=el('div','cc-mk cc-mk-'+kind);root.dataset.side=side;
 root.append(el('span','cc-ring'),el('span','cc-ring r2'));
 const dot=el('span','cc-dot'),ic=el('i','fa '+(kind==='origin'?'fa-globe':'fa-home'));ic.setAttribute('aria-hidden','true');dot.append(ic);root.append(dot);
 const tag=el('div','cc-tag');tag.append(el('strong',null,title),el('small',null,sub));root.append(tag);
 const m=L.marker(ll,{icon:L.divIcon({className:'cc-mk-wrap',html:root,iconSize:[0,0]}),title:title+' — '+sub,alt:title,keyboard:true,riseOnHover:true}).addTo(map);
 m.bindPopup(content,popupOpts(-20));markers.push(m);return m;
}
function popupOpts(dy){const w=Math.max(220,Math.min(310,$('cc-map').clientWidth-40));return{className:'cc-popup',maxWidth:w,minWidth:Math.min(240,w),offset:[0,dy||0],autoPanPaddingTopLeft:[16,16],autoPanPaddingBottomRight:[16,64]}}
function fit(animate){
 if(!map||!markers.length)return;
 const rt=$('cc-route'),top=(rt&&!rt.hidden?rt.offsetHeight:0)+34,side=$('cc-map').clientWidth<480?70:100;
 const o={paddingTopLeft:[side,top],paddingBottomRight:[side,86],maxZoom:11};
 if(markers.length>1){const a=markers[0].getLatLng(),c=markers[1].getLatLng(),dk=km([a.lat,a.lng],[c.lat,c.lng]);o.maxZoom=dk<10?14:dk<30?12:11;const b=L.latLngBounds(markers.map(m=>m.getLatLng()));(animate&&!reduce)?map.flyToBounds(b,Object.assign({duration:1.7},o)):map.fitBounds(b,Object.assign({animate:false},o))}
 else{const ll=markers[0].getLatLng();(animate&&!reduce)?map.flyTo(ll,10,{duration:1.5}):map.setView(ll,10,{animate:false})}
}
function drawRoute(a,b,distKm){
 const pts=arc(a,b);
 const halo=L.polyline([],{color:'#031826',weight:8,opacity:.5,lineCap:'round',interactive:false}).addTo(map);
 const glow=L.polyline([],{color:'#37c8f0',weight:6,opacity:.22,lineCap:'round',interactive:false}).addTo(map);
 const core=L.polyline([],{color:'#52d6f5',weight:2.5,opacity:.9,lineCap:'round',interactive:false}).addTo(map);
 const flow=L.polyline([],{color:'#ffffff',weight:3,opacity:.95,dashArray:'1 12',lineCap:'round',interactive:false,className:'cc-flow'}).addTo(map);
 const set=n=>{const s=pts.slice(0,n);halo.setLatLngs(s);glow.setLatLngs(s);core.setLatLngs(s);flow.setLatLngs(s)};
 const chipLL=pts[Math.floor(pts.length/2)];
 const addChip=()=>{
  const chip=el('button','cc-chip');chip.type='button';const i=el('i','fa fa-exchange');i.setAttribute('aria-hidden','true');chip.append(i,document.createTextNode(' ≈ '+fmtKm(distKm)+(inetAvg!=null?' · '+Math.round(inetAvg)+' ms':'')));
  const w=el('div');w.style.cssText='position:relative;width:0;height:0';w.append(chip);
  const m=L.marker(chipLL,{icon:L.divIcon({className:'cc-chip-wrap',html:w,iconSize:[0,0]}),title:'Detalhes da rota',keyboard:true}).addTo(map);
  m.bindPopup(routeCard(distKm),popupOpts(-14));
 };
 const packet=()=>{
  if(reduce)return;
  const head=L.circleMarker(pts[0],{radius:6,color:'#fff',weight:2,fillColor:'#52d6f5',fillOpacity:1,interactive:false}).addTo(map);
  const lags=[.035,.07,.105,.14],trail=lags.map((d,i)=>L.circleMarker(pts[0],{radius:5-i,stroke:false,fillColor:'#52d6f5',fillOpacity:.5-i*.1,interactive:false}).addTo(map));
  const at=u=>{const f=Math.max(0,Math.min(1,u))*(pts.length-1),i=Math.floor(f),r=f-i,a2=pts[i],b2=pts[Math.min(i+1,pts.length-1)];return[a2[0]+(b2[0]-a2[0])*r,a2[1]+(b2[1]-a2[1])*r]};
  let t0=0;const P=3600;
  const step=ts=>{if(!map||!document.getElementById('cc-map'))return;if(!t0)t0=ts;if(!document.hidden){const u=((ts-t0)%P)/P;head.setLatLng(at(u));trail.forEach((m,i)=>m.setLatLng(at(Math.max(0,u-lags[i]))))}raf=requestAnimationFrame(step)};
  raf=requestAnimationFrame(step);
 };
 const done=()=>{set(pts.length);addChip();packet()};
 if(reduce){done();return}
 const t0=performance.now(),dur=1400;
 const grow=ts=>{const k=Math.min(1,(ts-t0)/dur);set(Math.max(2,Math.round(k*(pts.length-1))+1));k<1?requestAnimationFrame(grow):done()};
 setTimeout(()=>requestAnimationFrame(grow),900);
}
function originCard(){
 const ll=origin.ll;
 return card({tone:'origin',icon:'fa-globe',kicker:'DE ONDE VEM · PROVEDOR',title:originLabel||'Cidade não identificada',sub:esc(p.isp),
  rows:[['Organização',p.org&&p.org!==p.isp?p.org:''],['ASN',p.asn?'AS'+String(p.asn).replace(/^AS/i,''):''],['Domínio',p.domain],['IP público',state.publicIp],['Cidade',origin.city?label(origin.city,origin.uf):''],['Estado',origin.uf?(UFN[origin.uf]||origin.region)+' ('+origin.uf+')':origin.region],['País',origin.cc==='br'?'Brasil':origin.country],['CEP do IP',p.postal],['Fuso horário',p.timezone],['Coordenadas',ll?ll[0].toFixed(4)+', '+ll[1].toFixed(4):''],['Fonte',p.source]],
  note:origin.gpsVerified?'Origem confirmada pelo gestor.':'Geolocalização aproximada por IP público. Não identifica com exatidão a sede da provedora nem a localização física de seu equipamento.',
  link:ll?{text:'Abrir no OpenStreetMap ↗',href:'https://www.openstreetmap.org/?mlat='+encodeURIComponent(ll[0])+'&mlon='+encodeURIComponent(ll[1])+'#map=11/'+encodeURIComponent(ll[0])+'/'+encodeURIComponent(ll[1])}:null});
}
function destCard(){
 const gw=state.gateway?ms(state.gateway):null;
 return card({tone:'dest',icon:'fa-home',kicker:'PARA ONDE VAI · REDE',title:destLabel,sub:esc(D.name||state.networkName),
  rows:[['Rede',D.name],['Cliente',D.client],['Código',D.site],['Endereço',D.address],['Cidade',label(dest.city,dest.uf)],['CEP',D.cep],['Agente',state.agentName?state.agentName+(state.localIp?' · '+state.localIp:''):state.localIp],['Gateway',state.gateway?(state.gateway):''],['Latência gateway',gw!=null?latNode(gw):''],['DNS',state.dns]],
  note:'Posição aproximada obtida por geocodificação: '+(dest.geocodeSource||'município informado')+'. Para precisão física, confirme coordenadas GPS no local.'});
}
function routeCard(distKm){
 const hops=Array.isArray(state.route)?state.route:[],ok=hops.filter(h=>h&&h.responded).length;
 const q=quality(inetAvg);
 return card({tone:'route',icon:'fa-exchange',kicker:'TRECHO ESTIMADO',title:originLabel+' → '+destLabel,sub:'Distância em linha reta ≈ '+fmtKm(distKm),
  rows:[['Origem',originLabel],['Destino',destLabel],['Distância','≈ '+fmtKm(distKm)],['Gateway',state.gateway&&ms(state.gateway)!=null?latNode(ms(state.gateway)):''],['1.1.1.1 Cloudflare',lat['1.1.1.1']?latNode(ms('1.1.1.1')):''],['8.8.8.8 Google',lat['8.8.8.8']?latNode(ms('8.8.8.8')):''],['Saltos medidos',hops.length?hops.length+' ('+ok+' responderam)':''],['Qualidade',q?q[0]:'']],
  note:'A linha é ilustrativa: o caminho real dos pacotes passa por vários saltos da operadora.'});
}
async function boot(){
 $('cc-route').hidden=false;
 setState(dest.city||origin.city||origin.ll?'Localizando a região…':'',true);
 try{await loadLeaflet()}catch(e){setState('');setEmpty('Não foi possível carregar o mapa. Verifique a conexão e atualize a página.');return}
 const base={
  dark:L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',{maxZoom:18,className:'cc-dark-tile',attribution:'Tiles © Esri, HERE, Garmin, OpenStreetMap contributors'}),
  streets:L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}),
  sat:L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:18,attribution:'Imagens © Esri'})
 };
 map=L.map('cc-map',{zoomControl:false,zoomSnap:.25,minZoom:3,maxZoom:18,fadeAnimation:!reduce,zoomAnimation:!reduce,markerZoomAnimation:!reduce});
 map.attributionControl.setPrefix(false);
 let cur='';const useLayer=k=>{if(!base[k])k='dark';if(cur)map.removeLayer(base[cur]);base[k].addTo(map);cur=k;document.querySelectorAll('.cc-layers button').forEach(b=>b.setAttribute('aria-pressed',b.dataset.layer===k?'true':'false'));try{localStorage.setItem('arc-conexao-layer',k)}catch(e){}};
 let saved='dark';try{saved=localStorage.getItem('arc-conexao-layer')||'dark'}catch(e){}
 useLayer(saved);
 document.querySelectorAll('.cc-layers button').forEach(b=>b.addEventListener('click',()=>useLayer(b.dataset.layer)));
 $('cc-zin').onclick=()=>map.zoomIn();$('cc-zout').onclick=()=>map.zoomOut();$('cc-fit').onclick=()=>{map.closePopup();fit(true)};
 map.setView(origin.ll||[-14.2,-51.9],origin.ll?6:4,{animate:false});
 if('ResizeObserver' in window){let tm=0;new ResizeObserver(()=>{clearTimeout(tm);tm=setTimeout(()=>map&&map.invalidateSize(),120)}).observe($('cc-map'))}
 setTimeout(()=>map.invalidateSize(),60);
 const jobs=[];
 if(!origin.ll&&origin.city)jobs.push(geocode([origin.city,origin.region,origin.country].filter(Boolean).join(', '),origin.cc).then(v=>{origin.ll=v}));
 if(dest.city&&!dest.gpsVerified){
  const cityQuery=dest.city+', '+(dest.uf?dest.uf+', ':'')+'Brasil';
  const fullAddress=String(D.address||'').trim();
  jobs.push((async()=>{
   // Usa endereço completo quando informado; caso não seja geocodificável, recua ao município.
   // A geocodificação de rua não equivale a GPS de precisão garantida.
   let found=null;
   if(fullAddress && fullAddress.length>12) found=await geocode(fullAddress+', Brasil','br');
   dest.ll=found||await geocode(cityQuery,'br');
   dest.geocodeSource=found?'endereço informado (estimativa)':'centro do município (estimativa)';
  })());
 }
 await Promise.all(jobs);
 setState('');
 /* O ponto mais ao norte recebe o rótulo acima e o do sul abaixo, para não se sobreporem. */
 const both=!!(origin.ll&&dest.ll),north=both?origin.ll[0]>=dest.ll[0]:true;
 if(origin.ll){
  if(origin.fromIp)L.circle(origin.ll,{radius:3500,className:'cc-area',color:'#46d3f5',weight:1.5,dashArray:'4 6',fillColor:'#46d3f5',fillOpacity:.08,interactive:false}).addTo(map);
  pin('origin',origin.ll,originLabel||'Provedor','ORIGEM · '+(p.isp?String(p.isp).slice(0,28):'PROVEDOR'),north?'n':'s',originCard());
 }
 if(dest.ll)pin('dest',dest.ll,destLabel,'DESTINO · '+(D.name?String(D.name).slice(0,28):'REDE'),both&&north?'s':'n',destCard());
 if(both){
  const d=km(origin.ll,dest.ll),out=$('cc-r-dist');out.replaceChildren(el('b',null,'≈ '+fmtKm(d)),document.createTextNode(' em linha reta'));
  if(d>=.4)drawRoute(origin.ll,dest.ll,d);else out.textContent='mesma localidade';
 }
 if(!markers.length){setEmpty(measured?'Não foi possível localizar a cidade do provedor no mapa e a rede não tem uma cidade válida no endereço.':'Aguardando o diagnóstico do agente. Use “Diagnosticar” para identificar a provedora e a região.');return}
 setState(!measured?'Aguardando diagnóstico para identificar o provedor.':!origin.ll?'Cidade do provedor não localizada no mapa.':!dest.city?'Informe a cidade no endereço da rede (formato CIDADE/UF) para exibir o destino.':!dest.ll?'Cidade do destino não localizada no mapa.':'');
 fit(true);
}
boot();
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&map)map.invalidateSize()});
$('cc-update').addEventListener('click',()=>location.reload());
document.addEventListener('arc:command-queued',e=>{if(e.detail?.command==='diagnostic'&&Number(e.detail?.agent_id)===state.agentId){$('cc-message').textContent='Diagnóstico solicitado ao agente. Os resultados aparecerão após a sincronização.';sessionStorage.setItem('arc-connection-pending-'+state.networkId+'-'+state.agentId,String(Date.now()));setTimeout(()=>location.reload(),2200);}});
const pendingKey='arc-connection-pending-'+state.networkId+'-'+state.agentId;const began=Number(sessionStorage.getItem(pendingKey)||0);if(began&&Date.now()-began<180000){$('cc-message').textContent='Aguardando o diagnóstico do agente Windows…';const last=<?=json_encode($latestDiagnostic ? (string)$latestDiagnostic['captured_at'] : '')?>;let attempts=0;const tick=setInterval(async()=>{if(document.hidden)return;if(++attempts>12){clearInterval(tick);sessionStorage.removeItem(pendingKey);$('cc-message').textContent='Ainda sem resposta. Confira o status do agente.';return}try{const url=<?=json_encode(arc_workspace_base_url())?>+'api/api.php?action=network-command-status&network_id='+state.networkId;const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});const j=await r.json();const latest=(j.commands||[]).find(c=>Number(c.agent_id)===state.agentId&&c.command_name==='diagnostic');if(latest&&['completed','failed','expired'].includes(latest.status)){clearInterval(tick);sessionStorage.removeItem(pendingKey);$('cc-message').textContent=latest.status==='completed'?'Diagnóstico concluído. Atualizando…':'Diagnóstico não concluído: '+esc(latest.result_text);if(latest.status==='completed')setTimeout(()=>location.reload(),1200)}}catch(e){}},6000)}
})();

</script>
<?php require __DIR__.'/_rodape.php';
