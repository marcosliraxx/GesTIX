// Caminho: agentes/windows/Arcoll.WinUI3/MainWindow.xaml.cs
using System.Diagnostics;
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Models;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using Microsoft.UI.Windowing;
using Windows.ApplicationModel.DataTransfer;
using Windows.Storage.Streams;
namespace Arcoll.WinUI3;

public sealed partial class MainWindow : Window
{
    private readonly AgentClient _agent = new();
    private readonly ThemeService _themes = new();
    private readonly PresenceService _presence = new();
    private readonly AgentRuntime _runtime = new();
    private readonly TelemetryHub _hub = new();
    private readonly TrayService _tray = new();
    private AuthState _trayLast = AuthState.Checking;
    private readonly ServerPage _serverPage;
    private readonly MonitorPage _monitorPage;
    private readonly SpeedPage _speedPage;
    private readonly DevicesPage _devicesPage;
    private readonly MapPage _mapPage;
    private readonly ProgramsPage _programsPage;
    private readonly CleanPage _cleanPage;
    private readonly FilesPage _filesPage;
    private readonly SupportPage _supportPage;
    private readonly TicketsPage _ticketsPage;
    private readonly DispatcherTimer _refreshTimer = new() { Interval = TimeSpan.FromSeconds(45) };
    private readonly DispatcherTimer _themeTimer = new() { Interval = TimeSpan.FromSeconds(30) };
    private DateTime _lastBrand = DateTime.MinValue;
    private CommandProgressWindow? _commandWindow;
    private bool _branding, _remoteDialog, _refreshing, _exiting;
    private string _section = "panel", _brandSite = "";
    private CancellationTokenSource? _pending;

    public MainWindow()
    {
        InitializeComponent();
        _serverPage = new(_hub, () => FooterText.Text); _monitorPage = new(_hub, () => _runtime.MonitorEnabled);
        _speedPage = new(_runtime); _devicesPage = new(_runtime, m => FooterText.Text = m); _mapPage = new(_runtime, m => FooterText.Text = m);
        _programsPage = new(_runtime, ConfirmAsync); _cleanPage = new(_runtime, ConfirmAsync); _filesPage = new(_runtime);
        _supportPage = new(_runtime, ShowRemoteCodeAsync, () => SelectSection("tickets")); _ticketsPage = new(_runtime);
        var icon = Path.Combine(AppContext.BaseDirectory, "Assets", "system.ico");
        if (File.Exists(icon)) try { AppWindow.SetIcon(icon); } catch (Exception ex) { RuntimeLog.Write("Ícone da janela: " + ex.Message); }
        var work = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        AppWindow.Resize(new Windows.Graphics.SizeInt32(Math.Min(940, work.Width - 24), Math.Min(700, work.Height - 40)));
        _hub.Start(); SelectSection("panel"); ApplyTheme(_themes.Current);
        _refreshTimer.Tick += async (_, _) => await RefreshAsync();
        _themeTimer.Tick += async (_, _) => { await RefreshThemeAsync(); if (DateTime.UtcNow - _lastBrand > TimeSpan.FromMinutes(2)) await RefreshBrandAsync(); };
        _refreshTimer.Start(); _themeTimer.Start();
        _runtime.ProgramActivity += message => DispatcherQueue.TryEnqueue(() => { _programsPage.ShowActivity(message); });
        _runtime.Changed += () => DispatcherQueue.TryEnqueue(UpdateRuntimeUi);
        OperationProgress.Changed += OnOperation;
        _runtime.Consent = message =>
        {
            var result = new TaskCompletionSource<bool>();
            if (!DispatcherQueue.TryEnqueue(async () => { try { ShowFromBackground(); result.TrySetResult(await ConfirmAsync("Solicitação do gestor", message, "Autorizar")); } catch { result.TrySetResult(false); } })) result.TrySetResult(false);
            return result.Task;
        };
        _runtime.RequestExit = () => DispatcherQueue.TryEnqueue(ExitAgent);
        AppWindow.Closing += (_, e) => { if (!_exiting) { e.Cancel = true; AppWindow.Hide(); } };
        _tray.OnOpen = () => DispatcherQueue.TryEnqueue(ShowFromBackground);
        _tray.OnRestart = () => DispatcherQueue.TryEnqueue(RestartAgent);
        _tray.OnExit = () => DispatcherQueue.TryEnqueue(async () => { ShowFromBackground(); if (await ConfirmAsync("Encerrar agente", "Encerrar interrompe o monitoramento até a próxima inicialização. Continuar?", "Encerrar")) ExitAgent(); });
        _tray.Start(); _runtime.Start(); _presence.Start();
        Closed += (_, _) =>
        {
            _exiting = true; OperationProgress.Changed -= OnOperation; _commandWindow?.Shutdown();
            _refreshTimer.Stop(); _themeTimer.Stop(); _pending?.Cancel(); _hub.Dispose(); _agent.Dispose(); _themes.Dispose(); _presence.Dispose(); _cleanPage.CancelOperations(); _programsPage.CancelOperations(); _runtime.Dispose(); _tray.Dispose();
        };
        _ = RefreshAsync(); _ = RefreshThemeAsync();
    }
    private void OnOperation(OperationNotice notice)
    {
        if (_exiting || !Environment.UserInteractive || Process.GetCurrentProcess().SessionId == 0) return;
        DispatcherQueue.TryEnqueue(() => { if (_exiting) return; _commandWindow ??= new(); _commandWindow.ApplyPalette(Palette.From(_themes.Current)); _commandWindow.ShowNotice(notice); });
    }
    public void ShowFromBackground() { AppWindow.Show(); Activate(); }
    private void ExitAgent() { _exiting = true; Close(); Application.Current.Exit(); }
    private void RestartAgent()
    {
        var exe = Environment.ProcessPath; if (string.IsNullOrEmpty(exe)) return;
        try { Process.Start(new ProcessStartInfo("cmd.exe", $"/c ping -n 4 127.0.0.1 >nul & start \"\" \"{exe}\" --background") { UseShellExecute = false, CreateNoWindow = true }); ExitAgent(); }
        catch (Exception ex) { RuntimeLog.Write("Falha ao reiniciar: " + ex.Message); }
    }
    private async Task RefreshThemeAsync()
    {
        try { using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(12)); await _themes.RefreshAsync(timeout.Token); } catch { }
        ApplyTheme(_themes.Current);
    }
    private Button[] Menus => new[] { CleanMenu, ProgramsMenu, DevicesMenu, MapMenu, FilesMenu, ServerMenu, InternetMenu, PanelMenu, SupportMenu, TicketsMenu };
    private void ApplyTheme(AgentTheme t)
    {
        var p = Palette.From(t); RootGrid.Background = p.Alt; RootGrid.RequestedTheme = p.Light ? ElementTheme.Light : ElementTheme.Dark;
        TopBar.Background = BottomBar.Background = p.Surface; TopBar.BorderBrush = BottomBar.BorderBrush = p.Line;
        foreach (var separator in new[] { TopSeparator1, TopSeparator2, TopSeparator3, TopSeparator4, TopSeparator5, BottomSeparator1, BottomSeparator2, BottomSeparator3, BottomSeparator4 }) separator.BorderBrush = p.Line;
        foreach (var menu in Menus) { var selected = menu.Tag?.ToString() == _section; menu.Background = selected ? p.Alt : p.Surface; menu.Foreground = selected ? p.Accent : p.Text; }
        RemoteButton.Foreground = p.Accent; NetworkBadge.Background = p.Surface; NetworkBadge.BorderBrush = p.Accent;
        ConnectionLabel.Foreground = FooterText.Foreground = p.Muted;
        _serverPage.ApplyPalette(p); _monitorPage.ApplyPalette(p); _speedPage.ApplyPalette(p); _devicesPage.ApplyPalette(p); _mapPage.ApplyPalette(p);
        _programsPage.ApplyPalette(p); _cleanPage.ApplyPalette(p); _filesPage.ApplyPalette(p); _supportPage.ApplyPalette(p); _ticketsPage.ApplyPalette(p); _commandWindow?.ApplyPalette(p);
    }
    private void Menu_Click(object sender, RoutedEventArgs e) { if (sender is Button menu) SelectSection(menu.Tag?.ToString() ?? "panel"); }
    private void SelectSection(string section)
    {
        _section = section;
        PageHost.Content = section switch { "clean" => _cleanPage, "programs" => _programsPage, "devices" => _devicesPage, "map" => _mapPage, "files" => _filesPage, "server" => _serverPage, "speed" => _speedPage, "support" => _supportPage, "tickets" => _ticketsPage, _ => _monitorPage };
        if (_serverPage is not null) ApplyTheme(_themes.Current);
    }
    private async Task RefreshAsync()
    {
        if (_refreshing) return; _refreshing = true;
        _pending?.Cancel(); _pending?.Dispose(); _pending = new(TimeSpan.FromSeconds(12));
        try { var info = await _agent.HealthAsync(_pending.Token); FooterText.Text = info.Host + " · " + info.Ip + " · v" + info.Version; }
        catch (Exception ex) { FooterText.Text = ex.Message; }
        finally { _refreshing = false; }
    }
    private async Task RefreshBrandAsync()
    {
        if (_branding || !_runtime.Allowed) return; _branding = true;
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            if (_brandSite != _runtime.Platform.Config.SiteCode) { _brandSite = _runtime.Platform.Config.SiteCode; NetworkBadge.Child = new TextBlock { Text = "REDE", FontSize = 10, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center }; }
            var info = await WorkspaceService.GetAsync(_runtime, "info", timeout.Token);
            var logo = info.GetProperty("logo_base64").GetString() ?? "";
            if (logo.Length > 0)
            {
                var bytes = Convert.FromBase64String(logo); using var stream = new InMemoryRandomAccessStream();
                using (var writer = new DataWriter(stream)) { writer.WriteBytes(bytes); await writer.StoreAsync(); writer.DetachStream(); }
                stream.Seek(0); var bitmap = new BitmapImage(); await bitmap.SetSourceAsync(stream);
                NetworkBadge.Child = new Image { Source = bitmap, Stretch = Stretch.Uniform };
            }
            else NetworkBadge.Child = new TextBlock { Text = info.GetProperty("network_name").ToString()[..Math.Min(2, info.GetProperty("network_name").ToString().Length)].ToUpperInvariant(), FontSize = 14, FontWeight = Microsoft.UI.Text.FontWeights.Bold, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
            ToolTipService.SetToolTip(PanelMenu, "Painel · " + info.GetProperty("network_name"));
            _lastBrand = DateTime.UtcNow;
        }
        catch (Exception ex) { RuntimeLog.Write("Logo da rede: " + ex.Message); _lastBrand = DateTime.UtcNow; }
        finally { _branding = false; }
    }
    private void UpdateRuntimeUi()
    {
        if (!_runtime.Allowed) { _cleanPage.CancelOperations(); _programsPage.CancelOperations(); }
        GateOverlay.Visibility = _runtime.Gated ? Visibility.Visible : Visibility.Collapsed; GateText.Text = _runtime.StateNotice;
        ConnectionLabel.Text = _runtime.State switch { AuthState.Approved or AuthState.UiLocked => "Conectado · " + _runtime.NetworkName, AuthState.Pending => "Aguardando liberação", AuthState.Checking => "Verificando…", _ => "Sem confirmação" };
        Title = _runtime.SystemName + " — Agente Windows";
        var state = _runtime.Allowed ? TrayState.Connected : _runtime.State is AuthState.Pending or AuthState.Checking ? TrayState.Pending : TrayState.Offline;
        _tray.SetState(state, ConnectionLabel.Text);
        if (_trayLast != _runtime.State) { _trayLast = _runtime.State; if (_runtime.Allowed) _ = RefreshBrandAsync(); }
        if (_runtime.LastActivity.Length > 0 && _runtime.Allowed) FooterText.Text = _runtime.LastActivity;
    }
    private async void GateRetry_Click(object sender, RoutedEventArgs e) { await _runtime.RecheckAsync(); GateText.Text = _runtime.StateNotice; }
    private async void RemoteAccess_Click(object sender, RoutedEventArgs e) => await ShowRemoteCodeAsync();
    private async Task ShowRemoteCodeAsync()
    {
        if (_remoteDialog || !_runtime.Allowed) return; _remoteDialog = true;
        try
        {
            ShowFromBackground(); string code; int seconds;
            using (var operation = OperationProgress.Begin("Gerar código de acesso remoto"))
            {
                try
                {
                    using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(15));
                    var result = await _runtime.Platform.PostAsync("agent-support-code", _runtime.Platform.WithCredentials(), timeout.Token, 12);
                    code = result.GetProperty("code").GetString() ?? ""; seconds = result.GetProperty("expires_in").GetInt32(); operation.Succeed("Código temporário gerado");
                }
                catch (Exception ex) { operation.Fail(ex.Message); throw; }
            }
            var content = new StackPanel { Spacing = 12 };
            content.Children.Add(new TextBlock { Text = code, FontSize = 34, FontWeight = Microsoft.UI.Text.FontWeights.Bold, IsTextSelectionEnabled = true, HorizontalAlignment = HorizontalAlignment.Center });
            var expiry = new TextBlock { TextWrapping = TextWrapping.Wrap }; content.Children.Add(expiry);
            var until = DateTime.UtcNow.AddSeconds(seconds); var timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
            void Tick() { var left = Math.Max(0, (int)Math.Ceiling((until - DateTime.UtcNow).TotalSeconds)); expiry.Text = left > 0 ? "Compartilhe este código. Expira em " + left + " segundos; uso único." : "Código expirado. Feche e gere um novo código."; }
            var dialog = new ContentDialog { Title = "Acesso remoto", XamlRoot = Content.XamlRoot, Content = content, PrimaryButtonText = "Copiar código", CloseButtonText = "Fechar" };
            dialog.PrimaryButtonClick += (_, e) => { e.Cancel = true; if (DateTime.UtcNow < until) { var package = new DataPackage(); package.SetText(code); try { Clipboard.SetContent(package); } catch { expiry.Text = "Não foi possível copiar. Selecione o código e copie manualmente."; } } };
            timer.Tick += (_, _) => { Tick(); dialog.IsPrimaryButtonEnabled = DateTime.UtcNow < until; }; Tick(); timer.Start();
            try { await dialog.ShowAsync(); } finally { timer.Stop(); }
        }
        catch (Exception ex) { StatusBar.Title = "Acesso remoto"; StatusBar.Message = ex.Message; StatusBar.Severity = InfoBarSeverity.Warning; StatusBar.IsOpen = true; }
        finally { _remoteDialog = false; }
    }
    private async Task<bool> ConfirmAsync(string title, string message, string primary)
    {
        var dialog = new ContentDialog { Title = title, Content = new ScrollViewer { MaxHeight = 360, Content = new TextBlock { Text = message, TextWrapping = TextWrapping.Wrap } }, PrimaryButtonText = primary, CloseButtonText = "Cancelar", DefaultButton = ContentDialogButton.Close, XamlRoot = Content.XamlRoot };
        return await dialog.ShowAsync() == ContentDialogResult.Primary;
    }
}
