using System;
using System.Globalization;
using System.IO;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;               // NuGet
using Microsoft.Web.WebView2.WinForms;           // NuGet
using ModularApp.Core;
using ModularApp.Modules.Database;
using ModularApp.Modules.Login;
using Newtonsoft.Json.Linq;

namespace ModularApp.Modules.Geo
{
    public struct GeoPoint { public double Latitude, Longitude; }

    public interface IGeoService
    {
        bool IsSupported { get; }
        bool IsPermissionDenied { get; }
        Task<GeoPoint?> TryGetCoordinatesAsync(int timeoutMs);
        Task<string> TryReverseGeocodeAsync(GeoPoint point, int timeoutMs, string lang);
    }

    public sealed class GeoModule : IAppModule, IGeoService
    {
        private AppCore _core;
        private IDatabaseService _db;
        private ILoginService _login;

        public string Name => "Geo";
        public bool Enabled => true;

        public bool IsSupported { get; private set; } = true;
        public bool IsPermissionDenied { get; private set; }

        public void Init(AppCore core) { _core = core; }

        public void Start()
        {
            _core?.Logger.Info("[Geo] Ready (WebView2 Geolocation)");
            _db = _core.Resolve<IDatabaseService>();
            _login = _core.Resolve<ILoginService>();

            if (_login == null || _db == null)
            {
                _core?.Logger.Warn("[Geo] Falta Login o Database; se esperará al evento.");
                return;
            }

            _login.LoggedIn += OnLoggedIn;

            if (_login.IsLogged)
            {
                _core?.Logger.Info("[Geo] Login ya activo al iniciar Geo. Kick diferido.");
                KickDeferred(150);
            }
        }

        public void Stop() { }
        public void Dispose() { if (_login != null) _login.LoggedIn -= OnLoggedIn; }

        private void OnLoggedIn()
        {
            _core?.Logger.Info("[Geo] LoggedIn recibido. Kick diferido.");
            KickDeferred(150);
        }

        private void KickDeferred(int ms)
        {
            var t = new System.Windows.Forms.Timer();
            t.Interval = ms;
            t.Tick += async (s, e) =>
            {
                t.Stop(); t.Dispose();
                // IMPORTANTe: sin ConfigureAwait(false) para permanecer en UI
                await KickAsync();
            };
            t.Start();
        }

        private async Task KickAsync()
        {
            try
            {
                if (_db == null || _login == null) return;

                _core?.Logger.Info("[Geo] Iniciando captura (WebView2)...");
                // IMPORTANTE: sin ConfigureAwait(false)
                var pt = await TryGetCoordinatesAsync(15000);

                string label;
                if (!pt.HasValue)
                {
                    label = Environment.MachineName; // fallback visible
                    _core?.Logger.Warn("[Geo] No se obtuvieron coordenadas. Fallback: " + label);
                }
                else
                {
                    _core?.Logger.Info("[Geo] Coordenadas: " +
                        pt.Value.Latitude.ToString("F6", CultureInfo.InvariantCulture) + "," +
                        pt.Value.Longitude.ToString("F6", CultureInfo.InvariantCulture));

                    // sin ConfigureAwait(false)
                    label = await TryReverseGeocodeAsync(pt.Value, 8000, "es");
                    if (string.IsNullOrWhiteSpace(label))
                        label = pt.Value.Latitude.ToString("F6", CultureInfo.InvariantCulture) + "," +
                                pt.Value.Longitude.ToString("F6", CultureInfo.InvariantCulture);
                }

                //_db.UpdateGeo(_login.EmployeeId, label);
                _db.UpdateGeoLatLon(_login.EmployeeId, pt.Value.Latitude, pt.Value.Longitude);
                _core?.Logger.Info("[Geo] Geo guardada en BD: " + label);
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[Geo] KickAsync falló: " + ex.Message);
            }
        }

        // --------------- IGeoService ---------------

        public async Task<GeoPoint?> TryGetCoordinatesAsync(int timeoutMs)
        {
            // Verifica runtime WebView2
            try
            {
                string ver = CoreWebView2Environment.GetAvailableBrowserVersionString();
                _core?.Logger.Info("[Geo/WebView2] Runtime version: " + (string.IsNullOrEmpty(ver) ? "(none)" : ver));
                if (string.IsNullOrEmpty(ver))
                {
                    _core?.Logger.Warn("[Geo/WebView2] Runtime no disponible en el equipo.");
                    return null;
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[Geo/WebView2] No se pudo verificar runtime: " + ex.Message);
                return null;
            }

            // Asegurar loop de mensajes
            if (!Application.MessageLoop)
            {
                _core?.Logger.Warn("[Geo/WebView2] Sin message loop aún; reintentando diferido.");
                var tcs = new TaskCompletionSource<GeoPoint?>();
                var t = new System.Windows.Forms.Timer { Interval = 100 };
                t.Tick += async (s, e) =>
                {
                    if (!Application.MessageLoop) return;
                    t.Stop(); t.Dispose();
                    try { tcs.SetResult(await TryGetCoordinatesAsync(timeoutMs)); }
                    catch (Exception ex) { _core?.Logger.Warn("[Geo/WebView2] Diferido falló: " + ex.Message); tcs.TrySetResult((GeoPoint?)null); }
                };
                t.Start();
                return await tcs.Task;
            }

            try
            {
                using (var form = new HiddenGeoForm(_core))
                using (var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(timeoutMs)))
                {
                    // sin ConfigureAwait(false)
                    var p = await form.GetCoordinatesAsync(cts.Token);
                    return p;
                }
            }
            catch (OperationCanceledException)
            {
                _core?.Logger.Warn("[Geo/WebView2] Timeout esperando geolocalización.");
                return null;
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[Geo/WebView2] TryGetCoordinatesAsync error: " + ex.Message);
                return null;
            }
        }

        public async Task<string> TryReverseGeocodeAsync(GeoPoint p, int timeoutMs, string lang)
        {
            try
            {
                using (var http = new HttpClient())
                {
                    http.Timeout = TimeSpan.FromMilliseconds(timeoutMs);
                    http.DefaultRequestHeaders.UserAgent.ParseAdd("AZCKeeper/1.0 (contacto: koichi.fumikatsu@azc.com.co)");

                    string lat = p.Latitude.ToString(CultureInfo.InvariantCulture);
                    string lon = p.Longitude.ToString(CultureInfo.InvariantCulture);
                    string url = "https://nominatim.openstreetmap.org/reverse" +
                                 "?format=jsonv2&lat=" + lat +
                                 "&lon=" + lon +
                                 "&accept-language=" + lang +
                                 "&email=koichi.fumikatsu@azc.com.co";

                    string json = await http.GetStringAsync(url);
                    var root = JObject.Parse(json);

                    var addr = root["address"] as JObject;
                    if (addr != null)
                    {
                        string city = (string)addr["city"] ?? (string)addr["town"] ?? (string)addr["village"] ?? (string)addr["suburb"];
                        string state = (string)addr["state"];
                        string country = (string)addr["country"];

                        var parts = new System.Collections.Generic.List<string>();
                        if (!string.IsNullOrWhiteSpace(city)) parts.Add(city);
                        if (!string.IsNullOrWhiteSpace(state)) parts.Add(state);
                        if (!string.IsNullOrWhiteSpace(country)) parts.Add(country);

                        if (parts.Count > 0) return string.Join(", ", parts.ToArray());
                    }

                    string display = (string)root["display_name"];
                    if (!string.IsNullOrWhiteSpace(display)) return display;
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[Geo] Reverse geocode falló: " + ex.Message);
            }

            return p.Latitude.ToString("F6", CultureInfo.InvariantCulture) + "," +
                   p.Longitude.ToString("F6", CultureInfo.InvariantCulture);
        }

        // --------------- Form oculto con WebView2 ---------------

        private sealed class HiddenGeoForm : Form
        {
            private readonly AppCore _core;
            private readonly WebView2 _wv;
            private TaskCompletionSource<GeoPoint?> _tcs;
            private string _tempDir;
            private bool _initialized;

            public HiddenGeoForm(AppCore core)
            {
                _core = core;

                this.ShowInTaskbar = false;
                this.FormBorderStyle = FormBorderStyle.None;
                this.StartPosition = FormStartPosition.Manual;
                this.Location = new System.Drawing.Point(-2000, -2000);
                this.Size = new System.Drawing.Size(10, 10);

                _wv = new WebView2 { Dock = DockStyle.Fill };
                this.Controls.Add(_wv);
            }

            public Task<GeoPoint?> GetCoordinatesAsync(CancellationToken ct)
            {
                if (_tcs != null) throw new InvalidOperationException("Captura ya en curso.");
                _tcs = new TaskCompletionSource<GeoPoint?>();

                // Cancelación
                ct.Register(() =>
                {
                    try { if (!_tcs.Task.IsCompleted) _tcs.TrySetCanceled(); } catch { }
                    SafeClose();
                });

                // Crear handle y disparar Load en UI
                this.Load += async (s, e) =>
                {
                    try
                    {
                        _core?.Logger.Info("[Geo/WebView2] Form Load → init env");
                        await EnsureInitAsync();              // sin ConfigureAwait(false)
                        _core?.Logger.Info("[Geo/WebView2] Init OK → navegar");
                        await NavigateAndCaptureAsync();      // sin ConfigureAwait(false)
                    }
                    catch (Exception ex)
                    {
                        _core?.Logger.Warn("[Geo/WebView2] Init error: " + ex.Message);
                        _tcs.TrySetResult(null);
                        SafeClose();
                    }
                };

                try
                {
                    this.Show();   // crea el handle y dispara Load
                }
                catch (Exception ex)
                {
                    _core?.Logger.Warn("[Geo/WebView2] Show() falló: " + ex.Message);
                    _tcs.TrySetResult(null);
                }

                return _tcs.Task;
            }

            private async Task EnsureInitAsync()
            {
                if (_initialized) return;

                _tempDir = Path.Combine(Path.GetTempPath(), "AZCKeeper_WebView2_" + Guid.NewGuid().ToString("N"));
                Directory.CreateDirectory(_tempDir);

                var env = await CoreWebView2Environment.CreateAsync(null, _tempDir);
                await _wv.EnsureCoreWebView2Async(env);

                _wv.CoreWebView2.PermissionRequested += (s, e) =>
                {
                    if (e.PermissionKind == CoreWebView2PermissionKind.Geolocation)
                    {
                        _core?.Logger.Info("[Geo/WebView2] PermissionRequested: Geolocation → Allow");
                        e.State = CoreWebView2PermissionState.Allow;
                        e.Handled = true;
                    }
                };

                // Host virtual https
                string vhost = "appassets.azc";
                string folder = Path.Combine(_tempDir, "site");
                Directory.CreateDirectory(folder);
                File.WriteAllText(Path.Combine(folder, "index.html"), HtmlPage());

                _wv.CoreWebView2.SetVirtualHostNameToFolderMapping(
                    vhost, folder, CoreWebView2HostResourceAccessKind.Allow);

                _initialized = true;
            }

            private async Task NavigateAndCaptureAsync()
            {
                _wv.CoreWebView2.WebMessageReceived += (s, e) =>
                {
                    try
                    {
                        // 1) Intenta como objeto (lo normal al enviar objeto desde JS)
                        string json = e.WebMessageAsJson ?? "{}";
                        JObject jo = null;

                        try
                        {
                            jo = JObject.Parse(json);
                        }
                        catch
                        {
                            // 2) Si venía como string JSON (doble-serialización), reintenta
                            var str = e.TryGetWebMessageAsString();
                            if (!string.IsNullOrEmpty(str) && str.TrimStart().StartsWith("{"))
                            {
                                try { jo = JObject.Parse(str); }
                                catch { /* cae abajo */ }
                            }

                            // 3) Si sigue sin parsear, log y salir
                            if (jo == null)
                            {
                                _core?.Logger.Warn("[Geo/WebView2] WebMessage no parseable: " + (str ?? json));
                                _tcs.TrySetResult(null);
                                this.SafeClose();
                                return;
                            }
                        }

                        if (jo["error"] != null)
                        {
                            _core?.Logger.Warn("[Geo/WebView2] JS error: " + jo["error"].ToString());
                            _tcs.TrySetResult(null);
                            this.SafeClose();
                            return;
                        }

                        if (jo["lat"] != null && jo["lon"] != null)
                        {
                            double lat = Convert.ToDouble(jo["lat"].ToString(), CultureInfo.InvariantCulture);
                            double lon = Convert.ToDouble(jo["lon"].ToString(), CultureInfo.InvariantCulture);
                            _core?.Logger.Info("[Geo/WebView2] JS coords → " +
                                lat.ToString("F6", CultureInfo.InvariantCulture) + "," +
                                lon.ToString("F6", CultureInfo.InvariantCulture));
                            _tcs.TrySetResult(new GeoPoint { Latitude = lat, Longitude = lon });
                            this.SafeClose();
                            return;
                        }

                        _core?.Logger.Warn("[Geo/WebView2] Mensaje JS desconocido: " + json);
                        _tcs.TrySetResult(null);
                        this.SafeClose();
                    }
                    catch (Exception ex)
                    {
                        _core?.Logger.Warn("[Geo/WebView2] WebMessage parse error: " + ex.Message);
                        _tcs.TrySetResult(null);
                        this.SafeClose();
                    }
                };


                var uri = new Uri("https://appassets.azc/index.html");
                _core?.Logger.Info("[Geo/WebView2] Navigate → " + uri);
                _wv.Source = uri;

                // watchdog de 12s
                _ = Task.Run(async () =>
                {
                    await Task.Delay(12000);
                    if (!_tcs.Task.IsCompleted)
                    {
                        _core?.Logger.Warn("[Geo/WebView2] JS no respondió a tiempo (watchdog).");
                        _tcs.TrySetResult(null);
                        SafeClose();
                    }
                });
            }

            private static string HtmlPage()
            {
                return @"<!DOCTYPE html>
                    <html>
                    <head>
                    <meta charset='utf-8'>
                    <meta http-equiv='Content-Security-Policy' content=""default-src 'self' 'unsafe-inline'"">
                    <title>geo</title>
                    </head>
                    <body>
                    <script>
                    (function(){
                      function ok(pos){
                        var lat = pos.coords.latitude;
                        var lon = pos.coords.longitude;
                        // ENVÍA OBJETO, NO STRING:
                        try { window.chrome.webview.postMessage({lat:lat, lon:lon}); } catch(e){}
                      }
                      function err(e){
                        var msg = (e && e.message) ? e.message : (e && e.code ? ('code '+e.code) : 'denied');
                        // ENVÍA OBJETO, NO STRING:
                        try { window.chrome.webview.postMessage({error: msg}); } catch(_){}
                      }
                      try {
                        navigator.geolocation.getCurrentPosition(ok, err, {enableHighAccuracy:false, timeout:8000, maximumAge:60000});
                      } catch(e) { err(e); }
                    })();
                    </script>
                    </body>
                    </html>";
            }


            protected override void Dispose(bool disposing)
            {
                try { if (disposing) _wv?.Dispose(); }
                catch { }
                finally
                {
                    try
                    {
                        if (!string.IsNullOrEmpty(_tempDir) && Directory.Exists(_tempDir))
                            Directory.Delete(_tempDir, true);
                    }
                    catch { }
                }
                base.Dispose(disposing);
            }

            private void SafeClose()
            {
                try
                {
                    if (this.IsDisposed) return;

                    if (this.IsHandleCreated)
                    {
                        this.BeginInvoke(new Action(() =>
                        {
                            try { this.Close(); } catch { }
                        }));
                    }
                    else
                    {
                        try { this.Close(); } catch { }
                    }
                }
                catch { }
            }
        }
    }
}
