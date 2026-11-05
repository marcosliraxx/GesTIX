# Auditoria e consolidação 3.9.0

Base de comparação: código do backup 3.8.135 enviado pelo usuário. O checkout antigo ArcMod não foi usado como base da aplicação. Somente os três recursos públicos de ícones ausentes foram recuperados do repositório já disponível. O backup original permaneceu intacto.

Foram inventariados todos os arquivos de código e recursos públicos dessa base. A extração de trabalho excluiu configuração privada, registros SQL, uploads, sessões, transferências e metadados do backup. Do SQL original foram utilizadas exclusivamente definições CREATE TABLE; não foram importados registros. O esquema entregue não contém INSERT, REPLACE, DROP, TRUNCATE ou valores de sequências de produção.

## Problemas encontrados e correções na origem

- `includes/cloud-backup.php` existia sem referências de integração no restante do código. Agora participa do bootstrap, do banco permitido, das APIs, da administração, da página Clean e do agente. Não foi criada outra implementação paralela de nuvem.
- As migrações legadas citadas pelo bootstrap não estavam no backup; a recuperação apenas registrava avisos. As definições originais foram consolidadas em `database/schema.sql`, as 15 definições de criação embutidas nesse trecho do bootstrap foram removidas, e os ajustes incrementais existentes foram preservados. Instalação vazia e reaplicação foram verificadas sem apagar dados.
- O marcador de esquema só identificava a versão: agora identifica também host, porta, banco e prefixo, evitando reutilizar prontidão de outro banco após troca de configuração.
- A autenticação original de comandos foi extraída para um único autenticador revogável usado também pelo backup. O vínculo rede/conta e o estado ativo da conta são revalidados; erros 403 do canal anterior foram preservados.
- O refresh OAuth podia prosseguir sem adquirir o lock; agora falha de forma controlada. Segredos e corpos de erros dos provedores não são refletidos nos relatórios nem nos logs de nuvem.
- URLs de upload/endpoints são verificadas para HTTPS público; IPs internos, credenciais na URL e portas alternativas são bloqueados. O transporte verifica certificados, limita respostas e fixa os endereços DNS validados. O provedor S3 compatível é selecionado pelo administrador, não por um agente.
- Destinos baseados só no nome da máquina podiam colidir. Os caminhos agora incluem rede, ID do agente e identificador estável da pasta. Caminhos inválidos não são renomeados silenciosamente.
- OneDrive não recebia a criação explícita dos diretórios. Dropbox agora usa criação nativa e cache por conexão. A criação de pastas Google foi serializada por conexão para evitar duplicatas em corridas.
- Envios não tinham um contrato integrado de concorrência/repetição. A API agora valida tamanho, SHA-256 do bloco, posição e revisão do job, usa lock por sessão e recibos idempotentes, revalida pausa/revogação e reinicia sessões expiradas. S3 usa multipart/SigV4 e checksum dos blocos. Cancelamento e retenção foram integrados à rotina original.
- As rotas destrutivas de espelhamento do módulo desconectado foram removidas; não são expostas pelo produto. O limpador de pastas de nuvem original continua separado e preservado. Exclusões locais do novo backup não apagam o provedor.
- Não existia um executor de backup no agente. `arcoll_cloud.py` é o componente nativo responsável pela função nova, incluído no inventário original de instalação/atualização. Usa consentimento local, SQLite fechado corretamente, manifesto incremental, blocos, retomada, limites, agenda, pausa e reenvio completo por nova geração. Não contém credenciais de provedores nem executa arquivos recebidos.
- A atualização periódica original podia reconstruir a tela durante edição. O renderizador do Clean passou a preservar o formulário editado até salvar ou navegar.
- A navegação desktop usava uma barra horizontal que consumia largura. A estrutura original foi substituída por cabeçalho e menu lateral nativos. A visibilidade consulta o estado real dos tabs, impedindo reabertura de abas ocultadas por bloqueio/permissão. Foram removidos os handlers de moldura/drag sem uso, o timer vazio de cartões e os construtores da tela legada de comandos não acessível; comandos e diagnósticos ativos foram preservados.
- A leitura de VERSION no agente não encontrava a raiz na estrutura `agentes/windows` do backup. A resolução original foi corrigida; a fonte única continua sendo VERSION, incluindo o suporte e o atualizador.
- Os três ícones padrão estavam ausentes, embora fossem exigidos pelo instalador, PWA e cabeçalho. O teste de pacote reproduziu a falha. Os recursos originais foram restaurados e o instalador completo voltou a ser gerado com SHA-256 e worker incluído.
- `api/agent-api.php` estava vazio e sem implementação/referências funcionais. É o único caminho identificado para exclusão manual e é incluído zerado no ZIP.

## Evidência de validação

Consulte LEIA-ME-3.9.0.txt. Foram usados banco e contas sintéticos, sem equipamentos de clientes. Os provedores foram simulados com TLS verificado, incluindo envio de múltiplos blocos e conferência de conteúdo. O instalador canônico completo foi construído e seu hash conferido. O navegador e Tkinter foram medidos sem gerar capturas.

A análise de duplicação percorreu toda a árvore pública extraída: não encontrou arquivos de código idênticos nem métodos Python repetidos dentro de uma mesma classe. Isso não significa equivalência semântica entre todos os módulos, nem homologação integral de todos os fluxos históricos. Os componentes não alterados estão listados abaixo como preservados; não se afirma teste funcional de todos eles. Windows real, OAuth real e cada serviço S3 compatível continuam exigindo homologação com contas/pastas descartáveis após configuração.

## Inventário da base pública

| Caminho original | Bytes originais | Destino nesta entrega |
| --- | ---: | --- |
| `.htaccess` | 786 | Preservado |
| `VERSION` | 8 | Modificado |
| `admin/agentwin.php` | 11311 | Preservado |
| `admin/clean.php` | 20730 | Preservado |
| `admin/clientes.php` | 76193 | Preservado |
| `admin/comercial.php` | 19542 | Preservado |
| `admin/configuracoes.php` | 56703 | Modificado |
| `admin/log-retention-cron.php` | 596 | Preservado |
| `admin/menus.php` | 45014 | Preservado |
| `admin/permissoes.php` | 42408 | Preservado |
| `admin/usuarios.php` | 29749 | Preservado |
| `admin/versao.php` | 3223 | Preservado |
| `agentes/suporte/Instalar_Suporte.bat` | 843 | Preservado |
| `agentes/suporte/Instalar_Suporte.py` | 4695 | Preservado |
| `agentes/suporte/arcoll_support.py` | 22783 | Preservado |
| `agentes/suporte/requirements.txt` | 61 | Preservado |
| `agentes/windows/Desinstalar_Arcoll.ps1` | 10499 | Preservado |
| `agentes/windows/Diagnosticar_Inicializacao.ps1` | 1033 | Preservado |
| `agentes/windows/Diagnosticar_Nucleo.ps1` | 1010 | Preservado |
| `agentes/windows/Instalar_Arcoll.ps1` | 27845 | Preservado |
| `agentes/windows/Preparar_Instalacao.ps1` | 12950 | Preservado |
| `agentes/windows/arcoll_agent.py` | 26466 | Preservado |
| `agentes/windows/arcoll_app.py` | 262823 | Modificado |
| `agentes/windows/arcoll_cc_ui.py` | 63650 | Modificado |
| `agentes/windows/arcoll_clean.py` | 102307 | Preservado |
| `agentes/windows/arcoll_desktop.py` | 35060 | Preservado |
| `agentes/windows/arcoll_programs.py` | 18407 | Preservado |
| `agentes/windows/arcoll_release.py` | 631 | Modificado |
| `agentes/windows/arcoll_remote.py` | 62050 | Preservado |
| `agentes/windows/arcoll_secure_helper.py` | 33669 | Preservado |
| `agentes/windows/arcoll_toolkit.py` | 56586 | Preservado |
| `agentes/windows/arcoll_updater.py` | 16460 | Modificado |
| `agentes/windows/connection_diagnostics.py` | 7577 | Preservado |
| `agentes/windows/rt_engine.py` | 35843 | Preservado |
| `api/agent-api.php` | 0 | Exclusão manual (marcador de zero bytes) |
| `api/api.php` | 116119 | Modificado |
| `api/billing-webhook.php` | 2163 | Preservado |
| `api/brand-logo.php` | 3181 | Preservado |
| `api/download-agent.php` | 13731 | Preservado |
| `api/download-support.php` | 5998 | Preservado |
| `api/download.php` | 1635 | Preservado |
| `api/invite-network-logo.php` | 1275 | Preservado |
| `api/manifest.php` | 2487 | Preservado |
| `api/network-logo.php` | 1138 | Preservado |
| `api/remote-screen.php` | 22225 | Preservado |
| `api/support.php` | 8027 | Preservado |
| `assets/css/arcoll.css` | 167410 | Preservado |
| `assets/css/redes.css` | 123651 | Preservado |
| `assets/js/arcoll.js` | 44710 | Preservado |
| `assets/js/support-transfers.js` | 4365 | Preservado |
| `assets/vendor/font-awesome/css/font-awesome.min.css` | 30705 | Preservado |
| `assets/vendor/font-awesome/fonts/FontAwesome.otf` | 134808 | Preservado |
| `assets/vendor/leaflet/leaflet.css` | 14855 | Preservado |
| `assets/vendor/leaflet/leaflet.js` | 147600 | Preservado |
| `assets/vendor/qrcode-generator/qrcode.js` | 56750 | Preservado |
| `google-auth.php` | 12879 | Preservado |
| `includes/.htaccess` | 428 | Preservado |
| `includes/admin-actions.php` | 36441 | Modificado |
| `includes/admin-data.php` | 1402 | Preservado |
| `includes/billing.php` | 19534 | Preservado |
| `includes/bootstrap.php` | 101683 | Modificado |
| `includes/cloud-backup.php` | 64487 | Modificado |
| `includes/footer.php` | 25739 | Preservado |
| `includes/header.php` | 32117 | Preservado |
| `includes/icones.php` | 10552 | Preservado |
| `includes/log-maintenance.php` | 3445 | Preservado |
| `includes/login-security.php` | 2285 | Preservado |
| `includes/media-optimizer.php` | 2322 | Preservado |
| `includes/navigation.php` | 4656 | Preservado |
| `includes/network-clean.php` | 43253 | Preservado |
| `includes/network-inventory.php` | 7556 | Preservado |
| `includes/network-invite-template.php` | 2475 | Preservado |
| `includes/network-register-modal.php` | 12139 | Preservado |
| `includes/releases.php` | 13627 | Preservado |
| `includes/sidebar.php` | 4802 | Preservado |
| `includes/storage-policy.php` | 10451 | Preservado |
| `includes/support.php` | 76255 | Preservado |
| `index.php` | 40635 | Preservado |
| `maintenance/billing-renewals.php` | 2697 | Preservado |
| `maintenance/storage-retention.php` | 1419 | Modificado |
| `maintenance/telemetry-retention.php` | 1928 | Preservado |
| `pages/assinatura.php` | 5851 | Preservado |
| `pages/atendimentos.php` | 5552 | Preservado |
| `pages/atividades.php` | 3463 | Preservado |
| `pages/checkout.php` | 9167 | Preservado |
| `pages/cobrancas.php` | 6501 | Preservado |
| `pages/install.php` | 27669 | Preservado |
| `pages/painel.php` | 66048 | Preservado |
| `pages/perfil.php` | 39921 | Preservado |
| `pages/preferencias.php` | 5354 | Preservado |
| `pages/renovar-senha.php` | 4947 | Preservado |
| `pwa/app.php` | 325 | Preservado |
| `redes/.htaccess` | 145 | Preservado |
| `redes/_base.php` | 28833 | Preservado |
| `redes/_clean_ui.php` | 21423 | Modificado |
| `redes/_clean_ui_js.php` | 62598 | Modificado |
| `redes/_clean_ui_js_extra.php` | 18088 | Preservado |
| `redes/_menu.php` | 3956 | Preservado |
| `redes/_rodape.php` | 99896 | Preservado |
| `redes/_support_workspace.php` | 109426 | Preservado |
| `redes/_topo.php` | 459 | Preservado |
| `redes/_topo_config.php` | 153 | Preservado |
| `redes/agenda.php` | 262 | Preservado |
| `redes/arquivos.php` | 262 | Preservado |
| `redes/cadastros.php` | 13344 | Preservado |
| `redes/chamados.php` | 264 | Preservado |
| `redes/clean.php` | 1137 | Modificado |
| `redes/dispositivos.php` | 26654 | Preservado |
| `redes/liberacao.php` | 44876 | Preservado |
| `redes/mapa.php` | 39696 | Preservado |
| `redes/painel.php` | 15196 | Preservado |
| `redes/permissoes.php` | 9406 | Preservado |
| `redes/preferencias.php` | 6045 | Preservado |
| `redes/relatorios.php` | 14152 | Preservado |
| `redes/remoto.php` | 259 | Preservado |
| `redes/servidor.php` | 27773 | Preservado |
| `redes/suporte.php` | 262 | Preservado |
| `redes/usuarios.php` | 16333 | Preservado |
| `redes/velocidade.php` | 55584 | Preservado |
| `sw.js` | 2064 | Preservado |
