# Testes de autenticação

Use somente uma instância separada e bancos descartáveis. Não execute testes de cadastro ou limpeza no banco do cPanel.

## Limite de tentativas com MySQL/MariaDB

O teste cria uma tabela com nome aleatório e a remove no final. Configure `GESTI_TEST_DSN` com o DSN de um banco de testes e, se necessário, `GESTI_TEST_DB_USER` e `GESTI_TEST_DB_PASSWORD` no ambiente do processo. Execute:

```sh
php tests/login_security_test.php
```

São nove testes, incluindo limites por IP, preservação de falhas, expiração, liberação de locks e seis conexões concorrentes. Requer PDO MySQL, InnoDB, `GET_LOCK`/`RELEASE_LOCK` e `proc_open` habilitado para o teste de concorrência. O aplicativo usa os locks do banco, mas não usa `proc_open` para limitar tentativas.

## Fluxos HTTP

Prepare outra cópia da aplicação com Apache/PHP e um banco vazio cujo nome termine em `_login_http_test`. Importe `database/schema.sql` somente nesse banco vazio e ajuste o `includes/config.php` dessa cópia. O teste insere contas e uma instalação fictícia de agente; recrie o banco descartável antes de repetir.

O teste deve rodar via PHP CLI no mesmo container/computador do Apache, com a cópia de testes atendendo em `http://127.0.0.1` na porta 80:

```sh
GESTI_LOGIN_HTTP_TEST=1 php tests/login_http_test.php
```

São sete testes: CSRF, sessão de cliente, sessão administrativa, limite compartilhado com a API, autenticação do agente, expiração e cadastro com sessão persistida. O teste recusa bancos sem o sufixo exigido e não acompanha redirecionamentos automaticamente. A pasta `tests` bloqueia acesso HTTP pelo Apache.

Validação realizada em PHP 8.3 e MariaDB 11.4. O ambiente do cPanel ainda precisa do teste de instalação da atualização.

## Painel responsivo e navegação mobile

Instale Playwright em um ambiente de testes e disponibilize Chromium. Prepare uma conta fictícia com pelo menos 17 redes, incluindo uma com `nome muito longo` no nome. Defina `GESTI_UI_BASE_URL`, `GESTI_UI_EMAIL` e `GESTI_UI_PASSWORD` no ambiente; opcionalmente defina `GESTI_CHROMIUM_PATH` e `GESTI_UI_OUTPUT_DIR` para o navegador e as capturas. Execute `node tests/panel_mobile.cjs`.

O teste verifica 35 combinações: 0, 1, 2, 5 e 17 redes em 320×568, 360×640, 390×844, 430×932, 768×1024, 844×390 e 1440×900. A quantidade de cartões é alterada somente no HTML interceptado pelo navegador, sem criar ou excluir redes no banco. Confere ausência de rolagem vertical no painel, cartões sem conteúdo cortado, paginação, busca com nome longo, recuperação de busca vazia, modais, menu mobile, destino inicial do PWA e ícones disponíveis. As telas longas de formulário mantêm rolagem interna acessível.

## Aparência completa da plataforma (2.8.0)

`node tests/platform_appearance.cjs` usa as mesmas variáveis do teste de painel, mais `GESTI_UI_ADMIN_EMAIL`, `GESTI_UI_ADMIN_PASSWORD` (opcional quando igual à senha do usuário de testes) e `GESTI_UI_NETWORK_ID`. Use contas e redes fictícias em uma cópia isolada. São 96 combinações de páginas de usuário/administração, dois temas e três telas; também verifica mudança automática do sistema e contraste dos gráficos Canvas. Não cria imagens. A tela de permissões vazia também deve iniciar sem erros JavaScript.

## Aparência do agente e coerência de versões

`python3 -m unittest discover -s tests -v` inclui segurança do serviço local, duas verificações de versão e seis testes de Tkinter. Os testes gráficos precisam de Tkinter e display; ficam marcados como ignorados quando não houver display. Em Linux, forneça Xvfb e `DISPLAY` ao processo. Na validação desta entrega os 15 testes executaram e passaram com display virtual e rede externa desativada. Os testes gráficos verificam persistência, modo automático, botões, texto de Canvas e construção das interfaces principal e de cadastro em janelas menores.

O instalador foi baixado da aplicação local e inspecionado como ZIP: componentes versionados em 2.8.0 e suporte de aparência presente. O canal autenticado de atualização foi verificado com seis arquivos e seus SHA-256. Não foi executado instalador nem comando remoto em computador de cliente. Sem alterações de schema nesta entrega.

A camada mobile também converte tabelas largas em registros rotulados, mantendo os cabeçalhos de ordenação e a seleção acessíveis. Estilos de impressão mantêm papel claro, mesmo quando o aplicativo está escuro. Na migração completa para 2.8.0, use o novo instalador para substituir também o atualizador auxiliar; o canal legado conserva sua lista de seis arquivos por compatibilidade.


## Refatoração direta — 2.8.1

Os mesmos testes agora usam as definições originais consolidadas, sem arquivos de sobreposição nem conversão automática de cores. A suíte de versão também verifica a ausência das implementações removidas e das interceptações de Canvas/Tkinter. O teste do agente usa componentes com papéis de cor declarados explicitamente; não depende de adaptar cores antigas. A cópia duplicada do agente no HTML legado deixou de existir.

Na 2.8.1 passaram 96 cenários de páginas, 35 do painel e 16 testes Python, incluindo uma verificação contra reintrodução dos arquivos e adaptadores removidos. A validação não gerou imagens.
