using System.Reflection;
using System.Runtime.InteropServices;
using Arcoll.WinUI3.Services;
var type=typeof(GpuTemperature);Type Nested(string name)=>type.GetNestedType(name,BindingFlags.NonPublic)!;
void Check(bool ok,string name){if(!ok)throw new Exception(name);Console.WriteLine("PASS "+name);}
Check(Marshal.SizeOf(Nested("PerfData"))==64,"D3DKMT_ADAPTER_PERFDATA = 64 bytes");
Check(Marshal.OffsetOf(Nested("PerfData"),"Temperature").ToInt32()==56,"Temperature offset = 56 bytes");
Check(Marshal.SizeOf(Nested("Query"))==24,"D3DKMT_QUERYADAPTERINFO = 24 bytes (64-bit)");
Check(Marshal.SizeOf(Nested("OpenAdapter"))==12,"D3DKMT_OPENADAPTERFROMLUID = 12 bytes");
