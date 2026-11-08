# Temas por área, círculo de verificação e histórico de atualizações

Este pacote evolui a Central de Atualização e o layout compacto já instalados. Contém **16 arquivos novos/alterados**, sem executáveis, credenciais, configuração de produção, banco SQL ou alteração de `VERSION`.

## Instalar

1. Guarde uma cópia dos arquivos substituídos, incluindo `api/download-agent.php`.
2. Extraia **ATUALIZACAO_TEMAS_HISTORICO_CPANEL.zip** na raiz do sistema, mesclando `api/` e `agentes/`.
3. Atualize/recompile o agente pelo fluxo já utilizado. Se usa releases pré-compiladas, gere e publique uma nova release com os fontes e recursos deste pacote. Upload de fontes no cPanel não altera o executável que já está aberto.
4. Reabra o agente. Use o **ícone de atualização na ponta da barra superior**. A área tem dois submenus: **Atualização** e **Histórico**.

A API atualizada deve acompanhar os fontes do agente: ela permite consultar a publicação sem iniciar a instalação. As chamadas de download já usadas pelas versões anteriores continuam retornando ZIP quando não é solicitada a consulta de metadados.

Preserve a versão atual da plataforma. A identificação 5.0.116 usada na compilação de compatibilidade acompanha o último print; não é um novo VERSION incluído no pacote.

## Cores conforme a numeração do print

| Área | Comportamento |
|---|---|
| 1 — título da janela | Acompanha o modo de aplicativos do Windows do computador. |
| 2 — categorias superiores | Usa a cor de destaque do tema escolhido em Admin/Configurações. |
| 3 — faixa de submenus | Superfície derivada do mesmo tema, com leve influência da cor de destaque. |
| 4 e 9 — barra de filtros e rodapé do Painel | Exatamente a mesma cor. |
| 5 — conteúdo | Superfície do tema da plataforma. |
| 6 e 8 — topo e base do histórico lateral | Exatamente a mesma cor, mais escura que a área 3. |
| 7 — conteúdo do histórico lateral | Exatamente a mesma cor da área 5. |
| 10 — rodapé do agente | Cor derivada da área 2, mais escura, com texto de contraste ajustado. |

As áreas 2–10 usam o tema e modo já publicados pela plataforma: Windows, Ocean, Crystal, Graphite, Neon e Emerald, incluindo a regra existente de claro/escuro/automático. O agente conectado atualiza o tema pela consulta periódica já existente, aproximadamente a cada 30 segundos. Em modo automático, a luminosidade segue a configuração prevista no tema administrativo.

A barra de título é tratada separadamente para respeitar o Windows, conforme a área 1. Não há mudança da configuração global do Windows nem escrita nas preferências do sistema. A logo e o nome no rodapé continuam esmaecendo quando a autorização/conexão não é confirmada.

As cores são aplicadas às páginas locais do agente. Este pacote não altera o CSS das páginas web da plataforma.

## Atualização pelo círculo

- O menu principal **Atualização** foi retirado da faixa de categorias. O acesso fica no ícone já existente à direita.
- A tela principal não tem o cabeçalho anterior, versão instalada, bloco de atividade ou cartões de etapas abaixo.
- **Clique no círculo para verificar.** A consulta autentica o agente, compara o inventário/manifesto da release binária ou o hash do pacote de fontes e informa a disponibilidade. Não inicia instalador, compilação ou troca de arquivos.
- Depois da consulta, aparecem **Atualizar** e **Reinstalar arquivos**, lado a lado. Se não houver novidade, Atualizar fica desabilitado; Reinstalar continua disponível de forma explícita.
- Reinstalar mantém a confirmação nativa já existente. Antes da instalação, o pacote é novamente baixado e validado: a consulta não substitui a verificação de integridade.
- Durante a atualização, o percentual fica no centro e as etapas aparecem em pequenas marcações ao redor do círculo. A mensagem atual aparece compacta junto ao círculo, com texto completo ao passar o ponteiro.
- O progresso continua sendo por etapas. Compilação sem percentual confiável não ganha uma porcentagem inventada pelo tempo. O processo só informa conclusão da instalação com o recibo da mesma operação.
- A breve reinicialização para liberar o executável e o aplicador oculto da entrega anterior permanecem.

Quando a última operação acabou de ser confirmada, o círculo pode mostrar 100%; continua clicável para fazer uma nova consulta. Uma verificação sem instalação não cria um registro de atualização feita.

## Histórico

A área é local ao perfil Windows que executa o agente e mantém os **últimos 1.000 registros**. Não envia esse histórico para uma nova tabela na plataforma.

Uma barra única reúne busca e filtros: Todas, Concluídas, Falhas e Canceladas. Abaixo fica a tabela com data/hora, versão anterior, versão publicada, modo, resultado, duração e descrição. Os cabeçalhos são clicáveis para alternar a ordenação. Clique numa linha, ou use Enter/Espaço, para abrir o modal com os detalhes e as etapas registradas.

Somente o conteúdo da grade rola verticalmente; a barra superior e os títulos das colunas permanecem visíveis. Em larguras pequenas, as áreas internas permitem rolagem horizontal sem ampliar a janela.

O histórico fica em `%LOCALAPPDATA%\TI-Digital\update-history.json`, fora da pasta do executável substituída pelo atualizador. O último estado terminal disponível da central anterior é importado sem duplicar a mesma operação. Versões ausentes em recibos antigos aparecem como não informadas. Não são fabricadas atualizações antigas para as quais não existe um registro confiável.

Novas operações guardam origem/destino, modo, horários, resultado e mensagens. Progresso e reinicialização em andamento não viram atualização concluída. Recibos repetidos são idempotentes. Arquivo ilegível é informado e preservado antes de recuperar a gravação. O modal descreve a operação registrada; não inventa um changelog de alterações de código do produto.

## Validação e base

Consulte **VALIDACAO_TEMAS_HISTORICO.md** e o workflow do GitHub. As imagens usam dados de exemplo; as prévias com a janela inteira são composições em navegador, não capturas de uma instalação Windows em produção.

O snapshot parte dos fontes completos disponíveis nas entregas anteriores, com os ajustes deste pacote. O código completo da sua instalação de produção 5.0.116 não foi enviado; diferenças adicionais feitas fora dessas entregas devem ser comparadas nos arquivos comuns. Não foi acessada ou atualizada sua produção durante os testes.

O modo real da barra de título do Windows, a propagação do tema administrativo no computador e a atualização ponta a ponta devem ser conferidos em uma máquina de teste antes da distribuição ampla.

**Não extraia `VALIDACAO_TEMAS_HISTORICO_WINDOWS.zip` no cPanel.** É somente o snapshot de compilação e testes. Use o ZIP de atualização de produção indicado no início.

## Reverter

Restaure os arquivos guardados e gere/republique a release anterior do agente. Preserve `update-history.json` e os recibos locais. Não há migração SQL para desfazer.
