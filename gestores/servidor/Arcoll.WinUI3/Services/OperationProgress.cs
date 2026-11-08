// Caminho: gestores/servidor/Arcoll.WinUI3/Services/OperationProgress.cs
using System.Text.Json;
using System.Text.RegularExpressions;
namespace Arcoll.WinUI3.Services;

public sealed record OperationNotice(Guid Id, string Command, string Origin, int Percent, string Message, bool Finished, bool Failed);
/// <summary>Eventos de comandos explicitos. Sem contadores ficticios; porcentagens seguem etapas ou itens medidos.</summary>
public static class OperationProgress
{
    private static readonly AsyncLocal<Scope?> Ambient = new();
    public static Scope? Current => Ambient.Value;
    public static event Action<OperationNotice>? Changed;
    public static Scope Begin(string command, string origin = "Local")
    {
        if (Ambient.Value is { } parent) return new Scope(parent);
        var scope = new Scope(command, origin); Ambient.Value = scope; scope.Report(0, "Iniciando comando"); return scope;
    }
    public sealed class Scope : IDisposable
    {
        private readonly Scope? _parent;
        private readonly Guid _id = Guid.NewGuid();
        private readonly string _command = "", _origin = "";
        private readonly object _lock = new();
        private int _percent;
        private bool _finished;
        private string? _failure;
        internal Scope(string command, string origin) { _command = command; _origin = origin; }
        internal Scope(Scope parent) { _parent = parent; }
        public void Report(int percent, string message)
        {
            if (_parent is not null) { _parent.Report(percent, message); return; }
            lock (_lock)
            {
                if (_finished) return; _percent = Math.Max(_percent, Math.Clamp(percent, 0, 99));
                Publish(new(_id, _command, _origin, _percent, message, false, false));
            }
        }
        public void ReportText(string message)
        {
            if (message.StartsWith("@@ARP@@", StringComparison.Ordinal))
            {
                try { using var doc = JsonDocument.Parse(message[7..]); var p = doc.RootElement; Report((int)(90d * p.GetProperty("i").GetDouble() / Math.Max(1, p.GetProperty("t").GetDouble())), p.TryGetProperty("c", out var c) ? c.ToString() : "Processando aplicativos"); return; } catch (Exception ex) when (ex is JsonException or InvalidOperationException or KeyNotFoundException or FormatException or OverflowException) { }
            }
            var percent = Regex.Match(message, @"(\d{1,3})\s*%");
            if (percent.Success) { Report(int.Parse(percent.Groups[1].Value), message); return; }
            var fraction = Regex.Match(message, @"\b(\d+)\s*/\s*(\d+)\b");
            if (fraction.Success && double.TryParse(fraction.Groups[2].Value, out var total) && total > 0)
            { Report((int)(90 * double.Parse(fraction.Groups[1].Value) / total), message); return; }
            Report(_parent?._percent ?? _percent, message);
        }
        public void FlagFailure(string message) { if (_parent is not null) _parent.FlagFailure(message); else lock (_lock) _failure = message; }
        public void Succeed(string message = "Comando aplicado")
        {
            if (_parent is not null) return;
            lock (_lock)
            {
                if (_finished) return; _finished = true;
                Publish(new(_id, _command, _origin, _failure is null ? 100 : _percent, _failure ?? message, true, _failure is not null));
            }
        }
        public void Fail(string message)
        {
            if (_parent is not null) { _parent.FlagFailure(message); return; }
            FlagFailure(message); Succeed();
        }
        public void Dispose()
        {
            if (_parent is not null) return;
            if (!_finished) Fail("Comando interrompido ou sem confirmação de conclusão");
            if (ReferenceEquals(Ambient.Value, this)) Ambient.Value = null;
        }
    }
    private static void Publish(OperationNotice notice)
    {
        foreach (var callback in Changed?.GetInvocationList() ?? Array.Empty<Delegate>())
            try { ((Action<OperationNotice>)callback)(notice); } catch { /* aviso visual nunca interrompe o comando */ }
    }
    public static string Title(string name) => name switch
    {
        "scan_devices" or "scan_map" => "Examinar dispositivos", "speed_test" => "Testar Internet", "diagnostic" => "Diagnosticar conexão",
        "clean_scan" => "Analisar limpeza", "clean_execute" => "Limpar computador", "clean_tool" => "Executar Clean",
        "program_install" => "Instalar e atualizar programas", "program_inventory" => "Consultar programas", "windows_update" => "Windows Update",
        "remote_assistance" => "Iniciar acesso remoto", "monitor_start" => "Iniciar monitoramento", "monitor_pause" => "Pausar monitoramento",
        "file_browser" or "cloud_folders" => "Consultar arquivos", "power_shutdown" => "Agendar desligamento", "power_restart" => "Agendar reinicialização",
        "power_cancel" => "Cancelar operação de energia", "screen_lock" => "Bloquear tela", _ => name.Replace('_', ' ')
    };
}
