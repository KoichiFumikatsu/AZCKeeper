using System;

namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Categorías de tiempo usadas para clasificar actividad diaria.
    /// </summary>
    internal enum TimeCategory
    {
        WorkHours,
        LunchTime,
        AfterHours
    }

    /// <summary>
    /// Define el horario laboral y de almuerzo para categorizar actividad.
    /// Comunicación:
    /// - ActivityTracker usa GetTimeCategory() para acumular métricas por categoría.
    /// - DebugWindowForm muestra estas métricas en UI.
    /// </summary>
    internal class WorkSchedule
    {
        public TimeSpan WorkStart { get; set; } = new TimeSpan(7, 0, 0);  // 7:00 AM
        public TimeSpan WorkEnd { get; set; } = new TimeSpan(19, 0, 0);   // 7:00 PM
        public TimeSpan LunchStart { get; set; } = new TimeSpan(12, 0, 0); // 12:00 PM
        public TimeSpan LunchEnd { get; set; } = new TimeSpan(13, 0, 0);   // 1:00 PM

        /// <summary>
        /// Devuelve la categoría de tiempo según la hora local.
        /// Fines de semana (sábado/domingo) siempre son AfterHours.
        /// </summary>
        public TimeCategory GetTimeCategory(DateTime localTime)
        {
            // Fines de semana: siempre fuera de horario laboral
            if (localTime.DayOfWeek == DayOfWeek.Saturday || localTime.DayOfWeek == DayOfWeek.Sunday)
                return TimeCategory.AfterHours;

            TimeSpan time = localTime.TimeOfDay;

            // Lunch
            if (time >= LunchStart && time < LunchEnd)
                return TimeCategory.LunchTime;

            // Work hours
            if (time >= WorkStart && time < WorkEnd)
                return TimeCategory.WorkHours;

            // After hours
            return TimeCategory.AfterHours;
        }
    }
}