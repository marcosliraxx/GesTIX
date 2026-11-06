// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/HealthPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class HealthPage : MaintenancePage
{
    private readonly Action<string> _open;
    private readonly Action<IEnumerable<string>,int,string> _review;
    public HealthPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm,Action<string> open,Action<IEnumerable<string>,int,string> review):base(runtime,confirm,"health","Saúde do computador","Diagnóstico de espaço, privacidade, registro e inicialização. Verificar não exclui arquivos."){_open=open;_review=review;}
    protected override void Build()
    {
        Actions(Action("Verificar saúde",()=>RunToolAsync("scan",("space",Rows(Catalog.GetProperty("health"),"space").Select(x=>x.ToString()).ToArray()),("privacy",Rows(Catalog.GetProperty("health"),"privacy").Select(x=>x.ToString()).ToArray()))));
        if(Report.ValueKind!=System.Text.Json.JsonValueKind.Object){Text("Inicie uma verificação para obter recomendações reais deste computador.");return;}
        foreach(var (key,title) in new[]{("space","Espaço recuperável"),("privacy","Privacidade")}){
            var part=Part(Report,key);Text(title+": "+Ui.Size(Number(part,"bytes"))+" · "+Get(part,"file_count")+" itens",true);
            Actions(Action("Revisar "+title.ToLowerInvariant(),()=>{var categories=Part(part,"categories");_review(categories.ValueKind==System.Text.Json.JsonValueKind.Object?categories.EnumerateObject().Select(x=>x.Name):Array.Empty<string>(),key=="privacy"?0:7,key=="privacy"?"browser":"junk");return Task.CompletedTask;}));
        }
        Text(Get(Part(Report,"registry"),"issues")+" problemas de registro · "+Get(Part(Report,"speed"),"startup_enabled")+" itens ativos na inicialização");
        foreach(var (route,label) in new[]{("registry","Revisar registro"),("startup","Gerenciar inicialização"),("performance","Ver desempenho")})Actions(Action(label,()=>{_open(route);return Task.CompletedTask;}));
    }
}
