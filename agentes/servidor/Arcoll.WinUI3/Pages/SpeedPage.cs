// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/SpeedPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Internet: mesmo fluxo de redes/velocidade.php — botão START circular, progresso, diagnóstico (download, upload, latência, perdas) e nota.</summary>
public sealed class SpeedPage : UserControl
{
    private sealed record Last(SpeedResult Result, double? Loss, int Sent, DateTime At, System.Text.Json.JsonElement Connection);
    private static Last? _last; // mantém o último resultado ao trocar de aba

    private readonly AgentRuntime _runtime;
    private Palette _p = new();
    private bool _running;
    private double _progress; private string _caption = "", _live = "";
    private readonly StackPanel _root = new() { Spacing = 16, HorizontalAlignment = HorizontalAlignment.Stretch };

    public SpeedPage(AgentRuntime runtime) { _runtime = runtime; Content = _root; SizeChanged += (_, e) => { if (Math.Abs(e.NewSize.Width - e.PreviousSize.Width) > 1) Render(); }; Render(); }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private async Task StartAsync()
    {
        if (_running || !_runtime.Allowed) return;
        _running = true; _progress = 0; _caption = "Preparando teste…"; _live = ""; Render();
        try
        {
            var lastDraw = DateTime.MinValue;
            var progress = new Progress<(double Percent, string Message, double? Download)>(v => { if (v.Percent < 100 && DateTime.UtcNow - lastDraw < TimeSpan.FromMilliseconds(200)) return; lastDraw = DateTime.UtcNow; _progress = v.Percent; _caption = v.Message; _live = v.Download is double d ? d.ToString("0.0") : _live; Render(); });
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(180));
            var result = await _runtime.InternetNowAsync(progress, cancel.Token);
            _last = new Last(result.Speed, result.Loss, result.Sent, DateTime.Now, result.Connection);
            _caption = result.SyncWarning.Length > 0 ? result.SyncWarning : "Velocidade e conexão atualizadas na plataforma.";
        }
        catch (Exception ex) { _caption = "Falha no teste: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); }
        finally { _running = false; Render(); }
    }

    private void Render()
    {
        _root.Children.Clear();
        _root.Children.Add(Ui.Label("Internet", _p.Text, 24, true));
        _root.Children.Add(Ui.Label("Velocidade e conexão em uma única medição", _p.Muted, 14, wrap: true));
        var inner = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
        inner.Children.Add(new TextBlock { Text = _running ? (_live.Length > 0 ? _live : "…") : "START", FontSize = 34, FontWeight = Microsoft.UI.Text.FontWeights.Bold, Foreground = _p.Text, HorizontalAlignment = HorizontalAlignment.Center });
        inner.Children.Add(new TextBlock { Text = _running ? (_live.Length > 0 ? "Mbps · download" : "MEDINDO") : "VELOCIDADE + CONEXÃO", FontSize = 10, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, Foreground = _p.Muted, HorizontalAlignment = HorizontalAlignment.Center });
        var start = new Button
        {
            Content = inner, Width = 190, Height = 190, CornerRadius = new CornerRadius(95), HorizontalAlignment = HorizontalAlignment.Center, IsEnabled = !_running && _runtime.Allowed,
            Background = _p.Surface, BorderBrush = _p.Accent, BorderThickness = new Thickness(4),
        };
        start.Click += async (_, _) => await StartAsync();
        AutomationProperties_SetName(start, "Iniciar medição real no agente");
        var launch = new StackPanel { Spacing = 10, HorizontalAlignment = HorizontalAlignment.Center };
        launch.Children.Add(start);
        launch.Children.Add(new TextBlock { Text = _caption, Foreground = _p.Muted, FontSize = 12, HorizontalAlignment = HorizontalAlignment.Center, TextWrapping = TextWrapping.Wrap, TextAlignment = TextAlignment.Center });
        if (_running) launch.Children.Add(new ProgressBar { Minimum = 0, Maximum = 100, Value = _progress, Width = Math.Max(180, Math.Min(300, ActualWidth - 40)), Foreground = _p.Accent });
        _root.Children.Add(Ui.Card(launch, _p, 22));

        var head = new Grid(); head.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) }); head.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        head.Children.Add(Ui.Label("DIAGNÓSTICO DA REDE", _p.Muted, 11, true));
        var repeat = new Button { Content = "Executar novamente", IsEnabled = !_running && _runtime.Allowed, Visibility = _last is null ? Visibility.Collapsed : Visibility.Visible };
        repeat.Click += async (_, _) => await StartAsync(); Grid.SetColumn(repeat, 1); head.Children.Add(repeat);

        var grid = new Grid { ColumnSpacing = 10 };
        var columns = ActualWidth < 680 ? 2 : 4;
        for (var i = 0; i < columns; i++) grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        if (columns == 2) grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        grid.RowSpacing = 10;
        var r = _last?.Result;
        var cards = new[]
        {
            ("↓ DOWNLOAD", r?.Download is double d ? d.ToString("0.0") : "—", "Mbps"), ("↑ UPLOAD", r?.Upload is double u ? u.ToString("0.0") : "—", "Mbps"),
            ("LATÊNCIA", r?.LatencyMs is double l ? l.ToString("0") : "—", "ms"),
            ("PERDAS", _last?.Loss is double p ? p.ToString("0.#") + "%" : "Não aferido", _last is null ? "Execute o teste" : $"{_last.Sent} pacotes enviados"),
        };
        for (var i = 0; i < cards.Length; i++)
        {
            var s = new StackPanel { Spacing = 4 };
            s.Children.Add(Ui.Label(cards[i].Item1, _p.Muted, 10, true)); s.Children.Add(Ui.Label(cards[i].Item2, _p.Text, 24, true)); s.Children.Add(Ui.Label(cards[i].Item3, _p.Muted, 11));
            var card = Ui.Card(s, _p, 14); Grid.SetColumn(card, i % columns); Grid.SetRow(card, i / columns); grid.Children.Add(card);
        }
        var note = Ui.Label(_last is null ? "Aguardando dados reais do agente." : $"Medido em {_last.At:dd/MM/yyyy HH:mm} · {_last.Result.Server}. A velocidade de enlace dos adaptadores não equivale à velocidade contratada.", _p.Muted, 12, false, true);
        var box = new StackPanel { Spacing = 12 }; box.Children.Add(head); box.Children.Add(grid); box.Children.Add(note);
        _root.Children.Add(Ui.Card(box, _p, 16));
        if (_last is not null) RenderConnection(_last.Connection);

    }

    private void RenderConnection(System.Text.Json.JsonElement connection)
    {
        var content = new StackPanel { Spacing = 10 };
        content.Children.Add(Ui.Label("CONEXÃO", _p.Text, 17, true));
        string Value(System.Text.Json.JsonElement parent, string key) => parent.ValueKind == System.Text.Json.JsonValueKind.Object && parent.TryGetProperty(key, out var v)
            ? v.ValueKind == System.Text.Json.JsonValueKind.Array ? string.Join(", ", v.EnumerateArray().Select(x => x.ToString())) : v.ToString() : "—";
        var network = connection.TryGetProperty("interface", out var n) ? n : default;
        var provider = connection.TryGetProperty("provider", out var p) ? p : default;
        foreach (var row in new[] {
            ("IP público", Value(connection, "public_ip")), ("Provedora", Value(provider, "isp")),
            ("Interface", Value(network, "name")), ("IP local", Value(network, "ip")),
            ("Gateway", Value(network, "gateway")), ("DNS", Value(network, "dns")),
            ("MAC", Value(network, "mac")), ("Enlace do adaptador", Value(network, "speed_bps")) })
            content.Children.Add(Ui.Label(row.Item1 + ": " + (string.IsNullOrWhiteSpace(row.Item2) ? "Não confirmado" : row.Item2), _p.Text, 14, wrap: true));
        if (connection.TryGetProperty("route", out var route) && route.ValueKind == System.Text.Json.JsonValueKind.Array)
        {
            content.Children.Add(Ui.Label("ROTA ATÉ A INTERNET", _p.Muted, 12, true));
            foreach (var hop in route.EnumerateArray()) content.Children.Add(Ui.Label(Value(hop, "hop") + " · " + Value(hop, "ip"), _p.Text, 13, wrap: true));
        }
        if (connection.TryGetProperty("errors", out var errors) && errors.ValueKind == System.Text.Json.JsonValueKind.Array)
            foreach (var error in errors.EnumerateArray()) content.Children.Add(Ui.Label(error.ToString(), _p.Warning, 13, wrap: true));
        content.Children.Add(Ui.Label("Perdas medidas com 12 pings para 1.1.1.1. Bloqueio de ICMP pode impedir respostas. Enlace do adaptador não equivale à banda de Internet.", _p.Muted, 12, wrap: true));
        _root.Children.Add(Ui.Card(content, _p));
    }

    private static void AutomationProperties_SetName(DependencyObject o, string name) => Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(o, name);
}
