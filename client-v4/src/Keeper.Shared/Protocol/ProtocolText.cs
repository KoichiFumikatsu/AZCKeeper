using System.Collections;
using System.Collections.Concurrent;
using System.ComponentModel.DataAnnotations;
using System.Globalization;
using System.Reflection;
using System.Text;
using System.Text.Json;

namespace Keeper.Shared.Protocol;

/// <summary>
/// Recorta los textos de lo que el agente envia a los maxLength del contrato (atributo MaxLength que genera
/// tools/generate.mjs) en vez de mandar algo que el servidor rechaza: un solo texto largo devolvia 422 a TODO el sync y
/// frenaba la cola del equipo (ACT-0033, 2026-10-13). El servidor mide con mb_strlen (puntos de codigo) y C# con
/// unidades UTF-16: se cuenta como PHP, se corta en limite de grafema (no parte emojis ni pares sustitutos) y un
/// sustituto suelto se cambia por U+FFFD (PHP rechaza el JSON entero con un sustituto sin pareja).
/// </summary>
public static class ProtocolText
{
    private sealed record TextProperty(PropertyInfo Property, int MaxLength);
    private sealed record Shape(TextProperty[] Texts, PropertyInfo[] Nested);
    private static readonly ConcurrentDictionary<Type, Shape> Shapes = new();

    /// <summary>Largo como lo cuenta PHP mb_strlen sobre el UTF-8 que llega (un sustituto suelto cuenta 1: viaja como U+FFFD).</summary>
    public static int CodePoints(ReadOnlySpan<char> value)
    {
        var count = 0;
        for (var i = 0; i < value.Length; i++, count++)
            if (char.IsHighSurrogate(value[i]) && i + 1 < value.Length && char.IsLowSurrogate(value[i + 1])) i++;
        return count;
    }

    /// <summary>Texto valido (sin sustitutos sueltos) de como mucho <paramref name="maxCodePoints"/> puntos de codigo.</summary>
    public static string Truncate(string value, int maxCodePoints)
    {
        ArgumentOutOfRangeException.ThrowIfNegative(maxCodePoints);
        value = ReplaceLoneSurrogates(value);
        if (value.Length <= maxCodePoints || CodePoints(value) <= maxCodePoints) return value;
        int index = 0, count = 0;
        while (index < value.Length)
        {
            var length = StringInfo.GetNextTextElementLength(value.AsSpan(index));
            var points = CodePoints(value.AsSpan(index, length));
            if (count + points > maxCodePoints) break;
            count += points;
            index += length;
        }
        // Un grafema enorme (marcas combinadas sin fin) al principio: se corta por punto de codigo, nunca a mitad de par.
        if (index == 0)
            for (count = 0; index < value.Length && count < maxCodePoints; count++)
                index += char.IsHighSurrogate(value[index]) && index + 1 < value.Length && char.IsLowSurrogate(value[index + 1]) ? 2 : 1;
        return value[..index];
    }

    /// <summary>
    /// Copia de <paramref name="value"/> con cada texto del protocolo recortado a su maxLength. Si nada excede devuelve
    /// la misma instancia (sin copiar).
    /// </summary>
    public static T? Sanitize<T>(T? value) where T : class
    {
        if (value is null || !Needs(value)) return value;
        var copy = (T)JsonSerializer.Deserialize(JsonSerializer.SerializeToUtf8Bytes(value, value.GetType(), ProtocolJson.Options),
            value.GetType(), ProtocolJson.Options)!;
        Fix(copy);
        return copy;
    }

    private static string ReplaceLoneSurrogates(string value)
    {
        StringBuilder? fixedText = null;
        for (var i = 0; i < value.Length; i++)
        {
            var c = value[i];
            if (char.IsHighSurrogate(c) && i + 1 < value.Length && char.IsLowSurrogate(value[i + 1]))
            {
                fixedText?.Append(c).Append(value[i + 1]);
                i++;
                continue;
            }
            if (char.IsSurrogate(c))
            {
                fixedText ??= new StringBuilder(value.Length).Append(value, 0, i);
                fixedText.Append('�');
                continue;
            }
            fixedText?.Append(c);
        }
        return fixedText?.ToString() ?? value;
    }

    private static bool Needs(object value)
    {
        var shape = ShapeOf(value.GetType());
        foreach (var text in shape.Texts)
            if (text.Property.GetValue(value) is string s && !ReferenceEquals(Truncate(s, text.MaxLength), s)) return true;
        foreach (var nested in shape.Nested)
            if (Children(nested.GetValue(value)).Any(Needs)) return true;
        return false;
    }

    private static void Fix(object value)
    {
        var shape = ShapeOf(value.GetType());
        foreach (var text in shape.Texts)
            if (text.Property.GetValue(value) is string s && Truncate(s, text.MaxLength) is var trimmed && !ReferenceEquals(trimmed, s))
                text.Property.SetValue(value, trimmed);
        foreach (var nested in shape.Nested)
            foreach (var child in Children(nested.GetValue(value))) Fix(child);
    }

    private static IEnumerable<object> Children(object? value) => value switch
    {
        null or string => [],
        IEnumerable list => list.OfType<object>().Where(x => IsProtocol(x.GetType())),
        _ when IsProtocol(value.GetType()) => [value],
        _ => []
    };

    private static bool IsProtocol(Type type) => type.Namespace == typeof(ProtocolText).Namespace && type.IsClass;

    private static Shape ShapeOf(Type type) => Shapes.GetOrAdd(type, t =>
    {
        var properties = t.GetProperties(BindingFlags.Public | BindingFlags.Instance).Where(p => p.CanRead && p.GetIndexParameters().Length == 0).ToArray();
        return new Shape(
            properties.Where(p => p.PropertyType == typeof(string) && p.CanWrite && p.GetCustomAttribute<MaxLengthAttribute>() is not null)
                .Select(p => new TextProperty(p, p.GetCustomAttribute<MaxLengthAttribute>()!.Length)).ToArray(),
            properties.Where(p => p.PropertyType != typeof(string) && (IsProtocol(p.PropertyType) ||
                p.PropertyType.IsGenericType && p.PropertyType.GetGenericArguments().Any(IsProtocol))).ToArray());
    });
}
