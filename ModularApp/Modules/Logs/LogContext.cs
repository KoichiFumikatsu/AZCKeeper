using System;

namespace ModularApp.Core
{
    public static class LogContext
    {
        // Asignas un proveedor dinámico desde LoginModule
        public static Func<string> Provider { get; set; }

        // Fallback con AppConfig.User si no hay proveedor
        // LogContext.cs
        public static string CurrentTag(AppConfig cfg = null)
        {
            try
            {
                var p = Provider?.Invoke();
                if (!string.IsNullOrWhiteSpace(p)) return p;

                var u = cfg?.User;
                if (u != null && u.EmployeeId > 0)
                {
                    var name = string.IsNullOrWhiteSpace(u.DisplayName) ? u.UserName : u.DisplayName;
                    return $"uid={u.EmployeeId} user={name}";
                }
            }
            catch { }
            return "uid=0 user=?";
        }
    }
}
