// Caminho: gestores/servidor/Arcoll.WinUI3/Services/FileBrowserService.cs
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Arcoll.WinUI3.Services;

/// <summary>
/// Navegação de arquivos autorizada pela plataforma. Somente enumeração: não copia, executa,
/// altera ou transmite conteúdo. Unidades montadas na sessão Windows, proteção contra pontos de junção
/// e recusa a diretórios do sistema/gestor e cofres de credenciais.
/// </summary>
public static class FileBrowserService
{
    private static readonly Regex DrivePath = new(@"^[A-Za-z]:[\\/]", RegexOptions.Compiled | RegexOptions.CultureInvariant);
    private static readonly string[] SecretNames = { "credentials", "protect", "vault", "crypto", ".ssh", ".gnupg", ".aws" };

    public static Task<Dictionary<string, object?>> BrowseAsync(string requested, bool foldersOnly, CancellationToken ct) =>
        Task.Run(() => Browse(requested, foldersOnly, ct), ct);

    private static Dictionary<string, object?> Browse(string requested, bool foldersOnly, CancellationToken ct)
    {
        var report = new Dictionary<string, object?> { ["path"] = "", ["entries"] = new List<Dictionary<string, object?>>(), ["truncated"] = false };
        var entries = (List<Dictionary<string, object?>>)report["entries"]!;
        var path = foldersOnly ? Environment.ExpandEnvironmentVariables(requested.Trim()) : requested.Trim();
        if (path.Length == 0)
        {
            foreach (var drive in DriveInfo.GetDrives())
            {
                ct.ThrowIfCancellationRequested();
                if (!AllowedDrive(drive.DriveType, foldersOnly)) continue;
                try
                {
                    var entry = new Dictionary<string, object?>
                    {
                        ["label"] = "", ["drive_type"] = drive.DriveType.ToString(),
                        ["name"] = drive.Name, ["path"] = drive.Name, ["directory"] = true,
                        ["drive"] = true, ["size"] = 0L, ["total"] = 0L, ["free"] = 0L, ["used"] = 0L,
                    };
                    // Unidades sem mídia ou sem capacidade reportada também aparecem no Explorer.
                    entries.Add(entry);
                    if (drive.IsReady)
                    {
                        entry["label"] = drive.VolumeLabel;
                        var total = drive.TotalSize; var free = drive.AvailableFreeSpace;
                        entry["size"] = total; entry["total"] = total; entry["free"] = free; entry["used"] = Math.Max(0L, total - free);
                    }
                }
                catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
            }
            return report;
        }
        var folder = ResolveDirectory(path, foldersOnly);
        report["path"] = folder.FullName;
        foreach (var child in folder.EnumerateFileSystemInfos())
        {
            ct.ThrowIfCancellationRequested();
            if (entries.Count >= 150) { report["truncated"] = true; break; }
            try
            {
                child.Refresh();
                if ((child.Attributes & FileAttributes.ReparsePoint) != 0) continue;
                var isDir = (child.Attributes & FileAttributes.Directory) != 0;
                if (foldersOnly && !isDir) continue;
                if (foldersOnly && (child.Attributes & FileAttributes.Hidden) != 0 && (child.Attributes & FileAttributes.System) != 0) continue;
                // O navegador não oferece caminhos com dados de autenticação do sistema/gestor.
                if (IsSecret(child.Name)) continue;
                var modified = new DateTimeOffset(child.LastWriteTimeUtc, TimeSpan.Zero).ToUnixTimeSeconds();
                var entry = new Dictionary<string, object?>
                {
                    ["name"] = child.Name, ["path"] = child.FullName, ["directory"] = isDir,
                    ["size"] = isDir ? 0 : ((FileInfo)child).Length, ["modified"] = Math.Max(0, modified),
                };
                if (isDir) { entry["folder_size"] = 0; entry["folder_size_complete"] = false; }
                entries.Add(entry);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or ArgumentException) { }
        }
        entries.Sort((a, b) =>
        {
            var firstDirectory = a["directory"] is true;
            var secondDirectory = b["directory"] is true;
            if (firstDirectory != secondDirectory) return firstDirectory ? -1 : 1;
            return StringComparer.OrdinalIgnoreCase.Compare((string?)a["name"], (string?)b["name"]);
        });
        // Evita ultrapassar o limite JSON do endpoint da plataforma, inclusive com caminhos longos.
        while (entries.Count > 0 && Encoding.UTF8.GetByteCount(JsonSerializer.Serialize(report)) > 70000)
        { entries.RemoveAt(entries.Count - 1); report["truncated"] = true; }
        return report;
    }

    public static string ResolveFile(string input)
    {
        if (input.Length > 1024 || input.Any(char.IsControl) || !DrivePath.IsMatch(input) || input.IndexOf(':',2)>=0) throw new ArgumentException("Caminho inválido.");
        var path=Path.GetFullPath(input);var parent=Path.GetDirectoryName(path)??throw new ArgumentException("Pasta inválida.");
        ResolveDirectory(parent,false);var file=new FileInfo(path);
        if(!file.Exists||IsSecret(file.Name)||(file.Attributes&FileAttributes.ReparsePoint)!=0)throw new UnauthorizedAccessException("Arquivo protegido, redirecionado ou indisponível.");
        return file.FullName;
    }

    private static DirectoryInfo ResolveDirectory(string input, bool foldersOnly)
    {
        if (input.Length > 1024 || input.Any(c => char.IsControl(c)) || !DrivePath.IsMatch(input) || input.IndexOf(':', 2) >= 0)
            throw new ArgumentException("Selecione um caminho absoluto em um disco local.");
        var full = Path.GetFullPath(input);
        var driveName = Path.GetPathRoot(full) ?? "";
        if (driveName.Length != 3) throw new ArgumentException("Disco local inválido.");
        var drive = new DriveInfo(driveName);
        if (!AllowedDrive(drive.DriveType, foldersOnly))
            throw new UnauthorizedAccessException("Selecione uma unidade montada e acessível nesta sessão Windows.");
        var folder = new DirectoryInfo(full);
        if (!folder.Exists) throw new DirectoryNotFoundException("A pasta não existe ou não está acessível.");
        var canonical = Path.TrimEndingDirectorySeparator(folder.FullName);
        foreach (var blocked in new[]
        {
            Environment.GetFolderPath(Environment.SpecialFolder.Windows),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Arcoll"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "Arcoll"),
        })
            if (StartsInside(canonical, blocked)) throw new UnauthorizedAccessException("Diretório protegido do sistema ou do gestor.");
        for (var parent = folder; parent is not null; parent = parent.Parent)
        {
            parent.Refresh();
            if ((parent.Attributes & FileAttributes.ReparsePoint) != 0 || IsSecret(parent.Name))
                throw new UnauthorizedAccessException("Caminhos redirecionados ou protegidos não podem ser navegados.");
        }
        return folder;
    }

    private static bool AllowedDrive(DriveType type, bool foldersOnly) =>
        (type is DriveType.Fixed or DriveType.Removable) || (!foldersOnly && (type is DriveType.Network or DriveType.CDRom or DriveType.Ram));

    private static bool StartsInside(string path, string root) => !string.IsNullOrWhiteSpace(root) &&
        (path.Equals(Path.TrimEndingDirectorySeparator(root), StringComparison.OrdinalIgnoreCase) ||
         path.StartsWith(Path.TrimEndingDirectorySeparator(root) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase));

    private static bool IsSecret(string name) => SecretNames.Contains(name, StringComparer.OrdinalIgnoreCase);
}
