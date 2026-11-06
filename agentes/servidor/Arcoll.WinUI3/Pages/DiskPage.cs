// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/DiskPage.cs
using Arcoll.WinUI3.Services;
namespace Arcoll.WinUI3.Pages;
public sealed class DiskPage : MaintenancePage
{
    private string _drive=Environment.GetEnvironmentVariable("SystemDrive")??"C:",_tab="types";
    public DiskPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm):base(runtime,confirm,"disk","Armazenamento e disco","Capacidade, ocupação por tipo, pastas e arquivos maiores. Esta página só analisa; não exclui arquivos."){}
    protected override void Build()
    {
        var drives=DriveInfo.GetDrives().Where(d=>d.DriveType==DriveType.Fixed).Select(d=>(d.Name.TrimEnd('\\'),"Unidade "+d.Name)).ToArray();
        if(!drives.Any(d=>d.Item1==_drive)&&drives.Length>0)_drive=drives[0].Item1;
        Choices(drives,_drive,value=>_drive=value);Actions(Action("Analisar unidade selecionada",()=>RunToolAsync("scan",("drive",_drive)),drives.Length>0));
        if(Report.ValueKind!=System.Text.Json.JsonValueKind.Object)return;
        Text("Relatório da unidade "+Get(Report,"drive")+" · "+Ui.Size(Number(Report,"disk_free"))+" livres de "+Ui.Size(Number(Report,"disk_total")),true);
        Text(Get(Report,"files")+" arquivos lidos · "+Ui.Size(Number(Report,"bytes"))+" analisados");
        Choices(new[]{("types","Tipos de arquivos"),("folders","Pastas maiores"),("largest","Arquivos maiores")},_tab,value=>_tab=value);
        foreach(var item in Rows(Report,_tab).OrderByDescending(x=>Number(x,"bytes"))){var name=Get(item,_tab=="types"?"name":"path");if(Matches(name))Text(name+" · "+Ui.Size(Number(item,"bytes"))+(_tab=="types"?" · "+Get(item,"count")+" arquivos":""));}
    }
}
