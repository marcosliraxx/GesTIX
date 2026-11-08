// Caminho: gestores/servidor/Arcoll.WinUI3/Services/SecurityInspectionService.cs
// Etapa 2 — Inventário local de segurança: somente leitura, sem alterar Defender ou Firewall.
using System.Text.Json;
namespace Arcoll.WinUI3.Services;

public static class SecurityInspectionService
{
    // Usamos exclusivamente comandos fixos e sem parâmetros originados na WebView.
    // Falhas individuais permanecem como "indisponível", nunca como "protegido".
    private const string Script = @"
$ErrorActionPreference = 'Stop'
$report = [ordered]@{ captured_at = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds();
  defender = $null; firewall = @(); antivirus = @(); tpm = $null; errors = @() }
try {
  $d = Get-MpComputerStatus -ErrorAction Stop
  $report.defender = [ordered]@{
    antivirus_enabled = [bool]$d.AntivirusEnabled
    real_time_enabled = [bool]$d.RealTimeProtectionEnabled
    antispyware_enabled = [bool]$d.AntispywareEnabled
    signatures_outdated = [bool]$d.DefenderSignaturesOutOfDate
    signatures_updated = if ($d.AntivirusSignatureLastUpdated) { $d.AntivirusSignatureLastUpdated.ToString('o') } else { $null }
    quick_scan_days = if ($null -ne $d.QuickScanAge) { [int]$d.QuickScanAge } else { $null }
    full_scan_days = if ($null -ne $d.FullScanAge) { [int]$d.FullScanAge } else { $null }
  }
} catch { $report.errors += 'Defender: consulta indisponível.' }
try {
  $report.firewall = @(Get-NetFirewallProfile -ErrorAction Stop | ForEach-Object {
    [ordered]@{ name = [string]$_.Name; enabled = [bool]$_.Enabled;
      inbound = [string]$_.DefaultInboundAction; outbound = [string]$_.DefaultOutboundAction }
  })
} catch { $report.errors += 'Firewall: consulta indisponível.' }
try {
  $report.antivirus = @(Get-CimInstance -Namespace 'root/SecurityCenter2' -ClassName AntivirusProduct -ErrorAction Stop |
    ForEach-Object { [ordered]@{ name = [string]$_.displayName; product_state = [int]$_.productState } })
} catch { $report.errors += 'Central de Segurança: inventário indisponível.' }
try {
  $t = Get-Tpm -ErrorAction Stop
  $report.tpm = [ordered]@{ present = [bool]$t.TpmPresent; ready = [bool]$t.TpmReady; enabled = [bool]$t.TpmEnabled }
} catch { $report.errors += 'TPM: consulta indisponível.' }
$report | ConvertTo-Json -Compress -Depth 5
";

    public static async Task<JsonElement> InspectAsync(CancellationToken ct)
    {
        var response = await ToolkitService.PsAsync(Script, ct);
        if (response.TryGetValue("data", out var data) && data is JsonElement element && element.ValueKind == JsonValueKind.Object)
            return element.Clone();
        var reason = response.TryGetValue("error", out var error) ? error?.ToString() :
            response.TryGetValue("output", out var output) ? output?.ToString() : "sem resposta do Windows";
        throw new InvalidOperationException("Não foi possível consultar os componentes de segurança: " + reason);
    }
}
