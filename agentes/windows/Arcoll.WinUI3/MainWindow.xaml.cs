// Caminho: agentes/windows/Arcoll.WinUI3/MainWindow.xaml.cs
using System.Diagnostics;
using Arcoll.WinUI3.Models;
using Arcoll.WinUI3.Pages;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3;

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
    private readonly SpeedPage _speedPage;
    private readonly DevicesPage _devicesPage;
    private readonly MapPage _mapPage;
    private readonly ProgramsPage _programsPage;
    private readonly CleanPage _cleanPage;
    private readonly WindowsUpdatePage _windowsUpdatePage;
    private readonly DispatcherTimer _refreshTimer = new() { Interval = TimeSpan.FromSeconds(45) };
    private readonly DispatcherTimer _themeTimer = new() { Interval = TimeSpan.FromMinutes(2) };
    private CancellationTokenSource? _pending;
    private bool _refreshing;
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
        _cleanPage=new CleanPage(_runtime,ConfirmAsync);
        _windowsUpdatePage=new WindowsUpdatePage(_runtime);
        var brandIcon=Path.Combine(AppContext.BaseDirectory,"Assets","system.ico");
        if(File.Exists(brandIcon))
        {
            try { AppWindow.SetIcon(brandIcon); BrandBadge.Child=new Image{Source=new Microsoft.UI.Xaml.Media.Imaging.BitmapImage(new Uri(brandIcon)),Stretch=Stretch.Uniform}; }
            catch(Exception ex){RuntimeLog.Write("Ícone da janela: "+ex.Message);}
        }
        _hub.Start();
        Navigation.SelectedItem=Navigation.MenuItems[0];
        _refreshTimer.Tick+= async (_,_)=>await RefreshAsync();
        _themeTimer.Tick+= async (_,_)=>await RefreshThemeAsync();
        _refreshTimer.Start();_themeTimer.Start();
        Closed+=(_,_)=>{_refreshTimer.Stop();_themeTimer.Stop();_hub.Dispose();_pending?.Cancel();_agent.Dispose();_themes.Dispose();_presence.Dispose();_cleanPage.CancelOperations();_programsPage.CancelOperations();_runtime.Dispose();_tray.Dispose();};
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
        foreach (var card in new[]{DetailCard}) card.Background=ThemeService.Brush(t.Surface);
        // Brushes locais são usados também nos textos para evitar recursos não resolvidos entre claro/escuro.
        foreach (var label in new[]{PageTitle,PageSubtitle,DetailHeading,DetailText,ConnectionLabel,FooterText}) label.Foreground=ThemeService.Brush(t.Text);
        foreach (var label in new[]{ThemeLabel}) label.Foreground=ThemeService.Brush(t.Muted);
        Navigation.Background=ThemeService.Brush(t.Background);
        // Mesma identidade da plataforma web: cards com raio 14, borda sutil e cor de destaque do tema administrativo.
        BrandTitle.Foreground=ThemeService.Brush(t.Text); BrandSub.Foreground=ThemeService.Brush(t.Muted);
        BrandBadge.Background=ThemeService.Brush(t.Accent);
        var line=ThemeService.Brush(ThemeService.IsLight(t)?"#d6e1ee":"#2b3a4f");
        foreach (var card in new[]{DetailCard}) card.BorderBrush=line;
        var palette=Palette.From(t); _serverPage.ApplyPalette(palette); _monitorPage.ApplyPalette(palette); _speedPage.ApplyPalette(palette); _devicesPage.ApplyPalette(palette); _mapPage.ApplyPalette(palette); _programsPage.ApplyPalette(palette); _cleanPage.ApplyPalette(palette); _windowsUpdatePage.ApplyPalette(palette);
        StatusBar.Background=ThemeService.Brush(t.Surface);
        var light=ThemeService.IsLight(t);
        RootGrid.RequestedTheme=light?ElementTheme.Light:ElementTheme.Dark;
    }
    private void Navigation_SelectionChanged(NavigationView sender,NavigationViewSelectionChangedEventArgs args)
    {
        if (args.SelectedItem is not NavigationViewItem item) return;
        _section=item.Tag?.ToString()??"server";
        var native=_section is "server" or "monitor" or "speed" or "devices" or "map" or "programs" or "windows_update" or "clean";
        PageHost.Content=_section switch{"server"=>_serverPage,"monitor"=>_monitorPage,"speed"=>_speedPage,"devices"=>_devicesPage,"map"=>_mapPage,"programs"=>_programsPage,"clean"=>_cleanPage,"windows_update"=>_windowsUpdatePage,_=>(object?)null};
        PageHost.Visibility=native?Visibility.Visible:Visibility.Collapsed;
        PageHeader.Visibility=native?Visibility.Collapsed:Visibility.Visible;
        DetailCard.Visibility=native?Visibility.Collapsed:Visibility.Visible;
        (PageTitle.Text,PageSubtitle.Text,DetailHeading.Text,DetailText.Text)=_section switch
        {
            "server"=>("Servidor","Visão geral do computador gerenciado","Estado do agente","Consultando informações locais..."),
            "speed"=>("Internet","Velocidade e diagnóstico da conexão","Velocidade da internet","O teste mede a banda real; a velocidade de enlace dos adaptadores (abaixo) não equivale à velocidade contratada."),
            "devices"=>("Dispositivos","Vizinhos detectados na rede local","Inventário de vizinhos","Consultando registros observados pelo Windows..."),
            "map"=>("Mapa","Dispositivos observados","Topologia local","Nós observados pelo cache de vizinhos; mapas e edições persistentes permanecem no núcleo existente."),
            "clean"=>("Clean","Manutenção do computador","Clean","Mesmas categorias e relatórios da plataforma."),
            "windows_update"=>("Windows Update","Atualizações oficiais do Windows","Atualizações do Windows","Verificação e instalação nativas pelo Windows Update Agent."),
            "programs"=>("Programas","Aplicativos instalados no Windows","Inventário de programas","Selecione um programa para desinstalar com o desinstalador oficial dele. Nada é removido sem sua confirmação."),
            "appearance"=>("Aparência","Tema controlado pelas Configurações Administrativas","Tema global","O tema é recebido da plataforma. Alterações locais não substituem a escolha administrativa."),
            _=>("Servidor","","Estado",""),
        };
        _=RefreshAsync();
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
            FooterText.Text=$"Versão do núcleo: {result.Version} · {result.Os}";
            StatusBar.IsOpen=false;
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
    private void UpdateRuntimeUi()
    {
        if (!_runtime.Allowed) { _cleanPage.CancelOperations(); _programsPage.CancelOperations(); }
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
    private async Task<bool> ConfirmAsync(string title,string message,string primary)
    {
        var dialog=new ContentDialog{Title=title,Content=new ScrollViewer{MaxHeight=420,Content=new TextBlock{Text=message,TextWrapping=TextWrapping.Wrap}},PrimaryButtonText=primary,CloseButtonText="Cancelar",DefaultButton=ContentDialogButton.Close,XamlRoot=Content.XamlRoot};
        return await dialog.ShowAsync()==ContentDialogResult.Primary;
    }
}
