using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// ACT-0033 (2026-10-13/15): un titulo de ventana fuera del contrato hizo que el servidor respondiera 422 a TODO el sync y el
// agente reenviara el mismo paquete cada 15 min durante dos dias. 4.0.25: recorta al contrato antes de enviar y, ante un
// 422, aparta solo el elemento malo (por pointer o partiendo el paquete) a una cuarentena durable y sigue con el resto.
public sealed class OutboxRejectionTests
{
    // mb_strlen de PHP sobre el UTF-8 que llega: puntos de codigo.
    private static int PhpLength(string value) => Encoding.UTF8.GetString(Encoding.UTF8.GetBytes(value)).EnumerateRunes().Count();
    private static bool HasLoneSurrogate(string value) =>
        value.Select((c, i) => char.IsHighSurrogate(c) ? i + 1 < value.Length && char.IsLowSurrogate(value[i + 1]) ? 0 : 1
            : char.IsLowSurrogate(c) && (i == 0 || !char.IsHighSurrogate(value[i - 1])) ? 1 : 0).Sum() > 0;

    [Theory]
    [InlineData(1100, 0)]  // ASCII
    [InlineData(600, 1)]   // emoji (par sustituto): 1200 unidades UTF-16, 600 puntos de codigo
    [InlineData(700, 2)]   // emoji compuesto (familia con ZWJ): no se parte el grafema
    [InlineData(1, 3)]     // marcas combinadas sin fin en un solo grafema: corte por punto de codigo sin partir pares
    public void TruncateMeetsPhpCountWithoutSplittingEmoji(int repeat, int kind)
    {
        var unit = kind switch { 0 => "a", 1 => "\U0001F600", 2 => "\U0001F468‍\U0001F469‍\U0001F467", _ => "e" + string.Concat(Enumerable.Repeat("́", 3000)) };
        var text = "x" + string.Concat(Enumerable.Repeat(unit, repeat));
        var cut = ProtocolText.Truncate(text, 512);
        Assert.InRange(PhpLength(cut), 1, 512);
        Assert.Equal(PhpLength(cut), ProtocolText.CodePoints(cut));
        Assert.False(HasLoneSurrogate(cut));
        Assert.StartsWith(cut, text, StringComparison.Ordinal);
        if (kind == 1) Assert.Equal(512, PhpLength(cut));
        if (kind == 2) Assert.Equal(0, (cut.Length - 1) % unit.Length);
        Assert.Same(cut, ProtocolText.Truncate(cut, 512));
    }

    [Fact]
    public void LoneSurrogateBecomesReplacementCharacter()
    {
        // GetWindowTextW con un buffer fijo puede cortar un par: PHP rechaza el JSON entero con un sustituto suelto.
        var cut = ProtocolText.Truncate("abc\uD83D", 1024);
        Assert.Equal("abc�", cut);
        Assert.Equal("�z", ProtocolText.Truncate("\uDE00z", 1024));
    }

    [Fact]
    public async Task LongTextsAreTrimmedToTheContractAndSent()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var episode = Samples.Episode(new string('t', 1500) + "\U0001F600") with { ProcessName = new string('p', 200), AppName = "\U0001F600" + new string('a', 300) };
        await outbox.EnqueueAsync(episode, default);
        await outbox.EnqueueAsync(Samples.Log() with { Fields = new LogEntryFields { ErrorCode = new string('e', 120) } }, default);
        var server = new FakeServer(request =>
        {
            var sent = Assert.Single(request.Episodes!);
            Assert.Equal(1024, PhpLength(sent.WindowTitle!));
            Assert.Equal(160, PhpLength(sent.ProcessName));
            Assert.Equal(190, PhpLength(sent.AppName!));
            Assert.Equal(80, Assert.Single(request.Logs!).Fields!.ErrorCode!.Length);
            return null;
        });
        await server.Client(outbox).ExecuteAsync(default);
        Assert.Equal(1, server.Syncs);
        Assert.Empty((await outbox.InspectAsync()).Events);
        Assert.Empty(await outbox.InspectQuarantineAsync());
    }

    [Fact]
    public async Task PendingBatchFromAnOlderAgentIsRebuiltTrimmed()
    {
        using var directory = new TestDirectory();
        var path = directory.File("outbox.json");
        // Cola de 4.0.24: proceso de 200 caracteres (el contrato acepta 160) ya metido en el lote pendiente.
        var old = Samples.Episode() with { ProcessName = new string('p', 200) };
        var body = JsonSerializer.SerializeToUtf8Bytes(new SyncRequest { ProtocolVersion = 1, Sequence = 1, PolicyVersion = null, ReleaseId = null, Episodes = [old], Logs = [], CommandResults = [] }, ProtocolJson.Options);
        var key = Guid.NewGuid();
        await File.WriteAllBytesAsync(path, JsonSerializer.SerializeToUtf8Bytes(
            new OutboxState(1, [new OutboxEvent(old.EventId, Episode: old)], [], new PendingBatch(key, body, [old.EventId])), ProtocolJson.Options));
        using var outbox = new DurableOutbox(path);
        var batch = await outbox.PrepareAsync(1, default);
        Assert.NotEqual(key, batch.IdempotencyKey);
        Assert.Equal(160, JsonSerializer.Deserialize<SyncRequest>(batch.Body, ProtocolJson.Options)!.Episodes!.Single().ProcessName.Length);
        Assert.Equal(batch.IdempotencyKey, (await outbox.PrepareAsync(1, default)).IdempotencyKey);
    }

    [Fact]
    public async Task PointerRejectionQuarantinesOnlyThatElementAndSendsTheRestInTheSameCycle()
    {
        using var directory = new TestDirectory();
        var path = directory.File("outbox.json");
        var episodes = Enumerable.Range(0, 3).Select(i => Samples.Episode($"titulo {i}")).ToArray();
        Guid bad = default;
        var server = new FakeServer(request =>
        {
            if (bad != default) return null;
            bad = request.Episodes![1].EventId;
            return Problem("/episodes/1/window_title", "length");
        });
        string? logged = null;
        using (var outbox = new DurableOutbox(path))
        {
            foreach (var e in episodes) await outbox.EnqueueAsync(e, default);
            await outbox.EnqueueAsync(Samples.Log(), default);
            await server.Client(outbox, m => logged = m).ExecuteAsync(default);
            Assert.Equal(2, server.Syncs);
            Assert.Empty((await outbox.InspectAsync()).Events);
        }
        Assert.Contains("422 validation_failed pointer=/episodes/1/window_title rule=length", logged);
        Assert.Contains("apartado a cuarentena", logged);
        // El segundo envio lleva todo menos el elemento malo, mas el aviso outbox_item_rejected (campo y regla, sin valor).
        var second = server.Requests[1];
        Assert.Equal(episodes.Select(e => e.EventId).Where(id => id != bad).Order(), second.Episodes!.Select(e => e.EventId).Order());
        var notice = Assert.Single(second.Logs!, l => l.Code == DurableOutbox.RejectedCode);
        Assert.Equal(DurableOutbox.Component, notice.Component);
        Assert.Equal(LogEntryLevel.Warn, notice.Level);
        Assert.Equal("episodes.1.window_title:length", notice.Fields!.ErrorCode);
        Assert.DoesNotContain("titulo", notice.Fields.ErrorCode);
        // La cuarentena sobrevive al reinicio y guarda el elemento intacto.
        using var restarted = new DurableOutbox(path);
        var kept = Assert.Single(await restarted.InspectQuarantineAsync());
        Assert.Equal(bad, kept.Event.Id);
        Assert.Equal("titulo 1", kept.Event.Episode!.WindowTitle);
        Assert.Equal("episodes.1.window_title:length", kept.Detail);
    }

    [Theory]
    [InlineData(8, 5)]
    [InlineData(1, 0)]
    [InlineData(13, 12)]
    public async Task RejectionWithoutPointerIsolatesTheBadElementBySplittingAcrossRestarts(int count, int poisonIndex)
    {
        using var directory = new TestDirectory();
        var path = directory.File("outbox.json");
        var episodes = Enumerable.Range(0, count).Select(i => Samples.Episode(i == poisonIndex ? "VENENO" : $"ok {i}")).ToArray();
        var rejectedBodies = new List<string>();
        var server = new FakeServer(request =>
            request.Episodes!.Any(e => e.WindowTitle == "VENENO") ? Problem(null, null) : null)
        { OnRejected = body => rejectedBodies.Add(body) };
        using (var initial = new DurableOutbox(path)) foreach (var e in episodes) await initial.EnqueueAsync(e, default);
        var cycles = 0;
        while (cycles < 10)
        {
            cycles++;
            // Cada ciclo con outbox y cliente nuevos: la busqueda y la cuarentena viven en disco.
            using var outbox = new DurableOutbox(path);
            try { await server.Client(outbox).ExecuteAsync(default); }
            catch (TransportException ex) { Assert.Equal(NetworkFailureKind.Rejected, NetworkBackoffPolicy.Classify(ex)); }
            if ((await outbox.InspectQuarantineAsync()).Count > 0 && (await outbox.InspectAsync()).Events.Count == 0) break;
        }
        using var final = new DurableOutbox(path);
        var kept = Assert.Single(await final.InspectQuarantineAsync());
        Assert.Equal(episodes[poisonIndex].EventId, kept.Event.Id);
        Assert.Equal("episodes:isolated", kept.Detail);
        Assert.Null(await final.InspectIsolationAsync());
        Assert.Empty((await final.InspectAsync()).Events);
        Assert.Equal(episodes.Where((_, i) => i != poisonIndex).Select(e => e.EventId).Order(), server.Accepted.Where(id => episodes.Any(e => e.EventId == id)).Order());
        Assert.Contains(server.AcceptedLogs, l => l.Code == DurableOutbox.RejectedCode && l.Fields!.ErrorCode == "episodes:isolated");
        // Nunca se reenvia el mismo paquete rechazado.
        Assert.Equal(rejectedBodies.Count, rejectedBodies.Select(b => JsonSerializer.Deserialize<SyncRequest>(b, ProtocolJson.Options)!)
            .Select(r => string.Join(',', r.Episodes!.Select(e => e.EventId))).Distinct().Count());
        Assert.InRange(cycles, 1, 4);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("/sequence")]
    public async Task GeneralRejectionQuarantinesNothingAndBacksOff(string? pointer)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var episodes = Enumerable.Range(0, 3).Select(_ => Samples.Episode()).ToArray();
        foreach (var e in episodes) await outbox.EnqueueAsync(e, default);
        var server = new FakeServer(_ => Problem(pointer, "type"));
        for (var cycle = 0; cycle < 3; cycle++)
        {
            var error = await Assert.ThrowsAsync<TransportException>(() => server.Client(outbox).ExecuteAsync(default));
            Assert.Equal(NetworkFailureKind.Rejected, NetworkBackoffPolicy.Classify(error));
            Assert.Contains("validation_failed", error.Message);
        }
        // Sin pointer, por ciclo: paquete, mitad (2), ultimo sospechoso (1) y sync vacio de control, rechazado: es general.
        // Con /sequence (campo del sobre): un envio por ciclo.
        Assert.Equal(pointer is null ? 12 : 3, server.Syncs);
        Assert.Empty(await outbox.InspectQuarantineAsync());
        Assert.Equal(episodes.Select(e => e.EventId).Order(), (await outbox.InspectAsync()).Events.Select(e => e.Id).Order());
        Assert.Null((await outbox.InspectAsync()).Pending);
        var schedule = new SyncSchedule(Samples.Device);
        Assert.True(schedule.AfterAttempt(false, 1, failureKind: NetworkFailureKind.Rejected) >= TimeSpan.FromSeconds(300));
    }

    [Fact]
    public async Task RejectionsPerCycleAreCappedAndTheSearchResumesNextCycle()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        for (var i = 0; i < 20; i++) await outbox.EnqueueAsync(Samples.Episode($"t{i}"), default);
        // Cada episodio da 422 con su propio pointer: uno apartado por envio, tope por ciclo.
        var server = new FakeServer(request => Problem("/episodes/0/window_title", "length"));
        await Assert.ThrowsAsync<TransportException>(() => server.Client(outbox).ExecuteAsync(default));
        Assert.Equal(SyncClient.MaxRejectionsPerCycle, server.Syncs);
        Assert.Equal(SyncClient.MaxRejectionsPerCycle, (await outbox.InspectQuarantineAsync()).Count);
    }

    [Fact]
    public void ProblemDetailKeepsPointerAndRuleOnly()
    {
        var problem = ProblemDetail.Parse(Encoding.UTF8.GetBytes(
            """{"type":"about:blank","title":"validation_failed","status":422,"code":"validation_failed","request_id":"x","errors":[{"pointer":"/episodes/10/window_title","code":"length","message":"m"}]}"""));
        Assert.Equal(new ProblemDetail("validation_failed", "/episodes/10/window_title", "length"), problem);
        Assert.Equal("HTTP 422 validation_failed pointer=/episodes/10/window_title rule=length",
            new TransportException(HttpStatusCode.UnprocessableEntity, null, problem).Message);
        // Servidor anterior (sin errors) o cuerpo raro: solo el code, o nada.
        Assert.Equal(new ProblemDetail("validation_failed", null, null), ProblemDetail.Parse(Encoding.UTF8.GetBytes("""{"code":"validation_failed"}""")));
        Assert.Null(ProblemDetail.Parse(Encoding.UTF8.GetBytes("no json")));
        Assert.Null(ProblemDetail.Parse([]));
    }

    [Fact]
    public async Task SchedulerLogsProblemCodeAndPointer()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var problem = new ProblemDetail("validation_failed", "/episodes/3/window_title", "length");
        var logs = new List<string>();
        await using var host = new ModuleHost([], Samples.Context(clock));
        using var scheduler = new Scheduler(host, new ThrowingCycle(new TransportException(HttpStatusCode.UnprocessableEntity, null, problem)),
            new SyncSchedule(Samples.Device), clock, directory.File("next.json"), logs.Add);
        clock.Advance(TimeSpan.FromMinutes(5));
        await scheduler.StepAsync(default);
        clock.Advance(TimeSpan.FromMinutes(5));
        await scheduler.StepAsync(default);
        Assert.Contains("sync_failed: HTTP 422 validation_failed pointer=/episodes/3/window_title rule=length", logs);
        Assert.Contains(logs, l => l.StartsWith("sync_retry: Rejected", StringComparison.Ordinal));
    }

    [Fact]
    public void SessionEpisodesWithLongTextsAreAcceptedForTrimming()
    {
        var correlation = Guid.NewGuid();
        var episode = Samples.Episode(new string('t', 2000)) with { ProcessName = new string('p', 300) };
        SessionProtocol.Validate(new SessionResponse(1, correlation, [episode], []), correlation);
    }

    private static HttpResponseMessage Problem(string? pointer, string? rule)
    {
        var errors = pointer is null ? "" : $",\"errors\":[{{\"pointer\":\"{pointer}\",\"code\":\"{rule}\",\"message\":\"El campo no cumple la regla {rule}\"}}]";
        return new HttpResponseMessage(HttpStatusCode.UnprocessableEntity)
        {
            Content = new StringContent($"{{\"type\":\"about:blank\",\"title\":\"validation_failed\",\"status\":422,\"code\":\"validation_failed\",\"request_id\":\"{Guid.NewGuid()}\"{errors}}}",
                Encoding.UTF8, "application/problem+json")
        };
    }

    private sealed class ThrowingCycle(Exception error) : ISyncCycle
    {
        public long RequestCount { get; private set; }
        public Task<int> ExecuteAsync(CancellationToken ct) { RequestCount++; return Task.FromException<int>(error); }
    }

    // Servidor de prueba: login fijo; cada sync se pasa a `reject` (null = aceptar todo con acks).
    private sealed class FakeServer(Func<SyncRequest, HttpResponseMessage?> reject)
    {
        public int Syncs { get; private set; }
        public List<SyncRequest> Requests { get; } = [];
        public HashSet<Guid> Accepted { get; } = [];
        public List<LogEntry> AcceptedLogs { get; } = [];
        public Action<string>? OnRejected { get; init; }
        private readonly TestClock _clock = new();
        private readonly HttpMessageSigner _signer = new(ECDsa.Create(ECCurve.NamedCurves.nistP256));

        public SyncClient Client(DurableOutbox outbox, Action<string>? log = null)
        {
            var handler = new StubHandler(async request =>
            {
                var path = request.RequestUri!.AbsolutePath;
                if (path.EndsWith("challenges", StringComparison.Ordinal))
                    return Json(new Challenge { Nonce = "nonce", ExpiresAt = _clock.GetUtcNow().AddMinutes(1) });
                if (path.EndsWith("login", StringComparison.Ordinal))
                    return Json(new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "test-token", TokenType = "Bearer", ExpiresIn = 3600 });
                var text = await request.Content!.ReadAsStringAsync();
                var body = JsonSerializer.Deserialize<SyncRequest>(text, ProtocolJson.Options)!;
                Syncs++;
                Requests.Add(body);
                if (reject(body) is { } rejection) { OnRejected?.Invoke(text); return rejection; }
                foreach (var e in body.Episodes ?? []) Accepted.Add(e.EventId);
                foreach (var l in body.Logs ?? []) { Accepted.Add(l.EventId); AcceptedLogs.Add(l); }
                return Json(new SyncResponse
                {
                    ProtocolVersion = 1, ServerTime = _clock.GetUtcNow(), NextSyncAfterSeconds = 120, PolicyVersion = 1, Policy = Samples.Policy(),
                    Release = null, Commands = [], CommandAcks = [], SecurityAck = null, ActivityAck = null,
                    EpisodeAcks = (body.Episodes ?? []).Select(e => new Ack { EventId = e.EventId, Status = AckStatus.Accepted, Retryable = false }).ToList(),
                    LogAcks = (body.Logs ?? []).Select(l => new Ack { EventId = l.EventId, Status = AckStatus.Accepted, Retryable = false }).ToList()
                });
            });
            var policy = new PolicyCoordinator(new MemoryPolicyStore(), new ModuleHost([], Samples.Context(_clock)), Samples.Device);
            return new SyncClient(new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") }, _signer, Samples.Device, outbox,
                policy, _clock) { Log = log };
        }
    }

    private static HttpResponseMessage Json<T>(T value) => new(HttpStatusCode.OK)
    {
        Content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options))
    };

    private sealed class StubHandler(Func<HttpRequestMessage, Task<HttpResponseMessage>> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => respond(request);
    }
}
