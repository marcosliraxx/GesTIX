# Validação — Central de atualização integrada

[Execução final aprovada no Windows](https://github.com/marcosliraxx/GesTI/actions/runs/42439527522).

| Verificação | Resultado |
|---|---|
| Estados, recibos e restauração de sessão em .NET 8 / Windows | 24 asserções aprovadas |
| Protocolo de troca com processos simulados em PowerShell | 11 asserções aprovadas |
| Agente completo WinUI 3 x64 | Build aprovado: 0 avisos, 0 erros |
| Parser dos scripts de instalação, aplicação e compatibilidade | Aprovado |
| Interface em Chromium/Playwright com a política CSP real | 27 asserções aprovadas |
| ZIP de produção | CRC aprovado; 13 arquivos |

Os testes de estados cobrem progresso monotônico, concorrência de notificações, cancelamento antes da troca, limite de 99% sem confirmação, restauração após reinício, rejeição de recibo de outra operação, leitura de JSON parcial, timeout, falhas e confirmação da mesma operação.

Os testes PowerShell extraem a função real dos dois aplicadores e usam processos simulados. Conferem igualdade do protocolo nos dois fluxos, identidade da instância de origem, identificação da operação, confirmação válida e rejeição de confirmação incorreta. Não encerram agentes reais nem substituem executáveis.

Os testes de interface cobrem solicitação normal/forçada, círculo/porcentagem, etapas, histórico, cancelamento conforme a fase, bloqueio de solicitações duplicadas, espera de confirmação, sucesso, falha, tema claro/escuro, largura de 390 px, movimento reduzido e mensagens exibidas como texto.

A compilação usa o snapshot completo disponível das entregas anteriores e o identificador 5.0.114. O código completo da produção 5.0.114 não foi enviado; o build não comprova diferenças adicionais dessa instalação.

Não houve teste de atualização ponta a ponta numa instalação Windows sua, acesso ao servidor de produção ou troca real de arquivos do agente. A interface foi testada com estados simulados e os processos da troca foram simulados. A migração inicial, permissões, antivírus, NuGet, tarefas de inicialização, rollback real e autenticação da nova instância devem ser conferidos num computador de teste antes da distribuição ampla.

As imagens são prévias da interface com estados de exemplo, não capturas de uma atualização de produção.

Use `ATUALIZACAO_CENTRAL_ATUALIZACAO_CPANEL.zip` para atualizar os fontes. O snapshot `VALIDACAO_CENTRAL_ATUALIZACAO_WINDOWS.zip` é exclusivo para compilação e testes.
