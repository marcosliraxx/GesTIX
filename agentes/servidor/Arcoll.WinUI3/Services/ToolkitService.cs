// Caminho: agentes/servidor/Arcoll.WinUI3/Services/ToolkitService.cs
using System.Diagnostics;
using System.Globalization;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Win32;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Medições e comandos rápidos da plataforma (toolkit, toolkit_*, diagnostic e QUICK_COMMANDS do agente Python).
/// Somente leitura/medição; a única ação que altera algo é limpar o cache DNS. Nenhum alvo é informado pela web.
/// </summary>
public static class ToolkitService
{
    public static readonly IReadOnlyDictionary<string, string> QuickTitles = new Dictionary<string, string>
    {
        ["storage_health"] = "Espaço dos discos", ["service_health"] = "Serviços essenciais", ["restart_readiness"] = "Precisa reiniciar?",
        ["quick_health"] = "Saúde rápida da rede", ["dns_test"] = "Teste de DNS", ["public_ip"] = "IP público",
        ["system_snapshot"] = "Recursos do computador", ["flush_dns"] = "Limpar cache DNS", ["wifi_info"] = "Sinal Wi-Fi",
    };

    private static readonly HttpClient Http = CreateHttp();
    private static HttpClient CreateHttp()
    {
        var h = new HttpClient(new HttpClientHandler { UseProxy = false }) { Timeout = TimeSpan.FromSeconds(8) };
        h.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollConnection/" + Models.AppInfo.Version);
        h.DefaultRequestHeaders.Accept.ParseAdd("application/json,text/plain");
        return h;
    }

    private static long Now() => DateTimeOffset.UtcNow.ToUnixTimeSeconds();
    private static Encoding Oem()
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        try { return Encoding.GetEncoding(CultureInfo.CurrentCulture.TextInfo.OEMCodePage); } catch (Exception) { return Encoding.UTF8; }
    }

    // ---------- execução de processos fixos ----------
    public static async Task<Dictionary<string, object?>> ExecAsync(string file, string[] args, int timeoutSeconds, Encoding? enc = null, CancellationToken ct = default)
    {
        try
        {
            var psi = new ProcessStartInfo(file) { RedirectStandardOutput = true, RedirectStandardError = true, RedirectStandardInput = true, UseShellExecute = false, CreateNoWindow = true, StandardOutputEncoding = enc ?? Oem(), StandardErrorEncoding = enc ?? Oem() };
            foreach (var a in args) psi.ArgumentList.Add(a);
            using var p = Process.Start(psi) ?? throw new InvalidOperationException("Processo não iniciado");
            p.StandardInput.Close();
            var stdout = p.StandardOutput.ReadToEndAsync(); var stderr = p.StandardError.ReadToEndAsync();
            using var limit = CancellationTokenSource.CreateLinkedTokenSource(ct); limit.CancelAfter(TimeSpan.FromSeconds(timeoutSeconds));
            try { await p.WaitForExitAsync(limit.Token); }
            catch (OperationCanceledException) { try { p.Kill(true); } catch (Exception) { } if (ct.IsCancellationRequested) throw; return new() { ["error"] = "Tempo limite excedido" }; }
            var text = (await stdout).Trim(); if (text.Length == 0) text = (await stderr).Trim();
            return new() { ["exit_code"] = p.ExitCode, ["output"] = text.Length > 12000 ? text[..12000] : text };
        }
        catch (OperationCanceledException) { throw; }
        catch (Exception ex) { return new() { ["error"] = ex.Message.Length > 180 ? ex.Message[..180] : ex.Message }; }
    }

    /// <summary>PowerShell com UTF-8 explícito; o resultado JSON entra em "data" quando o comando termina com sucesso.</summary>
    public static async Task<Dictionary<string, object?>> PsAsync(string command, CancellationToken ct = default)
    {
        const string setup = "[Console]::OutputEncoding=[System.Text.UTF8Encoding]::new($false);$OutputEncoding=[Console]::OutputEncoding;";
        var exe = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe");
        var r = await ExecAsync(File.Exists(exe) ? exe : "powershell.exe", new[] { "-NoProfile", "-NonInteractive", "-Command", setup + command }, 12, new UTF8Encoding(false), ct);
        if (r.TryGetValue("output", out var o) && o is string s && s.Length > 0 && r.TryGetValue("exit_code", out var c) && c is 0)
        {
            try { using var doc = JsonDocument.Parse(s); r["data"] = doc.RootElement.Clone(); } catch (JsonException) { }
        }
        return r;
    }

    private static Task<Dictionary<string, object?>> Sys(string exe, params string[] args) =>
        ExecAsync(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), exe + ".exe"), args, 6);

    private static IEnumerable<JsonElement> Rows(Dictionary<string, object?> r)
    {
        if (r.TryGetValue("data", out var d) && d is JsonElement e)
        {
            if (e.ValueKind == JsonValueKind.Array) return e.EnumerateArray().ToList();
            if (e.ValueKind == JsonValueKind.Object) return new[] { e };
        }
        return Array.Empty<JsonElement>();
    }
    private static string Prop(JsonElement e, string name) => e.TryGetProperty(name, out var v) ? (v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : v.ToString()) : "";
    private static long LongProp(JsonElement e, string name) => e.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.Number && v.TryGetInt64(out var n) ? n : 0;

    // ---------- sondas ----------
    private static async Task<Dictionary<string, object?>> ProbeAsync(string host, CancellationToken ct)
    {
        var times = new List<double>();
        using var ping = new Ping();
        for (var i = 0; i < 2; i++)
        {
            try { var r = await ping.SendPingAsync(host, 900); if (r.Status == IPStatus.Success) times.Add(r.RoundtripTime); } catch (Exception) { }
            ct.ThrowIfCancellationRequested();
        }
        return new() { ["host"] = host, ["response_count"] = times.Count, ["latency_ms"] = times.Count > 0 ? Math.Round(times.Average(), 2) : null, ["status"] = times.Count > 0 ? "responde" : "sem resposta" };
    }

    private static async Task<double?> TcpMsAsync(string host, int port, double timeoutSeconds = 2.5)
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(timeoutSeconds)); using var tcp = new TcpClient();
            await tcp.ConnectAsync(host, port, cts.Token); return Math.Round(sw.Elapsed.TotalMilliseconds, 1);
        }
        catch (Exception) { return null; }
    }

    private static async Task<double?> DnsMsAsync(string name, double timeoutSeconds = 3)
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(timeoutSeconds));
            var ips = await Dns.GetHostAddressesAsync(name, System.Net.Sockets.AddressFamily.InterNetwork, cts.Token);
            return ips.Length > 0 ? Math.Round(sw.Elapsed.TotalMilliseconds, 1) : null;
        }
        catch (Exception) { return null; }
    }

    private static string Gateway() => NetworkEngine.Local().Gateway ?? "";
    private static string Ms(double? v) => v is null ? "falhou" : Math.Round(v.Value).ToString(CultureInfo.InvariantCulture) + " ms";
    private static string Grade(int s) => s >= 90 ? "Excelente" : s >= 70 ? "Boa" : s >= 40 ? "Instável" : "Crítica";

    // ---------- toolkit ----------
    public static async Task<Dictionary<string, object?>> CollectToolkitAsync(string? selected, CancellationToken ct)
    {
        var report = new Dictionary<string, object?> { ["machine"] = Environment.MachineName, ["captured_at"] = Now() };
        if (selected is "traceroute" or "services" or "health" or "troubleshooter")
        {
            report["version"] = 2; report["selected"] = selected;
            report["results"] = new Dictionary<string, object?> { [selected] = selected switch { "traceroute" => await PathAsync(ct), "services" => await ServicesAsync(ct), "health" => await HealthAsync(ct), _ => await TroubleshootAsync(ct) } };
            return report;
        }
        if (!string.IsNullOrEmpty(selected) && selected != "pulse") throw new ArgumentException("Ferramenta não autorizada");
        var gw = Gateway(); var ps = (string c) => PsAsync(c, ct);
        var routes = await ps("Get-NetRoute -AddressFamily IPv4 | Where-Object {$_.DestinationPrefix -eq \"0.0.0.0/0\"} | Select-Object -First 5 InterfaceAlias,NextHop,RouteMetric | ConvertTo-Json -Compress");
        report["version"] = 1;
        report["results"] = new Dictionary<string, object?>
        {
            ["pulse"] = new Dictionary<string, object?> { ["gateway"] = gw.Length > 0 ? await ProbeAsync(gw, ct) : new Dictionary<string, object?> { ["status"] = "gateway não identificado" }, ["public_dns"] = await ProbeAsync("1.1.1.1", ct) },
            ["gateway"] = new Dictionary<string, object?> { ["default_route"] = routes },
            ["dns"] = new Dictionary<string, object?> { ["servers"] = await ps("Get-DnsClientServerAddress -AddressFamily IPv4 | Select-Object InterfaceAlias,ServerAddresses | ConvertTo-Json -Compress"), ["example_lookup"] = await ps("Resolve-DnsName example.com -Type A -ErrorAction Stop | Where-Object {$_.IPAddress} | Select-Object -First 3 Name,IPAddress | ConvertTo-Json -Compress") },
            ["routing"] = new Dictionary<string, object?> { ["routes"] = await ps("Get-NetRoute -AddressFamily IPv4 | Sort-Object RouteMetric | Select-Object -First 18 DestinationPrefix,NextHop,InterfaceAlias,RouteMetric | ConvertTo-Json -Compress") },
            ["interfaces"] = new Dictionary<string, object?> { ["adapters"] = await ps("Get-NetAdapter | Select-Object Name,Status,LinkSpeed,MacAddress,InterfaceDescription | ConvertTo-Json -Compress") },
            ["system"] = new Dictionary<string, object?> { ["os"] = Environment.OSVersion.VersionString, ["hostname"] = Environment.MachineName, ["resources"] = await ps("Get-CimInstance Win32_OperatingSystem | Select-Object FreePhysicalMemory,TotalVisibleMemorySize,LastBootUpTime | ConvertTo-Json -Compress") },
            ["firewall"] = new Dictionary<string, object?> { ["profiles"] = await ps("Get-NetFirewallProfile | Select-Object Name,Enabled,DefaultInboundAction,DefaultOutboundAction | ConvertTo-Json -Compress") },
            ["tcp"] = new Dictionary<string, object?> { ["summary"] = await ps("Get-NetTCPConnection | Group-Object State | Select-Object Name,Count | ConvertTo-Json -Compress") },
            ["wifi"] = new Dictionary<string, object?> { ["interface"] = await Sys("netsh", "wlan", "show", "interfaces") },
            ["dhcp"] = new Dictionary<string, object?> { ["leases"] = await ps("Get-CimInstance Win32_NetworkAdapterConfiguration -Filter \"IPEnabled=True\" | Select-Object Description,DHCPEnabled,DHCPServer,DHCPLeaseObtained,DHCPLeaseExpires | ConvertTo-Json -Compress") },
            ["ipconfig"] = new Dictionary<string, object?> { ["addresses"] = await ps("Get-NetIPConfiguration | Select-Object -First 16 InterfaceAlias,@{n=\"IPv4\";e={($_.IPv4Address.IPAddress -join \", \")}},@{n=\"IPv6\";e={($_.IPv6Address.IPAddress -join \", \")}},@{n=\"Gateway\";e={$_.IPv4DefaultGateway.NextHop}},@{n=\"DNS\";e={($_.DNSServer.ServerAddresses -join \", \")}} | ConvertTo-Json -Compress") },
            ["neighbors"] = new Dictionary<string, object?> { ["arp"] = await ps("Get-NetNeighbor -AddressFamily IPv4 | Where-Object {$_.State -ne \"Unreachable\"} | Select-Object -First 50 InterfaceAlias,IPAddress,LinkLayerAddress,State | ConvertTo-Json -Compress") },
            ["statistics"] = new Dictionary<string, object?> { ["counters"] = await ps("Get-NetAdapterStatistics | Select-Object Name,ReceivedBytes,SentBytes,ReceivedPacketErrors,OutboundPacketErrors,ReceivedDiscardedPackets,OutboundDiscardedPackets | ConvertTo-Json -Compress") },
            ["proxy"] = new Dictionary<string, object?> { ["winhttp"] = await Sys("netsh", "winhttp", "show", "proxy") },
            ["events"] = new Dictionary<string, object?> { ["recent_network_events"] = await ps("Get-WinEvent -FilterHashtable @{LogName=\"System\";Level=2,3;StartTime=(Get-Date).AddHours(-24)} -MaxEvents 30 -ErrorAction SilentlyContinue | Where-Object {$_.ProviderName -match \"Tcpip|DNS|Dhcp|Network|WLAN|NDIS\"} | Select-Object -First 12 TimeCreated,ProviderName,Id,LevelDisplayName,Message | ConvertTo-Json -Compress") },
            ["uptime"] = new Dictionary<string, object?> { ["system"] = await ps("Get-CimInstance Win32_OperatingSystem | Select-Object LastBootUpTime,LocalDateTime,Caption,Version | ConvertTo-Json -Compress") },
        };
        return report;
    }

    private static async Task<Dictionary<string, object?>> PathAsync(CancellationToken ct) =>
        new() { ["trace"] = await ExecAsync(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "tracert.exe"), new[] { "-d", "-h", "12", "-w", "650", "1.1.1.1" }, 14, null, ct) };

    private static async Task<Dictionary<string, object?>> ServicesAsync(CancellationToken ct)
    {
        var list = new List<object?>();
        foreach (var (host, port) in new[] { ("1.1.1.1", 443), ("1.1.1.1", 53), ("example.com", 443) })
        {
            var ms = await TcpMsAsync(host, port);
            list.Add(ms is null ? new Dictionary<string, object?> { ["destination"] = host, ["port"] = port, ["reachable"] = false, ["error"] = "Falha de conexão" }
                                : new Dictionary<string, object?> { ["destination"] = host, ["port"] = port, ["reachable"] = true, ["connect_ms"] = ms });
        }
        return new() { ["services"] = list };
    }

    private static async Task<Dictionary<string, object?>> HealthAsync(CancellationToken ct)
    {
        var gw = Gateway(); if (!(IPAddress.TryParse(gw, out var ip) && NetworkEngine.IsPrivate(ip))) gw = "";
        var external = await ProbeAsync("1.1.1.1", ct);
        var internalProbe = gw.Length > 0 ? await ProbeAsync(gw, ct) : new Dictionary<string, object?> { ["status"] = "indisponível" };
        var dns = await PsAsync("Resolve-DnsName example.com -Type A -ErrorAction Stop | Select-Object -First 1 -ExpandProperty IPAddress", ct);
        var checks = new List<bool> { (string?)external["status"] == "responde", dns.TryGetValue("exit_code", out var c) && c is 0 };
        if (gw.Length > 0) checks.Add((string?)internalProbe["status"] == "responde");
        var ok = checks.Count(x => x);
        return new()
        {
            ["score"] = (int)Math.Round(100.0 * ok / checks.Count), ["successful_checks"] = ok, ["total_checks"] = checks.Count,
            ["checks"] = new Dictionary<string, object?> { ["external_ping"] = external, ["gateway_ping"] = internalProbe, ["dns_lookup"] = dns },
            ["note"] = "Índice baseado somente nos testes executados, não é uma garantia de disponibilidade",
        };
    }

    private static async Task<Dictionary<string, object?>> TroubleshootAsync(CancellationToken ct)
    {
        var h = await HealthAsync(ct); var checks = (Dictionary<string, object?>)h["checks"]!; var issues = new List<string>();
        if ((string?)((Dictionary<string, object?>)checks["external_ping"]!)["status"] != "responde") issues.Add("Destino ICMP não respondeu; pode ser bloqueio de ICMP");
        if (!(((Dictionary<string, object?>)checks["dns_lookup"]!).TryGetValue("exit_code", out var c) && c is 0)) issues.Add("Resolução DNS de referência falhou");
        if ((string?)((Dictionary<string, object?>)checks["gateway_ping"]!)["status"] == "sem resposta") issues.Add("Gateway não respondeu ao ICMP");
        if (issues.Count == 0) issues.Add("Nenhuma falha identificada nos testes executados");
        return new() { ["findings"] = issues, ["measurements"] = h, ["disclaimer"] = "Diagnóstico indicativo; não altera a configuração do Windows" };
    }

    // ---------- comandos rápidos ----------
    private static Dictionary<string, object?> Result(bool ok, string summary, object? details = null) =>
        new() { ["status"] = ok ? "completed" : "failed", ["summary"] = summary, ["details"] = details ?? new Dictionary<string, object?>() };

    public static async Task<Dictionary<string, object?>> QuickAsync(string name, CancellationToken ct)
    {
        if (!QuickTitles.TryGetValue(name, out var title)) throw new ArgumentException("Comando não autorizado");
        Dictionary<string, object?> r;
        try
        {
            r = name switch
            {
                "quick_health" => await QuickHealthAsync(),
                "dns_test" => await DnsTestAsync(),
                "public_ip" => await PublicIpAsync(),
                "system_snapshot" => SystemSnapshot(),
                "flush_dns" => await FlushDnsAsync(),
                "wifi_info" => await WifiInfoAsync(),
                "storage_health" => await StorageHealthAsync(ct),
                "service_health" => await ServiceHealthAsync(ct),
                _ => RestartReadiness(),
            };
        }
        catch (OperationCanceledException) { throw; }
        catch (Exception ex) { r = Result(false, ex.Message); }
        var summary = r.TryGetValue("summary", out var s) ? s?.ToString() ?? "" : "";
        r["summary"] = summary.Length > 480 ? summary[..480] : summary; r["title"] = title; r["captured_at"] = Now();
        return r;
    }

    private static async Task<Dictionary<string, object?>> QuickHealthAsync()
    {
        var gw = Gateway(); double? gwMs = null;
        if (gw.Length > 0) foreach (var port in new[] { 80, 443, 53, 445 }) { gwMs = await TcpMsAsync(gw, port, 0.8); if (gwMs is not null) break; }
        var inet = await TcpMsAsync("1.1.1.1", 443); var dns = await DnsMsAsync("example.com");
        var checks = new List<bool> { inet is not null, dns is not null }; if (gw.Length > 0) checks.Add(gwMs is not null);
        var score = (int)Math.Round(100.0 * checks.Count(x => x) / checks.Count);
        if (inet > 150) score = Math.Max(0, score - 15);
        return Result(true, $"Saúde {score}/100 ({Grade(score)}) · Gateway {(gw.Length > 0 ? Ms(gwMs) : "n/d")} · Internet {Ms(inet)} · DNS {Ms(dns)}",
            new Dictionary<string, object?> { ["score"] = score, ["gateway"] = gw, ["gateway_ms"] = gwMs, ["internet_ms"] = inet, ["dns_ms"] = dns });
    }

    private static async Task<Dictionary<string, object?>> DnsTestAsync()
    {
        var names = new[] { "example.com", "google.com", "cloudflare.com", "microsoft.com" }; var rows = new Dictionary<string, object?>(); var ok = new List<double>();
        foreach (var n in names) { var v = await DnsMsAsync(n); rows[n] = v; if (v is not null) ok.Add(v.Value); }
        double? avg = ok.Count > 0 ? Math.Round(ok.Average()) : null;
        return Result(ok.Count > 0, $"DNS: {ok.Count}/{names.Length} nomes resolvidos · média {Ms(avg)} · " + string.Join(" · ", rows.Select(k => k.Key.Split('.')[0] + " " + Ms((double?)k.Value))), rows);
    }

    private static async Task<Dictionary<string, object?>> PublicIpAsync()
    {
        foreach (var url in new[] { "https://api.ipify.org", "https://ifconfig.me/ip", "https://icanhazip.com" })
        {
            try
            {
                var value = (await Http.GetStringAsync(url)).Trim();
                if (IPAddress.TryParse(value, out _)) return Result(true, "IP público desta rede: " + value, new Dictionary<string, object?> { ["public_ip"] = value, ["source"] = url });
            }
            catch (Exception) { }
        }
        return Result(false, "Não foi possível descobrir o IP público (sem acesso à internet?)");
    }

    private static Dictionary<string, object?> SystemSnapshot()
    {
        var (total, used) = SystemMetrics.Memory(); var free = total - used;
        var sys = new DriveInfo(Path.GetPathRoot(Environment.SystemDirectory) ?? "C:\\");
        long diskTotal = 0, diskFree = 0; try { diskTotal = sys.TotalSize; diskFree = sys.AvailableFreeSpace; } catch (Exception) { }
        var up = Environment.TickCount64 / 1000; string Gb(long b) => b <= 0 ? "--" : (b / 1073741824.0).ToString("0.0", CultureInfo.InvariantCulture) + " GB";
        var pct = total > 0 ? (int)Math.Round(100.0 * used / total) : 0;
        return Result(true, $"{Environment.MachineName} · {Environment.ProcessorCount} núcleos · RAM {Gb(total)} (uso {pct}%) · Disco livre {Gb(diskFree)} de {Gb(diskTotal)} · Ligado há {up / 86400}d {(up % 86400) / 3600:00}h",
            new Dictionary<string, object?> { ["host"] = Environment.MachineName, ["os"] = Environment.OSVersion.VersionString, ["cores"] = Environment.ProcessorCount, ["memory_total"] = total, ["memory_free"] = free, ["disk_total"] = diskTotal, ["disk_free"] = diskFree, ["uptime_seconds"] = up });
    }

    private static async Task<Dictionary<string, object?>> FlushDnsAsync()
    {
        var r = await Sys("ipconfig", "/flushdns"); var ok = r.TryGetValue("exit_code", out var c) && c is 0;
        return Result(ok, ok ? "Cache DNS do Windows limpo com sucesso" : "Não foi possível limpar o cache DNS (execute o agente como administrador)", r);
    }

    private static async Task<Dictionary<string, object?>> WifiInfoAsync()
    {
        var text = (await Sys("netsh", "wlan", "show", "interfaces")).GetValueOrDefault("output")?.ToString() ?? "";
        string Pick(params string[] keys)
        {
            foreach (var line in text.Split('\n')) { var i = line.IndexOf(':'); if (i > 0 && keys.Contains(line[..i].Trim().ToLowerInvariant()) && line[(i + 1)..].Trim().Length > 0) return line[(i + 1)..].Trim(); }
            return "";
        }
        var ssid = Pick("ssid"); if (ssid.Length == 0) return Result(true, "Este computador não está conectado a uma rede Wi-Fi (cabo ou adaptador desligado)");
        string signal = Pick("signal", "sinal"), radio = Pick("radio type", "tipo de rádio", "tipo de radio"), channel = Pick("channel", "canal"), rate = Pick("receive rate (mbps)", "taxa de recepção (mbps)", "taxa de recepcao (mbps)");
        string Or(string v) => v.Length > 0 ? v : "--";
        return Result(true, $"Wi-Fi \"{ssid}\" · sinal {Or(signal)} · {Or(radio)} · canal {Or(channel)} · {Or(rate)} Mbps",
            new Dictionary<string, object?> { ["ssid"] = ssid, ["signal"] = signal, ["radio"] = radio, ["channel"] = channel, ["rate"] = rate });
    }

    private static async Task<Dictionary<string, object?>> StorageHealthAsync(CancellationToken ct)
    {
        var r = await PsAsync("Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | Select-Object DeviceID,Size,FreeSpace | ConvertTo-Json -Compress", ct);
        var vols = new List<Dictionary<string, object?>>();
        foreach (var d in Rows(r)) { var total = LongProp(d, "Size"); var free = LongProp(d, "FreeSpace"); if (total > 0) vols.Add(new() { ["name"] = Prop(d, "DeviceID"), ["total_bytes"] = total, ["free_bytes"] = free, ["free_percent"] = Math.Round(100.0 * free / total, 1) }); }
        if (vols.Count == 0) return Result(false, "Não foi possível consultar o espaço dos discos.");
        var low = vols.Where(v => (double)v["free_percent"]! < 15).ToList();
        return Result(true, low.Count > 0 ? "Há pouco espaço em " + string.Join(", ", low.Select(v => v["name"])) + ". Libere arquivos antes que o disco fique cheio." : "Os discos têm espaço livre suficiente.",
            new Dictionary<string, object?> { ["level"] = low.Count > 0 ? "warning" : "good", ["volumes"] = vols });
    }

    private static async Task<Dictionary<string, object?>> ServiceHealthAsync(CancellationToken ct)
    {
        var r = await PsAsync("Get-CimInstance Win32_Service | Where-Object { $_.Name -in @('Winmgmt','Dnscache','Dhcp','LanmanWorkstation') } | Select-Object Name,State,StartMode | ConvertTo-Json -Compress", ct);
        var labels = new Dictionary<string, string> { ["Winmgmt"] = "Monitoramento do Windows", ["Dnscache"] = "Abertura de sites", ["Dhcp"] = "Configuração da conexão", ["LanmanWorkstation"] = "Pastas compartilhadas" };
        var items = Rows(r).Select(d => new Dictionary<string, object?> { ["name"] = labels.GetValueOrDefault(Prop(d, "Name"), "Serviço do Windows"), ["running"] = Prop(d, "State") == "Running", ["expected"] = Prop(d, "StartMode") == "Auto" }).ToList();
        if (items.Count == 0) return Result(false, "Não foi possível verificar os serviços do Windows.");
        var stopped = items.Any(i => (bool)i["expected"]! && !(bool)i["running"]!);
        return Result(true, stopped ? "Um serviço importante está parado. Peça ao responsável para conferir." : "Os serviços essenciais estão funcionando conforme sua configuração.",
            new Dictionary<string, object?> { ["level"] = stopped ? "warning" : "good", ["services"] = items });
    }

    private static Dictionary<string, object?> RestartReadiness()
    {
        bool pending = false;
        foreach (var path in new[] { @"SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending", @"SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired" })
        {
            try { using var key = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64).OpenSubKey(path); if (key is not null) pending = true; }
            catch (Exception) { return Result(false, "O Windows não permitiu conferir todos os avisos de reinicialização."); }
        }
        try
        {
            using var key = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64).OpenSubKey(@"SYSTEM\CurrentControlSet\Control\Session Manager");
            if (key?.GetValue("PendingFileRenameOperations") is Array { Length: > 0 }) pending = true;
        }
        catch (Exception) { return Result(false, "O Windows não permitiu conferir todos os avisos de reinicialização."); }
        return Result(true, pending ? "O Windows precisa reiniciar para concluir uma alteração. Combine um horário com o usuário." : "O Windows não informou reinicialização pendente.",
            new Dictionary<string, object?> { ["level"] = pending ? "warning" : "good", ["restart_pending"] = pending });
    }

    // ---------- diagnóstico de conexão e provedora ----------
    public static async Task<Dictionary<string, object?>> ConnectionAsync(CancellationToken ct)
    {
        var errors = new List<string>(); var iface = new Dictionary<string, object?>(); var latency = new Dictionary<string, object?>(); var route = new List<object?>(); string publicIp = ""; var provider = new Dictionary<string, object?>();
        const string script = "$ErrorActionPreference='Stop'; $r=Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Sort-Object RouteMetric,InterfaceMetric | Select-Object -First 1; if(!$r){throw 'No default route'}; $i=Get-NetIPConfiguration -InterfaceIndex $r.InterfaceIndex; $a=Get-NetAdapter -InterfaceIndex $r.InterfaceIndex; [pscustomobject]@{name=$a.Name;description=$a.InterfaceDescription;mac=$a.MacAddress;speed_bps=$a.LinkSpeed;status=$a.Status;ip=@($i.IPv4Address.IPAddress)[0];prefix=@($i.IPv4Address.PrefixLength)[0];gateway=$r.NextHop;dns=@($i.DNSServer.ServerAddresses)} | ConvertTo-Json -Compress -Depth 4";
        var ps = await PsAsync(script, ct);
        if (ps.TryGetValue("data", out var d) && d is JsonElement e && e.ValueKind == JsonValueKind.Object) foreach (var p in e.EnumerateObject()) iface[p.Name] = p.Value.Clone(); else errors.Add("Interface Windows indisponível");
        foreach (var host in new[] { iface.GetValueOrDefault("gateway") is JsonElement g ? g.GetString() : null, "1.1.1.1", "8.8.8.8" })
        {
            if (!IPAddress.TryParse(host ?? "", out _)) continue;
            var times = new List<double>(); using var ping = new Ping();
            for (var i = 0; i < 2; i++) { try { var r = await ping.SendPingAsync(host!, 1200); if (r.Status == IPStatus.Success) times.Add(r.RoundtripTime); } catch (Exception) { } }
            latency[host!] = new Dictionary<string, object?> { ["responded"] = times.Count > 0, ["avg_ms"] = times.Count > 0 ? Math.Round(times.Average(), 2) : null, ["samples"] = times.Count };
        }
        foreach (var url in new[] { "https://api.ipify.org?format=json", "https://api64.ipify.org?format=json", "https://checkip.amazonaws.com/" })
        {
            try
            {
                var raw = (await Http.GetStringAsync(url, ct)).Trim(); var candidate = raw;
                try { using var j = JsonDocument.Parse(raw); if (j.RootElement.ValueKind == JsonValueKind.Object && j.RootElement.TryGetProperty("ip", out var v)) candidate = v.GetString() ?? ""; } catch (JsonException) { }
                if (IPAddress.TryParse(candidate.Trim(), out var ip) && !NetworkEngine.IsPrivate(ip) && !IPAddress.IsLoopback(ip)) { publicIp = ip.ToString(); break; }
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
            catch (Exception) { }
        }
        if (publicIp.Length == 0) errors.Add("IP público não confirmado");
        else
        {
            provider = await ProviderAsync(publicIp, ct);
            if (!provider.ContainsKey("isp")) errors.Add("Consultas externas de provedora indisponíveis");
        }
        var trace = await ExecAsync(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "tracert.exe"), new[] { "-d", "-h", "8", "-w", "350", "1.1.1.1" }, 9, null, ct);
        foreach (var line in (trace.GetValueOrDefault("output")?.ToString() ?? "").Split('\n'))
        {
            var m = Regex.Match(line, @"^\s*(\d{1,2})\s+(.+)$"); if (!m.Success || int.Parse(m.Groups[1].Value) > 8) continue;
            var addrs = Regex.Matches(m.Groups[2].Value, @"\b(?:\d{1,3}\.){3}\d{1,3}\b");
            route.Add(new Dictionary<string, object?> { ["hop"] = int.Parse(m.Groups[1].Value), ["ip"] = addrs.Count > 0 ? addrs[^1].Value : null, ["responded"] = addrs.Count > 0 });
        }
        return new() { ["schema"] = 1, ["captured_at"] = Now(), ["machine"] = Environment.MachineName, ["os"] = Environment.OSVersion.VersionString, ["public_ip"] = publicIp, ["provider"] = provider, ["interface"] = iface, ["latency"] = latency, ["route"] = route, ["errors"] = errors, ["event"] = "diagnostic", ["text"] = "Diagnóstico estruturado da conexão" };
    }

    /// <summary>Provedora do IP público: ipwho.is, depois ipapi.co e ipinfo.io. A correspondência exata do IP é obrigatória.</summary>
    private static async Task<Dictionary<string, object?>> ProviderAsync(string ip, CancellationToken ct)
    {
        static string? S(JsonElement e, string n) => e.TryGetProperty(n, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : e.TryGetProperty(n, out var w) && w.ValueKind == JsonValueKind.Number ? w.ToString() : null;
        try
        {
            using var doc = JsonDocument.Parse(await Http.GetStringAsync("https://ipwho.is/" + ip, ct)); var g = doc.RootElement;
            if (g.TryGetProperty("success", out var ok) && ok.ValueKind == JsonValueKind.True && S(g, "ip") == ip && g.TryGetProperty("connection", out var c))
                return new() { ["isp"] = S(c, "isp"), ["org"] = S(c, "org"), ["asn"] = S(c, "asn"), ["domain"] = S(c, "domain"), ["city"] = S(g, "city"), ["region"] = S(g, "region"), ["country"] = S(g, "country"), ["country_code"] = S(g, "country_code"), ["postal"] = S(g, "postal"), ["latitude"] = S(g, "latitude"), ["longitude"] = S(g, "longitude"), ["source"] = "ipwho.is", ["approximate"] = true };
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception) { }
        try
        {
            using var doc = JsonDocument.Parse(await Http.GetStringAsync("https://ipapi.co/" + ip + "/json/", ct)); var g = doc.RootElement;
            if (!g.TryGetProperty("error", out _) && S(g, "ip") == ip)
                return new() { ["isp"] = S(g, "org"), ["org"] = S(g, "org"), ["asn"] = S(g, "asn"), ["city"] = S(g, "city"), ["region"] = S(g, "region"), ["country"] = S(g, "country_name"), ["country_code"] = S(g, "country_code"), ["postal"] = S(g, "postal"), ["latitude"] = S(g, "latitude"), ["longitude"] = S(g, "longitude"), ["source"] = "ipapi.co", ["approximate"] = true };
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception) { }
        try
        {
            using var doc = JsonDocument.Parse(await Http.GetStringAsync("https://ipinfo.io/" + ip + "/json", ct)); var g = doc.RootElement;
            if (S(g, "ip") == ip && !g.TryGetProperty("bogon", out _))
            {
                var org = S(g, "org") ?? ""; var m = Regex.Match(org, @"^AS(\d+)\s+(.+)$");
                return new() { ["isp"] = m.Success ? m.Groups[2].Value : org.Length > 0 ? org : null, ["org"] = org.Length > 0 ? org : null, ["asn"] = m.Success ? "AS" + m.Groups[1].Value : null, ["city"] = S(g, "city"), ["region"] = S(g, "region"), ["country"] = S(g, "country"), ["postal"] = S(g, "postal"), ["source"] = "ipinfo.io", ["approximate"] = true };
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception) { }
        return new();
    }
}
