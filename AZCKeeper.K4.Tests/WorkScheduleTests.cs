using System;
using AZCKeeper.K4.Contracts;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>El horario clasifica un instante en trabajo/almuerzo/fuera, y respeta días laborales.</summary>
public class WorkScheduleTests
{
    private static readonly WorkSchedule S = WorkSchedule.Default; // 08-18, almuerzo 12-13, L-V

    [Fact]
    public void Media_manana_es_trabajo()
        => Assert.Equal(WorkSchedule.Band.Work, S.Classify(new DateTime(2026, 7, 31, 10, 0, 0))); // viernes 10:00

    [Fact]
    public void Mediodia_es_almuerzo()
        => Assert.Equal(WorkSchedule.Band.Lunch, S.Classify(new DateTime(2026, 7, 31, 12, 30, 0)));

    [Fact]
    public void Noche_es_fuera_de_horario()
        => Assert.Equal(WorkSchedule.Band.After, S.Classify(new DateTime(2026, 7, 31, 20, 0, 0)));

    [Fact]
    public void Fin_de_semana_es_fuera()
        => Assert.Equal(WorkSchedule.Band.After, S.Classify(new DateTime(2026, 8, 1, 10, 0, 0))); // sábado 10:00

    [Fact]
    public void FromJson_parsea_y_defaultea()
    {
        var json = System.Text.Json.JsonDocument.Parse("{\"workStart\":\"09:00\",\"workEnd\":\"17:00\",\"lunchStart\":\"13:00\",\"lunchEnd\":\"14:00\",\"workdays\":[1,2,3,4,5,6]}").RootElement;
        var s = WorkSchedule.FromJson(json);
        Assert.Equal(new TimeSpan(9, 0, 0), s.WorkStart);
        Assert.True(s.IsWorkday(new DateTime(2026, 8, 1)));   // sábado incluido
        Assert.Equal(WorkSchedule.Band.Work, s.Classify(new DateTime(2026, 7, 31, 16, 0, 0)));
    }
}
