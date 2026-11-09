// Catálogo único da limpeza e dos diagnósticos integrados. Somente o host confirma mutações.
using System.Text.Json;
using Arcoll.WinUI3.Services.PenteFino;
namespace Arcoll.WinUI3.Services;
public sealed class GeneralAnalysisEngine(GestorConfig config,AnalysisPreferences preferences,string[] exclusions,string[]? customFolders=null)
{
 public static AnalysisPreferences Defaults(JsonElement catalog)=>new(catalog.GetProperty("catalog").EnumerateObject().Where(p=>p.Name!="custom_folders"&&p.Value.GetProperty("default").GetBoolean()).Select(p=>p.Name).ToArray(),catalog.GetProperty("registry").EnumerateObject().Select(p=>p.Name).ToArray(),["run","folder","task","service","context"],0,true,true);
 public static AnalysisPreferences Validate(AnalysisPreferences value,JsonElement catalog){
  var categories=catalog.GetProperty("catalog").EnumerateObject().Select(p=>p.Name).ToHashSet();
  var registry=catalog.GetProperty("registry").EnumerateObject().Select(p=>p.Name).ToHashSet();
  if(value.Categories is null||value.RegistryChecks is null||value.StartupKinds is null||value.Categories.Length>categories.Count||value.Categories.Distinct().Count()!=value.Categories.Length||value.Categories.Any(p=>!categories.Contains(p))||value.RegistryChecks.Any(p=>!registry.Contains(p))||value.RegistryChecks.Distinct().Count()!=value.RegistryChecks.Length||value.StartupKinds.Any(p=>!new[]{"run","folder","task","service","context"}.Contains(p))||value.StartupKinds.Distinct().Count()!=value.StartupKinds.Length||!new[]{0,7,15,30,180,365}.Contains(value.PeriodDays))throw new InvalidDataException("Configurações da análise inválidas.");return value;
 }
 public static string[] Modules(AnalysisPreferences p)=>new[]{p.Categories.Length>0?"clean":"",p.RegistryChecks.Length>0?"registry":"",p.StartupKinds.Length>0?"startup":"",p.SoftwareUpdates||p.WindowsUpdates?"updates":""}.Where(s=>s.Length>0).ToArray();
 private object CleanPayload(string[] categories)=>new{categories,age_days=0,period_days=preferences.PeriodDays,exclusions,custom_folders=customFolders??Array.Empty<string>()};
 private Task<JsonElement> Tool(string name,string action,CancellationToken ct,params (string Key,object? Value)[] extra){var body=new Dictionary<string,object?>{{"tool",name},{"action",action},{"recovery_copies",false}};foreach(var pair in extra)body[pair.Key]=pair.Value;return CleanEngine.RunAsync("tool",body,config,null,ct);}
 public IChecker[] Checkers()=>[new Reader("clean","Arquivos e dados",ScanClean),new Reader("registry","Registros",ScanRegistry),new Reader("startup","Inicialização",ScanStartup),new Reader("updates","Atualizações",ScanUpdates)];
 private async Task<CheckResult> ScanClean(ScanContext context,CancellationToken ct){
  var progress=new Progress<string>(text=>context.Progress?.Report(new("clean","running",0,text)));
  var data=await CleanEngine.RunAsync("scan",CleanPayload(preferences.Categories),config,progress,ct);var catalog=CleanEngine.Catalog().GetProperty("catalog");var items=new List<Finding>();var notes=new List<string>{"O período limita a data de modificação dos arquivos. Inventários e ações sem data representam o estado atual."};
  foreach(var row in data.GetProperty("categories").EnumerateObject()){
   var quantity=Number(row.Value,"file_count");var action=row.Value.TryGetProperty("action",out var flag)&&flag.ValueKind==JsonValueKind.True;var errors=Number(row.Value,"errors");if(errors>0)notes.Add(row.Name+": "+errors+" itens inacessíveis ou em uso.");if(quantity==0&&!action)continue;var label=catalog.GetProperty(row.Name).GetProperty("label").GetString()??row.Name;
   items.Add(new("clean-category:"+row.Name,"clean",label,"",Long(row.Value,"bytes"),"Categoria escolhida nas configurações.","Corrigir categoria selecionada",true,"clean-category",Selector:row.Name,Quantity:Math.Max(1,quantity)));
  }
  return new("clean","Arquivos e dados",items,notes,!CleanEngine.HasFailures(data));
 }
 private async Task<CheckResult> ScanRegistry(ScanContext context,CancellationToken ct){var data=await Tool("registry","scan",ct,("checks",preferences.RegistryChecks));var items=new List<Finding>();foreach(var row in Rows(data,"issues")){var id=Text(row,"id");if(id.Length==0)continue;items.Add(new("tool-registry:"+id,"registry",Text(row,"detail"),"",0,"Referência identificada pelo verificador selecionado.","Revalidar e corrigir referência selecionada",true,"tool-registry",Selector:id));}return new("registry","Registros",items,["Somente verificações marcadas nas configurações. Alvos são reanalisados antes da correção.",items.Count>=1500?"Limite de 1.500 referências atingido; verificação parcial.":"Verificações selecionadas concluídas."],items.Count<1500&&!CleanEngine.HasFailures(data));}
 private async Task<CheckResult> ScanStartup(ScanContext context,CancellationToken ct){var data=await Tool("startup","list",ct);var items=Rows(data,"items").Where(row=>preferences.StartupKinds.Contains(Text(row,"kind"))).Select(row=>new Finding("tool-startup:"+Text(row,"id"),"startup",Text(row,"name"),"",0,Text(row,"source"),"Revisão individual; não desativar todos automaticamente",false,"startup-entry",Selector:Text(row,"id"),Severity:"review")).ToList();return new("startup","Inicialização",items,["Itens inventariados não são falhas. Serviços, tarefas e programas exigem revisão individual antes de ativar ou desativar.",Rows(data,"items").Length>=700?"Inventário limitado a 700 entradas; consulta parcial.":"Tipos selecionados consultados."],Rows(data,"items").Length<700&&!CleanEngine.HasFailures(data));}
 private async Task<CheckResult> ScanUpdates(ScanContext context,CancellationToken ct){var items=new List<Finding>();var notes=new List<string>();var complete=true;
  if(preferences.SoftwareUpdates){try{var data=await Tool("updater","list",ct);foreach(var row in Rows(data,"items"))items.Add(new("software-update:"+Text(row,"id"),"updates",Text(row,"name"),"",0,"Atualização de software disponível.","Revisar atualização",false,"software-update",Severity:"review"));complete&=!CleanEngine.HasFailures(data)&&Rows(data,"items").Length<200;if(Rows(data,"items").Length>=200)notes.Add("Softwares: limite de 200 atualizações; consulta parcial.");}catch(Exception error)when(error is not OperationCanceledException){complete=false;notes.Add("Softwares: "+error.Message);}}
  if(preferences.WindowsUpdates){try{var data=JsonSerializer.SerializeToElement(await WindowsUpdateService.RunAsync("{\"mode\":\"scan\"}",ct));foreach(var row in Rows(data,"updates"))items.Add(new("windows-update:"+Text(row,"id"),"updates",Text(row,"title"),"",0,"Atualização do Windows disponível.","Revisar atualização",false,"windows-update",Severity:"review"));complete&=!CleanEngine.HasFailures(data)&&Long(data,"total")<=50;if(Long(data,"total")>50)notes.Add("Windows Update: exibidas as primeiras 50 atualizações; inventário parcial.");}catch(Exception error)when(error is not OperationCanceledException){complete=false;notes.Add("Windows Update: "+error.Message);}}
  notes.Add("Consulta somente leitura; instalação e reinicialização exigem ações explícitas nas ferramentas de atualização.");return new("updates","Atualizações",items,notes,complete);
 }
 public async Task<List<ActionResult>> FixAsync(Finding[] items,CancellationToken ct){
  if(items[0].Kind=="clean-category"){
   if(items.Any(i=>!preferences.Categories.Contains(i.Selector)))throw new InvalidDataException("Categoria fora das preferências analisadas.");
   var categories=items.Select(i=>i.Selector).Distinct().ToArray();var check=await CleanEngine.RunAsync("scan",CleanPayload(categories),config,null,ct);
   foreach(var item in items){var current=check.GetProperty("categories").GetProperty(item.Selector);if(Number(current,"file_count")!=item.Quantity&&!(item.Quantity==1&&Number(current,"file_count")==0&&Bool(current,"action"))||Long(current,"bytes")!=item.Bytes)throw new InvalidOperationException("Os dados mudaram. Faça nova análise antes de corrigir.");}
   ct.ThrowIfCancellationRequested();var result=await CleanEngine.RunAsync("execute",CleanPayload(categories),config,null,ct);
   return items.Select(i=>{var row=result.GetProperty("categories").GetProperty(i.Selector);var errors=Number(row,"errors");return new ActionResult(i.Id,errors==0&&!Bool(row,"truncated"),"Categoria processada: "+Number(row,"file_count")+" itens; "+errors+" falhas. Sem cópia de recuperação.");}).ToList();
  }
  if(items[0].Kind=="tool-registry"){var result=await Tool("registry","fix",ct,("checks",preferences.RegistryChecks),("ids",items.Select(i=>i.Selector).ToArray()));var failed=Number(result,"failed");var complete=failed==0&&Number(result,"fixed")==items.Length;return items.Select(i=>new ActionResult(i.Id,complete,complete?"Referências revalidadas e corrigidas sem cópia.":"O motor confirmou "+Number(result,"fixed")+" correções e "+failed+" falhas; reanalise para conferir o estado atual.")).ToList();}
  throw new InvalidDataException("Ação de análise não suportada.");
 }
 private static string Text(JsonElement e,string key)=>e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.String?p.GetString()??"":"";
 private static int Number(JsonElement e,string key)=>(int)Math.Clamp(Long(e,key),0,int.MaxValue);
 private static long Long(JsonElement e,string key)=>e.TryGetProperty(key,out var p)&&p.TryGetInt64(out var n)?n:0;
 private static bool Bool(JsonElement e,string key)=>e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.True;
 private static JsonElement[] Rows(JsonElement e,string key)=>e.TryGetProperty(key,out var p)&&p.ValueKind==JsonValueKind.Array?p.EnumerateArray().ToArray():[];
 private sealed class Reader(string id,string title,Func<ScanContext,CancellationToken,Task<CheckResult>> run):IChecker{public string Id=>id;public string Title=>title;public Task<CheckResult> ScanAsync(ScanContext context,CancellationToken ct)=>run(context,ct);}
}
