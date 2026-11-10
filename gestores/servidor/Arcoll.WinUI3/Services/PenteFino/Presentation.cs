// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PenteFino/Presentation.cs
using System.Text.Json;
namespace Arcoll.WinUI3.Services.PenteFino;
public sealed record CategorySummary(string Kind,int Count,int Fixable,long Bytes,string? Title=null);
public sealed record ModuleSummary(string Id,string Title,int Count,int Fixable,int Review,int Informational,long Bytes,bool Complete,string[] Notes,CategorySummary[] Categories);
/// <summary>Uma verificação configurada no modal "O que analisar" (mesmo sem ocorrências).</summary>
public sealed record EntrySummary(string Id,string Label,int Count,int Fixable,int Review,long Bytes);
/// <summary>Área do resultado, espelhando as seções configuradas (Windows, Sistema, Navegador, Registros...).</summary>
public sealed record AreaSummary(string Id,string Title,string Module,int Count,int Fixable,int Review,int Informational,long Bytes,bool Complete,string[] Notes,EntrySummary[] Entries);
public sealed record ReportSummary(string Id,DateTimeOffset At,string Mode,bool Cancelled,double Seconds,int Count,int Fixable,int Review,int Informational,long Bytes,bool Complete,ModuleSummary[] Modules,AreaSummary[] Areas);
/// <summary>The WebView receives quantities, never thousands of file paths or correction IDs.</summary>
public static class Presentation
{
 private static int Q(Finding i)=>Math.Max(1,i.Quantity);
 private static JsonElement Section(JsonElement catalog,string name)=>catalog.ValueKind==JsonValueKind.Object&&catalog.TryGetProperty(name,out var e)?e:default;
 private static string Label(JsonElement section,string key,string fallback){if(section.ValueKind!=JsonValueKind.Object||!section.TryGetProperty(key,out var e))return fallback;if(e.ValueKind==JsonValueKind.Array&&e.GetArrayLength()>0)return e[0].GetString()??fallback;if(e.ValueKind==JsonValueKind.Object&&e.TryGetProperty("label",out var l))return l.GetString()??fallback;return fallback;}
 /// <summary>Área de uma categoria de limpeza conforme o catálogo da plataforma.</summary>
 public static (string Id,string Title) CleanArea(JsonElement catalog,string key){var section=Section(catalog,"catalog");if(section.ValueKind==JsonValueKind.Object&&section.TryGetProperty(key,out var e)&&e.ValueKind==JsonValueKind.Object){var group=e.TryGetProperty("group",out var g)?g.GetString()??"Outros":"Outros";var browser=e.TryGetProperty("tab",out var t)&&t.GetString()=="browser";return("clean:"+group,browser?"Navegador · "+group:group);}return("clean:outros","Outros");}
 /// <summary>Área que contém o item: usada tanto para exibir quanto para corrigir, garantindo o mesmo escopo.</summary>
 public static string AreaOf(JsonElement catalog,Finding item)=>item.Kind=="clean-category"?CleanArea(catalog,item.Selector).Id:item.Module;
 private static EntrySummary Entry(string id,string label,IEnumerable<Finding> found){var items=found.DistinctBy(i=>i.Id).ToArray();var fixable=items.Where(i=>i.CanFix).Sum(Q);return new(id,label,items.Sum(Q),fixable,items.Where(i=>!i.CanFix&&i.Severity=="review").Sum(Q),items.Sum(i=>Math.Max(0,i.Bytes)));}
 private static AreaSummary Area(string id,string title,CheckResult module,EntrySummary[] entries)=>new(id,title,module.Id,entries.Sum(e=>e.Count),entries.Sum(e=>e.Fixable),entries.Sum(e=>e.Review),entries.Sum(e=>e.Count-e.Fixable-e.Review),entries.Sum(e=>e.Bytes),module.Complete,module.Notes.Take(30).Select(n=>n.Length>600?n[..600]:n).ToArray(),entries);
 private static AreaSummary[] Areas(ScanReport report,JsonElement catalog){
  var result=new List<AreaSummary>();var cleanCatalog=Section(catalog,"catalog");var registry=Section(catalog,"registry");
  var startupNames=new Dictionary<string,string>{["run"]="Entradas do Windows",["folder"]="Pastas de inicialização",["task"]="Tarefas agendadas",["service"]="Serviços",["context"]="Menu de contexto"};
  foreach(var module in report.Modules){
   var items=module.Items.DistinctBy(i=>i.Id).ToArray();
   switch(module.Id){
    case "clean":{
     var scanned=(module.Scanned??items.Select(i=>i.Selector).Distinct().ToArray()).ToHashSet();
     var ordered=cleanCatalog.ValueKind==JsonValueKind.Object?cleanCatalog.EnumerateObject().Select(p=>p.Name).Where(scanned.Contains).ToList():new List<string>();
     ordered.AddRange(scanned.Where(k=>!ordered.Contains(k)));
     foreach(var group in ordered.GroupBy(k=>CleanArea(catalog,k)))result.Add(Area(group.Key.Id,group.Key.Title,module,group.Select(k=>Entry(k,Label(cleanCatalog,k,k),items.Where(i=>i.Selector==k))).ToArray()));
     break;}
    case "registry":{
     var scanned=module.Scanned??items.Select(i=>i.Rule).Where(r=>r.Length>0).Distinct().ToArray();
     result.Add(Area("registry","Registros",module,scanned.Select(k=>Entry(k,Label(registry,k,k),items.Where(i=>i.Rule==k))).ToArray()));
     break;}
    case "startup":{
     var scanned=module.Scanned??items.Select(i=>i.Rule).Where(r=>r.Length>0).Distinct().ToArray();
     result.Add(Area("startup","Inicialização",module,scanned.Select(k=>Entry(k,startupNames.GetValueOrDefault(k,k),items.Where(i=>i.Rule==k))).ToArray()));
     break;}
    case "updates":{
     var scanned=module.Scanned??items.Select(i=>i.Kind=="windows-update"?"windows":"software").Distinct().ToArray();
     result.Add(Area("updates","Atualizações",module,scanned.Select(k=>Entry(k,k=="windows"?"Atualizações do Windows":"Atualizações de softwares",items.Where(i=>i.Kind==(k=="windows"?"windows-update":"software-update")))).ToArray()));
     break;}
    default:
     result.Add(Area(module.Id,module.Title,module,[Entry(module.Id,module.Title,items)]));break;
   }
  }
  return result.ToArray();
 }
 public static ReportSummary Summarize(ScanReport report,JsonElement catalog=default){var modules=report.Modules.Select(m=>{var items=m.Items.DistinctBy(i=>i.Id).ToArray();var fixable=items.Where(i=>i.CanFix).Sum(Q);var review=items.Where(i=>!i.CanFix&&i.Severity=="review").Sum(Q);return new ModuleSummary(m.Id,m.Title,items.Sum(Q),fixable,review,items.Sum(Q)-fixable-review,items.Sum(i=>Math.Max(0,i.Bytes)),m.Complete,m.Notes.Take(30).Select(n=>n.Length>600?n[..600]:n).ToArray(),items.GroupBy(i=>i.Kind=="clean-category"?i.Kind+"|"+i.Selector:i.Kind).Select(g=>new CategorySummary(g.First().Kind,g.Sum(Q),g.Where(i=>i.CanFix).Sum(Q),g.Sum(i=>Math.Max(0,i.Bytes)),g.First().Kind=="clean-category"?g.First().Title:null)).ToArray());}).ToArray();
  return new(report.Id,report.At,report.Mode,report.Cancelled,report.Seconds,modules.Sum(m=>m.Count),modules.Sum(m=>m.Fixable),modules.Sum(m=>m.Review),modules.Sum(m=>m.Informational),modules.Sum(m=>m.Bytes),!report.Cancelled&&modules.All(m=>m.Complete),modules,Areas(report,catalog));}
 /// <summary>IDs corrigíveis somente das áreas escolhidas no resultado; o motor nunca recebe caminhos da interface.</summary>
 public static string[] CorrectionIds(ScanReport report,string reportId,IReadOnlyCollection<string> areas,JsonElement catalog){
  if(report.Id!=reportId||report.Cancelled)throw new InvalidDataException("O resultado mudou ou foi interrompido. Faça uma nova análise.");
  var known=Areas(report,catalog).Select(a=>a.Id).ToHashSet();
  if(areas.Count==0||areas.Count>64||areas.Distinct().Count()!=areas.Count||areas.Any(id=>!known.Contains(id)))throw new InvalidDataException("Selecione áreas válidas do resultado.");
  var ids=report.Modules.SelectMany(m=>m.Items).Where(i=>i.CanFix&&areas.Contains(AreaOf(catalog,i))).Select(i=>i.Id).Distinct().ToArray();
  if(ids.Length==0)throw new InvalidDataException("Selecione ao menos uma área com ajustes corrigíveis.");return ids;}
}
