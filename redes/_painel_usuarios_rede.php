<?php declare(strict_types=1); ?>
<section class="card">
 <div class="card-header"><h2><i class="fa fa-users"></i> Usuários da equipe</h2><span class="text-muted">Rede: <?= arc_h((string)$network['name']) ?></span></div>
 <p>Vincule contas já cadastradas na plataforma à equipe desta rede. O proprietário da rede aparece automaticamente como administrador e não pode ser removido da equipe.</p>
 <p><strong>Aviso:</strong> esta etapa registra os integrantes e os perfis, mas não libera controle remoto ou acesso sem confirmação. A validação das permissões no login, nos comandos e no agente depende de integração adicional.</p>
 <form method="post" class="form-field">
  <input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
  <input type="hidden" name="action" value="team_save">
  <label>E-mail da conta na plataforma<input name="member_email" type="email" maxlength="254" autocomplete="off" required placeholder="usuario@empresa.com"></label>
  <label>Perfil da equipe<select name="member_role" required><option value="viewer">Visualizador — consulta</option><option value="operator">Operador — acesso assistido</option><option value="administrator">Administrador — cadastro administrativo</option></select></label>
  <div><button type="submit" class="button primary"><i class="fa fa-user-plus"></i> Adicionar à equipe</button></div>
 </form>
</section>
<section class="card"><div class="card-header"><h2>Integrantes cadastrados</h2><span class="arc-manager-tab-count"><?= count($networkTeamMembers) + 1 ?></span></div>
<div class="table-wrap"><table class="arc-responsive-table data-table"><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil cadastrado</th><th>Ações</th></tr></thead><tbody>
<tr><td data-label="Nome"><?= arc_h((string)$networkOwner['display_name']) ?> <strong>(Proprietário)</strong></td><td data-label="E-mail"><?= arc_h((string)$networkOwner['email']) ?></td><td data-label="Perfil cadastrado"><strong>Administrador</strong></td><td data-label="Ações">Permissão permanente · não removível</td></tr>
<?php foreach ($networkTeamMembers as $member): ?><tr>
<td data-label="Nome"><?= arc_h((string)$member['display_name']) ?></td><td data-label="E-mail"><?= arc_h((string)$member['email']) ?></td>
<td data-label="Perfil cadastrado"><?= arc_h(['viewer'=>'Visualizador','operator'=>'Operador','administrator'=>'Administrador'][(string)$member['role']] ?? 'Pendente') ?></td>
<td data-label="Ações"><form method="post" data-confirm="Remover este usuário da equipe?" style="display:inline"><input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>"><input type="hidden" name="action" value="team_remove"><input type="hidden" name="member_user_id" value="<?= (int)$member['member_user_id'] ?>"><button class="button danger" type="submit">Remover</button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></section>
