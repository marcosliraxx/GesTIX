// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/PenteFinoPage.cs
using System.Security.Principal;
using System.Text.Json;
using System.Text.RegularExpressions;
using Arcoll.WinUI3.Services;
using Arcoll.WinUI3.Services.PenteFino;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.Web.WebView2.Core;
using Windows.ApplicationModel.DataTransfer;
namespace Arcoll.WinUI3.Pages;
/// <summary>Interface local do Pente-Fino; confirmar/corrigir ocorre no host nativo, não no JavaScript.</summary>
public sealed class PenteFinoPage:UserControl,IPageDataSource
{
 private bool _dataBridgeConfigured;
 private readonly PageDataLoad _analysisLoad=new("Abrindo o Pente-Fino",true);
 public PageDataLoad DataLoad=>_activeTool is IPageDataSource source?source.DataLoad:_analysisLoad;
 public Task CollectDataAsync()=>_activeTool is IPageDataSource source?source.CollectDataAsync():InitializeAsync();
 public bool CanCancelCollection=>_activeTool is IPageDataSource source&&source.CanCancelCollection;
 public void CancelCollection(){if(_activeTool is IPageDataSource source)source.CancelCollection();}
 public UserControl? ActiveTool=>_activeTool;
 public string ActiveTitle=>_integrated.Values.FirstOrDefault(t=>ReferenceEquals(t.Page,_activeTool)).Title??"Pente-Fino";
 public bool HasToolOptions=>_activeTool is MaintenancePage;
 private UserControl? _activeTool;private readonly Grid _center=new();private readonly StackPanel _rail=new(){Spacing=3,Padding=new Thickness(7)};private readonly ContentControl _area=new(){HorizontalContentAlignment=HorizontalAlignment.Stretch,VerticalContentAlignment=VerticalAlignment.Stretch};
 private readonly Dictionary<string,(string Title,UserControl Page)> _integrated=[];private readonly Dictionary<string,Button> _railButtons=[];private readonly List<TextBlock> _railHeadings=[];
 public event Action? ToolChanged;
 public static readonly string[] IntegratedKeys=["clean","registry","startup","performance","restore","security","uninstall","updater","drivers","windows_update","disk","duplicates","cloud"];
 public void ConfigureTools(Func<string,UserControl> resolve){Content=null;
  _center.ColumnDefinitions.Add(new(){Width=new GridLength(156)});_center.ColumnDefinitions.Add(new(){Width=new GridLength(1,GridUnitType.Star)});
  var railScroll=new ScrollViewer{Content=_rail,VerticalScrollBarVisibility=ScrollBarVisibility.Hidden,HorizontalScrollBarVisibility=ScrollBarVisibility.Disabled};_center.Children.Add(railScroll);Grid.SetColumn(_area,1);_center.Children.Add(_area);_area.Content=_web;Content=_center;
  void Heading(string text){var heading=new TextBlock{Text=text,FontSize=9,Margin=new Thickness(8,12,0,5),CharacterSpacing=90,Opacity=.7};_railHeadings.Add(heading);_rail.Children.Add(heading);}
  void Link(string key,string title){
   var glyph=key switch{"analysis"=>Symbol.Filter,"clean"=>Symbol.Delete,"registry"=>Symbol.Repair,"startup"=>Symbol.Play,"performance"=>Symbol.View,"restore"=>Symbol.Undo,"security"=>Symbol.Important,"uninstall"=>Symbol.Remove,"updater"=>Symbol.Refresh,"drivers"=>Symbol.AllApps,"windows_update"=>Symbol.Download,"disk"=>Symbol.Folder,"duplicates"=>Symbol.Copy,_=>Symbol.World};
   var label=new StackPanel{Orientation=Orientation.Horizontal,Spacing=8};label.Children.Add(new SymbolIcon(glyph){Width=16,Height=16});label.Children.Add(new TextBlock{Text=title,FontSize=11,VerticalAlignment=VerticalAlignment.Center});
   var b=new Button{Content=label,HorizontalAlignment=HorizontalAlignment.Stretch,HorizontalContentAlignment=HorizontalAlignment.Left,Padding=new Thickness(8,9,5,9),BorderThickness=new Thickness(0),CornerRadius=new CornerRadius(0)};b.Click+=(_,_)=>OpenTool(key);_railButtons[key]=b;_rail.Children.Add(b);
  }

  Heading("PENTE-FINO");Link("analysis","Análise geral");
  foreach(var group in new[]{("SISTEMA",new[]{("clean","Limpeza"),("registry","Registro"),("startup","Inicialização"),("performance","Desempenho"),("restore","Restauração"),("security","Segurança")}), ("APLICATIVOS",new[]{("uninstall","Desinstalar"),("updater","Atualizações"),("drivers","Drivers"),("windows_update","Windows Update")}), ("ARMAZENAMENTO",new[]{("disk","Discos"),("duplicates","Duplicados"),("cloud","Sincronizados")})}){Heading(group.Item1);foreach(var item in group.Item2){_integrated[item.Item1]=(item.Item2,resolve(item.Item1));if(item.Item1 is not("clean" or "registry" or "startup"))Link(item.Item1,item.Item2);}}
  SizeChanged+=(_,e)=>_center.ColumnDefinitions[0].Width=new GridLength(e.NewSize.Width<700?128:156);PaintRail();
 }
 public void OpenTool(string key){if(_busy){_notice="Aguarde a operação atual ou interrompa antes de trocar de ferramenta.";Publish();return;}if(key=="analysis"){_activeTool=null;_area.Content=_web;}else if(_integrated.TryGetValue(key,out var tool)){_activeTool=tool.Page;_area.Content=tool.Page;}else return;PaintRail();ToolChanged?.Invoke();}
 private void PaintRail(){_center.Background=_palette.Surface;_rail.Background=_palette.Alt;foreach(var heading in _railHeadings)heading.Foreground=_palette.Muted;foreach(var item in _railButtons){var active=item.Key=="analysis"?_activeTool is null:_integrated.TryGetValue(item.Key,out var t)&&ReferenceEquals(t.Page,_activeTool);item.Value.Foreground=_palette.Text;item.Value.BorderBrush=_palette.Accent;item.Value.BorderThickness=new Thickness(active?2:0,0,0,0);item.Value.Background=active?_palette.Line:_palette.Alt;}}


    private const string Origin="https://pentefino.tidigital.local/";
    private static readonly JsonSerializerOptions Json=new(){PropertyNamingPolicy=JsonNamingPolicy.CamelCase};
    private readonly GestorRuntime _runtime;private readonly Func<string,string,string,Task<bool>> _confirm;private readonly Action<string> _navigate;
    private readonly WebView2 _web=new(){HorizontalAlignment=HorizontalAlignment.Stretch,VerticalAlignment=VerticalAlignment.Stretch};
    private object? _outcome;private bool _auditOk=true;private object[] _backups=[];private ScanReport? _summaryFor;private ReportSummary? _summary;private Palette _palette=new();private ResultsStore? _store;private Settings _settings=Settings.Default;private ScanReport? _report;private FixReport? _fix;private string _settingsHash="",_phase="",_notice="",_requestId="";private bool _busy,_ready,_loading,_historical=true,_cancelRequested;private CancellationTokenSource? _operation;private readonly SemaphoreSlim _messages=new(1,1);
    public PenteFinoPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm,Action<string> navigate){_runtime=runtime;_confirm=confirm;_navigate=navigate;Content=_web;Loaded+=async(_,_)=>await InitializeAsync();}
    public void ApplyPalette(Palette palette){_palette=palette;PaintRail();Publish();}
    public void CancelOperations(){_cancelRequested=true;_operation?.Cancel();Publish();}
    private ResultsStore Store(){if(_store is not null)return _store;using var identity=WindowsIdentity.GetCurrent();var scope=ResultsStore.Hash(identity.User?.Value??Environment.UserName)[..24];var root=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital","PenteFino",scope);_store=new ResultsStore(root);_settings=_store.LoadSettings() with { Mode="detailed" };if(_settings.Preferences is null){var preferences=GeneralAnalysisEngine.Defaults(CleanEngine.Catalog());_settings=_settings with{Preferences=preferences,Modules=GeneralAnalysisEngine.Modules(preferences)};_store.SaveSettings(_settings);}_report=_store.Last();new FixEngine(_store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions)).Expire();RefreshLocalSummary(_store);return _store;}
    private void RefreshLocalSummary(ResultsStore store){_auditOk=store.VerifyAudit();_backups=store.UndoEntries().Where(x=>x.State is "applied" or "prepared").GroupBy(x=>x.Session).Select(g=>new{session=g.Key,at=g.Max(x=>x.At),items=g.Count(),pending=g.Any(x=>x.State=="prepared")}).OrderByDescending(x=>x.at).Take(50).Cast<object>().ToArray();}
    private ReportSummary? Summary(){if(_report is null)return null;if(!ReferenceEquals(_summaryFor,_report)){_summary=Presentation.Summarize(_report);_summaryFor=_report;}return _summary;}
    private static string Text(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.String?p.GetString()??"":"";
    private static string[] Strings(JsonElement e,string key,int maximum)=>e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.Array&&p.GetArrayLength()<=maximum?p.EnumerateArray().Select(x=>x.ValueKind==JsonValueKind.String?x.GetString()??"":throw new InvalidDataException("Lista inválida.")).ToArray():[];
    private async Task InitializeAsync(){if(_ready||_loading)return;_loading=true;_analysisLoad.Begin("Abrindo o Pente-Fino");try{if(_dataBridgeConfigured&&_web.CoreWebView2 is not null){_web.CoreWebView2.Reload();return;}await Task.Run(()=>{Store();});var folder=Path.Combine(AppContext.BaseDirectory,"Assets","hybrid","pentefino");if(!File.Exists(Path.Combine(folder,"index.html")))throw new FileNotFoundException("Interface Pente-Fino não instalada.");var profile=Store().LocalPath("WebView2");Directory.CreateDirectory(profile);var env=await CoreWebView2Environment.CreateWithOptionsAsync(null,profile,null);await _web.EnsureCoreWebView2Async(env);var web=_web.CoreWebView2??throw new InvalidOperationException("WebView2 não inicializado.");web.Settings.AreDevToolsEnabled=false;web.Settings.AreDefaultContextMenusEnabled=false;web.Settings.AreHostObjectsAllowed=false;web.SetVirtualHostNameToFolderMapping("pentefino.tidigital.local",folder,CoreWebView2HostResourceAccessKind.DenyCors);web.NavigationStarting+=(_,e)=>{if(!e.Uri.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))e.Cancel=true;};web.NewWindowRequested+=(_,e)=>e.Handled=true;
        web.WebMessageReceived+=async(_,e)=>{if(!e.Source.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))return;try{using var doc=JsonDocument.Parse(e.WebMessageAsJson);var root=doc.RootElement;var action=Text(root,"action");if(action=="workspace-footer")return;if(action=="stop"){CancelOperations();return;}if(!await _messages.WaitAsync(0))return;try{await HandleAsync(action,root);}finally{_messages.Release();}}catch(Exception error){_notice=error.Message;Publish();}};
        web.NavigationCompleted+=(_,e)=>{if(!e.IsSuccess){_notice="Não foi possível abrir a interface local.";_analysisLoad.Fail(_notice);return;}_ready=true;Publish();};_dataBridgeConfigured=true;_web.Source=new Uri(Origin+"index.html");
    }catch(Exception error){_analysisLoad.Fail("Não foi possível abrir esta área: "+error.Message);_notice="Pente-Fino indisponível: "+error.Message;}finally{_loading=false;}}
    private async Task HandleAsync(string action,JsonElement body){var requestId=Text(body,"requestId");if(Regex.IsMatch(requestId,"^[a-zA-Z0-9_-]{1,64}$"))_requestId=requestId;if(action=="ready"){_ready=true;Store();Publish();_analysisLoad.Complete();return;}if(!_runtime.Allowed)throw new UnauthorizedAccessException("Autorize o gestor na plataforma.");if(action=="navigate"){var target=Text(body,"target");if(IntegratedKeys.Contains(target))OpenTool(target);return;}var store=Store();if(_busy)return;
        if(action=="settings"){
            if(!body.TryGetProperty("preferences",out var input))throw new InvalidDataException("Configurações ausentes.");
            var preferences=GeneralAnalysisEngine.Validate(input.Deserialize<AnalysisPreferences>(new JsonSerializerOptions{PropertyNameCaseInsensitive=true})??throw new InvalidDataException("Configurações inválidas."),CleanEngine.Catalog());
            var modules=GeneralAnalysisEngine.Modules(preferences);if(modules.Length==0)throw new InvalidDataException("Ative ao menos uma verificação.");
            _settings=_settings with{Mode="detailed",Modules=modules,Preferences=preferences};store.SaveSettings(_settings);_historical=true;_notice="Preferências salvas. Faça uma nova análise antes de corrigir.";Publish();return;
        }
        if(action=="ignore"){
            if(_report is null)return;var ids=Strings(body,"ids",20000);var known=_report.Modules.SelectMany(x=>x.Items).Select(x=>x.Id).ToHashSet();if(ids.Any(x=>!known.Contains(x)))throw new InvalidDataException("Item fora do relatório.");_settings=_settings with{Ignored=_settings.Ignored.Concat(ids).Distinct().ToArray()};store.SaveSettings(_settings);_historical=true;_notice="Itens ignorados de forma persistente. Faça nova análise para atualizar o escopo.";Publish();return;
        }
        if(action=="clearIgnored"){_settings=_settings with{Ignored=[]};store.SaveSettings(_settings);_historical=true;_notice="Itens ignorados removidos das preferências; refaça a análise.";Publish();return;}
        if(action=="export"){
            if(_report is null)return;var path=store.Export(_report,Text(body,"format")=="html");var package=new DataPackage();package.SetText(path);Clipboard.SetContent(package);_notice="Relatório exportado. Caminho copiado: "+path;Publish();return;
        }
        var operationRequestId=_requestId;_operation=new CancellationTokenSource();_outcome=null;_busy=true;_phase=action;_cancelRequested=false;_notice="";Publish();
        try{
            if(action=="scan"){
                // A escolha do círculo é parte do próprio comando: evita perder o scan
                // enquanto uma mensagem separada de preferências ainda é processada.
                _historical=true;await SyncAnalysisPathsAsync(false);
                if(_settings.Preferences is null)throw new InvalidDataException("Configure as verificações da análise.");
                GeneralAnalysisEngine.Validate(_settings.Preferences,CleanEngine.Catalog());
                _settings=_settings with {Mode="detailed",Modules=GeneralAnalysisEngine.Modules(_settings.Preferences)};store.SaveSettings(_settings);
                var options=new ScanOptions("detailed",_settings.Modules,_settings.Exclusions,_settings.TrustedCookies);var policy=new PathPolicy(PathPolicy.WindowsRules(options.Mode),options.Exclusions);_settingsHash=ResultsStore.Hash(JsonSerializer.Serialize(_settings));_fix=null;_report=null;
                var progress=new Progress<ProgressState>(state=>Post(new{type="progress",requestId=operationRequestId,state}));_report=await new ScanOrchestrator(store,new GeneralAnalysisEngine(_runtime.Platform.Config,_settings.Preferences!,_settings.Exclusions,_settings.CustomFolders).Checkers()).ScanAsync(options,policy,progress,_operation.Token);_historical=_report.Cancelled;_notice=_report.Cancelled?"Análise interrompida; nenhuma correção foi aplicada.":"Análise concluída. Revise e selecione os itens antes de corrigir.";
            }else if(action=="fix"){
                await SyncAnalysisPathsAsync(true);
                if(_activeTool is not null)throw new InvalidOperationException("Volte à análise geral antes de corrigir.");
                if(_report is null||_historical||_report.Cancelled||_settingsHash!=ResultsStore.Hash(JsonSerializer.Serialize(store.LoadSettings())))throw new InvalidOperationException("É necessária uma nova análise com as preferências atuais.");var selectedModules=Strings(body,"modules",5);var ids=Presentation.CorrectionIds(_report,Text(body,"reportId"),selectedModules);var summary=Presentation.Summarize(_report);
                var description=string.Join("\n",summary.Modules.Where(m=>selectedModules.Contains(m.Id)&&m.Fixable>0).Select(m=>m.Title+": "+m.Fixable+" ajuste(s)"));
                _fix=null;
                var selectedQuantity=_report.Modules.SelectMany(m=>m.Items).Where(i=>ids.Contains(i.Id)).Sum(i=>Math.Max(1,i.Quantity));var decision=await ConfirmCorrectionAsync(selectedQuantity);
                if(!decision.Confirmed||!_runtime.Allowed){_notice="Correção cancelada; nenhuma alteração foi iniciada.";return;}

                var modulesById=_report.Modules.SelectMany(m=>m.Items.Select(i=>new{ i.Id,Module=m.Id})).DistinctBy(i=>i.Id).ToDictionary(i=>i.Id,i=>i.Module);
                var fixProgress=new Progress<FixProgressState>(progress=>Post(new{type="fix-progress",requestId=operationRequestId,state=new{progress.Completed,progress.Total,progress.State,progress.Succeeded,progress.Failed,module=progress.Id is not null?modulesById.GetValueOrDefault(progress.Id):null}}));
                _fix=await Task.Run(()=>new FixEngine(store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions),new GeneralAnalysisEngine(_runtime.Platform.Config,_settings.Preferences!,_settings.Exclusions,_settings.CustomFolders).FixAsync).FixAsync(_report,ids,_operation.Token,fixProgress,decision.RestorePoint));
                _outcome=new{kind="fix",session=_fix.Session,applied=_fix.Results.Where(r=>r.Ok).Sum(r=>_report.Modules.SelectMany(m=>m.Items).First(i=>i.Id==r.Id).Quantity),failed=_fix.Results.Where(r=>!r.Ok).Sum(r=>_report.Modules.SelectMany(m=>m.Items).First(i=>i.Id==r.Id).Quantity),pending=Math.Max(0,selectedQuantity-_fix.Results.Sum(r=>_report.Modules.SelectMany(m=>m.Items).First(i=>i.Id==r.Id).Quantity)),cancelled=_fix.Cancelled,countsPartial=_fix.CountsPartial,errors=_fix.Results.Where(r=>!r.Ok).Select(r=>r.Message).Distinct().Take(8).ToArray()};_historical=true;_notice=_fix.Cancelled?"Correção interrompida. As etapas já aplicadas permanecem concluídas.":"Correção concluída. Revise o resultado e faça nova análise quando desejar.";
            }else if(action=="undo"){
                var session=Text(body,"session");if(!Regex.IsMatch(session,"^[a-f0-9]{32}$"))throw new InvalidDataException("Sessão inválida.");if(!await _confirm("Desfazer Pente-Fino","Restaurar as alterações desta sessão? Arquivos/registro alterados posteriormente serão preservados e informados como conflito.","Desfazer")||!_runtime.Allowed)return;var result=await Task.Run(()=>new FixEngine(store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions)).UndoAsync(session,_operation.Token));_outcome=new{kind="undo",session,applied=result.Count(r=>r.Ok),failed=result.Count(r=>!r.Ok),pending=0,cancelled=false,errors=result.Where(r=>!r.Ok).Select(r=>r.Message).Distinct().Take(8).ToArray()};_historical=true;_notice=string.Join(" · ",result.Select(x=>(x.Ok?"OK: ":"Conflito/falha: ")+x.Message));
            }
        }catch(OperationCanceledException){_notice="Operação interrompida. Consulte o histórico das etapas concluídas; cancelar não desfaz etapas aplicadas.";_historical=true;}
        catch(Exception error){_notice="Não foi possível concluir: "+error.Message;RuntimeLog.Write("Pente-Fino/"+action+": "+error);}
        finally{_phase="";_operation.Dispose();_operation=null;try{await Task.Run(()=>RefreshLocalSummary(store));}catch(Exception error){_auditOk=false;_notice="O histórico precisa de revisão: "+error.Message;} _busy=false;Publish();}
    }
    private async Task SyncAnalysisPathsAsync(bool validateOnly){
     var response=await _runtime.Platform.PostAsync("gestor-clean-local-settings",_runtime.Platform.WithCredentials(),_operation?.Token??CancellationToken.None,20);var settings=response.GetProperty("settings");
     var exclusions=Strings(settings,"exclusions",100);var folders=Strings(settings,"custom_folders",20);
     var combined=_settings.Exclusions.Concat(exclusions).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(s=>s,StringComparer.OrdinalIgnoreCase).ToArray();
     if(validateOnly){if(!combined.SequenceEqual(_settings.Exclusions,StringComparer.OrdinalIgnoreCase)||!folders.SequenceEqual(_settings.CustomFolders??[],StringComparer.OrdinalIgnoreCase))throw new InvalidOperationException("As exclusões ou pastas da plataforma mudaram. Faça uma nova análise.");return;}
     _settings=_settings with{Exclusions=combined,CustomFolders=folders};Store().SaveSettings(_settings);
    }
    private async Task<(bool Confirmed,bool RestorePoint)> ConfirmCorrectionAsync(int count){
     var restore=new CheckBox{Content="Criar ponto de restauração no Windows",IsChecked=false,FontSize=12,Foreground=_palette.Text};
     var body=new StackPanel{Spacing=12,MaxWidth=370};body.Children.Add(new TextBlock{Text=count.ToString("N0")+" ajustes selecionados. As correções não guardam cópias de recuperação. Cookies removidos podem encerrar sessões.",TextWrapping=TextWrapping.Wrap,FontSize=12,Foreground=_palette.Muted});body.Children.Add(restore);
     var dialog=new ContentDialog{XamlRoot=XamlRoot,Title="Confirma ajustes e correções?",Content=body,PrimaryButtonText="Corrigir",CloseButtonText="Cancelar",DefaultButton=ContentDialogButton.Close,Background=_palette.Surface,Foreground=_palette.Text,RequestedTheme=_palette.Light?ElementTheme.Light:ElementTheme.Dark};
     return(await dialog.ShowAsync()==ContentDialogResult.Primary,restore.IsChecked==true);
    }
    private static string Color(Brush brush)=>brush is SolidColorBrush b?$"#{b.Color.R:X2}{b.Color.G:X2}{b.Color.B:X2}":"#2563eb";
    private void Post(object data){if(!_ready)return;try{_web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(data,Json));}catch(InvalidOperationException){}}
    private void Publish(){Post(new{type="state",allowed=_runtime.Allowed,busy=_busy,phase=_phase,cancelRequested=_cancelRequested,notice=_notice,settings=_settings,requestId=_requestId,report=Summary(),outcome=_outcome,historical=_historical,
        catalog=CleanEngine.Catalog(),backups=_backups,auditOk=_auditOk,theme=new{light=_palette.Light,accent=Color(_palette.Accent),surface=Color(_palette.Surface),text=Color(_palette.Text),muted=Color(_palette.Muted),alt=Color(_palette.Alt),line=Color(_palette.Line)}});}
}
