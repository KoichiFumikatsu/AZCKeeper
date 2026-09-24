using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;

namespace Keeper.Bootstrapper;

[SupportedOSPlatform("windows")]
public sealed class WindowsElevation : IElevation
{
    private const int TokenIntegrityLevel = 25;
    private const int ErrorInsufficientBuffer = 122;
    private const int HighIntegrity = 0x3000;

    public bool IsElevated
    {
        get
        {
            using var identity = WindowsIdentity.GetCurrent();
            GetTokenInformation(identity.AccessToken, TokenIntegrityLevel, IntPtr.Zero, 0, out var length);
            if (Marshal.GetLastWin32Error() != ErrorInsufficientBuffer)
                throw new Win32Exception(Marshal.GetLastWin32Error(), "No se pudo consultar la integridad del token.");
            var buffer = Marshal.AllocHGlobal(length);
            try
            {
                if (!GetTokenInformation(identity.AccessToken, TokenIntegrityLevel, buffer, length, out _))
                    throw new Win32Exception(Marshal.GetLastWin32Error(), "No se pudo consultar la integridad del token.");
                // TOKEN_MANDATORY_LABEL starts with Label.Sid; its last subauthority is the integrity RID.
                var sid = Marshal.ReadIntPtr(buffer);
                var count = Marshal.ReadByte(GetSidSubAuthorityCount(sid));
                var integrity = Marshal.ReadInt32(GetSidSubAuthority(sid, (uint)(count - 1)));
                return integrity >= HighIntegrity &&
                    (identity.IsSystem || new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator));
            }
            finally
            {
                Marshal.FreeHGlobal(buffer);
            }
        }
    }

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetTokenInformation(SafeAccessTokenHandle token, int informationClass,
        IntPtr information, int informationLength, out int returnLength);

    [DllImport("advapi32.dll")]
    private static extern IntPtr GetSidSubAuthorityCount(IntPtr sid);

    [DllImport("advapi32.dll")]
    private static extern IntPtr GetSidSubAuthority(IntPtr sid, uint subAuthority);

    public int Relaunch(string[] arguments)
    {
        var executable = Environment.ProcessPath ?? throw new InvalidOperationException("No se encontro el ejecutable actual.");
        if (Path.GetFileNameWithoutExtension(executable).Equals("dotnet", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Para elevar, ejecute Keeper.Bootstrapper.exe directamente.");
        var start = new ProcessStartInfo(executable)
        {
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = Environment.CurrentDirectory
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        try
        {
            using var process = Process.Start(start) ?? throw new InvalidOperationException("No se pudo iniciar el bootstrapper elevado.");
            process.WaitForExit();
            return process.ExitCode;
        }
        catch (Win32Exception ex) when (ex.NativeErrorCode == BootstrapApplication.ElevationCancelled)
        {
            return BootstrapApplication.ElevationCancelled;
        }
    }
}
