// Caminho: agentes/windows/Arcoll.WinUI3/Services/NativeCleanCommands.cs
using System.Text.Json;
namespace Arcoll.WinUI3.Services;
internal static class NativeCleanCommands
{
    public static async Task<string> RunAsync(string name, JsonElement request, PlatformClient platform, long id, CancellationToken ct)
    {
        var mode = name switch { "clean_scan" => "scan", "clean_execute" => "execute", "clean_tool" => "tool", _ => throw new NotSupportedException("Comando Clean inválido.") };
        var report = await CleanEngine.RunAsync(mode, request, platform.Config, null, ct);
        await platform.PostAsync("agent-clean-result", platform.WithCredentials(("command_id", id), ("report", report)), ct, 40);
        return "Operação Clean concluída e sincronizada.";
    }
}
