// Caminho: agentes/servidor/Arcoll.WinUI3/Services/SystemMetrics.cs
using System.Net.NetworkInformation;
using System.Runtime.InteropServices;

namespace Arcoll.WinUI3.Services;

public sealed record DriveMetric(string Name, double Percent, long Used, long Total);
public sealed record InterfaceMetric(string Name, double DownloadMbps, double UploadMbps);
public sealed record MetricSample(long Time, double? Cpu, long MemTotal, long MemUsed, IReadOnlyList<DriveMetric> Drives,
    double DownloadMbps, double UploadMbps, IReadOnlyList<InterfaceMetric> Interfaces, string CpuName);

/// <summary>Medições nativas do computador (CPU, memória, discos e rede). Equivale a SystemMetrics do agente Python, sem psutil/PowerShell.</summary>
public sealed class SystemMetrics
{
    [StructLayout(LayoutKind.Sequential)] private struct FileTime { public uint Low, High; public ulong Value => ((ulong)High << 32) | Low; }
    [DllImport("kernel32.dll")] private static extern bool GetSystemTimes(out FileTime idle, out FileTime kernel, out FileTime user);
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)]
    private struct MemoryStatus
    {
        public uint Length, MemoryLoad; public ulong TotalPhys, AvailPhys, TotalPageFile, AvailPageFile, TotalVirtual, AvailVirtual, AvailExtendedVirtual;
    }
    [DllImport("kernel32.dll", CharSet = CharSet.Auto, SetLastError = true)] private static extern bool GlobalMemoryStatusEx(ref MemoryStatus status);

    private ulong _idle, _total; private bool _hasCpu;
    private DateTime _netAt; private readonly Dictionary<string, (long Rx, long Tx)> _netLast = new();
    private readonly string _cpuName = ReadCpuName();

    private static string ReadCpuName()
    {
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"HARDWARE\DESCRIPTION\System\CentralProcessor\0");
            return (Convert.ToString(key?.GetValue("ProcessorNameString")) ?? "").Trim();
        }
        catch (Exception) { return ""; }
    }

    private double? Cpu()
    {
        if (!GetSystemTimes(out var idle, out var kernel, out var user)) return null;
        var total = kernel.Value + user.Value; // o tempo de kernel já inclui o ocioso
        double? result = null;
        if (_hasCpu && total > _total)
        {
            var dTotal = total - _total; var dIdle = idle.Value - _idle;
            result = Math.Clamp(100d * (1d - (double)dIdle / dTotal), 0, 100);
        }
        _idle = idle.Value; _total = total; _hasCpu = true;
        return result;
    }

    public static (long Total, long Used) Memory()
    {
        var m = new MemoryStatus { Length = (uint)Marshal.SizeOf<MemoryStatus>() };
        return GlobalMemoryStatusEx(ref m) ? ((long)m.TotalPhys, (long)(m.TotalPhys - m.AvailPhys)) : (0, 0);
    }

    public static IReadOnlyList<DriveMetric> Drives()
    {
        var list = new List<DriveMetric>();
        foreach (var d in DriveInfo.GetDrives())
        {
            try
            {
                if (!d.IsReady || d.DriveType != DriveType.Fixed || d.TotalSize <= 0) continue;
                var used = d.TotalSize - d.AvailableFreeSpace;
                list.Add(new DriveMetric(d.Name.TrimEnd('\\'), Math.Round(100d * used / d.TotalSize, 2), used, d.TotalSize));
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        }
        return list;
    }

    private (double Down, double Up, List<InterfaceMetric> Interfaces) Network()
    {
        var now = DateTime.UtcNow; var seconds = _netAt == default ? 0 : (now - _netAt).TotalSeconds;
        double down = 0, up = 0; var items = new List<InterfaceMetric>(); var current = new Dictionary<string, (long, long)>();
        foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (nic.OperationalStatus != OperationalStatus.Up || nic.NetworkInterfaceType is NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel) continue;
            try
            {
                var s = nic.GetIPv4Statistics();
                current[nic.Id] = (s.BytesReceived, s.BytesSent);
                if (seconds > 0.2 && _netLast.TryGetValue(nic.Id, out var last))
                {
                    var d = Math.Max(0, s.BytesReceived - last.Rx) * 8d / seconds / 1_000_000d;
                    var u = Math.Max(0, s.BytesSent - last.Tx) * 8d / seconds / 1_000_000d;
                    down += d; up += u; items.Add(new InterfaceMetric(nic.Name, Math.Round(d, 3), Math.Round(u, 3)));
                }
            }
            catch (NetworkInformationException) { }
        }
        _netLast.Clear(); foreach (var kv in current) _netLast[kv.Key] = kv.Value; _netAt = now;
        return (down, up, items);
    }

    public MetricSample Sample()
    {
        var (total, used) = Memory(); var net = Network();
        return new MetricSample(DateTimeOffset.UtcNow.ToUnixTimeSeconds(), Cpu(), total, used, Drives(), net.Down, net.Up, net.Interfaces, _cpuName);
    }

    /// <summary>Formato plano aceito por arc_server_fallback_components (redes/servidor.php).</summary>
    public static Dictionary<string, object?> ToPlatformPayload(MetricSample s, long? latencyMs, double lossPercent)
    {
        var gb = 1073741824d; var memPercent = s.MemTotal > 0 ? Math.Round(100d * s.MemUsed / s.MemTotal, 2) : (double?)null;
        var details = new Dictionary<string, string>
        {
            ["CPU"] = s.Cpu is double c ? $"CPU: {c:0.0}% • {Environment.ProcessorCount} processadores lógicos" + (s.CpuName.Length > 0 ? " • " + s.CpuName : "") : "Calculando uso da CPU...",
            ["Memória"] = s.MemTotal > 0 ? $"Usada: {s.MemUsed / gb:0.00} GB • Disponível: {(s.MemTotal - s.MemUsed) / gb:0.00} GB • Total: {s.MemTotal / gb:0.00} GB" : "Leitura de memória indisponível neste computador.",
            ["Discos"] = "Ocupação: " + string.Join(", ", s.Drives.Select(d => $"{d.Name} {d.Percent:0.0}% ({d.Used / gb:0}/{d.Total / gb:0} GB)")),
            ["Rede"] = $"Download {s.DownloadMbps:0.00} Mbps • Upload {s.UploadMbps:0.00} Mbps (adaptadores ativos)",
        };
        var meta = new Dictionary<string, object?>
        {
            ["cpu_name"] = s.CpuName,
            ["memory"] = new Dictionary<string, object?> { ["used"] = s.MemUsed, ["available"] = s.MemTotal - s.MemUsed, ["total"] = s.MemTotal, ["percent"] = memPercent ?? 0d },
            ["drives"] = s.Drives.Select((d, i) => new Dictionary<string, object?> { ["index"] = i, ["name"] = d.Name, ["label"] = $"Disco {i} ({d.Name})", ["percent"] = d.Percent, ["used"] = d.Used, ["total"] = d.Total }).ToList(),
            ["interfaces"] = s.Interfaces.Select(n => new Dictionary<string, object?> { ["name"] = n.Name, ["download_mbps"] = n.DownloadMbps, ["upload_mbps"] = n.UploadMbps }).ToList(),
            ["gpus"] = new List<object>(),
        };
        return new Dictionary<string, object?>
        {
            ["event"] = "server_metrics",
            ["metrics"] = new Dictionary<string, object?>
            {
                ["time"] = s.Time, ["CPU"] = s.Cpu is double cpu ? Math.Round(cpu, 2) : null, ["Memória"] = memPercent,
                ["Discos"] = s.Drives.Count > 0 ? Math.Round(s.Drives.Average(d => d.Percent), 2) : null,
                ["Rede"] = Math.Round(s.DownloadMbps + s.UploadMbps, 3), ["Rede_download"] = Math.Round(s.DownloadMbps, 3), ["Rede_upload"] = Math.Round(s.UploadMbps, 3),
                ["Internet"] = latencyMs, ["Internet_download"] = Math.Round(s.DownloadMbps, 3), ["Internet_upload"] = Math.Round(s.UploadMbps, 3), ["Internet_loss"] = Math.Round(lossPercent, 2),
                ["details"] = details, ["meta"] = meta,
            },
        };
    }
}
