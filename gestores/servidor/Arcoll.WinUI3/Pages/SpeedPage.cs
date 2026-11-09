// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/SpeedPage.cs
using Arcoll.WinUI3.Services;
using Arcoll.WinUI3.Controls;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Internet híbrida com diagnóstico real e fallback nativo. Referência anterior: mesmo fluxo de redes/velocidade.php — botão START circular, progresso, diagnóstico (download, upload, latência, perdas) e nota.</summary>
public sealed class SpeedPage : UserControl, IPageDataSource
{
    private sealed record Last(SpeedResult Result, double? Loss, int Sent, DateTime At, System.Text.Json.JsonElement Connection);
    private static Last? _last; // mantém o último resultado ao trocar de aba

    public PageDataLoad DataLoad{get;}=new("Clique para medir a conexão deste computador.");
    private CancellationTokenSource? _testCancellation;private bool _testCancelledByUser;
    public bool CanCancelCollection=>true;public void CancelCollection(){_testCancelledByUser=true;_testCancellation?.Cancel();}
    public Task CollectDataAsync()=>StartAsync();
    private readonly GestorRuntime _runtime;
    private readonly WebView2 _web = new() { HorizontalAlignment = HorizontalAlignment.Stretch, VerticalAlignment = VerticalAlignment.Stretch };
    private bool _ready, _loading, _active;
    private const string Origin = "https://internet.tidigital.local/";

    private async Task InitializeAsync()
    {
        if (_ready || _loading) return;
        _loading = true;
        try
        {
            var folder = Path.Combine(AppContext.BaseDirectory, "Assets", "hybrid", "internet");
            if (!File.Exists(Path.Combine(folder, "index.html"))) throw new FileNotFoundException("Interface Internet ausente.");
            var profile = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "HybridWebView2");
            Directory.CreateDirectory(profile);
            var environment = await CoreWebView2Environment.CreateWithOptionsAsync(null, profile, null);
            await _web.EnsureCoreWebView2Async(environment);
            var web = _web.CoreWebView2;
            web.Settings.AreDevToolsEnabled = false;
            web.Settings.AreDefaultContextMenusEnabled = false;
            web.Settings.AreHostObjectsAllowed = false;
            web.SetVirtualHostNameToFolderMapping("internet.tidigital.local", folder, CoreWebView2HostResourceAccessKind.DenyCors);
            web.NavigationStarting += (_, e) => { if (!e.Uri.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) e.Cancel = true; };
            web.NewWindowRequested += (_, e) => e.Handled = true;
            web.WebMessageReceived += async (_, e) =>
            {
                if (!e.Source.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) return;
                try
                {
                    using var doc = JsonDocument.Parse(e.WebMessageAsJson);
                    var action = V(doc.RootElement, "action");
                    if (action == "ready") { _ready = true; Publish();if(_last is not null&&!_running)DataLoad.Complete(); }
                    else if (action == "start") await StartAsync();
                }
                catch (JsonException) { }
            };
            _web.Source = new Uri(Origin + "index.html");
        }
        catch (Exception ex) { _caption = "Interface nativa: " + ex.Message; Content = _root; RenderNative(); }
        finally { _loading = false; }
    }

    private static string Color(Brush brush) => brush is SolidColorBrush b ? $"#{b.Color.R:X2}{b.Color.G:X2}{b.Color.B:X2}" : "#2563eb";
    private void Publish()
    {
        if (!_ready || !_active) return;
        var data = new { running = _running, allowed = _runtime.Allowed, progress = _progress, caption = _caption,
            theme = new { light = _p.Light, surface = Color(_p.Surface), alt = Color(_p.Alt), text = Color(_p.Text), muted = Color(_p.Muted), accent = Color(_p.Accent), line = Color(_p.Line) },
            result = _last?.Result, loss = _last?.Loss, at = _last?.At.ToString("dd/MM/yyyy HH:mm"),
            connection = _last is null || _last.Connection.ValueKind == JsonValueKind.Undefined ? (JsonElement?)null : _last.Connection,
            map = _mapInfo.ValueKind == JsonValueKind.Undefined ? (JsonElement?)null : _mapInfo,
            destination = _destination };
        try { _web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(data)); }
        catch (InvalidOperationException) { }
    }

    private void Render() { if (ReferenceEquals(Content, _web)) Publish(); else RenderNative(); }

    private Palette _p = new();
    private JsonElement _mapInfo;
    private ConnectionMap.Point? _destination;
    private bool _running;
    private double _progress; private string _caption = "", _live = "";
    private readonly StackPanel _root = new() { Spacing = 16, HorizontalAlignment = HorizontalAlignment.Stretch };

    public SpeedPage(GestorRuntime runtime) { _runtime = runtime; Content = _web; Loaded += async (_, _) => { try { _active = true; await InitializeAsync(); Publish(); } catch (Exception ex) { RuntimeLog.Write("Internet/Loaded: " + ex); } }; Unloaded += (_, _) => _active = false; SizeChanged += (_, e) => { if (Math.Abs(e.NewSize.Width - e.PreviousSize.Width) > 1) Render(); }; Render(); }

    public void ApplyPalette(Palette p) { _p = p; Render(); }

    private async Task StartAsync()
    {
        if (_running || !_runtime.Allowed) return;
        _testCancelledByUser=false;var loadOperation=DataLoad.Begin("Preparando a medição de Internet");_running = true; _progress = 0; _caption = "Preparando teste…"; _live = ""; Render();
        try
        {
            var lastDraw = DateTime.MinValue;
            var progress = new Progress<(double Percent, string Message, double? Download)>(v => { if (v.Percent < 100 && DateTime.UtcNow - lastDraw < TimeSpan.FromMilliseconds(200)) return; lastDraw = DateTime.UtcNow; _progress = v.Percent;DataLoad.Report(v.Message,(int)v.Percent,loadOperation); _caption = v.Message; _live = v.Download is double d ? d.ToString("0.0") : _live; Render(); });
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(180));_testCancellation=cancel;
            var result = await _runtime.InternetNowAsync(progress, cancel.Token);
            if(result.Speed.Download is null&&result.Speed.Upload is null&&result.Speed.LatencyMs is null)throw new IOException("O servidor de teste não retornou medições válidas. Verifique a conexão e tente novamente.");
            try { _mapInfo=await WorkspaceService.GetAsync(_runtime,"connection-map",cancel.Token);_destination=Part(Part(_mapInfo,"points"),"dest").ValueKind==JsonValueKind.Object?null:await ConnectionMap.GeocodeAsync(V(_mapInfo,"address"),cancel.Token); } catch(Exception ex) { _caption="Mapa: "+ex.Message; }
            _last = new Last(result.Speed, result.Loss, result.Sent, DateTime.Now, result.Connection);
            DataLoad.Report("Organizando o diagnóstico e o mapa da conexão",99,loadOperation);
            _caption = result.SyncWarning.Length > 0 ? result.SyncWarning : "Velocidade e conexão atualizadas na plataforma.";
        }
        catch (Exception ex) { DataLoad.Fail("Não foi possível medir a conexão: "+(ex is OperationCanceledException?(_testCancelledByUser?"coleta interrompida pelo usuário":"tempo limite excedido"):ex.Message),loadOperation);_caption = "Falha no teste: " + (ex is OperationCanceledException ? (_testCancelledByUser?"coleta interrompida pelo usuário":"tempo limite excedido") : ex.Message); }
        finally { _testCancellation=null;_running = false; Render();if(DataLoad.Current.Phase!=DataLoadPhase.Failed){if(_ready||!ReferenceEquals(Content,_web))DataLoad.Complete("Diagnóstico de Internet disponível",loadOperation);else DataLoad.Report("Preparando a apresentação dos resultados",99,loadOperation);} }
    }

    private static string V(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var v)?v.ValueKind==JsonValueKind.Array?string.Join(", ",v.EnumerateArray().Select(x=>x.ToString())):v.ToString():"";
    private static JsonElement Part(JsonElement e,string key)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(key,out var v)?v:default;
    private void RenderNative()
    {
        _root.Children.Clear();
        var inner=new StackPanel{Spacing=2,HorizontalAlignment=HorizontalAlignment.Center};inner.Children.Add(Ui.Label(_running?Math.Round(_progress)+"%":"START",_p.Text,28,true));inner.Children.Add(Ui.Label(_running?"MEDINDO":"INICIAR MEDIÇÃO",_p.Muted,11));
        var start=new Button{Content=inner,Width=_last is null?154:96,Height=_last is null?154:64,CornerRadius=new CornerRadius(_last is null?77:12),HorizontalAlignment=HorizontalAlignment.Center,BorderBrush=_p.Accent,BorderThickness=new Thickness(3),Background=_p.Surface,IsEnabled=!_running&&_runtime.Allowed};start.Click+=async(_,_)=>await StartAsync();Microsoft.UI.Xaml.Automation.AutomationProperties.SetAutomationId(start,"internet-start");AutomationProperties_SetName(start,"Iniciar medição real no gestor");
        _root.Children.Add(start);if(_caption.Length>0)_root.Children.Add(Ui.Label(_caption,_p.Muted,12,wrap:true));if(_last is null||_running)return;
        var grid=new Grid{ColumnSpacing=10,RowSpacing=10};var compact=ActualWidth<720;grid.ColumnDefinitions.Add(new());if(!compact)grid.ColumnDefinitions.Add(new());grid.RowDefinitions.Add(new(){Height=GridLength.Auto});if(compact)grid.RowDefinitions.Add(new(){Height=GridLength.Auto});
        var measurements=new StackPanel{Spacing=8};measurements.Children.Add(Ui.Label("MEDIÇÕES",_p.Text,16,true));var r=_last.Result;
        foreach(var row in new[]{("Download",r.Download is double down?down.ToString("0.0")+" Mbps":"Não confirmado"),("Upload",r.Upload is double up?up.ToString("0.0")+" Mbps":"Não confirmado"),("Latência",r.LatencyMs is double latency?latency.ToString("0")+" ms":"Sem resposta"),("Perdas",_last.Loss is double loss?loss.ToString("0.0")+"%":"Não confirmado"),("Jitter HTTP",r.JitterMs is double jitter?jitter.ToString("0.0")+" ms":"Não aferido"),("Latência com download",r.LoadedDownloadMs is double ld?ld.ToString("0.0")+" ms":"Não aferida"),("Latência com upload",r.LoadedUploadMs is double lu?lu.ToString("0.0")+" ms":"Não aferida")})measurements.Children.Add(Ui.Label(row.Item1+": "+row.Item2,_p.Text,15,true,true));
        measurements.Children.Add(Ui.Label($"{_last.At:dd/MM HH:mm} · {r.Server}",_p.Muted,12,wrap:true));
        measurements.Children.Add(Ui.Label($"Tráfego do teste: {r.DownloadBytes/1_000_000d:0.0} MB recebidos · {r.UploadBytes/1_000_000d:0.0} MB enviados. Velocidades estimadas em nível de aplicação (HTTP/TLS).",_p.Muted,12,wrap:true));
        var connection=_last.Connection;var iface=Part(connection,"interface");var provider=Part(connection,"provider");
        foreach(var row in new[]{("Provedora",V(provider,"isp")),("IP público",V(connection,"public_ip")),("IP local",V(iface,"ip")),("Gateway",V(iface,"gateway")),("DNS",V(iface,"dns")),("Interface",V(iface,"name")),("MAC",V(iface,"mac"))})measurements.Children.Add(Ui.Label(row.Item1+": "+(row.Item2.Length>0?row.Item2:"Não confirmado"),_p.Text,13,wrap:true));
        var map=new StackPanel{Spacing=7};map.Children.Add(Ui.Label("MAPA DA CONEXÃO",_p.Text,16,true));var points=new List<ConnectionMap.Point>();var saved=Part(_mapInfo,"points");
        foreach(var (key,label) in new[]{("origin","Origem confirmada"),("dest","Destino confirmado")}){var pt=Part(saved,key);if(double.TryParse(V(pt,"latitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lat)&&double.TryParse(V(pt,"longitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lon))points.Add(new(lat,lon,label));}
        if(!points.Any(p=>p.Label.StartsWith("Origem"))&&double.TryParse(V(provider,"latitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var la)&&double.TryParse(V(provider,"longitude"),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lo))points.Insert(0,new(la,lo,"Provedora · IP aproximado"));
        if(!points.Any(p=>p.Label.StartsWith("Destino"))&&_destination is not null)points.Add(_destination);
        map.Children.Add(new ConnectionMap(points,_p));map.Children.Add(Ui.Label("Origem: "+V(provider,"isp")+" · "+V(provider,"city")+" · "+V(provider,"country")+"\nDestino cadastrado: "+V(_mapInfo,"network_name")+" · "+V(_mapInfo,"address"),_p.Text,13,wrap:true));map.Children.Add(Ui.Label("SALTOS DO TRACEROUTE ATÉ 1.1.1.1",_p.Text,13,true));
        var hops=Part(connection,"route");if(hops.ValueKind==JsonValueKind.Array)foreach(var hop in hops.EnumerateArray())map.Children.Add(Ui.Label("↓ "+V(hop,"hop")+" · "+(V(hop,"ip").Length>0?V(hop,"ip"):"Sem resposta"),_p.Text,12,wrap:true));
        map.Children.Add(Ui.Label("Saltos sem resposta podem bloquear ICMP. IP não é GPS. O mapa não comprova o trajeto físico dos pacotes.",_p.Muted,12,wrap:true));var left=Ui.Card(measurements,_p);var right=Ui.Card(map,_p);grid.Children.Add(left);Grid.SetColumn(right,compact?0:1);Grid.SetRow(right,compact?1:0);grid.Children.Add(right);_root.Children.Add(grid);
    }
    private static void AutomationProperties_SetName(DependencyObject o,string name)=>Microsoft.UI.Xaml.Automation.AutomationProperties.SetName(o,name);
}
