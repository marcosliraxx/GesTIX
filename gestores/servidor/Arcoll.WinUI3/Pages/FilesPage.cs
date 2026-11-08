// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/FilesPage.cs
using System.Diagnostics;
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Explorador local de leitura. Nao transmite, exclui ou executa arquivos.</summary>
public sealed class FilesPage : UserControl
{
    private readonly GestorRuntime _runtime;
    private readonly Grid _root = new() { RowSpacing = 8 }, _rows = new() { RowSpacing = 6 };
    private readonly TextBox _path = new() { PlaceholderText = "Caminho de uma pasta local" };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private Palette _p = new();
    private bool _busy;
    public FilesPage(GestorRuntime runtime)
    {
        _runtime = runtime; Content = _root;
        for (var i=0; i<5; i++) _root.RowDefinitions.Add(new() { Height = i==4 ? new GridLength(1,GridUnitType.Star) : GridLength.Auto });
        _root.Children.Add(new TextBlock { Text = "Arquivos", FontSize = 20 });
        Grid.SetRow(_path,1); _root.Children.Add(_path);
        var controls = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        foreach (var action in new[] { ("Abrir pasta", "open"), ("Voltar", "back"), ("Discos", "root") })
        {
            var button = new Button { Content = action.Item1 };
            button.Click += async (_, _) => await LoadAsync(action.Item2 == "root" ? "" : action.Item2 == "back" ? ParentPath() : _path.Text);
            controls.Children.Add(button);
        }
        Grid.SetRow(controls,2); _root.Children.Add(controls);
        Grid.SetRow(_status,3); _root.Children.Add(_status);
        var scroller=new ScrollViewer { Content=_rows, VerticalScrollBarVisibility=ScrollBarVisibility.Auto, HorizontalScrollBarVisibility=ScrollBarVisibility.Disabled };
        Grid.SetRow(scroller,4); _root.Children.Add(scroller);
        Loaded += async (_, _) => { try { if (_rows.Children.Count == 0) await LoadAsync(""); } catch (Exception ex) { RuntimeLog.Write("Arquivos/Loaded: " + ex); } };
    }
    private string ParentPath() { try { return string.IsNullOrWhiteSpace(_path.Text) ? "" : Directory.GetParent(_path.Text)?.FullName ?? ""; } catch (ArgumentException) { return ""; } }
    public void ApplyPalette(Palette p) { _p = p; _status.Foreground = p.Muted; }
    private async Task LoadAsync(string path)
    {
        if (_busy || !_runtime.Allowed) return; _busy = true;
        using var operation = OperationProgress.Begin("Consultar arquivos");
        try
        {
            operation.Report(15, "Consultando a pasta selecionada");
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
            var result = await FileBrowserService.BrowseAsync(path, false, timeout.Token);
            var json = JsonSerializer.SerializeToElement(result); _path.Text = json.GetProperty("path").GetString() ?? ""; _rows.Children.Clear();
            foreach (var item in json.GetProperty("entries").EnumerateArray())
            {
                var row = item.Clone(); var name = row.GetProperty("name").GetString() ?? "";
                var folder = row.GetProperty("directory").GetBoolean();
                if (folder)
                {
                    var button = new Button { Content = "📁 " + name, HorizontalAlignment = HorizontalAlignment.Stretch, HorizontalContentAlignment = HorizontalAlignment.Left };
                    button.Click += async (_, _) => await LoadAsync(row.GetProperty("path").GetString() ?? ""); _rows.Children.Add(button);
                }
                else _rows.Children.Add(Ui.Label(name + " · " + Ui.Size(row.GetProperty("size").GetDouble()), _p.Text, 13, wrap: true));
            }
            var folders=json.GetProperty("entries").EnumerateArray().Count(x=>x.GetProperty("directory").GetBoolean());
            var files=_rows.Children.Count-folders;
            _status.Text = folders + " pasta(s) · " + files + " arquivo(s). Leitura local, sem envio ou execução." +
                (json.GetProperty("truncated").GetBoolean() ? " Listagem parcial: entre na pasta desejada para continuar." : "");
            operation.Succeed("Pasta consultada");
        }
        catch (Exception ex) { _status.Text = ex.Message; operation.Fail(ex.Message); }
        finally { _busy = false; }
    }
}
