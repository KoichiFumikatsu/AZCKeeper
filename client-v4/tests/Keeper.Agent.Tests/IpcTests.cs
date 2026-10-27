using System.Buffers.Binary;
using System.IO.Pipes;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class IpcTests
{
    [Fact]
    public async Task FramedContractRoundTripsBothDirectionsWithoutOperatingSystemPrivileges()
    {
        using var requestStream = new MemoryStream();
        using var responseStream = new MemoryStream();
        var request = new SessionRequest(1, Guid.NewGuid(), null, null, true, false, []);
        await SessionProtocol.WriteAsync(requestStream, request, default);
        requestStream.Position = 0;
        var received = await SessionProtocol.ReadAsync<SessionRequest>(requestStream, default);
        Assert.Equal(request.CorrelationId, received.CorrelationId);
        var response = new SessionResponse(1, received.CorrelationId, [Samples.Episode()], []);
        await SessionProtocol.WriteAsync(responseStream, response, default);
        responseStream.Position = 0;
        var result = await SessionProtocol.ReadAsync<SessionResponse>(responseStream, default);
        SessionProtocol.Validate(result, request.CorrelationId);
        Assert.Single(result.Episodes);
    }

    [Fact]
    public void InactividadYEleccionViajanYSeOmitenSiSonNulas()
    {
        var run = Guid.NewGuid();
        var response = new SessionResponse(1, Guid.NewGuid(), [], [], IdleSeconds: 42, PowerChoice: new PowerChoice(run, PowerChoiceKind.Postpone));
        var json = JsonSerializer.Serialize(response, ProtocolJson.Options);
        Assert.Contains("\"idle_seconds\":42", json);
        Assert.Contains($"\"power_choice\":{{\"run_id\":\"{run}\",\"kind\":\"postpone\"}}", json);
        Assert.Equal(response.PowerChoice, JsonSerializer.Deserialize<SessionResponse>(json, ProtocolJson.Options)!.PowerChoice);
        var plain = JsonSerializer.Serialize(new SessionResponse(1, Guid.NewGuid(), [], []), ProtocolJson.Options);
        Assert.DoesNotContain("idle_seconds", plain);
        Assert.DoesNotContain("power_choice", plain);
    }

    [Theory]
    [InlineData(-1L, false)]
    [InlineData(31_536_001L, false)]
    [InlineData(0L, true)]
    [InlineData(31_536_000L, true)]
    public void ValidaElRangoDeInactividad(long idle, bool valid)
    {
        var id = Guid.NewGuid();
        var response = new SessionResponse(1, id, [], [], IdleSeconds: idle);
        if (valid) SessionProtocol.Validate(response, id);
        else Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(response, id));
    }

    [Fact]
    public void RechazaUnaEleccionSinOrden()
    {
        var id = Guid.NewGuid();
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(
            new SessionResponse(1, id, [], [], PowerChoice: new PowerChoice(Guid.Empty, PowerChoiceKind.Now)), id));
        SessionProtocol.Validate(new SessionResponse(1, id, [], [], PowerChoice: new PowerChoice(Guid.NewGuid(), PowerChoiceKind.Now)), id);
    }

    [Theory]
    [InlineData(-1)]
    [InlineData(0)]
    [InlineData(131073)]
    public async Task RejectsUnboundedFramesBeforeAllocating(int size)
    {
        var prefix = new byte[4]; BinaryPrimitives.WriteInt32LittleEndian(prefix, size);
        using var stream = new MemoryStream(prefix);
        await Assert.ThrowsAsync<InvalidDataException>(() => SessionProtocol.ReadAsync<SessionResponse>(stream, default));
    }

    [Fact]
    public void RejectsWrongVersionCorrelationCommandsAndInvalidDurations()
    {
        var correlation = Guid.NewGuid();
        var response = new SessionResponse(1, correlation, [Samples.Episode()], []);
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(response with { ProtocolVersion = 2 }, correlation));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(response, Guid.NewGuid()));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(response with { Episodes = [Samples.Episode() with { ActiveSeconds = 999 }] }, correlation));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(response with { Episodes = [null!] }, correlation));
        Assert.DoesNotContain("123456", (response with { Pin = "123456" }).ToString());
        Assert.Throws<JsonException>(() => JsonSerializer.Deserialize<SessionResponse>("{\"protocol_version\":1,\"command\":\"unlock\"}", ProtocolJson.Options));
        var json = JsonSerializer.Serialize(new SessionRequest(1, correlation, null, null, true, false, []), ProtocolJson.Options);
        Assert.DoesNotContain("access_token", json);
    }

    [Theory]
    [InlineData("ActivityTracker", "simulated_input", null, true)]
    [InlineData("ActivityTracker", "simulated_input_end", "390s", true)]
    [InlineData("ActivityTracker", "physical_input_unavailable", "win32_5", true)]
    [InlineData("ActivityTracker", "capture_gap", null, true)]
    [InlineData("Presence", "late_login", null, true)]
    [InlineData("ActivityTracker", "simulated_input_end", "390 s", false)]
    [InlineData("ActivityTracker", "capture_gap", "x", false)]
    [InlineData("WebEnforcer", "applied", null, false)]
    public void SesionSoloPuedeMandarAvisosDeSuLista(string component, string code, string? error, bool accepted)
    {
        var log = new LogEntry { EventId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, Level = LogEntryLevel.Info, Component = component, Code = code,
            Fields = error is null ? null : new LogEntryFields { ErrorCode = error } };
        Assert.Equal(accepted, SessionProtocol.IsAccepted(log));
        // Un aviso fuera de lista ya no invalida la respuesta entera (antes cortaba la captura de la sesion).
        SessionProtocol.Validate(new SessionResponse(1, Guid.Empty, [], [log]) with { CorrelationId = Guid.Empty }, Guid.Empty);
    }

    [Fact]
    public void ProductionAclAllowsOnlySystemAndAuthorizedSidAndRejectsNetwork()
    {
        if (!OperatingSystem.IsWindows()) return;
        using var identity = WindowsIdentity.GetCurrent();
        var security = SessionSupervisor.CreateSecurity(identity.User!.Value);
        Assert.True(security.AreAccessRulesProtected);
        var rules = security.GetAccessRules(true, false, typeof(SecurityIdentifier)).Cast<PipeAccessRule>().ToArray();
        Assert.Equal(3, rules.Length);
        Assert.Contains(rules, r => r.AccessControlType == AccessControlType.Deny && r.IdentityReference.Value == "S-1-5-2");
        Assert.DoesNotContain(rules, r => r.AccessControlType == AccessControlType.Allow && r.IdentityReference.Value == "S-1-1-0");
    }
}
