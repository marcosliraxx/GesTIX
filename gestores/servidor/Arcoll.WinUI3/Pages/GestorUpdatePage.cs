// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/GestorUpdatePage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3.Pages;
public sealed class GestorUpdatePage:UserControl,IPageDataSource
{
 private bool _dataBridgeConfigured;
 public PageDataLoad DataLoad{get;}=new("Abrindo a central de atualização",true);
 public Task CollectDataAsync()=>InitializeAsync();

 private const string Origin="https://update.tidigital.local/";private readonly GestorRuntime _runtime;private readonly Func<bool,Task> _run;private readonly Func<Task<UpdateAvailability>> _check;private readonly WebView2 _web=new(){HorizontalAlignment=HorizontalAlignment.Stretch,VerticalAlignment=VerticalAlignment.Stretch};private bool _ready,_loading,_running,_checking;private GestorAreaColors? _areas;private string _error="",_view="update";private UpdateAvailability? _availability;
 public GestorUpdatePage(GestorRuntime runtime,Func<bool,Task> run,Func<Task<UpdateAvailability>> check){_runtime=runtime;_run=run;_check=check;Content=_web;Loaded+=async(_,_)=>await InitializeAsync();}
 public void ApplyAreas(GestorAreaColors areas){_areas=areas;Publish();}
 public void Navigate(string view){_view=view=="history"?"history":"update";Publish();}
 public void Publish(){if(!_ready)return;try{var history=_view=="history"?UpdateHistoryStore.Read():Array.Empty<UpdateHistoryEntry>();_web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new{type="state",view=_view,state=UpdatePresentation.Current,allowed=_runtime.Allowed,areas=_areas,error=_error,checking=_checking,availability=_availability,history,historyWarning=UpdateHistoryStore.Warning},new JsonSerializerOptions{PropertyNamingPolicy=JsonNamingPolicy.CamelCase}));}catch(InvalidOperationException){}}
 private async Task InitializeAsync(){if(_ready||_loading)return;_loading=true;DataLoad.Begin("Abrindo a central de atualização");Content=_web;try{if(_dataBridgeConfigured&&_web.CoreWebView2 is not null){_web.CoreWebView2.Reload();return;}var folder=Path.Combine(AppContext.BaseDirectory,"Assets","hybrid","dashboard");var profile=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital","UpdateWebView");Directory.CreateDirectory(profile);var env=await CoreWebView2Environment.CreateWithOptionsAsync(null,profile,null);await _web.EnsureCoreWebView2Async(env);var core=_web.CoreWebView2;core.Settings.AreDevToolsEnabled=false;core.Settings.AreDefaultContextMenusEnabled=false;core.Settings.AreHostObjectsAllowed=false;core.SetVirtualHostNameToFolderMapping("update.tidigital.local",folder,CoreWebView2HostResourceAccessKind.DenyCors);core.NavigationStarting+=(_,e)=>{if(!e.Uri.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))e.Cancel=true;};core.NewWindowRequested+=(_,e)=>e.Handled=true;
  core.WebMessageReceived+=async(_,e)=>{if(!e.Source.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))return;try{using var doc=JsonDocument.Parse(e.WebMessageAsJson);var action=doc.RootElement.GetProperty("action").GetString();if(action=="ready"){_ready=true;Publish();DataLoad.Complete();return;}if(action=="cancel"){UpdateService.CancelPreparation();return;}if(_running||_checking)return;
   if(action=="check"){if(!_runtime.Allowed)throw new UnauthorizedAccessException("Autorize o gestor para verificar.");_checking=true;_error="";_availability=null;Publish();try{_availability=await _check();}finally{_checking=false;Publish();}return;}
   if(action is not("update" or "force"))return;if(_availability is null)throw new InvalidOperationException("Clique no círculo para verificar a publicação antes de continuar.");if(action=="update"&&!_availability.Available)throw new InvalidOperationException("Nenhum arquivo novo foi identificado. Use reinstalar para conferir novamente todos os arquivos.");_running=true;_error="";Publish();try{await _run(action=="force");}finally{_running=false;_availability=null;Publish();}
  }catch(Exception error){_error=error.Message;Publish();}};_dataBridgeConfigured=true;_web.Source=new Uri(Origin+"update.html");}
 catch(Exception error){DataLoad.Fail("Não foi possível abrir esta área: "+error.Message);Content=Ui.Label("Central de atualização indisponível: "+error.Message,ThemeService.Brush(_areas?.Text??"#172B44"),14,wrap:true);}finally{_loading=false;}}
}
