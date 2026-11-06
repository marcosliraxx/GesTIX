// Caminho: agentes/windows/Arcoll.WinUI3/Services/NativeCleanCommands.cs
using System.Text.Json;
using Microsoft.Win32;
using System.Security.Cryptography;
using System.Text;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Adaptador nativo para a API Clean. Cada operação é disparada exclusivamente pelo comando
/// autenticado da plataforma; varreduras não são executadas em segundo plano.
/// Categorias sem implementação segura falham explicitamente, nunca são apresentadas como limpas.
/// </summary>
internal static class NativeCleanCommands
{
    private sealed record Target(string Root, string Pattern = "*", bool Recursive = true);
    private sealed record CategoryResult(long Bytes, int Count, int Errors, bool Truncated = false);
    private static string Local => Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
    private static string Roaming => Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
    private static string Windows => Environment.GetFolderPath(Environment.SpecialFolder.Windows);
    private static readonly HashSet<string> AllowedAges = new() { "0", "1", "7", "30" };

    private static IReadOnlyList<Target> Targets(string key)
    {
        static Target T(string path, string pattern = "*", bool recursive = true) => new(path, pattern, recursive);
        return key switch
        {
            "user_temp" => [T(Path.GetTempPath())],
            "windows_temp" => [T(Path.Combine(Windows, "Temp"))],
            "recent_docs" => [T(Path.Combine(Roaming, @"Microsoft\Windows\Recent"), "*.lnk", false)],
            "jump_lists" => [T(Path.Combine(Roaming, @"Microsoft\Windows\Recent\AutomaticDestinations")), T(Path.Combine(Roaming, @"Microsoft\Windows\Recent\CustomDestinations"))],
            "thumbnails" => [T(Path.Combine(Local, @"Microsoft\Windows\Explorer"), "thumbcache_*", false), T(Path.Combine(Local, @"Microsoft\Windows\Explorer"), "iconcache_*", false)],
            "error_reports" => [T(Path.Combine(Local, "CrashDumps")), T(Path.Combine(Local, @"Microsoft\Windows\WER\ReportArchive")), T(Path.Combine(Local, @"Microsoft\Windows\WER\ReportQueue"))],
            "memory_dumps" => [T(Path.Combine(Windows, "Minidump")), T(Path.Combine(Windows, "LiveKernelReports"))],
            "teams_cache" => [T(Path.Combine(Roaming, @"Microsoft\Teams\Cache")), T(Path.Combine(Local, @"Microsoft\Teams\Cache"))],
            "discord_cache" => [T(Path.Combine(Roaming, @"discord\Cache")), T(Path.Combine(Roaming, @"discord\GPUCache"))],
            "vscode_cache" => [T(Path.Combine(Roaming, @"Code\Cache")), T(Path.Combine(Roaming, @"Code\CachedData"))],
            "spotify_cache" => [T(Path.Combine(Local, @"Spotify\Storage"))],
            "store_cache" => [T(Path.Combine(Local, @"Packages\Microsoft.WindowsStore_8wekyb3d8bbwe\LocalCache"))],
            "gpu_shader_cache" => [T(Path.Combine(Local, "D3DSCache"))],
            "prefetch" => [T(Path.Combine(Windows, "Prefetch"), "*.pf", false)],
            "office_recent" => [T(Path.Combine(Roaming, @"Microsoft\Office\Recent"), "*.lnk")],
            "firefox_cache" => FirefoxTargets("cache"),
            "firefox_cookies" => FirefoxTargets("cookies"),
            "firefox_history" => FirefoxTargets("history"),
            _ => BrowserTargets(key),
        };
    }

    private static IReadOnlyList<Target> BrowserTargets(string key)
    {
        var split = key.Split('_', 2);
        if (split.Length != 2 || !new[] { "cache", "history", "cookies" }.Contains(split[1])) return [];
        var root = split[0] switch
        {
            "chrome" => Path.Combine(Local, @"Google\Chrome\User Data"),
            "brave" => Path.Combine(Local, @"BraveSoftware\Brave-Browser\User Data"),
            "opera" => Path.Combine(Roaming, @"Opera Software\Opera Stable"),
            "vivaldi" => Path.Combine(Local, @"Vivaldi\User Data"),
            "edge" => Path.Combine(Local, @"Microsoft\Edge\User Data"),
            _ => "",
        };
        if (root == "") return [];
        // Navegador: jamais percorra o perfil inteiro para eliminar caches.
        var profiles = new List<string>();
        if (split[0] == "opera") profiles.Add(root);
        else
        {
            profiles.Add(Path.Combine(root, "Default"));
            try { profiles.AddRange(Directory.EnumerateDirectories(root, "Profile *")); }
            catch (IOException) { } catch (UnauthorizedAccessException) { }
        }
        return profiles.SelectMany(p => split[1] switch
        {
            "cache" => new[] { TCache(p, "Cache"), TCache(p, "Code Cache"), TCache(p, "GPUCache") },
            "history" => new[] { new Target(p, "History", false) },
            "cookies" => new[] { new Target(Path.Combine(p, "Network"), "Cookies", false) },
            _ => Array.Empty<Target>(),
        }).ToArray();
    }
    private static IReadOnlyList<Target> FirefoxTargets(string facet)
    {
        var root = Path.Combine(facet == "cache" ? Local : Roaming, @"Mozilla\Firefox\Profiles");
        string[] profiles;
        try { profiles = Directory.GetDirectories(root); }
        catch (IOException) { return []; } catch (UnauthorizedAccessException) { return []; }
        return profiles.SelectMany(p => facet switch
        {
            "cache" => new[] { new Target(Path.Combine(p, "cache2")), new Target(Path.Combine(p, "startupCache")) },
            "cookies" => new[] { new Target(p, "cookies.sqlite", false) },
            "history" => new[] { new Target(p, "places.sqlite", false) },
            _ => Array.Empty<Target>(),
        }).ToArray();
    }

    private static string Id(string source)
    {
        var hash = SHA256.HashData(Encoding.UTF8.GetBytes(source));
        return Convert.ToHexString(hash)[..16].ToLowerInvariant();
    }

    private static object StartupList()
    {
        var rows = new List<object>();
        foreach (var (hive, name) in new[] { (Registry.CurrentUser, "HKCU"), (Registry.LocalMachine, "HKLM") })
        {
            const string path = @"Software\Microsoft\Windows\CurrentVersion\Run";
            using var key = hive.OpenSubKey(path);
            if (key is null) continue;
            foreach (var entry in key.GetValueNames().Take(300))
            {
                var command = key.GetValue(entry)?.ToString() ?? "";
                rows.Add(new { id = Id(name + ":" + path + ":" + entry), kind = "run", name = entry,
                    detail = "Registro de inicialização", publisher = "", command = command.Length > 260 ? command[..260] : command,
                    source = name + "\\" + path, enabled = true });
            }
        }
        return new { tool = "startup", items = rows };
    }

    private static Target TCache(string profile, string suffix) => new(Path.Combine(profile, suffix));

    private static IEnumerable<FileInfo> Enumerate(Target target, CancellationToken ct)
    {
        if (!Directory.Exists(target.Root)) yield break;
        bool linked;
        try { linked = (new DirectoryInfo(target.Root).Attributes & FileAttributes.ReparsePoint) != 0; }
        catch (IOException) { linked = true; } catch (UnauthorizedAccessException) { linked = true; }
        if (linked) yield break;
        var pending = new Stack<DirectoryInfo>(); pending.Push(new DirectoryInfo(target.Root));
        while (pending.Count > 0)
        {
            ct.ThrowIfCancellationRequested();
            var dir = pending.Pop(); FileSystemInfo[] entries;
            try { entries = dir.GetFileSystemInfos(); }
            catch (IOException) { continue; } catch (UnauthorizedAccessException) { continue; }
            foreach (var entry in entries)
            {
                if ((entry.Attributes & FileAttributes.ReparsePoint) != 0) continue;
                if (entry is DirectoryInfo sub)
                {
                    if (target.Recursive) pending.Push(sub);
                    continue;
                }
                if (entry is FileInfo file && System.IO.Enumeration.FileSystemName.MatchesSimpleExpression(target.Pattern, file.Name, true)) yield return file;
            }
        }
    }
    private static bool Excluded(string path, IReadOnlyCollection<string> exclusions)
    {
        foreach (var pattern in exclusions)
        {
            if (pattern.Contains('*'))
            {
                if (System.IO.Enumeration.FileSystemName.MatchesSimpleExpression(pattern, path, true)) return true;
            }
            else if (path.Equals(pattern, StringComparison.OrdinalIgnoreCase) ||
                     path.StartsWith(pattern.TrimEnd('\\') + "\\", StringComparison.OrdinalIgnoreCase)) return true;
        }
        return false;
    }
    private static CategoryResult ProcessCategory(string key, int days, bool execute, IReadOnlyCollection<string> exclusions, CancellationToken ct)
    {
        var targets = Targets(key);
        if (targets.Count == 0) throw new NotSupportedException($"Categoria '{key}' ainda não possui implementação nativa segura. Nenhum item foi apagado.");
        var limit = DateTime.UtcNow.AddDays(-days); long bytes = 0; int count = 0, errors = 0;
        foreach (var target in targets)
        foreach (var file in Enumerate(target, ct))
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                if (file.LastWriteTimeUtc > limit || Excluded(file.FullName, exclusions)) continue;
                var length = file.Length;
                if (execute) file.Delete();
                bytes += length; count++;
            }
            catch (IOException) { errors++; }
            catch (UnauthorizedAccessException) { errors++; }
        }
        return new CategoryResult(bytes, count, errors);
    }
    private static Dictionary<string, object> Scan(IEnumerable<string> keys, int age, bool execute, CancellationToken ct, IReadOnlyCollection<string>? exclusions = null)
    {
        var results = new Dictionary<string, object>(); long bytes = 0; int files = 0, errors = 0;
        foreach (var key in keys)
        {
            var result = ProcessCategory(key, age, execute, exclusions ?? Array.Empty<string>(), ct);
            results[key] = new { file_count = result.Count, bytes = result.Bytes, errors = result.Errors, truncated = result.Truncated };
            bytes += result.Bytes; files += result.Count; errors += result.Errors;
        }
        return new Dictionary<string, object> { ["categories"] = results, ["age_days"] = age, ["file_count"] = files,
            ["bytes"] = bytes, ["errors"] = errors, ["execute"] = execute };
    }

    private static string[] Keys(JsonElement root, string key)
    {
        if (!root.TryGetProperty(key, out var value) || value.ValueKind != JsonValueKind.Array) throw new InvalidOperationException("Categorias não informadas.");
        var keys = value.EnumerateArray().Select(v => v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "").ToArray();
        if (keys.Length > 100 || keys.Any(string.IsNullOrWhiteSpace) || keys.Distinct(StringComparer.Ordinal).Count() != keys.Length)
            throw new InvalidOperationException("Categorias inválidas ou repetidas.");
        return keys;
    }
    private static int Age(JsonElement root)
    {
        var age = root.TryGetProperty("age_days", out var v) && v.TryGetInt32(out var n) ? n : 7;
        if (!AllowedAges.Contains(age.ToString())) throw new InvalidOperationException("Prazo de limpeza inválido.");
        return age;
    }
    private static object DiskReport(DriveInfo drive, CancellationToken ct)
    {
        long bytes = 0; int files = 0; bool truncated = false;
        var types = new Dictionary<string, (int Count, long Bytes)>(StringComparer.OrdinalIgnoreCase);
        var folders = new Dictionary<string, long>(StringComparer.OrdinalIgnoreCase);
        var largest = new List<(string Path, long Bytes)>();
        var stack = new Stack<DirectoryInfo>(); stack.Push(new DirectoryInfo(drive.RootDirectory.FullName));
        var deadline = DateTime.UtcNow.AddSeconds(45);
        while (stack.Count != 0)
        {
            ct.ThrowIfCancellationRequested();
            if (files >= 120000 || DateTime.UtcNow >= deadline) { truncated = true; break; }
            var dir = stack.Pop(); FileSystemInfo[] entries;
            try { entries = dir.GetFileSystemInfos(); }
            catch (IOException) { continue; } catch (UnauthorizedAccessException) { continue; }
            foreach (var entry in entries)
            {
                if ((entry.Attributes & FileAttributes.ReparsePoint) != 0) continue;
                if (entry is DirectoryInfo sub)
                {
                    if (!sub.Name.Equals("System Volume Information", StringComparison.OrdinalIgnoreCase) && !sub.Name.Equals("$Recycle.Bin", StringComparison.OrdinalIgnoreCase)) stack.Push(sub);
                    continue;
                }
                if (entry is not FileInfo file) continue;
                long size;
                try { size = file.Length; } catch (IOException) { continue; } catch (UnauthorizedAccessException) { continue; }
                bytes += size; files++;
                var type = file.Extension.TrimStart('.');
                if (type.Length == 0 || type.Length > 12) type = "Outros";
                var old = types.GetValueOrDefault(type);
                types[type] = (old.Count + 1, old.Bytes + size);
                var folder = Path.GetDirectoryName(file.FullName) ?? drive.RootDirectory.FullName;
                folders[folder] = folders.GetValueOrDefault(folder) + size;
                if (largest.Count < 50 || size > largest[^1].Bytes)
                {
                    largest.Add((file.FullName, size));
                    largest.Sort((a,b) => b.Bytes.CompareTo(a.Bytes));
                    if (largest.Count > 50) largest.RemoveAt(50);
                }
                if (files >= 120000) { truncated = true; break; }
            }
        }
        return new { tool = "disk", drive = drive.Name.TrimEnd('\\'), files, bytes, truncated,
            disk_total = drive.TotalSize, disk_free = drive.AvailableFreeSpace,
            types = types.OrderByDescending(t => t.Value.Bytes).Take(12).Select(t => new { name = t.Key, count = t.Value.Count, bytes = t.Value.Bytes }).ToArray(),
            folders = folders.OrderByDescending(t => t.Value).Take(80).Select(t => new { path = t.Key, bytes = t.Value }).ToArray(),
            largest = largest.Select(t => new { path = t.Path, bytes = t.Bytes }).ToArray() };
    }

    public static async Task<string> RunAsync(string name, JsonElement request, PlatformClient platform, long id, CancellationToken ct)
    {
        if (name is "clean_scan" or "clean_execute")
        {
            var keys = Keys(request, "categories"); var age = Age(request);
            if (keys.Length == 0) throw new InvalidOperationException("Selecione pelo menos uma categoria.");
            // Não se pode executar uma seleção parcialmente implementada: valide todas antes de alterar arquivos.
            foreach (var key in keys) if (Targets(key).Count == 0) throw new NotSupportedException($"Categoria {key} não implementada no agente nativo; nenhuma exclusão iniciada.");
            var exclusions = request.TryGetProperty("exclusions", out var ex) && ex.ValueKind == JsonValueKind.Array
                ? ex.EnumerateArray().Where(v => v.ValueKind == JsonValueKind.String).Select(v => v.GetString() ?? "").Where(v => v.Length > 0).ToArray()
                : Array.Empty<string>();
            var report = await Task.Run(() => Scan(keys, age, name == "clean_execute", ct, exclusions), ct);
            await platform.PostAsync("agent-clean-result", platform.WithCredentials(("command_id", id), ("report", report)), ct, 40);
            return $"{report["file_count"]} arquivo(s) {(name == "clean_execute" ? "removidos" : "analisados")}; {report["bytes"]} bytes; {report["errors"]} falha(s).";
        }
        if (name != "clean_tool") throw new NotSupportedException("Comando de limpeza não reconhecido.");
        var tool = request.TryGetProperty("tool", out var t) ? t.GetString() ?? "" : "";
        var action = request.TryGetProperty("action", out var a) ? a.GetString() ?? "" : "";
        object result;
        switch (tool, action)
        {
            case ("health", "scan"):
                var space = Keys(request, "space"); var privacy = Keys(request, "privacy");
                // Health Check cobre as categorias de cache implementadas, sem fingir que houve inspeção dos demais componentes.
                var supportedSpace = space.Where(k => Targets(k).Count != 0).ToArray();
                var supportedPrivacy = privacy.Where(k => Targets(k).Count != 0).ToArray();
                var unsupported = space.Concat(privacy).Where(k => Targets(k).Count == 0).Distinct().ToArray();
                // Relatório parcial sinalizado no backend e na interface; nunca apresente como auditoria completa.
                var spaceReport = await Task.Run(() => Scan(supportedSpace, 7, false, ct), ct);
                var privacyReport = await Task.Run(() => Scan(supportedPrivacy, 0, false, ct), ct);
                var startupCount = 0;
                using (var run = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Run")) startupCount = run?.ValueCount ?? 0;
                result = new { tool = "health", space = spaceReport, privacy = privacyReport,
                    speed = new { startup_total = startupCount, startup_enabled = startupCount }, registry = new { issues = 0 },
                    incomplete = true, missing = unsupported, note = "Verificação parcial: inspeção de registro e outras categorias indisponíveis no módulo nativo." };
                break;
            case ("startup", "list"):
                result = await Task.Run(StartupList, ct);
                break;
            case ("disk", "scan"):
                var drive = request.TryGetProperty("drive", out var d) ? d.GetString() ?? "C:" : "C:";
                if (!System.Text.RegularExpressions.Regex.IsMatch(drive, "^[A-Za-z]:$")) throw new InvalidOperationException("Unidade inválida.");
                var info = new DriveInfo(drive + "\\");
                if (!info.IsReady || info.DriveType != DriveType.Fixed) throw new InvalidOperationException("Unidade não disponível ou não é um disco fixo.");
                result = await Task.Run(() => DiskReport(info, ct), ct);
                break;
            default:
                throw new NotSupportedException($"Ação nativa não implementada: {tool}/{action}. Nenhuma alteração foi realizada.");
        }
        await platform.PostAsync("agent-clean-result", platform.WithCredentials(("command_id", id), ("report", result)), ct, 40);
        return tool == "health" ? "Health Check parcial salvo. Algumas categorias e a auditoria do registro ainda não foram verificadas; consulte o aviso na tela." : $"Ferramenta {tool}/{action} concluída.";
    }
}
