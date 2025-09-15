using System;
using System.Runtime.InteropServices;
using System.Text;

namespace ModularApp.Core
{
    public static class Win32
    {
        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll")]
        private static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);

        [DllImport("user32.dll")]
        private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

        [DllImport("user32.dll")]
        public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

        [StructLayout(LayoutKind.Sequential)]
        private struct LASTINPUTINFO
        {
            public uint cbSize;
            public uint dwTime;
        }

        /// <summary> Devuelve el título de la ventana en primer plano. </summary>
        public static string GetActiveWindowTitle()
        {
            IntPtr handle = GetForegroundWindow();
            var sb = new StringBuilder(256);
            if (GetWindowText(handle, sb, sb.Capacity) > 0)
                return sb.ToString();
            return string.Empty;
        }

        /// <summary> Tiempo de inactividad del usuario (TimeSpan). </summary>
        public static TimeSpan GetIdleTime()
        {
            var lii = new LASTINPUTINFO();
            lii.cbSize = (uint)Marshal.SizeOf(typeof(LASTINPUTINFO));
            if (!GetLastInputInfo(ref lii)) return TimeSpan.Zero;

            uint envTicks = (uint)Environment.TickCount;
            uint idleTicks = envTicks - lii.dwTime;
            return TimeSpan.FromMilliseconds(idleTicks);
        }

        /// <summary> Tiempo de inactividad en segundos (atajo útil para C# 7.3). </summary>
        public static int GetIdleSeconds()
        {
            return (int)GetIdleTime().TotalSeconds;
        }
    }
}
