// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/DuplicatesPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
public sealed class DuplicatesPage : MaintenancePage
{
    private string _origin="users",_minimum="1024";
    public DuplicatesPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"duplicates","Arquivos duplicados","Comparação por tamanho e conteúdo. Exclusão pela Lixeira, preservando ao menos uma cópia por grupo."){}
    protected override void Build()
    {
        var origins=new List<(string,string)>{("users","Pastas pessoais dos perfis")};foreach(var drive in DriveInfo.GetDrives().Where(x=>x.DriveType==DriveType.Fixed))origins.Add((drive.Name.TrimEnd('\\'),"Unidade "+drive.Name));
        Choices(origins.ToArray(),_origin,value=>_origin=value);Choices(new[]{("100","A partir de 100 KB"),("1024","A partir de 1 MB"),("10240","A partir de 10 MB"),("102400","A partir de 100 MB")},_minimum,value=>_minimum=value);
        Actions(CollectAction("Pesquisar duplicados",()=>RunToolAsync("scan",("roots",new[]{_origin}),("min_size_kb",int.Parse(_minimum)))),Action("Enviar selecionados à Lixeira",async()=>{
            foreach(var group in Rows(Report,"groups")){var files=Rows(group,"files");if(files.Length>0&&files.All(f=>Selected.Contains(Get(f,"id")))){Status("Mantenha pelo menos uma cópia em cada grupo.");return;}}
            await ApplySelectedAsync("delete","Enviar somente as cópias selecionadas à Lixeira?",500,("permanent",false));
        },Rows(Report,"groups").Length>0));
        var groups=Rows(Report,"groups");
        var copies=groups.Sum(g=>Math.Max(0,Rows(g,"files").Length-1));
        var reclaimable=groups.Sum(g=>Math.Max(0,Rows(g,"files").Length-1)*Number(g,"size"));
        Metrics(("Grupos",groups.Length.ToString(),"Conjuntos com conteúdo igual"),
            ("Cópias extras",copies.ToString(),"Mantendo ao menos uma por grupo"),
            ("Potencial",Ui.Size(reclaimable),"Estimativa, não espaço liberado"),
            ("Selecionados",Selected.Count.ToString(),"Envio confirmado à Lixeira"));
        foreach(var group in groups){var files=Rows(group,"files");if(!files.Any(f=>Matches(Get(f,"path"))))continue;Text(files.Length+" cópias · "+Ui.Size(Number(group,"size")),true);foreach(var file in files.Skip(1))Select(file,Get(file,"path"));
            if(files.Length>0)Text("Cópia de referência preservada: "+Get(files[0],"path"));}
    }
}
