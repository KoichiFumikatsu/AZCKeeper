namespace AZCKeeperAgent.Core;

/// <summary>
/// Traduce la política del Anillo 1 (navegador) a la lista concreta de controles HKLM que
/// el PolicyEnforcer aplica. Valores EXACTOS de la Fase 0 (spec modulo-seguridad §7.1):
///   DownloadRestrictions = 3           (bloquea todas las descargas)
///   URLBlocklist\1..n     = dominios   (subclave enumerada)
///   ExtensionInstallBlocklist\1 = *    (cierra todas las extensiones; tapa el hueco Urban VPN)
///   ExtensionInstallAllowlist\1..n = IDs aprobados
///
/// Se aplica a los tres navegadores soportados. Cada vendor usa su propia subclave bajo
/// HKLM\SOFTWARE\Policies (el WinPrivilegedRegistry ya ancla esa raíz), así que aquí solo
/// van las rutas relativas vendor\browser.
///
/// Anillo 2 (USB, UAC, SRP, CMD) NO está aquí: vive fuera de SOFTWARE\Policies y SRP puede
/// tumbar la operación — se diseña aparte, al final, con revisión (spec §7.2/§7.3, Fase 5).
/// </summary>
public static class ControlCatalog
{
    /// <summary>Un navegador soportado: código corto para el reporte y su ruta HKLM relativa.</summary>
    private sealed record Browser(string Code, string PolicyPath);

    private static readonly Browser[] Browsers =
    {
        new("chrome", @"Google\Chrome"),
        new("edge",   @"Microsoft\Edge"),
        new("brave",  @"BraveSoftware\Brave"),
    };

    public static IReadOnlyList<DesiredControl> BrowserRing(BrowserPolicy p)
    {
        var controls = new List<DesiredControl>();

        foreach (var b in Browsers)
        {
            if (p.BlockDownloads)
                controls.Add(new DesiredControl(
                    $"{b.Code}.DownloadRestrictions", b.PolicyPath, "DownloadRestrictions", 3));

            if (p.BlockedDomains.Count > 0)
                controls.Add(new DesiredControl(
                    $"{b.Code}.URLBlocklist", b.PolicyPath + @"\URLBlocklist", null, null,
                    IsEnumeratedSubkey: true, ListValues: p.BlockedDomains));

            if (p.BlockAllExtensions)
            {
                // '*' bloquea toda extensión; el allowlist es la escotilla para lo aprobado.
                controls.Add(new DesiredControl(
                    $"{b.Code}.ExtensionInstallBlocklist", b.PolicyPath + @"\ExtensionInstallBlocklist",
                    null, null, IsEnumeratedSubkey: true, ListValues: new[] { "*" }));

                if (p.AllowedExtensionIds.Count > 0)
                    controls.Add(new DesiredControl(
                        $"{b.Code}.ExtensionInstallAllowlist", b.PolicyPath + @"\ExtensionInstallAllowlist",
                        null, null, IsEnumeratedSubkey: true, ListValues: p.AllowedExtensionIds));
            }
        }

        return controls;
    }
}
