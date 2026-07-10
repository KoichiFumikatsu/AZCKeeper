using System;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Servidor loopback mínimo que SOLO sirve el texto del PAC en 127.0.0.1:&lt;puerto&gt;.
    /// No es un proxy: nunca reenvía tráfico. El puerto se persiste para mantener estable
    /// el AutoConfigURL entre reinicios (evita el "PAC muerto colgado" por puerto efímero).
    /// </summary>
    internal sealed class LocalPacServer
    {
        private readonly string _portFilePath;
        private readonly object _lock = new object();

        private TcpListener _listener;
        private CancellationTokenSource _cts;
        private volatile string _pac = string.Empty;

        public bool IsRunning { get; private set; }
        public int Port { get; private set; }

        public LocalPacServer(string cacheDirectory)
        {
            Directory.CreateDirectory(cacheDirectory);
            _portFilePath = Path.Combine(cacheDirectory, "pac_port.txt");
        }

        public int StartOrUpdate(string pacContent)
        {
            lock (_lock)
            {
                _pac = pacContent ?? string.Empty;
                if (IsRunning) return Port;

                Port = BindStablePort();
                _cts = new CancellationTokenSource();
                var token = _cts.Token;
                Task.Run(() => AcceptLoop(token));
                IsRunning = true;
                LocalLogger.Info($"LocalPacServer: sirviendo PAC en 127.0.0.1:{Port}/proxy.pac");
                return Port;
            }
        }

        public void UpdatePac(string pacContent)
        {
            _pac = pacContent ?? string.Empty;
        }

        public void Stop()
        {
            lock (_lock)
            {
                try { _cts?.Cancel(); } catch { }
                try { _listener?.Stop(); } catch { }
                _listener = null;
                _cts = null;
                IsRunning = false;
            }
        }

        private int BindStablePort()
        {
            int preferred = ReadPersistedPort();
            if (preferred > 0 && TryBind(preferred)) { return preferred; }

            // efímero: bind a puerto 0 y persistir el asignado
            _listener = new TcpListener(IPAddress.Loopback, 0);
            _listener.Start();
            int assigned = ((IPEndPoint)_listener.LocalEndpoint).Port;
            WritePersistedPort(assigned);
            return assigned;
        }

        private bool TryBind(int port)
        {
            try
            {
                var l = new TcpListener(IPAddress.Loopback, port);
                l.Start();
                _listener = l;
                return true;
            }
            catch { return false; }
        }

        private int ReadPersistedPort()
        {
            try { return File.Exists(_portFilePath) && int.TryParse(File.ReadAllText(_portFilePath).Trim(), out int p) ? p : 0; }
            catch { return 0; }
        }

        private void WritePersistedPort(int port)
        {
            try { File.WriteAllText(_portFilePath, port.ToString()); } catch { }
        }

        private async Task AcceptLoop(CancellationToken token)
        {
            while (!token.IsCancellationRequested)
            {
                TcpClient client;
                try { client = await _listener.AcceptTcpClientAsync().ConfigureAwait(false); }
                catch { break; }

                _ = Task.Run(() => Serve(client));
            }
        }

        private void Serve(TcpClient client)
        {
            try
            {
                using (client)
                using (var stream = client.GetStream())
                {
                    // Leer (y descartar) el request; solo importa devolver el PAC.
                    // Nota: no dependemos de client.Available > 0 (evita una carrera con el
                    // envío del request del cliente) — el read es best-effort y defensivo;
                    // el PAC se sirve siempre, haya llegado o no el request todavía.
                    var buf = new byte[2048];
                    try { stream.ReadTimeout = 2000; stream.Read(buf, 0, buf.Length); } catch { }

                    byte[] body = Encoding.ASCII.GetBytes(_pac);
                    string header = "HTTP/1.1 200 OK\r\n" +
                                    "Content-Type: application/x-ns-proxy-autoconfig\r\n" +
                                    "Content-Length: " + body.Length + "\r\n" +
                                    "Connection: close\r\n\r\n";
                    byte[] hb = Encoding.ASCII.GetBytes(header);
                    stream.Write(hb, 0, hb.Length);
                    stream.Write(body, 0, body.Length);
                    stream.Flush();
                }
            }
            catch { }
        }
    }
}
