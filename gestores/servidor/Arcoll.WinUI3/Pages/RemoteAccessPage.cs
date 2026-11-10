// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/RemoteAccessPage.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
namespace Arcoll.WinUI3.Pages;
/// <summary>Área de acesso remoto por código, usando o fluxo autenticado existente.</summary>
public sealed class RemoteAccessPage : UserControl
{
    public RemoteAccessPage(GestorRuntime runtime, Func<Task> showCode)
    {
        var content = new StackPanel { Spacing = 16, Padding = new Thickness(24), HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center, MaxWidth = 560 };
        content.Children.Add(new TextBlock { Text = "Acesso remoto", FontSize = 26, HorizontalAlignment = HorizontalAlignment.Center, FontWeight = Microsoft.UI.Text.FontWeights.SemiBold });
        content.Children.Add(new TextBlock { Text = "Gere um código temporário para permitir acesso remoto autorizado ao computador. A sessão é controlada pela plataforma.", TextWrapping = TextWrapping.Wrap, TextAlignment = Microsoft.UI.Xaml.TextAlignment.Center });
        var action = new Button { Content = "Exibir código de acesso", HorizontalAlignment = HorizontalAlignment.Center, Padding = new Thickness(22, 12, 22, 12) };
        action.Click += async (_, _) => { if (runtime.Allowed) await showCode(); };
        content.Children.Add(action);
        Content = content;
    }
}
