using System.Buffers.Binary;
using System.Security.Cryptography;
using Keeper.Agent.Transport;

namespace Keeper.Agent.Hosting;

public sealed class SyncSchedule
{
    private readonly byte[] _seed;
    private int _attempt;
    private int _failures;
    private NetworkFailureKind? _lastFailure;
    public int IntervalSeconds { get; }

    public SyncSchedule(Guid deviceId, int intervalSeconds = 120)
    {
        if (intervalSeconds < 120 || intervalSeconds > 3600) throw new ArgumentOutOfRangeException(nameof(intervalSeconds));
        _seed = deviceId.ToByteArray();
        IntervalSeconds = intervalSeconds;
    }

    public TimeSpan InitialDelay => TimeSpan.FromSeconds(BinaryPrimitives.ReadUInt32LittleEndian(SHA256.HashData(_seed)) % 121);

    public TimeSpan AfterAttempt(bool success, int requestCount, int serverSeconds = 120, TimeSpan? retryAfter = null,
        NetworkFailureKind failureKind = NetworkFailureKind.Transient)
    {
        if (_lastFailure != failureKind) _failures = 0;
        _failures = success ? 0 : Math.Min(_failures + 1, 13);
        _lastFailure = success ? null : failureKind;
        var backoff = success ? 0 : NetworkBackoffPolicy.DelaySeconds(failureKind, _failures);
        var seconds = Math.Max(Math.Max(IntervalSeconds, serverSeconds), Math.Max(backoff, Math.Max(1, requestCount) * IntervalSeconds));
        seconds = Math.Max(seconds, retryAfter?.TotalSeconds ?? 0);
        var input = new byte[_seed.Length + 4];
        _seed.CopyTo(input, 0);
        BinaryPrimitives.WriteInt32LittleEndian(input.AsSpan(_seed.Length), _attempt++);
        var jitter = BinaryPrimitives.ReadUInt32LittleEndian(SHA256.HashData(input)) % 12001 / 1000.0;
        return TimeSpan.FromSeconds(seconds + jitter);
    }
}
