namespace Keeper.Bootstrapper;

public static class WindowsConstants
{
    public const string DirectorySddl = "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)";
    public const string FileSddl = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)";
    public const string ServicesKey = @"SYSTEM\CurrentControlSet\Services";
    public static string ServiceKey(string name) => ServicesKey + @"\" + name;
}
