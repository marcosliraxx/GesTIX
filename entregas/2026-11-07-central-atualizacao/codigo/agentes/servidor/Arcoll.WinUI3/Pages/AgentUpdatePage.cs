using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3.Pages;
public sealed class AgentUpdatePage:UserControl
{
 private const string Origin="https://update.tidigital.local/";private readonly AgentRuntime _runtime;private readonly Func<bool,Task> _run;private readonly WebView2 _web=new(){HorizontalAlignment=HorizontalAlignment.Stretch,VerticalAlignment=VerticalAlignment.Stretch};private bool _ready,_loading,_running;private Palette _palette=new();private string _error="";
 public AgentUpdatePage(AgentRuntime runtime,Func<bool,Task> run){_runtime=runtime;_run=run;Content=_web;Loaded+=async(_,_)=>await InitializeAsync();}
 public void ApplyPalette(Palette palette){_palette=palette;Publish();}
 public void Publish(){if(!_ready)return;try{_web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(new{type="state",state=UpdatePresentation.Current,version=Models.AppInfo.Version,allowed=_runtime.Allowed,dark=!_palette.Light,error=_error},new JsonSerializerOptions{PropertyNamingPolicy=JsonNamingPolicy.CamelCase}));}catch(InvalidOperationException){}}
 private async Task InitializeAsync(){if(_ready||_loading)return;_loading=true;try{var folder=Path.Combine(AppContext.BaseDirectory,"Assets","hybrid","dashboard");var profile=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital","UpdateWebView");Directory.CreateDirectory(profile);var env=await CoreWebView2Environment.CreateWithOptionsAsync(null,profile,null);await _web.EnsureCoreWebView2Async(env);var core=_web.CoreWebView2;core.Settings.AreDevToolsEnabled=false;core.Settings.AreDefaultContextMenusEnabled=false;core.Settings.AreHostObjectsAllowed=false;core.SetVirtualHostNameToFolderMapping("update.tidigital.local",folder,CoreWebView2HostResourceAccessKind.DenyCors);core.NavigationStarting+=(_,e)=>{if(!e.Uri.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))e.Cancel=true;};core.NewWindowRequested+=(_,e)=>e.Handled=true;
  core.WebMessageReceived+=async(_,e)=>{if(!e.Source.StartsWith(Origin,StringComparison.OrdinalIgnoreCase))return;try{using var doc=JsonDocument.Parse(e.WebMessageAsJson);var action=doc.RootElement.GetProperty("action").GetString();if(action=="ready"){_ready=true;Publish();return;}if(action=="cancel"){UpdateService.CancelPreparation();return;}if(action is not("check" or "force")||_running)return;_running=true;_error="";Publish();try{await _run(action=="force");}finally{_running=false;Publish();}}catch(Exception error){_error=error.Message;Publish();}};_web.Source=new Uri(Origin+"update.html");}
 catch(Exception error){Content=Ui.Label("Central de atualização indisponível: "+error.Message,_palette.Text,14,wrap:true);}finally{_loading=false;}}
}
