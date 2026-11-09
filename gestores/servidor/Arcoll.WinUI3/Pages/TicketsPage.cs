// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/TicketsPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Chamados vinculados somente a este computador, pela identidade aprovada do gestor.</summary>
public sealed class TicketsPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Consultando chamados",true);
    public Task CollectDataAsync()=>LoadAsync();

    private readonly GestorRuntime _runtime;
    private readonly Grid _root = new() { RowSpacing = 10, Padding = new Thickness(14, 10, 14, 12) };
    private readonly StackPanel _rows = new() { Spacing = 8 };
    private readonly Border _header = new() { CornerRadius = new CornerRadius(14), BorderThickness = new Thickness(1), Padding = new Thickness(14) };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private Palette _p = new(); private bool _busy;
    public TicketsPage(GestorRuntime runtime)
    {
        _runtime = runtime; Content = _root;
        _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        _root.RowDefinitions.Add(new() { Height = new GridLength(1, GridUnitType.Star) });
        _header.Child = new TextBlock { Text = "Chamados deste computador", FontSize = 20, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        _root.Children.Add(_header);
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        var refresh = new Button { Content = "Atualizar" }; refresh.Click += async (_, _) => await LoadAsync(); actions.Children.Add(refresh);
        var create = new Button { Content = "Abrir chamado" }; create.Click += async (_, _) => { try { await CreateAsync(); } catch (Exception ex) { _status.Text = ex.Message; } }; actions.Children.Add(create);
        Grid.SetRow(actions, 1); _root.Children.Add(actions);
        Grid.SetRow(_status, 2); _root.Children.Add(_status);
        var scroll = new ScrollViewer { Content = _rows, VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0, 0, 5, 0) };
        Grid.SetRow(scroll, 3); _root.Children.Add(scroll);
        Loaded += async (_, _) => { try { await LoadAsync(); } catch (Exception ex) { RuntimeLog.Write("Chamados/Loaded: " + ex); } };
    }
    public void ApplyPalette(Palette p)
    {
        _p = p; _root.Background = p.Alt; _header.Background = p.Surface; _header.BorderBrush = p.Line;
        if (_header.Child is TextBlock title) title.Foreground = p.Text;
        _status.Foreground = p.Muted;
        // Recria os cartões existentes na paleta ativa sem efetuar outra consulta à plataforma.
        foreach (var child in _rows.Children.OfType<Border>()) { child.Background = p.Surface; child.BorderBrush = p.Line; }
    }
    private async Task LoadAsync()
    {
        if (_busy || !_runtime.Allowed) return; _busy = true;DataLoad.Begin("Consultando chamados",preserveData:true);
        using var operation = OperationProgress.Begin("Consultar chamados");
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var result = await WorkspaceService.GetAsync(_runtime, "tickets", timeout.Token); _rows.Children.Clear();
            foreach (var ticket in result.GetProperty("tickets").EnumerateArray())
            {
                var panel = new StackPanel { Spacing = 5 };
                panel.Children.Add(Ui.Label("#" + ticket.GetProperty("id") + " · " + ticket.GetProperty("title"), _p.Text, 14, true, true));
                var status = ticket.GetProperty("status").GetString() ?? "";
                var label = status switch { "open" => "Aberto", "in_progress" => "Em atendimento", "resolved" => "Resolvido", "closed" => "Fechado", _ => status };
                panel.Children.Add(Ui.Label(label + " · " + ticket.GetProperty("created_at"), _p.Muted, 12, wrap: true));
                panel.Children.Add(Ui.Label(ticket.GetProperty("description").ToString(), _p.Text, 13, wrap: true));
                _rows.Children.Add(Ui.Card(panel, _p));
            }
            _status.Text = _rows.Children.Count == 0 ? "Nenhum chamado vinculado a este computador." : _rows.Children.Count + " chamados.";
            operation.Succeed("Chamados consultados");DataLoad.Complete();
        }
        catch (Exception ex) { _status.Text = ex.Message; operation.Fail(ex.Message);DataLoad.Fail(ex.Message); }
        finally { _busy = false; }
    }
    private async Task CreateAsync()
    {
        if (_busy || !_runtime.Allowed) return;
        var title = new TextBox { Header = "Título", MaxLength = 160 };
        var description = new TextBox { Header = "Descreva o problema", AcceptsReturn = true, TextWrapping = TextWrapping.Wrap, MinHeight = 100, MaxLength = 12000 };
        var priority = new ComboBox { Header = "Prioridade", HorizontalAlignment = HorizontalAlignment.Stretch };
        foreach (var label in new[] { "Baixa", "Normal", "Alta", "Urgente" }) priority.Items.Add(label); priority.SelectedIndex = 1;
        var panel = new StackPanel { Spacing = 8 }; panel.Children.Add(title); panel.Children.Add(description); panel.Children.Add(priority);
        var dialog = Ui.Dialog(XamlRoot, _p, "Abrir chamado", panel, "Enviar chamado", close: "Cancelar", defaultButton: ContentDialogButton.Primary, note: "Descreva o atendimento solicitado para este computador.");
        if (await dialog.ShowAsync() != ContentDialogResult.Primary || !_runtime.Allowed) return;
        using (var operation = OperationProgress.Begin("Abrir chamado"))
        {
            try
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                var response = await WorkspaceService.GetAsync(_runtime, "ticket-create", timeout.Token, ("title", title.Text), ("description", description.Text), ("priority", new[] { "low", "normal", "high", "urgent" }[priority.SelectedIndex]));
                operation.Succeed("Chamado #" + response.GetProperty("ticket_id") + " aberto");
            }
            catch (Exception ex) { _status.Text = ex.Message; operation.Fail(ex.Message); return; }
        }
        await LoadAsync();
    }
}
