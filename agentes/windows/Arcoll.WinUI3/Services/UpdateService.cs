// Caminho: agentes/windows/Arcoll.WinUI3/Services/UpdateService.cs
using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

public sealed record UpdateOutcome(bool Launched, string Message);

/// <summary>
/// Atualização remota e forçada do agente nativo. Baixa o pacote autenticado (download-agent.php?mode=native-update), confere o SHA-256,
/// extrai e entrega a troca a um processo separado (INSTALAR_WINUI3.ps1 -Update), que aguarda o agente encerrar, compila, substitui e reabre em segundo plano.
/// O comando da plataforma só é confirmado depois que o agente atualizado volta a executar (CompletePendingAsync).
/// </summary>
public static class UpdateService
{
    private static string Root => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital");
    public static string PendingFile => Path.Combine(Root, "update-pending.json");
    public static string ResultFile => Path.Combine(Root, "update-result.json");

    public static async Task<UpdateOutcome> StageAndLaunchAsync(PlatformClient platform, long commandId, bool force, Action<int, string> progress, CancellationToken ct)
    {
        var lastReport = DateTimeOffset.MinValue;
        async Task ReportAsync(int percent, string message)
        {
            progress(percent, message);
            var now = DateTimeOffset.UtcNow;
            if (commandId < 1 || (percent < 55 && now - lastReport < TimeSpan.FromSeconds(5))) return;
            lastReport = now;
            try { await platform.AckAsync(commandId, "running", $"Atualização {percent}%: {message}", ct); }
            catch (Exception ex) when (ex is HttpRequestException or PlatformException or TaskCanceledException)
            { RuntimeLog.Write("Aviso de progresso da atualização não entregue: " + ex.Message); }
        }
        var cfg = platform.Config;
        if (!cfg.IsComplete) throw new InvalidOperationException("Agente sem autorização para atualizar.");
        await ReportAsync(3, force ? "Preparando reinstalação integral…" : "Verificando atualização disponível…");
        Directory.CreateDirectory(Root);
        var work = Path.Combine(Root, "Update");
        if (Directory.Exists(work)) { try { Directory.Delete(work, true); } catch (IOException) { } catch (UnauthorizedAccessException) { } }
        Directory.CreateDirectory(work);
        var zipPath = Path.Combine(work, "pacote.zip");

        using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromMinutes(6) };
        PlatformClient.ApplyDefaults(http);
        using var request = new HttpRequestMessage(HttpMethod.Post, cfg.Origin + "/api/download-agent.php?mode=native-update")
        { Content = PlatformClient.JsonBody(new { fingerprint = cfg.Fingerprint, site_code = cfg.SiteCode, download_key = cfg.DownloadKey }) };
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException($"O servidor recusou o pacote (HTTP {(int)response.StatusCode}).");
        var published = response.Headers.TryGetValues("X-Agent-Version", out var v) ? v.FirstOrDefault() ?? "" : "";
        var expected = response.Headers.TryGetValues("X-Package-SHA256", out var h) ? (h.FirstOrDefault() ?? "").ToLowerInvariant() : "";
        if (!Version.TryParse(published, out var next)) throw new InvalidOperationException("Versão publicada inválida.");
        if (expected.Length != 64 || expected.Any(c => !Uri.IsHexDigit(c))) throw new InvalidOperationException("O servidor não informou a integridade SHA-256 do pacote.");
        if (response.Content.Headers.ContentLength is long advertised && advertised > 104857600) throw new InvalidOperationException("Pacote de atualização excede o limite de 100 MB.");
        _ = Version.TryParse(AppInfo.Version, out var current);
        if (!force && current is not null && next <= current) return new UpdateOutcome(false, $"O agente já está na versão mais recente ({AppInfo.Version}).");

        var total = response.Content.Headers.ContentLength ?? 0; long done = 0;
        await using (var net = await response.Content.ReadAsStreamAsync(ct))
        await using (var file = File.Create(zipPath))
        {
            var buffer = new byte[81920]; int read;
            while ((read = await net.ReadAsync(buffer, ct)) > 0)
            {
                done += read;
                if (done > 104857600) throw new InvalidOperationException("O pacote de atualização excedeu 100 MB.");
                await file.WriteAsync(buffer.AsMemory(0, read), ct);
                if (total > 0) await ReportAsync(5 + (int)(35 * done / total), $"Baixando atualização {published}… {done / 1024} KB");
            }
        }
        await ReportAsync(42, "Verificando integridade do pacote…");
        string actual;
        await using (var check = File.OpenRead(zipPath)) actual = Convert.ToHexString(await SHA256.HashDataAsync(check, ct)).ToLowerInvariant();
        if (actual != expected) throw new InvalidOperationException("Pacote corrompido: o SHA-256 não confere.");

        await ReportAsync(48, "Extraindo arquivos…");
        var folder = Path.Combine(work, "pacote");
        // SHA-256 valida o arquivo recebido; limites de expansão evitam ZIP bombs.
        using (var archive = ZipFile.OpenRead(zipPath))
        {
            if (archive.Entries.Count > 550 || archive.Entries.Sum(e => e.Length) > 104857600L)
                throw new InvalidOperationException("Pacote contém arquivos em excesso ou ultrapassa 100 MB descompactados.");
            if (archive.Entries.Any(e => e.FullName.StartsWith('/') || e.FullName.Contains("..", StringComparison.Ordinal)
                || e.FullName.Contains(':') || e.FullName.Contains('\\')))
                throw new InvalidOperationException("Pacote contém caminhos não permitidos.");
        }
        ZipFile.ExtractToDirectory(zipPath, folder, true);
        var script = Path.Combine(folder, "Arcoll.WinUI3", "INSTALAR_WINUI3.ps1");
        if (!File.Exists(script)) throw new InvalidOperationException("O pacote não contém o instalador.");

        File.WriteAllText(PendingFile, JsonSerializer.Serialize(new { command_id = commandId, version = published, force, started = DateTimeOffset.UtcNow.ToUnixTimeSeconds() }));
        if (File.Exists(ResultFile)) File.Delete(ResultFile);
        await ReportAsync(55, "Iniciando a instalação da nova versão…");
        var args = $"-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File \"{script}\" -Update -ParentPid {Environment.ProcessId}" + (force ? " -ForceRebuild" : "");
        try
        {
            using var installer = Process.Start(new ProcessStartInfo("powershell.exe", args) { UseShellExecute = false, CreateNoWindow = true, WorkingDirectory = Path.GetDirectoryName(script)! });
            if (installer is null)
                throw new InvalidOperationException("Não foi possível iniciar o instalador nativo.");
        }
        catch
        {
            try { File.Delete(PendingFile); } catch (IOException) { }
            throw;
        }
        RuntimeLog.Write($"Atualização {AppInfo.Version} → {published} entregue ao instalador" + (force ? " (forçada)" : ""));
        return new UpdateOutcome(true, "Atualização iniciada");
    }

    /// <summary>Confirma na plataforma o comando agent_update pendente, depois que o agente atualizado reabriu.</summary>
    public static async Task CompletePendingAsync(PlatformClient platform, CancellationToken ct)
    {
        if (!File.Exists(PendingFile)) return;
        long id = 0; long started = 0; string expectedVersion = "";
        try
        {
            using var doc = JsonDocument.Parse(File.ReadAllText(PendingFile));
            id = doc.RootElement.TryGetProperty("command_id", out var c) && c.TryGetInt64(out var n) ? n : 0;
            expectedVersion = doc.RootElement.TryGetProperty("version", out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "";
            started = doc.RootElement.TryGetProperty("started", out var s) && s.TryGetInt64(out var t) ? t : 0;
        }
        catch (Exception ex) when (ex is JsonException or IOException) { }
        var ok = false; var message = "";
        if (File.Exists(ResultFile))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(ResultFile));
                ok = doc.RootElement.TryGetProperty("ok", out var o) && o.ValueKind == JsonValueKind.True;
                message = doc.RootElement.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String ? m.GetString() ?? "" : "";
            }
            catch (Exception ex) when (ex is JsonException or IOException) { message = "Resultado da atualização ilegível."; }
        }
        else if (DateTimeOffset.UtcNow.ToUnixTimeSeconds() - started < 25 * 60) return; // instalador ainda trabalhando
        else message = "A atualização não foi concluída. Consulte os registros em %LOCALAPPDATA%\\TI-Digital\\Logs.";
        if (ok && !string.Equals(expectedVersion, AppInfo.Version, StringComparison.Ordinal))
        { ok = false; message = $"Versão instalada {AppInfo.Version} diferente da versão esperada {expectedVersion}."; }
        if (id > 0)
        {
            var acknowledged = await platform.AckAsync(id, ok ? "completed" : "failed", ok ? $"Agente atualizado para {AppInfo.Version}. {message}".Trim() : message, ct);
            if (!acknowledged.TryGetProperty("acknowledged", out var receipt) || receipt.ValueKind != JsonValueKind.True)
            {
                RuntimeLog.Write($"Comando {id} não estava mais ativo na plataforma; resultado retido nos logs do agente.");
                // O servidor respondeu definitivamente que o comando nao esta
                // em execucao. Nao repetir a mesma confirmacao a cada 3 segundos.
                try { File.Delete(PendingFile); File.Delete(ResultFile); } catch (IOException) { }
                return;
            }
        }
        try { File.Delete(PendingFile); File.Delete(ResultFile); } catch (IOException) { }
    }
}
