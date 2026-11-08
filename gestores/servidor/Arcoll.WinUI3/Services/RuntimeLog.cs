// Caminho: gestores/servidor/Arcoll.WinUI3/Services/RuntimeLog.cs
using System.Text;

namespace Arcoll.WinUI3.Services;

/// <summary>Registro local simples (%LOCALAPPDATA%\TI-Digital\logs\gestor-runtime.log) para diagnosticar autorização e comandos. Nunca grava a chave de instalação.</summary>
public static class RuntimeLog
{
    private static readonly object Gate = new();
    public static string PathName => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "TI-Digital", "logs", "gestor-runtime.log");

    public static void Write(string message)
    {
        message=DiagnosticRedactor.Clean(message);
        try
        {
            lock (Gate)
            {
                Directory.CreateDirectory(Path.GetDirectoryName(PathName)!);
                var file = new FileInfo(PathName);
                if (file.Exists && file.Length > 2 * 1024 * 1024)
                {
                    var archived = System.IO.Path.Combine(file.DirectoryName!, "gestor-runtime-previous.log");
                    File.Move(PathName, archived, true);
                }
                File.AppendAllText(PathName, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss}  {message}{Environment.NewLine}", Encoding.UTF8);
            }
        }
        catch (Exception) { /* o log nunca pode derrubar o gestor */ }
    }
}
