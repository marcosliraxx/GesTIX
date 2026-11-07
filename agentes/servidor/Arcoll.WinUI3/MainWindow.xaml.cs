// Caminho: agentes/servidor/Arcoll.WinUI3/MainWindow.xaml.cs
using System.Diagnostics;
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
    private readonly DashboardPage _dashboardPage;
    private readonly SpeedPage _speedPage;
    private readonly DevicesPage _devicesPage;
    private readonly MapPage _mapPage;
    private readonly ProgramsPage _programsPage;
    private readonly Dictionary<string, MaintenancePage> _tools = new();
    private readonly WindowsUpdatePage _windowsUpdatePage;
    private readonly FilesPage _filesPage;
    private readonly SupportPage _supportPage;
    private readonly TicketsPage _ticketsPage;
    private readonly DispatcherTimer _refreshTimer = new() { Interval = TimeSpan.FromSeconds(45) };
    private readonly DispatcherTimer _themeTimer = new() { Interval = TimeSpan.FromSeconds(30) };
    private DateTime _lastBrand = DateTime.MinValue;
    private bool _branding, _remoteDialog, _refreshing, _exiting;
    private string _section = "panel", _brandSite = "";
    // Catálogo único: categoria, navegação, seleção visual e acessibilidade usam as mesmas definições.
    private sealed record MenuItem(string Key, string Label, Symbol Icon);
    private sealed record MenuGroup(string Key, string Label, Symbol Icon, MenuItem[] Items);
    private static readonly MenuGroup[] Groups = {
        new("home", "Início", Symbol.Home, new[] { new MenuItem("panel", "Painel", Symbol.Home), new MenuItem("monitor", "Monitoramento", Symbol.View), new MenuItem("server", "Servidor", Symbol.Setting), new MenuItem("speed", "Internet", Symbol.World), new MenuItem("health", "Saúde", Symbol.Important) }),
        new("maintenance", "Manutenção", Symbol.Repair, new[] { new MenuItem("clean", "Limpeza", Symbol.Delete), new MenuItem("registry", "Registro", Symbol.Repair), new MenuItem("startup", "Inicialização", Symbol.Play), new MenuItem("performance", "Desempenho", Symbol.View), new MenuItem("restore", "Restauração", Symbol.Undo), new MenuItem("options", "Configurações", Symbol.Setting) }),
        new("software", "Aplicativos", Symbol.Library, new[] { new MenuItem("programs", "Programas", Symbol.Library), new MenuItem("uninstall", "Desinstalar", Symbol.Remove), new MenuItem("updater", "Atualizações", Symbol.Refresh), new MenuItem("drivers", "Drivers", Symbol.AllApps), new MenuItem("windows_update", "Windows Update", Symbol.Download) }),
        new("storage", "Armazenamento", Symbol.Folder, new[] { new MenuItem("disk", "Disco", Symbol.Folder), new MenuItem("duplicates", "Duplicados", Symbol.Copy), new MenuItem("cloud", "Sincronizados", Symbol.World), new MenuItem("backup", "Backup", Symbol.Save) }),
        new("network", "Rede", Symbol.World, new[] { new MenuItem("devices", "Dispositivos", Symbol.AllApps), new MenuItem("map", "Mapa", Symbol.Map), new MenuItem("files", "Arquivos", Symbol.Folder) }),
        new("service", "Atendimento", Symbol.Help, new[] { new MenuItem("support", "Suporte", Symbol.Help), new MenuItem("tickets", "Chamados", Symbol.Message) })
    };
    private readonly Dictionary<string, Button> _tabs = new(), _menus = new();
    private readonly Dictionary<string, string> _lastSection = new();
    private readonly List<Border> _separators = new();
    private string _group = "home";
    private AgentTheme? _appliedTheme;
    private AuthState? _paletteState;

    private CancellationTokenSource? _pending;

    public MainWindow()
    {
        InitializeComponent();
        BuildCategories();
        _dashboardPage = new(_runtime); _serverPage = new(_hub, () => FooterText.Text); _monitorPage = new(_hub, () => _runtime.MonitorEnabled);
        _speedPage = new(_runtime); _devicesPage = new(_runtime, m => FooterText.Text = m); _mapPage = new(_runtime, m => FooterText.Text = m);
        _programsPage = new(_runtime, ConfirmAsync); _filesPage = new(_runtime); _windowsUpdatePage = new(_runtime);
        var clean = new CleanPage(_runtime, ConfirmAsync); _tools.Add("clean", clean);
        _tools.Add("health", new HealthPage(_runtime, ConfirmAsync, SelectSection, (categories, age, tab) => { clean.Review(categories, age, tab); SelectSection("clean"); }));
        _tools.Add("registry", new RegistryPage(_runtime, ConfirmAsync)); _tools.Add("startup", new StartupPage(_runtime, ConfirmAsync));
        _tools.Add("performance", new PerformancePage(_runtime, ConfirmAsync)); _tools.Add("restore", new RestorePage(_runtime, ConfirmAsync));
        _tools.Add("options", new MaintenanceSettingsPage(_runtime, ConfirmAsync)); _tools.Add("uninstall", new UninstallPage(_runtime, ConfirmAsync));
        _tools.Add("updater", new SoftwareUpdatesPage(_runtime, ConfirmAsync)); _tools.Add("drivers", new DriversPage(_runtime, ConfirmAsync));
        _tools.Add("disk", new DiskPage(_runtime, ConfirmAsync)); _tools.Add("duplicates", new DuplicatesPage(_runtime, ConfirmAsync));
        _tools.Add("cloud", new CloudCleanupPage(_runtime, ConfirmAsync)); _tools.Add("backup", new CloudBackupPage(_runtime, ConfirmAsync));
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
        // O andamento segue no serviço; não criar janela flutuante no agente.
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
            _exiting = true;
            _refreshTimer.Stop(); _themeTimer.Stop(); _pending?.Cancel(); _hub.Dispose(); _agent.Dispose(); _themes.Dispose(); _presence.Dispose(); foreach (var page in _tools.Values) page.CancelOperations(); _programsPage.CancelOperations(); _runtime.Dispose(); _tray.Dispose();
        };
        _ = RefreshAsync(); _ = RefreshThemeAsync();
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
    private void BuildCategories()
    {
        foreach (var group in Groups)
        {
            var label = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 6 };
            label.Children.Add(new SymbolIcon(group.Icon) { Width = 16, Height = 16 });
            label.Children.Add(new TextBlock { Text = group.Label, FontSize = 12, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
            var tab = new Button { Tag = group.Key, Content = label, Style = (Style)RootGrid.Resources["RibbonTab"] };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(tab, "category-" + group.Key);
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(tab, group.Label);
            tab.Click += (_, _) => SelectSection(_lastSection.GetValueOrDefault(group.Key, group.Items[0].Key));
            _tabs.Add(group.Key, tab); CategoryTabs.Children.Add(tab);
        }
    }
    private void BuildRibbon(MenuGroup group)
    {
        RibbonMenus.Children.Clear(); _menus.Clear(); _separators.Clear();
        foreach (var item in group.Items)
        {
            var content = new StackPanel { Spacing = 2, HorizontalAlignment = HorizontalAlignment.Center };
            content.Children.Add(new SymbolIcon(item.Icon) { Width = 23, Height = 23 });
            content.Children.Add(new TextBlock { Text = item.Label, FontSize = 12, HorizontalAlignment = HorizontalAlignment.Center });
            var button = new Button { Tag = item.Key, Content = content, MinWidth = 84, Height = 54, Padding = new Thickness(7, 3, 7, 3), BorderThickness = new Thickness(0, 0, 0, 2), CornerRadius = new CornerRadius(3) };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(button, "nav-" + (item.Key == "speed" ? "internet" : item.Key));
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(button, item.Label); ToolTipService.SetToolTip(button, item.Label);
            button.Click += Menu_Click; _menus.Add(item.Key, button);
            var separator = new Border { BorderThickness = new Thickness(0, 0, 1, 0), Margin = new Thickness(0, 3, 5, 3), Padding = new Thickness(0, 0, 5, 0), Child = button };
            _separators.Add(separator); RibbonMenus.Children.Add(separator);
        }
        RibbonScroll.ChangeView(0, null, null, true);
    }
    private void ApplyTheme(AgentTheme t)
    {
        var p = Palette.From(t); RootGrid.Background = p.Alt; RootGrid.RequestedTheme = p.Light ? ElementTheme.Light : ElementTheme.Dark;
        QuickBar.Background = RibbonBar.Background = StatusBorder.Background = p.Surface; CategoryBar.Background = p.Accent;
        QuickBar.BorderBrush = RibbonBar.BorderBrush = StatusBorder.BorderBrush = p.Line;
        var accent = ((SolidColorBrush)p.Accent).Color;
        var ink = ThemeService.Brush((accent.R * .299 + accent.G * .587 + accent.B * .114) > 155 ? "#152536" : "#ffffff");
        foreach (var (key, tab) in _tabs) { tab.Background = key == _group ? p.Surface : p.Accent; tab.Foreground = key == _group ? p.Text : ink; Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(tab, key == _group ? "Categoria selecionada" : "Abrir categoria"); }
        foreach (var (key, menu) in _menus) { var selected = key == _section; menu.Background = selected ? p.Alt : p.Surface; menu.Foreground = selected ? p.Accent : p.Text; menu.BorderBrush = selected ? p.Accent : p.Surface; Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(menu, selected ? "Página selecionada" : "Abrir página"); }
        foreach (var separator in _separators) separator.BorderBrush = p.Line;
        RemoteButton.Foreground = RefreshButton.Foreground = p.Accent; NetworkBadge.Background = p.Surface; NetworkBadge.BorderBrush = p.Accent;
        NetworkTitle.Foreground = p.Text; ConnectionLabel.Foreground = FooterText.Foreground = p.Muted;
        if (_appliedTheme == t && _paletteState == _runtime.State) return; _appliedTheme = t; _paletteState = _runtime.State;
        _dashboardPage.ApplyPalette(p); _serverPage.ApplyPalette(p); _monitorPage.ApplyPalette(p); _speedPage.ApplyPalette(p); _devicesPage.ApplyPalette(p); _mapPage.ApplyPalette(p);
        _programsPage.ApplyPalette(p); foreach (var page in _tools.Values) page.ApplyPalette(p); _windowsUpdatePage.ApplyPalette(p); _filesPage.ApplyPalette(p); _supportPage.ApplyPalette(p); _ticketsPage.ApplyPalette(p);
    }
    private void Menu_Click(object sender, RoutedEventArgs e) { if (sender is Button menu) SelectSection(menu.Tag?.ToString() ?? "panel"); }
    private void SelectSection(string section)
    {
        var group = Groups.FirstOrDefault(g => g.Items.Any(i => i.Key == section)) ?? Groups[0];
        if (!group.Items.Any(i => i.Key == section)) section = "panel";
        _section = section; _lastSection[group.Key] = section;
        if (_group != group.Key || _menus.Count == 0) { _group = group.Key; BuildRibbon(group); }
        // Cada ferramenta tem sua página e seu estado; trocar menus não cancela nem reinicia operações.
        PageHost.Content = null; CompactHost.Content = null;
        var bounded = section is "programs" or "panel" || _tools.ContainsKey(section);
        PageScroll.Visibility = bounded ? Visibility.Collapsed : Visibility.Visible;
        CompactHost.Visibility = bounded ? Visibility.Visible : Visibility.Collapsed;
        if (section == "panel") CompactHost.Content = _dashboardPage;
        else if (section == "programs") CompactHost.Content = _programsPage;
        else if (_tools.TryGetValue(section, out var tool)) CompactHost.Content = tool;
        else PageHost.Content = section switch { "windows_update" => _windowsUpdatePage, "devices" => _devicesPage, "map" => _mapPage, "files" => _filesPage, "server" => _serverPage, "speed" => _speedPage, "support" => _supportPage, "tickets" => _ticketsPage, _ => _monitorPage };
        ApplyTheme(_themes.Current);
    }
    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        RefreshButton.IsEnabled = false;
        try { await _runtime.RecheckAsync(); await RefreshAsync(); await RefreshThemeAsync(); }
        catch (Exception ex) { StatusBar.Title = "Conexão"; StatusBar.Message = ex.Message; StatusBar.Severity = InfoBarSeverity.Warning; StatusBar.IsOpen = true; }
        finally { RefreshButton.IsEnabled = true; }
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
            NetworkTitle.Text = info.GetProperty("network_name").ToString();
            var logo = info.GetProperty("logo_base64").GetString() ?? "";
            if (logo.Length > 0)
            {
                var bytes = Convert.FromBase64String(logo); using var stream = new InMemoryRandomAccessStream();
                using (var writer = new DataWriter(stream)) { writer.WriteBytes(bytes); await writer.StoreAsync(); writer.DetachStream(); }
                stream.Seek(0); var bitmap = new BitmapImage(); await bitmap.SetSourceAsync(stream);
                NetworkBadge.Child = new Image { Source = bitmap, Stretch = Stretch.Uniform };
            }
            else NetworkBadge.Child = new TextBlock { Text = string.IsNullOrWhiteSpace(info.GetProperty("network_name").ToString()) ? "REDE" : info.GetProperty("network_name").ToString()[..Math.Min(2, info.GetProperty("network_name").ToString().Length)].ToUpperInvariant(), FontSize = 14, FontWeight = Microsoft.UI.Text.FontWeights.Bold, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
            ToolTipService.SetToolTip(BrandButton, "Painel · " + info.GetProperty("network_name"));
            _lastBrand = DateTime.UtcNow;
        }
        catch (Exception ex) { RuntimeLog.Write("Logo da rede: " + ex.Message); _lastBrand = DateTime.UtcNow; }
        finally { _branding = false; }
    }
    private void UpdateRuntimeUi()
    {
        if (!_runtime.Allowed) { foreach (var page in _tools.Values) page.CancelOperations(); _programsPage.CancelOperations(); }
        if (_paletteState != _runtime.State) ApplyTheme(_themes.Current);
        GateOverlay.Visibility = _runtime.Gated ? Visibility.Visible : Visibility.Collapsed; GateText.Text = _runtime.StateNotice;
        ConnectionLabel.Text = _runtime.State switch { AuthState.Approved or AuthState.UiLocked => "Conectado · " + _runtime.NetworkName, AuthState.Pending => "Aguardando liberação", AuthState.Checking => "Verificando…", _ => "Sem confirmação" };
        NetworkTitle.Text = string.IsNullOrWhiteSpace(_runtime.NetworkName) ? NetworkTitle.Text : _runtime.NetworkName;
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
