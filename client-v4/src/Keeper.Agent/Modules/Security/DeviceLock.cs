using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Security;

public sealed record PinVerifier(byte[] Salt, byte[] Hash)
{
    public Guid TenantId { get; init; }
    public long Revision { get; init; }
    public static PinVerifier Create(string pin)
    {
        if (pin.Length is < 8 or > 32 || pin.Any(c => !char.IsAsciiDigit(c))) throw new ArgumentException("invalid_pin");
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
        => await SaveProtectedAsync(path, Create(pin), ct);
    public static async Task SaveProtectedAsync(string path, PinVerifier verifier, CancellationToken ct)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        var bytes = JsonSerializer.SerializeToUtf8Bytes(verifier);
        try { await AtomicFile.WriteAsync(path, ProtectedData.Protect(bytes, null, DataProtectionScope.CurrentUser), ct); }
        finally { CryptographicOperations.ZeroMemory(bytes); }
    }
}

public sealed record LockState(bool Locked, int Attempts, DateTimeOffset RetryAt, IReadOnlyList<LogEntry>? PendingUnlocks = null,
    TimeSpan? CooldownRemaining = null);
public sealed class DeviceLock(string statePath, PinVerifier? verifier = null, string? verifierPath = null) : ModuleBase
{
    public override string Name => "DeviceLock";
    private readonly SemaphoreSlim _gate = new(1, 1);
    private LockState _state = new(false, 0, DateTimeOffset.MinValue);
    private bool _ready;
    private long _cooldownTimestamp;
    public bool Locked => _state.Locked;
    public bool PinAllowed => verifier is not null;
    private TimeSpan Remaining => (_state.CooldownRemaining ?? TimeSpan.Zero) - Context.Clock.GetElapsedTime(_cooldownTimestamp);
    public PinStatus PinStatus => new(_state.Attempts, Remaining > TimeSpan.Zero ? Context.Clock.GetUtcNow() + Remaining : null);

    public async Task TryApplyVerifierAsync(UnlockPinVerifier settings, Guid tenant, CancellationToken ct)
    {
        try { await ApplyVerifierAsync(settings, tenant, ct); }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex) { Context.Log($"unlock_pin_apply_failed: {ex.GetType().Name}"); }
    }

    public async Task ApplyVerifierAsync(UnlockPinVerifier settings, Guid tenant, CancellationToken ct)
    {
        if (tenant == Guid.Empty || settings.Revision < 0) throw new InvalidDataException("invalid_pin_verifier");
        PinVerifier? next = null;
        if (settings.Revision == 0)
        {
            if (settings.Salt is not null || settings.Hash is not null) throw new InvalidDataException("invalid_pin_verifier");
        }
        else
        {
            byte[] salt, hash;
            try { salt = Convert.FromBase64String(settings.Salt ?? ""); hash = Convert.FromBase64String(settings.Hash ?? ""); }
            catch (FormatException) { throw new InvalidDataException("invalid_pin_verifier"); }
            if (salt.Length != 32 || hash.Length != 32) throw new InvalidDataException("invalid_pin_verifier");
            next = new(salt, hash) { TenantId = tenant, Revision = settings.Revision };
        }
        await _gate.WaitAsync(ct);
        try
        {
            if (next is null && verifier is null) return;
            if (next is not null && verifier is not null && next.TenantId == verifier.TenantId && next.Revision == verifier.Revision
                && next.Salt.SequenceEqual(verifier.Salt) && next.Hash.SequenceEqual(verifier.Hash)) return;
            if (verifierPath is not null)
            {
                if (next is null) File.Delete(verifierPath);
                else await PinVerifier.SaveProtectedAsync(verifierPath, next, ct);
            }
            verifier = next;
        }
        finally { _gate.Release(); }
    }
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        if (File.Exists(statePath))
        {
            _state = _state with { Locked = true };
            _state = JsonSerializer.Deserialize<LockState>(await File.ReadAllBytesAsync(statePath, ctx.StoppingToken))
                ?? throw new InvalidDataException("invalid_lock_state");
        }
        // El estado anterior sin contador debe cumplir la espera completa, sin confiar en su fecha.
        _state = _state with { CooldownRemaining = _state.CooldownRemaining ?? (_state.Attempts >= 5 ? TimeSpan.FromMinutes(15) : TimeSpan.Zero) };
        _cooldownTimestamp = ctx.Clock.GetTimestamp();
        _ready = true;
    }
    public async Task SetLockedAsync(bool locked, CancellationToken ct)
    {
        if (!_ready) throw new InvalidOperationException("lock_store_unavailable");
        await _gate.WaitAsync(ct);
        try
        {
            await CheckpointCooldownAsync(ct);
            await SaveAsync(_state.Locked == locked ? _state : _state with { Locked = locked, Attempts = 0, RetryAt = DateTimeOffset.MinValue, CooldownRemaining = TimeSpan.Zero }, ct);
        }
        finally { _gate.Release(); }
    }
    public async Task<bool> ValidatePinAsync(string pin, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (!_ready || !Locked || verifier is null) return false;
            await CheckpointCooldownAsync(ct);
            if (Remaining > TimeSpan.Zero) return false;
            var now = Context.Clock.GetUtcNow();
            var attempts = _state.Attempts;
            if (pin.Length is >= 8 and <= 32 && pin.All(char.IsAsciiDigit) && verifier.Verify(pin))
            {
                var entry = new LogEntry { EventId = Guid.NewGuid(), At = now, Level = LogEntryLevel.Info,
                    Component = Name, Code = "local_unlock" };
                await SaveAsync(new(false, 0, DateTimeOffset.MinValue, [.. _state.PendingUnlocks ?? [], entry]), ct);
                return true;
            }
            attempts = Math.Min(attempts + 1, 5);
            await SaveAsync(_state with { Attempts = attempts, RetryAt = DateTimeOffset.MinValue,
                CooldownRemaining = attempts == 5 ? TimeSpan.FromMinutes(15) : TimeSpan.Zero }, ct);
            return false;
        }
        finally { _gate.Release(); }
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            await CheckpointCooldownAsync(ct);
            foreach (var entry in _state.PendingUnlocks ?? []) await Context.Outbox.EnqueueAsync(entry, ct);
            if (_state.PendingUnlocks is { Count: > 0 }) await SaveAsync(_state with { PendingUnlocks = [] }, ct);
        }
        finally { _gate.Release(); }
    }
    private async Task CheckpointCooldownAsync(CancellationToken ct)
    {
        if (_state.CooldownRemaining is not { } saved || saved <= TimeSpan.Zero) return;
        var remaining = Remaining;
        await SaveAsync(_state with { CooldownRemaining = remaining > TimeSpan.Zero ? remaining : TimeSpan.Zero,
            Attempts = remaining > TimeSpan.Zero ? _state.Attempts : 0, RetryAt = DateTimeOffset.MinValue }, ct);
    }
    private async Task SaveAsync(LockState state, CancellationToken ct)
    {
        await AtomicFile.WriteAsync(statePath, JsonSerializer.SerializeToUtf8Bytes(state), ct);
        _state = state;
        _cooldownTimestamp = Context.Clock.GetTimestamp();
    }
    public override ModuleSnapshot Snapshot() => base.Snapshot() with { State = Locked ? "locked" : "unlocked", ErrorCode = Locked ? "Bloqueado" : "Desbloqueado" };
    public override Task ShutdownAsync() { _gate.Dispose(); return Task.CompletedTask; }
}
