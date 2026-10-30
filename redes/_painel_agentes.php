<div id="tab-agents">
            <div class="card">
                
                <div class="arc-agent-summary"><span class="badge"><?= count($networkAgents) ?> agentes · <?= $pendingAgentCount ?> aguardando aprovação</span></div>
                <?php if (!$networkAgents): ?>
                    <div class="empty-state"><b>Nenhum agente cadastrado</b>Quando um computador enviar o código <strong><?= arc_h((string)$network['site_code']) ?></strong>, a solicitação aparecerá nesta aba para aprovação.</div>
                <?php else: ?>
                    <div class="table-wrap"><table class="arc-responsive-table data-table">
                        <thead><tr><th>Computador</th><th>Sistema operacional</th><th>IP local</th><th>Versão</th><th>Status</th><th>Acesso remoto</th><th>Primeiro cadastro</th><th>Último contato</th><th>Ações do gestor</th></tr></thead>
                        <tbody><?php foreach ($networkAgents as $agent): $isHeadless = (int)($agent['headless_mode'] ?? 0) === 1; ?>
                            <tr>
                                <td data-label="Computador"><strong><?= arc_h((string)$agent['machine_name']) ?></strong><br><small><?= arc_h(substr((string)$agent['fingerprint'],0,16)) ?>…</small></td>
                                <td data-label="Sistema operacional"><?= arc_h((string)$agent['platform']) ?><?php $machineDetails = json_decode((string)($agent['machine_details'] ?? '{}'), true); if (is_array($machineDetails) && $machineDetails): ?><details class="mt-1"><summary class="link-action">Configuração</summary><small class="wrap-cell"><?php foreach ($machineDetails as $detailKey => $detailValue): ?><?= arc_h(ucwords(str_replace('_',' ',(string)$detailKey))) ?>: <?= arc_h(is_array($detailValue) ? implode(', ',array_map('strval',$detailValue)) : (string)$detailValue) ?><br><?php endforeach; ?></small></details><?php endif; ?></td><td data-label="IP local"><?= arc_h((string)($agent['local_ip'] ?: '—')) ?></td><td data-label="Versão"><?= arc_h((string)($agent['agent_version'] ?: '—')) ?></td>
                                <td data-label="Status"><span class="device-badge <?= $agent['status']==='approved'?'online':($agent['status']==='pending'?'warning':'offline') ?>"><?= arc_h(match((string)$agent['status']){'approved'=>'Aprovado','pending'=>'Pendente','rejected'=>'Rejeitado','blocked'=>'Bloqueado',default=>'Desconhecido'}) ?></span></td>
                                <td data-label="Acesso remoto">
                                    <span class="device-badge <?= $isHeadless?'warning':'online' ?>" title="<?= $isHeadless?'Servidor sem monitor/teclado/mouse: dispensa confirmação local para este dispositivo autorizado; todas as sessões são auditadas.':'Exige confirmação do usuário na tela do computador remoto.' ?>"><?= $isHeadless?'Sem monitor · automático':'Com confirmação local' ?></span>
                                    <button type="button" class="button secondary arc-headless-toggle-btn" style="margin-left:6px;padding:3px 8px;font-size:11px" data-headless-agent-id="<?= (int)$agent['id'] ?>" data-headless-agent-name="<?= arc_h((string)$agent['machine_name']) ?>" data-headless-current="<?= $isHeadless?'1':'0' ?>"><?= $isHeadless?'Desmarcar':'Marcar sem monitor' ?></button>
                                </td>
                                <td data-label="Primeiro cadastro"><?= arc_h(arc_when($agent['first_seen_at'] ?? null)) ?></td><td data-label="Último contato"><?= arc_h(arc_when($agent['last_seen_at'] ?? null)) ?></td>
                                <td data-label="Ações do gestor"><div class="toolbar">
                                    <?php if ($agent['status'] !== 'approved'): ?><form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="agent_approve"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="button" type="submit">Aprovar</button></form><?php endif; ?>
                                    <?php if ($agent['status'] !== 'blocked'): ?><form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="agent_block"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="button secondary" type="submit">Bloquear</button></form><?php endif; ?>
                                    <?php if ($agent['status'] !== 'rejected'): ?><form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="agent_reject"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="button secondary" type="submit">Rejeitar</button></form><?php endif; ?>
                                    <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" data-confirm="Excluir o vínculo deste agente? Um novo cadastro será exigido."><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="agent_delete"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="button danger" type="submit">Excluir</button></form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                    <p class="muted" style="font-size:12px;margin-top:8px">"Sem monitor" é só para computadores que realmente não têm teclado, mouse nem monitor (servidores só com cabo de rede). Nesses, ninguém consegue clicar em "Permitir" na tela do Windows, então o acesso é liberado automaticamente após a confirmação do gestor autenticado — e toda sessão, com ou sem monitor detectado na hora, fica no seu histórico de auditoria.</p>
                <?php endif; ?>
            </div>
        </div>
        <dialog id="arc-headless-confirm" aria-labelledby="arc-headless-confirm-title" style="border:1px solid #315c7d;border-radius:14px;background:#0c1f33;color:#e6f5ff;max-width:min(92vw,360px);padding:0">
            <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>" style="padding:18px 20px">
                <input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                <input type="hidden" name="action" value="agent_headless_toggle">
                <input type="hidden" name="agent_id" id="arc-headless-confirm-agent-id" value="">
                <h2 id="arc-headless-confirm-title" style="font-size:15px;margin:0 0 4px">Confirmar configuração</h2>
                <p id="arc-headless-confirm-text" style="font-size:12px;color:#9bbbd1;margin:0 0 10px"></p>
                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px">
                    <button type="button" class="button secondary" id="arc-headless-confirm-cancel">Cancelar</button>
                    <button type="submit" class="button">Confirmar</button>
                </div>
            </form>
        </dialog>
        <script>
        (function(){
            const dlg=document.getElementById('arc-headless-confirm');if(!dlg)return;
            const agentIdInput=document.getElementById('arc-headless-confirm-agent-id'),text=document.getElementById('arc-headless-confirm-text'),cancel=document.getElementById('arc-headless-confirm-cancel');
            document.querySelectorAll('.arc-headless-toggle-btn').forEach(function(btn){
                btn.addEventListener('click',function(){
                    agentIdInput.value=btn.dataset.headlessAgentId;
                    const turningOn=btn.dataset.headlessCurrent!=='1';
                    text.textContent=turningOn
                        ? 'Marcar "'+btn.dataset.headlessAgentName+'" como servidor sem monitor/teclado/mouse. O acesso remoto não vai mais esperar confirmação na tela do Windows.'
                        : 'Remover o acesso automático de "'+btn.dataset.headlessAgentName+'". Voltará a exigir confirmação na tela do Windows.';
                    dlg.showModal();
                });
            });
            cancel.addEventListener('click',function(){dlg.close()});
        })();
        </script>