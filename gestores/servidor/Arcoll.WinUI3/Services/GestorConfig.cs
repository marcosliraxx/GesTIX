// Caminho: gestores/servidor/Arcoll.WinUI3/Services/GestorConfig.cs
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Fonte única da configuração do gestor (equivalente a CFG/BUNDLED_CFG do gestor Python).
/// %APPDATA%\Arcoll\gestor.json tem prioridade; gestor-config.json (pacote do instalador) preenche o que faltar.
/// </summary>
public sealed record GestorConfig(string Origin, string SiteCode, string DownloadKey, string Fingerprint, string SystemName)
{
    private static readonly Regex CodeRx = new("^[A-Z0-9]{5}$", RegexOptions.Compiled);
    private static readonly Regex KeyRx = new("^[a-fA-F0-9]{64}$", RegexOptions.Compiled);

    public bool IsComplete => Origin.Length > 0 && CodeRx.IsMatch(SiteCode) && KeyRx.IsMatch(DownloadKey);

    /// <summary>Corpo padrão de autenticação enviado a todas as ações do gestor.</summary>
    public Dictionary<string, object?> Credentials() => new()
    {
        ["fingerprint"] = Fingerprint, ["site_code"] = SiteCode, ["download_key"] = DownloadKey,
    };

    public static GestorConfig Load()
    {
        var values = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        string[] files =
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "Arcoll", "gestor.json"),
            Path.Combine(AppContext.BaseDirectory, "gestor-config.json"),
            Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "..", "gestor-config.json")),
        };
        foreach (var file in files)
        {
            try
            {
                if (!File.Exists(file)) continue;
                using var doc = JsonDocument.Parse(File.ReadAllText(file), new JsonDocumentOptions { CommentHandling = JsonCommentHandling.Skip });
                if (doc.RootElement.ValueKind != JsonValueKind.Object) continue;
                foreach (var key in new[] { "origin", "site_code", "download_key", "fingerprint", "system_name" })
                    if (!values.ContainsKey(key) && doc.RootElement.TryGetProperty(key, out var v) && v.ValueKind == JsonValueKind.String
                        && !string.IsNullOrWhiteSpace(v.GetString()))
                        values[key] = v.GetString()!.Trim();
            }
            catch (Exception ex) when (ex is JsonException or IOException or UnauthorizedAccessException) { /* arquivo ausente ou truncado */ }
        }
        string Get(string k) => values.TryGetValue(k, out var v) ? v : "";
        var origin = Get("origin").TrimEnd('/');
        if (!Uri.TryCreate(origin, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps || uri.UserInfo.Length > 0) origin = "";
        var fingerprint = Get("fingerprint").ToLowerInvariant();
        if (!Regex.IsMatch(fingerprint, "^[a-f0-9]{64}$")) fingerprint = PresenceService.Fingerprint();
        var name = Get("system_name");
        return new GestorConfig(origin, Get("site_code").ToUpperInvariant(), Get("download_key"), fingerprint, name.Length > 0 ? name : "T.I Digital");
    }
}
