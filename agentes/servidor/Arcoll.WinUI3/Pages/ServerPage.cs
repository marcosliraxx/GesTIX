// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/ServerPage.cs
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Servidor: mesmos componentes e estrutura de redes/servidor.php (lista lateral, indicadores, gráfico de 60 amostras e nota).</summary>
public sealed class ServerPage : UserControl
{
    private sealed record Comp(string Key, string Title, string Subtitle, string ValueText, double? Percent, string Accent,
        (string Label, string Value)[] Stats, ChartSeries[] Series, string Detail, double? FixedMax);

    private sealed class Row { public Border Box = null!; public TextBlock Name = null!, Value = null!; public ProgressBar Bar = null!; }

    private readonly TelemetryHub _hub;
    private readonly Func<string> _status;
    private Palette _p = new();
    private readonly Dictionary<string, Row> _rows = new();
    private readonly StackPanel _list = new() { Spacing = 6 };
    private readonly TextBlock _title, _subtitle, _value, _note, _foot;
    private readonly TextBlock[] _statLabel = new TextBlock[4], _statValue = new TextBlock[4];
    private readonly ChartCanvas _chart = new() { MinHeight = 230, Height = 260 };
    private readonly List<Border> _cards = new();
    private string? _selected;
    private string _keys = "";
    private Comp[] _comps = Array.Empty<Comp>();
    private Border _side = null!;

    public ServerPage(TelemetryHub hub, Func<string> status)
    {
        _hub = hub; _status = status;
        _title = Ui.Label("Servidor", _p.Text, 20, true); _subtitle = Ui.Label("Monitoramento passivo dos componentes detectados automaticamente.", _p.Muted, 12, false, true);
        _value = Ui.Label("Aguardando leitura", _p.Accent, 22, true); _note = Ui.Label("Aguardando telemetria do computador.", _p.Muted, 12, false, true);
        _foot = Ui.Label("", _p.Muted, 11);
        Content = Build();
        _hub.Updated += OnUpdated;
        Unloaded += (_, _) => _hub.Updated -= OnUpdated;
        Render();
    }

    private UIElement Build()
    {
        var grid = new Grid { ColumnSpacing = 14 };
        grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(300) });
        grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        var sideStack = new StackPanel { Spacing = 10 };
        sideStack.Children.Add(Ui.Label("COMPONENTES", _p.Muted, 10, true)); sideStack.Children.Add(_list);
        _side = Ui.Card(new ScrollViewer { Content = sideStack, VerticalScrollBarVisibility = ScrollBarVisibility.Auto, MaxHeight = 560 }, _p);
        grid.Children.Add(_side);
        var main = new StackPanel { Spacing = 12 };
        var head = new Grid(); head.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) }); head.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        var titles = new StackPanel { Spacing = 3 }; titles.Children.Add(_title); titles.Children.Add(_subtitle);
        head.Children.Add(titles); Grid.SetColumn(_value, 1); _value.VerticalAlignment = VerticalAlignment.Center; head.Children.Add(_value);
        main.Children.Add(head);
        var stats = new Grid { ColumnSpacing = 10 };
        for (var i = 0; i < 4; i++)
        {
            stats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            var (card, value, caption) = Ui.Stat("—", _p, 16);
            _cards.Add(card); _statLabel[i] = caption; _statValue[i] = value; Grid.SetColumn(card, i); stats.Children.Add(card);
        }
        main.Children.Add(stats);
        main.Children.Add(Ui.Card(_chart, _p, 8));
        main.Children.Add(_note); main.Children.Add(_foot);
        Grid.SetColumn(main, 1); grid.Children.Add(main);
        return grid;
    }

    public void ApplyPalette(Palette p)
    {
        _p = p; _keys = ""; // recria os itens com as novas cores
        _side.Background = p.Surface; _side.BorderBrush = p.Line;
        foreach (var c in _cards) { c.Background = p.Surface; c.BorderBrush = p.Line; }
        _title.Foreground = p.Text; _subtitle.Foreground = p.Muted; _value.Foreground = p.Accent; _note.Foreground = p.Muted; _foot.Foreground = p.Muted;
        foreach (var t in _statLabel) t.Foreground = p.Muted; foreach (var t in _statValue) t.Foreground = p.Text;
        _chart.SetColors(p.Muted, p.Line);
        Render();
    }

    private void OnUpdated() => DispatcherQueue?.TryEnqueue(Render);

    private static string Pct(double? v) => v is double d ? $"{d:0.0}%" : "Sem dados";

    private Comp[] BuildComponents()
    {
        var pts = _hub.Snapshot(); var last = _hub.Last; var list = new List<Comp>();
        if (last is null || pts.Count == 0) return list.ToArray();
        var recent = pts.TakeLast(30).ToArray();
        IReadOnlyList<double?> Hist(Func<TelemetryPoint, double?> f) => pts.TakeLast(60).Select(f).ToArray();
        double Avg(IEnumerable<double?> v) { var a = v.Where(x => x is not null).Select(x => x!.Value).ToArray(); return a.Length == 0 ? 0 : a.Average(); }
        double Peak(IEnumerable<double?> v) { var a = v.Where(x => x is not null).Select(x => x!.Value).ToArray(); return a.Length == 0 ? 0 : a.Max(); }

        var cpu = Hist(x => x.Cpu);
        list.Add(new Comp("cpu", "CPU", last.CpuName.Length > 0 ? last.CpuName : "Processador", Pct(last.Cpu), last.Cpu, "#31beff",
            new[] { ("Utilização", Pct(last.Cpu)), ("Média (1 min)", $"{Avg(cpu):0.0}%"), ("Pico (1 min)", $"{Peak(cpu):0.0}%"), ("Processadores", $"{Environment.ProcessorCount} lógicos") },
            new[] { new ChartSeries("CPU", "#31beff", cpu, "%") }, "Uso total do processador medido pelo agente (GetSystemTimes).", 100));

        var mem = Hist(x => x.Mem); var gb = 1073741824d;
        var memPct = last.MemTotal > 0 ? 100.0 * last.MemUsed / last.MemTotal : 0;
        list.Add(new Comp("mem", "Memória", $"{last.MemTotal / gb:0.0} GB instalados", Pct(memPct), memPct, "#7352a6",
            new[] { ("Em uso", Ui.Size(last.MemUsed)), ("Disponível", Ui.Size(Math.Max(0, last.MemTotal - last.MemUsed))), ("Total", Ui.Size(last.MemTotal)), ("Média (1 min)", $"{Avg(mem):0.0}%") },
            new[] { new ChartSeries("Memória", "#7352a6", mem, "%") }, "Memória física do sistema em uso.", 100));

        foreach (var d in last.Drives)
        {
            var hist = Hist(x => x.Disks.TryGetValue(d.Name, out var v) ? v : null);
            list.Add(new Comp("disk:" + d.Name, "Disco " + d.Name, "Armazenamento local", Pct(d.Percent), d.Percent, "#11816f",
                new[] { ("Ocupação", Pct(d.Percent)), ("Usado", Ui.Size(d.Used)), ("Livre", Ui.Size(Math.Max(0, d.Total - d.Used))), ("Total", Ui.Size(d.Total)) },
                new[] { new ChartSeries("Disco " + d.Name, "#11816f", hist, "%") }, "Capacidade do volume. Mantenha ao menos 15% livre para desempenho e atualizações.", 100));
        }

        var down = Hist(x => x.Down); var up = Hist(x => x.Up);
        list.Add(new Comp("net", "Rede", "Tráfego dos adaptadores", $"↓ {last.DownloadMbps:0.00} · ↑ {last.UploadMbps:0.00} Mbps", null, "#087eac",
            new[] { ("Download", $"{last.DownloadMbps:0.00} Mbps"), ("Upload", $"{last.UploadMbps:0.00} Mbps"), ("Pico download", $"{Peak(down):0.00} Mbps"), ("Pico upload", $"{Peak(up):0.00} Mbps") },
            new[] { new ChartSeries("Download", "#31beff", down, "Mbps"), new ChartSeries("Upload", "#12856b", up, "Mbps") }, "Vazão atual somada de todos os adaptadores ativos.", null));

        var lat = Hist(x => x.Latency); var latest = pts[^1];
        list.Add(new Comp("internet", "Internet", "Latência para 1.1.1.1", latest.Latency is double l ? $"{l:0} ms" : "Sem resposta", null, "#a86b12",
            new[] { ("Latência", latest.Latency is double l2 ? $"{l2:0} ms" : "Sem resposta"), ("Perdas", $"{latest.Loss:0}%"), ("Média (1 min)", $"{Avg(lat):0} ms"), ("Pico (1 min)", $"{Peak(lat):0} ms") },
            new[] { new ChartSeries("Latência", "#a86b12", lat, "ms") }, "Medição ICMP a cada 3 segundos; perdas consideram as últimas 20 tentativas.", null));
        return list.ToArray();
    }

    private void Render()
    {
        _comps = BuildComponents();
        if (_comps.Length == 0) { _value.Text = "Aguardando leitura"; _foot.Text = _status(); return; }
        if (_selected is null || _comps.All(c => c.Key != _selected)) _selected = _comps[0].Key;
        var keys = string.Join("|", _comps.Select(c => c.Key));
        if (keys != _keys) RebuildList(keys);
        foreach (var c in _comps)
        {
            if (!_rows.TryGetValue(c.Key, out var r)) continue;
            r.Value.Text = c.ValueText; r.Bar.Visibility = c.Percent is null ? Visibility.Collapsed : Visibility.Visible; if (c.Percent is double v) r.Bar.Value = v;
            r.Box.Background = c.Key == _selected ? _p.Alt : _p.Surface;
            r.Box.BorderBrush = c.Key == _selected ? _p.Accent : _p.Line;
        }
        var sel = _comps.First(c => c.Key == _selected);
        _title.Text = sel.Title; _subtitle.Text = sel.Subtitle; _value.Text = sel.ValueText; _note.Text = sel.Detail;
        for (var i = 0; i < 4; i++)
        {
            var (label, value) = i < sel.Stats.Length ? sel.Stats[i] : ("—", "--");
            _statLabel[i].Text = label.ToUpperInvariant(); _statValue[i].Text = value;
        }
        _chart.Set(sel.Series.Select(s => s with { Color = s.Color }).ToArray(), ChartStyle.Area, 60, sel.FixedMax);
        _foot.Text = _status() + " · atualizado " + DateTime.Now.ToString("HH:mm:ss");
    }

    private void RebuildList(string keys)
    {
        _keys = keys; _list.Children.Clear(); _rows.Clear();
        foreach (var c in _comps)
        {
            var row = new Row { Name = Ui.Label(c.Title, _p.Text, 13, true), Value = Ui.Label("", _p.Muted, 11), Bar = new ProgressBar { Minimum = 0, Maximum = 100, Height = 4, Foreground = new SolidColorBrush(ChartCanvas.Parse(c.Accent)) } };
            var inner = new StackPanel { Spacing = 3 }; inner.Children.Add(row.Name); inner.Children.Add(row.Value); inner.Children.Add(row.Bar);
            var accent = new Border { Width = 4, CornerRadius = new CornerRadius(2), Background = new SolidColorBrush(ChartCanvas.Parse(c.Accent)) };
            var layout = new Grid { ColumnSpacing = 10 };
            layout.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto }); layout.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            layout.Children.Add(accent); Grid.SetColumn(inner, 1); layout.Children.Add(inner);
            row.Box = new Border { Child = layout, CornerRadius = new CornerRadius(10), BorderThickness = new Thickness(1), Padding = new Thickness(10, 8, 10, 8), Background = _p.Surface, BorderBrush = _p.Line, Tag = c.Key };
            row.Box.Tapped += (s, _) => { if (s is Border b && b.Tag is string k) { _selected = k; Render(); } };
            _rows[c.Key] = row; _list.Children.Add(row.Box);
        }
    }
}
