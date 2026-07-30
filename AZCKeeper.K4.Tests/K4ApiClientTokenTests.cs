using System;
using System.Collections.Generic;
using System.Net;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper.K4.Core;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// Persistencia de token y re-login silencioso. Usa un handler fake que enruta por path y
/// puede secuenciar respuestas (p.ej. handshake 401 y luego 200) para forzar el re-login.
/// </summary>
public class K4ApiClientTokenTests
{
    /// <summary>Handler fake: por cada path, una cola de (status, json). Consume en orden.</summary>
    private sealed class SeqHandler : HttpMessageHandler
    {
        private readonly Dictionary<string, Queue<(int, string)>> _byPath = new();
        public int LoginCalls { get; private set; }
        public int HandshakeCalls { get; private set; }

        public SeqHandler On(string pathContains, params (int status, string json)[] responses)
        {
            _byPath[pathContains] = new Queue<(int, string)>(Array.ConvertAll(responses, r => (r.status, r.json)));
            return this;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage req, CancellationToken ct)
        {
            var path = req.RequestUri!.AbsolutePath;
            if (path.Contains("login")) LoginCalls++;
            if (path.Contains("handshake")) HandshakeCalls++;

            foreach (var (key, queue) in _byPath)
            {
                if (path.Contains(key) && queue.Count > 0)
                {
                    var (status, json) = queue.Dequeue();
                    return Task.FromResult(new HttpResponseMessage((HttpStatusCode)status)
                    {
                        Content = new StringContent(json)
                    });
                }
            }
            // default: 200 vacío ok
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent("{\"ok\":true}")
            });
        }
    }

    private static K4ApiClient Make(SeqHandler h)
        => new("http://x/api", "a1b2c3d4-e5f6-4789-8abc-de0123456789", new HttpClient(h));

    [Fact]
    public void RestoreToken_HaceHasTokenTrue_SinDispararTokenChanged()
    {
        var api = Make(new SeqHandler());
        bool fired = false;
        api.TokenChanged += _ => fired = true;

        api.RestoreToken("guardado");
        Assert.True(api.HasToken);
        Assert.Equal("guardado", api.CurrentToken);
        Assert.False(fired); // restaurar del disco NO es un login nuevo
    }

    [Fact]
    public async Task LoginExitoso_DisparaTokenChanged_ConElToken()
    {
        var h = new SeqHandler().On("login", (200, "{\"token\":\"fresco\"}"));
        var api = Make(h);
        string? got = null;
        api.TokenChanged += t => got = t;

        var res = await api.LoginAsync("K4TEST", "eq", "4.0.0.0");
        Assert.True(res.Ok);
        Assert.Equal("fresco", got); // se persiste por este evento
    }

    [Fact]
    public void ClearToken_LoLimpia()
    {
        var api = Make(new SeqHandler());
        api.RestoreToken("x");
        api.ClearToken();
        Assert.False(api.HasToken);
    }

    [Fact]
    public async Task ReLoginSilencioso_CuandoElHandshakeDa401()
    {
        // handshake: primero 401 (token viejo), luego 200 (tras re-login). login: 200.
        var h = new SeqHandler()
            .On("handshake",
                (401, "{\"ok\":false}"),
                (200, "{\"ok\":true,\"effectiveConfig\":{\"modules\":{}}}"))
            .On("login", (200, "{\"token\":\"fresco\"}"));
        var api = Make(h);
        api.RestoreToken("viejo");

        var host = new ModuleHost();
        var core = new CoreService(api, host, "K4TEST", "eq", "4.0.0.0");

        var ok = await core.RunOnceAsync();

        Assert.True(ok);                       // el ciclo terminó bien tras re-loguear
        Assert.Equal(1, h.LoginCalls);         // re-login ocurrió una vez
        Assert.Equal(2, h.HandshakeCalls);     // handshake reintentado tras el 401
        Assert.Equal("fresco", api.CurrentToken);
    }

    [Fact]
    public async Task SinCredencialesValidas_NoEntraEnLoopDeReLogin()
    {
        // handshake 401 y login que sigue fallando -> un solo intento, sin bucle.
        var h = new SeqHandler()
            .On("handshake", (401, "{\"ok\":false}"), (401, "{\"ok\":false}"))
            .On("login", (403, "{\"ok\":false,\"status\":\"denied\"}"));
        var api = Make(h);
        api.RestoreToken("viejo");

        var core = new CoreService(api, new ModuleHost(), "K4TEST", "eq", "4.0.0.0");
        var ok = await core.RunOnceAsync();

        Assert.False(ok);
        Assert.Equal(1, h.LoginCalls);     // intentó re-login una vez, no en bucle
        Assert.Equal(1, h.HandshakeCalls); // no reintentó handshake porque el login falló
    }
}
