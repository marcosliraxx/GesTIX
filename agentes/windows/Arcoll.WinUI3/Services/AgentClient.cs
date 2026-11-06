// Caminho: agentes/windows/Arcoll.WinUI3/Services/AgentClient.cs
using System.Runtime.InteropServices;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Fachada do motor de rede nativo. Substitui o cliente HTTP do agente Python (127.0.0.1:8787):
/// nao depende de Python nem expoe nenhuma porta local.
/// </summary>
public sealed class AgentClient : IDisposable
{
    public Task<AgentSnapshot> HealthAsync(CancellationToken cancellationToken) => Task.Run(() =>
    {
        var net = NetworkEngine.Local();
        return new AgentSnapshot(Environment.MachineName, net.Ip, net.Gateway, RuntimeInformation.OSDescription, AppInfo.Version, true);
    }, cancellationToken);

    public Task<long?> PingAsync(string host) => NetworkEngine.PingAsync(host, 1200);
    public Task<(string Name, IReadOnlyList<string> Ips, IReadOnlyList<string> Reverse)> DnsAsync(string name, CancellationToken cancellationToken) => NetworkEngine.DnsAsync(name, cancellationToken);
    public Task<IReadOnlyList<OpenPort>> PortsAsync(string host, CancellationToken cancellationToken) => NetworkEngine.PortsAsync(host, cancellationToken);
    public Task WakeOnLanAsync(string mac) => NetworkEngine.WakeOnLanAsync(mac);

    public void Dispose() { }
}
