// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/MaintenancePage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;
using Windows.ApplicationModel.DataTransfer;
namespace Arcoll.WinUI3.Pages;

/// <summary>Infraestrutura comum de apresentação e execução. Cada página mantém estado e operações próprios.</summary>
public abstract class MaintenancePage : UserControl
{
    protected readonly AgentRuntime Runtime;
    protected readonly Func<string,string,string,Task<bool>> Confirm;
    protected readonly StackPanel View = new() { Spacing = 8 };
    protected readonly HashSet<string> Selected = new(StringComparer.Ordinal);
    protected Palette P = new();
    protected JsonElement Report, Settings;
    protected bool Busy { get; private set; }
    protected readonly string Kind;
    private readonly Grid _root = new() { RowSpacing = 6 };
    private readonly TextBlock _heading, _description, _status = new() { TextWrapping = TextWrapping.Wrap, IsTextSelectionEnabled = true }, _summary = new() { TextWrapping = TextWrapping.Wrap };
    private readonly TextBox _search = new() { PlaceholderText = "Filtrar resultados", MinHeight = 30, FontSize = 14 };
    private readonly Button _cancel = new() { Content = "Cancelar operação" }, _clear = new() { Content = "Limpar seleção" }, _copy = new() { Content = "Copiar relatório" };
    private CancellationTokenSource? _operation;
    private JsonElement _catalog;
    private DateTime? _collected;
    private bool _dispatching;
    protected MaintenancePage(AgentRuntime runtime, Func<string,string,string,Task<bool>> confirm, string kind, string title, string description)
    {
        Runtime = runtime; Confirm = confirm; Kind = kind; Content = _root;
        _heading = new TextBlock { Text = title, FontSize = 18, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold };
        _description = new TextBlock { Text = description, FontSize = 12, TextWrapping = TextWrapping.Wrap };
        for (var row = 0; row < 5; row++) _root.RowDefinitions.Add(new() { Height = row == 3 ? new GridLength(1, GridUnitType.Star) : GridLength.Auto });
        var header = new StackPanel { Spacing = 3 }; header.Children.Add(_heading); header.Children.Add(_description); _root.Children.Add(header);
        Grid.SetRow(_search, 1); _root.Children.Add(_search); Grid.SetRow(_status, 2); _root.Children.Add(_status);
        var scroll = new ScrollViewer { Content = View, VerticalScrollBarVisibility = ScrollBarVisibility.Auto, HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled, Padding = new Thickness(0,0,5,0) }; Grid.SetRow(scroll, 3); _root.Children.Add(scroll);
        var footer = new StackPanel { Spacing = 4 }; footer.Children.Add(_summary);
        var actions = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 5 }; actions.Children.Add(_clear); actions.Children.Add(_copy); actions.Children.Add(_cancel); footer.Children.Add(actions); Grid.SetRow(footer, 4); _root.Children.Add(footer);
        foreach (var button in new[] { _clear, _copy, _cancel }) { button.FontSize = 12; button.MinHeight = 28; button.Padding = new Thickness(8,3,8,3); }
        AutomationProperties.SetAutomationId(this, "tool-" + kind); AutomationProperties.SetAutomationId(_search, "filter-" + kind);
        AutomationProperties.SetName(_search, "Filtrar " + title); AutomationProperties.SetLiveSetting(_status, Microsoft.UI.Xaml.Automation.Peers.AutomationLiveSetting.Polite);
        _search.TextChanged += (_, _) => Render(); _clear.Click += (_, _) => { Selected.Clear(); Render(); }; _cancel.Click += (_, _) => CancelOperations();
        _copy.Click += (_, _) => { try { var data = new DataPackage(); data.SetText(JsonSerializer.Serialize(Report, new JsonSerializerOptions { WriteIndented = true })); Clipboard.SetContent(data); Status("Relatório copiado."); } catch (Exception ex) { Status(ex.Message); } };
        Loaded += (_, _) => Render();
    }
    protected JsonElement Catalog { get { if (_catalog.ValueKind != JsonValueKind.Object) _catalog = CleanEngine.Catalog(); return _catalog; } }
    public void CancelOperations() => _operation?.Cancel();
    public void ApplyPalette(Palette palette) { P = palette; Render(); }
    protected void StoreReport(object report) { Report=JsonSerializer.SerializeToElement(report);_collected=DateTime.Now; }
    protected void Status(string message) { _status.Text = message; _status.Visibility = string.IsNullOrWhiteSpace(message) ? Visibility.Collapsed : Visibility.Visible; }
    protected abstract void Build();
    protected void Render()
    {
        View.Children.Clear(); _heading.Foreground = P.Text; _description.Foreground = _summary.Foreground = P.Muted; _status.Foreground = P.Text;
        _status.Visibility = string.IsNullOrWhiteSpace(_status.Text) ? Visibility.Collapsed : Visibility.Visible;
        _search.IsEnabled = !Busy; _clear.IsEnabled = !Busy && Selected.Count > 0; _copy.IsEnabled = Report.ValueKind == JsonValueKind.Object;
        _cancel.Visibility = Busy ? Visibility.Visible : Visibility.Collapsed;
        try { Build(); ReportNotes(); } catch (Exception ex) { Text("Não foi possível mostrar os dados: " + ex.Message); }
        UpdateSummary();
    }
    private void UpdateSummary() { _summary.Text = Selected.Count + " selecionado(s)" + (_collected.HasValue ? " · Última execução: " + _collected.Value.ToString("dd/MM HH:mm:ss") : " · Carregue os dados para começar"); _clear.IsEnabled = !Busy && Selected.Count > 0; }
    protected bool Matches(params string[] values) => ProgramService.Normalize(string.Join(" ",values)).Contains(ProgramService.Normalize(_search.Text));
    protected static string Get(JsonElement parent,string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key,out var value) ? value.ToString() : "";
    protected static JsonElement Part(JsonElement parent,string key) => parent.ValueKind == JsonValueKind.Object && parent.TryGetProperty(key,out var value) ? value : default;
    protected static JsonElement[] Rows(JsonElement parent,string key) => Part(parent,key).ValueKind == JsonValueKind.Array ? Part(parent,key).EnumerateArray().ToArray() : Array.Empty<JsonElement>();
    protected static double Number(JsonElement parent,string key) => double.TryParse(Get(parent,key),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var n) ? n : 0;
    protected static bool Yes(JsonElement parent,string key) => string.Equals(Get(parent,key),"true",StringComparison.OrdinalIgnoreCase) || Get(parent,key)=="1";
    protected void Text(string value,bool heading=false) => View.Children.Add(Ui.Label(value,heading?P.Text:P.Muted,heading?15:13,heading,true));
    protected Button Action(string title,Func<Task> action,bool enabled=true)
    {
        var button = new Button { Content=title,IsEnabled=!Busy&&Runtime.Allowed&&enabled,FontSize=12,MinHeight=30,Padding=new Thickness(9,4,9,4),HorizontalAlignment=HorizontalAlignment.Left };
        button.Click += async (_,_) => { if(_dispatching||Busy||!Runtime.Allowed)return;_dispatching=true;button.IsEnabled=false;try { await action(); } catch(Exception ex) { Status(ex.Message); } finally {_dispatching=false;Render();} }; return button;
    }
    protected void Actions(params Button[] buttons) { foreach(var button in buttons) View.Children.Add(button); }
    protected ComboBox Choices((string Key,string Label)[] choices,string selected,Action<string> change)
    {
        var combo=new ComboBox { HorizontalAlignment=HorizontalAlignment.Stretch,MinHeight=30,IsEnabled=!Busy };
        foreach(var item in choices)combo.Items.Add(item.Label);combo.SelectedIndex=Array.FindIndex(choices,x=>x.Key==selected);
        combo.SelectionChanged+=(_,_)=>{if(combo.SelectedIndex>=0){Selected.Clear();change(choices[combo.SelectedIndex].Key);Render();}};View.Children.Add(combo);return combo;
    }
    protected void Select(JsonElement item,string title,string detail="",bool enabled=true)
    {
        if(!Matches(title,detail))return;
        var id=Get(item,"id");var label=new StackPanel { Spacing=2 };label.Children.Add(Ui.Label(title,P.Text,14,true,true));if(detail.Length>0)label.Children.Add(Ui.Label(detail,P.Muted,12,wrap:true));
        var box=new CheckBox { Content=label,IsChecked=Selected.Contains(id),IsEnabled=!Busy&&enabled&&id.Length>0,MinHeight=30,HorizontalAlignment=HorizontalAlignment.Stretch,HorizontalContentAlignment=HorizontalAlignment.Stretch };
        AutomationProperties.SetName(box,title);box.Click+=(_,_)=>{if(box.IsChecked==true)Selected.Add(id);else Selected.Remove(id);UpdateSummary();};View.Children.Add(box);
    }
    protected async Task<JsonElement> LoadSettingsAsync()
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));var response=await Runtime.Platform.PostAsync("agent-clean-local-settings",Runtime.Platform.WithCredentials(),timeout.Token,15);Settings=response.GetProperty("settings").Clone();return response;
    }
    protected string[] Setting(string key)=>Rows(Settings,key).Select(x=>x.ToString()).ToArray();
    protected async Task SaveSettingsAsync(string[] exclusions,string[] folders,string[] performance,string[] drivers)
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));var response=await Runtime.Platform.PostAsync("agent-clean-local-settings",Runtime.Platform.WithCredentials(("save",true),("exclusions",exclusions),("custom_folders",folders),("perf_excluded",performance),("drivers_ignored",drivers)),timeout.Token,15);Settings=response.GetProperty("settings").Clone();
    }
    protected async Task<bool> RunAsync(string mode,Dictionary<string,object?> payload)
    {
        if(Busy||!Runtime.Allowed)return false;Busy=true;_operation=new();Status("Preparando operação…");Render();
        try {
            var progress=new Progress<string>(message=>Status(message));
            Report=await CleanEngine.RunAsync(mode,payload,Runtime.Platform.Config,progress,_operation.Token);_collected=DateTime.Now;Selected.Clear();
            var partial=Number(Report,"errors")>0||Number(Report,"failed")>0||Part(Report,"complete").ValueKind==JsonValueKind.False||Yes(Report,"truncated")||Get(Report,"created").Equals("False",StringComparison.OrdinalIgnoreCase)||Rows(Report,"results").Any(x=>Part(x,"ok").ValueKind==JsonValueKind.False);
            Status(partial?"Operação parcial. Revise as falhas e limitações do relatório.":"Execução concluída. Revise o relatório.");
            if(!mode.StartsWith("backup",StringComparison.Ordinal))try{await Runtime.Platform.PostAsync("agent-clean-local-result",Runtime.Platform.WithCredentials(("mode",mode),("tool",Kind),("report",Report)),_operation.Token,40);}catch(Exception ex){Status(_status.Text+" Relatório local preservado; envio pendente: "+ex.Message);}
            return true;
        }catch(OperationCanceledException){Status("Operação interrompida. Alterações concluídas não foram desfeitas.");return false;}
        catch(Exception ex){Status("Falha: "+ex.Message);return false;}
        finally{_operation.Dispose();_operation=null;Busy=false;Render();}
    }
    protected async Task ExecuteLocalAsync(Func<CancellationToken,Task> action)
    {
        if(Busy||!Runtime.Allowed)return;Busy=true;_operation=new();Render();
        try{await action(_operation.Token);_collected=DateTime.Now;}
        catch(OperationCanceledException){Status("Acompanhamento interrompido. Um instalador oficial já iniciado pode continuar no Windows; verifique antes de repetir.");}
        catch(Exception ex){Status("Falha: "+ex.Message);}
        finally{_operation.Dispose();_operation=null;Busy=false;Render();}
    }
    protected Task<bool> RunToolAsync(string action,params (string Key,object? Value)[] args)
    {
        var body=new Dictionary<string,object?>{{"tool",Kind},{"action",action}};foreach(var (key,value) in args)body[key]=value;return RunAsync("tool",body);
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
