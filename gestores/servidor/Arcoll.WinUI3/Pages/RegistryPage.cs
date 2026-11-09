// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/RegistryPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
public sealed class RegistryPage : MaintenancePage
{
    private readonly HashSet<string> _checks=new();private bool _initialized;
    public RegistryPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"registry","Registro do Windows","Verificações selecionáveis, revisão por chave e backup antes de corrigir. Não é uma limpeza automática do registro."){}
    protected override void Build()
    {
        var checks=Catalog.GetProperty("registry").EnumerateObject().ToArray();if(!_initialized){foreach(var c in checks)_checks.Add(c.Name);_initialized=true;}
        Text("Verificações",true);
        foreach(var c in checks){var box=new CheckBox{Content=c.Value.ValueKind==System.Text.Json.JsonValueKind.Array?c.Value[0].ToString():c.Value.ToString(),IsChecked=_checks.Contains(c.Name),IsEnabled=!Busy};box.Click+=(_,_)=>{if(box.IsChecked==true)_checks.Add(c.Name);else _checks.Remove(c.Name);Selected.Clear();Render();};View.Children.Add(box);}
        Actions(Action("Analisar registro",()=>RunToolAsync("scan",("checks",_checks.OrderBy(x=>x).ToArray())),_checks.Count>0),Action("Corrigir selecionados",()=>ApplySelectedAsync("fix","Corrigir os problemas selecionados? O motor fará backup antes de alterar o registro.",1500,("checks",Rows(Report,"checks").Select(x=>x.ToString()).ToArray())),Rows(Report,"issues").Length>0));
        Text(Rows(Report,"issues").Length+" problemas no relatório",true);
        foreach(var issue in Rows(Report,"issues"))Select(issue,Get(issue,"detail"),Get(issue,"key")+" · "+Get(issue,"check"));
        Text("Backups disponíveis",true);
        foreach(var backup in Rows(Report,"backups")){var stamp=backup.ToString();Actions(Action("Restaurar "+stamp,async()=>{if(await Confirm("Restaurar registro","Restaurar o backup "+stamp+"?","Restaurar")&&Runtime.Allowed)await RunToolAsync("restore",("backup",stamp));}));}
    }
}
