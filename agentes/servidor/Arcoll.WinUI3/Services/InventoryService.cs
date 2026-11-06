// Caminho: agentes/servidor/Arcoll.WinUI3/Services/InventoryService.cs
using System.Text.Json;

namespace Arcoll.WinUI3.Services;

/// <summary>Registro do inventário da plataforma (mesma tabela do painel web: nome, tipo, grupo, fixado e situação).</summary>
public sealed class DeviceRecord
{
    public long Id { get; set; }
    public string Mac { get; set; } = "";
    public string Ip { get; set; } = "";
    public string DetectedName { get; set; } = "";
    public string DetectedType { get; set; } = "device";
    public string CustomName { get; set; } = "";
    public string CustomType { get; set; } = "";
    public string Group { get; set; } = "";
    public string Status { get; set; } = "offline";
    public string Source { get; set; } = "";
    public bool Pinned { get; set; }
    public long FirstSeen { get; set; }
    public long LastSeen { get; set; }
    public long LastChange { get; set; }
    public bool AgentInstalled { get; set; }
    public double? LatencyMs { get; set; }
    public string Name => CustomName.Length > 0 ? CustomName : DetectedName.Length > 0 ? DetectedName : Ip;
    public string Type => (CustomType.Length > 0 ? CustomType : DetectedType.Length > 0 ? DetectedType : "device").ToLowerInvariant();
    public bool Online => Status == "online";
}

/// <summary>Leitura e gravação do inventário pelas ações agent-devices / agent-device-save (autenticadas pela identidade do agente).</summary>
public static class InventoryService
{
    public static readonly (string Key, string Label)[] Types =
    {
        ("", "Identificação automática"), ("computer", "Computador"), ("phone", "Celular"), ("tablet", "Tablet"), ("tv", "TV"), ("router", "Roteador"),
        ("printer", "Impressora"), ("server", "Servidor"), ("camera", "Câmera"), ("iot", "IoT"), ("console", "Console"), ("speaker", "Caixa de som"),
        ("switch", "Switch"), ("ap", "Ponto de acesso"), ("nas", "NAS"), ("device", "Outro"),
    };

    public static string TypeLabel(string key) => Types.FirstOrDefault(t => t.Key == key).Label is { Length: > 0 } l ? l : "Outro";

    public static async Task<IReadOnlyList<DeviceRecord>> LoadAsync(PlatformClient platform, CancellationToken ct)
    {
        var root = await platform.PostAsync("agent-devices", platform.Config.Credentials(), ct, 15);
        var list = new List<DeviceRecord>();
        if (!root.TryGetProperty("devices", out var arr) || arr.ValueKind != JsonValueKind.Array) return list;
        foreach (var e in arr.EnumerateArray())
        {
            var d = new DeviceRecord
            {
                Id = Int(e, "id"), Mac = Str(e, "mac"), Ip = Str(e, "ip"), DetectedName = Str(e, "detected_name"), DetectedType = Str(e, "detected_type"),
                CustomName = Str(e, "custom_name"), CustomType = Str(e, "custom_type"), Group = Str(e, "group_name"), Status = Str(e, "status"),
                Source = Str(e, "source"), Pinned = Int(e, "pinned") == 1, FirstSeen = Int(e, "first_seen"), LastSeen = Int(e, "last_seen"), LastChange = Int(e, "last_change"),
            };
            if (e.TryGetProperty("payload", out var p) && p.ValueKind == JsonValueKind.Object)
            {
                d.AgentInstalled = p.TryGetProperty("agent_installed", out var a) && a.ValueKind == JsonValueKind.True;
                if (p.TryGetProperty("latency_ms", out var l) && l.ValueKind == JsonValueKind.Number) d.LatencyMs = l.GetDouble();
            }
            list.Add(d);
        }
        return list;
    }

    public static Task SaveAsync(PlatformClient platform, DeviceRecord d, bool hidden, CancellationToken ct) =>
        platform.PostAsync("agent-device-save", platform.WithCredentials(("id", d.Id), ("custom_name", d.CustomName.Trim()), ("custom_type", d.CustomType),
            ("group_name", d.Group.Trim()), ("pinned", d.Pinned ? 1 : 0), ("hidden", hidden ? 1 : 0)), ct, 15);

    private static string Str(JsonElement e, string name) => e.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() ?? "" : "";
    private static long Int(JsonElement e, string name) =>
        e.TryGetProperty(name, out var v) ? v.ValueKind == JsonValueKind.Number && v.TryGetInt64(out var n) ? n : v.ValueKind == JsonValueKind.String && long.TryParse(v.GetString(), out var m) ? m : 0 : 0;
}
