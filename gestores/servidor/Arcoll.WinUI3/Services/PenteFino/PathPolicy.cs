// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PenteFino/PathPolicy.cs
using System.Diagnostics;
namespace Arcoll.WinUI3.Services.PenteFino;
public sealed record FileRule(string Id,string Module,string Root,int Days,string Browser="");
public sealed class PathPolicy
{
    public IReadOnlyList<FileRule> Rules{get;}
    private readonly string[] _excluded;
    public PathPolicy(IEnumerable<FileRule> rules,IEnumerable<string> excluded){Rules=rules.ToArray();_excluded=excluded.Where(x=>!string.IsNullOrWhiteSpace(x)).Select(Path.GetFullPath).ToArray();}
    public static bool Inside(string path,string root){var p=Path.GetFullPath(path);var r=Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar,Path.AltDirectorySeparatorChar);return p.StartsWith(r+Path.DirectorySeparatorChar,StringComparison.OrdinalIgnoreCase);}
    public static void RejectLinks(string path){var current=Path.GetFullPath(path);while(!string.IsNullOrEmpty(current)){if(File.Exists(current)||Directory.Exists(current))if((File.GetAttributes(current)&FileAttributes.ReparsePoint)!=0)throw new IOException("Atalho de sistema de arquivos/junção não permitido: "+current);var parent=Path.GetDirectoryName(current);if(parent==current)break;current=parent??"";}}
    public bool Allows(string path,string rule){var found=Rules.FirstOrDefault(x=>x.Id==rule);if(found is null||!Inside(path,found.Root))return false;var full=Path.GetFullPath(path);if(_excluded.Any(x=>full.Equals(x,StringComparison.OrdinalIgnoreCase)||Inside(full,x)))return false;RejectLinks(full);return true;}
    public static bool BrowserOpen(string browser){if(browser.Length==0)return false;var processes=Process.GetProcessesByName(browser);foreach(var process in processes)process.Dispose();return processes.Length>0;}
    public static List<FileRule> WindowsRules(string mode){var local=Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);var roaming=Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);var windows=Environment.GetFolderPath(Environment.SpecialFolder.Windows);var age=mode=="detailed"?1:7;
        var rules=new List<FileRule>{new("user-temp","clean",Path.GetTempPath(),age),new("windows-temp","clean",Path.Combine(windows,"Temp"),age),new("recent","privacy",Path.Combine(roaming,"Microsoft","Windows","Recent"),1)};
        foreach(var (vendor,browser)in new[]{("Google\\Chrome","chrome"),("Microsoft\\Edge","msedge")}){var profiles=Path.Combine(local,vendor,"User Data");if(!Directory.Exists(profiles))continue;RejectLinks(profiles);foreach(var profile in Directory.EnumerateDirectories(profiles).Where(x=>Path.GetFileName(x)=="Default"||Path.GetFileName(x).StartsWith("Profile ",StringComparison.Ordinal)).Take(30)){RejectLinks(profile);var id=ResultsStore.Hash(profile)[..16];rules.Add(new(id+"-cache","clean",Path.Combine(profile,"Cache","Cache_Data"),age,browser));rules.Add(new(id+"-network","privacy",Path.Combine(profile,"Network"),0,browser));rules.Add(new(id+"-profile","privacy",profile,0,browser));}}
        var firefox=Path.Combine(roaming,"Mozilla","Firefox","Profiles");if(Directory.Exists(firefox)){RejectLinks(firefox);foreach(var profile in Directory.EnumerateDirectories(firefox).Take(30)){RejectLinks(profile);rules.Add(new(ResultsStore.Hash(profile)[..16]+"-firefox","privacy",profile,0,"firefox"));}}
        return rules;
    }
    public static IEnumerable<string> Walk(string root,CancellationToken ct,Action<string>? skipped=null){if(!Directory.Exists(root))yield break;var stack=new Stack<string>();stack.Push(root);while(stack.Count>0){ct.ThrowIfCancellationRequested();var path=stack.Pop();string[] files,folders;try{RejectLinks(path);files=Directory.GetFiles(path);folders=Directory.GetDirectories(path);}catch(Exception e)when(e is IOException or UnauthorizedAccessException){skipped?.Invoke(path);continue;}foreach(var file in files){ct.ThrowIfCancellationRequested();if((File.GetAttributes(file)&FileAttributes.ReparsePoint)==0)yield return file;}foreach(var folder in folders)stack.Push(folder);}}
    public bool TrustedCookie(string host,IEnumerable<string> domains){var normalized=host.TrimStart('.').ToLowerInvariant();return domains.Any(domain=>{var d=domain.Trim().TrimStart('.').ToLowerInvariant();return normalized==d||normalized.EndsWith("."+d,StringComparison.Ordinal);});}
}
