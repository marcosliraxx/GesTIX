// Caminho: agentes/windows/Arcoll.WinUI3/Services/DiagnosticCommands.cs
using System.Collections.Concurrent;
using System.Diagnostics;
using System.Globalization;
using System.Text;
using System.Text.RegularExpressions;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Terminal de diagnóstico da plataforma (terminal_diagnostic). Mesma lista fechada do agente Python
/// (arcoll_diagnostic_argv): sem cmd.exe, sem PowerShell, sem redirecionamento; apenas ping, tracert, nslookup e consultas fixas.
/// </summary>
public static class DiagnosticCommands
{
    private const string Host = @"(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,90}[A-Za-z0-9])?)";
    private static readonly Regex PingRx = new(@"^ping(?: (?:-n (10|[1-9])|(-t)))? (" + Host + ")$", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    private static readonly Regex TracertRx = new(@"^tracert( -d)? (" + Host + ")$", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    private static readonly Regex NsRx = new("^nslookup (" + Host + ")$", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    private static readonly Dictionary<string, string[]> Fixed = new(StringComparer.OrdinalIgnoreCase)
    {
        ["ipconfig"] = new[] { "ipconfig" }, ["ipconfig /all"] = new[] { "ipconfig", "/all" }, ["ipconfig /flushdns"] = new[] { "ipconfig", "/flushdns" },
        ["arp -a"] = new[] { "arp", "-a" }, ["route print"] = new[] { "route", "print" },
        ["netsh wlan show interfaces"] = new[] { "netsh", "wlan", "show", "interfaces" }, ["hostname"] = new[] { "hostname" },
    };

    public static string[] Argv(string? raw)
    {
        var text = string.Join(' ', (raw ?? "").Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        if (text.Length == 0 || text.Length > 180 || text.Any(char.IsControl)) throw new ArgumentException("Comando de diagnóstico inválido");
        var m = PingRx.Match(text);
        if (m.Success) return new[] { "ping", "-n", m.Groups[2].Success ? "60" : m.Groups[1].Success ? m.Groups[1].Value : "4", "-w", "1200", m.Groups[3].Value };
        m = TracertRx.Match(text);
        if (m.Success) return m.Groups[1].Success ? new[] { "tracert", "-d", "-h", "16", "-w", "900", m.Groups[2].Value } : new[] { "tracert", "-h", "16", "-w", "900", m.Groups[2].Value };
        m = NsRx.Match(text);
        if (m.Success) return new[] { "nslookup", m.Groups[1].Value };
        if (Fixed.TryGetValue(text, out var argv)) return argv;
        throw new ArgumentException("Comando não autorizado. Consulte ajuda no terminal da plataforma.");
    }

    /// <summary>Executa o diagnóstico e entrega a saída por trechos (limite de 30 mil caracteres e 80 s, como no agente antigo).</summary>
    public static async Task<(int Code, bool Truncated, bool TimedOut)> RunAsync(string[] argv, Func<string, Task<bool>> sink, CancellationToken ct)
    {
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        Encoding oem;
        try { oem = Encoding.GetEncoding(CultureInfo.CurrentCulture.TextInfo.OEMCodePage); } catch (Exception) { oem = Encoding.UTF8; }
        var exe = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), argv[0] + ".exe");
        var psi = new ProcessStartInfo(File.Exists(exe) ? exe : argv[0])
        {
            RedirectStandardOutput = true, RedirectStandardError = true, RedirectStandardInput = true, UseShellExecute = false, CreateNoWindow = true,
            StandardOutputEncoding = oem, StandardErrorEncoding = oem,
        };
        foreach (var a in argv.Skip(1)) psi.ArgumentList.Add(a);
        using var proc = new Process { StartInfo = psi };
        var queue = new ConcurrentQueue<string>();
        proc.OutputDataReceived += (_, e) => { if (e.Data is not null) queue.Enqueue(e.Data + "\n"); };
        proc.ErrorDataReceived += (_, e) => { if (e.Data is not null) queue.Enqueue(e.Data + "\n"); };
        proc.Start(); proc.StandardInput.Close(); proc.BeginOutputReadLine(); proc.BeginErrorReadLine();
        using var limit = CancellationTokenSource.CreateLinkedTokenSource(ct); limit.CancelAfter(TimeSpan.FromSeconds(80));
        int emitted = 0; bool truncated = false, timedOut = false; var pending = new StringBuilder(); var last = DateTime.UtcNow;
        while (true)
        {
            while (queue.TryDequeue(out var line))
            {
                var room = Math.Max(0, 30000 - emitted);
                if (line.Length > room) { line = line[..room]; truncated = true; }
                emitted += line.Length; pending.Append(line);
                if (truncated) break;
            }
            var exited = proc.HasExited && queue.IsEmpty;
            if (pending.Length > 0 && (exited || truncated || pending.Length >= 900 || DateTime.UtcNow - last > TimeSpan.FromMilliseconds(280)))
            {
                var text = pending.ToString(); pending.Clear(); last = DateTime.UtcNow;
                for (var at = 0; at < text.Length; at += 900)
                    if (!await sink(text.Substring(at, Math.Min(900, text.Length - at)))) { Kill(proc); return (-1, truncated, timedOut); }
            }
            if (truncated) { Kill(proc); break; }
            if (limit.IsCancellationRequested) { timedOut = !ct.IsCancellationRequested; Kill(proc); break; }
            if (exited) break;
            try { await Task.Delay(120, limit.Token); } catch (OperationCanceledException) { }
        }
        try { proc.WaitForExit(3000); } catch (Exception) { }
        var tail = truncated ? "\n[Saída limitada a 30 mil caracteres]\n" : timedOut ? "\n[Tempo limite de 80 segundos]\n" : "";
        if (tail.Length > 0) await sink(tail);
        return (proc.HasExited ? proc.ExitCode : -1, truncated, timedOut);
    }

    private static void Kill(Process p) { try { if (!p.HasExited) p.Kill(true); } catch (Exception) { } }
}
