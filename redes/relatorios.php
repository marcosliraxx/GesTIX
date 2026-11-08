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
$search=mb_substr(trim((string)($_GET['q']??'')),0,100);
$section=(string)($_GET['section']??'redes');
$sections=['geral'=>'Visão geral','redes'=>'Redes','gestores'=>'Computadores','equipe'=>'Equipe','medicoes'=>'Medições','acessos'=>'Acessos e atividades'];
if(!isset($sections[$section]))$section='redes';
$requested=max(0,(int)($_GET['network']??0));
/* Rede selecionada: acesso à gestão exige pelo menos um gestor liberado. */
if ($requested>0 && !$isAdmin) {
    $reportAccess=arc_network_team_access($pdo,$requested,$userId);
    if ($reportAccess) {
        $gate=$pdo->prepare('SELECT COUNT(*) FROM '.arc_table('network_gestores')." WHERE network_id=? AND account_id=? AND status='approved'");
        $gate->execute([$requested,(int)$reportAccess['account_id']]);
        if(!(int)$gate->fetchColumn()) {
            header('Location: '.arc_workspace_base_url().'redes/liberacao.php?network='.$requested,true,303);
            exit;
        }
    }
}

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
 $overview=[['Indicador'=>'Redes acessíveis','Quantidade'=>count($selected)],['Indicador'=>'Redes ativas','Quantidade'=>count(array_filter($selected,static fn($n)=>(int)$n['is_active']===1))]];
 $gestores=$selectedIds?$run('SELECT a.network_id,n.name AS rede,a.machine_name AS computador,a.platform AS sistema,a.gestor_version AS versao,a.status AS situacao,a.first_seen_at AS instalado,a.last_seen_at AS ultimo_contato FROM '.arc_table('network_gestores').' a JOIN '.arc_table('managed_networks').' n ON n.id=a.network_id WHERE a.network_id IN ('.$in.') AND a.first_seen_at>=? AND a.first_seen_at<? ORDER BY a.last_seen_at DESC LIMIT 1000',[...$selectedIds,$since,$until]):[];
 $overview[]=['Indicador'=>'Computadores cadastrados','Quantidade'=>count($gestores)];
 $overview[]=['Indicador'=>'Computadores aprovados','Quantidade'=>count(array_filter($gestores,static fn($a)=>$a['situacao']==='approved'))];
 $team=$selectedIds?$run('SELECT n.name AS rede,m.member_user_id AS usuario,m.role AS perfil,m.enabled AS ativo,m.created_at AS incluído FROM '.arc_table('network_team_members').' m JOIN '.arc_table('managed_networks').' n ON n.id=m.network_id WHERE m.network_id IN ('.$in.') AND m.created_at>=? AND m.created_at<? ORDER BY m.created_at DESC LIMIT 1000',[...$selectedIds,$since,$until]):[];
 $overview[]=['Indicador'=>'Participações na equipe','Quantidade'=>count($team)];
 $counts=$selectedIds?$run('SELECT n.name AS rede,t.event_type AS tipo,COUNT(*) AS registros,MAX(t.captured_at) AS ultima_coleta FROM '.arc_table('gestor_telemetry').' t JOIN '.arc_table('managed_networks').' n ON n.id=t.network_id WHERE t.network_id IN ('.$in.') AND t.captured_at>=? AND t.captured_at<? GROUP BY t.network_id,t.event_type ORDER BY registros DESC LIMIT 500',[...$selectedIds,$since,$until]):[];
 $overview[]=['Indicador'=>'Medições no período','Quantidade'=>array_sum(array_map('intval',array_column($counts,'registros')))];
 // Auditoria de titulares: não divulgar todo o histórico de uma conta a integrantes convidados.
 if($isAdmin){$audits=$run('SELECT event_type AS evento,details AS descricao,created_at AS data FROM '.arc_table('account_audit').' WHERE created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 500',[$since,$until]);
 $adminAudits=$run('SELECT event_type AS evento,description AS descricao,created_at AS data FROM '.arc_table('audit_logs').' WHERE created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 300',[$since,$until]);$audits=array_merge($audits,$adminAudits);usort($audits,static fn($a,$b)=>strcmp($b['data'],$a['data']));$audits=array_slice($audits,0,800);}
 else{$audits=$run('SELECT event_type AS evento,details AS descricao,created_at AS data FROM '.arc_table('account_audit').' WHERE account_id=? AND created_at>=? AND created_at<? ORDER BY created_at DESC LIMIT 500',[$userId,$since,$until]);}
 $overview[]=['Indicador'=>'Atividades consultadas','Quantidade'=>count($audits)];
 $networkRows=[];foreach($selected as $n){if($n['created_at']<$since||$n['created_at']>=$until)continue;$networkRows[]=['Rede'=>$n['name'],'Cliente'=>$n['client_name'],'Situação'=>((int)$n['is_active']?'Ativa':'Inativa'),'Cadastrada em'=>$n['created_at'],'Último contato'=>$n['last_seen_at']?:'Sem registro'];}
 $gestorRows=[];foreach($gestores as $a){$gestorRows[]=['Rede'=>$a['rede'],'Computador'=>$a['computador'],'Sistema'=>$a['sistema'],'Versão'=>$a['versao'],'Situação'=>$labels[$a['situacao']]??$a['situacao'],'Instalado'=>$a['instalado'],'Último contato'=>$a['ultimo_contato']];}
 $teamRows=[];foreach($team as $m){$teamRows[]=['Rede'=>$m['rede'],'Usuário nº'=>$m['usuario'],'Perfil'=>$labels[$m['perfil']]??$m['perfil'],'Acesso'=>((int)$m['ativo']?'Ativo':'Desativado'),'Incluído em'=>$m['incluído']];}
 $measureRows=[];foreach($counts as $c)$measureRows[]=['Rede'=>$c['rede'],'Tipo de coleta'=>(['monitor'=>'Monitoramento','speed_test'=>'Velocidade','diagnostic'=>'Conexão','devices'=>'Dispositivos','quick_command'=>'Verificação','command_execution'=>'Operação remota','map'=>'Topologia'][$c['tipo']]??'Coleta de informações'),'Registros'=>$c['registros'],'Última coleta'=>$c['ultima_coleta']];
 $activityRows=[];foreach($audits as $a){$copy=arc_notice_copy((string)$a['evento']);$activityRows[]=['Atividade'=>$copy[0],'Descrição'=>$copy[1],'Data'=>$a['data']];}
 $sectionsData=['geral'=>['Resumo'=>$overview,'Redes'=>$networkRows,'Computadores'=>$gestorRows,'Equipe'=>$teamRows,'Medições'=>$measureRows,'Acessos e atividades'=>$activityRows], 'redes'=>['Redes'=>$networkRows], 'gestores'=>['Computadores'=>$gestorRows], 'equipe'=>['Equipe'=>$teamRows], 'medicoes'=>['Medições'=>$measureRows], 'acessos'=>['Acessos e atividades'=>$activityRows]];
 $rows=$sectionsData[$section];
 if($search!=='')foreach($rows as $title=>$data)$rows[$title]=array_values(array_filter($data,static fn($record)=>mb_stripos(implode(' ',array_map('strval',array_values($record))),$search)!==false));
}catch(Throwable $e){error_log('Central relatórios: '.$e->getMessage());$error='Não foi possível consultar todos os dados. Verifique o log do servidor.';$rows=[];}
if(($_GET['export']??'')==='csv'){
 if($error!==''){http_response_code(422);header('Content-Type: text/plain; charset=utf-8');exit($error);}
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="relatorio-'.date('Ymd-His').'.csv"');echo "\xEF\xBB\xBF";$fh=fopen('php://output','w');
 foreach($rows as $name=>$data){fputcsv($fh,[$name],';');if($data){fputcsv($fh,array_keys($data[0]),';');foreach($data as $record)fputcsv($fh,array_map(static fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".(string)$v:(string)$v,array_values($record)),';');}else fputcsv($fh,['Sem registros'],';');fputcsv($fh,[],';');}fclose($fh);exit;
}
$ARCMode='app';$ARCPageTitle='Central de relatórios';$ARCActiveNav='reports_center';$ARCHeaderActions='';
require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';
?>

<main class="app-main"><div class="arc-rpt" id="report-result">
<header class="arc-service-heading"><h1>Relatórios</h1><button type="button" class="arc-icon-action" data-open-dialog="report-help" title="Sobre os filtros" aria-label="Sobre os filtros"><i class="fa fa-info" aria-hidden="true"></i></button></header>
<dialog id="report-help" class="arc-service-dialog"><header class="arc-service-heading"><h2>Filtros e impressão</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Redes, computadores e equipe usam a data de cadastro. Medições e atividades usam a data do registro. A busca considera o texto de todas as colunas exibidas. A impressão A4 e o CSV incluem exatamente a lista filtrada, dentro dos limites informados ao final. Deslize a barra para acessar os filtros no celular.</p></dialog>
<form class="arc-filter-bar" method="get" action="redes/relatorios.php">
<input type="search" name="q" value="<?=arc_h($search)?>" placeholder="Buscar na lista" aria-label="Buscar no relatório">
<select name="section" aria-label="Tipo de relatório"><?php foreach($sections as $key=>$value):?><option value="<?=arc_h($key)?>" <?=$section===$key?'selected':''?>><?=arc_h($value)?></option><?php endforeach;?></select>
<select name="network" aria-label="Rede"><option value="0">Todas as redes</option><?php foreach($networks as $n):?><option value="<?=(int)$n['id']?>" <?=$requested===(int)$n['id']?'selected':''?>><?=arc_h($n['name'])?></option><?php endforeach;?></select>
<input type="date" name="from" value="<?=arc_h($start)?>" required aria-label="Data inicial" title="Data inicial"><input type="date" name="to" value="<?=arc_h($finish)?>" required aria-label="Data final" title="Data final">
<button class="arc-icon-action" type="submit" title="Filtrar" aria-label="Filtrar"><i class="fa fa-search" aria-hidden="true"></i></button><button type="submit" class="arc-icon-action" name="export" value="csv" title="Exportar CSV" aria-label="Exportar CSV"><i class="fa fa-download" aria-hidden="true"></i></button><button type="submit" name="print" value="1" class="arc-icon-action" id="report-print" title="Imprimir lista filtrada" aria-label="Imprimir lista filtrada"><i class="fa fa-print" aria-hidden="true"></i></button>
</form>
<?php if($error!==''):?><div class="rpt-error" role="alert"><?=arc_h($error)?></div><?php endif;?>
<?php if($error===''):?><div class="rpt-print-heading"><h2>Relatório — <?=arc_h($sections[$section])?></h2><p><?=arc_h($start)?> a <?=arc_h($finish)?> · <?=arc_h($requested?($selected[0]['name']??'Rede'):'Todas as redes permitidas')?><?=$search!==''?' · Busca: '.arc_h($search):''?></p><small>Emitido em <?=arc_h(date('d/m/Y H:i'))?> · <?=arc_h($arcSiteName)?></small></div>
<?php foreach($rows as $title=>$data):?><section><h2 class="rpt-title"><?=arc_h($title)?> <small>(<?=count($data)?> exibidos)</small></h2><div class="rpt-table-wrap"><?php if(!$data):?><div class="rpt-empty">Não há registros neste período ou filtro.</div><?php else:?><table><thead><tr><?php foreach(array_keys($data[0]) as $field):?><th><?=arc_h((string)$field)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($data as $record):?><tr><?php foreach($record as $value):?><td><?=arc_h((string)$value)?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?></div></section><?php endforeach;?>
<span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Listagens limitadas para manter a consulta rápida: até 1.000 computadores e integrantes, 500 grupos de medições e até 800 atividades. Os números representam os registros consultados, não necessariamente o histórico integral.</p></dialog><?php endif;?>
</div></main>
<?php if(($_GET['print']??'')==='1'&&$error===''):?><script>window.addEventListener('load',()=>{const address=new URL(location.href);address.searchParams.delete('print');history.replaceState(null,'',address);window.print();},{once:true});</script><?php endif;?>
<?php if(!empty($networkId)) require __DIR__.'/_gestor_update_dialog.php'; ?>
<?php require dirname(__DIR__).'/includes/footer.php';
