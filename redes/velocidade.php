<?php
declare(strict_types=1);
$arcSectionFile='velocidade';
require __DIR__.'/_base.php';
// Os comandos continuam protegidos por login, titularidade da rede e agente aprovado.
if (($_GET['speed_feed']??'')==='1') {
    $lastResult=null; $lastProgress=null;
    $requestedCommand=max(0,(int)($_GET['command_id']??0));
    foreach ($remoteEvents as $event) {
        $payload=json_decode((string)($event['payload_json']??''),true);
        if (!is_array($payload)) continue;
        $type=(string)($payload['event']??$event['event_type']??'');
        if ($type==='speed_test' && $lastResult===null && (!$requestedCommand || (int)($payload['command_id']??0)===$requestedCommand)) $lastResult=['data'=>$payload,'time'=>(string)($event['captured_at']??'')];
        if ($type==='speed_progress' && $lastProgress===null && (!$requestedCommand || (int)($payload['command_id']??0)===$requestedCommand)) $lastProgress=['data'=>$payload,'time'=>(string)($event['captured_at']??'')];
        if ($lastResult && $lastProgress) break;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['ok'=>true,'result'=>$lastResult,'progress'=>$lastProgress],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
require __DIR__.'/_topo.php';
?>
<section class="arc-speed-view" aria-label="Teste de velocidade da internet">
 <button class="arc-speed-start" id="arc-speed-start" type="button" aria-label="Iniciar teste de velocidade" aria-busy="false"><span id="arc-speed-text">Start</span><small id="arc-speed-gauge">INICIAR TESTE</small></button>
 <div class="arc-speed-caption" id="arc-speed-caption" role="status" aria-live="polite">Selecione um agente Windows conectado para iniciar.</div>
 <div class="arc-speed-progress" role="progressbar" aria-label="Progresso do teste" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="arc-speed-progress"><span id="arc-speed-bar"></span></div>
 <div class="arc-speed-results" id="arc-speed-results" hidden>
  <div class="arc-speed-result"><span><i class="fa fa-arrow-down"></i> DOWNLOAD</span><strong id="arc-speed-down">--</strong><small>Mbps</small></div>
  <div class="arc-speed-result"><span><i class="fa fa-arrow-up"></i> UPLOAD</span><strong id="arc-speed-up">--</strong><small>Mbps</small></div>
  <div class="arc-speed-result"><span><i class="fa fa-clock-o"></i> LATÊNCIA</span><strong id="arc-speed-ping">--</strong><small>ms</small></div>
 </div>
 <div class="arc-speed-note" id="arc-speed-note">Medição real executada no computador pelo agente Arcoll. O teste consome tráfego de internet.</div>
</section>
<script>
(()=>{'use strict';
 const base=<?=json_encode(arc_workspace_base_url())?>,network=<?= (int)$networkId ?>,csrf=<?=json_encode($csrf)?>;
 const get=id=>document.getElementById(id), start=get('arc-speed-start'),target=get('arc-agent-target'),caption=get('arc-speed-caption'),text=get('arc-speed-text'),gauge=get('arc-speed-gauge'),progress=get('arc-speed-progress'),bar=get('arc-speed-bar'),results=get('arc-speed-results');
 let commandId=0,busy=false,startedAt=0,baseline='',timer=null,activeAgent='';
 const numeral=x=>x===null||x===undefined||!Number.isFinite(Number(x))?'--':Number(x).toFixed(2);
 function percent(x){const n=Math.min(100,Math.max(0,Number(x)||0));progress.setAttribute('aria-valuenow',String(Math.round(n)));bar.style.width=n+'%'}
 function setBusy(v){busy=v;start.disabled=v;start.setAttribute('aria-busy',String(v));if(!v){text.textContent='Start';gauge.textContent='TESTAR NOVAMENTE'}}
 async function json(url,opts){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...opts});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Erro de comunicação com o servidor');return j}
 function display(d){results.hidden=false;get('arc-speed-down').textContent=numeral(d.download_mbps);get('arc-speed-up').textContent=numeral(d.upload_mbps);get('arc-speed-ping').textContent=numeral(d.latency_ms)}
 function stamp(x){return x?String(x.time||'')+':'+String(x.data?.command_id||''):''}
 async function check(){
  if(!target.value)return;
  try{
   const [cmds,feed]=await Promise.all([json(base+'api/api.php?action=network-command-status&network_id='+network),json(location.pathname+'?network='+network+'&agent='+encodeURIComponent(target.value)+'&speed_feed=1'+(commandId?'&command_id='+commandId:''))]);
   const commands=(cmds.commands||[]).filter(c=>Number(c.agent_id)===Number(target.value)&&c.command_name==='speed_test');
   if(!busy && !commandId){const active=commands.find(c=>c.status==='pending'||c.status==='running');if(active){commandId=Number(active.id);setBusy(true);startedAt=Date.now();caption.textContent='Teste em execução no agente Windows. Sincronizando progresso…';gauge.textContent='MEDINDO';text.textContent='...'}}
   const mine=commands.find(c=>Number(c.id)===commandId);
   const progressData=feed.progress;
   if(busy){
    if(progressData && stamp(progressData)!==baseline && Number(progressData.data?.command_id)===commandId){
     const d=progressData.data||{}; percent(d.percent);caption.textContent=d.message||'Medição em andamento no agente Windows';gauge.textContent=Math.round(Number(d.percent)||0)+'%';
     if(d.download_mbps!=null||d.upload_mbps!=null||d.latency_ms!=null)display(d);
    }
    if(mine && mine.status==='failed'){setBusy(false);caption.textContent='Teste interrompido: '+(mine.result_text||'falha no agente');return}
    if(mine && mine.status==='completed'){
      const d=feed.result?.data||{};
      if(feed.result && Number(feed.result.data?.command_id)===commandId){display(d);percent(100);setBusy(false);caption.textContent='Teste concluído no agente Windows';get('arc-speed-note').textContent='Servidor: '+(d.server||'Não informado');return}
      caption.textContent='Teste finalizado. Aguardando sincronização do resultado…';
    }
    if(Date.now()-startedAt>150000){setBusy(false);caption.textContent='Tempo limite atingido. Confira a conexão do agente.'}
   } else if(!commandId && feed.result){display(feed.result.data||{});get('arc-speed-note').textContent='Último resultado sincronizado · '+feed.result.time}
  }catch(e){if(busy)caption.textContent='Aguardando comunicação: '+e.message}
 }
 start.addEventListener('click',async()=>{
  if(busy)return;if(!target.value){caption.textContent='Selecione um agente Windows aprovado na barra superior.';return}
  setBusy(true);percent(0);results.hidden=true;text.textContent='0%';gauge.textContent='PREPARANDO';caption.textContent='Enviando comando ao agente Windows…';
  commandId=0;startedAt=Date.now();activeAgent=target.value;
  try{
   const previous=await json(location.pathname+'?network='+network+'&agent='+encodeURIComponent(target.value)+'&speed_feed=1'+(commandId?'&command_id='+commandId:''));baseline=stamp(previous.progress);
   const j=await json(base+'api/api.php?action=network-command',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({network_id:network,agent_id:Number(target.value),command:'speed_test',_csrf:csrf})});
   commandId=Number(j.command_id);caption.textContent='Comando enviado. Aguardando o agente iniciar…';
  }catch(e){setBusy(false);caption.textContent=e.message}
 });
 check();timer=setInterval(()=>{if(!document.hidden && target.value && (!busy||activeAgent===target.value))check()},2000);
})();
</script>
<?php require __DIR__.'/_rodape.php';
