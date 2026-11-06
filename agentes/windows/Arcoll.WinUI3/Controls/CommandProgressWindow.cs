// Caminho: agentes/windows/Arcoll.WinUI3/Controls/CommandProgressWindow.cs
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Windowing;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Controls;

/// <summary>Aviso pequeno independente da janela principal, inclusive para comandos remotos com agente oculto.</summary>
public sealed class CommandProgressWindow : Window
{
    private readonly StackPanel _root = new() { Spacing = 8, Padding = new Thickness(12) };
    private readonly Dictionary<Guid, (OperationNotice Notice, DateTime At)> _notices = new();
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromMilliseconds(500) };
    private Palette _palette = new();
    private bool _shutdown;
    public CommandProgressWindow()
    {
        Title = "Comandos do agente"; Content = _root;
        if (AppWindow.Presenter is OverlappedPresenter presenter) { presenter.IsResizable = false; presenter.IsMaximizable = false; presenter.IsMinimizable = false; presenter.IsAlwaysOnTop = true; }
        AppWindow.Closing += (_, e) => { if (!_shutdown) { e.Cancel = true; AppWindow.Hide(); } };
        _root.Loaded += (_, _) => Place();
        _timer.Tick += (_, _) =>
        {
            var stale = _notices.Where(x => x.Value.Notice.Finished && DateTime.UtcNow - x.Value.At > TimeSpan.FromSeconds(x.Value.Notice.Failed ? 8 : 3)).Select(x => x.Key).ToArray();
            if (stale.Length == 0) return;
            foreach (var id in stale) _notices.Remove(id);
            Render();
        };
        _timer.Start();
    }
    public void ApplyPalette(Palette palette) { _palette = palette; Render(); }
    public void ShowNotice(OperationNotice notice) { _notices[notice.Id] = (notice, DateTime.UtcNow); Render(); AppWindow.Show(false); }
    public void Shutdown() { _shutdown = true; _timer.Stop(); Close(); }
    private void Render()
    {
        _root.Children.Clear(); _root.Background = _palette.Surface;
        _root.RequestedTheme = _palette.Light ? ElementTheme.Light : ElementTheme.Dark;
        if (_notices.Count == 0) { AppWindow.Hide(); return; }
        foreach (var item in _notices.Values.OrderByDescending(x => x.At).Take(3))
        {
            var n = item.Notice; var color = n.Failed ? _palette.Danger : _palette.Text;
            var title = Ui.Label(n.Origin + " · " + n.Command, color, 13, true, true); title.MaxLines = 1;
            var message = Ui.Label(n.Message + " · " + n.Percent + "%", color, 12, wrap: true); message.MaxLines = 2;
            ToolTipService.SetToolTip(message, n.Message); _root.Children.Add(title); _root.Children.Add(message);
            _root.Children.Add(new ProgressBar { Minimum = 0, Maximum = 100, Value = n.Percent, Foreground = n.Failed ? _palette.Danger : _palette.Accent });
        }
        Place();
    }
    private void Place()
    {
        var work = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        var scale = _root.XamlRoot?.RasterizationScale ?? 1;
        var width = Math.Min((int)(370 * scale), work.Width - 24); var height = Math.Min((int)((65 + Math.Min(3, _notices.Count) * 76) * scale), work.Height - 32);
        AppWindow.MoveAndResize(new Windows.Graphics.RectInt32(work.X + work.Width - width - 12, work.Y + work.Height - height - 12, width, height));
    }
}
