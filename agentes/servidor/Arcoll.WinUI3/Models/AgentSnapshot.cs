// Caminho: agentes/servidor/Arcoll.WinUI3/Models/AgentSnapshot.cs
namespace Arcoll.WinUI3.Models;

public sealed record AgentSnapshot(string Host, string Ip, string Gateway, string Os, string Version, bool Online);
public sealed record DeviceInfo(string Name, string Ip, string Mac, string Kind, long? LatencyMs, string Detail);
public sealed record OpenPort(int Port, string Service);

public static class AppInfo
{
    public static string Version => typeof(AppInfo).Assembly.GetName().Version?.ToString(3) ?? "5.0.60";
}
