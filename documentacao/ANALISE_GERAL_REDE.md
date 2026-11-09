# Análise geral configurável e identificação de dispositivos

## Instalar

Extraia `pacotes/ANALISE_GERAL_REDE_EXTRAIR_NA_RAIZ.zip` na raiz do sistema no cPanel, sobrescrevendo os caminhos correspondentes. Em seguida, atualize o gestor Windows pelo ícone de atualização. O pacote é incremental sobre a última entrega PENTE_FINO_CENTRAL e preserva VERSION, MINIMUM_RELEASE_VERSION, banco de dados e configurações dos clientes. Admin/Versão continua determinando automaticamente a versão publicada.

## Carregamento e Análise geral

O círculo global usa geometria nativa com cores do tema, sem depender da cor de destaque do Windows para pintar o arco. O botão e seu conteúdo têm alinhamento central explícito. Esse carregamento global não cobre mais o Pente-Fino nem suas ferramentas internas: cada ferramenta apresenta sua própria coleta. O círculo próprio da Análise geral continua disponível para iniciar e acompanhar a análise.

A engrenagem abre um modal com navegação lateral e estas quinze seções: Windows, Sistema, Programas, Windows Store, Utilitários, Multimídias, Internet, Downloads, Ferramentas, Avançado, Navegadores, Registros, Inicialização, Período e Outros. Não há campos para digitação. O catálogo das opções de limpeza e de registro vem da mesma fonte já usada pela plataforma e pelo gestor.

As verificações de limpeza, registro e inicialização foram incorporadas à Análise geral e deixam de aparecer como entradas duplicadas na lateral. Motores internos continuam modulares para preservar compatibilidade e ações especializadas, inclusive a revisão individual de inicialização. Inventariar serviços ou programas não significa considerá-los falhas, nem autoriza desativar todos automaticamente.

As preferências salvas controlam o motor efetivamente executado. A limpeza usa o catálogo existente; o registro usa somente as verificações marcadas; a inicialização respeita os tipos selecionados; softwares são consultados pelo WinGet e o Windows Update pela API oficial. Analisar não instala atualizações nem altera a inicialização. Os resultados apresentam quantidades, com detalhamento quantitativo por categoria, sem despejar a lista completa de arquivos na interface.

Períodos: todo período, últimos 7, 15, 30, 180 e 365 dias. O filtro considera a data de modificação dos arquivos dentro da janela escolhida; não é o antigo filtro de arquivos *mais velhos* que o prazo. Registro, inventários e ações sem data representam o estado atual, como indicado no modal. Exclusões e pastas personalizadas da plataforma são sincronizadas antes da análise e revalidadas antes da correção. Itens destrutivos, como senhas de navegadores, permanecem desmarcados por padrão conforme o catálogo existente.

A estimativa de conclusão é aproximada e usa a duração disponível da análise anterior ou uma estimativa inicial. Tempo estimado não altera a porcentagem: o progresso continua dependente das etapas efetivamente concluídas. Consultas limitadas ou inacessíveis são apresentadas como parciais. Se uma correção externa for interrompida, a interface informa quando as quantidades finais não puderem ser confirmadas.

Novas correções continuam sem cópias de recuperação, conforme a solicitação anterior. A opção de ponto de restauração do Windows continua na confirmação. Os arquivos de recuperação legados não são apagados por esta atualização.

## Dispositivos, Painel e Mapa

A identificação complementar usa o inventário compartilhado pelas três páginas. Consultas DNS e NetBIOS coletam nomes observados; a consulta NetBIOS nativa deixa de depender da palavra inglesa UNIQUE na saída de nbtstat. Sondagens limitadas às portas de serviço já suportadas coletam evidências na própria sub-rede, com cache, limite de concorrência e cancelamento. Não há login, execução de comandos nos dispositivos ou alteração de suas configurações.

Os provedores habilitados em Admin/Configurações/IA são usados no servidor, com o failover existente. Chaves nunca são enviadas ao gestor. São enviados lotes pequenos de evidências: nomes observados, fabricante quando disponível, prefixo OUI do MAC e portas identificadas. IP privado e MAC completo não são enviados ao modelo. MAC local/aleatório é explicitamente indicado para não atribuir fabricante a partir de um prefixo sem validade.

A resposta do modelo passa por validação de formato, tipos permitidos e confiança mínima. Um nome só é aceito se corresponder exatamente a um nome já observado nas evidências; a IA não inventa hostname. Nomes e tipos personalizados mantêm prioridade, e identificações confirmadas não são sobrescritas. Equipamentos sem evidências suficientes permanecem sem classificação complementar. Inferências de tipo não equivalem a uma confirmação física do modelo do aparelho.

O cache e a fila são isolados por conta, rede e MAC. A tabela auxiliar é criada automaticamente antes da transação do inventário. A classificação acontece depois de liberar o JSON da coleta, usando PHP-FPM, LiteSpeed ou a saída JSON com comprimento definido nas demais hospedagens. Há ainda o complemento CLI `php /CAMINHO/DO/SISTEMA/admin/network-identification-cron.php` para manutenção de filas. Não existe um endpoint público de execução desse complemento.

Erros do provedor recebem intervalo de repetição; não interrompem a coleta do inventário. Não há telas ou indicadores de IA na navegação operacional. O resultado aparece nas leituras seguintes do inventário compartilhado.

## Validação

- Compilação WinUI x64: zero erros e zero avisos.
- 30 verificações .NET de resumo, quantidades, seleção, cancelamento, correção sem cópia e contagem parcial.
- 119 verificações de ciclo de coleta e contraste dos temas.
- 47 verificações Chromium de interface, quinze seções, ausência de campos de texto, salvamento, período, seleção, progresso e diálogos.
- 6 verificações adicionais de coleta/repetição no Painel e Backup.
- 6 testes Python de limpeza/registro/inicialização, incluindo preservação de arquivos fora do período.
- 3 testes de nomes NetBIOS: resposta válida, transação incorreta e bloqueio de consulta a IP público.
- 8 verificações PHP de evidências, nomes inventados, tipos desconhecidos e baixa confiança.

Fluxo Windows e navegador: https://github.com/marcosliraxx/GesTI/actions/runs/42667078828

Os testes usam dados controlados. A identificação com os provedores e os dispositivos reais depende das credenciais já configuradas e das respostas disponíveis na rede; nenhuma correção foi executada em arquivos dos clientes durante esta preparação.
