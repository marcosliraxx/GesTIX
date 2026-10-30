# GesTI / Arcoll

Sistema PHP para suporte e monitoramento de servidores, redes e conexões, com agentes Python e PowerShell. Código importado do backup de 29/10/2026; versão declarada da aplicação: 2.2.1.

## Conteúdo e dados privados

Este repositório contém código, arquivos estáticos e apenas a estrutura do banco em `database/schema.sql`. Não contém o banco de produção, usuários, telemetria, uploads nem `includes/config.php`. O modelo `includes/config.example.php` não contém credenciais reais.

## Ambiente de desenvolvimento

Validado inicialmente com PHP 8.3, Apache com `rewrite` e `headers`, e MariaDB 11.4. Extensões: PDO MySQL, mbstring, GD, ZIP, cURL e OpenSSL. Não há dependências Composer ou npm declaradas no backup.

1. Prepare um banco local separado do banco do cPanel.
2. Importe `database/schema.sql` nesse banco vazio. O arquivo não contém registros de produção.
3. Copie `includes/config.example.php` para `includes/config.php`; preencha as credenciais locais e gere um `app_secret` aleatório.
4. Sirva a raiz com Apache e `AllowOverride All` para respeitar as regras dos arquivos `.htaccess`. Autorize escrita do processo PHP somente nos diretórios de execução necessários, como `uploads` e `includes/storage`.
5. Acesse `index.php`. O banco inicialmente não possui contas. O cadastro público cria clientes; não cria administradores. A configuração administrativa precisa de um procedimento separado para desenvolvimento.

Não execute o instalador sobre um banco de produção. O comando `php -l arquivo.php` verifica sintaxe; não substitui testes funcionais.

## Atualizações no cPanel

Faça backup de arquivos e banco antes de atualizar. Teste as alterações em desenvolvimento primeiro. Preserve o `includes/config.php`, os uploads e os dados existentes do servidor. Não substitua o banco de produção pelo esquema vazio deste repositório. Atualizar o GitHub não publica automaticamente no cPanel.

## Validação inicial

Na cópia isolada: 46 arquivos PHP passaram na verificação de sintaxe; tela de acesso retornou HTTP 200; manifesto PWA retornou JSON válido; acesso HTTP a `includes/config.php` foi bloqueado. Login autenticado, permissões entre clientes, agentes Windows, acesso remoto e integrações externas ainda precisam de testes.

## Proteção do agente local (7.4)

O serviço HTTP do agente atende somente em `127.0.0.1`. Abra o painel no mesmo computador em que o agente está instalado; o navegador continua sujeito à lista de origens autorizadas. O agente pode continuar descobrindo dispositivos da rede, mas outros computadores não podem chamar sua API diretamente. A entrada manual no painel aceita `localhost` e `127.0.0.1`.

O token da instalação não é mais enviado automaticamente aos IPs descobertos. Dados detalhados de hardware são coletados pelo agente instalado no próprio equipamento; o inventário de vizinhos continua identificando IP, MAC e serviços. O endpoint de inventário detalhado continua exigindo o token `ARCOLL_AGENT_TOKEN`.

Essa proteção está aplicada tanto ao agente do pacote quanto à cópia oferecida pelo painel. Instalações antigas só recebem a proteção depois de atualizar seus arquivos; pacotes publicados anteriormente também precisam ser republicados com a nova versão. Acesso direto ao agente de outro computador deixa de ser suportado nesta versão.

Testes de segurança: `python3 -m unittest discover -s tests -v`. Os testes simulam a varredura sem acessar equipamentos reais e usam uma porta HTTP temporária apenas no próprio computador para verificar a comunicação do navegador.

## Proteção do login

O formulário público, o acesso administrativo e a autenticação das configurações do agente compartilham o limite por IP definido em **Configurações > Login e acesso**. Sem um valor salvo, o padrão é de 5 tentativas em 15 minutos, com intervalo configurável de 3 a 30. Um acesso correto remove somente a própria tentativa; erros anteriores continuam contando até expirar. Requisições simultâneas reservam suas tentativas com locks do MySQL/MariaDB.

O bloqueio no formulário público e na API retorna HTTP 429 com `Retry-After`. Esta atualização utiliza a tabela existente, sem importação de SQL nem alteração de credenciais. O cadastro público também passa a preencher `access_notes`, obrigatório no schema atual.

Ao instalar, envie **também** o novo arquivo `includes/login-security.php`, antes de substituir o bootstrap e os demais arquivos. Consulte [os testes de autenticação](docs/TESTES.md) para reproduzir a validação em uma instância descartável.

## Painel e aplicativo mobile

O painel organiza as redes em uma grade que calcula sua capacidade pela área disponível da tela. Com muitas redes, a navegação usa páginas em vez de uma lista vertical extensa. Busca, filtros de situação e controles de página mantêm todas as redes acessíveis. No celular, uma barra inferior oferece Painel, Nova rede, Perfil e Menu; áreas seguras e modais respeitam o tamanho da tela.

O manifesto mantém a identidade existente do aplicativo e passa a iniciar em `pages/painel.php`. Ícones padrão e a fonte de ícones das telas compartilhadas são locais. O service worker usa um novo cache de arquivos estáticos; dados privados e páginas autenticadas continuam dependendo da rede. O painel foi validado em Chromium com 35 combinações de tamanho de tela e quantidade de redes, além das interações documentadas nos testes. Instalações físicas em Android/iOS ainda precisam ser conferidas no aparelho.

## Versão 2.8.1: estilos nos componentes originais

A plataforma, a interface principal do agente, o cadastro desktop, o serviço local e o atualizador usam a mesma versão de entrega. `VERSION` é a referência; execute `python3 scripts/bump_version.py X.Y.Z` em cada entrega e valide `tests/test_release_version.py`.

Todas as páginas compartilham as opções **Automático, Claro e Escuro**. No navegador, a escolha fica salva por dispositivo e o automático acompanha `prefers-color-scheme`. No agente, a escolha fica no perfil local do usuário e o automático acompanha o tema de aplicativos do Windows; temas do macOS e a variável GTK_THEME também são reconhecidos. O agente desktop continua sendo um aplicativo de computador, com janela redimensionável e controles compactos; não se torna um aplicativo Android/iOS.

As definições visuais estão nos arquivos originais `assets/css/arcoll.css`, `assets/css/redes.css` e nos componentes PHP. Seleção de tema e interações compartilhadas ficam em `assets/js/arcoll.js`; o painel possui sua própria inicialização em `pages/painel.php`. Não há leitura ou reescrita de folhas CSS pelo JavaScript, nem substituição de métodos Canvas/Tkinter. No agente, cores são declaradas por papel nos locais de criação dos componentes. A troca de tema atualiza somente essas declarações explícitas. A antiga cópia HTML do PWA foi removida; o endereço instalado continua levando ao painel atual.

O instalador e o canal de atualização usam os arquivos atuais da pasta `agent`. Pacotes antigos publicados separadamente não podem mais misturar versões no download. A geração recusa componentes versionados divergentes. O canal autenticado mantém os seis nomes aceitos por agentes antigos; para atualizar também o atualizador auxiliar e ter a migração completa, baixe o novo instalador na plataforma, encerre o agente anterior e reinstale o pacote atual preservando o cadastro. Reinicie o computador na primeira migração caso existam processos antigos abertos.

Confira a instalação em Windows e o PWA em aparelhos físicos após aplicar o ZIP. Os testes gráficos automatizados do agente usam Linux/Tkinter e não substituem a validação do instalador PowerShell no Windows.


Ao instalar 2.8.1 sobre uma versão anterior, remova também os arquivos obsoletos listados no `LEIA-ME` do ZIP. A extração de um ZIP não apaga arquivos antigos automaticamente.
