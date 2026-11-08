// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/CleanPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;

/// <summary>Limpeza de arquivos, independente das demais ferramentas de manutenção.</summary>
public sealed class CleanPage : MaintenancePage
{
    private readonly HashSet<string> _categories=new();
    private bool _initialized;
    private string _tab="junk",_signature="";
    private int _age=7;
    public CleanPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"clean","Limpeza de arquivos","Analise antes de excluir. As mesmas categorias e exclusões são compartilhadas com a plataforma."){}
    public void Review(IEnumerable<string> categories,int age,string tab){_categories.Clear();foreach(var key in categories)_categories.Add(key);_age=age;_tab=tab;_signature="";_initialized=true;Render();}
    private Dictionary<string,object?> Payload()=>new(){{"categories",_categories.OrderBy(x=>x).ToArray()},{"age_days",_age},{"exclusions",Setting("exclusions")},{"custom_folders",Setting("custom_folders")}};
    protected override void Build()
    {
        var catalog=Catalog.GetProperty("catalog");
        if(!_initialized){foreach(var item in catalog.EnumerateObject())if(Yes(item.Value,"default"))_categories.Add(item.Name);_initialized=true;}
        Choices(new[]{("junk","Windows e arquivos temporários"),("browser","Navegadores e aplicativos")},_tab,value=>_tab=value);
        var ages=Rows(Catalog,"ages").Select(x=>x.GetInt32()).ToArray();if(ages.Length==0)ages=new[]{0,1,7,30};
        Choices(ages.Select(d=>(d.ToString(),d==0?"Qualquer idade":"Mais de "+d+" dia(s)")).ToArray(),_age.ToString(),value=>{_age=int.Parse(value);_signature="";});
        Actions(Action("Analisar seleção",async()=>{await LoadSettingsAsync();var payload=Payload();_signature="";if(await RunAsync("scan",payload))_signature=JsonSerializer.Serialize(payload);},_categories.Count>0),
            Action("Limpar seleção analisada",async()=>{
                await LoadSettingsAsync();var payload=Payload();if(_signature!=JsonSerializer.Serialize(payload)){Status("Seleção ou configurações alteradas. Analise novamente antes de limpar.");return;}
                var labels=catalog.EnumerateObject().Where(x=>_categories.Contains(x.Name)).Select(x=>Get(x.Value,"group")+": "+Get(x.Value,"label"));
                if(await Confirm("Limpar arquivos","Excluir permanentemente as categorias abaixo?\n"+string.Join("\n",labels)+"\nCookies podem encerrar logins. Senhas selecionadas não são recuperáveis. Arquivos em uso são preservados.","Limpar")&&Runtime.Allowed){await RunAsync("execute",payload);_signature="";}
            },_categories.Count>0&&_signature.Length>0));
        if(Report.ValueKind==JsonValueKind.Object)Text(Get(Report,"file_count")+" itens · "+Ui.Size(Number(Report,"bytes"))+" · "+Get(Report,"errors")+" falhas",true);
        foreach(var group in catalog.EnumerateObject().Where(x=>_tab=="browser"?Get(x.Value,"tab") is "browser" or "apps":Get(x.Value,"tab") is "junk" or "windows").GroupBy(x=>Get(x.Value,"group")))
        {
            Text(group.Key,true);
            foreach(var item in group.Where(x=>Matches(Get(x.Value,"label"),Get(x.Value,"hint")))){
                var label=Get(item.Value,"label");var box=new CheckBox{Content=Ui.Label(label,P.Text,14,wrap:true),IsChecked=_categories.Contains(item.Name),IsEnabled=!Busy,MinHeight=30};ToolTipService.SetToolTip(box,Get(item.Value,"hint"));
                box.Click+=(_,_)=>{if(box.IsChecked==true)_categories.Add(item.Name);else _categories.Remove(item.Name);_signature="";Render();};View.Children.Add(box);
                var finding=Part(Part(Report,"categories"),item.Name);if(finding.ValueKind==JsonValueKind.Object)Text(Get(finding,"file_count")+" itens · "+Ui.Size(Number(finding,"bytes"))+" · "+Get(finding,"errors")+" falhas");
            }
        }
        Text(_categories.Count+" categorias selecionadas. Nenhuma categoria é adicionada automaticamente após a revisão.");
    }
}
