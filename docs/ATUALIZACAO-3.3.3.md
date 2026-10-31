<!-- Caminho: docs/ATUALIZACAO-3.3.3.md -->
# Arcoll 3.3.3

Suporte passa a concentrar os botões de acesso pelo navegador, aplicativo Windows, instalação, arquivos, seis verificações e três ações de energia. Não exibe equipe de atendimento, busca, lista de chamados ou formulário de novo chamado. Ajuda e detalhes permanecem em modais.

A nova aba Chamados reúne busca, situação, lista paginada e botão Novo chamado. Os formulários, responsável, prazo, registros de tempo, solução e histórico foram movidos da implementação original. Não há cópia paralela do fluxo. O painel agregado de atendimentos aponta para Chamados. As permissões e os dados existentes permanecem.

Ferramentas foi removida da navegação e do código. Excluir manualmente `redes/ferramentas.php`; o ZIP inclui esse caminho vazio como marcador, junto aos 14 caminhos obsoletos já identificados nas entregas anteriores. Não sobrescrever arquivos com os marcadores vazios.

Plataforma, agente e aplicativo de suporte alinhados em 3.3.3. O diagnóstico de versão consulta agora Suporte. Nenhuma alteração de banco; não há SQL para executar.

## Instalação e retorno

Faça backup dos arquivos atuais. Envie todos os arquivos não vazios do ZIP para a raiz da aplicação, preservando as pastas. Exclua manualmente os caminhos indicados no LEIA-ME. Feche e reabra o PWA. Publicação no GitHub não instala a atualização no cPanel ou nos agentes. Para desfazer, restaure os arquivos do backup, inclusive Ferramentas; o banco não foi alterado.

VERSION é texto sem suporte a comentários; seu caminho é `VERSION`.

## Validação

Sintaxe PHP e CSS; 42 testes Python; 232 combinações de páginas, temas e telas; 39 cenários de inventário, mapa e modais; 67 verificações de chamados e arquivos; fluxos de chamados, busca e transferência bidirecional com participantes simulados; quatro tamanhos de tela; cenários de inventário, mapa e modais. Testes em navegador local e banco fictício, sem capturas de tela. Windows físico, PWA em aparelho real e hospedagem cPanel não foram executados.
