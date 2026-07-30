using System.Runtime.Versioning;
using Microsoft.Win32;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Inicio automático del cliente vía HKCU\...\Run. Es per-user y NO requiere admin — es la
/// razón por la que el cliente se instala en el perfil del usuario y no en Program Files.
/// El valor apunta al exe instalado (%APPDATA%\AZCKeeper4\app), con fallback al proceso
/// actual la primera vez (antes de que exista la carpeta de instalación).
///
/// Nombre de valor "AZCKeeper4" (DISTINTO al "AZCKeeper_Cliente" de K3) para que ambos
/// puedan estar registrados durante la migración sin pisarse.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class StartupManager
{
    public const string AppName = "AZCKeeper4";
    private const string DefaultRunPath = @"SOFTWARE\Microsoft\Windows\CurrentVersion\Run";

    private readonly string _runPath;
    private readonly Func<string> _exePath;

    /// <param name="runSubkeyPath">Subclave de HKCU. Default = la Run real; los tests pasan una de prueba.</param>
    /// <param name="exePath">Ruta del exe a registrar. Default = el instalado, o el proceso actual.</param>
    public StartupManager(string? runSubkeyPath = null, Func<string>? exePath = null)
    {
        _runPath = runSubkeyPath ?? DefaultRunPath;
        _exePath = exePath ?? DefaultExePath;
    }

    public void EnableStartup()
    {
        using var key = Registry.CurrentUser.CreateSubKey(_runPath, writable: true);
        key?.SetValue(AppName, $"\"{_exePath()}\"");
    }

    public void DisableStartup()
    {
        using var key = Registry.CurrentUser.OpenSubKey(_runPath, writable: true);
        key?.DeleteValue(AppName, throwOnMissingValue: false);
    }

    public bool IsEnabled()
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(_runPath, writable: false);
            return key?.GetValue(AppName) != null;
        }
        catch { return false; }
    }

    /// <summary>El valor registrado (con comillas), o null si no está.</summary>
    public string? RegisteredValue()
    {
        using var key = Registry.CurrentUser.OpenSubKey(_runPath, writable: false);
        return key?.GetValue(AppName) as string;
    }

    private static string DefaultExePath()
    {
        if (File.Exists(K4Paths.AppExe)) return K4Paths.AppExe;
        // Primera vez (aún no instalado en %APPDATA%): registrar la ubicación actual.
        return Environment.ProcessPath ?? K4Paths.AppExe;
    }
}
