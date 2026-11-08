# Validação — temas, círculo e histórico

[Execução final aprovada no GitHub Actions](https://github.com/marcosliraxx/GesTI/actions/runs/42442928087).

| Verificação | Resultado |
|---|---|
| Estados e recibos da atualização, .NET 8 / Windows | 24 asserções aprovadas |
| Paletas, contraste, persistência e recuperação do histórico / Windows | 52 asserções aprovadas |
| API PHP 8.2 real com SQLite/credenciais sintéticas | 14 asserções aprovadas |
| Interface e aplicação das paletas, Chromium/Playwright | 58 asserções aprovadas |
| Agente completo WinUI 3 x64 | Compilação aprovada: 0 avisos e 0 erros |
| Sintaxe PHP/JavaScript e ZIP de produção | Aprovada |

As paletas testadas incluem os seis temas publicados pela plataforma. Foram conferidas as igualdades 4=9, 6=8 e 7=5, os tons derivados das áreas 3 e 2 e o contraste do texto do rodapé.

Os testes de histórico cobrem origem/destino, modo, horários de conclusão, recibos repetidos, persistência após restauração de sessão, cancelamento, falha sem falso 100%, preservação de arquivo ilegível, recuperação da gravação, limite de registros e disponibilização do registro antes da notificação final à interface. Os testes existentes de correlação de recibos e progresso foram repetidos com o modelo novo.

A API foi executada com seu código real, fixtures de bootstrap/catálogos, banco SQLite e identidades sintéticas. A consulta binária retorna JSON e contagem de diferenças, sem transferir o ZIP ao cliente. A consulta de fontes retorna o hash do pacote determinístico. Foram testadas credenciais inválidas, rede inativa, arquitetura e caminhos inválidos. Os downloads ZIP normais continuaram funcionando e mantendo SHA-256 válido. Nenhum executável da fixture foi executado.

Os testes de interface cobrem consulta pelo círculo sem solicitação de instalação, botões depois da consulta, caso sem novidade, percentual e etapas em torno do círculo, conclusão correlacionada, mensagens de falha, filtros, busca sem acentos, ordenação, modal, Escape, conteúdo escapado, preservação da rolagem e grade com cabeçalho fixo. Incluem largura de 390 px, movimento reduzido e as cores dos campos do Painel nos seis temas.

## Limites

A compilação usa o snapshot completo disponível das entregas anteriores, com este pacote, identificado como 5.0.116. O código completo de produção 5.0.116 não foi enviado; o build não comprova diferenças adicionais da instalação.

As telas foram testadas no navegador com estados/recibos de exemplo. A moldura nativa nas imagens completas é uma composição para demonstrar o layout. O modo real da barra de título, mudanças de tema do Windows e do administrador em uma sessão WinUI, a atualização ponta a ponta e o retorno do agente no computador do cliente precisam ser conferidos na implantação de teste.

Não houve acesso à produção, instalação real de atualização nem leitura de histórico pessoal do usuário. Os scripts de troca silenciosa/rollback da entrega anterior foram preservados neste pacote.

Use `ATUALIZACAO_TEMAS_HISTORICO_CPANEL.zip` para os fontes de produção. Não extraia o snapshot de validação no cPanel.
