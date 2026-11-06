// Caminho: agentes/windows/Arcoll.WinUI3/Services/AgentRuntime.cs
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Ciclo de vida do agente perante a plataforma: autorização (agent-poll/agent-register), fila de comandos
/// (agent-command-poll/ack), telemetria do servidor (agent-data) e presença. Substitui o laço do agente Python.
/// </summary>
public sealed class AgentRuntime : IDisposable
{
    /// <summary>Comandos implementados diretamente no agente WinUI 3/C#, sem recorrer ao núcleo Python.</summary>
    private static readonly HashSet<string> Native = new() { "monitor_start", "monitor_pause", "remote_assistance", "scan_devices", "scan_map", "speed_test", "program_inventory", "program_install", "clean_scan", "clean_execute", "clean_tool",
        "power_shutdown", "power_restart", "power_cancel", "screen_lock", "terminal_diagnostic", "windows_update",
        "diagnostic", "agent_update", "toolkit", "toolkit_traceroute", "toolkit_services", "toolkit_health", "toolkit_troubleshooter",
        "storage_health", "service_health", "restart_readiness", "file_browser", "cloud_folders", "quick_health", "dns_test", "public_ip", "system_snapshot", "flush_dns", "wifi_info" };

    private readonly PlatformClient _platform = new(AgentConfig.Load());
    private readonly AuthorizationService _auth;
    private readonly SystemMetrics _metrics = new();
    private readonly CancellationTokenSource _cts = new();
    private readonly List<double> _latencies = new();
    private Task? _loop;
    private DateTime _lastAuth = DateTime.MinValue, _lastMetrics = DateTime.MinValue, _lastNetworkScan = DateTime.MinValue;
    private bool _busy;
    private RemoteScreenService? _remoteScreen;

    public AgentRuntime() { _auth = new AuthorizationService(_platform); }

    public PlatformClient Platform => _platform;
    public bool MonitorEnabled { get; private set; } = true;
    public AuthState State => _auth.State;
    public bool Gated => _auth.Gated;
    public bool Allowed => _auth.Allowed;
    public Task RecheckAsync() { _lastAuth = DateTime.MinValue; return Task.CompletedTask; }
    public string Detail => _auth.Detail;
    public string StateNotice => AuthorizationService.Notice(_auth.State, _auth.Detail);
    public string NetworkName => _auth.NetworkName;
    public string SystemName => _platform.Config.SystemName;
    /// <summary>Consentimento local (diálogo da interface) exigido quando o pedido não é do modo sem monitor com administrador.</summary>
    public Func<string, Task<bool>>? Consent { get; set; }
    public MetricSample? LastSample { get; private set; }
    public string LastActivity { get; private set; } = "";
    /// <summary>Disparado fora da thread de interface; a janela deve usar o dispatcher.</summary>
    public event Action? Changed;
    public event Action<string>? ProgramActivity;
    /// <summary>Pede à janela principal que encerre o processo (usado pela atualização, que reabre o agente em seguida).</summary>
    public Action? RequestExit { get; set; }
    private bool _updateChecked;

    public void Start() { if (_loop == null) _loop = Task.Run(() => RunAsync(_cts.Token)); }

    private async Task RunAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                if (DateTime.UtcNow - _lastAuth > TimeSpan.FromSeconds(State == AuthState.Approved || State == AuthState.UiLocked ? 30 : 10))
                {
                    var before = _auth.State; await _auth.RefreshAsync(ct); _lastAuth = DateTime.UtcNow;
                    if (before != _auth.State) RuntimeLog.Write("Autorização: " + before + " → " + _auth.State + (_auth.Detail.Length > 0 ? " (" + _auth.Detail + ")" : ""));
                    // Recibo local de prontidao: o instalador so confirma a substituicao
                    // quando esta instancia nova obteve aprovacao real via HTTPS.
                    if (_auth.Allowed)
                    {
                        try
                        {
                            var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital");
                            Directory.CreateDirectory(dir);
                            File.WriteAllText(Path.Combine(dir, "native-ready.json"), JsonSerializer.Serialize(new
                            {
                                pid = Environment.ProcessId, version = AppInfo.Version,
                                fingerprint = _platform.Config.Fingerprint,
                                approved_at = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
                            }));
                        }
                        catch (IOException ex) { RuntimeLog.Write("Nao foi possivel registrar a prontidao: " + ex.Message); }
                        catch (UnauthorizedAccessException ex) { RuntimeLog.Write("Sem acesso ao arquivo de prontidao: " + ex.Message); }
                    }
                    Changed?.Invoke();
                }
                if (_auth.Allowed)
                {
                    if (!_updateChecked)
                    {
                        await UpdateService.CompletePendingAsync(_platform, ct);
                        // O instalador pode confirmar depois da primeira autenticação.
                        // Nunca pare de verificar enquanto houver recibo pendente.
                        _updateChecked = !File.Exists(UpdateService.PendingFile);
                    }
                    await PollCommandAsync(ct);
                    if (MonitorEnabled && DateTime.UtcNow - _lastMetrics > TimeSpan.FromSeconds(5)) await SendMetricsAsync(ct);
                    // Inventário de rede: varredura controlada, não uma consulta por página ou por segundo.
                    // Executa apenas quando não há comando em processamento.
                    if (MonitorEnabled && !_busy && DateTime.UtcNow - _lastNetworkScan > TimeSpan.FromMinutes(3))
                    {
                        _lastNetworkScan = DateTime.UtcNow;
                        try { await ScanNowAsync(ct, false); }
                        catch (Exception ex) when (!ct.IsCancellationRequested) { RuntimeLog.Write("Inventário de rede: " + ex.Message); }
                    }
                }
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { break; }
            catch (Exception ex)
            {
                // O laço nunca pode morrer: qualquer falha é registrada e tentada de novo no próximo ciclo.
                LastActivity = "Sem comunicação com a plataforma: " + (ex is TaskCanceledException ? "tempo esgotado" : ex.Message);
                RuntimeLog.Write("Ciclo falhou: " + ex.GetType().Name + ": " + ex.Message); Changed?.Invoke();
            }
            try { await Task.Delay(TimeSpan.FromSeconds(3), ct); } catch (OperationCanceledException) { break; }
        }
    }

    private async Task SendMetricsAsync(CancellationToken ct)
    {
        _lastMetrics = DateTime.UtcNow;
        var sample = _metrics.Sample(); LastSample = sample;
        var gateway = NetworkEngine.Local().Gateway;
        var latency = await NetworkEngine.PingAsync(string.IsNullOrWhiteSpace(gateway) ? "1.1.1.1" : gateway, 1200);
        _latencies.Add(latency is null ? 1 : 0); if (_latencies.Count > 20) _latencies.RemoveAt(0);
        await _platform.SendEventAsync(SystemMetrics.ToPlatformPayload(sample, latency, _latencies.Average() * 100), ct, 10);
        Changed?.Invoke();
    }

    private async Task PollCommandAsync(CancellationToken ct)
    {
        if (_busy) return;
        var body = _platform.Config.Credentials(); body["desktop_state"] = "unlocked";
        var response = await _platform.PostAsync("agent-command-poll", body, ct, 10);
        if (!response.TryGetProperty("command", out var cmd) || cmd.ValueKind != JsonValueKind.Object) return;
        _busy = true;
        try { await ExecuteAsync(cmd, ct); } finally { _busy = false; }
    }

    private async Task ExecuteAsync(JsonElement cmd, CancellationToken ct)
    {
        var id = cmd.TryGetProperty("id", out var i) && i.TryGetInt64(out var n) ? n : 0;
        var name = cmd.TryGetProperty("command_name", out var c) && c.ValueKind == JsonValueKind.String ? c.GetString() ?? "" : "";
        if (id < 1 || name.Length == 0) return;
        LastActivity = "Solicitação da plataforma: " + name.Replace('_', ' '); Changed?.Invoke();
        using var operation = name == "agent_update" ? null : OperationProgress.Begin(OperationProgress.Title(name), "Remoto");
        if (!Native.Contains(name))
        {
            await _platform.AckAsync(id, "failed", "Recurso ainda não disponível no agente nativo; mantenha o agente Python instalado para esta função.", ct);
            return;
        }
        try
        {
            operation?.Report(15, "Comando recebido e autorizado; executando");
            switch (name)
            {
                case "remote_assistance": await RemoteAssistanceAsync(id, cmd, ct); break;
                case "monitor_start": MonitorEnabled = true; _lastMetrics = DateTime.MinValue; await _platform.AckAsync(id, "completed", "Monitoramento iniciado", ct); break;
                case "monitor_pause": MonitorEnabled = false; await _platform.AckAsync(id, "completed", "Monitoramento pausado", ct); break;
                case "scan_devices": case "scan_map": await ScanAsync(id, ct); break;
                case "speed_test": await SpeedAsync(id, ct); break;
                case "program_inventory": await InventoryAsync(id, cmd, ct); break;
                case "program_install": await InstallProgramsAsync(id, cmd, ct); break;
                case "clean_scan": case "clean_execute": case "clean_tool": await CleanRemoteAsync(id, name, cmd, ct); break;
                case "file_browser": case "cloud_folders": await BrowseAsync(id, name, cmd, ct); break;
                case "power_shutdown": case "power_restart": case "power_cancel": await PowerAsync(id, name, cmd, ct); break;
                case "screen_lock": await MaintenanceAsync(id, name, cmd, ct); break;
                case "windows_update": await WindowsUpdateAsync(id, cmd, ct); break;
                case "terminal_diagnostic": await TerminalAsync(id, cmd, ct); break;
                case "agent_update": await UpdateAsync(id, cmd, ct); break;
                case "diagnostic": await ConnectionAsync(id, ct); break;
                case string t when t.StartsWith("toolkit", StringComparison.Ordinal): await ToolkitAsync(id, t, ct); break;
                case string q when ToolkitService.QuickTitles.ContainsKey(q): await QuickAsync(id, q, ct); break;
            }
            operation?.Succeed();
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            operation?.Fail(ex.Message);
            RuntimeLog.Write("Comando remoto #" + id + " (" + name + ") falhou: " + ErrorDiagnostic.FromException(ex));
            try { await _platform.AckFailureAsync(id, ex, CancellationToken.None); } catch (Exception ackEx) { RuntimeLog.Write("Erro ao comunicar falha: " + ackEx.GetType().Name); }
        }
    }

    /// <summary>Inicia a sessão temporária depois da autorização local, sem bloquear a fila inteira.</summary>
    private async Task RemoteAssistanceAsync(long commandId, JsonElement command, CancellationToken ct)
    {
        if (_remoteScreen is { Active: true })
            throw new InvalidOperationException("Já existe uma sessão remota em andamento neste computador.");
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Acesso remoto requer Windows.");
        // Sessão sem monitor é uma política previamente configurada no dispositivo e
        // registrada na plataforma. Nunca basta confiar no role do comando: o agente
        // revalida o vínculo e a sessão com sua própria chave de instalação.
        var unattended = Unattended(command);
        if (unattended)
        {
            var grant = await _platform.PostAsync("agent-remote-authorize",
                _platform.WithCredentials(("command_id", commandId)), ct, 12);
            if (!grant.TryGetProperty("unattended", out var flag) || flag.ValueKind != JsonValueKind.True
                || !grant.TryGetProperty("command", out var kind) || kind.GetString() != "remote_assistance")
                throw new UnauthorizedAccessException("A plataforma não confirmou a autorização sem monitor.");
            RuntimeLog.Write("Acesso remoto sem confirmação local autorizado pela política administrativa: sessão #" + commandId);
            await _platform.AckAsync(commandId, "running", "Sessão administrativa sem monitor validada; iniciando imagem e controle.", ct);
        }
        else
        {
            if (Consent is null)
            {
                await _platform.AckAsync(commandId, "failed", "Não há interface interativa para apresentar a autorização local.", ct);
                return;
            }
            await _platform.AckAsync(commandId, "running", "Aguardando autorização na janela do agente Windows.", ct);
            bool authorized;
            try
            {
                authorized = await Consent("O administrador da rede solicita acesso à tela e ao mouse/teclado deste computador. " +
                    "A sessão será visível e poderá ser encerrada localmente. Autorizar por até 30 minutos?")
                    .WaitAsync(TimeSpan.FromSeconds(90), ct);
            }
            catch (TimeoutException)
            {
                await _platform.AckAsync(commandId, "failed", "A autorização local não foi respondida em 90 segundos.", CancellationToken.None);
                return;
            }
            if (!authorized)
            {
                await _platform.AckAsync(commandId, "failed", "O acesso remoto foi recusado ou o diálogo de autorização falhou no Windows.", ct);
                return;
            }
        }
        var session = new RemoteScreenService(_platform.Config, commandId);
        _remoteScreen = session;
        await _platform.AckAsync(commandId, "running", unattended ? "Sessão sem monitor autorizada; iniciando captura." : "Sessão remota autorizada localmente; iniciando a captura da tela.", ct);
        _ = Task.Run(async () =>
        {
            try
            {
                await session.RunAsync(_cts.Token);
                await _platform.AckAsync(commandId, "completed", "Sessão remota encerrada.", CancellationToken.None);
            }
            catch (Exception ex)
            {
                RuntimeLog.Write("Acesso remoto #" + commandId + ": " + ErrorDiagnostic.FromException(ex));
                try { await _platform.AckFailureAsync(commandId, ex, CancellationToken.None); }
                catch (Exception logEx) { RuntimeLog.Write("Diagnóstico remoto indisponível: " + logEx.Message); }
            }
            finally
            {
                session.Dispose();
                if (ReferenceEquals(_remoteScreen, session)) _remoteScreen = null;
                Changed?.Invoke();
            }
        }, CancellationToken.None);
    }

    /// <summary>Instala somente IDs do catálogo local assinado pelo fluxo de distribuição.
    /// Nunca aceita URLs ou comandos executáveis enviados pelo navegador.</summary>
    private async Task InstallProgramsAsync(long id, JsonElement cmd, CancellationToken ct)
    {
        if (!cmd.TryGetProperty("request_text", out var raw) || raw.ValueKind != JsonValueKind.String)
            throw new InvalidOperationException("A plataforma não enviou a seleção dos programas.");
        using var doc = JsonDocument.Parse(raw.GetString() ?? "{}");
        if (!doc.RootElement.TryGetProperty("ids", out var list) || list.ValueKind != JsonValueKind.Array)
            throw new InvalidOperationException("Seleção de aplicativos inválida.");
        var ids = list.EnumerateArray().Select(x => x.ValueKind == JsonValueKind.String ? x.GetString() ?? "" : "").ToArray();
        if (ids.Length < 1 || ids.Length > 20 || ids.Any(string.IsNullOrWhiteSpace) || ids.Distinct(StringComparer.OrdinalIgnoreCase).Count() != ids.Length)
            throw new InvalidOperationException("Selecione de 1 a 20 programas, sem repetição.");
        var catalog = ProgramService.Catalog();
        if (catalog.Count == 0) throw new InvalidOperationException("Catálogo local não encontrado. Reinstale o agente atualizado.");
        var results = new List<object>();
        var failures = new List<string>();
        for (var i = 0; i < ids.Length; i++)
        {
            ct.ThrowIfCancellationRequested();
            var package = ids[i];
            var title = catalog.FirstOrDefault(x => x.Id.Equals(package, StringComparison.OrdinalIgnoreCase))?.Name
                ?? throw new InvalidOperationException("Programa fora do catálogo autorizado: " + package);
            var stage = $"{i + 1}/{ids.Length}: {title}";
            await _platform.AckAsync(id, "running", stage, ct);
            try
            {
                var code = await ProgramService.PackageAsync(package, false, catalog, ct);
                if (!ProgramService.SuccessfulExit(code)) throw new InvalidOperationException("WinGet retornou código " + code);
                results.Add(new { s = "Concluído" });
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                failures.Add(title + ": " + ex.Message);
                results.Add(new { s = "Falha" });
                RuntimeLog.Write("Instalação " + title + ": " + ErrorDiagnostic.FromException(ex));
            }
            // O progresso é real por aplicativo concluído; a interface faz o detalhamento por item.
            var packet = "@@ARP@@" + JsonSerializer.Serialize(new { i = i + 1, t = ids.Length, c = title,
                s = failures.Count > 0 ? "Falhas: " + failures.Count : "Concluído", r = results.Take(12),
                m = failures.Count == 0 ? "Instalando programas" : "Falhas: " + failures.Count });
            await _platform.AckAsync(id, "running", packet, ct);
        }
        if (failures.Count > 0) throw new InvalidOperationException(
            $"{ids.Length - failures.Count}/{ids.Length} programas concluídos. " + string.Join("; ", failures));
        await _platform.AckAsync(id, "completed", $"{ids.Length} programa(s) instalado(s) com êxito pelo WinGet.", ct);
    }

    /// <summary>Executa a limpeza somente após requisição explícita e registra o relatório antes de concluir a fila.</summary>
    private async Task CleanRemoteAsync(long id, string name, JsonElement cmd, CancellationToken ct)
    {
        if (!cmd.TryGetProperty("request_text", out var raw) || raw.ValueKind != JsonValueKind.String)
            throw new InvalidOperationException("Requisição Clean não encontrada.");
        using var doc = JsonDocument.Parse(raw.GetString() ?? "{}");
        await _platform.AckAsync(id, "running", "Executando " + name, ct);
        var result = await NativeCleanCommands.RunAsync(name, doc.RootElement, _platform, id, ct);
        await _platform.AckAsync(id, "completed", result, ct);
    }

    private async Task ScanAsync(long id, CancellationToken ct)
    {
        var count = await ScanNowAsync(ct);
        await _platform.AckAsync(id, "completed", $"{count} dispositivos sincronizados", ct);
    }

    /// <summary>Varredura da sub-rede e sincronização do inventário na plataforma (comando remoto e botão da interface usam o mesmo caminho).</summary>
    public async Task<int> ScanNowAsync(CancellationToken ct, bool notify = true)
    {
        using var operation = notify ? OperationProgress.Begin("Examinar dispositivos") : null;
        operation?.Report(15, "Examinando a rede local");
        try
        {
        var rows = await NetworkEngine.ScanAsync(null, true, ct);
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        var devices = rows.Take(180).Select(d => new Dictionary<string, object?>
        {
            ["name"] = d.Name, ["ip"] = d.Ip, ["mac"] = d.Mac, ["type"] = d.Kind, ["status"] = "online", ["group"] = "Rede local",
            ["first_seen"] = now, ["last_seen"] = now, ["last_change"] = now, ["source"] = "Agente Windows", ["latency_ms"] = d.LatencyMs,
            ["agent_installed"] = d.Ip == NetworkEngine.Local().Ip,
        }).ToList();
        await _platform.SendEventAsync(new Dictionary<string, object?> { ["event"] = "network_scan", ["device_count"] = devices.Count, ["devices"] = devices, ["groups"] = new List<object>() }, ct, 20);
        operation?.Succeed("Inventário sincronizado");
        return devices.Count;
        } catch (Exception ex) { operation?.Fail(ex.Message); throw; }
    }

    private async Task SpeedAsync(long id, CancellationToken ct)
    {
        var last = DateTime.MinValue;
        var progress = new Progress<(double Percent, string Message, double? Download)>(p =>
        {
            if (DateTime.UtcNow - last < TimeSpan.FromSeconds(1) && p.Percent < 100) return; last = DateTime.UtcNow;
            _ = _platform.SendEventAsync(new Dictionary<string, object?> { ["event"] = "speed_progress", ["command_id"] = id, ["percent"] = Math.Round(p.Percent, 1), ["message"] = p.Message, ["download_mbps"] = p.Download }, CancellationToken.None, 8);
        });
        var r = await InternetNowAsync(progress, ct, id);
        await _platform.AckAsync(id, "completed", $"Download {r.Speed.Download} Mbps · Upload {r.Speed.Upload} Mbps · conexão diagnosticada", ct);
    }

    public sealed record InternetResult(SpeedResult Speed, double Loss, int Sent, JsonElement Connection, string SyncWarning);

    /// <summary>Um START mede banda, perdas e conexão, e publica os dois relatórios do painel Internet.</summary>
    public async Task<InternetResult> InternetNowAsync(IProgress<(double Percent, string Message, double? Download)> progress, CancellationToken ct, long commandId = 0)
    {
        using var operation = OperationProgress.Begin("Testar Internet");
        try
        {
        var next = progress;
        progress = new Progress<(double Percent, string Message, double? Download)>(value => { operation.Report((int)value.Percent, value.Message); next.Report(value); });
        if (!Allowed) throw new UnauthorizedAccessException("Agente sem autorização para medir a conexão.");
        var speed = await SpeedTestService.RunAsync(progress, ct);
        progress.Report((85, "Medindo perdas de pacotes…", speed.Download));
        const int sent = 12; var lost = 0;
        for (var i = 0; i < sent; i++)
        {
            ct.ThrowIfCancellationRequested();
            if (await NetworkEngine.PingAsync("1.1.1.1", 700) is null) lost++;
            await Task.Delay(120, ct);
        }
        var loss = Math.Round(lost * 100d / sent, 1);
        progress.Report((92, "Identificando conexão, DNS, provedora e rota…", speed.Download));
        Dictionary<string, object?> details;
        try { details = await ToolkitService.ConnectionAsync(ct); }
        catch (Exception ex) when (ex is not OperationCanceledException)
        { details = new() { ["event"] = "diagnostic", ["schema"] = 1, ["interface"] = new { ip = NetworkEngine.Local().Ip, gateway = NetworkEngine.Local().Gateway }, ["errors"] = new[] { ex.Message } }; }
        var warning = "";
        try
        {
            await _platform.SendEventAsync(new Dictionary<string, object?> { ["event"] = "speed_test", ["command_id"] = commandId,
                ["download_mbps"] = speed.Download, ["upload_mbps"] = speed.Upload, ["latency_ms"] = speed.LatencyMs,
                ["packet_loss_pct"] = loss, ["packets_sent"] = sent, ["server"] = speed.Server }, ct, 16);
            await _platform.SendEventAsync(details, ct, 20);
        }
        catch (Exception ex) when (ex is HttpRequestException or PlatformException or TaskCanceledException)
        { warning = "Resultado local preservado; sincronização pendente: " + ex.Message; }
        progress.Report((100, "Medição e diagnóstico concluídos.", speed.Download));
        operation.Succeed("Medição e diagnóstico aplicados");
        return new InternetResult(speed, loss, sent, JsonSerializer.SerializeToElement(details), warning);
        } catch (Exception ex) { operation.Fail(ex.Message); throw; }
    }

    private async Task InventoryAsync(long id, JsonElement cmd, CancellationToken ct)
    {
        // Mesma regra do agente Python: exige modo sem monitor com administrador e revalida a autorização no servidor.
        var unattended = cmd.TryGetProperty("headless_mode", out var h) && (h.ValueKind == JsonValueKind.True || (h.ValueKind == JsonValueKind.Number && h.GetInt32() == 1) || (h.ValueKind == JsonValueKind.String && h.GetString() == "1"))
            && cmd.TryGetProperty("requester_role", out var role) && role.ValueKind == JsonValueKind.String && role.GetString() is "owner" or "administrator";
        if (!unattended) throw new InvalidOperationException("Modo sem monitor e administrador necessários.");
        var verified = await _platform.PostAsync("agent-command-authorize", _platform.WithCredentials(("command_id", id)), ct, 12);
        if (!verified.TryGetProperty("command", out var vc) || vc.GetString() != "program_inventory") throw new InvalidOperationException("Autorização revogada.");
        ProgramActivity?.Invoke("Solicitação remota: lendo programas instalados…");
        var programs = await Task.Run(() => ProgramService.InventoryPayload(WindowsInventory.Programs()),ct);
        await _platform.PostAsync("agent-program-inventory", _platform.WithCredentials(("command_id", id), ("programs", programs)), ct, 40);
        await _platform.AckAsync(id, "completed", $"{programs.Count} programas inventariados", ct);
        ProgramActivity?.Invoke($"Solicitação remota concluída: {programs.Count} programas enviados à plataforma.");
    }

    /// <summary>Inventário de unidades/pastas para o explorador e seletor do backup em nuvem, sem transferência de conteúdo.</summary>
    private async Task BrowseAsync(long id, string name, JsonElement command, CancellationToken ct)
    {
        if (Str(command, "requester_role") is not ("owner" or "administrator"))
            throw new UnauthorizedAccessException("Somente o gestor ou administrador pode consultar as pastas remotas.");
        if (name == "file_browser" && !Headless(command))
            throw new UnauthorizedAccessException("O explorador remoto exige modo sem monitor autorizado.");
        var request = Str(command, "request_text");
        if (Unattended(command))
        {
            var check = await _platform.PostAsync("agent-command-authorize", _platform.WithCredentials(("command_id", id)), ct, 12);
            if (!check.TryGetProperty("command", out var authorized) || authorized.GetString() != name)
                throw new UnauthorizedAccessException("Autorização de consulta de pastas revogada.");
            // Os caminhos do explorador são armazenados em file_browser_requests, não em agent_terminal_jobs.
            if (check.TryGetProperty("request_text", out var value) && value.ValueKind == JsonValueKind.String && !string.IsNullOrEmpty(value.GetString()))
                request = value.GetString()!;
        }
        var entries = await FileBrowserService.BrowseAsync(request, name == "cloud_folders", ct);
        entries["command_id"] = id;
        var answer = await _platform.PostAsync("agent-file-browser-result", _platform.WithCredentials(("command_id", id), ("entries", entries["entries"]),
            ("path", entries["path"]), ("truncated", entries["truncated"])), ct, 15);
        if (!answer.TryGetProperty("saved", out var saved) || saved.ValueKind != JsonValueKind.True)
            throw new InvalidOperationException("A plataforma não aceitou a listagem; solicitação expirada.");
        await _platform.AckAsync(id, "completed", "Pasta consultada (até 150 entradas, sem ler conteúdo de arquivos).", ct);
    }

    private static string Str(JsonElement cmd, string key) => cmd.TryGetProperty(key, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "";
    private static bool Headless(JsonElement cmd) => cmd.TryGetProperty("headless_mode", out var h) && (h.ValueKind == JsonValueKind.True || (h.ValueKind == JsonValueKind.Number && h.GetInt32() == 1) || (h.ValueKind == JsonValueKind.String && h.GetString() == "1"));
    private static bool Unattended(JsonElement cmd) => Headless(cmd) && Str(cmd, "requester_role") is "owner" or "administrator";

    private async Task<bool> AskAsync(string message)
    {
        var ask = Consent;
        return ask is not null && await ask(message);
    }

    private async Task PowerAsync(long id, string name, JsonElement cmd, CancellationToken ct)
    {
        var unattended = Unattended(cmd);
        if (name != "power_cancel" && !unattended
            && !await AskAsync("O gestor solicitou " + (name == "power_shutdown" ? "desligar" : "reiniciar") + " este computador. Salve seu trabalho. Deseja autorizar? A operação será agendada conforme o prazo selecionado."))
        { await _platform.AckAsync(id, "failed", "Operação não autorizada no computador.", ct); return; }
        var auth = await _platform.PostAsync("agent-power-authorize", _platform.WithCredentials(("command_id", id)), ct, 8);
        if (!auth.TryGetProperty("command", out var ac) || ac.GetString() != name) throw new InvalidOperationException("Autorização expirada. Solicite novamente.");
        if (Headless(cmd) && unattended && !(auth.TryGetProperty("headless", out var hl) && hl.ValueKind == JsonValueKind.True)) throw new InvalidOperationException("O modo de servidor sem monitor foi desativado. Solicite novamente.");
        var message = await Task.Run(() => MaintenanceService.SchedulePower(name, MaintenanceService.DelaySeconds(Str(cmd, "request_text"))), ct);
        await _platform.AckAsync(id, "completed", message, ct);
    }

    private async Task MaintenanceAsync(long id, string name, JsonElement cmd, CancellationToken ct)
    {
        var request = Str(cmd, "request_text"); var unattended = Unattended(cmd); var mode = MaintenanceService.Mode(request);
        var what = "bloquear esta sessão";
        if (!unattended && !await AskAsync("O gestor solicitou " + what + ". Autorizar?")) { await _platform.AckAsync(id, "failed", "Usuário não autorizou a operação.", ct); return; }
        if (name == "screen_lock")
        {
            await Task.Delay(TimeSpan.FromSeconds(MaintenanceService.DelaySeconds(request)), ct);
            if (unattended)
            {
                var again = await _platform.PostAsync("agent-command-authorize", _platform.WithCredentials(("command_id", id)), ct, 10);
                if (!again.TryGetProperty("command", out var c) || c.GetString() != name) throw new InvalidOperationException("Autorização revogada durante a espera.");
            }
            await _platform.AckAsync(id, "completed", MaintenanceService.LockSession(), ct);
        }
        else await _platform.AckAsync(id, "completed", MaintenanceService.OpenSettings(mode), ct);
    }

    /// <summary>Executa o protocolo de Windows Update do painel: autoriza, valida as identidades e publica JSON em blocos com offsets em bytes.</summary>
    private async Task WindowsUpdateAsync(long id, JsonElement command, CancellationToken ct)
    {
        var unattended = Unattended(command);
        var request = Str(command, "request_text");
        if (unattended)
        {
            var authorized = await _platform.PostAsync("agent-command-authorize", _platform.WithCredentials(("command_id", id)), ct, 12);
            if (!authorized.TryGetProperty("command", out var c) || c.GetString() != "windows_update"
                || !authorized.TryGetProperty("headless", out var h) || h.ValueKind != JsonValueKind.True)
                throw new InvalidOperationException("Autorização sem monitor do Windows Update revogada.");
            request = authorized.TryGetProperty("request_text", out var a) && a.ValueKind == JsonValueKind.String ? a.GetString() ?? "{}" : "{}";
        }
        var mode = MaintenanceService.Mode(request);
        if (mode is "install" or "pause" or "resume" or "latest_on" or "latest_off")
        {
            if (!unattended && !await AskAsync("O gestor solicitou a operação '" + mode + "' do Windows Update. Autorizar?"))
            { await _platform.AckAsync(id, "failed", "Operação não autorizada no computador.", ct); return; }
        }
        await _platform.AckAsync(id, "running", "Windows Update: " + mode + " em andamento", ct);
        var report = await WindowsUpdateService.RunAsync(request, ct);
        var json = JsonSerializer.Serialize(report);
        if (Encoding.UTF8.GetByteCount(json) > 59000) throw new InvalidOperationException("O relatório do Windows Update excede o limite de envio.");
        long offset = 0;
        for (var index = 0; index < json.Length;)
        {
            var size = Math.Min(700, json.Length - index);
            if (index + size < json.Length && char.IsHighSurrogate(json[index + size - 1]) && char.IsLowSurrogate(json[index + size])) size--;
            var chunk = json.Substring(index, size);
            var response = await _platform.PostAsync("agent-command-output", _platform.WithCredentials(("command_id", id), ("offset", offset), ("chunk", chunk)), ct, 16);
            offset += Encoding.UTF8.GetByteCount(chunk);
            if (!response.TryGetProperty("next_offset", out var next) || !next.TryGetInt64(out var received) || received != offset)
                throw new InvalidOperationException("Falha na sincronização do relatório do Windows Update.");
            index += size;
        }
        var summary = mode switch
        {
            "scan" => "Atualizações disponíveis: " + report.GetValueOrDefault("total"),
            "install" => "Instalação finalizada" + (report.TryGetValue("reboot_required", out var reboot) && reboot is true ? "; reinicie manualmente quando for seguro." : "."),
            _ => "Operação Windows Update " + mode + " finalizada",
        };
        await _platform.AckAsync(id, mode == "install" && report.GetValueOrDefault("result") is int installationResult && installationResult != 2 ? "failed" : "completed", summary, ct);
    }

    private async Task QuickAsync(long id, string name, CancellationToken ct)
    {
        var res = await ToolkitService.QuickAsync(name, ct);
        var ok = (string?)res["status"] == "completed";
        await _platform.SendEventAsync(new Dictionary<string, object?>
        {
            ["event"] = "quick_command", ["command_name"] = name, ["command_id"] = id, ["title"] = res["title"], ["status"] = res["status"],
            ["summary"] = res["summary"], ["details"] = res["details"], ["origin"] = "platform",
        }, ct, 16);
        await _platform.AckAsync(id, ok ? "completed" : "failed", (string?)res["summary"] ?? "", ct);
    }

    private async Task ToolkitAsync(long id, string name, CancellationToken ct)
    {
        var report = await ToolkitService.CollectToolkitAsync(name == "toolkit" ? null : name["toolkit_".Length..], ct);
        await _platform.SendEventAsync(new Dictionary<string, object?> { ["event"] = "toolkit", ["report"] = report }, ct, 16);
        await _platform.AckAsync(id, "completed", "Medições sincronizadas com sucesso", ct);
    }

    private async Task UpdateAsync(long id, JsonElement cmd, CancellationToken ct)
    {
        var force = Str(cmd, "request_text").Contains("\"force\"", StringComparison.Ordinal);
        var outcome = await UpdateService.StageAndLaunchAsync(_platform, id, force, (percent, message) => { LastActivity = $"Atualização {percent}% · {message}"; Changed?.Invoke(); }, ct);
        if (!outcome.Launched) { await _platform.AckAsync(id, "completed", outcome.Message, ct); return; }
        // O comando só é confirmado quando o agente atualizado reabrir (CompletePendingAsync).
        await Task.Delay(1500, ct);
        RequestExit?.Invoke();
    }

    private async Task ConnectionAsync(long id, CancellationToken ct)
    {
        var details = await ToolkitService.ConnectionAsync(ct);
        await _platform.SendEventAsync(details, ct, 20);
        await _platform.AckAsync(id, "completed", "Diagnóstico da conexão sincronizado", ct);
    }

    private async Task TerminalAsync(long id, JsonElement cmd, CancellationToken ct)
    {
        string[] argv;
        try { argv = DiagnosticCommands.Argv(Str(cmd, "request_text")); }
        catch (ArgumentException ex) { await _platform.AckAsync(id, "failed", ex.Message, ct); return; }
        long offset = 0; string lastError = ""; var errors = 0; var aborted = false;
        async Task<bool> Send(string chunk)
        {
            var size = Encoding.UTF8.GetByteCount(chunk);
            for (var attempt = 0; attempt < 3; attempt++)
            {
                try
                {
                    var r = await _platform.PostAsync("agent-command-output", _platform.WithCredentials(("command_id", id), ("offset", offset), ("chunk", chunk)), ct, 6);
                    var next = r.TryGetProperty("next_offset", out var n) && n.TryGetInt64(out var v) ? v : -1;
                    if (r.TryGetProperty("status", out var st) && st.ValueKind == JsonValueKind.String && st.GetString() is "failed" or "expired" or "completed") { aborted = true; return false; }
                    if (next == offset + size) { offset = next; errors = 0; lastError = ""; return true; }
                    lastError = "Resposta de sequência inesperada (offset servidor: " + next + ")";
                    if (next >= 0 && next != offset) break;
                }
                catch (Exception ex) when (ex is not OperationCanceledException) { lastError = ex.Message; }
                if (attempt < 2) await Task.Delay(250 * (attempt + 1), ct);
            }
            errors++; return errors < 8;
        }
        var (code, truncated, timedOut) = await DiagnosticCommands.RunAsync(argv, Send, ct);
        if (aborted) return;
        var ok = code == 0 && !truncated && !timedOut && errors == 0 && offset > 0;
        var summary = offset == 0 || errors > 0 ? "Falha na sincronização da saída do CMD: " + (lastError.Length > 0 ? lastError : "nenhum resultado transferido") : (ok ? "Concluído" : "Falha") + $" (código {code})";
        await _platform.AckAsync(id, ok ? "completed" : "failed", summary, ct);
    }

    public void Dispose() { _cts.Cancel(); _remoteScreen?.Dispose(); _platform.Dispose(); _cts.Dispose(); }
}
