using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Persiste el token de sesión y las credenciales de re-login, cifrados con DPAPI
/// (DataProtectionScope.CurrentUser): solo el mismo usuario de Windows en el mismo equipo
/// puede descifrarlos. Sirve para no re-loguear en cada arranque y para re-login silencioso
/// cuando el token expira.
///
/// NOTA (decisión de Koichi, estilo K3): se guarda CC+contraseña de forma reversible bajo
/// DPAPI. Es reversible en la máquina para quien tenga la sesión del usuario. Se aceptó por
/// paridad con producción; si algún día se endurece, migrar a solo-token + re-enrolar por
/// identidad del equipo.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class K4CredentialStore
{
    private readonly string _tokenFile;
    private readonly string _credFile;

    public K4CredentialStore(string? authDir = null)
    {
        authDir ??= K4Paths.AuthDir;
        Directory.CreateDirectory(authDir);
        _tokenFile = Path.Combine(authDir, "token.bin");
        _credFile  = Path.Combine(authDir, "credentials.bin");
    }

    public void SaveToken(string token) => WriteProtected(_tokenFile, token);

    public string? LoadToken() => ReadProtected(_tokenFile);

    public void ClearToken() => Delete(_tokenFile);

    /// <summary>Guarda CC y contraseña en un solo blob 'cc\npass' cifrado.</summary>
    public void SaveCredentials(string cc, string password)
        => WriteProtected(_credFile, cc + "\n" + password);

    public (string Cc, string Password)? LoadCredentials()
    {
        var raw = ReadProtected(_credFile);
        if (raw is null) return null;
        var i = raw.IndexOf('\n');
        if (i < 0) return null;
        return (raw[..i], raw[(i + 1)..]);
    }

    public bool HasCredentials() => File.Exists(_credFile);

    public void ClearCredentials() => Delete(_credFile);

    // --- DPAPI + escritura atómica ---

    private static void WriteProtected(string path, string plain)
    {
        var cipher = ProtectedData.Protect(
            Encoding.UTF8.GetBytes(plain), optionalEntropy: null, scope: DataProtectionScope.CurrentUser);
        var tmp = path + ".tmp";
        File.WriteAllBytes(tmp, cipher);
        File.Move(tmp, path, overwrite: true);
    }

    private static string? ReadProtected(string path)
    {
        try
        {
            if (!File.Exists(path)) return null;
            var plain = ProtectedData.Unprotect(
                File.ReadAllBytes(path), optionalEntropy: null, scope: DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(plain);
        }
        catch (CryptographicException)
        {
            // Blob de otro usuario/equipo o corrupto: tratar como ausente, no reventar.
            return null;
        }
        catch (IOException)
        {
            return null;
        }
    }

    private static void Delete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch { /* best-effort */ }
    }
}
