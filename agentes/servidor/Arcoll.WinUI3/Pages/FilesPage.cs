// Caminho: agentes/servidor/Arcoll.WinUI3/Pages/FilesPage.cs
using System.Diagnostics;
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Explorador local de leitura. Nao transmite, exclui ou executa arquivos.</summary>
public sealed class FilesPage : UserControl
{
    private readonly AgentRuntime _runtime;
    private readonly StackPanel _root = new() { Spacing = 8 }, _rows = new() { Spacing = 4 };
    private readonly TextBox _path = new() { PlaceholderText = "Caminho de uma pasta local" };
    private readonly TextBlock _status = new() { TextWrapping = TextWrapping.Wrap };
    private Palette _p = new();
    private bool _busy;
    public FilesPage(AgentRuntime runtime)
    {
        _runtime = runtime; Content = _root; _root.Children.Add(new TextBlock { Text = "Arquivos", FontSize = 20 }); _root.Children.Add(_path);
        var controls = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        foreach (var action in new[] { ("Abrir pasta", "open"), ("Voltar", "back"), ("Discos", "root") })
        {
            var button = new Button { Content = action.Item1 };
            button.Click += async (_, _) => await LoadAsync(action.Item2 == "root" ? "" : action.Item2 == "back" ? ParentPath() : _path.Text);
            controls.Children.Add(button);
        }
        _root.Children.Add(controls); _root.Children.Add(_status); _root.Children.Add(_rows);
        Loaded += async (_, _) => { if (_rows.Children.Count == 0) await LoadAsync(""); };
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
            _status.Text = json.GetProperty("truncated").GetBoolean() ? "Lista parcial: entre em uma pasta para continuar." : _rows.Children.Count + " itens. Arquivos não são executados nem enviados.";
            operation.Succeed("Pasta consultada");
        }
        catch (Exception ex) { _status.Text = ex.Message; operation.Fail(ex.Message); }
        finally { _busy = false; }
    }
}
