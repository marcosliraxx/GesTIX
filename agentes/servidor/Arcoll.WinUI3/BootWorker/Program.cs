// Caminho: agentes/servidor/Arcoll.WinUI3/BootWorker/Program.cs
// Componente de presenca antes do login. Nao interage com a tela de bloqueio,
// nao recebe comandos, nao executa comandos, nem captura credenciais.
using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.RegularExpressions;

internal static class Program
{
    private static string Version => typeof(Program).Assembly.GetName().Version!.ToString(3);
    private sealed record Config(string Origin, string SiteCode, string DownloadKey, string Fingerprint);
    private static readonly string Base = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TI-Digital", "Boot");
    private static readonly string ConfigFile = Path.Combine(Base, "agent-config.json");
    private static readonly string LogFile = Path.Combine(Base, "presence.log");

    private static void Log(string msg)
    {
        try
        {
            Directory.CreateDirectory(Base);
            if (File.Exists(LogFile) && new FileInfo(LogFile).Length > 524288) File.Delete(LogFile);
            File.AppendAllText(LogFile, DateTimeOffset.UtcNow.ToString("u") + " " + msg + Environment.NewLine);
        }
        catch (IOException) { }
        catch (UnauthorizedAccessException) { }
    }

    private static Config? ReadConfig()
    {
        try
        {
            using var d = JsonDocument.Parse(File.ReadAllText(ConfigFile));
            var root = d.RootElement;
            string Read(string k) => root.TryGetProperty(k, out var e) && e.ValueKind == JsonValueKind.String ? e.GetString()!.Trim() : "";
            var origin = Read("origin").TrimEnd('/');
            var code = Read("site_code").ToUpperInvariant();
            var key = Read("download_key");
            var fp = Read("fingerprint").ToLowerInvariant();
            if (!Uri.TryCreate(origin, UriKind.Absolute, out var u) || u.Scheme != Uri.UriSchemeHttps || !string.IsNullOrEmpty(u.UserInfo) ||
                !Regex.IsMatch(code, "^[A-Z0-9]{5}$") || !Regex.IsMatch(key, "^[a-fA-F0-9]{64}$") || !Regex.IsMatch(fp, "^[a-f0-9]{64}$"))
            { Log("Configuracao invalida. Reinstale o modulo pelo administrador."); return null; }
            return new Config(origin, code, key, fp);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException)
        { Log("Configuracao ausente ou ilegivel: " + ex.GetType().Name); return null; }
    }

    private static async Task<int> Main()
    {
        // Worker de inicializacao do sistema: sem UI e sem direitos de operacao remota.
        var config = ReadConfig();
        if (config is null) return 2;
        using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false, AutomaticDecompression = DecompressionMethods.GZip };
        using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(9) };
        client.DefaultRequestHeaders.UserAgent.ParseAdd("TI-Digital-BootPresence/" + Version);
        var uri = config.Origin + "/api/api.php?action=agent-presence";
        using var stop = new CancellationTokenSource();
        Console.CancelKeyPress += (_, e) => { e.Cancel = true; stop.Cancel(); };
        Log("Presenca no boot iniciada; modo limitado, sem comandos remotos.");
        var lastReport = "";
        while (!stop.IsCancellationRequested)
        {
            try
            {
                // O servidor somente aceita esta presenca para um agente previamente aprovado.
                using var resp = await client.PostAsJsonAsync(uri, new
                {
                    fingerprint = config.Fingerprint, site_code = config.SiteCode,
                    download_key = config.DownloadKey, state = "online", version = Version, mode = "boot_presence"
                }, stop.Token);
                var unauthorized = resp.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden;
                var status = unauthorized ? "aguardando autorizacao" : resp.IsSuccessStatusCode ? "autenticado" : "HTTP " + (int)resp.StatusCode;
                if (status != lastReport) { Log("Estado: " + status); lastReport = status; }
                // Credenciais continuam verificadas no servidor a cada tentativa;
                // nao encerra definitivamente enquanto aguarda aprovacao.
            }
            catch (OperationCanceledException) when (stop.IsCancellationRequested) { break; }
            catch (HttpRequestException) { if (lastReport != "rede indisponivel") { Log("Rede indisponivel"); lastReport = "rede indisponivel"; } }
            catch (TaskCanceledException) { if (lastReport != "tempo esgotado") { Log("Tempo esgotado"); lastReport = "tempo esgotado"; } }
            try { await Task.Delay(TimeSpan.FromSeconds(lastReport == "aguardando autorizacao" ? 60 : 8), stop.Token); }
            catch (OperationCanceledException) { break; }
        }
        Log("Presenca encerrada.");
        return 0;
    }
}
