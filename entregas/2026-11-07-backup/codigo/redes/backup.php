<?php
/* Área independente da Central de Backup; não carrega o Clean. */
declare(strict_types=1);
$arcSectionFile='backup';require __DIR__.'/_base.php';require_once dirname(__DIR__).'/includes/backup-center.php';
if(!in_array($teamRole,['owner','administrator'],true)){http_response_code(403);exit('Gestão de backup exige permissão administrativa.');}
$agents=arc_bc_q($pdo,'SELECT id,machine_name FROM '.arc_table('network_agents')." WHERE account_id=? AND network_id=? AND status='approved' ORDER BY machine_name,id",[(int)$account,(int)$networkId])->fetchAll(PDO::FETCH_ASSOC);
$chosen=(int)($_GET['agent']??($agents[0]['id']??0));if(!array_filter($agents,static fn($a)=>(int)$a['id']===$chosen))$chosen=(int)($agents[0]['id']??0);
if(!$chosen){require __DIR__.'/_topo.php';echo '<p>Aprove um computador para configurar o backup.</p>';require __DIR__.'/_rodape.php';exit;}
arc_bc_manager($pdo,(int)$networkId,$chosen);require __DIR__.'/_topo.php';$asset=dirname(__DIR__).'/assets/backup-center/';
?>
<style><?php readfile($asset.'styles.css'); ?></style>
<div class="bc-agent-picker"><label for="bc-agent">Computador</label><select id="bc-agent"><?php foreach($agents as $a): ?><option value="<?=(int)$a['id']?>" <?=(int)$a['id']===$chosen?'selected':''?>><?=arc_h((string)$a['machine_name'])?></option><?php endforeach; ?></select></div>
<section id="backup-center" class="bc-app" data-transport="web" data-api="<?=arc_h(arc_workspace_base_url().'api/backup.php')?>" data-network="<?=(int)$networkId?>" data-agent="<?=$chosen?>" data-csrf="<?=arc_h($csrf)?>" data-tab="<?=arc_h((string)($_GET['tab']??'overview'))?>"><p role="status">Carregando Central de Backup…</p></section>
<script><?php readfile($asset.'app.js'); ?>
document.getElementById('bc-agent').addEventListener('change',function(){const u=new URL(location.href);u.searchParams.set('agent',this.value);location.assign(u.href);});
</script>
<?php require __DIR__.'/_rodape.php'; ?>
