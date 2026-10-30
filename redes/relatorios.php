<?php
/** Caminho: redes/relatorios.php — central de relatórios com isolamento de contas. */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
$pdo=arc_db();
header('Cache-Control: no-store, private');
$isAdmin=arc_is_admin();
if(!$isAdmin&&!arc_is_user()){header('Location: '.arc_workspace_base_url().'index.php',true,303);exit;}
$userId=(int)arc_user_id();
$today=new DateTimeImmutable('today');
$start=(string)($_GET['from']??$today->modify('-30 days')->format('Y-m-d'));
$finish=(string)($_GET['to']??$today->format('Y-m-d'));
$fromDate=DateTimeImmutable::createFromFormat('!Y-m-d',$start);
$toDate=DateTimeImmutable::createFromFormat('!Y-m-d',$finish);
$error='';
if(!$fromDate||!$toDate||$fromDate->format('Y-m-d')!==$start||$toDate->format('Y-m-d')!==$finish||$fromDate>$toDate||$toDate>$today||$fromDate->diff($toDate)->days>365){$error='Escolha datas válidas dentro de um período máximo de 366 dias.';$start=$today->modify('-30 days')->format('Y-m-d');$finish=$today->format('Y-m-d');}
$since=$start.' 00:00:00';$until=(new DateTimeImmutable($finish))->modify('+1 day')->format('Y-m-d').' 00:00:00';
$section=(string)($_GET['section']??'geral');
$sections=['geral'=>'Visão geral','redes'=>'Redes','agentes'=>'Computadores','equipe'=>'Equipe','medicoes'=>'Medições','acessos'=>'Acessos e atividades'];
if(!isset($sections[$section]))$section='geral';
$requested=max(0,(int)($_GET['network']??0));
// Escopo: administradores visualizam tudo; titulares e convidados somente redes autorizadas.
if($isAdmin){$networks=$pdo->query('SELECT id,account_id,name,client_name,is_active,created_at,last_seen_at FROM '.arc_table('managed_networks').' ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);}
else{
 $q=$pdo->prepare('SELECT DISTINCT n.id,n.account_id,n.name,n.client_name,n.is_active,n.created_at,n.last_seen_at FROM '.arc_table('managed_networks').' n LEFT JOIN '.arc_table('network_team_members').' m ON m.network_id=n.id AND m.member_user_id=? AND m.enabled=1 WHERE n.account_id=? OR m.id IS NOT NULL ORDER BY n.name,n.id');
 $q->execute([$userId,$userId]);$networks=$q->fetchAll(PDO::FETCH_ASSOC);
}
$ids=array_map('intval',array_column($networks,'id'));
if($requested && !in_array($requested,$ids,true)){$error='Você não tem acesso à rede escolhida.';$requested=0;}
$selected=$requested?array_values(array_filter($networks,static fn($n)=>(int)$n['id']===$requested)):$networks;
$selectedIds=array_map('intval',array_column($selected,'id'));
$rows=[];$total=[];$sectionsData=[];
$labels=['pending'=>'Pendente','approved'=>'Aprovado','blocked'=>'Bloqueado','rejected'=>'Rejeitado','owner'=>'Responsável','admin'=>'Administrador','operator'=>'Operador','viewer'=>'Visualização'];
$run=function(string $sql,array $parameters=[]) use($pdo):array {$statement=$pdo->prepare($sql);$statement->execute($parameters);return $statement->fetchAll(PDO::FETCH_ASSOC);};
$in=$selectedIds?implode(',',array_fill(0,count($selectedIds),'?')):'0';
try{
 $overview=[['Indicador','Quantidade'],['Redes acessíveis',count($selected)],['Redes ativas',count(array_filter($selected,static fn($n)=>(int)$n['is_active']===1))]];
 $agents=$selectedIds?$run('SELECT a.network_id,n.name AS rede,a.machine_name AS computador,a.platform AS sistema,a.agent_version AS versao,a.status AS situacao,a.first_seen_at AS instalado,a.last_seen_at AS ultimo_contato FROM '.arc_table('network_agents').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id WHERE a.network_id IN ('.$in.') ORDER BY a.last_seen_at DESC LIMIT 1000',$selectedIds):[];
 $overview[]=['Computadores cadastrados',count($agents)];
 $overview[]=['Computadores aprovados',count(array_filter($agents,static fn($a)=>$a['situacao']==='approved'))];
 $team=$selectedIds?$run('SELECT n.name AS rede,m.member_user_id AS usuario,m.role AS perfil,m.enabled AS ativo,m.created_at AS incluído FROM '.arc_table('network_team_members').' m JOIN '.arc_table('managed_networks').' n ON n.id=m.network_id WHERE m.network_id IN ('.$in.') ORDER BY m.created_at DESC LIMIT 1000',$selectedIds):[];
 $overview[]=['Participações na equipe',count($team)];
 $counts=$selectedIds?$run('SELECT n.name AS rede,t.event_type AS tipo,COUNT(*) AS registros,MAX(t.captured_at) AS ultima_coleta FROM '.arc_table('agent_telemetry').' t JOIN '.arc_table('managed_networks').' n ON n.id=t.network_id WHERE t.network_id IN ('.$in.') AND t.captured_at>=? AND t.captured_at<? GROUP BY t.network_id,t.event_type ORDER BY registros DESC LIMIT 500',[...$selectedIds,$since,$until]):[];
 $overview[]=['Medições no período',array_sum(array_map('intval',array_column($counts,'registros')))];
 $accountIds=array_values(array_unique(array_map('intval',array_column($selected,'account_id'))));
 $accountsIn=$accountIds?implode(',',array_fill(0,count($accountIds),'?')):'0';
 // Auditoria de titulares: não divulgar todo o histórico de uma conta a integrantes convidados.
 if($isAdmin){$audits=$run('SELECT event_type AS evento,details AS descricao,created_at AS data FROM '.arc_table('account_audit').' WHERE created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 500',[$since,$until]);
 $adminAudits=$run('SELECT event_type AS evento,description AS descricao,created_at AS data FROM '.arc_table('audit_logs').' WHERE created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 300',[$since,$until]);$audits=array_merge($audits,$adminAudits);usort($audits,static fn($a,$b)=>strcmp($b['data'],$a['data']));$audits=array_slice($audits,0,800);}
 else{$audits=$run('SELECT event_type AS evento,details AS descricao,created_at AS data FROM '.arc_table('account_audit').' WHERE account_id=? AND created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 500',[$userId,$since,$until]);}
 $overview[]=['Atividades consultadas',count($audits)];
 $networkRows=[];foreach($selected as $n)$networkRows[]=['Rede'=>$n['name'],'Cliente'=>$n['client_name'],'Situação'=>((int)$n['is_active']?'Ativa':'Inativa'),'Cadastrada em'=>$n['created_at'],'Último contato'=>$n['last_seen_at']?:'Sem registro'];
 $agentRows=[];foreach($agents as $a)$agentRows[]=['Rede'=>$a['rede'],'Computador'=>$a['computador'],'Sistema'=>$a['sistema'],'Versão'=>$a['versao'],'Situação'=>$labels[$a['situacao']]??$a['situacao'],'Instalado'=>$a['instalado'],'Último contato'=>$a['ultimo_contato']];
 $teamRows=[];foreach($team as $m)$teamRows[]=['Rede'=>$m['rede'],'Usuário nº'=>$m['usuario'],'Perfil'=>$labels[$m['perfil']]??$m['perfil'],'Acesso'=>((int)$m['ativo']?'Ativo':'Desativado'),'Incluído em'=>$m['incluído']];
 $measureRows=[];foreach($counts as $c)$measureRows[]=['Rede'=>$c['rede'],'Tipo de coleta'=>$c['tipo'],'Registros'=>$c['registros'],'Última coleta'=>$c['ultima_coleta']];
 $activityRows=[];foreach($audits as $a)$activityRows[]=['Atividade'=>ucfirst(str_replace(['_','.'],' ',$a['evento'])),'Descrição'=>$a['descricao'],'Data'=>$a['data']];
 $sectionsData=['geral'=>['Resumo'=>$overview,'Redes'=>$networkRows,'Computadores'=>$agentRows,'Equipe'=>$teamRows,'Medições'=>$measureRows,'Acessos e atividades'=>$activityRows], 'redes'=>['Redes'=>$networkRows], 'agentes'=>['Computadores'=>$agentRows], 'equipe'=>['Equipe'=>$teamRows], 'medicoes'=>['Medições'=>$measureRows], 'acessos'=>['Acessos e atividades'=>$activityRows]];
 $rows=$sectionsData[$section];
}catch(Throwable $e){error_log('Central relatórios: '.$e->getMessage());$error='Não foi possível consultar todos os dados. Verifique o log do servidor.';$rows=[];}
if(($_GET['export']??'')==='csv'){
 if($error!==''){http_response_code(422);header('Content-Type: text/plain; charset=utf-8');exit($error);}
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="relatorio-'.date('Ymd-His').'.csv"');echo "\xEF\xBB\xBF";$fh=fopen('php://output','w');
 foreach($rows as $name=>$data){fputcsv($fh,[$name],';');if($data){fputcsv($fh,array_keys($data[0]),';');foreach($data as $record)fputcsv($fh,array_values($record),';');}else fputcsv($fh,['Sem registros'],';');fputcsv($fh,[],';');}fclose($fh);exit;
}
$ARCMode='app';$ARCPageTitle='Central de relatórios';$ARCActiveNav='reports_center';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>
<style>
.arc-rpt{padding:17px;min-width:0}.arc-rpt h1{font-size:21px;margin:0}.arc-rpt .sub{color:var(--muted);margin:5px 0 17px}.arc-rpt-controls{display:flex;flex-wrap:wrap;gap:9px;align-items:end;padding:13px;background:var(--surface);border:1px solid var(--border);border-radius:12px}.arc-rpt-controls label{display:grid;gap:5px;color:var(--muted);font-size:11px;flex:1 1 130px}.arc-rpt-controls select,.arc-rpt-controls input{background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:7px;padding:9px;min-width:0;width:100%;box-sizing:border-box}.arc-rpt .rpt-btn{display:inline-flex;gap:7px;align-items:center;justify-content:center;border:1px solid var(--border);background:var(--surface-alt);color:var(--text);padding:9px 12px;border-radius:8px;text-decoration:none;cursor:pointer}.arc-rpt .rpt-primary{background:#117ca9;color:white}.arc-rpt .rpt-title{font-size:14px;margin:20px 0 8px}.arc-rpt .rpt-table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.arc-rpt table{width:100%;border-collapse:collapse;font-size:12px}.arc-rpt th,.arc-rpt td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--border);white-space:nowrap}.arc-rpt th{color:var(--muted);font-size:11px}.arc-rpt .rpt-empty{padding:15px;color:var(--muted)}.arc-rpt .rpt-error{padding:12px;border:1px solid #b85c5c;border-radius:8px;margin:14px 0}.arc-rpt .rpt-print-heading{display:none}@media print{.app-sidebar,.app-header,.arc-rpt-controls,.arc-rpt .rpt-actions,.app-footer{display:none!important}.arc-rpt{padding:0;color:#111}.arc-rpt .rpt-print-heading{display:block}.arc-rpt .rpt-table-wrap{overflow:visible}.arc-rpt th,.arc-rpt td{white-space:normal;color:#111;border-color:#bbb}.arc-rpt table{font-size:9px}.arc-rpt section{break-inside:auto}}
</style>
<main class="app-main"><div class="arc-rpt">
<h1><i class="fa fa-bar-chart" aria-hidden="true"></i> Central de relatórios</h1><p class="sub"><?= $isAdmin?'Visão administrativa de todo o sistema.':'Relatórios das suas redes e atividades autorizadas.' ?> Dados registrados na plataforma, referentes ao período escolhido.</p>
<form class="arc-rpt-controls" method="get" action="redes/relatorios.php">
<label>Relatório<select name="section"><?php foreach($sections as $key=>$value):?><option value="<?=arc_h($key)?>" <?=$section===$key?'selected':''?>><?=arc_h($value)?></option><?php endforeach;?></select></label>
<label>Rede<select name="network"><option value="0">Todas as redes permitidas</option><?php foreach($networks as $n):?><option value="<?=(int)$n['id']?>" <?=$requested===(int)$n['id']?'selected':''?>><?=arc_h($n['name'])?></option><?php endforeach;?></select></label>
<label>De<input type="date" name="from" value="<?=arc_h($start)?>" required></label><label>Até<input type="date" name="to" value="<?=arc_h($finish)?>" required></label>
<button class="rpt-btn rpt-primary" type="submit"><i class="fa fa-search"></i> Consultar</button>
</form>
<?php if($error!==''):?><div class="rpt-error" role="alert"><?=arc_h($error)?></div><?php endif;?>
<?php if($error===''):?><div class="rpt-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px"><a class="rpt-btn" href="<?=arc_h('redes/relatorios.php?'.http_build_query(array_merge($_GET,['export'=>'csv'])))?>"><i class="fa fa-file-excel-o"></i> Exportar CSV</a><button type="button" class="rpt-btn" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button></div>
<div class="rpt-print-heading"><h2>Relatório — <?=arc_h($sections[$section])?></h2><p><?=arc_h($start)?> a <?=arc_h($finish)?></p></div>
<?php foreach($rows as $title=>$data):?><section><h2 class="rpt-title"><?=arc_h($title)?> <small>(<?=count($data)?> exibidos)</small></h2><div class="rpt-table-wrap"><?php if(!$data):?><div class="rpt-empty">Não há registros neste período ou filtro.</div><?php else:?><table><thead><tr><?php foreach(array_keys($data[0]) as $field):?><th><?=arc_h((string)$field)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($data as $record):?><tr><?php foreach($record as $value):?><td><?=arc_h((string)$value)?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?></div></section><?php endforeach;?>
<span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Listagens limitadas para manter a consulta rápida: até 1.000 computadores e integrantes, 500 grupos de medições e até 800 atividades. Os números representam os registros consultados, não necessariamente o histórico integral.</p></dialog><?php endif;?>
</div></main>
<?php require dirname(__DIR__).'/includes/footer.php';
