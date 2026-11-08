// Caminho: gestores/servidor/Arcoll.WinUI3/App.xaml.cs
using Microsoft.UI.Xaml;

namespace Arcoll.WinUI3;

public partial class App : Application
{
    private MainWindow? _window;
    private static Mutex? _instance;
    private static EventWaitHandle? _show;
    public App()
    {
        InitializeComponent();
        AppDomain.CurrentDomain.UnhandledException += (_, error) =>
            Services.RuntimeLog.Write("FALHA FATAL CLR: " + (error.ExceptionObject?.ToString() ?? "sem detalhes"));
        TaskScheduler.UnobservedTaskException += (_, error) =>
        {
            Services.RuntimeLog.Write("Falha de tarefa assíncrona: " + error.Exception);
            error.SetObserved();
        };
        UnhandledException += (_, error) =>
        {
            // Preserva o diagnostico de falhas de paginas carregadas por eventos XAML/async void.
            // Apenas excecoes gerenciadas recuperaveis sao tratadas; falhas fatais nao sao ocultadas.
            Services.RuntimeLog.Write("Falha na interface WinUI: " + error.Exception);
            // Não marcar como recuperada uma exceção desconhecida de XAML/COM:
            // isso pode deixar a árvore visual em estado inválido.
            if (error.Exception is ArgumentException or InvalidOperationException)
                error.Handled = true;
        };
    }

    /// <summary>
    /// Instância única e execução em segundo plano: com --background (inicialização com o Windows e pós-atualização) a janela não é exibida,
    /// mas autorização, telemetria, presença e comandos continuam ativos. O atalho abre a mesma instância já em execução.
    /// </summary>
    protected override void OnLaunched(LaunchActivatedEventArgs args)
    {
        var launchArgs=Environment.GetCommandLineArgs();
        var waitIndex=Array.IndexOf(launchArgs,"--wait-exit");
        if(waitIndex>=0&&waitIndex+1<launchArgs.Length&&int.TryParse(launchArgs[waitIndex+1],out var previousPid)&&previousPid!=Environment.ProcessId)
            try{using var previous=System.Diagnostics.Process.GetProcessById(previousPid);previous.WaitForExit(15000);}catch(ArgumentException){}
        var background = Environment.GetCommandLineArgs().Skip(1).Any(a => a.Equals("--background", StringComparison.OrdinalIgnoreCase));
        _instance = new Mutex(true, @"Local\TIDigital.Arcoll.Gestor", out var first);
        _show = new EventWaitHandle(false, EventResetMode.AutoReset, @"Local\TIDigital.Arcoll.Show");
        if (!first)
        {
            if (!background) _show.Set();
            Environment.Exit(0);
            return;
        }
        Services.RuntimeLog.Write("Inicialização do gestor: criando MainWindow");
        _window = new MainWindow();
        Services.RuntimeLog.Write("Inicialização do gestor: MainWindow criada");
        if(launchArgs.Contains("--pentefino",StringComparer.OrdinalIgnoreCase))_window.OpenPenteFino();
        if (!background) _window.Activate();
        var window = _window; var signal = _show;
        _ = Task.Run(() =>
        {
            while (signal.WaitOne()) window.DispatcherQueue.TryEnqueue(window.ShowFromBackground);
        });
    }
}
