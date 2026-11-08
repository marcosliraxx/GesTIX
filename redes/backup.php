<?php
/* Caminho: redes/backup.php */
/* Área independente da Central de Backup; não carrega o Clean. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

$arcSectionFile='backup';require __DIR__.'/_base.php';require_once dirname(__DIR__).'/includes/backup-center.php';
if(!in_array($teamRole,['owner','administrator','system_admin'],true)){http_response_code(403);exit('Gestão de backup exige permissão administrativa.');}
$gestores=arc_bc_q($pdo,'SELECT id,machine_name FROM '.arc_table('network_gestores')." WHERE account_id=? AND network_id=? AND status='approved' ORDER BY machine_name,id",[(int)$account,(int)$networkId])->fetchAll(PDO::FETCH_ASSOC);
$chosen=(int)($_GET['gestor']??($gestores[0]['id']??0));if(!array_filter($gestores,static fn($a)=>(int)$a['id']===$chosen))$chosen=(int)($gestores[0]['id']??0);
if(!$chosen){require __DIR__.'/_topo.php';echo '<p>Aprove um computador para configurar o backup.</p>';require __DIR__.'/_rodape.php';exit;}
arc_bc_manager($pdo,(int)$networkId,$chosen);require __DIR__.'/_topo.php';$asset=dirname(__DIR__).'/assets/backup-center/';
?>
<style><?php readfile($asset.'styles.css'); ?></style>
<div class="bc-gestor-picker"><label for="bc-gestor">Computador</label><select id="bc-gestor"><?php foreach($gestores as $a): ?><option value="<?=(int)$a['id']?>" <?=(int)$a['id']===$chosen?'selected':''?>><?=arc_h((string)$a['machine_name'])?></option><?php endforeach; ?></select></div>
<section id="backup-center" class="bc-app" data-transport="web" data-api="<?=arc_h(arc_workspace_base_url().'api/backup.php')?>" data-network="<?=(int)$networkId?>" data-gestor="<?=$chosen?>" data-csrf="<?=arc_h($csrf)?>" data-tab="<?=arc_h((string)($_GET['tab']??'overview'))?>"><p role="status">Carregando Central de Backup…</p></section>
<script><?php readfile($asset.'app.js'); ?>
document.getElementById('bc-gestor').addEventListener('change',function(){const u=new URL(location.href);u.searchParams.set('gestor',this.value);location.assign(u.href);});
</script>
<?php require __DIR__.'/_rodape.php'; ?>
