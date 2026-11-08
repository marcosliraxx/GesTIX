// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/SupportPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
public sealed class SupportPage : UserControl
{
    private readonly GestorRuntime _runtime; private readonly Func<Task> _remote; private readonly Action _tickets;
    private Palette _p = new();
    public SupportPage(GestorRuntime runtime, Func<Task> remote, Action tickets) { _runtime = runtime; _remote = remote; _tickets = tickets; Loaded += (_, _) => Render(); }
    public void ApplyPalette(Palette p) { _p = p; Render(); }
    private void Render()
    {
        var root = new StackPanel { Spacing = 12 };
        root.Children.Add(Ui.Label("Central de atendimento", _p.Text, 20, true));
        root.Children.Add(Ui.Label("Atendimento vinculado à rede e ao gestor atualmente autorizado.", _p.Muted, 13, wrap: true));
        var network = new StackPanel { Spacing = 5 };
        network.Children.Add(Ui.Label("REDE ASSOCIADA", _p.Muted, 10, true));
        network.Children.Add(Ui.Label(string.IsNullOrWhiteSpace(_runtime.NetworkName) ? "Sem rede identificada" : _runtime.NetworkName, _p.Text, 17, true, true));
        network.Children.Add(Ui.Label(_runtime.Allowed ? "Sessão autorizada" : "Sessão indisponível — operações bloqueadas", _runtime.Allowed ? _p.Success : _p.Warning, 12, true));
        root.Children.Add(Ui.Card(network, _p, 14));
        var actions = new Grid { ColumnSpacing = 10, RowSpacing = 10 };
        actions.ColumnDefinitions.Add(new() { Width = new GridLength(1,GridUnitType.Star) });
        actions.ColumnDefinitions.Add(new() { Width = new GridLength(1,GridUnitType.Star) });
        var remote = new Button { Content = "Gerar código temporário", IsEnabled = _runtime.Allowed, HorizontalAlignment = HorizontalAlignment.Stretch, MinHeight = 44 };
        remote.Click += async (_, _) => await _remote();
        var tickets = new Button { Content = "Consultar chamados", IsEnabled = _runtime.Allowed, HorizontalAlignment = HorizontalAlignment.Stretch, MinHeight = 44 };
        tickets.Click += (_, _) => _tickets();
        actions.Children.Add(remote); Grid.SetColumn(tickets, 1); actions.Children.Add(tickets);
        root.Children.Add(actions);
        root.Children.Add(Ui.Label("O código de acesso remoto deve ser compartilhado somente com o atendente autorizado. Os chamados deste computador ficam disponíveis no menu Chamados.", _p.Muted, 12, wrap: true));
        Content = Ui.Card(root, _p, 15);
    }
}
