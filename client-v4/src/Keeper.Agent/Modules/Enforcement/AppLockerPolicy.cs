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

        // AppLocker variables are not environment variables; PROGRAMFILES covers both architectures.
        string[] allowedPaths = [@"%PROGRAMFILES%\*", @"%WINDIR%\*"];
        string[] writablePaths = [@"%OSDRIVE%\Users\*\Downloads\*", @"%OSDRIVE%\Users\*\AppData\*",
            @"%WINDIR%\Temp\*", @"%OSDRIVE%\Temp\*"];
        var root = new XElement("AppLockerPolicy", new XAttribute("Version", "1"));
        foreach (var type in new[] { "Exe", "Msi", "Script" })
        {
            var collection = new XElement("RuleCollection", new XAttribute("Type", type), new XAttribute("EnforcementMode", mode));
            foreach (var path in allowedPaths) collection.Add(PathRule(type, "Allow", path));
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
