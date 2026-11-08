// Caminho: gestores/servidor/Arcoll.WinUI3/Services/RemoteScreenService.cs
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Net;
using System.Runtime.InteropServices;
using System.Text.Json;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Sessão remota temporária e consentida: HTTPS autenticado, JPEG com taxa limitada,
/// mensagens de entrada autorizadas e encerramento por revogação/timeout.
/// Opera exclusivamente na área de trabalho interativa normal (não no desktop seguro/UAC).
/// </summary>
public sealed class RemoteScreenService : IDisposable
{
    private readonly GestorConfig _config;
    private readonly long _commandId;
    private readonly HttpClient _http;
    private readonly CancellationTokenSource _stop = new();
    private DateTimeOffset _started;
    private bool _active;
    private bool _disposed;
    // O gestor mantém uma única sessão HTTP e evita enviar novamente imagens idênticas.
    private byte[]? _lastFrame;
    private DateTimeOffset _lastHeartbeat = DateTimeOffset.MinValue;
    private string _quality = "balanced";
    public bool Active => _active && !_stop.IsCancellationRequested;

    public RemoteScreenService(GestorConfig config, long commandId)
    {
        if (!config.IsComplete) throw new InvalidOperationException("Credenciais do gestor incompletas.");
        _config = config;
        _commandId = commandId;
        _http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false })
        { Timeout = TimeSpan.FromSeconds(12) };
        PlatformClient.ApplyDefaults(_http);
    }

    private async Task<JsonElement> PostAsync(string action, Dictionary<string, object?> body, CancellationToken ct)
    {
        foreach (var (key, value) in _config.Credentials()) body[key] = value;
        using var content = PlatformClient.JsonBody(body);
        using var reply = await _http.PostAsync(_config.Origin + "/api/remote-screen.php?action=" + action +
            "&command_id=" + _commandId, content, ct);
        var raw = await reply.Content.ReadAsStringAsync(ct);
        JsonElement result;
        try { using var doc = JsonDocument.Parse(raw); result = doc.RootElement.Clone(); }
        catch (JsonException) { throw new PlatformException("Resposta remota inválida (HTTP " + (int)reply.StatusCode + ").", reply.StatusCode); }
        if (!reply.IsSuccessStatusCode || !result.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True)
        {
            if (result.TryGetProperty("stop", out var stopped) && stopped.ValueKind == JsonValueKind.True)
                throw new RemoteSessionEndedException();
            var message = result.TryGetProperty("error", out var error) && error.ValueKind == JsonValueKind.String
                ? error.GetString() : "HTTP " + (int)reply.StatusCode;
            throw new PlatformException(message ?? "Resposta remota recusada.", reply.StatusCode);
        }
        if (result.TryGetProperty("stop", out var stop) && stop.ValueKind == JsonValueKind.True)
            throw new RemoteSessionEndedException();
        return result;
    }

    public async Task RunAsync(CancellationToken lifetime)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(_stop.Token, lifetime);
        var ct = linked.Token;
        _started = DateTimeOffset.UtcNow;
        _active = true;
        RuntimeLog.Write("Sessão remota consentida #" + _commandId + " iniciada (máximo 30 minutos).");
        try
        {
            // A captura funciona somente na sessão interativa, nunca em Session 0.
            if (Environment.UserInteractive == false) throw new InvalidOperationException("Não há sessão gráfica interativa disponível.");
            var consecutiveErrors = 0;
            while (!ct.IsCancellationRequested && DateTimeOffset.UtcNow - _started < TimeSpan.FromMinutes(30))
            {
                try
                {
                    var frame = CaptureJpeg(_quality);
                    bool changed = _lastFrame is null || !_lastFrame.AsSpan().SequenceEqual(frame);
                    bool heartbeatDue = DateTimeOffset.UtcNow - _lastHeartbeat >= TimeSpan.FromSeconds(3);
                    if (changed || heartbeatDue)
                    {
                        var body = new Dictionary<string, object?>
                        {
                            ["control"] = true,
                            // O estado da área de trabalho é apurado pelo monitor interativo, não presumido na transmissão.
                            ["selected_monitor"] = "primary",
                            ["monitors"] = new[] { new { id = "primary", label = "Tela principal", width = GetSystemMetrics(0), height = GetSystemMetrics(1), primary = true } },
                        };
                        if (changed)
                            body["image"] = Convert.ToBase64String(frame);
                        else
                            body["keepalive"] = true;
                        var reply = await PostAsync("frame", body, ct);
                        _lastHeartbeat = DateTimeOffset.UtcNow;
                        if (changed) _lastFrame = frame;
                        if (reply.TryGetProperty("quality", out var requested) && requested.ValueKind == JsonValueKind.String)
                        {
                            var next = requested.GetString();
                            if (next is "low" or "balanced" or "high") _quality = next;
                        }
                    }
                    consecutiveErrors = 0;
                    // Esta consulta também renova a autorização de controle e detecta revogação.
                    var input = await PostAsync("poll", new() { ["control"] = true }, ct);
                    if (input.TryGetProperty("events", out var events) && events.ValueKind == JsonValueKind.Array)
                        ApplyInput(events);
                    await Task.Delay(changed ? 350 : 700, ct);
                }
                catch (RemoteSessionEndedException) { break; }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { break; }
                catch (Exception ex) when (ex is IOException or HttpRequestException or PlatformException or ExternalException)
                {
                    RuntimeLog.Write("Transmissão remota #" + _commandId + ": " + ex.Message);
                    if (++consecutiveErrors >= 5) throw new InvalidOperationException("A comunicação/captura remota falhou repetidamente.", ex);
                    // Reconexão progressiva evita rajadas de solicitações durante falhas de rede.
                    await Task.Delay(Math.Min(6000, 600 * (1 << (consecutiveErrors - 1))), ct);
                }
            }
        }
        finally
        {
            _active = false;
            RuntimeLog.Write("Sessão remota #" + _commandId + " encerrada.");
        }
    }

    private static byte[] CaptureJpeg(string profile)
    {
        // Fallback GDI da sessão interativa. Perfis limitam banda sem alterar o protocolo existente.
        var width = GetSystemMetrics(0); var height = GetSystemMetrics(1);
        if (width < 1 || height < 1 || width > 16384 || height > 16384)
            throw new InvalidOperationException("Monitor principal indisponível para captura.");
        using var screen = new Bitmap(width, height, PixelFormat.Format24bppRgb);
        using (var g = Graphics.FromImage(screen)) g.CopyFromScreen(0, 0, 0, 0, new Size(width, height), CopyPixelOperation.SourceCopy);
        var maxWidth = profile == "low" ? 960.0 : profile == "high" ? 1600.0 : 1280.0;
        var maxHeight = profile == "low" ? 600.0 : profile == "high" ? 1000.0 : 800.0;
        var factor = Math.Min(1.0, Math.Min(maxWidth / width, maxHeight / height));
        using var scaled = new Bitmap(Math.Max(1, (int)(width * factor)), Math.Max(1, (int)(height * factor)));
        using (var g = Graphics.FromImage(scaled))
        {
            g.InterpolationMode = InterpolationMode.Bilinear;
            g.DrawImage(screen, 0, 0, scaled.Width, scaled.Height);
        }
        var encoder = ImageCodecInfo.GetImageEncoders().FirstOrDefault(x => x.MimeType == "image/jpeg")
            ?? throw new InvalidOperationException("Codificador JPEG indisponível no Windows.");
        long[] qualities = profile == "low" ? [35, 28, 20]
            : profile == "high" ? [72, 55, 35, 22] : [55, 40, 28, 22];
        foreach (var quality in qualities)
        {
            using var buffer = new MemoryStream();
            using var opts = new EncoderParameters(1);
            opts.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, quality);
            scaled.Save(buffer, encoder, opts);
            if (buffer.Length <= 300000) return buffer.ToArray();
        }
        throw new InvalidOperationException("Imagem excede o limite de envio. Reduza a resolução do monitor.");
    }

    private static void ApplyInput(JsonElement events)
    {
        // Apenas entrada básica de mouse/teclado na área de trabalho atual. Não opera
        // na tela de bloqueio do Windows, UAC, desktops elevados ou sessões sem usuário.
        var count = 0;
        foreach (var e in events.EnumerateArray())
        {
            if (++count > 80) break;
            if (!e.TryGetProperty("t", out var type)) continue;
            string kind = type.GetString() ?? "";
            if (kind is "m" or "d" or "u")
            {
                var x = e.TryGetProperty("x", out var xx) ? Math.Clamp(xx.GetDouble(), 0, 1) : 0;
                var y = e.TryGetProperty("y", out var yy) ? Math.Clamp(yy.GetDouble(), 0, 1) : 0;
                var pointX = (int)(x * Math.Max(1, GetSystemMetrics(0) - 1));
                var pointY = (int)(y * Math.Max(1, GetSystemMetrics(1) - 1));
                SetCursorPos(pointX, pointY);
                if (kind is "d" or "u")
                {
                    int button = e.TryGetProperty("b", out var b) ? b.GetInt32() : 0;
                    uint flag = button switch
                    {
                        1 => kind == "d" ? 0x0008u : 0x0010u,
                        2 => kind == "d" ? 0x0020u : 0x0040u,
                        _ => kind == "d" ? 0x0002u : 0x0004u,
                    };
                    mouse_event(flag, 0, 0, 0, UIntPtr.Zero);
                }
            }
            else if (kind == "k" && e.TryGetProperty("c", out var code))
            {
                var key = code.GetString() ?? "";
                if (key.Length == 1 && char.IsLetterOrDigit(key[0]))
                {
                    var vk = (byte)char.ToUpperInvariant(key[0]);
                    var down = e.TryGetProperty("d", out var d) && d.GetInt32() != 0;
                    keybd_event(vk, 0, down ? 0u : 2u, UIntPtr.Zero);
                }
            }
        }
    }

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        _stop.Cancel();
        _http.Dispose();
    }
    [DllImport("user32.dll")] private static extern int GetSystemMetrics(int index);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool SetCursorPos(int x, int y);
    [DllImport("user32.dll")] private static extern void mouse_event(uint flags, uint x, uint y, uint data, UIntPtr extraInfo);
    [DllImport("user32.dll")] private static extern void keybd_event(byte key, byte scan, uint flags, UIntPtr extraInfo);
    private sealed class RemoteSessionEndedException : Exception { }
}
