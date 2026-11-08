using System.Text;
using System.Text.Json;
using Microsoft.Win32;
using Microsoft.UI;
using Microsoft.UI.Xaml.Media;
using Windows.UI;
namespace Arcoll.WinUI3.Services;
public sealed record GestorTheme(string Id,string Mode,string Background,string Surface,string Text,string Accent,string Muted);
/// <summary>Catalog and published colors are the authority; offline cache contains no credentials.</summary>
public sealed class ThemeService:IDisposable
{
 private readonly HttpClient _http=new(new HttpClientHandler{AllowAutoRedirect=false,UseProxy=false}){Timeout=TimeSpan.FromSeconds(10)};
 private readonly SemaphoreSlim _refresh=new(1,1);private JsonElement _published;
 private static string CachePath=>Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"Arcoll","winui-theme.json");
 public GestorTheme Current{get;private set;}
 public ThemeService(){PlatformClient.ApplyDefaults(_http);_published=ReadCached();Current=Resolve(_published)??Palette("windows","theme");}
 private static JsonElement ReadCached(){try{using var doc=JsonDocument.Parse(File.ReadAllText(CachePath));if(doc.RootElement.TryGetProperty("visual",out var visual))return visual.Clone();var id=Get(doc.RootElement,"id");var mode=Get(doc.RootElement,"mode");return Catalog(id,mode);}catch(Exception e)when(e is IOException or JsonException or UnauthorizedAccessException){return Catalog("windows","theme");}}
 private static JsonElement Catalog(string id,string mode){try{using var doc=JsonDocument.Parse(File.ReadAllText(Path.Combine(AppContext.BaseDirectory,"Assets","theme-catalog.json")));if(doc.RootElement.GetProperty("themes").TryGetProperty(id,out var theme)){var values=JsonSerializer.Deserialize<Dictionary<string,object?>>(theme.GetRawText())!;values["mode"]=mode;return JsonSerializer.SerializeToElement(values);}}catch(Exception e)when(e is IOException or JsonException or UnauthorizedAccessException or KeyNotFoundException){}return JsonSerializer.SerializeToElement(new{id="windows",mode="theme",light=true,bg="#eef3fb",surface="#ffffff",text="#172b45",accent="#225bce",muted="#526782"});}
 private static bool WindowsLight(){try{using var key=Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");return Convert.ToInt32(key?.GetValue("AppsUseLightTheme",1))!=0;}catch(Exception e)when(e is IOException or UnauthorizedAccessException or System.Security.SecurityException){return true;}}
 private static GestorTheme? Resolve(JsonElement visual){var p=ThemeRules.Resolve(visual,WindowsLight());return p is null?null:new(p.Id,p.Mode,p.Background,p.Surface,p.Text,p.Accent,p.Muted);}
 public static GestorTheme Palette(string id,string mode)=>Resolve(Catalog(id,mode))??new("windows","theme","#eef3fb","#ffffff","#172b45","#225bce","#526782");
 public static bool IsLight(GestorTheme theme)=>theme.Mode switch{"auto"=>WindowsLight(),"light"=>true,"dark"=>false,_=>ThemeRules.IsLight(theme.Surface)};
 public async Task<GestorTheme> RefreshAsync(CancellationToken token){if(!await _refresh.WaitAsync(0,token))return Current;try{var cached=Resolve(_published);if(cached is not null)Current=cached;var cfg=GestorConfig.Load();if(!cfg.IsComplete)return Current;using var req=new HttpRequestMessage(HttpMethod.Post,cfg.Origin+"/api/api.php?action=gestor-config"){Content=PlatformClient.JsonBody(new{fingerprint=cfg.Fingerprint,site_code=cfg.SiteCode,download_key=cfg.DownloadKey})};using var response=await _http.SendAsync(req,HttpCompletionOption.ResponseHeadersRead,token);response.EnsureSuccessStatusCode();await using var source=await response.Content.ReadAsStreamAsync(token);using var bytes=new MemoryStream();var buffer=new byte[16384];int n;while((n=await source.ReadAsync(buffer,token))>0){if(bytes.Length+n>4*1024*1024)throw new InvalidDataException("Configuração visual acima do limite.");bytes.Write(buffer,0,n);}using var payload=JsonDocument.Parse(bytes.ToArray());var root=payload.RootElement;if(root.TryGetProperty("ok",out var ok)&&ok.ValueKind==JsonValueKind.True&&root.TryGetProperty("visual_theme",out var visual)){var next=Resolve(visual);if(next is not null){var changed=_published.GetRawText()!=visual.GetRawText();_published=visual.Clone();Current=next;if(changed)Save();}}return Current;}finally{_refresh.Release();}}
 private void Save(){var tmp=CachePath+"."+Guid.NewGuid().ToString("N")+".tmp";try{Directory.CreateDirectory(Path.GetDirectoryName(CachePath)!);File.WriteAllText(tmp,JsonSerializer.Serialize(new{visual=_published}),new UTF8Encoding(false));File.Move(tmp,CachePath,true);}catch(Exception e)when(e is IOException or UnauthorizedAccessException){}finally{try{if(File.Exists(tmp))File.Delete(tmp);}catch(IOException){}catch(UnauthorizedAccessException){}}}
 private static string Get(JsonElement e,string name)=>e.ValueKind==JsonValueKind.Object&&e.TryGetProperty(name,out var p)&&p.ValueKind==JsonValueKind.String?p.GetString()??"":"";
 public static SolidColorBrush Brush(string hex){if(!ThemeRules.IsHex(hex))return new SolidColorBrush(Colors.Gray);return new SolidColorBrush(Color.FromArgb(255,Convert.ToByte(hex[1..3],16),Convert.ToByte(hex[3..5],16),Convert.ToByte(hex[5..7],16)));}
 public void Dispose(){_http.Dispose();_refresh.Dispose();}
}
