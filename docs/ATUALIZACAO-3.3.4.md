<!-- Caminho: docs/ATUALIZACAO-3.3.4.md -->
# Arcoll 3.3.4 — painéis e mosaico de suporte

Suporte e Chamados usam o painel com contorno, fundo do tema e largura total disponível, sem a antiga limitação de 1440 px. As margens seguem Dispositivos: 8 px verticais e 12 px laterais no desktop, 6 px até 860 px de largura. A lista de chamados continua rolando somente dentro do painel.

Os 14 botões do Suporte ficam em um único mosaico que ocupa a área disponível: três colunas em áreas estreitas, quatro nas demais e sete em áreas panorâmicas. Os últimos cartões preenchem a última linha. Ícones acima e nomes abaixo; altura das linhas acompanha a área real do painel. Container queries respondem também a mudanças na área disponível. Feedback fica fora da grade e só ocupa espaço quando há mensagem. Modais, autorização, transferência de arquivos e comandos permanecem nos fluxos originais.

Removidos os estilos sem dependências do antigo cartão de suporte e da grade horizontal de ferramentas. Não há arquivo novo de sobreposição ou cálculo de layout em JavaScript.

Plataforma, agente e aplicativo de suporte alinhados em 3.3.4. Nenhuma alteração de banco; nenhuma query SQL necessária. VERSION não aceita comentário: seu caminho é `VERSION`.

## Instalação

Faça backup. Envie todos os arquivos não vazios do ZIP para a raiz da aplicação preservando os caminhos. Os 15 marcadores vazios correspondem a exclusões já indicadas anteriormente; exclua esses caminhos manualmente, não sobrescreva com arquivos vazios. Feche e reabra o PWA. A publicação no GitHub não instala a atualização no cPanel nem nos aplicativos Windows. Para desfazer, restaure os arquivos do backup anterior; o banco não mudou.

## Validação

235 verificações de suporte, chamados, busca, permissões e transferência bidirecional. Medição dos 14 cartões sem rolagem, ícone acima do nome, largura igual ao inventário e largura de Chamados igual ao Suporte em oito telas (320×568, 360×640, 390×844, 667×375, 844×390, 1024×768, 1440×900, 1920×1080), temas claro e escuro. Mais 39 cenários de inventário populado, mapa, planos e login; 42 testes Python; sintaxe PHP e CSS. Participante Windows simulado nas transferências. Sem capturas de tela; não testado em Windows físico, PWA em aparelho real ou cPanel.
