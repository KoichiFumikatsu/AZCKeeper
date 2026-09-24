using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security;
using System.Security.Principal;

namespace Keeper.Bootstrapper.Hardening;

[SupportedOSPlatform("windows")]
public sealed class WindowsLocalAccounts : ILocalAccounts
{
    public IReadOnlyList<LocalAccount> List()
    {
        var result = new List<LocalAccount>();
        var admins = GroupMembers(AccountSids.Administrators);
        var users = GroupMembers(AccountSids.Users);
        uint resume = 0;
        int status;
        do
        {
            status = NetUserEnum(null, 0, 2, out var buffer, -1, out var read, out _, ref resume);
            try
            {
                if (status != 234) Check(status);
                for (var i = 0; i < read; i++)
                {
                    var name = Marshal.PtrToStringUni(Marshal.ReadIntPtr(buffer, i * IntPtr.Size))!;
                    Check(NetUserGetInfo(null, name, 23, out var info));
                    try
                    {
                        var user = Marshal.PtrToStructure<UserInfo23>(info);
                        var sid = new SecurityIdentifier(user.Sid).Value;
                        result.Add(new(name, sid, (user.Flags & (0x2 | 0x10)) == 0, admins.Contains(sid), users.Contains(sid)));
                    }
                    finally { NetApiBufferFree(info); }
                }
            }
            finally { if (buffer != IntPtr.Zero) NetApiBufferFree(buffer); }
        } while (status == 234);
        return result;
    }

    public IReadOnlyList<string> ActiveUserSids()
    {
        if (!WTSEnumerateSessions(IntPtr.Zero, 0, 1, out var buffer, out var count)) ThrowLastError();
        try
        {
            var result = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            for (var i = 0; i < count; i++)
            {
                var session = Marshal.PtrToStructure<SessionInfo>(buffer + i * Marshal.SizeOf<SessionInfo>());
                if (session.State != 0 || session.Id == 0) continue;
                var name = SessionText(session.Id, 5);
                if (name.Length == 0) continue;
                var domain = SessionText(session.Id, 7);
                var sid = (SecurityIdentifier)new NTAccount(domain, name).Translate(typeof(SecurityIdentifier));
                result.Add(sid.Value);
            }
            return result.ToArray();
        }
        finally { WTSFreeMemory(buffer); }
    }

    public LocalAccount Create(string name, SecureString password)
    {
        var pointer = Marshal.SecureStringToGlobalAllocUnicode(password);
        try
        {
            var info = new UserInfo1 { Name = name, Password = pointer, Privilege = 1, Flags = 0x200 | 0x10000 };
            Check(NetUserAdd(null, 1, ref info, out _));
        }
        finally { Marshal.ZeroFreeGlobalAllocUnicode(pointer); }
        return List().Single(a => a.Name.Equals(name, StringComparison.OrdinalIgnoreCase));
    }

    public void SetPassword(string sid, SecureString password)
    {
        var user = Find(sid);
        if (user.BuiltInAdministrator) throw new InvalidOperationException("protected_account");
        var pointer = Marshal.SecureStringToGlobalAllocUnicode(password);
        try
        {
            var info = new UserInfo1003 { Password = pointer };
            Check(NetUserSetInfo(null, user.Name, 1003, ref info, out _));
        }
        finally { Marshal.ZeroFreeGlobalAllocUnicode(pointer); }
    }

    public void AddToGroup(string sid, string groupSid) => ChangeGroup(sid, groupSid, true);
    public void RemoveFromGroup(string sid, string groupSid) => ChangeGroup(sid, groupSid, false);

    public bool ValidateInteractiveLogon(string sid, SecureString password)
    {
        var user = Find(sid);
        var pointer = Marshal.SecureStringToGlobalAllocUnicode(password);
        try
        {
            // INTERACTIVE (2) remains valid after denying NETWORK (3) to S-1-5-114.
            if (!LogonUser(user.Name, Environment.MachineName, pointer, 2, 0, out var token)) return false;
            try
            {
                using var identity = new WindowsIdentity(token);
                return identity.User?.Value == sid;
            }
            finally { CloseHandle(token); }
        }
        finally { Marshal.ZeroFreeGlobalAllocUnicode(pointer); }
    }

    private LocalAccount Find(string sid) => List().Single(a => a.Sid == sid);
    private static string GroupName(string sid)
    {
        if (sid is not (AccountSids.Administrators or AccountSids.Users)) throw new ArgumentException("unsupported_group");
        var name = ((NTAccount)new SecurityIdentifier(sid).Translate(typeof(NTAccount))).Value;
        return name[(name.LastIndexOf('\\') + 1)..];
    }
    private static HashSet<string> GroupMembers(string groupSid)
    {
        var result = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        nuint resume = 0;
        int status;
        do
        {
            status = NetLocalGroupGetMembers(null, GroupName(groupSid), 0, out var buffer, -1, out var count, out _, ref resume);
            try
            {
                if (status != 234) Check(status);
                for (var i = 0; i < count; i++) result.Add(new SecurityIdentifier(Marshal.ReadIntPtr(buffer, i * IntPtr.Size)).Value);
            }
            finally { if (buffer != IntPtr.Zero) NetApiBufferFree(buffer); }
        } while (status == 234);
        return result;
    }
    private void ChangeGroup(string sid, string groupSid, bool add)
    {
        var user = Find(sid);
        if (!add && user.BuiltInAdministrator) throw new InvalidOperationException("protected_account");
        var securityId = new SecurityIdentifier(sid);
        var bytes = new byte[securityId.BinaryLength];
        securityId.GetBinaryForm(bytes, 0);
        var pinned = GCHandle.Alloc(bytes, GCHandleType.Pinned);
        try
        {
            var member = pinned.AddrOfPinnedObject();
            var status = add ? NetLocalGroupAddMembers(null, GroupName(groupSid), 0, ref member, 1)
                : NetLocalGroupDelMembers(null, GroupName(groupSid), 0, ref member, 1);
            if (status != (add ? 1378 : 1377)) Check(status);
        }
        finally { pinned.Free(); }
    }
    private static string SessionText(int session, int field)
    {
        if (!WTSQuerySessionInformation(IntPtr.Zero, session, field, out var buffer, out _)) ThrowLastError();
        try { return Marshal.PtrToStringUni(buffer) ?? ""; }
        finally { WTSFreeMemory(buffer); }
    }
    private static void Check(int status) { if (status != 0) throw new Win32Exception(status); }
    private static void ThrowLastError() => throw new Win32Exception(Marshal.GetLastWin32Error());

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct UserInfo1
    {
        public string Name;
        public IntPtr Password;
        public uint PasswordAge, Privilege;
        public string? HomeDirectory, Comment;
        public uint Flags;
        public string? ScriptPath;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct UserInfo1003 { public IntPtr Password; }
    [StructLayout(LayoutKind.Sequential)]
    private struct UserInfo23 { public IntPtr Name, FullName, Comment; public uint Flags; public IntPtr Sid; }
    [StructLayout(LayoutKind.Sequential)]
    private struct SessionInfo { public int Id; public IntPtr StationName; public int State; }

    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetUserEnum(string? server, int level, int filter, out IntPtr buffer, int length, out int read, out int total, ref uint resume);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetUserGetInfo(string? server, string name, int level, out IntPtr buffer);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetUserAdd(string? server, int level, ref UserInfo1 info, out int error);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetUserSetInfo(string? server, string name, int level, ref UserInfo1003 info, out int error);
    [DllImport("netapi32.dll")] private static extern int NetApiBufferFree(IntPtr buffer);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetLocalGroupGetMembers(string? server, string group, int level, out IntPtr buffer, int length, out int read, out int total, ref nuint resume);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetLocalGroupAddMembers(string? server, string group, int level, ref IntPtr member, int count);
    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)] private static extern int NetLocalGroupDelMembers(string? server, string group, int level, ref IntPtr member, int count);
    [DllImport("advapi32.dll", EntryPoint = "LogonUserW", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool LogonUser(string name, string domain, IntPtr password, int type, int provider, out IntPtr token);
    [DllImport("kernel32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool CloseHandle(IntPtr handle);
    [DllImport("wtsapi32.dll", EntryPoint = "WTSEnumerateSessionsW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSEnumerateSessions(IntPtr server, int reserved, int version, out IntPtr buffer, out int count);
    [DllImport("wtsapi32.dll", EntryPoint = "WTSQuerySessionInformationW", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSQuerySessionInformation(IntPtr server, int session, int field, out IntPtr buffer, out int length);
    [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr memory);
}
