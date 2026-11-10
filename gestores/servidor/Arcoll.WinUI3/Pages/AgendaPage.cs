// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/AgendaPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Agenda de rotinas deste computador, igual à da plataforma: busca, filtro, nova rotina, editar, cancelar e histórico.</summary>
public sealed class AgendaPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad { get; } = new("Consultando agenda", true);
    public Task CollectDataAsync() => LoadAsync();
    private static readonly (string Key, string Label)[] Filters = { ("all", "Todas"), ("active", "Ativas"), ("inactive", "Encerradas") };
    private readonly GestorRuntime _runtime;
    private readonly StackPanel _items = new() { Spacing = 6 };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBox _search = new() { PlaceholderText = "Buscar rotina", HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly ComboBox _filter = new() { MinWidth = 130 };
    private readonly StackPanel _body = new() { Spacing = 12, Padding = new Thickness(20) };
    private Palette _p = new(); private bool _busy;
    private Dictionary<string, string> _services = new();
    private List<JsonElement> _rows = new(), _runs = new();
    public AgendaPage(GestorRuntime runtime)
    {
        _runtime = runtime;
        _body.Children.Add(new TextBlock { Text = "Agenda", FontSize = 24, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
        foreach (var (_, label) in Filters) _filter.Items.Add(label); _filter.SelectedIndex = 0;
        var bar = new Grid { ColumnSpacing = 8 };
        bar.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < 3; i++) bar.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        var refresh = new Button { Content = "Atualizar" }; var create = new Button { Content = "Nova rotina" };
        Grid.SetColumn(_filter, 1); Grid.SetColumn(refresh, 2); Grid.SetColumn(create, 3);
        bar.Children.Add(_search); bar.Children.Add(_filter); bar.Children.Add(refresh); bar.Children.Add(create);
        _body.Children.Add(bar); _body.Children.Add(_status); _body.Children.Add(_items);
        Content = new ScrollViewer { Content = _body, VerticalScrollBarVisibility = ScrollBarVisibility.Auto };
        _search.TextChanged += (_, _) => Render(); _filter.SelectionChanged += (_, _) => Render();
        refresh.Click += async (_, _) => await LoadAsync();
        create.Click += async (_, _) => await EditAsync(null);
        Loaded += async (_, _) => await LoadAsync();
    }
    public void ApplyPalette(Palette p)
    {
        _p = p; _body.Background = p.Alt; _status.Foreground = p.Muted;
        if (_body.Children[0] is TextBlock title) title.Foreground = p.Text;
        Render();
    }
    private async Task LoadAsync()
    {
        if (_busy) return;
        if (!_runtime.Allowed) { _status.Text = "Aguardando autenticação do computador."; return; }
        _busy = true; DataLoad.Begin("Consultando agenda", preserveData: true);
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            if (_services.Count == 0) _services = await SupportFmt.ServicesAsync(_runtime, timeout.Token);
            var response = await WorkspaceService.GetAsync(_runtime, "schedule-list", timeout.Token);
            _rows = response.GetProperty("schedules").EnumerateArray().Select(x => x.Clone()).ToList();
            _runs = response.TryGetProperty("runs", out var runs) ? runs.EnumerateArray().Select(x => x.Clone()).ToList() : new();
            _status.Text = _rows.Count + " rotina(s) encontrada(s).";
            Render(); DataLoad.Complete();
        }
        catch (Exception ex) { _status.Text = ex.Message; DataLoad.Fail(ex.Message); }
        finally { _busy = false; }
    }
    private string Service(JsonElement row) { var name = SupportFmt.Str(row, "command_name"); return _services.TryGetValue(name, out var label) ? label : name; }
    private static bool Active(JsonElement row) => SupportFmt.Flag(row, "enabled") && SupportFmt.Str(row, "next_run_at").Length > 0;
    private void Render()
    {
        _items.Children.Clear();
        var term = _search.Text.Trim().ToLowerInvariant(); var filter = Filters[Math.Max(0, _filter.SelectedIndex)].Key;
        _items.Children.Add(Line(new[] { "Serviço", "Repetição", "Próxima execução", "Situação" }, null, true));
        var shown = 0;
        foreach (var row in _rows)
        {
            var active = Active(row);
            if ((filter == "active" && !active) || (filter == "inactive" && active)) continue;
            if (term.Length > 0 && !Service(row).ToLowerInvariant().Contains(term)) continue;
            var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 5 };
            var history = new Button { Content = "Histórico" }; var current = row;
            history.Click += async (_, _) => await HistoryAsync(current); actions.Children.Add(history);
            if (SupportFmt.Flag(row, "enabled"))
            {
                var edit = new Button { Content = "Editar" }; edit.Click += async (_, _) => await EditAsync(current); actions.Children.Add(edit);
                var cancel = new Button { Content = "Cancelar" }; cancel.Click += async (_, _) => await CancelAsync(current); actions.Children.Add(cancel);
            }
            var state = active ? "Ativo" : SupportFmt.Flag(row, "enabled") ? "Encerrado" : "Cancelado";
            _items.Children.Add(Line(new[] { Service(row), SupportFmt.Recurrence(row), SupportFmt.When(SupportFmt.Str(row, "next_run_at")), state }, actions, false)); shown++;
        }
        if (shown == 0) _items.Children.Add(Ui.Label("Nenhuma rotina para os filtros selecionados.", _p.Muted, 13, wrap: true));
    }
    private UIElement Line(string[] cells, UIElement? actions, bool header)
    {
        var grid = new Grid { ColumnSpacing = 10 };
        var widths = new[] { 2.0, 1.5, 1.5, 1.0 };
        foreach (var w in widths) grid.ColumnDefinitions.Add(new() { Width = new GridLength(w, GridUnitType.Star) });
        grid.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        for (var i = 0; i < cells.Length; i++)
        {
            var text = Ui.Label(cells[i], header ? _p.Muted : _p.Text, header ? 11 : 13, header || i == 0, true); Grid.SetColumn(text, i); grid.Children.Add(text);
        }
        if (header) { var spacer = Ui.Label("Ações", _p.Muted, 11, true); Grid.SetColumn(spacer, 4); grid.Children.Add(spacer); }
        else if (actions is not null) { Grid.SetColumn((FrameworkElement)actions, 4); grid.Children.Add(actions); }
        return header ? grid : Ui.Card(grid, _p, 10);
    }
    private async Task EditAsync(JsonElement? row)
    {
        if (XamlRoot is null || !_runtime.Allowed) return;
        try
        {
            if (_services.Count == 0) { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20)); _services = await SupportFmt.ServicesAsync(_runtime, timeout.Token); }
            if (await ScheduleEditor.ShowAsync(XamlRoot, _p, _runtime, _services, null, row)) await LoadAsync();
        }
        catch (Exception ex) { _status.Text = ex.Message; }
    }
    private async Task CancelAsync(JsonElement row)
    {
        if (XamlRoot is null) return; var id = SupportFmt.Num(row, "id");
        var ask = Ui.MessageDialog(XamlRoot, _p, "Cancelar rotina", "Cancelar a rotina #" + id + "?", "Cancelar rotina", close: "Voltar", defaultButton: ContentDialogButton.Close);
        if (await ask.ShowAsync() != ContentDialogResult.Primary) return;
        try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20)); await WorkspaceService.GetAsync(_runtime, "schedule-cancel", timeout.Token, ("id", id)); await LoadAsync(); }
        catch (Exception ex) { _status.Text = ex.Message; }
    }
    private async Task HistoryAsync(JsonElement row)
    {
        if (XamlRoot is null) return; var id = SupportFmt.Num(row, "id");
        var list = new StackPanel { Spacing = 8 };
        foreach (var run in _runs.Where(x => SupportFmt.Num(x, "schedule_id") == id))
        {
            var state = SupportFmt.Str(run, "task_status"); if (state.Length == 0) state = SupportFmt.Str(run, "outcome");
            var detail = SupportFmt.Str(run, "result_text"); if (detail.Length == 0) detail = SupportFmt.Str(run, "message");
            list.Children.Add(Ui.Label(SupportFmt.When(SupportFmt.Str(run, "scheduled_for")) + " — " + state + " · " + detail, _p.Text, 12, wrap: true));
        }
        if (list.Children.Count == 0) list.Children.Add(Ui.Label("Nenhuma execução registrada.", _p.Muted, 12, wrap: true));
        await Ui.Dialog(XamlRoot, _p, "Execuções da rotina", new ScrollViewer { Content = list, MaxHeight = 400, VerticalScrollBarVisibility = ScrollBarVisibility.Auto }).ShowAsync();
    }
}
