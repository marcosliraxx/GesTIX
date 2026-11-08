# Validação — agente 5.0.118

**Aprovada:** [execução 42447564318](https://github.com/marcosliraxx/GesTI/actions/runs/42447564318), sobre o código `e006add79169a216479365ad8ff8eac6d0073d85`. Os arquivos de produção e o ZIP permanecem idênticos aos dessa execução; a atualização posterior acrescenta este relatório e os testes de layout ABI.

| Verificação | Resultado |
|---|---|
| Compilação WinUI 3 / .NET 8 / Windows 2022 / x64 | 0 erros, 0 avisos do compilador |
| Regras de telemetria, identidade, taxas, intervalos e unidades | 32 verificações aprovadas |
| Coleta real em Windows, sem instalação ou varredura de rede | 10 verificações aprovadas |
| Estados e recibos da atualização | 24 verificações aprovadas |
| Paletas e histórico persistente | 52 verificações aprovadas |
| Workspace e componentes no navegador | 60 verificações aprovadas |
| Central de atualização, histórico e seis paletas | 58 verificações aprovadas |
| API PHP 8.2: autenticação, consulta, hashes e downloads | 16 verificações aprovadas |
| Layout das estruturas WDDM no CLR x64, sem chamar drivers | 4 verificações locais aprovadas |

O Windows de teste enumerou 1 componente Internet, 1 CPU, 1 memória, 2 discos físicos, 1 GPU e 2 interfaces. O teste verificou identidades únicas, timestamps, valores finitos/indisponíveis e reinicialização dos contadores após pausa. Não foram registrados endereços, números de série ou identidade da máquina.

Os testes de navegador cobrem recolhimento e abertura de Atividades, armazenamento local indisponível, barra única, ausência de rolagem externa, busca, componentes distintos, volumes agrupados, GPU integrada/dedicada, seleção de séries, envio dos intervalos ao agente, contexto do rodapé, reconstrução do Backup, grade/modal/ordenação/busca do histórico e seis paletas. Usam estados sintéticos identificados; não executam instaladores, correções, backups ou restaurações.

A API confirmou também que uma release binária anterior ao mínimo nativo retorna o caminho de fallback, e que o pacote fonte autenticado continua disponível. O ZIP final foi aberto, comparado byte a byte com os arquivos entregues e conferido por SHA-256:

```text
1a4812c4b5ac6dc97ae7b4227e36500ea42af7ad6aafd667fab421bf7d5468e7
```

São 25 arquivos, 65.899 bytes, com caminhos diretos para a raiz do site. Não contém `agent-config.json` nem bancos ou executáveis de clientes.

## Reproduzir

Extraia `VALIDACAO_WORKSPACE_WINDOWS.zip` em uma pasta de testes. O workflow `.github/workflows/validar-workspace-servidor.yml` registra os comandos de compilação, testes .NET e fixture PHP. Os scripts em `BrowserTests` usam Playwright e Chrome local; `CHROMIUM_EXECUTABLE` seleciona o navegador. São necessárias as dependências de `package.json`.

O teste local de ABI está em `validacao-abi` e pode ser executado com `dotnet run --project validacao-abi/Abi.csproj -c Release`, a partir desta entrega. Confere tamanho e offsets das estruturas contra o SDK público da Microsoft, sem chamadas de GPU. A conversão da temperatura de décimos de Celsius é verificada nas regras de telemetria.

## Limites da validação

A interface WebView foi exercitada no Chromium; o executável WinUI foi compilado e o coletor foi executado em Windows. Não houve interação com a janela completa do agente em um computador de cliente, nem validação dos modelos específicos de disco/GPU dos prints. Temperatura, alguns dados de memória e metadados dependem do driver/provedor e podem aparecer como **Indisponível**. A primeira conferência no equipamento real está descrita no arquivo de instalação.
