// Caminho: agentes/windows/Arcoll.WinUI3/Pages/CleanPage.cs
using System.Diagnostics;
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;

namespace Arcoll.WinUI3.Pages;

/// <summary>Clean nativo: mesmos catálogos, seções, relatórios e motor de execução da plataforma.</summary>
public sealed class CleanPage : UserControl
{
    private static readonly (string Key, string Label)[] Sections = {
        ("health","Health Check"),("custom","Limpeza personalizada"),("uninstall","Desinstalador"),
        ("duplicates","Localizador de arquivos duplicados"),("cloud","Limpador de Disco na Nuvem"),("backup","Backup em Nuvem"),
        ("performance","Otimização de Desempenho"),("startup","Gerenciador de inicialização"),("updater","Atualizador de Software"),
        ("drivers","Driver Updater"),("disk","Analisador de disco"),("restore","Restauração do sistema"),("windows_update","Windows Update"),("options","Configurações") };
    private readonly AgentRuntime _runtime;
    private readonly Func<string,string,string,Task<bool>> _confirm;
    private readonly Grid _root = new() { ColumnSpacing = 16, RowSpacing = 12 };
    private readonly StackPanel _nav = new() { Spacing = 4 }, _view = new() { Spacing = 12 };
    private readonly ComboBox _compactNav = new() { HorizontalAlignment = HorizontalAlignment.Stretch };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap, IsTextSelectionEnabled = true };
    private readonly ProgramsPage _uninstall;
    private readonly WindowsUpdatePage _windowsUpdate;
    private readonly Dictionary<string, JsonElement> _reports = new();
    private readonly HashSet<string> _categories = new(), _items = new();
    private JsonElement _config, _settings;
    private string _section = "health", _tab = "junk", _scanSignature = "", _drive = "C:", _startupTab = "run", _perfTab = "active", _driverTab = "outdated";
    private int _age = 7;
    private Palette _p = new();
    private bool _busy, _initialized;
    private CancellationTokenSource? _operation;

    public CleanPage(AgentRuntime runtime, Func<string,string,string,Task<bool>> confirm)
    {
        _runtime = runtime; _confirm = confirm; _uninstall = new(runtime, confirm, true); _windowsUpdate = new(runtime); Content = _root;
        _root.ColumnDefinitions.Add(new() { Width = new GridLength(252) }); _root.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        _root.RowDefinitions.Add(new() { Height = GridLength.Auto }); _root.RowDefinitions.Add(new() { Height = GridLength.Auto });
        Grid.SetColumnSpan(_compactNav, 2); _root.Children.Add(_compactNav);
        var sidebar = new ScrollViewer { Content = _nav, MaxHeight = 740, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled };
        Grid.SetRow(sidebar, 1); _root.Children.Add(sidebar);
        Grid.SetColumn(_view, 1); Grid.SetRow(_view, 1); _root.Children.Add(_view);
        foreach (var section in Sections)
        {
            _compactNav.Items.Add(section.Label);
            var button = new Button { Content = Ui.Label(section.Label, _p.Text, 14, wrap: true), HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Left, Padding = new Thickness(10) };
            button.Click += (_, _) => Navigate(section.Key); _nav.Children.Add(button);
        }
        _compactNav.SelectedIndex = 0; _compactNav.SelectionChanged += (_, _) => { if (_compactNav.SelectedIndex >= 0) Navigate(Sections[_compactNav.SelectedIndex].Key); };
        AutomationProperties.SetName(_compactNav, "Seção do Clean");
        SizeChanged += (_, e) =>
        {
            var compact = e.NewSize.Width < 850; _compactNav.Visibility = compact ? Visibility.Visible : Visibility.Collapsed;
            sidebar.Visibility = compact ? Visibility.Collapsed : Visibility.Visible; _root.ColumnDefinitions[0].Width = new GridLength(compact ? 0 : 252);
        };
        Loaded += (_, _) => Initialize();
    }
    private void Initialize()
    {
        if (_initialized) return;
        try
        {
            _config = CleanEngine.Catalog();
            foreach (var item in _config.GetProperty("catalog").EnumerateObject()) if (Get(item.Value, "default") == "True" || Get(item.Value, "default") == "true") _categories.Add(item.Name);
            _initialized = true;
        }
        catch (Exception ex) { _status.Text = ex.Message; }
        Render();
    }
    private void Navigate(string section)
    {
        if (_busy) return; _section = section; _items.Clear(); Render();
    }
    public void CancelOperations() => _operation?.Cancel();
    public void ApplyPalette(Palette p)
    {
        _p = p; _uninstall.ApplyPalette(p); _windowsUpdate.ApplyPalette(p);
        foreach (var button in _nav.Children.OfType<Button>()) if (button.Content is TextBlock text) text.Foreground = p.Text;
        Render();
    }
    private Button Action(string title, Func<Task> action, bool enabled = true)
    {
        var button = new Button { Content = title, IsEnabled = !_busy && _runtime.Allowed && enabled };
        button.Click += async (_, _) => { try { await action(); } catch (Exception ex) { _status.Text = ex.Message; } };
        return button;
    }
    private static string Get(JsonElement parent, string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key, out var value) ? value.ToString() : "";
    private static IEnumerable<JsonElement> Rows(JsonElement parent, string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key, out var value) && value.ValueKind == JsonValueKind.Array ? value.EnumerateArray().ToArray() : Array.Empty<JsonElement>();
    private JsonElement Report(string key) => _reports.GetValueOrDefault(key);
    private void Text(string value, bool heading = false) => _view.Children.Add(Ui.Label(value, heading ? _p.Text : _p.Muted, heading ? 18 : 14, heading, true));
    private void Actions(params Button[] buttons)
    {
        var row = new StackPanel { Spacing = 8 };
        foreach (var button in buttons) row.Children.Add(button);
        _view.Children.Add(row);
    }
    private void Tabs((string Key, string Label)[] tabs, string selected, Action<string> change)
    {
        var combo = new ComboBox { HorizontalAlignment = HorizontalAlignment.Stretch, IsEnabled = !_busy };
        foreach (var tab in tabs) combo.Items.Add(tab.Label);
        combo.SelectedIndex = Array.FindIndex(tabs, x => x.Key == selected);
        combo.SelectionChanged += (_, _) => { if (combo.SelectedIndex >= 0) { change(tabs[combo.SelectedIndex].Key); _items.Clear(); Render(); } };
        _view.Children.Add(combo);
    }
    private void Render()
    {
        _view.Children.Clear();
        Text(Sections.First(x => x.Key == _section).Label, true); _status.Foreground = _p.Text; _view.Children.Add(_status);
        foreach (var button in _nav.Children.OfType<Button>()) button.IsEnabled = !_busy;
        _compactNav.IsEnabled = !_busy;
        if (_busy)
        {
            _view.Children.Add(new ProgressBar { IsIndeterminate = true });
            var cancel = new Button { Content = "Cancelar operação" }; cancel.Click += (_, _) => _operation?.Cancel(); _view.Children.Add(cancel); return;
        }
        if (!_initialized) { Text("O catálogo Clean não foi carregado."); return; }
        switch (_section)
        {
            case "custom": Custom(); break;
            case "health": Health(); break;
            case "uninstall": _view.Children.Add(_uninstall); break;
            case "windows_update": _view.Children.Add(_windowsUpdate); break;
            case "options": Options(); break;
            case "backup": Backup(); break;
            default: Tool(); break;
        }
    }
    private async Task LoadSettingsAsync()
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(15));
        var response = await _runtime.Platform.PostAsync("agent-clean-local-settings", _runtime.Platform.WithCredentials(), timeout.Token, 12);
        _settings = response.GetProperty("settings").Clone();
    }
    private string[] Setting(string key) => Rows(_settings, key).Select(x => x.GetString() ?? "").ToArray();
    private Dictionary<string, object?> CleanPayload() => new() { ["categories"] = _categories.OrderBy(x => x).ToArray(), ["age_days"] = _age, ["exclusions"] = Setting("exclusions"), ["custom_folders"] = Setting("custom_folders") };
    private async Task RunAsync(string mode, Dictionary<string, object?> payload, string key)
    {
        if (_busy || !_runtime.Allowed) return;
        _busy = true; _operation = new(); _status.Text = "Preparando operação…"; Render();
        try
        {
            var progress = new Progress<string>(message => _status.Text = message);
            var report = await CleanEngine.RunAsync(mode, payload, _runtime.Platform.Config, progress, _operation.Token);
            _reports[key] = report; _items.Clear();
            if (mode == "scan") _scanSignature = JsonSerializer.Serialize(payload);
            else if (mode == "execute") _scanSignature = "";
            _status.Text = "Operação concluída.";
            if (Get(report, "errors") is string errors && errors.Length > 0) _status.Text += " Falhas/itens preservados: " + errors + ".";
            if (Get(report, "failed") is string failures && failures.Length > 0) _status.Text += " Itens não alterados: " + failures + ".";
            if (mode.StartsWith("backup", StringComparison.Ordinal)) return;
            try
            {
                await _runtime.Platform.PostAsync("agent-clean-local-result", _runtime.Platform.WithCredentials(("mode", mode), ("tool", key), ("report", report)), _operation.Token, 40);
            }
            catch (Exception ex) { _status.Text += " Relatório local preservado; envio pendente: " + ex.Message; }
        }
        catch (OperationCanceledException) { _status.Text = "Operação cancelada. Alterações concluídas foram preservadas."; }
        catch (Exception ex) { _status.Text = "Falha: " + ex.Message; }
        finally { _operation.Dispose(); _operation = null; _busy = false; Render(); }
    }
    private Task RunToolAsync(string tool, string action, params (string Key, object? Value)[] args)
    {
        var body = new Dictionary<string, object?> { ["tool"] = tool, ["action"] = action };
        foreach (var (key, value) in args) body[key] = value;
        return RunAsync("tool", body, tool);
    }
    private void Health()
    {
        Text("Analisa espaço em disco, privacidade, inicialização e registro. Nada é excluído durante a verificação.");
        Actions(Action("Verificar agora", () => RunToolAsync("health", "scan", ("space", Rows(_config.GetProperty("health"), "space").Select(x => x.GetString()).ToArray()), ("privacy", Rows(_config.GetProperty("health"), "privacy").Select(x => x.GetString()).ToArray()))));
        var report = Report("health"); if (report.ValueKind != JsonValueKind.Object) return;
        foreach (var (key, title) in new[] { ("privacy", "Mais privacidade"), ("space", "Mais espaço") })
        {
            var part = report.GetProperty(key); Text(title, true);
            Text(Get(part, "file_count") + " itens · " + Ui.Size(part.GetProperty("bytes").GetDouble()));
            Actions(Action("Revisar " + title.ToLowerInvariant(), () =>
            {
                _categories.Clear(); foreach (var item in part.GetProperty("categories").EnumerateObject()) _categories.Add(item.Name);
                _age = key == "privacy" ? 0 : 7; _section = "custom"; _tab = key == "privacy" ? "browser" : "junk"; _scanSignature = ""; Render(); return Task.CompletedTask;
            }));
        }
        Text("Resolver problemas", true);
        Text(Get(report.GetProperty("registry"), "issues") + " problemas de registro · " + Get(report.GetProperty("speed"), "startup_enabled") + " programas na inicialização.");
        Actions(Action("Revisar registro", () => { _section = "custom"; _tab = "registry"; Render(); return Task.CompletedTask; }), Action("Gerenciar inicialização", () => { Navigate("startup"); return Task.CompletedTask; }));
    }
    private void Custom()
    {
        Tabs(new[] { ("junk", "Lixo"), ("browser", "Navegador"), ("registry", "Registro") }, _tab, value => _tab = value);
        if (_tab == "registry") { Registry(); return; }
        var ages = new ComboBox { IsEnabled = !_busy }; foreach (var age in new[] { 0, 1, 7, 30 }) ages.Items.Add(age == 0 ? "Qualquer idade" : "Mais de " + age + " dia(s)");
        ages.SelectedIndex = Array.IndexOf(new[] { 0, 1, 7, 30 }, _age); ages.SelectionChanged += (_, _) => { _age = new[] { 0, 1, 7, 30 }[ages.SelectedIndex]; _scanSignature = ""; }; _view.Children.Add(ages);
        var catalog = _config.GetProperty("catalog").EnumerateObject().Where(x => Get(x.Value, "tab") == _tab || (_tab == "browser" && Get(x.Value, "tab") == "apps") || (_tab == "junk" && Get(x.Value, "tab") == "windows"));
        var list = new StackPanel { Spacing = 8 }; var report = Report("clean");
        foreach (var group in catalog.GroupBy(x => Get(x.Value, "group")))
        {
            list.Children.Add(Ui.Label(group.Key, _p.Text, 16, true));
            foreach (var item in group)
            {
                var box = new CheckBox { Content = Ui.Label(Get(item.Value, "label"), _p.Text, 14, wrap: true), IsChecked = _categories.Contains(item.Name) };
                ToolTipService.SetToolTip(box, Get(item.Value, "hint"));
                box.Click += (_, _) => { if (box.IsChecked == true) _categories.Add(item.Name); else _categories.Remove(item.Name); _scanSignature = ""; };
                list.Children.Add(box);
                if (report.ValueKind == JsonValueKind.Object && report.TryGetProperty("categories", out var parts) && parts.TryGetProperty(item.Name, out var finding))
                    list.Children.Add(Ui.Label(Get(finding, "file_count") + " itens · " + Ui.Size(finding.GetProperty("bytes").GetDouble()) + " · " + Get(finding, "errors") + " falhas", _p.Muted, 13, wrap: true));
            }
        }
        _view.Children.Add(new ScrollViewer { Content = list, MaxHeight = 420 });
        Actions(Action("Analisar", async () => { await LoadSettingsAsync(); await RunAsync("scan", CleanPayload(), "clean"); }),
            Action("Limpar agora", async () =>
            {
                await LoadSettingsAsync(); var payload = CleanPayload();
                if (_scanSignature != JsonSerializer.Serialize(payload)) { _status.Text = "A seleção ou as configurações mudaram. Analise novamente antes de limpar."; return; }
                if (!await _confirm("Limpar agora", "Excluir permanentemente estas categorias?\n" + string.Join("\n", _config.GetProperty("catalog").EnumerateObject().Where(x => _categories.Contains(x.Name)).Select(x => Get(x.Value, "group") + ": " + Get(x.Value, "label"))) + "\nArquivos em uso serão preservados. Cookies podem encerrar logins; senhas selecionadas não podem ser recuperadas.", "Limpar")) return;
                if (_runtime.Allowed) await RunAsync("execute", payload, "clean");
            }, _categories.Count > 0));
    }
    private CheckBox Select(JsonElement item, string label, string detail = "")
    {
        var id = Get(item, "id");
        var panel = new StackPanel { Spacing = 3 }; panel.Children.Add(Ui.Label(label, _p.Text, 14, true, true));
        if (detail.Length > 0) panel.Children.Add(Ui.Label(detail, _p.Muted, 13, wrap: true));
        var box = new CheckBox { Content = panel, IsChecked = _items.Contains(id), IsEnabled = id.Length > 0 };
        box.Click += (_, _) => { if (box.IsChecked == true) _items.Add(id); else _items.Remove(id); };
        return box;
    }
    private void Registry()
    {
        Text("Verificação do registro com backup antes de corrigir; selecione explicitamente os problemas.");
        Actions(Action("Carregar dados", () => RunToolAsync("registry", "scan")), Action("Corrigir selecionados", () => ModifyAsync("registry", "fix", "Corrigir os problemas selecionados? Será criado backup do registro.")));
        var report = Report("registry");
        foreach (var issue in Rows(report, "issues")) _view.Children.Add(Select(issue, Get(issue, "detail"), Get(issue, "key")));
        foreach (var backup in Rows(report, "backups"))
        {
            var stamp = backup.GetString() ?? "";
            Actions(Action("Restaurar backup " + stamp, async () => { if (await _confirm("Restaurar registro", "Restaurar o backup " + stamp + "?", "Restaurar")) await RunToolAsync("registry", "restore", ("backup", stamp)); }));
        }
    }
    private async Task ModifyAsync(string tool, string action, string message, params (string Key, object? Value)[] extra)
    {
        if (_items.Count == 0) { _status.Text = "Selecione pelo menos um item."; return; }
        if (!await _confirm("Confirmar operação", message, "Continuar") || !_runtime.Allowed) return;
        var args = new List<(string Key, object? Value)> { ("ids", _items.ToArray()) }; args.AddRange(extra);
        await RunToolAsync(tool, action, args.ToArray()); _items.Clear();
    }
    private void Tool()
    {
        var report = Report(_section);
        switch (_section)
        {
            case "duplicates":
                Text("Busca por tamanho e conteúdo; mantenha pelo menos uma cópia de cada grupo.");
                Actions(Action("Pesquisar duplicados", () => RunToolAsync("duplicates", "scan", ("roots", new[] { "users" }), ("min_size_kb", 1024))), Action("Excluir selecionados", () => ModifyAsync("duplicates", "delete", "Enviar as cópias selecionadas à Lixeira? Pelo menos uma cópia será preservada.", ("permanent", false))));
                foreach (var group in Rows(report, "groups")) foreach (var file in Rows(group, "files")) _view.Children.Add(Select(file, Get(file, "path"), Get(group, "size") + " bytes"));
                break;
            case "startup":
                Tabs(new[] { ("run", "Windows"), ("task", "Tarefas agendadas"), ("context", "Menu de contexto"), ("service", "Serviços do Windows") }, _startupTab, value => _startupTab = value);
                Actions(Action("Carregar dados", () => RunToolAsync("startup", "list")), Action("Ativar selecionados", () => StartupAsync(true)), Action("Desativar selecionados", () => StartupAsync(false)));
                foreach (var item in Rows(report, "items").Where(x => Get(x, "kind") == _startupTab || (_startupTab == "run" && Get(x, "kind") == "folder"))) _view.Children.Add(Select(item, Get(item, "name"), Get(item, "source") + " · " + Get(item, "enabled")));
                break;
            case "performance":
                Tabs(new[] { ("active", "Aplicativos ativos"), ("suspended", "Aplicativos suspensos") }, _perfTab, value => _perfTab = value);
                Actions(Action("Carregar dados", () => RunToolAsync("performance", "list")), Action(_perfTab == "active" ? "Suspender selecionados" : "Retomar selecionados", () => ModifyAsync("performance", _perfTab == "active" ? "suspend" : "resume", "Alterar somente os aplicativos selecionados?")));
                foreach (var item in Rows(report, _perfTab)) _view.Children.Add(Select(item, Get(item, "name"), Get(item, "publisher") + " · " + Get(item, "memory") + " bytes"));
                break;
            case "cloud":
                Text("Analisa arquivos locais sincronizados com provedores de nuvem; excluir pode refletir nos demais dispositivos.");
                Actions(Action("Verificar agora", () => RunToolAsync("cloud", "scan")), Action("Limpar selecionados", () => ModifyAsync("cloud", "delete", "Enviar à Lixeira os arquivos selecionados das pastas sincronizadas? O provedor poderá sincronizar a exclusão.")));
                foreach (var item in Rows(report, "items")) _view.Children.Add(Select(item, Get(item, "name").Length > 0 ? Get(item, "name") : Get(item, "path"), Get(item, "path")));
                break;
            case "updater":
                Actions(Action("Procurar atualizações", () => RunToolAsync("updater", "list")), Action("Atualizar selecionados", () => ModifyAsync("updater", "apply", "Instalar as atualizações selecionadas pelo WinGet?")));
                foreach (var item in Rows(report, "items")) _view.Children.Add(Select(item, Get(item, "name"), Get(item, "version") + " → " + Get(item, "available")));
                break;
            case "drivers":
                Tabs(new[] { ("outdated", "Atualizações disponíveis"), ("current", "Drivers instalados") }, _driverTab, value => _driverTab = value);
                Actions(Action("Verificar drivers", () => RunToolAsync("drivers", "scan")), Action("Atualizar selecionados", () => ModifyAsync("drivers", "install", "Instalar os drivers selecionados pelo Windows Update? Poderá ser necessário reiniciar.")));
                foreach (var item in Rows(report, _driverTab)) _view.Children.Add(Select(item, Get(item, "name"), Get(item, "version") + " · " + Get(item, "provider")));
                break;
            case "disk":
                var drives = new ComboBox(); foreach (var drive in DriveInfo.GetDrives().Where(x => x.DriveType == DriveType.Fixed)) drives.Items.Add(drive.Name.TrimEnd('\\'));
                drives.SelectedItem = drives.Items.Contains(_drive) ? _drive : drives.Items.FirstOrDefault(); drives.SelectionChanged += (_, _) => _drive = drives.SelectedItem?.ToString() ?? "C:"; _view.Children.Add(drives);
                Actions(Action("Analisar disco", () => RunToolAsync("disk", "scan", ("drive", _drive))));
                foreach (var type in Rows(report, "types")) Text(Get(type, "name") + " · " + Get(type, "bytes") + " bytes");
                foreach (var file in Rows(report, "largest")) Text(Get(file, "path") + " · " + Get(file, "bytes") + " bytes");
                break;
            case "restore":
                Actions(Action("Atualizar", () => RunToolAsync("restore", "list")), Action("Criar ponto agora", async () => { if (await _confirm("Criar ponto de restauração", "Criar um ponto de restauração do sistema?", "Criar")) await RunToolAsync("restore", "create"); }));
                foreach (var point in Rows(report, "points")) Text(Get(point, "created") + " · " + Get(point, "description") + " · " + Get(point, "sequence"));
                break;
        }
        if (report.ValueKind != JsonValueKind.Object) Text("Use o botão para carregar dados. Nenhuma verificação é iniciada ao trocar de seção.");
        else
        {
            if (Get(report, "message").Length > 0) Text(Get(report, "message"));
            if (Get(report, "truncated").Equals("True", StringComparison.OrdinalIgnoreCase)) Text("A análise atingiu o limite. O relatório é parcial.");
            foreach (var result in Rows(report, "results")) Text(Get(result, "id") + " · " + Get(result, "message"));
        }
    }
    private async Task StartupAsync(bool enabled)
    {
        if (_items.Count == 0 || !await _confirm("Inicialização do Windows", "Alterar os itens selecionados?", "Aplicar")) return;
        await RunToolAsync("startup", "apply", ("operations", _items.Select(id => new Dictionary<string, object?> { ["id"] = id, ["op"] = enabled ? "enable" : "disable" }).ToArray())); _items.Clear();
    }
    private void Options()
    {
        var exclusions = new TextBox { Header = "Excluir (Exclusões)", AcceptsReturn = true, Text = string.Join("\n", Setting("exclusions")), TextWrapping = TextWrapping.Wrap, MinHeight = 100 };
        var folders = new TextBox { Header = "Incluir (Pastas personalizadas)", AcceptsReturn = true, Text = string.Join("\n", Setting("custom_folders")), TextWrapping = TextWrapping.Wrap, MinHeight = 100 };
        Text("Um caminho por linha. Raízes, pastas do Windows, perfis completos e diretórios de documentos são recusados pelo motor."); _view.Children.Add(exclusions); _view.Children.Add(folders);
        Actions(Action("Carregar configurações", async () => { await LoadSettingsAsync(); Render(); }), Action("Salvar", async () =>
        {
            string[] Lines(TextBox box) => box.Text.Split('\n').Select(x => x.Trim()).Where(x => x.Length > 0).ToArray();
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            var response = await _runtime.Platform.PostAsync("agent-clean-local-settings", _runtime.Platform.WithCredentials(("save", true), ("exclusions", Lines(exclusions)), ("custom_folders", Lines(folders)), ("perf_excluded", Setting("perf_excluded")), ("drivers_ignored", Setting("drivers_ignored"))), timeout.Token, 12);
            _settings = response.GetProperty("settings").Clone(); _scanSignature = ""; _status.Text = "Configurações compartilhadas com a plataforma salvas.";
        }, _settings.ValueKind == JsonValueKind.Object));
    }
    private void Backup()
    {
        Text("Mesmo destino e pastas definidos na plataforma. O provedor é conectado pelo login oficial no navegador; credenciais do provedor não ficam no agente.");
        Actions(Action("Atualizar estado", () => RunAsync("backup_status", new(), "backup")), Action("Executar backup agora", () => RunAsync("backup_run", new(), "backup")),
            Action("Configurar destino e pastas", () => { Process.Start(new ProcessStartInfo(_runtime.Platform.Config.Origin + "/redes/clean.php?section=backup") { UseShellExecute = true }); return Task.CompletedTask; }));
        var report = Report("backup");
        if (report.ValueKind == JsonValueKind.Object)
        {
            var job = report.GetProperty("job"); Text("Disponível: " + Get(job, "available"));
            if (job.TryGetProperty("settings", out var settings)) foreach (var folder in Rows(settings, "folders")) Text(Get(folder, "label") + " · " + Get(folder, "path"));
            if (report.TryGetProperty("status", out var status)) Text(Get(status, "message") + " · " + Get(status, "last_success_at"));
        }
    }
}
