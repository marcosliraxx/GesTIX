<!-- Caminho: agentes/servidor/Arcoll.WinUI3/STATUS_MIGRACAO.md -->
# Situação da migração do agente Windows

## 5.0.0 — integração nativa adicional (entrega para homologação, 06/11/2026)

- Mantidas sem modificações as páginas `ServerPage.cs` e `MonitorPage.cs`.
- Novo `WindowsUpdateService.cs` (API COM) e `WindowsUpdatePage.cs`, com verificação, histórico, escolha de atualizações, confirmação e retorno remoto por trechos.
- Novo `FileBrowserService.cs`: listagem de arquivos e pastas, sem transferência nem execução de arquivos. Comandos `file_browser` e `cloud_folders` conectados.
- Alinhados `VERSION`, versão do `.csproj` e fallback de `AppInfo`, adicionados limites de extração, verificação da versão atualizada e tentativa de rollback em erro de instalação.
- **Pendente:** o Python segue necessário para acesso remoto, backup em nuvem, arquivo/transferência/execução, unidade virtual e Clean avançado. Não está compilado nem homologado em Windows.
- Detalhes: `docs/AUDITORIA-5.0.0.md`.

## 4.0.26 — telas nativas Velocidade, Dispositivos e Mapa (padrão da plataforma web)
- **Velocidade** (`Pages/SpeedPage.cs`): mesmo fluxo de `redes/velocidade.php` — botão circular START com download ao vivo, progresso, diagnóstico (Download, Upload, Latência e Perdas) e nota. O resultado é publicado na plataforma (evento `speed_test`) e as **perdas de pacotes são medidas de verdade** (12 pings ao gateway). Último resultado preservado ao trocar de aba; "Executar novamente".
- **Dispositivos** (`Pages/DevicesPage.cs`): mesma estrutura de `redes/dispositivos.php` — busca, filtros (situação, tipo, grupo, ordenação), tabela ordenável (Nº, Dispositivo, IP, MAC, Tipo, Grupo, Situação, Há quanto tempo, Última atividade), seleção e ações em massa (fixar, desafixar, alterar grupo, excluir da lista), exportação CSV (Documentos\TI-Digital, separador ";", BOM e proteção contra fórmulas) e varredura. Clique na linha abre **Identificar dispositivo** (`Pages/DeviceEditor.cs`: nome, tipo, grupo, fixar, excluir da lista) — gravado no mesmo inventário do painel web.
- **Mapa** (`Pages/MapPage.cs`): topologia como `redes/mapa.php` — rede no topo (tocar executa a varredura), ramos por grupo, equipamentos online/offline com ícone por tipo, marca de agente instalado, legenda e a mesma janela de identificação.
- **Plataforma:** novas ações `agent-devices` e `agent-device-save` em `api/api.php`, autenticadas pela identidade do agente (impressão digital, código e chave), com a mesma validação, limites e escopo (conta/rede/agente) de `network-devices` / `network-device-save`. O painel web não muda.
- **Consolidação:** removidos os painéis genéricos antigos (lista de vizinhos, painel de velocidade, adaptadores) e `AgentClient.ScanAsync/NeighborsAsync`; varredura e velocidade sob demanda passam por `AgentRuntime.ScanNowAsync/SpeedNowAsync`, o mesmo caminho dos comandos remotos.
- **Fora desta etapa:** mapa da conexão com tiles (provedora → rede) da página de velocidade, opções do mapa e prévia de impressão A4 da topologia.
- **Validação:** `php -l` em `api/api.php`; serviços C# compilados sem erros pelo Roslyn do .NET 8; páginas e `MainWindow` só com análise de sintaxe (exigem Windows App SDK para compilar). Os endpoints novos e a gravação de `speed_test` sem `command_id` não foram exercitados contra o servidor real.

## 4.0.25 — bandeja do sistema, indicador de conexão e notificações nativas
- **Bandeja** (`Services/TrayService.cs`): ícone nativo (Shell_NotifyIcon, janela de mensagens própria, sem portas e sem pacotes extras) com ponto de estado — verde (conectado), amarelo (aguardando/verificando), vermelho (sem conexão autorizada) — e dica com o estado atual. Clique abre a janela; menu: Abrir, Reiniciar agente, Encerrar agente (com confirmação).
- **Notificações:** aviso nativo do Windows a cada mudança de estado de autorização/conexão (sem aviso na verificação inicial).
- **Reinício controlado:** processo auxiliar oculto aguarda o encerramento (instância única) e reabre com `--background`.
- **Validação feita neste ambiente:** PHP (`php -l` em todos os .php) e Python (`py_compile`) sem erros; serviços C# não-UI compilados com o Roslyn do .NET 8 SDK sem erros nem avisos (inclui `TrayService`). A camada WinUI (MainWindow, páginas, ThemeService) só foi analisada sintaticamente — compilação e teste real exigem Windows + Windows App SDK.
- **Observação de auditoria:** `redes/_clean_ui_backup.php` parece legado, mas é incluído por `_clean_ui_js.php`; foi mantido (remoção quebraria o Backup em Nuvem).
- **Continua no núcleo Python:** acesso remoto, backup/nuvem, unidade virtual, explorador de arquivos, Windows Update (scan/install), Clean avançado e topologia editável. Não desinstale o agente Python.

## 4.0.24 — abas, Servidor e Monitoramento completos, atualização remota e instalação profissional
- **Compilação:** corrigido o erro CS1061 (`NavigationView.PaneBackground` não existe) que derrubava o XamlCompiler (MSB3073, código 1). Era uma regressão da 4.0.23.
- **Menu em abas** (NavigationView no topo): Servidor, Monitoramento, Velocidade, Dispositivos, Ferramentas, Mapa, Conexão, Clean, Programas, Aparência.
- **Servidor** (`Pages/ServerPage.cs`): mesma estrutura de `redes/servidor.php` — lista lateral de componentes (CPU, Memória, cada disco, Rede, Internet), 4 indicadores, gráfico de 60 amostras com dica ao passar o mouse e nota.
- **Monitoramento** (`Pages/MonitorPage.cs`): mesma tela do agente 3.10 — selo contínuo/pausado, indicadores (Latência, Perda, Dispositivos, Estado), gráfico em linhas/área/barras com séries selecionáveis, varredura e Relatório (CSV em Documentos\TI-Digital). Dados reais de `Services/TelemetryHub.cs` (amostra a cada 2 s; ICMP a cada 3 s).
- **Atualização remota e forçada:** o comando `agent_update` da plataforma (botões Atualizar / Atualizar forçado) é executado por `Services/UpdateService.cs`: baixa `download-agent.php?mode=native-update` (autenticado por impressão digital, código e chave), confere o SHA-256, extrai e entrega a troca a `INSTALAR_WINUI3.ps1 -Update` (`-ForceRebuild` na forçada: build limpo). O comando só é confirmado depois que o agente atualizado reabre. Sem mudança de versão, a atualização normal informa "já está na versão mais recente".
- **Instalação profissional:** instalador visual com barra de progresso em porcentagem; atalhos na Área de Trabalho e no Menu Iniciar; tarefa agendada por usuário (ao entrar no Windows, reinício automático) com `--background`; instância única — o atalho abre a janela do agente já em execução; fechar a janela mantém o agente em segundo plano; "Encerrar agente" no rodapé finaliza o processo.
- **Limites conhecidos:** não compilado neste ambiente (sem .NET; a sintaxe C# foi verificada por analisador). Sem ícone na bandeja (reabrir pelo atalho). Ainda no núcleo Python: acesso remoto, backup/nuvem, unidade virtual, explorador de arquivos, Windows Update (scan/install), Clean avançado, topologia editável. Não desinstale o agente Python.

## 4.0.22 — fase 4: toolkit, comandos rápidos e diagnóstico de conexão nativos

**Novo em C# (`ToolkitService`):**
- `toolkit` e `toolkit_traceroute|services|health|troubleshooter`: mesmas medições do agente antigo (PowerShell/netsh/tracert fixos), enviadas como evento `toolkit`.
- Comandos rápidos: `storage_health`, `service_health`, `restart_readiness`, `quick_health`, `dns_test`, `public_ip`, `system_snapshot`, `flush_dns`, `wifi_info` (evento `quick_command`).
- `diagnostic`: interface padrão, latências, IP público, provedora (ipwho.is → ipapi.co → ipinfo.io, exigindo o mesmo IP) e rota (evento `diagnostic`).

**Ainda no núcleo Python:** acesso remoto/tela segura, backup/nuvem, unidade virtual, explorador/transferência de arquivos, Windows Update (scan/install/...), atualizador, Clean remoto e avançado, topologia editável, bandeja e serviço pré-login.

## 4.0.21 — fase 3: energia, bloqueio de sessão e terminal de diagnóstico nativos

**Novo em C# (sem Python):**
- `power_shutdown` / `power_restart` / `power_cancel` (`MaintenanceService`): consentimento local (diálogo) ou modo sem monitor com administrador; revalidação em `agent-power-authorize`; execução por `shutdown.exe` com prazo de 5 a 3600 s.
- `screen_lock`: espera o prazo, revalida (`agent-command-authorize`) no modo sem monitor e chama `LockWorkStation`.
- `windows_update` com `advanced`/`insider`: abre as configurações do Windows. Os demais modos (scan/install/history/pause/resume/settings/latest) seguem no núcleo Python.
- `terminal_diagnostic` (`DiagnosticCommands`): mesma lista fechada do agente antigo (ping, tracert, nslookup, ipconfig, arp, route print, netsh wlan, hostname), sem cmd.exe/PowerShell; saída em trechos confirmados por offset (`agent-command-output`), limite de 30 mil caracteres e 80 s.
- Diálogo de consentimento único (`AgentRuntime.Consent`) ligado à janela principal.

**Continua no núcleo Python:** acesso remoto/tela segura, backup/nuvem, unidade virtual, explorador/transferência de arquivos, toolkit remoto, Windows Update (scan/install/...), atualizador, Clean avançado, topologia editável, diagnóstico de provedora. Não desinstale o agente Python.

**Validação:** não compilado (sem .NET neste ambiente). Compile em Windows com .NET 8 antes de publicar.

## 4.0.16 — fase 2: protocolo da plataforma e funções do agente antigo (3.10.x)

**Implementado em C# (sem Python):**
- **Autorização** (`AuthorizationService`): `agent-poll` e `agent-register` (cadastro PENDENTE por chave válida; só o gestor aprova). Estados pendente/bloqueado/rejeitado/desinstalação bloqueiam a interface (falha fechada); queda de rede após a 1ª aprovação não bloqueia.
- **Fila de comandos** (`AgentRuntime`): `agent-command-poll/ack` a cada 3 s. Nativos: `monitor_start`, `monitor_pause`, `scan_devices`, `scan_map`, `speed_test` (com `speed_progress`), `program_inventory` (exige modo sem monitor + revalidação `agent-command-authorize`). Os demais comandos são confirmados como *failed* com mensagem clara, em vez de ficarem presos em *running*.
- **Telemetria do servidor** (`SystemMetrics`): CPU, memória, discos e rede enviados a cada 5 s (`server_metrics`), no formato plano aceito por `redes/servidor.php`.
- **Telas:** Monitoramento ao vivo; Velocidade (Cloudflare, mesmos volumes do agente antigo); Clean (análise + limpeza com confirmação, só temporários com mais de 7 dias, sem seguir links simbólicos); Programas com desinstalação pelo desinstalador oficial, após confirmação.
- **Consolidação:** `AgentConfig` é a fonte única da configuração (antes duplicada em Presence e Theme); `PlatformClient` concentra todo o tráfego da API; corrigido o uso de memória do GC no lugar da memória do sistema; versão do `.csproj` alinhada ao `VERSION`.

**Ainda no núcleo Python (não desinstalar):** acesso remoto/consentimento, tela segura, backup/nuvem, unidade virtual, explorador/transferência de arquivos, terminal e toolkit remotos, energia, Windows Update, atualizador, Clean avançado (duplicados, inicialização, drivers) e topologia editável.

**Validação:** o código **não foi compilado** neste ambiente Linux (sem .NET). Compile em Windows com .NET 8 antes de publicar.

## 4.0.15 — fase 1: motor de rede e presença nativos (sem Python)

Migrado de `arcoll_agent.py` para C#: IP/gateway/sub-rede, tabela ARP, varredura ICMP+ARP com nomes (DNS reverso e NetBIOS), classificação de dispositivos, ping, DNS, portas de serviço (somente sub-rede local), Wake-on-LAN e presença `agent-presence` (a cada 15 s). A interface não usa mais `127.0.0.1:8787` e não abre nenhuma porta. Nova tela **Ferramentas**.

**Pendente (fases seguintes):** serviço Windows pré-login (a presença hoje roda com a janela aberta), acesso remoto (`arcoll_remote.py`), Clean (`arcoll_clean.py`), desinstalação de programas (`arcoll_programs.py`), helper privilegiado, nuvem/backup, unidade virtual, teste de velocidade, atualizador e topologia. Enquanto isso, o agente Python não deve ser desinstalado.

## 4.0.14 — correção da falha de instalação (MSB3073 / XamlCompiler código 1)

**Causa:** o `App.xaml` era declarado como `ApplicationDefinition` no `.csproj` e também incluído automaticamente pelo SDK do Windows App SDK. O `input.json` do compilador XAML listava o arquivo duas vezes e o `XamlCompiler.exe` encerrava com código 1, sem mensagem.

**Correção:** o `.csproj` deixou de declarar `ApplicationDefinition` e `Page`; foram adicionados `EnableMsixTooling` (recursos `.pri` para app sem pacote) e `SelfContained` (restauração coerente com a publicação `--no-restore`). O instalador valida duplicidade antes de compilar, usa mensagens em inglês/UTF-8 nos logs, imprime o `output.json` do compilador XAML em caso de falha e remove a pasta temporária de publicação.

## Estado funcional

A interface faz inventário local somente leitura e consulta o agente Python instalado (health e vizinhos). Ainda depende do núcleo Python para acesso remoto, limpeza, desinstalação, mapa editável e atualização; portanto **não substitui integralmente o agente anterior**. A compilação deve ser validada em Windows com .NET 8.
