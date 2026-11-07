// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/SpeedPage.cs
using Arcoll.WinUI3.Services;
using Arcoll.WinUI3.Controls;
using System.Text.Json;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Internet: mesmo fluxo de redes/velocidade.php — botão START circular, progresso, diagnóstico (download, upload, latência, perdas) e nota.</summary>
public sealed class SpeedPage : UserControl
{
    private sealed record Last(SpeedResult Result, double? Loss, int Sent, DateTime At, System.Text.Json.JsonElement Connection);
    private static Last? _last; // mantém o último resultado ao trocar de aba

    private readonly AgentRuntime _runtime;
    private Palette _p = new();
    private JsonElement _mapInfo;
    private bool _running;
    private double _progress; private string _caption = "", _live = "";
    private readonly StackPanel _root = new() { Spacing = 16, HorizontalAlignment = HorizontalAlignment.Stretch };

    public SpeedPage(AgentRuntime runtime) { _runtime = runtime; Content = _root; SizeChanged += (_, e) => { if (Math.Abs(e.NewSize.Width - e.PreviousSize.Width) > 1) Render(); }; Render(); }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private async Task StartAsync()
    {
        if (_running || !_runtime.Allowed) return;
        _running = true; _progress = 0; _caption = "Preparando teste…"; _live = ""; Render();
        try
        {
            var lastDraw = DateTime.MinValue;
            var progress = new Progress<(double Percent, string Message, double? Download)>(v => { if (v.Percent < 100 && DateTime.UtcNow - lastDraw < TimeSpan.FromMilliseconds(200)) return; lastDraw = DateTime.UtcNow; _progress = v.Percent; _caption = v.Message; _live = v.Download is double d ? d.ToString("0.0") : _live; Render(); });
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(180));
            var result = await _runtime.InternetNowAsync(progress, cancel.Token);
            try { _mapInfo=await WorkspaceService.GetAsync(_runtime,"connection-map",cancel.Token); } catch(Exception ex) { _caption="Mapa: "+ex.Message; }
            _last = new Last(result.Speed, result.Loss, result.Sent, DateTime.Now, result.Connection);
            _caption = result.SyncWarning.Length > 0 ? result.SyncWarning : "Velocidade e conexão atualizadas na plataforma.";
        }
        catch (Exception ex) { _caption = "Falha no teste: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); }
        finally { _running = false; Render(); }
    }

    private static string V(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var v)?v.ValueKind==JsonValueKind.Array?string.Join(", ",v.EnumerateArray().Select(x=>x.ToString())):v.ToString():"";
    private static JsonElement Part(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var v)?v:default;
    private void Render()
    {
        _root.Children.Clear();
        var inner=new StackPanel{Spacing=2,HorizontalAlignment=HorizontalAlignment.Center};inner.Children.Add(Ui.Label(_running?Math.Round(_progress)+"%":"START",_p.Text,28,true));inner.Children.Add(Ui.Label(_running?"MEDINDO":"INICIAR MEDIÇÃO",_p.Muted,11));
        var start=new Button{Content=inner,Width=_last is null?154:96,Height=_last is null?154:64,CornerRadius=new CornerRadius(_last is null?77:12),HorizontalAlignment=HorizontalAlignment.Center,BorderBrush=_p.Accent,BorderThickness=new Thickness(3),Background=_p.Surface,IsEnabled=!_running&&_runtime.Allowed};start.Click+=async(_,_)=>await StartAsync();Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(start,"internet-start");AutomationProperties_SetName(start,"Iniciar medição real no agente");
        _root.Children.Add(start);if(_caption.Length>0)_root.Children.Add(Ui.Label(_caption,_p.Muted,12,wrap:true));if(_last is null||_running)return;
        var grid=new Grid{ColumnSpacing=10,RowSpacing=10};var compact=ActualWidth<720;grid.ColumnDefinitions.Add(new());if(!compact)grid.ColumnDefinitions.Add(new());grid.RowDefinitions.Add(new(){Height=GridLength.Auto});if(compact)grid.RowDefinitions.Add(new(){Height=GridLength.Auto});
        var measurements=new StackPanel{Spacing=8};measurements.Children.Add(Ui.Label("MEDIÇÕES",_p.Text,16,true));var r=_last.Result;
        foreach(var row in new[]{("Download",r.Download is double down?down.ToString("0.0")+" Mbps":"Não confirmado"),("Upload",r.Upload is double up?up.ToString("0.0")+" Mbps":"Não confirmado"),("Latência",r.LatencyMs is double latency?latency.ToString("0")+" ms":"Sem resposta"),("Perdas",_last.Loss is double loss?loss.ToString("0.0")+"%":"Não confirmado")})measurements.Children.Add(Ui.Label(row.Item1+": "+row.Item2,_p.Text,15,true,true));
        measurements.Children.Add(Ui.Label($"{_last.At:dd/MM HH:mm} · {r.Server}",_p.Muted,12,wrap:true));
        var connection=_last.Connection;var iface=Part(connection,"interface");var provider=Part(connection,"provider");
        foreach(var row in new[]{("Provedora",V(provider,"isp")),("IP público",V(connection,"public_ip")),("IP local",V(iface,"ip")),("Gateway",V(iface,"gateway")),("DNS",V(iface,"dns")),("Interface",V(iface,"name")),("MAC",V(iface,"mac"))})measurements.Children.Add(Ui.Label(row.Item1+": "+(row.Item2.Length>0?row.Item2:"Não confirmado"),_p.Text,13,wrap:true));
        var map=new StackPanel{Spacing=7};map.Children.Add(Ui.Label("MAPA DA CONEXÃO",_p.Text,16,true));var points=new List<ConnectionMap.Point>();var saved=Part(_mapInfo,"points");
        foreach(var (key,label) in new[]{("origin","Origem confirmada"),("dest","Destino confirmado")}){var pt=Part(saved,key);if(double.TryParse(V(pt,"latitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lat)&&double.TryParse(V(pt,"longitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lon))points.Add(new(lat,lon,label));}
        if(!points.Any(p=>p.Label.StartsWith("Origem"))&&double.TryParse(V(provider,"latitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var la)&&double.TryParse(V(provider,"longitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lo))points.Insert(0,new(la,lo,"Provedora · IP aproximado"));
        map.Children.Add(new ConnectionMap(points,_p));map.Children.Add(Ui.Label("Origem: "+V(provider,"isp")+" · "+V(provider,"city")+" · "+V(provider,"country")+"\nDestino cadastrado: "+V(_mapInfo,"network_name"),_p.Text,13,wrap:true));map.Children.Add(Ui.Label("ROTA MEDIDA ATÉ 1.1.1.1",_p.Text,13,true));
        var hops=Part(connection,"route");if(hops.ValueKind==JsonValueKind.Array)foreach(var hop in hops.EnumerateArray())map.Children.Add(Ui.Label("↓ "+V(hop,"hop")+" · "+(V(hop,"ip").Length>0?V(hop,"ip"):"Sem resposta"),_p.Text,12,wrap:true));
        map.Children.Add(Ui.Label("Saltos sem resposta podem bloquear ICMP. IP não é GPS. O mapa não comprova o trajeto físico dos pacotes.",_p.Muted,12,wrap:true));var left=Ui.Card(measurements,_p);var right=Ui.Card(map,_p);grid.Children.Add(left);Grid.SetColumn(right,compact?0:1);Grid.SetRow(right,compact?1:0);grid.Children.Add(right);_root.Children.Add(grid);
    }
    private static void AutomationProperties_SetName(DependencyObject o,string name)=>Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(o,name);
}
