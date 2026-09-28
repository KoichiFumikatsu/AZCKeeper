using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using System.Text;
using System.Security.Cryptography;
using Microsoft.Win32.SafeHandles;

namespace Keeper.Agent.Hosting;

public sealed record InteractiveSession(int Id, string Sid);

[SupportedOSPlatform("windows")]
public sealed class WindowsSessionLauncher(IReadOnlyDictionary<string, string> binaryHashes)
{
    public IReadOnlyList<InteractiveSession> Enumerate()
    {
        using var identity = WindowsIdentity.GetCurrent();
        if (!identity.IsSystem) return [];
        if (!WTSEnumerateSessions(IntPtr.Zero, 0, 1, out var buffer, out var count)) throw new Win32Exception(Marshal.GetLastWin32Error());
        try
        {
            var result = new List<InteractiveSession>();
            for (var i = 0; i < count; i++)
            {
                var session = Marshal.PtrToStructure<SessionInfo>(buffer + i * Marshal.SizeOf<SessionInfo>());
                if (session.Id == 0 || session.State is not (0 or 4)) continue;
                if (!WTSQueryUserToken((uint)session.Id, out var token)) continue;
                using (token)
                using (var user = new WindowsIdentity(token.DangerousGetHandle()))
                    if (user.User is not null) result.Add(new(session.Id, user.User.Value));
            }
            return result;
        }
        finally { WTSFreeMemory(buffer); }
    }

    // El .exe siempre debe estar en el trust. El .dll homonimo solo existe en publicaciones framework-
    // dependent; en single-file (el paquete real) no hay .dll. Si existe, debe estar en el trust y coincidir:
    // un .dll suelto no confiable junto al .exe se rechaza.
    public static void VerifyTrustedBinaries(string executable, IReadOnlyDictionary<string, string> binaryHashes)
    {
        var sidecar = Path.ChangeExtension(executable, ".dll");
        foreach (var path in new[] { executable, sidecar })
        {
            if (!binaryHashes.TryGetValue(path, out var expected))
            {
                if (path == sidecar && !File.Exists(sidecar)) continue;
                throw new InvalidDataException("session_trust_not_provisioned");
            }
            using var stream = File.OpenRead(path);
            if (!Convert.ToHexString(SHA256.HashData(stream)).Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new CryptographicException("session_binary_modified");
        }
    }

    public Process Launch(InteractiveSession session, string executable, string pipeName)
    {
        if (!Path.IsPathFullyQualified(executable) || !File.Exists(executable) || executable.Contains('"')) throw new InvalidDataException("invalid_session_executable");
        VerifyTrustedBinaries(executable, binaryHashes);
        if (!WTSQueryUserToken((uint)session.Id, out var token)) throw new Win32Exception(Marshal.GetLastWin32Error());
        using (token)
        {
            if (!CreateEnvironmentBlock(out var environment, token, false)) throw new Win32Exception(Marshal.GetLastWin32Error());
            try
            {
                var startup = new StartupInfo { Size = Marshal.SizeOf<StartupInfo>(), Desktop = @"winsta0\default" };
                var command = new StringBuilder($"\"{executable}\" {pipeName} {Environment.ProcessId}");
                if (!CreateProcessAsUser(token, executable, command, IntPtr.Zero, IntPtr.Zero, false, 0x400,
                    environment, Path.GetDirectoryName(executable)!, ref startup, out var info)) throw new Win32Exception(Marshal.GetLastWin32Error());
                try { return Process.GetProcessById((int)info.ProcessId); }
                finally { CloseHandle(info.Process); CloseHandle(info.Thread); }
            }
            finally { DestroyEnvironmentBlock(environment); }
        }
    }
    [StructLayout(LayoutKind.Sequential)] private struct SessionInfo { public int Id; public IntPtr Station; public int State; }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] private struct StartupInfo
    {
        public int Size; public string? Reserved; public string? Desktop; public string? Title;
        public int X, Y, XSize, YSize, XCountChars, YCountChars, FillAttribute, Flags;
        public short ShowWindow, ReservedBytes; public IntPtr ReservedPointer, StdInput, StdOutput, StdError;
    }
    [StructLayout(LayoutKind.Sequential)] private struct ProcessInfo { public IntPtr Process, Thread; public uint ProcessId, ThreadId; }
    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSEnumerateSessions(IntPtr server, int reserved, int version, out IntPtr sessions, out int count);
    [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr memory);
    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSQueryUserToken(uint session, out SafeAccessTokenHandle token);
    [DllImport("userenv.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool CreateEnvironmentBlock(out IntPtr environment, SafeAccessTokenHandle token, bool inherit);
    [DllImport("userenv.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool DestroyEnvironmentBlock(IntPtr environment);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateProcessAsUser(SafeAccessTokenHandle token, string application, StringBuilder command,
        IntPtr processAttributes, IntPtr threadAttributes, bool inherit, int flags, IntPtr environment, string directory, ref StartupInfo startup, out ProcessInfo info);
    [DllImport("kernel32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool CloseHandle(IntPtr handle);
}
