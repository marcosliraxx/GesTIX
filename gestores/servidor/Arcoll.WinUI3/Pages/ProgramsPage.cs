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
    private readonly Grid _root = new() { RowSpacing = 6 };
    private readonly TextBox _search = new() { PlaceholderText = "Pesquisar aplicativos", HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly ComboBox _category = new() { HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly TextBlock _summary = new() { TextWrapping = TextWrapping.Wrap }, _status = new() { TextWrapping = TextWrapping.Wrap, IsTextSelectionEnabled = true };
    private readonly Grid _groups = new() { ColumnSpacing = 13, RowSpacing = 13 };
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
        _runtime = runtime; _confirm = confirm; Content = _root;
        for (var row = 0; row < 5; row++) _root.RowDefinitions.Add(new() { Height = row == 3 ? new GridLength(1, GridUnitType.Star) : GridLength.Auto });
        _root.Children.Add(new TextBlock { Text = "Catálogo de aplicativos", FontSize = 15, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
        foreach (var button in new[] { _install, _clear, _cancel }) { button.FontSize = 12; button.MinHeight = 38; button.Padding = new Thickness(14, 7, 14, 7); button.CornerRadius = new CornerRadius(11); }
        _search.MinHeight = _category.MinHeight = 38; _search.FontSize = _category.FontSize = 14;
        _status.FontSize = _summary.FontSize = 12;
        var controls = new Grid { ColumnSpacing = 8, RowSpacing = 6 };
        controls.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        controls.ColumnDefinitions.Add(new() { Width = new GridLength(190) });
        controls.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        controls.Children.Add(_search); Grid.SetColumn(_category, 1); controls.Children.Add(_category);
        var action = _clear; Grid.SetColumn(action, 2); controls.Children.Add(action);
        Grid.SetRow(controls, 1); _root.Children.Add(controls); Grid.SetRow(_status, 2); _root.Children.Add(_status);
        var list = new ScrollViewer { Content = _groups, VerticalScrollBarVisibility = ScrollBarVisibility.Hidden, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0, 0, 4, 0) };
        Grid.SetRow(list, 3); _root.Children.Add(list);
        AutomationProperties.SetAutomationId(_search, "program-search"); AutomationProperties.SetAutomationId(_category, "program-category");
        AutomationProperties.SetAutomationId(list, "program-list"); AutomationProperties.SetAutomationId(_install, "program-install");
        var footer = new StackPanel { Spacing = 8 };
        footer.Children.Add(_summary);

            var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 6 };
            actions.Children.Add(_cancel); actions.Children.Add(_install); footer.Children.Add(actions);

        _footer = Ui.Card(footer, _p, 13); Grid.SetRow(_footer, 4); _root.Children.Add(_footer);
        AutomationProperties.SetName(_search, "Pesquisar aplicativos"); AutomationProperties.SetName(_category, "Filtrar categoria");
        AutomationProperties.SetLiveSetting(_status, Microsoft.UI.Xaml.Automation.Peers.AutomationLiveSetting.Polite);
        _search.TextChanged += (_, _) => Render(); _category.SelectionChanged += (_, _) => Render();
        _clear.Click += (_, _) => { _selected.Clear(); Render(); };
        _install.Click += async (_, _) => await InstallAsync(); _cancel.Click += (_, _) => _operation?.Cancel();
        Loaded += async (_, _) => { try { if (!_loaded) await RefreshAsync(); } catch (Exception ex) { RuntimeLog.Write("Programas/Loaded: " + ex); } };
        bool? compactLayout = null; var previousColumns = 0;
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
            }
            var columns = CatalogColumns(width);
            if (previousColumns != columns) { previousColumns = columns; Render(); }
        }
        SizeChanged += (_, e) => LayoutControls(e.NewSize.Width); LayoutControls(0);

    }
    public void CancelOperations() => _operation?.Cancel();
    public void ShowActivity(string text) { _status.Text = text; _status.Visibility = string.IsNullOrWhiteSpace(text) ? Visibility.Collapsed : Visibility.Visible; }
    private int CatalogColumns(double width) => width >= 1080 ? 4 : width >= 790 ? 3 : width >= 510 ? 2 : 1;
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
    private void Render()
    {
        _groups.Children.Clear(); _groups.ColumnDefinitions.Clear(); _groups.RowDefinitions.Clear();
        var columns = CatalogColumns(ActualWidth);
        _selected.RemoveWhere(id => !_catalog.Any(app => app.Id == id));
        _status.Visibility = string.IsNullOrWhiteSpace(_status.Text) ? Visibility.Collapsed : Visibility.Visible;
        for (var i = 0; i < columns; i++) _groups.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        _footer.Background = _p.Surface; _footer.BorderBrush = _p.Line;
        _status.Foreground = _p.Text; _summary.Foreground = _p.Muted;
        _clear.IsEnabled = !_busy; _install.IsEnabled = !_busy && CanAct && _selected.Count is > 0 and <= 20;
        _cancel.IsEnabled = _busy && _operation is not null; _search.IsEnabled = _category.IsEnabled = !_busy;
        bool Matches(string value) => ProgramService.Normalize(value).Contains(ProgramService.Normalize(_search.Text));
        var cards = new List<UIElement>();

            foreach (var group in _catalog.Where(x => (_category.SelectedIndex <= 0 || x.Category == _category.SelectedItem?.ToString()) && Matches(x.Name + " " + x.Category)).GroupBy(x => x.Category))
            {
                var panel = new StackPanel { Spacing = 7 };
                var header = new Grid { ColumnSpacing = 10, Margin = new Thickness(0, 0, 0, 5) };
                header.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
                header.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
                header.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
                var categoryIcon = new Border { Width = 34, Height = 34, CornerRadius = new CornerRadius(10), Background = _p.Alt,
                    Child = new SymbolIcon(group.Key.ToLowerInvariant() switch {
                        var k when k.Contains("remoto") => Symbol.View,
                        var k when k.Contains("armaz") => Symbol.Folder,
                        var k when k.Contains("desenvolv") => Symbol.Setting,
                        var k when k.Contains("document") => Symbol.Library,
                        var k when k.Contains("imagem") => Symbol.Library,
                        _ => Symbol.Library
                    }) { Foreground = _p.Accent, Width = 20, Height = 20 } };
                header.Children.Add(categoryIcon);
                var label = Ui.Label(group.Key, _p.Text, 14, true, true); label.VerticalAlignment = VerticalAlignment.Center;
                Grid.SetColumn(label, 1); header.Children.Add(label);
                var count = Ui.Label(group.Count().ToString(), _p.Muted, 11, true);
                count.VerticalAlignment = VerticalAlignment.Center;
                Grid.SetColumn(count, 2); header.Children.Add(count);
                panel.Children.Add(header);
                foreach (var app in group)
                {
                    var row = new Grid { ColumnSpacing = 6 }; row.ColumnDefinitions.Add(new() { Width = GridLength.Auto }); row.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
                    row.Children.Add(Logo(app)); var name = Ui.Label(app.Name, _p.Text, 14, true, true); Grid.SetColumn(name, 1); row.Children.Add(name);
                    var box = new CheckBox { MinHeight = 30, MinWidth = 0, Padding = new Thickness(0), Content = row, IsChecked = _selected.Contains(app.Id), IsEnabled = !_busy, HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Stretch };
                    AutomationProperties.SetName(box, "Selecionar " + app.Name);
                    box.Click += (_, _) =>
                    {
                        if (box.IsChecked == true && _selected.Count >= 20) { box.IsChecked = false; _status.Text = "Selecione no máximo 20 programas por operação."; return; }
                        if (box.IsChecked == true) _selected.Add(app.Id); else _selected.Remove(app.Id);
                        _summary.Text = _selected.Count + " de 20 selecionado(s) · " + _catalog.Count + " aplicativo(s) no catálogo · escolha manual";
                        _install.IsEnabled = !_busy && CanAct && _selected.Count is > 0 and <= 20;
                    };
                    panel.Children.Add(box);
                }
                cards.Add(Ui.Card(panel, _p, 14));
            }
            _summary.Text = _selected.Count + " de 20 selecionado(s) · " + _catalog.Count + " aplicativo(s) no catálogo · escolha manual";

        if (cards.Count == 0) cards.Add(Ui.Label(_loaded ? "Nenhum aplicativo encontrado para este filtro." : "Carregando…", _p.Muted, 14, wrap: true));
        for (var i = 0; i < cards.Count; i++)
        {
            if (i % columns == 0) _groups.RowDefinitions.Add(new() { Height = GridLength.Auto });
            Grid.SetColumn((FrameworkElement)cards[i], i % columns); Grid.SetRow((FrameworkElement)cards[i], i / columns); _groups.Children.Add(cards[i]);
        }
    }
    private static FrameworkElement Logo(CatalogApplication app)
    {
        var tile = new Border { Width = 22, Height = 22, CornerRadius = new(4), Background = ThemeService.Brush(ProgramService.LogoColor(app.Id)) };
        var path = ProgramService.LogoPath(app.Id);
        if (path.Length > 0) { tile.Background = ThemeService.Brush("#f4f7fb"); tile.Padding = new(3); tile.Child = new Image { Stretch = Stretch.Uniform, Source = new BitmapImage(new Uri(path)) }; }
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
