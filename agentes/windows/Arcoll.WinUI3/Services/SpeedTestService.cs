// Caminho: agentes/windows/Arcoll.WinUI3/Services/SpeedTestService.cs
using System.Diagnostics;
using System.Security.Cryptography;

namespace Arcoll.WinUI3.Services;

public sealed record SpeedResult(double? Download, double? Upload, double? LatencyMs, string Server);

/// <summary>Teste de banda (mesmos endpoints e volumes do agente Python: Cloudflare, 10 MB de descida e 3 MB de subida).</summary>
public static class SpeedTestService
{
    private static readonly HttpClient Http = CreateClient();
    private static HttpClient CreateClient()
    {
        var c = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromSeconds(40) };
        c.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollSpeedConnection/4.0");
        c.DefaultRequestHeaders.CacheControl = new System.Net.Http.Headers.CacheControlHeaderValue { NoCache = true };
        return c;
    }

    public static async Task<SpeedResult> RunAsync(IProgress<(double Percent, string Message, double? Download)>? progress, CancellationToken ct)
    {
        double? down = null, up = null;
        var gateway = NetworkEngine.Local().Gateway;
        var latency = await NetworkEngine.PingAsync(string.IsNullOrWhiteSpace(gateway) ? "1.1.1.1" : gateway, 1500);
        progress?.Report((10, "Medindo download…", null));
        var url = "https://speed.cloudflare.com/__down?bytes=10000000&cache=" + DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        var sw = Stopwatch.StartNew(); long total = 0;
        using (var response = await Http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct))
        {
            response.EnsureSuccessStatusCode();
            await using var stream = await response.Content.ReadAsStreamAsync(ct);
            var buffer = new byte[65536]; int n;
            while ((n = await stream.ReadAsync(buffer, ct)) > 0)
            {
                total += n;
                progress?.Report((Math.Min(48, 10 + total / 10_000_000d * 38), "Recebendo dados para o teste de download…", Math.Round(total * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2)));
            }
        }
        down = Math.Round(total * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2);
        progress?.Report((55, "Medindo upload…", down));
        var payload = RandomNumberGenerator.GetBytes(3 * 1024 * 1024);
        sw.Restart();
        using (var content = new ByteArrayContent(payload))
        {
            content.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("application/octet-stream");
            using var response = await Http.PostAsync("https://speed.cloudflare.com/__up", content, ct);
            response.EnsureSuccessStatusCode();
        }
        up = Math.Round(payload.Length * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2);
        progress?.Report((100, "Teste concluído.", down));
        return new SpeedResult(down, up, latency, "Cloudflare Speed Test");
    }
}
