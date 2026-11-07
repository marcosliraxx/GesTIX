// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/HealthPage.cs
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Painel orquestrador: espaço, privacidade, registro e inicialização; otimização somente de arquivos.</summary>
public sealed class HealthPage : MaintenancePage
{
    private JsonElement _analysis;private string _settingsSignature="";
    private readonly Action<string> _open;private readonly Action<IEnumerable<string>,int,string> _review;
    private bool _space,_privacy;
    public HealthPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm,Action<string> open,Action<IEnumerable<string>,int,string> review):base(runtime,confirm,"health","Saúde do computador","Verifique sem alterar arquivos. Revise espaço, privacidade, registro e inicialização antes de aplicar qualquer ação."){_open=open;_review=review;}
    protected override void Build()
    {
        RestoreSnapshot();if(_analysis.ValueKind!=JsonValueKind.Object&&Report.ValueKind==JsonValueKind.Object)_analysis=Yes(Report,"optimization")?Part(Report,"analysis"):Report;
        Actions(Action("Verificar saúde",async()=>{
            await LoadSettingsAsync();_settingsSignature=JsonSerializer.Serialize(Settings);_space=_privacy=false;
            if(await RunAsync("tool",new(){{"tool","health"},{"action","scan"},{"space",Rows(Part(Catalog,"health"),"space").Select(x=>x.ToString()).ToArray()},{"privacy",Rows(Part(Catalog,"health"),"privacy").Select(x=>x.ToString()).ToArray()},{"exclusions",Setting("exclusions")},{"custom_folders",Setting("custom_folders")}}))_analysis=Report;else _settingsSignature="";
        }));
        if(_analysis.ValueKind!=JsonValueKind.Object){View.Children.Add(Ui.Card(Ui.Label("Seu computador merece atenção. Clique em Verificar saúde para obter uma análise real; nenhuma limpeza começa automaticamente.",P.Text,14,wrap:true),P,16));return;}
        Text("Resumo da última análise — sem alterações",true);
        if(Number(_analysis,"captured_at")>0)Text("Analisado em "+DateTimeOffset.FromUnixTimeSeconds((long)Number(_analysis,"captured_at")).ToLocalTime().ToString("dd/MM/yyyy HH:mm:ss"));
        Actions(Action("Sincronizar relatório salvo",async()=>{Status("Reenviando o relatório salvo, sem repetir operações.");await SaveSnapshotAsync(Report.Clone());if(SyncNotice.Length==0)Status("Relatório sincronizado sem repetir operações.");}));
        foreach(var (key,title,button,age,tab) in new[]{("space","Espaço recuperável","Revisar espaço",7,"junk"),("privacy","Privacidade","Revisar privacidade",0,"browser")}){
            var part=Part(_analysis,key);if(!Matches(title))continue;var card=new StackPanel{Spacing=6};card.Children.Add(Ui.Label(title,P.Text,16,true));card.Children.Add(Ui.Label(Ui.Size(Number(part,"bytes"))+" · "+Get(part,"file_count")+" itens · "+Get(part,"errors")+" falhas",P.Muted,13,wrap:true));
            card.Children.Add(Action(button,()=>{var cats=Part(part,"categories");_review(cats.ValueKind==JsonValueKind.Object?cats.EnumerateObject().Select(x=>x.Name):Array.Empty<string>(),age,tab);return Task.CompletedTask;}));
            var include=new CheckBox{Content="Incluir "+title.ToLowerInvariant()+" na otimização",IsChecked=key=="space"?_space:_privacy,IsEnabled=!Busy&&_settingsSignature.Length>0};include.Click+=(_,_)=>{if(key=="space")_space=include.IsChecked==true;else _privacy=include.IsChecked==true;Render();};card.Children.Add(include);View.Children.Add(Ui.Card(card,P,12));
        }
        var registry=Part(_analysis,"registry");Summary("Registro",Part(registry,"complete").ValueKind==JsonValueKind.False?"Consulta não confirmada: "+Get(registry,"message"):Get(registry,"issues")+" problemas encontrados", "Revisar registro","registry");
        var startup=Part(_analysis,"speed");Summary("Inicialização",Get(startup,"startup_enabled")+" de "+Get(startup,"startup_total")+" entradas ativas","Gerenciar inicialização","startup");
        Actions(Action("Ver aplicativos em segundo plano",()=>{_open("performance");return Task.CompletedTask;}),Action("Otimizar agora",OptimizeAsync,_settingsSignature.Length>0&&(_space||_privacy)));
        if(_settingsSignature.Length==0)Text("Relatório histórico ou configuração alterada. Faça nova verificação antes de otimizar.");
        if(Yes(Report,"optimization")){
            Text(Yes(Report,"incomplete")?"Operação parcial":"Resultado da otimização",true);Text(Get(Report,"removed")+" itens removidos confirmados · "+Ui.Size(Number(Report,"freed_bytes"))+" em arquivos removidos · "+Get(Report,"errors")+" falhas confirmadas");
            if(Part(Report,"free_space_delta").ValueKind==JsonValueKind.Number)Text("Variação observada de espaço livre na unidade do sistema: "+Get(Report,"free_space_delta")+" bytes. Outras atividades do computador podem influenciar este valor.");
            foreach(var issue in Rows(Report,"sync_errors"))Text(issue.ToString());
            Text(Yes(Report,"reboot")?"Há exigência de reinício; nenhum reinício foi iniciado.":"Reinício não solicitado por esta otimização.");
        }
    }
    private void Summary(string title,string value,string action,string route)
    {
        if(!Matches(title,value))return;var card=new StackPanel{Spacing=6};card.Children.Add(Ui.Label(title,P.Text,16,true));card.Children.Add(Ui.Label(value,P.Muted,13,wrap:true));card.Children.Add(Action(action,()=>{_open(route);return Task.CompletedTask;}));View.Children.Add(Ui.Card(card,P,12));
    }
    private static long? FreeSpace(){try{return new DriveInfo(Path.GetPathRoot(Environment.SystemDirectory)!).AvailableFreeSpace;}catch(Exception ex) when(ex is IOException or UnauthorizedAccessException or ArgumentException){return null;}}
    private async Task OptimizeAsync()
    {
        if(!_space&&!_privacy)return;
        if(!await Confirm("Otimizar agora","Executar somente os grupos de espaço e privacidade marcados? Temporários, caches e rastros selecionados serão excluídos permanentemente. Cookies podem encerrar logins. Arquivos em uso e exclusões serão respeitados. Problemas de registro não serão corrigidos e entradas de inicialização não serão alteradas. Cancelar não desfaz alterações já aplicadas.","Otimizar")||!Runtime.Allowed)return;
        await LoadSettingsAsync();if(_settingsSignature!=JsonSerializer.Serialize(Settings)){_settingsSignature="";Status("As configurações mudaram. Verifique a saúde novamente.");return;}
        var reports=new Dictionary<string,JsonElement>();var completed=new List<string>();var syncErrors=new List<string>();var incomplete=false;var before=FreeSpace();
        try{foreach(var (key,age,enabled) in new[]{("space",7,_space),("privacy",0,_privacy)}){
            if(!enabled)continue;if(!Runtime.Allowed){incomplete=true;break;}
            await LoadSettingsAsync();if(_settingsSignature!=JsonSerializer.Serialize(Settings)){incomplete=true;syncErrors.Add("Configurações alteradas entre etapas; nenhuma outra etapa foi iniciada.");break;}
            var categories=Part(Part(_analysis,key),"categories");var ids=categories.ValueKind==JsonValueKind.Object?categories.EnumerateObject().Select(x=>x.Name).ToArray():Array.Empty<string>();if(ids.Length==0)continue;
            if(!await RunAsync("execute",new(){{"categories",ids},{"age_days",age},{"exclusions",Setting("exclusions")},{"custom_folders",Setting("custom_folders")}})){incomplete=true;break;}
            reports[key]=Report.Clone();if(SyncNotice.Length>0)syncErrors.Add(key+": "+SyncNotice);completed.Add(key);incomplete|=CleanEngine.HasFailures(Report);if(CancellationRequested){incomplete=true;break;}
        }
        }catch(Exception ex){incomplete=true;syncErrors.Add("Etapas restantes interrompidas: "+ex.Message);}
        var after=FreeSpace();_settingsSignature="";_space=_privacy=false;
        var result=new{tool="health",optimization=true,analysis=_analysis,space=reports.TryGetValue("space",out var space)?(JsonElement?)space:null,privacy=reports.TryGetValue("privacy",out var privacy)?(JsonElement?)privacy:null,speed=Part(_analysis,"speed"),registry=Part(_analysis,"registry"),removed=reports.Values.Sum(x=>Number(x,"file_count")),freed_bytes=reports.Values.Sum(x=>Number(x,"bytes")),errors=reports.Values.Sum(x=>Number(x,"errors")),incomplete,sync_errors=syncErrors,steps=completed,captured_at=DateTimeOffset.Now.ToUnixTimeSeconds(),free_space_delta=before.HasValue&&after.HasValue?(long?)(after.Value-before.Value):null,reboot=false,message=incomplete?"Operação parcial. Totais incluem somente etapas confirmadas; uma etapa interrompida pode ter aplicado alterações não contabilizadas.":"Otimização concluída somente em espaço e privacidade. Registro e inicialização preservados."};
        Status(result.message+(syncErrors.Count>0?" "+string.Join("; ",syncErrors):""));await SaveSnapshotAsync(result);
    }
}
