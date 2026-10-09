using System.ComponentModel;
using System.Runtime.InteropServices;

namespace Keeper.Session.Modules.Activity;

/// <summary>
/// Physical: Raw Input registrado; <c>Idle</c> es el tiempo desde el ultimo teclado/raton con dispositivo (null = ninguno
/// desde que arranco la sesion). Remote: sesion RDP, la entrada del cliente remoto llega sin dispositivo y solo
/// GetLastInputInfo sirve. Unavailable: Raw Input no se pudo registrar; se mide como antes de 4.0.22.
/// </summary>
public enum PhysicalInputStatus { Physical, Remote, Unavailable }
public sealed record PhysicalInputReading(PhysicalInputStatus Status, TimeSpan? Idle, string? Error = null);
public interface IPhysicalInputSource { PhysicalInputReading Read(); }

public enum RawInputKind { Ignored, Physical, Injected }
public static class RawInputClassifier
{
    public const uint Mouse = 0, Keyboard = 1, Hid = 2;
    // hDevice == 0: la genero SendInput (jiggler, DWService, cualquier inyector), no un dispositivo. Un paquete de raton
    // relativo sin movimiento ni botones no es uso (algunos drivers los emiten en reposo).
    public static RawInputKind Classify(uint type, nint device, ushort mouseFlags = 0, ushort buttonFlags = 0, int x = 0, int y = 0)
    {
        // Touchpad de precision, pantalla tactil y lapiz: el cursor que generan puede llegar como raton SIN dispositivo
        // (lo sintetiza Windows), asi que se registran tambien como HID digitalizador; el paquete HID trae su dispositivo.
        if (type == Hid) return device == 0 ? RawInputKind.Ignored : RawInputKind.Physical;
        if (type is not (Mouse or Keyboard)) return RawInputKind.Ignored;
        if (type == Mouse && (mouseFlags & 1) == 0 && buttonFlags == 0 && x == 0 && y == 0) return RawInputKind.Ignored;
        return device == 0 ? RawInputKind.Injected : RawInputKind.Physical;
    }
}

/// <summary>
/// Raw Input (RIDEV_INPUTSINK) sobre una ventana solo-mensajes en un hilo propio, como LockKeyboard monta su hook. No
/// usa hooks de bajo nivel: solo observa, no puede bloquear ni retrasar la entrada del usuario. Nunca lanza: si el
/// registro falla queda Unavailable con el codigo Win32 y el tracker cae a GetLastInputInfo.
/// </summary>
public sealed class WindowsPhysicalInputSource(Func<long>? ticks = null) : IPhysicalInputSource, IDisposable
{
    private readonly Func<long> _ticks = ticks ?? (() => Environment.TickCount64);
    private long _lastPhysical = long.MinValue, _lastInjected = long.MinValue;
    private Thread? _thread;
    private uint _threadId;
    private string? _error;
    private WindowProc? _proc;

    // Marca de la ultima entrada inyectada (diagnostico); el tracker la deduce de GetLastInputInfo sin fisica.
    public long? LastInjected { get { var v = Interlocked.Read(ref _lastInjected); return v == long.MinValue ? null : v; } }

    public void Start()
    {
        if (_thread is not null) return;
        var started = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _thread = new Thread(() => Run(started)) { IsBackground = true, Name = "Keeper physical input" };
        _thread.Start();
        try { started.Task.GetAwaiter().GetResult(); }
        catch (Exception ex)
        {
            _error = ex is Win32Exception w ? $"win32_{w.NativeErrorCode}" : ex.GetType().Name;
            _thread.Join(); _thread = null;
        }
    }

    public PhysicalInputReading Read()
    {
        if (GetSystemMetrics(0x1000) != 0) return new(PhysicalInputStatus.Remote, null);
        // Hilo caido: la ultima marca quedaria congelada y todo el uso pasaria a simulado; mejor medir como antes.
        if (_thread is not { IsAlive: true }) return new(PhysicalInputStatus.Unavailable, null, _error ?? (_thread is null ? "not_started" : "message_loop_ended"));
        var last = Interlocked.Read(ref _lastPhysical);
        return new(PhysicalInputStatus.Physical, last == long.MinValue ? null : TimeSpan.FromMilliseconds(Math.Max(0, _ticks() - last)));
    }

    private void Run(TaskCompletionSource started)
    {
        nint window = 0;
        var className = $"KeeperPhysicalInput{Environment.ProcessId}";
        _proc = (hwnd, message, wParam, lParam) =>
        {
            if (message == 0x00FF) Record(lParam);
            return DefWindowProc(hwnd, message, wParam, lParam);
        };
        try
        {
            _threadId = GetCurrentThreadId();
            var module = GetModuleHandle(null);
            var cls = new WindowClass { Size = (uint)Marshal.SizeOf<WindowClass>(), Proc = Marshal.GetFunctionPointerForDelegate(_proc), Instance = module, ClassName = className };
            if (RegisterClassEx(ref cls) == 0) throw new Win32Exception(Marshal.GetLastWin32Error());
            window = CreateWindowEx(0, className, "", 0, 0, 0, 0, 0, -3, 0, module, 0);
            if (window == 0) throw new Win32Exception(Marshal.GetLastWin32Error());
            var devices = new[]
            {
                new RawInputDevice { UsagePage = 0x01, Usage = 0x02, Flags = 0x100, Target = window },
                new RawInputDevice { UsagePage = 0x01, Usage = 0x06, Flags = 0x100, Target = window },
            };
            if (!RegisterRawInputDevices(devices, (uint)devices.Length, (uint)Marshal.SizeOf<RawInputDevice>()))
                throw new Win32Exception(Marshal.GetLastWin32Error());
            // Digitalizadores aparte y sin exigir exito: un equipo sin touchpad/tactil/lapiz sigue midiendo raton y teclado.
            var digitizers = new[]
            {
                new RawInputDevice { UsagePage = 0x0D, Usage = 0x05, Flags = 0x100, Target = window },
                new RawInputDevice { UsagePage = 0x0D, Usage = 0x04, Flags = 0x100, Target = window },
                new RawInputDevice { UsagePage = 0x0D, Usage = 0x02, Flags = 0x100, Target = window },
            };
            RegisterRawInputDevices(digitizers, (uint)digitizers.Length, (uint)Marshal.SizeOf<RawInputDevice>());
            started.SetResult();
            while (GetMessage(out var message, 0, 0, 0) > 0)
            {
                TranslateMessage(ref message);
                DispatchMessage(ref message);
            }
        }
        catch (Exception ex) { started.TrySetException(ex); }
        finally
        {
            if (window != 0) DestroyWindow(window);
            UnregisterClass(className, GetModuleHandle(null));
        }
    }

    private readonly byte[] _buffer = new byte[64];
    private void Record(nint handle)
    {
        var headerSize = (uint)Marshal.SizeOf<RawInputHeader>();
        // Cabecera (24 B en x64) + RAWMOUSE (24 B) o RAWKEYBOARD (16 B): 64 B alcanzan para ambos. Solo lo usa este hilo.
        var size = (uint)_buffer.Length;
        if (GetRawInputData(handle, 0x10000003, _buffer, ref size, headerSize) == uint.MaxValue)
        {
            // Un paquete HID (digitalizador) no cabe en 64 B; para clasificarlo basta la cabecera.
            size = (uint)_buffer.Length;
            if (GetRawInputData(handle, 0x10000005, _buffer, ref size, headerSize) == uint.MaxValue) return;
        }
        var header = MemoryMarshal.Read<RawInputHeader>(_buffer);
        var data = _buffer.AsSpan((int)headerSize);
        var kind = header.Type == RawInputClassifier.Mouse
            ? RawInputClassifier.Classify(header.Type, header.Device, BitConverter.ToUInt16(data), BitConverter.ToUInt16(data[4..]), BitConverter.ToInt32(data[12..]), BitConverter.ToInt32(data[16..]))
            : RawInputClassifier.Classify(header.Type, header.Device);
        if (kind == RawInputKind.Physical) Interlocked.Exchange(ref _lastPhysical, _ticks());
        else if (kind == RawInputKind.Injected) Interlocked.Exchange(ref _lastInjected, _ticks());
    }

    public void Dispose()
    {
        if (_thread is null) return;
        if (_thread.IsAlive) PostThreadMessage(_threadId, 0x12, 0, 0);
        _thread.Join(TimeSpan.FromSeconds(2));
        _thread = null;
    }

    private delegate nint WindowProc(nint hwnd, uint message, nint wParam, nint lParam);
    [StructLayout(LayoutKind.Sequential)] private struct RawInputHeader { public uint Type, Size; public nint Device, WParam; }
    [StructLayout(LayoutKind.Sequential)] private struct RawInputDevice { public ushort UsagePage, Usage; public uint Flags; public nint Target; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] private struct WindowClass
    {
        public uint Size, Style; public nint Proc; public int ClassExtra, WindowExtra;
        public nint Instance, Icon, Cursor, Background; public string? MenuName; public string ClassName; public nint IconSmall;
    }
    [StructLayout(LayoutKind.Sequential)] private struct Message
    {
        public nint Window; public uint Id; public nuint WParam; public nint LParam;
        public uint Time; public int X, Y; public uint Private;
    }
    [DllImport("user32.dll")] private static extern int GetSystemMetrics(int index);
    [DllImport("user32.dll", SetLastError = true, CharSet = CharSet.Unicode)] private static extern ushort RegisterClassEx(ref WindowClass cls);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool UnregisterClass(string name, nint instance);
    [DllImport("user32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern nint CreateWindowEx(uint exStyle, string className, string name, uint style, int x, int y, int width, int height, nint parent, nint menu, nint instance, nint param);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool DestroyWindow(nint window);
    [DllImport("user32.dll")] private static extern nint DefWindowProc(nint window, uint message, nint wParam, nint lParam);
    [DllImport("user32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool RegisterRawInputDevices(RawInputDevice[] devices, uint count, uint size);
    [DllImport("user32.dll")] private static extern uint GetRawInputData(nint input, uint command, byte[] data, ref uint size, uint headerSize);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] private static extern nint GetModuleHandle(string? name);
    [DllImport("kernel32.dll")] private static extern uint GetCurrentThreadId();
    [DllImport("user32.dll", SetLastError = true)] private static extern int GetMessage(out Message message, nint window, uint min, uint max);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool TranslateMessage(ref Message message);
    [DllImport("user32.dll")] private static extern nint DispatchMessage(ref Message message);
    [DllImport("user32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool PostThreadMessage(uint thread, uint message, nint wParam, nint lParam);
}
