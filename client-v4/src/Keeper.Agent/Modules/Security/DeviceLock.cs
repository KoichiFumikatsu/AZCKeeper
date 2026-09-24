using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Modules.Security;

public sealed record PinVerifier(byte[] Salt, byte[] Hash)
{
    public static PinVerifier Create(string pin)
    {
        if (pin.Length is < 6 or > 32 || pin.Any(c => !char.IsAsciiDigit(c))) throw new ArgumentException("invalid_pin");
        var salt = RandomNumberGenerator.GetBytes(32);
        return new(salt, Rfc2898DeriveBytes.Pbkdf2(pin, salt, 210000, HashAlgorithmName.SHA256, 32));
    }
    public bool Verify(string pin)
    {
        var hash = Rfc2898DeriveBytes.Pbkdf2(pin, Salt, 210000, HashAlgorithmName.SHA256, 32);
        try { return CryptographicOperations.FixedTimeEquals(hash, Hash); }
        finally { CryptographicOperations.ZeroMemory(hash); }
    }
    public static PinVerifier? LoadProtected(string path)
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(path)) return null;
        var bytes = ProtectedData.Unprotect(File.ReadAllBytes(path), null, DataProtectionScope.CurrentUser);
        try { return JsonSerializer.Deserialize<PinVerifier>(bytes); }
        finally { CryptographicOperations.ZeroMemory(bytes); }
    }
    public static async Task SaveProtectedAsync(string path, string pin, CancellationToken ct)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        var bytes = JsonSerializer.SerializeToUtf8Bytes(Create(pin));
        try { await AtomicFile.WriteAsync(path, ProtectedData.Protect(bytes, null, DataProtectionScope.CurrentUser), ct); }
        finally { CryptographicOperations.ZeroMemory(bytes); }
    }
}

public sealed record LockState(bool Locked, int Attempts, DateTimeOffset RetryAt);
public sealed class DeviceLock(string statePath, PinVerifier? verifier = null) : ModuleBase
{
    public override string Name => "DeviceLock";
    private readonly SemaphoreSlim _gate = new(1, 1);
    private LockState _state = new(false, 0, DateTimeOffset.MinValue);
    private bool _ready;
    public bool Locked => _state.Locked;
    public bool PinAllowed => verifier is not null;
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        if (File.Exists(statePath))
        {
            _state = _state with { Locked = true };
            _state = JsonSerializer.Deserialize<LockState>(await File.ReadAllBytesAsync(statePath, ctx.StoppingToken))
                ?? throw new InvalidDataException("invalid_lock_state");
        }
        _ready = true;
    }
    public async Task SetLockedAsync(bool locked, CancellationToken ct)
    {
        if (!_ready) throw new InvalidOperationException("lock_store_unavailable");
        await _gate.WaitAsync(ct);
        try { await SaveAsync(_state.Locked == locked ? _state : new(locked, 0, DateTimeOffset.MinValue), ct); }
        finally { _gate.Release(); }
    }
    public async Task<bool> ValidatePinAsync(string pin, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var now = Context.Clock.GetUtcNow();
            if (!_ready || !Locked || verifier is null || now < _state.RetryAt || pin.Length is < 6 or > 32 || pin.Any(c => !char.IsAsciiDigit(c))) return false;
            if (verifier.Verify(pin))
            {
                await SaveAsync(new(false, 0, DateTimeOffset.MinValue), ct);
                return true;
            }
            var attempts = Math.Min(_state.Attempts + 1, 12);
            await SaveAsync(_state with { Attempts = attempts, RetryAt = now.AddSeconds(Math.Min(900, Math.Pow(2, attempts))) }, ct);
            return false;
        }
        finally { _gate.Release(); }
    }
    private async Task SaveAsync(LockState state, CancellationToken ct)
    {
        await AtomicFile.WriteAsync(statePath, JsonSerializer.SerializeToUtf8Bytes(state), ct);
        _state = state;
    }
    public override ModuleSnapshot Snapshot() => base.Snapshot() with { State = Locked ? "locked" : "unlocked" };
    public override Task ShutdownAsync() { _gate.Dispose(); return Task.CompletedTask; }
}
