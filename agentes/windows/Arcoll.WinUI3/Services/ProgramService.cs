// Caminho: agentes/windows/Arcoll.WinUI3/Services/ProgramService.cs
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Arcoll.WinUI3.Services;

public sealed record InstalledApplication(string Name, string Version, string Publisher, string InstalledOn, string Id = "", string Uninstall = "", string QuietUninstall = "");
public sealed record CatalogApplication(string Name,string Id,string Category);

/// <summary>Um executor para ações locais confirmadas. Catálogo publicado pela própria plataforma.</summary>
public static class ProgramService
{
    public static IReadOnlyList<CatalogApplication> Catalog()
    {
        var path=Path.Combine(AppContext.BaseDirectory,"Assets","program-catalog.json");
        if(!File.Exists(path)) return Array.Empty<CatalogApplication>();
        if(new FileInfo(path).Length>262144) throw new InvalidDataException("Catálogo excede o limite.");
        var rows=JsonSerializer.Deserialize<List<CatalogApplication>>(File.ReadAllText(path),new JsonSerializerOptions{PropertyNameCaseInsensitive=true})??new();
        if(rows.Count>500 || rows.Any(x=>string.IsNullOrWhiteSpace(x.Name)||string.IsNullOrWhiteSpace(x.Id)||!Regex.IsMatch(x.Id,@"^[A-Za-z0-9][A-Za-z0-9.+_-]{1,150}$"))) throw new InvalidDataException("Catálogo inválido.");
        return rows.DistinctBy(x=>x.Id,StringComparer.OrdinalIgnoreCase).ToArray();
    }
    /// <summary>Nome do PNG do logo: o ID do WinGet com tudo fora de [A-Za-z0-9.-] trocado por "_" (mesma regra da plataforma).</summary>
    public static string LogoFileName(string id)=>Regex.Replace(id,@"[^A-Za-z0-9.-]","_")+".png";
    /// <summary>Caminho do logo embutido em Assets\program-logos ou vazio se o arquivo não existir.</summary>
    public static string LogoPath(string id)
    {
        try{var path=Path.Combine(AppContext.BaseDirectory,"Assets","program-logos",LogoFileName(id));return File.Exists(path)?path:"";}
        catch{return "";}
    }
    /// <summary>Iniciais por palavra ou CamelCase (WinRAR => WR), iguais às da plataforma, para quando não houver logo.</summary>
    public static string Initials(string name)
    {
        var words=Regex.Replace(name??"",@"([a-z0-9])([A-Z])","$1 $2").Split(new[]{' ','.','-','_','+','/','(',')'},StringSplitOptions.RemoveEmptyEntries)
            .Select(w=>Regex.Replace(w,"[^A-Za-z0-9]","")).Where(w=>w.Length>0).ToArray();
        if(words.Length==0)return "?";
        return (words.Length>1?words[0][..1]+words[1][..1]:words[0][..Math.Min(2,words[0].Length)]).ToUpperInvariant();
    }
    public static string LogoColor(string id)
    {
        string[] palette={"#1f6feb","#2ea043","#bf8700","#8957e5","#d1242f","#0969da","#8250df","#1a7f37","#0a7ea4","#bc4c00"};
        return palette[SHA256.HashData(Encoding.UTF8.GetBytes(id))[0]%palette.Length];
    }
    public static List<Dictionary<string,object?>> InventoryPayload(IReadOnlyList<InstalledApplication> programs)=>programs.Take(1500).Select(p=>new Dictionary<string,object?>
    {
        ["id"]=Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(p.Id))).ToLowerInvariant(),
        ["name"]=p.Name,["version"]=p.Version,["publisher"]=p.Publisher,["date"]=p.InstalledOn,["size"]=0,
        ["method"]=p.QuietUninstall.Length>0?"silent":p.Uninstall.Length>0?"interactive":""
    }).ToList();
    public static string Normalize(string value)=>Regex.Replace(value.ToLowerInvariant(),@"[^\p{L}\p{N}]","");
    public static InstalledApplication? Match(CatalogApplication app,IReadOnlyList<InstalledApplication> installed)
    {
        var name=Normalize(app.Name);
        // Nunca confunde runtimes diferentes ou edições com o mesmo prefixo.
        return installed.FirstOrDefault(x=>Normalize(x.Name)==name);
    }
    public static (string File,string Args) Split(string command)
    {
        command=Environment.ExpandEnvironmentVariables(command.Trim());
        if(command.Length==0)return("","");
        if(command[0]=='"')
        {
            var end=command.IndexOf('"',1);
            if(end<2)throw new InvalidOperationException("Desinstalador registrado com aspas inválidas.");
            return(command[1..end],command[(end+1)..].Trim());
        }
        var exe=command.IndexOf(".exe",StringComparison.OrdinalIgnoreCase);
        return exe>0?(command[..(exe+4)],command[(exe+4)..].Trim()):(command,"");
    }
    public static bool CanUninstall(InstalledApplication app)=>app.Uninstall.Length>0||app.QuietUninstall.Length>0;
    public static bool SuccessfulExit(int code)=>code is 0 or 1641 or 3010;
    public static async Task<int> UninstallAsync(InstalledApplication app,bool silent,CancellationToken ct)
    {
        using var operation = OperationProgress.Begin("Desinstalar " + app.Name);
        operation.Report(15, "Preparando instalador oficial");
        try
        {
        var command=silent&&app.QuietUninstall.Length>0?app.QuietUninstall:app.Uninstall.Length>0?app.Uninstall:app.QuietUninstall;
        var(file,args)=Split(command);
        if(file.Length==0)throw new InvalidOperationException("Este programa não informa um desinstalador.");
        using var process=Process.Start(new ProcessStartInfo(file,args){UseShellExecute=true,Verb="runas"})??throw new InvalidOperationException("Não foi possível iniciar o desinstalador.");
        await process.WaitForExitAsync(ct);
        if (!SuccessfulExit(process.ExitCode)) operation.FlagFailure("Desinstalador retornou código " + process.ExitCode);
        operation.Succeed("Desinstalador finalizado"); return process.ExitCode;
        } catch (Exception ex) { operation.Fail(ex.Message); throw; }
    }
    public static string[] PackageArguments(string id,bool installed,IReadOnlyList<CatalogApplication> catalog)
    {
        if(!catalog.Any(x=>x.Id.Equals(id,StringComparison.Ordinal))||!Regex.IsMatch(id,@"^[A-Za-z0-9][A-Za-z0-9.+_-]{1,150}$"))throw new InvalidOperationException("Programa fora do catálogo autorizado.");
        return new[]{installed?"upgrade":"install","--id",id,"--exact","--source","winget","--silent","--disable-interactivity","--accept-package-agreements","--accept-source-agreements"};
    }
    public static ProcessStartInfo PackageStart(string id,bool installed,IReadOnlyList<CatalogApplication> catalog)
    {
        var arguments=PackageArguments(id,installed,catalog);
        // O alias pode não existir no PATH de tarefas agendadas; não abrir UAC invisível.
        var candidates = new List<string>();
        var local=Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
        candidates.Add(Path.Combine(local,"Microsoft","WindowsApps","winget.exe"));
        var windowsApps=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),"WindowsApps");
        try { if(Directory.Exists(windowsApps))candidates.AddRange(Directory.GetDirectories(windowsApps,"Microsoft.DesktopAppInstaller_*",SearchOption.TopDirectoryOnly).Select(x=>Path.Combine(x,"winget.exe"))); }
        catch(UnauthorizedAccessException) { /* WindowsApps pode estar protegido. */ }
        var winget=candidates.FirstOrDefault(File.Exists);
        if(winget is null)throw new InvalidOperationException("WinGet não disponível nesta sessão do Windows. Atualize o Instalador de Aplicativo da Microsoft Store e entre na conta do usuário.");
        var info=new ProcessStartInfo(winget){UseShellExecute=false,CreateNoWindow=true,RedirectStandardOutput=true,RedirectStandardError=true,StandardOutputEncoding=Encoding.UTF8,StandardErrorEncoding=Encoding.UTF8};
        foreach(var arg in arguments)info.ArgumentList.Add(arg);
        return info;
    }
    public static async Task<int> PackageAsync(string id,bool installed,IReadOnlyList<CatalogApplication> catalog,CancellationToken ct)
    {
        using var operation = OperationProgress.Begin((installed ? "Atualizar " : "Instalar ") + id);
        operation.Report(15, "Preparando instalador oficial");
        try
        {
        using var process=Process.Start(PackageStart(id,installed,catalog))??throw new InvalidOperationException("Não foi possível iniciar o WinGet.");
        operation.Report(30, "Instalador iniciado; aguardando conclusão");
        var stdout=process.StandardOutput.ReadToEndAsync(ct);
        var stderr=process.StandardError.ReadToEndAsync(ct);
        using var deadline=CancellationTokenSource.CreateLinkedTokenSource(ct);
        deadline.CancelAfter(TimeSpan.FromMinutes(30));
        try { await process.WaitForExitAsync(deadline.Token); }
        catch(OperationCanceledException) { try { if(!process.HasExited)process.Kill(true); } catch(InvalidOperationException) {} throw; }
        var output=((await stdout) + "\n" + (await stderr)).Trim();
        if(!SuccessfulExit(process.ExitCode))
        {
            var reason = process.ExitCode == unchecked((int)0x8A150014)
                ? "O pacote '" + id + "' não foi encontrado no catálogo WinGet deste computador. Confira a fonte winget e sincronize os pacotes com 'winget source update'. "
                : process.ExitCode == unchecked((int)0x8A150010)
                    ? "Não existe instalador compatível com este Windows para o pacote '" + id + "'. "
                    : "";
            throw new InvalidOperationException(reason + "WinGet " + process.ExitCode + " (0x" + unchecked((uint)process.ExitCode).ToString("X8") + "): " + (output.Length>320?output[^320..]:output));
        }
        operation.Succeed("Instalador finalizado");
        return process.ExitCode;
        } catch (Exception ex) { operation.Fail(ex.Message); throw; }
    }

}
