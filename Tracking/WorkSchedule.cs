using System;

namespace AZCKeeper_Cliente.Tracking
{
    internal enum TimeCategory
    {
        WorkHours,
        LunchTime,
        AfterHours
    }

    internal class WorkSchedule
    {
        public TimeSpan WorkStart { get; set; } = new TimeSpan(7, 0, 0);  // 7:00 AM
        public TimeSpan WorkEnd { get; set; } = new TimeSpan(19, 0, 0);   // 7:00 PM
        public TimeSpan LunchStart { get; set; } = new TimeSpan(12, 0, 0); // 12:00 PM
        public TimeSpan LunchEnd { get; set; } = new TimeSpan(13, 0, 0);   // 1:00 PM

        public TimeCategory GetTimeCategory(DateTime localTime)
        {
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