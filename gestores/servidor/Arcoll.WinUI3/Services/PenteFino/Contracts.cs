// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PenteFino/Contracts.cs
// Pente-Fino: contratos versionados; a interface recebe IDs, nunca comandos ou caminhos de correção.
namespace Arcoll.WinUI3.Services.PenteFino;
public sealed record Finding(string Id,string Module,string Title,string Path,long Bytes,string Reason,string Action,bool CanFix,
    string Kind="file",string Rule="",string Selector="",long ModifiedTicks=0,string Expected="",string ValueKind="",string Severity="info",int Quantity=1);
public sealed record CheckResult(string Id,string Title,List<Finding> Items,List<string> Notes,bool Complete=true);
public sealed record ScanReport(string Schema,string Id,DateTimeOffset At,string Mode,List<CheckResult> Modules,bool Cancelled,double Seconds);
public sealed record ScanOptions(string Mode,string[] Modules,string[] Exclusions,string[] TrustedCookies);
public sealed record ProgressState(string Module,string State,int Percent,string Message);
public sealed record FixProgressState(int Completed,int Total,string? Id,string State,Dictionary<string,bool> Outcomes,int Succeeded=0,int Failed=0);
public sealed record Settings(string Mode,string[] Modules,string[] Exclusions,string[] TrustedCookies,string[] Ignored,AnalysisPreferences? Preferences=null,string[]? CustomFolders=null){
    public static Settings Default=>new("quick",["privacy","clean","startup","registry","updates"],[],[],[]);
}
public sealed record AnalysisPreferences(string[] Categories,string[] RegistryChecks,string[] StartupKinds,int PeriodDays,bool SoftwareUpdates,bool WindowsUpdates);
public sealed record UndoRecord(string Id,string Session,string Kind,string Target,string Backup,string BeforeHash,string AfterHash,string BeforeValue,string ValueKind,string State,DateTimeOffset At,string Message="");
public sealed record ActionResult(string Id,bool Ok,string Message);
public sealed record FixReport(string Session,DateTimeOffset At,List<ActionResult> Results,bool Cancelled);
public interface IChecker { string Id{get;} string Title{get;} Task<CheckResult> ScanAsync(ScanContext context,CancellationToken ct); }
public sealed record ScanContext(ScanOptions Options,ResultsStore Store,PathPolicy Policy,IProgress<ProgressState>? Progress);
