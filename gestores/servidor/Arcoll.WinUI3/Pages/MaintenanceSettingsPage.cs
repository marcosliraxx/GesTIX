// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/MaintenanceSettingsPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
public sealed class MaintenanceSettingsPage : MaintenancePage
{
    private readonly TextBox _exclusions=new(){Header="Exclusões — um caminho por linha",AcceptsReturn=true,TextWrapping=TextWrapping.Wrap,MinHeight=100,MaxHeight=180},_folders=new(){Header="Pastas personalizadas — um caminho por linha",AcceptsReturn=true,TextWrapping=TextWrapping.Wrap,MinHeight=100,MaxHeight=180};
    private bool _dirty;
    public MaintenanceSettingsPage(GestorRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"options","Configurações de manutenção","Exclusões e pastas são compartilhadas com a plataforma. Caminhos protegidos e identificadores continuam validados pelo servidor e pelo motor."){_exclusions.TextChanged+=(_,_)=>_dirty=true;_folders.TextChanged+=(_,_)=>_dirty=true;}
    protected override void Build()
    {
        Actions(Action("Carregar configurações",async()=>{if(_dirty&&!await Confirm("Descartar edição","Substituir os campos editados pelas configurações atuais da plataforma?","Carregar"))return;await LoadSettingsAsync();_exclusions.Text=string.Join("\n",Setting("exclusions"));_folders.Text=string.Join("\n",Setting("custom_folders"));_dirty=false;StoreReport(new { settings=Settings });Render();}),Action("Salvar configurações",async()=>{
            var exclusions=Lines(_exclusions);var folders=Lines(_folders);
            // Recarrega antes de salvar para preservar as listas administradas em outras áreas.
            await LoadSettingsAsync();await SaveSettingsAsync(exclusions,folders,Setting("perf_excluded"),Setting("drivers_ignored"));_dirty=false;StoreReport(new { settings=Settings });Status("Configurações compartilhadas salvas. Uma nova análise será exigida antes da limpeza.");Render();
        },Settings.ValueKind==System.Text.Json.JsonValueKind.Object));
        _exclusions.IsEnabled=_folders.IsEnabled=!Busy&&Runtime.Allowed;View.Children.Add(_exclusions);View.Children.Add(_folders);
        Text("Pastas inteiras do Windows, raízes e perfis completos não são alvos de limpeza válidos. Exclusões podem aceitar os curingas autorizados pelo servidor.");
        if(Settings.ValueKind==System.Text.Json.JsonValueKind.Object)Text(Setting("exclusions").Length+" exclusões · "+Setting("custom_folders").Length+" pastas · "+Setting("perf_excluded").Length+" aplicativos protegidos · "+Setting("drivers_ignored").Length+" drivers ignorados");
    }
    private static string[] Lines(TextBox box)=>box.Text.Split('\n').Select(x=>x.Trim()).Where(x=>x.Length>0).Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
}
