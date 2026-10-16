using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Muestras autenticadas de la hora del servidor, eventos estampados con ella mientras el reloj de Windows va corrido
// y vigencia de las ordenes contra la hora corregida.
public sealed class ServerTimeTests
{
    [Fact]
    public void ConsistentOffsetNeedsTwoAuthenticatedSamplesSpacedAndAgreeing()
    {
        var local = new TestClock();
        var clock = new ServerClock(local);
        clock.Observe(local.GetUtcNow().AddMinutes(10), confirmed: false);
        clock.Observe(local.GetUtcNow().AddMinutes(10), confirmed: true);
        Assert.Equal(1, clock.SampleCount);
        Assert.Null(clock.ConsistentOffset(0));
        local.Advance(TimeSpan.FromSeconds(29));
        clock.Observe(local.GetUtcNow().AddMinutes(10), confirmed: true);
        Assert.Null(clock.ConsistentOffset(0));
        local.Advance(TimeSpan.FromSeconds(2));
        clock.Observe(local.GetUtcNow().AddMinutes(10).AddSeconds(4), confirmed: true);
        Assert.Equal(TimeSpan.FromSeconds(604), clock.ConsistentOffset(0));
        // Solo cuentan las posteriores a la marca que pide TimeSync.
        Assert.Null(clock.ConsistentOffset(2));
        // Un salto del reloj de Windows mueve tambien las muestras.
        clock.ShiftLocal(TimeSpan.FromMinutes(10));
        Assert.Equal(TimeSpan.FromSeconds(4), clock.ConsistentOffset(0));
        clock.ResetAfterSystemSet();
        Assert.Null(clock.ConsistentOffset(0));
        Assert.Equal(TimeSpan.Zero, clock.Offset);
        Assert.Equal(TimeSpan.Zero, clock.Confirmed);
    }

    [Fact]
    public void EventCorrectionOnlyAboveFiveMinutesOfConfirmedOffset()
    {
        var local = new TestClock();
        var clock = new ServerClock(local);
        clock.Observe(local.GetUtcNow().AddMinutes(-4), confirmed: true);
        Assert.Equal(TimeSpan.Zero, clock.EventCorrection);
        clock.Observe(local.GetUtcNow().AddMinutes(-20), confirmed: false);
        Assert.Equal(TimeSpan.Zero, clock.EventCorrection);
        clock.Observe(local.GetUtcNow().AddMinutes(-20), confirmed: true);
        Assert.Equal(TimeSpan.FromMinutes(-20), clock.EventCorrection);
    }

    [Fact]
    public async Task OutboxStampsEventsWithServerTimeWhileWindowsClockIsOff()
    {
        using var directory = new TestDirectory();
        var shift = TimeSpan.FromMinutes(-20);
        using var outbox = new DurableOutbox(directory.File("outbox")) { TimeCorrection = () => shift };
        var at = DateTimeOffset.FromUnixTimeSeconds(1_760_000_000);
        await outbox.EnqueueAsync(Samples.Log() with { At = at }, default);
        await outbox.EnqueueAsync(Samples.Episode() with { StartedAt = at, EndedAt = at.AddMinutes(1) }, default);
        shift = TimeSpan.Zero;
        await outbox.EnqueueAsync(Samples.Log() with { At = at }, default);
        var batch = await outbox.PrepareAsync(null, default);
        using var body = JsonDocument.Parse(batch.Body);
        var logs = body.RootElement.GetProperty("logs").EnumerateArray().Select(l => l.GetProperty("at").GetDateTimeOffset()).ToArray();
        Assert.Equal([at.AddMinutes(-20), at], logs);
        var episode = body.RootElement.GetProperty("episodes")[0];
        Assert.Equal(at.AddMinutes(-20), episode.GetProperty("started_at").GetDateTimeOffset());
        Assert.Equal(at.AddMinutes(-19), episode.GetProperty("ended_at").GetDateTimeOffset());
    }

    [Fact]
    public void CorrectedShiftsCommandResultsAndSecurityReportsKeepingTheReportHashConsistent()
    {
        var at = DateTimeOffset.FromUnixTimeSeconds(1_760_000_000);
        SecurityControl[] controls = [new() { ControlId = "TimeSync", State = SecurityControlState.Applied, ObservedAt = at }];
        var report = new SecurityReport { EventId = Guid.NewGuid(), ObservedAt = at, Controls = controls, ReportHash = "x" };
        var result = new SyncRequestCommandResultsItem
        {
            CommandId = Guid.NewGuid(), Result = new CommandResult { EventId = Guid.NewGuid(), At = at, Status = CommandResultStatus.Succeeded }
        };
        var shift = TimeSpan.FromMinutes(7);
        var security = DurableOutbox.Corrected(new OutboxEvent(report.EventId, Security: report), shift).Security!;
        Assert.Equal(at + shift, security.ObservedAt);
        Assert.Equal(at + shift, security.Controls[0].ObservedAt);
        Assert.Equal(Convert.ToHexString(SHA256.HashData(JsonSerializer.SerializeToUtf8Bytes(security.Controls, ProtocolJson.Options))).ToLowerInvariant(),
            security.ReportHash);
        Assert.Equal(at + shift, DurableOutbox.Corrected(new OutboxEvent(result.Result.EventId, Command: result), shift).Command!.Result.At);
        Assert.Same(report, DurableOutbox.Corrected(new OutboxEvent(report.EventId, Security: report), TimeSpan.Zero).Security);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task CommandExpiryIsComparedWithServerTime(bool corrected)
    {
        // Reloj de Windows 10 min adelantado; la orden vence en 5 min (hora del servidor).
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, new NoActions())
        {
            ServerOffset = corrected ? () => TimeSpan.FromMinutes(-10) : null
        };
        await executor.InitAsync(context);
        var server = clock.GetUtcNow().AddMinutes(-10);
        await executor.AcceptAsync([new Command
        {
            Id = Guid.NewGuid(), DeviceId = Samples.Device, TenantId = Samples.Tenant, Type = CommandType.Lock,
            Status = CommandStatus.Pending, CreatedAt = server, ExpiresAt = server.AddMinutes(5), Result = null
        }], Samples.Tenant, default);
        var results = ((MemoryEvents)context.Outbox).CommandResults;
        if (corrected) Assert.Empty(results);
        else Assert.Equal("expired", Assert.Single(results).Result.Code);
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
    }

    private sealed class NoActions : IDeviceActions
    {
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) => Task.CompletedTask;
    }
}
