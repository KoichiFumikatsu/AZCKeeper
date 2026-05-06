using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Proxy HTTP/HTTPS local. Bloquea dominios por Host/CONNECT y deja pasar el resto.
    /// No hace inspección TLS; para HTTPS decide usando el host del CONNECT.
    /// </summary>
    internal sealed class LocalWebBlockProxy
    {
        private const int ConnectTimeoutSeconds = 10;
        private const int IdleTimeoutMilliseconds = 30000;
        private const int MaxHeaderBytes = 65536;
        private const int RelayBufferSize = 81920;

        private readonly object _stateLock = new object();

        private TcpListener _listener;
        private CancellationTokenSource _cts;
        private Task _acceptLoopTask;
        private int _port;
        private DomainRuleMatcher _matcher = new DomainRuleMatcher(Array.Empty<string>());

        public bool IsRunning { get; private set; }
        public int Port => _port;

        public int StartOrUpdate(int preferredPort, string[] domains)
        {
            lock (_stateLock)
            {
                _matcher = new DomainRuleMatcher(domains ?? Array.Empty<string>());

                if (IsRunning)
                {
                    LocalLogger.Info($"LocalWebBlockProxy: reglas actualizadas. Domains={_matcher.RuleCount}, Port={_port}");
                    return _port;
                }

                StopInternal();

                _port = BindToAvailablePort(preferredPort);
                _cts = new CancellationTokenSource();
                _acceptLoopTask = Task.Run(() => AcceptLoopAsync(_cts.Token));
                IsRunning = true;

                LocalLogger.Info($"LocalWebBlockProxy: iniciado en 127.0.0.1:{_port}. Domains={_matcher.RuleCount}");
                return _port;
            }
        }

        public void Stop()
        {
            lock (_stateLock)
            {
                StopInternal();
            }
        }

        private void StopInternal()
        {
            try
            {
                _cts?.Cancel();
            }
            catch { }

            try
            {
                _listener?.Stop();
            }
            catch { }

            _listener = null;
            _cts = null;
            _acceptLoopTask = null;
            IsRunning = false;
        }

        private int BindToAvailablePort(int preferredPort)
        {
            int[] candidates = new[] { preferredPort, preferredPort + 1, preferredPort + 2, preferredPort + 3, preferredPort + 4 };
            foreach (int port in candidates)
            {
                try
                {
                    _listener = new TcpListener(System.Net.IPAddress.Loopback, port);
                    _listener.Start();
                    return port;
                }
                catch (SocketException)
                {
                    try { _listener?.Stop(); } catch { }
                    _listener = null;
                }
            }

            throw new InvalidOperationException($"No fue posible abrir puerto para proxy local. Preferido={preferredPort}");
        }

        private async Task AcceptLoopAsync(CancellationToken ct)
        {
            try
            {
                while (!ct.IsCancellationRequested && _listener != null)
                {
                    TcpClient client = null;
                    try
                    {
                        client = await _listener.AcceptTcpClientAsync().ConfigureAwait(false);
                        ConfigureSocket(client);
                        _ = Task.Run(() => HandleClientAsync(client, ct), ct);
                    }
                    catch (ObjectDisposedException) when (ct.IsCancellationRequested)
                    {
                        break;
                    }
                    catch (Exception ex)
                    {
                        client?.Dispose();
                        if (!ct.IsCancellationRequested)
                            LocalLogger.Error(ex, "LocalWebBlockProxy.AcceptLoopAsync(): error aceptando cliente.");
                    }
                }
            }
            catch (Exception ex)
            {
                if (!ct.IsCancellationRequested)
                    LocalLogger.Error(ex, "LocalWebBlockProxy.AcceptLoopAsync(): error general.");
            }
        }

        private async Task HandleClientAsync(TcpClient client, CancellationToken ct)
        {
            using (client)
            {
                try
                {
                    using NetworkStream clientStream = client.GetStream();
                    var headerRead = await ReadRequestHeaderAsync(clientStream, ct).ConfigureAwait(false);
                    if (headerRead == null)
                        return;

                    string headerText = Encoding.ASCII.GetString(headerRead.HeaderBytes);
                    var request = ProxyRequest.TryParse(headerText);
                    if (request == null)
                        return;

                    if (request.IsConnect)
                    {
                        await HandleConnectAsync(client, clientStream, request, ct).ConfigureAwait(false);
                        return;
                    }

                    await HandleHttpAsync(client, clientStream, request, headerRead, ct).ConfigureAwait(false);
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, "LocalWebBlockProxy.HandleClientAsync(): error procesando request.");
                }
            }
        }

        private async Task HandleConnectAsync(TcpClient client, NetworkStream clientStream, ProxyRequest request, CancellationToken ct)
        {
            if (_matcher.IsBlocked(request.Host))
            {
                await WriteBlockedResponseAsync(clientStream, request.Host).ConfigureAwait(false);
                return;
            }

            using var remote = new TcpClient();
            ConfigureSocket(remote);
            bool isConnected = await TryConnectAsync(remote, request.Host, request.Port, ct).ConfigureAwait(false);
            if (!isConnected)
            {
                await WriteGatewayErrorAsync(clientStream, request.Host).ConfigureAwait(false);
                return;
            }
            using NetworkStream remoteStream = remote.GetStream();

            byte[] responseBytes = Encoding.ASCII.GetBytes("HTTP/1.1 200 Connection Established\r\n\r\n");
            await clientStream.WriteAsync(responseBytes, 0, responseBytes.Length, ct).ConfigureAwait(false);

            await RelayBidirectionalAsync(client, clientStream, remote, remoteStream, ct).ConfigureAwait(false);
        }

        private async Task HandleHttpAsync(TcpClient client, NetworkStream clientStream, ProxyRequest request, HeaderReadResult headerRead, CancellationToken ct)
        {
            string host = request.Host;
            if (string.IsNullOrWhiteSpace(host))
                return;

            if (_matcher.IsBlocked(host))
            {
                await WriteBlockedResponseAsync(clientStream, host).ConfigureAwait(false);
                return;
            }

            using var remote = new TcpClient();
            ConfigureSocket(remote);
            bool isConnected = await TryConnectAsync(remote, host, request.Port, ct).ConfigureAwait(false);
            if (!isConnected)
            {
                await WriteGatewayErrorAsync(clientStream, host).ConfigureAwait(false);
                return;
            }
            using NetworkStream remoteStream = remote.GetStream();

            byte[] outboundHeader = Encoding.ASCII.GetBytes(request.BuildForwardHeader());
            await remoteStream.WriteAsync(outboundHeader, 0, outboundHeader.Length, ct).ConfigureAwait(false);

            if (headerRead.RemainingBytes.Length > 0)
            {
                await remoteStream.WriteAsync(headerRead.RemainingBytes, 0, headerRead.RemainingBytes.Length, ct).ConfigureAwait(false);
            }

            await RelayBidirectionalAsync(client, clientStream, remote, remoteStream, ct).ConfigureAwait(false);
        }

        private static async Task WriteBlockedResponseAsync(NetworkStream stream, string host)
        {
            string body = $"Blocked by AZCKeeper: {host}";
            string response =
                "HTTP/1.1 403 Forbidden\r\n" +
                "Content-Type: text/plain; charset=utf-8\r\n" +
                $"Content-Length: {Encoding.UTF8.GetByteCount(body)}\r\n" +
                "Connection: close\r\n\r\n" +
                body;

            byte[] bytes = Encoding.UTF8.GetBytes(response);
            await stream.WriteAsync(bytes, 0, bytes.Length).ConfigureAwait(false);
        }

        private static async Task WriteGatewayErrorAsync(NetworkStream stream, string host)
        {
            string body = $"Gateway error while connecting to: {host}";
            string response =
                "HTTP/1.1 502 Bad Gateway\r\n" +
                "Content-Type: text/plain; charset=utf-8\r\n" +
                $"Content-Length: {Encoding.UTF8.GetByteCount(body)}\r\n" +
                "Connection: close\r\n\r\n" +
                body;

            byte[] bytes = Encoding.UTF8.GetBytes(response);
            await stream.WriteAsync(bytes, 0, bytes.Length).ConfigureAwait(false);
        }

        private static async Task<HeaderReadResult> ReadRequestHeaderAsync(NetworkStream stream, CancellationToken ct)
        {
            byte[] buffer = new byte[8192];
            using var ms = new MemoryStream();

            while (ms.Length < MaxHeaderBytes)
            {
                int read = await stream.ReadAsync(buffer, 0, buffer.Length, ct).ConfigureAwait(false);
                if (read <= 0)
                    return null;

                ms.Write(buffer, 0, read);
                byte[] data = ms.ToArray();
                int headerEnd = FindHeaderEnd(data);
                if (headerEnd >= 0)
                {
                    int headerLength = headerEnd + 4;
                    byte[] headerBytes = new byte[headerLength];
                    Buffer.BlockCopy(data, 0, headerBytes, 0, headerLength);

                    int remainingLength = data.Length - headerLength;
                    byte[] remainingBytes = new byte[Math.Max(0, remainingLength)];
                    if (remainingLength > 0)
                        Buffer.BlockCopy(data, headerLength, remainingBytes, 0, remainingLength);

                    return new HeaderReadResult
                    {
                        HeaderBytes = headerBytes,
                        RemainingBytes = remainingBytes
                    };
                }
            }

            return null;
        }

        private static void ConfigureSocket(TcpClient client)
        {
            client.NoDelay = true;
            client.ReceiveTimeout = IdleTimeoutMilliseconds;
            client.SendTimeout = IdleTimeoutMilliseconds;
        }

        private static async Task<bool> TryConnectAsync(TcpClient client, string host, int port, CancellationToken ct)
        {
            try
            {
                using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(ct);
                timeoutCts.CancelAfter(TimeSpan.FromSeconds(ConnectTimeoutSeconds));
                await client.ConnectAsync(host, port, timeoutCts.Token).ConfigureAwait(false);
                return true;
            }
            catch (OperationCanceledException)
            {
                return false;
            }
            catch (SocketException)
            {
                return false;
            }
        }

        private static async Task RelayBidirectionalAsync(
            TcpClient client,
            NetworkStream clientStream,
            TcpClient remote,
            NetworkStream remoteStream,
            CancellationToken ct)
        {
            Task upstream = PumpAsync(clientStream, remoteStream, ct);
            Task downstream = PumpAsync(remoteStream, clientStream, ct);

            Task completed = await Task.WhenAny(upstream, downstream).ConfigureAwait(false);

            try
            {
                remote.Client?.Shutdown(SocketShutdown.Both);
            }
            catch { }

            try
            {
                client?.Client?.Shutdown(SocketShutdown.Both);
            }
            catch { }

            try
            {
                await completed.ConfigureAwait(false);
            }
            catch { }
        }

        private static async Task PumpAsync(Stream input, Stream output, CancellationToken ct)
        {
            byte[] buffer = new byte[RelayBufferSize];
            while (!ct.IsCancellationRequested)
            {
                int read = await input.ReadAsync(buffer, 0, buffer.Length, ct).ConfigureAwait(false);
                if (read <= 0)
                    break;

                await output.WriteAsync(buffer, 0, read, ct).ConfigureAwait(false);
                await output.FlushAsync(ct).ConfigureAwait(false);
            }
        }

        private static int FindHeaderEnd(byte[] data)
        {
            for (int i = 0; i <= data.Length - 4; i++)
            {
                if (data[i] == 13 && data[i + 1] == 10 && data[i + 2] == 13 && data[i + 3] == 10)
                    return i;
            }
            return -1;
        }

        private sealed class HeaderReadResult
        {
            public byte[] HeaderBytes { get; set; }
            public byte[] RemainingBytes { get; set; }
        }

        private sealed class ProxyRequest
        {
            public string Method { get; private set; }
            public string Target { get; private set; }
            public string HttpVersion { get; private set; }
            public string Host { get; private set; }
            public int Port { get; private set; }
            public string RelativeTarget { get; private set; }
            public bool IsConnect => string.Equals(Method, "CONNECT", StringComparison.OrdinalIgnoreCase);
            public List<string> HeaderLines { get; } = new List<string>();

            public static ProxyRequest TryParse(string rawHeader)
            {
                if (string.IsNullOrWhiteSpace(rawHeader))
                    return null;

                string[] lines = rawHeader.Split(new[] { "\r\n" }, StringSplitOptions.None);
                if (lines.Length == 0)
                    return null;

                string[] first = lines[0].Split(' ');
                if (first.Length < 3)
                    return null;

                var request = new ProxyRequest
                {
                    Method = first[0],
                    Target = first[1],
                    HttpVersion = first[2]
                };

                for (int i = 1; i < lines.Length; i++)
                {
                    string line = lines[i];
                    if (string.IsNullOrWhiteSpace(line))
                        continue;
                    request.HeaderLines.Add(line);
                }

                if (request.IsConnect)
                {
                    var parts = request.Target.Split(':');
                    request.Host = parts[0].Trim().ToLowerInvariant();
                    request.Port = parts.Length > 1 && int.TryParse(parts[1], out int p) ? p : 443;
                    request.RelativeTarget = request.Target;
                    return request;
                }

                if (Uri.TryCreate(request.Target, UriKind.Absolute, out var uri))
                {
                    request.Host = uri.Host.ToLowerInvariant();
                    request.Port = uri.Port > 0 ? uri.Port : (uri.Scheme.Equals("https", StringComparison.OrdinalIgnoreCase) ? 443 : 80);
                    request.RelativeTarget = string.IsNullOrEmpty(uri.PathAndQuery) ? "/" : uri.PathAndQuery;
                    return request;
                }

                string hostHeader = request.HeaderLines
                    .FirstOrDefault(x => x.StartsWith("Host:", StringComparison.OrdinalIgnoreCase));
                if (hostHeader == null)
                    return null;

                string hostValue = hostHeader.Substring(5).Trim();
                string[] hostParts = hostValue.Split(':');
                request.Host = hostParts[0].Trim().ToLowerInvariant();
                request.Port = hostParts.Length > 1 && int.TryParse(hostParts[1], out int hostPort) ? hostPort : 80;
                request.RelativeTarget = string.IsNullOrWhiteSpace(request.Target) ? "/" : request.Target;
                return request;
            }

            public string BuildForwardHeader()
            {
                var sb = new StringBuilder();
                sb.Append(Method).Append(' ').Append(RelativeTarget).Append(' ').Append(HttpVersion).Append("\r\n");

                foreach (string header in HeaderLines)
                {
                    if (header.StartsWith("Proxy-Connection:", StringComparison.OrdinalIgnoreCase))
                        continue;
                    sb.Append(header).Append("\r\n");
                }

                sb.Append("\r\n");
                return sb.ToString();
            }
        }

        private sealed class DomainRuleMatcher
        {
            private readonly string[] _rules;

            public DomainRuleMatcher(string[] rules)
            {
                _rules = (rules ?? Array.Empty<string>())
                    .Where(x => !string.IsNullOrWhiteSpace(x))
                    .Select(x => x.Trim().ToLowerInvariant())
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .ToArray();
            }

            public int RuleCount => _rules.Length;

            public bool IsBlocked(string host)
            {
                if (string.IsNullOrWhiteSpace(host))
                    return false;

                host = host.Trim().ToLowerInvariant();
                foreach (string rule in _rules)
                {
                    if (Matches(host, rule))
                        return true;
                }
                return false;
            }

            private static bool Matches(string host, string rule)
            {
                if (string.IsNullOrWhiteSpace(rule))
                    return false;

                if (rule.StartsWith("*.", StringComparison.Ordinal))
                {
                    string suffix = rule.Substring(1);
                    return host.EndsWith(suffix, StringComparison.OrdinalIgnoreCase);
                }

                return host.Equals(rule, StringComparison.OrdinalIgnoreCase)
                    || host.EndsWith("." + rule, StringComparison.OrdinalIgnoreCase);
            }
        }
    }
}
