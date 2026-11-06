// Caminho: agentes/servidor/Arcoll.WinUI3/Services/WindowsInventory.cs
using System.Diagnostics;
using System.Net.NetworkInformation;
using Microsoft.Win32;

namespace Arcoll.WinUI3.Services;

public sealed record NetworkAdapterInfo(string Name, string Status, string Speed, string Description);
public sealed record DiskInfo(string Name, long Total, long Free);
public sealed record RuntimeInfo(string Machine, string Windows, int CpuCount, long UsedMemory, long TotalMemory, IReadOnlyList<DiskInfo> Disks);

public static class WindowsInventory
{
    // Inventário do registro (somente leitura). A desinstalação fica em ProgramService, com confirmação do usuário.
    public static IReadOnlyList<InstalledApplication> Programs()
    {
        var list = new Dictionary<string, InstalledApplication>(StringComparer.OrdinalIgnoreCase);
        foreach (var hive in new[] {Registry.CurrentUser, Registry.LocalMachine})
        foreach (var view in new[] {RegistryView.Registry64, RegistryView.Registry32})
        {
            try
            {
                using var baseKey = RegistryKey.OpenBaseKey(hive == Registry.CurrentUser ? RegistryHive.CurrentUser : RegistryHive.LocalMachine, view);
                using var uninstall = baseKey.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall");
                if (uninstall is null) continue;
                foreach (var subName in uninstall.GetSubKeyNames())
                {
                    try
                    {
                        using var key = uninstall.OpenSubKey(subName);
                        var name = key?.GetValue("DisplayName") as string;
                        if (string.IsNullOrWhiteSpace(name) || Convert.ToString(key?.GetValue("SystemComponent")) == "1") continue;
                        var app = new InstalledApplication(name.Trim(), Convert.ToString(key?.GetValue("DisplayVersion")) ?? "", Convert.ToString(key?.GetValue("Publisher")) ?? "", Convert.ToString(key?.GetValue("InstallDate")) ?? "",
                            (hive == Registry.CurrentUser ? "HKCU" : "HKLM") + "\\" + (view == RegistryView.Registry32 ? "32" : "64") + "\\" + subName,
                            Convert.ToString(key?.GetValue("UninstallString")) ?? "", Convert.ToString(key?.GetValue("QuietUninstallString")) ?? "");
                        list.TryAdd(name.Trim() + "|" + app.Version, app);
                    }
                    catch (Exception) { /* entradas desinstaladas/inacessíveis são ignoradas */ }
                }
            }
            catch (Exception) { /* visualização indisponível */ }
        }
        return list.Values.OrderBy(x=>x.Name,StringComparer.CurrentCultureIgnoreCase).Take(2000).ToArray();
    }

    public static IReadOnlyList<NetworkAdapterInfo> Adapters() => NetworkInterface.GetAllNetworkInterfaces()
        .Where(x => x.NetworkInterfaceType != NetworkInterfaceType.Loopback)
        .Select(x => new NetworkAdapterInfo(x.Name, x.OperationalStatus.ToString(), x.Speed > 0 ? $"{x.Speed / 1_000_000d:0.#} Mbps" : "—", x.Description))
        .OrderBy(x=>x.Name,StringComparer.CurrentCultureIgnoreCase).ToArray();

    public static RuntimeInfo Runtime()
    {
        var disks = new List<DiskInfo>();
        foreach (var disk in DriveInfo.GetDrives())
        {
            try { if (disk.IsReady && disk.DriveType == DriveType.Fixed) disks.Add(new DiskInfo(disk.Name,disk.TotalSize,disk.AvailableFreeSpace)); }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
        var (total, used) = SystemMetrics.Memory();
        return new RuntimeInfo(Environment.MachineName, Environment.OSVersion.VersionString, Environment.ProcessorCount, used, total, disks);
    }
}
