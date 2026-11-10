// Caminho: gestores/servidor/Arcoll.WinUI3/Pages/SupportShared.cs
using System.Globalization;
using System.Text.Json;
using Arcoll.WinUI3.Services;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;

namespace Arcoll.WinUI3.Pages;

/// <summary>Formatação única de Suporte, Chamados e Agenda, igual à da plataforma (datas em dd/MM/aaaa HH:mm).</summary>
public static class SupportFmt
{
    public static string Str(JsonElement e, string name)
    {
        if (e.ValueKind != JsonValueKind.Object || !e.TryGetProperty(name, out var v)) return "";
        return v.ValueKind switch { JsonValueKind.Null or JsonValueKind.Undefined => "", JsonValueKind.String => v.GetString() ?? "", _ => v.ToString() };
    }
    public static long Num(JsonElement e, string name) => long.TryParse(Str(e, name), out var n) ? n : 0;
    public static bool Flag(JsonElement e, string name) { var s = Str(e, name); return s == "1" || s.Equals("true", StringComparison.OrdinalIgnoreCase); }
    public static bool TryDate(string value, out DateTime date) => DateTime.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.None, out date);
    public static string When(string value) => value.Length == 0 ? "—" : TryDate(value, out var d) ? d.ToString("dd/MM/yyyy HH:mm", CultureInfo.GetCultureInfo("pt-BR")) : value;
    public static string Recurrence(JsonElement row) => Str(row, "recurrence") switch
    {
        "once" => "Uma vez", "interval" => "A cada " + Str(row, "interval_minutes") + " min", _ => "Dias: " + (Str(row, "weekdays") is { Length: > 0 } d ? d : "—"),
    };
    public static string Glyph(string command) => command switch
    {
        "power_restart" => "\uE777", "power_shutdown" => "\uE7E8", "screen_lock" => "\uE72E", "windows_update" => "\uE895", _ => "\uE72C",
    };
    public static async Task<Dictionary<string, string>> ServicesAsync(GestorRuntime runtime, CancellationToken ct)
    {
        var response = await WorkspaceService.GetAsync(runtime, "support-services", ct);
        var list = new Dictionary<string, string>();
        foreach (var item in response.GetProperty("services").EnumerateObject()) list[item.Name] = item.Value.GetString() ?? item.Name;
        return list;
    }
}

/// <summary>Formulário único de rotinas (Suporte e Agenda): mesmos campos, regras e validações da plataforma.</summary>
public static class ScheduleEditor
{
    private static readonly string[] Recurrences = { "once", "weekdays", "interval" };
    private static readonly string[] DayNames = { "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom" };

    /// <summary>Abre o formulário. Devolve true quando a rotina foi salva no servidor.</summary>
    public static async Task<bool> ShowAsync(XamlRoot root, Palette p, GestorRuntime runtime, IReadOnlyDictionary<string, string> services, string? command, JsonElement? row)
    {
        var edit = row is { ValueKind: JsonValueKind.Object };
        var editId = edit ? SupportFmt.Num(row!.Value, "id") : 0;
        var keys = services.Keys.ToArray();
        var service = new ComboBox { Header = "Serviço", HorizontalAlignment = HorizontalAlignment.Stretch };
        foreach (var key in keys) service.Items.Add(services[key]);
        var wanted = edit ? SupportFmt.Str(row!.Value, "command_name") : command ?? "";
        service.SelectedIndex = Math.Max(0, Array.IndexOf(keys, wanted));
        var startDate = new CalendarDatePicker { Header = "Início", HorizontalAlignment = HorizontalAlignment.Stretch };
        var startTime = new TimePicker { ClockIdentifier = "24HourClock", MinuteIncrement = 1 };
        var endDate = new CalendarDatePicker { Header = "Data de término (opcional)", HorizontalAlignment = HorizontalAlignment.Stretch };
        var endTime = new TimePicker { ClockIdentifier = "24HourClock", MinuteIncrement = 1 };
        var clearEnd = new HyperlinkButton { Content = "Remover término", Padding = new Thickness(0) };
        clearEnd.Click += (_, _) => endDate.Date = null;
        var recurrence = new ComboBox { Header = "Repetir", HorizontalAlignment = HorizontalAlignment.Stretch };
        foreach (var label in new[] { "Uma vez", "Dias da semana", "A cada intervalo" }) recurrence.Items.Add(label);
        var interval = new NumberBox { Header = "Intervalo em minutos", Minimum = 15, Maximum = 10080, Value = 60, SpinButtonPlacementMode = NumberBoxSpinButtonPlacementMode.Compact };
        var days = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        var dayBoxes = DayNames.Select(name => new CheckBox { Content = name, MinWidth = 0 }).ToArray();
        foreach (var box in dayBoxes) days.Children.Add(box);
        var daysPanel = new StackPanel { Spacing = 4 };
        daysPanel.Children.Add(Ui.Label("Dias da semana", p.Muted, 12)); daysPanel.Children.Add(days);
        var password = new PasswordBox { Header = "Senha da sua conta" };
        var confirm = new CheckBox { Content = "Confirmo a operação" };
        var error = Ui.Label("", p.Danger, 12, false, true);

        DateTime initial = DateTime.Now.AddMinutes(5);
        if (edit)
        {
            var source = SupportFmt.Str(row!.Value, "starts_at"); if (source.Length == 0) source = SupportFmt.Str(row.Value, "next_run_at");
            if (SupportFmt.TryDate(source, out var parsed)) initial = parsed;
            var rec = Array.IndexOf(Recurrences, SupportFmt.Str(row.Value, "recurrence")); recurrence.SelectedIndex = Math.Max(0, rec);
            if (int.TryParse(SupportFmt.Str(row.Value, "interval_minutes"), out var minutes) && minutes >= 15) interval.Value = minutes;
            var selected = SupportFmt.Str(row.Value, "weekdays").Split(',', StringSplitOptions.RemoveEmptyEntries);
            for (var i = 0; i < dayBoxes.Length; i++) dayBoxes[i].IsChecked = selected.Contains((i + 1).ToString());
            if (SupportFmt.TryDate(SupportFmt.Str(row.Value, "ends_at"), out var end)) { endDate.Date = new DateTimeOffset(end.Date); endTime.Time = end.TimeOfDay; }
        }
        else recurrence.SelectedIndex = 0;
        startDate.Date = new DateTimeOffset(initial.Date); startTime.Time = new TimeSpan(initial.Hour, initial.Minute, 0);

        void Fields()
        {
            interval.Visibility = recurrence.SelectedIndex == 2 ? Visibility.Visible : Visibility.Collapsed;
            daysPanel.Visibility = recurrence.SelectedIndex == 1 ? Visibility.Visible : Visibility.Collapsed;
            var energy = service.SelectedIndex >= 0 && keys[service.SelectedIndex].StartsWith("power_", StringComparison.Ordinal);
            password.Visibility = confirm.Visibility = energy ? Visibility.Visible : Visibility.Collapsed;
        }
        recurrence.SelectionChanged += (_, _) => Fields(); service.SelectionChanged += (_, _) => Fields(); Fields();

        var form = new StackPanel { Spacing = 10 };
        form.Children.Add(service); form.Children.Add(startDate); form.Children.Add(startTime); form.Children.Add(recurrence);
        form.Children.Add(interval); form.Children.Add(daysPanel); form.Children.Add(endDate); form.Children.Add(endTime); form.Children.Add(clearEnd);
        form.Children.Add(password); form.Children.Add(confirm); form.Children.Add(error);
        var dialog = Ui.Dialog(root, p, edit ? "Editar rotina #" + editId : "Nova rotina", new ScrollViewer { Content = form, MaxHeight = 460, VerticalScrollBarVisibility = ScrollBarVisibility.Auto }, "Salvar", close: "Cancelar", defaultButton: ContentDialogButton.Primary);
        var saved = false;
        dialog.PrimaryButtonClick += async (_, args) =>
        {
            var deferral = args.GetDeferral();
            try
            {
                if (startDate.Date is not { } sd) throw new InvalidOperationException("Informe uma data e um horário válidos.");
                var start = sd.Date + startTime.Time;
                var ends = endDate.Date is { } ed ? (ed.Date + endTime.Time).ToString("yyyy-MM-dd'T'HH:mm", System.Globalization.CultureInfo.InvariantCulture) : "";
                var weekdays = dayBoxes.Select((box, i) => box.IsChecked == true ? i + 1 : 0).Where(d => d > 0).ToArray();
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
                await WorkspaceService.GetAsync(runtime, edit ? "schedule-edit" : "schedule-save", timeout.Token,
                    ("id", editId), ("command", keys[Math.Max(0, service.SelectedIndex)]), ("starts_at", start.ToString("yyyy-MM-dd'T'HH:mm", System.Globalization.CultureInfo.InvariantCulture)),
                    ("recurrence", Recurrences[Math.Max(0, recurrence.SelectedIndex)]), ("interval_minutes", (int)interval.Value), ("weekdays", weekdays),
                    ("ends_at", ends), ("password", password.Password), ("confirmed", confirm.IsChecked == true));
                saved = true;
            }
            catch (Exception ex) { args.Cancel = true; error.Text = ex.Message; }
            finally { deferral.Complete(); }
        };
        await dialog.ShowAsync();
        return saved;
    }
}
