using System.Runtime.Versioning;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Auto-instalación per-user, sin admin. El .exe publicado (single-file, self-contained:
/// el runtime .NET va embebido, no hay que instalarlo aparte) ES el instalador: si se
/// corre desde fuera del directorio de instalación (una descarga, un USB), se copia a
/// %APPDATA%\AZCKeeper4\app junto con el helper de update, registra el arranque y relanza
/// la copia instalada. Nada de Program Files, UAC ni MSI.
///
/// La detección de "¿ya estoy instalado?" es pura (IsInInstallDir) para testearla; la copia
/// es I/O real (InstallFrom), verificable contra directorios temporales.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class Installer
{
    public const string ExeName = "AZCKeeper4.exe";
    public const string UpdaterName = "AZCKeeperUpdater.exe";

    private readonly string _installDir;

    public Installer(string? installDir = null) => _installDir = installDir ?? K4Paths.InstallDir;

    public string InstalledExePath => Path.Combine(_installDir, ExeName);

    /// <summary>¿El exe actual ya vive en el directorio de instalación? Comparación normalizada.</summary>
    public static bool IsInInstallDir(string currentExePath, string installDir)
    {
        var curDir = Path.GetFullPath(Path.GetDirectoryName(currentExePath) ?? ".").TrimEnd('\\', '/');
        var inst = Path.GetFullPath(installDir).TrimEnd('\\', '/');
        return string.Equals(curDir, inst, StringComparison.OrdinalIgnoreCase);
    }

    /// <summary>
    /// Copia el exe actual al directorio de instalación (sobrescribiendo) y deja al lado el
    /// helper de update. El helper puede venir de dos fuentes: un archivo al lado del exe
    /// actual, o —para un Setup de un solo archivo— un stream embebido en el propio cliente.
    /// Devuelve la ruta del exe instalado. No lanza procesos ni registra el arranque: eso lo
    /// orquesta el caller para poder secuenciarlo.
    /// </summary>
    public string InstallFrom(string currentExePath, Stream? embeddedUpdater = null)
    {
        Directory.CreateDirectory(_installDir);

        var destExe = InstalledExePath;
        File.Copy(currentExePath, destExe, overwrite: true);

        var destUpdater = Path.Combine(_installDir, UpdaterName);
        var srcUpdater = Path.Combine(Path.GetDirectoryName(currentExePath) ?? ".", UpdaterName);
        if (File.Exists(srcUpdater))
        {
            File.Copy(srcUpdater, destUpdater, overwrite: true);
        }
        else if (embeddedUpdater is not null)
        {
            // Setup de un solo archivo: el updater viaja embebido dentro del cliente.
            using var f = File.Create(destUpdater);
            embeddedUpdater.CopyTo(f);
        }

        return destExe;
    }
}
