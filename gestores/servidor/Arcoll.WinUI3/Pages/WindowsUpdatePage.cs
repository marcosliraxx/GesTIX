// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/WindowsUpdatePage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace Arcoll.WinUI3.Pages;

/// <summary>Página nativa de Windows Update. Usa COM do Windows, seleção real por GUID e revisão; não executa scripts.</summary>
public sealed class WindowsUpdatePage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Clique para consultar as atualizações oficiais do Windows.");
    public Task CollectDataAsync()=>ExecuteAsync("scan");
    private readonly GestorRuntime _runtime;
    private Palette _p = new();
    private readonly StackPanel _root = new() { Spacing = 12, HorizontalAlignment = HorizontalAlignment.Stretch };
    private string _statusText = "Verifique as atualizações do Windows.";
    private readonly List<string> _history = new();
    private string _details = "";
    private readonly HashSet<(string Id, int Revision)> _selected = new();
    private readonly List<(string Id, int Revision, string Title, double Size)> _updates = new();
    private bool _working;
    private Button? _installButton;
    private TextBlock? _selectedMetric;

    public WindowsUpdatePage(GestorRuntime runtime)
    {
        _runtime = runtime;
        Content = _root;
        SizeChanged += (_,e) => { if((e.NewSize.Width < 720)!=(e.PreviousSize.Width < 720)) Render(); };
        Render();
    }

    public void ApplyPalette(Palette palette) { _p = palette; Render(); }

    private void AddAction(StackPanel bar, string label, string mode, bool enabled = true)
    {
        var button = new Button { Content = label, IsEnabled = !_working && _runtime.Allowed && enabled };
        button.Click += async (_, _) => await ExecuteAsync(mode);
        if (mode == "install") _installButton = button;
        bar.Children.Add(button);
    }

    private void Render()
    {
        _root.Children.Clear();
        var bar = new StackPanel { Orientation = ActualWidth < 720 ? Orientation.Vertical : Orientation.Horizontal, Spacing = 6 };
        var rows = new StackPanel { Spacing = 8 };
        var title = Ui.Label("WINDOWS UPDATE", _p.Text, 19, true);
        var subtitle = Ui.Label("Atualizações verificadas pelo serviço oficial do Windows, sem reinicialização automática.", _p.Muted, 12, false, true);
        var header = new StackPanel { Spacing = 6 }; header.Children.Add(title); header.Children.Add(subtitle);
        _root.Children.Add(Ui.Card(header, _p, 18));

        AddAction(bar, "Verificar", "scan"); AddAction(bar, "Histórico", "history"); AddAction(bar, "Configurações", "settings");
        AddAction(bar, "Instalar selecionadas (" + _selected.Count + ")", "install", _selected.Count > 0);
        var actions = new StackPanel { Spacing = 10 };
        actions.Children.Add(bar);
        var second = new StackPanel { Orientation = ActualWidth < 720 ? Orientation.Vertical : Orientation.Horizontal, Spacing = 6 };
        foreach (var (label, mode) in new[] { ("Pausar", "pause"), ("Retomar", "resume"), ("Avançadas", "advanced"), ("Windows Insider", "insider") })
        {
            var button = new Button { Content = label, IsEnabled = !_working && _runtime.Allowed };
            button.Click += async (_, _) => await ExecuteAsync(mode);
            second.Children.Add(button);
        }
        actions.Children.Add(second);
        var state = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 10 };
        var progress = new ProgressRing { Width = 28, Height = 28, IsActive = _working, Visibility = _working ? Visibility.Visible : Visibility.Collapsed };
        state.Children.Add(progress);
        state.Children.Add(new TextBlock { Text = _statusText, TextWrapping = TextWrapping.Wrap, Foreground = _p.Muted });
        actions.Children.Add(state);
        _root.Children.Add(Ui.Card(actions, _p, 16));
        var list = new StackPanel { Spacing = 12 };
        var metrics = new Grid { ColumnSpacing = 10 };
        metrics.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        metrics.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        var found = Ui.Stat("Atualizações listadas", _p); found.Value.Text = _updates.Count.ToString();
        var chosen = Ui.Stat("Selecionadas", _p); chosen.Value.Text = _selected.Count.ToString(); _selectedMetric = chosen.Value;
        metrics.Children.Add(found.Card); Grid.SetColumn(chosen.Card, 1); metrics.Children.Add(chosen.Card);
        list.Children.Add(metrics);
        list.Children.Add(Ui.Label("RESULTADOS", _p.Text, 15, true));
        foreach (var item in _updates)
        {
            var selected = (item.Id, item.Revision);
            var checkbox = new CheckBox { Content = item.Title + " · " + Ui.Size(item.Size), IsChecked = _selected.Contains(selected) };
            checkbox.Checked += (_, _) => { _selected.Add(selected); RefreshSelectionAction(); };
            checkbox.Unchecked += (_, _) => { _selected.Remove(selected); RefreshSelectionAction(); };
            rows.Children.Add(checkbox);
        }
        foreach (var history in _history) rows.Children.Add(Ui.Label(history, _p.Text, 12, false, true));
        if (_details.Length > 0) rows.Children.Add(Ui.Label(_details, _p.Muted, 11, false, true));
        if (rows.Children.Count == 0) rows.Children.Add(Ui.Label("Nenhum registro encontrado.", _p.Muted, 13));
        list.Children.Add(rows);
        _root.Children.Add(Ui.Card(list, _p, 16));
    }

    private void RefreshSelectionAction()
    {
        if (_installButton is null) return;
        if (_selectedMetric is not null) _selectedMetric.Text = _selected.Count.ToString();
        _installButton.Content = "Instalar selecionadas (" + _selected.Count + ")";
        _installButton.IsEnabled = !_working && _runtime.Allowed && _selected.Count > 0;
    }

    private async Task ExecuteAsync(string mode)
    {
        if (_working || !_runtime.Allowed) { _statusText = "Autorize o gestor na plataforma antes de operar."; Render(); return; }
        if (mode is "install" or "pause" or "resume")
        {
            if (mode == "install" && _selected.Count == 0) return;
            var message = mode == "install" ? $"Baixar e instalar {_selected.Count} atualizações selecionadas? O Windows pode solicitar elevação administrativa; não haverá reinicialização automática." :
                (mode == "pause" ? "Pausar as atualizações segundo as políticas do Windows?" : "Retomar as atualizações segundo as políticas do Windows?");
            var confirmation = Ui.MessageDialog(XamlRoot, _p, "Confirmar Windows Update", message, "Confirmar", close: "Cancelar", defaultButton: ContentDialogButton.Primary, note: "A execução usa os componentes oficiais do Windows e não reinicia automaticamente.");
            if (await confirmation.ShowAsync() != ContentDialogResult.Primary || !_runtime.Allowed) return;
        }
        _working = true;if(mode=="scan")DataLoad.Begin("Consultando o serviço Windows Update"); _statusText = mode == "scan" ? "Consultando o serviço Windows Update..." : "Executando " + mode + "...";
        Render();
        try
        {
            var values = _selected.Select(x => new { id = x.Id, revision = x.Revision }).ToArray();
            var request = JsonSerializer.Serialize(new { mode, updates = values });
            using var timeout = new CancellationTokenSource(mode == "install" ? TimeSpan.FromHours(2) : TimeSpan.FromMinutes(15));
            var report = await WindowsUpdateService.RunAsync(request, timeout.Token);
            PresentReport(report, mode);if(mode=="scan")DataLoad.Complete();
        }
        catch (Exception ex) {if(mode=="scan")DataLoad.Fail("Falha ao consultar o Windows Update: "+ex.Message);_statusText = "Falha no Windows Update: " + ex.Message; }
        finally { _working = false; Render(); }
    }

    private void PresentReport(Dictionary<string, object?> report, string mode)
    {
        _history.Clear(); _details = "";
        var json = JsonSerializer.Serialize(report);
        using var document = JsonDocument.Parse(json);
        var root = document.RootElement;
        var version = root.TryGetProperty("windows", out var w) ? w.GetString() ?? "Windows" : "Windows";
        if (mode == "scan")
        {
            _updates.Clear(); _selected.Clear();
            foreach (var update in root.GetProperty("updates").EnumerateArray())
            {
                var id = update.GetProperty("id").GetString() ?? "";
                var revision = update.GetProperty("revision").GetInt32();
                var title = update.GetProperty("title").GetString() ?? "Atualização";
                var size = update.GetProperty("size").GetDouble();
                _updates.Add((id, revision, title, size));
            }
            _statusText = $"{version} · {root.GetProperty("total").GetInt32()} atualizações disponíveis. Selecione as desejadas.";
        }
        else if (mode == "history")
        {
            _updates.Clear(); _selected.Clear();
            foreach (var row in root.GetProperty("history").EnumerateArray())
                _history.Add(row.GetProperty("date").GetString() + " · " + row.GetProperty("title").GetString());
            _statusText = $"Histórico: {root.GetProperty("total").GetInt32()} eventos encontrados.";
        }
        else
        {
            if (mode == "install") { _selected.Clear(); _updates.Clear(); }
            var partial = mode == "install" && root.TryGetProperty("result", out var installCode) && installCode.ValueKind == JsonValueKind.Number && installCode.GetInt32() != 2;
            _statusText = mode == "install"
                ? (partial ? "Instalação parcial ou com falhas. Verifique os códigos do relatório. " : "Instalação concluída. ") + (root.TryGetProperty("reboot_required", out var r) && r.ValueKind == JsonValueKind.True ? "Reinicialização manual necessária." : "Nenhum reinício automático foi iniciado.")
                : root.TryGetProperty("message", out var message) ? message.GetString() ?? "Operação concluída." : "Configurações consultadas.";
            _details = json.Length > 1800 ? json[..1800] + "…" : json;
        }

    }
}
