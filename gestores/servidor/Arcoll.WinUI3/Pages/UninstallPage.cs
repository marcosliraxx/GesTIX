// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/UninstallPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class UninstallPage : MaintenancePage
{
    private IReadOnlyList<InstalledApplication> _installed=Array.Empty<InstalledApplication>();private string _filter="all";
    public UninstallPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"uninstall","Aplicativos instalados","Inventário local, filtro por capacidade de desinstalação e remoção confirmada pelo desinstalador oficial do aplicativo."){}
    protected override void Build()
    {
        Choices(new[]{("all","Todos os aplicativos"),("available","Com desinstalador"),("silent","Com opção silenciosa"),("missing","Sem desinstalador registrado")},_filter,value=>_filter=value);
        Actions(CollectAction("Atualizar inventário",async()=>{_installed=await Task.Run(WindowsInventory.Programs);StoreReport(new { programs=ProgramService.InventoryPayload(_installed) });Status(_installed.Count+" aplicativos inventariados.");await PublishAsync();Render();}));
        Metrics(("Instalados",_installed.Count.ToString(),"Inventário local de aplicativos"),
            ("Com desinstalador",_installed.Count(ProgramService.CanUninstall).ToString(),"Remoção registrada no Windows"),
            ("Sem desinstalador",_installed.Count(a=>!ProgramService.CanUninstall(a)).ToString(),"Requer revisão manual"));
        foreach(var app in _installed.Where(a=>Matches(a.Name,a.Version,a.Publisher)&&(_filter=="all"||(_filter=="available"&&ProgramService.CanUninstall(a))||(_filter=="silent"&&a.QuietUninstall.Length>0)||(_filter=="missing"&&!ProgramService.CanUninstall(a)))).OrderBy(a=>a.Name)){
            Text(app.Name,true);Text(app.Version+" · "+app.Publisher+" · "+app.InstalledOn);
            Actions(Action("Desinstalar "+app.Name,async()=>{
                if(!await Confirm("Desinstalar aplicativo","Remover "+app.Name+" pelo desinstalador oficial? O programa poderá abrir uma janela no Windows.","Desinstalar")||!Runtime.Allowed)return;
                var current=(await Task.Run(WindowsInventory.Programs)).FirstOrDefault(a=>a.Id==app.Id&&a.Uninstall==app.Uninstall&&a.QuietUninstall==app.QuietUninstall)??throw new InvalidOperationException("O registro mudou. Atualize o inventário.");
                await RunUninstallAsync(current);
            },ProgramService.CanUninstall(app)));
        }
    }
    private async Task RunUninstallAsync(InstalledApplication app)
    {
        await ExecuteLocalAsync(async ct=>{var code=await ProgramService.UninstallAsync(app,false,ct);if(!ProgramService.SuccessfulExit(code))throw new InvalidOperationException("Desinstalador retornou código "+code);_installed=await Task.Run(WindowsInventory.Programs,ct);StoreReport(new { programs=ProgramService.InventoryPayload(_installed) });Status("Desinstalador concluído. Confira o inventário atualizado.");await PublishAsync();});
    }
    private async Task PublishAsync()
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(20));try{await Runtime.Platform.PostAsync("gestor-program-local-inventory",Runtime.Platform.WithCredentials(("programs",ProgramService.InventoryPayload(_installed))),timeout.Token,15);}catch(Exception ex){Status("Inventário local preservado; envio pendente: "+ex.Message);}
    }
}
