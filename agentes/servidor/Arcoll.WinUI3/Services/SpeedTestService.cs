// Caminho: agentes/servidor/Arcoll.WinUI3/Services/SpeedTestService.cs
using System.Diagnostics;
using System.Security.Cryptography;
using Arcoll.WinUI3.Models;
namespace Arcoll.WinUI3.Services;
public sealed record SpeedResult(double? Download, double? Upload, double? LatencyMs, string Server);
/// <summary>Medições reais e independentes: falha de banda não impede o diagnóstico da conexão.</summary>
public static class SpeedTestService
{
    public static async Task<SpeedResult> RunAsync(IProgress<(double Percent, string Message, double? Download)>? progress, CancellationToken ct)
    {
        using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromSeconds(40) };
        http.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollSpeedConnection/" + AppInfo.Version);
        http.DefaultRequestHeaders.CacheControl = new System.Net.Http.Headers.CacheControlHeaderValue { NoCache = true };
        double? down = null, up = null; var errors = new List<string>();
        var latency = await NetworkEngine.PingAsync("1.1.1.1", 1500);
        progress?.Report((10, "Medindo download…", null));
        try
        {
            var sw = Stopwatch.StartNew(); long total = 0;
            using var response = await http.GetAsync("https://speed.cloudflare.com/__down?bytes=10000000&cache=" + DateTimeOffset.UtcNow.ToUnixTimeSeconds(), HttpCompletionOption.ResponseHeadersRead, ct);
            response.EnsureSuccessStatusCode();
            await using var stream = await response.Content.ReadAsStreamAsync(ct); var buffer = new byte[65536]; int count;
            while ((count = await stream.ReadAsync(buffer, ct)) > 0)
            {
                total += count;
                progress?.Report((Math.Min(48, 10 + total / 10_000_000d * 38), "Medindo download…", Math.Round(total * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2)));
            }
            if (total > 0) down = Math.Round(total * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2);
        }
        catch (Exception ex) when (ex is HttpRequestException or IOException || ex is TaskCanceledException && !ct.IsCancellationRequested) { errors.Add("Download não aferido: " + ex.Message); }
        ct.ThrowIfCancellationRequested(); progress?.Report((55, "Medindo upload…", down));
        try
        {
            var data = RandomNumberGenerator.GetBytes(3 * 1024 * 1024); using var content = new ByteArrayContent(data);
            content.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("application/octet-stream"); var sw = Stopwatch.StartNew();
            using var response = await http.PostAsync("https://speed.cloudflare.com/__up", content, ct); response.EnsureSuccessStatusCode();
            up = Math.Round(data.Length * 8 / Math.Max(.001, sw.Elapsed.TotalSeconds) / 1_000_000, 2);
        }
        catch (Exception ex) when (ex is HttpRequestException or IOException || ex is TaskCanceledException && !ct.IsCancellationRequested) { errors.Add("Upload não aferido: " + ex.Message); }
        ct.ThrowIfCancellationRequested(); progress?.Report((80, "Banda medida; verificando conexão…", down));
        return new(down, up, latency, "Cloudflare Speed Test" + (errors.Count > 0 ? " · " + string.Join("; ", errors) : ""));
    }
}
