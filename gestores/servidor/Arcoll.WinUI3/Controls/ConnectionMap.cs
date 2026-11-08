// Caminho: gestores/servidor/Arcoll.WinUI3/Controls/ConnectionMap.cs
using System.Net.Http;
using System.Text.Json;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;
using Microsoft.UI.Xaml.Shapes;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Models;
using Windows.Storage.Streams;
namespace Arcoll.WinUI3.Controls;
/// <summary>Mapa geográfico nativo. Ligações entre pontos são ilustrativas, não trajetos físicos dos pacotes.</summary>
public sealed class ConnectionMap : UserControl
{
    public sealed record Point(double Latitude,double Longitude,string Label);
    private readonly Canvas _canvas=new(){Height=260};private readonly Point[] _points;private readonly Palette _p;private readonly Dictionary<string,BitmapImage> _cache=new();private CancellationTokenSource? _cancel;private int _zoom=4;private bool _fit=true;
    private static readonly HttpClient Http=CreateHttp();
    private static readonly SemaphoreSlim GeoGate=new(1,1);
    private static readonly Dictionary<string,Point> GeoCache=new();
    public static async Task<Point?> GeocodeAsync(string address,CancellationToken ct)
    {
        if(string.IsNullOrWhiteSpace(address)||address.Length>500)return null;
        await GeoGate.WaitAsync(ct);
        try{if(GeoCache.TryGetValue(address,out var cached))return cached;await Task.Delay(1100,ct);var raw=await Http.GetStringAsync("https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q="+Uri.EscapeDataString(address),ct);if(raw.Length>65536)return null;using var doc=JsonDocument.Parse(raw);if(doc.RootElement.ValueKind!=JsonValueKind.Array||doc.RootElement.GetArrayLength()==0)return null;var item=doc.RootElement[0];if(!double.TryParse(item.GetProperty("lat").ToString(),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lat)||!double.TryParse(item.GetProperty("lon").ToString(),System.Globalization.NumberStyles.Float,System.Globalization.CultureInfo.InvariantCulture,out var lon)||Math.Abs(lat)>85||Math.Abs(lon)>180)return null;var point=new Point(lat,lon,"Destino · endereço estimado");if(GeoCache.Count>=50)GeoCache.Clear();GeoCache[address]=point;return point;}
        catch(Exception ex) when(ex is HttpRequestException or JsonException or KeyNotFoundException){return null;}
        finally{GeoGate.Release();}
    }

    private static HttpClient CreateHttp(){var h=new HttpClient{Timeout=TimeSpan.FromSeconds(12)};h.DefaultRequestHeaders.UserAgent.ParseAdd("TIDigital/"+AppInfo.Version);return h;}
    public ConnectionMap(IEnumerable<Point> points,Palette p)
    {
        _points=points.Where(x=>double.IsFinite(x.Latitude)&&double.IsFinite(x.Longitude)&&Math.Abs(x.Latitude)<=85&&Math.Abs(x.Longitude)<=180).ToArray();_p=p;
        var root=new StackPanel{Spacing=4};var bar=new StackPanel{Orientation=Orientation.Horizontal,Spacing=5};foreach(var (text,delta) in new[]{("+",1),("−",-1)}){var b=new Button{Content=text,MinWidth=28,MinHeight=28};b.Click+=async(_,_)=>{_fit=false;_zoom=Math.Clamp(_zoom+delta,1,15);await DrawAsync();};bar.Children.Add(b);}root.Children.Add(bar);root.Children.Add(_canvas);root.Children.Add(Ui.Label("© OpenStreetMap contributors · Localização por IP é aproximada. A linha não representa o caminho físico da conexão.",p.Muted,11,wrap:true));Content=root;Loaded+=async(_,_)=>await DrawAsync();SizeChanged+=async(_,e)=>{if(Math.Abs(e.NewSize.Width-e.PreviousSize.Width)>10)await DrawAsync();};Unloaded+=(_,_)=>_cancel?.Cancel();
    }
    private static (double X,double Y) Project(Point p,int z){var size=256*Math.Pow(2,z);var lat=p.Latitude*Math.PI/180;return((p.Longitude+180)/360*size,(1-Math.Log(Math.Tan(lat)+1/Math.Cos(lat))/Math.PI)/2*size);}
    private async Task DrawAsync()
    {
        _cancel?.Cancel();_cancel=new();var ct=_cancel.Token;_canvas.Children.Clear();var width=Math.Max(200,ActualWidth);_canvas.Clip=new RectangleGeometry{Rect=new Windows.Foundation.Rect(0,0,width,260)};_canvas.Background=_p.Alt;
        if(_points.Length==0){_canvas.Children.Add(Ui.Label("Localização não confirmada. Defina os pontos na plataforma; os saltos medidos continuam listados abaixo.",_p.Muted,13,wrap:true));return;}
        if(_fit){_zoom=_points.Length==1?6:15;while(_zoom>1){var projected=_points.Select(p=>Project(p,_zoom)).ToArray();if(projected.Max(p=>p.X)-projected.Min(p=>p.X)<width-100&&projected.Max(p=>p.Y)-projected.Min(p=>p.Y)<160)break;_zoom--;}}
        var positions=_points.Select(p=>Project(p,_zoom)).ToArray();var cx=positions.Average(p=>p.X);var cy=positions.Average(p=>p.Y);var left=cx-width/2;var top=cy-130;var n=1<<_zoom;
        var tasks=new List<Task>();var count=0;
        for(var x=(int)Math.Floor(left/256);x<=(int)Math.Floor((left+width)/256);x++)for(var y=(int)Math.Floor(top/256);y<=(int)Math.Floor((top+260)/256);y++){
            if(y<0||y>=n||count++>=20)continue;var tx=((x%n)+n)%n;var image=new Image{Width=256,Height=256};Canvas.SetLeft(image,x*256-left);Canvas.SetTop(image,y*256-top);_canvas.Children.Add(image);tasks.Add(TileAsync(image,$"https://tile.openstreetmap.org/{_zoom}/{tx}/{y}.png",ct));
        }
        if(positions.Length>1){var line=new Polyline{Stroke=_p.Accent,StrokeThickness=3};foreach(var pos in positions)line.Points.Add(new Windows.Foundation.Point(pos.X-left,pos.Y-top));_canvas.Children.Add(line);}
        for(var i=0;i<_points.Length;i++){var pin=new Border{Background=_p.Surface,BorderBrush=_p.Accent,BorderThickness=new Thickness(2),CornerRadius=new CornerRadius(7),Padding=new Thickness(5),Child=Ui.Label(_points[i].Label,_p.Text,12,true)};Canvas.SetLeft(pin,Math.Clamp(positions[i].X-left,0,Math.Max(0,width-130)));Canvas.SetTop(pin,Math.Clamp(positions[i].Y-top,0,230));_canvas.Children.Add(pin);}
        await Task.WhenAll(tasks);
    }
    private async Task TileAsync(Image image,string url,CancellationToken ct)
    {
        try{if(!_cache.TryGetValue(url,out var bitmap)){var bytes=await Http.GetByteArrayAsync(url,ct);if(bytes.Length>1048576)return;ct.ThrowIfCancellationRequested();using var stream=new InMemoryRandomAccessStream();using(var writer=new DataWriter(stream)){writer.WriteBytes(bytes);await writer.StoreAsync();writer.DetachStream();}stream.Seek(0);bitmap=new BitmapImage();await bitmap.SetSourceAsync(stream);if(_cache.Count>=64)_cache.Clear();_cache[url]=bitmap;}if(!ct.IsCancellationRequested)image.Source=bitmap;}catch(Exception ex) when(ex is HttpRequestException or OperationCanceledException or System.Runtime.InteropServices.COMException){/* Sem tiles, marcadores e dados medidos permanecem disponíveis. */}
    }
}
