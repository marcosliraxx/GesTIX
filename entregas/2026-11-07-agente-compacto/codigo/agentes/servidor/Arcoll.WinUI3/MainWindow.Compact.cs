// Visual extension: leaves MainWindow.xaml.cs, menus, permissions and operations intact.
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Automation;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.Web.WebView2.Core;

namespace Arcoll.WinUI3;
public sealed partial class MainWindow
{
    private bool _compactLoaded, _compactClosed, _compactInkUpdating;
    private UIElement? _compactFirstMenu;
    private TextBlock? _compactBadgeLabel;
    private int _compactMenuCount=-1;
    private readonly HashSet<WebView2> _compactViews=[];
    private readonly HashSet<UserControl> _compactPages=[];
    private readonly HashSet<CoreWebView2> _compactCores=[];
    private string? _compactScript;

    private void CompactShell_Loaded(object sender,RoutedEventArgs args)
    {
        if(_compactLoaded)return;_compactLoaded=true;
        var path=Path.Combine(AppContext.BaseDirectory,"Assets","hybrid","dashboard","compact-shell.js");
        if(File.Exists(path))_compactScript=File.ReadAllText(path);
        CompactCategories();CompactNavigation();CompactFooter();
        _runtime.Changed+=CompactRuntimeChanged;
        RootGrid.SizeChanged+=(_,_)=>CompactFooter();
        RibbonMenus.LayoutUpdated+=(_,_)=>CompactNavigation();
        PageHost.RegisterPropertyChangedCallback(ContentControl.ContentProperty,(_,_)=>CompactScheduleContent());
        CompactHost.RegisterPropertyChangedCallback(ContentControl.ContentProperty,(_,_)=>CompactScheduleContent());
        NetworkBadge.LayoutUpdated+=(_,_)=>CompactBadgeText();
        FooterText.RegisterPropertyChangedCallback(TextBlock.ForegroundProperty,(_,_)=>CompactFooterInk());
        NetworkTitle.RegisterPropertyChangedCallback(TextBlock.ForegroundProperty,(_,_)=>CompactFooterInk());
        Closed+=(_,_)=>{_compactClosed=true;_runtime.Changed-=CompactRuntimeChanged;};
        CompactBadgeText();CompactScheduleContent();
    }
    private void CompactScheduleContent()
    {
        DispatcherQueue.TryEnqueue(()=>
        {
            if(_compactClosed)return;
            CompactContentViews(HybridDashboard);
            if(PageHost.Content is DependencyObject page)CompactContentViews(page);
            if(CompactHost.Content is DependencyObject compact)CompactContentViews(compact);
        });
    }
    private void CompactBadgeText()
    {
        if(NetworkBadge.Child is TextBlock text&&!ReferenceEquals(text,_compactBadgeLabel)){_compactBadgeLabel=text;text.Foreground=ThemeService.Brush("#153150");text.FontSize=text.Text.Length>2?7:9;}
    }
    private void CompactRuntimeChanged(){if(!_compactClosed)DispatcherQueue.TryEnqueue(()=>{if(!_compactClosed)CompactFooter();});}
    private void CompactFooterInk()
    {
        if(_compactInkUpdating)return;_compactInkUpdating=true;
        try{if(!ReferenceEquals(NetworkTitle.Foreground,FooterText.Foreground))NetworkTitle.Foreground=FooterText.Foreground;}
        finally{_compactInkUpdating=false;}
    }
    private void CompactFooter()
    {
        FooterNetworkIdentity.Opacity=_runtime.Allowed?1:.42;
        AutomationProperties.SetHelpText(FooterNetworkIdentity,_runtime.Allowed?"Rede autenticada":"Conexão com a rede sem confirmação");
        NetworkTitle.MaxWidth=Math.Max(70,RootGrid.ActualWidth*.5-66);
        CompactFooterInk();
    }
    private void CompactCategories()
    {
        foreach(var child in CategoryTabs.Children)
            if(child is Button button&&button.Content is StackPanel panel)
            {
                panel.Spacing=5;
                foreach(var item in panel.Children)
                    if(item is TextBlock text)text.FontSize=11;
                    else if(item is SymbolIcon icon){icon.Width=14;icon.Height=14;}
            }
    }
    private void CompactNavigation()
    {
        var first=RibbonMenus.Children.Count>0?RibbonMenus.Children[0]:null;
        if(_compactMenuCount==RibbonMenus.Children.Count&&ReferenceEquals(first,_compactFirstMenu))return;
        _compactMenuCount=RibbonMenus.Children.Count;_compactFirstMenu=first;
        foreach(var child in RibbonMenus.Children)
        {
            if(child is Border separator){separator.Margin=new Thickness(4,6,4,6);continue;}
            if(child is not Grid cell)continue;
            foreach(var element in cell.Children)
            {
                if(element is not Button button)continue;
                if(button.Content is StackPanel content)
                {
                    button.MinWidth=64;button.Height=50;button.Padding=new Thickness(8,3,12,3);button.CornerRadius=new CornerRadius(6);content.Spacing=2;
                    foreach(var item in content.Children)
                        if(item is FontIcon icon)icon.FontSize=20;
                        else if(item is TextBlock text){text.FontSize=10.5;text.TextWrapping=TextWrapping.NoWrap;text.MaxLines=1;}
                }
                else if(button.Content is FontIcon star){button.Width=18;button.Height=18;button.CornerRadius=new CornerRadius(9);star.FontSize=9;}
            }
        }
    }
    private void CompactContentViews(DependencyObject node)
    {
        if(_compactClosed)return;
        if(node is UserControl page)
        {
            if(_compactPages.Add(page))page.RegisterPropertyChangedCallback(UserControl.ContentProperty,(_,_)=>CompactScheduleContent());
            if(page.Content is DependencyObject content)CompactContentViews(content);
            return;
        }
        if(node is WebView2 view)
        {
            if(_compactViews.Add(view))
            {
                view.DefaultBackgroundColor=Windows.UI.Color.FromArgb(0,0,0,0);
                view.CoreWebView2Initialized+=async(_,_)=>await InstallCompactDocumentAsync(view);
                if(view.CoreWebView2 is not null)_=InstallCompactDocumentAsync(view);
            }
            return;
        }
        for(var i=0;i<VisualTreeHelper.GetChildrenCount(node);i++)CompactContentViews(VisualTreeHelper.GetChild(node,i));
    }
    private async Task InstallCompactDocumentAsync(WebView2 view)
    {
        var core=view.CoreWebView2;if(core is null||_compactScript is null||!_compactCores.Add(core))return;
        try
        {
            await core.AddScriptToExecuteOnDocumentCreatedAsync(_compactScript);
            core.DOMContentLoaded+=async(_,_)=>{if(!_compactClosed)try{await core.ExecuteScriptAsync(_compactScript);}catch(Exception error){RuntimeLog.Write("Moldura compacta: "+error.GetType().Name);}};
            if(!_compactClosed)await core.ExecuteScriptAsync(_compactScript);
        }
        catch(Exception error){RuntimeLog.Write("Moldura compacta: "+error.GetType().Name);}
    }
}
