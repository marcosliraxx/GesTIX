// Caminho: gestores/servidor/Arcoll.WinUI3/Controls/ChartCanvas.cs
using Microsoft.UI;
using Microsoft.UI.Xaml;
using Microsoft.UI.Xaml.Controls;
using Microsoft.UI.Xaml.Input;
using Microsoft.UI.Xaml.Media;
using Microsoft.UI.Xaml.Shapes;
using Windows.Foundation;
using Windows.UI;

namespace Arcoll.WinUI3.Controls;

public enum ChartStyle { Lines, Area, Bars }
public sealed record ChartSeries(string Name, string Color, IReadOnlyList<double?> Values, string Unit);

/// <summary>Gráfico histórico nativo (linhas, área ou barras) no padrão visual dos gráficos da plataforma web.</summary>
public sealed class ChartCanvas : Canvas
{
    private IReadOnlyList<ChartSeries> _series = Array.Empty<ChartSeries>();
    private ChartStyle _style = ChartStyle.Lines;
    private double? _fixedMax;
    private bool _normalize;
    private int _window = 60;
    private int _hover = -1;
    private Brush _text = new SolidColorBrush(Colors.Gray);
    private Brush _grid = new SolidColorBrush(Color.FromArgb(60, 128, 128, 128));

    public ChartCanvas()
    {
        SizeChanged += (_, _) => Redraw();
        PointerMoved += OnPointerMoved;
        PointerExited += (_, _) => { _hover = -1; Redraw(); };
        Background = new SolidColorBrush(Colors.Transparent);
    }

    public void SetColors(Brush text, Brush grid) { _text = text; _grid = grid; Redraw(); }

    public void Set(IReadOnlyList<ChartSeries> series, ChartStyle style, int window, double? fixedMax = null, bool normalize = false)
    {
        _series = series; _style = style; _window = Math.Max(10, window); _fixedMax = fixedMax; _normalize = normalize; Redraw();
    }

    public static Color Parse(string hex, byte alpha = 255)
    {
        hex = hex.TrimStart('#');
        if (hex.Length != 6) return Color.FromArgb(alpha, 128, 128, 128);
        return Color.FromArgb(alpha, Convert.ToByte(hex[..2], 16), Convert.ToByte(hex[2..4], 16), Convert.ToByte(hex[4..6], 16));
    }

    private static double Nice(double peak)
    {
        double[] steps = { 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000 };
        foreach (var s in steps) if (peak <= s) return s;
        return Math.Ceiling(peak / 1000) * 1000;
    }

    private void OnPointerMoved(object sender, PointerRoutedEventArgs e)
    {
        var w = ActualWidth; if (w < 120) return;
        var pw = w - 54; var x = e.GetCurrentPoint(this).Position.X - 46;
        var idx = (int)Math.Round(x / pw * (_window - 1));
        var count = _series.Count == 0 ? 0 : _series.Max(s => s.Values.Count);
        var slot = idx - (_window - count);
        _hover = slot >= 0 && slot < count ? slot : -1;
        Redraw();
    }

    private void Redraw()
    {
        Children.Clear();
        double w = ActualWidth, h = ActualHeight;
        if (w < 120 || h < 80) return;
        const double left = 46, top = 10, right = 8, bottom = 22;
        double pw = w - left - right, ph = h - top - bottom;
        double? max = _fixedMax;
        if (max is null && !_normalize)
        {
            var peak = _series.SelectMany(s => s.Values).Where(v => v is not null).Select(v => v!.Value).DefaultIfEmpty(0).Max();
            max = Nice(Math.Max(peak, 1));
        }
        for (var i = 0; i <= 4; i++)
        {
            var y = top + ph * i / 4;
            Children.Add(new Line { X1 = left, X2 = left + pw, Y1 = y, Y2 = y, Stroke = _grid, StrokeThickness = 1 });
            var label = _normalize ? $"{100 - 25 * i}%" : (max!.Value * (4 - i) / 4).ToString(max >= 10 ? "0" : "0.0");
            var tb = new TextBlock { Text = label, FontSize = 10, Foreground = _text };
            SetLeft(tb, 4); SetTop(tb, y - 8); Children.Add(tb);
        }
        foreach (var (text, x, anchorRight) in new[] { ($"{_window * 2} s", left, false), ("agora", left + pw, true) })
        {
            var tb = new TextBlock { Text = text, FontSize = 10, Foreground = _text };
            tb.Measure(new Size(200, 20));
            SetLeft(tb, anchorRight ? x - tb.DesiredSize.Width : x); SetTop(tb, top + ph + 4); Children.Add(tb);
        }
        var n = _series.Count == 0 ? 0 : _series.Max(s => s.Values.Count);
        if (n == 0)
        {
            var empty = new TextBlock { Text = "Aguardando amostras…", Foreground = _text, FontSize = 12 };
            SetLeft(empty, left + 12); SetTop(empty, top + ph / 2 - 8); Children.Add(empty); return;
        }
        double X(int idx) => left + pw * (_window - n + idx) / Math.Max(1, _window - 1);
        var barSlot = pw / _window; var seriesIndex = 0;
        foreach (var s in _series)
        {
            var scale = _normalize ? Math.Max(1, s.Values.Where(v => v is not null).Select(v => v!.Value).DefaultIfEmpty(1).Max()) : max!.Value;
            double Y(double v) => top + ph - Math.Min(1, Math.Max(0, v / scale)) * ph;
            var stroke = new SolidColorBrush(Parse(s.Color));
            if (_style == ChartStyle.Bars)
            {
                var bw = Math.Max(1.5, barSlot * 0.75 / Math.Max(1, _series.Count));
                for (var i = 0; i < s.Values.Count; i++)
                {
                    if (s.Values[i] is not double v) continue;
                    var rect = new Rectangle { Width = bw, Height = Math.Max(1, top + ph - Y(v)), Fill = stroke };
                    SetLeft(rect, X(i) - barSlot * 0.375 + bw * seriesIndex); SetTop(rect, Y(v)); Children.Add(rect);
                }
            }
            else
            {
                var line = new Polyline { Stroke = stroke, StrokeThickness = 2, StrokeLineJoin = PenLineJoin.Round };
                var area = new Polygon { Fill = new SolidColorBrush(Parse(s.Color, 46)) };
                double? firstX = null, lastX = null;
                for (var i = 0; i < s.Values.Count; i++)
                {
                    if (s.Values[i] is not double v)
                    {
                        if (_style == ChartStyle.Area && area.Points.Count > 1) { Close(area, top + ph); Children.Add(area); area = new Polygon { Fill = area.Fill }; firstX = null; }
                        if (line.Points.Count > 1) Children.Add(line);
                        line = new Polyline { Stroke = stroke, StrokeThickness = 2, StrokeLineJoin = PenLineJoin.Round };
                        continue;
                    }
                    var p = new Point(X(i), Y(v)); line.Points.Add(p); area.Points.Add(p); firstX ??= p.X; lastX = p.X;
                }
                if (_style == ChartStyle.Area && area.Points.Count > 1) { Close(area, top + ph); Children.Add(area); }
                if (line.Points.Count > 1) Children.Add(line);
                else if (line.Points.Count == 1)
                {
                    var dot = new Ellipse { Width = 6, Height = 6, Fill = stroke };
                    SetLeft(dot, line.Points[0].X - 3); SetTop(dot, line.Points[0].Y - 3); Children.Add(dot);
                }
            }
            seriesIndex++;
        }
        if (_hover >= 0)
        {
            var x = X(_hover);
            Children.Add(new Line { X1 = x, X2 = x, Y1 = top, Y2 = top + ph, Stroke = _text, StrokeThickness = 1, Opacity = 0.5 });
            var lines = _series.Select(s => _hover < s.Values.Count && s.Values[_hover] is double v ? $"{s.Name}: {v:0.##} {s.Unit}" : null).Where(t => t is not null);
            var tip = new Border
            {
                Background = new SolidColorBrush(Color.FromArgb(235, 17, 44, 69)), CornerRadius = new CornerRadius(8), Padding = new Thickness(8, 5, 8, 5),
                Child = new TextBlock { Text = string.Join("\n", lines), FontSize = 11, Foreground = new SolidColorBrush(Colors.White) },
            };
            tip.Measure(new Size(240, 100));
            SetLeft(tip, Math.Min(Math.Max(left, x + 8), w - tip.DesiredSize.Width - 4)); SetTop(tip, top + 4); Children.Add(tip);
        }
    }

    private static void Close(Polygon area, double baseline)
    {
        var first = area.Points[0]; var last = area.Points[^1];
        area.Points.Add(new Point(last.X, baseline)); area.Points.Add(new Point(first.X, baseline));
    }
}
