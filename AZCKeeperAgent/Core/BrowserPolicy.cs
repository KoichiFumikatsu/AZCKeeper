namespace AZCKeeperAgent.Core;

/// <summary>
/// La política del Anillo 1 (navegador) que el servidor envía y el agente traduce a
/// controles HKLM. Es ~90% del riesgo real de exfiltración según la Fase 0: con
/// descargas bloqueadas, dominios de nube personal en la lista negra y extensiones
/// cerradas, el navegador deja de ser una vía de fuga.
///
/// Todo vive bajo HKLM\SOFTWARE\Policies\&lt;vendor&gt;\&lt;browser&gt;, que solo escribe
/// admin/SYSTEM — por eso en Keeper 3 fallaba en HKCU (el usuario lo revertía).
/// </summary>
public sealed record BrowserPolicy(
    bool BlockDownloads,
    IReadOnlyList<string> BlockedDomains,
    bool BlockAllExtensions,
    IReadOnlyList<string> AllowedExtensionIds)
{
    public static BrowserPolicy Empty { get; } =
        new(false, Array.Empty<string>(), false, Array.Empty<string>());
}
