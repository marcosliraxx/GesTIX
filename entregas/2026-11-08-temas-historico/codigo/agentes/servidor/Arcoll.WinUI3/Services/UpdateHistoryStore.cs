using System.Text.Json;
namespace Arcoll.WinUI3.Services;
public sealed record UpdateHistoryEntry(string Id,long Started,long Ended,string FromVersion,string ToVersion,string Status,int Percent,string Mode,string Message,string[] Events);
public static class UpdateHistoryStore
{
 private static readonly object Sync=new();private static bool _readFailed;private static string FilePath=>Path.Combine(UpdatePresentation.Root,"update-history.json");public static string Warning{get;private set;}="";
 public static UpdateHistoryEntry[] Read(){lock(Sync){try{if(!File.Exists(FilePath)){_readFailed=false;return [];}if(new FileInfo(FilePath).Length>8388608)throw new InvalidDataException("Histórico acima do limite.");var rows=JsonSerializer.Deserialize<UpdateHistoryEntry[]>(File.ReadAllText(FilePath))??[];_readFailed=false;return rows.Take(1000).ToArray();}catch(Exception error)when(error is IOException or UnauthorizedAccessException or JsonException){_readFailed=true;Warning="Não foi possível ler o histórico local. O arquivo foi preservado para diagnóstico.";return [];}}}
 public static void Record(UpdateViewState state,long ended=0)
 {
  if(state.Id.Length!=32||state.Status is not("complete" or "failed" or "cancelled"))return;
  lock(Sync){try{var items=Read();if(_readFailed&&File.Exists(FilePath)){File.Copy(FilePath,FilePath+".preserved-"+DateTimeOffset.UtcNow.ToUnixTimeMilliseconds(),false);Warning="Histórico anterior ilegível foi preservado em cópia local.";}
   var entry=new UpdateHistoryEntry(state.Id,state.Started,ended>0?ended:DateTimeOffset.UtcNow.ToUnixTimeSeconds(),state.FromVersion,state.Version,state.Status,state.Percent,state.Mode,state.Message,state.Events??[]);
   if(items.Any(x=>x.Id==entry.Id&&x.Status==entry.Status))return;
   UpdatePresentation.Write(FilePath,items.Where(x=>x.Id!=entry.Id).Append(entry).OrderByDescending(x=>x.Ended).Take(1000).ToArray());
  }catch(Exception error)when(error is IOException or UnauthorizedAccessException){Warning="Não foi possível gravar o histórico; os recibos atuais permanecem disponíveis.";}}
 }
 public static void ImportLastSession(){var s=UpdatePresentation.Current;if(!Read().Any(x=>x.Id==s.Id))Record(s);}
}
