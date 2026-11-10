// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/SupportPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Suporte do gestor: os mesmos serviços da plataforma (executar agora ou criar rotina), aplicados a este computador.</summary>
public sealed class SupportPage : UserControl
{
    private readonly GestorRuntime _runtime; private readonly Func<Task> _remote; private readonly Action _tickets;
    private Palette _p = new();
    private Dictionary<string, string> _services = new();
    private string _feedback = ""; private bool _busy;
    public SupportPage(GestorRuntime runtime, Func<Task> remote, Action tickets) { _runtime = runtime; _remote = remote; _tickets = tickets; Loaded += async (_, _) => await LoadAsync(); }
    public void ApplyPalette(Palette p) { _p = p; Render(); }
    private async Task LoadAsync()
    {
        if (!_runtime.Allowed) { Render(); return; }
        try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20)); _services = await SupportFmt.ServicesAsync(_runtime, timeout.Token); _feedback = ""; }
        catch (Exception ex) { _feedback = ex.Message; }
        Render();
    }
    private void Render()
    {
        var root = new StackPanel { Spacing = 12 };
        root.Children.Add(Ui.Label("Serviços de suporte", _p.Text, 20, true));
        var network = new StackPanel { Spacing = 5 };
        network.Children.Add(Ui.Label("REDE ASSOCIADA", _p.Muted, 10, true));
        network.Children.Add(Ui.Label(string.IsNullOrWhiteSpace(_runtime.NetworkName) ? "Sem rede identificada" : _runtime.NetworkName, _p.Text, 17, true, true));
        network.Children.Add(Ui.Label(_runtime.Allowed ? "Sessão autorizada" : "Sessão indisponível — operações bloqueadas", _runtime.Allowed ? _p.Success : _p.Warning, 12, true));
        root.Children.Add(Ui.Card(network, _p, 14));
        if (_feedback.Length > 0) root.Children.Add(Ui.Label(_feedback, _p.Muted, 12, false, true));
        var grid = new VariableSizedWrapGrid { Orientation = Orientation.Horizontal, ItemWidth = 214, ItemHeight = 104 };
        foreach (var (command, label) in _services)
        {
            var tile = new StackPanel { Spacing = 6 };
            tile.Children.Add(new FontIcon { Glyph = SupportFmt.Glyph(command), FontSize = 20, Foreground = _p.Accent });
            tile.Children.Add(Ui.Label(label, _p.Text, 13, true, true));
            tile.Children.Add(Ui.Label("Executar agora ou criar rotina", _p.Muted, 11, false, true));
            var button = new Button { Content = tile, IsEnabled = _runtime.Allowed, HorizontalAlignment = HorizontalAlignment.Stretch, VerticalAlignment = VerticalAlignment.Stretch,
                HorizontalContentAlignment = HorizontalAlignment.Left, Margin = new Thickness(0, 0, 10, 10), Padding = new Thickness(14), Background = _p.Surface, BorderBrush = _p.Line, CornerRadius = new CornerRadius(12) };
            var key = command; var title = label;
            button.Click += async (_, _) => await OpenServiceAsync(key, title);
            grid.Children.Add(button);
        }
        root.Children.Add(grid);
        var actions = new Grid { ColumnSpacing = 10, RowSpacing = 10 };
        actions.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        actions.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        var remote = new Button { Content = "Gerar código temporário", IsEnabled = _runtime.Allowed, HorizontalAlignment = HorizontalAlignment.Stretch, MinHeight = 44 };
        remote.Click += async (_, _) => await _remote();
        var tickets = new Button { Content = "Consultar chamados", IsEnabled = _runtime.Allowed, HorizontalAlignment = HorizontalAlignment.Stretch, MinHeight = 44 };
        tickets.Click += (_, _) => _tickets();
        actions.Children.Add(remote); Grid.SetColumn(tickets, 1); actions.Children.Add(tickets);
        root.Children.Add(actions);
        root.Children.Add(Ui.Label("O código de acesso remoto deve ser compartilhado somente com o atendente autorizado. Os chamados e as rotinas deste computador ficam nos menus Chamados e Agenda.", _p.Muted, 12, wrap: true));
        Content = new ScrollViewer { Content = Ui.Card(root, _p, 15), VerticalScrollBarVisibility = ScrollBarVisibility.Auto };
    }
    private async Task OpenServiceAsync(string command, string label)
    {
        if (_busy || XamlRoot is null) return;
        var energy = command.StartsWith("power_", StringComparison.Ordinal);
        var password = new PasswordBox { Header = "Senha da sua conta (comandos de energia)", Visibility = energy ? Visibility.Visible : Visibility.Collapsed };
        var confirm = new CheckBox { Content = "Confirmo a operação de energia", Visibility = energy ? Visibility.Visible : Visibility.Collapsed };
        var error = Ui.Label("", _p.Danger, 12, false, true);
        var body = new StackPanel { Spacing = 10 };
        body.Children.Add(Ui.Label("Escolha como deseja executar este serviço.", _p.Muted, 12, false, true));
        body.Children.Add(password); body.Children.Add(confirm); body.Children.Add(error);
        var dialog = Ui.Dialog(XamlRoot, _p, label, body, "Executar agora", "Criar rotina", "Fechar", ContentDialogButton.Primary);
        var runNow = false;
        dialog.PrimaryButtonClick += async (_, args) =>
        {
            var deferral = args.GetDeferral();
            try
            {
                _busy = true; using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                var result = await WorkspaceService.GetAsync(_runtime, "service-run", timeout.Token, ("command", command), ("password", password.Password), ("confirmed", confirm.IsChecked == true));
                _feedback = "Comando #" + SupportFmt.Str(result, "command_id") + " enviado; aguarde a confirmação do agente."; runNow = true;
            }
            catch (Exception ex) { args.Cancel = true; error.Text = ex.Message; }
            finally { _busy = false; deferral.Complete(); }
        };
        var choice = await dialog.ShowAsync();
        if (choice == ContentDialogResult.Secondary)
        {
            if (await ScheduleEditor.ShowAsync(XamlRoot, _p, _runtime, _services, command, null)) _feedback = "Rotina criada. Consulte a Agenda para gerenciar.";
        }
        if (runNow || choice == ContentDialogResult.Secondary) Render();
    }
}
