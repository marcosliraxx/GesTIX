// Caminho: gestores/servidor/Arcoll.WinUI3/Services/PageDataLoad.cs
namespace Arcoll.WinUI3.Services;
public enum DataLoadPhase { Waiting, Loading, Ready, Failed }
public sealed record DataLoadSnapshot(DataLoadPhase Phase,string Stage,int? Percent,bool HasData,long Operation);
/// <summary>Readiness follows completed reads, including successful empty results. No elapsed-time completion.</summary>
public sealed class PageDataLoad
{
 private readonly object _gate=new();private DataLoadSnapshot _current;
 public event Action? Changed;
 public PageDataLoad(string stage,bool automatic=false){_current=new(automatic?DataLoadPhase.Loading:DataLoadPhase.Waiting,stage,automatic?null:0,false,0);}
 public DataLoadSnapshot Current{get{lock(_gate)return _current;}}
 public long Begin(string stage,bool preserveData=false){long operation;lock(_gate){operation=_current.Operation+1;_current=new(DataLoadPhase.Loading,stage,null,preserveData&&_current.HasData,operation);}Changed?.Invoke();return operation;}
 public void Report(string stage,int? percent=null,long operation=0)=>Update(DataLoadPhase.Loading,stage,percent,null,operation);
 public void Complete(string stage="Dados disponíveis",long operation=0)=>Update(DataLoadPhase.Ready,stage,100,true,operation);
 public void Fail(string message,long operation=0)=>Update(DataLoadPhase.Failed,message,null,null,operation);
 public void Wait(string message)=>Update(DataLoadPhase.Waiting,message,0,false,0);
 private void Update(DataLoadPhase phase,string stage,int? percent,bool? hasData,long operation){lock(_gate){if(operation!=0&&operation!=_current.Operation)return;if(phase==DataLoadPhase.Loading&&_current.Phase is DataLoadPhase.Ready or DataLoadPhase.Failed)return;_current=new(phase,stage,percent.HasValue?Math.Clamp(percent.Value,0,phase==DataLoadPhase.Ready?100:99):null,hasData??_current.HasData,_current.Operation);}Changed?.Invoke();}
}
public interface IPageDataSource { PageDataLoad DataLoad{get;} Task CollectDataAsync(); bool CanCancelCollection=>false; void CancelCollection(){} }
/// <summary>Explicit foregrounds and contrast guards; the loading screen never inherits one color for every part.</summary>
public sealed record LoadingColors(string Surface,string Text,string Muted,string Accent,string Track)
{
 public static LoadingColors Create(string surface,string text,string muted,string accent){surface=ThemeRules.IsHex(surface)?surface:"#ffffff";string Readable(string candidate,double ratio){if(ThemeRules.IsHex(candidate)&&Contrast(candidate,surface)>=ratio)return candidate;return Contrast("#000000",surface)>=Contrast("#ffffff",surface)?"#000000":"#ffffff";}return new(surface,Readable(text,4.5),Readable(muted,4.5),Readable(accent,3),Mix(surface,Readable(text,4.5),.18));}
 public static double Contrast(string a,string b){var x=Luminance(a);var y=Luminance(b);return(Math.Max(x,y)+.05)/(Math.Min(x,y)+.05);}
 private static double Luminance(string color){double C(int i){var c=Convert.ToInt32(color.Substring(i,2),16)/255d;return c<=.04045?c/12.92:Math.Pow((c+.055)/1.055,2.4);}return .2126*C(1)+.7152*C(3)+.0722*C(5);}
 private static string Mix(string a,string b,double amount)=>"#"+string.Concat(new[]{1,3,5}.Select(i=>((int)Math.Round(Convert.ToInt32(a.Substring(i,2),16)*(1-amount)+Convert.ToInt32(b.Substring(i,2),16)*amount)).ToString("X2")));
}
