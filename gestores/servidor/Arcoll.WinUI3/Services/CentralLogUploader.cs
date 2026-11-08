using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
namespace Arcoll.WinUI3.Services;
public sealed record LogIdentity(string Origin,string Fingerprint,string SiteCode,string DownloadKey)
{
 public bool Valid=>Uri.TryCreate(Origin,UriKind.Absolute,out var u)&&u.Scheme=="https"&&string.IsNullOrEmpty(u.UserInfo)&&string.IsNullOrEmpty(u.Query)&&Regex.IsMatch(Fingerprint,"^[a-f0-9]{64}$")&&Regex.IsMatch(SiteCode,"^[A-Z0-9]{5}$")&&Regex.IsMatch(DownloadKey,"^[a-fA-F0-9]{64}$");
}
public sealed record DiagnosticEvent(string Source,string Level,string Message);
public sealed record DiagnosticBatch(string BatchId,DiagnosticEvent[] Events);
public sealed record LogChunk(long EndOffset,DiagnosticEvent[] Events);
public static class DiagnosticRedactor
{
 public static string Clean(string text){text=Regex.Replace(text,@"(?i)([?&](?:t|token|key|download_key|access_token|refresh_token)=)[^\s&""'<>]+","$1[oculto]");text=Regex.Replace(text,"(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\\s*[\"']?\\s*[:=]\\s*)([\"'])(.*?)\\2","$1$2[oculto]$2");text=Regex.Replace(text,@"(?i)((?:password|passwd|senha|secret|app_secret|api[_-]?key|download_key|access_token|refresh_token|authorization|cookie)\s*[""']?\s*[:=]\s*[""']?)[^\s,""'\r\n}]+","$1[oculto]");text=Regex.Replace(text,@"(?i)\bBearer\s+[A-Za-z0-9._~+/-]+","Bearer [oculto]");text=Regex.Replace(text,@"\b[a-f0-9]{64}\b","[hash/chave oculto]",RegexOptions.IgnoreCase);text=Regex.Replace(text,@"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]","");return text.Length>3800?text[..3800]+" [linha limitada]":text;}
 public static string Hash(string text)=>Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(text))).ToLowerInvariant();
}
/// <summary>Byte checkpoints preserve UTF-8/UTF-16 lines and do not depend on StreamReader buffering.</summary>
public static class DiagnosticLogReader
{
 public static LogChunk Read(string path,long offset,string source){using var file=new FileStream(path,FileMode.Open,FileAccess.Read,FileShare.ReadWrite|FileShare.Delete);var bom=new byte[3];var prefix=file.Read(bom,0,3);bool unicode=prefix>=2&&bom[0]==255&&bom[1]==254;int skip=unicode?2:prefix>=3&&bom[0]==239&&bom[1]==187&&bom[2]==191?3:0;if(offset<skip||offset>file.Length)offset=skip;file.Position=offset;var buffer=new byte[16384];int count=file.Read(buffer,0,buffer.Length),end=0,lines=0;
  if(unicode){count-=count%2;for(int i=0;i+1<count;i+=2)if(buffer[i]==10&&buffer[i+1]==0){end=i+2;if(++lines==100)break;}}
  else for(int i=0;i<count;i++)if(buffer[i]==10){end=i+1;if(++lines==100)break;}
  if(end==0&&count>0&&(count==buffer.Length||DateTime.UtcNow-File.GetLastWriteTimeUtc(path)>TimeSpan.FromSeconds(5)))end=count;
  if(end==0)return new(offset,[]);var text=(unicode?Encoding.Unicode:Encoding.UTF8).GetString(buffer,0,end);var events=text.Split('\n').Where(s=>!string.IsNullOrWhiteSpace(s)).Take(100).Select(s=>new DiagnosticEvent(source,Regex.IsMatch(s,"ERROR|ERRO|FALHA|CRITICAL",RegexOptions.IgnoreCase)?"ERROR":Regex.IsMatch(s,"WARN|AVISO",RegexOptions.IgnoreCase)?"WARNING":"INFO",DiagnosticRedactor.Clean(s.TrimEnd('\r')))).ToArray();return new(offset+end,events);
 }
}
/// <summary>Read-only log collection, durable redacted outbox, bounded disk and authenticated HTTPS acknowledgments.</summary>
public sealed class CentralLogUploader:IDisposable
{
 private readonly Func<LogIdentity?> _identity;private readonly string _state;private readonly (string Path,string Label)[] _roots;private readonly HttpClient _http;private readonly CancellationTokenSource _stop=new();private Task? _loop;
 private static readonly JsonSerializerOptions Json=new(){PropertyNamingPolicy=JsonNamingPolicy.CamelCase};
 public CentralLogUploader(Func<LogIdentity?> identity,string state,IEnumerable<(string Path,string Label)> roots,HttpMessageHandler? handler=null){_identity=identity;_state=state;_roots=roots.ToArray();_http=new(handler??new HttpClientHandler{AllowAutoRedirect=false,UseProxy=false}){Timeout=TimeSpan.FromSeconds(12)};_http.DefaultRequestHeaders.UserAgent.ParseAdd("ArcollGestor/"+(typeof(CentralLogUploader).Assembly.GetName().Version?.ToString(3)??"5.0.152"));_http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));}
 public static (string Path,string Label)[] UserRoots()=>[(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"TI-Digital"),"user-ti"),(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"Arcoll"),"user-arcoll"),(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),"Arcoll"),"profile-arcoll"),(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),"TI-Digital"),"system")];
 public static (string Path,string Label)[] SystemRoots()=>[(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),"TI-Digital"),"system")];
 public void Start(){if(_loop is null)_loop=Task.Run(LoopAsync);}
 private async Task LoopAsync(){while(!_stop.IsCancellationRequested){try{await PumpOnceAsync(_stop.Token);}catch(OperationCanceledException)when(_stop.IsCancellationRequested){break;}catch(Exception e)when(e is IOException or UnauthorizedAccessException or JsonException or HttpRequestException or InvalidOperationException){/* Never feed transport failures back into the log collection loop. */}try{await Task.Delay(TimeSpan.FromSeconds(30),_stop.Token);}catch(OperationCanceledException){break;}}}
 public async Task PumpOnceAsync(CancellationToken token){var identity=_identity();if(identity is null||!identity.Valid)return;Directory.CreateDirectory(_state);var queue=Path.Combine(_state,"outbox");Directory.CreateDirectory(queue);var scope=DiagnosticRedactor.Hash(identity.Origin+"|"+identity.Fingerprint+"|"+identity.SiteCode+"|"+identity.DownloadKey);var queueRoot=Path.Combine(queue,scope);Directory.CreateDirectory(queueRoot);
  // An identity change gets its own outbox. Old records are never sent with another network's credentials.
  foreach(var file in Directory.EnumerateFiles(queueRoot,"*.json").OrderBy(File.GetLastWriteTimeUtc).Take(4)){token.ThrowIfCancellationRequested();var batch=JsonSerializer.Deserialize<DiagnosticBatch>(File.ReadAllText(file),Json);if(batch is null||batch.Events.Length is <1 or >100){File.Delete(file);continue;}using var content=new StringContent(JsonSerializer.Serialize(new{fingerprint=identity.Fingerprint,site_code=identity.SiteCode,download_key=identity.DownloadKey,batch_id=batch.BatchId,events=batch.Events},Json),Encoding.UTF8);content.Headers.ContentType=new MediaTypeHeaderValue("application/json");using var reply=await _http.PostAsync(identity.Origin.TrimEnd('/')+"/api/gestor-logs.php",content,token);if(!reply.IsSuccessStatusCode)break;var bytes=await reply.Content.ReadAsByteArrayAsync(token);if(bytes.Length>8192)break;using var ack=JsonDocument.Parse(bytes);var root=ack.RootElement;if(!root.TryGetProperty("ok",out var ok)||ok.ValueKind!=JsonValueKind.True||!root.TryGetProperty("batch_id",out var id)||id.GetString()!=batch.BatchId||!root.TryGetProperty("accepted",out var accepted)||accepted.GetInt32()!=batch.Events.Length)break;File.Delete(file);}
  if(Directory.EnumerateFiles(queue,"*.json",SearchOption.AllDirectories).Sum(f=>new FileInfo(f).Length)>32*1024*1024)return;
  var checkpoint=Path.Combine(_state,"cursor-"+scope+".json");Dictionary<string,long> cursors;try{cursors=File.Exists(checkpoint)&&new FileInfo(checkpoint).Length<1048576?JsonSerializer.Deserialize<Dictionary<string,long>>(File.ReadAllText(checkpoint))??[]:[];}catch(JsonException){cursors=[];}
  var currentScopeFile=Path.Combine(_state,"current-scope.txt");var previousScope=File.Exists(currentScopeFile)?File.ReadAllText(currentScopeFile):"";
  if(previousScope.Length>0&&previousScope!=scope){cursors.Clear();foreach(var existing in Sources()){var info=new FileInfo(existing.Path);cursors[SourceKey(existing.Path,info)]=info.Length;}Atomic(checkpoint,JsonSerializer.Serialize(cursors));}
  if(previousScope!=scope)Atomic(currentScopeFile,scope);
  int produced=0;foreach(var source in Sources()){token.ThrowIfCancellationRequested();if(produced>=4)break;var info=new FileInfo(source.Path);var fileKey=SourceKey(source.Path,info);var start=cursors.GetValueOrDefault(fileKey);var chunk=DiagnosticLogReader.Read(source.Path,start,source.Label);if(chunk.EndOffset==start)continue;
   if(chunk.Events.Length>0){var id=DiagnosticRedactor.Hash(identity.Fingerprint+"|"+fileKey+"|"+start+"|"+chunk.EndOffset+"|"+JsonSerializer.Serialize(chunk.Events,Json));var destination=Path.Combine(queueRoot,id+".json");if(!File.Exists(destination))Atomic(destination,JsonSerializer.Serialize(new DiagnosticBatch(id,chunk.Events),Json));produced++;}
   cursors[fileKey]=chunk.EndOffset;Atomic(checkpoint,JsonSerializer.Serialize(cursors));
  }
 }
 private IEnumerable<(string Path,string Label)> Sources(){var seen=new HashSet<string>(StringComparer.OrdinalIgnoreCase);foreach(var root in _roots){if(!Directory.Exists(root.Path))continue;IEnumerable<string> paths;try{paths=Directory.EnumerateFiles(root.Path,"*",new EnumerationOptions{RecurseSubdirectories=true,IgnoreInaccessible=true,AttributesToSkip=FileAttributes.ReparsePoint,MaxRecursionDepth=5}).Where(f=>{var relative=Path.GetRelativePath(root.Path,f);return relative.Split(Path.DirectorySeparatorChar).Any(p=>p.Equals("logs",StringComparison.OrdinalIgnoreCase))&&(f.EndsWith(".log",StringComparison.OrdinalIgnoreCase)||f.EndsWith(".txt",StringComparison.OrdinalIgnoreCase)||f.EndsWith(".jsonl",StringComparison.OrdinalIgnoreCase));}).Take(200).ToArray();}catch(IOException){continue;}foreach(var path in paths)if(seen.Add(path))yield return(path,ShortSource(root.Label+"/"+Path.GetRelativePath(root.Path,path).Replace('\\','/')));}}
 private static string SourceKey(string path,FileInfo info)=>DiagnosticRedactor.Hash(path.ToLowerInvariant()+"|"+(OperatingSystem.IsWindows()?info.CreationTimeUtc.Ticks:0));
 private static string ShortSource(string source)=>source.Length<=150?source:source[..70]+".../"+source[^70..];
 private static void Atomic(string path,string content){var tmp=path+"."+Guid.NewGuid().ToString("N")+".tmp";try{File.WriteAllText(tmp,content,new UTF8Encoding(false));File.Move(tmp,path,true);}finally{if(File.Exists(tmp))File.Delete(tmp);}}
 public void Dispose(){_stop.Cancel();_http.Dispose();}
}
