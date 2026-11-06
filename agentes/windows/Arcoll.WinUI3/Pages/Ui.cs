// Caminho: agentes/windows/Arcoll.WinUI3/Pages/Ui.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Media;

namespace Arcoll.WinUI3.Pages;

/// <summary>Paleta e fábrica de componentes no padrão visual da plataforma (cards raio 14, borda sutil, indicadores coloridos).</summary>
public sealed class Palette
{
    public Brush Surface = ThemeService.Brush("#ffffff"), Alt = ThemeService.Brush("#edf3fa"), Text = ThemeService.Brush("#172b44"),
        Muted = ThemeService.Brush("#536982"), Accent = ThemeService.Brush("#087eac"), Line = ThemeService.Brush("#d6e1ee"),
        Success = ThemeService.Brush("#12856b"), Warning = ThemeService.Brush("#a86b12"), Danger = ThemeService.Brush("#bc4356");
    public bool Light = true;

    public static Palette From(AgentTheme t)
    {
        var light = ThemeService.IsLight(t);
        return new Palette
        {
            Light = light, Surface = ThemeService.Brush(t.Surface), Alt = ThemeService.Brush(t.Background), Text = ThemeService.Brush(t.Text),
            Muted = ThemeService.Brush(t.Muted), Accent = ThemeService.Brush(t.Accent), Line = ThemeService.Brush(light ? "#d6e1ee" : "#2b3a4f"),
            Success = ThemeService.Brush(light ? "#12856b" : "#32d4a1"), Warning = ThemeService.Brush(light ? "#a86b12" : "#f0b45a"),
            Danger = ThemeService.Brush(light ? "#bc4356" : "#ff7a8c"),
        };
    }
}

public static class Ui
{
    public static TextBlock Label(string text, Brush brush, double size = 13, bool bold = false, bool wrap = false) => new()
    {
        Text = text, Foreground = brush, FontSize = size, FontWeight = bold ? Microsoft.UI.Text.FontWeights.SemiBold : Microsoft.UI.Text.FontWeights.Normal,
        TextWrapping = wrap ? TextWrapping.Wrap : TextWrapping.NoWrap, TextTrimming = wrap ? TextTrimming.None : TextTrimming.CharacterEllipsis,
    };

    public static Border Card(UIElement child, Palette p, double padding = 10) => new()
    {
        Background = p.Surface, BorderBrush = p.Line, BorderThickness = new Thickness(1), CornerRadius = new CornerRadius(14),
        Padding = new Thickness(padding), Child = child,
    };

    /// <summary>Indicador (KPI) com título em caixa alta e valor grande, como nos cards da plataforma.</summary>
    public static (Border Card, TextBlock Value, TextBlock Caption) Stat(string title, Palette p, double valueSize = 18)
    {
        var caption = Label(title.ToUpperInvariant(), p.Muted, 10, true);
        var value = Label("—", p.Text, valueSize, true);
        var panel = new StackPanel { Spacing = 4 }; panel.Children.Add(caption); panel.Children.Add(value);
        return (Card(panel, p, 12), value, caption);
    }

    public static string Size(double bytes)
    {
        string[] unit = { "B", "KB", "MB", "GB", "TB" }; var i = 0;
        while (bytes >= 1024 && i < unit.Length - 1) { bytes /= 1024; i++; }
        return $"{bytes:0.#} {unit[i]}";
    }
}
