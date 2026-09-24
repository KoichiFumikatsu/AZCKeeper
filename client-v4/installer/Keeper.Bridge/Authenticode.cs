using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Keeper.Agent.Migration;

namespace Keeper.Bridge;

internal static class Authenticode
{
    public static void Verify(string path, BridgePublisher publisher)
    {
        using var certificate = new X509Certificate2(X509Certificate.CreateFromSignedFile(path));
        BridgePackageVerifier.VerifyPublisher(certificate, publisher, DateTimeOffset.UtcNow);
        var file = new FileInfoData { Size = (uint)Marshal.SizeOf<FileInfoData>(), Path = path };
        var pointer = Marshal.AllocHGlobal(Marshal.SizeOf<FileInfoData>());
        Marshal.StructureToPtr(file, pointer, false);
        var data = new TrustData { Size = (uint)Marshal.SizeOf<TrustData>(), Ui = 2, Choice = 1, File = pointer, StateAction = 1, Flags = 0x1000 };
        var action = new Guid("00AAC56B-CD44-11d0-8CC2-00C04FC295EE");
        try
        {
            var result = WinVerifyTrust(new IntPtr(-1), ref action, ref data);
            // The signed release binds every byte. Only the pinned self-signed root may be initially untrusted.
            if (result != 0 && !(result == unchecked((int)0x800B0109) && certificate.Subject == certificate.Issuer))
                throw new CryptographicException("authenticode_invalid");
        }
        finally
        {
            data.StateAction = 2;
            WinVerifyTrust(new IntPtr(-1), ref action, ref data);
            Marshal.DestroyStructure<FileInfoData>(pointer);
            Marshal.FreeHGlobal(pointer);
        }
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct FileInfoData { public uint Size; [MarshalAs(UnmanagedType.LPWStr)] public string Path; public IntPtr File, KnownSubject; }
    [StructLayout(LayoutKind.Sequential)]
    private struct TrustData
    {
        public uint Size; public IntPtr Policy, Sip; public uint Ui, Revocation, Choice; public IntPtr File;
        public uint StateAction; public IntPtr StateData, Url; public uint Flags, Context; public IntPtr Signature;
    }
    [DllImport("wintrust.dll", ExactSpelling = true)] private static extern int WinVerifyTrust(IntPtr window, ref Guid action, ref TrustData data);
}
