using System;
using System.Globalization;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using ModularApp.Core;
using Newtonsoft.Json.Linq;

namespace ModularApp.Modules.Geo
{
    internal sealed class GeoWebViewProbe : Form
    {
        private readonly int _timeoutMs;
        private readonly ILogger _log;
        private readonly TaskCompletionSource<GeoPoint?> _tcs;
        private WebView2 _wv;
        private System.Windows.Forms.Timer _timer;

        private GeoWebViewProbe(int timeoutMs, ILogger log, TaskCompletionSource<GeoPoint?> tcs)
        {
            _timeoutMs = timeoutMs;
            _log = log;
            _tcs = tcs;

            // Ventana invisible
            this.FormBorderStyle = FormBorderStyle.None;
            this.ShowInTaskbar = false;
            this.Opacity = 0;
            this.StartPosition = FormStartPosition.Manual;
            this.Bounds = new System.Drawing.Rectangle(-2000, -2000, 10, 10);

            this.Shown += async (s, e) =>
            {
                try
                {
                    string userData = System.IO.Path.Combine(
                        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                        "AZCKeeper", "WebView2");

                    var env = await CoreWebView2Environment.CreateAsync(userDataFolder: userData);
                    _wv = new WebView2 { Dock = DockStyle.Fill };
                    this.Controls.Add(_wv);
                    await _wv.EnsureCoreWebView2Async(env);

                    _wv.CoreWebView2.WebMessageReceived += (s2, e2) =>
                    {
                        try
                        {
                            var msg = e2.TryGetWebMessageAsString();
                            var jo = JObject.Parse(msg);
                            bool ok = jo["ok"] != null && jo["ok"].Value<bool>();
                            if (ok)
                            {
                                double lat = jo["lat"].Value<double>();
                                double lon = jo["lon"].Value<double>();
                                _tcs.TrySetResult(new GeoPoint { Latitude = lat, Longitude = lon });
                            }
                            else
                            {
                                _log?.Warn("[Geo] WebView2 geolocation error: " + (jo["error"] ?? "unknown").ToString());
                                _tcs.TrySetResult(null);
                            }
                        }
                        catch (Exception ex)
                        {
                            _log?.Warn("[Geo] WebMessage parse error: " + ex.Message);
                            _tcs.TrySetResult(null);
                        }
                        finally
                        {
                            try { this.BeginInvoke(new Action(this.Close)); } catch { }
                        }
                    };

                    string html = @"<!doctype html><html><body>
<script>
function send(o){ try{ chrome.webview.postMessage(JSON.stringify(o)); }catch(e){} }
try{
  navigator.geolocation.getCurrentPosition(
    function(p){ send({ok:true, lat:p.coords.latitude, lon:p.coords.longitude, acc:p.coords.accuracy}); },
    function(err){ send({ok:false, error: err.code + ':' + err.message}); },
    {enableHighAccuracy:false, timeout:" + _timeoutMs + @", maximumAge:0}
  );
}catch(e){ send({ok:false, error:''+e}); }
</script></body></html>";
                    _wv.NavigateToString(html);

                    _timer = new System.Windows.Forms.Timer();
                    _timer.Interval = _timeoutMs + 1000;
                    _timer.Tick += (s3, e3) =>
                    {
                        _timer.Stop();
                        _tcs.TrySetResult(null);
                        try { this.Close(); } catch { }
                    };
                    _timer.Start();
                }
                catch (Exception ex)
                {
                    _log?.Warn("[Geo] WebView2 init failed: " + ex.Message);
                    _tcs.TrySetResult(null);
                    try { this.Close(); } catch { }
                }
            };

            this.FormClosed += (s, e) =>
            {
                try { if (_timer != null) { _timer.Stop(); _timer.Dispose(); } } catch { }
                try { if (_wv != null) _wv.Dispose(); } catch { }
            };
        }

        public static Task<GeoPoint?> ProbeAsync(int timeoutMs, ILogger log)
        {
            var tcs = new TaskCompletionSource<GeoPoint?>();
            var th = new Thread(() =>
            {
                try
                {
                    Application.EnableVisualStyles();
                    using (var f = new GeoWebViewProbe(timeoutMs, log, tcs))
                        Application.Run(f);
                }
                catch (Exception ex)
                {
                    log?.Warn("[Geo] Probe thread failed: " + ex.Message);
                    tcs.TrySetResult(null);
                }
            });
            th.SetApartmentState(ApartmentState.STA);
            th.IsBackground = true;
            th.Start();
            return tcs.Task;
        }
    }
}
