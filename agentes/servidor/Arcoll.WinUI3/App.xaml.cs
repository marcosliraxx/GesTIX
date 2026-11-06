// Caminho: agentes/servidor/Arcoll.WinUI3/App.xaml.cs
using Microsoft.UI.Xaml;

namespace Arcoll.WinUI3;

public partial class App : Application
{
    private MainWindow? _window;
    private static Mutex? _instance;
    private static EventWaitHandle? _show;
    public App() { InitializeComponent(); }

    /// <summary>
    /// Instância única e execução em segundo plano: com --background (inicialização com o Windows e pós-atualização) a janela não é exibida,
    /// mas autorização, telemetria, presença e comandos continuam ativos. O atalho abre a mesma instância já em execução.
    /// </summary>
    protected override void OnLaunched(LaunchActivatedEventArgs args)
    {
        var background = Environment.GetCommandLineArgs().Skip(1).Any(a => a.Equals("--background", StringComparison.OrdinalIgnoreCase));
        _instance = new Mutex(true, @"Local\TIDigital.Arcoll.Agent", out var first);
        _show = new EventWaitHandle(false, EventResetMode.AutoReset, @"Local\TIDigital.Arcoll.Show");
        if (!first)
        {
            if (!background) _show.Set();
            Environment.Exit(0);
            return;
        }
        _window = new MainWindow();
        if (!background) _window.Activate();
        var window = _window; var signal = _show;
        _ = Task.Run(() =>
        {
            while (signal.WaitOne()) window.DispatcherQueue.TryEnqueue(window.ShowFromBackground);
        });
    }
}
