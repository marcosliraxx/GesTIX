// Caminho: tests/command_progress/Program.cs
using Arcoll.WinUI3.Services;
var events = new List<OperationNotice>();
OperationProgress.Changed += n => { lock (events) events.Add(n); };
void Assert(bool condition, string message) { if (!condition) throw new Exception(message); }
using (var root = OperationProgress.Begin("Teste remoto", "Remoto"))
{
    root.Report(35, "Primeira etapa");
    using (var child = OperationProgress.Begin("Serviço interno")) { child.ReportText("[22%] Etapa menor"); child.Succeed(); }
    Assert(events.Count(x => x.Finished) == 0, "Filho confirmou pai antes de concluir");
    Assert(events[^1].Percent == 35, "Percentual regrediu");
    await Task.Delay(1);
    OperationProgress.Current?.ReportText("@@ARP@@{\"i\":2,\"t\":4,\"c\":\"Aplicativo\"}");
    Assert(events[^1].Percent == 45, "Progresso por itens não foi medido");
    root.Report(100, "Aguardando confirmação"); Assert(events[^1].Percent == 99, "100% sem sucesso");
    root.Succeed();
}
Assert(events[^1].Finished && events[^1].Percent == 100 && !events[^1].Failed, "Conclusão incorreta");
Assert(events.Select(x => x.Id).Distinct().Count() == 1, "Serviço interno criou outro comando");
using (var root = OperationProgress.Begin("Falha")) { root.FlagFailure("Sem autorização"); root.Succeed(); }
Assert(events[^1].Failed && events[^1].Percent < 100, "Falha foi anunciada como sucesso");
using (OperationProgress.Begin("Interrompido")) { }
Assert(events[^1].Failed, "Operação sem confirmação não foi sinalizada");
var count = events.Count;
OperationProgress.Scope old;
using (old = OperationProgress.Begin("Finalizado")) old.Succeed();
old.Report(50, "Evento atrasado"); Assert(events.Count == count + 2, "Evento tardio reabriu comando");
var first = events.Count;
await Task.WhenAll(Enumerable.Range(0, 4).Select(i => Task.Run(async () => { using var op = OperationProgress.Begin("Concorrente " + i); await Task.Delay(i + 1); op.Succeed(); })));
Assert(events.Skip(first).Select(x => x.Id).Distinct().Count() == 4, "Comandos concorrentes foram misturados");
Console.WriteLine("PASS: progresso local/remoto, fases, itens, escopos aninhados, falha, interrupção e concorrência.");
