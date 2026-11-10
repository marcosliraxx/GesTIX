// Caminho: gestores/servidor/Arcoll.WinUI3/MainWindow.Update.cs
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 // Indicador de nova versão: um ponto na categoria que contém o submenu Atualizações e outro no próprio submenu.
 private const string UpdateMenuKey="updates";private readonly Dictionary<string,InfoBadge> _tabBadges=new();private readonly List<InfoBadge> _menuBadges=new();private readonly DispatcherTimer _updateBadgeTimer=new(){Interval=TimeSpan.FromMinutes(15)};private bool _updateBadge,_badgePolling;
 private InfoBadge CategoryUpdateBadge(string group){var badge=new InfoBadge{VerticalAlignment=VerticalAlignment.Top,Visibility=Visibility.Collapsed};Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(badge,"Atualização do gestor disponível");_tabBadges[group]=badge;return badge;}
 private InfoBadge UpdateMenuBadge(){var badge=new InfoBadge{HorizontalAlignment=HorizontalAlignment.Left,VerticalAlignment=VerticalAlignment.Top,Margin=new Thickness(8,6,0,0),IsHitTestVisible=false,Visibility=_updateBadge?Visibility.Visible:Visibility.Collapsed};Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(badge,"Atualização do gestor disponível");_menuBadges.Add(badge);return badge;}
 private void ApplyUpdateBadges(){var parent=GroupKeyOf(UpdateMenuKey);foreach(var entry in _tabBadges)entry.Value.Visibility=_updateBadge&&(entry.Key==parent||(entry.Key=="home"&&_favorites.Contains(UpdateMenuKey)))?Visibility.Visible:Visibility.Collapsed;_menuBadges.RemoveAll(b=>b.XamlRoot is null);foreach(var badge in _menuBadges)badge.Visibility=_updateBadge?Visibility.Visible:Visibility.Collapsed;}
 private void SetUpdateBadge(bool available){DispatcherQueue.TryEnqueue(()=>{if(_updateUiClosed||_updateBadge==available)return;_updateBadge=available;ApplyUpdateBadges();});}
 private async Task PollUpdateBadgeAsync(){if(_badgePolling||_updateUiClosed||!_runtime.Allowed||UpdatePresentation.Current.Status is "working" or "restarting")return;_badgePolling=true;try{SetUpdateBadge((await UpdateCheckService.CheckAsync(_runtime.Platform,CancellationToken.None)).Available);}catch(Exception error){RuntimeLog.Write("Indicador de atualização: "+error.Message);}finally{_badgePolling=false;}}
 private GestorUpdatePage? _gestorUpdatePage;private readonly DispatcherTimer _updateReceiptTimer=new(){Interval=TimeSpan.FromSeconds(1)};private bool _updateUiClosed;private string _updateShownId="";
 private void InitializeUpdateArea()
 {
  // Entry exists only on the existing refresh icon; no extra top-level category.
  _gestorUpdatePage=new GestorUpdatePage(_runtime,StartGestorUpdateAsync,CheckGestorUpdateAsync);
  UpdatePresentation.Changed+=UpdateViewChanged;_runtime.Changed+=UpdateAuthorizationChanged;
  var restored=UpdatePresentation.Restore();UpdateHistoryStore.ImportLastSession();
  _updateReceiptTimer.Tick+=(_,_)=>{UpdatePresentation.PollReceipts();RefreshGestorAreas();};_updateReceiptTimer.Start();
  _updateBadgeTimer.Tick+=async(_,_)=>await PollUpdateBadgeAsync();_updateBadgeTimer.Start();var firstBadge=new DispatcherTimer{Interval=TimeSpan.FromSeconds(20)};firstBadge.Tick+=async(_,_)=>{firstBadge.Stop();await PollUpdateBadgeAsync();};firstBadge.Start();
  Closed+=(_,_)=>{_updateUiClosed=true;_updateReceiptTimer.Stop();_updateBadgeTimer.Stop();UpdatePresentation.Changed-=UpdateViewChanged;_runtime.Changed-=UpdateAuthorizationChanged;};
  if(restored&&UpdatePresentation.Current.Status is "working" or "restarting")ShowGestorUpdateArea();
 }
 private void UpdateViewChanged(UpdateViewState state){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>{if(_updateUiClosed)return;if(state.Status=="working"&&state.Id!=_updateShownId){_updateShownId=state.Id;ShowGestorUpdateArea();}_gestorUpdatePage?.Publish();});}
 private void UpdateAuthorizationChanged(){if(!_updateUiClosed)DispatcherQueue.TryEnqueue(()=>_gestorUpdatePage?.Publish());}
 private void GestorUpdate_Open_Click(object sender,RoutedEventArgs args)=>ShowGestorUpdateArea();
 private void ShowGestorUpdateArea(string view="update")
 {
  if(_gestorUpdatePage is null)return;
  var group=Groups.First(g=>g.Key=="helpdesk");
  _section=view=="history"?"history":"updates";
  _lastSection[group.Key]=_section;
  _group=group.Key;
  BuildRibbon(group);
  HybridDashboard.Visibility=Visibility.Collapsed;PageScroll.Visibility=Visibility.Collapsed;PageHost.Content=null;CompactHost.Visibility=Visibility.Visible;CompactHost.Content=_gestorUpdatePage;HeroFrame.Visibility=Visibility.Collapsed;StatusBar.IsOpen=false;
  _gestorUpdatePage.ApplyAreas(AreaColors());_gestorUpdatePage.Navigate(view);ApplyTheme(_themes.Current);_gestorUpdatePage.Publish();FinishPageLoading();
 }
 private async Task<UpdateAvailability> CheckGestorUpdateAsync(){if(!_runtime.Allowed)throw new UnauthorizedAccessException("Conecte e autorize o gestor para verificar.");var result=await UpdateCheckService.CheckAsync(_runtime.Platform,CancellationToken.None);SetUpdateBadge(result.Available);return result;}
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
