using Keeper.Agent.Modules.Devices;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public class PowerScheduleCalendarTests
{
    private static PowerScheduleDirective Weekly(params long[] days) => new()
    {
        Id = Guid.NewGuid(), Version = 1, Action = PowerScheduleDirectiveAction.Shutdown, Recurrence = PowerScheduleDirectiveRecurrence.Weekly,
        Days = [.. days], TimeLocal = "19:00", ValidFrom = new DateOnly(2026, 10, 1), Timezone = "America/Bogota", Message = "m",
        IdleMinutes = 15, NoticeMinutes = 10, WindowMinutes = 180, UpdatedAt = DateTimeOffset.UnixEpoch,
    };

    [Fact]
    public void ViernesALas19DeBogotaEsMedianocheUtc()
    {
        // 2026-10-30 es viernes; 19:00 Bogotá (UTC-5) = 2026-10-31 00:00Z.
        var now = new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero);
        Assert.Equal(new DateTimeOffset(2026, 10, 31, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(Weekly(5), now));
    }

    [Fact]
    public void AntesDeLaHoraDevuelveLaSemanaAnterior()
    {
        var now = new DateTimeOffset(2026, 10, 30, 23, 0, 0, TimeSpan.Zero); // viernes 18:00 Bogotá
        Assert.Equal(new DateTimeOffset(2026, 10, 24, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(Weekly(5), now));
    }

    [Fact]
    public void FueraDeVigenciaNoHayOcurrencia()
    {
        var s = Weekly(5) with { ValidFrom = new DateOnly(2026, 11, 1) };
        Assert.Null(PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero)));
        var ended = Weekly(5) with { ValidUntil = new DateOnly(2026, 10, 29) };
        Assert.Equal(new DateTimeOffset(2026, 10, 24, 0, 0, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(ended, new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero)));
    }

    [Fact]
    public void UnaVez()
    {
        var s = Weekly() with { Recurrence = PowerScheduleDirectiveRecurrence.Once, Days = null, RunOn = new DateOnly(2026, 10, 28), TimeLocal = "18:30" };
        Assert.Null(PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 28, 23, 29, 0, TimeSpan.Zero)));
        Assert.Equal(new DateTimeOffset(2026, 10, 28, 23, 30, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 29, 3, 0, 0, TimeSpan.Zero)));
    }

    [Fact]
    public void UnaVezSinFechaNoProgramaNada()
    {
        var s = Weekly() with { Recurrence = PowerScheduleDirectiveRecurrence.Once, Days = null, RunOn = null };
        Assert.Null(PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 10, 31, 0, 5, 0, TimeSpan.Zero)));
    }

    [Fact]
    public void VentanaDeTresHoras()
    {
        var s = Weekly(5); var occ = new DateTimeOffset(2026, 10, 31, 0, 0, 0, TimeSpan.Zero);
        Assert.True(PowerScheduleCalendar.InWindow(s, occ, occ.AddMinutes(180)));
        Assert.False(PowerScheduleCalendar.InWindow(s, occ, occ.AddMinutes(181)));
    }

    [Fact]
    public void ZonaHorariaInvalidaNoProgramaNada() =>
        Assert.Null(PowerScheduleCalendar.Latest(Weekly(5) with { Timezone = "Nada/Nada" }, DateTimeOffset.UtcNow));

    [Fact]
    public void HoraInexistenteDelSaltoDeVeranoSeAdelantaUnaHora()
    {
        // 2026-03-08 (domingo) 02:30 no existe en America/New_York; se toma 03:30 EDT = 07:30Z.
        var s = Weekly(7) with { TimeLocal = "02:30", Timezone = "America/New_York", ValidFrom = new DateOnly(2026, 1, 1) };
        Assert.Equal(new DateTimeOffset(2026, 3, 8, 7, 30, 0, TimeSpan.Zero), PowerScheduleCalendar.Latest(s, new DateTimeOffset(2026, 3, 8, 12, 0, 0, TimeSpan.Zero)));
    }
}
