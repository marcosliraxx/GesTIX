// Caminho: gestores/servidor/Arcoll.WinUI3/Services/ThemeService.cs
using System.Text;
using Microsoft.Win32;
using System.Text.Json;
using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Media;
using Windows.UI;

namespace Arcoll.WinUI3.Services;

public sealed record GestorTheme(string Id,string Mode,string Background,string Surface,string Text,string Accent,string Muted);

/// <summary>Consome o mesmo tema administrativo que a API de configuração do gestor Python.</summary>
public sealed class ThemeService : IDisposable
{
    private static readonly Dictionary<string,string[]> Palettes = new(StringComparer.OrdinalIgnoreCase)
    {
        ["windows"]=["#edf3fa","#ffffff","#172b44","#2563eb","#536982"],
        ["ocean"]=["#081c30","#112b44","#e7f7ff","#26bae9","#a7c1d5"],
        ["crystal"]=["#f2f5fa","#ffffff","#222842","#6366f1","#64718c"],
        ["graphite"]=["#16191f","#242a34","#f1f4fa","#9aaee5","#b0bbc9"],
        ["neon"]=["#110e27","#221a40","#f5f0ff","#a879ff","#c5b4e3"],
        ["emerald"]=["#0b211d","#15352d","#e6fcf3","#32d4a1","#acd5c5"]
    };
    private readonly HttpClient _http = new(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromSeconds(10) };
    public ThemeService() { PlatformClient.ApplyDefaults(_http); }
    public GestorTheme Current { get; private set; } = ReadCachedTheme();
    private static string CachePath => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Arcoll", "winui-theme.json");
    private static GestorTheme ReadCachedTheme()
    {
        // O tema publicado pode ser restaurado após uma desconexão, sem armazenar chaves de acesso.
        foreach (var file in new[] {
            CachePath,
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),"Arcoll","appearance.json")
        })
        {
            try
            {
                if (!File.Exists(file)) continue;
                using var doc = JsonDocument.Parse(File.ReadAllText(file));
                var root=doc.RootElement;
                var id=Get(root,"id");
                if (string.IsNullOrEmpty(id)) id=Get(root,"remote_theme");
                if (string.IsNullOrEmpty(id)) id=Get(root,"theme_id");
                var mode=Get(root,"mode");
                if (string.IsNullOrEmpty(mode)) mode=Get(root,"remote_mode");
                if (Palettes.ContainsKey(id)) return Palette(id,ValidMode(mode));
            }
            catch (Exception) { /* arquivo ausente ou truncado: usa o próximo candidato */ }
        }
        return Palette("windows","theme");
    }
    private static string ValidMode(string mode) => mode is "auto" or "light" or "dark" or "theme" ? mode : "theme";
    private static bool WindowsLightMode()
    {
        try
        {
            using var key=Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            return Convert.ToInt32(key?.GetValue("AppsUseLightTheme",1)) != 0;
        }
        catch (Exception) { return true; }
    }
    public static bool IsLight(GestorTheme t)
    {
        if (t.Mode is "light" or "dark") return t.Mode=="light";
        if (t.Mode=="auto") return WindowsLightMode();
        return t.Id is "windows" or "crystal";
    }
    private static void SaveTheme(GestorTheme theme)
    {
        try
        {
            var dir=Path.GetDirectoryName(CachePath)!;
            Directory.CreateDirectory(dir);
            var temp=CachePath+"."+Guid.NewGuid().ToString("N")+".tmp";
            try
            {
                File.WriteAllText(temp,JsonSerializer.Serialize(new {id=theme.Id,mode=theme.Mode}),Encoding.UTF8);
                File.Move(temp,CachePath,true);
            }
            finally {if(File.Exists(temp)) File.Delete(temp);}
        }
        catch (IOException) { } catch (UnauthorizedAccessException) { }
    }
    public static GestorTheme Palette(string id,string mode)
    {
        if (!Palettes.TryGetValue(id,out var p)) { id="windows";p=Palettes[id]; }
        // Mesma regra do AppearanceManager Python: mantém a cor de identidade,
        // mas usa superfícies neutras quando o modo solicitado inverte a luminosidade.
        var requestedLight=mode switch {"light"=>true,"dark"=>false,"auto"=>WindowsLightMode(),_=>id is "windows" or "crystal"};
        var defaultLight=id is "windows" or "crystal";
        if (mode!="theme" && requestedLight!=defaultLight)
        {
            var neutral=requestedLight
                ? new[]{"#edf2f7","#ffffff","#203249","#5d7189"}
                : new[]{"#0b1420","#111f30","#e5edf8","#9db0c8"};
            return new GestorTheme(id,mode,neutral[0],neutral[1],neutral[2],p[3],neutral[3]);
        }
        return new GestorTheme(id,mode,p[0],p[1],p[2],p[3],p[4]);
    }
    public async Task<GestorTheme> RefreshAsync(CancellationToken token)
    {
        var cfg=GestorConfig.Load();
        if (!cfg.IsComplete) return Current;
        using var request = new HttpRequestMessage(HttpMethod.Post,new Uri(cfg.Origin + "/api/api.php?action=gestor-config"));
        request.Content=PlatformClient.JsonBody(new {fingerprint=cfg.Fingerprint,site_code=cfg.SiteCode,download_key=cfg.DownloadKey});
        using var response = await _http.SendAsync(request,token);
        response.EnsureSuccessStatusCode();
        using var payload = await JsonDocument.ParseAsync(await response.Content.ReadAsStreamAsync(token),new JsonDocumentOptions(),token);
        if (payload.RootElement.TryGetProperty("ok",out var ok) && ok.ValueKind==JsonValueKind.True && payload.RootElement.TryGetProperty("visual_theme",out var visual))
        {
            var id=Get(visual,"id");var mode=ValidMode(Get(visual,"mode"));
            if (Palettes.ContainsKey(id))
            {
                var next=Palette(id,mode);
                if (next!=Current) {Current=next;SaveTheme(next);}
            }
        }
        return Current;
    }
    private static string Get(JsonElement e,string name)=>e.ValueKind==JsonValueKind.Object && e.TryGetProperty(name,out var prop)&&prop.ValueKind==JsonValueKind.String ? prop.GetString()??"" : "";
    public static SolidColorBrush Brush(string hex)
    {
        hex=hex.TrimStart('#');
        if (hex.Length!=6) return new SolidColorBrush(Colors.Gray);
        return new SolidColorBrush(Color.FromArgb(255,Convert.ToByte(hex[..2],16),Convert.ToByte(hex[2..4],16),Convert.ToByte(hex[4..6],16)));
    }
    public void Dispose()=>_http.Dispose();
}
