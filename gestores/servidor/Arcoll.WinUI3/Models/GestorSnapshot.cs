// Caminho: gestores/servidor/Arcoll.WinUI3/Models/GestorSnapshot.cs
namespace Arcoll.WinUI3.Models;

public sealed record GestorSnapshot(string Host, string Ip, string Gateway, string Os, string Version, bool Online);
public sealed record DeviceInfo(string Name, string Ip, string Mac, string Kind, long? LatencyMs, string Detail);
public sealed record OpenPort(int Port, string Service);

public static class AppInfo
{
    public static string Version => typeof(AppInfo).Assembly.GetName().Version?.ToString(3) ?? throw new InvalidOperationException("Versão do executável não definida.");
    // Comparação do formato publicável (2 a 5 casas), sem a limitação de 4 componentes de System.Version.
    public static bool TryCompareRelease(string left, string right, out int comparison)
    {
        comparison = 0;
        static bool Parse(string value, out int[] parts)
        {
            parts = Array.Empty<int>();
            if (string.IsNullOrWhiteSpace(value) || value.Length > 32) return false;
            var items = value.Split('.');
            if (items.Length < 2 || items.Length > 5) return false;
            var result = new int[items.Length];
            for (var i = 0; i < items.Length; i++)
            {
                if (items[i].Length is < 1 or > 5 || !items[i].All(char.IsAsciiDigit)
                    || !int.TryParse(items[i], out result[i])) return false;
            }
            parts = result;
            return true;
        }
        if (!Parse(left, out var a) || !Parse(right, out var b)) return false;
        for (var i = 0; i < Math.Max(a.Length, b.Length); i++)
        {
            comparison = (i < a.Length ? a[i] : 0).CompareTo(i < b.Length ? b[i] : 0);
            if (comparison != 0) return true;
        }
        return true;
    }
}
