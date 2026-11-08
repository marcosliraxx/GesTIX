// Caminho: gestores/servidor/Arcoll.WinUI3/BackupWorker/Program.cs
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Security.Principal;
using Arcoll.WinUI3.Services.BackupCenter;
if(!OperatingSystem.IsWindows())return 2;
var home=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),"TI-Digital","BackupService");
try{
 BackupSafety.NoLinks(home);var config=Path.Combine(home,"gestor-config.json");using var doc=JsonDocument.Parse(File.ReadAllText(config));string Read(string key)=>doc.RootElement.GetProperty(key).GetString()??"";var origin=Read("origin").TrimEnd('/');var fp=Read("fingerprint");var code=Read("site_code");var key=Read("download_key");
 if(!Uri.TryCreate(origin,UriKind.Absolute,out var uri)||uri.Scheme!="https"||uri.UserInfo.Length>0||uri.Query.Length>0||uri.Fragment.Length>0||!Regex.IsMatch(fp,"^[a-f0-9]{64}$")||!Regex.IsMatch(code,"^[A-Z0-9]{5}$")||!Regex.IsMatch(key,"^[a-fA-F0-9]{64}$"))throw new InvalidDataException("Configuração inválida.");
 using var identity=WindowsIdentity.GetCurrent();if(!new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator)&&identity.User?.IsWellKnown(WellKnownSidType.LocalSystemSid)!=true)throw new UnauthorizedAccessException("Executor de início requer contexto administrativo autorizado.");
 using var stop=new CancellationTokenSource();Console.CancelKeyPress+=(_,e)=>{e.Cancel=true;stop.Cancel();};
 void Log(string message){try{var path=Path.Combine(home,"logs","worker.log");Directory.CreateDirectory(Path.GetDirectoryName(path)!);if(File.Exists(path)&&new FileInfo(path).Length>1048576)File.Move(path,path+".previous",true);File.AppendAllText(path,DateTimeOffset.UtcNow.ToString("O")+" "+message.Replace('\r',' ').Replace('\n',' ')+Environment.NewLine);}catch(IOException){}}
 Log("Executor dedicado iniciado. Sem interface e sem comandos remotos genéricos.");using var loop=new BackupBackgroundLoop(()=>new BackupIdentity(origin,fp,code,key),()=>true,Path.Combine(home,"cache"),Path.Combine(home,"Restaurados"),"service",Log);loop.Start();try{await Task.Delay(Timeout.Infinite,stop.Token);}catch(OperationCanceledException){}return 0;
}catch(Exception error){try{Directory.CreateDirectory(Path.Combine(home,"logs"));File.AppendAllText(Path.Combine(home,"logs","worker.log"),DateTimeOffset.UtcNow.ToString("O")+" Falha ao iniciar: "+error.GetType().Name+Environment.NewLine);}catch(Exception){}return 2;}
