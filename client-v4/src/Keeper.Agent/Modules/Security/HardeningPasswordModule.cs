using System.Security.Cryptography;
using System.Text.Json;

namespace Keeper.Agent.Modules.Security;

public interface IHardeningPasswordFileSystem
{
    void PrepareDirectory(string directory);
    bool Exists(string path);
    void EnsureFileSecurity(string path, string sddl);
    byte[] ReadAllBytes(string path);
    void WriteNew(string path, byte[] encrypted, string sddl);
    void Move(string source, string destination);
    void Delete(string path);
}

public interface IHardeningPasswordProtector
{
    byte[] Protect(byte[] plaintext);
    byte[] Unprotect(byte[] encrypted);
}

public sealed class HardeningPasswordModule(string dataDirectory, IHardeningPasswordFileSystem files,
    IHardeningPasswordProtector protector)
{
    public const string FileSddl = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)";

    public void ApplyResponse(ReadOnlySpan<byte> response, CancellationToken ct)
    {
        byte[]? plaintext = null;
        try
        {
            var reader = new Utf8JsonReader(response);
            if (!reader.Read() || reader.TokenType != JsonTokenType.StartObject) throw InvalidResponse();
            while (reader.Read() && reader.TokenType != JsonTokenType.EndObject)
            {
                if (reader.TokenType != JsonTokenType.PropertyName) throw InvalidResponse();
                var password = reader.ValueTextEquals("shared_password"u8);
                if (!reader.Read()) throw InvalidResponse();
                if (!password) { reader.Skip(); continue; }
                if (plaintext is not null || reader.TokenType != JsonTokenType.String) throw InvalidResponse();
                var decoded = new byte[reader.ValueSpan.Length];
                try
                {
                    var length = reader.CopyString(decoded);
                    if (length == 0 || decoded.AsSpan(0, length).Contains((byte)0)) throw InvalidResponse();
                    plaintext = decoded.AsSpan(0, length).ToArray();
                }
                finally { CryptographicOperations.ZeroMemory(decoded); }
            }
            if (reader.TokenType != JsonTokenType.EndObject || reader.Read() || plaintext is null) throw InvalidResponse();
            Store(plaintext, ct);
        }
        catch (JsonException) { throw InvalidResponse(); }
        finally { if (plaintext is not null) CryptographicOperations.ZeroMemory(plaintext); }
    }

    private void Store(byte[] plaintext, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        var directory = Path.Combine(dataDirectory, "hardening");
        var path = Path.Combine(directory, "password.dpapi");
        files.PrepareDirectory(directory);
        if (files.Exists(path))
        {
            files.EnsureFileSecurity(path, FileSddl);
            var previous = protector.Unprotect(files.ReadAllBytes(path));
            try { if (CryptographicOperations.FixedTimeEquals(previous, plaintext)) return; }
            finally { CryptographicOperations.ZeroMemory(previous); }
        }
        var encrypted = protector.Protect(plaintext);
        var temporary = Path.Combine(directory, "password." + Guid.NewGuid().ToString("N") + ".tmp");
        try
        {
            files.WriteNew(temporary, encrypted, FileSddl);
            ct.ThrowIfCancellationRequested();
            files.Move(temporary, path);
        }
        finally { if (files.Exists(temporary)) files.Delete(temporary); }
    }

    private static InvalidDataException InvalidResponse() => new("invalid_hardening_response");
}
