namespace Keeper.Shared.Contracts;

// Autoidentificacion en el alta (docs/architecture/v4-alta-equipos.md): mientras el equipo espera aprobacion y la
// empresa lo pide, el agente abre Keeper.Session en modo identificacion por un canal propio y recibe la cedula que
// escribio la persona. Un solo mensaje, Session -> agente; Document null = la persona cerro la ventana.
public sealed record IdentifyAnswer(int ProtocolVersion, string? Document);

public static class IdentifyProtocol
{
    public const string PipePrefix = "AZCKeeper.v4.identify.";

    // Solo digitos: se aceptan puntos, espacios y guiones al escribir y se quitan aqui, igual que en el backend.
    public static string? Normalize(string? value)
    {
        if (value is null) return null;
        var digits = new string(value.Where(c => c is not ('.' or ' ' or '-')).ToArray());
        return digits.Length is >= 5 and <= 15 && digits.All(char.IsAsciiDigit) ? digits : null;
    }

    public static string? Validate(IdentifyAnswer answer)
    {
        if (answer.ProtocolVersion != 1) throw new InvalidDataException("invalid_identify_answer");
        if (answer.Document is null) return null;
        return Normalize(answer.Document) ?? throw new InvalidDataException("invalid_identify_document");
    }
}
