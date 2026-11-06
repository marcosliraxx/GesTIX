// Caminho: agentes/windows/Arcoll.WinUI3/Services/AuthorizationService.cs
namespace Arcoll.WinUI3.Services;

public enum AuthState { Checking, Approved, UiLocked, Pending, Blocked, Rejected, Unauthorized, Uninstall, Unavailable }

/// <summary>
/// Estado de autorização do computador perante a plataforma (verify_installation_status do agente Python).
/// Sem registro, uma chave válida cria apenas uma solicitação PENDENTE; somente o gestor aprova em Redes &gt; Liberação.
/// </summary>
public sealed class AuthorizationService
{
    private readonly PlatformClient _platform;
    private DateTime _nextRegistration = DateTime.MinValue;
    public AuthState State { get; private set; } = AuthState.Checking;
    public string Detail { get; private set; } = "";
    public string NetworkName { get; private set; } = "";
    /// <summary>Verdadeiro depois da primeira aprovação: uma queda de rede passageira não bloqueia a interface.</summary>
    public bool EverApproved { get; private set; }
    /// <summary>Estados em que a interface fica bloqueada (falha fechada, como no agente Python).</summary>
    public bool Gated => State is AuthState.Pending or AuthState.Blocked or AuthState.Rejected or AuthState.Unauthorized or AuthState.Uninstall
        || (State == AuthState.Unavailable && !EverApproved);
    public bool Allowed => State is AuthState.Approved or AuthState.UiLocked;

    public AuthorizationService(PlatformClient platform) { _platform = platform; }

    public static string Notice(AuthState state, string detail) => state switch
    {
        AuthState.Blocked or AuthState.Rejected or AuthState.Unauthorized => "ACESSO BLOQUEADO\nEste agente não está autorizado pelo gestor da rede." + (detail.Length > 0 ? "\n" + detail : ""),
        AuthState.Pending => "AGUARDANDO LIBERAÇÃO\nEste computador ainda não foi autorizado pelo gestor (Redes › Liberação).",
        AuthState.Uninstall => "DESINSTALAÇÃO SOLICITADA\nO gestor da rede solicitou a remoção deste agente.",
        AuthState.Checking => "Verificando autorização…",
        _ => "ACESSO INDISPONÍVEL\nNão foi possível confirmar a autorização. Verifique a conexão." + (detail.Length > 0 ? "\n" + detail : ""),
    };

    public async Task<AuthState> RefreshAsync(CancellationToken ct)
    {
        _platform.Reload();
        var cfg = _platform.Config;
        Detail = "";
        if (!cfg.IsComplete)
        {
            var missing = string.Join(", ", new[] { cfg.Origin.Length == 0 ? "origem HTTPS" : "", cfg.SiteCode.Length == 0 ? "código do local" : "", cfg.DownloadKey.Length == 0 ? "chave" : "" }.Where(x => x.Length > 0));
            RuntimeLog.Write("Configuração incompleta (" + missing + ").");
            return Set(AuthState.Unavailable, "Configuração incompleta (" + (missing.Length > 0 ? missing : "formato inválido") + "). Reinstale pelo painel de Redes.");
        }
        try
        {
            var r = await _platform.PostAsync("agent-poll", cfg.Credentials(), ct, 8);
            NetworkName = r.TryGetProperty("network_name", out var n) && n.ValueKind == System.Text.Json.JsonValueKind.String ? n.GetString() ?? "" : "";
            if (r.TryGetProperty("uninstall", out var u) && u.ValueKind == System.Text.Json.JsonValueKind.True) return Set(AuthState.Uninstall);
            var status = r.TryGetProperty("status", out var s) && s.ValueKind == System.Text.Json.JsonValueKind.String ? (s.GetString() ?? "").ToLowerInvariant() : "";
            var locked = r.TryGetProperty("ui_locked", out var l) && l.ValueKind == System.Text.Json.JsonValueKind.True;
            return status switch
            {
                "approved" => Set(locked ? AuthState.UiLocked : AuthState.Approved),
                "pending" => Set(AuthState.Pending),
                "blocked" => Set(AuthState.Blocked),
                "rejected" => Set(AuthState.Rejected),
                "removed" => Set(AuthState.Uninstall),
                _ => Set(AuthState.Unavailable),
            };
        }
        catch (PlatformException ex) when (ex.Status is System.Net.HttpStatusCode.Forbidden or System.Net.HttpStatusCode.NotFound)
        {
            // Sem registro a API não cria a solicitação sozinha: uma chave válida registra o computador como PENDENTE.
            return await RegisterAsync(ct);
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            // Qualquer falha vira um estado visível com motivo, nunca um bloqueio mudo.
            var reason = ex is TaskCanceledException or OperationCanceledException ? "Tempo esgotado ao consultar a plataforma." : ex.Message;
            RuntimeLog.Write("agent-poll falhou: " + ex.GetType().Name + ": " + reason);
            return Set(AuthState.Unavailable, reason);
        }
    }

    private async Task<AuthState> RegisterAsync(CancellationToken ct)
    {
        if (DateTime.UtcNow < _nextRegistration) return Set(AuthState.Unauthorized);
        _nextRegistration = DateTime.UtcNow.AddSeconds(60);
        try
        {
            var body = _platform.WithCredentials(("machine_name", Environment.MachineName), ("platform", "Windows"), ("version", Models.AppInfo.Version));
            var r = await _platform.PostAsync("agent-register", body, ct, 10);
            var status = r.TryGetProperty("status", out var s) && s.ValueKind == System.Text.Json.JsonValueKind.String ? (s.GetString() ?? "pending").ToLowerInvariant() : "pending";
            RuntimeLog.Write("agent-register: " + status);
            return status switch
            {
                "approved" => Set(AuthState.Approved), "blocked" => Set(AuthState.Blocked), "rejected" => Set(AuthState.Rejected), _ => Set(AuthState.Pending),
            };
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            var reason = ex is TaskCanceledException or OperationCanceledException ? "Tempo esgotado no cadastro." : ex.Message;
            RuntimeLog.Write("agent-register falhou: " + ex.GetType().Name + ": " + reason);
            return Set(AuthState.Unauthorized, reason);
        }
    }

    private AuthState Set(AuthState state, string detail = "") { State = state; Detail = detail; if (Allowed) EverApproved = true; return state; }
}
