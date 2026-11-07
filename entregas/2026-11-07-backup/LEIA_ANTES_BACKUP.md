# Central de Backup 1.0 — T.I. Digital 5.0.108

Esta entrega cria uma área própria de Backup na plataforma e no agente Windows, separada do Clean. É uma implementação de backup **por arquivo**, com destinos em nuvem e Local/NAS, versões, agendamento e recuperação validada por SHA-256. A conexão Google Drive existente é reutilizada; não é necessário reconectar os clientes nem mover seus arquivos antigos.

## Instalar no cPanel

1. Faça backup dos arquivos que serão substituídos e do banco de dados. Preserve também, em local protegido, a configuração e a chave de criptografia já usadas pela plataforma para os tokens OAuth.
2. Extraia **ATUALIZACAO_BACKUP_CPANEL.zip** na raiz do sistema, onde ficam `api`, `includes`, `redes`, `assets`, `agentes` e `VERSION`. Mescle as pastas e substitua os arquivos do pacote.
3. Entre em **Redes → Backup**, escolha o computador e confira os destinos. As tabelas `backup_center_*` são criadas automaticamente pela API, com o prefixo já configurado. O usuário MySQL precisa de permissão CREATE TABLE. Não há importação de um SQL de clientes.
4. Atualize o agente pelo fluxo existente. A versão central passa de **5.0.107 para 5.0.108**. Os arquivos PHP não atualizam sozinhos o executável Windows já instalado.
5. Se usa releases pré-compiladas, compile e publique uma release 5.0.108 antes de distribuí-la. No Windows, a partir da pasta do projeto, execute `PUBLICAR_RELEASE_PRECOMPILADA.ps1 -Arch win-x64` e publique `release.json` e `files/` no destino de releases já usado pela plataforma. ARM64 exige a publicação dessa arquitetura.
6. Crie um plano inicialmente desativado, revise os caminhos, escolha um destino e ative. Execute uma cópia pequena, consulte o ponto e faça uma restauração de teste antes de ampliar as origens.

O pacote é incremental sobre o backup **5.0.107 fornecido nesta conversa**, com as atualizações anteriores de Internet, Painel e Pente-Fino aplicadas. Não substitua uma instalação de outra versão sem comparar os arquivos comuns. O pacote preserva as integrações e páginas aceitas nas entregas anteriores. Não contém credenciais, banco de produção ou executáveis instaláveis.

Requisitos: PHP 8.1+ com PDO MySQL, mbstring, cURL, HTTPS e os requisitos já existentes de distribuição do agente. Para envio, permita POST binário de até 5 MiB no PHP, proxy e WAF; `post_max_size` de pelo menos 8 MiB e `memory_limit` de 128 MiB são referências de configuração. Limites do provedor e do plano continuam valendo. Não desabilite autenticação ou validação TLS para contornar erros de conexão.

## O que foi implementado

- Menu principal **Backup** no agente: Visão geral, Nuvem, Local/NAS, Restauração, Atividade, Destinos e Disco virtual.
- Página `redes/backup.php` própria, com seleção do computador. A aba de backup do Clean deixa de carregar seus códigos; links antigos redirecionam para a nova área.
- Planos independentes por conta/rede/computador, com até 20 origens por plano e 25 planos por computador.
- Agendamento manual, por intervalo (mínimo 15 minutos) ou diário com fuso IANA explícito. Padrão: `America/Belem`. O computador precisa estar ligado e conectado. A fila é consultada a cada 30 segundos; não é um agendador de tempo real.
- Novas tentativas após falha/resultado parcial/interrupção, com espera e limite configuráveis. Não há loop ilimitado de tentativas. Uma execução diária perdida pode ocorrer quando o executor voltar a se conectar, conforme o horário do dia atual.
- SHA-256 para identidade do conteúdo; um arquivo inalterado reutiliza o objeto do mesmo plano/destino. Arquivos alterados geram novos objetos. A deduplicação é **por arquivo**, não por blocos, e não é compartilhada entre clientes.
- Captura temporária de um arquivo por vez, limite por arquivo, exclusões por padrão e controle de banda de envio para a nuvem em KiB/s.
- Envio em blocos usando os adaptadores existentes de Google Drive, OneDrive, Dropbox e S3. Sessões de envio retomáveis, confirmação de offset e tentativas limitadas para indisponibilidade/limites HTTP. O caminho principal solicitado é Google Drive; os demais dependem das integrações já configuradas.
- No Google Drive, confirmação de tamanho e MD5 após o envio de um objeto; na restauração/verificação, conferência de tamanho e SHA-256 do conteúdo efetivamente lido.
- Pontos separados: excluir ou alterar um arquivo de origem não remove suas versões anteriores.
- Manifesto e hash de cada ponto no catálogo SQL. No Local/NAS, cópia independente em `points/`. Na nuvem, o manifesto com a configuração daquele ponto é enviado para `points/` antes de o catálogo indicar conclusão. Objetos e manifestos ficam em uma área nova, separada do formato antigo.
- Recuperação integral ou de até 1.000 arquivos selecionados; a consulta do catálogo é paginada. Até 20.000 arquivos por ponto e manifesto de até 8 MiB.
- Restauração em **pasta nova**, organizada por `Origem1`, `Origem2` etc. Nenhum original é sobrescrito. Arquivos temporários corrompidos/incompletos não são entregues como restaurados.
- Verificação integral sob demanda: lê todos os arquivos do ponto e confere SHA-256, sem criar uma cópia de restauração. Somente uma verificação integral concluída marca o ponto como verificado.
- Histórico, falhas por amostra, cancelamento, pausa de planos, pontos fixados, prévia de retenção do catálogo e reativação de pontos arquivados nas últimas 100 operações.
- Alertas visuais por RPO: o prazo é contado desde o último backup completo. Resultado parcial e executor sem contato não viram proteção comprovada.
- Permissão **Backup** própria para perfis customizados. Proprietário/administrador gerencia; operador sem essa permissão é negado. O agente pode executar planos já autorizados e solicitar verificação; criação de origens, alterações e restauração são feitas na sessão autenticada do gestor na plataforma.
- Credencial revogável do agente, CSRF no navegador, escopo de conta/rede/computador, lease com heartbeat e apenas uma operação ativa por computador. Backup não depende da coleta de métricas estar habilitada; rede, conta e agente precisam continuar autorizados.

## Antes do login do Windows

O agente aberto já executa os planos em segundo plano. Para executar após reinicializar o servidor, antes do login, há um executável dedicado **TIDigital.BackupWorker**, sem WinUI e sem comandos remotos genéricos.

O processo de publicação inclui o worker na pasta `BackupWorker/`. Para instalar, abra PowerShell como administrador na pasta publicada do agente e execute:

```powershell
.\INSTALAR_BACKUP_SERVICO.ps1 -SourceConfig 'C:\CAMINHO\agent-config.json'
```

Use a configuração válida do agente já aprovado. O script exibe somente origem e código da rede, pede que o administrador digite `INSTALAR` e cria a tarefa `TIDigital-BackupCenter` no início do Windows. A tarefa roda como SYSTEM, com binários em Program Files e configuração/cache protegidos em ProgramData. Ela não é instalada silenciosamente pelo upload no cPanel.

**NAS/UNC:** SYSTEM usa a conta do computador na rede; unidades mapeadas de uma sessão de usuário normalmente não existem nesse contexto. Use caminhos UNC e conceda as permissões necessárias, ou ajuste a conta de execução da tarefa no Windows. Não coloque senhas do NAS no plano, no Git ou em scripts públicos. No agente aberto, valem as permissões da conta que o executa.

Após atualizar os binários, execute novamente o instalador da tarefa para atualizar a cópia protegida do worker. `-Remove` remove somente a tarefa; preserva configuração, cache e dados. Nenhum ponto é apagado por essa remoção.

Restaurações feitas pelo agente aberto ficam sob `%LOCALAPPDATA%\TI-Digital\Restaurados\<escopo>\<operacao>`. Pelo worker SYSTEM, ficam sob `%PROGRAMDATA%\TI-Digital\BackupService\Restaurados\<escopo>\<operacao>`. O caminho efetivo aparece nos detalhes da operação. O diretório SYSTEM é acessível a administradores.

## Migrar a configuração anterior

A Central identifica a configuração antiga e oferece **Revisar origens anteriores**. Isso preenche um novo plano **desativado**. Revise caminhos com variáveis como `%USERPROFILE%`: prefira caminhos absolutos estáveis para execução antes do login.

1. Reutilize a conexão existente e revise as origens.
2. Ative o novo plano, execute e teste uma restauração.
3. Use **Pausar envio anterior** para desativar somente o job antigo. Não desconecta a conta, não apaga arquivos e não desativa a configuração do disco virtual.

O disco virtual continua acessando o formato anterior. Os novos pontos usam objetos por hash e são acessados pela aba Restauração. Os arquivos antigos não são convertidos automaticamente em pontos novos.

## Limites que precisam ficar claros ao cliente

- **Retenção nesta versão arquiva pontos no catálogo; não apaga objetos nem libera espaço físico.** A prévia exige confirmação. Pontos fixados e pontos consultados em operações ativas são preservados na seleção. Coleta física de objetos órfãos e GFS automático não foram implementados.
- **Sem VSS ou imagem do sistema.** SQL Server, Exchange, PostgreSQL, máquinas virtuais e aplicações ativas exigem exportação consistente ou mecanismos próprios. Os formatos comuns de arquivos de SQL Server/Exchange e discos VHD são rejeitados; isso não equivale a detectar todas as aplicações. Não prometa recuperação bare metal nem consistência transacional.
- Cópia por arquivo não preserva todos os ACLs, fluxos alternativos, hardlinks, metadados de aplicações ou identidade do volume. Não é clonagem de disco. Junções, links, arquivos somente online e origens protegidas são recusados/informados.
- A captura usa espaço temporário no computador: precisa caber um arquivo inteiro, mais margem. O padrão é 1.024 MiB por arquivo. Arquivos alterados grandes são enviados por inteiro. Não há compressão nem criptografia própria no cliente nesta entrega.
- HTTPS protege o transporte; tokens OAuth continuam criptografados no servidor pelo mecanismo existente. Isso **não é criptografia ponta a ponta** dos backups nem proteção contra um administrador do provedor.
- Google Drive não é armazenamento WORM. Fixar um ponto no catálogo não impede exclusão pelo administrador da conta ou por credenciais comprometidas. Uma segunda cópia independente/offline continua necessária.
- A interface apresenta armazenamento gerenciado da T.I. Digital, mas não afirma que os dados estão em servidor próprio. O provedor pode ser Google Drive. Nesta arquitetura, os blocos passam pelo PHP em memória antes do provedor; o conteúdo não é intencionalmente armazenado em disco no cPanel. Proxies, logs e a infraestrutura de hospedagem precisam seguir essa política.
- A capacidade de 24 TB não elimina os limites de API/envio da conta. Uma conta compartilhada precisa de orçamento de tráfego e monitoramento operacional. Limites comerciais por cliente, faturamento por consumo e notificações externas não foram implementados.
- Os alertas desta entrega são visuais. Não há disparo de e-mail/WhatsApp/SMS nem verificação agendada automática de restaurações.
- O catálogo SQL e a configuração segura da plataforma continuam essenciais para a operação normal. Manifestos independentes ajudam na recuperação, mas ainda não há assistente de importação/offline após perda completa da plataforma. Faça backup do banco e da configuração em outro destino.

## Validação e arquivos de teste

Os testes usam arquivos, contas e provedores sintéticos; não acessam seus dados ou Drive de produção. O snapshot de validação contém o agente completo com Internet, Painéis e Pente-Fino, o novo worker e os testes. Ele é exclusivo para CI/desenvolvimento: **não extraia VALIDACAO_BACKUP_FONTES_WINDOWS.zip no cPanel**.

A compilação e os testes estão no workflow `validar-backup-entrega.yml` do GitHub. As prévias são identificadas como demonstração e usam dados de exemplo. O arquivo `VALIDACAO_BACKUP.md` registra os resultados e suas limitações.

## Reverter

Pause os novos planos e remova a tarefa dedicada, se instalada. Restaure os arquivos e a release anterior do agente. Preserve tabelas, manifestos e objetos novos para análise/recuperação; não execute DROP/DELETE como parte da reversão. O formato antigo permanece separado.
