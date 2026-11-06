// Caminho: agentes/windows/Arcoll.WinUI3/Pages/SupportPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
public sealed class SupportPage : UserControl
{
    private readonly AgentRuntime _runtime; private readonly Func<Task> _remote; private readonly Action _tickets;
    private Palette _p = new();
    public SupportPage(AgentRuntime runtime, Func<Task> remote, Action tickets) { _runtime = runtime; _remote = remote; _tickets = tickets; Loaded += (_, _) => Render(); }
    public void ApplyPalette(Palette p) { _p = p; Render(); }
    private void Render()
    {
        var root = new StackPanel { Spacing = 10 };
        root.Children.Add(Ui.Label("Suporte", _p.Text, 20, true));
        root.Children.Add(Ui.Label(_runtime.NetworkName, _p.Muted, 14, wrap: true));
        root.Children.Add(Ui.Label("Compartilhe o código temporário com o responsável pelo atendimento ou acompanhe os chamados deste computador.", _p.Text, 14, wrap: true));
        var remote = new Button { Content = "Gerar código de acesso remoto" }; remote.Click += async (_, _) => await _remote(); root.Children.Add(remote);
        var tickets = new Button { Content = "Meus chamados" }; tickets.Click += (_, _) => _tickets(); root.Children.Add(tickets);
        Content = Ui.Card(root, _p);
    }
}
