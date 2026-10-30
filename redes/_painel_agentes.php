<?php /** Caminho: redes/_painel_agentes.php */ ?>
<section class="arc-manage-section" id="tab-agents">
 <div class="arc-manage-toolbar"><label class="arc-manage-search"><i class="fa fa-search" aria-hidden="true"></i><input type="search" data-manager-search="agents" placeholder="Buscar computador, sistema ou versão" aria-label="Buscar agentes"></label><span class="arc-manage-count"><?= count($networkAgents) ?> agentes<?php if ($pendingAgentCount): ?> · <?= (int)$pendingAgentCount ?> pendentes<?php endif; ?></span><a class="arc-icon-btn arc-download" href="<?= arc_h(arc_workspace_base_url()) ?>api/download-agent.php?network=<?= (int)$networkId ?>" title="Baixar agente" aria-label="Baixar agente"><i class="fa fa-download" aria-hidden="true"></i></a></div>
 <div class="arc-manage-table-scroll"><table class="arc-manage-table"><thead><tr><th>Computador</th><th>Sist. op.</th><th>Versão</th><th>Instalado</th><th>Último contato</th><th class="arc-actions-col">Ações</th></tr></thead><tbody>
 <?php foreach ($networkAgents as $agent): $isHeadless=(int)($agent['headless_mode']??0)===1; $details=json_decode((string)($agent['machine_details']??'{}'),true); ?>
 <tr class="arc-agent-row" tabindex="0" role="button" aria-label="Ver detalhes de <?= arc_h((string)$agent['machine_name']) ?>" data-agent-details="agent-details-<?= (int)$agent['id'] ?>" data-search-text="<?= arc_h(mb_strtolower(implode(' ',[(string)$agent['machine_name'],(string)$agent['platform'],(string)($agent['agent_version']??''),(string)$agent['status']]),'UTF-8')) ?>">
 <td><span class="arc-name" title="<?= arc_h((string)$agent['machine_name']) ?>"><?= arc_h((string)$agent['machine_name']) ?></span><small class="arc-status-dot <?= arc_h((string)$agent['status']) ?>" title="<?= arc_h((string)$agent['status']) ?>"></small></td>
 <td title="<?= arc_h((string)$agent['platform']) ?>"><span class="arc-ellipsis"><?= arc_h(mb_strimwidth(preg_replace('/^Windows-(.+?)-10\.0\.(\d+).*$/i', 'Windows $1', (string)$agent['platform']) ?? (string)$agent['platform'],0,28,'…','UTF-8')) ?></span></td><td><?= arc_h((string)($agent['agent_version']?:'—')) ?></td><td><?= arc_h(arc_when($agent['first_seen_at']??null)) ?></td><td><?= arc_h(arc_when($agent['last_seen_at']??null)) ?></td>
 <td><div class="arc-manage-actions">
 <?php foreach ([['agent_approve','fa-check-circle','Aprovar','approved'],['agent_block','fa-ban','Bloquear','blocked'],['agent_reject','fa-times-circle','Rejeitar','rejected'],['agent_delete','fa-trash','Excluir','']] as [$task,$icon,$label,$skip]): if ($skip!=='' && $agent['status']===$skip) continue; ?>
 <form method="post" action="<?= arc_h(arc_workspace_base_url()) ?>redes/servidor.php?network=<?= (int)$networkId ?>" <?= $task==='agent_delete'?'data-confirm="Excluir este agente? Um novo cadastro será necessário."':'' ?>><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="<?= $task ?>"><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button type="submit" class="arc-icon-btn <?= $task==='agent_delete'?'arc-danger':'' ?>" title="<?= $label ?>" aria-label="<?= $label ?>: <?= arc_h((string)$agent['machine_name']) ?>"><i class="fa <?= $icon ?>" aria-hidden="true"></i></button></form>
 <?php endforeach; ?>
 <button type="button" class="arc-icon-btn arc-headless-toggle-btn <?= $isHeadless?'arc-selected':'' ?>" data-headless-agent-id="<?= (int)$agent['id'] ?>" data-headless-agent-name="<?= arc_h((string)$agent['machine_name']) ?>" data-headless-current="<?= $isHeadless?'1':'0' ?>" aria-label="<?= $isHeadless?'Desativar':'Ativar' ?> modo sem monitor" title="<?= $isHeadless?'Desativar':'Ativar' ?> modo sem monitor"><i class="fa fa-television" aria-hidden="true"></i></button>
 </div></td></tr>
 <?php endforeach; ?>
 </tbody></table><div class="arc-no-results" data-manager-empty="agents" hidden>Nenhum agente encontrado.</div><?php if (!$networkAgents): ?><div class="arc-no-results">Nenhum agente cadastrado.</div><?php endif; ?></div>
 <?php
 // Informações confirmadas pelo cadastro do agente; campos não recebidos não são deduzidos.
 $agentSystemName = static function (string $raw): string {
     if ($raw === '') return 'Não informado';
     if (preg_match('/^Windows-(.+?)-10\.0\.(\d+)(?:-.*)?$/i', $raw, $m)) {
         $name = str_replace(['2025Server','2022Server','2019Server'], ['Server 2025','Server 2022','Server 2019'], $m[1]);
         return 'Windows ' . $name . ' (compilação ' . $m[2] . ')';
     }
     return str_replace(['-SP0','-'], ['', ' '], $raw);
 };
 $agentGigabytes = static function ($value): ?string {
     return is_numeric($value) && (float)$value >= 0 ? number_format((float)$value, 1, ',', '.') . ' GB' : null;
 };
 foreach ($networkAgents as $agent):
     $details = json_decode((string)($agent['machine_details'] ?? ''), true);
     if (!is_array($details)) $details = [];
     $memory = isset($details['memory']) && is_array($details['memory']) ? $details['memory'] : [];
     $disk = isset($details['system_disk']) && is_array($details['system_disk']) ? $details['system_disk'] : [];
     $data = [
         'Computador' => (string)($agent['machine_name'] ?? ''),
         'Sistema operacional' => $agentSystemName((string)($agent['platform'] ?? '')),
         'Versão do aplicativo' => (string)($agent['agent_version'] ?? ''),
         'Situação do acesso' => ['approved'=>'Aprovado', 'pending'=>'Aguardando aprovação', 'rejected'=>'Rejeitado', 'blocked'=>'Bloqueado'][(string)($agent['status'] ?? '')] ?? 'Não informada',
         'Endereço na rede local' => (string)($agent['local_ip'] ?? ''),
         'Roteador da rede' => (string)($agent['gateway_ip'] ?? ''),
         'Primeiro cadastro' => arc_when($agent['first_seen_at'] ?? null),
         'Último contato registrado' => arc_when($agent['last_seen_at'] ?? null),
         'Acesso sem confirmação no computador' => (int)($agent['headless_mode'] ?? 0) === 1 ? 'Habilitado' : 'Desabilitado',
     ];
     if (isset($details['cpu_count']) && is_numeric($details['cpu_count']) && (int)$details['cpu_count'] > 0) $data['Processadores lógicos'] = (string)(int)$details['cpu_count'];
     if (($total = $agentGigabytes($memory['total_gb'] ?? null)) !== null) $data['Memória RAM instalada'] = $total;
     if (($available = $agentGigabytes($memory['available_gb'] ?? null)) !== null) $data['Memória RAM disponível na coleta'] = $available;
     if (($total = $agentGigabytes($disk['total_gb'] ?? null)) !== null) $data['Espaço total do disco'] = $total;
     if (($free = $agentGigabytes($disk['free_gb'] ?? null)) !== null) $data['Espaço livre no disco na coleta'] = $free;
 ?>
 <div class="arc-agent-details-source" id="agent-details-<?= (int)$agent['id'] ?>" hidden>
  <h3><i class="fa fa-desktop" aria-hidden="true"></i> <?= arc_h((string)$agent['machine_name']) ?></h3>
  <p class="arc-detail-explainer">Informações enviadas pelo computador. Dados de memória e disco são da última coleta recebida, não uma leitura em tempo real.</p>
  <dl class="arc-detail-grid">
  <?php foreach ($data as $label => $value): $value = trim((string)$value); if ($value === '' || $value === '—') continue; ?>
   <div><dt><?= arc_h($label) ?></dt><dd title="<?= arc_h($value) ?>"><?= arc_h($value) ?></dd></div>
  <?php endforeach; ?>
  </dl>
 </div>
 <?php endforeach; ?>
</section>
<dialog id="arc-agent-detail-modal" class="arc-manage-detail" aria-label="Detalhes do agente"><div class="arc-manage-detail-header"><strong>Detalhes do agente</strong><button type="button" class="arc-icon-btn" data-detail-close aria-label="Fechar"><i class="fa fa-times"></i></button></div><div data-detail-content></div></dialog>
<dialog id="arc-headless-confirm" class="arc-manage-detail" aria-labelledby="arc-headless-confirm-title"><form method="post" action="<?= arc_h(arc_workspace_base_url()) ?>redes/servidor.php?network=<?= (int)$networkId ?>"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="agent_headless_toggle"><input type="hidden" name="agent_id" id="arc-headless-confirm-agent-id"><h3 id="arc-headless-confirm-title">Modo sem monitor</h3><p id="arc-headless-confirm-text"></p><p class="arc-security-note">Ative somente em equipamentos autorizados sem usuário presente para confirmar o acesso remoto.</p><div class="arc-modal-buttons"><button class="button secondary" type="button" id="arc-headless-confirm-cancel">Cancelar</button><button class="button" type="submit">Confirmar</button></div></form></dialog>
