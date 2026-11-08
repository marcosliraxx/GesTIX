using System.Globalization;
using System.Text.RegularExpressions;
namespace Arcoll.WinUI3.Services;
public sealed record PerformanceStat(string Label,string Value);
public sealed record PerformanceSeries(string Key,string Label,string Unit,double? Value,double? Maximum=null);
public sealed record PerformanceComponent(string Key,string Kind,string Title,string Model,PerformanceStat[] Stats,PerformanceSeries[] Series,string Note="");
public sealed record PerformanceSnapshot(long Sequence,DateTimeOffset At,int Seconds,int? Devices,DateTimeOffset? DiscoveryAt,PerformanceComponent[] Components,string[] Warnings);
public static class PerformanceRules
{
 public static readonly int[] Cadences=[0,1,5,10,30,60,120]; // 0 pauses; 1 is continuous, one reading/second.
 public static bool IsCadence(int seconds)=>Cadences.Contains(seconds);
 public static string Size(double? bytes)=>bytes is not double n||!double.IsFinite(n)||n<0?"Indisponível":n>=1073741824?$"{n/1073741824:0.0} GB":n>=1048576?$"{n/1048576:0.0} MB":$"{n/1024:0.0} KB";
 public static double? Number(object? value)=>double.TryParse(Convert.ToString(value,CultureInfo.InvariantCulture),NumberStyles.Float,CultureInfo.InvariantCulture,out var n)&&double.IsFinite(n)&&n>=0?n:null;
 public static double? Rate(long current,long previous,double elapsed)=>elapsed>0&&current>=previous?(current-previous)*8d/elapsed/1e6:null;
 public static string? GpuLuid(string instance){var m=Regex.Match(instance,@"luid_0x([a-fA-F0-9]{8})_0x([a-fA-F0-9]{8})",RegexOptions.CultureInvariant);return m.Success?m.Groups[1].Value.ToLowerInvariant()+":"+m.Groups[2].Value.ToLowerInvariant():null;}
 public static string? GpuEngine(string instance){var m=Regex.Match(instance,@"phys_(\d+)_eng_(\d+)_engtype_(.+)$",RegexOptions.CultureInvariant);return m.Success?$"{m.Groups[1].Value}:{m.Groups[2].Value}:{m.Groups[3].Value}":null;}
 public static double? Busiest(IEnumerable<double?> values){var a=values.Where(x=>x.HasValue).Select(x=>x!.Value).ToArray();return a.Length==0?null:Math.Clamp(a.Max(),0,100);}
 public static string Percent(double? value)=>value is double n?$"{n:0.0}%":"Indisponível";
}
