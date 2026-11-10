// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/MapPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;

namespace Arcoll.WinUI3.Pages;

/// <summary>Aba Mapa: mesma interface da plataforma (redes/mapa.php), hospedada em WebView2 com a barra padrão das ferramentas.</summary>
public sealed class MapPage : UserControl, IPageDataSource
{
    public PageDataLoad DataLoad{get;}=new("Consultando a topologia da rede",true);
    public Task CollectDataAsync()=>LoadAsync();

    private const string Origin = "https://mapa.tidigital.local/";
    private readonly GestorRuntime _runtime;
    private readonly Action<string> _notify;
    private readonly WebView2 _web = new() { HorizontalAlignment = HorizontalAlignment.Stretch, VerticalAlignment = VerticalAlignment.Stretch };
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(20) };
    private readonly TextBlock _fallback = new() { Margin = new Thickness(16), TextWrapping = TextWrapping.Wrap };
    private Palette _p = new();
    private IReadOnlyList<DeviceRecord> _all = Array.Empty<DeviceRecord>();
    private bool _busy, _loaded, _scanning, _ready, _loading, _active;
    private string _message = "";

    public MapPage(GestorRuntime runtime, Action<string> notify)
    {
        _runtime = runtime; _notify = notify; Content = _web;
        _timer.Tick += async (_, _) => await LoadAsync();
        Loaded += async (_, _) => { try { _active = true; _timer.Start(); await InitializeAsync(); await LoadAsync(); } catch (Exception ex) { DataLoad.Fail("Não foi possível carregar: " + ex.Message); RuntimeLog.Write("Mapa/Loaded: " + ex); _timer.Stop(); } };
        Unloaded += (_, _) => { _active = false; _timer.Stop(); };
    }

    public void ApplyPalette(Palette p) { _p = p; }

    private async Task InitializeAsync()
    {
        if (_ready || _loading) return; _loading = true;
        try
        {
            var folder = Path.Combine(AppContext.BaseDirectory, "Assets", "hybrid", "mapa");
            if (!File.Exists(Path.Combine(folder, "index.html"))) throw new FileNotFoundException("Interface do Mapa ausente.");
            var profile = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "HybridWebView2");
            Directory.CreateDirectory(profile);
            var environment = await CoreWebView2Environment.CreateWithOptionsAsync(null, profile, null);
            await _web.EnsureCoreWebView2Async(environment);
            var web = _web.CoreWebView2;
            web.Settings.AreDevToolsEnabled = false;
            web.Settings.AreDefaultContextMenusEnabled = false;
            web.Settings.AreHostObjectsAllowed = false;
            web.SetVirtualHostNameToFolderMapping("mapa.tidigital.local", folder, CoreWebView2HostResourceAccessKind.DenyCors);
            web.NavigationStarting += (_, e) => { if (!e.Uri.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) e.Cancel = true; };
            web.NewWindowRequested += (_, e) => e.Handled = true;
            web.WebMessageReceived += async (_, e) =>
            {
                if (!e.Source.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) return;
                try
                {
                    using var doc = JsonDocument.Parse(e.WebMessageAsJson);
                    var action = Text(doc.RootElement, "action");
                    if (action == "ready") { _ready = true; Publish(); }
                    else if (action == "scan") await ScanAsync();
                    else if (action == "device") await EditAsync(Number(doc.RootElement, "id"));
                    else if (action == "move") await MoveAsync(Number(doc.RootElement, "id"), Text(doc.RootElement, "group"));
                }
                catch (JsonException) { }
                catch (Exception ex) { _message = "Falha na operação: " + ex.Message; RuntimeLog.Write("Mapa/Mensagem: " + ex); Publish(); }
            };
            _web.Source = new Uri(Origin + "index.html");
        }
        catch (Exception ex) { _fallback.Text = "Não foi possível abrir a interface do Mapa: " + ex.Message; Content = _fallback; RuntimeLog.Write("Mapa/Inicialização: " + ex); }
        finally { _loading = false; }
    }

    private static string Text(JsonElement e, string key) => e.ValueKind == JsonValueKind.Object && e.TryGetProperty(key, out var v) ? v.ToString() : "";
    private static long Number(JsonElement e, string key) => e.ValueKind == JsonValueKind.Object && e.TryGetProperty(key, out var v) && v.TryGetInt64(out var n) ? n : 0;

    private void Publish()
    {
        if (!_ready || !_active || _web.CoreWebView2 is null) return;
        var data = new
        {
            type = "data",
            network = _runtime.NetworkName.Length > 0 ? _runtime.NetworkName : "Rede",
            scanning = _scanning,
            message = _message,
            devices = _all.OrderByDescending(d => d.Pinned).ThenBy(d => d.Name, StringComparer.CurrentCultureIgnoreCase)
                .Select(d => new { id = d.Id, name = d.Name, ip = d.Ip, type = d.Type, online = d.Online, gestor = d.GestorInstalled, group = d.Group.Length > 0 ? d.Group : "Sem grupo" })
        };
        try { _web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(data)); }
        catch (InvalidOperationException) { }
    }

    private async Task LoadAsync()
    {
        if (_busy || !_runtime.Allowed) return; _busy = true; DataLoad.Begin("Consultando a topologia da rede", preserveData: true);
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20)); _all = await InventoryService.LoadAsync(_runtime.Platform, cancel.Token); _loaded = true; DataLoad.Complete(); }
        catch (Exception ex) { DataLoad.Fail("Não foi possível carregar: " + ex.Message); _message = "Não foi possível consultar o inventário: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message); }
        finally { _busy = false; Publish(); }
    }

    /// <summary>Tocar na rede executa a varredura (mesmo comportamento do hub no painel web).</summary>
    private async Task ScanAsync()
    {
        if (_scanning) return; _scanning = true; _message = "Examinando dispositivos da rede…"; Publish();
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(120)); var n = await _runtime.ScanNowAsync(cancel.Token); _message = $"{n} dispositivos sincronizados."; }
        catch (Exception ex) { _message = "Falha na varredura: " + ex.Message; }
        finally { _scanning = false; }
        await LoadAsync();
    }

    private async Task EditAsync(long id)
    {
        var device = _all.FirstOrDefault(d => d.Id == id); if (device is null || XamlRoot is null) return;
        if (await DeviceEditor.ShowAsync(XamlRoot, device, _p, _runtime, s => { _message = s; _notify(s); }) != EditorResult.Cancelled) await LoadAsync();
    }

    private async Task MoveAsync(long id, string group)
    {
        var device = _all.FirstOrDefault(d => d.Id == id); if (device is null) return;
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20)); device.Group = group; await InventoryService.SaveAsync(_runtime.Platform, device, false, cancel.Token); _message = "Equipamento movido para " + (group.Length > 0 ? group : "Sem grupo") + "."; }
        catch (Exception ex) { _message = "Não foi possível mover o equipamento: " + ex.Message; }
        await LoadAsync();
    }
}
