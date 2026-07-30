using System.Drawing;
using System.Drawing.Imaging;
using System.Windows.Forms;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Platform;

/// <summary>
/// Implementación real de captura de pantalla en Windows: Graphics.CopyFromScreen sobre
/// un Bitmap del tamaño de la pantalla primaria, codificado a PNG. System.Drawing.Common
/// es Windows-only en net8, de ahí el atributo de plataforma. Nunca lanza hacia afuera:
/// si no hay escritorio (ej. sesión bloqueada/servicio sin interacción) devuelve null.
/// </summary>
[System.Runtime.Versioning.SupportedOSPlatform("windows")]
public sealed class WinScreenCapturer : IScreenCapturer
{
    public CaptureResult? Capture()
    {
        try
        {
            var bounds = Screen.PrimaryScreen?.Bounds;
            if (bounds is null || bounds.Value.Width <= 0 || bounds.Value.Height <= 0) return null;

            var width = bounds.Value.Width;
            var height = bounds.Value.Height;

            using var bitmap = new Bitmap(width, height, PixelFormat.Format32bppArgb);
            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.CopyFromScreen(bounds.Value.Location, Point.Empty, bounds.Value.Size);
            }

            using var stream = new MemoryStream();
            bitmap.Save(stream, ImageFormat.Png);
            return new CaptureResult(stream.ToArray(), width, height);
        }
        catch (Exception)
        {
            // ej. sesión sin escritorio, permisos insuficientes, driver de video caído.
            return null;
        }
    }
}
