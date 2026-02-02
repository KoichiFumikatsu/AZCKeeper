using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Auth
{
    /// <summary>
    /// AuthManager:
    /// Mantiene el estado de autenticación del cliente basado en token (Bearer).
    ///
    /// Responsabilidades:
    /// - Mantener AuthToken en memoria.
    /// - Persistir token cifrado en disco (DPAPI CurrentUser) para "silent session".
    /// - Cargar token al iniciar (TryLoadTokenFromDisk).
    /// - Limpiar token (logout / invalidación).
    ///
    /// Comunicación / flujo:
    /// - CoreService crea e inicializa AuthManager (Initialize) y lo usa en login/logout.
    /// - ApiClient lo consume para adjuntar el Bearer en peticiones (CreateRequest).
    ///   Si no hay token, las llamadas autenticadas fallan o retornan 401/403.
    ///
    /// Seguridad:
    /// - Nunca loguea el token completo (solo preview).
    /// - DPAPI CurrentUser: el archivo solo se puede descifrar en el mismo usuario Windows.
    ///
    /// </summary>
    internal class AuthManager
    {
        private const string AppFolderName = "AZCKeeper"; // AppData\Roaming\AZCKeeper
        private const string AuthFolderName = "Auth";     // subcarpeta para secretos locales
        private const string TokenFileName = "auth_token.bin"; // archivo binario cifrado

        private string _authToken; // Token en memoria (fuente para Authorization: Bearer)

        /// <summary>
        /// Token actual (Bearer).
        /// Si está vacío, el cliente operará sin Authorization.
        /// </summary>
        public string AuthToken => _authToken;

        /// <summary>
        /// Indica si hay token cargado en memoria.
        /// </summary>
        public bool HasToken => !string.IsNullOrWhiteSpace(_authToken);

        /// <summary>
        /// Actualiza token en memoria y lo persiste cifrado.
        /// - Si el token llega vacío: no se aplica.
        /// </summary>
        public void UpdateAuthToken(string newToken)
        {
            if (string.IsNullOrWhiteSpace(newToken))
            {
                LocalLogger.Warn("AuthManager.UpdateAuthToken(): token vacío. No se aplica.");
                return;
            }

            _authToken = newToken;

            // Persistir en disco cifrado (no debe romper flujo si falla).
            TrySaveTokenToDisk(newToken);

            // Log seguro (preview).
            LocalLogger.Info($"AuthManager.UpdateAuthToken(): token actualizado. Preview={GetTokenPreview(newToken)}");
        }

        /// <summary>
        /// Carga token desde disco (si existe) y lo deja en memoria.
        /// Retorna true si se cargó correctamente.
        ///
        /// Uso esperado:
        /// - En Initialize() del Core: si retorna true, el handshake puede ir autenticado.
        /// </summary>
        public bool TryLoadTokenFromDisk()
        {
            try
            {
                string path = GetTokenFilePath();

                if (!File.Exists(path))
                {
                    LocalLogger.Info("AuthManager.TryLoadTokenFromDisk(): no existe token en disco.");
                    return false;
                }

                byte[] protectedBytes = File.ReadAllBytes(path);
                if (protectedBytes == null || protectedBytes.Length == 0)
                {
                    LocalLogger.Warn("AuthManager.TryLoadTokenFromDisk(): archivo token vacío. Se ignorará.");
                    return false;
                }

                // DPAPI CurrentUser
                byte[] bytes = ProtectedData.Unprotect(
                    protectedBytes,
                    optionalEntropy: null,
                    scope: DataProtectionScope.CurrentUser);

                string token = Encoding.UTF8.GetString(bytes);

                if (string.IsNullOrWhiteSpace(token))
                {
                    LocalLogger.Warn("AuthManager.TryLoadTokenFromDisk(): token descifrado vacío. Se ignorará.");
                    return false;
                }

                _authToken = token;
                LocalLogger.Info($"AuthManager.TryLoadTokenFromDisk(): token cargado en memoria. Preview={GetTokenPreview(token)}");
                return true;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "AuthManager.TryLoadTokenFromDisk(): error al cargar/descifrar token. Se continuará sin token.");
                return false;
            }
        }

        /// <summary>
        /// Limpia el token en memoria y opcionalmente borra el archivo en disco.
        ///
        /// </summary>
        public void ClearToken(bool deleteFromDisk = true)
        {
            if (string.IsNullOrWhiteSpace(_authToken) && !deleteFromDisk)
                return;

            _authToken = null;

            if (deleteFromDisk)
                TryDeleteTokenFromDisk();

            LocalLogger.Warn("AuthManager.ClearToken(): token limpiado.");
        }

        /// <summary>
        /// Guarda token cifrado usando DPAPI (CurrentUser).
        /// Implementa write-temp + replace para evitar archivos corruptos.
        /// </summary>
        private void TrySaveTokenToDisk(string token)
        {
            try
            {
                string path = GetTokenFilePath();
                EnsureAuthFolderExists();

                byte[] bytes = Encoding.UTF8.GetBytes(token);

                byte[] protectedBytes = ProtectedData.Protect(
                    bytes,
                    optionalEntropy: null,
                    scope: DataProtectionScope.CurrentUser);

                string tmp = path + ".tmp";
                File.WriteAllBytes(tmp, protectedBytes);

                if (File.Exists(path))
                    File.Delete(path);

                File.Move(tmp, path);

                LocalLogger.Info("AuthManager: token persistido cifrado en disco (DPAPI).");
            }
            catch (Exception ex)
            {
                // Mantener resiliencia: si falla persistencia, seguimos con token en memoria.
                LocalLogger.Error(ex, "AuthManager: error al persistir token en disco. Se mantendrá solo en memoria.");
            }
        }

        /// <summary>
        /// Elimina el archivo de token (si existe).
        /// </summary>
        private void TryDeleteTokenFromDisk()
        {
            try
            {
                string path = GetTokenFilePath();
                if (File.Exists(path))
                {
                    File.Delete(path);
                    LocalLogger.Info("AuthManager: token eliminado de disco.");
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "AuthManager: error al eliminar token de disco.");
            }
        }

        /// <summary>
        /// Asegura carpeta AppData\Roaming\AZCKeeper\Auth
        /// </summary>
        private static void EnsureAuthFolderExists()
        {
            string folder = GetAuthFolderPath();
            if (!Directory.Exists(folder))
                Directory.CreateDirectory(folder);
        }

        /// <summary>
        /// Devuelve la ruta AppData\Roaming\AZCKeeper\Auth.
        /// </summary>
        private static string GetAuthFolderPath()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            return Path.Combine(appData, AppFolderName, AuthFolderName);
        }

        /// <summary>
        /// Devuelve la ruta completa del archivo de token cifrado.
        /// </summary>
        private static string GetTokenFilePath()
        {
            return Path.Combine(GetAuthFolderPath(), TokenFileName);
        }

        /// <summary>
        /// Preview seguro para logs:
        /// - primeros 6 chars + últimos 4 chars.
        /// </summary>
        private static string GetTokenPreview(string token)
        {
            if (string.IsNullOrEmpty(token))
                return "(null)";

            if (token.Length <= 12)
                return "***";

            return $"{token.Substring(0, 6)}...{token.Substring(token.Length - 4)}";
        }
    }
}
