// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/TicketsPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Chamados deste computador, com a mesma busca, filtros, paginação, detalhe e histórico da plataforma.</summary>
public sealed class TicketsPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad { get; } = new("Consultando chamados", true);
    public Task CollectDataAsync() => LoadAsync();

    private static readonly (string Key, string Label)[] Filters =
    {
        ("active", "Em aberto"), ("all", "Todos"), ("open", "Aberto"), ("in_progress", "Em atendimento"), ("waiting", "Aguardando cliente"), ("resolved", "Resolvido"), ("cancelled", "Cancelado"),
    };
    private static readonly (string Key, string Label)[] Priorities = { ("low", "Baixa"), ("normal", "Normal"), ("high", "Alta"), ("urgent", "Urgente") };

    private readonly GestorRuntime _runtime;
    private readonly Grid _root = new() { RowSpacing = 10, Padding = new Thickness(14, 10, 14, 12) };
    private readonly StackPanel _rows = new() { Spacing = 8 };
    private readonly Border _header = new() { CornerRadius = new CornerRadius(14), BorderThickness = new Thickness(1), Padding = new Thickness(14) };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBox _search = new() { PlaceholderText = "Buscar chamado", MaxLength = 100, HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly ComboBox _filter = new() { MinWidth = 170 };
    private readonly TextBlock _pageLabel = new() { VerticalAlignment = VerticalAlignment.Center };
    private readonly Button _previous = new() { Content = "Anterior" }, _next = new() { Content = "Próxima" };
    private Palette _p = new(); private bool _busy; private int _page = 1;

    public TicketsPage(GestorRuntime runtime)
    {
        _runtime = runtime; Content = _root;
        for (var i = 0; i < 3; i++) _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        _root.RowDefinitions.Add(new() { Height = new GridLength(1, GridUnitType.Star) });
        _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        _header.Child = new TextBlock { Text = "Chamados", FontSize = 20, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        _root.Children.Add(_header);
        foreach (var (_, label) in Filters) _filter.Items.Add(label); _filter.SelectedIndex = 0;
        var bar = new Grid { ColumnSpacing = 8 };
        bar.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < 3; i++) bar.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        var find = new Button { Content = "Filtrar" }; var create = new Button { Content = "Novo chamado" };
        Grid.SetColumn(_filter, 1); Grid.SetColumn(find, 2); Grid.SetColumn(create, 3);
        bar.Children.Add(_search); bar.Children.Add(_filter); bar.Children.Add(find); bar.Children.Add(create);
        Grid.SetRow(bar, 1); _root.Children.Add(bar);
        Grid.SetRow(_status, 2); _root.Children.Add(_status);
        var scroll = new ScrollViewer { Content = _rows, VerticalScrollBarVisibility = ScrollBarVisibility.Hidden, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0, 0, 5, 0) };
        Grid.SetRow(scroll, 3); _root.Children.Add(scroll);
        var paging = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 12, HorizontalAlignment = HorizontalAlignment.Center };
        paging.Children.Add(_previous); paging.Children.Add(_pageLabel); paging.Children.Add(_next);
        Grid.SetRow(paging, 4); _root.Children.Add(paging);
        find.Click += async (_, _) => { _page = 1; await LoadAsync(); };
        _search.KeyDown += async (_, e) => { if (e.Key == Windows.System.VirtualKey.Enter) { _page = 1; await LoadAsync(); } };
        _filter.SelectionChanged += async (_, _) => { if (IsLoaded) { _page = 1; await LoadAsync(); } };
        _previous.Click += async (_, _) => { if (_page > 1) { _page--; await LoadAsync(); } };
        _next.Click += async (_, _) => { _page++; await LoadAsync(); };
        create.Click += async (_, _) => { try { await CreateAsync(); } catch (Exception ex) { _status.Text = ex.Message; } };
        Loaded += async (_, _) => { try { await LoadAsync(); } catch (Exception ex) { RuntimeLog.Write("Chamados/Loaded: " + ex); } };
    }
    public void ApplyPalette(Palette p)
    {
        _p = p; _root.Background = p.Alt; _header.Background = p.Surface; _header.BorderBrush = p.Line;
        if (_header.Child is TextBlock title) title.Foreground = p.Text;
        _status.Foreground = p.Muted; _pageLabel.Foreground = p.Muted;
    }
    private async Task LoadAsync()
    {
        if (_busy || !_runtime.Allowed) return; _busy = true; DataLoad.Begin("Consultando chamados", preserveData: true);
        using var operation = OperationProgress.Begin("Consultar chamados");
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var result = await WorkspaceService.GetAsync(_runtime, "tickets", timeout.Token, ("status", Filters[Math.Max(0, _filter.SelectedIndex)].Key), ("q", _search.Text.Trim()), ("page", _page));
            _rows.Children.Clear();
            foreach (var ticket in result.GetProperty("tickets").EnumerateArray())
            {
                var id = SupportFmt.Num(ticket, "id"); var overdue = SupportFmt.Flag(ticket, "overdue");
                var left = new StackPanel { Spacing = 4 };
                left.Children.Add(Ui.Label("#" + id + " · " + SupportFmt.Str(ticket, "title"), _p.Text, 14, true, true));
                var technician = SupportFmt.Str(ticket, "technician"); if (technician.Length == 0) technician = "Sem responsável";
                left.Children.Add(Ui.Label(technician + " · " + SupportFmt.Str(ticket, "priority_label") + " · " + SupportFmt.Str(ticket, "minutes") + " min", _p.Muted, 12, wrap: true));
                var right = new StackPanel { Spacing = 4, HorizontalAlignment = HorizontalAlignment.Right };
                right.Children.Add(Ui.Label(SupportFmt.Str(ticket, "status_label"), _p.Accent, 12, true));
                right.Children.Add(Ui.Label((overdue ? "Prazo excedido · " : "") + SupportFmt.When(SupportFmt.Str(ticket, "due_at")), overdue ? _p.Danger : _p.Muted, 11));
                var line = new Grid { ColumnSpacing = 12 };
                line.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) }); line.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
                Grid.SetColumn(right, 1); line.Children.Add(left); line.Children.Add(right);
                var card = new Button { Content = line, HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Stretch, Padding = new Thickness(12), Background = _p.Surface, BorderBrush = _p.Line, CornerRadius = new CornerRadius(14) };
                var ticketId = id; card.Click += async (_, _) => { try { await DetailAsync(ticketId); } catch (Exception ex) { _status.Text = ex.Message; } };
                _rows.Children.Add(card);
            }
            _status.Text = _rows.Children.Count == 0 ? "Nenhum chamado neste filtro." : _rows.Children.Count + " chamado(s) nesta página.";
            _pageLabel.Text = "Página " + _page; _previous.IsEnabled = _page > 1; _next.IsEnabled = result.TryGetProperty("has_next", out var more) && more.ValueKind == JsonValueKind.True;
            operation.Succeed("Chamados consultados"); DataLoad.Complete();
        }
        catch (Exception ex) { _status.Text = ex.Message; operation.Fail(ex.Message); DataLoad.Fail(ex.Message); }
        finally { _busy = false; }
    }
    private async Task DetailAsync(long id)
    {
        if (XamlRoot is null || !_runtime.Allowed) return;
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
        var result = await WorkspaceService.GetAsync(_runtime, "ticket-detail", timeout.Token, ("ticket_id", id));
        var ticket = result.GetProperty("ticket"); var closed = SupportFmt.Str(ticket, "status") is "resolved" or "cancelled";
        var body = new StackPanel { Spacing = 10 };
        body.Children.Add(Ui.Label(SupportFmt.Str(ticket, "status_label"), _p.Accent, 12, true));
        body.Children.Add(Ui.Label(SupportFmt.Str(ticket, "description"), _p.Text, 13, wrap: true));
        body.Children.Add(Ui.Label("Prioridade " + SupportFmt.Str(ticket, "priority_label") + " · prazo " + SupportFmt.When(SupportFmt.Str(ticket, "due_at")), _p.Muted, 12, wrap: true));
        var note = new TextBox { Header = "Anotação para o atendimento", AcceptsReturn = true, TextWrapping = TextWrapping.Wrap, MinHeight = 70, MaxLength = 12000, Visibility = closed ? Visibility.Collapsed : Visibility.Visible };
        var error = Ui.Label("", _p.Danger, 12, false, true);
        body.Children.Add(note); body.Children.Add(error);
        body.Children.Add(Ui.Label("Histórico", _p.Text, 14, true));
        foreach (var e in result.GetProperty("events").EnumerateArray())
        {
            var item = new StackPanel { Spacing = 2 };
            var who = SupportFmt.Str(e, "display_name"); if (who.Length == 0) who = "Conta removida";
            item.Children.Add(Ui.Label(who + " · " + SupportFmt.When(SupportFmt.Str(e, "created_at")) + " · " + SupportFmt.Str(e, "minutes_spent") + " min", _p.Muted, 11));
            item.Children.Add(Ui.Label(SupportFmt.Str(e, "message"), _p.Text, 12, wrap: true));
            body.Children.Add(item);
        }
        var dialog = Ui.Dialog(XamlRoot, _p, "#" + id + " · " + SupportFmt.Str(ticket, "title"), new ScrollViewer { Content = body, MaxHeight = 480, VerticalScrollBarVisibility = ScrollBarVisibility.Auto }, closed ? "" : "Registrar atendimento", close: "Fechar", defaultButton: closed ? ContentDialogButton.Close : ContentDialogButton.Primary);
        var saved = false;
        dialog.PrimaryButtonClick += async (_, args) =>
        {
            var deferral = args.GetDeferral();
            try
            {
                using var t = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                await WorkspaceService.GetAsync(_runtime, "ticket-note", t.Token, ("ticket_id", id), ("message", note.Text));
                saved = true;
            }
            catch (Exception ex) { args.Cancel = true; error.Text = ex.Message; }
            finally { deferral.Complete(); }
        };
        await dialog.ShowAsync();
        if (saved) await LoadAsync();
    }
    private async Task CreateAsync()
    {
        if (_busy || !_runtime.Allowed || XamlRoot is null) return;
        var title = new TextBox { Header = "Título", MaxLength = 160 };
        var description = new TextBox { Header = "Descrição do problema", AcceptsReturn = true, TextWrapping = TextWrapping.Wrap, MinHeight = 100, MaxLength = 12000 };
        var priority = new ComboBox { Header = "Prioridade", HorizontalAlignment = HorizontalAlignment.Stretch };
        foreach (var (_, label) in Priorities) priority.Items.Add(label); priority.SelectedIndex = 1;
        var error = Ui.Label("", _p.Danger, 12, false, true);
        var panel = new StackPanel { Spacing = 8 }; panel.Children.Add(title); panel.Children.Add(description); panel.Children.Add(priority); panel.Children.Add(error);
        var dialog = Ui.Dialog(XamlRoot, _p, "Abrir chamado", panel, "Criar chamado", close: "Cancelar", defaultButton: ContentDialogButton.Primary, note: "O chamado fica vinculado a este computador e aparece na plataforma.");
        var created = false;
        dialog.PrimaryButtonClick += async (_, args) =>
        {
            var deferral = args.GetDeferral();
            try
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                await WorkspaceService.GetAsync(_runtime, "ticket-create", timeout.Token, ("title", title.Text), ("description", description.Text), ("priority", Priorities[Math.Max(0, priority.SelectedIndex)].Key));
                created = true;
            }
            catch (Exception ex) { args.Cancel = true; error.Text = ex.Message; }
            finally { deferral.Complete(); }
        };
        await dialog.ShowAsync();
        if (created) { _page = 1; await LoadAsync(); }
    }
}
