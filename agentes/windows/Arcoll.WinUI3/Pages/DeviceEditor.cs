// Caminho: agentes/windows/Arcoll.WinUI3/Pages/DeviceEditor.cs
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace Arcoll.WinUI3.Pages;

public enum EditorResult { Cancelled, Saved, Removed }

/// <summary>Janela "Identificar dispositivo" (nome, tipo, grupo, fixar, excluir da lista) — mesmos campos e regras de redes/dispositivos.php.</summary>
public static class DeviceEditor
{
    public static readonly string IconFont = "Segoe Fluent Icons, Segoe MDL2 Assets";

    public static string Glyph(string type) => type switch
    {
        "phone" => "\uE8EA", "tablet" => "\uE70A", "tv" => "\uE7F4", "router" or "ap" or "switch" => "\uE968", "printer" => "\uE749",
        "camera" => "\uE722", "server" or "nas" => "\uEDA2", "speaker" => "\uE767", "console" => "\uE7FC", _ => "\uE7F8",
    };

    public static string When(long unix)
    {
        if (unix <= 0) return "—";
        var span = DateTimeOffset.UtcNow - DateTimeOffset.FromUnixTimeSeconds(unix);
        if (span.TotalSeconds < 60) return "agora";
        if (span.TotalMinutes < 60) return $"{(int)span.TotalMinutes} min";
        if (span.TotalHours < 48) return $"{(int)span.TotalHours} h";
        return $"{(int)span.TotalDays} dias";
    }

    public static async Task<EditorResult> ShowAsync(XamlRoot root, DeviceRecord d, Palette p, AgentRuntime runtime, Action<string> notify)
    {
        var name = new TextBox { Text = d.CustomName, MaxLength = 160, PlaceholderText = "Nome do equipamento", Header = "Nome personalizado" };
        var type = new ComboBox { Header = "Tipo do dispositivo", HorizontalAlignment = HorizontalAlignment.Stretch };
        foreach (var t in InventoryService.Types) type.Items.Add(new ComboBoxItem { Content = t.Label, Tag = t.Key });
        type.SelectedIndex = Math.Max(0, Array.FindIndex(InventoryService.Types, t => t.Key == d.CustomType));
        var group = new TextBox { Text = d.Group, MaxLength = 90, PlaceholderText = "Ex.: Sala, Escritório, IoT", Header = "Grupo / setor" };
        var pinned = new CheckBox { Content = "Fixar no início da lista", IsChecked = d.Pinned };
        var summary = new StackPanel { Spacing = 2 };
        var title = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 10 };
        title.Children.Add(new FontIcon { Glyph = Glyph(d.Type), FontFamily = new Microsoft.UI.Xaml.Media.FontFamily(IconFont), FontSize = 22, Foreground = p.Accent });
        title.Children.Add(Ui.Label(d.Name, p.Text, 16, true));
        summary.Children.Add(title);
        summary.Children.Add(Ui.Label($"{d.Ip} · {d.Mac}", p.Muted, 12));
        summary.Children.Add(Ui.Label(d.Online ? "Conectado" : "Sem resposta", d.Online ? p.Success : p.Danger, 12, true));
        var facts = new StackPanel { Spacing = 2 };
        foreach (var (k, v) in new[] { ("Nome detectado", d.DetectedName), ("Tipo detectado", InventoryService.TypeLabel(d.DetectedType)), ("Origem", d.Source), ("Primeira vez visto", DeviceEditor.When(d.FirstSeen)), ("Última atividade", DeviceEditor.When(d.LastSeen)) })
            if (v.Length > 0 && v != "—") facts.Children.Add(Ui.Label($"{k}: {v}", p.Muted, 11));
        var body = new StackPanel { Spacing = 12, MinWidth = 380 };
        foreach (var c in new UIElement[] { summary, name, type, group, pinned, facts }) body.Children.Add(c);

        var dialog = new ContentDialog
        {
            XamlRoot = root, Title = "Identificar dispositivo", Content = body, PrimaryButtonText = "Salvar", SecondaryButtonText = "Excluir da lista",
            CloseButtonText = "Cancelar", DefaultButton = ContentDialogButton.Primary,
        };
        var result = await dialog.ShowAsync();
        if (result == ContentDialogResult.None) return EditorResult.Cancelled;
        var remove = result == ContentDialogResult.Secondary;
        if (remove)
        {
            var confirm = new ContentDialog
            {
                XamlRoot = root, Title = "Excluir da lista", Content = $"“{d.Name}” deixa de aparecer na lista e no mapa, mas pode continuar presente na rede. Continuar?",
                PrimaryButtonText = "Excluir", CloseButtonText = "Cancelar", DefaultButton = ContentDialogButton.Close,
            };
            if (await confirm.ShowAsync() != ContentDialogResult.Primary) return EditorResult.Cancelled;
        }
        var edited = new DeviceRecord
        {
            Id = d.Id, CustomName = name.Text.Trim(), CustomType = (type.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "", Group = group.Text.Trim(), Pinned = pinned.IsChecked == true,
        };
        try
        {
            using var cancel = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            await InventoryService.SaveAsync(runtime.Platform, edited, remove, cancel.Token);
            d.CustomName = edited.CustomName; d.CustomType = edited.CustomType; d.Group = edited.Group; d.Pinned = edited.Pinned;
            notify(remove ? "Dispositivo excluído da lista. Ele pode continuar presente na rede." : "Identificação salva na plataforma.");
            return remove ? EditorResult.Removed : EditorResult.Saved;
        }
        catch (Exception ex) { notify("Não foi possível salvar: " + (ex is OperationCanceledException ? "tempo limite excedido" : ex.Message)); return EditorResult.Cancelled; }
    }
}
