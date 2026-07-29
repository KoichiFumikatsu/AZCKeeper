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
        /// <summary>true = RegistryPath es una subclave con valores "1".."n" que se leen como lista.</summary>
        public bool IsEnumeratedSubkey { get; }

        public SecurityControlDefinition(string key, string registryPath, string valueName)
            : this(key, registryPath, valueName, false) { }

        public SecurityControlDefinition(string key, string registryPath, string valueName, bool isEnumeratedSubkey)
        {
            Key = key;
            RegistryPath = registryPath;
            ValueName = valueName;
            IsEnumeratedSubkey = isEnumeratedSubkey;
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
            new SecurityControlDefinition("chrome.URLBlocklist",                @"SOFTWARE\Policies\Google\Chrome\URLBlocklist", null, true),
            new SecurityControlDefinition("chrome.URLAllowlist",                @"SOFTWARE\Policies\Google\Chrome\URLAllowlist", null, true),
            new SecurityControlDefinition("chrome.ExtensionInstallBlocklist",   @"SOFTWARE\Policies\Google\Chrome\ExtensionInstallBlocklist", null, true),
            new SecurityControlDefinition("chrome.ExtensionInstallAllowlist",   @"SOFTWARE\Policies\Google\Chrome\ExtensionInstallAllowlist", null, true),

            // Navegador — Edge
            new SecurityControlDefinition("edge.DownloadRestrictions",          @"SOFTWARE\Policies\Microsoft\Edge", "DownloadRestrictions"),
            new SecurityControlDefinition("edge.DeveloperToolsAvailability",    @"SOFTWARE\Policies\Microsoft\Edge", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("edge.BrowserSignin",                 @"SOFTWARE\Policies\Microsoft\Edge", "BrowserSignin"),
            new SecurityControlDefinition("edge.SyncDisabled",                  @"SOFTWARE\Policies\Microsoft\Edge", "SyncDisabled"),
            new SecurityControlDefinition("edge.IncognitoModeAvailability",     @"SOFTWARE\Policies\Microsoft\Edge", "IncognitoModeAvailability"),
            new SecurityControlDefinition("edge.PrintingEnabled",               @"SOFTWARE\Policies\Microsoft\Edge", "PrintingEnabled"),
            new SecurityControlDefinition("edge.PasswordManagerEnabled",        @"SOFTWARE\Policies\Microsoft\Edge", "PasswordManagerEnabled"),
            new SecurityControlDefinition("edge.URLBlocklist",                  @"SOFTWARE\Policies\Microsoft\Edge\URLBlocklist", null, true),
            new SecurityControlDefinition("edge.URLAllowlist",                  @"SOFTWARE\Policies\Microsoft\Edge\URLAllowlist", null, true),
            new SecurityControlDefinition("edge.ExtensionInstallBlocklist",     @"SOFTWARE\Policies\Microsoft\Edge\ExtensionInstallBlocklist", null, true),
            new SecurityControlDefinition("edge.ExtensionInstallAllowlist",     @"SOFTWARE\Policies\Microsoft\Edge\ExtensionInstallAllowlist", null, true),

            // Navegador — Brave
            new SecurityControlDefinition("brave.DownloadRestrictions",         @"SOFTWARE\Policies\BraveSoftware\Brave", "DownloadRestrictions"),
            new SecurityControlDefinition("brave.DeveloperToolsAvailability",   @"SOFTWARE\Policies\BraveSoftware\Brave", "DeveloperToolsAvailability"),
            new SecurityControlDefinition("brave.BrowserSignin",                @"SOFTWARE\Policies\BraveSoftware\Brave", "BrowserSignin"),
            new SecurityControlDefinition("brave.SyncDisabled",                 @"SOFTWARE\Policies\BraveSoftware\Brave", "SyncDisabled"),
            new SecurityControlDefinition("brave.IncognitoModeAvailability",    @"SOFTWARE\Policies\BraveSoftware\Brave", "IncognitoModeAvailability"),
            new SecurityControlDefinition("brave.PrintingEnabled",              @"SOFTWARE\Policies\BraveSoftware\Brave", "PrintingEnabled"),
            new SecurityControlDefinition("brave.PasswordManagerEnabled",       @"SOFTWARE\Policies\BraveSoftware\Brave", "PasswordManagerEnabled"),
            new SecurityControlDefinition("brave.URLBlocklist",                 @"SOFTWARE\Policies\BraveSoftware\Brave\URLBlocklist", null, true),
            new SecurityControlDefinition("brave.URLAllowlist",                 @"SOFTWARE\Policies\BraveSoftware\Brave\URLAllowlist", null, true),
            new SecurityControlDefinition("brave.ExtensionInstallBlocklist",    @"SOFTWARE\Policies\BraveSoftware\Brave\ExtensionInstallBlocklist", null, true),
            new SecurityControlDefinition("brave.ExtensionInstallAllowlist",    @"SOFTWARE\Policies\BraveSoftware\Brave\ExtensionInstallAllowlist", null, true),

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
