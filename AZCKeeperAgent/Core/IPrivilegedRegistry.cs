namespace AZCKeeperAgent.Core;

/// <summary>
/// Acceso al registro con privilegio (HKLM\SOFTWARE\Policies). Detrás de una interfaz
/// para que la lógica del agente —el auto-test y la aplicación de políticas— sea
/// testeable sin correr elevado. La implementación real (WinPrivilegedRegistry) usa
/// Microsoft.Win32.Registry; los tests usan un fake que simula "acceso denegado".
/// </summary>
public interface IPrivilegedRegistry
{
    /// <summary>Escribe un valor bajo HKLM\SOFTWARE\Policies\{subkey}. Lanza si no hay privilegio.</summary>
    void WriteValue(string subkeyPath, string name, object value);

    /// <summary>Lee un valor; null si no existe.</summary>
    object? ReadValue(string subkeyPath, string name);

    /// <summary>Borra un valor (best-effort).</summary>
    void DeleteValue(string subkeyPath, string name);

    /// <summary>Reemplaza por completo una subclave con valores numerados 1..n (URLBlocklist, etc.).</summary>
    void WriteEnumeratedSubkey(string subkeyPath, IReadOnlyList<string> values);
}
