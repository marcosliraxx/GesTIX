// Caminho: gestores/servidor/Arcoll.WinUI3/Services/ScrollGuard.cs
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;
namespace Arcoll.WinUI3.Services;
/// <summary>Rede de segurança visual: nenhuma barra de rolagem é exibida (a rolagem por roda/toque continua funcionando).</summary>
public static class ScrollGuard
{
    public static void Apply(DependencyObject node)
    {
        if (node is ScrollViewer viewer) Hide(viewer);
        else if (node is TextBox box)
        {
            ScrollViewer.SetVerticalScrollBarVisibility(box, ScrollBarVisibility.Hidden);
            ScrollViewer.SetHorizontalScrollBarVisibility(box, ScrollBarVisibility.Hidden);
        }
        for (var i = 0; i < VisualTreeHelper.GetChildrenCount(node); i++) Apply(VisualTreeHelper.GetChild(node, i));
    }
    public static void ApplyToPopups(XamlRoot root)
    {
        foreach (var popup in VisualTreeHelper.GetOpenPopupsForXamlRoot(root)) if (popup.Child is DependencyObject child) Apply(child);
    }
    public static void Hide(ScrollViewer viewer)
    {
        if (viewer.VerticalScrollBarVisibility is ScrollBarVisibility.Auto or ScrollBarVisibility.Visible) viewer.VerticalScrollBarVisibility = ScrollBarVisibility.Hidden;
        if (viewer.HorizontalScrollBarVisibility is ScrollBarVisibility.Auto or ScrollBarVisibility.Visible) viewer.HorizontalScrollBarVisibility = ScrollBarVisibility.Hidden;
    }
}
