using System.Collections.Generic;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>Foto read-only del estado del cliente para la ventana Debug.</summary>
    internal sealed class DebugSnapshot
    {
        // Versión + auto-update
        public string RunningVersion;
        public string AvailableVersion;
        public string MinimumVersion;
        public string UpdateStatus;      // OK / error

        // API + conexión
        public string ApiBaseUrl;
        public string LastHandshake;     // "12:33:52 (hace 8s)" | "Nunca"
        public string HandshakeStatus;   // OK / HTTP 4xx / error
        public string BackoffStatus;     // "activo hasta HH:mm:ss" | "no"

        // Cola + errores
        public int QueuePending;
        public IReadOnlyList<string> RecentIssues;

        // Web-blocking + Auth
        public bool WebBlockEnabled;
        public int WebBlockDomains;
        public bool PacActive;
        public string DeviceId;
        public string UserName;
        public bool HasToken;
    }
}
