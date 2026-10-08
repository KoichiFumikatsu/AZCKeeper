using System;
using System.ComponentModel;
using System.IO;
using System.Net.Http;
using System.Security.Cryptography;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Migration
{
    /// <summary>
    /// Release puente K3 -> Keeper v4 (docs/architecture/v4-client-agent.md §7.2). Corre tras un login valido de K3:
    /// 1) preflight sin elevar (UAC, Smart App Control, Administradores, servicio KeeperAgent) -> v4_preflight;
    ///    si no se puede, v4_transition_skipped y no reintenta en este arranque;
    /// 2) si KeeperAgent ya corre, salta a la espera de cierre;
    /// 3) baja el manifiesto firmado y el ZIP de devkeep y los verifica con la clave EMBEBIDA (v4_transition_failed si no);
    /// 4) escribe installation.json (api_base, enrollment_key, claimed_document = CC numerica de K3: credenciales guardadas o,
    ///    si no hay, la userCc del handshake) y lanza el bootstrapper
    ///    con runas UNA vez; borra installation.json y la carpeta temporal siempre; UAC cancelado -> v4_transition_cancelled;
    /// 5) cuando KeeperAgent corre y Keeper.Session captura en esta sesion (5 min seguidos): v4_transition_done, quita el
    ///    autoarranque de K3 y lo cierra. No borra datos de K3.
    /// Cualquier fallo deja K3 funcionando; los fallos de descarga/instalacion se reintentan cada 6 h.
    /// </summary>
    internal sealed class V4TransitionManager : IDisposable
    {
        public const string ServiceName = "KeeperAgent";
        public const string ReportSource = "update";
        public static readonly TimeSpan RetryInterval = TimeSpan.FromHours(6);
        public static readonly TimeSpan TickInterval = TimeSpan.FromSeconds(30);
        public static readonly TimeSpan BootstrapperTimeout = TimeSpan.FromMinutes(15);
        public static readonly TimeSpan WaitingNotice = TimeSpan.FromMinutes(30);

        internal enum Phase { Idle, Watching, Stopped, Done }

        private readonly V4BuildSettings _settings;
        private readonly V4ReleaseTrust _trust;
        private readonly IV4SystemProbe _probe;
        private readonly IV4Downloader _downloader;
        private readonly IV4Launcher _launcher;
        private readonly Func<string> _loadUsername;
        private readonly Func<string> _loadServerCc;
        private readonly Action<LocalLogger.LogLevel, string> _report;
        private readonly Action _closeK3;
        private readonly Func<DateTimeOffset> _clock;
        private readonly string _workRoot;
        private readonly V4CloseTracker _close = new V4CloseTracker();

        private System.Threading.Timer _timer;
        private int _busy;
        private bool _preflightReported;
        private bool _waitingReported;
        private DateTimeOffset _due;
        private DateTimeOffset _watchingSince;

        internal Phase CurrentPhase { get; private set; } = Phase.Idle;
        internal DateTimeOffset Due => _due;

        public V4TransitionManager(V4BuildSettings settings, V4ReleaseTrust trust, IV4SystemProbe probe, IV4Downloader downloader,
            IV4Launcher launcher, Func<string> loadUsername, Func<string> loadServerCc, Action<LocalLogger.LogLevel, string> report,
            Action closeK3, Func<DateTimeOffset> clock, string workRoot)
        {
            _settings = settings; _trust = trust; _probe = probe; _downloader = downloader; _launcher = launcher;
            _loadUsername = loadUsername; _loadServerCc = loadServerCc; _report = report; _closeK3 = closeK3; _clock = clock; _workRoot = workRoot;
        }

        public static string DefaultWorkRoot => Path.Combine(Path.GetTempPath(), "AZCKeeper-v4-transition");

        /// <summary>Arranca el ciclo (idempotente). Primer intento tras <paramref name="firstDelay"/>.</summary>
        public void Start(TimeSpan firstDelay)
        {
            if (_timer != null) return;
            _due = _clock() + firstDelay;
            _timer = new System.Threading.Timer(_ => Tick(), null, TickInterval, TickInterval);
        }

        private void Tick()
        {
            if (Interlocked.Exchange(ref _busy, 1) == 1) return;
            try { TickAsync(CancellationToken.None).GetAwaiter().GetResult(); }
            catch (Exception ex) { LocalLogger.Error(ex, "V4TransitionManager: error inesperado; K3 sigue funcionando."); }
            finally { Interlocked.Exchange(ref _busy, 0); }
        }

        internal async Task TickAsync(CancellationToken ct)
        {
            var now = _clock();
            switch (CurrentPhase)
            {
                case Phase.Watching: Watch(now); return;
                case Phase.Idle when now >= _due: await AttemptAsync(now, ct).ConfigureAwait(false); return;
                default: return;
            }
        }

        private async Task AttemptAsync(DateTimeOffset now, CancellationToken ct)
        {
            CleanWorkRoot();
            V4PreflightResult preflight;
            try { preflight = V4PreflightResult.Read(_probe, ServiceName); }
            catch (Exception ex) when (ex is Win32Exception or UnauthorizedAccessException or IOException or System.Security.SecurityException)
            {
                Fail(now, "preflight_error_" + ex.GetType().Name);
                return;
            }
            if (!_preflightReported)
            {
                _preflightReported = true;
                _report(LocalLogger.LogLevel.Info, $"v4_preflight: {preflight.Describe()}");
            }
            if (preflight.Service == V4ServiceState.Running)
            {
                StartWatching(now);
                return;
            }
            var skip = preflight.SkipReason() ?? (_settings.HasEnrollmentKey ? null : "enrollment_key_missing");
            if (skip != null)
            {
                // Una vez por arranque: no se reintenta hasta que K3 vuelva a iniciar.
                _report(LocalLogger.LogLevel.Warn, $"v4_transition_skipped: {skip}");
                CurrentPhase = Phase.Stopped;
                return;
            }

            var work = Path.Combine(_workRoot, Guid.NewGuid().ToString("N"));
            var package = Path.Combine(work, "pkg");
            var config = Path.Combine(package, "installation.json");
            var keepWork = false;
            try
            {
                Directory.CreateDirectory(work);
                var manifest = await _downloader.GetSmallAsync(_settings.ManifestUrl, 64 * 1024, ct).ConfigureAwait(false);
                var release = V4ReleaseVerifier.VerifyManifest(manifest, _trust, _settings.Origin);
                string bootstrapper;
                // El mismo handle exclusivo se descarga, verifica y extrae: nadie lo cambia entre la verificacion y el uso.
                using (var zip = new FileStream(Path.Combine(work, "package.zip"), FileMode.CreateNew, FileAccess.ReadWrite,
                           FileShare.None, 81920, FileOptions.DeleteOnClose))
                {
                    await _downloader.DownloadAsync(release.ArtifactUrl, zip, release.SizeBytes, ct).ConfigureAwait(false);
                    await V4ReleaseVerifier.VerifyPackageAsync(zip, release, ct).ConfigureAwait(false);
                    bootstrapper = V4Installation.Extract(zip, package);
                }

                var (document, source) = V4Installation.ResolveClaimedDocument(SafeRead(_loadUsername), SafeRead(_loadServerCc));
                File.WriteAllText(config, V4Installation.BuildConfigJson(_settings.ApiBase, _settings.EnrollmentKey, document));
                _report(LocalLogger.LogLevel.Info,
                    $"v4_transition: instalando Keeper v4 {release.Version} (sequence {release.Sequence}); " +
                    (document != null ? $"cedula_declarada=si origen={source}" : "cedula_declarada=no"));

                int? code;
                try { code = _launcher.RunElevated(bootstrapper, $"--system-install --config \"{config}\"", Path.GetDirectoryName(bootstrapper), BootstrapperTimeout); }
                finally { DeleteFile(config); }

                if (code == 0)
                {
                    _report(LocalLogger.LogLevel.Info, $"v4_transition_installed: Keeper v4 {release.Version}; esperando que capture en esta sesion");
                    StartWatching(now);
                }
                else if (code == null)
                {
                    keepWork = true; // el bootstrapper sigue corriendo desde esa carpeta; se limpia en el proximo intento/arranque
                    Fail(now, "bootstrapper_timeout");
                }
                else Fail(now, code == 740 ? "not_elevated" : $"bootstrapper_exit_{code}");
            }
            catch (V4ElevationCancelledException)
            {
                _report(LocalLogger.LogLevel.Warn, "v4_transition_cancelled: el usuario cancelo el UAC; se reintenta en el proximo arranque de K3");
                CurrentPhase = Phase.Stopped;
            }
            catch (Exception ex) when (ex is CryptographicException or InvalidDataException)
            {
                Fail(now, ex.Message);
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or IOException or UnauthorizedAccessException
                                           or Win32Exception or InvalidOperationException or ArgumentException)
            {
                Fail(now, ex is HttpRequestException ? "download_" + ex.Message : ex.GetType().Name);
            }
            finally
            {
                DeleteFile(config);
                if (!keepWork) DeleteDirectory(work);
            }
        }

        private void Watch(DateTimeOffset now)
        {
            V4ServiceState service;
            bool session;
            try
            {
                service = _probe.QueryService(ServiceName);
                session = service == V4ServiceState.Running && _probe.SessionAgentRunning();
            }
            catch (Exception ex) when (ex is Win32Exception or InvalidOperationException or UnauthorizedAccessException)
            {
                return;
            }
            if (_close.Observe(now, service, session))
            {
                CurrentPhase = Phase.Done;
                _report(LocalLogger.LogLevel.Info,
                    "v4_transition_done: KeeperAgent Running y Keeper.Session capturando en esta sesion; K3 quita su autoarranque y se cierra");
                _closeK3();
                return;
            }
            if (service == V4ServiceState.Missing && now - _watchingSince >= WaitingNotice)
            {
                // Desinstalado despues de instalarse: vuelve a intentarlo en el ciclo normal.
                CurrentPhase = Phase.Idle;
                _due = now + RetryInterval;
                return;
            }
            if (!_waitingReported && now - _watchingSince >= WaitingNotice)
            {
                _waitingReported = true;
                _report(LocalLogger.LogLevel.Info,
                    $"v4_transition_waiting: service={service.ToString().ToLowerInvariant()} session={(session ? "si" : "no")}; " +
                    "alta v4 pendiente de aprobacion o sesion sin capturar. K3 sigue capturando");
            }
        }

        private void StartWatching(DateTimeOffset now)
        {
            CurrentPhase = Phase.Watching;
            _watchingSince = now;
        }

        private void Fail(DateTimeOffset now, string reason)
        {
            _report(LocalLogger.LogLevel.Warn, $"v4_transition_failed: {reason}; reintento en {(int)RetryInterval.TotalHours} h");
            CurrentPhase = Phase.Idle;
            _due = now + RetryInterval;
        }

        private static string SafeRead(Func<string> load)
        {
            try { return load?.Invoke(); }
            catch (Exception ex) { LocalLogger.Warn($"V4TransitionManager: no se pudo leer la CC de K3 ({ex.GetType().Name})."); return null; }
        }

        // Restos de un intento anterior (K3 cerrado a mitad, timeout del bootstrapper): pueden contener installation.json.
        private void CleanWorkRoot()
        {
            try
            {
                if (!Directory.Exists(_workRoot)) return;
                foreach (var file in Directory.EnumerateFiles(_workRoot, "installation.json", SearchOption.AllDirectories)) DeleteFile(file);
                foreach (var directory in Directory.EnumerateDirectories(_workRoot)) DeleteDirectory(directory);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        }

        private static void DeleteFile(string path)
        {
            try { if (File.Exists(path)) File.Delete(path); }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                LocalLogger.Warn($"V4TransitionManager: no se pudo borrar un archivo temporal ({ex.GetType().Name}); se reintenta al proximo intento.");
            }
        }

        private static void DeleteDirectory(string path)
        {
            try { if (Directory.Exists(path)) Directory.Delete(path, recursive: true); }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        }

        public void Dispose()
        {
            _timer?.Dispose();
            _timer = null;
        }
    }
}
