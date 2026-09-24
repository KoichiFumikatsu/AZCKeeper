using System.Text;
using Keeper.Agent.Migration;

namespace Keeper.Agent.Tests;

public sealed class MigrationRecoveryTests
{
    [Fact]
    public void RecoveredSecretIsProtectedAndHttpBufferIsErased()
    {
        var input = Encoding.UTF8.GetBytes("{\"account_sid\":\"S-1-5-21-1-2-3-1001\",\"revision\":1,\"audit_id\":\"11111111-1111-4111-8111-111111111111\",\"password\":\"ABCDEFGHIJKLMNOPQRSTUVWXYZ123456\"}");
        using var recovery = ProtectedEscrowRecovery.ParseAndClear(input);
        Assert.Equal(32, recovery.Password.Length);
        Assert.True(recovery.Password.IsReadOnly());
        Assert.Equal("S-1-5-21-1-2-3-1001", recovery.AccountSid);
        Assert.All(input, value => Assert.Equal(0, value));
    }

    [Theory]
    [InlineData("{\"password\":\"too-short\"}")]
    [InlineData("{\"revision\":1,\"revision\":2}")]
    [InlineData("{\"unexpected\":\"value\"}")]
    public void InvalidRecoveryResponseAlsoErasesTheInput(string json)
    {
        var bytes = Encoding.UTF8.GetBytes(json);
        Assert.Throws<InvalidDataException>(() => ProtectedEscrowRecovery.ParseAndClear(bytes));
        Assert.All(bytes, value => Assert.Equal(0, value));
    }
}
