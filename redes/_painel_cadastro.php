<section class="arc-redes-secao" id="tab-cadastro">

            <div class="card">
                
                <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                    <input type="hidden" name="action" value="network_update">
                    <input type="hidden" name="network_id" value="<?= (int)$network['id'] ?>">

                    <!-- Dados fixos (somente leitura) -->
                    <div class="form-field">
                        <label>Código do local<input value="<?= arc_h((string)($network['site_code'] ?: '—')) ?>" readonly></label>
                        <label>Registrada em<input value="<?= arc_h(arc_when($network['created_at'] ?? null)) ?>" readonly></label>

                    </div>

                    <!-- Dados editáveis -->
                    <div class="form-field">
                        <div class="arc-network-brand-editor span2">
 <label class="arc-network-logo-pick" title="Clique para alterar a logo" aria-label="Alterar logo da rede">
  <?php
/* Caminho: redes/_painel_cadastro.php */ if(!empty($network['logo_file'])): ?><img id="arc-logo-preview" alt="Logo da rede" src="<?=arc_h(arc_workspace_base_url())?>api/network-logo.php?network=<?=(int)$networkId?>"><?php else: ?><span id="arc-logo-placeholder"><i class="fa fa-picture-o"></i><small>Adicionar logo</small></span><img id="arc-logo-preview" alt="Prévia da logo" hidden><?php endif; ?>
  <input type="file" name="network_logo" accept="image/png,image/jpeg,image/webp" id="arc-network-logo-input" hidden>
 </label>
 <div class="arc-network-brand-fields"><label>Nome da rede<input name="network_name" value="<?=arc_h((string)$network['name'])?>" required></label><label>Cliente / empresa<input name="client_name" value="<?=arc_h((string)$network['client_name'])?>"></label></div>
 </div>
                        <label>Responsável<input name="contact_name" value="<?= arc_h((string)$network['contact_name']) ?>"></label>
                        <label>Telefone<input name="contact_phone" value="<?= arc_h((string)$network['contact_phone']) ?>"></label>
                        <label class="span2">Endereço<input name="address" value="<?= arc_h((string)$network['address']) ?>"></label>
                        <label class="span2">Observações<textarea name="notes"><?= arc_h((string)$network['notes']) ?></textarea></label>
                        <label class="inline-flex">
                            <input type="checkbox" name="monitoring_enabled" <?= $network['monitoring_enabled'] ? 'checked' : '' ?>>
                            Monitoramento ativo
                        </label>
                    </div>
                    <button class="button" type="submit">Salvar cadastro</button>
                </form>
                    <div class="network-danger-zone">
                        <div><strong>Zona de administração da rede</strong><small>Arquive temporariamente ou exclua todos os dados vinculados a esta rede.</small></div>
                        <div class="network-danger-actions">
                            <?php if ($isActive): ?>
                                <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" data-confirm="<?= !empty($network['monitoring_enabled']) ? 'Desativar' : 'Ativar' ?> o monitoramento desta rede?"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="network_monitoring_toggle"><button class="button secondary" type="submit"><?= !empty($network['monitoring_enabled']) ? 'Desativar monitoramento' : 'Ativar monitoramento' ?></button></form>
                                <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" data-confirm="Arquivar a rede <?= arc_h($arcNetName) ?>?"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="network_archive"><button class="button secondary" type="submit">Arquivar rede</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" data-confirm="Excluir definitivamente a rede <?= arc_h($arcNetName) ?>? Esta ação não pode ser desfeita."><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="network_delete"><button class="button danger" type="submit">Excluir rede</button></form>
                        </div>
                    </div>
            </div>



        </section>
