using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Xml.Linq;

namespace Keeper.Agent.Modules.Enforcement;

public enum AppLockerMode { Audit, Enforce }

public sealed record AppLockerOptions
{
    public AppLockerMode Mode { get; init; } = AppLockerMode.Audit;
    public IReadOnlyList<string> TrustedPublishers { get; init; } =
        ["O=MICROSOFT CORPORATION, L=REDMOND, S=WASHINGTON, C=US"];
    public IReadOnlyList<string> AdditionalWritablePaths { get; init; } = [];
    // Carpeta de instalacion de Keeper: siempre permitida para ejecutables (ver AppLockerPolicy.Create).
    public string KeeperDirectory { get; init; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper");

    public static AppLockerOptions FromEnvironment(Func<string, string?> read)
    {
        var mode = read("KEEPER_APPLOCKER_MODE") ?? "Audit";
        return new AppLockerOptions
        {
            Mode = mode.ToUpperInvariant() switch
            {
                "AUDIT" => AppLockerMode.Audit,
                "ENFORCE" => AppLockerMode.Enforce,
                _ => throw new ArgumentException("KEEPER_APPLOCKER_MODE must be Audit or Enforce")
            },
            TrustedPublishers = ReadList("KEEPER_APPLOCKER_TRUSTED_PUBLISHERS") ?? new AppLockerOptions().TrustedPublishers,
            AdditionalWritablePaths = ReadList("KEEPER_APPLOCKER_WRITABLE_PATHS") ?? []
        };

        string[]? ReadList(string name) => read(name) is { } json
            ? JsonSerializer.Deserialize<string[]>(json) ?? throw new ArgumentException($"{name} must be a JSON array")
            : null;
    }
}

public static class AppLockerPolicy
{
    public const string Everyone = "S-1-1-0";
    public static string Create(AppLockerOptions options)
    {
        ArgumentNullException.ThrowIfNull(options);
        ArgumentNullException.ThrowIfNull(options.TrustedPublishers);
        ArgumentNullException.ThrowIfNull(options.AdditionalWritablePaths);
        var mode = options.Mode switch
        {
            AppLockerMode.Audit => "AuditOnly",
            AppLockerMode.Enforce => "Enabled",
            _ => throw new ArgumentOutOfRangeException(nameof(options))
        };
        if (options.TrustedPublishers.Any(p => string.IsNullOrWhiteSpace(p) || p.Contains('*') || p.Contains('?')))
            throw new ArgumentException("Trusted publishers must be explicit AppLocker publisher names");
        foreach (var path in options.AdditionalWritablePaths)
            if (string.IsNullOrWhiteSpace(path) || !path.EndsWith(@"\*", StringComparison.Ordinal) ||
                !(path.StartsWith(@"\\", StringComparison.Ordinal) ||
                  path.Length > 3 && char.IsAsciiLetter(path[0]) && path[1] == ':' && path[2] == '\\') || path.Contains('%'))
                throw new ArgumentException("Additional writable paths must be absolute paths ending in \\*");

        // Invariante: Keeper nunca se bloquea a si mismo. Keeper.Session (como el usuario) y el bootstrapper del
        // auto-update (desde v4\staging) corren dentro de ProgramData\AZCKeeper, fuera de Program Files y sin firma
        // de Microsoft. Sin esta excepcion, pasar a Enforce dejaria sin tracking a la flota y podria bloquear el
        // propio update (el caso de K3 que bloqueo GitHub). Los usuarios no pueden escribir en esa carpeta (ACL).
        var keeper = Path.TrimEndingDirectorySeparator(options.KeeperDirectory);
        if (string.IsNullOrWhiteSpace(keeper) || !Path.IsPathFullyQualified(keeper) || keeper.Contains('*') || keeper.Contains('%'))
            throw new ArgumentException("Keeper directory must be an absolute path");
        var keeperRule = keeper + @"\*";
        // En AppLocker una denegacion gana a un permiso: ninguna ruta escribible puede cubrir la carpeta de Keeper.
        foreach (var path in options.AdditionalWritablePaths)
        {
            var pattern = "^" + System.Text.RegularExpressions.Regex.Escape(path).Replace(@"\*", ".*") + "$";
            if (System.Text.RegularExpressions.Regex.IsMatch(keeper + @"\bin\Keeper.Session.exe", pattern, System.Text.RegularExpressions.RegexOptions.IgnoreCase))
                throw new ArgumentException($"Writable path {path} would block Keeper itself");
        }

        // AppLocker variables are not environment variables; PROGRAMFILES covers both architectures.
        string[] allowedPaths = [@"%PROGRAMFILES%\*", @"%WINDIR%\*"];
        string[] writablePaths = [@"%OSDRIVE%\Users\*\Downloads\*", @"%OSDRIVE%\Users\*\AppData\*",
            @"%WINDIR%\Temp\*", @"%OSDRIVE%\Temp\*"];
        var root = new XElement("AppLockerPolicy", new XAttribute("Version", "1"));
        foreach (var type in new[] { "Exe", "Msi", "Script" })
        {
            var collection = new XElement("RuleCollection", new XAttribute("Type", type), new XAttribute("EnforcementMode", mode));
            foreach (var path in allowedPaths) collection.Add(PathRule(type, "Allow", path));
            // Exe: Session y bootstrapper. Script: el rescate independiente (recovery\Keeper-Recovery.ps1).
            if (type is "Exe" or "Script") collection.Add(PathRule(type, "Allow", keeperRule));
            foreach (var publisher in options.TrustedPublishers.Distinct(StringComparer.OrdinalIgnoreCase))
                collection.Add(PublisherRule(type, publisher));
            // A collection-wide path deny also covers renamed PE files (.scr), MSI and supported scripts.
            foreach (var path in writablePaths.Concat(options.AdditionalWritablePaths).Distinct(StringComparer.OrdinalIgnoreCase))
                collection.Add(PathRule(type, "Deny", path));
            root.Add(collection);
        }
        // EXE enforcement without an Appx collection can block packaged Windows apps (event 8027).
        root.Add(new XElement("RuleCollection", new XAttribute("Type", "Appx"), new XAttribute("EnforcementMode", mode),
            PublisherRule("Appx", "*")));
        return root.ToString(SaveOptions.DisableFormatting);
    }

    private static XElement PathRule(string type, string action, string path) =>
        Rule("FilePathRule", type, action, path, new XElement("FilePathCondition", new XAttribute("Path", path)));

    private static XElement PublisherRule(string type, string publisher) =>
        Rule("FilePublisherRule", type, "Allow", publisher, new XElement("FilePublisherCondition",
            new XAttribute("PublisherName", publisher), new XAttribute("ProductName", "*"), new XAttribute("BinaryName", "*"),
            new XElement("BinaryVersionRange", new XAttribute("LowSection", "0.0.0.0"), new XAttribute("HighSection", "*"))));

    private static XElement Rule(string element, string type, string action, string condition, XElement predicate)
    {
        var id = new Guid(SHA256.HashData(Encoding.UTF8.GetBytes($"AZCKeeper:v4:{type}:{action}:{condition}"))[..16]);
        return new XElement(element, new XAttribute("Id", id), new XAttribute("Name", $"Keeper {action} {condition}"),
            new XAttribute("Description", "AZCKeeper installation/execution policy"),
            new XAttribute("UserOrGroupSid", Everyone), new XAttribute("Action", action), new XElement("Conditions", predicate));
    }
}
