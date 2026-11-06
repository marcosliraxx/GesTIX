// Caminho: agentes/windows/Arcoll.WinUI3/Pages/ProgramsPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Automation;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Media.Imaging;

namespace Arcoll.WinUI3.Pages;

/// <summary>Inventário, catálogo e execução no painel nativo; sem WebView nem executor paralelo.</summary>
public sealed class ProgramsPage : UserControl
{
    private readonly AgentRuntime _runtime;
    private readonly Func<string,string,string,Task<bool>> _confirm;
    private readonly StackPanel _root=new(){Spacing=12};
    private readonly TextBox _search=new(){PlaceholderText="Pesquisar nome, versão ou fabricante"};
    private readonly ComboBox _mode=new(){HorizontalAlignment=HorizontalAlignment.Stretch};
    private readonly TextBlock _summary=new(){TextWrapping=TextWrapping.Wrap};
    private readonly TextBlock _status=new(){TextWrapping=TextWrapping.Wrap,IsTextSelectionEnabled=true};
    private readonly StackPanel _rows=new(){Spacing=8};
    private readonly ProgressBar _progress=new(){Minimum=0,Maximum=100,Visibility=Visibility.Collapsed};
    private readonly Button _refresh=new(){Content="Atualizar inventário"},_install=new(){Content="Aplicar selecionados"},_clear=new(){Content="Limpar seleção"};
    private readonly HashSet<string> _selected=new(StringComparer.OrdinalIgnoreCase);
    private IReadOnlyList<InstalledApplication> _installed=Array.Empty<InstalledApplication>();
    private IReadOnlyList<CatalogApplication> _catalog=Array.Empty<CatalogApplication>();
    private Palette _p=new();
    private bool _busy,_loaded;
    private int _visibleLimit=80;
    private bool CanAct=>_runtime.State==AuthState.Approved;
    private readonly ComboBox _category=new(){HorizontalAlignment=HorizontalAlignment.Stretch};
    public ProgramsPage(AgentRuntime runtime,Func<string,string,string,Task<bool>> confirm)
    {
        _runtime=runtime;_confirm=confirm;Content=_root;
        var title=new TextBlock{Text="Programas",FontSize=24,FontWeight=Microsoft.UI.Text.FontWeights.SemiBold};
        _root.Children.Add(title);_root.Children.Add(new TextBlock{Text="Gerencie os aplicativos deste computador com as mesmas categorias da plataforma.",TextWrapping=TextWrapping.Wrap});
        _mode.Items.Add("Instalados");_mode.Items.Add("Catálogo de instalação");_mode.SelectedIndex=0;
        _category.Items.Add("Todas as categorias");_category.SelectedIndex=0;
        _root.Children.Add(_search);_root.Children.Add(_mode);_root.Children.Add(_category);
        var actions=new VariableSizedWrapGrid{Orientation=Orientation.Horizontal,ItemHeight=44,ItemWidth=200,HorizontalAlignment=HorizontalAlignment.Stretch};
        foreach(var b in new[]{_refresh,_clear,_install}){b.Margin=new Thickness(0,0,8,6);actions.Children.Add(b);}
        _root.Children.Add(actions);_root.Children.Add(_summary);_root.Children.Add(_progress);_root.Children.Add(_status);_root.Children.Add(_rows);
        AutomationProperties.SetName(_search,"Pesquisar programas");AutomationProperties.SetName(_mode,"Exibir instalados ou catálogo");
        AutomationProperties.SetLiveSetting(_status,Microsoft.UI.Xaml.Automation.Peers.AutomationLiveSetting.Polite);
        _search.TextChanged+=(_,_)=>{_visibleLimit=80;Render();};_mode.SelectionChanged+=(_,_)=>{_visibleLimit=80;Render();};_category.SelectionChanged+=(_,_)=>{_visibleLimit=80;Render();};
        _refresh.Click+=async(_,_)=>await RefreshAsync();_clear.Click+=(_,_)=>{_selected.Clear();Render();};_install.Click+=async(_,_)=>await InstallAsync();
        Loaded+=async(_,_)=>{if(!_loaded)await RefreshAsync();};
        SizeChanged+=(_,e)=>{actions.ItemWidth=Math.Min(200,Math.Max(150,e.NewSize.Width/3-8));};
    }
    public void ShowActivity(string text){_status.Text=text;}
    private async Task PublishInventoryAsync()
    {
        if(!CanAct)return;
        try
        {
            using var cancel=new CancellationTokenSource(TimeSpan.FromSeconds(15));
            await _runtime.Platform.PostAsync("agent-program-local-inventory",_runtime.Platform.WithCredentials(("programs",ProgramService.InventoryPayload(_installed))),cancel.Token,12);
        }
        catch(Exception e){_status.Text+="\nInventário local preservado; envio à plataforma pendente: "+e.Message;}
    }
    public void ApplyPalette(Palette p){_p=p;Render();}
    public async Task RefreshAsync()
    {
        if(_busy)return;_busy=true;SetBusy();_status.Text="Lendo o inventário local…";
        try
        {
            var result=await Task.Run(()=> (WindowsInventory.Programs(),ProgramService.Catalog()));
            _installed=result.Item1;_catalog=result.Item2;
            var category=_category.SelectedItem?.ToString();_category.Items.Clear();_category.Items.Add("Todas as categorias");
            foreach(var name in _catalog.Select(x=>x.Category).Distinct().OrderBy(x=>x))_category.Items.Add(name);
            _category.SelectedItem=category is not null&&_category.Items.Contains(category)?category:"Todas as categorias";
            _loaded=true;_status.Text="Inventário atualizado às "+DateTime.Now.ToString("HH:mm")+".";
            await PublishInventoryAsync();
        }
        catch(Exception e){_status.Text="Não foi possível atualizar: "+e.Message;}
        finally{_busy=false;SetBusy();Render();}
    }
    private void SetBusy()
    {
        _refresh.IsEnabled=!_busy;_clear.IsEnabled=!_busy;_install.IsEnabled=!_busy&&_selected.Count>0&&_mode.SelectedIndex==1&&CanAct;
        _progress.Visibility=_busy?Visibility.Visible:Visibility.Collapsed;_progress.IsIndeterminate=_busy;
    }
    private bool Matches(string text)=>text.Contains(_search.Text.Trim(),StringComparison.CurrentCultureIgnoreCase);
    private void Render()
    {
        _rows.Children.Clear();_install.Visibility=_clear.Visibility=_mode.SelectedIndex==1?Visibility.Visible:Visibility.Collapsed;_category.Visibility=_mode.SelectedIndex==1?Visibility.Visible:Visibility.Collapsed;_summary.Foreground=_p.Muted;_status.Foreground=_p.Text;
        if(_mode.SelectedIndex==1)
        {
            var filtered=_catalog.Where(x=>(_category.SelectedIndex<=0||x.Category==_category.SelectedItem?.ToString())&&Matches(x.Name+" "+x.Category+" "+x.Id)).ToArray();
            _summary.Text=$"{_catalog.Count} no catálogo · {filtered.Length} exibidos · {_selected.Count}/20 selecionados";
            foreach(var app in filtered.Take(_visibleLimit))
            {
                var installed=ProgramService.Match(app,_installed);
                var box=new CheckBox{Content=Ui.Label(app.Name,_p.Text,14,true,true),IsChecked=_selected.Contains(app.Id),IsEnabled=!_busy,HorizontalAlignment=HorizontalAlignment.Stretch};
                AutomationProperties.SetName(box,"Selecionar "+app.Name);
                box.Click+=(_,_)=>{if(box.IsChecked==true){if(_selected.Count>=20){box.IsChecked=false;_status.Text="Selecione até 20 programas por operação.";return;}_selected.Add(app.Id);}else _selected.Remove(app.Id);Render();};
                var content=new StackPanel{Spacing=5};content.Children.Add(box);
                content.Children.Add(Ui.Label(app.Category+" · "+(installed is null?"Instalar ou verificar pelo WinGet":"Verificar atualização · "+installed.Version),_p.Muted,12,wrap:true));
                content.Children.Add(Ui.Label(app.Id,_p.Muted,11,wrap:true));
                var line=new Grid();line.ColumnDefinitions.Add(new ColumnDefinition{Width=GridLength.Auto});line.ColumnDefinitions.Add(new ColumnDefinition{Width=new GridLength(1,GridUnitType.Star)});
                var logo=Logo(app);Grid.SetColumn(logo,0);Grid.SetColumn(content,1);line.Children.Add(logo);line.Children.Add(content);
                _rows.Children.Add(Ui.Card(line,_p));
            }
            More(filtered.Length);
            if(_catalog.Count==0)_status.Text="Catálogo indisponível. Atualize o agente pelo pacote publicado na plataforma.";
        }
        else
        {
            var filtered=_installed.Where(x=>Matches(x.Name+" "+x.Version+" "+x.Publisher)).ToArray();
            _summary.Text=$"{_installed.Count} instalados · {filtered.Length} exibidos";
            foreach(var app in filtered.Take(_visibleLimit))
            {
                var content=new StackPanel{Spacing=6};content.Children.Add(Ui.Label(app.Name,_p.Text,15,true,true));
                content.Children.Add(Ui.Label($"Versão: {Value(app.Version)} · Fabricante: {Value(app.Publisher)}",_p.Muted,12,wrap:true));
                content.Children.Add(Ui.Label("Instalado em: "+Date(app.InstalledOn),_p.Muted,12,wrap:true));
                var actions=new StackPanel{Orientation=Orientation.Horizontal,Spacing=8};
                var details=new Button{Content="Detalhes",IsEnabled=!_busy};details.Click+=async(_,_)=>await DetailsAsync(app);actions.Children.Add(details);
                var remove=new Button{Content="Desinstalar",IsEnabled=!_busy&&CanAct&&ProgramService.CanUninstall(app)};
                AutomationProperties.SetName(remove,"Desinstalar "+app.Name);remove.Click+=async(_,_)=>await UninstallAsync(app);actions.Children.Add(remove);content.Children.Add(actions);
                _rows.Children.Add(Ui.Card(content,_p));
            }
            More(filtered.Length);
        }
        if(_rows.Children.Count==0)_rows.Children.Add(Ui.Label("Nenhum programa encontrado para este filtro.",_p.Muted,13,wrap:true));
        SetBusy();
    }
    /// <summary>Logo do programa (PNG embutido em Assets\program-logos). Sem arquivo, mostra o monograma colorido.</summary>
    private static FrameworkElement Logo(CatalogApplication app)
    {
        var tile=new Border{Width=44,Height=44,CornerRadius=new CornerRadius(10),VerticalAlignment=VerticalAlignment.Top,Margin=new Thickness(0,0,12,0)};
        AutomationProperties.SetName(tile,"Logo de "+app.Name);
        var path=ProgramService.LogoPath(app.Id);
        if(path.Length>0)
        {
            try
            {
                // Fundo claro fixo: os logos escuros continuam legíveis nos temas escuros.
                tile.Background=ThemeService.Brush("#f4f7fb");tile.Padding=new Thickness(5);
                tile.Child=new Image{Width=34,Height=34,Stretch=Stretch.Uniform,Source=new BitmapImage(new Uri(path)){DecodePixelWidth=68}};
                return tile;
            }
            catch{tile.Child=null;tile.Padding=new Thickness(0);}
        }
        tile.Background=ThemeService.Brush(ProgramService.LogoColor(app.Id));
        tile.Child=new TextBlock{Text=ProgramService.Initials(app.Name),Foreground=ThemeService.Brush("#ffffff"),FontWeight=Microsoft.UI.Text.FontWeights.Bold,FontSize=16,HorizontalAlignment=HorizontalAlignment.Center,VerticalAlignment=VerticalAlignment.Center};
        return tile;
    }
    private void More(int count)
    {
        if(count<=_visibleLimit)return;
        var button=new Button{Content=$"Carregar mais ({Math.Min(_visibleLimit,count)} de {count})",IsEnabled=!_busy};
        button.Click+=(_,_)=>{_visibleLimit+=80;Render();};_rows.Children.Add(button);
    }
    private static string Value(string text)=>string.IsNullOrWhiteSpace(text)?"Não informado":text;
    private static string Date(string text)=>DateTime.TryParseExact(text,"yyyyMMdd",System.Globalization.CultureInfo.InvariantCulture,System.Globalization.DateTimeStyles.None,out var value)?value.ToString("dd/MM/yyyy"):Value(text);
    private async Task DetailsAsync(InstalledApplication app)
    {
        var dialog=new ContentDialog{XamlRoot=XamlRoot,Title=app.Name,CloseButtonText="Fechar",Content=new TextBlock{Text=$"Versão: {Value(app.Version)}\nFabricante: {Value(app.Publisher)}\nInstalado em: {Date(app.InstalledOn)}\nIdentificador do Windows: {app.Id}",TextWrapping=TextWrapping.Wrap,IsTextSelectionEnabled=true}};
        await dialog.ShowAsync();
    }
    private async Task UninstallAsync(InstalledApplication app)
    {
        if(_busy||!CanAct)return;
        if(!await _confirm("Desinstalar programa",$"Desinstalar {app.Name}? O desinstalador oficial poderá solicitar elevação. Salve seu trabalho antes de continuar.","Desinstalar"))return;
        if(!CanAct)return;
        _busy=true;_status.Text="Executando desinstalador de "+app.Name+"…";Render();
        try
        {
            // Releitura impede executar um registro que já mudou desde a seleção.
            var current=(await Task.Run(WindowsInventory.Programs)).FirstOrDefault(x=>x.Id==app.Id&&x.Uninstall==app.Uninstall&&x.QuietUninstall==app.QuietUninstall)??throw new InvalidOperationException("O programa mudou. Atualize o inventário.");
            var code=await ProgramService.UninstallAsync(current,false,CancellationToken.None);
            _status.Text=ProgramService.SuccessfulExit(code)?"Desinstalador concluído"+(code==0?".":"; reinicialização necessária."):"Desinstalador finalizou com código "+code+". Confira o resultado no Windows.";
            _installed=await Task.Run(WindowsInventory.Programs);RuntimeLog.Write("Desinstalação local: "+app.Name+"; código "+code);await PublishInventoryAsync();
        }
        catch(Exception e){_status.Text="Não foi possível desinstalar: "+e.Message;}
        finally{_busy=false;Render();}
    }
    private async Task InstallAsync()
    {
        if(_busy||!CanAct||_selected.Count is <1 or >20)return;
        var ids=_selected.ToArray();
        if(!await _confirm("Instalar / atualizar programas","Aplicar aos programas selecionados:\n"+string.Join("\n",_catalog.Where(x=>ids.Contains(x.Id)).Select(x=>x.Name))+"\nO WinGet solicitará as permissões e contratos necessários.","Continuar"))return;
        if(!CanAct)return;
        _busy=true;Render();var outcomes=new List<string>();
        try
        {
            for(var i=0;i<ids.Length;i++)
            {
                if(!CanAct){outcomes.Add("Autorização suspensa; fila interrompida.");break;}
                var app=_catalog.Single(x=>x.Id==ids[i]);_status.Text=$"{i+1}/{ids.Length} · {app.Name}: aguardando o instalador oficial…";
                _progress.IsIndeterminate=false;_progress.Value=100d*i/ids.Length;
                try{var code=await ProgramService.PackageAsync(app.Id,ProgramService.Match(app,_installed)is not null,_catalog,CancellationToken.None);outcomes.Add(app.Name+": "+(ProgramService.SuccessfulExit(code)?"concluído"+(code==0?"":"; reinício necessário"):"código "+code+"; confira o WinGet"));}
                catch(Exception e){outcomes.Add(app.Name+": "+e.Message);}
            }
            _installed=await Task.Run(WindowsInventory.Programs);_selected.Clear();
            _status.Text=string.Join("\n",outcomes);RuntimeLog.Write("Operação local de programas: "+string.Join("; ",outcomes));await PublishInventoryAsync();
        }
        catch(Exception e){_status.Text="Falha ao atualizar o inventário: "+e.Message+"\n"+string.Join("\n",outcomes);}
        finally{_busy=false;Render();}
    }
}
