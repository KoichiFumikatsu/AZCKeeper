using System.Security;
using System.Security.Cryptography;
using System.Text.Json;

namespace Keeper.Agent.Migration;

public sealed record ProtectedEscrowRecovery(string AccountSid, long Revision, Guid AuditId, SecureString Password) : IDisposable
{
    public void Dispose() => Password.Dispose();

    // The IT adapter passes a bounded HTTP byte buffer; never deserialize password to a managed string.
    public static ProtectedEscrowRecovery ParseAndClear(byte[] response)
    {
        var password = new SecureString();
        var success = false;
        try
        {
            if (response.Length > 16384) throw new InvalidDataException("recovery_response_too_large");
            var reader = new Utf8JsonReader(response);
            if (!reader.Read() || reader.TokenType != JsonTokenType.StartObject) throw new InvalidDataException("invalid_recovery_response");
            var fields = new HashSet<string>();
            string? sid = null; long revision = 0; Guid audit = Guid.Empty;
            while (reader.Read() && reader.TokenType != JsonTokenType.EndObject)
            {
                if (reader.TokenType != JsonTokenType.PropertyName) throw new InvalidDataException("invalid_recovery_field");
                var name = reader.GetString()!;
                if (!fields.Add(name) || !reader.Read()) throw new InvalidDataException("duplicate_recovery_field");
                switch (name)
                {
                    case "account_sid": sid = reader.GetString(); break;
                    case "revision": revision = reader.GetInt64(); break;
                    case "audit_id": audit = reader.GetGuid(); break;
                    case "password":
                        if (reader.TokenType != JsonTokenType.String || reader.ValueIsEscaped || reader.ValueSpan.Length != 32)
                            throw new InvalidDataException("invalid_recovery_secret");
                        foreach (var value in reader.ValueSpan)
                        {
                            if (value is < 0x21 or > 0x7e) throw new InvalidDataException("invalid_recovery_secret");
                            password.AppendChar((char)value);
                        }
                        break;
                    default: throw new InvalidDataException("unexpected_recovery_field");
                }
            }
            if (reader.TokenType != JsonTokenType.EndObject || reader.Read() || fields.Count != 4 || revision < 1 || audit == Guid.Empty ||
                sid is null || !System.Text.RegularExpressions.Regex.IsMatch(sid, @"^S-1-5-21-\d+-\d+-\d+-\d+$") || password.Length != 32)
                throw new InvalidDataException("invalid_recovery_response");
            password.MakeReadOnly();
            success = true;
            return new(sid, revision, audit, password);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(response);
            if (!success) password.Dispose();
        }
    }
}
