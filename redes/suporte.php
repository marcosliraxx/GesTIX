<?php
/** Caminho: redes/suporte.php */
declare(strict_types=1);
$arcSectionFile = 'suporte';
require __DIR__ . '/_base.php';
require __DIR__ . '/_topo.php';
?>
<section class="arc-support-page" aria-labelledby="arc-support-title">
  <div class="arc-support-card">
    <div class="arc-support-icon"><i class="fa fa-life-ring" aria-hidden="true"></i></div>
    <div class="arc-support-summary">
      <h1 id="arc-support-title">Suporte remoto</h1>
      <p>Selecione o computador na barra superior e solicite o acesso à tela. O computador remoto deve autorizar a conexão, conforme sua configuração.</p>
      <p class="arc-support-selected">Computador: <strong id="arc-support-agent-name"><?= $selectedAgentId > 0 ? arc_h((string)(array_values(array_filter($networkAgents, static fn($agent) => (int)$agent['id'] === $selectedAgentId))[0]['machine_name'] ?? 'Selecione um computador')) : 'Selecione um computador' ?></strong></p>
    </div>
    <button type="button" class="button arc-support-start" id="arc-agent-remote-view" <?= $selectedAgentId > 0 ? '' : 'disabled' ?>><i class="fa fa-desktop" aria-hidden="true"></i> Solicitar acesso remoto</button>
  </div>
</section>
<?php require __DIR__ . '/_rodape.php'; ?>
