namespace Keeper.Bootstrapper;

public static class WindowsConstants
{
    public const string DirectorySddl = "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)";
    public const string FileSddl = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)";
    // bin: ademas lectura+ejecucion (0x1200a9) para Usuarios, sin escritura. Keeper.Session corre con el token
    // del usuario y el apphost de .NET necesita leer su propio ejecutable. v4 (datos, claves) NO usa esto.
    public const string BinDirectorySddl = "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;0x1200a9;;;BU)";
    public const string BinFileSddl = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x1200a9;;;BU)";
    public const string ServicesKey = @"SYSTEM\CurrentControlSet\Services";
    public static string ServiceKey(string name) => ServicesKey + @"\" + name;
}
