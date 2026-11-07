// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/HealthPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
/// <summary>Fluxo integrado dos quatro domínios documentados do Health Check, com seleção e confirmação por etapa.</summary>
public sealed class HealthPage : MaintenancePage
{
    private JsonElement _analysis;private string _settingsSignature="",_lastAction="";private string _stage="privacy";private readonly Action<string> _open;
    public HealthPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm,Action<string> open,Action<IEnumerable<string>,int,string> review):base(runtime,confirm,"health","Saúde do computador","Privacidade, espaço, inicialização e atualizações de aplicativos suportados. Diagnosticar não altera arquivos; aplicar exige seleção e confirmação."){_open=open;}
    protected override void Build()
    {
        if(_lastAction.Length>0)Text(_lastAction,true);
        Actions(Action("Verificar saúde",async()=>{await LoadSettingsAsync();_settingsSignature=JsonSerializer.Serialize(Settings);if(await RunAsync("tool",new(){{"tool","health"},{"action","scan"},{"space",Rows(Part(Catalog,"health"),"space").Select(x=>x.ToString()).ToArray()},{"privacy",Rows(Part(Catalog,"health"),"privacy").Select(x=>x.ToString()).ToArray()},{"exclusions",Setting("exclusions")},{"custom_folders",Setting("custom_folders")}}))_analysis=Report;}));
        if(_analysis.ValueKind!=JsonValueKind.Object){Text("Inicie a verificação para revisar as quatro áreas. Registro, drivers e suspensão de processos têm ferramentas próprias.");return;}
        Choices(new[]{("privacy","1 · Mais privacidade"),("space","2 · Mais espaço"),("speed","3 · Inicialização"),("security","4 · Aplicativos desatualizados")},_stage,value=>_stage=value);
        if(_stage is "privacy" or "space"){
            var part=Part(_analysis,_stage);Text(Get(part,"file_count")+" itens encontrados · "+Ui.Size(Number(part,"bytes")),true);var categories=Part(part,"categories");
            if(categories.ValueKind==JsonValueKind.Object)foreach(var category in categories.EnumerateObject()){
                var item=JsonSerializer.SerializeToElement(new{id=category.Name});var definition=Part(Part(Catalog,"catalog"),category.Name);Select(item,Get(definition,"label"),Get(category.Value,"file_count")+" itens · "+Ui.Size(Number(category.Value,"bytes"))+" · "+Get(definition,"hint"));
            }
            Actions(Action("Aplicar limpeza selecionada",async()=>{
                if(Selected.Count==0){Status("Selecione as categorias que deseja limpar.");return;}var ids=Selected.OrderBy(x=>x).ToArray();
                if(!await Confirm("Revisar limpeza","Excluir permanentemente as categorias selecionadas? Cookies podem encerrar sessões. Arquivos em uso e exclusões serão respeitados.","Aplicar")||!Runtime.Allowed)return;
                await LoadSettingsAsync();if(_settingsSignature!=JsonSerializer.Serialize(Settings)){_analysis=default;Status("As configurações mudaram. Verifique a saúde novamente antes de limpar.");return;}await RunAsync("execute",new(){{"categories",ids},{"age_days",_stage=="privacy"?0:7},{"exclusions",Setting("exclusions")},{"custom_folders",Setting("custom_folders")}});
                _lastAction="Resultado informado: "+Get(Report,"file_count")+" itens · "+Ui.Size(Number(Report,"bytes"))+" · "+Get(Report,"errors")+" falhas";_analysis=default;
            }));
        }else if(_stage=="speed"){
            Text(Get(Part(_analysis,"speed"),"startup_enabled")+" entradas ativas; não há ganho de velocidade garantido.");foreach(var item in Rows(Part(_analysis,"speed"),"items").Where(x=>Yes(x,"enabled")))Select(item,Get(item,"name"),Get(item,"source")+" · "+Get(item,"command"));
            Actions(Action("Desativar selecionados",async()=>{if(Selected.Count is <1 or >100){Status("Selecione de 1 a 100 entradas.");return;}var ids=Selected.ToArray();if(await Confirm("Inicialização","Desativar somente as entradas escolhidas? Preserve antivírus, ferramentas corporativas, acessibilidade e backup. A operação pode ser revertida em Inicialização.","Desativar")&&Runtime.Allowed){await RunAsync("tool",new(){{"tool","startup"},{"action","apply"},{"operations",ids.Select(id=>new{id,op="disable"}).ToArray()}});_analysis=default;}}));
        }else{
            var security=Part(_analysis,"security");Text(Get(security,"message"));if(!Yes(security,"complete")){Text("Segurança não verificada. Não equivale a ausência de atualizações.");return;}
            Text(Get(security,"available")+" atualizações disponíveis",true);foreach(var item in Rows(security,"items"))Select(item,Get(item,"name"),Get(item,"version")+" → "+Get(item,"available"));
            Actions(Action("Atualizar aplicativos selecionados",async()=>{if(Selected.Count is <1 or >30){Status("Selecione de 1 a 30 aplicativos.");return;}var ids=Selected.ToArray();if(await Confirm("Atualizar aplicativos","Aplicar apenas os pacotes selecionados pelo WinGet?","Atualizar")&&Runtime.Allowed){await RunAsync("tool",new(){{"tool","updater"},{"action","apply"},{"ids",ids}});_analysis=default;}}));
        }
        Actions(Action("Configurar exclusões",()=>{_open("options");return Task.CompletedTask;}));
        Text("Após aplicar, execute nova verificação. Contagens são de itens elegíveis, não de rastreadores comprovados; a limpeza local não remove registros dos provedores.");
    }
}
