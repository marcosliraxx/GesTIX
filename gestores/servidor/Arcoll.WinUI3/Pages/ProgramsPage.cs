// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/ProgramsPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace Arcoll.WinUI3.Pages;

/// <summary>Mesmo catálogo, agrupamento e lote manual da plataforma. Desinstalação possui página independente.</summary>
public sealed class ProgramsPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Carregando catálogo de programas",true);
    public Task CollectDataAsync()=>RefreshAsync();

    private readonly GestorRuntime _runtime;
    private readonly Func<string,string,string,Task<bool>> _confirm;
    private readonly Grid _root = new() { RowSpacing = 0 };
    private readonly TextBox _search = new() { PlaceholderText = "Pesquisar aplicativos", HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly ComboBox _category = new() { HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly TextBlock _summary = new() { TextWrapping = TextWrapping.Wrap }, _status = new() { TextWrapping = TextWrapping.Wrap, IsTextSelectionEnabled = true };
    private readonly Grid _groups = new() { ColumnSpacing = 4, RowSpacing = 0, HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly Grid _shell = new() { ColumnSpacing = 0 };
    private readonly Border _central = new() { Visibility = Visibility.Collapsed, Width = 260 };
    private readonly ScrollViewer _catalogScroll;
    private readonly Button _settings = new() { Content = new SymbolIcon(Symbol.Setting), MinWidth = 33, MinHeight = 33, Padding = new Thickness(5) };
    private readonly TextBlock _activity = new() { Text = "Catálogo carregado", TextWrapping = TextWrapping.Wrap };
    private int _visibleRows;

    private readonly Button _install = new() { Content = "Instalar e Atualizar" }, _clear = new() { Content = "Limpar" },
        _cancel = new() { Content = "Cancelar operação", IsEnabled = false };
    private readonly HashSet<string> _selected = new(StringComparer.Ordinal);
    private IReadOnlyList<CatalogApplication> _catalog = Array.Empty<CatalogApplication>();
    private IReadOnlyList<InstalledApplication> _installed = Array.Empty<InstalledApplication>();
    private Palette _p = new();
    private readonly Border _footer;
    private bool _busy, _loaded;
    private CancellationTokenSource? _operation;
    private bool CanAct => _runtime.Allowed;

    public ProgramsPage(GestorRuntime runtime, Func<string,string,string,Task<bool>> confirm)
    {
        _runtime = runtime; _confirm = confirm; Content = _shell;
        _shell.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        _shell.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        _shell.Children.Add(_root);
        _shell.Children.Add(_central); Grid.SetColumn(_central, 1);
        for (var row = 0; row < 4; row++) _root.RowDefinitions.Add(new() { Height = row == 2 ? new GridLength(1, GridUnitType.Star) : GridLength.Auto });
        foreach (var button in new[] { _install, _clear, _cancel }) { button.FontSize = 12; button.MinHeight = 32; button.Padding = new Thickness(12, 5, 12, 5); button.CornerRadius = new CornerRadius(7); }
        _search.MinHeight = _category.MinHeight = 32; _search.FontSize = _category.FontSize = 12;
        _status.FontSize = _summary.FontSize = 12;
        var controls = new Grid { ColumnSpacing = 8, RowSpacing = 6 };
        controls.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        controls.ColumnDefinitions.Add(new() { Width = new GridLength(190) });
        controls.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        controls.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        controls.Children.Add(_search); Grid.SetColumn(_category, 1); controls.Children.Add(_category);
        var action = _clear; Grid.SetColumn(action, 2); controls.Children.Add(action);
        Grid.SetColumn(_settings, 3); controls.Children.Add(_settings);
        _settings.Click += (_, _) => ToggleCenter();
        AutomationProperties.SetName(_settings, "Atividades e Configurações de Programas");
        SetupCenter();
        controls.MinHeight = 46; controls.Padding = new Thickness(10, 6, 10, 6); controls.Background = ThemeService.Brush("#1e2c3e"); controls.BorderBrush = ThemeService.Brush("#394658"); controls.BorderThickness = new Thickness(0, 0, 0, 1);
        Grid.SetRow(controls, 0); _root.Children.Add(controls); _status.Visibility = Visibility.Collapsed; Grid.SetRow(_status, 1); _root.Children.Add(_status);
        _catalogScroll = new ScrollViewer { Content = _groups, VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
            HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0, 0, 3, 0) };
        Grid.SetRow(_catalogScroll, 2); _root.Children.Add(_catalogScroll);
        _catalogScroll.SizeChanged += (_, _) => RefreshLayoutIfNeeded();
        AutomationProperties.SetAutomationId(_search, "program-search"); AutomationProperties.SetAutomationId(_category, "program-category");
        AutomationProperties.SetAutomationId(_catalogScroll, "program-list"); AutomationProperties.SetAutomationId(_install, "program-install");
        var footer = new StackPanel { Spacing = 8 };
        footer.Children.Add(_summary);

            var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 6 };
            actions.Children.Add(_cancel); actions.Children.Add(_install); footer.Children.Add(actions);

        _footer = Ui.Card(footer, _p, 7); _footer.CornerRadius = new CornerRadius(0); _footer.BorderThickness = new Thickness(0, 1, 0, 0); _footer.Padding = new Thickness(10, 6, 10, 6); Grid.SetRow(_footer, 3); _root.Children.Add(_footer);
        AutomationProperties.SetName(_search, "Pesquisar aplicativos"); AutomationProperties.SetName(_category, "Filtrar categoria");
        AutomationProperties.SetLiveSetting(_status, Microsoft.UI.Xaml.Automation.Peers.AutomationLiveSetting.Polite);
        _search.TextChanged += (_, _) => Render(); _category.SelectionChanged += (_, _) => Render();
        _clear.Click += (_, _) => { _selected.Clear(); Render(); };
        _install.Click += async (_, _) => await InstallAsync(); _cancel.Click += (_, _) => _operation?.Cancel();
        Loaded += async (_, _) => { try { if (!_loaded) await RefreshAsync(); } catch (Exception ex) { RuntimeLog.Write("Programas/Loaded: " + ex); } };
        bool? compactLayout = null;
        void LayoutControls(double width)
        {
            var compact = width < 560;
            if (compactLayout != compact)
            {
                compactLayout = compact;
                controls.RowDefinitions.Clear(); controls.RowDefinitions.Add(new() { Height = GridLength.Auto }); controls.RowDefinitions.Add(new() { Height = GridLength.Auto });
                controls.ColumnDefinitions[0].Width = new GridLength(1, GridUnitType.Star);
                controls.ColumnDefinitions[1].Width = compact ? GridLength.Auto : new GridLength(190);
                Grid.SetColumnSpan(_search, compact ? 2 : 1);
                Grid.SetColumn(_category, compact ? 0 : 1); Grid.SetRow(_category, compact ? 1 : 0);
                Grid.SetColumn(action, 2); Grid.SetRow(action, compact ? 1 : 0);
                Grid.SetColumn(_settings, 3); Grid.SetRow(_settings, compact ? 1 : 0);
            }
            RefreshLayoutIfNeeded();
        }
        SizeChanged += (_, e) => LayoutControls(e.NewSize.Width); LayoutControls(0);

    }
    public void CancelOperations() => _operation?.Cancel();
    public void ShowActivity(string text) { _status.Text = text; _activity.Text = text; _status.Visibility = string.IsNullOrWhiteSpace(text) ? Visibility.Collapsed : Visibility.Visible; }
    private void RefreshLayoutIfNeeded()
    {
        var cols = Math.Max(1, Math.Min(8, (int)Math.Floor(Math.Max(190, _catalogScroll.ActualWidth - 10) / 190)));
        if (cols != _visibleRows) { _visibleRows = cols; Render(); }
    }
    private void ToggleCenter()
    {
        _central.Visibility = _central.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
        RefreshLayoutIfNeeded();
    }
    private void SetupCenter()
    {
        _central.Background = ThemeService.Brush("#172536");
        _central.BorderBrush = ThemeService.Brush("#394658"); _central.BorderThickness = new Thickness(1,0,0,0);
        var stack = new StackPanel { Spacing = 10, Padding = new Thickness(11) };
        var header = new Grid(); header.ColumnDefinitions.Add(new() { Width = new GridLength(1,GridUnitType.Star) });header.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        header.Children.Add(new TextBlock { Text = "Central de Programas", FontSize = 13, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
        var close = new Button { Content = "×", MinWidth = 26, MinHeight = 26, Padding = new Thickness(4) };
        close.Click += (_, _) => ToggleCenter(); Grid.SetColumn(close,1);header.Children.Add(close);stack.Children.Add(header);
        var tabs = new Grid { ColumnSpacing = 5 };tabs.ColumnDefinitions.Add(new(){Width=new GridLength(1,GridUnitType.Star)});tabs.ColumnDefinitions.Add(new(){Width=new GridLength(1,GridUnitType.Star)});
        var activities = new Button { Content = "Atividades", HorizontalAlignment = HorizontalAlignment.Stretch };
        var settings = new Button { Content = "Configurações", HorizontalAlignment = HorizontalAlignment.Stretch };
        tabs.Children.Add(activities);Grid.SetColumn(settings,1);tabs.Children.Add(settings);stack.Children.Add(tabs);
        var activityContent = new StackPanel { Spacing = 8 };activityContent.Children.Add(_activity);
        var settingsContent = new StackPanel { Spacing = 8, Visibility = Visibility.Collapsed };
        settingsContent.Children.Add(new TextBlock { Text = "Visualização compacta por colunas, ajustada à altura da janela.", TextWrapping = TextWrapping.Wrap, FontSize = 12 });
        settingsContent.Children.Add(new TextBlock { Text = "O catálogo e as permissões são obtidos da configuração central da plataforma.", TextWrapping = TextWrapping.Wrap, FontSize = 11 });
        activities.Click += (_,_) => { activityContent.Visibility=Visibility.Visible;settingsContent.Visibility=Visibility.Collapsed; };
        settings.Click += (_,_) => { activityContent.Visibility=Visibility.Collapsed;settingsContent.Visibility=Visibility.Visible; };
        stack.Children.Add(activityContent);stack.Children.Add(settingsContent);_central.Child = stack;
    }
    public void ApplyPalette(Palette p) { _p = p; Render(); }
    public async Task RefreshAsync()
    {
        if (_busy) return; using var notice = OperationProgress.Begin("Carregar catálogo de programas"); _busy = true;DataLoad.Begin("Carregando catálogo local de programas",preserveData:true); Render();
        try
        {
            _catalog = ProgramService.Catalog();
            var selected = _category.SelectedItem?.ToString(); _category.Items.Clear(); _category.Items.Add("Todas as categorias");
            foreach (var group in _catalog.Select(x => x.Category).Distinct()) _category.Items.Add(group);
            _category.SelectedItem = selected is not null && _category.Items.Contains(selected) ? selected : "Todas as categorias";
            _loaded = true;DataLoad.Complete(); notice.Succeed("Consulta local concluída");
        }
        catch (Exception ex) { notice.Fail(ex.Message);DataLoad.Fail(ex.Message); _status.Text = "Não foi possível carregar: " + ex.Message; }
        finally { _busy = false; Render(); }
    }
    private async Task PublishInventoryAsync()
    {
        if (!CanAct) return;
        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            await _runtime.Platform.PostAsync("gestor-program-local-inventory", _runtime.Platform.WithCredentials(("programs", ProgramService.InventoryPayload(_installed))), timeout.Token, 12);
        }
        catch (Exception ex) { _status.Text += "\nInventário local preservado; envio pendente: " + ex.Message; }
    }
    // O catálogo é distribuído em colunas que cabem na janela; só há rolagem vertical se necessário.
    private void Render()
    {
        _groups.Children.Clear(); _groups.ColumnDefinitions.Clear(); _groups.RowDefinitions.Clear();
        _selected.RemoveWhere(id => !_catalog.Any(app => app.Id == id));
        _status.Visibility = string.IsNullOrWhiteSpace(_status.Text) ? Visibility.Collapsed : Visibility.Visible;
        _footer.Background = _p.Surface; _footer.BorderBrush = _p.Line;
        _status.Foreground = _p.Text; _summary.Foreground = _p.Muted;
        _clear.IsEnabled = !_busy; _install.IsEnabled = !_busy && CanAct && _selected.Count is > 0 and <= 20;
        _cancel.IsEnabled = _busy && _operation is not null; _search.IsEnabled = _category.IsEnabled = !_busy;
        bool Matches(string value) => ProgramService.Normalize(value).Contains(ProgramService.Normalize(_search.Text));
        var entries = new List<(string Category, CatalogApplication App)>();
        foreach (var group in _catalog.Where(x => (_category.SelectedIndex <= 0 || x.Category == _category.SelectedItem?.ToString()) && Matches(x.Name + " " + x.Category)).GroupBy(x => x.Category))
            foreach (var app in group) entries.Add((group.Key, app));
        var width = Math.Max(190, _catalogScroll.ActualWidth - 10);
        var requested = Math.Max(1, Math.Min(8, (int)Math.Floor(width / 190)));
        var count = Math.Max(1, Math.Min(requested, entries.Count));
        var target = (int)Math.Ceiling((entries.Count + _catalog.Select(a => a.Category).Distinct().Count()) / (double)count);
        var columns = new List<StackPanel>();
        for (var i = 0; i < count; i++)
        {
            _groups.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
            var panel = new StackPanel { Spacing = 0, Padding = new Thickness(3, 3, 4, 2) };
            Grid.SetColumn(panel, i); _groups.Children.Add(panel); columns.Add(panel);
        }
        var currentColumn = 0; var usedRows = 0; string? currentCategory = null;
        foreach (var item in entries)
        {
            if (currentColumn < count - 1 && usedRows >= target)
            { currentColumn++; usedRows = 0; currentCategory = null; }
            if (currentCategory != item.Category)
            {
                var header = Ui.Label(item.Category, _p.Text, 11, true, true);
                header.Margin = new Thickness(0, 1, 0, 3); header.MinHeight = 17;
                columns[currentColumn].Children.Add(header); currentCategory = item.Category; usedRows++;
            }
            var line = new Grid { ColumnSpacing = 4, MinHeight = 27, VerticalAlignment = VerticalAlignment.Center };
            line.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
            line.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
            line.Children.Add(Logo(item.App));
            var name = Ui.Label(item.App.Name, _p.Text, 11, true, true);
            name.VerticalAlignment = VerticalAlignment.Center; Grid.SetColumn(name, 1); line.Children.Add(name);
            var box = new CheckBox { MinHeight = 27, MinWidth = 0, Padding = new Thickness(0), Content = line,
                IsChecked = _selected.Contains(item.App.Id), IsEnabled = !_busy,
                HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Stretch };
            AutomationProperties.SetName(box, "Selecionar " + item.App.Name);
            var appId = item.App.Id;
            box.Click += (_, _) =>
            {
                if (box.IsChecked == true && _selected.Count >= 20) { box.IsChecked = false; _status.Text = "Selecione no máximo 20 programas por operação."; return; }
                if (box.IsChecked == true) _selected.Add(appId); else _selected.Remove(appId);
                _summary.Text = _selected.Count + " de 20 selecionado(s) · " + _catalog.Count + " aplicativo(s) no catálogo · escolha manual";
                _install.IsEnabled = !_busy && CanAct && _selected.Count is > 0 and <= 20;
            };
            columns[currentColumn].Children.Add(box); usedRows++;
        }
        if (entries.Count == 0)
            columns[0].Children.Add(Ui.Label(_loaded ? "Nenhum aplicativo encontrado para este filtro." : "Carregando…", _p.Muted, 13, wrap: true));
        _summary.Text = _selected.Count + " de 20 selecionado(s) · " + _catalog.Count + " aplicativo(s) no catálogo · escolha manual";
    }
    private static FrameworkElement Logo(CatalogApplication app)
    {
        var tile = new Border { Width = 22, Height = 22, CornerRadius = new(4), Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent) };
        var path = ProgramService.LogoPath(app.Id);
        if (path.Length > 0) { tile.Background = new SolidColorBrush(Microsoft.UI.Colors.Transparent); tile.Padding = new(0); tile.Child = new Image { Stretch = Stretch.Uniform, Source = new BitmapImage(new Uri(path)) }; }
        else tile.Child = new TextBlock { Text = ProgramService.Initials(app.Name), Foreground = ThemeService.Brush("#ffffff"), HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center, FontWeight = Microsoft.UI.Text.FontWeights.Bold };
        return tile;
    }

    private async Task InstallAsync()
    {
        if (_busy || !CanAct || _selected.Count is < 1 or > 20) return;
        var apps = _catalog.Where(x => _selected.Contains(x.Id)).ToArray();
        if (!await _confirm("Instalar e Atualizar", "Aplicar somente aos programas selecionados?\n" + string.Join("\n", apps.Select(x => x.Name)), "Continuar") || !CanAct) return;
        _busy = true; _operation = new(); Render();
        var rows = new StackPanel { Spacing = 10 }; var states = new List<TextBlock>();
        var progress = new ProgressBar { Minimum = 0, Maximum = apps.Length }; rows.Children.Add(progress);
        foreach (var app in apps) { var line = new TextBlock { Text = app.Name + " — Aguardando", TextWrapping = TextWrapping.Wrap }; states.Add(line); rows.Children.Add(line); }
        var dialog = Ui.Dialog(XamlRoot, _p, "Instalação de programas", new ScrollViewer { Content = rows, MaxHeight = 420, VerticalScrollBarVisibility = ScrollBarVisibility.Hidden, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled }, "Cancelar operação", close: "Fechar", defaultButton: ContentDialogButton.Primary, note: "Acompanhe o andamento da instalação ou atualização dos programas selecionados.");
        var finished = false;
        dialog.PrimaryButtonClick += (_, e) => { e.Cancel = true; _operation?.Cancel(); };
        dialog.CloseButtonClick += (_, e) => e.Cancel = !finished;
        _ = dialog.ShowAsync();
        try
        {
            _installed = await Task.Run(WindowsInventory.Programs);
            for (var i = 0; i < apps.Length; i++)
            {
                _operation.Token.ThrowIfCancellationRequested();
                if (!CanAct) throw new UnauthorizedAccessException("Autorização suspensa; fila interrompida.");
                states[i].Text = apps[i].Name + " — Instalando ou atualizando…";
                try
                {
                    var code = await ProgramService.PackageAsync(apps[i].Id, ProgramService.Match(apps[i], _installed) is not null, _catalog, _operation.Token);
                    states[i].Text = apps[i].Name + " — Concluído" + (code == 0 ? "" : "; reinício necessário"); _selected.Remove(apps[i].Id);
                }
                catch (OperationCanceledException) { states[i].Text = apps[i].Name + " — Cancelado"; throw; }
                catch (Exception ex) { states[i].Text = apps[i].Name + " — Falha: " + ex.Message; }
                progress.Value = i + 1;
            }
            _status.Text = "Operação encerrada. Confira o resultado de cada aplicativo.";
        }
        catch (OperationCanceledException) { _status.Text = "Operação cancelada. Alterações já concluídas foram preservadas."; }
        catch (Exception ex) { _status.Text = ex.Message; }
        finally
        {
            finished = true; dialog.IsPrimaryButtonEnabled = false; _operation.Dispose(); _operation = null; _busy = false; Render();
            _installed = await Task.Run(WindowsInventory.Programs); await PublishInventoryAsync();
        }
    }
}
