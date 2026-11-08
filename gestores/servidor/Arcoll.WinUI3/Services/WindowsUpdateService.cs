// Caminho: gestores/servidor/Arcoll.WinUI3/Services/WindowsUpdateService.cs
using System.Text.Json;
using Microsoft.Win32;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Operacoes do Windows Update realizadas em C# diretamente sobre a API COM do Windows Update Gestor.
/// Não executa comandos obtidos da rede, não instala atualizações não selecionadas por identidade/revisão,
/// não desativa proteções do Windows e nunca reinicia automaticamente o computador.
/// </summary>
public static class WindowsUpdateService
{
    private const string SearchCriteria = "IsInstalled=0 and IsHidden=0 and Type='Software'";
    private const string InnovationKey = @"SOFTWARE\Microsoft\WindowsUpdate\UX\Settings";
    private static readonly HashSet<string> Modes = new(StringComparer.Ordinal)
    { "scan", "install", "history", "pause", "resume", "settings", "advanced", "insider", "latest_on", "latest_off" };

    public static async Task<Dictionary<string, object?>> RunAsync(string requestText, CancellationToken ct)
    {
        using var operation = OperationProgress.Begin("Windows Update");
        try
        {
            operation.Report(15, "Consultando o serviço do Windows");
            var result = await Task.Run(() => Run(requestText, ct), ct);
            if (result.TryGetValue("result", out var code) && code is int n && n != 2) operation.FlagFailure("Instalação do Windows Update não foi concluída integralmente");
            operation.Succeed("Comando Windows Update aplicado"); return result;
        }
        catch (Exception ex) { operation.Fail(ex.Message); throw; }
    }

    private static Dictionary<string, object?> Run(string requestText, CancellationToken ct)
    {
        using var request = JsonDocument.Parse(string.IsNullOrWhiteSpace(requestText) ? "{}" : requestText);
        if (request.RootElement.ValueKind != JsonValueKind.Object) throw new ArgumentException("Solicitação de atualização inválida.");
        var mode = request.RootElement.TryGetProperty("mode", out var m) && m.ValueKind == JsonValueKind.String ? m.GetString() ?? "scan" : "scan";
        if (!Modes.Contains(mode)) throw new ArgumentException("Operação do Windows Update não permitida.");
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("O Windows Update exige Windows 10 ou 11.");
        ct.ThrowIfCancellationRequested();
        var report = new Dictionary<string, object?> { ["mode"] = mode, ["windows"] = WindowsVersion() };
        if (mode is "advanced" or "insider")
        {
            report["message"] = MaintenanceService.OpenSettings(mode);
            return report;
        }
        if (mode is "latest_on" or "latest_off")
        {
            using var key = Registry.LocalMachine.OpenSubKey(InnovationKey, writable: true)
                ?? throw new InvalidOperationException("Esta preferência não está disponível na edição atual do Windows.");
            key.SetValue("IsContinuousInnovationOptedIn", mode == "latest_on" ? 1 : 0, RegistryValueKind.DWord);
            report["mode"] = "settings"; // O contrato anterior devolve settings após alterar esta preferência.
            report["latest_opt_in"] = Convert.ToInt32(key.GetValue("IsContinuousInnovationOptedIn", 0)) == 1;
            report["message"] = "Preferência atualizada. Políticas corporativas podem prevalecer.";
            return report;
        }
        dynamic session = ComObject("Microsoft.Update.Session");
        session.ClientApplicationID = "TI Digital Gestor Windows";
        if (mode is "pause" or "resume")
        {
            dynamic auto = ComObject("Microsoft.Update.AutoUpdate");
            if (mode == "pause") auto.Pause(); else auto.Resume();
            report["message"] = "Solicitação encaminhada ao Windows Update, respeitando as políticas do dispositivo.";
            return report;
        }
        if (mode == "settings")
        {
            dynamic auto = ComObject("Microsoft.Update.AutoUpdate");
            report["notification_level"] = Convert.ToInt32(auto.Settings.NotificationLevel);
            report["read_only"] = Convert.ToBoolean(auto.Settings.ReadOnly);
            report["reboot_required"] = Convert.ToBoolean(ComObject("Microsoft.Update.SystemInfo").RebootRequired);
            using var key = Registry.LocalMachine.OpenSubKey(InnovationKey);
            report["latest_opt_in"] = key?.GetValue("IsContinuousInnovationOptedIn") is int opt ? opt == 1 : null;
            return report;
        }
        dynamic searcher = session.CreateUpdateSearcher();
        if (mode == "history")
        {
            var total = Convert.ToInt32(searcher.GetTotalHistoryCount());
            var count = Math.Min(total, 80);
            var rows = new List<object>(count);
            if (count > 0)
            {
                dynamic history = searcher.QueryHistory(0, count);
                for (var i = 0; i < count; ++i)
                {
                    ct.ThrowIfCancellationRequested();
                    dynamic row = history.Item(i);
                    rows.Add(new { title = (string)row.Title, date = ((DateTime)row.Date).ToString("yyyy-MM-dd HH:mm"), operation = (int)row.Operation, result = (int)row.ResultCode, hresult = (int)row.HResult });
                }
            }
            report["history"] = rows;
            report["total"] = total;
            return report;
        }
        var selected = new HashSet<(Guid Id, int Revision)>();
        if (mode == "install")
        {
            if (!request.RootElement.TryGetProperty("updates", out var updates) || updates.ValueKind != JsonValueKind.Array || updates.GetArrayLength() is < 1 or > 50)
                throw new ArgumentException("Selecione entre 1 e 50 atualizações previamente verificadas.");
            foreach (var row in updates.EnumerateArray())
            {
                if (row.ValueKind != JsonValueKind.Object || !row.TryGetProperty("id", out var id) || id.ValueKind != JsonValueKind.String
                    || !Guid.TryParseExact(id.GetString(), "D", out var guid) || !row.TryGetProperty("revision", out var r) || !r.TryGetInt32(out var rev) || rev < 1)
                    throw new ArgumentException("Identidade ou revisão da atualização inválida.");
                if (!selected.Add((guid, rev))) throw new ArgumentException("Atualização duplicada.");
            }
        }
        dynamic search = searcher.Search(SearchCriteria);
        if (Convert.ToInt32(search.ResultCode) != 2) throw new InvalidOperationException("A verificação do Windows Update não foi concluída.");
        var available = Convert.ToInt32(search.Updates.Count);
        dynamic collection = ComObject("Microsoft.Update.UpdateColl");
        var discovered = new List<object>();
        for (var i = 0; i < available; ++i)
        {
            ct.ThrowIfCancellationRequested();
            dynamic update = search.Updates.Item(i);
            if (mode == "scan")
            {
                if (discovered.Count >= 50) continue;
                string title = update.Title;
                var kbs = new List<string>();
                try { dynamic ids = update.KBArticleIDs; for (int j = 0; j < Math.Min(10, (int)ids.Count); ++j) kbs.Add((string)ids.Item(j)); }
                catch (Exception) { /* Algumas edições não apresentam os KBs. */ }
                discovered.Add(new { id = (string)update.Identity.UpdateID, revision = (int)update.Identity.RevisionNumber,
                    title = title.Length > 160 ? title[..160] : title, kb = kbs, size = (double)update.MaxDownloadSize,
                    reboot = (int)update.InstallationBehavior.RebootBehavior });
            }
            else if (Guid.TryParse((string)update.Identity.UpdateID, out var found) && selected.Contains((found, (int)update.Identity.RevisionNumber)))
            {
                if ((bool)update.InstallationBehavior.CanRequestUserInput) throw new InvalidOperationException("Uma atualização exige interação local; a instalação remota foi recusada.");
                if (!(bool)update.EulaAccepted) update.AcceptEula();
                collection.Add(update);
            }
        }
        if (mode == "scan")
        {
            report["updates"] = discovered; report["total"] = available;
            report["reboot_required"] = Convert.ToBoolean(ComObject("Microsoft.Update.SystemInfo").RebootRequired);
            return report;
        }
        if ((int)collection.Count != selected.Count) throw new InvalidOperationException("As atualizações mudaram; execute uma nova verificação.");
        ct.ThrowIfCancellationRequested();
        dynamic downloader = session.CreateUpdateDownloader(); downloader.Updates = collection;
        OperationProgress.Current?.Report(40, "Baixando atualizações selecionadas");
        dynamic downloaded = downloader.Download();
        if ((int)downloaded.ResultCode != 2) throw new InvalidOperationException("Falha ao baixar o conjunto completo de atualizações; instalação não iniciada.");
        ct.ThrowIfCancellationRequested();
        dynamic installer = session.CreateUpdateInstaller(); installer.Updates = collection; installer.AllowSourcePrompts = false;
        OperationProgress.Current?.Report(70, "Instalando atualizações selecionadas");
        dynamic installed = installer.Install();
        var result = new List<object>();
        for (var i = 0; i < (int)collection.Count; ++i)
        {
            dynamic update = collection.Item(i); dynamic row = installed.GetUpdateResult(i);
            result.Add(new { id = (string)update.Identity.UpdateID, code = (int)row.ResultCode, hresult = (int)row.HResult });
        }
        report["result"] = (int)installed.ResultCode;
        report["reboot_required"] = (bool)installed.RebootRequired;
        report["results"] = result;
        return report;
    }

    private static dynamic ComObject(string progId)
    {
        var type = Type.GetTypeFromProgID(progId) ?? throw new InvalidOperationException("Windows Update Gestor COM indisponível: " + progId);
        return Activator.CreateInstance(type) ?? throw new InvalidOperationException("Não foi possível iniciar o componente " + progId);
    }

    private static string WindowsVersion()
    {
        using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
        var build = key?.GetValue("CurrentBuild")?.ToString() ?? "0";
        var name = key?.GetValue("ProductName")?.ToString() ?? "Windows";
        if (int.TryParse(build, out var b) && b >= 22000 && !name.Contains("Server", StringComparison.OrdinalIgnoreCase)) name = name.Replace("Windows 10", "Windows 11");
        return $"{name} {key?.GetValue("DisplayVersion") ?? ""} (build {build}.{key?.GetValue("UBR") ?? "0"})".Trim();
    }
}
