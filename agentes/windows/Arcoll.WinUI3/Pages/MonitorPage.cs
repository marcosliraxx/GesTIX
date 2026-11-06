// Caminho: agentes/windows/Arcoll.WinUI3/Pages/MonitorPage.cs
using System.Globalization;
using System.Text;
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Monitoramento: mesmos indicadores e gráfico do agente 3.10 (latência, perda, dispositivos, estado; linhas/área/barras; relatório).</summary>
public sealed class MonitorPage : UserControl
{
    private readonly TelemetryHub _hub;
    private readonly Func<bool> _monitoring;
    private Palette _p = new();
    private readonly Border _badge = new();
    private readonly TextBlock _badgeText;
    private readonly (Border Card, TextBlock Value, TextBlock Caption)[] _kpi = new (Border, TextBlock, TextBlock)[4];
    private readonly ChartCanvas _chart = new() { Height = 320 };
    private readonly ComboBox _style = new() { MinWidth = 120 };
    private readonly Dictionary<string, CheckBox> _flags = new();
    private readonly TextBlock _legend, _message, _caption, _sub;
    private readonly Button _report = new() { Content = "Relatório" }, _scan = new() { Content = "Varrer rede" };
    private readonly List<Border> _cards = new();
    private Border _chartCard = null!;
    private int? _devices;
    private bool _scanning;

    private static readonly (string Key, string Name, string Color, string Unit)[] Defs =
    {
        ("latency", "Latência", "#31beff", "ms"), ("download", "Download", "#12856b", "Mbps"), ("upload", "Upload", "#7352a6", "Mbps"), ("loss", "Perdas", "#bc4356", "%"),
    };

    public MonitorPage(TelemetryHub hub, Func<bool> monitoring)
    {
        _hub = hub; _monitoring = monitoring;
        _badgeText = Ui.Label("● MONITORAMENTO CONTÍNUO", _p.Success, 11, true);
        _caption = Ui.Label("MONITORAMENTO · LATÊNCIA / DOWNLOAD / UPLOAD", _p.Accent, 12, true);
        _sub = Ui.Label("Amostras do computador com agente", _p.Muted, 11);
        _legend = Ui.Label("", _p.Muted, 11, false, true); _message = Ui.Label("", _p.Muted, 11, false, true);
        Content = Build();
        _hub.Updated += OnUpdated; Unloaded += (_, _) => _hub.Updated -= OnUpdated;
        _ = ScanAsync(false); Render();
    }

    private UIElement Build()
    {
        var root = new StackPanel { Spacing = 12 };
        var top = new Grid { ColumnSpacing = 10 };
        top.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        for (var i = 0; i < 4; i++) top.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        _badge.CornerRadius = new CornerRadius(14); _badge.BorderThickness = new Thickness(1); _badge.Padding = new Thickness(16, 12, 16, 12); _badge.Child = _badgeText;
        _badge.VerticalAlignment = VerticalAlignment.Stretch; top.Children.Add(_badge);
        var titles = new[] { "Latência", "Perda", "Dispositivos", "Estado" };
        for (var i = 0; i < 4; i++)
        {
            _kpi[i] = Ui.Stat(titles[i], _p, 20); _cards.Add(_kpi[i].Card); Grid.SetColumn(_kpi[i].Card, i + 1); top.Children.Add(_kpi[i].Card);
        }
        root.Children.Add(top);

        var head = new Grid(); head.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) }); head.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        head.Children.Add(_caption); Grid.SetColumn(_sub, 1); head.Children.Add(_sub);
        var controls = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 12 };
        foreach (var s in new[] { "Linhas", "Área", "Barras" }) _style.Items.Add(s);
        _style.SelectedIndex = 0; _style.SelectionChanged += (_, _) => Render(); controls.Children.Add(_style);
        foreach (var d in Defs)
        {
            var cb = new CheckBox { Content = d.Name, IsChecked = true, MinWidth = 0 }; cb.Checked += (_, _) => Render(); cb.Unchecked += (_, _) => Render();
            _flags[d.Key] = cb; controls.Children.Add(cb);
        }
        _scan.Click += async (_, _) => await ScanAsync(true); _report.Click += (_, _) => SaveReport();
        controls.Children.Add(_scan); controls.Children.Add(_report);
        var inner = new StackPanel { Spacing = 10 }; inner.Children.Add(head); inner.Children.Add(controls); inner.Children.Add(_chart); inner.Children.Add(_legend); inner.Children.Add(_message);
        _chartCard = Ui.Card(inner, _p, 16); root.Children.Add(_chartCard);
        return root;
    }

    public void ApplyPalette(Palette p)
    {
        _p = p;
        foreach (var c in _cards) { c.Background = p.Surface; c.BorderBrush = p.Line; }
        _chartCard.Background = p.Surface; _chartCard.BorderBrush = p.Line; _badge.Background = p.Alt; _badge.BorderBrush = p.Line;
        _caption.Foreground = p.Accent; _sub.Foreground = p.Muted; _legend.Foreground = p.Muted; _message.Foreground = p.Muted;
        foreach (var k in _kpi) { k.Caption.Foreground = p.Muted; k.Value.Foreground = p.Text; }
        _chart.SetColors(p.Muted, p.Line); Render();
    }

    private void OnUpdated() => DispatcherQueue?.TryEnqueue(Render);

    private async Task ScanAsync(bool refresh)
    {
        if (_scanning) return; _scanning = true; _scan.IsEnabled = false;
        try
        {
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(60));
            var found = await NetworkEngine.ScanAsync(null, refresh, cancel.Token);
            _devices = found.Count;
        }
        catch (Exception ex) { _message.Text = "Varredura indisponível: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); }
        finally { _scanning = false; _scan.IsEnabled = true; Render(); }
    }

    private static IReadOnlyList<double?> Values(IReadOnlyList<TelemetryPoint> pts, string key) =>
        pts.TakeLast(60).Select(x => key switch { "latency" => x.Latency, "download" => (double?)x.Down, "upload" => (double?)x.Up, _ => (double?)x.Loss }).ToArray();

    private void Render()
    {
        var pts = _hub.Snapshot(); var last = pts.Count > 0 ? pts[^1] : null; var on = _monitoring();
        _badgeText.Text = on ? "● MONITORAMENTO CONTÍNUO" : "❚❚ MONITORAMENTO PAUSADO";
        _badgeText.Foreground = on ? _p.Success : _p.Warning;
        _kpi[0].Value.Text = last?.Latency is double l ? $"{l:0} ms" : "--";
        _kpi[1].Value.Text = last is null ? "--" : $"{last.Loss:0}%";
        _kpi[2].Value.Text = _devices is int d ? d.ToString(CultureInfo.CurrentCulture) : "--";
        _kpi[3].Value.Text = !on ? "Pausado" : last is null ? "Iniciando" : _hub.InternetUp ? "Online" : "Sem Internet";
        _kpi[3].Value.Foreground = !on ? _p.Warning : last is null || _hub.InternetUp ? _p.Success : _p.Danger;
        var style = _style.SelectedIndex switch { 1 => ChartStyle.Area, 2 => ChartStyle.Bars, _ => ChartStyle.Lines };
        var series = Defs.Where(x => _flags.TryGetValue(x.Key, out var cb) && cb.IsChecked == true)
            .Select(x => new ChartSeries(x.Name, x.Color, Values(pts, x.Key), x.Unit)).ToArray();
        _chart.Set(series, style, 60, null, true);
        _legend.Text = series.Length == 0 ? "Selecione ao menos uma série." : string.Join("   ·   ", series.Select(s =>
        {
            var peak = s.Values.Where(v => v is not null).Select(v => v!.Value).DefaultIfEmpty(0).Max();
            return $"{s.Name}: pico {peak:0.##} {s.Unit}";
        })) + "   ·   cada série na própria escala (0–100% do pico)";
    }

    private void SaveReport()
    {
        try
        {
            var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "TI-Digital");
            Directory.CreateDirectory(dir);
            var file = Path.Combine(dir, "Monitoramento-" + DateTime.Now.ToString("yyyyMMdd-HHmmss") + ".csv");
            var sb = new StringBuilder("hora;latencia_ms;perda_percent;download_mbps;upload_mbps;cpu_percent;memoria_percent\r\n");
            var inv = CultureInfo.InvariantCulture;
            foreach (var x in _hub.Snapshot())
                sb.Append(x.Time.ToString("yyyy-MM-dd HH:mm:ss", inv)).Append(';').Append(x.Latency?.ToString("0", inv) ?? "").Append(';').Append(x.Loss.ToString("0.#", inv)).Append(';')
                  .Append(x.Down.ToString("0.00", inv)).Append(';').Append(x.Up.ToString("0.00", inv)).Append(';').Append(x.Cpu?.ToString("0.0", inv) ?? "").Append(';').Append(x.Mem.ToString("0.0", inv)).Append("\r\n");
            File.WriteAllText(file, sb.ToString(), new UTF8Encoding(true));
            _message.Text = "Relatório salvo em " + file;
        }
        catch (Exception ex) { _message.Text = "Não foi possível salvar o relatório: " + ex.Message; }
    }
}
