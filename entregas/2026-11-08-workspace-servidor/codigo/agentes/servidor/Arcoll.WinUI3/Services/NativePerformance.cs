using System.Runtime.InteropServices;
namespace Arcoll.WinUI3.Services;
internal static class NativePerformance
{
 [StructLayout(LayoutKind.Sequential)]private struct Info{public uint Size;public UIntPtr CommitTotal,CommitLimit,CommitPeak,PhysicalTotal,PhysicalAvailable,SystemCache,KernelTotal,KernelPaged,KernelNonpaged,PageSize;public uint Handles,Processes,Threads;}
 internal sealed record Counts(double Handles,double Processes,double Threads);
 [DllImport("psapi.dll",SetLastError=true)]private static extern bool GetPerformanceInfo(ref Info info,uint size);
 internal static Counts? Read(){var info=new Info{Size=(uint)Marshal.SizeOf<Info>()};return GetPerformanceInfo(ref info,info.Size)?new(info.Handles,info.Processes,info.Threads):null;}
 [DllImport("kernel32.dll",SetLastError=true)]private static extern bool GetLogicalProcessorInformationEx(int relation,IntPtr buffer,ref uint length);
 internal static Dictionary<int,double> Caches(){var result=new Dictionary<int,double>();uint size=0;GetLogicalProcessorInformationEx(2,IntPtr.Zero,ref size);if(size==0||size>4*1024*1024)return result;var ptr=Marshal.AllocHGlobal((int)size);try{if(!GetLogicalProcessorInformationEx(2,ptr,ref size))return result;int offset=0;while(offset+16<=size){var p=IntPtr.Add(ptr,offset);var length=Marshal.ReadInt32(p,4);if(length<16||offset+length>size)break;if(Marshal.ReadInt32(p)==2){int level=Marshal.ReadByte(p,8);var bytes=unchecked((uint)Marshal.ReadInt32(p,12));result[level]=result.GetValueOrDefault(level)+bytes;}offset+=length;}}finally{Marshal.FreeHGlobal(ptr);}return result;}
}
internal sealed record GpuAdapter(int Index,string Name,string Luid,uint Vendor,uint Device,double Dedicated,double Shared,string FeatureLevel);
internal static class DxgiAdapters
{
 [StructLayout(LayoutKind.Sequential,CharSet=CharSet.Unicode)]private struct Desc{[MarshalAs(UnmanagedType.ByValTStr,SizeConst=128)]public string Description;public uint Vendor,Device,Subsystem,Revision;public UIntPtr DedicatedVideo,DedicatedSystem,Shared;public uint LuidLow;public int LuidHigh;public uint Flags;}
 [DllImport("dxgi.dll")]private static extern int CreateDXGIFactory1(ref Guid iid,out IntPtr factory);
 [UnmanagedFunctionPointer(CallingConvention.StdCall)]private delegate int EnumAdapter(IntPtr self,uint index,out IntPtr adapter);
 [UnmanagedFunctionPointer(CallingConvention.StdCall)]private delegate int GetDesc(IntPtr self,out Desc desc);
 [DllImport("d3d11.dll")]private static extern int D3D11CreateDevice(IntPtr adapter,uint type,IntPtr software,uint flags,IntPtr levels,uint count,uint version,out IntPtr device,out uint feature,out IntPtr context);
 [DllImport("d3d12.dll")]private static extern int D3D12CreateDevice(IntPtr adapter,uint level,ref Guid iid,IntPtr device);
 private static string Feature(IntPtr adapter){try{var iid=new Guid("189819f1-1db6-4b57-be54-1821339b85f7");foreach(uint level in new uint[]{0xc200,0xc100,0xc000,0xb100,0xb000})if(D3D12CreateDevice(adapter,level,ref iid,IntPtr.Zero)>=0)return $"DirectX 12 (FL {(level>>12)&15}.{(level>>8)&15})";}catch(Exception e)when(e is DllNotFoundException or EntryPointNotFoundException){}return "";}
 private static T Method<T>(IntPtr self,int slot)where T:Delegate=>Marshal.GetDelegateForFunctionPointer<T>(Marshal.ReadIntPtr(Marshal.ReadIntPtr(self),slot*IntPtr.Size));
 internal static List<GpuAdapter> Read(){var list=new List<GpuAdapter>();IntPtr factory=IntPtr.Zero;try{var iid=new Guid("770aae78-f26f-4dba-a829-253c83d1b387");if(CreateDXGIFactory1(ref iid,out factory)<0)return list;var enumerate=Method<EnumAdapter>(factory,12);for(uint i=0;i<32;i++){if(enumerate(factory,i,out var adapter)<0)break;try{if(Method<GetDesc>(adapter,10)(adapter,out var d)<0||(d.Flags&2)!=0)continue;string feature=Feature(adapter);IntPtr device=IntPtr.Zero,context=IntPtr.Zero;try{if(feature.Length==0&&D3D11CreateDevice(adapter,0,IntPtr.Zero,0,IntPtr.Zero,0,7,out device,out var level,out context)>=0)feature=$"D3D11 · nível {(level>>12)&15}.{(level>>8)&15}";}finally{if(context!=IntPtr.Zero)Marshal.Release(context);if(device!=IntPtr.Zero)Marshal.Release(device);}
 list.Add(new((int)i,d.Description.Trim(),$"{unchecked((uint)d.LuidHigh):x8}:{d.LuidLow:x8}",d.Vendor,d.Device,d.DedicatedVideo.ToUInt64(),d.Shared.ToUInt64(),feature.Length>0?feature:"Indisponível"));}finally{Marshal.Release(adapter);}}}catch(Exception e)when(e is COMException or DllNotFoundException or EntryPointNotFoundException){}finally{if(factory!=IntPtr.Zero)Marshal.Release(factory);}return list;}
}
