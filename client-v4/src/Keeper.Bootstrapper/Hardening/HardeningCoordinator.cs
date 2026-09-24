using System.Security;
using System.Text.Json;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Hardening;

public sealed class HardeningCoordinator(ILocalAccounts accounts, ISecurityPolicy policy,
    IHardeningSecret secret, IHardeningStateStore store, Action<string> log) : IHardeningRunner
{
    public int Run(HardeningConfig config, bool dryRun, bool explicitCommand, bool undo = false)
    {
        config.Validate();
        if (dryRun)
        {
            log("DRY-RUN: sin elevacion, lectura de credenciales ni acceso a cuentas/politicas/registro.");
            if (!undo && !explicitCommand && config.HardeningMode == HardeningMode.Panel)
                log("Panel: esperar comando explicito --harden.");
            else if (undo)
            {
                log("1. Restaurar Administradores S-1-5-32-544 a los SIDs del journal; verificar.");
                log("2. Restaurar SeDenyNetworkLogonRight / S-1-5-114 y visibilidad previos.");
                log($"3. Quitar membresias Users agregadas; reportar rollback OK, modo Panel; {config.AdminName} permanece.");
            }
            else
            {
                log($"1. Crear/verificar propiedad de {config.AdminName}; clave compartida protegida -> NetUserAdd/NetUserSetInfo; grupo S-1-5-32-544.");
                log("2. Verificar cuenta habilitada, membresia admin y LogonUser INTERACTIVE; si falla, abortar.");
                log("3. LsaAddAccountRights: S-1-5-114 -> SeDenyNetworkLogonRight; verificar.");
                log($"4. Resolver sesiones WTS activas; sin sesion: {config.NoSessionTarget}. Guardar journal; agregar S-1-5-32-545 y retirar S-1-5-32-544 solo con admin operativo.");
                log("5. Revalidar admin operativo; preservar RID-500 deshabilitado. Fallo -> restaurar membresias.");
                log($"6. HKLM64 Winlogon\\SpecialAccounts\\UserList: {config.AdminName}=DWORD 0; verificar.");
                log("7. Persistir estado para SecurityReport; fallo -> rollback y Panel. Tokens actuales requieren nuevo inicio de sesion.");
            }
            log("DRY-RUN finalizado: 0 mutaciones.");
            return 0;
        }

        using var lease = store.Acquire();
        var state = store.Read() ?? new HardeningState { Mode = config.HardeningMode, AdminName = config.AdminName };
        if (!undo && !explicitCommand && state.Mode == HardeningMode.Panel)
        {
            Publish(state);
            return 0;
        }
        if (undo) return Undo(state);
        if (state.RecoveryRequired)
        {
            log("recovery_required: ejecutar --unharden antes de reintentar.");
            return 1;
        }
        if (state.AdminSid is not null && !state.AdminName.Equals(config.AdminName, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("managed_admin_name_conflict");
        if (state.Status == "hardened") { log("already_hardened; --unharden antes de cambiar destinos."); return 0; }

        var requestedMode = config.HardeningMode;
        var step = 1;
        try
        {
            // Panel is durable BEFORE mutation, so a crash cannot repeat Auto demotion.
            state = state with { Mode = HardeningMode.Panel, Status = "running", Step = step, ErrorCode = null,
                RestoreAdminSids = [], AddedUsersSids = [], PreviousNetworkDeny = null, VisibilityCaptured = false, PreviousVisibility = null };
            Publish(state);
            var inventory = accounts.List();
            Require(inventory.Any(a => a.BuiltInAdministrator && !a.Enabled && a.Administrator), "builtin_admin_must_be_disabled");
            var existing = inventory.SingleOrDefault(a => a.Name.Equals(config.AdminName, StringComparison.OrdinalIgnoreCase));
            Require(existing is null || (existing.Sid == state.AdminSid && !existing.BuiltInAdministrator), "unowned_admin_name_collision");
            using var password = secret.Read();
            Require(password.Length > 0, "empty_password");
            var admin = existing ?? accounts.Create(config.AdminName, password);
            state = state with { AdminSid = admin.Sid, AdminName = config.AdminName };
            Publish(state);
            if (existing is not null) accounts.SetPassword(admin.Sid, password);
            accounts.AddToGroup(admin.Sid, AccountSids.Administrators);

            step = 2;
            VerifyAdmin(admin.Sid, password);
            step = 3;
            state = state with { Step = step, PreviousNetworkDeny = policy.HasNetworkDeny(), RecoveryRequired = true };
            Publish(state);
            policy.SetNetworkDeny(true);
            Require(policy.HasNetworkDeny(), "network_deny_not_applied");

            step = 4;
            var active = accounts.ActiveUserSids();
            var targets = active.Count > 0 ? active : config.NoSessionTarget == NoSessionTarget.EnrolledAccounts
                ? config.EnrolledAccountSids : config.LastConsoleUserSid is { } last ? [last] : Array.Empty<string>();
            Require(targets.Count > 0, "no_target_accounts");
            inventory = accounts.List();
            var selected = targets.Distinct(StringComparer.OrdinalIgnoreCase).Select(sid =>
                inventory.SingleOrDefault(a => a.Sid == sid) ?? throw new InvalidOperationException("target_not_local")).ToArray();
            Require(selected.All(a => a.Sid != admin.Sid && !a.BuiltInAdministrator &&
                !a.Name.Equals(config.AdminName, StringComparison.OrdinalIgnoreCase)), "protected_target");
            var demote = selected.Where(a => a.Administrator).ToArray();
            state = state with { Step = step, RestoreAdminSids = demote.Select(a => a.Sid).ToArray(),
                AddedUsersSids = selected.Where(a => !a.User).Select(a => a.Sid).ToArray() };
            Publish(state);
            VerifyAdmin(admin.Sid, password);
            foreach (var user in selected)
            {
                accounts.AddToGroup(user.Sid, AccountSids.Users);
                if (user.Administrator) accounts.RemoveFromGroup(user.Sid, AccountSids.Administrators);
            }
            inventory = accounts.List();
            Require(selected.All(user => inventory.Any(a => a.Sid == user.Sid && a.User && !a.Administrator)), "demotion_not_applied");
            step = 5;
            VerifyAdmin(admin.Sid, password);
            Require(accounts.List().Any(a => a.BuiltInAdministrator && !a.Enabled && a.Administrator), "builtin_admin_changed");
            step = 6;
            state = state with { Step = step, VisibilityCaptured = true, PreviousVisibility = policy.ReadVisibility(state.AdminName) };
            Publish(state);
            policy.SetVisibility(state.AdminName, 0);
            Require(policy.ReadVisibility(state.AdminName) == 0, "visibility_not_applied");
            step = 7;
            state = state with { Step = step, Mode = requestedMode, Status = "hardened", RecoveryRequired = false,
                LogoffRequired = demote.Length > 0 || state.AddedUsersSids.Length > 0 };
            Publish(state);
            return 0;
        }
        catch (Exception)
        {
            // Do not log exception messages: adapters may carry credential-bearing native input.
            var recovered = Restore(state);
            state = state with { Mode = HardeningMode.Panel, Status = "failed",
                Step = step, ErrorCode = $"failed_step_{step}", RecoveryRequired = !recovered, LogoffRequired = false };
            Publish(state);
            return 1;
        }
    }

    private int Undo(HardeningState state)
    {
        state = state with { Mode = HardeningMode.Panel, RecoveryRequired = true };
        Publish(state);
        var restored = Restore(state);
        Publish(state with { Status = restored ? "unhardened" : "rollback_failed", RecoveryRequired = !restored,
            ErrorCode = restored ? null : "rollback_incomplete", LogoffRequired = false, Step = 0 });
        return restored ? 0 : 1;
    }

    private bool Restore(HardeningState state)
    {
        var ok = true;
        IReadOnlyList<LocalAccount>? inventory = null;
        try { inventory = accounts.List(); } catch { ok = false; }
        foreach (var sid in state.RestoreAdminSids)
        {
            try
            {
                var account = inventory?.SingleOrDefault(a => a.Sid == sid);
                if (account is null) continue;
                Require(!account.BuiltInAdministrator && sid != state.AdminSid, "protected_restore_target");
                accounts.AddToGroup(sid, AccountSids.Administrators);
            }
            catch { ok = false; }
        }
        try
        {
            inventory = accounts.List();
            Require(state.RestoreAdminSids.All(sid => inventory.SingleOrDefault(a => a.Sid == sid) is not { Administrator: false }),
                "promotion_not_applied");
        }
        catch { ok = false; }
        // Keep network protection if any promotion failed; retain the full journal for retry.
        var promotionsRestored = ok;
        foreach (var sid in state.AddedUsersSids)
            try
            {
                if (inventory?.SingleOrDefault(a => a.Sid == sid) is null) continue;
                accounts.RemoveFromGroup(sid, AccountSids.Users);
            }
            catch { ok = false; }
        try
        {
            if (promotionsRestored && state.PreviousNetworkDeny is { } deny)
            {
                policy.SetNetworkDeny(deny);
                Require(policy.HasNetworkDeny() == deny, "deny_restore_failed");
            }
        }
        catch { ok = false; }
        try
        {
            if (state.VisibilityCaptured)
            {
                policy.SetVisibility(state.AdminName, state.PreviousVisibility);
                Require(policy.ReadVisibility(state.AdminName) == state.PreviousVisibility, "visibility_restore_failed");
            }
        }
        catch { ok = false; }
        return ok;
    }

    private void VerifyAdmin(string sid, SecureString password)
    {
        Require(accounts.List().Any(a => a.Sid == sid && a.Enabled && a.Administrator && !a.BuiltInAdministrator), "no_operational_admin");
        Require(accounts.ValidateInteractiveLogon(sid, password), "admin_logon_failed");
    }
    private void Publish(HardeningState state)
    {
        state = state with { Revision = Guid.NewGuid() };
        store.Write(state);
        log(JsonSerializer.Serialize(state, InstallationConfig.Json));
    }
    private static void Require(bool value, string code)
    {
        if (!value) throw new InvalidOperationException(code);
    }
}
