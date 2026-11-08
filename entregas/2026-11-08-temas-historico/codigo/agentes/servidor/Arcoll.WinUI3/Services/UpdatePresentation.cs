using System.Text.Json;
namespace Arcoll.WinUI3.Services;
public sealed record UpdateViewState(string Id,string Status,int Percent,string Message,string Phase,long Started,string Version,bool CanCancel,string[] Events,string FromVersion="",string Mode="normal");
/// <summary>Presentation follows receipts for one operation; a restart is not a success receipt.</summary>
public static class UpdatePresentation
{
 private static readonly object Sync=new();private static UpdateViewState _state=new("","idle",0,"Pronto para verificar a versão publicada.","check",0,"",false,[]);
 public static event Action<UpdateViewState>? Changed;
 public static UpdateViewState Current{get{lock(Sync)return _state;}}
 public static string Root=>Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital");
 public static string SessionFile=>Path.Combine(Root,"update-session.json");
 public static void Begin(string id,string fromVersion="",bool force=false){Set(new(id,"working",0,"Preparando atualização segura…","check",DateTimeOffset.UtcNow.ToUnixTimeSeconds(),"",true,[],fromVersion,force?"forçada":"normal"));}
 public static void Report(int percent,string message,bool? canCancel=null){var old=Current;if(old.Status is not("working" or "restarting"))return;percent=Math.Max(old.Percent,Math.Clamp(percent,0,99));var phase=percent<12?"check":percent<42?"download":percent<55?"verify":percent<87?"prepare":percent<98?"restart":"confirm";if(old.Percent==Math.Max(old.Percent,percent)&&old.Message==message&&old.Phase==phase&&(canCancel is null||canCancel==old.CanCancel))return;var history=old.Events;if(history.LastOrDefault()!=message)history=history.Append(message).TakeLast(10).ToArray();Set(old with{Percent=Math.Max(old.Percent,percent),Message=message,Phase=phase,CanCancel=canCancel??old.CanCancel,Events=history});}
 public static void Handoff(){var old=Current;Set(old with{Status="restarting",Percent=Math.Max(87,old.Percent),Phase="restart",Message="Arquivos preparados. O agente vai reiniciar para concluir a troca.",CanCancel=false});}
 public static void SetTarget(string version){var s=Current;Set(s with{Version=version});}
 public static void Finish(bool ok,string message,long ended=0){var old=Current;Set(old with{Status=ok?"complete":"failed",Percent=ok?100:old.Percent,Message=message,Phase=ok?"done":old.Phase,CanCancel=false},ended);}
 public static void Cancelled(){var old=Current;Set(old with{Status="cancelled",Message="Preparação cancelada. A instalação atual foi preservada.",CanCancel=false});}
 public static void Write(string file,object value){Directory.CreateDirectory(Root);var temp=file+"."+Guid.NewGuid().ToString("N")+".tmp";try{File.WriteAllText(temp,JsonSerializer.Serialize(value));File.Move(temp,file,true);}finally{if(File.Exists(temp))File.Delete(temp);}}
 private static void Set(UpdateViewState value,long ended=0)
 {
  lock(Sync){
   if(value.Id==_state.Id&&value.Status is "working" or "restarting"){
    if(_state.Status is "complete" or "failed" or "cancelled")return;
    var percent=Math.Max(_state.Percent,value.Percent);var phase=percent<12?"check":percent<42?"download":percent<55?"verify":percent<87?"prepare":percent<98?"restart":"confirm";
    value=value with{Percent=percent,Phase=phase,CanCancel=_state.CanCancel&&value.CanCancel,Status=_state.Status=="restarting"?"restarting":value.Status};
   }
   _state=value;try{Write(SessionFile,value);}catch(Exception e)when(e is IOException or UnauthorizedAccessException){}
  }
  if(value.Status is "complete" or "failed" or "cancelled")UpdateHistoryStore.Record(value,ended);
  Changed?.Invoke(value);
 }
 public static bool Restore(){try{if(!File.Exists(SessionFile))return false;var loaded=JsonSerializer.Deserialize<UpdateViewState>(File.ReadAllText(SessionFile));if(loaded is null||!System.Text.RegularExpressions.Regex.IsMatch(loaded.Id,"^[a-f0-9]{32}$")||loaded.Started<DateTimeOffset.UtcNow.AddDays(-2).ToUnixTimeSeconds())return false;lock(Sync)_state=loaded;return true;}catch(Exception e)when(e is IOException or UnauthorizedAccessException or JsonException){return false;}}
 public static void PollReceipts()
 {
  var old=Current;if(old.Id.Length==0||old.Status is not("working" or "restarting"))return;
  try{
   var result=Path.Combine(Root,"update-ui-result.json");if(File.Exists(result)){using var doc=JsonDocument.Parse(File.ReadAllText(result));var r=doc.RootElement;if(Matches(r,old.Id)&&r.TryGetProperty("ok",out var ok)&&ok.ValueKind is JsonValueKind.True or JsonValueKind.False){Finish(ok.GetBoolean(),r.TryGetProperty("message",out var m)?m.GetString()??"Operação concluída.":"Operação concluída.",r.TryGetProperty("finished",out var finished)&&finished.TryGetInt64(out var timestamp)?timestamp:0);return;}}
   var path=Path.Combine(Root,"update-progress.json");if(File.Exists(path)){using var doc=JsonDocument.Parse(File.ReadAllText(path));var r=doc.RootElement;if(Matches(r,old.Id)&&r.TryGetProperty("percent",out var p)&&p.TryGetInt32(out var n))Report(Math.Min(n,99),r.TryGetProperty("message",out var m)?m.GetString()??old.Message:old.Message,old.CanCancel);}
   if(DateTimeOffset.UtcNow.ToUnixTimeSeconds()-old.Started>3600)Finish(false,"A operação excedeu uma hora sem confirmação. Consulte o histórico antes de tentar novamente.");
  }catch(Exception e)when(e is IOException or UnauthorizedAccessException or JsonException){}
 }
 public static bool Matches(JsonElement value,string id)=>value.ValueKind==JsonValueKind.Object&&value.TryGetProperty("update_id",out var key)&&key.ValueKind==JsonValueKind.String&&string.Equals(key.GetString(),id,StringComparison.Ordinal);
}
