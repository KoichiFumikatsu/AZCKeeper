using System;
using AZCKeeper_Cliente.Security;
using Xunit;

/// <summary>
/// El orden de los valores de una subclave enumerada tiene que ser estable y numerico.
/// GetValueNames() no garantiza orden, y SecurityReportCache hashea el string[] tal cual:
/// un orden inestable produce un hash distinto sin que el estado haya cambiado, lo que
/// dispara POST inutiles contra un hosting que ya bloqueo la IP de la oficina por volumen.
/// </summary>
public class SecurityStateReaderOrderTests
{
    [Fact]
    public void Ordena_LosNombresComoNumerosNoComoTexto()
    {
        var nombres = new[] { "10", "2", "1" };

        Array.Sort(nombres, SecurityStateReader.CompararNombreNumerico);

        Assert.Equal(new[] { "1", "2", "10" }, nombres);
    }

    [Fact]
    public void Ordena_DejaLosNoNumericosAlFinal()
    {
        var nombres = new[] { "otro", "2", "1" };

        Array.Sort(nombres, SecurityStateReader.CompararNombreNumerico);

        Assert.Equal(new[] { "1", "2", "otro" }, nombres);
    }

    [Fact]
    public void Ordena_ElResultadoNoDependeDelOrdenDeEntrada()
    {
        var a = new[] { "3", "1", "2" };
        var b = new[] { "2", "3", "1" };

        Array.Sort(a, SecurityStateReader.CompararNombreNumerico);
        Array.Sort(b, SecurityStateReader.CompararNombreNumerico);

        Assert.Equal(a, b);
    }
}
