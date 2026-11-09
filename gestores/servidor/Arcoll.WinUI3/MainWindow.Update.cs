// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.Update.cs
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private GestorUpdatePage? _gestorUpdatePage;private readonly DispatcherTimer _updateReceiptTimer=new(){Interval=TimeSpan.FromSeconds(1)};private bool _updateUiClosed;private string _updateShownId="";
 private void InitializeUpdateArea()
 {
  // Entry exists only on the existing refresh icon; no extra top-level category.
  _gestorUpdatePage=new GestorUpdatePage(_runtime,StartGestorUpdateAsync,CheckGestorUpdateAsync);
  UpdatePresentation.Changed+=UpdateViewChanged;_runtime.Changed+=UpdateAuthorizationChanged;
  var restored=UpdatePresentation.Restore();UpdateHistoryStore.ImportLastSession();
  _updateReceiptTimer.Tick+=(_,_)=>{UpdatePresentation.PollReceipts();RefreshGestorAreas();};_updateReceiptTimer.Start();
  Closed+=(_,_)=>{_updateUiClosed=true;_updateReceiptTimer.Stop();UpdatePresentation.Changed-=UpdateViewChanged;_runtime.Changed-=UpdateAuthorizationChanged;};
  if(restored&&UpdatePresentation.Current.Status is "working" or "restarting")ShowGestorUpdateArea();
 }
 private void UpdateViewChanged(UpdateViewState state){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>{if(_updateUiClosed)return;if(state.Status=="working"&&state.Id!=_updateShownId){_updateShownId=state.Id;ShowGestorUpdateArea();}_gestorUpdatePage?.Publish();});}
 private void UpdateAuthorizationChanged(){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>_gestorUpdatePage?.Publish());}
 private void GestorUpdate_Open_Click(object sender,RoutedEventArgs args)=>ShowGestorUpdateArea();
 private void ShowGestorUpdateArea(string view="update")
 {
  if(_gestorUpdatePage is null)return;_group="gestor_update";_section=view=="history"?"gestor_update_history":"gestor_update";
  _menus.Clear();_stars.Clear();_separators.Clear();RibbonMenus.Children.Clear();
  foreach(var item in new[]{("gestor_update","Atualização","\uE895","update"),("gestor_update_history","Histórico","\uE823","history")}){
   var content=new StackPanel{Spacing=2};content.Children.Add(new FontIcon{Glyph=item.Item3,FontSize=20});content.Children.Add(new TextBlock{Text=item.Item2,FontSize=10.5});var menu=new Button{Content=content,Style=(Style)RootGrid.Resources["FlatButton"],Height=50,MinWidth=80,Padding=new Thickness(8,3,12,3)};menu.Click+=(_,_)=>ShowGestorUpdateArea(item.Item4);Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(menu,item.Item2);_menus[item.Item1]=menu;var cell=new Grid();cell.Children.Add(menu);RibbonMenus.Children.Add(cell);
  }
  HybridDashboard.Visibility=Visibility.Collapsed;PageScroll.Visibility=Visibility.Collapsed;PageHost.Content=null;CompactHost.Visibility=Visibility.Visible;CompactHost.Content=_gestorUpdatePage;HeroFrame.Visibility=Visibility.Collapsed;StatusBar.IsOpen=false;
  _gestorUpdatePage.ApplyAreas(AreaColors());_gestorUpdatePage.Navigate(view);ApplyTheme(_themes.Current);_gestorUpdatePage.Publish();FinishPageLoading();
 }
 private Task<UpdateAvailability> CheckGestorUpdateAsync(){if(!_runtime.Allowed)throw new UnauthorizedAccessException("Conecte e autorize o gestor para verificar.");return UpdateCheckService.CheckAsync(_runtime.Platform,CancellationToken.None);}
 private async Task StartGestorUpdateAsync(bool force)
 {
  if(!_runtime.Allowed)throw new UnauthorizedAccessException("Conecte e autorize o gestor para atualizar.");
  if(force&&!await ConfirmAsync("Reinstalar gestor","Baixar e validar novamente os arquivos publicados? A configuração do computador será preservada.","Reinstalar"))return;
  using var timeout=new CancellationTokenSource(TimeSpan.FromMinutes(50));
  try{var result=await UpdateService.StageAndLaunchAsync(_runtime.Platform,0,force,(_,_)=>{},timeout.Token);if(result.Launched){await Task.Delay(1000);ExitGestor();}}
  catch(OperationCanceledException){}
  catch(Exception error){RuntimeLog.Write("Central de atualização: "+error.Message);throw;}
 }
}
