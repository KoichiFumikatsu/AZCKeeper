using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using System.Text.Json;

namespace Keeper.Shared.Time;

// Derechos de cuenta de la politica LSA local (secpol.msc > Asignacion de derechos de usuario). Detras de una interfaz
// para probar sin tocar la LSA del equipo.
public interface IAccountRights
{
    bool Has(string sid, string right);
    // Idempotentes: agregar un derecho que ya tiene o quitar uno que no tiene no es un error.
    void Add(string sid, string right);
    void Remove(string sid, string right);
}

// Lo que Keeper cambio para poder devolverlo exactamente: AdminsRemoved = se lo quito a Administradores (solo si lo
// tenian); Granted = cuentas a las que se lo dio porque no lo tenian (SYSTEM y/o LOCAL SERVICE).
public sealed record TimeChangeLockState(bool AdminsRemoved, IReadOnlyList<string> Granted, DateTimeOffset At);

// Resultado de un Lock: Changed = se toco la LSA; Reapplied = Administradores lo habia recuperado (alguien lo devolvio
// con secpol.msc) y se le volvio a quitar.
public sealed record TimeChangeLockResult(bool Changed, bool Reapplied);

// Bloqueo real del cambio manual de la hora (agente 4.0.28, opcion time_change_lock de la politica; apagado por
// defecto). Windows separa «Cambiar la hora del sistema» (SeSystemtimePrivilege) de «Cambiar la zona horaria»
// (SeTimeZonePrivilege): se quita solo el primero a BUILTIN\Administradores, asi los abogados siguen eligiendo la zona
// de la firma extranjera. Lo conservan LOCAL SERVICE (W32Time, que corrige la hora por NTP) y SYSTEM (el agente pone la
// hora del servidor con SetSystemTime); si alguno no lo tiene asignado se le asigna antes de quitarselo a
// Administradores. Nunca en dominio: alli manda la GPO del DC (el modulo lo decide). El estado se guarda en el disco del
// agente ANTES de cada cambio, asi un corte a medias se puede deshacer; Unlock devuelve exactamente lo que se cambio.
// Lo usan el agente (TimeChangeLockModule) y el Bootstrapper al desinstalar (que borra el directorio de datos).
public sealed class TimeChangeLock(IAccountRights rights, string statePath)
{
    public const string Right = "SeSystemtimePrivilege";
    public const string Administrators = "S-1-5-32-544";
    public const string LocalSystem = "S-1-5-18";
    public const string LocalService = "S-1-5-19";
    // Orden: primero se garantiza a quienes deben conservarlo; despues se le quita a Administradores.
    public static IReadOnlyList<string> Keepers { get; } = [LocalSystem, LocalService];

    private static readonly JsonSerializerOptions Json = new() { WriteIndented = false };

    public bool IsLocked => Load() is not null;

    public TimeChangeLockState? Load()
    {
        if (!File.Exists(statePath)) return null;
        try { return JsonSerializer.Deserialize<TimeChangeLockState>(File.ReadAllBytes(statePath), Json); }
        catch (JsonException ex) { throw new InvalidDataException("invalid_time_change_lock_state", ex); }
    }

    public TimeChangeLockResult Lock(DateTimeOffset now)
    {
        var state = Load();
        var granted = new List<string>(state?.Granted ?? []);
        var adminsRemoved = state?.AdminsRemoved ?? false;
        var changed = false;
        foreach (var keeper in Keepers)
        {
            if (rights.Has(keeper, Right)) continue;
            if (!granted.Contains(keeper)) granted.Add(keeper);
            Save(new(adminsRemoved, granted, state?.At ?? now));
            rights.Add(keeper, Right);
            changed = true;
        }
        var reapplied = false;
        if (rights.Has(Administrators, Right))
        {
            reapplied = state is not null;
            adminsRemoved = true;
            Save(new(true, granted, state?.At ?? now));
            rights.Remove(Administrators, Right);
            changed = true;
        }
        // Sin cambios previos ni ahora (Administradores ya no lo tenia): igual queda el registro para saber que esta
        // encendido, sin nada que devolver.
        if (state is null) Save(new(adminsRemoved, granted, now));
        if (rights.Has(Administrators, Right) || Keepers.Any(k => !rights.Has(k, Right)))
            throw new InvalidOperationException("time_change_lock_not_persisted");
        return new(changed, reapplied);
    }

    // Devuelve lo que Keeper cambio y borra el registro. false = no habia nada que devolver.
    public bool Unlock()
    {
        var state = Load();
        if (state is null) return false;
        if (state.AdminsRemoved) rights.Add(Administrators, Right);
        foreach (var sid in state.Granted) rights.Remove(sid, Right);
        if (state.AdminsRemoved && !rights.Has(Administrators, Right)) throw new InvalidOperationException("time_change_unlock_not_persisted");
        File.Delete(statePath);
        return true;
    }

    private void Save(TimeChangeLockState state)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(statePath))!);
        var temporary = statePath + ".tmp";
        using (var stream = new FileStream(temporary, FileMode.Create, FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
        {
            JsonSerializer.Serialize(stream, state, Json);
            stream.Flush(flushToDisk: true);
        }
        File.Move(temporary, statePath, overwrite: true);
    }
}

// Paso unico del Bootstrapper al desinstalar: el directorio de datos (con el registro) se borra, asi que lo que Keeper
// quito se devuelve antes. Nunca lanza: la desinstalacion sigue aunque falle.
public static class TimeChangeLockUninstall
{
    public static string Run(IAccountRights rights, string statePath)
    {
        try
        {
            return new TimeChangeLock(rights, statePath).Unlock()
                ? "HORA: SeSystemtimePrivilege devuelto a Administradores (Keeper lo habia quitado)"
                : "HORA: el cambio manual de la hora no estaba bloqueado por Keeper; nada que devolver";
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or Win32Exception or InvalidDataException or InvalidOperationException)
        {
            return $"HORA: no se pudo devolver SeSystemtimePrivilege a Administradores ({ex.GetType().Name}); la desinstalacion continua";
        }
    }
}

// LSA local: LsaOpenPolicy + LsaEnumerateAccountRights / LsaAddAccountRights / LsaRemoveAccountRights (como
// WindowsSecurityPolicy del endurecimiento). Requiere SYSTEM o administrador elevado.
[SupportedOSPlatform("windows")]
public sealed class WindowsAccountRights : IAccountRights
{
    private const uint ObjectNameNotFound = 0xC0000034;

    public bool Has(string sid, string right) => WithLsa(handle =>
    {
        var status = LsaEnumerateAccountRights(handle, SidBytes(sid), out var list, out var count);
        if (status == ObjectNameNotFound) return false; // la cuenta no tiene ningun derecho asignado
        Check(status);
        try
        {
            for (var i = 0; i < count; i++)
            {
                var item = Marshal.PtrToStructure<LsaString>(list + i * Marshal.SizeOf<LsaString>());
                if (Marshal.PtrToStringUni(item.Buffer, item.Length / 2) == right) return true;
            }
            return false;
        }
        finally { LsaFreeMemory(list); }
    });

    public void Add(string sid, string right) => Change(sid, right, add: true);
    public void Remove(string sid, string right) => Change(sid, right, add: false);

    private static void Change(string sid, string right, bool add) => WithLsa(handle =>
    {
        var buffer = Marshal.StringToHGlobalUni(right);
        try
        {
            var item = new LsaString { Buffer = buffer, Length = checked((ushort)(right.Length * 2)), MaximumLength = checked((ushort)((right.Length + 1) * 2)) };
            var status = add ? LsaAddAccountRights(handle, SidBytes(sid), [item], 1)
                : LsaRemoveAccountRights(handle, SidBytes(sid), false, [item], 1);
            // Quitar a una cuenta sin derechos asignados: ya esta como se queria.
            if (!add && status == ObjectNameNotFound) return true;
            Check(status);
            return true;
        }
        finally { Marshal.FreeHGlobal(buffer); }
    });

    private static T WithLsa<T>(Func<IntPtr, T> action)
    {
        var attributes = new ObjectAttributes { Length = (uint)Marshal.SizeOf<ObjectAttributes>() };
        // POLICY_VIEW_LOCAL_INFORMATION | POLICY_CREATE_ACCOUNT | POLICY_LOOKUP_NAMES
        Check(LsaOpenPolicy(IntPtr.Zero, ref attributes, 0x1 | 0x10 | 0x800, out var handle));
        try { return action(handle); }
        finally { LsaClose(handle); }
    }

    private static byte[] SidBytes(string value)
    {
        var sid = new SecurityIdentifier(value);
        var bytes = new byte[sid.BinaryLength];
        sid.GetBinaryForm(bytes, 0);
        return bytes;
    }

    private static void Check(uint status) { if (status != 0) throw new Win32Exception((int)LsaNtStatusToWinError(status)); }

    [StructLayout(LayoutKind.Sequential)]
    private struct ObjectAttributes
    {
        public uint Length;
        public IntPtr RootDirectory, ObjectName;
        public uint Attributes;
        public IntPtr SecurityDescriptor, SecurityQualityOfService;
    }
    [StructLayout(LayoutKind.Sequential)]
    private struct LsaString { public ushort Length, MaximumLength; public IntPtr Buffer; }
    [DllImport("advapi32.dll")] private static extern uint LsaOpenPolicy(IntPtr system, ref ObjectAttributes attributes, uint access, out IntPtr handle);
    [DllImport("advapi32.dll")] private static extern uint LsaEnumerateAccountRights(IntPtr handle, byte[] sid, out IntPtr rights, out int count);
    [DllImport("advapi32.dll")] private static extern uint LsaAddAccountRights(IntPtr handle, byte[] sid, LsaString[] rights, int count);
    [DllImport("advapi32.dll")] private static extern uint LsaRemoveAccountRights(IntPtr handle, byte[] sid, [MarshalAs(UnmanagedType.U1)] bool allRights, LsaString[] rights, int count);
    [DllImport("advapi32.dll")] private static extern uint LsaFreeMemory(IntPtr memory);
    [DllImport("advapi32.dll")] private static extern uint LsaClose(IntPtr handle);
    [DllImport("advapi32.dll")] private static extern uint LsaNtStatusToWinError(uint status);
}
