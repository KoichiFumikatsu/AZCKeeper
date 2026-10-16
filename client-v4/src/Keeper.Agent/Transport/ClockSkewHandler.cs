using System.Net;

namespace Keeper.Agent.Transport;

// En la tuberia de cada HttpClient del agente que habla con el servidor (alta, challenge/login, sync, politica,
// clave de azcadmin). Lee la cabecera Date de cada respuesta (ServerClock) y, si una peticion firmada recibe
// 401 invalid_signature y Date dice que la firma salio con mas de 30 s de diferencia, la vuelve a firmar con la hora
// corregida y la reenvia UNA vez en el acto (mismo nonce: el servidor no guarda el nonce de una firma rechazada, y el
// del login es el del challenge, que solo se consume con una firma valida).
public sealed class ClockSkewHandler(HttpMessageSigner signer) : DelegatingHandler
{
    private const int ProblemLimit = 32 * 1024;

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var clock = signer.Clock;
        var signature = HttpMessageSigner.ReadSignature(request);
        var response = await base.SendAsync(request, ct);
        if (clock is null) return response;
        if (signature is null)
        {
            clock.Observe(response.Headers.Date, confirmed: false);
            return response;
        }
        if (response.IsSuccessStatusCode)
        {
            // La firma fue aceptada: el desfase con que se hizo queda confirmado y Date (respuesta autenticada por TLS a
            // una peticion que el servidor valido) es una muestra de su hora.
            clock.Confirm();
            clock.Observe(response.Headers.Date, confirmed: true);
            return response;
        }
        if (response.StatusCode != HttpStatusCode.Unauthorized)
        {
            clock.Observe(response.Headers.Date, confirmed: false);
            return response;
        }
        var drift = response.Headers.Date is { } date && clock.Measure(date) is not null
            ? date - DateTimeOffset.FromUnixTimeSeconds(signature.Value.Created) : (TimeSpan?)null;
        if (drift is not { } skew || skew.Duration() <= ServerClock.RetryThreshold || !await InvalidSignatureAsync(response, ct))
        {
            // 401 por otra causa (token, clave de alta) o sin desfase que corregir: un desfase sin confirmar no se arrastra.
            clock.Revert();
            return response;
        }
        clock.Observe(response.Headers.Date, confirmed: false);
        HttpRequestMessage retry;
        try { retry = await CloneAsync(request, ct); }
        catch (Exception ex) when (ex is InvalidOperationException or IOException) { clock.Revert(); return response; }
        using (retry)
        {
            var body = retry.Content is null ? [] : await retry.Content.ReadAsByteArrayAsync(ct);
            signer.Sign(retry, body, clock.Local.GetUtcNow(), signature.Value.Nonce);
            var second = await base.SendAsync(retry, ct);
            if (second.IsSuccessStatusCode)
            {
                clock.Confirm();
                clock.Observe(second.Headers.Date, confirmed: true);
            }
            else if (second.StatusCode == HttpStatusCode.Unauthorized) clock.Revert();
            response.Dispose();
            return second;
        }
    }

    private static async Task<bool> InvalidSignatureAsync(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.Content.Headers.ContentLength is > ProblemLimit) return false;
        try
        {
            // Queda en memoria para quien lea la respuesta si no se reintenta.
            await response.Content.LoadIntoBufferAsync(ProblemLimit);
            return ProblemDetail.Parse(await response.Content.ReadAsByteArrayAsync(ct))?.Code == "invalid_signature";
        }
        catch (Exception ex) when (ex is HttpRequestException or IOException) { return false; }
    }

    private static async Task<HttpRequestMessage> CloneAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var clone = new HttpRequestMessage(request.Method, request.RequestUri) { Version = request.Version, VersionPolicy = request.VersionPolicy };
        foreach (var header in request.Headers)
            if (header.Key is not ("Signature" or "Signature-Input")) clone.Headers.TryAddWithoutValidation(header.Key, header.Value);
        if (request.Content is not null)
        {
            var bytes = await request.Content.ReadAsByteArrayAsync(ct);
            clone.Content = new ByteArrayContent(bytes);
            foreach (var header in request.Content.Headers)
                if (header.Key is not ("Content-Digest" or "Content-Length")) clone.Content.Headers.TryAddWithoutValidation(header.Key, header.Value);
        }
        return clone;
    }
}
