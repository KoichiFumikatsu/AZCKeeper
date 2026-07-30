using System.Runtime.Versioning;
using AZCKeeperAgent.Core;
using Microsoft.Win32;

namespace AZCKeeperAgent.Platform;

/// <summary>
/// Acceso real a HKLM\SOFTWARE\Policies con privilegio. Requiere que el proceso corra
/// elevado (el servicio como LocalSystem). Si NO está elevado, OpenSubKey(writable:true)
/// o SetValue lanzan UnauthorizedAccessException — que es justo lo que el auto-test
/// espera para reportar "sin privilegio".
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WinPrivilegedRegistry : IPrivilegedRegistry
{
    private const string Root = @"SOFTWARE\Policies\";

    public void WriteValue(string subkeyPath, string name, object value)
    {
        using var key = Registry.LocalMachine.CreateSubKey(Root + subkeyPath, writable: true)
            ?? throw new UnauthorizedAccessException("no se pudo abrir " + subkeyPath);
        key.SetValue(name, value, KindOf(value));
    }

    public object? ReadValue(string subkeyPath, string name)
    {
        using var key = Registry.LocalMachine.OpenSubKey(Root + subkeyPath, writable: false);
        return key?.GetValue(name);
    }

    public void DeleteValue(string subkeyPath, string name)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(Root + subkeyPath, writable: true);
            key?.DeleteValue(name, throwOnMissingValue: false);
        }
        catch { /* best-effort */ }
    }

    public void WriteEnumeratedSubkey(string subkeyPath, IReadOnlyList<string> values)
    {
        // Recrea limpia: borra el árbol y reescribe 1..n. Así quitar un dominio lo elimina.
        try { Registry.LocalMachine.DeleteSubKeyTree(Root + subkeyPath, throwOnMissingSubKey: false); } catch { }
        using var key = Registry.LocalMachine.CreateSubKey(Root + subkeyPath, writable: true)
            ?? throw new UnauthorizedAccessException("no se pudo abrir " + subkeyPath);
        for (int i = 0; i < values.Count; i++)
            key.SetValue((i + 1).ToString(), values[i], RegistryValueKind.String);
    }

    private static RegistryValueKind KindOf(object value)
        => value is int ? RegistryValueKind.DWord : RegistryValueKind.String;
}
