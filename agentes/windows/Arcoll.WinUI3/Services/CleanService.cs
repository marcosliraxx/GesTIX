// Caminho: agentes/windows/Arcoll.WinUI3/Services/CleanService.cs
using System.Runtime.InteropServices;

namespace Arcoll.WinUI3.Services;

public sealed record CleanCategory(string Key, string Title, string Path);
public sealed record CleanFinding(CleanCategory Category, long Bytes, int Files);
public sealed record CleanReport(IReadOnlyList<CleanFinding> Findings, long FreedBytes, int FailedFiles, bool Executed);

/// <summary>
/// Limpeza segura de arquivos temporários. Só toca pastas de lixo conhecidas, apenas arquivos mais antigos que o limite,
/// nunca segue links simbólicos/junções e nunca remove nada sem a confirmação explícita da interface.
/// </summary>
public static class CleanService
{
    [DllImport("shell32.dll", CharSet = CharSet.Unicode)] private static extern int SHEmptyRecycleBin(IntPtr hwnd, string? root, uint flags);

    public static IReadOnlyList<CleanCategory> Categories()
    {
        var local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        var windows = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
        return new[]
        {
            new CleanCategory("user_temp", "Temporários do usuário", Path.GetTempPath()),
            new CleanCategory("windows_temp", "Temporários do Windows", Path.Combine(windows, "Temp")),
            new CleanCategory("inet_cache", "Cache de internet do Windows", Path.Combine(local, @"Microsoft\Windows\INetCache")),
            new CleanCategory("crash_dumps", "Relatórios de falhas", Path.Combine(local, "CrashDumps")),
            new CleanCategory("thumb_cache", "Cache de miniaturas", Path.Combine(local, @"Microsoft\Windows\Explorer")),
        };
    }

    private static bool IsLink(FileSystemInfo info) => info.Attributes.HasFlag(FileAttributes.ReparsePoint);

    private static IEnumerable<FileInfo> Files(string root, DateTime olderThan, string key)
    {
        if (!Directory.Exists(root)) yield break;
        var pending = new Stack<DirectoryInfo>(); pending.Push(new DirectoryInfo(root));
        while (pending.Count > 0)
        {
            var dir = pending.Pop(); FileSystemInfo[] entries;
            try { entries = dir.GetFileSystemInfos(); } catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { continue; }
            foreach (var entry in entries)
            {
                if (IsLink(entry)) continue;
                if (entry is DirectoryInfo sub) { pending.Push(sub); continue; }
                if (entry is not FileInfo file) continue;
                // Miniaturas: apenas thumbcache_*.db; o restante da pasta do Explorer não é lixo.
                if (key == "thumb_cache" && !file.Name.StartsWith("thumbcache_", StringComparison.OrdinalIgnoreCase)) continue;
                if (file.LastWriteTimeUtc <= olderThan) yield return file;
            }
        }
    }

    public static Task<CleanReport> ScanAsync(int ageDays, CancellationToken ct) => Task.Run(() =>
    {
        var limit = DateTime.UtcNow.AddDays(-Math.Max(0, ageDays)); var findings = new List<CleanFinding>();
        foreach (var category in Categories())
        {
            long bytes = 0; var count = 0;
            foreach (var file in Files(category.Path, limit, category.Key))
            {
                ct.ThrowIfCancellationRequested();
                try { bytes += file.Length; count++; } catch (IOException) { }
            }
            findings.Add(new CleanFinding(category, bytes, count));
        }
        return new CleanReport(findings, 0, 0, false);
    }, ct);

    public static Task<CleanReport> ExecuteAsync(int ageDays, IReadOnlyCollection<string> keys, bool recycleBin, CancellationToken ct) => Task.Run(() =>
    {
        var limit = DateTime.UtcNow.AddDays(-Math.Max(0, ageDays)); var findings = new List<CleanFinding>(); long freed = 0; var failed = 0;
        foreach (var category in Categories().Where(c => keys.Contains(c.Key)))
        {
            long bytes = 0; var count = 0;
            foreach (var file in Files(category.Path, limit, category.Key).ToList())
            {
                ct.ThrowIfCancellationRequested();
                try { var size = file.Length; file.Delete(); bytes += size; count++; }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { failed++; } // arquivo em uso: preservado
            }
            freed += bytes; findings.Add(new CleanFinding(category, bytes, count));
        }
        if (recycleBin)
        {
            // 0x7 = sem confirmação, sem barra de progresso e sem som; o usuário já confirmou na interface.
            try { SHEmptyRecycleBin(IntPtr.Zero, null, 0x7); } catch (Exception ex) when (ex is DllNotFoundException or EntryPointNotFoundException) { }
        }
        return new CleanReport(findings, freed, failed, true);
    }, ct);

    public static string Size(long bytes) => bytes switch
    {
        >= 1073741824 => $"{bytes / 1073741824d:0.00} GB",
        >= 1048576 => $"{bytes / 1048576d:0.0} MB",
        >= 1024 => $"{bytes / 1024d:0} KB",
        _ => bytes + " B",
    };
}
