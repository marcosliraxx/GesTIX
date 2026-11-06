// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/SoftwareUpdatesPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class SoftwareUpdatesPage : MaintenancePage
{
    public SoftwareUpdatesPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"updater","Atualizações de aplicativos","Consulte versões instaladas e disponíveis pelo WinGet. Escolha explicitamente os pacotes; nenhuma atualização começa ao abrir a página."){}
    protected override void Build()
    {
        Actions(Action("Procurar atualizações",()=>RunToolAsync("list")),Action("Atualizar selecionados",()=>ApplySelectedAsync("apply","Instalar as atualizações selecionadas pelos instaladores oficiais do WinGet?",30),Rows(Report,"items").Length>0));
        Text(Rows(Report,"items").Length+" atualizações disponíveis",true);
        foreach(var item in Rows(Report,"items").OrderBy(x=>Get(x,"name")))Select(item,Get(item,"name"),Get(item,"id")+" · "+Get(item,"version")+" → "+Get(item,"available"));
    }
}
