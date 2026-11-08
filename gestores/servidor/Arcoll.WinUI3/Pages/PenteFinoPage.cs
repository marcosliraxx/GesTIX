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
public sealed class PenteFinoPage:UserControl
{
    private const string Origin="https://pentefino.tidigital.local/";
    private static readonly JsonSerializerOptions Json=new(){PropertyNamingPolicy=JsonNamingPolicy.CamelCase};
    private readonly GestorRuntime _runtime;private readonly Func<string,string,string,Task<bool>> _confirm;private readonly Action<string> _navigate;
    private readonly WebView2 _web=new(){HorizontalAlignment=HorizontalAlignment.Stretch,VerticalAlignment=VerticalAlignment.Stretch};
    private Palette _palette=new();private ResultsStore? _store;private Settings _settings=Settings.Default;private ScanReport? _report;private FixReport? _fix;private string _settingsHash="",_phase="",_notice="";private bool _busy,_ready,_loading,_historical=true,_cancelRequested;private CancellationTokenSource? _operation;private readonly SemaphoreSlim _messages=new(1,1);
    public PenteFinoPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm,Action<string> navigate){_runtime=runtime;_confirm=confirm;_navigate=navigate;Content=_web;Loaded+=async(_,_)=>await InitializeAsync();}
    public void ApplyPalette(Palette palette){_palette=palette;Publish();}
    public void CancelOperations(){_cancelRequested=true;_operation?.Cancel();Publish();}
    private ResultsStore Store(){if(_store is not null)return _store;using var identity=WindowsIdentity.GetCurrent();var scope=ResultsStore.Hash(identity.User?.Value??Environment.UserName)[..24];var root=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital","PenteFino",scope);_store=new ResultsStore(root);_settings=_store.LoadSettings() with { Mode="detailed" };_report=_store.Last();new FixEngine(_store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions)).Expire();return _store;}
    private static string Text(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.String?p.GetString()??"":"";
    private static string[] Strings(JsonElement e,string key,int maximum)=>e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.Array&&p.GetArrayLength()<=maximum?p.EnumerateArray().Select(x=>x.ValueKind==JsonValueKind.String?x.GetString()??"":throw new InvalidDataException("Lista inválida.")).ToArray():[];
    private async Task InitializeAsync(){if(_ready||_loading)return;_loading=true;try{Store();var folder=Path.Combine(AppContext.BaseDirectory,"Assets","hybrid","pentefino");if(!File.Exists(Path.Combine(folder,"index.html")))throw new FileNotFoundException("Interface Pente-Fino não instalada.");var profile=Store().LocalPath("WebView2");Directory.CreateDirectory(profile);var env=await CoreWebView2Environment.CreateWithOptionsAsync(null,profile,null);await _web.EnsureCoreWebView2Async(env);var web=_web.CoreWebView2;web.Settings.AreDevToolsEnabled=false;web.Settings.AreDefaultContextMenusEnabled=false;web.Settings.AreHostObjectsAllowed=false;web.SetVirtualHostNameToFolderMapping("pentefino.tidigital.local",folder,CoreWebView2HostResourceAccessKind.DenyCors);web.NavigationStarting+=(_,e)=>{if(!e.Uri.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))e.Cancel=true;};web.NewWindowRequested+=(_,e)=>e.Handled=true;
        web.WebMessageReceived+=async(_,e)=>{if(!e.Source.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))return;try{using var doc=JsonDocument.Parse(e.WebMessageAsJson);var root=doc.RootElement;var action=Text(root,"action");if(action=="workspace-footer")return;if(action=="stop"){CancelOperations();return;}if(!await _messages.WaitAsync(0))return;try{await HandleAsync(action,root);}finally{_messages.Release();}}catch(Exception error){_notice=error.Message;Publish();}};
        web.NavigationCompleted+=(_,e)=>{if(!e.IsSuccess){_notice="Não foi possível abrir a interface local.";return;}_ready=true;Publish();};_web.Source=new Uri(Origin+"index.html");
    }catch(Exception error){Content=Ui.Label("Pente-Fino indisponível: "+error.Message+" Atualize o gestor e verifique o WebView2.",_palette.Text,14,wrap:true);}finally{_loading=false;}}
    private async Task HandleAsync(string action,JsonElement body){if(action=="ready"){_ready=true;Store();Publish();return;}if(!_runtime.Allowed)throw new UnauthorizedAccessException("Autorize o gestor na plataforma.");if(action=="navigate"){var target=Text(body,"target");if(target is "startup" or "updater" or "registry")_navigate(target);return;}var store=Store();if(_busy)return;
        if(action=="settings"){
            var mode=Text(body,"mode");var modules=Strings(body,"modules",5);var exclusions=Strings(body,"exclusions",100);var trusted=Strings(body,"trustedCookies",200).Select(x=>x.Trim().TrimStart('.').ToLowerInvariant()).Distinct().ToArray();
            if(mode!="detailed"||modules.Length==0||modules.Distinct().Count()!=modules.Length||modules.Any(x=>!ScanOrchestrator.ModuleIds.Contains(x))||exclusions.Any(x=>x.Length>1000||!Path.IsPathRooted(x))||trusted.Any(x=>!Regex.IsMatch(x,@"^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")))throw new InvalidDataException("Módulos, exclusões ou domínios inválidos. Use caminhos absolutos e domínios sem https://.");
            _settings=new(mode,modules,exclusions,trusted,_settings.Ignored);store.SaveSettings(_settings);_historical=true;_notice="Preferências salvas. Faça uma nova análise antes de corrigir.";Publish();return;
        }
        if(action=="ignore"){
            if(_report is null)return;var ids=Strings(body,"ids",20000);var known=_report.Modules.SelectMany(x=>x.Items).Select(x=>x.Id).ToHashSet();if(ids.Any(x=>!known.Contains(x)))throw new InvalidDataException("Item fora do relatório.");_settings=_settings with{Ignored=_settings.Ignored.Concat(ids).Distinct().ToArray()};store.SaveSettings(_settings);_historical=true;_notice="Itens ignorados de forma persistente. Faça nova análise para atualizar o escopo.";Publish();return;
        }
        if(action=="clearIgnored"){_settings=_settings with{Ignored=[]};store.SaveSettings(_settings);_historical=true;_notice="Itens ignorados removidos das preferências; refaça a análise.";Publish();return;}
        if(action=="export"){
            if(_report is null)return;var path=store.Export(_report,Text(body,"format")=="html");var package=new DataPackage();package.SetText(path);Clipboard.SetContent(package);_notice="Relatório exportado. Caminho copiado: "+path;Publish();return;
        }
        _operation=new CancellationTokenSource();_busy=true;_phase=action;_cancelRequested=false;_notice="";Publish();
        try{
            if(action=="scan"){
                // A escolha do círculo é parte do próprio comando: evita perder o scan
                // enquanto uma mensagem separada de preferências ainda é processada.
                var requestedMode=Text(body,"mode");
                if(requestedMode=="detailed"){
                    _settings=_settings with {Mode=requestedMode};
                    store.SaveSettings(_settings);
                }
                var options=new ScanOptions("detailed",_settings.Modules,_settings.Exclusions,_settings.TrustedCookies);var policy=new PathPolicy(PathPolicy.WindowsRules(options.Mode),options.Exclusions);_settingsHash=ResultsStore.Hash(JsonSerializer.Serialize(_settings));_fix=null;_report=null;
                var progress=new Progress<ProgressState>(state=>Post(new{type="progress",state}));_report=await new ScanOrchestrator(store).ScanAsync(options,policy,progress,_operation.Token);_historical=_report.Cancelled;_notice=_report.Cancelled?"Análise interrompida; nenhuma correção foi aplicada.":"Análise concluída. Revise e selecione os itens antes de corrigir.";
            }else if(action=="fix"){
                if(_report is null||_historical||_report.Cancelled||_settingsHash!=ResultsStore.Hash(JsonSerializer.Serialize(store.LoadSettings())))throw new InvalidOperationException("É necessária uma nova análise com as preferências atuais.");var ids=Strings(body,"ids",20000);var lookup=_report.Modules.SelectMany(x=>x.Items).ToDictionary(x=>x.Id);if(ids.Length==0||ids.Any(x=>!lookup.TryGetValue(x,out var item)||!item.CanFix))throw new InvalidDataException("Selecione itens corrigíveis do relatório.");
                var description=string.Join("\n",ids.Take(12).Select(id=>lookup[id].Title+" · "+lookup[id].Action));if(ids.Length>12)description+="\n… e mais "+(ids.Length-12)+" item(ns).";
                if(!await _confirm("Confirmar correção Pente-Fino",description+"\n\nAplicar somente os "+ids.Length+" itens selecionados? Arquivos serão guardados em quarentena por 30 dias. Cookies podem encerrar logins. Inicialização altera somente StartupApproved. Registro exige um novo ponto de restauração. Backups no mesmo disco não liberam o espaço ocupado até expirar. Cancelar para as próximas etapas; desfazer é separado.","Corrigir com backup")||!_runtime.Allowed)return;
                _fix=await new FixEngine(store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions)).FixAsync(_report,ids,_operation.Token,new Progress<FixProgressState>(progress=>Post(new{type="fix-progress",state=progress})));_historical=true;_notice=_fix.Cancelled?"Correção interrompida. Etapas aplicadas estão no histórico e podem ser desfeitas.":"Correção concluída. Revise falhas por item e o histórico de backups.";
            }else if(action=="undo"){
                var session=Text(body,"session");if(!Regex.IsMatch(session,"^[a-f0-9]{32}$"))throw new InvalidDataException("Sessão inválida.");if(!await _confirm("Desfazer Pente-Fino","Restaurar as alterações desta sessão? Arquivos/registro alterados posteriormente serão preservados e informados como conflito.","Desfazer")||!_runtime.Allowed)return;var result=await new FixEngine(store,new PathPolicy(PathPolicy.WindowsRules(_settings.Mode),_settings.Exclusions)).UndoAsync(session,_operation.Token);_historical=true;_notice=string.Join(" · ",result.Select(x=>(x.Ok?"OK: ":"Conflito/falha: ")+x.Message));
            }
        }catch(OperationCanceledException){_notice="Operação interrompida. Consulte o histórico das etapas concluídas; cancelar não desfaz etapas aplicadas.";_historical=true;}
        finally{_busy=false;_phase="";_operation.Dispose();_operation=null;Publish();}
    }
    private static string Color(Brush brush)=>brush is SolidColorBrush b?$"#{b.Color.R:X2}{b.Color.G:X2}{b.Color.B:X2}":"#2563eb";
    private void Post(object data){if(!_ready)return;try{_web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(data,Json));}catch(InvalidOperationException){}}
    private void Publish(){Post(new{type="state",allowed=_runtime.Allowed,busy=_busy,phase=_phase,cancelRequested=_cancelRequested,notice=_notice,settings=_settings,report=_report,fix=_fix,historical=_historical,
        backups=_store?.UndoEntries().Where(x=>x.State is "applied" or "prepared").GroupBy(x=>x.Session).Select(g=>new{session=g.Key,at=g.Max(x=>x.At),items=g.Count(),pending=g.Any(x=>x.State=="prepared")}),
        auditOk=_store?.VerifyAudit(),root=_store?.Root,theme=new{light=_palette.Light,accent=Color(_palette.Accent),surface=Color(_palette.Surface),text=Color(_palette.Text),muted=Color(_palette.Muted),alt=Color(_palette.Alt),line=Color(_palette.Line)}});}
}
