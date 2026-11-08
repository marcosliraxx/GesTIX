<?php
/* Caminho: admin/versao.php */
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';arc_require_admin();$pdo=arc_db();arc_ensure_user_schema($pdo);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{arc_verify_csrf();$action=(string)($_POST['action']??'');
  if($action==='detect'){arc_release_sync($pdo,true);$notice='Arquivos conferidos. Se algum arquivo mudou, a nova versão já foi publicada automaticamente.';}else throw new RuntimeException('Ação inválida.');
 }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Não foi possível publicar a versão.';}
}
$history=$pdo->query('SELECT * FROM '.arc_table('platform_release_history').' ORDER BY id DESC LIMIT 100')->fetchAll();$ARCMode='app';$ARCPageTitle='Versão da plataforma';$ARCActiveNav='versions';require dirname(__DIR__).'/includes/header.php';require dirname(__DIR__).'/includes/sidebar.php';?>
<main class="app-main"><section class="card"><h1>Versão da plataforma</h1><p>Publicada: <strong><?=arc_h(arc_app_version())?></strong></p><p>A versão é única e automática: muda sozinha sempre que um arquivo da plataforma é alterado. Não é preciso informar nem editar versão em nenhum código ou formulário.</p><?php if($error):?><p role="alert"><?=arc_h($error)?></p><?php endif;?><?php if($notice):?><p role="status"><?=arc_h($notice)?></p><?php endif;?>
<form method="post"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="detect"><button class="button">Conferir alterações de arquivos</button></form><p>Alterações de conteúdo e novos arquivos/pastas públicos são detectados automaticamente. A publicação é imediata na próxima requisição à plataforma. Configurações privadas, uploads, logs e pacotes não provocam versões. Permissão de escrita em VERSION é necessária.</p>
<h2>Histórico</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Versão</th><th>Publicação</th><th>Origem</th><th>Alterações</th></tr></thead><tbody><?php foreach($history as $item):?><tr><td><?=arc_h($item['version'])?></td><td><?=arc_h(arc_when($item['created_at']))?></td><td><?=arc_h($item['source'])?></td><td style="white-space:pre-wrap;overflow-wrap:anywhere"><?=arc_h($item['notes'])?></td></tr><?php endforeach;?></tbody></table></div></section></main><?php require dirname(__DIR__).'/includes/footer.php';?>
