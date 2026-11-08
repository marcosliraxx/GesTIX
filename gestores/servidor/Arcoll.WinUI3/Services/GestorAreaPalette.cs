// Caminho: gestores/servidor/Arcoll.WinUI3/Services/GestorAreaPalette.cs
using System.Globalization;
namespace Arcoll.WinUI3.Services;
public sealed record GestorAreaColors(string Area2,string Area3,string Area4,string Area5,string Area6,string Area7,string Area8,string Area9,string Area10,string Text,string Muted,string Accent,string FooterText,string Line,bool Light);
/// <summary>One palette for native chrome and local HTML. The OS caption remains independent.</summary>
public static class GestorAreaPalette
{
 public static GestorAreaColors Create(string background,string surface,string text,string accent,string muted,bool light)
 {
  surface=Valid(surface,light?"#FFFFFF":"#172A40");accent=Valid(accent,"#2563EB");text=Valid(text,light?"#172B44":"#E7F7FF");muted=Valid(muted,light?"#536982":"#ADC5D7");
  var ribbon=Mix(surface,accent,.055);var bar=Shade(ribbon,.08);var sideEnds=Shade(ribbon,.14);var footer=Shade(accent,.30);var line=Mix(surface,text,.16);
  return new(accent,ribbon,bar,surface,sideEnds,surface,sideEnds,bar,footer,text,muted,accent,Ink(footer),line,light);
 }
 public static string Valid(string value,string fallback)=>System.Text.RegularExpressions.Regex.IsMatch(value??"","^#[0-9a-fA-F]{6}$")?value!.ToUpperInvariant():fallback;
 public static string Shade(string color,double amount)=>Mix(color,"#000000",amount);
 public static string Mix(string a,string b,double weight){weight=Math.Clamp(weight,0,1);int Read(string v,int i)=>int.Parse(v.Substring(i,2),NumberStyles.HexNumber,CultureInfo.InvariantCulture);return "#"+string.Concat(new[]{1,3,5}.Select(i=>((int)Math.Round(Read(a,i)*(1-weight)+Read(b,i)*weight)).ToString("X2",CultureInfo.InvariantCulture)));}
 private static double Luminance(string hex){double Convert(int start){var c=int.Parse(hex.Substring(start,2),NumberStyles.HexNumber)/255d;return c<=.04045?c/12.92:Math.Pow((c+.055)/1.055,2.4);}return .2126*Convert(1)+.7152*Convert(3)+.0722*Convert(5);}
 public static string Ink(string color)=>Luminance(color)>.179?"#000000":"#FFFFFF";
}
