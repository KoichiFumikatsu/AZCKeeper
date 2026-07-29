using System;
using System.Drawing;
using System.Windows.Forms;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Tracking;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>
    /// Ventana de diagnóstico compacta: tracking de tiempo + versión/API/cola/errores/web-blocking/auth.
    /// Read-only; hace pull de un DebugSnapshot cada segundo.
    /// </summary>
    internal class DebugWindowForm : Form
    {
        private readonly ActivityTracker _activity;
        private readonly WindowTracker _window;
        private readonly Func<DebugSnapshot> _getSnapshot;
        private readonly System.Windows.Forms.Timer _timer;

        private readonly Label _diag = NewMono();
        private readonly Label _issues = NewMono();
        private readonly Label _tracking = NewMono();

        public DebugWindowForm(ActivityTracker activity, WindowTracker window, Func<DebugSnapshot> getSnapshot)
        {
            _activity = activity;
            _window = window;
            _getSnapshot = getSnapshot;

            Text = "AZCKeeper - Debug Activity";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(760, 560);
            FormBorderStyle = FormBorderStyle.Sizable;
            MaximizeBox = true;

            var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 2, Padding = new Padding(6) };
            root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 55));
            root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 45));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 60));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 40));

            root.Controls.Add(Titled("Estado", _diag), 0, 0);
            root.Controls.Add(Titled("Tracking de tiempo", _tracking), 1, 0);
            var issuesWrap = Titled("Últimos errores / warnings", _issues);
            root.Controls.Add(issuesWrap, 0, 1);
            root.SetColumnSpan(issuesWrap, 2);

            Controls.Add(root);

            _timer = new System.Windows.Forms.Timer { Interval = 1000 };
            _timer.Tick += (s, e) => Refresh_();
            _timer.Start();
            Refresh_();
        }

        private static Label NewMono() => new Label
        {
            AutoSize = false,
            Dock = DockStyle.Fill,
            Font = new Font("Consolas", 8.5F),
            Padding = new Padding(4),
        };

        private static Control Titled(string title, Control body)
        {
            var box = new GroupBox { Text = title, Dock = DockStyle.Fill, Padding = new Padding(4) };
            box.Controls.Add(body);
            return box;
        }

        private void Refresh_()
        {
            try
            {
                var s = _getSnapshot != null ? _getSnapshot() : null;
                _diag.Text = BuildDiag(s);
                _issues.Text = s?.RecentIssues != null && s.RecentIssues.Count > 0
                    ? string.Join(Environment.NewLine, s.RecentIssues)
                    : "(sin errores recientes)";
                _issues.ForeColor = (s?.RecentIssues?.Count ?? 0) > 0 ? Color.Firebrick : Color.Gray;
                _tracking.Text = BuildTracking();
            }
            catch (Exception ex)
            {
                LocalLogger.Warn($"DebugWindowForm.Refresh_(): {ex.Message}");
            }
        }

        private static string BuildDiag(DebugSnapshot s)
        {
            if (s == null) return "(sin datos)";
            string ok(bool b) => b ? "sí" : "NO";
            return string.Join(Environment.NewLine, new[]
            {
                "— Versión / update —",
                $"  corriendo:  {s.RunningVersion}",
                $"  disponible: {s.AvailableVersion}   mínima: {s.MinimumVersion}",
                $"  update:     {s.UpdateStatus}",
                "",
                "— API / conexión —",
                $"  api:        {s.ApiBaseUrl}",
                $"  handshake:  {s.LastHandshake}",
                $"  estado:     {s.HandshakeStatus}",
                $"  backoff:    {s.BackoffStatus}",
                "",
                "— Cola —",
                $"  pendientes: {s.QueuePending}",
                "",
                "— Web-blocking / Auth —",
                $"  bloqueo:    {ok(s.WebBlockEnabled)}   dominios: {s.WebBlockDomains}",
                $"  device:     {s.DeviceId}",
                $"  usuario:    {s.UserName}   token: {ok(s.HasToken)}",
            });
        }

        private string BuildTracking()
        {
            if (_activity == null) return "(tracker deshabilitado)";
            string win = _window != null
                ? $"[{_window.LastProcessName}] {_window.LastWindowTitle}"
                : "(WindowTracker off)";
            return string.Join(Environment.NewLine, new[]
            {
                $"inicio:   {(_activity.StartLocalTime == default ? "—" : _activity.StartLocalTime.ToString("HH:mm:ss"))}",
                $"ahora:    {DateTime.Now:HH:mm:ss}",
                $"sesión:   act {FormatSeconds(_activity.SessionActiveSeconds)} / inact {FormatSeconds(_activity.SessionInactiveSeconds)}",
                $"día:      act {FormatSeconds(_activity.CurrentDayActiveSeconds)} / inact {FormatSeconds(_activity.CurrentDayInactiveSeconds)}",
                $"ventana:  {win}",
            });
        }

        private static string FormatSeconds(double seconds)
        {
            var ts = TimeSpan.FromSeconds(seconds < 0 ? 0 : seconds);
            return $"{(int)ts.TotalHours:00}:{ts.Minutes:00}:{ts.Seconds:00}";
        }

        protected override void OnFormClosed(FormClosedEventArgs e)
        {
            try { _timer?.Stop(); _timer?.Dispose(); } catch { }
            base.OnFormClosed(e);
        }
    }
}
