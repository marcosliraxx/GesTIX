// Caminho: agentes/windows/Arcoll.WinUI3/Services/WorkspaceService.cs
using System.Text.Json;
namespace Arcoll.WinUI3.Services;
public static class WorkspaceService
{
    public static Task<JsonElement> GetAsync(AgentRuntime runtime, string op, CancellationToken ct, params (string Key, object? Value)[] values)
    {
        if (!runtime.Allowed) throw new UnauthorizedAccessException("Autorize este computador na plataforma.");
        var body = runtime.Platform.WithCredentials(values); body["op"] = op;
        return runtime.Platform.PostAsync("agent-workspace", body, ct, 15);
    }
}
