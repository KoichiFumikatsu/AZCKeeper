using System.Collections.Generic;
using AZCKeeper_Cliente.Contracts;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>Definicion de un control: donde vive en el registro.</summary>
    internal sealed class SecurityControlDefinition
    {
        public string Key { get; }
        public string RegistryPath { get; }
        public string ValueName { get; }

        public SecurityControlDefinition(string key, string registryPath, string valueName)
        {
            Key = key;
            RegistryPath = registryPath;
            ValueName = valueName;
        }
    }

    /// <summary>
    /// Catalogo de controles del Modulo de Seguridad y evaluacion del estado leido.
    /// La evaluacion es pura: no toca el registro, para poder testearla.
    /// </summary>
    internal static class SecurityControls
    {
        public static readonly IReadOnlyList<SecurityControlDefinition> All = new List<SecurityControlDefinition>
        {
            // Navegador — Chrome
            new SecurityControlDefinition("chrome.DownloadRestrictions",        @"SOFTWARE\Policies\Google\Chrome", "DownloadRestrictions"),
            new SecurityControlDefinition("chrome.DeveloperToolsAvailability",  @"SOFTWARE\Policies\Google\Chrome", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("chrome.BrowserSignin",               @"SOFTWARE\Policies\Google\Chrome", "BrowserSignin"),
            new SecurityControlDefinition("chrome.SyncDisabled",                @"SOFTWARE\Policies\Google\Chrome", "SyncDisabled"),
            new SecurityControlDefinition("chrome.IncognitoModeAvailability",   @"SOFTWARE\Policies\Google\Chrome", "IncognitoModeAvailability"),
            new SecurityControlDefinition("chrome.PrintingEnabled",             @"SOFTWARE\Policies\Google\Chrome", "PrintingEnabled"),
            new SecurityControlDefinition("chrome.PasswordManagerEnabled",      @"SOFTWARE\Policies\Google\Chrome", "PasswordManagerEnabled"),

            // Navegador — Edge
            new SecurityControlDefinition("edge.DownloadRestrictions",          @"SOFTWARE\Policies\Microsoft\Edge", "DownloadRestrictions"),
            new SecurityControlDefinition("edge.DeveloperToolsAvailability",    @"SOFTWARE\Policies\Microsoft\Edge", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("edge.BrowserSignin",                 @"SOFTWARE\Policies\Microsoft\Edge", "BrowserSignin"),
            new SecurityControlDefinition("edge.SyncDisabled",                  @"SOFTWARE\Policies\Microsoft\Edge", "SyncDisabled"),
            new SecurityControlDefinition("edge.InPrivateModeAvailability",     @"SOFTWARE\Policies\Microsoft\Edge", "InPrivateModeAvailability"),

            // Sistema
            new SecurityControlDefinition("system.UsbStorStart",                @"SYSTEM\CurrentControlSet\Services\USBSTOR", "Start"),
            new SecurityControlDefinition("system.RemovableStorageDenyAll",     @"SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices", "Deny_All"),
            new SecurityControlDefinition("system.OneDriveDisablePersonalSync", @"SOFTWARE\Policies\Microsoft\OneDrive", "DisablePersonalSync"),
            new SecurityControlDefinition("system.ConsentPromptBehaviorUser",   @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "ConsentPromptBehaviorUser"),
            new SecurityControlDefinition("system.PromptOnSecureDesktop",       @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "PromptOnSecureDesktop"),
            new SecurityControlDefinition("system.EnableLUA",                   @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System", "EnableLUA"),

            // SRP
            new SecurityControlDefinition("srp.DefaultLevel",                   @"SOFTWARE\Policies\Microsoft\Windows\Safer\CodeIdentifiers", "DefaultLevel"),
        };

        /// <summary>
        /// Convierte los valores crudos leidos del registro en el mapa de estado del reporte.
        /// Una clave ausente en <paramref name="raw"/> se reporta como Present=false.
        /// </summary>
        public static Dictionary<string, SecurityControlState> Evaluate(IReadOnlyDictionary<string, object> raw)
        {
            var result = new Dictionary<string, SecurityControlState>();
            foreach (var def in All)
            {
                bool present = raw != null && raw.TryGetValue(def.Key, out object value) && value != null;
                result[def.Key] = new SecurityControlState
                {
                    Present = present,
                    Value = present ? raw[def.Key] : null
                };
            }
            return result;
        }
    }
}
