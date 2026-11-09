// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.Workspace.cs
using System.Text.Json;
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Pages;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private int _pageLoadGeneration;
 private void ShowPageLoading(string stage,int percent)
 {
  _pageLoadGeneration++;
  PageLoadingOverlay.Visibility=Visibility.Visible; PageLoadingRing.IsActive=true;
  PageLoadingStage.Text=stage; PageLoadingPercent.Text=Math.Clamp(percent,0,100)+"%";
 }
 private async void FinishPageLoading()
 {
  var generation=_pageLoadGeneration;
  PageLoadingStage.Text="Carregando dados da área"; PageLoadingPercent.Text="45%";
  // Esta porcentagem corresponde às etapas de navegação; dados internos seguem
  // sinalizados pela própria página. Não simular porcentagens de rede.
  await System.Threading.Tasks.Task.Delay(350);
  if(generation!=_pageLoadGeneration)return;
  PageLoadingStage.Text="Interface disponível"; PageLoadingPercent.Text="100%";
  PageLoadingRing.IsActive=false;
  await System.Threading.Tasks.Task.Delay(200);
  if(generation==_pageLoadGeneration)PageLoadingOverlay.Visibility=Visibility.Collapsed;
 }
 private void PageLoadingButton_Click(object sender,RoutedEventArgs e)
 {
  var current=_section;
  PageHost.Content=null;CompactHost.Content=null;
  SelectSection(current);
 }
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
