// Caminho: agentes/windows/Arcoll.WinUI3/Pages/SpeedPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Velocidade: mesmo fluxo de redes/velocidade.php — botão START circular, progresso, diagnóstico (download, upload, latência, perdas) e nota.</summary>
public sealed class SpeedPage : UserControl
{
    private sealed record Last(SpeedResult Result, double? Loss, int Sent, DateTime At);
    private static Last? _last; // mantém o último resultado ao trocar de aba

    private readonly AgentRuntime _runtime;
    private Palette _p = new();
    private bool _running;
    private double _progress; private string _caption = "", _live = "";
    private readonly StackPanel _root = new() { Spacing = 16, HorizontalAlignment = HorizontalAlignment.Stretch };

    public SpeedPage(AgentRuntime runtime) { _runtime = runtime; Content = _root; Render(); }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private async Task StartAsync()
    {
        if (_running || !_runtime.Allowed) return;
        _running = true; _progress = 0; _caption = "Preparando teste…"; _live = ""; Render();
        try
        {
            var lastDraw = DateTime.MinValue;
            var progress = new Progress<(double Percent, string Message, double? Download)>(v => { if (v.Percent < 100 && DateTime.UtcNow - lastDraw < TimeSpan.FromMilliseconds(200)) return; lastDraw = DateTime.UtcNow; _progress = v.Percent; _caption = v.Message; _live = v.Download is double d ? d.ToString("0.0") : _live; Render(); });
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(120));
            var result = await _runtime.SpeedNowAsync(progress, cancel.Token);
            _caption = "Medindo perdas de pacotes…"; Render();
            var (loss, sent) = await LossAsync(cancel.Token);
            _last = new Last(result, loss, sent, DateTime.Now); _caption = "Teste concluído e enviado à plataforma.";
        }
        catch (Exception ex) { _caption = "Falha no teste: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); }
        finally { _running = false; Render(); }
    }

    /// <summary>12 pings ao gateway (ou 1.1.1.1 sem gateway): perda real medida pelo agente.</summary>
    private static async Task<(double? Loss, int Sent)> LossAsync(CancellationToken ct)
    {
        var gateway = NetworkEngine.Local().Gateway; var host = string.IsNullOrWhiteSpace(gateway) ? "1.1.1.1" : gateway; var lost = 0; const int sent = 12;
        for (var i = 0; i < sent; i++) { ct.ThrowIfCancellationRequested(); if (await NetworkEngine.PingAsync(host, 700) is null) lost++; await Task.Delay(120, ct); }
        return (Math.Round(lost * 100.0 / sent, 1), sent);
    }

    private void Render()
    {
        _root.Children.Clear();
        var inner = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
        inner.Children.Add(new TextBlock { Text = _running ? (_live.Length > 0 ? _live : "…") : "START", FontSize = 34, FontWeight = Microsoft.UI.Text.FontWeights.Bold, Foreground = _p.Text, HorizontalAlignment = HorizontalAlignment.Center });
        inner.Children.Add(new TextBlock { Text = _running ? (_live.Length > 0 ? "Mbps · download" : "MEDINDO") : "INICIAR MEDIÇÃO", FontSize = 10, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, Foreground = _p.Muted, HorizontalAlignment = HorizontalAlignment.Center });
        var start = new Button
        {
            Content = inner, Width = 190, Height = 190, CornerRadius = new CornerRadius(95), HorizontalAlignment = HorizontalAlignment.Center, IsEnabled = !_running,
            Background = _p.Surface, BorderBrush = _p.Accent, BorderThickness = new Thickness(4),
        };
        start.Click += async (_, _) => await StartAsync();
        AutomationProperties_SetName(start, "Iniciar medição real no agente");
        var launch = new StackPanel { Spacing = 10, HorizontalAlignment = HorizontalAlignment.Center };
        launch.Children.Add(start);
        launch.Children.Add(new TextBlock { Text = _caption, Foreground = _p.Muted, FontSize = 12, HorizontalAlignment = HorizontalAlignment.Center, TextWrapping = TextWrapping.Wrap, TextAlignment = TextAlignment.Center });
        if (_running) launch.Children.Add(new ProgressBar { Minimum = 0, Maximum = 100, Value = _progress, Width = 300, Foreground = _p.Accent });
        _root.Children.Add(Ui.Card(launch, _p, 22));

        var head = new Grid(); head.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) }); head.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        head.Children.Add(Ui.Label("DIAGNÓSTICO DA REDE", _p.Muted, 11, true));
        var repeat = new Button { Content = "Executar novamente", IsEnabled = !_running, Visibility = _last is null ? Visibility.Collapsed : Visibility.Visible };
        repeat.Click += async (_, _) => await StartAsync(); Grid.SetColumn(repeat, 1); head.Children.Add(repeat);

        var grid = new Grid { ColumnSpacing = 10 };
        for (var i = 0; i < 4; i++) grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
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
            var card = Ui.Card(s, _p, 14); Grid.SetColumn(card, i); grid.Children.Add(card);
        }
        var note = Ui.Label(_last is null ? "Aguardando dados reais do agente." : $"Medido em {_last.At:dd/MM/yyyy HH:mm} · {_last.Result.Server}. A velocidade de enlace dos adaptadores não equivale à velocidade contratada.", _p.Muted, 12, false, true);
        var box = new StackPanel { Spacing = 12 }; box.Children.Add(head); box.Children.Add(grid); box.Children.Add(note);
        _root.Children.Add(Ui.Card(box, _p, 16));
    }

    private static void AutomationProperties_SetName(DependencyObject o, string name) => Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(o, name);
}
