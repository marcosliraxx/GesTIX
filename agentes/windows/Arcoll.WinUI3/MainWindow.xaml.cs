// Caminho: agentes/windows/Arcoll.WinUI3/MainWindow.xaml.cs
using System.Collections.ObjectModel;
using System.Diagnostics;
using Arcoll.WinUI3.Models;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3;

public sealed class InventoryRow
{
    public string Name {get;set;}="";
    public string Detail {get;set;}="";
}

public sealed partial class MainWindow : Window
{
    private readonly AgentClient _agent = new();
    private readonly ThemeService _themes = new();
    private readonly PresenceService _presence = new();
    private readonly AgentRuntime _runtime = new();
    private readonly TelemetryHub _hub = new();
    private readonly TrayService _tray = new();
    private AuthState _trayLast = AuthState.Checking;
    private readonly ServerPage _serverPage;
    private readonly MonitorPage _monitorPage;
    private bool _cleanBusy;
    private readonly SpeedPage _speedPage;
    private readonly DevicesPage _devicesPage;
    private readonly MapPage _mapPage;
    private readonly ProgramsPage _programsPage;
    private readonly WindowsUpdatePage _windowsUpdatePage;
    private readonly ObservableCollection<InventoryRow> _inventory = new();
    private IReadOnlyList<InventoryRow> _allInventory = Array.Empty<InventoryRow>();
    private readonly DispatcherTimer _refreshTimer = new() { Interval = TimeSpan.FromSeconds(45) };
    private readonly DispatcherTimer _themeTimer = new() { Interval = TimeSpan.FromMinutes(2) };
    private CancellationTokenSource? _pending;
    private bool _refreshing;
    private int _inventoryRevision;
    private readonly Dictionary<string,(DateTime At,IReadOnlyList<InventoryRow> Rows)> _inventoryCache = new();
    private string _section="server";
    private bool _exiting;

    public MainWindow()
    {
        InitializeComponent();
        _serverPage=new ServerPage(_hub,()=>FooterText.Text);
        _monitorPage=new MonitorPage(_hub,()=>_runtime.MonitorEnabled);
        _speedPage=new SpeedPage(_runtime);
        _devicesPage=new DevicesPage(_runtime,m=>FooterText.Text=m);
        _mapPage=new MapPage(_runtime,m=>FooterText.Text=m);
        _programsPage=new ProgramsPage(_runtime,ConfirmAsync);
        _windowsUpdatePage=new WindowsUpdatePage(_runtime);
        var brandIcon=Path.Combine(AppContext.BaseDirectory,"Assets","system.ico");
        if(File.Exists(brandIcon))
        {
            try { AppWindow.SetIcon(brandIcon); BrandBadge.Child=new Image{Source=new Microsoft.UI.Xaml.Media.Imaging.BitmapImage(new Uri(brandIcon)),Stretch=Stretch.Uniform}; }
            catch(Exception ex){RuntimeLog.Write("Ícone da janela: "+ex.Message);}
        }
        _hub.Start();
        InventoryList.ItemsSource = _inventory;
        Navigation.SelectedItem=Navigation.MenuItems[0];
        _refreshTimer.Tick+= async (_,_)=>await RefreshAsync();
        _themeTimer.Tick+= async (_,_)=>await RefreshThemeAsync();
        _refreshTimer.Start();_themeTimer.Start();
        Closed+=(_,_)=>{_refreshTimer.Stop();_themeTimer.Stop();_hub.Dispose();_pending?.Cancel();_agent.Dispose();_themes.Dispose();_presence.Dispose();_runtime.Dispose();_tray.Dispose();};
        _runtime.ProgramActivity+=message=>DispatcherQueue.TryEnqueue(()=>
        {
            ShowFromBackground();
            Navigation.SelectedItem=Navigation.MenuItems.OfType<NavigationViewItem>().FirstOrDefault(x=>x.Tag?.ToString()=="programs");
            _programsPage.ShowActivity(message);
        });
        _runtime.Changed+=()=>DispatcherQueue.TryEnqueue(UpdateRuntimeUi);
        _runtime.Consent=message=>
        {
            var tcs=new TaskCompletionSource<bool>();
            if(!DispatcherQueue.TryEnqueue(async()=>{try{ShowFromBackground();tcs.TrySetResult(await ConfirmAsync("Solicitação do gestor",message,"Autorizar"));}catch(Exception ex){RuntimeLog.Write("Não foi possível apresentar o consentimento remoto: "+ex.GetType().Name);tcs.TrySetResult(false);}})) tcs.TrySetResult(false);
            return tcs.Task;
        };
        _runtime.RequestExit=()=>DispatcherQueue.TryEnqueue(ExitAgent);
        // Fechar a janela mantém o agente em segundo plano; "Encerrar" no rodapé finaliza o processo.
        AppWindow.Closing+=(_,e)=>{ if(!_exiting){ e.Cancel=true; AppWindow.Hide(); } };
        _tray.OnOpen=()=>DispatcherQueue.TryEnqueue(ShowFromBackground);
        _tray.OnRestart=()=>DispatcherQueue.TryEnqueue(RestartAgent);
        _tray.OnExit=()=>DispatcherQueue.TryEnqueue(()=>{ ShowFromBackground(); ExitButton_Click(this,new RoutedEventArgs()); });
        _tray.Start();
        _runtime.Start();
        _presence.Start();
        _=RefreshAsync();_ = RefreshThemeAsync();
    }
    public void ShowFromBackground(){ AppWindow.Show(); Activate(); }
    /// <summary>Reinicia o agente: um processo auxiliar oculto espera este encerrar (instância única) e reabre em segundo plano.</summary>
    private void RestartAgent()
    {
        var exe=Environment.ProcessPath;
        if (string.IsNullOrEmpty(exe)) return;
        try
        {
            Process.Start(new ProcessStartInfo("cmd.exe",$"/c ping -n 4 127.0.0.1 >nul & start \"\" \"{exe}\" --background"){UseShellExecute=false,CreateNoWindow=true});
            RuntimeLog.Write("Reinício solicitado pela bandeja");
            ExitAgent();
        }
        catch (Exception ex) when (ex is System.ComponentModel.Win32Exception or InvalidOperationException) { RuntimeLog.Write("Falha ao reiniciar: "+ex.Message); }
    }
    private void ExitAgent(){ _exiting=true; Close(); Application.Current.Exit(); }
    private async void ExitButton_Click(object sender,RoutedEventArgs e)
    {
        if (await ConfirmAsync("Encerrar o agente","Encerrar o agente interrompe o monitoramento e o acesso do gestor a este computador até a próxima inicialização do Windows. Continuar?","Encerrar")) ExitAgent();
    }
    private async Task RefreshThemeAsync()
    {
        try { using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(12)); await _themes.RefreshAsync(cancel.Token); }
        catch (Exception) { /* mantém último tema válido durante falhas de rede */ }
        ApplyTheme(ThemeService.Palette(_themes.Current.Id,_themes.Current.Mode));
    }
    private void ApplyTheme(AgentTheme t)
    {
        ThemeLabel.Text=t.Id+" · "+t.Mode;
        RootGrid.Background=ThemeService.Brush(t.Background);
        foreach (var card in new[]{MachineCard,NetworkCard,GatewayCard,DetailCard}) card.Background=ThemeService.Brush(t.Surface);
        // Brushes locais são usados também nos textos para evitar recursos não resolvidos entre claro/escuro.
        foreach (var label in new[]{PageTitle,PageSubtitle,DetailHeading,DetailText,MachineValue,IpValue,GatewayValue,ConnectionLabel,FooterText,ToolOutput}) label.Foreground=ThemeService.Brush(t.Text);
        foreach (var label in new[]{ThemeLabel}) label.Foreground=ThemeService.Brush(t.Muted);
        Navigation.Background=ThemeService.Brush(t.Background);
        // Mesma identidade da plataforma web: cards com raio 14, borda sutil e cor de destaque do tema administrativo.
        BrandTitle.Foreground=ThemeService.Brush(t.Text); BrandSub.Foreground=ThemeService.Brush(t.Muted);
        BrandBadge.Background=ThemeService.Brush(t.Accent);
        var line=ThemeService.Brush(ThemeService.IsLight(t)?"#d6e1ee":"#2b3a4f");
        foreach (var card in new[]{MachineCard,NetworkCard,GatewayCard,DetailCard}) card.BorderBrush=line;
        var palette=Palette.From(t); _serverPage.ApplyPalette(palette); _monitorPage.ApplyPalette(palette); _speedPage.ApplyPalette(palette); _devicesPage.ApplyPalette(palette); _mapPage.ApplyPalette(palette); _programsPage.ApplyPalette(palette); _windowsUpdatePage.ApplyPalette(palette);
        StatusBar.Background=ThemeService.Brush(t.Surface);
        var light=ThemeService.IsLight(t);
        RootGrid.RequestedTheme=light?ElementTheme.Light:ElementTheme.Dark;
    }
    private void Navigation_SelectionChanged(NavigationView sender,NavigationViewSelectionChangedEventArgs args)
    {
        if (args.SelectedItem is not NavigationViewItem item) return;
        _section=item.Tag?.ToString()??"server";
        ToolsPanel.Visibility=_section=="tools"?Visibility.Visible:Visibility.Collapsed;
        NativePanel.Visibility=_section is "clean" ? Visibility.Visible:Visibility.Collapsed;
        var native=_section is "server" or "monitor" or "speed" or "devices" or "map" or "programs" or "windows_update";
        PageHost.Content=_section switch{"server"=>_serverPage,"monitor"=>_monitorPage,"speed"=>_speedPage,"devices"=>_devicesPage,"map"=>_mapPage,"programs"=>_programsPage,"windows_update"=>_windowsUpdatePage,_=>(object?)null};
        PageHost.Visibility=native?Visibility.Visible:Visibility.Collapsed;
        PageHeader.Visibility=native?Visibility.Collapsed:Visibility.Visible;
        DetailCard.Visibility=native?Visibility.Collapsed:Visibility.Visible;
        CleanPanel.Visibility=_section=="clean"?Visibility.Visible:Visibility.Collapsed;
        InventoryList.SelectionMode=ListViewSelectionMode.None;
        InventoryFilter.Text="";
        _=UpdateInventoryAsync();
        ServerPanel.Visibility=_section=="connection"?Visibility.Visible:Visibility.Collapsed;
        (PageTitle.Text,PageSubtitle.Text,DetailHeading.Text,DetailText.Text)=_section switch
        {
            "server"=>("Servidor","Visão geral do computador gerenciado","Estado do agente","Consultando informações locais..."),
            "speed"=>("Velocidade","Teste de banda e adaptadores","Velocidade da internet","O teste mede a banda real; a velocidade de enlace dos adaptadores (abaixo) não equivale à velocidade contratada."),
            "tools"=>("Ferramentas","Diagnóstico da rede local","Ping, DNS, portas e Wake-on-LAN","Informe um host da rede local (ou um MAC para Wake-on-LAN)."),
            "devices"=>("Dispositivos","Vizinhos detectados na rede local","Inventário de vizinhos","Consultando registros observados pelo Windows..."),
            "map"=>("Mapa","Dispositivos observados","Topologia local","Nós observados pelo cache de vizinhos; mapas e edições persistentes permanecem no núcleo existente."),
            "connection"=>("Conexão","Endereços e gateway","Informações da conexão","Consultando o agente local..."),
            "clean"=>("Clean","Limpeza segura de temporários","Volumes locais","Analise e confirme antes de remover. Somente arquivos temporários antigos são tocados; arquivos em uso são preservados."),
            "windows_update"=>("Windows Update","Atualizações oficiais do Windows","Atualizações do Windows","Verificação e instalação nativas pelo Windows Update Agent."),
            "programs"=>("Programas","Aplicativos instalados no Windows","Inventário de programas","Selecione um programa para desinstalar com o desinstalador oficial dele. Nada é removido sem sua confirmação."),
            "appearance"=>("Aparência","Tema controlado pelas Configurações Administrativas","Tema global","O tema é recebido da plataforma. Alterações locais não substituem a escolha administrativa."),
            _=>("Servidor","","Estado",""),
        };
        _=RefreshAsync();
    }
    private void InventoryFilter_TextChanged(object sender, TextChangedEventArgs e) => FilterInventory();
    private void FilterInventory()
    {
        var filter=InventoryFilter.Text?.Trim() ?? "";
        _inventory.Clear();
        foreach (var row in _allInventory.Where(x=>x.Name.Contains(filter,StringComparison.CurrentCultureIgnoreCase) || x.Detail.Contains(filter,StringComparison.CurrentCultureIgnoreCase)).Take(1000))
            _inventory.Add(row);
        InventoryCount.Text=$"{_inventory.Count} registros";
    }
    private async Task UpdateInventoryAsync()
    {
        var section=_section;
        var revision=++_inventoryRevision;
        if (_section != "clean") return;
        try
        {
            if (_inventoryCache.TryGetValue(section,out var cached) && DateTime.UtcNow-cached.At < TimeSpan.FromMinutes(3))
            {
                _allInventory=cached.Rows; FilterInventory(); return;
            }
            var rows=await Task.Run(()=>section switch
            {
                "clean"=>WindowsInventory.Runtime().Disks.Select(x=>new InventoryRow{Name=x.Name,Detail=$"Livre: {x.Free / 1073741824.0:0.0} GB de {x.Total / 1073741824.0:0.0} GB"}).ToArray(),
                _=>Array.Empty<InventoryRow>()
            });
            if(revision!=_inventoryRevision || section!=_section) return;
            _inventoryCache[section]=(DateTime.UtcNow,rows);
            _allInventory=rows;
            FilterInventory();
        }
        catch(Exception ex)
        {
            if (revision!=_inventoryRevision) return;
            _allInventory=Array.Empty<InventoryRow>(); FilterInventory();
            StatusBar.Title="Não foi possível obter o inventário local";
            StatusBar.Message=ex.Message;
            StatusBar.Severity=InfoBarSeverity.Warning;
            StatusBar.IsOpen=true;
        }
    }
    private async void RefreshButton_Click(object sender,RoutedEventArgs e)=>await RefreshAsync();
    private async Task RefreshAsync()
    {
        if (_refreshing) return;
        _refreshing=true;
        _pending?.Cancel();_pending?.Dispose();_pending=new CancellationTokenSource(TimeSpan.FromSeconds(12));
        try
        {
            var result=await _agent.HealthAsync(_pending.Token);
            ConnectionLabel.Text="Agente conectado";
            MachineValue.Text=result.Host;IpValue.Text=result.Ip;GatewayValue.Text=result.Gateway;
            FooterText.Text=$"Versão do núcleo: {result.Version} · {result.Os}";
            StatusBar.IsOpen=false;
            if (_section is "connection") DetailText.Text=$"Computador: {result.Host}\nIP: {result.Ip}\nGateway: {result.Gateway}\nSistema: {result.Os}";
        }
        catch (Exception ex) when (ex is not null)
        {
            ConnectionLabel.Text="Rede indisponível";
            StatusBar.Title="Não foi possível consultar a rede local";
            StatusBar.Message=ex is OperationCanceledException ? "A consulta excedeu o tempo limite. Tente atualizar novamente." : ex.Message;
            StatusBar.Severity=InfoBarSeverity.Warning;StatusBar.IsOpen=true;
        }
        finally {_refreshing=false;}
    }
    private async Task RunToolAsync(Func<CancellationToken,Task<string>> action)
    {
        ToolOutput.Text="Executando...";
        try { using var cancel=new CancellationTokenSource(TimeSpan.FromSeconds(30)); ToolOutput.Text=await action(cancel.Token); }
        catch (Exception ex) { ToolOutput.Text="Falha: "+(ex is OperationCanceledException?"tempo limite excedido":ex.Message); }
    }
    private async void ToolPing_Click(object sender,RoutedEventArgs e)
    {
        var host=ToolInput.Text.Trim();
        await RunToolAsync(async _=>{var ms=await _agent.PingAsync(host);return ms is long v?$"{host}: {v} ms":$"{host}: sem resposta";});
    }
    private async void ToolDns_Click(object sender,RoutedEventArgs e)
    {
        var name=ToolInput.Text.Trim();
        await RunToolAsync(async ct=>{var r=await _agent.DnsAsync(name,ct);return r.Ips.Count==0?$"{name}: sem endereços IPv4":string.Join("\n",r.Ips.Select((ip,i)=>$"{ip}  ·  {r.Reverse[i]}"));});
    }
    private async void ToolPorts_Click(object sender,RoutedEventArgs e)
    {
        var host=ToolInput.Text.Trim();
        await RunToolAsync(async ct=>{var r=await _agent.PortsAsync(host,ct);return r.Count==0?$"{host}: nenhuma porta de serviço conhecida aberta":string.Join("\n",r.Select(p=>$"{p.Port}  ·  {p.Service}"));});
    }
    private async void ToolWol_Click(object sender,RoutedEventArgs e)
    {
        var mac=ToolInput.Text.Trim();
        await RunToolAsync(async _=>{await _agent.WakeOnLanAsync(mac);return "Pacote Wake-on-LAN enviado para "+mac+".";});
    }
    private void UpdateRuntimeUi()
    {
        GateOverlay.Visibility=_runtime.Gated?Visibility.Visible:Visibility.Collapsed;
        GateText.Text=_runtime.StateNotice;
        ConnectionLabel.Text=_runtime.State switch
        {
            AuthState.Approved or AuthState.UiLocked=>"Autorizado"+(_runtime.NetworkName.Length>0?" · "+_runtime.NetworkName:""),
            AuthState.Pending=>"Aguardando liberação",
            AuthState.Checking=>"Verificando…",
            AuthState.Unavailable=>"Sem confirmação",
            _=>"Bloqueado",
        };
        var trayState=_runtime.State switch
        {
            AuthState.Approved or AuthState.UiLocked=>TrayState.Connected,
            AuthState.Pending or AuthState.Checking=>TrayState.Pending,
            _=>TrayState.Offline,
        };
        _tray.SetState(trayState,ConnectionLabel.Text);
        if (_runtime.State!=_trayLast)
        {
            var first=_trayLast==AuthState.Checking; _trayLast=_runtime.State;
            if (!first || trayState!=TrayState.Pending) _tray.Notify("T.I Digital",trayState switch
            {
                TrayState.Connected=>"Agente conectado à plataforma.",
                TrayState.Pending=>"Aguardando liberação do gestor.",
                _=>"Sem conexão autorizada com a plataforma.",
            });
        }
        if (_runtime.LastActivity.Length>0 && _runtime.Allowed) FooterText.Text=_runtime.LastActivity;
        else if (_runtime.Detail.Length>0) FooterText.Text=_runtime.Detail;
    }
    private async void GateRetry_Click(object sender,RoutedEventArgs e){await _runtime.RecheckAsync();GateText.Text=_runtime.StateNotice+"\n\nNova verificação solicitada…\nRegistro: "+RuntimeLog.PathName;}
    private CleanReport? _cleanScan;
    private async void CleanScan_Click(object sender,RoutedEventArgs e)
    {
        if (_cleanBusy) return; _cleanBusy=true; CleanRunButton.IsEnabled=false; CleanText.Text="Analisando…";
        try
        {
            _cleanScan=await CleanService.ScanAsync(7,CancellationToken.None);
            var total=_cleanScan.Findings.Sum(f=>f.Bytes);
            CleanText.Text=string.Join("\n",_cleanScan.Findings.Select(f=>$"{f.Category.Title}: {CleanService.Size(f.Bytes)} ({f.Files} arquivos)"))+$"\nTotal recuperável: {CleanService.Size(total)}";
            CleanRunButton.IsEnabled=total>0 || CleanRecycle.IsChecked==true;
        }
        catch (Exception ex) { CleanText.Text="Falha na análise: "+ex.Message; }
        finally { _cleanBusy=false; }
    }
    private async void CleanRun_Click(object sender,RoutedEventArgs e)
    {
        if (_cleanBusy || _cleanScan is null) return;
        var keys=_cleanScan.Findings.Where(f=>f.Files>0).Select(f=>f.Category.Key).ToArray();
        var total=_cleanScan.Findings.Sum(f=>f.Bytes);
        if (!await ConfirmAsync("Confirmar limpeza",$"Serão removidos {CleanService.Size(total)} de arquivos temporários com mais de 7 dias"+(CleanRecycle.IsChecked==true?" e a lixeira será esvaziada":"")+". Arquivos em uso são preservados. Continuar?","Limpar")) return;
        _cleanBusy=true; CleanRunButton.IsEnabled=false; CleanText.Text="Limpando…";
        try
        {
            var r=await CleanService.ExecuteAsync(7,keys,CleanRecycle.IsChecked==true,CancellationToken.None);
            CleanText.Text=$"Concluído: {CleanService.Size(r.FreedBytes)} liberados"+(r.FailedFiles>0?$" · {r.FailedFiles} arquivos em uso foram preservados":"")+".";
            _inventoryCache.Remove("clean"); _ = UpdateInventoryAsync();
        }
        catch (Exception ex) { CleanText.Text="Falha na limpeza: "+ex.Message; }
        finally { _cleanBusy=false; }
    }
    private async Task<bool> ConfirmAsync(string title,string message,string primary)
    {
        var dialog=new ContentDialog{Title=title,Content=new TextBlock{Text=message,TextWrapping=TextWrapping.Wrap},PrimaryButtonText=primary,CloseButtonText="Cancelar",DefaultButton=ContentDialogButton.Close,XamlRoot=Content.XamlRoot};
        return await dialog.ShowAsync()==ContentDialogResult.Primary;
    }
    private void OriginalButton_Click(object sender,RoutedEventArgs e)
    {
        // Integração transitória: não duplica operações privilegiadas e conserva as confirmações existentes.
        var root=AppContext.BaseDirectory;
        var original=Path.GetFullPath(Path.Combine(root,"..","..","arcoll_app.py"));
        if (!File.Exists(original)) original=Path.Combine(root,"arcoll_app.py");
        if (!File.Exists(original))
        {
            StatusBar.Title="Interface existente não localizada";
            StatusBar.Message="Instale o projeto junto aos arquivos do agente, sem substituir seu executável atual.";
            StatusBar.Severity=InfoBarSeverity.Warning;StatusBar.IsOpen=true;return;
        }
        try
        {
            Process.Start(new ProcessStartInfo("pythonw.exe") { Arguments="\""+original+"\" --show",WorkingDirectory=Path.GetDirectoryName(original)!,UseShellExecute=false });
        }
        catch(Exception ex)
        {
            StatusBar.Title="Não foi possível abrir as funções existentes";StatusBar.Message=ex.Message;StatusBar.IsOpen=true;
        }
    }
}
