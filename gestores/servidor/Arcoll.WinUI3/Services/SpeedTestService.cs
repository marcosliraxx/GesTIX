// Caminho: gestores/servidor/Arcoll.WinUI3/Services/SpeedTestService.cs
using System.Diagnostics;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using Arcoll.WinUI3.Models;
namespace Arcoll.WinUI3.Services;

public sealed record SpeedResult(double? Download, double? Upload, double? LatencyMs, string Server,
    double? JitterMs = null, double? LoadedDownloadMs = null, double? LoadedUploadMs = null,
    long DownloadBytes = 0, long UploadBytes = 0);

/// <summary>
/// Goodput HTTP/TLS no equipamento Windows; não mede a banda do servidor cPanel.
/// Amostras concorrentes, com orçamento de bytes e duração, sem acumular downloads na memória.
/// Teste contra o edge Cloudflare; resultados dependem da rota e servidor de destino.
/// </summary>
public static class SpeedTestService
{
    private const string Endpoint = "https://speed.cloudflare.com";
    private static readonly HttpClient Http = CreateHttp();
    private static HttpClient CreateHttp()
    {
        var handler = new SocketsHttpHandler { AllowAutoRedirect = false, AutomaticDecompression = DecompressionMethods.None,
            MaxConnectionsPerServer = 4, PooledConnectionLifetime = TimeSpan.FromMinutes(5) };
        var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(18) };
        client.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollSpeedConnection/" + AppInfo.Version);
        client.DefaultRequestHeaders.CacheControl = new CacheControlHeaderValue { NoCache = true, NoStore = true };
        return client;
    }
    private static double Mbps(long bytes, double seconds) => Math.Round(bytes * 8d / Math.Max(.001, seconds) / 1_000_000d, 2);
    private static double? Median(IEnumerable<double> values)
    {
        var a = values.Where(double.IsFinite).OrderBy(x => x).ToArray();
        return a.Length == 0 ? null : a.Length % 2 == 1 ? a[a.Length/2] : (a[a.Length/2-1]+a[a.Length/2])/2;
    }
    private static async Task<double?> ProbeAsync(CancellationToken ct)
    {
        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(2));
            var watch = Stopwatch.StartNew();
            using var req = new HttpRequestMessage(HttpMethod.Get, Endpoint + "/__down?bytes=0&r=" + Guid.NewGuid().ToString("N"));
            using var res = await Http.SendAsync(req, HttpCompletionOption.ResponseHeadersRead, timeout.Token);
            res.EnsureSuccessStatusCode();
            return watch.Elapsed.TotalMilliseconds;
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex) when (ex is HttpRequestException or IOException or TaskCanceledException) { return null; }
    }
    private static async Task<double?> LoadedProbeAsync(CancellationToken ct)
    {
        var values = new List<double>();
        for (var i=0;i<5 && !ct.IsCancellationRequested;i++)
        {
            var val = await ProbeAsync(ct);
            if (val.HasValue) values.Add(val.Value);
            await Task.Delay(300, ct);
        }
        return Median(values);
    }
    public static async Task<SpeedResult> RunAsync(IProgress<(double Percent, string Message, double? Download)>? progress, CancellationToken ct)
    {
        var latencies = new List<double>();
        progress?.Report((3, "Aferindo latência sem carga…", null));
        for(var i=0;i<9;i++)
        {
            ct.ThrowIfCancellationRequested();
            var value=await ProbeAsync(ct);
            if(value.HasValue)latencies.Add(value.Value);
            progress?.Report((3+i*1.5,"Aferindo latência sem carga…",null));
        }
        var latency=Median(latencies);
        double? jitter=latencies.Count>1?Math.Round(latencies.Zip(latencies.Skip(1),(a,b)=>Math.Abs(b-a)).Average(),2):null;
        double? down=null,up=null,loadedDown=null,loadedUp=null;
        long bytesDown=0,bytesUp=0;
        var errors = new List<string>();
        try
        {
            using var window = CancellationTokenSource.CreateLinkedTokenSource(ct);
            window.CancelAfter(TimeSpan.FromSeconds(8));
            var watch = Stopwatch.StartNew();
            long transferred = 0;
            // Dois fluxos paralelos medem melhor conexões rápidas que uma única transferência de 10 MB.
            async Task Worker()
            {
                var buffer = new byte[65536];
                while(!window.IsCancellationRequested && Interlocked.Read(ref transferred)<96_000_000)
                {
                    using var req=new HttpRequestMessage(HttpMethod.Get,Endpoint+"/__down?bytes=4000000&r="+Guid.NewGuid().ToString("N"));
                    using var res=await Http.SendAsync(req,HttpCompletionOption.ResponseHeadersRead,window.Token);
                    res.EnsureSuccessStatusCode();
                    await using var stream=await res.Content.ReadAsStreamAsync(window.Token);
                    int count;
                    while((count=await stream.ReadAsync(buffer,window.Token))>0)
                    {
                        var total=Interlocked.Add(ref transferred,count);
                        progress?.Report((Math.Min(56,17+watch.Elapsed.TotalSeconds/8*39),"Download · "+Mbps(total,watch.Elapsed.TotalSeconds).ToString("0.00")+" Mbps",Mbps(total,watch.Elapsed.TotalSeconds)));
                    }
                }
            }
            var workers=Task.WhenAll(Worker(),Worker());
            var probe=LoadedProbeAsync(window.Token);
            try{await workers;}catch(OperationCanceledException) when(!ct.IsCancellationRequested) { /* janela encerrada */ }
            catch(HttpRequestException ex){errors.Add("Download: "+ex.Message);}
            bytesDown=Interlocked.Read(ref transferred);
            if(bytesDown>0)down=Mbps(bytesDown,watch.Elapsed.TotalSeconds);
            try{loadedDown=await probe;}catch(OperationCanceledException) when(!ct.IsCancellationRequested) { }
        }
        catch(OperationCanceledException) when(!ct.IsCancellationRequested) { }
        catch(Exception ex) when(ex is HttpRequestException or IOException or TaskCanceledException) { errors.Add("Download: "+ex.Message); }
        ct.ThrowIfCancellationRequested();
        progress?.Report((60,"Preparando upload…",down));
        try
        {
            using var window=CancellationTokenSource.CreateLinkedTokenSource(ct);
            window.CancelAfter(TimeSpan.FromSeconds(7));
            var watch=Stopwatch.StartNew();long sent=0;
            var payload=RandomNumberGenerator.GetBytes(1_000_000);
            async Task Worker()
            {
                while(!window.IsCancellationRequested && Interlocked.Read(ref sent)<48_000_000)
                {
                    using var body=new ByteArrayContent(payload);
                    body.Headers.ContentType=new MediaTypeHeaderValue("application/octet-stream");
                    using var response=await Http.PostAsync(Endpoint+"/__up?r="+Guid.NewGuid().ToString("N"),body,window.Token);
                    response.EnsureSuccessStatusCode();
                    var total=Interlocked.Add(ref sent,payload.Length);
                    progress?.Report((Math.Min(83,60+watch.Elapsed.TotalSeconds/7*23),"Upload · "+Mbps(total,watch.Elapsed.TotalSeconds).ToString("0.00")+" Mbps",down));
                }
            }
            var workers=Task.WhenAll(Worker(),Worker());
            var probe=LoadedProbeAsync(window.Token);
            try{await workers;}catch(OperationCanceledException) when(!ct.IsCancellationRequested) { }
            catch(HttpRequestException ex){errors.Add("Upload: "+ex.Message);}
            bytesUp=Interlocked.Read(ref sent);
            if(bytesUp>0)up=Mbps(bytesUp,watch.Elapsed.TotalSeconds);
            try{loadedUp=await probe;}catch(OperationCanceledException) when(!ct.IsCancellationRequested) { }
        }
        catch(OperationCanceledException) when(!ct.IsCancellationRequested) { }
        catch(Exception ex) when(ex is HttpRequestException or IOException or TaskCanceledException) { errors.Add("Upload: "+ex.Message); }
        ct.ThrowIfCancellationRequested();
        progress?.Report((84,"Finalizando indicadores de qualidade…",down));
        return new(down,up,latency,"Cloudflare Edge · HTTP/TLS"+(errors.Count>0?" · "+string.Join("; ",errors):""),
            jitter,loadedDown,loadedUp,bytesDown,bytesUp);
    }
}
