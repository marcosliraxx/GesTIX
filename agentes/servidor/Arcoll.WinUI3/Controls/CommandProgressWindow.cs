// Caminho: agentes/servidor/Arcoll.WinUI3/Controls/CommandProgressWindow.cs
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI;
using Microsoft.UI.Windowing;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Controls;

/// <summary>Fila visual compacta; mostra somente percentuais confirmados pelo processamento local.</summary>
public sealed class CommandProgressWindow : Window
{
    private readonly StackPanel _stack = new() { Spacing = 7, HorizontalAlignment = HorizontalAlignment.Right };
    private readonly ScrollViewer _scroll;
    private readonly Dictionary<Guid, (OperationNotice Notice, DateTime At)> _notices = new();
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(2) };
    private Palette _palette = new();
    private Guid? _selected;
    private bool _shutdown;

    public CommandProgressWindow()
    {
        Title = "Fila de operações do agente";
        _scroll = new ScrollViewer
        {
            Content = _stack, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
            VerticalScrollBarVisibility = ScrollBarVisibility.Auto, MaxHeight = 142,
            HorizontalAlignment = HorizontalAlignment.Right
        };
        Content = _scroll;
        if (AppWindow.Presenter is OverlappedPresenter presenter)
        {
            presenter.IsResizable = false; presenter.IsMaximizable = false;
            presenter.IsMinimizable = false; presenter.IsAlwaysOnTop = true;
        }
        AppWindow.Closing += (_, e) => { if (!_shutdown) { e.Cancel = true; AppWindow.Hide(); } };
        _stack.Loaded += (_, _) => Place();
        _timer.Tick += (_, _) =>
        {
            // Histórico local limitado em memória; comandos finalizados permanecem consultáveis.
            var stale = _notices.Where(x => x.Value.Notice.Finished && DateTime.UtcNow - x.Value.At > TimeSpan.FromDays(1))
                .Select(x => x.Key).ToArray();
            foreach (var id in stale) _notices.Remove(id);
            if (stale.Length > 0) Render();
        };
        _timer.Start();
    }

    public void ApplyPalette(Palette palette) { _palette = palette; Render(); }
    public void ShowNotice(OperationNotice notice)
    {
        _notices[notice.Id] = (notice, DateTime.UtcNow);
        Render(); AppWindow.Show(false);
    }
    public void Shutdown() { _shutdown = true; _timer.Stop(); Close(); }

    private void Render()
    {
        _stack.Children.Clear();
        _stack.Background = _palette.Surface;
        _stack.RequestedTheme = _palette.Light ? ElementTheme.Light : ElementTheme.Dark;
        if (_notices.Count == 0) { AppWindow.Hide(); return; }
        foreach (var pair in _notices.OrderBy(x => x.Value.Notice.Finished).ThenBy(x => x.Value.At).Take(80))
        {
            var id = pair.Key;
            var notice = pair.Value.Notice;
            var row = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 7,
                HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Center };
            if (_selected == id)
            {
                var info = new StackPanel { Width = 205, Spacing = 4 };
                var heading = Ui.Label(OperationProgress.Title(notice.Command), _palette.Text, 12, true, true);
                heading.MaxLines = 2;
                var detail = Ui.Label(notice.Origin + " · " + notice.Message, _palette.Text, 11, wrap: true);
                detail.MaxLines = 4;
                info.Children.Add(heading); info.Children.Add(detail);
                row.Children.Add(info);
            }
            var button = new Button
            {
                Content = IconFor(notice.Command),
                Width = 35, Height = 35, CornerRadius = new CornerRadius(18),
                FontSize = 16, FontWeight = Microsoft.UI.Text.FontWeights.Bold,
                Foreground = new SolidColorBrush(Colors.White),
                Background = notice.Finished ? new SolidColorBrush(Colors.Gray) : _palette.Accent,
                Opacity = notice.Finished ? .58 : 1,
                HorizontalAlignment = HorizontalAlignment.Right
            };
            ToolTipService.SetToolTip(button, OperationProgress.Title(notice.Command) + " · " + notice.Message);
            button.Click += (_, _) => { _selected = _selected == id ? null : id; Render(); };
            var bubble = new Grid { Width = 49, Height = 43 };
            bubble.Children.Add(button);
            var badge = new Border { HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Bottom,
                Background = _palette.Surface, CornerRadius = new CornerRadius(6), Padding = new Thickness(2, 0, 2, 0) };
            badge.Child = Ui.Label(notice.Finished && !notice.Failed ? "100%" : notice.Percent + "%", _palette.Text, 9, true);
            bubble.Children.Add(badge);
            row.Children.Add(bubble);
            _stack.Children.Add(row);
        }
        Place();
    }

    private static string IconFor(string command) => command switch
    {
        "agent_update" or "windows_update" or "power_restart" => "↻",
        "program_install" or "program_inventory" or "program_uninstall" => "▦",
        "power_shutdown" => "⏻", "remote_assistance" or "screen_lock" => "▣",
        "clean_scan" or "clean_execute" or "clean_tool" => "✧",
        "file_browser" or "file_operation" => "▤", _ => "⚙"
    };

    private void Place()
    {
        var work = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        var scale = _stack.XamlRoot?.RasterizationScale ?? 1;
        var width = Math.Min((int)((_selected is null ? 64 : 295) * scale), work.Width - 24);
        var height = Math.Min((int)((22 + Math.Min(3, _notices.Count) * 47) * scale), work.Height - 32);
        AppWindow.MoveAndResize(new Windows.Graphics.RectInt32(
            work.X + work.Width - width - 10,
            work.Y + Math.Max(0, (work.Height - height) / 2), width, height));
    }
}
