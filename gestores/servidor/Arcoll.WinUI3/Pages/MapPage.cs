// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/MapPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Shapes;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Mapa: topologia da rede como em redes/mapa.php — rede (hub) no topo, ramos por grupo, equipamentos online/offline e legenda.</summary>
public sealed class MapPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Consultando a topologia da rede",true);
    public Task CollectDataAsync()=>LoadAsync();

    private readonly GestorRuntime _runtime;
    private readonly Action<string> _notify;
    private Palette _p = new();
    private IReadOnlyList<DeviceRecord> _all = Array.Empty<DeviceRecord>();
    private bool _busy, _loaded, _scanning;
    private string _message = "";
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(20) };
    private readonly ScrollViewer _scroll = new() { VerticalScrollBarVisibility = ScrollBarVisibility.Hidden, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled };

    public MapPage(GestorRuntime runtime, Action<string> notify)
    {
        _runtime = runtime; _notify = notify; Content = _scroll;
        _timer.Tick += async (_, _) => await LoadAsync();
        Loaded += async (_, _) => { try { _timer.Start(); await LoadAsync(); } catch (Exception ex) {DataLoad.Fail("Não foi possível carregar: "+ex.Message); RuntimeLog.Write("Mapa/Loaded: " + ex); _timer.Stop(); } };
        Unloaded += (_, _) => _timer.Stop();
        Render();
    }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private async Task LoadAsync()
    {
        if (_busy || !_runtime.Allowed) return; _busy = true;DataLoad.Begin("Consultando a topologia da rede",preserveData:true);
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20)); _all = await InventoryService.LoadAsync(_runtime.Platform, cancel.Token); _loaded = true;DataLoad.Complete(); Render(); }
        catch (Exception ex) {DataLoad.Fail("Não foi possível carregar: "+ex.Message); _message = "Não foi possível consultar o inventário: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); Render(); }
        finally { _busy = false; }
    }

    /// <summary>Tocar na rede executa a varredura (mesmo comportamento do hub no painel web).</summary>
    private async Task ScanAsync()
    {
        if (_scanning) return; _scanning = true; _message = "Examinando dispositivos da rede…"; Render();
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(120)); var n = await _runtime.ScanNowAsync(cancel.Token); _message = $"{n} dispositivos sincronizados."; }
        catch (Exception ex) { _message = "Falha na varredura: " + ex.Message; }
        finally { _scanning = false; }
        await LoadAsync();
    }

    private void Render()
    {
        var page = new StackPanel { Spacing = 14 };
        var groups = _all.GroupBy(d => d.Group.Length > 0 ? d.Group : "Sem grupo").OrderBy(g => g.Key).ToList();
        var top = new Grid { ColumnSpacing = 10 };
        top.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        top.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        top.Children.Add(Ui.Label(_loaded ? $"{_all.Count} dispositivos · {groups.Count} grupos" : "Consultando inventário da plataforma…", _p.Muted, 12));
        var refresh = new Button { Content = "Atualizar" }; refresh.Click += async (_, _) => await LoadAsync();
        Grid.SetColumn(refresh, 1); top.Children.Add(refresh); page.Children.Add(top);

        // Hub da rede
        var hubText = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Center };
        hubText.Children.Add(new FontIcon { Glyph = "\uE968", FontFamily = new FontFamily(DeviceEditor.IconFont), FontSize = 26, Foreground = _p.Accent });
        var net = _runtime.NetworkName.Length > 0 ? _runtime.NetworkName : "Rede";
        hubText.Children.Add(Ui.Label(net, _p.Text, 15, true));
        hubText.Children.Add(Ui.Label(_scanning ? "Examinando…" : "Toque para examinar a rede", _p.Muted, 11));
        var hub = new Button { Content = hubText, Padding = new Thickness(26, 12, 26, 12), CornerRadius = new CornerRadius(14), HorizontalAlignment = HorizontalAlignment.Center, IsEnabled = !_scanning, Background = _p.Surface, BorderBrush = _p.Accent, BorderThickness = new Thickness(2) };
        hub.Click += async (_, _) => await ScanAsync();
        ToolTipService.SetToolTip(hub, "Executar varredura de dispositivos");
        page.Children.Add(hub);
        page.Children.Add(Trunk());

        if (_loaded && _all.Count == 0)
        {
            var empty = new StackPanel { Spacing = 6, HorizontalAlignment = HorizontalAlignment.Center, Padding = new Thickness(0, 24, 0, 24) };
            empty.Children.Add(Ui.Label("Nenhum dispositivo no inventário", _p.Text, 16, true)); empty.Children.Add(Ui.Label("Toque na rede para procurar equipamentos.", _p.Muted, 12));
            page.Children.Add(Ui.Card(empty, _p));
        }
        foreach (var g in groups)
        {
            var head = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
            head.Children.Add(new FontIcon { Glyph = "\uED25", FontFamily = new FontFamily(DeviceEditor.IconFont), FontSize = 15, Foreground = _p.Accent });
            head.Children.Add(Ui.Label(g.Key, _p.Text, 14, true)); head.Children.Add(Ui.Label($"{g.Count()} equipamentos", _p.Muted, 12));
            foreach (var c in head.Children) if (c is FrameworkElement fe) fe.VerticalAlignment = VerticalAlignment.Center;
            var grid = new VariableSizedWrapGrid { Orientation = Orientation.Horizontal, ItemWidth = 214, ItemHeight = 74 };
            foreach (var d in g.OrderByDescending(x => x.Pinned).ThenBy(x => x.Name, StringComparer.CurrentCultureIgnoreCase)) grid.Children.Add(Node(d));
            var body = new StackPanel { Spacing = 10 }; body.Children.Add(head); body.Children.Add(grid);
            page.Children.Add(Ui.Card(body, _p));
        }

        var legend = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 18, HorizontalAlignment = HorizontalAlignment.Center };
        legend.Children.Add(Ui.Label("● Online", _p.Success, 12, true)); legend.Children.Add(Ui.Label("● Offline", _p.Danger, 12, true)); legend.Children.Add(Ui.Label("▣ Gestor instalado", _p.Accent, 12, true));
        page.Children.Add(legend);
        if (_message.Length > 0) page.Children.Add(Ui.Label(_message, _p.Muted, 12, false, true));
        _scroll.Content = page;
    }

    private UIElement Trunk() => new Rectangle { Width = 2, Height = 18, Fill = _p.Accent, HorizontalAlignment = HorizontalAlignment.Center, Opacity = .6 };

    private UIElement Node(DeviceRecord d)
    {
        var tone = d.Online ? _p.Success : _p.Danger;
        var icon = new FontIcon { Glyph = DeviceEditor.Glyph(d.Type), FontFamily = new FontFamily(DeviceEditor.IconFont), FontSize = 20, Foreground = d.Online ? _p.Accent : _p.Muted };
        var text = new StackPanel { Spacing = 1, VerticalAlignment = VerticalAlignment.Center };
        var title = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 5 };
        title.Children.Add(new Ellipse { Width = 8, Height = 8, Fill = tone, VerticalAlignment = VerticalAlignment.Center });
        title.Children.Add(Ui.Label(d.Name, _p.Text, 13, true));
        if (d.GestorInstalled) title.Children.Add(new FontIcon { Glyph = "\uE74C", FontFamily = new FontFamily(DeviceEditor.IconFont), FontSize = 11, Foreground = _p.Accent });
        text.Children.Add(title); text.Children.Add(Ui.Label(d.Ip, _p.Muted, 11)); text.Children.Add(Ui.Label(InventoryService.TypeLabel(d.Type), _p.Muted, 10));
        var row = new Grid { ColumnSpacing = 10 };
        row.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto }); row.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        row.Children.Add(icon); icon.VerticalAlignment = VerticalAlignment.Center; Grid.SetColumn(text, 1); row.Children.Add(text);
        var button = new Button
        {
            Content = row, HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Left, Margin = new Thickness(0, 0, 8, 8), Padding = new Thickness(10, 8, 10, 8),
            Background = _p.Alt, BorderBrush = tone, BorderThickness = new Thickness(1.5), CornerRadius = new CornerRadius(12), Opacity = d.Online ? 1 : .78,
        };
        ToolTipService.SetToolTip(button, $"{d.Name} · {d.Ip}");
        var device = d;
        button.Click += async (_, _) => { if (await DeviceEditor.ShowAsync(XamlRoot, device, _p, _runtime, s => { _message = s; _notify(s); }) != EditorResult.Cancelled) await LoadAsync(); };
        return button;
    }
}
