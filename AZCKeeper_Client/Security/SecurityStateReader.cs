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

                    if (def.IsEnumeratedSubkey)
                    {
                        // Subclave con valores "1".."n". El orden de GetValueNames() NO esta
                        // garantizado, y ordenar importa por dos razones: la lista de politicas
                        // Chromium esta numerada (el orden es semantico), y SecurityReportCache
                        // hashea el string[] tal cual — un orden inestable produciria un hash
                        // distinto sin que el estado haya cambiado, disparando POST inutiles.
                        string[] nombres = key.GetValueNames();
                        Array.Sort(nombres, CompararNombreNumerico);

                        var items = new List<string>();
                        foreach (string name in nombres)
                        {
                            object v = key.GetValue(name);
                            if (v != null) items.Add(Convert.ToString(v));
                        }
                        if (items.Count > 0) raw[def.Key] = items.ToArray();
                    }
                    else
                    {
                        object value = key.GetValue(def.ValueName);
                        if (value != null) raw[def.Key] = value;
                    }
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, $"SecurityStateReader: error leyendo {def.Key}.");
                }
            }

            return SecurityControls.Evaluate(raw);
        }

        /// <summary>
        /// Ordena "1","2","10" como numeros y no como texto (que daria "1","10","2").
        /// Los nombres no numericos van al final, en orden ordinal.
        /// </summary>
        internal static int CompararNombreNumerico(string a, string b)
        {
            bool aEsNumero = int.TryParse(a, out int ia);
            bool bEsNumero = int.TryParse(b, out int ib);

            if (aEsNumero && bEsNumero) return ia.CompareTo(ib);
            if (aEsNumero) return -1;
            if (bEsNumero) return 1;
            return string.CompareOrdinal(a, b);
        }
    }
}
