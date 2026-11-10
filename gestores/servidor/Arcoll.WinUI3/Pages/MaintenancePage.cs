// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/MaintenancePage.cs
using System.Text.Json;
using System.Security.Cryptography;
using System.Text;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;
using Microsoft.UI.Xaml.Media;
using Windows.ApplicationModel.DataTransfer;
namespace Arcoll.WinUI3.Pages;

/// <summary>Infraestrutura comum de apresentação e execução. Cada página mantém estado e operações próprios.</summary>
public abstract class MaintenancePage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Clique para consultar os dados desta ferramenta.");
    private bool _collectionCancelled,_collectionInProgress;public bool CanCancelCollection=>true;public void CancelCollection(){_collectionCancelled=true;CancelOperations();}
    private Func<Task>? _collect;private bool _collectEnabled;
    public async Task CollectDataAsync(){if(Busy||_dispatching||!Runtime.Allowed)return;Render();if(_collect is null||!_collectEnabled){DataLoad.Wait("Escolha as opções desta ferramenta antes de coletar.");return;}_dispatching=true;_collectionInProgress=true;_collectionCancelled=false;DataLoad.Begin("Consultando "+_heading.Text);try{await _collect();if(_collectionCancelled)DataLoad.Fail("Coleta interrompida. Clique para tentar novamente.");if(DataLoad.Current.Phase!=DataLoadPhase.Failed){if(Report.ValueKind==JsonValueKind.Object)DataLoad.Complete();else DataLoad.Fail(string.IsNullOrWhiteSpace(_status.Text)?"A consulta não retornou um relatório. Tente novamente.":_status.Text);}}catch(Exception error){Status(error.Message);DataLoad.Fail(error.Message);}finally{_collectionInProgress=false;_dispatching=false;Render();}}
    protected Button CollectAction(string title,Func<Task> collect,bool enabled=true){_collect=collect;_collectEnabled=enabled;return Action(title,CollectDataAsync,enabled,collection:true);}
    protected readonly GestorRuntime Runtime;
    protected readonly Func<string,string,string,Task<bool>> Confirm;
    protected readonly StackPanel View = new() { Spacing = 8 };
    protected readonly HashSet<string> Selected = new(StringComparer.Ordinal);
    protected Palette P = new();
    protected JsonElement Report, Settings;
    protected bool Busy { get; private set; }
    protected string SyncNotice { get; private set; }="";
    protected bool CancellationRequested { get; private set; }
    protected readonly string Kind;
    private readonly Grid _root = new() { RowSpacing = 10, Padding = new Thickness(14, 10, 14, 12) };
    private readonly Border _headerCard = new() { BorderThickness = new Thickness(1, 1, 1, 1), CornerRadius = new CornerRadius(14), Padding = new Thickness(14, 10, 14, 10) };
    private readonly Border _footerCard = new() { BorderThickness = new Thickness(1), CornerRadius = new CornerRadius(12), Padding = new Thickness(10, 7, 10, 7) };
    private readonly Border _statusCard = new() { BorderThickness = new Thickness(1), CornerRadius = new CornerRadius(10), Padding = new Thickness(11, 6, 11, 6), Visibility = Visibility.Collapsed };
    private readonly ProgressBar _progress = new() { IsIndeterminate = true, Visibility = Visibility.Collapsed, Height = 3, MinHeight = 3 };
    private readonly Border _stateChip = new() { BorderThickness = new Thickness(1), CornerRadius = new CornerRadius(20), Padding = new Thickness(10, 3, 10, 4), VerticalAlignment = VerticalAlignment.Top };
    private readonly TextBlock _stateText = new() { FontSize = 11, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
    private readonly TextBlock _heading, _description, _status = new() { TextWrapping = TextWrapping.Wrap, IsTextSelectionEnabled = true }, _summary = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBox _search = new() { PlaceholderText = "Filtrar resultados", MinHeight = 30, FontSize = 14 };
    private readonly Button _cancel = new() { Content = "Cancelar operação" }, _clear = new() { Content = "Limpar seleção" }, _copy = new() { Content = "Copiar relatório" }, _selectVisible = new() { Content = "Marcar resultados visíveis" };
    private readonly HashSet<string> _visibleIds = new(StringComparer.Ordinal);
    private readonly Border _accentBar = new() { Width = 4, CornerRadius = new CornerRadius(2) };
    private CancellationTokenSource? _operation;
    private JsonElement _catalog;
    private DateTime? _collected;
    private bool _dispatching;
    private string _restoredIdentity="";
    private int _lastMetricColumns = 0;
    protected MaintenancePage(GestorRuntime runtime, Func<string,string,string,Task<bool>> confirm, string kind, string title, string description)
    {
        Runtime = runtime; Confirm = confirm; Kind = kind; Content = _root;
        _heading = new TextBlock { Text = title, FontSize = 19, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        _description = new TextBlock { Text = description, FontSize = 12, TextWrapping = TextWrapping.Wrap };
        for (var row = 0; row < 5; row++) _root.RowDefinitions.Add(new() { Height = row == 3 ? new GridLength(1, GridUnitType.Star) : GridLength.Auto });
        var header = new StackPanel { Spacing = 4 }; header.Children.Add(_heading); header.Children.Add(_description);
        _stateChip.Child = _stateText;
        var headerGrid = new Grid { ColumnSpacing = 12 };
        headerGrid.ColumnDefinitions.Add(new() { Width = GridLength.Auto }); headerGrid.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) }); headerGrid.ColumnDefinitions.Add(new() { Width = GridLength.Auto });
        headerGrid.Children.Add(_accentBar); Grid.SetColumn(header, 1); headerGrid.Children.Add(header); Grid.SetColumn(_stateChip, 2); headerGrid.Children.Add(_stateChip);
        _headerCard.Child = headerGrid; _root.Children.Add(_headerCard);
        _search.CornerRadius = new CornerRadius(9);
        _search.PlaceholderText = "Pesquisar na página…";
        Grid.SetRow(_search, 1); _root.Children.Add(_search);
        var statusStack = new StackPanel { Spacing = 5 }; statusStack.Children.Add(_status); statusStack.Children.Add(_progress); _statusCard.Child = statusStack;
        Grid.SetRow(_statusCard, 2); _root.Children.Add(_statusCard);
        var scroll = new ScrollViewer { Content = View, VerticalScrollBarVisibility = ScrollBarVisibility.Hidden, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0,0,5,0) }; Grid.SetRow(scroll, 3); _root.Children.Add(scroll);
        var footer = new StackPanel { Spacing = 5 }; footer.Children.Add(_summary);
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 7 }; actions.Children.Add(_selectVisible); actions.Children.Add(_clear); actions.Children.Add(_copy); actions.Children.Add(_cancel); footer.Children.Add(actions);
        _footerCard.Child = footer; Grid.SetRow(_footerCard, 4); _root.Children.Add(_footerCard);
        foreach (var button in new[] { _selectVisible, _clear, _copy, _cancel }) { button.FontSize = 12; button.MinHeight = 32; button.Padding = new Thickness(10,3,10,3); button.CornerRadius = new CornerRadius(8); }
        AutomationProperties.SetAutomationId(this, "tool-" + kind); AutomationProperties.SetAutomationId(_search, "filter-" + kind);
        AutomationProperties.SetName(_search, "Filtrar " + title); AutomationProperties.SetLiveSetting(_status, Microsoft.UI.Xaml.Automation.Peers.AutomationLiveSetting.Polite);
        _search.TextChanged += (_, _) => Render();
        _selectVisible.Click += (_, _) => { foreach(var id in _visibleIds) Selected.Add(id); Render(); };
        _clear.Click += (_, _) => { Selected.Clear(); Render(); }; _cancel.Click += (_, _) => CancelOperations();
        _copy.Click += (_, _) => { try { var data = new DataPackage(); data.SetText(JsonSerializer.Serialize(Report, new JsonSerializerOptions { WriteIndented = true })); Clipboard.SetContent(data); Status("Relatório copiado."); } catch (Exception ex) { Status(ex.Message); } };
        Loaded += (_, _) => Render();
        SizeChanged += (_, e) =>
        {
            var columns = e.NewSize.Width >= 930 ? 4 : e.NewSize.Width >= 560 ? 2 : 1;
            if (_lastMetricColumns != columns) { _lastMetricColumns = columns; Render(); }
        };
    }
    protected JsonElement Catalog { get { if (_catalog.ValueKind != JsonValueKind.Object) _catalog = PenteFinoEngine.Catalog(); return _catalog; } }
    public void CancelOperations() => _operation?.Cancel();
    public void ApplyPalette(Palette palette) { P = palette; Render(); }
    protected void StoreReport(object report) { Report=JsonSerializer.SerializeToElement(report);_collected=DateTime.Now; }
    private string SnapshotPath()
    {
        var config=Runtime.Platform.Config;
        var identity=Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(config.Origin+"|"+config.SiteCode+"|"+config.Fingerprint)));
        return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital","Reports",identity,"health.json");
    }
    protected void RestoreSnapshot()
    {
        if(Kind!="health"||!Runtime.Allowed)return;var path=SnapshotPath();if(_restoredIdentity==path)return;_restoredIdentity=path;
        try{if(!File.Exists(path)||new FileInfo(path).Length>8*1024*1024)return;using var doc=JsonDocument.Parse(File.ReadAllText(path));var envelope=doc.RootElement;if(envelope.GetProperty("report").ValueKind!=JsonValueKind.Object)return;Report=envelope.GetProperty("report").Clone();if(envelope.TryGetProperty("saved_at",out var at)&&at.TryGetInt64(out var stamp))_collected=DateTimeOffset.FromUnixTimeSeconds(stamp).LocalDateTime;}
        catch(Exception ex) when(ex is IOException or UnauthorizedAccessException or JsonException or KeyNotFoundException or ArgumentOutOfRangeException or InvalidOperationException){Status("Não foi possível ler o relatório local: "+ex.Message);}
    }
    private void PersistSnapshot()
    {
        if(Kind!="health"||Report.ValueKind!=JsonValueKind.Object||Get(Report,"tool")!="health")return;var path=SnapshotPath();var temp=path+"."+Guid.NewGuid().ToString("N")+".tmp";
        try{Directory.CreateDirectory(Path.GetDirectoryName(path)!);var data=JsonSerializer.Serialize(new{saved_at=DateTimeOffset.Now.ToUnixTimeSeconds(),report=Report});if(Encoding.UTF8.GetByteCount(data)>8*1024*1024)throw new IOException("Relatório excede o limite local.");File.WriteAllText(temp,data,Encoding.UTF8);File.Move(temp,path,true);}
        catch(Exception ex) when(ex is IOException or UnauthorizedAccessException){Status(_status.Text+" Relatório preservado em memória; gravação local pendente: "+ex.Message);}
        finally{try{if(File.Exists(temp))File.Delete(temp);}catch(IOException){}catch(UnauthorizedAccessException){}}
    }
    protected async Task SaveSnapshotAsync(object report)
    {
        SyncNotice="";StoreReport(report);PersistSnapshot();
        try{using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(40));if(!Runtime.Allowed)throw new UnauthorizedAccessException("Autorização indisponível para sincronizar.");await Runtime.Platform.PostAsync("gestor-pentefino-local-result",Runtime.Platform.WithCredentials(("mode","tool"),("tool",Kind),("report",Report)),timeout.Token,35);}
        catch(Exception ex){SyncNotice="Envio pendente: "+ex.Message;Status(_status.Text+" Relatório local preservado; "+SyncNotice);}
    }
    protected void Status(string message) { _status.Text = message; _statusCard.Visibility = string.IsNullOrWhiteSpace(message) && !Busy ? Visibility.Collapsed : Visibility.Visible; }
    private void PaintState()
    {
        var (text, tone) = Busy || _collectionInProgress ? ("Em andamento", P.Accent)
            : Report.ValueKind != JsonValueKind.Object ? ("Aguardando análise", P.Muted)
            : Yes(Report, "truncated") || Part(Report, "complete").ValueKind == JsonValueKind.False || SyncNotice.Length > 0 ? ("Resultado parcial", P.Warning)
            : ("Atualizado", P.Success);
        _stateText.Text = text; _stateText.Foreground = tone; _stateChip.BorderBrush = tone; _stateChip.Background = P.Alt; _accentBar.Background = P.Accent;
        _statusCard.Background = P.Surface; _statusCard.BorderBrush = Busy ? P.Accent : P.Line;
        _progress.Visibility = Busy || _collectionInProgress ? Visibility.Visible : Visibility.Collapsed; _progress.Foreground = P.Accent;
        _statusCard.Visibility = string.IsNullOrWhiteSpace(_status.Text) && _progress.Visibility == Visibility.Collapsed ? Visibility.Collapsed : Visibility.Visible;
    }
    protected abstract void Build();
    protected void Render()
    {
        _collect=null;_collectEnabled=false;View.Children.Clear();_visibleIds.Clear();
        _root.Background = P.Alt;
        _headerCard.Background = _footerCard.Background = P.Surface;
        _headerCard.BorderBrush = _footerCard.BorderBrush = P.Line;
        _heading.Foreground = P.Text; _description.Foreground = _summary.Foreground = P.Muted; _status.Foreground = P.Text;
        PaintState();
        _search.IsEnabled = !Busy; _clear.IsEnabled = !Busy && Selected.Count > 0; _copy.IsEnabled = Report.ValueKind == JsonValueKind.Object;
        _cancel.Visibility = Busy ? Visibility.Visible : Visibility.Collapsed; _cancel.Foreground = P.Danger; _cancel.BorderBrush = P.Danger;
        _search.Background = P.Surface; _search.BorderBrush = P.Line;
        foreach (var b in new[] { _selectVisible, _clear, _copy }) { b.Background = P.Alt; b.BorderBrush = P.Line; b.Foreground = P.Text; }
        try { Build(); ReportNotes(); } catch (Exception ex) { Text("Não foi possível mostrar os dados: " + ex.Message); }
        UpdateSummary();
    }
    private void UpdateSummary() { _summary.Text = Selected.Count + " selecionado(s) · " + _visibleIds.Count + " resultado(s) elegível(is) visíveis" + (_collected.HasValue ? " · Última execução: " + _collected.Value.ToString("dd/MM HH:mm:ss") : " · Carregue os dados para começar"); _clear.IsEnabled = !Busy && Selected.Count > 0; _selectVisible.IsEnabled = !Busy && Runtime.Allowed && _visibleIds.Count > 0 && _visibleIds.Any(id=>!Selected.Contains(id)); }
    protected bool Matches(params string[] values) => ProgramService.Normalize(string.Join(" ",values)).Contains(ProgramService.Normalize(_search.Text));
    protected static string Get(JsonElement parent,string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key,out var value) ? value.ToString() : "";
    protected static JsonElement Part(JsonElement parent,string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key,out var value) ? value : default;
    protected static JsonElement[] Rows(JsonElement parent,string key) => Part(parent,key).ValueKind == JsonValueKind.Array ? Part(parent,key).EnumerateArray().ToArray() : Array.Empty<JsonElement>();
    protected static double Number(JsonElement parent,string key) => double.TryParse(Get(parent,key),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var n) ? n : 0;
    protected static bool Yes(JsonElement parent,string key) => string.Equals(Get(parent,key),"true",StringComparison.OrdinalIgnoreCase) || Get(parent,key)=="1";
    protected void Text(string value,bool heading=false)
    {
        if (!heading) { View.Children.Add(Ui.Label(value, P.Muted, 12, wrap:true)); return; }
        var panel = new StackPanel { Spacing = 5, Margin = new Thickness(0, 7, 0, 3) };
        panel.Children.Add(new Border { Height = 2, Width = 34, HorizontalAlignment = HorizontalAlignment.Left, Background = P.Accent, CornerRadius = new CornerRadius(1) });
        panel.Children.Add(Ui.Label(value, P.Text, 14, true, true));
        View.Children.Add(panel);
    }
    /// <summary>Indicadores reutilizáveis, responsivos e sem adicionar fontes de dados paralelas.</summary>
    protected void Metrics(params (string Title, string Value, string Detail)[] metrics)
    {
        if (metrics.Length == 0) return;
        var columns = Math.Min(metrics.Length, ActualWidth >= 930 ? 4 : ActualWidth >= 560 ? 2 : 1);
        var grid = new Grid { ColumnSpacing = 8, RowSpacing = 8 };
        for (var i = 0; i < columns; i++) grid.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < metrics.Length; i++)
        {
            if (i % columns == 0) grid.RowDefinitions.Add(new() { Height = GridLength.Auto });
            var panel = new StackPanel { Spacing = 5, MinHeight = 92 };
            panel.Children.Add(new Border { Height = 3, Width = 26, HorizontalAlignment = HorizontalAlignment.Left, Background = P.Accent, CornerRadius = new CornerRadius(2) });
            panel.Children.Add(Ui.Label(metrics[i].Title.ToUpperInvariant(), P.Muted, 10, true, true));
            panel.Children.Add(Ui.Label(metrics[i].Value, P.Text, 19, true, true));
            panel.Children.Add(Ui.Label(metrics[i].Detail, P.Muted, 11, false, true));
            var card = Ui.Card(panel, P, 12);
            Grid.SetColumn(card, i % columns); Grid.SetRow(card, i / columns); grid.Children.Add(card);
        }
        View.Children.Add(grid);
    }
    protected Button Action(string title,Func<Task> action,bool enabled=true,bool collection=false)
    {
        var label=Ui.Label(title,collection?ThemeService.Brush("#ffffff"):P.Text,12,true,true);label.TextAlignment=TextAlignment.Center;
        var button = new Button { Content=label,IsEnabled=!Busy&&Runtime.Allowed&&enabled,FontSize=12,MinHeight=40,Padding=new Thickness(12,7,12,7),HorizontalAlignment=HorizontalAlignment.Stretch,HorizontalContentAlignment=HorizontalAlignment.Center,CornerRadius=new CornerRadius(10),Background=collection?P.Accent:P.Surface,BorderBrush=collection?P.Accent:P.Line,BorderThickness=new Thickness(1) };
        button.Opacity=button.IsEnabled?1:.5;
        AutomationProperties.SetName(button,title);
        button.Click += async (_,_) => {if(collection){await action();return;} if(_dispatching||Busy||!Runtime.Allowed)return;_dispatching=true;button.IsEnabled=false;try { await action(); } catch(Exception ex) { Status(ex.Message); } finally {_dispatching=false;Render();} }; return button;
    }
    protected void Actions(params Button[] buttons)
    {
        if (buttons.Length == 0) return;
        // Comandos agrupados, responsivos, na mesma linguagem visual dos cartões.
        // Não altera callbacks, estado ou regras de autorização das páginas.
        var columns = Math.Min(buttons.Length, ActualWidth >= 850 ? 3 : ActualWidth >= 510 ? 2 : 1);
        var grid = new Grid { ColumnSpacing = 8, RowSpacing = 8, Margin = new Thickness(0, 3, 0, 5) };
        for (var i = 0; i < columns; i++) grid.ColumnDefinitions.Add(new() { Width = new GridLength(1, GridUnitType.Star) });
        for (var i = 0; i < buttons.Length; i++)
        {
            if (i % columns == 0) grid.RowDefinitions.Add(new() { Height = GridLength.Auto });
            Grid.SetRow(buttons[i], i / columns); Grid.SetColumn(buttons[i], i % columns);
            grid.Children.Add(buttons[i]);
        }
        View.Children.Add(grid);
    }
    protected ComboBox Choices((string Key,string Label)[] choices,string selected,Action<string> change)
    {
        var combo=new ComboBox { HorizontalAlignment=HorizontalAlignment.Stretch,MinHeight=40,IsEnabled=!Busy,CornerRadius=new CornerRadius(10),Background=P.Surface,BorderBrush=P.Line,Foreground=P.Text };
        foreach(var item in choices)combo.Items.Add(item.Label);combo.SelectedIndex=Array.FindIndex(choices,x=>x.Key==selected);
        combo.SelectionChanged+=(_,_)=>{if(combo.SelectedIndex>=0){Selected.Clear();change(choices[combo.SelectedIndex].Key);Render();}};View.Children.Add(combo);return combo;
    }
    protected void Select(JsonElement item,string title,string detail="",bool enabled=true)
    {
        if(!Matches(title,detail))return;
        var id=Get(item,"id");if(enabled&&id.Length>0)_visibleIds.Add(id);var label=new StackPanel { Spacing=2 };label.Children.Add(Ui.Label(title,P.Text,14,true,true));if(detail.Length>0)label.Children.Add(Ui.Label(detail,P.Muted,12,wrap:true));
        var box=new CheckBox { Content=label,IsChecked=Selected.Contains(id),IsEnabled=!Busy&&enabled&&id.Length>0,MinHeight=44,HorizontalAlignment=HorizontalAlignment.Stretch,HorizontalContentAlignment=HorizontalAlignment.Stretch,Padding=new Thickness(5,2,5,2) };
        var tile = new Border { Background=P.Surface,BorderBrush=P.Line,BorderThickness=new Thickness(1),CornerRadius=new CornerRadius(10),Padding=new Thickness(8,4,8,4),Child=box };
        void Paint(){var on=box.IsChecked==true;tile.BorderBrush=on?P.Accent:P.Line;tile.Background=on?P.Alt:P.Surface;tile.BorderThickness=new Thickness(on?2:1);}
        Paint();
        AutomationProperties.SetName(box,title);box.Click+=(_,_)=>{if(box.IsChecked==true)Selected.Add(id);else Selected.Remove(id);Paint();UpdateSummary();};
        View.Children.Add(tile);
    }
    protected async Task<JsonElement> LoadSettingsAsync()
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));var response=await Runtime.Platform.PostAsync("gestor-pentefino-local-settings",Runtime.Platform.WithCredentials(),timeout.Token,15);Settings=response.GetProperty("settings").Clone();return response;
    }
    protected string[] Setting(string key)=>Rows(Settings,key).Select(x=>x.ToString()).ToArray();
    protected async Task SaveSettingsAsync(string[] exclusions,string[] folders,string[] performance,string[] drivers)
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));var response=await Runtime.Platform.PostAsync("gestor-pentefino-local-settings",Runtime.Platform.WithCredentials(("save",true),("exclusions",exclusions),("custom_folders",folders),("perf_excluded",performance),("drivers_ignored",drivers)),timeout.Token,15);Settings=response.GetProperty("settings").Clone();
    }
    protected async Task<bool> RunAsync(string mode,Dictionary<string,object?> payload)
    {
        if(Busy||!Runtime.Allowed)return false;if(_collectionInProgress&&_collectionCancelled){DataLoad.Fail("Coleta interrompida antes da análise.");return false;}SyncNotice="";CancellationRequested=false;Busy=true;_operation=new();Status("Preparando operação…");Render();
        try {
            var progress=new Progress<string>(message=>Status(message));
            Report=await PenteFinoEngine.RunAsync(mode,payload,Runtime.Platform.Config,progress,_operation.Token);_collected=DateTime.Now;Selected.Clear();
            var partial=PenteFinoEngine.HasFailures(Report)||Number(Part(Report,"status"),"errors")>0;
            Status(partial?"Operação parcial. Revise as falhas e limitações do relatório.":"Execução concluída. Revise o relatório.");
            PersistSnapshot();
            if(!mode.StartsWith("backup",StringComparison.Ordinal))try{await Runtime.Platform.PostAsync("gestor-pentefino-local-result",Runtime.Platform.WithCredentials(("mode",mode),("tool",Get(Report,"tool").Length>0?Get(Report,"tool"):Kind),("report",Report)),_operation.Token,40);}catch(Exception ex){SyncNotice="Envio pendente: "+ex.Message;Status(_status.Text+" Relatório local preservado; "+SyncNotice);}
            return true;
        }catch(OperationCanceledException){if(DataLoad.Current.Phase==DataLoadPhase.Loading)DataLoad.Fail("A consulta foi interrompida; tente novamente.");Status("Operação interrompida. Alterações concluídas não foram desfeitas.");return false;}
        catch(Exception ex){if(DataLoad.Current.Phase==DataLoadPhase.Loading)DataLoad.Fail("Falha na consulta: "+ex.Message);Status("Falha: "+ex.Message);return false;}
        finally{CancellationRequested=_operation.IsCancellationRequested;_operation.Dispose();_operation=null;Busy=false;Render();}
    }
    protected async Task ExecuteLocalAsync(Func<CancellationToken,Task> action)
    {
        if(Busy||!Runtime.Allowed)return;if(_collectionInProgress&&_collectionCancelled){DataLoad.Fail("Coleta interrompida antes da análise.");return;}Busy=true;_operation=new();Render();
        try{await action(_operation.Token);_collected=DateTime.Now;}
        catch(OperationCanceledException){Status("Acompanhamento interrompido. Um instalador oficial já iniciado pode continuar no Windows; verifique antes de repetir.");}
        catch(Exception ex){if(DataLoad.Current.Phase==DataLoadPhase.Loading)DataLoad.Fail("Falha na consulta: "+ex.Message);Status("Falha: "+ex.Message);}
        finally{_operation.Dispose();_operation=null;Busy=false;Render();}
    }
    protected Task<bool> RunToolAsync(string action,params (string Key,object? Value)[] args)
    {
        var body=new Dictionary<string,object?>{{"tool",Kind},{"action",action},{"recovery_copies",false}};foreach(var (key,value) in args)body[key]=value;return RunAsync("tool",body);
    }
    protected async Task ApplySelectedAsync(string action,string message,int max,params (string Key,object? Value)[] extra)
    {
        if(Selected.Count<1||Selected.Count>max){Status("Selecione de 1 a "+max+" itens.");return;}
        var ids=Selected.ToArray();if(!await Confirm("Confirmar operação",message+"\n"+ids.Length+" item(ns) selecionado(s). Alterações já aplicadas não são desfeitas ao cancelar.","Aplicar")||!Runtime.Allowed||Busy)return;
        var args=new List<(string Key,object? Value)>{("ids",ids)};args.AddRange(extra);await RunToolAsync(action,args.ToArray());
    }
    private void ReportNotes()
    {
        if(Report.ValueKind!=JsonValueKind.Object)return;
        if(Get(Report,"message").Length>0)Text(Get(Report,"message"));
        if(Yes(Report,"truncated")||Part(Report,"complete").ValueKind==JsonValueKind.False)Text("Relatório parcial: limites, permissões ou tempo impediram a leitura de todos os itens.");
        if(Yes(Report,"reboot"))Text("Há alterações que exigem reinicialização. Nenhum reinício foi iniciado automaticamente.");
        foreach(var row in Rows(Report,"results"))Text(Get(row,"id")+" · "+Get(row,"message"));
    }
}
