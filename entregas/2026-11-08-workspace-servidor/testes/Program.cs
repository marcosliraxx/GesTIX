using Arcoll.WinUI3.Services;
int checks=0;void Check(bool ok,string message){checks++;if(!ok)throw new Exception(message);}
foreach(var n in new[]{0,1,5,10,30,60,120})Check(PerformanceRules.IsCadence(n),"cadence "+n);
foreach(var n in new[]{-1,2,15,1000,int.MaxValue})Check(!PerformanceRules.IsCadence(n),"invalid cadence");
Check(PerformanceRules.Rate(1_250_000,0,5)==2,"rate uses elapsed time");Check(PerformanceRules.Rate(1_250_000,0,10)==1,"10 seconds rate");Check(PerformanceRules.Rate(0,1,10)==null,"counter reset");Check(PerformanceRules.Rate(1,0,0)==null,"warmup");
Check(PerformanceRules.GpuLuid("pid_45_luid_0x00000001_0x000ABCD2_phys_0_eng_1_engtype_3D")=="00000001:000abcd2","GPU identity normalized");Check(PerformanceRules.GpuLuid("wrong")==null,"no invented GPU identity");
Check(PerformanceRules.GpuEngine("pid_45_luid_0x00000001_0x000ABCD2_phys_0_eng_1_engtype_3D")=="0:1:3D","engine identity independent of PID");
Check(PerformanceRules.GpuEngine("pid_50_luid_0x00000001_0x000ABCD2_phys_0_eng_1_engtype_3D")=="0:1:3D","same engine no duplicate charts");Check(PerformanceRules.GpuEngine("wrong")==null,"missing engine");
Check(PerformanceRules.Busiest(new double?[]{80,70})==80,"GPU utilization cannot sum engines");Check(PerformanceRules.Busiest(new double?[]{null,null})==null,"unavailable GPU not zero");Check(PerformanceRules.Busiest(new double?[]{150})==100,"percentage capped");
Check(PerformanceRules.Number(double.NaN)==null,"no NaN");Check(PerformanceRules.Number(double.PositiveInfinity)==null,"no infinity");Check(PerformanceRules.Number(-1)==null,"no negative counters");Check(PerformanceRules.Size(null)=="Indisponível","unknown capacity");Check(PerformanceRules.Percent(null)=="Indisponível","unknown utilization");
Check(PerformanceRules.Temperature(530)==53,"WDDM deci-Celsius conversion");Check(PerformanceRules.Temperature(0)==null,"unreported temperature");Check(PerformanceRules.Temperature(uint.MaxValue)==null,"invalid driver temperature");
Console.WriteLine($"{checks} performance rule checks passed.");
