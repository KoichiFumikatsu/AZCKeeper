using System;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.WebBlocking
{
    /// <summary>
    /// Servidor HTTP local minimalista para exponer el archivo PAC sin URL ACL
    /// ni privilegios de administrador. Escucha solo en loopback.
    /// </summary>
    internal sealed class LocalPacServer : IDisposable
    {
        private readonly TcpListener _listener;
        private readonly object _sync = new object();
        private CancellationTokenSource _cts;
        private Task _acceptLoopTask;
        private string _pacContent = "function FindProxyForURL(url, host) { return 'DIRECT'; }";

        public LocalPacServer(IPAddress ipAddress, int port)
        {
            _listener = new TcpListener(ipAddress, port);
        }

        public void UpdatePac(string pacContent)
        {
            lock (_sync)
            {
                _pacContent = string.IsNullOrWhiteSpace(pacContent)
                    ? "function FindProxyForURL(url, host) { return 'DIRECT'; }"
                    : pacContent;
            }
        }

        public void EnsureStarted()
        {
            if (_acceptLoopTask != null) return;

            _cts = new CancellationTokenSource();
            _listener.Start();
            _acceptLoopTask = Task.Run(() => AcceptLoopAsync(_cts.Token));
            LocalLogger.Info("LocalPacServer: listener iniciado.");
        }

        private async Task AcceptLoopAsync(CancellationToken ct)
        {
            while (!ct.IsCancellationRequested)
            {
                TcpClient client = null;

                try
                {
                    client = await _listener.AcceptTcpClientAsync().ConfigureAwait(false);
                    _ = Task.Run(() => HandleClientAsync(client, ct), ct);
                }
                catch (ObjectDisposedException) when (ct.IsCancellationRequested)
                {
                    break;
                }
                catch (SocketException) when (ct.IsCancellationRequested)
                {
                    break;
                }
                catch (Exception ex)
                {
                    client?.Dispose();
                    LocalLogger.Error(ex, "LocalPacServer: error aceptando cliente.");
                }
            }
        }

        private async Task HandleClientAsync(TcpClient client, CancellationToken ct)
        {
            using (client)
            using (var stream = client.GetStream())
            using (var reader = new StreamReader(stream, Encoding.ASCII, false, 4096, leaveOpen: true))
            {
                try
                {
                    string requestLine = await reader.ReadLineAsync().ConfigureAwait(false);
                    if (string.IsNullOrWhiteSpace(requestLine))
                        return;

                    string path = "/";
                    string[] parts = requestLine.Split(' ');
                    if (parts.Length >= 2) path = parts[1];

                    while (!ct.IsCancellationRequested)
                    {
                        string headerLine = await reader.ReadLineAsync().ConfigureAwait(false);
                        if (string.IsNullOrEmpty(headerLine))
                            break;
                    }

                    if (path.Equals("/proxy.pac", StringComparison.OrdinalIgnoreCase))
                    {
                        string content;
                        lock (_sync) content = _pacContent;

                        byte[] body = Encoding.UTF8.GetBytes(content);
                        string headers =
                            "HTTP/1.1 200 OK\r\n" +
                            "Content-Type: application/x-ns-proxy-autoconfig\r\n" +
                            $"Content-Length: {body.Length}\r\n" +
                            "Connection: close\r\n\r\n";

                        byte[] headerBytes = Encoding.ASCII.GetBytes(headers);
                        await stream.WriteAsync(headerBytes, 0, headerBytes.Length, ct).ConfigureAwait(false);
                        await stream.WriteAsync(body, 0, body.Length, ct).ConfigureAwait(false);
                    }
                    else
                    {
                        byte[] bytes = Encoding.ASCII.GetBytes("HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
                        await stream.WriteAsync(bytes, 0, bytes.Length, ct).ConfigureAwait(false);
                    }
                }
                catch
                {
                    // Si el navegador corta la conexión antes de leer el PAC, ignoramos.
                }
            }
        }

        public void Stop()
        {
            try
            {
                _cts?.Cancel();
                _listener.Stop();
            }
            catch { }
        }

        public void Dispose()
        {
            Stop();
            _cts?.Dispose();
        }
    }
}
