// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PenteFino/FileCheckers.cs
using System.Runtime.InteropServices;
using Microsoft.Data.Sqlite;
namespace Arcoll.WinUI3.Services.PenteFino;
public sealed class FileChecker(string id,string title):IChecker
{
    public string Id=>id;public string Title=>title;
    [StructLayout(LayoutKind.Sequential)]private struct BinInfo{public uint Size;public long Bytes;public long Items;}
    [DllImport("shell32.dll",CharSet=CharSet.Unicode)]private static extern int SHQueryRecycleBin(string? root,ref BinInfo info);
    public async Task<CheckResult> ScanAsync(ScanContext context,CancellationToken ct){var items=new List<Finding>();var notes=new List<string>();var complete=true;var maximum=context.Options.Mode=="quick"?5000:20000;
        foreach(var rule in context.Policy.Rules.Where(x=>x.Module==Id)){
            ct.ThrowIfCancellationRequested();if(PathPolicy.BrowserOpen(rule.Browser)){notes.Add(rule.Browser+": navegador aberto, perfil ignorado.");complete=false;continue;}
            if(rule.Id.EndsWith("-network")||rule.Id.EndsWith("-profile")||rule.Id.EndsWith("-firefox")){
                var cookies=Path.Combine(rule.Root,rule.Browser=="firefox"?"cookies.sqlite":"Cookies");
                if(File.Exists(cookies))try{items.AddRange(await CookiesAsync(cookies,rule,context,ct));}catch(Exception e)when(e is IOException or SqliteException or UnauthorizedAccessException){notes.Add("Cookies não lidos: "+e.Message);complete=false;}
                if(rule.Id.EndsWith("-profile")){var history=Path.Combine(rule.Root,"History");if(File.Exists(history)&&context.Policy.Allows(history,rule.Id)){var f=new FileInfo(history);items.Add(new(ResultsStore.Hash("privacy|"+history),Id,"Histórico de "+rule.Browser,history,f.Length,"Banco local de histórico; navegador fechado. Os cookies confiáveis ficam preservados em outro arquivo.","Quarentenar histórico",true,"file",rule.Id,ModifiedTicks:f.LastWriteTimeUtc.Ticks));}}
                if(rule.Browser=="firefox")notes.Add("Firefox: histórico em places.sqlite também contém favoritos. A correção desse banco fica desabilitada; cookies são tratados por domínio.");continue;
            }
            foreach(var path in PathPolicy.Walk(rule.Root,ct,_=>{complete=false;})){
                if(items.Count>=maximum){complete=false;notes.Add("Limite de itens atingido. O relatório é parcial.");break;}ct.ThrowIfCancellationRequested();
                try{if(!context.Policy.Allows(path,rule.Id))continue;var file=new FileInfo(path);if(file.LastWriteTimeUtc>DateTime.UtcNow.AddDays(-rule.Days)||file.Length>256L*1024*1024)continue;
                    using(var check=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.None)){} // ignora arquivos em uso sem os alterar
                    items.Add(new(ResultsStore.Hash(Id+"|"+path),Id,Path.GetFileName(path),path,file.Length,"Regra "+rule.Id+" · sem alteração há "+rule.Days+" dia(s).","Mover para quarentena",true,"file",rule.Id,ModifiedTicks:file.LastWriteTimeUtc.Ticks));
                }catch(Exception e)when(e is IOException or UnauthorizedAccessException){complete=false;}
                if(items.Count%100==0)context.Progress?.Report(new(Id,"running",Math.Min(90,items.Count*90/maximum),items.Count+" itens elegíveis"));
            }
        }
        if(Id=="clean"){
            var bin=new BinInfo{Size=(uint)Marshal.SizeOf<BinInfo>()};if(OperatingSystem.IsWindows()&&SHQueryRecycleBin(null,ref bin)==0&&bin.Items>0)items.Add(new("recycle-bin","clean","Lixeira",Environment.GetFolderPath(Environment.SpecialFolder.Desktop),bin.Bytes,bin.Items+" itens. A restauração individual precisa conservar a estrutura da Lixeira.","Somente diagnóstico; abrir Lixeira para revisão",false,"info"));
            notes.Add("Escopo: temporários e caches permitidos. Logs fora dessas raízes e supostos leftovers de aplicativos não são removidos por heurística.");
        }
        return new(Id,Title,items,notes,complete);
    }
    private async Task<List<Finding>> CookiesAsync(string source,FileRule rule,ScanContext context,CancellationToken ct){
        if(!context.Policy.Allows(source,rule.Id))return [];if(new FileInfo(source).Length>128L*1024*1024)throw new IOException("Banco de cookies excede 128 MB.");if(File.Exists(source+"-wal")&&new FileInfo(source+"-wal").Length>0)throw new IOException("Banco com transações pendentes; feche o navegador.");
        var copy=context.Store.LocalPath("snapshots");Directory.CreateDirectory(copy);var target=context.Store.LocalPath("snapshots",Guid.NewGuid().ToString("N")+".sqlite");
        try{await using(var input=new FileStream(source,FileMode.Open,FileAccess.Read,FileShare.None,65536,true))await using(var output=new FileStream(target,FileMode.CreateNew,FileAccess.Write,FileShare.None,65536,true))await input.CopyToAsync(output,65536,ct);
            using var db=new SqliteConnection(new SqliteConnectionStringBuilder{DataSource=target,Mode=SqliteOpenMode.ReadOnly,Pooling=false}.ToString());db.Open();using var query=db.CreateCommand();var firefox=rule.Browser=="firefox";query.CommandText=firefox?"SELECT host,COUNT(*) FROM moz_cookies GROUP BY host LIMIT 3001":"SELECT host_key,COUNT(*) FROM cookies GROUP BY host_key LIMIT 3001";using var reader=query.ExecuteReader();var result=new List<Finding>();var timestamp=File.GetLastWriteTimeUtc(source).Ticks;
            while(reader.Read()){ct.ThrowIfCancellationRequested();var host=reader.GetString(0);if(context.Policy.TrustedCookie(host,context.Options.TrustedCookies))continue;result.Add(new(ResultsStore.Hash(source+"|"+host),"privacy",host+" · "+reader.GetInt64(1)+" cookie(s)",source,0,"Cookies do domínio; domínios confiáveis ficam excluídos. A remoção pode encerrar sessões.","Remover cookies deste domínio com backup do banco",true,"cookies",rule.Id,host,timestamp,ValueKind:firefox?"firefox":"chromium",Severity:"review"));if(result.Count==3000)throw new IOException("Mais de 3.000 domínios: reduza o escopo antes de corrigir.");}return result;
        }finally{if(File.Exists(target))File.Delete(target);}
    }
}
