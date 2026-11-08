// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/StartupPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class StartupPage : MaintenancePage
{
    private string _kind="run",_state="all";
    public StartupPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"startup","Inicialização do Windows","Gerencie entradas do Windows, pastas de inicialização, tarefas, serviços e menu de contexto. Somente itens selecionados são alterados."){}
    protected override void Build()
    {
        Choices(new[]{("run","Windows e pastas"),("task","Tarefas agendadas"),("context","Menu de contexto"),("service","Serviços")},_kind,value=>_kind=value);
        Choices(new[]{("all","Todos os estados"),("enabled","Ativos"),("disabled","Desativados")},_state,value=>_state=value);
        Actions(Action("Atualizar inventário",()=>RunToolAsync("list")),Action("Ativar selecionados",()=>Apply(true)),Action("Desativar selecionados",()=>Apply(false)));
        var rows=Rows(Report,"items");Text(rows.Length+" entradas inventariadas",true);
        foreach(var item in rows.Where(x=>(Get(x,"kind")==_kind||(_kind=="run"&&Get(x,"kind")=="folder"))&&(_state=="all"||Yes(x,"enabled")==(_state=="enabled"))).OrderBy(x=>Get(x,"name")))Select(item,Get(item,"name"),Get(item,"source")+" · "+(Yes(item,"enabled")?"Ativo":"Desativado")+" · "+Get(item,"command"));
    }
    private async Task Apply(bool enabled)
    {
        if(Selected.Count<1||Selected.Count>100){Status("Selecione de 1 a 100 entradas.");return;}var ids=Selected.ToArray();
        if(await Confirm("Inicialização",(enabled?"Ativar":"Desativar")+" "+ids.Length+" entradas? Serviços e tarefas podem afetar o funcionamento de aplicativos.","Aplicar")&&Runtime.Allowed)await RunToolAsync("apply",("operations",ids.Select(id=>new Dictionary<string,object?>{{"id",id},{"op",enabled?"enable":"disable"}}).ToArray()));
    }
}
