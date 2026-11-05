# GesTI / Arcoll — 3.9.0

Plataforma PHP para gestão de redes e suporte, com agente Windows em Python/PowerShell. Esta versão consolida o backup 3.8.135 fornecido pelo proprietário e a atualização Backup em Nuvem.

Leia [instalação, configuração, validação e reversão](LEIA-ME-3.9.0.txt), [auditoria da consolidação](docs/AUDITORIA-3.9.0.md) e [testes](docs/TESTES.md).

O código canônico do agente está em `agentes/windows`; o aplicativo de suporte está em `agentes/suporte`. As antigas pastas `agent` e `support` foram substituídas. `VERSION` é a fonte única da versão; use `python3 scripts/bump_version.py X.Y.Z` e valide `tests/test_release_version.py`.

## Desenvolvimento

PHP 8.3+ com PDO MySQL, cURL, OpenSSL, mbstring, GD e ZIP; Apache com rewrite/headers e AllowOverride; MariaDB/MySQL com InnoDB e locks nomeados. Os testes desta atualização usaram PHP 8.4 e MariaDB 11.8. Não há manifesto Composer/npm da aplicação.

Use um banco local separado. `database/schema.sql` contém exclusivamente estrutura consolidada, sem registros de produção. Crie `includes/config.php` a partir de `includes/config.example.php`, com credenciais locais e app_secret aleatório. O bootstrap aplica o esquema aditivo; não importe SQL de produção nem apague tabelas para atualizar. A configuração privada, uploads e dados de execução não devem ser enviados ao Git.

Backup em Nuvem usa contas Google Drive, OneDrive, Dropbox ou S3 configuradas pelo administrador e conectadas pelo gestor da rede. O agente só lê pastas autorizadas localmente e repassa blocos pela plataforma. O conteúdo não é persistido pela aplicação; configurações, autorizações criptografadas e recibos ficam no banco. Veja no LEIA-ME os requisitos de hospedagem e os limites dessa garantia. O backup é em uma direção e preserva cópias remotas após exclusões locais.

## Entrega

O ZIP em `pacotes/GesTI-3.9.0-atualizacao-consolidada.zip` contém apenas o delta em relação ao backup fornecido, instruções em português e marcador vazio para exclusão manual de `api/agent-api.php`. O código completo atualizado está nesta branch. O manifesto do ZIP descreve esse delta, não uma comparação com a versão anterior do Git.

Atualizar o GitHub não instala arquivos no cPanel nem atualiza automaticamente os agentes Windows. Homologue OAuth e provedores reais e o instalador em um Windows de testes antes de produção. Não há credenciais ou dados de clientes neste repositório.
