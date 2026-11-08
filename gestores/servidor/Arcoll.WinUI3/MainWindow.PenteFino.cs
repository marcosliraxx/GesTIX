// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.PenteFino.cs
using System.Diagnostics;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
    public void OpenPenteFino()=>SelectSection("health");
    private async Task ElevatePenteFinoAsync()
    {
        if(!await ConfirmAsync("Pente-Fino — abrir como administrador","O Windows exibirá a confirmação UAC. O gestor será reiniciado e o monitoramento poderá pausar por alguns segundos. Nenhuma correção é iniciada por esta ação.","Continuar"))return;
        var executable=Environment.ProcessPath??throw new IOException("Executável do gestor não identificado.");
        var start=new ProcessStartInfo(executable){UseShellExecute=true,Verb="runas",WorkingDirectory=AppContext.BaseDirectory};
        start.ArgumentList.Add("--pentefino");start.ArgumentList.Add("--wait-exit");start.ArgumentList.Add(Environment.ProcessId.ToString(System.Globalization.CultureInfo.InvariantCulture));
        using var process=Process.Start(start)??throw new IOException("O Windows não iniciou a sessão elevada.");
        ExitGestor();
    }
}
