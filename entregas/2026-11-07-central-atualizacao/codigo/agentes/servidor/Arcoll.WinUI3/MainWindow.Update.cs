using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private AgentUpdatePage? _agentUpdatePage;private readonly DispatcherTimer _updateReceiptTimer=new(){Interval=TimeSpan.FromSeconds(1)};private bool _updateUiClosed;private string _updateShownId="";
 private void InitializeUpdateArea()
 {
  _agentUpdatePage=new AgentUpdatePage(_runtime,StartAgentUpdateAsync);
  var label=new StackPanel{Orientation=Orientation.Horizontal,Spacing=5};label.Children.Add(new SymbolIcon(Symbol.Download){Width=14,Height=14});label.Children.Add(new TextBlock{Text="Atualização",FontSize=11});
  var tab=new Button{Style=(Style)RootGrid.Resources["RibbonTab"],Content=label};Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(tab,"Atualização do agente");Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(tab,"category-agent-update");tab.Click+=(_,_)=>ShowAgentUpdateArea();_tabs["agent_update"]=tab;CategoryTabs.Children.Add(tab);
  UpdatePresentation.Changed+=UpdateViewChanged;
  _runtime.Changed+=UpdateAuthorizationChanged;
  RootGrid.RegisterPropertyChangedCallback(FrameworkElement.RequestedThemeProperty,(_,_)=>_agentUpdatePage?.ApplyPalette(Palette.From(_themes.Current)));
  var restored=UpdatePresentation.Restore();
  _updateReceiptTimer.Tick+=(_,_)=>UpdatePresentation.PollReceipts();_updateReceiptTimer.Start();
  Closed+=(_,_)=>{_updateUiClosed=true;_updateReceiptTimer.Stop();UpdatePresentation.Changed-=UpdateViewChanged;_runtime.Changed-=UpdateAuthorizationChanged;};
  if(restored&&UpdatePresentation.Current.Status is "working" or "restarting")ShowAgentUpdateArea();
 }
 private void UpdateViewChanged(UpdateViewState state){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>{if(_updateUiClosed)return;if(state.Status=="working"&&state.Id!=_updateShownId){_updateShownId=state.Id;ShowAgentUpdateArea();}_agentUpdatePage?.Publish();});}
 private void UpdateAuthorizationChanged(){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>_agentUpdatePage?.Publish());}
 private void AgentUpdate_Open_Click(object sender,RoutedEventArgs args)=>ShowAgentUpdateArea();
 private void ShowAgentUpdateArea()
 {
  if(_agentUpdatePage is null)return;_group="agent_update";_section="agent_update";
  _menus.Clear();_stars.Clear();_separators.Clear();RibbonMenus.Children.Clear();var content=new StackPanel{Spacing=2};content.Children.Add(new FontIcon{Glyph="\uE895",FontSize=20});content.Children.Add(new TextBlock{Text="Atualização",FontSize=10.5});var menu=new Button{Content=content,Style=(Style)RootGrid.Resources["FlatButton"],Height=50,MinWidth=80,Padding=new Thickness(8,3,12,3)};menu.Click+=(_,_)=>ShowAgentUpdateArea();_menus["agent_update"]=menu;var cell=new Grid();cell.Children.Add(menu);RibbonMenus.Children.Add(cell);
  HybridDashboard.Visibility=Visibility.Collapsed;PageScroll.Visibility=Visibility.Collapsed;PageHost.Content=null;CompactHost.Visibility=Visibility.Visible;CompactHost.Content=_agentUpdatePage;HeroFrame.Visibility=Visibility.Collapsed;StatusBar.IsOpen=false;
  _agentUpdatePage.ApplyPalette(Palette.From(_themes.Current));ApplyTheme(_themes.Current);_agentUpdatePage.Publish();
 }
 private async Task StartAgentUpdateAsync(bool force)
 {
  if(!_runtime.Allowed)throw new UnauthorizedAccessException("Conecte e autorize o agente para atualizar.");
  if(force&&!await ConfirmAsync("Reinstalar agente","Baixar e validar novamente os arquivos publicados? A configuração do computador será preservada.","Reinstalar"))return;
  using var timeout=new CancellationTokenSource(TimeSpan.FromMinutes(50));
  try{var result=await UpdateService.StageAndLaunchAsync(_runtime.Platform,0,force,(_,_)=>{},timeout.Token);if(result.Launched){await Task.Delay(1000);ExitAgent();}}
  catch(OperationCanceledException){}
  catch(Exception error){RuntimeLog.Write("Central de atualização: "+error.Message);throw;}
 }
}
