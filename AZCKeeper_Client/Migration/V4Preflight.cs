using System;

namespace AZCKeeper_Cliente.Migration
{
    /// <summary>Estado observado sin elevar antes de intentar instalar Keeper v4.</summary>
    internal sealed record V4PreflightResult(int? EnableLua, int? ConsentPromptBehaviorAdmin, int? SmartAppControl,
        bool IsAdministrator, V4ServiceState Service)
    {
        public const string UacPath = @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System";
        public const string SacPath = @"SYSTEM\CurrentControlSet\Control\CI\Policy";

        /// <summary>
        /// null = se puede intentar; si no, el motivo para no intentar en este arranque.
        /// Smart App Control encendido (1) bloquea el bootstrapper sin firma Authenticode. Sin admin, runas pediria
        /// credenciales de otra cuenta. ConsentPromptBehaviorAdmin 1/3 pide credenciales en vez de consentimiento.
        /// Los demas valores (0 sin aviso, 2/5 consentimiento, 4 consentimiento en escritorio normal) se aceptan.
        /// </summary>
        public string SkipReason()
        {
            if (SmartAppControl == 1) return "smart_app_control_on";
            if (!IsAdministrator) return "user_not_admin";
            if (ConsentPromptBehaviorAdmin is 1 or 3) return "uac_requires_credentials";
            return null;
        }

        public string Describe() =>
            $"uac_lua={Show(EnableLua)} uac_cpba={Show(ConsentPromptBehaviorAdmin)} sac={Show(SmartAppControl)} " +
            $"admin={(IsAdministrator ? "yes" : "no")} service={Service.ToString().ToLowerInvariant()}";

        private static string Show(int? value) => value?.ToString() ?? "none";

        public static V4PreflightResult Read(IV4SystemProbe probe, string serviceName) => new V4PreflightResult(
            probe.ReadHklmDword(UacPath, "EnableLUA"),
            probe.ReadHklmDword(UacPath, "ConsentPromptBehaviorAdmin"),
            probe.ReadHklmDword(SacPath, "VerifiedAndReputablePolicyState"),
            probe.UserIsAdministrator(),
            probe.QueryService(serviceName));
    }
}
