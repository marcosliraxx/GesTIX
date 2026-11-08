# Validação da entrega 5.0.152

Base recebida: `bkp_app.digital.log.br_20261108_044015_256874.zip`, versão 5.0.151. Código funcional validado: commit `fc55e6c` (ArcMod). Data: 08/11/2026.

[Execução completa no GitHub Actions](https://github.com/marcosliraxx/GesTI/actions/runs/42464555167) — cinco jobs aprovados: Windows, compilação nativa, PHP, Python e navegador.

| Verificação | Resultado |
|---|---|
| Gestor WinUI x64, Release, Windows Server 2022 | Compilado, zero erros e zero avisos |
| BootWorker com coletor de logs | Compilado, zero erros e zero avisos |
| Núcleo .NET | 256 verificações: temas, redação, leitura por bytes, UTF-8/UTF-16, fila durável, ACK e troca de rede |
| Instalador WPF em PowerShell 5.1 | 19 verificações por execução, incluindo validação do script worker; repetido para os dez temas |
| Renderização WPF real | Estados preparando arquivos, concluído e falha; imagens geradas no Windows, sem instalar |
| Sintaxe PHP | Todos os arquivos da árvore conferidos com PHP 8.2 |
| Armazenamento PHP | 114 verificações: catálogo, limites, isolamento, gravação atômica, duplicatas e retenção |
| API HTTP | 24 verificações com SQLite sintético e endpoint real: autenticação, credencial de outra rede, recebimento, repetição, bootstrap, ZIP/SHA-256, BAT e VBS |
| Python | Compilação de scripts e quatro cenários de teste: contraste das 20 paletas, tema publicado, redação e fila offline/transferência de rede |
| Chromium | 123 verificações: dez temas claros/escuros, cores CSS reais, prioridade administrativa, foco e mudança do tema do sistema |

Os testes da API usam dados fictícios e não acessam o banco de produção. O teste de fila utiliza transporte simulado, incluindo indisponibilidade e confirmação de lote. As telas geradas são explicitamente marcadas como prévias.

A instalação completa, elevação UAC, tarefas/serviços, comportamento de antivírus e recuperação de instalação interrompida não foram executados em uma máquina física de cliente. A compilação e a renderização não substituem essa homologação operacional. Nenhum resultado de desempenho remoto foi inventado; a pesquisa DWService separa leitura do código, documentação e propostas futuras.

A revisão visual confirmou a composição do instalador e a legibilidade do PDF. A configuração privada, arquivos de clientes, SQL e logs reais não integram o pacote.
