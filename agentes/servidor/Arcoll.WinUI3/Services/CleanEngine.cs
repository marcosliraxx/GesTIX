// Caminho: agentes/servidor/Arcoll.WinUI3/Services/CleanEngine.cs
using System.Diagnostics;
using System.IO.Compression;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;

namespace Arcoll.WinUI3.Services;

/// <summary>Interface WinUI nativa sobre o mesmo motor de limpeza validado que a plataforma já distribui.</summary>
public static class CleanEngine
{
    private static readonly SemaphoreSlim Gate = new(1, 1);
    private static string Runtime => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "CleanRuntime");
    private static string Worker => Path.Combine(AppContext.BaseDirectory, "Assets", "clean-engine", "clean_worker.py");
    public static JsonElement Catalog()
    {
        using var doc = JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "Assets", "clean-catalog.json")));
        return doc.RootElement.Clone();
    }
    private static async Task<string> InterpreterAsync(IProgress<string>? progress, CancellationToken ct)
    {
        var exe = Path.Combine(Runtime, "python.exe");
        if (File.Exists(exe) && File.Exists(Path.Combine(Runtime, "python312.zip"))) return exe;
        progress?.Report("Preparando os componentes do Clean…");
        var arch = RuntimeInformation.OSArchitecture == Architecture.Arm64 ? "arm64" : "amd64";
        var stage = Runtime + "-stage-" + Guid.NewGuid().ToString("N");
        Directory.CreateDirectory(stage);
        try
        {
            using var http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false }) { Timeout = TimeSpan.FromMinutes(4) };
            using var response = await http.GetAsync($"https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-{arch}.zip", HttpCompletionOption.ResponseHeadersRead, ct);
            response.EnsureSuccessStatusCode();
            await using (var source = await response.Content.ReadAsStreamAsync(ct))
            await using (var target = File.Create(Path.Combine(stage, "runtime.zip")))
            {
                long total = 0; var buffer = new byte[81920]; int size;
                while ((size = await source.ReadAsync(buffer, ct)) > 0)
                { total += size; if (total > 32 * 1024 * 1024) throw new InvalidDataException("Componente Clean excede o limite."); await target.WriteAsync(buffer.AsMemory(0, size), ct); }
            }
            var zipPath = Path.Combine(stage, "runtime.zip");
            using (var zip = ZipFile.OpenRead(zipPath))
            {
                if (zip.Entries.Sum(x => x.Length) > 100 * 1024 * 1024) throw new InvalidDataException("Expansão do componente excede o limite.");
                foreach (var entry in zip.Entries)
                {
                    var full = Path.GetFullPath(Path.Combine(stage, entry.FullName));
                    if (!full.StartsWith(Path.GetFullPath(stage) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Caminho inválido no componente.");
                }
            }
            ZipFile.ExtractToDirectory(zipPath, stage, true); File.Delete(zipPath);
            // Não executa o interpretador baixado antes de validar a assinatura do fornecedor.
            var candidate = Path.Combine(stage, "python.exe").Replace("'", "''");
            var signature = await ToolkitService.PsAsync("$s=Get-AuthenticodeSignature -LiteralPath '" + candidate + "'; if($s.Status -ne 'Valid' -or $s.SignerCertificate.Subject -notmatch 'Python Software Foundation'){exit 1}; @{valid=$true}|ConvertTo-Json -Compress", ct);
            if (!signature.TryGetValue("exit_code", out var code) || code is not 0) throw new InvalidDataException("Assinatura do componente Clean não foi validada.");
            if (Directory.Exists(Runtime)) Directory.Delete(Runtime, true);
            Directory.Move(stage, Runtime);
            return exe;
        }
        finally { if (Directory.Exists(stage)) Directory.Delete(stage, true); }
    }
    public static async Task<JsonElement> RunAsync(string mode, object payload, AgentConfig config, IProgress<string>? progress, CancellationToken ct)
    {
        using var operation = OperationProgress.Begin(mode is "backup_run" or "backup_status" ? "Backup em Nuvem" : mode == "scan" ? "Analisar limpeza" : mode == "execute" ? "Limpar arquivos" : "Executar Clean");
        operation.Report(5, "Preparando comando");
        await Gate.WaitAsync(ct);
        try
        {
            if (!File.Exists(Worker)) throw new FileNotFoundException("Motor Clean ausente. Atualize o agente pelo pacote completo.");
            var python = await InterpreterAsync(progress, ct);
            using var deadline = CancellationTokenSource.CreateLinkedTokenSource(ct); deadline.CancelAfter(TimeSpan.FromHours(2));
            var start = new ProcessStartInfo(python) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardInput = true, RedirectStandardOutput = true, RedirectStandardError = true,
                StandardInputEncoding = new UTF8Encoding(false), StandardOutputEncoding = Encoding.UTF8, StandardErrorEncoding = Encoding.UTF8 };
            start.ArgumentList.Add("-I"); start.ArgumentList.Add(Worker);
            start.Environment["PYTHONIOENCODING"] = "utf-8";
            using var process = Process.Start(start) ?? throw new InvalidOperationException("Motor Clean não iniciou.");
            try
            {
                await process.StandardInput.WriteAsync(JsonSerializer.Serialize(new { mode, payload, config = new { origin = config.Origin, fingerprint = config.Fingerprint, site_code = config.SiteCode, download_key = config.DownloadKey } }));
                process.StandardInput.Close();
                var errors = process.StandardError.ReadToEndAsync(deadline.Token);
                JsonElement result = default;
                while (await process.StandardOutput.ReadLineAsync(deadline.Token) is string line)
                {
                    if (line.Length > 8 * 1024 * 1024) throw new InvalidDataException("Relatório Clean excede o limite.");
                    using var doc = JsonDocument.Parse(line); var item = doc.RootElement;
                    if (item.TryGetProperty("progress", out var message)) { var text = message.GetString() ?? ""; operation.ReportText(text); progress?.Report(text); }
                    if (item.TryGetProperty("error", out var error)) throw new InvalidOperationException(error.GetString());
                    if (item.TryGetProperty("result", out var report)) result = report.Clone();
                }
                await process.WaitForExitAsync(deadline.Token);
                var stderr = await errors;
                if (process.ExitCode != 0 || result.ValueKind == JsonValueKind.Undefined) throw new InvalidOperationException("Clean não confirmou a operação. " + (stderr.Length > 500 ? stderr[^500..] : stderr));
                if (result.TryGetProperty("errors", out var failures) && failures.ValueKind == JsonValueKind.Number && failures.GetDouble() > 0) operation.FlagFailure("Operação encerrada com itens não alterados: " + failures);
                if (result.TryGetProperty("failed", out failures) && failures.ValueKind == JsonValueKind.Number && failures.GetDouble() > 0) operation.FlagFailure("Operação parcial; falhas: " + failures);
                operation.Succeed("Operação Clean aplicada");
                return result;
            }
            finally { if (!process.HasExited) { try { process.Kill(true); } catch (InvalidOperationException) { } } }
        }
        catch (Exception ex) { operation.Fail(ex.Message); throw; }
        finally { Gate.Release(); }
    }
}
