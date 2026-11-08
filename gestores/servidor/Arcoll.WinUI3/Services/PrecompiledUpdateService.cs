// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PrecompiledUpdateService.cs
using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text;
using Arcoll.WinUI3.Models;
namespace Arcoll.WinUI3.Services;

/// <summary>Entrega binaria incremental autenticada: nunca compila no computador cliente.</summary>
public static class PrecompiledUpdateService
{
    private static string Root => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital");
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = true };
    private static bool SafePath(string name) => name.Length is > 0 and < 240 && !name.StartsWith('/') && !name.Contains('\\') && !name.Contains(':') &&
        name.Split('/').All(part => part.Length > 0 && part != "." && part != ".." && part.All(c => char.IsAsciiLetterOrDigit(c) || c is '.' or '_' or '-'));

    private sealed record CacheEntry(long Size, long Ticks, string Hash);
    private static string CacheFile => Path.Combine(Root, "inventory-cache.json");

    internal static Dictionary<string,string> Inventory(string installed, CancellationToken ct)
    {
        var cache = new Dictionary<string,CacheEntry>(StringComparer.OrdinalIgnoreCase);
        try { if (File.Exists(CacheFile)) cache = JsonSerializer.Deserialize<Dictionary<string,CacheEntry>>(File.ReadAllText(CacheFile), Json) ?? cache; }
        catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException) { cache = new(StringComparer.OrdinalIgnoreCase); }
        var local = new Dictionary<string,string>(StringComparer.OrdinalIgnoreCase);
        var fresh = new Dictionary<string,CacheEntry>(StringComparer.OrdinalIgnoreCase);
        foreach (var file in Directory.EnumerateFiles(installed,"*",SearchOption.AllDirectories))
        {
            ct.ThrowIfCancellationRequested();
            var rel = Path.GetRelativePath(installed,file).Replace('\\','/');
            if (!SafePath(rel) || rel.Equals("gestor-config.json",StringComparison.OrdinalIgnoreCase) || (File.GetAttributes(file) & FileAttributes.ReparsePoint) != 0) continue;
            var info = new FileInfo(file);
            string hash;
            if (cache.TryGetValue(rel, out var known) && known.Size == info.Length && known.Ticks == info.LastWriteTimeUtc.Ticks) hash = known.Hash;
            else using (var input = File.OpenRead(file)) hash = Convert.ToHexString(SHA256.HashData(input)).ToLowerInvariant();
            local[rel] = hash; fresh[rel] = new CacheEntry(info.Length, info.LastWriteTimeUtc.Ticks, hash);
            if (local.Count > 3000) throw new InvalidOperationException("Inventario do gestor excede limite.");
        }
        try { Directory.CreateDirectory(Root); File.WriteAllText(CacheFile, JsonSerializer.Serialize(fresh)); }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        return local;
    }

    public static async Task<UpdateOutcome?> TryStageAsync(PlatformClient platform, long commandId, bool force, Action<int,string> progress, CancellationToken ct)
    {
        var cfg = platform.Config;
        if (!cfg.IsComplete) throw new InvalidOperationException("Gestor sem vinculo autorizado.");
        var installed = Path.Combine(Root, "WinUI3");
        if (!File.Exists(Path.Combine(installed,"Arcoll.WinUI3.exe"))) return null;
        var lastReport = DateTimeOffset.MinValue;
        // Progresso real na fila da plataforma (antes a atualizacao precompilada nao informava nada
        // e a barra do site ficava parada ate o gestor reabrir).
        async Task Report(int percent, string message)
        {
            progress(percent, message);
            var now = DateTimeOffset.UtcNow;
            if (commandId < 1 || now - lastReport < TimeSpan.FromSeconds(4)) return;
            lastReport = now;
            try { await platform.AckAsync(commandId, "running", $"Atualização {percent}%: {message}", ct); }
            catch (Exception ex) when (ex is HttpRequestException or PlatformException or TaskCanceledException)
            { RuntimeLog.Write("Aviso de progresso nao entregue: " + ex.Message); }
        }
        await Report(3, force ? "Preparando reinstalação do pacote..." : "Conferindo arquivos instalados...");
        // Atualizacao forcada reenvia todos os arquivos: nao ha necessidade de inventariar (e reler centenas de MB).
        // Na normal, o conteudo efetivo e conferido; hashes sao reaproveitados quando tamanho e data nao mudaram.
        var local = force ? new Dictionary<string,string>(StringComparer.OrdinalIgnoreCase) : await Task.Run(() => Inventory(installed, ct), ct);
        var work = Path.Combine(Root,"Update",Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(work);
        try
        {
            await Report(8, "Consultando pacote binario publicado...");
            using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromMinutes(12) };
            PlatformClient.ApplyDefaults(http);
            var arch = System.Runtime.InteropServices.RuntimeInformation.OSArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? "win-arm64" : "win-x64";
            var requestBody = new { fingerprint=cfg.Fingerprint, site_code=cfg.SiteCode, download_key=cfg.DownloadKey, arch, force, files=local };
            using var request = new HttpRequestMessage(HttpMethod.Post, cfg.Origin + "/api/download-gestor.php?mode=native-precompiled")
                { Content = PlatformClient.JsonBody(requestBody) };
            using var response = await http.SendAsync(request,HttpCompletionOption.ResponseHeadersRead,ct);
            if (response.StatusCode == System.Net.HttpStatusCode.NotFound || response.StatusCode == System.Net.HttpStatusCode.NotImplemented)
            { try { Directory.Delete(work, true); } catch (IOException) { } return null; } // sem release publicada: usa o fluxo por fontes
            if (!response.IsSuccessStatusCode)
            {
                string detail = "";
                try { detail = (await response.Content.ReadAsStringAsync(ct)).Trim(); } catch (Exception ex) when (ex is HttpRequestException or IOException) { }
                if (detail.Length > 300) detail = detail[..300];
                throw new InvalidOperationException($"Pacote binario indisponivel: HTTP {(int)response.StatusCode}." + (detail.Length > 0 ? " " + detail : ""));
            }
            await Report(20, "Baixando arquivos alterados...");
            var ver = response.Headers.GetValues("X-Gestor-Version").First();
            UpdatePresentation.SetTarget(ver);
            var expected = response.Headers.GetValues("X-Package-SHA256").First();
            if (!AppInfo.TryCompareRelease(ver,AppInfo.Version,out var releaseComparison)) throw new InvalidOperationException("Versao invalida no manifesto remoto.");
            if (!force && releaseComparison < 0) throw new InvalidOperationException("Retrocesso de versao bloqueado.");
            if (expected.Length != 64 || expected.Any(c=>!Uri.IsHexDigit(c))) throw new InvalidOperationException("SHA256 remoto invalido.");
            var zip = Path.Combine(work,"delta.zip");
            await using (var source=await response.Content.ReadAsStreamAsync(ct))
            await using (var dest=File.Create(zip))
            {
                var buffer=new byte[65536]; long count=0; int n;var total=response.Content.Headers.ContentLength??0;var lastDownload=DateTimeOffset.MinValue;
                while((n=await source.ReadAsync(buffer,ct))>0) {
                    count+=n; if(count>750L*1024*1024) throw new InvalidOperationException("Pacote ultrapassa limite de 750 MB.");
                    await dest.WriteAsync(buffer.AsMemory(0,n),ct);
                    if(DateTimeOffset.UtcNow-lastDownload>TimeSpan.FromMilliseconds(350)||count==total){lastDownload=DateTimeOffset.UtcNow;await Report(total>0?20+(int)Math.Min(20,20*count/total):20,$"Recebendo arquivos: {count/1048576d:F1} MiB…");}
                }
            }
            await Report(42,"Conferindo SHA-256 do pacote recebido…");
            await using (var check=File.OpenRead(zip))
            { if(!Convert.ToHexString(await SHA256.HashDataAsync(check,ct)).Equals(expected,StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Falha de integridade SHA256 no pacote precompilado."); }
            await Report(48,"Validando manifesto e arquivos alterados…");
            using var archive=ZipFile.OpenRead(zip);
            if(archive.Entries.Count>3500 || archive.Entries.Sum(e=>e.Length)>1200L*1024*1024) throw new InvalidOperationException("Expansao do pacote excede limites.");
            var metadata=archive.GetEntry("release.json") ?? throw new InvalidOperationException("Manifesto ausente.");
            string releaseText;
            using(var rd=new StreamReader(metadata.Open(),Encoding.UTF8)) releaseText=await rd.ReadToEndAsync(ct);
            using var doc=JsonDocument.Parse(releaseText);
            var release=doc.RootElement;
            if(release.GetProperty("version").GetString()!=ver || release.GetProperty("arch").GetString()!=arch) throw new InvalidOperationException("Versao ou arquitetura divergente.");
            var files=release.GetProperty("files");
            var changed=new List<string>();
            foreach(var item in files.EnumerateObject()) {
                var rel=item.Name; var hash=item.Value.GetString()??"";
                if(!SafePath(rel) || hash.Length!=64 || hash.Any(c=>!Uri.IsHexDigit(c))) throw new InvalidOperationException("Manifesto invalido.");
                if (force || !local.TryGetValue(rel,out var currentHash) || !currentHash.Equals(hash,StringComparison.OrdinalIgnoreCase)) changed.Add(rel);
            }
            if (!force && changed.Count==0) { try { Directory.Delete(work,true); } catch(IOException) { } return new UpdateOutcome(false,"O gestor ja possui todos os arquivos da versao "+ver+"."); }
            foreach(var entry in archive.Entries) {
                if(entry.FullName is "release.json" or "APLICAR_PRECOMPILADO.ps1") continue;
                if(!entry.FullName.StartsWith("files/",StringComparison.Ordinal) || !SafePath(entry.FullName[6..]) || !changed.Contains(entry.FullName[6..],StringComparer.OrdinalIgnoreCase))
                    throw new InvalidOperationException("Entrada nao permitida no pacote.");
            }
            foreach(var rel in changed) {
                var e=archive.GetEntry("files/"+rel) ?? throw new InvalidOperationException("Arquivo alterado ausente: "+rel);
                var target=Path.Combine(work,"files",rel.Replace('/',Path.DirectorySeparatorChar));
                Directory.CreateDirectory(Path.GetDirectoryName(target)!);
                e.ExtractToFile(target);
                using var incoming = File.OpenRead(target);
                if (!Convert.ToHexString(SHA256.HashData(incoming)).Equals(files.GetProperty(rel).GetString(),StringComparison.OrdinalIgnoreCase))
                    throw new InvalidOperationException("Arquivo alterado com hash divergente: "+rel);
            }
            var apply=archive.GetEntry("APLICAR_PRECOMPILADO.ps1") ?? throw new InvalidOperationException("Aplicador ausente.");
            var script=Path.Combine(work,"APLICAR_PRECOMPILADO.ps1"); apply.ExtractToFile(script);
            File.WriteAllText(Path.Combine(work,"release.json"),releaseText,Encoding.UTF8);
            if (!File.Exists(Path.Combine(work,"files","Arcoll.WinUI3.exe")) && !File.Exists(Path.Combine(installed,"Arcoll.WinUI3.exe")))
                throw new InvalidOperationException("Executavel WinUI3 ausente.");
            await Report(75, $"Aplicando {changed.Count} arquivo(s) alterado(s)...");
            var updateId=UpdateService.SessionId;
            File.WriteAllText(UpdateService.PendingFile,JsonSerializer.Serialize(new {update_id=updateId,command_id=commandId,version=ver,package_hash=expected,force,integrated=true,started=DateTimeOffset.UtcNow.ToUnixTimeSeconds()}));
            if(File.Exists(UpdateService.ResultFile)) File.Delete(UpdateService.ResultFile);
            UpdateService.ReportProgressState(updateId, 76, "Preparando atualizacao binaria...");
            UpdateService.StartProgressWindow(updateId);

            ct.ThrowIfCancellationRequested();
            UpdatePresentation.Report(76,"Preparando a nova versão em segundo plano…",false);
            var start=new ProcessStartInfo("powershell.exe") {UseShellExecute=false,CreateNoWindow=true,WorkingDirectory=work};
            foreach(var arg in new[]{"-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass","-File",script,"-Integrated","-ParentPid",Environment.ProcessId.ToString(),"-UpdateId",updateId}) start.ArgumentList.Add(arg);
            using var process=Process.Start(start) ?? throw new InvalidOperationException("Aplicador nao iniciou.");
            await Task.Delay(500,CancellationToken.None);
            if(process.HasExited && process.ExitCode!=0) throw new InvalidOperationException("Aplicador encerrou com erro "+process.ExitCode);
            await UpdateService.AwaitInstallerAsync(process,updateId,progress,CancellationToken.None);
            return new UpdateOutcome(true,"Atualizacao binaria iniciada");
        }
        catch { try { if(Directory.Exists(work)) Directory.Delete(work,true); } catch(IOException){} throw; }
    }
}
