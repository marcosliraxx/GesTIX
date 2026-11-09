// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/RestorePage.cs
using System.Diagnostics;
using System.Globalization;
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class RestorePage : MaintenancePage
{
    public RestorePage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"restore","Proteção e restauração","Consulte pontos, crie uma proteção antes de mudanças e abra o assistente oficial do Windows para restaurar. Não há restauração ou reinício automático."){}
    protected override void Build()
    {
        Actions(CollectAction("Consultar pontos",()=>RunToolAsync("list")),Action("Criar ponto de restauração",async()=>{if(await Confirm("Criar ponto","Criar um ponto de restauração do Windows? A proteção da unidade deve estar ativa; o Windows pode limitar a frequência.","Criar")&&Runtime.Allowed)await RunToolAsync("create");}),Action("Abrir assistente de restauração",()=>OpenAsync("rstrui.exe","Abrir o assistente de restauração do Windows? A escolha do ponto e as confirmações serão feitas no assistente oficial.")),Action("Configurar proteção do sistema",()=>OpenAsync("SystemPropertiesProtection.exe","Abrir as configurações oficiais de proteção do sistema?")));
        var points=Rows(Report,"points");Text(points.Length+" pontos informados pelo Windows",true);
        foreach(var point in points.OrderByDescending(x=>Number(x,"sequence"))){var raw=Get(point,"created");var date=DateTime.TryParseExact(raw,"yyyyMMddHHmmss",CultureInfo.InvariantCulture,DateTimeStyles.None,out var parsed)?parsed.ToString("dd/MM/yyyy HH:mm:ss"):raw;if(Matches(Get(point,"description"),raw))Text(date+" · "+Get(point,"description")+" · #"+Get(point,"sequence"));}
        if(Report.ValueKind==System.Text.Json.JsonValueKind.Object&&points.Length==0)Text("Nenhum ponto foi informado. Verifique proteção, permissões e a mensagem do relatório; ausência de itens não confirma que a consulta teve sucesso.");
    }
    private async Task OpenAsync(string file,string message)
    {
        if(!await Confirm("Proteção do Windows",message,"Abrir")||!Runtime.Allowed)return;var path=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),file);if(!File.Exists(path))throw new FileNotFoundException("Ferramenta do Windows indisponível.");Process.Start(new ProcessStartInfo(path){UseShellExecute=true});
    }
}
