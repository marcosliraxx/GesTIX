// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.Workspace.cs
using System.Text.Json;
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private IPageDataSource? _pageDataSource;private PageDataLoad? _pageDataLoad;private Action? _pageDataHandler;private Func<Task>? _pageCollect;private bool _pageGateInitialized,_pageOptionsExposed;private int _pageLoadGeneration;
 private readonly PageDataLoad _networkPageLoad=new("Consultando dispositivos da rede",true);
 private void InitializePageDataGate(){if(_pageGateInitialized)return;_pageGateInitialized=true;PageLoader.CollectRequested+=async()=>await CollectCurrentPageAsync();PageLoader.OptionsRequested+=()=>{_pageOptionsExposed=true;PageLoader.Suspend();PageLoadingOverlay.Visibility=Visibility.Collapsed;};_runtime.Changed+=PageAuthorizationChanged;Closed+=(_,_)=>{_runtime.Changed-=PageAuthorizationChanged;UnbindPageData();};}
 private void PageAuthorizationChanged(){if(!_compactClosed)DispatcherQueue.TryEnqueue(()=>{if(!_compactClosed){RenderPageLoading();TryAutomaticCollection();}});}
 private void UnbindPageData(){if(_pageDataLoad is not null&&_pageDataHandler is not null)_pageDataLoad.Changed-=_pageDataHandler;_pageDataHandler=null;_pageDataLoad=null;_pageDataSource=null;_pageCollect=null;}
 private void ShowPageLoading(string stage,int percent){InitializePageDataGate();UnbindPageData();_pageLoadGeneration++;_pageOptionsExposed=false;PaintPageLoading();PageLoader.Present("Preparando área",new(DataLoadPhase.Loading,"Preparando a consulta da página",null,false,0),false,false);PageLoadingOverlay.Visibility=Visibility.Visible;}
 private void FinishPageLoading(){InitializePageDataGate();UnbindPageData();_pageOptionsExposed=false;_pageDataSource=(CompactHost.Content??PageHost.Content)as IPageDataSource;
  if(_section=="panel"&&_hybridReady){_pageDataLoad=_networkPageLoad;_pageCollect=CollectNetworkPageAsync;}else if(_pageDataSource is not null){_pageDataLoad=_pageDataSource.DataLoad;_pageCollect=_pageDataSource.CollectDataAsync;}
  if(_pageDataLoad is not null){var load=_pageDataLoad;var generation=_pageLoadGeneration;_pageDataHandler=()=>DispatcherQueue.TryEnqueue(()=>{if(generation!=_pageLoadGeneration||!ReferenceEquals(load,_pageDataLoad))return;if(load.Current.Phase==DataLoadPhase.Loading)_pageOptionsExposed=false;RenderPageLoading();});load.Changed+=_pageDataHandler;}
  RenderPageLoading();TryAutomaticCollection();
 }
 private void TryAutomaticCollection(){if(_runtime.Allowed&&_pageDataLoad?.Current is {Phase:DataLoadPhase.Loading,Operation:0}&&_pageDataSource is not null){var source=_pageDataSource;DispatcherQueue.TryEnqueue(async()=>{if(ReferenceEquals(source,_pageDataSource)&&source.DataLoad.Current.Operation==0)await CollectCurrentPageAsync();});}}
 private async Task CollectCurrentPageAsync(){var source=_pageDataLoad;var collect=_pageCollect;if(source is null||collect is null||!_runtime.Allowed)return;try{await collect();}catch(Exception error){source.Fail("Não foi possível coletar: "+error.Message);RuntimeLog.Write("Coleta da página: "+error);}if(ReferenceEquals(source,_pageDataLoad))RenderPageLoading();}
 private async Task CollectNetworkPageAsync(){if(_networkPanelBusy)return;_networkPageLoad.Begin("Examinando os dispositivos da rede");await _runtime.ScanNowAsync(CancellationToken.None);if(!_hybridReady)throw new InvalidOperationException("A interface do painel ainda não está disponível.");await HybridDashboard.CoreWebView2.ExecuteScriptAsync("window.dispatchEvent(new Event('ti-page-collect'));");}
 private void PaintPageLoading(){var t=_themes.Current;var c=LoadingColors.Create(t.Surface,t.Text,t.Muted,t.Accent);PageLoadingOverlay.Background=ThemeService.Brush(c.Surface);PageLoader.Paint(c);}
 private void RenderPageLoading(){PaintPageLoading();if(_pageDataLoad is null||_pageOptionsExposed||_pageDataLoad.Current.HasData){PageLoader.Suspend();PageLoadingOverlay.Visibility=Visibility.Collapsed;return;}var title=Groups.SelectMany(g=>g.Items).FirstOrDefault(i=>i.Key==_section)?.Label??"Dados da página";PageLoader.Present(title,_pageDataLoad.Current,_runtime.Allowed,_pageDataSource is MaintenancePage);PageLoadingOverlay.Visibility=Visibility.Visible;}
 private readonly Dictionary<UserControl,GestorWorkspace> _nativeWorkspaces=[];private readonly Dictionary<UserControl,string> _workspaceContexts=[];private readonly Dictionary<CoreWebView2,string> _webWorkspaceContexts=[];
 private readonly TextBlock _pageContext=new(){FontSize=9,TextTrimming=TextTrimming.CharacterEllipsis,VerticalAlignment=VerticalAlignment.Center,TextAlignment=TextAlignment.Center};
 private void InitializeWorkspaceFooter(){if(StatusBorder.Child is not Grid grid)return;grid.ColumnDefinitions.Clear();grid.ColumnDefinitions.Add(new(){Width=new GridLength(3,GridUnitType.Star)});grid.ColumnDefinitions.Add(new(){Width=new GridLength(4,GridUnitType.Star)});grid.ColumnDefinitions.Add(new(){Width=new GridLength(3,GridUnitType.Star)});Grid.SetColumn(FooterText,2);Grid.SetColumn(_pageContext,1);grid.Children.Add(_pageContext);FooterText.RegisterPropertyChangedCallback(TextBlock.ForegroundProperty,(_,_)=>_pageContext.Foreground=FooterText.Foreground);_pageContext.Foreground=FooterText.Foreground;}
 private string _workspaceRuntimeLast="";
 private void WorkspaceRuntimeActivity(string text){if(text==_workspaceRuntimeLast)return;_workspaceRuntimeLast=text;var page=(CompactHost.Content??PageHost.Content)as UserControl;if(page is not null&&_nativeWorkspaces.TryGetValue(page,out var frame))frame.AddActivity(text);}
 private void WorkspaceContext(UserControl page,string text){_workspaceContexts[page]=text;if(ReferenceEquals(CompactHost.Content,page)||ReferenceEquals(PageHost.Content,page))SetWorkspaceFooter(text);}
 private void SetWorkspaceFooter(string text){var safe=new string(text.Where(c=>!char.IsControl(c)||c=='\t').ToArray());if(safe.Length>1000)safe=safe[..1000];_pageContext.Text=safe;ToolTipService.SetToolTip(_pageContext,safe);}
 private void RefreshWorkspaceFooter(){var page=(CompactHost.Content??PageHost.Content)as UserControl;if(page is not null&&_workspaceContexts.TryGetValue(page,out var context))SetWorkspaceFooter(context);else SetWorkspaceFooter(Groups.SelectMany(g=>g.Items).FirstOrDefault(i=>i.Key==_section)?.Label??"Atualização");foreach(var frame in _nativeWorkspaces.Values)frame.Paint(AreaColors());}
 private void AttachWorkspaceBridge(WebView2 view,CoreWebView2 core){core.WebMessageReceived+=(_,e)=>{try{var origin=new Uri(e.Source);if(origin.Scheme!="https"||!new[]{"tidigital.local","server.tidigital.local","internet.tidigital.local","pentefino.tidigital.local","backup.tidigital.local","update.tidigital.local"}.Contains(origin.Host))return;using var doc=JsonDocument.Parse(e.WebMessageAsJson);var r=doc.RootElement;if(!r.TryGetProperty("type",out var type)||type.GetString()!="workspace-footer"||!r.TryGetProperty("text",out var value)||value.ValueKind!=JsonValueKind.String)return;var text=value.GetString()??"";if(text.Length>1000)return;_webWorkspaceContexts[core]=text;if(IsWorkspaceViewActive(view))SetWorkspaceFooter(text);}catch(Exception error)when(error is JsonException or UriFormatException or InvalidOperationException){}};view.Loaded+=(_,_)=>{if(IsWorkspaceViewActive(view)&&_webWorkspaceContexts.TryGetValue(core,out var text))SetWorkspaceFooter(text);};}
 private bool IsWorkspaceViewActive(WebView2 view){if(ReferenceEquals(view,HybridDashboard))return HybridDashboard.Visibility==Visibility.Visible&&_section=="panel";DependencyObject? node=view;for(int i=0;node is not null&&i<64;i++){if(node is UIElement element&&element.Visibility!=Visibility.Visible)return false;if(ReferenceEquals(node,CompactHost)||ReferenceEquals(node,PageHost))return true;node=Microsoft.UI.Xaml.Media.VisualTreeHelper.GetParent(node);}return false;}
 private void EnsureNativeWorkspace(UserControl page)
 {
  // Não reorganizar controles nativos durante LayoutUpdated: reparenting repetitivo pode
  // provocar ciclos de layout, instabilidade do XAML e encerramento do processo ao navegar.
  if (_nativeWorkspaces.ContainsKey(page)) return;
  if (page.Content is not UIElement original || original is WebView2 or GestorWorkspace) return;
  try
  {
   page.Content = null;
   var frame = new GestorWorkspace(original, original is Grid or ScrollViewer,
    text => WorkspaceContext(page, text));
   page.Content = frame;
   _nativeWorkspaces[page] = frame;
   frame.Paint(AreaColors());
   // Controles permanecem nos seus pais originais; não se registra LayoutUpdated.
  }
  catch (Exception error)
  {
   Services.RuntimeLog.Write("Workspace nativo: " + page.GetType().Name + ": " + error);
   // Recuperar o conteúdo sem perder a página funcional.
   try { if (page.Content is null) page.Content = original; } catch (Exception restore)
   { Services.RuntimeLog.Write("Workspace recuperação: " + restore); }
  }
 }
 private static IEnumerable<TextBlock> NativeTexts(UIElement node){if(node is TextBlock text){yield return text;yield break;}foreach(var child in GestorWorkspace.ChildrenOf(node))foreach(var textChild in NativeTexts(child))yield return textChild;}
}
