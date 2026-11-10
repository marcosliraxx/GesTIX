// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/FilesPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.Web.WebView2.Core;
namespace Arcoll.WinUI3.Pages;
/// <summary>Navegação local e acesso explícito ao Explorer da sessão Windows.</summary>
public sealed class FilesPage : UserControl, IPageDataSource
{
    private const string Origin = "https://arquivos.tidigital.local/";
    private static readonly JsonSerializerOptions Json = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
    public PageDataLoad DataLoad { get; } = new("Consultando discos e pastas", true);
    public Task CollectDataAsync() => !_ready ? InitializeAsync() : BrowseAsync(_current);
    private readonly GestorRuntime _runtime;
    private readonly WebView2 _web = new() { HorizontalAlignment = HorizontalAlignment.Stretch, VerticalAlignment = VerticalAlignment.Stretch };
    private readonly SemaphoreSlim _gate = new(1, 1);
    private bool _ready, _loading, _bridge;
    private string _current = "";
    private readonly HashSet<string> _files = new(StringComparer.OrdinalIgnoreCase);
    public FilesPage(GestorRuntime runtime)
    {
        _runtime = runtime; Content = _web;
        Loaded += async (_, _) => { try { await InitializeAsync(); } catch (Exception ex) { RuntimeLog.Write("Arquivos/Loaded: " + ex); } };
    }
    public void ApplyPalette(Palette p) { }
    private void Post(object data) { if (!_ready) return; try { _web.CoreWebView2.PostWebMessageAsJson(JsonSerializer.Serialize(data, Json)); } catch (InvalidOperationException) { } }
    private async Task InitializeAsync()
    {
        if (_ready || _loading) return; _loading = true; DataLoad.Begin("Consultando discos e pastas", true);
        try
        {
            if (_bridge && _web.CoreWebView2 is not null) { _web.CoreWebView2.Reload(); return; }
            var folder = Path.Combine(AppContext.BaseDirectory, "Assets", "hybrid", "arquivos");
            if (!File.Exists(Path.Combine(folder, "index.html"))) throw new FileNotFoundException("Interface de Arquivos não instalada.");
            var profile = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "ArquivosWebView");
            Directory.CreateDirectory(profile);
            var env = await CoreWebView2Environment.CreateWithOptionsAsync(null, profile, null);
            await _web.EnsureCoreWebView2Async(env);
            var web = _web.CoreWebView2 ?? throw new InvalidOperationException("WebView2 não inicializado.");
            web.Settings.AreDevToolsEnabled = false; web.Settings.AreDefaultContextMenusEnabled = false; web.Settings.AreHostObjectsAllowed = false;
            web.SetVirtualHostNameToFolderMapping("arquivos.tidigital.local", folder, CoreWebView2HostResourceAccessKind.DenyCors);
            web.NavigationStarting += (_, e) => { if (!e.Uri.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) e.Cancel = true; };
            web.NewWindowRequested += (_, e) => e.Handled = true;
            web.WebMessageReceived += async (_, e) =>
            {
                if (!e.Source.StartsWith(Origin, StringComparison.OrdinalIgnoreCase)) return;
                try
                {
                    using var doc = JsonDocument.Parse(e.WebMessageAsJson); var root = doc.RootElement;
                    var action = root.TryGetProperty("action", out var a) && a.ValueKind == JsonValueKind.String ? a.GetString() : "";
                    if (action == "ready") { _ready = true; Post(new { type = "machine", name = Environment.MachineName }); await BrowseAsync(_current); return; }
                    if (action == "open")
                    {
                        if (!_runtime.Allowed) throw new UnauthorizedAccessException("Autorize o gestor na plataforma.");
                        var name = root.TryGetProperty("name", out var n) && n.ValueKind == JsonValueKind.String ? n.GetString() ?? "" : "";
                        if (_current.Length == 0 || !_files.Contains(name) || Path.GetFileName(name) != name)
                            throw new UnauthorizedAccessException("Selecione um arquivo da pasta atual.");
                        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
                        var listing = await FileBrowserService.BrowseAsync(_current, false, timeout.Token);
                        var items = (List<Dictionary<string, object?>>)listing["entries"]!;
                        if (!items.Any(item => item["directory"] is false && string.Equals(item["name"] as string, name, StringComparison.OrdinalIgnoreCase)))
                            throw new IOException("O arquivo mudou ou não está mais acessível. Atualize a pasta.");
                        var target = Path.Combine(_current, name);
                        if ((File.GetAttributes(target) & FileAttributes.ReparsePoint) != 0)
                            throw new UnauthorizedAccessException("Arquivo redirecionado não permitido.");
                        // Ação local explícita: o Windows escolhe o aplicativo associado e aplica suas permissões.
                        System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(target) { UseShellExecute = true });
                        return;
                    }
                    if (action == "explorer")
                    {
                        if (!_runtime.Allowed) throw new UnauthorizedAccessException("Autorize o gestor na plataforma.");
                        // Apenas a pasta já validada pela navegação; nunca aceita comandos ou caminhos da mensagem.
                        if (_current.Length > 0)
                        {
                            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
                            await FileBrowserService.BrowseAsync(_current, false, timeout.Token);
                        }
                        var start = new System.Diagnostics.ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Windows), "explorer.exe")) { UseShellExecute = true };
                        start.Arguments = _current.Length == 0 ? "shell:MyComputerFolder" : "\"" + _current + "\"";
                        System.Diagnostics.Process.Start(start);
                        return;
                    }
                    if (action == "browse") await BrowseAsync(root.TryGetProperty("path", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() ?? "" : "");
                }
                catch (Exception error) { RuntimeLog.Write("Arquivos/mensagem: " + error); Post(new { type = "notice", error = error.Message }); }
            };
            web.NavigationCompleted += (_, e) => { if (!e.IsSuccess) DataLoad.Fail("Não foi possível abrir a interface local."); };
            _bridge = true; _web.Source = new Uri(Origin + "index.html");
        }
        catch (Exception error) { DataLoad.Fail("Não foi possível abrir esta área: " + error.Message); }
        finally { _loading = false; }
    }
    private async Task BrowseAsync(string path)
    {
        if (!_runtime.Allowed) { Post(new { type = "browse", error = "Autorize o gestor na plataforma." }); DataLoad.Complete(); return; }
        if (!await _gate.WaitAsync(0)) return;
        DataLoad.Begin("Consultando discos e pastas", true);
        using var operation = OperationProgress.Begin("Consultar arquivos");
        try
        {
            operation.Report(15, "Consultando a pasta selecionada");
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
            var result = await FileBrowserService.BrowseAsync(path, false, timeout.Token);
            var json = JsonSerializer.SerializeToElement(result);
            _current = json.GetProperty("path").GetString() ?? "";
            _files.Clear();
            foreach (var item in json.GetProperty("entries").EnumerateArray())
                if (!item.GetProperty("directory").GetBoolean()) _files.Add(item.GetProperty("name").GetString() ?? "");
            Post(new { type = "browse", path = _current, entries = json.GetProperty("entries"), truncated = json.GetProperty("truncated").GetBoolean() });
            operation.Succeed("Pasta consultada"); DataLoad.Complete();
        }
        catch (Exception ex) { Post(new { type = "browse", error = ex.Message }); operation.Fail(ex.Message); DataLoad.Fail(ex.Message); }
        finally { _gate.Release(); }
    }
}
