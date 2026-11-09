// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/DriversPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class DriversPage : MaintenancePage
{
    private string _tab="outdated";
    public DriversPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"drivers","Drivers e dispositivos","Inventário dos drivers e atualizações oferecidas pelo Windows Update. Instalação somente após seleção e confirmação."){}
    protected override void Build()
    {
        Choices(new[]{("outdated","Atualizações disponíveis"),("current","Drivers instalados")},_tab,value=>_tab=value);
        Actions(Action("Verificar drivers",()=>RunToolAsync("scan")),Action("Instalar drivers selecionados",()=>ApplySelectedAsync("install","Instalar os drivers selecionados pelo Windows Update? Pode ser necessário reiniciar depois.",20),_tab=="outdated"&&Rows(Report,"outdated").Length>0));
        Metrics(("Drivers instalados",Rows(Report,"current").Length.ToString(),"Inventário local"),
            ("Atualizações",Rows(Report,"outdated").Length.ToString(),"Ofertas do Windows Update"),
            ("Selecionados",Selected.Count.ToString(),"Limite de 20 por instalação"));
        if(Report.ValueKind!=System.Text.Json.JsonValueKind.Object)Text("Verifique os drivers para consultar o estado real do computador.");
        foreach(var item in Rows(Report,_tab).OrderBy(x=>Get(x,"group")).ThenBy(x=>Get(x,"name")))Select(item,Get(item,"name"),Get(item,"group")+" · "+Get(item,"version")+" · "+Get(item,"manufacturer")+" · "+Get(item,"date"),_tab=="outdated");
    }
}
