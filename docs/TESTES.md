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
