// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.Hybrid.cs
// EAtapa 1 · Shell híbrido (WinUI 3 + WebView2). O núcleo do gestor permanece nativo.
using System.Text.Json;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Animation;
using Microsoft.Web.WebView2.Core;

namespace Arcoll.WinUI3;

public sealed partial class MainWindow
{
    private bool _hybridReady, _hybridLoading, _hybridClosed, _networkPanelBusy;
    private readonly HashSet<Button> _navigationHover = new();
    private const string HybridOrigin = "https://tidigital.local/";

    private void AdaptHybridLayout(double width)
    {
        // A barra superior e o submenu são horizontais com rolagem independente.
        // O conteúdo permanece em largura total mesmo em janelas compactas.
        HeroFrame.Visibility = width >= 560 ? Visibility.Visible : Visibility.Collapsed;
        HeroTag.Visibility = Visibility.Collapsed; // categoria já indicada no menu superior
        BrandSubtitle.Visibility = width >= 760 ? Visibility.Visible : Visibility.Collapsed;
    }

    private static Microsoft.UI.Xaml.Media.Brush InkOn(Microsoft.UI.Xaml.Media.Brush background)
    {
        var color = ((SolidColorBrush)background).Color;
        return ThemeService.Brush(GestorAreaPalette.Ink($"#{color.R:X2}{color.G:X2}{color.B:X2}"));
    }

    private void ApplyHybridChrome(Palette p)
    {
        // Áreas 2 e 3 vêm do tema administrativo; o rodapé é uma variação mais escura da área 2.
        var areas=AreaColors();
        var ink = InkOn(p.Accent);
        QuickBar.Background = p.Accent;
        NetworkTitle.Foreground = ink;
        BrandSubtitle.Foreground = ink;
        RefreshButton.Foreground = RemoteButton.Foreground = ink;
        // O logo permanece no rodapé e mantém uma base neutra para preservar sua leitura.
        NetworkBadge.Background = ThemeService.Brush("#FFFFFF");
        NetworkBadge.BorderBrush = ink;
        CategoryBar.Background = p.Accent;
        RibbonBar.Background = ThemeService.Brush(AreaColors().Area3);
        RibbonBar.BorderBrush = ThemeService.Brush(areas.Line);
        StatusBorder.Background = ThemeService.Brush(areas.Area10);
        HeroFrame.Background = p.Light ? ThemeService.Brush("#EAF2FF") : ThemeService.Brush("#142C47");
        HeroFrame.BorderBrush = p.Line;
        HeroTitle.Foreground = p.Text;
        HeroDescription.Foreground = p.Muted;
        HeroIcon.Foreground = p.Accent;
        PaintNavigation(p);
        if(_compactLoaded)RefreshGestorAreas();
    }

    private void SetNavigationHover(Button button, bool isOver)
    {
        if (isOver) _navigationHover.Add(button);
        else _navigationHover.Remove(button);
        PaintNavigation(Palette.From(_themes.Current));
    }

    private static void SetNavigationForeground(Button button, Microsoft.UI.Xaml.Media.Brush ink)
    {
        button.Foreground = ink;
        if (button.Content is StackPanel content)
            foreach (var child in content.Children)
            {
                if (child is TextBlock label) label.Foreground = ink;
                else if (child is SymbolIcon glyph) glyph.Foreground = ink;
                else if (child is FontIcon font) font.Foreground = ink;
            }
    }

    private void PaintNavigation(Palette p)
    {
        // O modelo simples dos botões usa sempre as cores calculadas aqui, sem estados visuais internos conflitantes.
        var ribbonSurface = ThemeService.Brush(AreaColors().Area3);
        var tabInk = InkOn(p.Accent);
        var tabHover = new SolidColorBrush(Windows.UI.Color.FromArgb(46, 255, 255, 255));
        var menuHover = ThemeService.Brush(GestorAreaPalette.Mix(AreaColors().Area3,AreaColors().Accent,.14));
        var transparent = new SolidColorBrush(Microsoft.UI.Colors.Transparent);
        var gold = ThemeService.Brush("#F59E0B");
        foreach (var (key, tab) in _tabs)
        {
            var selected = key == _group;
            var isOver = _navigationHover.Contains(tab);
            // A aba ativa tem a mesma cor da faixa de ícones: parece uma pasta aberta ligada ao menu.
            tab.Background = selected ? ribbonSurface : (isOver ? tabHover : transparent);
            tab.BorderBrush = transparent;
            tab.BorderThickness = new Thickness(0);
            SetNavigationForeground(tab, selected ? p.Accent : tabInk);
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(tab, selected ? "Categoria selecionada" : "Abrir categoria");
        }
        foreach (var (key, button) in _menus)
        {
            var selected = key == _section;
            var isOver = _navigationHover.Contains(button);
            button.Background = selected || isOver ? menuHover : transparent;
            button.BorderBrush = transparent;
            button.BorderThickness = new Thickness(0);
            SetNavigationForeground(button, p.Text);
            if (button.Content is StackPanel content)
                foreach (var child in content.Children) if (child is FontIcon icon) icon.Foreground = p.Accent;
            Microsoft.UI.Xaml.Automation.AutomationProperties.SetHelpText(button, selected ? "Página selecionada" : "Abrir página");
        }
        foreach (var (key, star) in _stars)
        {
            var favorite = _favorites.Contains(key);
            if (star.Content is FontIcon icon) { icon.Glyph = favorite ? "\uE735" : "\uE734"; icon.Foreground = favorite ? gold : p.Muted; }
            star.Opacity = favorite ? 1 : 0.7;
        }
    }

    private void UpdateHybridHeading()
    {
        var group = Groups.FirstOrDefault(x => x.Key == _group);
        var item = Groups.SelectMany(x => x.Items).FirstOrDefault(x => x.Key == _section);
        HeroTitle.Text = item?.Label ?? "Painel";
        HeroIcon.Glyph = _section switch
        {
            "programs" => "\uE80F", "health" => "\uEB52", "monitor" => "\uE9D9", "server" => "\uE713",
            "backup" => "\uE753", "drivers" => "\uE964", "security" => "\uE7BA", "tickets" => "\uE8F2", _ => "\uE80F"
        };
        HeroDescription.Text = _section switch
        {
            "programs" => "Instale, atualize e gerencie seus aplicativos em um só lugar.",
            "panel" => "Dispositivos da rede, identificação, tempo de presença e últimas entradas e saídas observadas.",
            "monitor" => "Indicadores e dados do monitoramento do Windows.",
            "health" => "Pente-Fino: examinar, revisar e corrigir com backup, quarentena e desfazer.",
            "security" => "Verifique proteção do Windows, firewall, antivírus registrados e TPM.",
            "clean" => "Analise e selecione com segurança o que deseja limpar.",
            "drivers" => "Consulte e atualize drivers compatíveis com este dispositivo.",
            "backup" => "Gerencie cópias de segurança e sincronização em nuvem.",
            _ => "Ferramentas de " + (group?.Label.ToLowerInvariant() ?? "gestão") + " do T.I. Digital."
        };
        HeroTag.Text = (group?.Label ?? "INÍCIO").ToUpperInvariant();
    }

    private void AnimateHybridPage()
    {
        if (PageRegion.ActualWidth < 1) return;
        var animation = new DoubleAnimation { From = 0.72, To = 1.0, Duration = new Duration(TimeSpan.FromMilliseconds(190)),
            EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut } };
        Storyboard.SetTarget(animation, PageRegion);
        Storyboard.SetTargetProperty(animation, "Opacity");
        var storyboard = new Storyboard();
        storyboard.Children.Add(animation);
        storyboard.Begin();
    }

    private async Task InitializeHybridDashboardAsync()
    {
        if (_hybridReady || _hybridLoading || _hybridClosed) return;
        _hybridLoading = true;
        Closed += (_, _) => _hybridClosed = true;
        try
        {
            var folder = Path.Combine(AppContext.BaseDirectory, "Assets", "hybrid", "dashboard");
            if (!File.Exists(Path.Combine(folder, "index.html")))
                throw new FileNotFoundException("Os arquivos da interface híbrida não foram instalados.");
            var profile = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TI-Digital", "HybridWebView2");
            Directory.CreateDirectory(profile);
            var environment = await CoreWebView2Environment.CreateWithOptionsAsync(null, profile, null);
            await HybridDashboard.EnsureCoreWebView2Async(environment);
            var web = HybridDashboard.CoreWebView2;
            if (web is null) throw new InvalidOperationException("WebView2 indisponível.");
            web.Settings.AreDevToolsEnabled = false;
            web.Settings.AreDefaultContextMenusEnabled = false;
            web.Settings.AreHostObjectsAllowed = false;
            web.Settings.IsWebMessageEnabled = true;
            web.SetVirtualHostNameToFolderMapping("tidigital.local", folder, CoreWebView2HostResourceAccessKind.DenyCors);
            web.NavigationStarting += (_, e) =>
            {
                // Somente conteúdo local empacotado. Nenhum site externo recebe acesso à bridge.
                if (!e.Uri.StartsWith(HybridOrigin, StringComparison.OrdinalIgnoreCase)) e.Cancel = true;
            };
            web.NewWindowRequested += (_, e) => e.Handled = true;
            web.WebMessageReceived += async (_, e) =>
            {
                if (!e.Source.StartsWith(HybridOrigin, StringComparison.OrdinalIgnoreCase)) return;
                try
                {
                    using var message = JsonDocument.Parse(e.WebMessageAsJson);
                    var root = message.RootElement;
                    if (!root.TryGetProperty("type", out var typ) || typ.ValueKind != JsonValueKind.String) return;
                    if (typ.GetString() == "network-ready") { PublishHybridState(); return; }
                    if (typ.GetString() == "network-refresh")
                    {
                        if (root.TryGetProperty("requestId", out var request) && request.ValueKind == JsonValueKind.Number && request.TryGetInt32(out var requestId) && requestId > 0)
                            await RefreshNetworkPanelAsync(requestId);
                        return;
                    }
                    if (typ.GetString() != "navigate") return;
                    if (!root.TryGetProperty("section", out var target) || target.ValueKind != JsonValueKind.String) return;
                    var section = target.GetString() ?? "";
                    // Navegação permanece restrita às seções registradas do gestor.
                    if (Groups.Any(g => g.Items.Any(i => i.Key == section))) SelectSection(section);
                }
                catch (JsonException) { /* Mensagens inválidas não alteram o estado do gestor. */ }
            };
            HybridDashboard.NavigationCompleted += (_, args) =>
            {
                if (!args.IsSuccess) { _networkPageLoad.Fail("Não foi possível abrir o painel de dispositivos.");RuntimeLog.Write("Dashboard híbrido: navegação não concluída."); return; }
                _hybridReady = true;
                SelectSection(_section);
                PublishHybridState();
            };
            HybridDashboard.Source = new Uri(HybridOrigin + "index.html");
        }
        catch (Exception ex)
        {
            // Falta do runtime WebView2 não pode derrubar o gestor: fallback no Painel nativo.
            RuntimeLog.Write("Interface híbrida indisponível. Utilizando Painel nativo: " + ex.Message);
            _hybridReady = false;
            StatusBar.Title = "Interface híbrida";
            StatusBar.Message = "Painel nativo ativado: a interface WebView2 não pôde ser iniciada.";
            StatusBar.Severity = InfoBarSeverity.Informational;
            StatusBar.IsOpen = true;
        }
        finally { _hybridLoading = false; }
    }

    // A consulta usa as credenciais somente no cliente nativo e a rede do gestor autenticado.
    // A página local recebe apenas o inventário sanitizado; nenhuma chave atravessa a bridge.
    private async Task RefreshNetworkPanelAsync(int requestId)
    {
        if (_networkPanelBusy || !_hybridReady || _hybridClosed || _section != "panel") return;
        if (!_runtime.Allowed) { PublishHybridState(); return; }
        _networkPanelBusy = true;_networkPageLoad.Begin("Consultando o inventário da rede",preserveData:true);
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(18));
            var data = await WorkspaceService.GetAsync(_runtime, "dashboard", timeout.Token);
            if (!_runtime.Allowed) { PublishHybridState(); return; }
            if (!_hybridClosed && _hybridReady)
                {HybridDashboard.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new { type = "network-data", requestId, payload = data }));
                if(data.TryGetProperty("devices",out var devices)&&devices.ValueKind==JsonValueKind.Array){if(devices.GetArrayLength()>0||(data.TryGetProperty("updated",out var updated)&&updated.TryGetInt64(out var stamp)&&stamp>0))_networkPageLoad.Complete();else _networkPageLoad.Wait("Clique para descobrir e coletar os dispositivos da rede.");}else _networkPageLoad.Fail("O inventário recebido está incompleto. Tente novamente.");}
        }
        catch (Exception ex)
        {
            _networkPageLoad.Fail("Não foi possível consultar o inventário. Clique para tentar novamente.");RuntimeLog.Write("Painel de dispositivos: " + ErrorDiagnostic.Sanitize(ex.Message));
            if (!_hybridClosed && _hybridReady)
                try { HybridDashboard.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new { type = "network-error", requestId, message = "Não foi possível consultar o inventário. Verifique a conexão com a plataforma." })); }
                catch (InvalidOperationException) { }
        }
        finally { _networkPanelBusy = false; }
    }

    private void PublishHybridState()
    {
        if (!_hybridReady || HybridDashboard.CoreWebView2 is null) return;
        var info = Groups.Select(g => new { id = g.Key, label = g.Label, items = ItemsOf(g).Select(i => new { id = i.Key, label = i.Label }) });
        var message = new
        {
            type = "state", title = _runtime.SystemName, network = _runtime.NetworkName,
            connected = _runtime.Allowed, section = _section, group = _group,
            theme = ThemeService.IsLight(_themes.Current) ? "light" : "dark",
            accent = _themes.Current.Accent, areas=AreaColors(), groups = info
        };
        try { HybridDashboard.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(message)); }
        catch (InvalidOperationException) { /* WebView reiniciando; o shell nativo continua funcional. */ }
    }
}
