// Caminho: gestores/servidor/Arcoll.WinUI3/Services/MaintenanceService.cs
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json;

namespace Arcoll.WinUI3.Services;

/// <summary>Ações fixas de manutenção do Windows (energia, bloqueio de sessão, configurações). Nunca executa texto recebido da rede.</summary>
public static class MaintenanceService
{
    [DllImport("user32.dll", SetLastError = true)] private static extern bool LockWorkStation();
    [DllImport("kernel32.dll")] private static extern uint WTSGetActiveConsoleSessionId();
    [DllImport("wtsapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQuerySessionInformationW(nint server, uint session, int infoClass,
        out nint buffer, out int bytesReturned);
    [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(nint buffer);

    /// <summary>
    /// Estado real do bloqueio na sessão ativa. WTSSessionInfoEx(25) reporta
    /// WTS_SESSIONSTATE_LOCK(0) / UNLOCK(1). Falhas retornam unknown; não
    /// presumir que o computador está desbloqueado.
    /// </summary>
    public static string DesktopState()
    {
        if (!OperatingSystem.IsWindows() || !Environment.UserInteractive) return "unknown";
        nint data = 0;
        try
        {
            // A sessão do gestor pode ser RDP, não necessariamente a sessão do console.
            uint session = (uint)Process.GetCurrentProcess().SessionId;
            if (!WTSQuerySessionInformationW(0, session, 25, out data, out int size) || data == 0 || size < 20)
                return "unknown";
            if (Marshal.ReadInt32(data) != 1) return "unknown"; // WTSINFOEX_LEVEL_1
            // A união de WTSINFOEX é alinhada em 8 bytes nas arquiteturas 64-bit;
            // no layout de 32 bits seu início é deslocado em 4 bytes.
            int offset = IntPtr.Size == 8 ? 8 : 4;
            int flags = Marshal.ReadInt32(data, offset + 8);
            // Windows 7/Server 2008 R2 possuem flags invertidos; não inferir estado.
            if (Environment.OSVersion.Version.Major == 6 && Environment.OSVersion.Version.Minor == 1)
                return "unknown";
            return flags == 0 ? "locked" : flags == 1 ? "unlocked" : "unknown";
        }
        catch (Exception ex) when (ex is System.ComponentModel.Win32Exception or AccessViolationException or ArgumentException)
        {
            return "unknown";
        }
        finally { if (data != 0) WTSFreeMemory(data); }
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct UserInfo1
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string Name;
        [MarshalAs(UnmanagedType.LPWStr)] public string Password;
        public uint PasswordAge;
        public uint Privilege;
        [MarshalAs(UnmanagedType.LPWStr)] public string HomeDirectory;
        [MarshalAs(UnmanagedType.LPWStr)] public string Comment;
        public uint Flags;
        [MarshalAs(UnmanagedType.LPWStr)] public string ScriptPath;
    }

    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)]
    private static extern uint NetUserEnum(string? server, uint level, uint filter, out nint buffer,
        uint preferredMaxLength, out uint entriesRead, out uint totalEntries, ref nuint resumeHandle);
    [DllImport("netapi32.dll")] private static extern uint NetApiBufferFree(nint buffer);

    /// <summary>Lista somente contas locais habilitadas; nunca consulta nem envia senhas.</summary>
    public static object[] EnabledLocalUsers()
    {
        if (!OperatingSystem.IsWindows()) return [];
        var users = new List<(string Username, string Label)>();
        nuint resume = 0;
        for (var page = 0; page < 16; page++)
        {
            nint buffer = 0;
            var status = NetUserEnum(null, 1, 2, out buffer, 65536, out var read, out _, ref resume);
            try
            {
                if (status != 0 && status != 234) throw new InvalidOperationException($"NetUserEnum: erro {status}.");
                var width = Marshal.SizeOf<UserInfo1>();
                for (var index = 0; index < read && users.Count < 50; index++)
                {
                    var user = Marshal.PtrToStructure<UserInfo1>(buffer + (int)index * width);
                    var name = user.Name;
                    if (string.IsNullOrWhiteSpace(name) || name.Length > 256 || (user.Flags & 0x2) != 0 || name.Any(char.IsControl)) continue;
                    users.Add((@".\" + name, name));
                }
            }
            finally { if (buffer != 0) NetApiBufferFree(buffer); }
            if (status == 0 || users.Count >= 50) break;
        }
        return users.OrderBy(u => u.Label, StringComparer.OrdinalIgnoreCase)
            .Select(u => (object)new { username = u.Username, label = u.Label }).ToArray();
    }

    /// <summary>Prazo em segundos do pedido (5–3600; padrão 60), igual ao gestor Python.</summary>
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
        return "Configurações do Windows abertas na sessão local do gestor.";
    }
}
