using System.Text.Json;
using Arcoll.WinUI3.Controls;
using Arcoll.WinUI3.Pages;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private readonly Dictionary<UserControl,AgentWorkspace> _nativeWorkspaces=[];private readonly Dictionary<UserControl,string> _workspaceContexts=[];private readonly Dictionary<CoreWebView2,string> _webWorkspaceContexts=[];
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
  if(page.Content is not UIElement original||original is WebView2 or AgentWorkspace)return;
  // Native fallbacks and tools use the same chrome; web pages receive the equivalent agent-only document frame.
  page.Content=null;var bounded=original is Grid or ScrollViewer;var frame=new AgentWorkspace(original,bounded,text=>WorkspaceContext(page,text));_nativeWorkspaces[page]=frame;page.Content=frame;frame.Paint(AreaColors());
  UIElement? first=null;int count=-1;
  void Adapt(){var root=original is Border{Child:UIElement b}?b:original is ScrollViewer{Content:UIElement s}?s:original;var children=AgentWorkspace.ChildrenOf(root).ToArray();if(children.Length==0)return;if(ReferenceEquals(first,children[0])&&count==children.Length)return;first=children[0];count=children.Length;if(page is WindowsUpdatePage or MapPage)frame.ResetDynamicTools();
   if(root is Grid grid&&grid.RowDefinitions.Count==5){grid.RowSpacing=0;foreach(var child in children){if(child is not FrameworkElement element)continue;var row=Grid.GetRow(element);if(row==0){child.Visibility=Visibility.Collapsed;continue;}if(row==1||(page is FilesPage&&row==2)){frame.AddTools(child);if(child is not Control)child.Visibility=Visibility.Collapsed;continue;}if((page is FilesPage&&row==3)||(!(page is FilesPage)&&row==2)){foreach(var t in NativeTexts(child))frame.Track(t);continue;}if(!(page is FilesPage)&&row==4){frame.AddTools(child);foreach(var t in NativeTexts(child))frame.Track(t,true);child.Visibility=Visibility.Collapsed;}}}
   else if(page is DevicesPage or TicketsPage){var bar=children.FirstOrDefault(c=>c is Panel&&AgentWorkspace.ChildrenOf(c).Any(x=>x is Button or TextBox));if(bar is not null){frame.AddTools(bar);bar.Visibility=Visibility.Collapsed;}foreach(var t in children.OfType<TextBlock>()){if(t.FontSize>=18)t.Visibility=Visibility.Collapsed;else frame.Track(t,true);}}
   else if(page is WindowsUpdatePage){children[0].Visibility=Visibility.Collapsed;if(children.Length>1){frame.AddTools(children[1]);foreach(var t in NativeTexts(children[1]))frame.Track(t);children[1].Visibility=Visibility.Collapsed;}}
   else if(page is MapPage){var head=children[0];frame.AddTools(head);foreach(var t in NativeTexts(head))frame.Track(t);head.Visibility=Visibility.Collapsed;}
   else if(page is SupportPage){foreach(var child in children.Take(2))child.Visibility=Visibility.Collapsed;foreach(var child in children)if(child is Grid)frame.AddTools(child);}
   count=AgentWorkspace.ChildrenOf(root).Count();first=AgentWorkspace.ChildrenOf(root).FirstOrDefault();}
  Adapt();frame.LayoutUpdated+=(_,_)=>{if(page.IsLoaded)Adapt();};
 }
 private static IEnumerable<TextBlock> NativeTexts(UIElement node){if(node is TextBlock text){yield return text;yield break;}foreach(var child in AgentWorkspace.ChildrenOf(node))foreach(var textChild in NativeTexts(child))yield return textChild;}
}
