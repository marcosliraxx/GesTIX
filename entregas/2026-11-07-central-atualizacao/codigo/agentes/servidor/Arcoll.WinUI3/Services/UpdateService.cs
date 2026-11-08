// Caminho: agentes/servidor/Arcoll.WinUI3/Services/UpdateService.cs
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

    private static string UiResultFile => Path.Combine(Root, "update-ui-result.json");
    private static void WriteState(string path, object state)
    {
        Directory.CreateDirectory(Root);
        var temp = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try { File.WriteAllText(temp, JsonSerializer.Serialize(state)); File.Move(temp, path, true); }
        finally { if (File.Exists(temp)) File.Delete(temp); }
    }
    // Kept for compatible callers; progress is now rendered in the agent, never in a PowerShell window.
    internal static void StartProgressWindow(string updateId) { }
    internal static string SessionId => UpdatePresentation.Current.Id;
    private static int _active;
    private static CancellationTokenSource? _preparation;
    public static void CancelPreparation(){if(UpdatePresentation.Current.CanCancel)_preparation?.Cancel();}
    public static async Task<UpdateOutcome> StageAndLaunchAsync(PlatformClient platform,long commandId,bool force,Action<int,string> progress,CancellationToken ct)
    {
        if(File.Exists(PendingFile)&&UpdatePresentation.Current.Status is "working" or "restarting")throw new InvalidOperationException("A atualização anterior ainda está sendo confirmada. Aguarde a conclusão.");
        if(Interlocked.CompareExchange(ref _active,1,0)!=0)throw new InvalidOperationException("Uma atualização já está em andamento.");
        var launched=false;using var linked=CancellationTokenSource.CreateLinkedTokenSource(ct);_preparation=linked;
        void Report(int percent,string message){UpdatePresentation.Report(percent,message);progress(percent,message);}
        try{UpdatePresentation.Begin(Guid.NewGuid().ToString("N"));var outcome=await Task.Run(()=>StageCoreAsync(platform,commandId,force,Report,linked.Token),linked.Token);launched=outcome.Launched;if(!launched)UpdatePresentation.Finish(true,outcome.Message);return outcome;}
        catch(OperationCanceledException){UpdatePresentation.Cancelled();throw;}
        catch(Exception error){UpdatePresentation.Finish(false,error.Message);throw;}
        finally{_preparation=null;if(!launched)Interlocked.Exchange(ref _active,0);}
    }
    internal static async Task AwaitInstallerAsync(Process process,string id,Action<int,string> progress,CancellationToken ct)
    {
        // A spawned worker has ownership now: do not cancel midway through a verified staging/swap.
        UpdatePresentation.Report(UpdatePresentation.Current.Percent,"Preparando os arquivos em segundo plano…",false);
        var until=DateTimeOffset.UtcNow.AddMinutes(40);
        while(DateTimeOffset.UtcNow<until)
        {
            UpdatePresentation.PollReceipts();
            if(UpdatePresentation.Current.Status=="failed")throw new InvalidOperationException(UpdatePresentation.Current.Message);
            var handoff=Path.Combine(Root,"update-handoff.json");
            try{if(File.Exists(handoff)){using var doc=JsonDocument.Parse(File.ReadAllText(handoff));if(UpdatePresentation.Matches(doc.RootElement,id)){UpdatePresentation.Handoff();UpdatePresentation.Write(Path.Combine(Root,"update-handoff-ack.json"),new{update_id=id});return;}}}
            catch(Exception error)when(error is IOException or JsonException){}
            if(process.HasExited)throw new InvalidOperationException("O instalador encerrou antes de preparar a troca (código "+process.ExitCode+").");
            await Task.Delay(500,CancellationToken.None);
        }
        // The worker must not replace the running agent without this operation's explicit handoff.
        try{if(!process.HasExited)process.Kill(true);}catch(Exception){}
        throw new TimeoutException("Preparação excedeu 40 minutos. A instalação atual foi mantida.");
    }

    internal static void ReportProgressState(string updateId, int percent, string message)
    {
        WriteState(Path.Combine(Root, "update-progress.json"), new { update_id = updateId, percent, message });
    }

    private static async Task<UpdateOutcome> StageCoreAsync(PlatformClient platform, long commandId, bool force, Action<int, string> progress, CancellationToken ct)
    {
        // Caminho prioritario sem compilacao: binarios precompilados, delta e SHA256.
        // Sem release precompilada publicada, usa o fluxo anterior uma unica vez para migracao.
        var fast = await PrecompiledUpdateService.TryStageAsync(platform,commandId,force,progress,ct);
        if (fast != null) return fast;
        // Sem release binaria publicada no servidor: o computador precisara compilar (lento, exige SDK .NET).
        // Informar na fila da plataforma para o administrador publicar a release precompilada.
        RuntimeLog.Write("Release precompilada nao publicada para esta arquitetura; usando atualizacao por compilacao local.");
        progress(4, "Release binária não publicada no servidor; compilando no computador (pode demorar vários minutos).");
        if (commandId > 0)
        {
            try { await platform.AckAsync(commandId, "running", "Atualização 4%: release binária não publicada; compilando no computador (pode demorar vários minutos).", ct); }
            catch (Exception ex) when (ex is HttpRequestException or PlatformException or TaskCanceledException) { }
        }
        var updateId = SessionId;
        var lastReport = DateTimeOffset.MinValue;
        async Task ReportAsync(int percent, string message)
        {
            progress(percent, message);
            try { WriteState(Path.Combine(Root, "update-progress.json"), new { update_id = updateId, percent, message }); }
            catch (IOException) { } catch (UnauthorizedAccessException) { }
            var now = DateTimeOffset.UtcNow;
            if (commandId < 1 || (percent < 55 && now - lastReport < TimeSpan.FromSeconds(5))) return;
            lastReport = now;
            try { await platform.AckAsync(commandId, "running", $"Atualização {percent}%: {message}", ct); }
            catch (Exception ex) when (ex is HttpRequestException or PlatformException or TaskCanceledException)
            { RuntimeLog.Write("Aviso de progresso da atualização não entregue: " + ex.Message); }
        }
        // A fila de operacoes da plataforma e a unica interface de progresso remoto.
        // O atualizador ainda grava recibos duraveis para diagnostico e confirmacao.
        try
        {
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
            // A versão pode permanecer igual quando o servidor publica correções nos fontes.
            // A confirmação de atualização usa o hash real do ZIP, não só a versão.
            var installedPackage = Path.Combine(Root, "installed-package.sha256");
            string previousHash = "";
            try { if (File.Exists(installedPackage)) previousHash = File.ReadAllText(installedPackage).Trim(); }
            catch (IOException) { } catch (UnauthorizedAccessException) { }
            if (!force && previousHash.Equals(expected, StringComparison.OrdinalIgnoreCase))
            {
                WriteState(UiResultFile, new { update_id = updateId, ok = true, unchanged = true, message = "Arquivos da versão publicada já instalados." });
                return new UpdateOutcome(false, $"Os arquivos publicados da versão {AppInfo.Version} já estão instalados.");
            }
            if (!force && current is not null && next < current)
                throw new InvalidOperationException("A plataforma publicou uma versão anterior à instalada; atualização normal cancelada.");

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
                // Falhar durante a validacao do ZIP, antes de encerrar a instancia atual.
                foreach (var required in new[] { "index.html", "styles.css", "app.js" })
                {
                    var entry = archive.GetEntry("Arcoll.WinUI3/Assets/hybrid/dashboard/" + required);
                    if (entry is null || entry.Length == 0 || entry.Length > 524288)
                        throw new InvalidOperationException("Atualização incompleta: recurso do painel híbrido ausente ou inválido (" + required + ").");
                }
            }
            ZipFile.ExtractToDirectory(zipPath, folder, true);
            var script = Path.Combine(folder, "Arcoll.WinUI3", "INSTALAR_WINUI3.ps1");
            if (!File.Exists(script)) throw new InvalidOperationException("O pacote não contém o instalador.");

            File.WriteAllText(PendingFile, JsonSerializer.Serialize(new { update_id = updateId, command_id = commandId, version = published, package_hash = expected, force, integrated = true, started = DateTimeOffset.UtcNow.ToUnixTimeSeconds() }));
            if (File.Exists(ResultFile)) File.Delete(ResultFile);
            if (File.Exists(UiResultFile)) File.Delete(UiResultFile);
            StartProgressWindow(updateId);
            await ReportAsync(55, "Iniciando a instalação da nova versão…");
            var args = $"-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File \"{script}\" -Update -Integrated -UpdateId {updateId} -ParentPid {Environment.ProcessId}" + (force ? " -ForceRebuild" : "");
            ct.ThrowIfCancellationRequested();
            UpdatePresentation.Report(55,"Preparando a nova versão em segundo plano…",false);
            try
            {
                using var installer = Process.Start(new ProcessStartInfo("powershell.exe", args) { UseShellExecute = false, CreateNoWindow = true, WorkingDirectory = Path.GetDirectoryName(script)! });
                if (installer is null)
                    throw new InvalidOperationException("Não foi possível iniciar o instalador nativo.");
                // Captura falhas imediatas (argumentos, PowerShell ou script ausente)
                // ANTES de encerrar a instancia que ainda esta operacional.
                await Task.Delay(500, CancellationToken.None);
                if (installer.HasExited && installer.ExitCode != 0)
                    throw new InvalidOperationException($"Instalador de atualização encerrou imediatamente com código {installer.ExitCode}. Consulte os logs locais.");
                await AwaitInstallerAsync(installer,updateId,progress,CancellationToken.None);
            }
            catch
            {
                try { File.Delete(PendingFile); } catch (IOException) { }
                throw;
            }
            RuntimeLog.Write($"Atualização {AppInfo.Version} → {published} entregue ao instalador" + (force ? " (forçada)" : ""));
            return new UpdateOutcome(true, "Atualização iniciada");
        }
        catch (Exception ex)
        {
            try { WriteState(UiResultFile, new { update_id = updateId, ok = false, message = ex.Message }); }
            catch (IOException) { } catch (UnauthorizedAccessException) { }
            throw;
        }
    }

    /// <summary>Confirma na plataforma o comando agent_update pendente, depois que o agente atualizado reabriu.</summary>
    public static async Task CompletePendingAsync(PlatformClient platform, CancellationToken ct)
    {
        if (!File.Exists(PendingFile)) return;
        string pendingUpdateId = ""; long id = 0; long started = 0; string expectedVersion = ""; string packageHash = "";
        try
        {
            using var doc = JsonDocument.Parse(File.ReadAllText(PendingFile));
            pendingUpdateId=doc.RootElement.TryGetProperty("update_id",out var pendingKey)?pendingKey.GetString()??"":"";
            id = doc.RootElement.TryGetProperty("command_id", out var c) && c.TryGetInt64(out var n) ? n : 0;
            expectedVersion = doc.RootElement.TryGetProperty("version", out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "";
            packageHash = doc.RootElement.TryGetProperty("package_hash", out var ph) && ph.ValueKind == JsonValueKind.String ? ph.GetString() ?? "" : "";
            started = doc.RootElement.TryGetProperty("started", out var s) && s.TryGetInt64(out var t) ? t : 0;
        }
        catch (Exception ex) when (ex is JsonException or IOException) { }
        var ok = false; var message = "";
        if (File.Exists(ResultFile))
        {
            try
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(ResultFile));
                if(!UpdatePresentation.Matches(doc.RootElement,pendingUpdateId))return;
                ok = doc.RootElement.TryGetProperty("ok", out var o) && o.ValueKind == JsonValueKind.True;
                message = doc.RootElement.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String ? m.GetString() ?? "" : "";
            }
            catch (Exception ex) when (ex is JsonException or IOException) { message = "Resultado da atualização ilegível."; }
        }
        else if (DateTimeOffset.UtcNow.ToUnixTimeSeconds() - started < 25 * 60) return; // instalador ainda trabalhando
        else message = "A atualização não foi concluída. Consulte os registros em %LOCALAPPDATA%\\TI-Digital\\Logs.";
        if (ok && !string.Equals(expectedVersion, AppInfo.Version, StringComparison.Ordinal))
        { ok = false; message = $"Versão instalada {AppInfo.Version} diferente da versão esperada {expectedVersion}."; }
        if (ok && packageHash.Length == 64 && packageHash.All(Uri.IsHexDigit))
        {
            try { WriteState(Path.Combine(Root, "installed-package-state.json"), new { version = AppInfo.Version, hash = packageHash });
                  File.WriteAllText(Path.Combine(Root, "installed-package.sha256"), packageHash); }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { RuntimeLog.Write("Não foi possível registrar o hash instalado: " + ex.Message); }
        }
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
