using System.Globalization;
using System.Runtime.InteropServices;
namespace Arcoll.WinUI3.Services;
/// <summary>Read-only WDDM capability; no vendor DLLs or extra drivers.
/// Layout/units: microsoft/win32metadata, shared/d3dkmthk.h, D3DKMT_ADAPTER_PERFDATA.
/// https://learn.microsoft.com/windows-hardware/drivers/ddi/d3dkmthk/ns-d3dkmthk-_d3dkmt_adapter_perfdata
/// </summary>
internal static class GpuTemperature
{
 [StructLayout(LayoutKind.Sequential)]private struct OpenAdapter{public uint Low;public int High;public uint Handle;}
 [StructLayout(LayoutKind.Sequential)]private struct CloseAdapter{public uint Handle;}
 [StructLayout(LayoutKind.Sequential)]private struct Query{public uint Handle,Type;public IntPtr Data;public uint Size;}
 [StructLayout(LayoutKind.Sequential,Pack=8)]private struct PerfData{public uint PhysicalAdapterIndex;public ulong MemoryFrequency,MaxMemoryFrequency,MaxMemoryFrequencyOC,MemoryBandwidth,PCIEBandwidth;public uint FanRPM,Power,Temperature;public byte PowerStateOverride;}
 [DllImport("gdi32.dll")]private static extern int D3DKMTOpenAdapterFromLuid(ref OpenAdapter adapter);
 [DllImport("gdi32.dll")]private static extern int D3DKMTQueryAdapterInfo(ref Query query);
 [DllImport("gdi32.dll")]private static extern int D3DKMTCloseAdapter(ref CloseAdapter adapter);
 internal static double? Read(string luid)
 {
  var parts=luid.Split(':');if(parts.Length!=2||!uint.TryParse(parts[0],NumberStyles.HexNumber,CultureInfo.InvariantCulture,out var high)||!uint.TryParse(parts[1],NumberStyles.HexNumber,CultureInfo.InvariantCulture,out var low))return null;
  var adapter=new OpenAdapter{Low=low,High=unchecked((int)high)};IntPtr data=IntPtr.Zero;
  try{if(D3DKMTOpenAdapterFromLuid(ref adapter)!=0)return null;var size=Marshal.SizeOf<PerfData>();data=Marshal.AllocHGlobal(size);Marshal.StructureToPtr(new PerfData(),data,false);var query=new Query{Handle=adapter.Handle,Type=62,Data=data,Size=(uint)size};if(D3DKMTQueryAdapterInfo(ref query)!=0)return null;return PerformanceRules.Temperature(Marshal.PtrToStructure<PerfData>(data).Temperature);}
  catch(Exception e)when(e is DllNotFoundException or EntryPointNotFoundException or SEHException){return null;}
  finally{if(data!=IntPtr.Zero)Marshal.FreeHGlobal(data);if(adapter.Handle!=0){var close=new CloseAdapter{Handle=adapter.Handle};try{D3DKMTCloseAdapter(ref close);}catch(EntryPointNotFoundException){}}}
 }
}
