<!-- Caminho: docs/ATUALIZACAO-3.4.0.md -->
# Arcoll 3.4.0 — suporte simplificado e agendamentos

O mosaico contém sete opções, com ícone em cima e nome embaixo: Acesso remoto web, Acesso remoto Windows, Baixar aplicativo de suporte, Enviar e receber arquivos, Verificar reinicialização, Desligar computador e Agendar serviços. A verificação de reinicialização apenas consulta o pedido do Windows; reinício imediato, ajuda e as demais verificações solicitadas foram removidos deste painel, incluindo seus textos e controlador sem uso. As operações internas continuam disponíveis ao agente e ao agendador quando necessárias.

## Agendar serviços

O gestor escolhe um serviço predefinido: atualizar dispositivos, verificar conexão/discos/serviços/recursos/reinicialização, reiniciar ou desligar. Não aceita scripts, programas ou comandos digitados. Pode executar uma vez, nos dias selecionados da semana, ou a cada intervalo de 15 minutos até 7 dias, com início futuro e fim opcional. Limite de 50 agendamentos ativos por conta. Cada período informado tem até um ano; repetição sem fim pode ser cancelada pelo gestor.

Horários usam America/Belem (UTC−3), como o restante do sistema. A execução acontece no poll autenticado do agente, sem cron, outro programa ou agendador paralelo no Windows. Requer agente 3.4.0 ou superior. O computador deve estar ligado, conectado, aprovado e com a rede ativa/monitoramento habilitado. Serviços não ligam computadores desligados. Uma ocorrência entregue até três minutos após o horário pode entrar na fila; atrasos maiores são registrados como não executados. Não há execução acumulada de horários perdidos. Computador ocupado gera registro de não execução daquela ocorrência. A frequência é ancorada no horário inicial, sem desvio acumulado.

Ações de energia exigem senha/confirmacão do gestor ao agendar e mantêm a confirmação local, exceto o modo sem monitor previamente habilitado. O Windows recebe a ação com 60 segundos para salvar trabalhos, sem fechamento forçado. A autorização é revalidada antes da ação. Cancelamento preserva o histórico, impede novas execuções e revoga solicitações pendentes/em execução antes de sua autorização; uma ação já aceita/iniciada no Windows não é desfeita. Operadores, administradores convidados e visitantes não podem gerenciar os agendamentos.

Histórico distingue na fila, em execução, concluído, falha, horário perdido e computador ocupado. Conclusão de energia significa que o Windows aceitou a solicitação, não que o computador desligou fisicamente. Dados e identidade das instalações existentes são preservados.

## Banco e instalação

Migração aditiva e reaplicável `database/migrations/3.4.0.sql` cria somente support_schedules e support_schedule_runs, sem alterar/apagar dados existentes. A aplicação aplica pelo fluxo original de atualização de esquema. Se o usuário do banco não puder criar tabelas, executar o arquivo pelo administrador no banco correto, adaptando arc_ ao prefixo configurado. Fazer backup antes de atualizar. Não importar schema.sql em produção para aplicar esta atualização; ele é o esquema canônico de instalações novas.

ZIP incremental desde 3.3.4: enviar somente seus arquivos não vazios, preservando pastas. Os 15 marcadores vazios de caminhos obsoletos já identificados são para exclusão manual, nunca para sobrescrever. Configuração, credenciais, banco de produção e uploads não incluídos. Plataforma e componentes Windows alinhados em 3.4.0; atualizar o agente pela ação existente após enviar o pacote ao cPanel. VERSION, na raiz, não permite comentário.

Para desfazer: cancelar os agendamentos, restaurar os arquivos do backup 3.3.4 e manter as duas tabelas novas para preservar histórico. Não excluir tabelas. Publicação no GitHub não instala no cPanel nem nos computadores.

## Validação

267 verificações de suporte/chamados/arquivos e mosaico em oito tamanhos de tela, claro/escuro; 22 verificações de recorrência, isolamento, horário perdido, não duplicação, autenticação pelo poll, revogação de energia e migração reaplicada; 25 verificações HTTP/interface/permissões do agendador; 33 verificações de atualização remota; 232 combinações de páginas/temas/telas; 42 testes Python e sintaxe PHP/CSS. Fluxos Windows e transferência de arquivos simulados; sem capturas de tela. Sem execução física de desligamento/reinício, Windows real, aparelho PWA real ou hospedagem cPanel.
