# Validação da entrega Backup 5.0.108

[Execução final no GitHub Actions](https://github.com/marcosliraxx/GesTI/actions/runs/42374486367) — **sucesso**.

Snapshot validado no commit `25ab58fdb345ef072c1b3f325c49c458a3835fff` (consulte o SHA completo da execução no GitHub). O código entregue no ZIP de produção corresponde aos arquivos novos/alterados desse snapshot e aos recursos da interface testada.

| Verificação | Resultado |
|---|---|
| Núcleo de backup/recuperação, .NET 8, Windows x64 | **41 asserções aprovadas** |
| Mesmos testes de núcleo no Linux, com arquivos temporários | **41 asserções aprovadas** |
| API PHP 8.2 / MySQL 8 real / identidades sintéticas | **52 asserções aprovadas** |
| Agente completo WinUI 3 x64, versão 5.0.108 | **Compilação aprovada: 0 avisos, 0 erros** |
| Publicação do BackupWorker independente win-x64 | **Aprovada** |
| Sintaxe dos scripts PowerShell do snapshot | **Aprovada** |
| Sintaxe dos PHP alterados | **Aprovada** |
| Interface em Chromium / Playwright | **Aprovada**, sem erros JavaScript nos cenários testados |
| ZIP de produção e igualdade dos recursos web/nativos | **CRC e comparação aprovados** |

## Cenários cobertos

Núcleo: caminhos inseguros e escape de diretório, nomes reservados, Unicode, exclusões, arquivos vazios, subpastas, deduplicação de conteúdo, alteração de versão, recuperação de arquivo excluído na origem, restauração sem sobrescrever originais, leitura integral de verificação, seleção de arquivos, rejeição de conteúdo corrompido, rejeição de manifesto adulterado, falhas de formatos de banco ativo, repositório Local/NAS, manifesto local independente, destino dentro da origem, cancelamento e uso do endpoint autenticado configurado.

Backend: CSRF, papel administrativo, permissão Backup separada de Clean, isolamento entre contas, credencial revogada, conta bloqueada, execução com monitoramento de métricas pausado, intervalos/fuso/horário diário, limite de tentativas, criação e pausa de planos, lease exclusiva por computador, metadados idempotentes, manifesto canônico, contagem calculada no servidor, verificação sem falso positivo, envio HTTP binário, confirmação e reutilização de objeto, manifesto independente no destino, nome original no download, exibição do executor SYSTEM, preservação do último ponto completo, pontos fixados/em uso, retenção sem exclusão física e retorno de ponto arquivado ao catálogo.

Navegador: abas, formulário, plano novo desativado por padrão, consulta de versões, seleção de arquivos, solicitação de verificação, detalhes das falhas, escape de conteúdo HTML, erro de conexão preservando o último estado, largura móvel de 390 px, tema escuro, transporte HTTP mesmo com `window.chrome.webview` presente e ponte nativa emulada.

## Limites da validação

Os testes da API usam o MySQL real e o código real da API/central. Identidades de sessão e adaptadores de provedor são fixtures; o fluxo de autorização do agente usa a implementação real da nova central com credenciais sintéticas. Os testes de nuvem não enviam dados a contas Google/AWS/Microsoft/Dropbox reais. O caminho de Google Drive reutiliza os adaptadores já existentes na plataforma.

O WinUI foi compilado no Windows; a sessão gráfica completa e a tarefa SYSTEM não foram executadas em seu servidor. O teste de ponte no navegador é uma emulação, não uma execução do WebView2 do Windows. ARM64, NAS real, permissões de domínio, reinstalação/atualização do worker e limites do seu cPanel devem ser conferidos na implantação controlada.

Não houve acesso à produção, transferência de arquivos dos clientes nem alteração do seu Drive. As imagens de prévia usam dados de exemplo.
