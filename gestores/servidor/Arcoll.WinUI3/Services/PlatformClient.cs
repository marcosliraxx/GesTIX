// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PlatformClient.cs
using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

public sealed class PlatformException : Exception
{
    public HttpStatusCode? Status { get; }
    public PlatformException(string message, HttpStatusCode? status = null) : base(message) { Status = status; }
}

/// <summary>Cliente único da API da plataforma (api/api.php). Todo o tráfego do gestor passa por aqui.</summary>
public sealed class PlatformClient : IDisposable
{
    private readonly HttpClient _http = new(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromSeconds(30) };
    public GestorConfig Config { get; private set; }

    /// <summary>
    /// Cabeçalhos idênticos aos do gestor Python (Accept + Content-Type sem charset + User-Agent ArcollGestor/x).
    /// O servidor de hospedagem recusa (HTTP 406) pedidos sem Accept ou com "charset" no Content-Type.
    /// </summary>
    public static void ApplyDefaults(HttpClient http)
    {
        http.DefaultRequestHeaders.Accept.Clear();
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        http.DefaultRequestHeaders.UserAgent.Clear();
        http.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollGestor/" + AppInfo.Version);
    }

    public static StringContent JsonBody(object body)
    {
        var content = new StringContent(System.Text.Json.JsonSerializer.Serialize(body), new UTF8Encoding(false), "application/json");
        content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        return content;
    }

    public PlatformClient(GestorConfig config)
    {
        Config = config;
        ApplyDefaults(_http);
    }

    public void Reload() => Config = GestorConfig.Load();

    /// <summary>POST JSON em api.php?action=... e devolve a resposta já validada (ok=true).</summary>
    public async Task<JsonElement> PostAsync(string action, object body, CancellationToken ct, int timeoutSeconds = 12)
    {
        using var operation = action is "gestor-device-save" or "gestor-pentefino-local-settings" ? OperationProgress.Begin(action == "gestor-device-save" ? "Salvar dispositivo" : "Configurações do Clean") : null;
        try
        {
        operation?.Report(20, "Enviando solicitação à plataforma");
        if (Config.Origin.Length == 0) throw new PlatformException("Servidor HTTPS não configurado. Reinstale pelo painel.");
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(TimeSpan.FromSeconds(timeoutSeconds));
        using var content = JsonBody(body);
        using var response = await _http.PostAsync(Config.Origin + "/api/api.php?action=" + action, content, timeout.Token);
        var text = await response.Content.ReadAsStringAsync(timeout.Token);
        JsonElement root;
        try { using var doc = JsonDocument.Parse(text); root = doc.RootElement.Clone(); }
        catch (JsonException) { throw new PlatformException($"Resposta inválida do servidor (HTTP {(int)response.StatusCode}).", response.StatusCode); }
        if (!response.IsSuccessStatusCode || !(root.ValueKind == JsonValueKind.Object && root.TryGetProperty("ok", out var ok) && ok.ValueKind == JsonValueKind.True))
        {
            var message = root.ValueKind == JsonValueKind.Object && root.TryGetProperty("error", out var e) && e.ValueKind == JsonValueKind.String ? e.GetString()! : $"HTTP {(int)response.StatusCode}";
            throw new PlatformException(message, response.StatusCode);
        }
        operation?.Succeed("Solicitação confirmada");
        return root;
        } catch (Exception ex) { operation?.Fail(ex.Message); throw; }
    }

    public Dictionary<string, object?> WithCredentials(params (string Key, object? Value)[] extra)
    {
        var body = Config.Credentials();
        foreach (var (k, v) in extra) body[k] = v;
        return body;
    }

    /// <summary>Envia um evento de telemetria (gestor-data).</summary>
    public Task<JsonElement> SendEventAsync(Dictionary<string, object?> evt, CancellationToken ct, int timeoutSeconds = 16)
    {
        evt["captured_at"] = evt.TryGetValue("captured_at", out var c) && c is not null ? c : DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        return PostAsync("gestor-data", WithCredentials(("machine_name", Environment.MachineName), ("event", evt), ("captured_at", DateTimeOffset.UtcNow.ToUnixTimeSeconds())), ct, timeoutSeconds);
    }

    public Task<JsonElement> AckAsync(long commandId, string status, string result, CancellationToken ct)
    {
        if (status == "running") OperationProgress.Current?.ReportText(result);
        if (status == "failed") OperationProgress.Current?.FlagFailure(result);
        return PostAsync("gestor-command-ack", WithCredentials(("command_id", commandId), ("status", status), ("result", result.Length > 480 ? result[..480] : result),
            ("error_detail", status == "failed" ? ErrorDiagnostic.Sanitize(result) : null)), ct, 10);
    }

    public Task<JsonElement> AckFailureAsync(long commandId, Exception error, CancellationToken ct) =>
        PostAsync("gestor-command-ack", WithCredentials(("command_id", commandId), ("status", "failed"),
            ("result", ErrorDiagnostic.Sanitize(error.Message, 480)), ("error_code", ErrorDiagnostic.Code(error)),
            ("error_detail", ErrorDiagnostic.FromException(error))), ct, 10);

    public void Dispose() => _http.Dispose();
}
