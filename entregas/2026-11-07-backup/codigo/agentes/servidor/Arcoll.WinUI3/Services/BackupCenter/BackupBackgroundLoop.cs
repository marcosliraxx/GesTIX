namespace Arcoll.WinUI3.Services.BackupCenter;
public sealed class BackupBackgroundLoop:IDisposable
{
 private readonly Func<BackupIdentity> _identity;private readonly Func<bool> _enabled;private readonly string _work,_restore,_runner;private readonly Action<string>? _log;private readonly CancellationTokenSource _stop=new();private Task? _task;
 public BackupBackgroundLoop(Func<BackupIdentity> identity,Func<bool> enabled,string work,string restore,string runner="agent",Action<string>? log=null){_identity=identity;_enabled=enabled;_work=work;_restore=restore;_runner=runner;_log=log;}
 public void Start(){if(_task is not null)return;_task=Task.Run(async()=>{while(!_stop.IsCancellationRequested){try{if(_enabled()){var identity=_identity();using var remote=new BackupHttpClient(identity);var operation=await remote.NextAsync(_runner,_stop.Token);if(operation is not null){var scope=BackupSafety.HashText(identity.Origin+"|"+identity.Fingerprint)[..24];await new BackupExecutor(remote,Path.Combine(_work,scope),Path.Combine(_restore,scope)).RunAsync(operation,_stop.Token);}}}catch(OperationCanceledException)when(_stop.IsCancellationRequested){break;}catch(Exception error){_log?.Invoke("Central de Backup: "+error.GetType().Name+" · "+error.Message);}try{await Task.Delay(TimeSpan.FromSeconds(30),_stop.Token);}catch(OperationCanceledException){break;}}});}
 public void Dispose()=>_stop.Cancel();
}
