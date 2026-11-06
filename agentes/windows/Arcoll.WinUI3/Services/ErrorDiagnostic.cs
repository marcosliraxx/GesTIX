// Caminho: agentes/windows/Arcoll.WinUI3/Services/ErrorDiagnostic.cs
using System.Text.RegularExpressions;

namespace Arcoll.WinUI3.Services;

/// <summary>Erro técnico compacto para o gestor; não inclui arquivos locais, credenciais ou dumps de memória.</summary>
public static class ErrorDiagnostic
{
    public static string Code(Exception error) => error switch
    {
        PlatformException platform when platform.Status.HasValue => "HTTP_" + (int)platform.Status.Value,
        TimeoutException => "TIMEOUT",
        HttpRequestException => "NETWORK_ERROR",
        UnauthorizedAccessException => "ACCESS_DENIED",
        System.IO.IOException => "IO_ERROR",
        _ => "AGENT_" + Regex.Replace(error.GetType().Name.ToUpperInvariant(), "[^A-Z0-9_]", "_")[..Math.Min(Regex.Replace(error.GetType().Name, "[^A-Za-z0-9_]", "_").Length, 30)]
    };

    public static string FromException(Exception error)
    {
        var lines = new List<string> { error.GetType().FullName + ": " + error.Message };
        if (error.HResult != 0) lines.Add("HRESULT: 0x" + error.HResult.ToString("X8"));
        if (error.InnerException is not null)
            lines.Add("Causa interna: " + error.InnerException.GetType().Name + ": " + error.InnerException.Message);
        // Stack frames may contain user paths or source fragments; exclude them intentionally.
        return Sanitize(string.Join(Environment.NewLine, lines));
    }

    public static string Sanitize(string input, int max = 5500)
    {
        input = Regex.Replace(input, @"(?i)\b(password|passwd|senha|secret|token|api[_-]?key|download_key|authorization)\b\s*[:=]\s*[^\s,;]+", "$1=[REDACTED]");
        input = Regex.Replace(input, @"(?i)(bearer\s+)[A-Za-z0-9._~+/-]+", "$1[REDACTED]");
        input = Regex.Replace(input, @"(?i)([?&](?:key|token|secret|password|download_key)=)[^&\s]+", "$1[REDACTED]");
        input = Regex.Replace(input, @"(?i)[A-Z]:\\Users\\[^\\\r\n]+", @"C:\Users\[usuario]");
        input = Regex.Replace(input, @"(?i)(/home/|/Users/)[^/\s\r\n]+", "$1[usuario]");
        input = new string(input.Where(ch => ch is '\n' or '\r' or '\t' || !char.IsControl(ch)).ToArray());
        return input.Length > max ? input[..max] : input;
    }
}
