using System.Text.Json;
using System.Text.Json.Serialization;

namespace Keeper.Shared.Diagnostics;

// Marca de salud del agente en {data}\health.json. La escribe el agente y la lee el bootstrapper para decidir si
// una version recien instalada funciona (vuelta atras automatica) y el programa de rescate para saber si el agente
// lleva demasiado sin sincronizar.
public sealed record AgentHealth(
    [property: JsonPropertyName("agent_version")] string AgentVersion,
    [property: JsonPropertyName("alive_at")] DateTimeOffset AliveAt,
    [property: JsonPropertyName("last_sync_ok")] DateTimeOffset? LastSyncOk)
{
    public static AgentHealth? Read(string path)
    {
        try { return File.Exists(path) ? JsonSerializer.Deserialize<AgentHealth>(File.ReadAllBytes(path)) : null; }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException) { return null; }
    }

    public void Write(string path)
    {
        var temporary = path + ".tmp";
        File.WriteAllBytes(temporary, JsonSerializer.SerializeToUtf8Bytes(this));
        File.Move(temporary, path, overwrite: true);
    }
}

public sealed class AgentHealthFile(string path, string agentVersion)
{
    private static readonly TimeSpan AliveEvery = TimeSpan.FromSeconds(30);
    private DateTimeOffset _lastAlive = DateTimeOffset.MinValue;
    private DateTimeOffset? _lastSyncOk = AgentHealth.Read(path) is { } previous && previous.AgentVersion == agentVersion ? previous.LastSyncOk : null;
    public string Path => path;

    public void Alive(DateTimeOffset now)
    {
        if (now - _lastAlive < AliveEvery) return;
        Save(now);
    }

    public void SyncOk(DateTimeOffset now) { _lastSyncOk = now; Save(now); }

    private void Save(DateTimeOffset now)
    {
        try { new AgentHealth(agentVersion, now, _lastSyncOk).Write(path); _lastAlive = now; }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
    }
}
