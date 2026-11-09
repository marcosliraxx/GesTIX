// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/SecurityPage.cs
// Etapa 2 — Central de Segurança local do Windows. Consultas somente leitura.
using System.Diagnostics;
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;

public sealed class SecurityPage : MaintenancePage
{
    public SecurityPage(GestorRuntime runtime, Func<string,string,string,Task<bool>> confirm)
        : base(runtime,confirm,"security","Segurança do Windows",
            "Consulte Microsoft Defender, firewall, antivírus registrados e TPM. Diagnóstico somente leitura; alterações são feitas pelo Windows.") { }

    protected override void Build()
    {
        RestoreSnapshot();
        Actions(CollectAction("Verificar proteção", () => ExecuteLocalAsync(async ct =>
        {
            Status("Consultando o Windows Security...");
            var result = await SecurityInspectionService.InspectAsync(ct);
            await SaveSnapshotAsync(result);
            Status("Verificação concluída. Os dados são locais e refletem as informações fornecidas pelo Windows.");
        })), Action("Abrir Segurança do Windows", () => OpenWindowsSecurityAsync()));
        if (Report.ValueKind != JsonValueKind.Object)
        {
            View.Children.Add(Ui.Card(Ui.Label("Nenhum diagnóstico disponível. Clique em Verificar proteção. Se uma consulta não for suportada, ela será indicada como indisponível.",P.Text,14,wrap:true),P,14));
            return;
        }
        var captured = Number(Report,"captured_at");
        if (captured > 0) Text("Consultado em "+DateTimeOffset.FromUnixTimeSeconds((long)captured).ToLocalTime().ToString("dd/MM/yyyy HH:mm:ss"));
        var defender = Part(Report,"defender");
        var firewall = Rows(Report,"firewall");
        var antivirus = Rows(Report,"antivirus");
        var tpm = Part(Report,"tpm");
        var statuses = new (string Name, string Value, bool Warning)[] {
            ("ANTIVÍRUS", defender.ValueKind==JsonValueKind.Object ? State(defender,"antivirus_enabled") : "Indisponível", defender.ValueKind==JsonValueKind.Object && !Yes(defender,"antivirus_enabled")),
            ("PROTEÇÃO EM TEMPO REAL", defender.ValueKind==JsonValueKind.Object ? State(defender,"real_time_enabled") : "Indisponível", defender.ValueKind==JsonValueKind.Object && !Yes(defender,"real_time_enabled")),
            ("FIREWALL", firewall.Length>0 ? firewall.Count(f=>Yes(f,"enabled"))+" de "+firewall.Length+" perfis ativos" : "Indisponível", firewall.Any(f=>!Yes(f,"enabled"))),
            ("TPM", tpm.ValueKind==JsonValueKind.Object ? State(tpm,"ready") : "Indisponível", tpm.ValueKind==JsonValueKind.Object && !Yes(tpm,"ready"))
        };
        var grid = new Grid { ColumnSpacing=10, RowSpacing=10 };
        grid.ColumnDefinitions.Add(new ColumnDefinition());grid.ColumnDefinitions.Add(new ColumnDefinition());
        grid.RowDefinitions.Add(new RowDefinition{Height=GridLength.Auto});grid.RowDefinitions.Add(new RowDefinition{Height=GridLength.Auto});
        for(var index=0;index<statuses.Length;index++)
        {
            var s=statuses[index];
            var box=new StackPanel{Spacing=7};
            box.Children.Add(Ui.Label(s.Name,P.Muted,11,true));
            box.Children.Add(Ui.Label(s.Value,s.Warning?P.Warning:P.Text,16,true,true));
            var tile=Ui.Card(box,P,15);Grid.SetRow(tile,index/2);Grid.SetColumn(tile,index%2);grid.Children.Add(tile);
        }
        View.Children.Add(grid);
        Text("Microsoft Defender",true);
        if(defender.ValueKind==JsonValueKind.Object)
        {
            Text("Definições de segurança: "+(Yes(defender,"signatures_outdated")?"desatualizadas (conforme o Windows)":"não indicadas como desatualizadas pelo Windows"));
            Text("Última atualização informada: "+(Get(defender,"signatures_updated") is var date && date.Length>0 ? date : "não informada"));
            Text("Última verificação rápida: "+Age(defender,"quick_scan_days"));
            Text("Última verificação completa: "+Age(defender,"full_scan_days"));
        }
        else Text("Dados do Defender não disponíveis. Outra solução de antivírus pode estar registrada; consulte a Central de Segurança do Windows.");
        Text("Perfis do Firewall",true);
        foreach(var profile in firewall)
        {
            if(!Matches(Get(profile,"name")))continue;
            var body = new StackPanel{Spacing=4};
            body.Children.Add(Ui.Label(Get(profile,"name")+" — "+State(profile,"enabled"),P.Text,14,true));
            body.Children.Add(Ui.Label("Entrada: "+Get(profile,"inbound")+" · Saída: "+Get(profile,"outbound"),P.Muted,12,wrap:true));
            View.Children.Add(Ui.Card(body,P,10));
        }
        Text("Antivírus registrados",true);
        if(antivirus.Length==0)Text("Nenhum produto retornado por SecurityCenter2 (não significa que o computador esteja sem proteção).");
        foreach(var product in antivirus)if(Matches(Get(product,"name")))Text(Get(product,"name")+" · estado bruto informado pelo Windows: "+Get(product,"product_state"));
        Text("TPM e integridade",true);
        if(tpm.ValueKind==JsonValueKind.Object)Text("TPM presente: "+State(tpm,"present")+" · pronto: "+State(tpm,"ready")+" · habilitado: "+State(tpm,"enabled"));
        else Text("Consulta de TPM indisponível.");
        foreach(var message in Rows(Report,"errors"))Text("Limitação: "+message.ToString());
        Text("Esta tela não substitui um antivírus, não calcula um índice fictício de segurança e não ativa/desativa proteções silenciosamente.");
    }

    private static string State(JsonElement parent,string name)
        => Part(parent,name).ValueKind == JsonValueKind.True ? "Ativo" :
           Part(parent,name).ValueKind == JsonValueKind.False ? "Inativo" : "Indisponível";
    private static string Age(JsonElement parent,string key)
    {
        var value=Get(parent,key);
        return int.TryParse(value,out var number) && number>=0 ? number+" dia(s)" : "não informada";
    }
    private Task OpenWindowsSecurityAsync()
    {
        try { Process.Start(new ProcessStartInfo("windowsdefender:"){UseShellExecute=true});Status("Segurança do Windows aberta para revisão pelo usuário."); }
        catch(Exception ex) { Status("Não foi possível abrir a Segurança do Windows: "+ex.Message); }
        return Task.CompletedTask;
    }
}
