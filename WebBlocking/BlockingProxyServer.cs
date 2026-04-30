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
    /// Proxy local minimalista de bloqueo.
    /// Rechaza peticiones HTTP y CONNECT para que los hosts enviados por PAC
    /// fallen de forma controlada sin requerir privilegios elevados.
    /// </summary>
    internal sealed class BlockingProxyServer : IDisposable
    {
        private readonly TcpListener _listener;
        private CancellationTokenSource _cts;
        private Task _acceptLoopTask;

        public BlockingProxyServer(IPAddress ipAddress, int port)
        {
            _listener = new TcpListener(ipAddress, port);
        }

        public void EnsureStarted()
        {
            if (_acceptLoopTask != null) return;

            _cts = new CancellationTokenSource();
            _listener.Start();
            _acceptLoopTask = Task.Run(() => AcceptLoopAsync(_cts.Token));
            LocalLogger.Info("BlockingProxyServer: listener iniciado.");
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
                    LocalLogger.Error(ex, "BlockingProxyServer: error aceptando cliente.");
                }
            }
        }

        private static async Task HandleClientAsync(TcpClient client, CancellationToken ct)
        {
            using (client)
            using (var stream = client.GetStream())
            using (var reader = new StreamReader(stream, Encoding.ASCII, false, 4096, leaveOpen: true))
            using (var writer = new StreamWriter(stream, new UTF8Encoding(false), 4096, leaveOpen: true) { NewLine = "\r\n", AutoFlush = true })
            {
                try
                {
                    string requestLine = await reader.ReadLineAsync().ConfigureAwait(false);
                    if (string.IsNullOrWhiteSpace(requestLine))
                        return;

                    while (!ct.IsCancellationRequested)
                    {
                        string headerLine = await reader.ReadLineAsync().ConfigureAwait(false);
                        if (string.IsNullOrEmpty(headerLine))
                            break;
                    }

                    string response = requestLine.StartsWith("CONNECT ", StringComparison.OrdinalIgnoreCase)
                        ? "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n"
                        : "HTTP/1.1 403 Forbidden\r\nContent-Type: text/plain; charset=utf-8\r\nConnection: close\r\nContent-Length: 17\r\n\r\nBlocked by policy";

                    byte[] bytes = Encoding.ASCII.GetBytes(response);
                    await stream.WriteAsync(bytes, 0, bytes.Length, ct).ConfigureAwait(false);
                }
                catch
                {
                    // Ignorar conexiones truncadas: el objetivo es fallar rápido y silencioso.
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
