// Caminho: gestores/servidor/Arcoll.WinUI3/Services/UpdateCheckService.cs
using System.Net;
using System.Text.Json;
using Arcoll.WinUI3.Models;
namespace Arcoll.WinUI3.Services;
public sealed record UpdateAvailability(string Version,bool Available,string Kind,long CheckedAt,int ChangedCount);
/// <summary>Authenticated metadata probe. No installer, staging or session/history creation.</summary>
public static class UpdateCheckService
{
 public static async Task<UpdateAvailability> CheckAsync(PlatformClient platform,CancellationToken ct)
 {
  if(UpdatePresentation.Current.Status is "working" or "restarting")throw new InvalidOperationException("Aguarde a atualização em andamento.");
  if(!UpdateService.TryBeginCheck())throw new InvalidOperationException("Uma operação de atualização já está em andamento.");
  try{return await Task.Run(()=>CheckCoreAsync(platform,ct),ct);}finally{UpdateService.EndCheck();}
 }
 private static async Task<UpdateAvailability> CheckCoreAsync(PlatformClient platform,CancellationToken ct)
 {
  var cfg=platform.Config;if(!cfg.IsComplete)throw new InvalidOperationException("Gestor sem autorização para verificar atualizações.");
  var installed=Path.Combine(UpdatePresentation.Root,"WinUI3");var inventory=Directory.Exists(installed)?PrecompiledUpdateService.Inventory(installed,ct):new Dictionary<string,string>();
  using var http=new HttpClient(new HttpClientHandler{AllowAutoRedirect=false,UseProxy=false}){Timeout=TimeSpan.FromMinutes(2)};PlatformClient.ApplyDefaults(http);
  var arch=System.Runtime.InteropServices.RuntimeInformation.OSArchitecture==System.Runtime.InteropServices.Architecture.Arm64?"win-arm64":"win-x64";
  foreach(var mode in new[]{"native-precompiled","native-update"}){
   using var request=new HttpRequestMessage(HttpMethod.Post,cfg.Origin+"/api/download-gestor.php?mode="+mode){Content=PlatformClient.JsonBody(mode=="native-precompiled"?(object)new{fingerprint=cfg.Fingerprint,site_code=cfg.SiteCode,download_key=cfg.DownloadKey,arch,probe=true,files=inventory}:new{fingerprint=cfg.Fingerprint,site_code=cfg.SiteCode,download_key=cfg.DownloadKey,probe=true})};
   using var response=await http.SendAsync(request,HttpCompletionOption.ResponseHeadersRead,ct);
   if(mode=="native-precompiled"&&response.StatusCode is HttpStatusCode.NotFound or HttpStatusCode.NotImplemented)continue;
   if(!response.IsSuccessStatusCode)throw new InvalidOperationException("Consulta recusada pela plataforma (HTTP "+(int)response.StatusCode+").");
   if(response.Content.Headers.ContentType?.MediaType!="application/json")throw new InvalidOperationException("A plataforma precisa receber o arquivo atualizado da API antes desta verificação.");
   using var bytes=new MemoryStream();await using(var body=await response.Content.ReadAsStreamAsync(ct)){var buffer=new byte[8192];int n;while((n=await body.ReadAsync(buffer,ct))>0){if(bytes.Length+n>65536)throw new InvalidDataException("Resposta de verificação acima do limite.");bytes.Write(buffer,0,n);}}
   using var doc=JsonDocument.Parse(bytes.ToArray());var root=doc.RootElement;if(!root.TryGetProperty("ok",out var ok)||ok.ValueKind!=JsonValueKind.True)throw new InvalidDataException("A plataforma não confirmou a verificação.");var version=root.GetProperty("version").GetString()??"";if(!AppInfo.TryCompareRelease(version,AppInfo.Version,out var comparison)||comparison<0)throw new InvalidDataException("Versão publicada inválida ou anterior à instalada.");
   if(mode=="native-precompiled"){var count=root.GetProperty("changed_count").GetInt32();if(count<0||count>3500)throw new InvalidDataException("Contagem de arquivos inválida.");return new(version,count>0||comparison>0,"binary",DateTimeOffset.UtcNow.ToUnixTimeSeconds(),count);}
   var hash=root.GetProperty("package_hash").GetString()??"";if(!System.Text.RegularExpressions.Regex.IsMatch(hash,"^[a-fA-F0-9]{64}$"))throw new InvalidDataException("Hash de consulta inválido.");var previous=Path.Combine(UpdatePresentation.Root,"installed-package.sha256");var have=File.Exists(previous)?File.ReadAllText(previous).Trim():"";return new(version,!hash.Equals(have,StringComparison.OrdinalIgnoreCase)||comparison>0,"source",DateTimeOffset.UtcNow.ToUnixTimeSeconds(),-1);
  }
  throw new InvalidOperationException("Nenhuma publicação disponível para este computador.");
 }
}
