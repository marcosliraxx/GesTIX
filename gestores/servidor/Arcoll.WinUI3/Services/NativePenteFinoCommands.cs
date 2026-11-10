// Caminho: gestores/servidor/Arcoll.WinUI3/Services/NativePenteFinoCommands.cs
using System.Text.Json;
namespace Arcoll.WinUI3.Services;
internal static class NativePenteFinoCommands
{
    public static async Task<string> RunAsync(string name, JsonElement request, PlatformClient platform, long id, CancellationToken ct)
    {
        var mode = name switch { "pentefino_scan" => "scan", "pentefino_execute" => "execute", "pentefino_tool" => "tool", _ => throw new NotSupportedException("Comando do Pente-Fino inválido.") };
        var report = await PenteFinoEngine.RunAsync(mode, request, platform.Config, null, ct);
        await platform.PostAsync("gestor-pentefino-result", platform.WithCredentials(("command_id", id), ("report", report)), ct, 40);
        if (PenteFinoEngine.HasFailures(report)) throw new InvalidOperationException("Operação parcial ou não confirmada. O relatório foi sincronizado; revise os itens não aplicados.");
        return "Operação do Pente-Fino concluída e sincronizada.";
    }
}
