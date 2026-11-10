// Caminho: gestores/servidor/Arcoll.WinUI3/Services/FileThumbnailService.cs
using Windows.Storage;
using Windows.Storage.FileProperties;
using Windows.Storage.Streams;
namespace Arcoll.WinUI3.Services;
/// <summary>Miniaturas do Windows, incluindo provedores de vídeo, PDF e documentos instalados.</summary>
public static class FileThumbnailService
{
 public sealed record Preview(string Name,string Type,string Data,bool Icon);
 public static async Task<Preview?> ReadAsync(string path,CancellationToken ct)
 {
  ct.ThrowIfCancellationRequested();
  path=FileBrowserService.ResolveFile(path);
  var file=await StorageFile.GetFileFromPathAsync(path);
  using var thumb=await file.GetThumbnailAsync(ThumbnailMode.SingleItem,256,ThumbnailOptions.ResizeThumbnail);
  ct.ThrowIfCancellationRequested();
  if(thumb is null||thumb.Size==0||thumb.Size>262144||!thumb.ContentType.StartsWith("image/",StringComparison.OrdinalIgnoreCase))return null;
  using var input=thumb.GetInputStreamAt(0);using var reader=new DataReader(input);
  await reader.LoadAsync((uint)thumb.Size);var bytes=new byte[(int)thumb.Size];reader.ReadBytes(bytes);
  return new(Path.GetFileName(path),thumb.ContentType,Convert.ToBase64String(bytes),thumb.Type==ThumbnailType.Icon);
 }
}
