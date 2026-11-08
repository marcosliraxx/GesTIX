<?php
/** Caminho: admin/gestorwin.php — gerenciamento de publicações Windows. */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/admin-actions.php';
arc_require_admin();
$pdo = arc_db();
arc_v16_release_schema($pdo);
$flash = isset($flash) && is_array($flash) ? $flash : null;
if ($flash) {
    if (($flash['type'] ?? '') === 'error') {
        $arcError = (string) ($flash['message'] ?? '');
    } else {
        $_SESSION['arc_flash'] = (string) ($flash['message'] ?? '');
    }
}
$rows = $pdo->query(
    'SELECT r.id,r.version,r.original_name,r.file_size,r.created_at,r.is_active,r.sha256,m.notes,m.channel '
    . 'FROM ' . arc_table('releases') . ' r LEFT JOIN ' . arc_table('gestor_release_metadata')
    . " m ON m.release_id=r.id WHERE r.platform='windows' ORDER BY r.created_at DESC LIMIT 60"
)->fetchAll(PDO::FETCH_ASSOC);
$latest = $rows[0] ?? null;
$activeCount = count(array_filter($rows, static fn(array $r): bool => (int) $r['is_active'] === 1));
$ARCPageTitle = 'Versões do gestor Windows';
$ARCActiveNav = 'releases';
$ARCPageBodyClass = 'arc-gestorwin-page';
$ARCMode = 'app';
require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
?>
<style>
.arc-gestorwin-page .aw-page{width:100%;max-width:none;min-width:0;padding:12px 16px;box-sizing:border-box;display:grid;gap:16px;align-content:start}
.arc-gestorwin-page .aw-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.arc-gestorwin-page .aw-top h1{margin:0;font-size:20px;line-height:1.3}
.arc-gestorwin-page .aw-muted{color:var(--muted);font-size:12px;margin:5px 0 0;line-height:1.5}
.arc-gestorwin-page .aw-panels{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(330px,.9fr);gap:16px;align-items:start}
.arc-gestorwin-page .aw-panel{min-width:0;border:1px solid var(--border);border-radius:var(--arc-card-radius,13px);background:var(--surface);overflow:hidden}
.arc-gestorwin-page .aw-panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:17px 19px;border-bottom:1px solid var(--border)}
.arc-gestorwin-page .aw-panel-head h2{margin:0;font-size:15px}
.arc-gestorwin-page .aw-panel-body{padding:18px 19px}
.arc-gestorwin-page .aw-fields{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.arc-gestorwin-page .aw-field{display:grid;gap:6px;min-width:0;color:var(--text);font-size:12px;font-weight:650}
.arc-gestorwin-page .aw-field.aw-span{grid-column:1/-1}
.arc-gestorwin-page .aw-field input:not([type=file]),.arc-gestorwin-page .aw-field select,.arc-gestorwin-page .aw-field textarea{width:100%;min-width:0;box-sizing:border-box;padding:10px 11px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text);font:inherit;font-size:13px}
.arc-gestorwin-page .aw-field textarea{resize:vertical;min-height:130px;line-height:1.55}
.arc-gestorwin-page .aw-field input[type=file]{width:100%;min-width:0;box-sizing:border-box;padding:11px;border:1px dashed var(--border);border-radius:9px;background:var(--surface-alt,var(--surface));font-size:12px}
.arc-gestorwin-page .aw-field small{color:var(--muted);font-weight:400;line-height:1.5}
.arc-gestorwin-page .aw-actions{display:flex;flex-wrap:wrap;gap:9px;align-items:center}
.arc-gestorwin-page .aw-button{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text);padding:10px 13px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none}
.arc-gestorwin-page .aw-button.aw-primary{background:var(--arc-brand-accent,var(--primary));border-color:var(--arc-brand-accent,var(--primary));color:#071521}
.arc-gestorwin-page .aw-button.aw-danger{color:#ec6e76}
.arc-gestorwin-page .aw-button:disabled{opacity:.55;cursor:wait}
.arc-gestorwin-page .aw-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.arc-gestorwin-page .aw-stat{padding:15px;border:1px solid var(--border);border-radius:10px;min-width:0}
.arc-gestorwin-page .aw-stat strong{display:block;font-size:21px;overflow-wrap:anywhere}
.arc-gestorwin-page .aw-stat span{display:block;color:var(--muted);font-size:11px;margin-top:5px}
.arc-gestorwin-page .aw-history{display:grid;gap:10px;margin-top:15px}
.arc-gestorwin-page .aw-release{border:1px solid var(--border);border-radius:11px;padding:14px;display:grid;gap:9px}
.arc-gestorwin-page .aw-release-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.arc-gestorwin-page .aw-release h3{font-size:14px;margin:0}
.arc-gestorwin-page .aw-badge{display:inline-block;border-radius:20px;border:1px solid var(--border);padding:4px 8px;font-size:10px;font-weight:750}
.arc-gestorwin-page .aw-release p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px;line-height:1.6}
.arc-gestorwin-page .aw-filemeta{overflow-wrap:anywhere;color:var(--muted);font-size:11px;line-height:1.6}
.arc-gestorwin-page .aw-empty{color:var(--muted);text-align:center;padding:28px 10px;font-size:13px}
.arc-gestorwin-page .aw-hint{font-size:12px;line-height:1.6;color:var(--muted);margin:0}
@media(max-width:1050px){.arc-gestorwin-page .aw-panels{grid-template-columns:1fr}}
@media(max-width:640px){.arc-gestorwin-page .aw-page{padding:13px}.arc-gestorwin-page .aw-fields{grid-template-columns:1fr}.arc-gestorwin-page .aw-stat-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.arc-gestorwin-page .aw-stat{padding:10px}.arc-gestorwin-page .aw-stat strong{font-size:16px}}
</style>
<main class="aw-page" id="gestorwin-content">
  <div class="aw-top"><div><h1><i class="fa fa-windows" aria-hidden="true"></i> Gerenciar atualizações Windows</h1><span class="arc-page-help"><button type="button" class="arc-icon-action" data-open-dialog="page-help-1" aria-label="Saiba mais sobre esta área" title="Saiba mais"><i class="fa fa-info" aria-hidden="true"></i></button></span><dialog id="page-help-1" class="arc-service-dialog"><header class="arc-service-heading"><h2>Saiba mais</h2><button type="button" class="arc-icon-action" data-close-dialog aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header><p>Publique versões, acompanhe o histórico e mantenha as notas de atualização organizadas.</p></dialog></div><a class="aw-button" href="<?=arc_h(arc_navigation_home($pdo))?>"><i class="fa fa-desktop" aria-hidden="true"></i> Voltar ao painel</a></div>
  <div class="aw-stat-grid">
    <div class="aw-stat"><strong><?=count($rows)?></strong><span>Publicações recentes</span></div>
    <div class="aw-stat"><strong><?= $activeCount ?></strong><span>Publicações ativas</span></div>
    <div class="aw-stat"><strong><?= $latest ? arc_h((string)$latest['version']) : '—' ?></strong><span>Última versão publicada</span></div>
  </div>
  <div class="aw-panels">
    <section class="aw-panel" aria-labelledby="aw-publish-title">
      <div class="aw-panel-head"><h2 id="aw-publish-title"><i class="fa fa-cloud-upload" aria-hidden="true"></i> Publicar nova versão</h2></div>
      <div class="aw-panel-body">
        <form id="aw-upload-form" class="aw-fields" method="post" enctype="multipart/form-data" action="admin/gestorwin.php">
          <input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="upload_release"><input type="hidden" name="platform" value="windows">
          <label class="aw-field">Versão central<input name="version" value="<?=arc_h(arc_app_version())?>" readonly><small>Gerencie a versão e as notas em Administração / Versões.</small></label>
          <label class="aw-field">Canal<select name="release_channel"><option value="stable">Estável</option><option value="beta">Beta / testes</option></select></label>
          <label class="aw-field aw-span">Pacote de atualização<input id="aw-file" name="release_file" type="file" accept=".zip,.exe,.msi" required><small>ZIP, EXE ou MSI; limite de 100 MB. A publicação não instala o pacote automaticamente nos computadores.</small></label>
          <label class="aw-field aw-span">Novidades e correções<textarea name="release_notes" maxlength="3000" rows="6" placeholder="Descreva as novidades, correções e melhorias desta versão..." required></textarea><small>As notas serão apresentadas ao gestor quando a versão correspondente for instalada.</small></label>
          <div class="aw-actions aw-span"><button class="aw-button aw-primary" id="aw-submit" type="submit"><i class="fa fa-upload" aria-hidden="true"></i> Publicar versão</button><span id="aw-progress" class="aw-muted" role="status" aria-live="polite"></span></div>
        </form>
      </div>
    </section>
    <section class="aw-panel" aria-labelledby="aw-history-title">
      <div class="aw-panel-head"><h2 id="aw-history-title"><i class="fa fa-history" aria-hidden="true"></i> Histórico de versões</h2><span class="aw-badge"><?= count($rows) ?> registro(s)</span></div>
      <div class="aw-panel-body">
      <?php if (!$rows): ?><div class="aw-empty"><i class="fa fa-folder-open-o fa-2x" aria-hidden="true"></i><p>Nenhuma versão Windows publicada.</p></div><?php endif; ?>
      <div class="aw-history">
      <?php foreach($rows as $r): ?>
        <article class="aw-release">
          <div class="aw-release-head"><h3>Versão <?=arc_h((string)$r['version'])?></h3><div><span class="aw-badge"><?=($r['channel']??'stable')==='beta'?'Beta':'Estável'?></span> <span class="aw-badge"><?=(int)$r['is_active']===1?'Ativa':'Inativa'?></span></div></div>
          <div class="aw-filemeta"><i class="fa fa-file-archive-o" aria-hidden="true"></i> <?=arc_h((string)$r['original_name'])?> · <?=number_format((int)$r['file_size']/1048576,2,',','.')?> MB · <?=arc_h((string)$r['created_at'])?></div>
          <?php if(trim((string)($r['notes']??''))!==''): ?><p><?=arc_h((string)$r['notes'])?></p><?php endif; ?>
          <div class="aw-filemeta" title="SHA-256">SHA-256: <?=arc_h((string)$r['sha256'])?></div>
          <div class="aw-actions"><form method="post" action="admin/gestorwin.php" onsubmit="return confirm('Excluir permanentemente esta publicação e seu pacote de atualização?');"><input type="hidden" name="_csrf" value="<?=arc_h(arc_csrf_token())?>"><input type="hidden" name="action" value="delete_release"><input type="hidden" name="release_id" value="<?=arc_h((string)$r['id'])?>"><button class="aw-button aw-danger" type="submit"><i class="fa fa-trash" aria-hidden="true"></i> Excluir publicação</button></form></div>
        </article>
      <?php endforeach; ?>
      </div>
      <?php if(count($rows)===60): ?><p class="aw-hint">Exibindo as 60 publicações mais recentes.</p><?php endif; ?>
      </div>
    </section>
  </div>
</main>
<script>
(function(){
  const form=document.getElementById('aw-upload-form'),file=document.getElementById('aw-file'),button=document.getElementById('aw-submit'),status=document.getElementById('aw-progress');
  form.addEventListener('submit',function(e){
    if(file.files.length&&file.files[0].size>104857600){e.preventDefault();status.textContent='O arquivo excede o limite de 100 MB.';return;}
    button.disabled=true;status.textContent='Enviando pacote. Não feche esta página.';
  });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
