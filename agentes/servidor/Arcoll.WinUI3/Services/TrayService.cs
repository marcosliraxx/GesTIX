// Caminho: agentes/servidor/Arcoll.WinUI3/Services/TrayService.cs
using System.Runtime.InteropServices;

namespace Arcoll.WinUI3.Services;

public enum TrayState { Connected, Pending, Offline }

/// <summary>
/// Ícone na bandeja do sistema (Shell_NotifyIcon) com indicador de conexão, notificações e menu
/// Abrir / Reiniciar / Encerrar. Janela de mensagens própria (message-only): sem portas, sem dependências extras e custo ocioso nulo.
/// </summary>
public sealed class TrayService : IDisposable
{
    private const int WM_USER = 0x0400, WM_CALLBACK = WM_USER + 1, WM_LBUTTONUP = 0x0202, WM_LBUTTONDBLCLK = 0x0203, WM_RBUTTONUP = 0x0205, WM_DESTROY = 0x0002;
    private const int NIM_ADD = 0, NIM_MODIFY = 1, NIM_DELETE = 2, NIF_MESSAGE = 1, NIF_ICON = 2, NIF_TIP = 4, NIF_INFO = 0x10, NIIF_INFO = 1;
    private const uint MF_STRING = 0, MF_SEPARATOR = 0x800, TPM_RETURNCMD = 0x100, TPM_RIGHTBUTTON = 2;
    private const int CmdOpen = 1, CmdRestart = 2, CmdExit = 3;
    private static readonly IntPtr HwndMessage = new(-3);

    private delegate IntPtr WndProc(IntPtr hwnd, uint msg, IntPtr wParam, IntPtr lParam);
    private readonly WndProc _proc;
    private readonly Dictionary<TrayState, IntPtr> _icons = new();
    private IntPtr _hwnd, _classAtom, _brandIcon;
    private NOTIFYICONDATA _data;
    private bool _added, _disposed;
    private TrayState _state = TrayState.Pending;
    private readonly string _className = "TIDigital.Arcoll.Tray." + Environment.ProcessId;

    public Action? OnOpen { get; set; }
    public Action? OnRestart { get; set; }
    public Action? OnExit { get; set; }

    public TrayService() { _proc = Handle; }

    public void Start()
    {
        if (_added || _disposed) return;
        var wc = new WNDCLASS { lpfnWndProc = Marshal.GetFunctionPointerForDelegate(_proc), hInstance = GetModuleHandle(null), lpszClassName = _className };
        _classAtom = (IntPtr)RegisterClass(ref wc);
        _hwnd = CreateWindowEx(0, _className, "TI Digital Tray", 0, 0, 0, 0, 0, HwndMessage, IntPtr.Zero, wc.hInstance, IntPtr.Zero);
        if (_hwnd == IntPtr.Zero) return;
        _data = new NOTIFYICONDATA
        {
            cbSize = Marshal.SizeOf<NOTIFYICONDATA>(), hWnd = _hwnd, uID = 1, uFlags = NIF_MESSAGE | NIF_ICON | NIF_TIP,
            uCallbackMessage = WM_CALLBACK, hIcon = IconFor(_state), szTip = "T.I Digital — iniciando", szInfo = "", szInfoTitle = "",
        };
        _added = Shell_NotifyIcon(NIM_ADD, ref _data);
    }

    /// <summary>Atualiza o estado na dica, mantendo a logo oficial quando disponível.</summary>
    public void SetState(TrayState state, string tip)
    {
        if (!_added || _disposed) return;
        _state = state;
        _data.hIcon = IconFor(state);
        _data.szTip = ("T.I Digital — " + tip).Length > 127 ? ("T.I Digital — " + tip)[..127] : "T.I Digital — " + tip;
        _data.uFlags = NIF_ICON | NIF_TIP | NIF_MESSAGE;
        Shell_NotifyIcon(NIM_MODIFY, ref _data);
    }

    /// <summary>Notificação nativa do Windows (balão/toast do Shell).</summary>
    public void Notify(string title, string message)
    {
        if (!_added || _disposed) return;
        _data.uFlags = NIF_INFO | NIF_MESSAGE;
        _data.szInfoTitle = title.Length > 63 ? title[..63] : title;
        _data.szInfo = message.Length > 255 ? message[..255] : message;
        _data.dwInfoFlags = NIIF_INFO;
        Shell_NotifyIcon(NIM_MODIFY, ref _data);
    }

    /// <summary>Ícone 32x32 gerado em memória (disco escuro, letra T e ponto de estado), sem System.Drawing.</summary>
    private IntPtr IconFor(TrayState state)
    {
        if (_brandIcon == IntPtr.Zero)
        {
            var file=Path.Combine(AppContext.BaseDirectory,"Assets","system.ico");
            if(File.Exists(file)) _brandIcon=LoadImage(IntPtr.Zero,file,1,32,32,0x10);
        }
        if (_brandIcon != IntPtr.Zero) return _brandIcon;
        if (_icons.TryGetValue(state, out var cached)) return cached;
        const int N = 32;
        uint dot = state switch { TrayState.Connected => 0xFF22C55E, TrayState.Pending => 0xFFF59E0B, _ => 0xFFEF4444 };
        var xor = new uint[N * N];
        for (var y = 0; y < N; y++)
            for (var x = 0; x < N; x++)
            {
                double dx = x + 0.5 - 16, dy = y + 0.5 - 16; var inside = dx * dx + dy * dy <= 15.5 * 15.5;
                uint px = inside ? 0xFF0F172A : 0x00000000;
                if (inside && ((y >= 7 && y <= 11 && x >= 6 && x <= 20) || (x >= 11 && x <= 15 && y >= 7 && y <= 25))) px = 0xFFFFFFFF;
                double ex = x + 0.5 - 24.5, ey = y + 0.5 - 24.5, d = ex * ex + ey * ey;
                if (d <= 8.5 * 8.5) px = 0xFF0F172A;
                if (d <= 6.2 * 6.2) px = dot;
                xor[(N - 1 - y) * N + x] = px; // DIB de baixo para cima
            }
        var image = new byte[40 + N * N * 4 + N * 4];
        BitConverter.GetBytes(40).CopyTo(image, 0); BitConverter.GetBytes(N).CopyTo(image, 4); BitConverter.GetBytes(N * 2).CopyTo(image, 8);
        BitConverter.GetBytes((short)1).CopyTo(image, 12); BitConverter.GetBytes((short)32).CopyTo(image, 14);
        for (var i = 0; i < xor.Length; i++) BitConverter.GetBytes(xor[i]).CopyTo(image, 40 + i * 4);
        var handle = CreateIconFromResourceEx(image, (uint)image.Length, true, 0x00030000, N, N, 0);
        _icons[state] = handle; return handle;
    }

    private IntPtr Handle(IntPtr hwnd, uint msg, IntPtr wParam, IntPtr lParam)
    {
        if (msg == WM_CALLBACK)
        {
            var ev = (int)(lParam.ToInt64() & 0xFFFF);
            if (ev is WM_LBUTTONUP or WM_LBUTTONDBLCLK) OnOpen?.Invoke();
            else if (ev == WM_RBUTTONUP) ShowMenu(hwnd);
            return IntPtr.Zero;
        }
        return DefWindowProc(hwnd, msg, wParam, lParam);
    }

    private void ShowMenu(IntPtr hwnd)
    {
        var menu = CreatePopupMenu();
        AppendMenu(menu, MF_STRING, CmdOpen, "Abrir T.I Digital");
        AppendMenu(menu, MF_STRING, CmdRestart, "Reiniciar agente");
        AppendMenu(menu, MF_SEPARATOR, 0, null);
        AppendMenu(menu, MF_STRING, CmdExit, "Encerrar agente");
        GetCursorPos(out var pt); SetForegroundWindow(hwnd);
        var cmd = TrackPopupMenu(menu, TPM_RETURNCMD | TPM_RIGHTBUTTON, pt.X, pt.Y, 0, hwnd, IntPtr.Zero);
        DestroyMenu(menu);
        switch (cmd) { case CmdOpen: OnOpen?.Invoke(); break; case CmdRestart: OnRestart?.Invoke(); break; case CmdExit: OnExit?.Invoke(); break; }
    }

    public void Dispose()
    {
        if (_disposed) return; _disposed = true;
        if (_added) { Shell_NotifyIcon(NIM_DELETE, ref _data); _added = false; }
        if (_hwnd != IntPtr.Zero) { DestroyWindow(_hwnd); _hwnd = IntPtr.Zero; }
        foreach (var icon in _icons.Values) DestroyIcon(icon);
        _icons.Clear();
        if(_brandIcon!=IntPtr.Zero){DestroyIcon(_brandIcon);_brandIcon=IntPtr.Zero;}
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct NOTIFYICONDATA
    {
        public int cbSize; public IntPtr hWnd; public uint uID; public int uFlags; public uint uCallbackMessage; public IntPtr hIcon;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 128)] public string szTip;
        public int dwState, dwStateMask;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)] public string szInfo;
        public uint uTimeoutOrVersion;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 64)] public string szInfoTitle;
        public int dwInfoFlags; public Guid guidItem; public IntPtr hBalloonIcon;
    }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct WNDCLASS
    {
        public uint style; public IntPtr lpfnWndProc; public int cbClsExtra, cbWndExtra; public IntPtr hInstance, hIcon, hCursor, hbrBackground;
        [MarshalAs(UnmanagedType.LPWStr)] public string? lpszMenuName; [MarshalAs(UnmanagedType.LPWStr)] public string lpszClassName;
    }
    [StructLayout(LayoutKind.Sequential)] private struct POINT { public int X, Y; }

    [DllImport("user32.dll",CharSet=CharSet.Unicode,EntryPoint="LoadImageW")] private static extern IntPtr LoadImage(IntPtr instance,string name,uint type,int width,int height,uint flags);
    [DllImport("shell32.dll", CharSet = CharSet.Unicode)] private static extern bool Shell_NotifyIcon(int message, ref NOTIFYICONDATA data);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern ushort RegisterClass(ref WNDCLASS wc);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern IntPtr CreateWindowEx(int ex, string cls, string name, int style, int x, int y, int w, int h, IntPtr parent, IntPtr menu, IntPtr inst, IntPtr param);
    [DllImport("user32.dll")] private static extern IntPtr DefWindowProc(IntPtr hwnd, uint msg, IntPtr w, IntPtr l);
    [DllImport("user32.dll")] private static extern bool DestroyWindow(IntPtr hwnd);
    [DllImport("user32.dll")] private static extern IntPtr CreatePopupMenu();
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern bool AppendMenu(IntPtr menu, uint flags, uint id, string? text);
    [DllImport("user32.dll")] private static extern int TrackPopupMenu(IntPtr menu, uint flags, int x, int y, int reserved, IntPtr hwnd, IntPtr rect);
    [DllImport("user32.dll")] private static extern bool DestroyMenu(IntPtr menu);
    [DllImport("user32.dll")] private static extern bool GetCursorPos(out POINT pt);
    [DllImport("user32.dll")] private static extern bool SetForegroundWindow(IntPtr hwnd);
    [DllImport("user32.dll")] private static extern IntPtr CreateIconFromResourceEx(byte[] bits, uint size, bool icon, uint version, int cx, int cy, uint flags);
    [DllImport("user32.dll")] private static extern bool DestroyIcon(IntPtr icon);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] private static extern IntPtr GetModuleHandle(string? name);
}
