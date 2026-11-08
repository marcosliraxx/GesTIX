// Caminho: gestores/servidor/Arcoll.WinUI3/Services/CloudVirtualDriveService.cs
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.RegularExpressions;
using Microsoft.Win32;
using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Xml;

namespace Arcoll.WinUI3.Services;

/// <summary>WebDAV local somente leitura, ligado ao backup autorizado na plataforma.
/// Executa na sessão Windows do usuário, pois unidades mapeadas são específicas da sessão.</summary>
public sealed class CloudVirtualDriveService : IDisposable
{
    private readonly GestorConfig _cfg;
    private readonly HttpClient _cloud = new(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { Timeout = TimeSpan.FromMinutes(3) };
    private readonly CancellationTokenSource _stop = new();
    private HttpListener? _server;
    private Task? _worker;
    private int _port;
    // Enumeracao pela API do redirector: net use e sensivel ao idioma e pode truncar UNC.
    [DllImport("mpr.dll", CharSet = CharSet.Unicode, EntryPoint = "WNetGetConnectionW")]
    private static extern int WNetGetConnection(string localName, StringBuilder remoteName, ref int length);

    private static readonly Regex OurLegacyWebDav = new(
        @"^\\\\127\.0\.0\.1@(?<port>\d{5})\\DavWWWRoot\\(?<key>[0-9a-f]{40}|[0-9a-f]{48})\\",
        RegexOptions.IgnoreCase | RegexOptions.CultureInvariant | RegexOptions.Compiled);
    private string _letter = "";
    private string _rootLabel = "Arcoll Nuvem";
    private readonly string _secret = Convert.ToHexString(RandomNumberGenerator.GetBytes(24)).ToLowerInvariant();
    private readonly SemaphoreSlim _requestGate = new(6);
    private readonly Dictionary<string, (DateTimeOffset Time, List<Entry> Items)> _cache = new(StringComparer.Ordinal);
    private readonly object _cacheLock = new();
    private static readonly SemaphoreSlim MappingGate = new(1, 1);
    private sealed record Entry(string Name, string Display, bool Directory, long Size, DateTimeOffset Modified);

    public CloudVirtualDriveService(GestorConfig cfg)
    {
        _cfg = cfg;
        _rootLabel = NormalizeRootLabel(cfg.SystemName);
        PlatformClient.ApplyDefaults(_cloud);
        _cloud.DefaultRequestHeaders.Add("X-Arcoll-Fingerprint", cfg.Fingerprint);
        _cloud.DefaultRequestHeaders.Add("X-Arcoll-Site-Code", cfg.SiteCode);
        _cloud.DefaultRequestHeaders.Add("X-Arcoll-Key", cfg.DownloadKey);
    }

    public void Start() => _worker ??= Task.Run(() => LoopAsync(_stop.Token));

    private async Task<JsonElement> JsonAsync(string action, object body, CancellationToken ct)
    {
        using var reply = await _cloud.PostAsync(_cfg.Origin + "/api/cloud.php?action=" + action, PlatformClient.JsonBody(body), ct);
        var payload = await reply.Content.ReadAsStringAsync(ct);
        using var doc = JsonDocument.Parse(payload);
        if (!reply.IsSuccessStatusCode || !doc.RootElement.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True)
            throw new InvalidOperationException(doc.RootElement.TryGetProperty("error", out var error) ? error.ToString() : $"Nuvem HTTP {(int)reply.StatusCode}");
        return doc.RootElement.Clone();
    }

    private async Task LoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                // Eliminar somente mapeamentos WebDAV identificados como pertencentes
                // aos gestores T.I Digital anteriores. Nunca tocar em unidades de terceiros.
                await ReconcileMappedDrivesAsync(ct);
                var state = await JsonAsync("gestor-state", new { }, ct);
                var vdrive = state.GetProperty("vdrive");
                var enabled = vdrive.GetProperty("available").GetBoolean();
                var desired = (vdrive.GetProperty("letter").GetString() ?? "AUTO").Trim().ToUpperInvariant();
                if (desired.Length == 0) desired = "AUTO";
                var label = vdrive.TryGetProperty("label", out var labelNode) ? NormalizeRootLabel(labelNode.GetString()) : NormalizeRootLabel(_cfg.SystemName);
                var automatic = desired == "AUTO";
                if (!enabled || (!automatic && (desired.Length != 1 || desired[0] < 'D' || desired[0] > 'Z')))
                {
                    if (_letter.Length > 0) await UnmountAsync(ct);
                }
                else
                {
                    if ((_letter.Length > 0 && !automatic && _letter != desired) || !string.Equals(_rootLabel, label, StringComparison.Ordinal)) await UnmountAsync(ct);
                    _rootLabel = label;
                    if (_server == null) StartServer();
                    if (_letter.Length == 0) await MountAsync(desired, ct);
                    else if (!await IsOurMappingAsync(_letter, ct)) _letter = "";
                }
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { break; }
            catch (Exception ex) { RuntimeLog.Write("Disco virtual: " + ex.GetType().Name + ": " + ex.Message); }
            try { await Task.Delay(TimeSpan.FromSeconds(15), ct); } catch (OperationCanceledException) { break; }
        }
    }

    private void StartServer()
    {
        for (var p = 47831; p <= 47860; p++)
        {
            var listener = new HttpListener();
            listener.Prefixes.Add($"http://127.0.0.1:{p}/");
            try { listener.Start(); _server = listener; _port = p; _ = Task.Run(() => ServeAsync(listener, _stop.Token)); return; }
            catch (HttpListenerException) { listener.Close(); }
        }
        throw new InvalidOperationException("Nenhuma porta local WebDAV disponível. Verifique a reserva HTTP do Windows.");
    }

    private static async Task<(int Code, string Output)> CommandAsync(string filename, CancellationToken ct, params string[] arguments)
    {
        var info = new ProcessStartInfo(filename) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
        foreach (var arg in arguments) info.ArgumentList.Add(arg);
        using var process = Process.Start(info) ?? throw new InvalidOperationException("Não foi possível iniciar " + filename);
        var output = process.StandardOutput.ReadToEndAsync(ct);
        var errors = process.StandardError.ReadToEndAsync(ct);
        await process.WaitForExitAsync(ct);
        return (process.ExitCode, (await output) + " " + (await errors));
    }

    private static string NormalizeRootLabel(string? value)
    {
        var text = (value ?? "").Trim();
        if (text.Length == 0) return "Arcoll Nuvem";
        var invalid = new HashSet<char>(Path.GetInvalidFileNameChars());
        foreach (var extra in new[] { '\\', '/', ':', '*', '?', '"', '<', '>', '|' }) invalid.Add(extra);
        var clean = new string(text.Select(c => invalid.Contains(c) || char.IsControl(c) ? ' ' : c).ToArray());
        clean = System.Text.RegularExpressions.Regex.Replace(clean, @"\s+", " ").Trim();
        if (clean.Length == 0) clean = "Arcoll Nuvem";
        return clean.Length > 60 ? clean[..60].Trim() : clean;
    }

    private static string? RemoteFor(char drive)
    {
        var n = 4096;
        var value = new StringBuilder(n);
        var code = WNetGetConnection(drive + ":", value, ref n);
        if (code == 234 && n is > 0 and < 32768)
        {
            value = new StringBuilder(n);
            code = WNetGetConnection(drive + ":", value, ref n);
        }
        return code == 0 ? value.ToString() : null;
    }

    private static bool IsGestorWebDav(string? remote)
    {
        if (string.IsNullOrWhiteSpace(remote)) return false;
        var match = OurLegacyWebDav.Match(remote);
        return match.Success && int.TryParse(match.Groups["port"].Value, out var port)
            && port >= 47831 && port <= 47860;
    }

    private bool IsOurMapping(string letter)
    {
        if (letter.Length != 1 || _port == 0) return false;
        var remote = RemoteFor(char.ToUpperInvariant(letter[0]));
        return IsGestorWebDav(remote) && remote!.Contains("127.0.0.1@" + _port + "\\DavWWWRoot\\" + _secret + "\\", StringComparison.OrdinalIgnoreCase);
    }

    private Task<bool> IsOurMappingAsync(string letter, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        return Task.FromResult(IsOurMapping(letter));
    }

    /// <summary>
    /// Retem a unidade desta instancia e remove unidades WebDAV antigas geradas
    /// pelas versoes Python/WinUI (portas locais 47831-47860 + segredo do gestor).
    /// Nao interfere em unidades de rede comuns, inclusive Google Drive.
    /// </summary>
    private async Task ReconcileMappedDrivesAsync(CancellationToken ct)
    {
        await MappingGate.WaitAsync(ct);
        try
        {
            for (var letter = 'D'; letter <= 'Z'; letter++)
            {
                ct.ThrowIfCancellationRequested();
                var remote = RemoteFor(letter);
                if (!IsGestorWebDav(remote)) continue;
                if (_letter.Equals(letter.ToString(), StringComparison.OrdinalIgnoreCase) && IsOurMapping(_letter)) continue;
                var result = await CommandAsync("net.exe", ct, "use", letter + ":", "/delete", "/y");
                if (result.Code == 0) RuntimeLog.Write("Mapeamento T.I Digital antigo removido: " + letter + ":");
                else RuntimeLog.Write("Nao foi possivel remover unidade antiga " + letter + ": " + result.Output.Trim());
            }
        }
        finally { MappingGate.Release(); }
    }

    private void SetFriendlyLabel(string letter)
    {
        // O Explorer pode continuar exibindo o caminho UNC sob o rotulo em WebDAV.
        // Para ocultar integralmente o endereco, a unidade deve ser nativa (WinFsp).
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(@"Network\" + letter);
            key?.SetValue("_LabelFromReg", _rootLabel, RegistryValueKind.String);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException)
        { RuntimeLog.Write("Rotulo da unidade: " + ex.Message); }
    }

    private const string AutoLetters = "ZYXWVUTSRQPONMLKJIHGFED";

    /// <summary>Letras já atribuídas no Windows, inclusive mapeamentos de rede desconectados.</summary>
    private static HashSet<char> UsedLetters() =>
        new(Environment.GetLogicalDrives().Where(d => d.Length > 0).Select(d => char.ToUpperInvariant(d[0]))
            .Concat(Enumerable.Range('D', 'Z' - 'D' + 1).Select(n => (char)n).Where(c => RemoteFor(c) != null)));

    private async Task MountAsync(string desired, CancellationToken ct)
    {
        var automatic = desired == "AUTO";
        await ReconcileMappedDrivesAsync(ct);
        if (!automatic && await IsOurMappingAsync(desired, ct)) { _letter = desired; SetFriendlyLabel(desired); return; }
        var used = UsedLetters();
        // Letra fixa ocupada por outro programa: erro claro. Letra automática: primeira livre.
        if (!automatic && used.Contains(desired[0])) throw new InvalidOperationException($"A unidade {desired}: já está em uso; escolha uma letra livre ou a opção automática.");
        var candidates = automatic ? AutoLetters.Where(c => !used.Contains(c)).Take(4).Select(c => c.ToString()).ToList() : new List<string> { desired };
        if (candidates.Count == 0) throw new InvalidOperationException("Sem letras de unidade livres para o disco virtual.");
        var service = await CommandAsync("sc.exe", ct, "query", "WebClient");
        if (!service.Output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase))
        {
            await CommandAsync("sc.exe", ct, "config", "WebClient", "start=", "auto"); // só tem efeito como administrador
            var start = await CommandAsync("sc.exe", ct, "start", "WebClient");
            if (start.Code != 0 && !start.Output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Serviço WebClient desativado. Habilite Cliente Web no Windows para montar a unidade.");
        }
        var unc = $@"\\127.0.0.1@{_port}\DavWWWRoot\{_secret}\{_rootLabel}";
        var failure = "";
        foreach (var letter in candidates)
        {
            var mapped = await CommandAsync("net.exe", ct, "use", letter + ":", unc, "/persistent:no");
            if (mapped.Code != 0) { failure = $"Falha ao montar {letter}: {mapped.Output.Trim()}"; continue; }
            if (!await IsOurMappingAsync(letter, ct)) { failure = "O mapeamento WebDAV não apareceu na sessão atual do Windows."; continue; }
            _letter = letter;
            SetFriendlyLabel(letter);
            RuntimeLog.Write("Disco virtual da nuvem montado em " + letter + ": (somente leitura)");
            return;
        }
        throw new InvalidOperationException(failure);
    }

    private async Task UnmountAsync(CancellationToken ct)
    {
        var mounted = _letter; _letter = "";
        if (mounted.Length > 0 && await IsOurMappingAsync(mounted, ct))
            await CommandAsync("net.exe", ct, "use", mounted + ":", "/delete", "/y");
    }

    private async Task ServeAsync(HttpListener server, CancellationToken ct)
    {
        while (server.IsListening && !ct.IsCancellationRequested)
        {
            HttpListenerContext context;
            try { context = await server.GetContextAsync().WaitAsync(ct); }
            catch (Exception ex) when (ex is OperationCanceledException or HttpListenerException or ObjectDisposedException) { break; }
            _ = Task.Run(async () =>
            {
                try { await _requestGate.WaitAsync(ct); } catch (OperationCanceledException) { return; }
                try { await RespondAsync(context, ct); }
                catch (Exception ex) { RuntimeLog.Write("WebDAV: " + ex.Message); try { context.Response.StatusCode = 502; } catch { } }
                finally { try { context.Response.Close(); } catch { } _requestGate.Release(); }
            }, ct);
        }
    }

    private static string SafeName(string value)
    {
        var invalid = Path.GetInvalidFileNameChars();
        var s = new string(value.Select(c => invalid.Contains(c) || c < 32 ? '_' : c).ToArray()).Trim().TrimEnd('.', ' ');
        return s.Length > 120 ? s[..120] : s.Length == 0 ? "_" : s;
    }

    private async Task<List<Entry>> ChildrenAsync(string path, CancellationToken ct)
    {
        lock (_cacheLock) if (_cache.TryGetValue(path, out var cached) && DateTimeOffset.UtcNow - cached.Time < TimeSpan.FromSeconds(15)) return cached.Items;
        var result = new List<Entry>(); string? page = null;
        for (var n = 0; n < 10; n++)
        {
            var payload = await JsonAsync("gestor-list", new { path, page }, ct);
            var browser = payload.GetProperty("browser");
            foreach (var item in browser.GetProperty("entries").EnumerateArray())
            {
                var name = item.GetProperty("name").GetString() ?? "";
                if (name.Length == 0 || name is "." or "..") continue;
                var display = SafeName(item.TryGetProperty("display", out var label) ? label.GetString() ?? name : name);
                if (result.Any(e => e.Display.Equals(display, StringComparison.OrdinalIgnoreCase))) continue;
                result.Add(new Entry(name, display, item.GetProperty("directory").GetBoolean(), item.TryGetProperty("size", out var size) && size.TryGetInt64(out var len) ? len : 0,
                    item.TryGetProperty("modified", out var date) && DateTimeOffset.TryParse(date.ToString(), out var at) ? at : DateTimeOffset.UtcNow));
            }
            page = browser.TryGetProperty("next", out var next) ? next.GetString() : null;
            if (string.IsNullOrEmpty(page)) break;
        }
        lock (_cacheLock) { if (_cache.Count > 200) _cache.Clear(); _cache[path] = (DateTimeOffset.UtcNow, result); }
        return result;
    }

    private async Task<(Entry? Entry, string? RawPath)> ResolveAsync(string[] parts, CancellationToken ct)
    {
        string path = ""; Entry? selected = null;
        foreach (var part in parts)
        {
            selected = (await ChildrenAsync(path, ct)).FirstOrDefault(x => x.Display.Equals(part, StringComparison.OrdinalIgnoreCase));
            if (selected == null) return (null, null);
            path = path.Length == 0 ? selected.Name : path + "/" + selected.Name;
        }
        return (selected, path);
    }

    private static string Esc(string v) => System.Security.SecurityElement.Escape(v) ?? "";
    private static string Href(IEnumerable<string> parts) => "/" + string.Join("/", parts.Select(Uri.EscapeDataString));
    private string ItemXml(string[] parts, Entry? entry)
    {
        var directory = entry == null || entry.Directory;
        var display = parts.Length == 2 ? _rootLabel : parts[^1];
        var href = Href(parts) + (directory ? "/" : "");
        var modified = (entry?.Modified ?? DateTimeOffset.UtcNow).UtcDateTime.ToString("R", System.Globalization.CultureInfo.InvariantCulture);
        var xml = $"<D:response><D:href>{Esc(href)}</D:href><D:propstat><D:prop><D:displayname>{Esc(display)}</D:displayname><D:resourcetype>{(directory ? "<D:collection/>" : "")}</D:resourcetype><D:getlastmodified>{modified}</D:getlastmodified>";
        if (!directory) xml += $"<D:getcontentlength>{entry!.Size}</D:getcontentlength><D:getcontenttype>application/octet-stream</D:getcontenttype>";
        return xml + "</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>";
    }

    private async Task RespondAsync(HttpListenerContext context, CancellationToken ct)
    {
        var req = context.Request; var reply = context.Response;
        reply.Headers["DAV"] = "1"; reply.Headers["MS-Author-Via"] = "DAV";
        var host = req.Url?.Host ?? "";
        if (host != "127.0.0.1" && !host.Equals("localhost", StringComparison.OrdinalIgnoreCase)) { reply.StatusCode = 403; return; }
        if (req.HttpMethod == "OPTIONS") { reply.Headers["Allow"] = "OPTIONS, PROPFIND, HEAD, GET"; return; }
        if (req.HttpMethod is not ("PROPFIND" or "HEAD" or "GET")) { reply.StatusCode = 403; return; }
        var parts = (req.Url?.AbsolutePath ?? "").Split('/', StringSplitOptions.RemoveEmptyEntries).Select(Uri.UnescapeDataString).ToArray();
        if (parts.Length < 2 || parts[0] != _secret || !string.Equals(parts[1], _rootLabel, StringComparison.Ordinal)) { reply.StatusCode = 404; return; }
        var displayParts = parts.Skip(2).ToArray();
        var (entry, raw) = await ResolveAsync(displayParts, ct);
        if (raw == null) { reply.StatusCode = 404; return; }
        if (req.HttpMethod == "PROPFIND")
        {
            var output = new StringBuilder("<?xml version=\"1.0\" encoding=\"utf-8\"?><D:multistatus xmlns:D=\"DAV:\">");
            output.Append(ItemXml(parts, entry));
            if (req.Headers["Depth"] != "0" && (entry == null || entry.Directory))
            {
                foreach (var child in await ChildrenAsync(raw, ct))
                    output.Append(ItemXml(parts.Append(child.Display).ToArray(), child));
            }
            output.Append("</D:multistatus>");
            var bytes = Encoding.UTF8.GetBytes(output.ToString());
            reply.StatusCode = 207; reply.ContentType = "text/xml; charset=utf-8"; reply.ContentLength64 = bytes.Length;
            await reply.OutputStream.WriteAsync(bytes, ct);
            return;
        }
        if (entry == null || entry.Directory) { reply.StatusCode = req.HttpMethod == "HEAD" ? 200 : 403; return; }
        reply.Headers["Accept-Ranges"] = "bytes";
        if (req.HttpMethod == "HEAD") { reply.ContentLength64 = entry.Size; return; }
        var remote = new Dictionary<string, string> { ["path"] = raw };
        if (req.Headers["Range"] is string range && System.Text.RegularExpressions.Regex.IsMatch(range, @"^bytes=\d*-\d*$")) remote["range"] = range;
        using var request = new HttpRequestMessage(HttpMethod.Post, _cfg.Origin + "/api/cloud.php?action=gestor-download") { Content = PlatformClient.JsonBody(remote) };
        using var upstream = await _cloud.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        if (!upstream.IsSuccessStatusCode) { reply.StatusCode = 502; return; }
        reply.StatusCode = upstream.StatusCode == HttpStatusCode.PartialContent ? 206 : 200;
        if (upstream.Content.Headers.ContentLength is long length) reply.ContentLength64 = length;
        if (upstream.Content.Headers.ContentRange != null) reply.Headers["Content-Range"] = upstream.Content.Headers.ContentRange.ToString();
        reply.ContentType = "application/octet-stream";
        await using var stream = await upstream.Content.ReadAsStreamAsync(ct);
        await stream.CopyToAsync(reply.OutputStream, ct);
    }

    public void Dispose()
    {
        _stop.Cancel(); _server?.Close();
        if (_letter.Length > 0) { try { UnmountAsync(CancellationToken.None).GetAwaiter().GetResult(); } catch { } }
        _cloud.Dispose(); _requestGate.Dispose(); _stop.Dispose();
    }
}
