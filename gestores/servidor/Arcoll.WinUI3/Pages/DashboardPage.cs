// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/DashboardPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;
namespace Arcoll.WinUI3.Pages;
/// <summary>Grade do Painel web: mesmo inventário consolidado e mesma regra de presença.</summary>
public sealed class DashboardPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Consultando dispositivos da rede",true);
    public Task CollectDataAsync()=>LoadAsync();

    private readonly GestorRuntime _runtime;
    private readonly Grid _root=new(){RowSpacing=6};private readonly StackPanel _groups=new(){Spacing=8},_toolbar=new(){Orientation=Orientation.Horizontal,Spacing=4};
    private readonly ScrollViewer _scroll;private readonly TextBlock _status=new(){TextWrapping=TextWrapping.Wrap,FontSize=11};
    private readonly DispatcherTimer _timer=new(){Interval=TimeSpan.FromSeconds(15)};
    private JsonElement[] _rows=Array.Empty<JsonElement>();private Palette _p=new();private string _filter="all",_view="all";private bool _oldest,_fit,_busy;
    public DashboardPage(GestorRuntime runtime)
    {
        _runtime=runtime;Content=_root;_root.RowDefinitions.Add(new(){Height=GridLength.Auto});_root.RowDefinitions.Add(new(){Height=new GridLength(1,GridUnitType.Star)});_root.RowDefinitions.Add(new(){Height=GridLength.Auto});
        _root.Children.Add(new ScrollViewer{Content=_toolbar,HorizontalScrollBarVisibility=ScrollBarVisibility.Hidden,VerticalScrollBarVisibility=ScrollBarVisibility.Disabled});_scroll=new(){Content=_groups,VerticalScrollBarVisibility=ScrollBarVisibility.Hidden,HorizontalScrollBarVisibility=ScrollBarVisibility.Disabled};Grid.SetRow(_scroll,1);_root.Children.Add(_scroll);Grid.SetRow(_status,2);_root.Children.Add(_status);
        AutomationProperties.SetAutomationId(this,"network-dashboard");_timer.Tick+=async(_,_)=>await LoadAsync();Loaded+=async(_,_)=>{_timer.Start();await LoadAsync();};Unloaded+=(_,_)=>_timer.Stop();SizeChanged+=(_,_)=>Render();
    }
    public void ApplyPalette(Palette p){_p=p;Render();}
    private static string V(JsonElement e,string k)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(k,out var v)?v.ToString():"";
    private static long N(JsonElement e,string k)=>long.TryParse(V(e,k),out var n)?n:0;
    private static string State(JsonElement row)=>N(row,"x")==1?"unknown":N(row,"o")==1?"online":"offline";
    private static string Duration(JsonElement row)
    {
        var unknown=State(row)=="unknown";var timestamp=N(row,unknown?"l":"s");
        if(timestamp<=0)return "Tempo não informado";
        var seconds=Math.Max(0,DateTimeOffset.UtcNow.ToUnixTimeSeconds()-timestamp);
        var value=seconds<60?seconds+" s":seconds<3600?(seconds/60)+" min":seconds<86400?(seconds/3600)+" h":(seconds/86400)+" d";
        return (unknown?"Último contato há ":State(row)=="online"?"Online há ":"Offline há ")+value;
    }
    private Button Button(string name,Action action,bool selected=false){var b=new Button{Content=name,FontSize=12,MinHeight=28,Padding=new Thickness(7,3,7,3),Background=selected?_p.Accent:_p.Surface,Foreground=selected?ThemeService.Brush("#ffffff"):_p.Text};b.Click+=(_,_)=>action();return b;}
    private async Task LoadAsync()
    {
        if(_busy||!_runtime.Allowed)return;_busy=true;DataLoad.Begin("Consultando dispositivos da rede",preserveData:true);
        try{using var ct=new CancellationTokenSource(TimeSpan.FromSeconds(20));var result=await WorkspaceService.GetAsync(_runtime,"dashboard",ct.Token);_rows=result.GetProperty("devices").EnumerateArray().Select(x=>x.Clone()).ToArray();var updated=result.GetProperty("updated").GetInt64();_status.Text=updated>0?"Última atualização: "+DateTimeOffset.FromUnixTimeSeconds(updated).ToLocalTime().ToString("dd/MM HH:mm:ss"):"Nenhum inventário registrado";Render();DataLoad.Complete();}catch(Exception ex){DataLoad.Fail("Não foi possível consultar a rede: "+ex.Message);_status.Text="Não foi possível atualizar: "+ex.Message;}finally{_busy=false;}
    }
    private void Render()
    {
        _toolbar.Children.Clear();_groups.Children.Clear();_status.Foreground=_p.Muted;
        foreach(var (key,label) in new[]{("all","Todos"),("online","Online"),("offline","Offline"),("unknown","Sem confirmação")}){var count=_rows.Count(x=>key=="all"||State(x)==key);_toolbar.Children.Add(Button(label+" "+count,()=>{_filter=key;Render();},_filter==key));}
        foreach(var (key,label) in new[]{("all","Grade"),("type","Por tipo"),("group","Por grupo")})_toolbar.Children.Add(Button(label,()=>{_view=key;Render();},_view==key));
        _toolbar.Children.Add(Button(_fit?"Rolagem":"Ajustar todos",()=>{_fit=!_fit;Render();},_fit));_toolbar.Children.Add(Button(_oldest?"Mais antigos":"Mais recentes",()=>{_oldest=!_oldest;Render();}));var refresh=Button("↻",()=>{_=LoadAsync();});AutomationProperties.SetName(refresh,"Atualizar inventário do Painel");_toolbar.Children.Add(refresh);
        var rows=_rows.Where(x=>_filter=="all"||State(x)==_filter);rows=_oldest?rows.OrderBy(x=>N(x,"c")):rows.OrderByDescending(x=>N(x,"c"));var groups=rows.GroupBy(x=>_view=="type"?V(x,"t"):_view=="group"?(V(x,"g").Length>0?V(x,"g"):"Sem grupo"):"").ToArray();
        var width=Math.Max(200,ActualWidth-20);var columns=Math.Max(1,(int)(width/92));var height=110d;
        if(_fit&&rows.Any()){var best=0d;for(var c=1;c<=Math.Min(100,rows.Count());c++){var lines=groups.Sum(g=>(int)Math.Ceiling(g.Count()/(double)c));var h=(_scroll.ActualHeight-groups.Length*24)/Math.Max(1,lines)-4;var score=Math.Min(width/c/1.4,h);if(score>best){best=score;columns=c;height=Math.Clamp(h,28,72);}}}
        foreach(var group in groups){if(group.Key.Length>0)_groups.Children.Add(Ui.Label(group.Key,_p.Muted,12,true));var grid=new Grid{ColumnSpacing=4,RowSpacing=4};for(var c=0;c<columns;c++)grid.ColumnDefinitions.Add(new());var index=0;foreach(var row in group){if(index%columns==0)grid.RowDefinitions.Add(new(){Height=GridLength.Auto});var content=new StackPanel{Spacing=3};content.Children.Add(new SymbolIcon(V(row,"t") switch{"phone"=>Symbol.CellPhone,"printer"=>Symbol.Print,"router"=>Symbol.World,"server"=>Symbol.Setting,_=>Symbol.AllApps}){Height=_fit?18:24});var name=new TextBlock{Text=V(row,"n"),FontSize=12,TextWrapping=TextWrapping.Wrap,MaxLines=_fit?1:2,TextAlignment=TextAlignment.Center};content.Children.Add(name);if(!_fit){content.Children.Add(Ui.Label(V(row,"ip"),N(row,"o")==1?ThemeService.Brush("#ffffff"):_p.Muted,10));content.Children.Add(Ui.Label(Duration(row),N(row,"o")==1?ThemeService.Brush("#ffffff"):_p.Muted,10));}var tile=new Button{Content=content,Height=height,Padding=new Thickness(3),HorizontalAlignment=HorizontalAlignment.Stretch,HorizontalContentAlignment=HorizontalAlignment.Center,Background=N(row,"o")==1?_p.Success:_p.Surface,Foreground=N(row,"o")==1?ThemeService.Brush("#ffffff"):_p.Text};AutomationProperties.SetName(tile,V(row,"n")+" · "+(State(row)=="unknown"?"Sem confirmação":N(row,"o")==1?"Online":"Offline"));tile.Click+=async(_,_)=>await DetailsAsync(row);Grid.SetColumn(tile,index%columns);Grid.SetRow(tile,index/columns);grid.Children.Add(tile);index++;}_groups.Children.Add(grid);}
        if(!rows.Any())_groups.Children.Add(Ui.Label("Nenhum dispositivo neste filtro. Atualize para consultar o inventário.",_p.Muted,13,wrap:true));
    }
    private async Task DetailsAsync(JsonElement row)
    {
        var panel=new StackPanel{Spacing=7};foreach(var (key,label) in new[]{("ip","IP"),("m","MAC"),("t","Tipo"),("g","Grupo")})panel.Children.Add(Ui.Label(label+": "+V(row,key),_p.Text,13,wrap:true));panel.Children.Add(Ui.Label(N(row,"x")==1?"Gestor ou coleta sem atualização; estado não confirmado":N(row,"o")==1?"Online":"Offline",_p.Text,13));foreach(var (key,label) in new[]{("s","No estado atual desde"),("c","Última mudança"),("l","Último contato"),("f","Primeira detecção")})panel.Children.Add(Ui.Label(label+": "+(N(row,key)>0?DateTimeOffset.FromUnixTimeSeconds(N(row,key)).ToLocalTime().ToString("dd/MM/yyyy HH:mm:ss"):"Não informado"),_p.Muted,12,wrap:true));await Ui.Dialog(XamlRoot,_p,V(row,"n"),panel,close:"Fechar",note:"Resumo deste equipamento no inventário atual.").ShowAsync();
    }
}
