using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.Win32;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
 private Services.AgentTheme? _areaTheme;private bool _areaApplying;private int _osCaption=-1;
 [DllImport("dwmapi.dll")]private static extern int DwmSetWindowAttribute(IntPtr hwnd,int attribute,ref int value,int size);
 private Services.AgentAreaColors AreaColors()=>Services.AgentAreaPalette.Create(_themes.Current.Background,_themes.Current.Surface,_themes.Current.Text,_themes.Current.Accent,_themes.Current.Muted,Services.ThemeService.IsLight(_themes.Current));
 private void InitializeAgentAreas()
 {
  FooterText.RegisterPropertyChangedCallback(TextBlock.ForegroundProperty,(_,_)=>PaintAreaFooter());
  RootGrid.RegisterPropertyChangedCallback(Microsoft.UI.Xaml.FrameworkElement.RequestedThemeProperty,(_,_)=>DispatcherQueue.TryEnqueue(()=>{_osCaption=-1;RefreshOsCaption();}));
  PaintAreaFooter();RefreshOsCaption();
 }
 private void PaintAreaFooter()
 {
  if(_areaApplying)return;_areaApplying=true;
  try{var c=AreaColors();StatusBorder.Background=Services.ThemeService.Brush(c.Area10);var brush=Services.ThemeService.Brush(c.FooterText);if(FooterText.Foreground is not Microsoft.UI.Xaml.Media.SolidColorBrush old||old.Color!=((Microsoft.UI.Xaml.Media.SolidColorBrush)brush).Color)FooterText.Foreground=brush;}
  finally{_areaApplying=false;}
 }
 private void RefreshAgentAreas()
 {
  RefreshOsCaption();PaintAreaFooter();
  if(_areaTheme==_themes.Current)return;_areaTheme=_themes.Current;
  var c=AreaColors();_agentUpdatePage?.ApplyAreas(c);foreach(var frame in _nativeWorkspaces.Values)frame.Paint(c);
  var json=JsonSerializer.Serialize(c,new JsonSerializerOptions{PropertyNamingPolicy=JsonNamingPolicy.CamelCase});
  foreach(var core in _compactCores.ToArray())_ = ApplyAreaScriptAsync(core,"window.__tiSetAreas?.("+json+");");
 }
 private static async Task ApplyAreaScriptAsync(Microsoft.Web.WebView2.Core.CoreWebView2 core,string script){try{await core.ExecuteScriptAsync(script);}catch(Exception error)when(error is InvalidOperationException or COMException){}}
 private string CurrentCompactScript()=>"window.__tiAreaPalette="+JsonSerializer.Serialize(AreaColors(),new JsonSerializerOptions{PropertyNamingPolicy=JsonNamingPolicy.CamelCase})+";\n"+_compactScript;
 private void RefreshOsCaption()
 {
  try{using var key=Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");var dark=Convert.ToInt32(key?.GetValue("AppsUseLightTheme",1))==0?1:0;if(dark==_osCaption)return;_osCaption=dark;
   var bar=AppWindow.TitleBar;bar.BackgroundColor=null;bar.ForegroundColor=null;bar.ButtonBackgroundColor=null;bar.ButtonForegroundColor=null;bar.InactiveBackgroundColor=null;bar.InactiveForegroundColor=null;bar.ButtonInactiveBackgroundColor=null;bar.ButtonInactiveForegroundColor=null;
   var hwnd=WinRT.Interop.WindowNative.GetWindowHandle(this);if(DwmSetWindowAttribute(hwnd,20,ref dark,sizeof(int))!=0)DwmSetWindowAttribute(hwnd,19,ref dark,sizeof(int));
  }catch(Exception error)when(error is InvalidOperationException or COMException or UnauthorizedAccessException){ }
 }
}
