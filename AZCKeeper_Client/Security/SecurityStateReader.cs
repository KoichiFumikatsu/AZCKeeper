using System;
using System.Collections.Generic;
using AZCKeeper_Cliente.Contracts;
using AZCKeeper_Cliente.Logging;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>
    /// Lee de HKLM el estado de los controles del catalogo. SOLO LECTURA:
    /// leer el registro no requiere privilegio elevado. Quien escribe es
    /// AZCKeeperAgent (Fase 1).
    /// </summary>
    internal static class SecurityStateReader
    {
        public static Dictionary<string, SecurityControlState> Read()
        {
            var raw = new Dictionary<string, object>();

            foreach (var def in SecurityControls.All)
            {
                try
                {
                    using var key = Registry.LocalMachine.OpenSubKey(def.RegistryPath, writable: false);
                    if (key == null) continue;

                    object value = key.GetValue(def.ValueName);
                    if (value != null) raw[def.Key] = value;
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, $"SecurityStateReader: error leyendo {def.Key}.");
                }
            }

            return SecurityControls.Evaluate(raw);
        }
    }
}
