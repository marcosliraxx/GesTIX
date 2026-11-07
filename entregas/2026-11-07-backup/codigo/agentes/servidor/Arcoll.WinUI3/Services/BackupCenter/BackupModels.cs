using System.Text.Json;
namespace Arcoll.WinUI3.Services.BackupCenter;
public sealed record BackupIdentity(string Origin,string Fingerprint,string SiteCode,string Key);
public sealed record BackupSettings(string[] Folders,string Destination,string Schedule,int IntervalMinutes,string DailyTime,string Timezone,string[] Exclusions,int MaxFileMb,int BandwidthKbps,int KeepLast,int RpoMinutes,int MaxRetries=3,int RetryMinutes=15);
public sealed record BackupPlan(string Id,string Name,string Target,long? ConnectionId,bool Enabled,bool Requested,BackupSettings Settings,string Revision,long AccountId,long NetworkId,long AgentId,long Updated=0,string StoreKey="",long LastSuccess=0);
public sealed record BackupOperation(string Id,string Kind,string? SourceRun,string[] Selection,BackupPlan Plan,string Lease,long MaxFileBytes);
public sealed record BackupFile(int Folder,string Relative,string Digest,long Size,long Mtime){public string PathHash=>BackupSafety.HashText(Folder.ToString(System.Globalization.CultureInfo.InvariantCulture)+"|"+Relative);}
public sealed record BackupManifest(string Schema,List<BackupFile> Files);
public sealed record ManifestReply(string Canonical,string Digest,BackupPlan Plan,string[] Selection);
public sealed record UploadReply(bool Done,long NextOffset,string? UploadId,int Chunk=0);
public sealed record FinishReply(string Canonical,string Digest);
public sealed class BackupSummary{
 public long Files{get;set;}public long Bytes{get;set;}public long LogicalBytes{get;set;}public long Errors{get;set;}public long Unchanged{get;set;}public long SpeedBps{get;set;}
 public string Phase{get;set;}="waiting";public string Current{get;set;}="";public string Message{get;set;}="";public string Destination{get;set;}="";public List<string> Failures{get;set;}=[];
 public void Error(string path,string reason){Errors++;if(Failures.Count<12)Failures.Add(path+" · "+reason);}
}
public interface IBackupRemote:IDisposable{
 Task<BackupOperation?> NextAsync(string runner,CancellationToken ct);
 Task<JsonElement> StateAsync(CancellationToken ct);
 Task ProgressAsync(BackupOperation operation,BackupSummary summary,CancellationToken ct);
 Task<UploadReply> BeginAsync(BackupOperation op,string digest,string md5,long size,CancellationToken ct);
 Task<UploadReply> ChunkAsync(BackupOperation op,string digest,string md5,string upload,long offset,byte[] data,CancellationToken ct);
 Task FilesAsync(BackupOperation op,IReadOnlyList<BackupFile> files,CancellationToken ct);
 Task<FinishReply> FinishAsync(BackupOperation op,BackupSummary summary,CancellationToken ct);
 Task FailAsync(BackupOperation op,BackupSummary summary,CancellationToken ct);
 Task<ManifestReply> ManifestAsync(BackupOperation op,CancellationToken ct);
 Task<Stream> DownloadAsync(BackupOperation op,BackupFile file,CancellationToken ct);
 Task FinishOperationAsync(BackupOperation op,BackupSummary summary,CancellationToken ct);
}
public static class BackupJson{public static readonly JsonSerializerOptions Options=new(){PropertyNamingPolicy=JsonNamingPolicy.SnakeCaseLower,PropertyNameCaseInsensitive=true};}
