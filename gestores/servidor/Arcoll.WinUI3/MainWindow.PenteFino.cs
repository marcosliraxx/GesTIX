// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.PenteFino.cs
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
    // O Pente-Fino abre na sessão normal do usuário; não há elevação nem confirmação UAC para abrir a área.
    public void OpenPenteFino()=>SelectSection("health");
}
