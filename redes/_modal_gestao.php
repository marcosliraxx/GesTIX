<dialog id="arc-network-manager" class="arc-network-manager" aria-labelledby="arc-network-manager-title" aria-modal="true">
 <div class="arc-network-manager-shell">
  <header class="arc-manager-header"><div class="arc-manager-heading"><span class="arc-manager-mark"><i class="fa fa-sliders" aria-hidden="true"></i></span><div><small>ARCOLL / GESTÃO DA REDE</small><h2 id="arc-network-manager-title">Administrar rede</h2><p><?= arc_h((string)$network['name']) ?> · <?= arc_h((string)($network['site_code']??'')) ?></p></div></div><button type="button" class="arc-manager-close" data-network-modal-close aria-label="Fechar"><i class="fa fa-times" aria-hidden="true"></i></button></header>
  <nav class="arc-manager-tabs" aria-label="Configurações da rede">
   <button type="button" class="arc-manager-tab active" data-manager-tab="agents" aria-selected="true"><i class="fa fa-desktop" aria-hidden="true"></i> Agentes <span class="arc-manager-tab-count"><?= count($networkAgents) ?></span></button>
   <button type="button" class="arc-manager-tab" data-manager-tab="team" aria-selected="false"><i class="fa fa-users" aria-hidden="true"></i> Usuários <span class="arc-manager-tab-count"><?= count($networkTeamMembers) ?></span></button>
   <button type="button" class="arc-manager-tab" data-manager-tab="settings" aria-selected="false"><i class="fa fa-cog" aria-hidden="true"></i> Configurações</button>
   <button type="button" class="arc-manager-tab" data-manager-tab="data" aria-selected="false"><i class="fa fa-building-o" aria-hidden="true"></i> Dados da rede</button>
  </nav>
  <div class="arc-manager-body">
   <div class="arc-manager-panel active" data-manager-panel="agents"><?php require __DIR__.'/_painel_agentes.php'; ?></div>
   <div class="arc-manager-panel" data-manager-panel="team" hidden><?php require __DIR__.'/_painel_usuarios_rede.php'; ?></div>
   <div class="arc-manager-panel" data-manager-panel="settings" hidden><?php require __DIR__.'/_painel_configuracoes.php'; ?></div>
   <div class="arc-manager-panel" data-manager-panel="data" hidden><?php require __DIR__.'/_painel_cadastro.php'; ?></div>
  </div>
  <footer class="arc-manager-footer"><span><i class="fa fa-lock" aria-hidden="true"></i> Alterações vinculadas exclusivamente a esta rede</span><button type="button" class="arc-manager-dismiss" data-network-modal-close>Fechar</button></footer>
 </div>
</dialog>
