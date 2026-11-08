// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PerformanceCollector.cs
using System.Management;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
namespace Arcoll.WinUI3.Services;

/// <summary>Read-only Windows providers. Inventory is cached; no PowerShell, drivers or fabricated counters.</summary>
public sealed class PerformanceCollector
{
 private SystemMetrics _basic=new();private DateTimeOffset _inventoryAt;private long _sequence;
 private List<Dictionary<string,object?>> _cpu=[],_ram=[],_arrays=[],_disks=[],_volumes=[],_videos=[],_storage=[];
 private readonly Dictionary<int,string[]> _diskVolumes=[];private readonly Dictionary<int,(double Formatted,bool Page)> _diskInfo=[];
 private List<GpuAdapter> _gpu=[];private readonly Queue<bool> _ping=[];
 private readonly Dictionary<string,(long Rx,long Tx)> _previous=[];private long _netStamp;
 private readonly List<string> _warnings=[];
 private List<Dictionary<string,object?>> Query(string query,string scope=@"root\cimv2",bool optional=false)
 {
  try{using var search=new ManagementObjectSearcher(new ManagementScope(scope),new ObjectQuery(query),new System.Management.EnumerationOptions{ReturnImmediately=true,Timeout=TimeSpan.FromSeconds(3)});using var result=search.Get();var rows=new List<Dictionary<string,object?>>();foreach(ManagementBaseObject row in result){using(row){var d=new Dictionary<string,object?>(StringComparer.OrdinalIgnoreCase);foreach(PropertyData p in row.Properties)d[p.Name]=p.Value;rows.Add(d);}}return rows;}
  catch(Exception e)when(e is ManagementException or COMException or UnauthorizedAccessException or System.Runtime.InteropServices.ExternalException or InvalidOperationException){if(!optional)_warnings.Add("Provedor Windows indisponível: "+query.Split(' ').Last());return [];}
 }
 public void ResetSampling(){_basic=new SystemMetrics();_previous.Clear();_netStamp=0;_response.Clear();}
 private static object? V(Dictionary<string,object?>? d,string name)=>d is not null&&d.TryGetValue(name,out var v)?v:null;
 private static string S(Dictionary<string,object?>? d,string name)=>Convert.ToString(V(d,name))?.Trim()??"";
 private static double? N(Dictionary<string,object?>? d,string name)=>PerformanceRules.Number(V(d,name));
 private static string Text(string s)=>string.IsNullOrWhiteSpace(s)?"Indisponível":s;
 private static PerformanceStat Stat(string label,string? value)=>new(label,Text(value??""));
 private static string Num(double? n,string unit="")=>n is double d?$"{d:0.##}{unit}":"Indisponível";
 private void Inventory()
 {
  if(DateTimeOffset.UtcNow-_inventoryAt<TimeSpan.FromMinutes(2))return;_inventoryAt=DateTimeOffset.UtcNow;
  _cpu=Query("SELECT Name,NumberOfCores,NumberOfLogicalProcessors,MaxClockSpeed,SocketDesignation,VirtualizationFirmwareEnabled,L2CacheSize,L3CacheSize FROM Win32_Processor");
  _ram=Query("SELECT Capacity,ConfiguredClockSpeed,Speed,FormFactor,SMBIOSMemoryType FROM Win32_PhysicalMemory");_arrays=Query("SELECT MemoryDevices FROM Win32_PhysicalMemoryArray");
  _disks=Query("SELECT Index,DeviceID,Model,SerialNumber,Size,InterfaceType,MediaType FROM Win32_DiskDrive");
  _volumes=Query("SELECT DeviceID,Size FROM Win32_LogicalDisk");_storage=Query("SELECT DeviceId,FriendlyName,SerialNumber,Size,MediaType,BusType FROM MSFT_PhysicalDisk",@"root\Microsoft\Windows\Storage",true);
  var pagefiles=Query("SELECT Name FROM Win32_PageFileUsage",optional:true);_diskVolumes.Clear();_diskInfo.Clear();
  foreach(var disk in _disks){var index=(int)(N(disk,"Index")??-1);if(index<0)continue;var volumes=new List<string>();var id=S(disk,"DeviceID").Replace("\\","\\\\").Replace("'","\\'");
   foreach(var partition in Query($"ASSOCIATORS OF {{Win32_DiskDrive.DeviceID='{id}'}} WHERE AssocClass=Win32_DiskDriveToDiskPartition",optional:true)){
    var pid=S(partition,"DeviceID").Replace("\\","\\\\").Replace("'","\\'");foreach(var volume in Query($"ASSOCIATORS OF {{Win32_DiskPartition.DeviceID='{pid}'}} WHERE AssocClass=Win32_LogicalDiskToPartition",optional:true))volumes.Add(S(volume,"DeviceID"));}
   _diskVolumes[index]=volumes.Distinct(StringComparer.OrdinalIgnoreCase).Order().ToArray();_diskInfo[index]=(_volumes.Where(x=>volumes.Contains(S(x,"DeviceID"))).Sum(x=>N(x,"Size")??0),pagefiles.Any(x=>volumes.Any(v=>S(x,"Name").StartsWith(v,StringComparison.OrdinalIgnoreCase))));}
  _videos=Query("SELECT Name,PNPDeviceID,DriverVersion,DriverDate FROM Win32_VideoController",optional:true);_gpu=DxgiAdapters.Read();
 }
 public async Task<PerformanceSnapshot> SampleAsync(int seconds,int? devices,DateTimeOffset? discoveryAt,CancellationToken ct)
 {
  _warnings.Clear();Inventory();ct.ThrowIfCancellationRequested();var basic=_basic.Sample();var at=DateTimeOffset.UtcNow;
  var cpuPerf=Query("SELECT Name,ProcessorFrequency FROM Win32_PerfFormattedData_Counters_ProcessorInformation",optional:true).FirstOrDefault(x=>S(x,"Name")=="_Total");
  var mem=Query("SELECT CommittedBytes,CommitLimit,CacheBytes,PoolPagedBytes,PoolNonpagedBytes FROM Win32_PerfFormattedData_PerfOS_Memory",optional:true).FirstOrDefault();
  var diskPerf=Query("SELECT Name,PercentIdleTime,AvgDisksecPerTransfer,DiskReadBytesPersec,DiskWriteBytesPersec FROM Win32_PerfFormattedData_PerfDisk_PhysicalDisk",optional:true);
  // Formatted AvgDisksecPerTransfer truncates sub-second values on some Windows versions; raw fraction/base gives actual milliseconds.
  var diskRaw=Query("SELECT Name,AvgDisksecPerTransfer,AvgDisksecPerTransfer_Base,Frequency_PerfTime FROM Win32_PerfRawData_PerfDisk_PhysicalDisk",optional:true);
  var engine=Query("SELECT Name,UtilizationPercentage FROM Win32_PerfFormattedData_GPUPerformanceCounters_GPUEngine",optional:true);
  var gpuMem=Query("SELECT Name,DedicatedUsage,SharedUsage FROM Win32_PerfFormattedData_GPUPerformanceCounters_GPUAdapterMemory",optional:true);
  var list=new List<PerformanceComponent>();double? latency=null;bool reachable=false;
  ct.ThrowIfCancellationRequested();try{using var ping=new Ping();var response=await ping.SendPingAsync("1.1.1.1",1000).WaitAsync(ct);reachable=response.Status==IPStatus.Success;if(reachable)latency=response.RoundtripTime;}catch(PingException){}catch(SocketException){}
  _ping.Enqueue(reachable);while(_ping.Count>20)_ping.Dequeue();var loss=100d*_ping.Count(x=>!x)/_ping.Count;
  var nics=Network();var internet=nics.FirstOrDefault(n=>n.Route);var perf=NativePerformance.Read();
  list.Add(new("internet","internet","Internet",internet?.Description??"Rota de saída não identificada",
   [Stat("Latência",Num(latency," ms")),Stat("Perdas",Num(loss,"%")),Stat("Dispositivos",devices?.ToString()??"Descoberta em andamento"),Stat("Estado",reachable?"Destino respondeu":"Destino sem resposta ICMP"),Stat("Download",Num(internet?.Down," Mbps")),Stat("Upload",Num(internet?.Up," Mbps"))],
   [new("latency","Latência","ms",latency),new("loss","Perdas","%",loss,100),new("down","Download","Mbps",internet?.Down),new("up","Upload","Mbps",internet?.Up)],"ICMP para 1.1.1.1; ausência de resposta não comprova queda. Download/upload são tráfego do adaptador da rota de saída, incluindo tráfego local; não medem a velocidade contratada."));
  var first=_cpu.FirstOrDefault();var cpuStats=new List<PerformanceStat>{Stat("Utilização",PerformanceRules.Percent(basic.Cpu)),Stat("Velocidade atual",Num(N(cpuPerf,"ProcessorFrequency")/1000," GHz")),Stat("Velocidade base",Num(N(first,"MaxClockSpeed")/1000," GHz")),Stat("Processos",Num(perf?.Processes)),Stat("Threads",Num(perf?.Threads)),Stat("Identificadores",Num(perf?.Handles)),Stat("Tempo de atividade",TimeSpan.FromMilliseconds(Environment.TickCount64).ToString(@"d\:hh\:mm\:ss")),Stat("Soquetes",_cpu.Count==0?null:_cpu.Count.ToString()),Stat("Núcleos",_cpu.Count==0?null:Num(_cpu.Sum(x=>N(x,"NumberOfCores")??0))),Stat("Processadores lógicos",_cpu.Count==0?Environment.ProcessorCount.ToString():Num(_cpu.Sum(x=>N(x,"NumberOfLogicalProcessors")??0))),Stat("Virtualização",V(first,"VirtualizationFirmwareEnabled") is bool b?(b?"Habilitada":"Desabilitada"):null)};
  foreach(var cache in NativePerformance.Caches())cpuStats.Add(Stat("Cache L"+cache.Key,PerformanceRules.Size(cache.Value)));
  list.Add(new("cpu","cpu","CPU",basic.CpuName,cpuStats.ToArray(),[new("cpu","Utilização","%",basic.Cpu,100)]));
  var memPercent=basic.MemTotal>0?100d*basic.MemUsed/basic.MemTotal:(double?)null;var installed=_ram.Sum(x=>N(x,"Capacity")??0);var speed=_ram.Select(x=>N(x,"ConfiguredClockSpeed")??N(x,"Speed")).Where(x=>x.HasValue).Distinct();
  var form=new Dictionary<int,string>{{8,"DIMM"},{12,"SO-DIMM"}};var types=new Dictionary<int,string>{{24,"DDR3"},{26,"DDR4"},{34,"DDR5"},{30,"LPDDR4"},{35,"LPDDR5"}};
  var memoryType=string.Join(" / ",_ram.Select(x=>types.GetValueOrDefault((int)(N(x,"SMBIOSMemoryType")??0),"Tipo não informado")).Distinct());
  list.Add(new("memory","memory","Memória",PerformanceRules.Size(installed>0?installed:basic.MemTotal)+" · "+memoryType,
   [Stat("Em uso",PerformanceRules.Size(basic.MemTotal>0?basic.MemUsed:null)),Stat("Disponível",PerformanceRules.Size(basic.MemTotal>0?basic.MemTotal-basic.MemUsed:null)),Stat("Confirmado",PerformanceRules.Size(N(mem,"CommittedBytes"))+" / "+PerformanceRules.Size(N(mem,"CommitLimit"))),Stat("Em cache",PerformanceRules.Size(N(mem,"CacheBytes"))),Stat("Pool paginável",PerformanceRules.Size(N(mem,"PoolPagedBytes"))),Stat("Pool não paginável",PerformanceRules.Size(N(mem,"PoolNonpagedBytes"))),Stat("Velocidade",Text(string.Join(" / ",speed.Select(x=>Num(x," MT/s"))))),Stat("Slots usados",_ram.Count==0?null:$"{_ram.Count} de {Num(_arrays.Count>0?_arrays.Sum(x=>N(x,"MemoryDevices")??0):null)}"),Stat("Fator forma",string.Join(" / ",_ram.Select(x=>form.GetValueOrDefault((int)(N(x,"FormFactor")??0),"Não informado")).Distinct())),Stat("Reservada para hardware",PerformanceRules.Size(installed>=basic.MemTotal&&installed>0?installed-basic.MemTotal:null)),Stat("Compactada",null)],
   [new("memory","Uso da memória","%",memPercent,100)],"Composição: memória física em uso e disponível. Cache do provedor Windows pode diferir de standby do Gerenciador de Tarefas; compressão não é exposta por este provedor."));
  foreach(var d in _disks.OrderBy(x=>N(x,"Index"))){ct.ThrowIfCancellationRequested();var index=(int)(N(d,"Index")??-1);if(index<0)continue;var dp=diskPerf.FirstOrDefault(x=>S(x,"Name").Split(' ')[0]==index.ToString());var raw=diskRaw.FirstOrDefault(x=>S(x,"Name").Split(' ')[0]==index.ToString());double? responseMs=Response(index,raw);var idle=N(dp,"PercentIdleTime");var active=idle.HasValue?Math.Clamp(100-idle.Value,0,100):(double?)null;
   var volumes=_diskVolumes.GetValueOrDefault(index)??[];var info=_diskInfo.GetValueOrDefault(index);var matches=_storage.Where(x=>(S(d,"SerialNumber").Length>0&&string.Equals(S(x,"SerialNumber"),S(d,"SerialNumber"),StringComparison.OrdinalIgnoreCase))||(string.Equals(S(x,"FriendlyName"),S(d,"Model"),StringComparison.OrdinalIgnoreCase)&&N(x,"Size")==N(d,"Size"))).ToArray();var storage=matches.Length==1?matches[0]:null;var bus=N(storage,"BusType");var media=N(storage,"MediaType");var type=(media==4?"SSD":media==3?"HDD":Text(S(d,"MediaType")))+" · "+(bus==17?"NVMe":bus==7?"USB":Text(S(d,"InterfaceType")));
   list.Add(new("disk:"+index,"disk",$"Disco {index}"+(volumes.Length>0?" ("+string.Join(" ",volumes)+")":""),Text(S(d,"Model")),
    [Stat("Tempo de atividade",PerformanceRules.Percent(active)),Stat("Tempo médio de resposta",Num(responseMs," ms")),Stat("Velocidade de leitura",PerformanceRules.Size(N(dp,"DiskReadBytesPersec"))+"/s"),Stat("Velocidade de gravação",PerformanceRules.Size(N(dp,"DiskWriteBytesPersec"))+"/s"),Stat("Capacidade",PerformanceRules.Size(N(d,"Size"))),Stat("Formatado",PerformanceRules.Size(volumes.Length>0?info.Formatted:null)),Stat("Disco do sistema",volumes.Length==0?null:volumes.Contains(Path.GetPathRoot(Environment.SystemDirectory)?.TrimEnd('\\'),StringComparer.OrdinalIgnoreCase)?"Sim":"Não"),Stat("Arquivo de paginação",volumes.Length==0?null:info.Page?"Sim":"Não"),Stat("Tipo",type)],
    [new("active","Tempo de atividade","%",active,100),new("read","Leitura","MB/s",N(dp,"DiskReadBytesPersec")/1e6),new("write","Gravação","MB/s",N(dp,"DiskWriteBytesPersec")/1e6)],"Um componente por disco físico; os volumes do mesmo disco permanecem agrupados. O espaço ocupado não é usado como atividade."));}
  foreach(var g in _gpu){ct.ThrowIfCancellationRequested();var temperature=GpuTemperature.Read(g.Luid);var counters=engine.Where(x=>PerformanceRules.GpuLuid(S(x,"Name"))==g.Luid).ToArray();var series=counters.GroupBy(x=>PerformanceRules.GpuEngine(S(x,"Name"))).Where(x=>x.Key is not null).Select(x=>new PerformanceSeries(x.Key!,x.Key!.Split(':',3)[2]+" · motor "+x.Key.Split(':')[1],"%",Math.Clamp(x.Sum(v=>N(v,"UtilizationPercentage")??0),0,100),100)).OrderBy(x=>x.Key).ToList();var gm=gpuMem.FirstOrDefault(x=>PerformanceRules.GpuLuid(S(x,"Name"))==g.Luid);var dedicated=N(gm,"DedicatedUsage");var shared=N(gm,"SharedUsage");
   if(g.Dedicated>0)series.Add(new("dedicated","Memória dedicada","GB",dedicated/1073741824d,g.Dedicated/1073741824d));if(g.Shared>0)series.Add(new("shared","Memória compartilhada","GB",shared/1073741824d,g.Shared/1073741824d));
   var matched=_videos.Where(x=>S(x,"PNPDeviceID").Contains($"VEN_{g.Vendor:X4}&DEV_{g.Device:X4}",StringComparison.OrdinalIgnoreCase)).ToArray();var driver=matched.Length==1?matched[0]:null;
   list.Add(new("gpu:"+g.Luid,"gpu","GPU "+g.Index,g.Name,[Stat("Utilização",PerformanceRules.Percent(PerformanceRules.Busiest(series.Where(x=>x.Unit=="%").Select(x=>x.Value)))),Stat("Memória dedicada",g.Dedicated>0?PerformanceRules.Size(dedicated)+" / "+PerformanceRules.Size(g.Dedicated):"Não possui memória dedicada"),Stat("Memória compartilhada",PerformanceRules.Size(shared)+" / "+PerformanceRules.Size(g.Shared)),Stat("Versão do driver",S(driver,"DriverVersion")),Stat("Data do driver",DriverDate(S(driver,"DriverDate"))),Stat("DirectX",g.FeatureLevel),Stat("Local físico",PhysicalLocation(S(driver,"PNPDeviceID"))),Stat("Temperatura",Num(temperature," °C")),Stat("Reservada para hardware",null)],series.ToArray(),"Motores identificados pelo Windows; utilização é o motor mais ocupado, sem somar percentuais de motores diferentes. Temperatura depende do suporte WDDM do driver. Reserva de hardware não exposta; nenhum driver adicional é instalado."));}
  if(_gpu.Count==0&&_videos.Count>0)_warnings.Add("Adaptadores de vídeo informados pelo WMI, mas não enumerados por DXGI; gráficos GPU indisponíveis.");
  foreach(var n in nics)list.Add(new("nic:"+n.Id,"network",n.Name,n.Description,[Stat("Enviar",Num(n.Up," Mbps")),Stat("Receber",Num(n.Down," Mbps")),Stat("Nome do adaptador",n.Name),Stat("Tipo de conexão",n.Type),Stat("IPv4",n.IPv4),Stat("IPv6",n.IPv6),Stat("Velocidade do link",Num(n.Speed/1e6," Mbps")),Stat("Estado",n.State),Stat("Rota de saída",n.Route?"Sim":"Não")],[new("receive","Receber","Mbps",n.Down),new("send","Enviar","Mbps",n.Up)],"Taxa por adaptador, calculada pelos bytes transferidos e pelo tempo real entre leituras. Interfaces não são somadas."));
  return new(++_sequence,at,seconds,devices,discoveryAt,list.ToArray(),_warnings.Distinct().ToArray());
 }
 private readonly Dictionary<int,(double Count,double Base)> _response=[];
 private double? Response(int index,Dictionary<string,object?>? row){var n=N(row,"AvgDisksecPerTransfer");var b=N(row,"AvgDisksecPerTransfer_Base");var f=N(row,"Frequency_PerfTime");if(n is null||b is null||f is null||f<=0)return null;double? v=null;if(_response.TryGetValue(index,out var last)&&b>last.Base&&n>=last.Count)v=(n-last.Count)/(b-last.Base)/f*1000;_response[index]=(n.Value,b.Value);return v;}
 private static string PhysicalLocation(string id){if(string.IsNullOrWhiteSpace(id))return "";try{using var key=Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Enum\"+id);var location=Convert.ToString(key?.GetValue("LocationInformation"))?.Trim()??"";return location.StartsWith('@')?"":location;}catch(Exception e)when(e is UnauthorizedAccessException or System.Security.SecurityException or IOException){return "";}}
 private static string DriverDate(string s){try{return s.Length>=8?DateTime.ParseExact(s[..8],"yyyyMMdd",null).ToString("dd/MM/yyyy"):"";}catch(FormatException){return "";}}
 private sealed record Nic(string Id,string Name,string Description,string Type,string State,string IPv4,string IPv6,long Speed,bool Route,double? Down,double? Up);
 [DllImport("iphlpapi.dll")]private static extern int GetBestInterface(uint destination,out uint index);
 private List<Nic> Network(){var now=System.Diagnostics.Stopwatch.GetTimestamp();var seconds=_netStamp==0?0:(now-_netStamp)/(double)System.Diagnostics.Stopwatch.Frequency;_netStamp=now;var rows=new List<Nic>();GetBestInterface(BitConverter.ToUInt32(new byte[]{1,1,1,1}),out var best);var seen=new HashSet<string>();
  foreach(var nic in NetworkInterface.GetAllNetworkInterfaces().Where(n=>n.NetworkInterfaceType is not(NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel))){try{var p=nic.GetIPProperties();var s=nic.GetIPStatistics();double? down=null,up=null;if(_previous.TryGetValue(nic.Id,out var previous)){down=PerformanceRules.Rate(s.BytesReceived,previous.Rx,seconds);up=PerformanceRules.Rate(s.BytesSent,previous.Tx,seconds);}_previous[nic.Id]=(s.BytesReceived,s.BytesSent);seen.Add(nic.Id);bool route=false;try{route=p.GetIPv4Properties()?.Index==best;}catch(NetworkInformationException){}rows.Add(new(nic.Id,nic.Name,nic.Description,nic.NetworkInterfaceType.ToString(),nic.OperationalStatus.ToString(),string.Join(", ",p.UnicastAddresses.Where(a=>a.Address.AddressFamily==AddressFamily.InterNetwork).Select(a=>a.Address)),string.Join(", ",p.UnicastAddresses.Where(a=>a.Address.AddressFamily==AddressFamily.InterNetworkV6).Select(a=>a.Address)),nic.Speed,route,down,up));}catch(NetworkInformationException){}}
  foreach(var id in _previous.Keys.Where(x=>!seen.Contains(x)).ToArray())_previous.Remove(id);return rows;}
}
