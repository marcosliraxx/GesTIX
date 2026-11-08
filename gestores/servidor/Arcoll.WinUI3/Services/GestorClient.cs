// Caminho: gestores/servidor/Arcoll.WinUI3/Services/GestorClient.cs
using System.Runtime.InteropServices;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Fachada do motor de rede nativo. Substitui o cliente HTTP do gestor Python (127.0.0.1:8787):
/// nao depende de Python nem expoe nenhuma porta local.
/// </summary>
public sealed class GestorClient : IDisposable
{
    public Task<GestorSnapshot> HealthAsync(CancellationToken cancellationToken) => Task.Run(() =>
    {
        var net = NetworkEngine.Local();
        return new GestorSnapshot(Environment.MachineName, net.Ip, net.Gateway, RuntimeInformation.OSDescription, AppInfo.Version, true);
    }, cancellationToken);

    public void Dispose() { }
}
