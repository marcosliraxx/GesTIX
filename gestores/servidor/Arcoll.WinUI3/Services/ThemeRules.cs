using System.Text.Json;
using System.Text.RegularExpressions;
namespace Arcoll.WinUI3.Services;
public sealed record PublishedPalette(string Id,string Mode,string Background,string Surface,string Text,string Accent,string Muted);
/// <summary>Pure catalog resolver shared by the service and tests. New published IDs need no executable whitelist.</summary>
public static class ThemeRules
{
 public static bool IsHex(string? value)=>value is not null&&Regex.IsMatch(value,"^#[a-fA-F0-9]{6}$",RegexOptions.CultureInvariant);
 public static bool IsLight(string hex){if(!IsHex(hex))return true;double Channel(int i){var n=Convert.ToInt32(hex.Substring(i,2),16)/255d;return n<=.04045?n/12.92:Math.Pow((n+.055)/1.055,2.4);}return .2126*Channel(1)+.7152*Channel(3)+.0722*Channel(5)>.45;}
 public static PublishedPalette? Resolve(JsonElement visual,bool windowsLight)
 {
  if(visual.ValueKind!=JsonValueKind.Object)return null;
  string Get(JsonElement e,string k)=>e.TryGetProperty(k,out var p)&&p.ValueKind==JsonValueKind.String?p.GetString()??"":"";
  var id=Get(visual,"id");if(!Regex.IsMatch(id,"^[a-z0-9_-]{1,40}$",RegexOptions.CultureInvariant))return null;var mode=Get(visual,"mode");if(mode is not("theme" or "light" or "dark" or "auto"))mode="theme";
  var light=mode switch{"light"=>true,"dark"=>false,"auto"=>windowsLight,_=>visual.TryGetProperty("light",out var flag)&&flag.ValueKind is JsonValueKind.True};
  var colors=visual;if(visual.TryGetProperty("variants",out var variants)&&variants.ValueKind==JsonValueKind.Object&&variants.TryGetProperty(light?"light":"dark",out var selected)&&selected.ValueKind==JsonValueKind.Object)colors=selected;
  var values=new[]{"bg","surface","text","accent","muted"}.Select(k=>Get(colors,k)).ToArray();if(values.Any(v=>!IsHex(v)))return null;
  if(colors.Equals(visual)&&mode!="theme"&&light!=IsLight(values[1])){values[0]=light?"#f0f4f9":"#0e1724";values[1]=light?"#ffffff":"#172536";values[2]=light?"#203248":"#edf5ff";values[4]=light?"#5c7188":"#afc1d7";}
  return new(id,mode,values[0],values[1],values[2],values[3],values[4]);
 }
}
