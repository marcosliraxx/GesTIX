// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/DevicesPage.cs
using System.Text;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Dispositivos: mesma estrutura de redes/dispositivos.php (busca, filtros, ordenação, seleção em massa, tabela, exportação e identificação).</summary>
public sealed class DevicesPage : UserControl
{
    private static readonly double[] Widths = { 38, 46, 230, 130, 150, 130, 130, 120, 130, 140 };
    private static readonly (string Key, string Title)[] Columns =
    {
        ("", ""), ("number", "Nº"), ("name", "Dispositivo"), ("ip", "Endereço IP"), ("mac", "MAC"), ("type", "Tipo"), ("group", "Grupo"), ("status", "Situação"), ("duration", "Há quanto tempo"), ("seen", "Última atividade"),
    };

    private readonly AgentRuntime _runtime;
    private readonly Action<string> _notify;
    private Palette _p = new();
    private IReadOnlyList<DeviceRecord> _all = Array.Empty<DeviceRecord>();
    private readonly HashSet<long> _selected = new();
    private string _search = "", _status = "all", _type = "all", _group = "all", _sortKey = "name"; private int _sortDir = 1;
    private bool _busy, _loaded;
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(20) };
    private readonly StackPanel _root = new() { Spacing = 12 };
    private readonly Border _tableHost = new(), _bulkHost = new();
    private readonly TextBlock _summary = new(), _feedback = new();
    private readonly Button _scan = new() { Content = "Atualizar varredura" };

    public DevicesPage(AgentRuntime runtime, Action<string> notify)
    {
        _runtime = runtime; _notify = notify;
        var search = new TextBox { PlaceholderText = "Buscar dispositivo", MinWidth = 0, HorizontalAlignment = HorizontalAlignment.Stretch };
        search.TextChanged += (_, _) => { _search = search.Text.Trim(); RenderTable(); };
        var filters = new Button { Content = "Filtros" }; filters.Click += async (_, _) => await FiltersAsync();
        var export = new Button { Content = "Baixar lista" }; export.Click += async (_, _) => await ExportAsync();
        _scan.Click += async (_, _) => await ScanAsync();
        var bar = new Grid { ColumnSpacing = 8 };
        bar.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < 3; i++) bar.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
        bar.Children.Add(search); Grid.SetColumn(filters, 1); bar.Children.Add(filters); Grid.SetColumn(export, 2); bar.Children.Add(export); Grid.SetColumn(_scan, 3); bar.Children.Add(_scan);
        bar.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); bar.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
        SizeChanged += (_, e) => {
            var compact = e.NewSize.Width < 700;
            Grid.SetColumnSpan(search, compact ? 4 : 1);
            Grid.SetRow(filters, compact ? 1 : 0); Grid.SetRow(export, compact ? 1 : 0); Grid.SetRow(_scan, compact ? 1 : 0);
            Grid.SetColumn(filters, compact ? 0 : 1); Grid.SetColumn(export, compact ? 1 : 2); Grid.SetColumn(_scan, compact ? 2 : 3);
        };
        _root.Children.Add(bar); _root.Children.Add(_summary); _root.Children.Add(_bulkHost); _root.Children.Add(_tableHost); _root.Children.Add(_feedback);
        Content = _root;
        _timer.Tick += async (_, _) => await LoadAsync();
        Loaded += async (_, _) => { _timer.Start(); await LoadAsync(); };
        Unloaded += (_, _) => _timer.Stop();
        Render();
    }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private void Say(string text) { _feedback.Text = text; _feedback.Foreground = _p.Muted; _notify(text); }

    private async Task LoadAsync()
    {
        if (_busy || !_runtime.Allowed) return; _busy = true;
        try
        {
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            _all = await InventoryService.LoadAsync(_runtime.Platform, cancel.Token); _loaded = true;
            _selected.IntersectWith(_all.Select(d => d.Id)); Render();
        }
        catch (Exception ex) { _feedback.Text = "Não foi possível consultar o inventário: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); _feedback.Foreground = _p.Warning; }
        finally { _busy = false; }
    }

    private async Task ScanAsync()
    {
        if (_busy) return; _scan.IsEnabled = false; _feedback.Text = "Procurando dispositivos na rede…"; _feedback.Foreground = _p.Muted;
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(120)); var n = await _runtime.ScanNowAsync(cancel.Token); _feedback.Text = $"{n} dispositivos sincronizados com a plataforma."; }
        catch (Exception ex) { _feedback.Text = "Falha na varredura: " + ex.Message; _feedback.Foreground = _p.Warning; }
        finally { _scan.IsEnabled = true; }
        await LoadAsync();
    }

    private IEnumerable<DeviceRecord> Filtered()
    {
        var q = _all.Where(d =>
            (_status == "all" || (_status == "online" && d.Online) || (_status == "offline" && !d.Online) || (_status == "pinned" && d.Pinned)) &&
            (_type == "all" || d.Type == _type) && (_group == "all" || d.Group == _group) &&
            (_search.Length == 0 || $"{d.Name} {d.Ip} {d.Mac} {d.Group} {InventoryService.TypeLabel(d.Type)}".Contains(_search, StringComparison.CurrentCultureIgnoreCase)));
        Func<DeviceRecord, object> key = _sortKey switch
        {
            "ip" => d => IpKey(d.Ip), "mac" => d => d.Mac, "type" => d => InventoryService.TypeLabel(d.Type), "group" => d => d.Group.ToLowerInvariant(),
            "status" => d => d.Online ? 0 : 1, "duration" => d => d.LastChange, "seen" => d => d.LastSeen, _ => d => d.Name.ToLowerInvariant(),
        };
        var ordered = _sortDir > 0 ? q.OrderByDescending(d => d.Pinned).ThenBy(key) : q.OrderByDescending(d => d.Pinned).ThenByDescending(key);
        return ordered.ToList();
    }

    private static string IpKey(string ip) => string.Join(".", ip.Split('.').Select(x => int.TryParse(x, out var n) ? n.ToString("D3") : x));

    private void Render() { RenderTable(); }

    private void RenderTable()
    {
        var rows = Filtered().ToList();
        _summary.Text = _loaded ? $"{rows.Count} de {_all.Count} dispositivos · {_all.Count(d => d.Online)} conectados" : "Consultando inventário da plataforma…";
        _summary.Foreground = _p.Muted; _summary.FontSize = 12; _feedback.Foreground = _p.Muted; _feedback.FontSize = 12;
        RenderBulk(rows);
        if (_loaded && _all.Count == 0)
        {
            var empty = new StackPanel { Spacing = 6, HorizontalAlignment = HorizontalAlignment.Center, Padding = new Thickness(0, 30, 0, 30) };
            empty.Children.Add(Ui.Label("Nenhum dispositivo inventariado", _p.Text, 16, true)); empty.Children.Add(Ui.Label("Use “Atualizar varredura” para procurar equipamentos na rede.", _p.Muted, 12));
            _tableHost.Child = Ui.Card(empty, _p); return;
        }
        var table = new StackPanel { Spacing = 0 };
        var head = RowGrid(); 
        for (var c = 0; c < Columns.Length; c++)
        {
            if (c == 0)
            {
                var all = new CheckBox { MinWidth = 0, IsChecked = rows.Count > 0 && rows.All(r => _selected.Contains(r.Id)) };
                all.Click += (_, _) => { foreach (var r in rows) { if (all.IsChecked == true) _selected.Add(r.Id); else _selected.Remove(r.Id); } RenderTable(); };
                head.Children.Add(all); continue;
            }
            var key = Columns[c].Key; var arrow = key == _sortKey ? (_sortDir > 0 ? " ▲" : " ▼") : "";
            var label = Ui.Label(Columns[c].Title.ToUpperInvariant() + arrow, _p.Muted, 10, true); label.Margin = new Thickness(0, 8, 0, 8);
            label.Tapped += (_, _) => { if (key == "number") { _sortKey = "name"; _sortDir = 1; } else if (_sortKey == key) _sortDir = -_sortDir; else { _sortKey = key; _sortDir = 1; } RenderTable(); };
            Grid.SetColumn(label, c); head.Children.Add(label);
        }
        table.Children.Add(head);
        var n = 0;
        foreach (var d in rows)
        {
            n++; var row = RowGrid(); var device = d;
            var check = new CheckBox { MinWidth = 0, IsChecked = _selected.Contains(d.Id) };
            check.Tapped += (_, e) => e.Handled = true;
            check.Click += (_, _) => { if (check.IsChecked == true) _selected.Add(device.Id); else _selected.Remove(device.Id); RenderBulk(Filtered().ToList()); };
            row.Children.Add(check);
            var glyph = new FontIcon { Glyph = DeviceEditor.Glyph(d.Type), FontFamily = new Microsoft.UI.Xaml.Media.FontFamily(DeviceEditor.IconFont), FontSize = 15, Foreground = d.Online ? _p.Accent : _p.Muted };
            var nameCell = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8, VerticalAlignment = VerticalAlignment.Center };
            nameCell.Children.Add(glyph);
            if (d.Pinned) nameCell.Children.Add(new FontIcon { Glyph = "\uE718", FontFamily = new Microsoft.UI.Xaml.Media.FontFamily(DeviceEditor.IconFont), FontSize = 11, Foreground = _p.Warning });
            nameCell.Children.Add(Ui.Label(d.Name, _p.Text, 13, true));
            var cells = new UIElement[]
            {
                Ui.Label(n.ToString(), _p.Muted, 12), nameCell, Ui.Label(d.Ip, _p.Text, 12), Ui.Label(d.Mac, _p.Muted, 12), Ui.Label(InventoryService.TypeLabel(d.Type), _p.Text, 12),
                Ui.Label(d.Group.Length > 0 ? d.Group : "—", _p.Text, 12), Ui.Label(d.Online ? "● Conectado" : "● Sem resposta", d.Online ? _p.Success : _p.Danger, 12, true),
                Ui.Label(DeviceEditor.When(d.LastChange), _p.Muted, 12), Ui.Label(DeviceEditor.When(d.LastSeen), _p.Muted, 12),
            };
            for (var c = 0; c < cells.Length; c++) { var el = (FrameworkElement)cells[c]; el.VerticalAlignment = VerticalAlignment.Center; Grid.SetColumn(el, c + 1); row.Children.Add(el); }
            var host = new Border { BorderBrush = _p.Line, BorderThickness = new Thickness(0, 1, 0, 0), Child = row, Background = _p.Surface };
            host.Tapped += async (_, _) => { if (await DeviceEditor.ShowAsync(XamlRoot, device, _p, _runtime, Say) != EditorResult.Cancelled) await LoadAsync(); };
            table.Children.Add(host);
        }
        if (_loaded && rows.Count == 0) table.Children.Add(new Border { Padding = new Thickness(12), Child = Ui.Label("Nenhum dispositivo corresponde aos filtros selecionados.", _p.Muted, 12) });
        _tableHost.Child = Ui.Card(new ScrollViewer { Content = table, HorizontalScrollBarVisibility = ScrollBarVisibility.Auto, VerticalScrollBarVisibility = ScrollBarVisibility.Disabled, HorizontalScrollMode = ScrollMode.Auto }, _p, 6);
    }

    private static Grid RowGrid()
    {
        var g = new Grid { MinHeight = 40 };
        foreach (var w in Widths) g.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(w) });
        return g;
    }

    private void RenderBulk(List<DeviceRecord> visible)
    {
        if (_selected.Count == 0) { _bulkHost.Child = null; return; }
        var action = new ComboBox { PlaceholderText = "Escolher ação…", MinWidth = 170 };
        foreach (var (k, t) in new[] { ("pin", "Fixar"), ("unpin", "Desafixar"), ("group", "Alterar grupo"), ("hide", "Excluir da lista") }) action.Items.Add(new ComboBoxItem { Content = t, Tag = k });
        var group = new TextBox { PlaceholderText = "Novo grupo", MaxLength = 90, Visibility = Visibility.Collapsed, MinWidth = 160 };
        action.SelectionChanged += (_, _) => group.Visibility = (action.SelectedItem as ComboBoxItem)?.Tag?.ToString() == "group" ? Visibility.Visible : Visibility.Collapsed;
        var apply = new Button { Content = "Aplicar aos selecionados" }; var clear = new Button { Content = "Limpar seleção" };
        clear.Click += (_, _) => { _selected.Clear(); RenderTable(); };
        apply.Click += async (_, _) =>
        {
            var key = (action.SelectedItem as ComboBoxItem)?.Tag?.ToString();
            if (key is null) { Say("Selecione uma ação em massa."); return; }
            if (key == "group" && group.Text.Trim().Length == 0) { Say("Informe o novo grupo."); return; }
            if (key == "hide")
            {
                var ask = new ContentDialog { XamlRoot = XamlRoot, Title = "Excluir da lista", Content = $"Excluir {_selected.Count} dispositivos da lista? Eles podem continuar presentes na rede.", PrimaryButtonText = "Excluir", CloseButtonText = "Cancelar" };
                if (await ask.ShowAsync() != ContentDialogResult.Primary) return;
            }
            apply.IsEnabled = false; var done = 0; var failure = "";
            try
            {
                foreach (var d in _all.Where(x => _selected.Contains(x.Id)).ToList())
                {
                    var copy = new DeviceRecord { Id = d.Id, CustomName = d.CustomName, CustomType = d.CustomType, Group = key == "group" ? group.Text.Trim() : d.Group, Pinned = key == "pin" ? true : key == "unpin" ? false : d.Pinned };
                    using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                    await InventoryService.SaveAsync(_runtime.Platform, copy, key == "hide", cancel.Token); done++;
                }
            }
            catch (Exception ex) { failure = ex.Message; }
            Say(failure.Length > 0 ? $"{done} atualizados; falha: {failure}" : $"{done} dispositivos atualizados.");
            _selected.Clear(); await LoadAsync();
        };
        var bar = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        bar.Children.Add(Ui.Label($"{_selected.Count} selecionados", _p.Text, 13, true)); bar.Children.Add(action); bar.Children.Add(group); bar.Children.Add(apply); bar.Children.Add(clear);
        foreach (var c in bar.Children) if (c is FrameworkElement fe) fe.VerticalAlignment = VerticalAlignment.Center;
        _bulkHost.Child = Ui.Card(bar, _p, 10);
    }

    private async Task FiltersAsync()
    {
        ComboBox Combo(string header, IEnumerable<(string Key, string Label)> items, string current)
        {
            var c = new ComboBox { Header = header, HorizontalAlignment = HorizontalAlignment.Stretch };
            foreach (var (k, l) in items) c.Items.Add(new ComboBoxItem { Content = l, Tag = k });
            c.SelectedIndex = Math.Max(0, items.ToList().FindIndex(i => i.Key == current)); return c;
        }
        var status = Combo("Situação", new[] { ("all", "Todas as situações"), ("online", "Conectados"), ("offline", "Sem resposta"), ("pinned", "Fixados") }, _status);
        var types = Combo("Tipo", new[] { ("all", "Todos os tipos") }.Concat(_all.Select(d => d.Type).Distinct().OrderBy(InventoryService.TypeLabel).Select(t => (t, InventoryService.TypeLabel(t)))), _type);
        var groups = Combo("Grupo", new[] { ("all", "Todos os grupos") }.Concat(_all.Select(d => d.Group).Where(g => g.Length > 0).Distinct().OrderBy(g => g).Select(g => (g, g))), _group);
        var sort = Combo("Organizar", new[] { ("name:1", "Nome: A a Z"), ("name:-1", "Nome: Z a A"), ("ip:1", "Endereço da rede"), ("status:1", "Situação"), ("group:1", "Grupo"), ("seen:-1", "Mais recentes") }, $"{_sortKey}:{_sortDir}");
        var body = new StackPanel { Spacing = 10, MinWidth = 340 }; foreach (var c in new UIElement[] { status, types, groups, sort }) body.Children.Add(c);
        var dialog = new ContentDialog { XamlRoot = XamlRoot, Title = "Filtros", Content = body, PrimaryButtonText = "Aplicar", SecondaryButtonText = "Limpar", CloseButtonText = "Cancelar", DefaultButton = ContentDialogButton.Primary };
        var r = await dialog.ShowAsync();
        if (r == ContentDialogResult.Secondary) { _status = _type = _group = "all"; _sortKey = "name"; _sortDir = 1; }
        else if (r == ContentDialogResult.Primary)
        {
            string Tag(ComboBox c) => (c.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "all";
            _status = Tag(status); _type = Tag(types); _group = Tag(groups);
            var parts = Tag(sort).Split(':'); if (parts.Length == 2) { _sortKey = parts[0]; _sortDir = parts[1] == "-1" ? -1 : 1; }
        }
        RenderTable();
    }

    /// <summary>CSV com separador “;”, BOM UTF-8 e proteção contra fórmulas (mesmo formato do painel web).</summary>
    private async Task ExportAsync()
    {
        var rows = Filtered().ToList();
        if (rows.Count == 0) { Say("Nenhum dispositivo para baixar."); return; }
        static string Safe(string v) { v ??= ""; var t = v.TrimStart(); if (t.Length > 0 && "=+@-".IndexOf(t[0]) >= 0) v = "'" + v; return "\"" + v.Replace("\"", "\"\"") + "\""; }
        var csv = new StringBuilder("Nome;IP;MAC;Tipo;Grupo;Situação\r\n");
        foreach (var d in rows) csv.Append(string.Join(';', new[] { d.Name, d.Ip, d.Mac, InventoryService.TypeLabel(d.Type), d.Group, d.Online ? "Conectado" : "Sem resposta" }.Select(Safe))).Append("\r\n");
        try
        {
            var folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "TI-Digital"); Directory.CreateDirectory(folder);
            var path = Path.Combine(folder, $"dispositivos-{DateTime.Now:yyyyMMdd-HHmmss}.csv");
            await File.WriteAllTextAsync(path, csv.ToString(), new UTF8Encoding(true));
            Say("Lista salva em " + path);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { Say("Não foi possível salvar a lista: " + ex.Message); }
    }
}
