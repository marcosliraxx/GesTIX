// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/CloudBackupPage.cs
using System.Diagnostics;
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class CloudBackupPage : MaintenancePage
{
    public CloudBackupPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"backup","Backup em nuvem","Estado, pastas, conexão e execução do backup da rede. Autenticação do provedor e escolha de destino permanecem no fluxo oficial da plataforma."){}
    protected override void Build()
    {
        Actions(CollectAction("Atualizar estado do backup",()=>RunAsync("backup_status",new())),Action("Executar backup configurado",async()=>{if(await Confirm("Executar backup","Enviar as pastas configuradas ao destino conectado na plataforma?","Executar")&&Runtime.Allowed)await RunAsync("backup_run",new());}),Action("Configurar conexão, pastas e destino",async()=>{var info=await LoadSettingsAsync();var origin=Runtime.Platform.Config.Origin;if(!Uri.TryCreate(origin,UriKind.Absolute,out var url)||url.Scheme!="https")throw new InvalidOperationException("Origem HTTPS inválida.");Process.Start(new ProcessStartInfo(origin+"/redes/clean.php?network="+info.GetProperty("network_id").GetInt64()+"&gestor="+info.GetProperty("gestor_id").GetInt64()+"&section=backup"){UseShellExecute=true});}));
        var job=Part(Report,"job");var settings=Part(job,"settings");var status=Part(Report,"status");
        if(Report.ValueKind!=System.Text.Json.JsonValueKind.Object){Text("Atualize o estado para consultar a configuração real.");return;}
        Metrics(("Backup",Yes(job,"available")?"Disponível":"Indisponível","Configuração da rede"),
            ("Concluídos",Get(status,"files_done")+" / "+Get(status,"files_total"),"Arquivos enviados"),
            ("Transferidos",Ui.Size(Number(status,"bytes_done")),"De "+Ui.Size(Number(status,"bytes_total"))),
            ("Falhas",Get(status,"errors"),"Arquivos com erro"));
        Text(Yes(job,"available")?"Backup disponível":"Backup indisponível ou desativado",true);
        var connection=Part(Report,"connection");if(connection.ValueKind==System.Text.Json.JsonValueKind.Object)Text("Provedor: "+Get(connection,"provider")+" · "+Get(connection,"status"));
        Text("Estado: "+Get(status,"message"));Text("Último sucesso: "+Get(status,"last_success_at"));
        Text(Get(status,"files_done")+" / "+Get(status,"files_total")+" arquivos · "+Ui.Size(Number(status,"bytes_done"))+" / "+Ui.Size(Number(status,"bytes_total")));
        Text(Get(status,"errors")+" erros · "+Get(status,"skipped")+" somente online ignorados");
        if(Get(status,"reported_at").Length>0)Text("Informado em "+Get(status,"reported_at")+(Yes(status,"fresh")?"":" · Estado sem atualização recente"));
        foreach(var failure in Rows(status,"failures"))Text(Get(failure,"path")+" · "+Get(failure,"message"));
        Text("Últimas execuções",true);foreach(var run in Rows(status,"runs"))Text(Get(run,"at")+" · "+Get(run,"result")+" · "+Get(run,"files")+" arquivos · "+Ui.Size(Number(run,"bytes"))+" · "+Get(run,"errors")+" erros · "+Get(run,"seconds")+" s");
        Text("Pastas configuradas",true);foreach(var folder in Rows(settings,"folders"))if(Matches(Get(folder,"label"),Get(folder,"path")))Text(Get(folder,"label")+" · "+Get(folder,"path"));
        if(Rows(settings,"folders").Length==0)Text("Nenhuma pasta definida. Use a configuração da plataforma.");
    }
}
