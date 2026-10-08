using System;
using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Security.Claims;
using System.Security.Principal;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Migration
{
    internal enum V4ServiceState { Missing, Stopped, Running, Other }

    /// <summary>Lecturas del sistema que hace la transicion sin elevar. Abstraidas para probar sin Windows real.</summary>
    internal interface IV4SystemProbe
    {
        int? ReadHklmDword(string path, string name);
        bool UserIsAdministrator();
        V4ServiceState QueryService(string name);
        /// <summary>true si Keeper.Session (el capturador de v4) corre en la sesion de este usuario.</summary>
        bool SessionAgentRunning();
    }

    internal interface IV4Downloader
    {
        Task<byte[]> GetSmallAsync(Uri url, int maxBytes, CancellationToken ct);
        /// <summary>Copia el cuerpo a <paramref name="destination"/> sin pasar de <paramref name="maxBytes"/>.</summary>
        Task DownloadAsync(Uri url, Stream destination, long maxBytes, CancellationToken ct);
    }

    internal sealed class V4ElevationCancelledException : Exception
    {
        public V4ElevationCancelledException() : base("uac_cancelled") { }
    }

    internal interface IV4Launcher
    {
        /// <summary>
        /// Lanza elevado (runas) y espera. Devuelve el codigo de salida o null si se agoto el tiempo.
        /// Lanza <see cref="V4ElevationCancelledException"/> si el usuario cancela el UAC (1223).
        /// </summary>
        int? RunElevated(string executable, string arguments, string workingDirectory, TimeSpan timeout);
    }

    internal sealed class WindowsV4SystemProbe : IV4SystemProbe
    {
        private const string AdministratorsSid = "S-1-5-32-544";

        public int? ReadHklmDword(string path, string name)
        {
            using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
            using var key = machine.OpenSubKey(path, writable: false);
            return key?.GetValue(name) is int value ? value : null;
        }

        // Con UAC el token del usuario es filtrado: Administradores aparece como "deny only", no como grupo habilitado,
        // y IsInRole devuelve false. La pertenencia real se ve en cualquiera de los dos tipos de claim.
        public bool UserIsAdministrator()
        {
            using var identity = WindowsIdentity.GetCurrent();
            if (new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator)) return true;
            return identity.Claims.Any(c => c.Value == AdministratorsSid &&
                (c.Type == ClaimTypes.GroupSid || c.Type == ClaimTypes.DenyOnlySid));
        }

        public V4ServiceState QueryService(string name)
        {
            IntPtr manager = OpenSCManager(null, null, ScManagerConnect);
            if (manager == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            try
            {
                IntPtr service = OpenService(manager, name, ServiceQueryStatus);
                if (service == IntPtr.Zero)
                {
                    int error = Marshal.GetLastWin32Error();
                    if (error == ErrorServiceDoesNotExist) return V4ServiceState.Missing;
                    throw new Win32Exception(error);
                }
                try
                {
                    if (!QueryServiceStatus(service, out var status)) throw new Win32Exception(Marshal.GetLastWin32Error());
                    return status.CurrentState switch
                    {
                        ServiceRunning => V4ServiceState.Running,
                        ServiceStopped => V4ServiceState.Stopped,
                        _ => V4ServiceState.Other
                    };
                }
                finally { CloseServiceHandle(service); }
            }
            finally { CloseServiceHandle(manager); }
        }

        public bool SessionAgentRunning()
        {
            int session = Process.GetCurrentProcess().SessionId;
            var processes = Process.GetProcessesByName("Keeper.Session");
            try { return processes.Any(p => p.SessionId == session); }
            finally { foreach (var p in processes) p.Dispose(); }
        }

        private const uint ScManagerConnect = 0x0001;
        private const uint ServiceQueryStatus = 0x0004;
        private const int ErrorServiceDoesNotExist = 1060;
        private const int ServiceStopped = 1;
        private const int ServiceRunning = 4;

        [StructLayout(LayoutKind.Sequential)]
        private struct ServiceStatus
        {
            public int ServiceType, CurrentState, ControlsAccepted, Win32ExitCode, ServiceSpecificExitCode, CheckPoint, WaitHint;
        }

        [DllImport("advapi32.dll", EntryPoint = "OpenSCManagerW", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern IntPtr OpenSCManager(string machine, string database, uint access);

        [DllImport("advapi32.dll", EntryPoint = "OpenServiceW", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern IntPtr OpenService(IntPtr manager, string name, uint access);

        [DllImport("advapi32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool QueryServiceStatus(IntPtr service, out ServiceStatus status);

        [DllImport("advapi32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool CloseServiceHandle(IntPtr handle);
    }

    internal sealed class HttpV4Downloader : IV4Downloader, IDisposable
    {
        private readonly HttpClient _http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false })
        {
            Timeout = TimeSpan.FromMinutes(15)
        };

        public async Task<byte[]> GetSmallAsync(Uri url, int maxBytes, CancellationToken ct)
        {
            using var buffer = new MemoryStream();
            await DownloadAsync(url, buffer, maxBytes, ct).ConfigureAwait(false);
            return buffer.ToArray();
        }

        public async Task DownloadAsync(Uri url, Stream destination, long maxBytes, CancellationToken ct)
        {
            if (url.Scheme != Uri.UriSchemeHttps) throw new InvalidDataException("https_required");
            using var response = await _http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode) throw new HttpRequestException($"http_{(int)response.StatusCode}");
            if (response.Content.Headers.ContentLength > maxBytes) throw new InvalidDataException("download_too_large");
            using var body = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false);
            var chunk = new byte[81920];
            long total = 0;
            int read;
            while ((read = await body.ReadAsync(chunk, ct).ConfigureAwait(false)) > 0)
            {
                total += read;
                if (total > maxBytes) throw new InvalidDataException("download_too_large");
                await destination.WriteAsync(chunk.AsMemory(0, read), ct).ConfigureAwait(false);
            }
        }

        public void Dispose() => _http.Dispose();
    }

    internal sealed class WindowsV4Launcher : IV4Launcher
    {
        private const int ErrorCancelled = 1223;

        public int? RunElevated(string executable, string arguments, string workingDirectory, TimeSpan timeout)
        {
            var start = new ProcessStartInfo(executable, arguments)
            {
                UseShellExecute = true,
                Verb = "runas",
                WorkingDirectory = workingDirectory,
                WindowStyle = ProcessWindowStyle.Hidden
            };
            Process process;
            try { process = Process.Start(start); }
            catch (Win32Exception ex) when (ex.NativeErrorCode == ErrorCancelled) { throw new V4ElevationCancelledException(); }
            if (process == null) throw new InvalidOperationException("bootstrapper_not_started");
            using (process)
            {
                if (!process.WaitForExit((int)timeout.TotalMilliseconds)) return null;
                return process.ExitCode;
            }
        }
    }
}
