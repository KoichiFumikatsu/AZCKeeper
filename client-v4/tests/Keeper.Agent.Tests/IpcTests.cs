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
