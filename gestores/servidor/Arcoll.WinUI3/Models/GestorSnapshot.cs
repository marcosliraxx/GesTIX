// Caminho: gestores/servidor/Arcoll.WinUI3/Models/GestorSnapshot.cs
namespace Arcoll.WinUI3.Models;

public sealed record GestorSnapshot(string Host, string Ip, string Gateway, string Os, string Version, bool Online);
public sealed record DeviceInfo(string Name, string Ip, string Mac, string Kind, long? LatencyMs, string Detail);
public sealed record OpenPort(int Port, string Service);

public static class AppInfo
{
    public static string Version => typeof(AppInfo).Assembly.GetName().Version?.ToString(3) ?? throw new InvalidOperationException("Versão do executável não definida.");
}
