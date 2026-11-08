// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.xaml.cs
using System.Diagnostics;
using System.Text.Json;
using Arcoll.WinUI3.Models;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Arcoll.WinUI3.Services.BackupCenter;
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
    private readonly GestorClient _gestor = new();
    private readonly ThemeService _themes = new();
    private readonly PresenceService _presence = new();
    private readonly GestorRuntime _runtime = new();
    private readonly TelemetryHub _hub = new();
    private readonly TrayService _tray = new();
    private AuthState _trayLast = AuthState.Checking;
    private readonly ServerPage _serverPage;
    
    private readonly DashboardPage _dashboardPage;
    private readonly PenteFinoPage _penteFinoPage;
    private readonly BackupCenterPage _backupPage;
    private readonly BackupBackgroundLoop _backupLoop;
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
    private sealed record MenuItem(string Key, string Label, Symbol Icon, int Band = 0);
    private sealed record MenuGroup(string Key, string Label, Symbol Icon, MenuItem[] Items);
    private static readonly MenuGroup[] Groups = {
        new("home", "Início", Symbol.Home, new[] { new MenuItem("panel", "Painel", Symbol.Home), new MenuItem("server", "Servidor", Symbol.Setting, 1), new MenuItem("speed", "Internet", Symbol.World, 1), new MenuItem("health", "Pente-Fino", Symbol.Filter, 1) }),
        new("maintenance", "Manutenção", Symbol.Repair, new[] { new MenuItem("clean", "Limpeza", Symbol.Delete), new MenuItem("registry", "Registro", Symbol.Repair), new MenuItem("startup", "Inicialização", Symbol.Play), new MenuItem("performance", "Desempenho", Symbol.View, 1), new MenuItem("restore", "Restauração", Symbol.Undo, 1), new MenuItem("security", "Segurança", Symbol.Important, 1), new MenuItem("options", "Configurações", Symbol.Setting, 2) }),
        new("software", "Aplicativos", Symbol.Library, new[] { new MenuItem("programs", "Programas", Symbol.Library), new MenuItem("uninstall", "Desinstalar", Symbol.Remove), new MenuItem("updater", "Atualizações", Symbol.Refresh, 1), new MenuItem("drivers", "Drivers", Symbol.AllApps, 1), new MenuItem("windows_update", "Windows Update", Symbol.Download, 1) }),
        new("storage", "Armazenamento", Symbol.Folder, new[] { new MenuItem("disk", "Disco", Symbol.Folder), new MenuItem("duplicates", "Duplicados", Symbol.Copy), new MenuItem("cloud", "Sincronizados", Symbol.World, 1) }),
        new("backup_center", "Backup", Symbol.Save, new[] { new MenuItem("backup", "Visão geral", Symbol.Home), new MenuItem("backup_cloud", "Nuvem", Symbol.World), new MenuItem("backup_local", "Local / NAS", Symbol.Folder), new MenuItem("backup_restore", "Restauração", Symbol.Undo, 1), new MenuItem("backup_history", "Atividade", Symbol.Clock, 1), new MenuItem("backup_settings", "Destinos", Symbol.Setting, 1), new MenuItem("backup_drive", "Disco virtual", Symbol.Save, 1) }),
        new("network", "Rede", Symbol.World, new[] { new MenuItem("devices", "Dispositivos", Symbol.AllApps), new MenuItem("map", "Mapa", Symbol.Map), new MenuItem("files", "Arquivos", Symbol.Folder, 1) }),
        new("service", "Atendimento", Symbol.Help, new[] { new MenuItem("support", "Suporte", Symbol.Help), new MenuItem("tickets", "Chamados", Symbol.Message) })
    };
    private readonly Dictionary<string, Button> _tabs = new(), _menus = new();
    private readonly Dictionary<string, string> _lastSection = new();
    private readonly List<Border> _separators = new();
    private readonly Dictionary<string, Button> _stars = new();
    private readonly HashSet<string> _favorites = LoadFavorites();
    private static string FavoritesFile => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "menu-favorites.json");
    private static HashSet<string> LoadFavorites()
    {
        try { if (File.Exists(FavoritesFile)) return new(JsonSerializer.Deserialize<string[]>(File.ReadAllText(FavoritesFile)) ?? Array.Empty<string>()); }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException) { }
        return new();
    }
    private void SaveFavorites()
    {
        try { Directory.CreateDirectory(Path.GetDirectoryName(FavoritesFile)!); File.WriteAllText(FavoritesFile, JsonSerializer.Serialize(_favorites.OrderBy(x => x).ToArray())); }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { RuntimeLog.Write("Favoritos do menu: " + ex.Message); }
    }
    private string _group = "home";
    private GestorTheme? _appliedTheme;
    private AuthState? _paletteState;

    private CancellationTokenSource? _pending;

    public MainWindow()
    {
        InitializeComponent();
        BuildCategories();
        RootGrid.SizeChanged += (_, e) => AdaptHybridLayout(e.NewSize.Width);
        RootGrid.Loaded += async (_, _) => await InitializeHybridDashboardAsync();
        _dashboardPage = new(_runtime); _serverPage = new(_hub, () => FooterText.Text, () => _runtime.Allowed);
        _speedPage = new(_runtime); _devicesPage = new(_runtime, m => SetWorkspaceFooter(m)); _mapPage = new(_runtime, m => SetWorkspaceFooter(m));
        _programsPage = new(_runtime, ConfirmAsync); _filesPage = new(_runtime); _windowsUpdatePage = new(_runtime);
        var clean = new CleanPage(_runtime, ConfirmAsync); _tools.Add("clean", clean);
        _backupPage = new BackupCenterPage(_runtime);
        _backupLoop = new BackupBackgroundLoop(() => { var c = _runtime.Platform.Config; return new(c.Origin,c.Fingerprint,c.SiteCode,c.DownloadKey); }, () => _runtime.Allowed, Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "BackupCache"), Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "Restaurados"), log: RuntimeLog.Write);
        _backupLoop.Start();
        _penteFinoPage = new PenteFinoPage(_runtime, ConfirmAsync, SelectSection, ElevatePenteFinoAsync);
        _tools.Add("registry", new RegistryPage(_runtime, ConfirmAsync)); _tools.Add("startup", new StartupPage(_runtime, ConfirmAsync));
        _tools.Add("performance", new PerformancePage(_runtime, ConfirmAsync)); _tools.Add("restore", new RestorePage(_runtime, ConfirmAsync));
        _tools.Add("security", new SecurityPage(_runtime, ConfirmAsync));
        _tools.Add("options", new MaintenanceSettingsPage(_runtime, ConfirmAsync)); _tools.Add("uninstall", new UninstallPage(_runtime, ConfirmAsync));
        _tools.Add("updater", new SoftwareUpdatesPage(_runtime, ConfirmAsync)); _tools.Add("drivers", new DriversPage(_runtime, ConfirmAsync));
        _tools.Add("disk", new DiskPage(_runtime, ConfirmAsync)); _tools.Add("duplicates", new DuplicatesPage(_runtime, ConfirmAsync));
        _tools.Add("cloud", new CloudCleanupPage(_runtime, ConfirmAsync)); 
        _supportPage = new(_runtime, ShowRemoteCodeAsync, () => SelectSection("tickets")); _ticketsPage = new(_runtime);
        var icon = Path.Combine(AppContext.BaseDirectory, "Assets", "system.ico");
        if (File.Exists(icon)) try { AppWindow.SetIcon(icon); } catch (Exception ex) { RuntimeLog.Write("Ícone da janela: " + ex.Message); }
        var work = DisplayArea.GetFromWindowId(AppWindow.Id, DisplayAreaFallback.Primary).WorkArea;
        AppWindow.Resize(new Windows.Graphics.SizeInt32(Math.Max(480, Math.Min(1320, work.Width - 24)), Math.Max(420, Math.Min(830, work.Height - 40))));
        _hub.Start(); SelectSection("panel"); ApplyTheme(_themes.Current);
        _refreshTimer.Tick += async (_, _) => await RefreshAsync();
        _themeTimer.Tick += async (_, _) => { await RefreshThemeAsync(); if (DateTime.UtcNow - _lastBrand > TimeSpan.FromMinutes(2)) await RefreshBrandAsync(); };
        _refreshTimer.Start(); _themeTimer.Start();
        _runtime.ProgramActivity += message => DispatcherQueue.TryEnqueue(() => { _programsPage.ShowActivity(message); });
        _runtime.Changed += () => DispatcherQueue.TryEnqueue(UpdateRuntimeUi);
        // O andamento segue no serviço; não criar janela flutuante no gestor.
        _runtime.Consent = message =>
        {
            var result = new TaskCompletionSource<bool>();
            if (!DispatcherQueue.TryEnqueue(async () => { try { ShowFromBackground(); result.TrySetResult(await ConfirmAsync("Solicitação do gestor", message, "Autorizar")); } catch { result.TrySetResult(false); } })) result.TrySetResult(false);
            return result.Task;
        };
        _runtime.RequestExit = () => DispatcherQueue.TryEnqueue(ExitGestor);
        AppWindow.Closing += (_, e) => { if (!_exiting) { e.Cancel = true; AppWindow.Hide(); } };
        _tray.OnOpen = () => DispatcherQueue.TryEnqueue(ShowFromBackground);
        _tray.OnRestart = () => DispatcherQueue.TryEnqueue(RestartGestor);
        _tray.OnExit = () => DispatcherQueue.TryEnqueue(async () => { ShowFromBackground(); if (await ConfirmAsync("Encerrar gestor", "Encerrar interrompe o monitoramento até a próxima inicialização. Continuar?", "Encerrar")) ExitGestor(); });
        _tray.Start(); _runtime.Start(); _presence.Start();
        Closed += (_, _) =>
        {
            _exiting = true;
            _refreshTimer.Stop(); _themeTimer.Stop(); _pending?.Cancel(); _hub.Dispose(); _gestor.Dispose(); _themes.Dispose(); _presence.Dispose(); foreach (var page in _tools.Values) page.CancelOperations(); _programsPage.CancelOperations(); _penteFinoPage.CancelOperations(); _backupLoop.Dispose(); _runtime.Dispose(); _tray.Dispose();
        };
        _ = RefreshAsync(); _ = RefreshThemeAsync();
    }
    public void ShowFromBackground() { AppWindow.Show(); Activate(); }
    private void ExitGestor() { _exiting = true; Close(); Application.Current.Exit(); }
    private void RestartGestor()
    {
        var exe = Environment.ProcessPath; if (string.IsNullOrEmpty(exe)) return;
        try { Process.Start(new ProcessStartInfo(exe) { UseShellExecute = false, CreateNoWindow = true, Arguments = "--background --wait-exit " + Environment.ProcessId }); ExitGestor(); }
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
            var label = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 6, VerticalAlignment = VerticalAlignment.Center };
            label.Children.Add(new SymbolIcon(group.Icon) { Width = 16, Height = 16 });
            label.Children.Add(new TextBlock { Text = group.Label, FontSize = 12, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold, VerticalAlignment = VerticalAlignment.Center });
            var tab = new Button { Tag = group.Key, Content = label, Style = (Style)RootGrid.Resources["RibbonTab"], Margin = new Thickness(0) };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(tab, "category-" + group.Key);
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(tab, group.Label);
            tab.Click += (_, _) => QueueSectionNavigation(_lastSection.GetValueOrDefault(group.Key, group.Items[0].Key));
            tab.PointerEntered += (_, _) => SetNavigationHover(tab, true); tab.PointerExited += (_, _) => SetNavigationHover(tab, false);
            _tabs.Add(group.Key, tab); CategoryTabs.Children.Add(tab);
        }
    }
    private void BuildRibbon(MenuGroup group)
    {
        RibbonMenus.Children.Clear(); _menus.Clear(); _separators.Clear(); _stars.Clear();
        // Botões em faixas (bandas) separadas por linha vertical; favoritos vêm primeiro dentro da própria faixa.
        var ordered = group.Items.Select((item, index) => (item, index))
            .OrderBy(x => x.item.Band).ThenByDescending(x => _favorites.Contains(x.item.Key)).ThenBy(x => x.index).Select(x => x.item).ToArray();
        var band = ordered[0].Band;
        foreach (var item in ordered)
        {
            if (item.Band != band)
            {
                band = item.Band;
                var line = new Border { Width = 1, Margin = new Thickness(6, 8, 6, 8), VerticalAlignment = VerticalAlignment.Stretch };
                _separators.Add(line); RibbonMenus.Children.Add(line);
            }
            var content = new StackPanel { Orientation = Orientation.Vertical, Spacing = 3, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
            // Os valores de Symbol são os próprios códigos do Segoe MDL2: o FontIcon permite ícones maiores que o SymbolIcon.
            content.Children.Add(new FontIcon { Glyph = ((char)(int)item.Icon).ToString(), FontSize = 26, HorizontalAlignment = HorizontalAlignment.Center });
            content.Children.Add(new TextBlock { Text = item.Label, FontSize = 12, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center, TextAlignment = TextAlignment.Center, TextWrapping = TextWrapping.Wrap, MaxLines = 2 });
            var button = new Button { Tag = item.Key, Content = content, Style = (Style)RootGrid.Resources["FlatButton"], MinWidth = 78, Height = 66, Padding = new Thickness(12, 6, 12, 4), CornerRadius = new CornerRadius(8) };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(button, "nav-" + (item.Key == "speed" ? "internet" : item.Key));
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(button, item.Label); ToolTipService.SetToolTip(button, item.Label);
            button.Click += Menu_Click;
            button.PointerEntered += (_, _) => SetNavigationHover(button, true); button.PointerExited += (_, _) => SetNavigationHover(button, false);
            _menus.Add(item.Key, button);
            var star = new Button { Tag = item.Key, Style = (Style)RootGrid.Resources["FlatButton"], Width = 22, Height = 22, Padding = new Thickness(0), CornerRadius = new CornerRadius(11), HorizontalAlignment = HorizontalAlignment.Right, VerticalAlignment = VerticalAlignment.Top, Margin = new Thickness(0, 1, 1, 0), Content = new FontIcon { FontSize = 11 } };
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(star, "Favoritar " + item.Label); ToolTipService.SetToolTip(star, "Favoritar");
            star.Click += (_, _) =>
            {
                if (!_favorites.Remove(item.Key)) _favorites.Add(item.Key);
                SaveFavorites(); BuildRibbon(group); ApplyTheme(_themes.Current);
            };
            _stars.Add(item.Key, star);
            var cell = new Grid(); cell.Children.Add(button); cell.Children.Add(star);
            RibbonMenus.Children.Add(cell);
        }
        RibbonScroll.ChangeView(0, null, null, true);
    }
    private void ApplyTheme(GestorTheme t)
    {
        var p = Palette.From(t); RootGrid.Background = p.Alt; RootGrid.RequestedTheme = p.Light ? ElementTheme.Light : ElementTheme.Dark;
        QuickBar.Background = RibbonBar.Background = StatusBorder.Background = p.Surface; CategoryBar.Background = p.Surface;
        QuickBar.BorderBrush = RibbonBar.BorderBrush = StatusBorder.BorderBrush = p.Line;
        var accent = ((SolidColorBrush)p.Accent).Color;
        var ink = ThemeService.Brush((accent.R * .299 + accent.G * .587 + accent.B * .114) > 155 ? "#152536" : "#ffffff");
        foreach (var (key, tab) in _tabs)
        {
            var selected = key == _group;
            tab.Background = selected ? p.Accent : p.Surface;
            tab.Foreground = selected ? ThemeService.Brush("#ffffff") : p.Text;
            tab.BorderBrush = selected ? p.Accent : p.Line;
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(tab, selected ? "Categoria selecionada" : "Abrir categoria");
        }
        foreach (var (key, menu) in _menus)
        {
            var selected = key == _section;
            menu.Background = selected ? p.Alt : p.Surface;
            menu.Foreground = selected ? p.Accent : p.Text;
            menu.BorderBrush = selected ? p.Accent : p.Line;
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(menu, selected ? "Página selecionada" : "Abrir página");
        }
        foreach (var separator in _separators) separator.Background = p.Line;
        ApplyHybridChrome(p);
        ConnectionLabel.Foreground = FooterText.Foreground = p.Muted;
        if (_appliedTheme == t && _paletteState == _runtime.State) return; _appliedTheme = t; _paletteState = _runtime.State;
        _dashboardPage.ApplyPalette(p); _serverPage.ApplyPalette(p); _speedPage.ApplyPalette(p); _devicesPage.ApplyPalette(p); _mapPage.ApplyPalette(p);
        _backupPage.ApplyPalette(p); _penteFinoPage.ApplyPalette(p); _programsPage.ApplyPalette(p); foreach (var page in _tools.Values) page.ApplyPalette(p); _windowsUpdatePage.ApplyPalette(p); _filesPage.ApplyPalette(p); _supportPage.ApplyPalette(p); _ticketsPage.ApplyPalette(p);
    }
    private void Menu_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button menu) return;
        var target = menu.Tag?.ToString() ?? "panel";
        RuntimeLog.Write($"Navegação: clique em {target}; atual={_section}");
        QueueSectionNavigation(target);
    }
    // Adiar a troca para depois do evento Click evita alterar a árvore visual
    // enquanto WinUI ainda processa RoutedEventArgs do botão que originou a ação.
    private void QueueSectionNavigation(string section)
    {
        if (!DispatcherQueue.TryEnqueue(() =>
        {
            RuntimeLog.Write("Navegação: evento concluído, iniciando troca para " + section);
            SelectSection(section);
        })) RuntimeLog.Write("Navegação: DispatcherQueue indisponível para " + section);
    }
    private void SelectSection(string section)
    {
        // Erros de uma área não devem encerrar a interface inteira; preservar telemetria diagnóstica.
        try { SelectSectionCore(section); }
        catch (Exception ex)
        {
            RuntimeLog.Write("Falha ao abrir área " + section + ": " + ex);
            try
            {
                PageHost.Content = null;
                CompactHost.Content = null;
                HybridDashboard.Visibility = Visibility.Collapsed;
                CompactHost.Visibility = Visibility.Visible;
                CompactHost.Content = _dashboardPage;
                _section = "panel";
                StatusBar.Title = "Área temporariamente indisponível";
                StatusBar.Message = "A área não pôde ser aberta. Consulte o registro do gestor para identificar a causa.";
                StatusBar.Severity = InfoBarSeverity.Warning;
                StatusBar.IsOpen = true;
            }
            catch (Exception fallback) { RuntimeLog.Write("Falha na recuperação da navegação: " + fallback); }
        }
    }
    private void SelectSectionCore(string section)
    {
        if(section=="monitor")section="server";
        if (section == _section && (_menus.Count > 0) && (CompactHost.Content is not null || (_hybridReady && HybridDashboard.Visibility == Visibility.Visible)))
        {
            RuntimeLog.Write("Navegação: área já ativa: " + section);
            return;
        }
        RuntimeLog.Write("Navegação: iniciando " + section);
        var group = Groups.FirstOrDefault(g => g.Items.Any(i => i.Key == section)) ?? Groups[0];
        if (!group.Items.Any(i => i.Key == section)) section = "panel";
        _section = section; _lastSection[group.Key] = section;
        if (_group != group.Key || _menus.Count == 0) { _group = group.Key; BuildRibbon(group); }
        // Cada ferramenta tem sua página e seu estado; trocar menus não cancela nem reinicia operações.
        RuntimeLog.Write("Navegação: liberando conteúdo anterior");
        PageHost.Content = null; CompactHost.Content = null; HybridDashboard.Visibility = Visibility.Collapsed;
        var bounded = true;
        PageScroll.Visibility = bounded ? Visibility.Collapsed : Visibility.Visible;
        CompactHost.Visibility = bounded ? Visibility.Visible : Visibility.Collapsed;
        if (section == "panel") { if (_hybridReady) { HybridDashboard.Visibility = Visibility.Visible; CompactHost.Visibility = Visibility.Collapsed; } else CompactHost.Content = _dashboardPage; }
        else if (section.StartsWith("backup", StringComparison.Ordinal)) { _backupPage.Navigate(section); CompactHost.Content = _backupPage; }
        else if (section == "programs") CompactHost.Content = _programsPage;
        else if (section == "health") CompactHost.Content = _penteFinoPage;
        else if (_tools.TryGetValue(section, out var tool)) CompactHost.Content = tool;
        else CompactHost.Content = section switch { "windows_update" => _windowsUpdatePage, "devices" => _devicesPage, "map" => _mapPage, "files" => _filesPage, "server" => _serverPage, "speed" => _speedPage, "support" => _supportPage, "tickets" => _ticketsPage, _ => _serverPage };
        RuntimeLog.Write("Navegação: conteúdo criado; aplicando tema: " + section);
        ApplyTheme(_themes.Current);
        UpdateHybridHeading();
        // Evita animar a árvore visual durante a troca de páginas nativas;
        // mantém a animação no painel web sem competir com o carregamento WinUI.
        if (section == "panel" && _hybridReady) AnimateHybridPage();
        PublishHybridState();
        RuntimeLog.Write("Navegação: concluída " + section);
    }
    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        if (!RefreshButton.IsEnabled) return;
        RefreshButton.IsEnabled = false;
        try
        {
            await _runtime.RecheckAsync();
            await RefreshAsync();
            await RefreshThemeAsync();
            if (!_runtime.Allowed) throw new InvalidOperationException("O gestor precisa estar conectado e autorizado para buscar atualizações.");
            using var timeout = new CancellationTokenSource(TimeSpan.FromMinutes(12));
            var result = await UpdateService.StageAndLaunchAsync(_runtime.Platform, 0, false,
                (percent, message) => DispatcherQueue.TryEnqueue(() =>
                {
                    StatusBar.Title = "Atualização do gestor";
                    StatusBar.Message = $"{percent}% · {message}";
                    StatusBar.Severity = InfoBarSeverity.Informational;
                    StatusBar.IsOpen = true;
                }), timeout.Token);
            if (result.Launched)
            {
                StatusBar.Title = "Atualização iniciada";
                StatusBar.Message = "O progresso será exibido em uma janela separada. O gestor será aberto ao concluir.";
                StatusBar.IsOpen = true;
                // O instalador separado precisa encerrar esta instancia antes de substituir os executaveis.
                await Task.Delay(1500);
                ExitGestor();
                return;
            }
            StatusBar.Title = "Atualização do gestor";
            StatusBar.Message = result.Message;
            StatusBar.Severity = InfoBarSeverity.Success;
            StatusBar.IsOpen = true;
        }
        catch (Exception ex)
        {
            StatusBar.Title = "Não foi possível atualizar";
            StatusBar.Message = ex.Message;
            StatusBar.Severity = InfoBarSeverity.Warning;
            StatusBar.IsOpen = true;
        }
        finally { RefreshButton.IsEnabled = true; }
    }
    private async Task RefreshAsync()
    {
        if (_refreshing) return; _refreshing = true;
        _pending?.Cancel(); _pending?.Dispose(); _pending = new(TimeSpan.FromSeconds(12));
        try { var info = await _gestor.HealthAsync(_pending.Token); FooterText.Text = info.Host + " · " + info.Ip + " · v" + info.Version; }
        catch (Exception ex) { SetWorkspaceFooter(ex.Message); }
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
            NetworkTitle.Text = info.GetProperty("network_name").ToString(); ToolTipService.SetToolTip(NetworkTitle,NetworkTitle.Text);
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
        if (!_runtime.Allowed) { _penteFinoPage.CancelOperations(); foreach (var page in _tools.Values) page.CancelOperations(); _programsPage.CancelOperations(); }
        if (_paletteState != _runtime.State) ApplyTheme(_themes.Current);
        _serverPage.RefreshAuthorization();
        GateOverlay.Visibility = _runtime.Gated ? Visibility.Visible : Visibility.Collapsed; GateText.Text = _runtime.StateNotice;
        ConnectionLabel.Text = _runtime.State switch { AuthState.Approved or AuthState.UiLocked => "Conectado · " + _runtime.NetworkName, AuthState.Pending => "Aguardando liberação", AuthState.Checking => "Verificando…", _ => "Sem confirmação" };
        NetworkTitle.Text = string.IsNullOrWhiteSpace(_runtime.NetworkName) ? NetworkTitle.Text : _runtime.NetworkName;
        Title = _runtime.SystemName + " — Gestor Windows";
        var state = _runtime.Allowed ? TrayState.Connected : _runtime.State is AuthState.Pending or AuthState.Checking ? TrayState.Pending : TrayState.Offline;
        _tray.SetState(state, ConnectionLabel.Text);
        if (_trayLast != _runtime.State) { _trayLast = _runtime.State; if (_runtime.Allowed) _ = RefreshBrandAsync(); }
        if (_runtime.LastActivity.Length > 0 && _runtime.Allowed) WorkspaceRuntimeActivity(_runtime.LastActivity);
        PublishHybridState();
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
                    var result = await _runtime.Platform.PostAsync("gestor-support-code", _runtime.Platform.WithCredentials(), timeout.Token, 12);
                    code = result.GetProperty("code").GetString() ?? ""; seconds = result.GetProperty("expires_in").GetInt32(); operation.Succeed("Código temporário gerado");
                }
                catch (Exception ex) { operation.Fail(ex.Message); throw; }
            }
            var content = new StackPanel { Spacing = 12 };
            content.Children.Add(new TextBlock { Text = code, FontSize = 34, FontWeight = Microsoft.UI.Text.FontWeights.Bold, IsTextSelectionEnabled = true, HorizontalAlignment = HorizontalAlignment.Center });
            var expiry = new TextBlock { TextWrapping = TextWrapping.Wrap }; content.Children.Add(expiry);
            var until = DateTime.UtcNow.AddSeconds(seconds); var timer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
            void Tick() { var left = Math.Max(0, (int)Math.Ceiling((until - DateTime.UtcNow).TotalSeconds)); expiry.Text = left > 0 ? "Compartilhe este código. Expira em " + left + " segundos; uso único." : "Código expirado. Feche e gere um novo código."; }
            var dialog = Ui.Dialog(Content.XamlRoot, Palette.From(_themes.Current), "Acesso remoto", content, "Copiar código", close: "Fechar", defaultButton: ContentDialogButton.Primary, note: "Código temporário de uso único para suporte autorizado.");
            dialog.PrimaryButtonClick += (_, e) => { e.Cancel = true; if (DateTime.UtcNow < until) { var package = new DataPackage(); package.SetText(code); try { Clipboard.SetContent(package); } catch { expiry.Text = "Não foi possível copiar. Selecione o código e copie manualmente."; } } };
            timer.Tick += (_, _) => { Tick(); dialog.IsPrimaryButtonEnabled = DateTime.UtcNow < until; }; Tick(); timer.Start();
            try { await dialog.ShowAsync(); } finally { timer.Stop(); }
        }
        catch (Exception ex) { StatusBar.Title = "Acesso remoto"; StatusBar.Message = ex.Message; StatusBar.Severity = InfoBarSeverity.Warning; StatusBar.IsOpen = true; }
        finally { _remoteDialog = false; }
    }
    private async Task<bool> ConfirmAsync(string title, string message, string primary)
    {
        var dialog = Ui.MessageDialog(Content.XamlRoot, Palette.From(_themes.Current), title, message, primary, close: "Cancelar", defaultButton: ContentDialogButton.Close, note: "Revise a ação antes de continuar.");
        return await dialog.ShowAsync() == ContentDialogResult.Primary;
    }
}
