// Caminho: agentes/windows/Arcoll.WinUI3/Services/PresenceService.cs
using System.Security.Cryptography;
using System.Text;
using Microsoft.Win32;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

/// <summary>Presenca do agente na plataforma (equivalente a prelogin_presence_loop do agente Python): POST agent-presence a cada 15 s.</summary>
public sealed class PresenceService : IDisposable
{
    private readonly HttpClient _http = new(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromSeconds(8) };
    private readonly CancellationTokenSource _cts = new();
    private Task? _loop;

    public bool Active { get; private set; }

    public PresenceService() { PlatformClient.ApplyDefaults(_http); }

    public static string Fingerprint()
    {
        var machine = "";
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");
            machine = Convert.ToString(key?.GetValue("MachineGuid")) ?? "";
        }
        catch (Exception) { }
        var raw = !string.IsNullOrEmpty(machine) ? machine : string.Join("|", Environment.MachineName, "Windows", Environment.Is64BitOperatingSystem ? "AMD64" : "x86");
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes("arcoll-speed-connection|" + raw))).ToLowerInvariant();
    }

    public void Start()
    {
        if (_loop != null) return;
        _loop = Task.Run(() => RunAsync(_cts.Token));
    }

    private async Task RunAsync(CancellationToken token)
    {
        var cfg = AgentConfig.Load();
        if (!cfg.IsComplete) return;
        var endpoint = cfg.Origin + "/api/api.php?action=agent-presence";
        var payload = new { fingerprint = cfg.Fingerprint, site_code = cfg.SiteCode, download_key = cfg.DownloadKey, state = "online", version = AppInfo.Version };
        Active = true;
        while (!token.IsCancellationRequested)
        {
            try
            {
                using var content = PlatformClient.JsonBody(payload);
                using var response = await _http.PostAsync(endpoint, content, token);
                await response.Content.ReadAsStringAsync(token);
            }
            catch (Exception) { /* falha de rede temporaria: tenta de novo no proximo ciclo */ }
            try { await Task.Delay(TimeSpan.FromSeconds(15), token); } catch (OperationCanceledException) { break; }
        }
        Active = false;
    }

    public void Dispose() { _cts.Cancel(); _http.Dispose(); _cts.Dispose(); }
}
