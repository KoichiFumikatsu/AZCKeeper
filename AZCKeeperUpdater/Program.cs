using System;
using System.IO;
using System.Diagnostics;
using System.Threading;

namespace AZCKeeperUpdater
{
    class Program
    {
        private static string _logFile;

        static void Main(string[] args)
        {
            try
            {
                // Configurar archivo de log
                string appDataPath = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                string logDir = Path.Combine(appDataPath, "AZCKeeper", "Logs");
                if (!Directory.Exists(logDir))
                    Directory.CreateDirectory(logDir);
                
                _logFile = Path.Combine(logDir, $"updater_{DateTime.Now:yyyyMMdd_HHmmss}.log");

                Log("=== AZCKeeper Updater v1.0 ===");

                if (args.Length < 3)
                {
                    Log("ERROR: Parámetros insuficientes.");
                    Log("Uso: AZCKeeperUpdater.exe <targetDir> <sourceDir> <oldExe>");
                    Thread.Sleep(3000);
                    return;
                }

                string targetDir = args[0];   // Donde está instalado el cliente
                string sourceDir = args[1];   // Carpeta con archivos nuevos
                string oldExePath = args[2];  // EXE del cliente viejo

                Log($"Target:  {targetDir}");
                Log($"Source:  {sourceDir}");
                Log($"OldExe:  {oldExePath}");
                Log("");
                Log("Esperando cierre del cliente...");

                // Esperar a que el proceso viejo termine
                Thread.Sleep(3000);

                if (!Directory.Exists(sourceDir))
                {
                    Log($"ERROR: Carpeta fuente no existe: {sourceDir}");
                    Thread.Sleep(5000);
                    return;
                }

                if (!Directory.Exists(targetDir))
                {
                    Log($"ERROR: Carpeta destino no existe: {targetDir}");
                    Thread.Sleep(5000);
                    return;
                }

                // Copiar archivos nuevos sobre los viejos
                int filesUpdated = 0;
                foreach (string file in Directory.GetFiles(sourceDir, "*.*", SearchOption.AllDirectories))
                {
                    string relativePath = Path.GetRelativePath(sourceDir, file);
                    string targetPath = Path.Combine(targetDir, relativePath);

                    string? targetDirPath = Path.GetDirectoryName(targetPath);
                    if (!string.IsNullOrEmpty(targetDirPath) && !Directory.Exists(targetDirPath))
                    {
                        Directory.CreateDirectory(targetDirPath);
                    }

                    Log($"[{filesUpdated + 1}] {relativePath}");
                    File.Copy(file, targetPath, overwrite: true);
                    filesUpdated++;
                }

                Log("");
                Log($"✓ Actualización completada ({filesUpdated} archivos).");
                Log("  Reiniciando cliente...");

                Thread.Sleep(1000);

                // Lanzar el cliente actualizado
                if (File.Exists(oldExePath))
                {
                    Process.Start(new ProcessStartInfo
                    {
                        FileName = oldExePath,
                        UseShellExecute = true,
                        WorkingDirectory = targetDir
                    });

                    Log("✓ Cliente reiniciado.");
                }
                else
                {
                    Log($"ADVERTENCIA: No se pudo reiniciar. EXE no encontrado: {oldExePath}");
                }

                Thread.Sleep(2000);

                // Autodestruirse
                SelfDestruct();
            }
            catch (Exception ex)
            {
                Log("");
                Log($"ERROR CRÍTICO: {ex.Message}");
                Log("");
                Log("Detalles técnicos:");
                Log(ex.ToString());
            }
        }

        static void Log(string message)
        {
            try
            {
                if (!string.IsNullOrEmpty(_logFile))
                {
                    string timestamp = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss");
                    File.AppendAllText(_logFile, $"[{timestamp}] {message}\n");
                }
            }
            catch
            {
                // Ignorar errores de logging para no interrumpir la actualización
            }
        }

        static void SelfDestruct()
        {
            try
            {
                string? currentExePath = Process.GetCurrentProcess().MainModule?.FileName;
                if (string.IsNullOrEmpty(currentExePath))
                {
                    Log("No se pudo autodestruir (path null).");
                    return;
                }

                string batchPath = Path.Combine(Path.GetTempPath(), "cleanup_updater.bat");

                string batchContent = $@"@echo off
timeout /t 2 /nobreak > nul
del /f /q ""{currentExePath}"" 2>nul
del /f /q ""{batchPath}"" 2>nul
exit
";
                File.WriteAllText(batchPath, batchContent);

                // NOTA: En .NET 8, UseShellExecute=false con un .bat lanza Win32Exception
                // porque el OS no puede ejecutar scripts directamente. Se llama cmd.exe /c explícitamente.
                Process.Start(new ProcessStartInfo
                {
                    FileName = "cmd.exe",
                    Arguments = $"/c \"{batchPath}\"",
                    CreateNoWindow = true,
                    UseShellExecute = false,
                    WindowStyle = ProcessWindowStyle.Hidden
                });

                Log("✓ Limpieza programada.");
            }
            catch (Exception ex)
            {
                Log($"No se pudo programar autodestrucción: {ex.Message}");
            }
        }
    }
}