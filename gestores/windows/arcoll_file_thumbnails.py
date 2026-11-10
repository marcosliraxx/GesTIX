# Caminho: gestores/windows/arcoll_file_thumbnails.py
"""Miniaturas locais pelo mesmo provedor Shell usado pelo Explorer. Sem upload a terceiros."""
import base64
import os
import subprocess

_SCRIPT = r"""
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System; using System.Drawing; using System.Drawing.Imaging; using System.IO; using System.Runtime.InteropServices;
public static class ArcThumbnail {
 [StructLayout(LayoutKind.Sequential)] struct SIZE {public int cx,cy;}
 [ComImport,Guid("bcc18b79-ba16-442f-80c4-8a59c30c463b"),InterfaceType(ComInterfaceType.InterfaceIsIUnknown)] interface Factory {void GetImage(SIZE size,int flags,out IntPtr bitmap);}
 [DllImport("shell32.dll",CharSet=CharSet.Unicode,PreserveSig=false)] static extern void SHCreateItemFromParsingName(string path,IntPtr context,ref Guid iid,[MarshalAs(UnmanagedType.Interface)] out Factory item);
 [DllImport("gdi32.dll")] static extern bool DeleteObject(IntPtr obj);
 public static string Read(string path) {Factory item=null;IntPtr bitmap=IntPtr.Zero;try {var id=typeof(Factory).GUID;SHCreateItemFromParsingName(path,IntPtr.Zero,ref id,out item);item.GetImage(new SIZE{cx=256,cy=256},0x09,out bitmap);using(var image=Image.FromHbitmap(bitmap))using(var stream=new MemoryStream()){image.Save(stream,ImageFormat.Jpeg);if(stream.Length>262144)return "";return Convert.ToBase64String(stream.ToArray());}}finally{if(bitmap!=IntPtr.Zero)DeleteObject(bitmap);if(item!=null)Marshal.ReleaseComObject(item);}}
}
'@
[ArcThumbnail]::Read($env:ARCOLL_THUMBNAIL_PATH)
"""

def shell_thumbnail(path):
    if os.name != 'nt':
        return None
    env=dict(os.environ, ARCOLL_THUMBNAIL_PATH=str(path))
    encoded=base64.b64encode(_SCRIPT.encode('utf-16le')).decode('ascii')
    executable=os.path.join(os.environ.get('SystemRoot',r'C:\Windows'),'System32','WindowsPowerShell','v1.0','powershell.exe')
    result=subprocess.run([executable,'-NoLogo','-NoProfile','-NonInteractive','-EncodedCommand',encoded],env=env,capture_output=True,timeout=12,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    if result.returncode:
        return None
    data=base64.b64decode(result.stdout.strip(),validate=True)
    return data if 0<len(data)<=262144 else None
