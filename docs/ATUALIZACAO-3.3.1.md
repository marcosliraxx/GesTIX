<!-- Caminho: docs/ATUALIZACAO-3.3.1.md -->
# Arcoll 3.3.1 — conferência da atualização e pacote consolidado

Este ZIP reúne todas as alterações desde **3.1.0**, incluindo o cartão **Arquivos**, os ajustes do mapa/suporte e as correções de cobrança. Plataforma, página Ferramentas, cobrança e todos os componentes versionados Windows ficam em 3.3.1.

## Instalação no cPanel

1. Faça backup dos arquivos e do banco.
2. Use o arquivo **Arcoll-3.3.1-atualizacao.zip** fornecido na pasta `pacotes` do GitHub. O ZIP completo do repositório obtido em “Code → Download ZIP” tem outra estrutura e não deve ser extraído como uma pasta adicional dentro do site.
3. Extraia e substitua **todos os arquivos não vazios**, mantendo as pastas originais. As pastas `agent`, `api`, `assets`, `includes`, `pages` e `redes` precisam ficar diretamente na raiz de `app.digital.log.br`, ao lado do `index.php` já usado pelo site. Não deixe a atualização somente dentro de `GesTI-ArcMod`, `#GesTI`, `pacotes` ou outra subpasta. Não exclua essas pastas antigas sem verificar o conteúdo.
4. Preserve sua configuração, uploads e armazenamento privado. **Não altere números de versão manualmente**: os componentes precisam ser os arquivos completos do pacote.
5. Caso a hospedagem mantenha PHP antigo em cache, solicite a ela atualizar o cache PHP/OPcache após a substituição. Limpar somente o cache do PWA não reinicia o PHP no servidor.
6. No PWA, selecione a rede e abra **Ferramentas**. O primeiro cartão é **Arquivos**. Em **Como funciona** (?), confira “Versão desta página: 3.3.1” e a conferência do pacote publicado.
7. Toque em atualizar o agente. A confirmação mostra a versão da plataforma e de cinco componentes do pacote Windows **no servidor**. Se faltar um arquivo ou houver versões diferentes, conclua o envio do pacote antes de tentar novamente. A versão antiga instalada no computador não é confundida com o pacote no servidor.

Não há nova migração nesta correção. As migrações aditivas 3.2.0 e 3.3.0 estão incluídas para instalações anteriores e seguem o fluxo automático existente. Não execute SQL avulso para tentar resolver uma atualização incompleta de arquivos.

Os 11 arquivos com **zero bytes** indicam exclusão manual de implementações antigas, listadas ao final. Não envie os marcadores vazios para sobrescrever arquivos no servidor. Não publique este LEIA-ME na pasta pública. `VERSION`, na raiz, não permite comentário de caminho.

## O que foi corrigido

A mensagem “Revise a conexão com o banco” era usada para qualquer exceção da API, incluindo a recusa de um pacote Windows com arquivos de versões diferentes. Portanto, aquela mensagem não demonstrava que o banco estava indisponível.

- As validações de atualização agora respondem com a causa específica quando o pacote no servidor está incompleto ou desalinhado. A validação continua obrigatória; não foi liberada a instalação de um pacote misturado.
- A conferência inclui aplicativo, inicializador, serviço, atualizador e ferramentas internas do agente. O módulo de ferramentas internas também passou a ter versão explícita, incluída no comando de versão e no teste de release.
- A confirmação existente mostra as versões antes de enfileirar a atualização e impede o envio de pacote sabidamente incompleto. O modal usa os estilos padrão, cabe no celular e permite cancelar por botão, Escape ou fechamento sem deixar a próxima tentativa travada.
- A página Ferramentas tem versão própria, também controlada pelo processo de release, e apresenta a conferência em sua ajuda. Não são injetados botões sobre uma página antiga: o pacote entrega a implementação original completa, com o cartão Arquivos.
- Falhas inesperadas recebem mensagem neutra e uma referência curta para suporte. Os detalhes técnicos ficam somente no `error_log`, vinculados à referência; SQL e caminhos privados não são mostrados na notificação.
- A consulta de versões reaproveita a leitura dos componentes em vez de ler o aplicativo do agente duas vezes.

O envio/recebimento de arquivos continua exigindo agente 3.3.0 ou superior e confirmação na sessão Windows, com limites e retenção descritos em `docs/ATUALIZACAO-3.3.0.md` no GitHub. Um agente antigo, sem comunicação ou sem atualizador funcional não pode ser recuperado apenas mudando arquivos da plataforma.

## Validação

- 33 cenários com pacote alinhado: cartão e modal Arquivos disponíveis, versões coerentes, confirmação e cancelamento repetido, em 320×568, 390×844, 844×390 e 1440×900.
- 37 cenários com aplicativo do agente 2.8.1 deliberadamente colocado no pacote de teste: discrepância detectada, envio bloqueado e resposta específica sem acusar conexão com o banco.
- 3 verificações de falha real de gravação simulada no banco isolado: mensagem sem SQL, referência gerada e erro registrado com contexto no log. O mecanismo de simulação foi removido ao concluir.
- 47 verificações existentes de checkout, QR, relatórios, atualização com protocolo antigo e permissões passaram.
- 39 testes Python, incluindo alinhamento das versões, agente, cliente Windows, integridade e arquivos.
- Sintaxe PHP/JavaScript/Python, CSS e integridade do ZIP verificadas.

Não houve acesso ao cPanel, arquivos executados, banco ou cache PHP do usuário. A captura demonstra uma página sem o cartão publicado, mas não confirma se o motivo é extração em outra pasta, substituição parcial, cache PHP ou outra diferença local. Esta versão identifica incompatibilidades e corrige o diagnóstico incorreto; não comprova por si só que o agente específico foi atualizado. Instalação física Windows, aparelhos móveis reais e pagamentos bancários não foram testados.

## Desfazer

Restaure os arquivos do backup mantendo uma mesma versão dos componentes. As tabelas/colunas aditivas podem permanecer. Preserve cobranças, dados de suporte e transferências; não restaure um banco antigo sobre transações recentes sem conciliação.
