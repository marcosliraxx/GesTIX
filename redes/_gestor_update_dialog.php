<?php
/* Caminho: redes/_gestor_update_dialog.php
 * Modal "Gestor Windows" (instalar / atualizar / forçar) + script autônomo.
 * Incluído por _rodape.php e também pelas páginas de rede que usam o cabeçalho sem o rodapé
 * (liberacao.php, relatorios.php): antes, nelas o botão do cabeçalho não abria nada.
 * Exige $networkId. Usa $teamRole e $csrf quando existirem. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/error-logging.php';

if (!empty($GLOBALS['arc_gestor_update_dialog_rendered'])) return;
$GLOBALS['arc_gestor_update_dialog_rendered'] = true;
$arcUpdRole = (string)($teamRole ?? ($access['role'] ?? ''));
$arcUpdCsrf = (string)($csrf ?? arc_csrf_token());
$arcUpdBase = arc_workspace_base_url();
$arcUpdJs = dirname(__DIR__) . '/assets/js/gestor-update.js';
?>
<dialog id="arc-gestor-update-confirm" class="arc-gestor-update-confirm arc-service-dialog" aria-labelledby="arc-gestor-update-title"
        data-network="<?= (int)$networkId ?>" data-base="<?= arc_h($arcUpdBase) ?>" data-csrf="<?= arc_h($arcUpdCsrf) ?>"
        data-can-manage="<?= in_array($arcUpdRole, ['owner', 'administrator'], true) ? '1' : '0' ?>">
<div class="arc-gestor-update-card">
 <div class="arc-update-modal-header"><div><strong id="arc-gestor-update-title">Atualização do gestor</strong> <small data-update-modal-version>Versão publicada: consultando…</small></div><button class="arc-update-modal-close" type="button" data-update-cancel aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></div>
 <div class="arc-gestor-update-copy"><small data-update-gestor-name>Gestor selecionado</small><p data-update-gestor-details></p><p data-update-status class="arc-update-state" role="status">Consultando a versão instalada…</p><div data-update-release class="arc-update-release-details" role="region" aria-label="Notas da versão" tabindex="0"></div></div>
 <div class="arc-gestor-update-actions arc-service-actions" role="group" aria-label="Instalação e atualização do gestor">
 <?php if ($arcUpdRole === 'owner'): ?>
 <a class="button secondary" data-update-install rel="noopener" href="<?= arc_h($arcUpdBase . 'api/download-gestor.php?network=' . (int)$networkId) ?>" title="Baixar o instalador Windows para executar no computador de destino"><i class="fa fa-download" aria-hidden="true"></i> Instalar gestor</a>
 <a class="button secondary" rel="noopener" href="<?= arc_h($arcUpdBase . 'api/download-gestor.php?mode=bat&network=' . (int)$networkId) ?>" title="Alternativa de instalação pelo iniciador BAT">Alternativa BAT</a>
 <?php else: ?>
 <button type="button" class="button secondary" disabled title="Somente o proprietário da rede pode gerar instaladores"><i class="fa fa-download" aria-hidden="true"></i> Instalar gestor</button>
 <?php endif; ?>
 <button type="button" class="button" data-update-confirm><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar</button>
 <button type="button" class="button secondary" data-update-force-confirm><i class="fa fa-cloud-download" aria-hidden="true"></i> Forçar atualização</button>
 </div>
</div></dialog>
<script src="<?= arc_h($arcUpdBase) ?>assets/js/gestor-update.js?v=<?= (int)@filemtime($arcUpdJs) ?>" defer></script>
