namespace Keeper.Agent.Storage;

// Version del agente en formato Major.Minor.Build, tomada del ensamblado (build-installer pasa -p:Version).
public static class AgentIdentity
{
    public static Version Version { get; } = typeof(AgentIdentity).Assembly.GetName().Version is { } v
        ? new Version(v.Major, v.Minor, Math.Max(0, v.Build)) : new Version(4, 0, 0);
}

// El backend solo registra agent_version en /client/login, y un agente actualizado reusa su token: sin esto,
// el panel mostraria la version anterior hasta que el token expire. Recuerda la ultima version que arranco
// y avisa cuando cambia, para que el host descarte el token y fuerce un login nuevo.
public static class AgentVersionMarker
{
    public static bool Changed(string path, Version current)
    {
        var text = current.ToString(3);
        string? previous = null;
        try { if (File.Exists(path)) previous = File.ReadAllText(path).Trim(); }
        catch (IOException) { }
        if (previous == text) return false;
        var temporary = path + ".tmp";
        File.WriteAllText(temporary, text);
        File.Move(temporary, path, overwrite: true);
        return true;
    }
}
