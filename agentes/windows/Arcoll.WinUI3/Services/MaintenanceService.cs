// Caminho: agentes/windows/Arcoll.WinUI3/Services/MaintenanceService.cs
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json;

namespace Arcoll.WinUI3.Services;

/// <summary>Ações fixas de manutenção do Windows (energia, bloqueio de sessão, configurações). Nunca executa texto recebido da rede.</summary>
public static class MaintenanceService
{
    [DllImport("user32.dll", SetLastError = true)] private static extern bool LockWorkStation();

    /// <summary>Prazo em segundos do pedido (5–3600; padrão 60), igual ao agente Python.</summary>
    public static int DelaySeconds(string? requestText)
    {
        try
        {
            using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(requestText) ? "{}" : requestText);
            if (doc.RootElement.ValueKind == JsonValueKind.Object && doc.RootElement.TryGetProperty("delay_seconds", out var v) && v.TryGetInt32(out var n) && n is >= 5 and <= 3600) return n;
        }
        catch (JsonException) { }
        return 60;
    }

    public static string Mode(string? requestText)
    {
        try
        {
            using var doc = JsonDocument.Parse(string.IsNullOrWhiteSpace(requestText) ? "{}" : requestText);
            if (doc.RootElement.ValueKind == JsonValueKind.Object && doc.RootElement.TryGetProperty("mode", out var m) && m.ValueKind == JsonValueKind.String) return m.GetString() ?? "scan";
        }
        catch (JsonException) { }
        return "scan";
    }

    public static string SchedulePower(string action, int delaySeconds)
    {
        if (delaySeconds is < 5 or > 3600) throw new ArgumentException("Tempo de espera inválido.");
        var flags = action switch
        {
            "power_shutdown" => new[] { "/s", "/t", delaySeconds.ToString() },
            "power_restart" => new[] { "/r", "/t", delaySeconds.ToString() },
            "power_cancel" => new[] { "/a" },
            _ => throw new ArgumentException("Operação de energia inválida."),
        };
        var psi = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "shutdown.exe")) { UseShellExecute = false, CreateNoWindow = true };
        foreach (var f in flags) psi.ArgumentList.Add(f);
        using var p = Process.Start(psi) ?? throw new InvalidOperationException("Não foi possível iniciar o shutdown.exe.");
        if (!p.WaitForExit(10000)) { try { p.Kill(); } catch (Exception) { } throw new TimeoutException("O Windows não respondeu à operação de energia."); }
        if (p.ExitCode != 0) throw new InvalidOperationException("O Windows não autorizou a operação ou não há desligamento agendado.");
        return action == "power_cancel" ? "Agendamento cancelado." : $"Operação agendada para daqui a {delaySeconds} segundos. Salve os arquivos abertos. O Windows pode adiar se houver trabalho não salvo.";
    }

    public static string LockSession()
    {
        if (!LockWorkStation()) throw new InvalidOperationException("O Windows não permitiu bloquear esta sessão.");
        return "Sessão bloqueada pelo Windows.";
    }

    /// <summary>Abre as configurações do Windows Update (avançado/Insider) na sessão do usuário.</summary>
    public static string OpenSettings(string mode)
    {
        var uri = mode == "insider" ? "ms-settings:windowsinsider" : "ms-settings:windowsupdate-options";
        Process.Start(new ProcessStartInfo(uri) { UseShellExecute = true });
        return "Configurações do Windows abertas na sessão local do agente.";
    }
}
