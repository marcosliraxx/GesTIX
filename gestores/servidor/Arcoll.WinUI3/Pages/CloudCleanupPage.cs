// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/CloudCleanupPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class CloudCleanupPage : MaintenancePage
{
    private string _provider="all",_category="all";
    public CloudCleanupPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"cloud","Pastas sincronizadas","Analise arquivos locais de provedores de nuvem. Excluir uma cópia sincronizada pode removê-la também nos demais dispositivos."){}
    protected override void Build()
    {
        Actions(CollectAction("Analisar pastas sincronizadas",()=>RunToolAsync("scan")),Action("Enviar selecionados à Lixeira",()=>ApplySelectedAsync("delete","Enviar os arquivos selecionados à Lixeira? O provedor poderá sincronizar a exclusão para a nuvem e outros computadores.",300),Rows(Report,"items").Length>0));
        var providers=new List<(string,string)>{("all","Todos os provedores")};foreach(var p in Rows(Report,"providers")){providers.Add((Get(p,"key"),Get(p,"name")));Text(Get(p,"name")+" · "+Get(p,"files")+" arquivos · "+Ui.Size(Number(p,"bytes")));}
        Choices(providers.DistinctBy(x=>x.Item1).ToArray(),_provider,value=>_provider=value);
        Choices(new[]{("all","Todas as categorias"),("junk","Temporários"),("duplicate","Duplicados"),("large","Arquivos grandes"),("old","Arquivos antigos")},_category,value=>_category=value);
        var items=Rows(Report,"items").Where(x=>(_provider=="all"||Get(x,"provider")==_provider)&&(_category=="all"||Get(x,"cat")==_category)).DistinctBy(x=>Get(x,"id")).ToArray();
        Metrics(("Arquivos no filtro",items.Length.ToString(),"Provedores sincronizados"),
            ("Tamanho",Ui.Size(items.Sum(x=>Number(x,"bytes"))),"Soma dos itens exibidos"),
            ("Cópias locais",items.Count(x=>Yes(x,"local")).ToString(),"Dados disponíveis neste PC"),
            ("Selecionados",Selected.Count.ToString(),"Excluir pode afetar a nuvem"));
        Text("Atenção: arquivos apagados em pastas sincronizadas podem desaparecer também de outros dispositivos.");
        foreach(var item in items.OrderByDescending(x=>Number(x,"bytes")))Select(item,Get(item,"path"),Get(item,"provider")+" · "+Get(item,"cat")+" · "+Ui.Size(Number(item,"bytes"))+" · "+(Yes(item,"local")?"Disponível localmente":"Somente na nuvem"),Yes(item,"local"));
    }
}
