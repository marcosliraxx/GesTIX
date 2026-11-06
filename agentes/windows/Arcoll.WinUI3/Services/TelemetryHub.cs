// Caminho: agentes/windows/Arcoll.WinUI3/Services/TelemetryHub.cs
namespace Arcoll.WinUI3.Services;

public sealed record TelemetryPoint(DateTime Time, double? Cpu, double Mem, IReadOnlyDictionary<string, double> Disks, double Down, double Up, double? Latency, double Loss);

/// <summary>
/// Fonte única das medições exibidas nas abas Servidor e Monitoramento: amostra o computador a cada 2 s,
/// mede latência/perda da Internet a cada 3 s e guarda o histórico recente em memória.
/// </summary>
public sealed class TelemetryHub : IDisposable
{
    public const int Capacity = 120;
    private const string InternetHost = "1.1.1.1";
    private readonly SystemMetrics _metrics = new();
    private readonly List<TelemetryPoint> _points = new();
    private readonly Queue<bool> _pings = new();
    private readonly object _gate = new();
    private readonly CancellationTokenSource _cts = new();
    private double? _latency;
    private bool _started;

    public MetricSample? Last { get; private set; }
    public bool InternetUp { get; private set; } = true;
    /// <summary>Disparado fora da thread de interface.</summary>
    public event Action? Updated;

    public void Start()
    {
        if (_started) return; _started = true;
        _ = Task.Run(() => SampleLoopAsync(_cts.Token));
        _ = Task.Run(() => PingLoopAsync(_cts.Token));
    }

    public IReadOnlyList<TelemetryPoint> Snapshot() { lock (_gate) return _points.ToArray(); }

    private async Task SampleLoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var s = _metrics.Sample(); Last = s;
                double loss; double? latency;
                lock (_gate) { latency = _latency; loss = _pings.Count == 0 ? 0 : 100.0 * _pings.Count(x => !x) / _pings.Count; }
                var disks = s.Drives.ToDictionary(d => d.Name, d => d.Percent);
                var mem = s.MemTotal > 0 ? 100.0 * s.MemUsed / s.MemTotal : 0;
                lock (_gate)
                {
                    _points.Add(new TelemetryPoint(DateTime.Now, s.Cpu, mem, disks, s.DownloadMbps, s.UploadMbps, latency, loss));
                    if (_points.Count > Capacity) _points.RemoveRange(0, _points.Count - Capacity);
                }
                Updated?.Invoke();
            }
            catch (Exception) { /* leitura pontual indisponível: tenta no próximo ciclo */ }
            try { await Task.Delay(TimeSpan.FromSeconds(2), ct); } catch (OperationCanceledException) { break; }
        }
    }

    private async Task PingLoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var ms = await NetworkEngine.PingAsync(InternetHost, 1200);
                lock (_gate)
                {
                    _pings.Enqueue(ms is not null); while (_pings.Count > 20) _pings.Dequeue();
                    if (ms is long v) _latency = v;
                    InternetUp = _pings.Any(x => x);
                    if (!InternetUp) _latency = null;
                }
            }
            catch (Exception) { /* ping indisponível: conta como perda no próximo ciclo */ }
            try { await Task.Delay(TimeSpan.FromSeconds(3), ct); } catch (OperationCanceledException) { break; }
        }
    }

    public void Dispose() { _cts.Cancel(); _cts.Dispose(); }
}
