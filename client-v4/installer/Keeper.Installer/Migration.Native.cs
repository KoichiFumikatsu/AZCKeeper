using System;
using System.ComponentModel;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Security;
using System.Security.Principal;

namespace Keeper.Migration
{
    public sealed class SessionToken
    {
        public int Id;
        public string Sid;
        public bool Standard;
        public string LogonId;
        public SessionToken(int id, string sid, bool standard, string logonId) { Id = id; Sid = sid; Standard = standard; LogonId = logonId; }
    }

    public static class Native
    {
        public static string Seal(SecureString password, string tenant, string device, string sid, int revision, byte[] publicKey)
        {
            Guid.Parse(tenant); Guid.Parse(device);
            if (!System.Text.RegularExpressions.Regex.IsMatch(sid, @"^S-1-5-21-\d+-\d+-\d+-\d+$") || revision < 1 || publicKey.Length != 32 || password.Length != 32)
                throw new InvalidOperationException("invalid_escrow_input");
            var prefix = System.Text.Encoding.UTF8.GetBytes("{\"tenant_id\":\"" + tenant + "\",\"device_id\":\"" + device + "\",\"account_sid\":\"" + sid + "\",\"revision\":" + revision + ",\"password\":\"");
            var plain = new byte[prefix.Length + 34];
            Array.Copy(prefix, plain, prefix.Length);
            IntPtr pointer = Marshal.SecureStringToGlobalAllocUnicode(password);
            try
            {
                for (int i = 0; i < 32; i++)
                {
                    var c = (char)Marshal.ReadInt16(pointer, i * 2);
                    if (c < 0x21 || c > 0x7e || c == '"' || c == '\\') throw new InvalidOperationException("invalid_password_character");
                    plain[prefix.Length + i] = (byte)c;
                }
                plain[plain.Length - 2] = (byte)'"'; plain[plain.Length - 1] = (byte)'}';
                var cipher = new byte[plain.Length + 48];
                if (sodium_init() < 0 || crypto_box_seal(cipher, plain, (ulong)plain.Length, publicKey) != 0) throw new InvalidOperationException("escrow_encryption_failed");
                return Convert.ToBase64String(cipher);
            }
            finally { Array.Clear(plain, 0, plain.Length); Marshal.ZeroFreeGlobalAllocUnicode(pointer); }
        }

        // libsodium se distribuye SIN firma Authenticode, asi que la confianza no puede venir del
        // editor: se ancla el SHA-256 esperado antes de cargarla. Cifra el sobre del escrow, de modo
        // que una DLL sustituida podria entregar la contrasena de azcadmin en claro.
        public static void LoadSodium(string path, string expectedSha256)
        {
            if (!System.IO.Path.IsPathRooted(path)) throw new ArgumentException("sodium_path_must_be_absolute", "path");
            if (expectedSha256 == null || expectedSha256.Length != 64) throw new ArgumentException("sodium_hash_required", "expectedSha256");
            byte[] digest;
            using (var stream = new System.IO.FileStream(path, System.IO.FileMode.Open, System.IO.FileAccess.Read, System.IO.FileShare.Read))
            using (var sha = System.Security.Cryptography.SHA256.Create())
                digest = sha.ComputeHash(stream);
            var actual = BitConverter.ToString(digest).Replace("-", string.Empty);
            if (!string.Equals(actual, expectedSha256, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("sodium_hash_mismatch");
            // 0x100|0x800: buscar solo en el directorio de la aplicacion y System32, nunca en el PATH.
            if (LoadLibraryEx(path, IntPtr.Zero, 0x100 | 0x800) == IntPtr.Zero) throw new Win32Exception();
        }

        // NETWORK validates the password without opening an interactive session or caching credentials.
        public static int Probe(string name, SecureString password)
        {
            IntPtr plain = Marshal.SecureStringToGlobalAllocUnicode(password);
            IntPtr token;
            try
            {
                if (!LogonUser(name, Environment.MachineName, plain, 3, 0, out token)) return Marshal.GetLastWin32Error();
                CloseHandle(token);
                return 0;
            }
            finally { Marshal.ZeroFreeGlobalAllocUnicode(plain); }
        }

        public static SecureString NewPassword()
        {
            const string alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*-_";
            using (var rng = System.Security.Cryptography.RandomNumberGenerator.Create())
            {
                var bytes = new byte[1];
                var secret = new SecureString();
                // Guarantee all four Windows complexity categories; the remaining characters are random.
                string[] pools = { "ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnopqrstuvwxyz", "23456789", "!@#$%*-_" };
                for (int i = 0; i < 32; i++)
                {
                    var pool = i < 4 ? pools[i] : alphabet;
                    do { rng.GetBytes(bytes); } while (bytes[0] >= 256 - (256 % pool.Length));
                    secret.AppendChar(pool[bytes[0] % pool.Length]);
                }
                Array.Clear(bytes, 0, bytes.Length);
                secret.MakeReadOnly();
                return secret;
            }
        }

        public static SessionToken[] Sessions()
        {
            using (var current = WindowsIdentity.GetCurrent())
                if (!current.IsSystem) throw new InvalidOperationException("token_query_requires_SYSTEM");
            IntPtr buffer;
            int count;
            if (!WTSEnumerateSessions(IntPtr.Zero, 0, 1, out buffer, out count)) throw new Win32Exception();
            try
            {
                var result = new List<SessionToken>();
                for (int i = 0; i < count; i++)
                {
                    var session = (SessionInfo)Marshal.PtrToStructure(IntPtr.Add(buffer, i * Marshal.SizeOf(typeof(SessionInfo))), typeof(SessionInfo));
                    if (session.Id == 0 || (session.State != 0 && session.State != 4)) continue;
                    IntPtr token;
                    if (!WTSQueryUserToken((uint)session.Id, out token)) throw new Win32Exception();
                    try
                    {
                        using (var identity = new WindowsIdentity(token))
                            result.Add(new SessionToken(session.Id, identity.User.Value, IsStandard(token, identity), AuthenticationId(token)));
                    }
                    finally { CloseHandle(token); }
                }
                return result.ToArray();
            }
            finally { WTSFreeMemory(buffer); }
        }

        private static bool IsStandard(IntPtr token, WindowsIdentity identity)
        {
            IntPtr duplicate;
            if (!DuplicateToken(token, 2, out duplicate)) throw new Win32Exception();
            try
            {
                var sid = new SecurityIdentifier("S-1-5-32-544");
                var bytes = new byte[sid.BinaryLength];
                sid.GetBinaryForm(bytes, 0);
                bool member;
                if (!CheckTokenMembership(duplicate, bytes, out member)) throw new Win32Exception();
                if (member) return false;
                // A filtered UAC administrator has Administrators as deny-only: membership alone is insufficient.
                foreach (var group in identity.Groups) if (group.Value == sid.Value) return false;
                return true;
            }
            finally { CloseHandle(duplicate); }
        }

        private static string AuthenticationId(IntPtr token)
        {
            int length;
            GetTokenInformation(token, 10, IntPtr.Zero, 0, out length);
            IntPtr buffer = Marshal.AllocHGlobal(length);
            try
            {
                if (!GetTokenInformation(token, 10, buffer, length, out length)) throw new Win32Exception();
                // TOKEN_STATISTICS starts with TokenId and AuthenticationId (two LUIDs).
                return Marshal.ReadInt64(buffer, 8).ToString("X16");
            }
            finally { Marshal.FreeHGlobal(buffer); }
        }

        public static void Warn(int sessionId, int seconds)
        {
            string title = "AZCKeeper";
            string message = "Guarde su trabajo. Su sesion se cerrara en " + seconds + " segundos para completar la migracion.";
            int response;
            if (!WTSSendMessage(IntPtr.Zero, sessionId, title, title.Length * 2, message, message.Length * 2, 0x40, seconds, out response, false))
                throw new Win32Exception();
        }

        public static void Logoff(int sessionId)
        {
            if (!WTSLogoffSession(IntPtr.Zero, sessionId, true)) throw new Win32Exception();
        }

        [StructLayout(LayoutKind.Sequential)] private struct SessionInfo { public int Id; public IntPtr Station; public int State; }
        [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool LogonUser(string user, string domain, IntPtr password, int type, int provider, out IntPtr token);
        [DllImport("advapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool DuplicateToken(IntPtr token, int level, out IntPtr duplicate);
        [DllImport("advapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool CheckTokenMembership(IntPtr token, byte[] sid, out bool member);
        [DllImport("advapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool GetTokenInformation(IntPtr token, int type, IntPtr buffer, int length, out int returned);
        [DllImport("kernel32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool CloseHandle(IntPtr handle);
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] private static extern IntPtr LoadLibraryEx(string path, IntPtr file, int flags);
        [DllImport("libsodium.dll", CallingConvention = CallingConvention.Cdecl)] private static extern int sodium_init();
        [DllImport("libsodium.dll", CallingConvention = CallingConvention.Cdecl)] private static extern int crypto_box_seal(byte[] cipher, byte[] message, ulong length, byte[] key);
        [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSEnumerateSessions(IntPtr server, int reserved, int version, out IntPtr sessions, out int count);
        [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr buffer);
        [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSQueryUserToken(uint session, out IntPtr token);
        [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSLogoffSession(IntPtr server, int session, bool wait);
        [DllImport("wtsapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool WTSSendMessage(IntPtr server, int session, string title, int titleLength, string message, int messageLength, int style, int timeout, out int response, bool wait);
    }
}
