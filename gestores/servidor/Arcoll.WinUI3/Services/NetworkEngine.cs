// Caminho: gestores/servidor/Arcoll.WinUI3/Services/NetworkEngine.cs
using System.Collections.Concurrent;
using System.Diagnostics;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text;
using System.Text.RegularExpressions;
using System.Runtime.InteropServices;
using Arcoll.WinUI3.Models;

namespace Arcoll.WinUI3.Services;

public sealed record LocalNet(string Ip, string Gateway, uint Network, uint Mask);

/// <summary>
/// Motor de rede nativo (substitui o servidor HTTP local arcoll_gestor.py):
/// vizinhos, varredura ICMP+ARP, ping, DNS, portas de servico e Wake-on-LAN.
/// Nenhuma porta de rede e aberta; tudo e executado dentro do proprio processo.
/// </summary>
public static class NetworkEngine
{
    public static readonly IReadOnlyDictionary<int, string> ServicePorts = new Dictionary<int, string>
    {
        [8787] = "Arcoll Gestor", [21] = "FTP", [22] = "SSH", [23] = "Telnet", [53] = "DNS", [80] = "HTTP",
        [139] = "NetBIOS", [443] = "HTTPS", [445] = "SMB", [515] = "LPD", [554] = "RTSP", [631] = "IPP",
        [1900] = "UPnP", [2869] = "UPnP/HTTP", [3389] = "RDP", [5357] = "WSD", [8008] = "Chromecast",
        [8009] = "Chromecast", [8080] = "HTTP-alt", [8443] = "HTTPS-alt", [9100] = "JetDirect",
        [62078] = "Apple-mux", [5900] = "VNC"
    };

    private static readonly Regex HostRe = new(@"^[A-Za-z0-9._-]{1,253}$", RegexOptions.Compiled);
    private static readonly Regex ArpRe = new(@"(?<ip>\d{1,3}(?:\.\d{1,3}){3})\s+.*?(?<mac>[0-9a-fA-F]{2}(?:[:-][0-9a-fA-F]{2}){5})(?![0-9a-fA-F])", RegexOptions.Compiled);
    private static readonly ConcurrentDictionary<string, (DateTime At, string? Name)> NameCache = new();
    private static readonly SemaphoreSlim ScanLock = new(1, 1);
    private static (DateTime At, string Ip, IReadOnlyList<DeviceInfo> Rows) _scanCache = (DateTime.MinValue, "", Array.Empty<DeviceInfo>());

    // ---------- Utilidades de endereco ----------
    private static uint ToUint(IPAddress a) { var b = a.GetAddressBytes(); return ((uint)b[0] << 24) | ((uint)b[1] << 16) | ((uint)b[2] << 8) | b[3]; }
    private static string FromUint(uint v) => new IPAddress(new[] { (byte)(v >> 24), (byte)(v >> 16), (byte)(v >> 8), (byte)v }).ToString();

    public static bool IsPrivate(IPAddress a)
    {
        if (a.AddressFamily != AddressFamily.InterNetwork) return false;
        var b = a.GetAddressBytes();
        return b[0] == 10 || (b[0] == 172 && b[1] >= 16 && b[1] <= 31) || (b[0] == 192 && b[1] == 168) || (b[0] == 169 && b[1] == 254);
    }

    public static string? ValidMac(string? mac)
    {
        var raw = Regex.Replace(mac ?? "", "[^0-9a-fA-F]", "").ToUpperInvariant();
        if (raw.Length != 12 || raw == "000000000000" || raw == "FFFFFFFFFFFF") return null;
        if ((Convert.ToInt32(raw[..2], 16) & 1) != 0) return null; // multicast/broadcast nao e host
        return string.Join(":", Enumerable.Range(0, 6).Select(i => raw.Substring(i * 2, 2)));
    }

    /// <summary>Interface IPv4 ativa com gateway padrao (equivalente a local_ip/gateway/local_network do gestor Python).</summary>
    public static LocalNet Local()
    {
        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != OperationalStatus.Up) continue;
                if (ni.NetworkInterfaceType is NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel) continue;
                var props = ni.GetIPProperties();
                var gw = props.GatewayAddresses.FirstOrDefault(g => g.Address.AddressFamily == AddressFamily.InterNetwork && !g.Address.Equals(IPAddress.Any));
                var uni = props.UnicastAddresses.FirstOrDefault(u => u.Address.AddressFamily == AddressFamily.InterNetwork && u.IPv4Mask != null);
                if (gw == null || uni == null) continue;
                var mask = ToUint(uni.IPv4Mask);
                return new LocalNet(uni.Address.ToString(), gw.Address.ToString(), ToUint(uni.Address) & mask, mask);
            }
        }
        catch (NetworkInformationException) { }
        var ip = FallbackIp();
        var parts = ip.Split('.');
        var net = ToUint(IPAddress.Parse(ip)) & 0xFFFFFF00u;
        return new LocalNet(ip, parts.Length == 4 ? $"{parts[0]}.{parts[1]}.{parts[2]}.1" : "", net, 0xFFFFFF00u);
    }

    private static string FallbackIp()
    {
        try
        {
            using var s = new Socket(AddressFamily.InterNetwork, SocketType.Dgram, ProtocolType.Udp);
            s.Connect("10.255.255.255", 1);
            return (s.LocalEndPoint as IPEndPoint)?.Address.ToString() ?? "127.0.0.1";
        }
        catch (SocketException) { return "127.0.0.1"; }
    }

    private static bool InSubnet(LocalNet net, string ip) => IPAddress.TryParse(ip, out var a) && a.AddressFamily == AddressFamily.InterNetwork && (ToUint(a) & net.Mask) == net.Network;

    private static List<string> Hosts(LocalNet net)
    {
        var mask = net.Mask; var network = net.Network;
        // Sub-redes grandes nao sao enumeradas: varre somente o /24 do gestor (mesma regra do gestor Python).
        if (~mask + 1u > 256u || mask == 0) { mask = 0xFFFFFF00u; network = ToUint(IPAddress.Parse(net.Ip)) & mask; }
        var list = new List<string>();
        for (uint h = network + 1; h < (network | ~mask); h++) list.Add(FromUint(h));
        return list;
    }

    // ---------- Processos auxiliares ----------
    private static async Task<string> RunAsync(string file, string args, int timeoutMs, CancellationToken ct = default)
    {
        try
        {
            using var p = new Process { StartInfo = new ProcessStartInfo(file, args) { RedirectStandardOutput = true, RedirectStandardError = true, UseShellExecute = false, CreateNoWindow = true } };
            p.Start();
            var read = p.StandardOutput.ReadToEndAsync();
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(timeoutMs);
            try { await p.WaitForExitAsync(cts.Token); }
            catch (OperationCanceledException) { try { p.Kill(true); } catch (Exception) { } return ""; }
            return await read;
        }
        catch (Exception) { return ""; }
    }

    // ---------- Vizinhos ----------
    public static async Task<Dictionary<string, string>> ArpTableAsync(CancellationToken ct = default)
    {
        var result = new Dictionary<string, string>();
        var output = await RunAsync("arp", "-a", 6000, ct);
        foreach (var line in output.Split('\n'))
        {
            var m = ArpRe.Match(line);
            if (!m.Success || !IPAddress.TryParse(m.Groups["ip"].Value, out var ip) || !IsPrivate(ip)) continue;
            if (ip.GetAddressBytes()[3] == 255 || ip.GetAddressBytes()[0] >= 224) continue;
            var mac = ValidMac(m.Groups["mac"].Value);
            if (mac != null) result[ip.ToString()] = mac;
        }
        return result;
    }

    [DllImport("iphlpapi.dll", ExactSpelling = true)]
    private static extern uint SendARP(uint destination, uint source, [Out] byte[] mac, ref uint length);

    // Uma entrada de arp -a pode continuar em cache após a saída do equipamento.
    // Somente uma resposta ICMP ou uma resolução ARP ativa confirma a presença atual.
    private static string? ResolveLiveMac(string ip, string source)
    {
        try
        {
            var buffer = new byte[6]; uint length = 6;
            var result = SendARP(BitConverter.ToUInt32(IPAddress.Parse(ip).GetAddressBytes(), 0),
                BitConverter.ToUInt32(IPAddress.Parse(source).GetAddressBytes(), 0), buffer, ref length);
            return result == 0 && length == 6 ? ValidMac(Convert.ToHexString(buffer)) : null;
        }
        catch (Exception ex) when (ex is DllNotFoundException or EntryPointNotFoundException or System.Runtime.InteropServices.SEHException) { return null; }
    }

    // ---------- Ping / DNS / Portas / WOL ----------
    public static async Task<long?> PingAsync(string host, int timeoutMs = 900)
    {
        if (!HostRe.IsMatch(host)) return null;
        try
        {
            using var ping = new Ping();
            var reply = await ping.SendPingAsync(host, timeoutMs);
            return reply.Status == IPStatus.Success ? (long?)Math.Max(1L, reply.RoundtripTime) : null;
        }
        catch (Exception) { return null; }
    }

    public static async Task<string?> ReverseNameAsync(string ip, CancellationToken ct = default)
    {
        if (NameCache.TryGetValue(ip, out var c) && DateTime.UtcNow - c.At < TimeSpan.FromMinutes(5)) return c.Name;
        string? name = null;
        try
        {
            var task = Dns.GetHostEntryAsync(ip, ct);
            if (await Task.WhenAny(task, Task.Delay(1500, ct)) == task) name = (await task).HostName.Split('.')[0];
        }
        catch (Exception) { }
        if (NameCache.Count >= 512) NameCache.Clear();
        NameCache[ip] = (DateTime.UtcNow, name);
        return name;
    }

    public static async Task<(string Name, IReadOnlyList<string> Ips, IReadOnlyList<string> Reverse)> DnsAsync(string name, CancellationToken ct = default)
    {
        if (!HostRe.IsMatch(name)) throw new ArgumentException("Nome inválido.");
        var addrs = await Dns.GetHostAddressesAsync(name, ct);
        var ips = addrs.Where(a => a.AddressFamily == AddressFamily.InterNetwork).Select(a => a.ToString()).Distinct().OrderBy(x => x).ToList();
        var rev = new List<string>();
        foreach (var ip in ips) rev.Add(await ReverseNameAsync(ip, ct) ?? "—");
        return (name, ips, rev);
    }

    public static async Task<IReadOnlyList<OpenPort>> PortsAsync(string host, CancellationToken ct = default)
    {
        if (!HostRe.IsMatch(host)) throw new ArgumentException("Host inválido.");
        var addr = (await Dns.GetHostAddressesAsync(host, ct)).FirstOrDefault(a => a.AddressFamily == AddressFamily.InterNetwork)
                   ?? throw new ArgumentException("Host sem endereço IPv4.");
        var net = Local();
        if (!IsPrivate(addr) || !InSubnet(net, addr.ToString())) throw new ArgumentException("Apenas endereços da sub-rede local são permitidos.");
        var found = new ConcurrentBag<OpenPort>();
        using var gate = new SemaphoreSlim(8);
        await Task.WhenAll(ServicePorts.Select(async kv =>
        {
            await gate.WaitAsync(ct);
            try
            {
                using var client = new TcpClient();
                using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
                cts.CancelAfter(400);
                try { await client.ConnectAsync(addr, kv.Key, cts.Token); found.Add(new OpenPort(kv.Key, kv.Value)); }
                catch (Exception) { }
            }
            finally { gate.Release(); }
        }));
        return found.OrderBy(x => x.Port).ToList();
    }

    public static async Task WakeOnLanAsync(string mac)
    {
        var raw = Regex.Replace(mac ?? "", "[^0-9a-fA-F]", "");
        if (raw.Length != 12) throw new ArgumentException("MAC inválido.");
        var bytes = Convert.FromHexString(raw);
        var packet = new byte[6 + 16 * 6];
        for (var i = 0; i < 6; i++) packet[i] = 0xFF;
        for (var i = 0; i < 16; i++) Buffer.BlockCopy(bytes, 0, packet, 6 + i * 6, 6);
        using var udp = new UdpClient { EnableBroadcast = true };
        await udp.SendAsync(packet, packet.Length, new IPEndPoint(IPAddress.Broadcast, 9));
    }

    // ---------- Varredura ----------
    public static string DeviceKind(string? name, string ip, string gw, string me, IEnumerable<int> ports)
    {
        if (ip == gw) return "router";
        if (ip == me) return "computer";
        var text = (name ?? "").ToLowerInvariant();
        var opened = new HashSet<int>(ports);
        var groups = new (string Kind, string[] Words)[]
        {
            ("printer", new[]{"print","printer","impressora"}), ("phone", new[]{"iphone","android","galaxy","pixel","phone"}),
            ("tablet", new[]{"ipad","tablet"}), ("tv", new[]{"smarttv","smart-tv","webos","bravia","roku","tizen","tv"}),
            ("camera", new[]{"cam","nvr","camera"}), ("nas", new[]{"nas","synology","qnap"}),
            ("speaker", new[]{"echo","homepod","speaker","sonos"}), ("laptop", new[]{"laptop","notebook","macbook"}),
            ("server", new[]{"server","servidor"}), ("iot", new[]{"sensor","tuya","esp-","smartplug","thermostat"}),
            ("game", new[]{"xbox","playstation","nintendo"}), ("watch", new[]{"watch","wearable"}),
            ("computer", new[]{"desktop","workstation","pc-"})
        };
        foreach (var g in groups) if (g.Words.Any(w => text.Contains(w))) return g.Kind;
        if (opened.Overlaps(new[] { 515, 631, 9100 })) return "printer";
        if (opened.Contains(554)) return "camera";
        if (opened.Overlaps(new[] { 8008, 8009 })) return "tv";
        if (opened.Overlaps(new[] { 445, 3389, 22, 8787 })) return "computer";
        if (opened.Overlaps(new[] { 80, 443, 8080, 8443 }) && opened.Overlaps(new[] { 53, 1900 })) return "router";
        return "device";
    }

    private static string? LocalAdapterMac(string ip)
    {
        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
                if (ni.GetIPProperties().UnicastAddresses.Any(u => u.Address.ToString() == ip))
                    return ValidMac(ni.GetPhysicalAddress().ToString());
        }
        catch (NetworkInformationException) { }
        return null;
    }

    private static async Task<string?> NetbiosNameAsync(string ip, CancellationToken ct)
    {
        var output = await RunAsync("nbtstat", "-A " + ip, 3000, ct);
        foreach (var line in output.Split('\n'))
        {
            var m = Regex.Match(line, @"^\s*([^\s<]+)\s+<00>\s+UNIQUE", RegexOptions.IgnoreCase);
            if (m.Success && m.Groups[1].Value != "__MSBROWSE__") return m.Groups[1].Value.Trim();
        }
        return null;
    }

    /// <summary>Varredura ICMP + tabela ARP. Resultado em cache por 60 s, salvo refresh.</summary>
    public static async Task<IReadOnlyList<DeviceInfo>> ScanAsync(IProgress<(int Done, int Total)>? progress, bool refresh, CancellationToken ct)
    {
        var net = Local();
        if (!refresh && _scanCache.Ip == net.Ip && DateTime.UtcNow - _scanCache.At < TimeSpan.FromSeconds(60)) return _scanCache.Rows;
        await ScanLock.WaitAsync(ct);
        try
        {
            if (!refresh && _scanCache.Ip == net.Ip && DateTime.UtcNow - _scanCache.At < TimeSpan.FromSeconds(60)) return _scanCache.Rows;
            var me = net.Ip; var gw = net.Gateway;
            var ips = IsPrivate(IPAddress.Parse(me)) ? Hosts(net) : new List<string> { me };
            foreach (var known in (await ArpTableAsync(ct)).Keys)
                if (InSubnet(net, known) && !ips.Contains(known) && ips.Count < 512) ips.Add(known);

            var latencies = new ConcurrentDictionary<string, long?>();
            using (var gate = new SemaphoreSlim(24))
            {
                var done = 0;
                await Task.WhenAll(ips.Select(async ip =>
                {
                    await gate.WaitAsync(ct);
                    try { latencies[ip] = await PingAsync(ip, 650); }
                    finally { gate.Release(); progress?.Report((Interlocked.Increment(ref done), ips.Count)); }
                }));
            }
            var arp = await ArpTableAsync(ct);
            var arpReplies = new ConcurrentDictionary<string, string>();
            using (var arpGate = new SemaphoreSlim(16))
            {
                // Hosts sem ICMP só ficam online com resposta ARP; cache antigo não basta.
                await Task.WhenAll(arp.Keys.Where(ip => InSubnet(net, ip) && ip != me &&
                    (!latencies.TryGetValue(ip, out var latency) || latency is null)).Select(async ip =>
                {
                    await arpGate.WaitAsync(ct);
                    try { var mac = await Task.Run(() => ResolveLiveMac(ip, me), ct); if (mac != null) arpReplies[ip] = mac; }
                    finally { arpGate.Release(); }
                }));
            }
            foreach (var reply in arpReplies) arp[reply.Key] = reply.Value;
            var live = ips.Where(ip => ip == me || (latencies.TryGetValue(ip, out var l) && l != null) || arpReplies.ContainsKey(ip))
                          .Distinct().OrderBy(ip => ToUint(IPAddress.Parse(ip))).ToList();

            var names = new ConcurrentDictionary<string, string>();
            using (var gate = new SemaphoreSlim(8))
            {
                await Task.WhenAll(live.Take(48).Select(async ip =>
                {
                    await gate.WaitAsync(ct);
                    try { var n = await ReverseNameAsync(ip, ct); if (!string.IsNullOrWhiteSpace(n)) names[ip] = n!; }
                    finally { gate.Release(); }
                }));
                var unknown = live.Where(ip => !names.ContainsKey(ip) && ip != me && ip != gw).Take(16).ToList();
                await Task.WhenAll(unknown.Select(async ip =>
                {
                    await gate.WaitAsync(ct);
                    try { var n = await NetbiosNameAsync(ip, ct); if (!string.IsNullOrWhiteSpace(n)) names[ip] = n!; }
                    finally { gate.Release(); }
                }));
            }

            var rows = new List<DeviceInfo>();
            var seen = new HashSet<string>();
            foreach (var ip in live)
            {
                var mac = ValidMac(arp.TryGetValue(ip, out var m) ? m : null);
                if (mac == null && ip == me) mac = LocalAdapterMac(me);
                if (mac == null || !seen.Add(mac)) continue; // sem identidade permanente ou duplicado por outro IP
                names.TryGetValue(ip, out var name);
                var kind = DeviceKind(name, ip, gw, me, Array.Empty<int>());
                var label = !string.IsNullOrWhiteSpace(name) ? name! : ip == gw ? "Gateway" : ip == me ? "Este computador" : "Dispositivo " + ip[(ip.LastIndexOf('.') + 1)..];
                var privateMac = (Convert.ToInt32(mac[..2], 16) & 2) != 0;
                latencies.TryGetValue(ip, out var lat);
                rows.Add(new DeviceInfo(label, ip, mac, kind, lat, privateMac ? "MAC privado/aleatório típico de celular" : ""));
            }
            _scanCache = (DateTime.UtcNow, net.Ip, rows);
            return rows;
        }
        finally { ScanLock.Release(); }
    }
}
