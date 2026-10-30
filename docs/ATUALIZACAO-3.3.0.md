<!-- Caminho: docs/ATUALIZACAO-3.3.0.md -->
# Arcoll 3.3.0 — mapa, suporte e arquivos

Aplicar sobre **3.2.2**. Plataforma, cobrança, agente e cliente de suporte Windows ficam alinhados em 3.3.0. O ZIP contém somente arquivos alterados/novos e os 11 marcadores de exclusão manual. Publicar no GitHub não atualiza o cPanel nem os agentes instalados.

## Instalação

1. Faça backup dos arquivos e do banco.
2. Envie todos os arquivos não vazios do ZIP para as pastas originais. Preserve configuração e uploads. Não publique este LEIA-ME no diretório público.
3. A migração aditiva `database/migrations/3.3.0.sql` cria a tabela das transferências e é aplicada automaticamente pelo fluxo existente. Não apaga tabelas ou registros. Para aplicação manual, ajuste `arc_` ao prefixo configurado. A migração pode ser reaplicada.
4. Atualize o agente pelo botão de atualização remota e confirme **3.3.0** antes de usar a transferência de arquivos. O instalador e a atualização autenticada usam os componentes alinhados; não envie apenas as páginas PHP.
5. Reabra o PWA para carregar os estilos novos. Se necessário, peça à hospedagem para atualizar o cache PHP depois da substituição completa dos arquivos.

Os arquivos de **zero bytes** indicam exclusão manual dos arquivos antigos listados ao final: não envie os marcadores vazios para sobrescrever arquivos no servidor. `VERSION`, na raiz, não aceita comentário de caminho.

## Mapa

- O ajuste inicial usa **a largura disponível**, em vez de reduzir todo o desenho até caber na altura. Redes grandes continuam legíveis e podem rolar dentro da área do mapa, mantendo o restante da tela encaixado.
- O botão de largura recalcula o zoom de verdade. A visão geral continua disponível para mostrar todo o desenho; aproximar, afastar, tela inteira, impressão e posições salvas foram preservados.
- No celular, deslizar sobre os equipamentos permite rolar. Para mover equipamentos pelo toque, ative **Mover equipamentos no celular** nas opções. A restauração da organização está nas opções e mantém sua confirmação antes de limpar as posições salvas.
- O ajuste acompanha o tamanho da área do mapa; uma escolha manual de zoom é preservada enquanto o usuário a utiliza.

## Suporte compacto

- Removidos o título e a descrição grandes do corpo da página, os cartões de indicadores sempre abertos e o bloco grande de acesso remoto.
- Barra de ações por ícones: novo chamado, navegador, aplicativo Windows, instalação, equipe e ajuda/indicadores. Os dois acessos agora têm ícones distintos.
- Busca e situação ficam acima da fila; a lista rola dentro do painel. Abertura de chamado e detalhes/edição/histórico ficam em modais.
- Criação, atribuição, prioridade, prazo, anotações, tempo trabalhado, resolução, histórico, paginação e permissões foram mantidos. O cabeçalho global e o menu da rede permanecem disponíveis.

## Ferramentas → Arquivos

Escolha o computador no topo e abra **Arquivos**:

- **Enviar:** selecione um arquivo no navegador e confirme o envio. No Windows, a pessoa autoriza e escolhe onde salvar. O agente confere tamanho e SHA-256 antes de substituir atomicamente o destino escolhido. O conteúdo não é executado.
- **Receber:** solicite um arquivo. A pessoa no Windows autoriza, escolhe o arquivo e o agente o envia; aparece o botão para baixar na plataforma. Não é necessário digitar caminho ou comando.

A transferência é assistida, inclusive em servidores configurados sem monitor: é necessária uma sessão de desktop para autorizar e selecionar o arquivo/destino. Ela pode ser usada junto do acesso remoto autorizado existente. Não foi implementado navegador irrestrito do disco nem coleta de arquivos sem consentimento.

Limites e integração:

- Até **8 MiB por arquivo**. O envio pelo navegador respeita também o limite menor configurado no PHP da hospedagem, mostrado na janela. Quota temporária de 64 MiB/50 registros por conta em 24 horas; uma transferência ativa por computador.
- Arquivos expiram após 24 horas. O acesso é negado após a expiração; a limpeza dos bytes ocorre no uso da área e no cron existente `maintenance/billing-renewals.php`. O histórico de metadados permanece. Sem tráfego nem cron, os bytes podem permanecer até a próxima limpeza, mas não podem ser baixados após expirar.
- Arquivos ficam no armazenamento privado existente, sob `includes/storage/transfers`, com nomes aleatórios e acesso pelo endpoint autenticado. A hospedagem deve respeitar a proteção original de `includes/.htaccess`/`includes/storage/.htaccess`, e o PHP precisa poder criar a pasta privada.
- Gestores e operadores autorizados podem solicitar. Visualizadores não podem. O download na plataforma é restrito a quem pediu o arquivo, com permissões revalidadas. Bloqueio do agente, rede inativa, assinatura sem acesso e remoção da equipe impedem continuar acessando a transferência.
- A fila original do agente, a autenticação da instalação e o endpoint original de suporte são reutilizados. Os dados binários não passam por campos de telemetria, não entram no banco e não são incluídos no Git/ZIP. O transporte do agente exige HTTPS verificado e recusa redirecionamentos com suas credenciais.
- A atualização do agente não inicia durante uma transferência em andamento. Solicite novamente após concluir, evitando reiniciar o processo enquanto ele salva um arquivo.

## Validação

- 232 combinações de páginas, temas e tamanhos de tela sem falhas.
- 39 cenários de inventário, mapa populado, enquadramento pela largura/visão geral, planos, login e modais preservados.
- 45 verificações de suporte compacto e arquivos: formulários de abertura/resolução e histórico, modais em celular/paisagem/desktop, envio e recebimento binários completos via HTTP, recusa local simulada, CSRF, credenciais, hash, nome de arquivo, reuso indevido, isolamento de contas e bloqueio de visualizadores.
- 12 verificações de armazenamento: permissões, nomes reservados/travessia de pastas, limite, revogação da equipe, expiração, limpeza dos bytes com preservação de metadados e reaplicação da migração.
- 45 verificações dos serviços existentes, incluindo cobrança sem cancelamento obrigatório e permissões de suporte.
- 39 testes Python, incluindo oito novos cenários de transporte HTTPS/redirecionamentos/limites e fluxo do agente com arquivos temporários reais: salvar no destino escolhido, preservar arquivo diante de hash incorreto, enviar somente o arquivo selecionado e não transferir após recusa.
- Sintaxe PHP/JavaScript/Python, CSS e integridade do ZIP conferidas. Não foram produzidas imagens de apresentação ou capturas de tela.

Os testes usaram dados fictícios e navegador Chromium. O participante Windows foi simulado nas chamadas HTTP; os testes Python exercitaram o método original com seletores de arquivos simulados e gravação real em pasta temporária. Não foram testados instalação física/transferência ponta a ponta em Windows real, iPhone/Android físicos, pagamentos bancários ou o servidor do usuário.

## Desfazer

Restaure os arquivos do backup mantendo versões alinhadas. A tabela aditiva pode permanecer; não é necessário apagar dados. Preserve os metadados e os arquivos privados enquanto houver necessidade de consulta, respeitando sua expiração. O fluxo de atualização do agente mantém o backup local dos arquivos de código alterados e preserva a identidade da instalação.
