using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using Microsoft.Win32;

namespace Keeper.Bootstrapper.Hardening;

[SupportedOSPlatform("windows")]
public sealed class WindowsSecurityPolicy : ISecurityPolicy
{
    public const string Right = "SeDenyNetworkLogonRight";
    public const string VisibilityKey = @"SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList";

    public bool HasNetworkDeny() => WithLsa(handle =>
    {
        var status = LsaEnumerateAccountRights(handle, SidBytes(), out var rights, out var count);
        if (status == 0xC0000034) return false; // STATUS_OBJECT_NAME_NOT_FOUND: no rights assigned.
        Check(status);
        try
        {
            for (var i = 0; i < count; i++)
            {
                var right = Marshal.PtrToStructure<LsaString>(rights + i * Marshal.SizeOf<LsaString>());
                if (Marshal.PtrToStringUni(right.Buffer, right.Length / 2) == Right) return true;
            }
            return false;
        }
        finally { LsaFreeMemory(rights); }
    });

    public void SetNetworkDeny(bool enabled)
    {
        if (HasNetworkDeny() == enabled) return;
        WithLsa(handle =>
        {
            var buffer = Marshal.StringToHGlobalUni(Right);
            try
            {
                var right = new LsaString { Buffer = buffer, Length = checked((ushort)(Right.Length * 2)), MaximumLength = checked((ushort)((Right.Length + 1) * 2)) };
                Check(enabled ? LsaAddAccountRights(handle, SidBytes(), [right], 1)
                    : LsaRemoveAccountRights(handle, SidBytes(), false, [right], 1));
                return true;
            }
            finally { Marshal.FreeHGlobal(buffer); }
        });
    }

    public int? ReadVisibility(string name)
    {
        using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = root.OpenSubKey(VisibilityKey);
        var value = key?.GetValue(name);
        if (value is null) return null;
        if (key!.GetValueKind(name) != RegistryValueKind.DWord || value is not int number)
            throw new InvalidOperationException("visibility_type_conflict");
        return number;
    }

    public void SetVisibility(string name, int? value)
    {
        using var root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        if (value is { } number)
        {
            using var key = root.CreateSubKey(VisibilityKey, true);
            key.SetValue(name, number, RegistryValueKind.DWord);
        }
        else
        {
            using var key = root.OpenSubKey(VisibilityKey, true);
            key?.DeleteValue(name, false);
        }
    }

    private static T WithLsa<T>(Func<IntPtr, T> action)
    {
        var attributes = new ObjectAttributes { Length = (uint)Marshal.SizeOf<ObjectAttributes>() };
        Check(LsaOpenPolicy(IntPtr.Zero, ref attributes, 0x1 | 0x10 | 0x800, out var handle));
        try { return action(handle); }
        finally { LsaClose(handle); }
    }
    private static byte[] SidBytes()
    {
        var sid = new SecurityIdentifier(AccountSids.LocalAdministrators);
        var bytes = new byte[sid.BinaryLength];
        sid.GetBinaryForm(bytes, 0);
        return bytes;
    }
    private static void Check(uint status) { if (status != 0) throw new Win32Exception((int)LsaNtStatusToWinError(status)); }
    [StructLayout(LayoutKind.Sequential)]
    private struct ObjectAttributes
    {
        public uint Length;
        public IntPtr RootDirectory, ObjectName;
        public uint Attributes;
        public IntPtr SecurityDescriptor, SecurityQualityOfService;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct LsaString { public ushort Length, MaximumLength; public IntPtr Buffer; }
    [DllImport("advapi32.dll")] private static extern uint LsaOpenPolicy(IntPtr system, ref ObjectAttributes attributes, uint access, out IntPtr handle);
    [DllImport("advapi32.dll")] private static extern uint LsaEnumerateAccountRights(IntPtr handle, byte[] sid, out IntPtr rights, out int count);
    [DllImport("advapi32.dll")] private static extern uint LsaAddAccountRights(IntPtr handle, byte[] sid, LsaString[] rights, int count);
    [DllImport("advapi32.dll")] private static extern uint LsaRemoveAccountRights(IntPtr handle, byte[] sid, [MarshalAs(UnmanagedType.U1)] bool allRights, LsaString[] rights, int count);
    [DllImport("advapi32.dll")] private static extern uint LsaFreeMemory(IntPtr memory);
    [DllImport("advapi32.dll")] private static extern uint LsaClose(IntPtr handle);
    [DllImport("advapi32.dll")] private static extern uint LsaNtStatusToWinError(uint status);
}
