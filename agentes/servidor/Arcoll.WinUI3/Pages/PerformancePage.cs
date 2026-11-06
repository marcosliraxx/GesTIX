// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/PerformancePage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class PerformancePage : MaintenancePage
{
    private string _state="active";
    public PerformancePage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"performance","Desempenho de aplicativos","Veja memória e processos. Suspenda ou retome somente aplicativos selecionados; componentes protegidos continuam excluídos pelo motor."){}
    protected override void Build()
    {
        Choices(new[]{("active","Aplicativos ativos"),("suspended","Aplicativos suspensos")},_state,value=>_state=value);
        Actions(Action("Atualizar processos",()=>RunToolAsync("list")),Action(_state=="active"?"Suspender selecionados":"Retomar selecionados",()=>ApplySelectedAsync(_state=="active"?"suspend":"resume","Alterar os aplicativos selecionados? A suspensão pode pausar atividades em segundo plano.",40)));
        var rows=Rows(Report,_state);Text(rows.Length+" aplicativos · "+Ui.Size(rows.Sum(x=>Number(x,"memory")))+" de memória informada",true);
        foreach(var item in rows.OrderByDescending(x=>Number(x,"memory")))Select(item,Get(item,"name"),Get(item,"publisher")+" · "+Ui.Size(Number(item,"memory"))+" · "+Get(item,"path"));
    }
}
