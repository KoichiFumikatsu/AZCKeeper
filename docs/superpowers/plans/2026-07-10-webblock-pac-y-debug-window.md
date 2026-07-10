# Web-blocking por PAC + Ventana Debug — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el web-blocking roto (URLBlocklist, requiere admin) por un PAC blackhole per-usuario que bloquea SOLO los dominios designados + subdominios, y enriquecer la ventana Debug con diagnósticos (versión, API, cola/errores, web-blocking/auth) más compacta.

**Architecture:** El cliente hospeda un servidor loopback que sirve un `.pac`; `AutoConfigURL` (HKCU, sin admin) apunta ahí; los dominios bloqueados van a un puerto muerto (fallo rápido), todo lo demás `DIRECT`. La ventana Debug pasa a leer un `DebugSnapshot` read-only que arma `CoreService`.

**Tech Stack:** C# `net8.0-windows`, WinForms, `Microsoft.Win32` registry, `System.Net.Sockets`, xUnit (proyecto de tests nuevo).

## Global Constraints

- TargetFramework: `net8.0-windows`; `Nullable` **disable** (no anotaciones nullable en código nuevo).
- Sin dependencias NuGet nuevas en el cliente (solo el proyecto de tests usa xUnit).
- Sin admin: todo el registro es `HKCU`.
- Puerto muerto (blackhole) del PAC: **`127.0.0.1:9`** (constante).
- Namespace de blocking: `AZCKeeper_Cliente.Blocking`. Logging: `AZCKeeper_Cliente.Logging.LocalLogger`.
- Comentarios y textos de UI en español (como el resto del código).
- Build cliente: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug` (en Windows).
- Commits frecuentes, uno por tarea como mínimo.

---

## Estructura de archivos

**Nuevos:**
- `AZCKeeper.Tests/AZCKeeper.Tests.csproj` — proyecto de tests xUnit.
- `AZCKeeper.Tests/PacContentBuilderTests.cs`
- `AZCKeeper.Tests/LocalPacServerTests.cs`
- `AZCKeeper.Tests/LocalLoggerRingBufferTests.cs`
- `AZCKeeper_Client/Blocking/PacContentBuilder.cs`
- `AZCKeeper_Client/Blocking/LocalPacServer.cs`
- `AZCKeeper_Client/Core/DebugSnapshot.cs`
- `AZCKeeper_Client/Core/DebugWindowForm.cs` (extraído de `CoreService.cs`)

**Modificados:**
- `AZCKeeper_Client/AZCKeeper_Client.csproj` — `InternalsVisibleTo`.
- `AZCKeeper_Client/Blocking/SystemProxyManager.cs` — recuperar `EnablePac`/`Restore`/backup; quitar `MigrateAwayFromPac`.
- `AZCKeeper_Client/Blocking/WebBlockingManager.cs` — reescritura a PAC + props de estado.
- `AZCKeeper_Client/Logging/LocalLogger.cs` — buffer circular de issues.
- `AZCKeeper_Client/Network/ApiClient.cs` — accessors `IsInBackoff`, `BackoffUntilUtc`, `PendingQueueCount`.
- `AZCKeeper_Client/Update/UpdateManager.cs` — accessors de última verificación.
- `AZCKeeper_Client/Core/CoreService.cs` — quitar `DebugWindowForm` inline; armar `DebugSnapshot`; rastrear estado de handshake.
- `AZCKeeper.sln` — agregar proyecto de tests.

**Eliminado:**
- `AZCKeeper_Client/Blocking/BrowserPolicyBlocker.cs`.

---

## Task 0: Proyecto de tests xUnit

**Files:**
- Create: `AZCKeeper.Tests/AZCKeeper.Tests.csproj`
- Create: `AZCKeeper.Tests/SmokeTest.cs`
- Modify: `AZCKeeper_Client/AZCKeeper_Client.csproj` (InternalsVisibleTo)
- Modify: `AZCKeeper.sln`

**Interfaces:**
- Produces: proyecto `AZCKeeper.Tests` que referencia `AZCKeeper_Client` y ve sus `internal`.

- [ ] **Step 1: Crear el csproj de tests**

Create `AZCKeeper.Tests/AZCKeeper.Tests.csproj`:

```xml
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>net8.0-windows</TargetFramework>
    <Nullable>disable</Nullable>
    <IsPackable>false</IsPackable>
    <UseWindowsForms>true</UseWindowsForms>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Microsoft.NET.Test.Sdk" Version="17.11.1" />
    <PackageReference Include="xunit" Version="2.9.2" />
    <PackageReference Include="xunit.runner.visualstudio" Version="2.8.2" />
  </ItemGroup>
  <ItemGroup>
    <ProjectReference Include="..\AZCKeeper_Client\AZCKeeper_Client.csproj" />
  </ItemGroup>
</Project>
```

- [ ] **Step 2: Exponer internals al proyecto de tests**

En `AZCKeeper_Client/AZCKeeper_Client.csproj`, dentro del primer `<PropertyGroup>`, agregar:

```xml
    <InternalsVisibleTo Include="AZCKeeper.Tests" />
```

Si el SDK no soporta el item `InternalsVisibleTo` directo, en su lugar crear `AZCKeeper_Client/Properties/AssemblyInfo.cs` con:

```csharp
using System.Runtime.CompilerServices;
[assembly: InternalsVisibleTo("AZCKeeper.Tests")]
```

- [ ] **Step 3: Smoke test**

Create `AZCKeeper.Tests/SmokeTest.cs`:

```csharp
using Xunit;

namespace AZCKeeper.Tests
{
    public class SmokeTest
    {
        [Fact]
        public void Sanity() => Assert.True(true);
    }
}
```

- [ ] **Step 4: Agregar el proyecto a la solución**

Run: `dotnet sln AZCKeeper.sln add AZCKeeper.Tests/AZCKeeper.Tests.csproj`
Expected: "Project ... added to the solution."

- [ ] **Step 5: Correr los tests**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add AZCKeeper.Tests AZCKeeper_Client/AZCKeeper_Client.csproj AZCKeeper.sln AZCKeeper_Client/Properties/AssemblyInfo.cs
git commit -m "test: agrega proyecto xUnit AZCKeeper.Tests"
```

---

## Task 1: PacContentBuilder (matching preciso)

**Files:**
- Create: `AZCKeeper_Client/Blocking/PacContentBuilder.cs`
- Test: `AZCKeeper.Tests/PacContentBuilderTests.cs`

**Interfaces:**
- Produces:
  - `internal static class PacContentBuilder`
  - `static string Build(string[] domains)` → texto JS del PAC.
  - `static bool WouldBlock(string[] domains, string host)` → helper puro para tests (misma lógica de match que el PAC emite).

- [ ] **Step 1: Escribir el test que falla**

Create `AZCKeeper.Tests/PacContentBuilderTests.cs`:

```csharp
using AZCKeeper_Cliente.Blocking;
using Xunit;

namespace AZCKeeper.Tests
{
    public class PacContentBuilderTests
    {
        private static readonly string[] Domains = { "facebook.com", "x.com" };

        [Theory]
        [InlineData("facebook.com", true)]
        [InlineData("www.facebook.com", true)]
        [InlineData("m.facebook.com", true)]
        [InlineData("x.com", true)]
        [InlineData("notfacebook.com", false)]
        [InlineData("facebook.com.evil.com", false)]
        [InlineData("fbcdn.net", false)]
        [InlineData("google.com", false)]
        [InlineData("irs.gov", false)]
        public void WouldBlock_matches_only_domain_and_subdomains(string host, bool expected)
        {
            Assert.Equal(expected, PacContentBuilder.WouldBlock(Domains, host));
        }

        [Fact]
        public void Build_emits_blackhole_and_direct()
        {
            string pac = PacContentBuilder.Build(Domains);
            Assert.Contains("PROXY 127.0.0.1:9", pac);
            Assert.Contains("return \"DIRECT\"", pac);
            Assert.Contains("\"facebook.com\"", pac);
        }

        [Fact]
        public void Build_sanitizes_and_rejects_invalid_domains()
        {
            string[] dirty = { "  FACEBOOK.com ", "*.x.com", "bad domain!", "" };
            Assert.True(PacContentBuilder.WouldBlock(dirty, "facebook.com"));   // normalizado
            Assert.True(PacContentBuilder.WouldBlock(dirty, "sub.x.com"));      // *. removido
            Assert.False(PacContentBuilder.WouldBlock(dirty, "bad")); // "bad domain!" descartado
        }

        [Fact]
        public void WouldBlock_handles_trailing_dot_host()
        {
            Assert.True(PacContentBuilder.WouldBlock(Domains, "facebook.com."));
        }
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter PacContentBuilderTests`
Expected: FAIL de compilación ("PacContentBuilder does not exist").

- [ ] **Step 3: Implementar PacContentBuilder**

Create `AZCKeeper_Client/Blocking/PacContentBuilder.cs`:

```csharp
using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Genera el contenido del PAC (proxy auto-config). Los dominios designados y sus
    /// subdominios se enrutan a un puerto muerto (bloqueo por fallo rápido); todo lo demás
    /// queda DIRECT. Match por sufijo exacto, sin shExpMatch, para no sobre-bloquear.
    /// </summary>
    internal static class PacContentBuilder
    {
        private const string Blackhole = "PROXY 127.0.0.1:9";
        private static readonly Regex ValidDomain = new Regex("^[a-z0-9.-]+$", RegexOptions.Compiled);

        /// <summary>Normaliza y valida la lista de dominios (minúsculas, sin *. inicial, solo [a-z0-9.-]).</summary>
        internal static string[] Sanitize(string[] domains)
        {
            return (domains ?? Array.Empty<string>())
                .Where(d => !string.IsNullOrWhiteSpace(d))
                .Select(d => d.Trim().ToLowerInvariant().TrimStart('*', '.').TrimEnd('.'))
                .Where(d => d.Length > 0 && ValidDomain.IsMatch(d))
                .Distinct(StringComparer.Ordinal)
                .ToArray();
        }

        /// <summary>Misma lógica de match que el PAC emitido (para pruebas y para el estado).</summary>
        internal static bool WouldBlock(string[] domains, string host)
        {
            if (string.IsNullOrWhiteSpace(host)) return false;
            host = host.Trim().ToLowerInvariant().TrimEnd('.');
            foreach (string d in Sanitize(domains))
            {
                if (host == d || (host.Length > d.Length && host.EndsWith("." + d, StringComparison.Ordinal)))
                    return true;
            }
            return false;
        }

        /// <summary>Construye el texto del PAC.</summary>
        internal static string Build(string[] domains)
        {
            string[] clean = Sanitize(domains);
            string list = string.Join(", ", clean.Select(d => "\"" + d + "\""));

            var sb = new StringBuilder();
            sb.AppendLine("function FindProxyForURL(url, host) {");
            sb.AppendLine("  host = host.toLowerCase();");
            sb.AppendLine("  if (host.charAt(host.length - 1) == \".\") host = host.substring(0, host.length - 1);");
            sb.AppendLine("  var blocked = [" + list + "];");
            sb.AppendLine("  for (var i = 0; i < blocked.length; i++) {");
            sb.AppendLine("    var b = blocked[i];");
            sb.AppendLine("    if (host == b || (host.length > b.length && host.substr(host.length - b.length - 1) == \".\" + b)) {");
            sb.AppendLine("      return \"" + Blackhole + "\";");
            sb.AppendLine("    }");
            sb.AppendLine("  }");
            sb.AppendLine("  return \"DIRECT\";");
            sb.AppendLine("}");
            return sb.ToString();
        }
    }
}
```

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter PacContentBuilderTests`
Expected: PASS (todos).

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Blocking/PacContentBuilder.cs AZCKeeper.Tests/PacContentBuilderTests.cs
git commit -m "feat(blocking): PacContentBuilder con match exacto dominio+subdominios"
```

---

## Task 2: LocalPacServer (servidor loopback del .pac)

**Files:**
- Create: `AZCKeeper_Client/Blocking/LocalPacServer.cs`
- Test: `AZCKeeper.Tests/LocalPacServerTests.cs`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `internal sealed class LocalPacServer(string cacheDirectory)`
  - `int StartOrUpdate(string pacContent)` → arranca (o hace swap del contenido) y devuelve el puerto.
  - `void UpdatePac(string pacContent)`
  - `void Stop()`
  - `bool IsRunning { get; }`
  - `int Port { get; }`

- [ ] **Step 1: Escribir el test que falla**

Create `AZCKeeper.Tests/LocalPacServerTests.cs`:

```csharp
using System.IO;
using System.Net.Http;
using AZCKeeper_Cliente.Blocking;
using Xunit;

namespace AZCKeeper.Tests
{
    public class LocalPacServerTests
    {
        [Fact]
        public void Serves_pac_content_over_loopback()
        {
            string dir = Path.Combine(Path.GetTempPath(), "azck_pac_" + System.Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(dir);
            var server = new LocalPacServer(dir);
            try
            {
                int port = server.StartOrUpdate("function FindProxyForURL(u,h){return \"DIRECT\";}");
                Assert.True(port > 0);
                Assert.True(server.IsRunning);

                using var http = new HttpClient();
                var resp = http.GetAsync($"http://127.0.0.1:{port}/proxy.pac").Result;
                Assert.True(resp.IsSuccessStatusCode);
                Assert.Equal("application/x-ns-proxy-autoconfig", resp.Content.Headers.ContentType.MediaType);
                string body = resp.Content.ReadAsStringAsync().Result;
                Assert.Contains("FindProxyForURL", body);
            }
            finally
            {
                server.Stop();
                Directory.Delete(dir, true);
            }
        }

        [Fact]
        public void Persists_port_between_instances()
        {
            string dir = Path.Combine(Path.GetTempPath(), "azck_pac_" + System.Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(dir);
            var a = new LocalPacServer(dir);
            try
            {
                int p1 = a.StartOrUpdate("x");
                a.Stop();
                var b = new LocalPacServer(dir);
                int p2 = b.StartOrUpdate("y");
                b.Stop();
                Assert.Equal(p1, p2);
            }
            finally { Directory.Delete(dir, true); }
        }
    }
}
```

- [ ] **Step 2: Correr el test para verificar que falla**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter LocalPacServerTests`
Expected: FAIL de compilación.

- [ ] **Step 3: Implementar LocalPacServer**

Create `AZCKeeper_Client/Blocking/LocalPacServer.cs`:

```csharp
using System;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Servidor loopback mínimo que SOLO sirve el texto del PAC en 127.0.0.1:&lt;puerto&gt;.
    /// No es un proxy: nunca reenvía tráfico. El puerto se persiste para mantener estable
    /// el AutoConfigURL entre reinicios (evita el "PAC muerto colgado" por puerto efímero).
    /// </summary>
    internal sealed class LocalPacServer
    {
        private readonly string _portFilePath;
        private readonly object _lock = new object();

        private TcpListener _listener;
        private CancellationTokenSource _cts;
        private volatile string _pac = string.Empty;

        public bool IsRunning { get; private set; }
        public int Port { get; private set; }

        public LocalPacServer(string cacheDirectory)
        {
            Directory.CreateDirectory(cacheDirectory);
            _portFilePath = Path.Combine(cacheDirectory, "pac_port.txt");
        }

        public int StartOrUpdate(string pacContent)
        {
            lock (_lock)
            {
                _pac = pacContent ?? string.Empty;
                if (IsRunning) return Port;

                Port = BindStablePort();
                _cts = new CancellationTokenSource();
                var token = _cts.Token;
                Task.Run(() => AcceptLoop(token));
                IsRunning = true;
                LocalLogger.Info($"LocalPacServer: sirviendo PAC en 127.0.0.1:{Port}/proxy.pac");
                return Port;
            }
        }

        public void UpdatePac(string pacContent)
        {
            _pac = pacContent ?? string.Empty;
        }

        public void Stop()
        {
            lock (_lock)
            {
                try { _cts?.Cancel(); } catch { }
                try { _listener?.Stop(); } catch { }
                _listener = null;
                _cts = null;
                IsRunning = false;
            }
        }

        private int BindStablePort()
        {
            int preferred = ReadPersistedPort();
            if (preferred > 0 && TryBind(preferred)) { return preferred; }

            // efímero: bind a puerto 0 y persistir el asignado
            _listener = new TcpListener(IPAddress.Loopback, 0);
            _listener.Start();
            int assigned = ((IPEndPoint)_listener.LocalEndpoint).Port;
            WritePersistedPort(assigned);
            return assigned;
        }

        private bool TryBind(int port)
        {
            try
            {
                var l = new TcpListener(IPAddress.Loopback, port);
                l.Start();
                _listener = l;
                return true;
            }
            catch { return false; }
        }

        private int ReadPersistedPort()
        {
            try { return File.Exists(_portFilePath) && int.TryParse(File.ReadAllText(_portFilePath).Trim(), out int p) ? p : 0; }
            catch { return 0; }
        }

        private void WritePersistedPort(int port)
        {
            try { File.WriteAllText(_portFilePath, port.ToString()); } catch { }
        }

        private async Task AcceptLoop(CancellationToken token)
        {
            while (!token.IsCancellationRequested)
            {
                TcpClient client;
                try { client = await _listener.AcceptTcpClientAsync().ConfigureAwait(false); }
                catch { break; }

                _ = Task.Run(() => Serve(client));
            }
        }

        private void Serve(TcpClient client)
        {
            try
            {
                using (client)
                using (var stream = client.GetStream())
                {
                    // Leer (y descartar) el request; solo importa devolver el PAC.
                    var buf = new byte[2048];
                    if (stream.CanRead && client.Available > 0) { try { stream.Read(buf, 0, buf.Length); } catch { } }

                    byte[] body = Encoding.ASCII.GetBytes(_pac);
                    string header = "HTTP/1.1 200 OK\r\n" +
                                    "Content-Type: application/x-ns-proxy-autoconfig\r\n" +
                                    "Content-Length: " + body.Length + "\r\n" +
                                    "Connection: close\r\n\r\n";
                    byte[] hb = Encoding.ASCII.GetBytes(header);
                    stream.Write(hb, 0, hb.Length);
                    stream.Write(body, 0, body.Length);
                    stream.Flush();
                }
            }
            catch { }
        }
    }
}
```

> Nota: el test lee justo tras conectar; si `client.Available` es 0 en ese instante, igual se responde el PAC (no dependemos del request). Si un test resulta flaky por timing, aceptar leer sin el guard `Available > 0` con un try/catch — el objetivo es servir el body siempre.

- [ ] **Step 4: Correr los tests para verificar que pasan**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter LocalPacServerTests`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Blocking/LocalPacServer.cs AZCKeeper.Tests/LocalPacServerTests.cs
git commit -m "feat(blocking): LocalPacServer loopback con puerto persistido"
```

---

## Task 3: SystemProxyManager — recuperar EnablePac/Restore, quitar migración

**Files:**
- Modify: `AZCKeeper_Client/Blocking/SystemProxyManager.cs`

**Interfaces:**
- Produces:
  - `void EnablePac(string pacUrl)` — escribe `AutoConfigURL` en HKCU (respalda proxy previo legítimo), refresca WinInet.
  - `void Restore()` — restaura backup o borra nuestro `AutoConfigURL`.
  - `bool IsOurPacActive(int port)` — true si `AutoConfigURL == http://127.0.0.1:{port}/proxy.pac` (para anti-manipulación y estado).

- [ ] **Step 1: Recuperar la versión con PAC del historial**

Run:
```bash
git show 10f03cc~1:AZCKeeper_Client/Blocking/SystemProxyManager.cs > AZCKeeper_Client/Blocking/SystemProxyManager.cs
```
Esto restaura `EnablePac`, `Restore`, `BackupCurrentSettingsIfNeeded`, `LoadBackup`, `RefreshWinInetSettings`, `ProxyBackup` (versión pre-`10f03cc`, que NO tiene `MigrateAwayFromPac`).

- [ ] **Step 2: Agregar `IsOurPacActive`**

En `SystemProxyManager.cs`, dentro de la clase, agregar el método (usa `InternetSettingsPath` ya existente):

```csharp
        /// <summary>True si el AutoConfigURL actual es el PAC que servimos en el puerto dado.</summary>
        public bool IsOurPacActive(int port)
        {
            try
            {
                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: false);
                string url = key?.GetValue("AutoConfigURL", string.Empty)?.ToString() ?? string.Empty;
                return url.Equals($"http://127.0.0.1:{port}/proxy.pac", StringComparison.OrdinalIgnoreCase);
            }
            catch { return false; }
        }
```

- [ ] **Step 3: Verificar que compila**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: fallará por referencias a `LocalWebBlockProxy`/`BrowserPolicyBlocker` en `WebBlockingManager` (se arreglan en Task 4). **Verificar solo que `SystemProxyManager.cs` no aporte errores propios** (buscar en el output errores del archivo `SystemProxyManager.cs`; no debe haber).

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Blocking/SystemProxyManager.cs
git commit -m "feat(blocking): recupera EnablePac/Restore y agrega IsOurPacActive"
```

---

## Task 4: Reescribir WebBlockingManager a PAC + borrar BrowserPolicyBlocker

**Files:**
- Modify (reescritura completa): `AZCKeeper_Client/Blocking/WebBlockingManager.cs`
- Delete: `AZCKeeper_Client/Blocking/BrowserPolicyBlocker.cs`

**Interfaces:**
- Consumes: `PacContentBuilder.Build`, `LocalPacServer`, `SystemProxyManager.EnablePac/Restore/IsOurPacActive`, `ConfigManager.WebBlockingConfig`.
- Produces (para el DebugSnapshot):
  - `bool Enabled { get; }`
  - `int DomainCount { get; }`
  - `bool PacActive { get; }`
  - `int PacPort { get; }`
  - firmas existentes intactas: `Initialize(ConfigManager.WebBlockingConfig, string)`, `ApplyRemotePolicy(ConfigManager.WebBlockingConfig, int, string)`, `Shutdown()`, `string[] GetCachedDomains()`.

- [ ] **Step 1: Reescribir WebBlockingManager**

Reemplazar TODO el contenido de `AZCKeeper_Client/Blocking/WebBlockingManager.cs` por:

```csharp
using System;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Bloqueo web por PAC per-usuario (sin admin). Sirve un .pac local que manda los dominios
    /// designados (+ subdominios) a un puerto muerto y deja todo lo demás DIRECT. Persiste la
    /// última política para operar aunque la API no responda. Cierre limpio quita el PAC.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private static readonly string TracePath =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Logs", "webblocking-trace.log");

        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _systemProxy;
        private readonly LocalPacServer _pacServer;

        private WebBlockingCache _currentCache;

        public bool Enabled => _currentCache?.Enabled == true;
        public int DomainCount => _currentCache?.Domains?.Length ?? 0;
        public int PacPort => _pacServer.Port;
        public bool PacActive => Enabled && _pacServer.IsRunning && _systemProxy.IsOurPacActive(_pacServer.Port);

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _systemProxy = new SystemProxyManager(_cacheDirectory);
            _pacServer = new LocalPacServer(_cacheDirectory);
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            AppendTrace($"Initialize() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}");
            CleanupLegacyUrlBlocklist(); // best-effort: borra residuo del intento URLBlocklist (3.0.2.5/2.6)

            _currentCache = LoadCacheFromDisk() ?? BuildCache(config, config?.PolicyVersion ?? 0);
            ApplyLocalState(_currentCache, "startup");
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            AppendTrace($"ApplyRemotePolicy() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, PolicyVersion={policyVersion}");
            var next = BuildCache(config, policyVersion);

            bool unchanged = _currentCache != null &&
                _currentCache.PolicyVersion == next.PolicyVersion &&
                string.Equals(_currentCache.DomainsHash ?? "", next.DomainsHash ?? "", StringComparison.OrdinalIgnoreCase) &&
                _currentCache.Enabled == next.Enabled;

            if (unchanged) { Reassert(_currentCache); return; }

            SaveCacheToDisk(next);
            _currentCache = next;
            ApplyLocalState(_currentCache, "remote-policy");
        }

        public string[] GetCachedDomains() => _currentCache?.Domains ?? Array.Empty<string>();

        public void Shutdown()
        {
            // Cierre limpio: quitar el PAC para no dejar un AutoConfigURL colgado.
            try { _pacServer.Stop(); } catch { }
            try { _systemProxy.Restore(); } catch { }
        }

        private void ApplyLocalState(WebBlockingCache cache, string source)
        {
            AppendTrace($"ApplyLocalState() Source={source}, Enabled={cache?.Enabled}, Domains={(cache?.Domains?.Length ?? 0)}");
            if (cache == null) return;
            try
            {
                if (!cache.Enabled || cache.Domains.Length == 0)
                {
                    _pacServer.Stop();
                    _systemProxy.Restore();
                    LocalLogger.Info($"WebBlockingManager: bloqueo web deshabilitado ({source}).");
                    return;
                }

                string pac = PacContentBuilder.Build(cache.Domains);
                int port = _pacServer.StartOrUpdate(pac);
                _systemProxy.EnablePac($"http://127.0.0.1:{port}/proxy.pac");
                LocalLogger.Info($"WebBlockingManager: PAC aplicado ({source}). Port={port}, Domains={cache.Domains.Length}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"WebBlockingManager: error aplicando política ({source}).");
            }
        }

        // Re-aplicación silenciosa (anti-manipulación por handshake).
        private void Reassert(WebBlockingCache cache)
        {
            try
            {
                if (cache == null || !cache.Enabled || cache.Domains.Length == 0)
                {
                    if (_pacServer.IsRunning) { _pacServer.Stop(); _systemProxy.Restore(); }
                    return;
                }
                string pac = PacContentBuilder.Build(cache.Domains);
                int port = _pacServer.StartOrUpdate(pac);
                _pacServer.UpdatePac(pac);
                if (!_systemProxy.IsOurPacActive(port))
                    _systemProxy.EnablePac($"http://127.0.0.1:{port}/proxy.pac");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.Reassert(): error.");
            }
        }

        private static void CleanupLegacyUrlBlocklist()
        {
            string[] roots =
            {
                @"SOFTWARE\Policies\Google\Chrome\URLBlocklist",
                @"SOFTWARE\Policies\Microsoft\Edge\URLBlocklist",
                @"SOFTWARE\Policies\BraveSoftware\Brave\URLBlocklist",
            };
            foreach (string r in roots)
            {
                try { Registry.CurrentUser.DeleteSubKeyTree(r, throwOnMissingSubKey: false); } catch { }
            }
        }

        private WebBlockingCache BuildCache(ConfigManager.WebBlockingConfig config, int policyVersion)
        {
            var domains = (config?.Domains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                .ToArray();

            return new WebBlockingCache
            {
                Enabled = config?.Enabled == true && domains.Length > 0,
                SyncIntervalSeconds = Math.Max(300, config?.SyncIntervalSeconds ?? 600),
                PolicyVersion = Math.Max(0, policyVersion),
                LastUpdatedUtc = DateTime.UtcNow.ToString("O"),
                Domains = domains,
                DomainsHash = ComputeDomainsHash(domains)
            };
        }

        private WebBlockingCache LoadCacheFromDisk()
        {
            try
            {
                if (!File.Exists(_cacheFilePath)) return null;
                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json)) return null;
                var cache = JsonSerializer.Deserialize<WebBlockingCache>(json);
                if (cache == null) return null;
                cache.Domains ??= Array.Empty<string>();
                cache.DomainsHash ??= ComputeDomainsHash(cache.Domains);
                cache.SyncIntervalSeconds = Math.Max(300, cache.SyncIntervalSeconds);
                return cache;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.LoadCacheFromDisk(): error.");
                return null;
            }
        }

        private void SaveCacheToDisk(WebBlockingCache cache)
        {
            try
            {
                Directory.CreateDirectory(_cacheDirectory);
                string json = JsonSerializer.Serialize(cache, new JsonSerializerOptions { WriteIndented = true });
                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);
                if (File.Exists(_cacheFilePath)) File.Delete(_cacheFilePath);
                File.Move(tmp, _cacheFilePath);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.SaveCacheToDisk(): error.");
            }
        }

        private static string ComputeDomainsHash(string[] domains)
        {
            using var sha = SHA256.Create();
            string joined = string.Join("\n", domains ?? Array.Empty<string>());
            return Convert.ToHexString(sha.ComputeHash(Encoding.UTF8.GetBytes(joined)));
        }

        private static void AppendTrace(string message)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(TracePath) ?? ".");
                File.AppendAllText(TracePath, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} {message}{Environment.NewLine}");
            }
            catch { }
        }

        private sealed class WebBlockingCache
        {
            public bool Enabled { get; set; }
            public int SyncIntervalSeconds { get; set; }
            public int PolicyVersion { get; set; }
            public string LastUpdatedUtc { get; set; }
            public string DomainsHash { get; set; }
            public string[] Domains { get; set; } = Array.Empty<string>();
        }
    }
}
```

- [ ] **Step 2: Borrar BrowserPolicyBlocker**

Run: `git rm AZCKeeper_Client/Blocking/BrowserPolicyBlocker.cs`

- [ ] **Step 3: Verificar build del cliente**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: PASS (0 errores). Si aparece error por `MigrateAwayFromPac` referenciado en `CoreService.cs`, no debería (solo se llamaba dentro de `WebBlockingManager.Initialize`, ya reescrito).

- [ ] **Step 4: Correr toda la suite**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Blocking/WebBlockingManager.cs
git rm AZCKeeper_Client/Blocking/BrowserPolicyBlocker.cs
git commit -m "feat(blocking): WebBlockingManager por PAC; elimina URLBlocklist"
```

---

## Task 5: Verificación end-to-end del bloqueo (manual/scripted)

**Files:** ninguno (verificación).

- [ ] **Step 1: Build de escritorio**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: PASS.

- [ ] **Step 2: Ejecutar y aplicar política con dominios de prueba**

Correr el cliente (o esperar handshake con política Enabled). Con la política activa, verificar la resolución del sistema:

```powershell
$p=[System.Net.WebRequest]::GetSystemWebProxy()
foreach($u in @("https://www.facebook.com/","https://x.com/","https://www.google.com/","https://www.irs.gov/")){
  $uri=[Uri]$u; $r=$p.GetProxy($uri)
  "{0,-22} -> {1}" -f $uri.Host, ($(if($r.AbsoluteUri -eq $uri.AbsoluteUri){"DIRECT"}else{"PROXY "+$r.Authority}))
}
```
Expected: facebook/x → `PROXY 127.0.0.1:9`; google/irs.gov → `DIRECT`.

- [ ] **Step 3: Verificación en navegador**

Abrir facebook.com (no carga) y google.com + una página gov (cargan). Confirmar que un iframe embebido de un dominio bloqueado queda roto sin romper la página contenedora.

- [ ] **Step 4: Reinicio y cierre limpio**

Reiniciar el cliente → el PAC se re-sirve en el mismo puerto (revisar `AutoConfigURL`). Cerrar el cliente limpio (no `taskkill /F`) → `AutoConfigURL` desaparece:

```powershell
(Get-ItemProperty "HKCU:\Software\Microsoft\Windows\CurrentVersion\Internet Settings").AutoConfigURL
```

- [ ] **Step 5: Commit (si hubo ajustes)** — si no hubo cambios, saltar.

---

## Task 6: LocalLogger — buffer circular de issues

**Files:**
- Modify: `AZCKeeper_Client/Logging/LocalLogger.cs`
- Test: `AZCKeeper.Tests/LocalLoggerRingBufferTests.cs`

**Interfaces:**
- Produces:
  - `static IReadOnlyList<string> GetRecentIssues()` → últimos ~15 Warn/Error formateados `"HH:mm:ss [LVL] msg"`, más reciente último.

- [ ] **Step 1: Escribir el test que falla**

Create `AZCKeeper.Tests/LocalLoggerRingBufferTests.cs`:

```csharp
using System.Linq;
using AZCKeeper_Cliente.Logging;
using Xunit;

namespace AZCKeeper.Tests
{
    public class LocalLoggerRingBufferTests
    {
        [Fact]
        public void Captures_warn_and_error_but_not_info()
        {
            LocalLogger.Warn("prueba-warn-xyz");
            LocalLogger.Error("prueba-error-xyz");
            LocalLogger.Info("prueba-info-xyz");

            var issues = GetRecentIssues();
            Assert.Contains(issues, s => s.Contains("prueba-warn-xyz"));
            Assert.Contains(issues, s => s.Contains("prueba-error-xyz"));
            Assert.DoesNotContain(issues, s => s.Contains("prueba-info-xyz"));
        }

        [Fact]
        public void Keeps_at_most_15()
        {
            for (int i = 0; i < 30; i++) LocalLogger.Warn($"linea-{i}");
            Assert.True(GetRecentIssues().Count <= 15);
        }

        private static System.Collections.Generic.IReadOnlyList<string> GetRecentIssues()
            => LocalLogger.GetRecentIssues();
    }
}
```

- [ ] **Step 2: Correr para verificar que falla**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter LocalLoggerRingBufferTests`
Expected: FAIL de compilación ("GetRecentIssues no existe").

- [ ] **Step 3: Implementar el buffer (captura pre-filtro)**

La captura va en `Warn`/`Error` **antes** de `ShouldLog`, para que el buffer registre los issues aunque el nivel de log filtre la salida a archivo (que es justo el caso en producción, con nivel `Warn`).

(a) Agregar campos junto a los otros `private static` (cerca de la línea 34):

```csharp
        private const int RecentIssuesMax = 15;
        private static readonly System.Collections.Generic.Queue<string> _recentIssues =
            new System.Collections.Generic.Queue<string>();
        private static readonly object _recentIssuesLock = new object();
```

(b) Agregar el helper de captura y el accessor público (cerca de los otros métodos):

```csharp
        private static void CaptureIssue(LogLevel level, string message)
        {
            lock (_recentIssuesLock)
            {
                _recentIssues.Enqueue($"{DateTime.Now:HH:mm:ss} [{level}] {message}");
                while (_recentIssues.Count > RecentIssuesMax) _recentIssues.Dequeue();
            }
        }

        /// <summary>Últimos Warn/Error para diagnóstico en la ventana Debug (más reciente al final).</summary>
        public static System.Collections.Generic.IReadOnlyList<string> GetRecentIssues()
        {
            lock (_recentIssuesLock) { return _recentIssues.ToArray(); }
        }
```

(c) Llamar a `CaptureIssue` como **primera línea** de cada método (antes del `if (!ShouldLog(...))`):
- `Warn(string message)`: `CaptureIssue(LogLevel.Warn, message);`
- `Error(string message)`: `CaptureIssue(LogLevel.Error, message);`
- `Error(Exception exception, string contextMessage)`: `CaptureIssue(LogLevel.Error, contextMessage ?? exception?.Message ?? "error");`

No tocar `WriteLog` ni `Info` (Info no se captura).

- [ ] **Step 4: Correr los tests**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj --filter LocalLoggerRingBufferTests`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Logging/LocalLogger.cs AZCKeeper.Tests/LocalLoggerRingBufferTests.cs
git commit -m "feat(logging): buffer circular de Warn/Error para la ventana Debug"
```

---

## Task 7: Accessors de diagnóstico (ApiClient, UpdateManager)

**Files:**
- Modify: `AZCKeeper_Client/Network/ApiClient.cs`
- Modify: `AZCKeeper_Client/Update/UpdateManager.cs`

**Interfaces:**
- Produces:
  - ApiClient: `bool IsInBackoff { get; }`, `DateTime BackoffUntilUtc { get; }`, `int PendingQueueCount { get; }`.
  - UpdateManager: `string LastAvailableVersion { get; }`, `string LastMinimumVersion { get; }`, `bool LastCriticalFlag { get; }`, `string LastUpdateError { get; }`, `DateTime LastCheckUtc { get; }`.

- [ ] **Step 1: ApiClient accessors**

En `AZCKeeper_Client/Network/ApiClient.cs`, agregar (los campos `_backoffUntilUtc`, `_backoffConsecutiveFailures`, `_offlineQueue` ya existen):

```csharp
        public bool IsInBackoff { get { lock (_backoffLock) { return DateTime.UtcNow < _backoffUntilUtc; } } }
        public DateTime BackoffUntilUtc { get { lock (_backoffLock) { return _backoffUntilUtc; } } }
        public int PendingQueueCount { get { try { return _offlineQueue.GetPendingCount(); } catch { return -1; } } }
```

- [ ] **Step 2: UpdateManager accessors**

En `AZCKeeper_Client/Update/UpdateManager.cs`, agregar campos y props:

```csharp
        public string LastAvailableVersion { get; private set; } = "—";
        public string LastMinimumVersion { get; private set; } = "—";
        public bool LastCriticalFlag { get; private set; }
        public string LastUpdateError { get; private set; } = "";
        public DateTime LastCheckUtc { get; private set; } = DateTime.MinValue;
```

En el método de verificación (donde ya se calcula `latest`/`minimum` ~líneas 120-131), setear:

```csharp
                LastCheckUtc = DateTime.UtcNow;
                LastAvailableVersion = data.LatestVersion;
                LastMinimumVersion = minimum.ToString();
                LastCriticalFlag = current < minimum;
```

En el catch de `DownloadAndInstallAsync` (donde loguea "error al descargar/instalar"), setear `LastUpdateError = ex.Message;`. En un intento exitoso, `LastUpdateError = "";`.

- [ ] **Step 3: Verificar build**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Network/ApiClient.cs AZCKeeper_Client/Update/UpdateManager.cs
git commit -m "feat: accessors de diagnostico (backoff, cola, versiones) para Debug"
```

---

## Task 8: DebugSnapshot + estado de handshake en CoreService

**Files:**
- Create: `AZCKeeper_Client/Core/DebugSnapshot.cs`
- Modify: `AZCKeeper_Client/Core/CoreService.cs`

**Interfaces:**
- Produces:
  - `internal sealed class DebugSnapshot` con campos string/bool (ver abajo).
  - `CoreService` expone `DebugSnapshot BuildDebugSnapshot()` y rastrea `_lastHandshakeStatus`.

- [ ] **Step 1: Crear el DTO**

Create `AZCKeeper_Client/Core/DebugSnapshot.cs`:

```csharp
using System.Collections.Generic;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>Foto read-only del estado del cliente para la ventana Debug.</summary>
    internal sealed class DebugSnapshot
    {
        // Versión + auto-update
        public string RunningVersion;
        public string AvailableVersion;
        public string MinimumVersion;
        public string UpdateStatus;      // OK / error

        // API + conexión
        public string ApiBaseUrl;
        public string LastHandshake;     // "12:33:52 (hace 8s)" | "Nunca"
        public string HandshakeStatus;   // OK / HTTP 4xx / error
        public string BackoffStatus;     // "activo hasta HH:mm:ss" | "no"

        // Cola + errores
        public int QueuePending;
        public IReadOnlyList<string> RecentIssues;

        // Web-blocking + Auth
        public bool WebBlockEnabled;
        public int WebBlockDomains;
        public bool PacActive;
        public string DeviceId;
        public string UserName;
        public bool HasToken;
    }
}
```

- [ ] **Step 2: Rastrear estado de handshake en CoreService**

En `CoreService.cs`, junto a `_lastHandshakeTime` (línea ~55) agregar:

```csharp
        private string _lastHandshakeStatus = "—";
```

En `PerformHandshake`, tras un handshake OK (donde ya se setea `_lastHandshakeTime`), agregar `_lastHandshakeStatus = "OK";`. En los catch/paths de error del handshake, setear `_lastHandshakeStatus` al mensaje/código (ej. `_lastHandshakeStatus = "error: " + ex.Message;`).

- [ ] **Step 3: Builder del snapshot**

Agregar a `CoreService` el método:

```csharp
        internal DebugSnapshot BuildDebugSnapshot()
        {
            var cfg = _configManager?.CurrentConfig;
            var s = new DebugSnapshot
            {
                RunningVersion = cfg?.Version ?? "—",
                AvailableVersion = _updateManager?.LastAvailableVersion ?? "—",
                MinimumVersion = _updateManager?.LastMinimumVersion ?? "—",
                UpdateStatus = string.IsNullOrEmpty(_updateManager?.LastUpdateError) ? "OK" : _updateManager.LastUpdateError,
                ApiBaseUrl = cfg?.ApiBaseUrl ?? "—",
                HandshakeStatus = _lastHandshakeStatus,
                BackoffStatus = _apiClient != null && _apiClient.IsInBackoff
                    ? "activo hasta " + _apiClient.BackoffUntilUtc.ToLocalTime().ToString("HH:mm:ss")
                    : "no",
                QueuePending = _apiClient?.PendingQueueCount ?? -1,
                RecentIssues = AZCKeeper_Cliente.Logging.LocalLogger.GetRecentIssues(),
                WebBlockEnabled = _webBlockingManager?.Enabled ?? false,
                WebBlockDomains = _webBlockingManager?.DomainCount ?? 0,
                PacActive = _webBlockingManager?.PacActive ?? false,
                DeviceId = cfg?.DeviceId ?? "—",
                UserName = cfg?.UserDisplayName ?? "—",
                HasToken = _authManager?.HasToken ?? false,
            };

            if (_lastHandshakeTime == DateTime.MinValue) s.LastHandshake = "Nunca";
            else s.LastHandshake = $"{_lastHandshakeTime:HH:mm:ss} (hace {(DateTime.Now - _lastHandshakeTime).TotalSeconds:F0}s)";
            return s;
        }
```

- [ ] **Step 4: Verificar build**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Core/DebugSnapshot.cs AZCKeeper_Client/Core/CoreService.cs
git commit -m "feat(debug): DebugSnapshot + estado de handshake en CoreService"
```

---

## Task 9: Extraer y reescribir DebugWindowForm (compacta + secciones)

**Files:**
- Create: `AZCKeeper_Client/Core/DebugWindowForm.cs`
- Modify: `AZCKeeper_Client/Core/CoreService.cs` (borrar la clase `DebugWindowForm` inline)

**Interfaces:**
- Consumes: `ActivityTracker`, `WindowTracker`, `Func<DebugSnapshot>`.
- Produces: `internal class DebugWindowForm : Form` con constructor `(ActivityTracker, WindowTracker, Func<DebugSnapshot>)`.

- [ ] **Step 1: Borrar la clase inline en CoreService**

En `AZCKeeper_Client/Core/CoreService.cs`, eliminar completa la clase `internal class DebugWindowForm : Form { ... }` (desde el comentario `/// DebugWindowForm:` ~línea 1384 hasta su llave de cierre). Dejar el `FormatSeconds` si es usado por otras clases (no lo es fuera del form → se mueve al form nuevo).

- [ ] **Step 2: Crear el form nuevo**

Create `AZCKeeper_Client/Core/DebugWindowForm.cs`:

```csharp
using System;
using System.Drawing;
using System.Windows.Forms;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Tracking;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>
    /// Ventana de diagnóstico compacta: tracking de tiempo + versión/API/cola/errores/web-blocking/auth.
    /// Read-only; hace pull de un DebugSnapshot cada segundo.
    /// </summary>
    internal class DebugWindowForm : Form
    {
        private readonly ActivityTracker _activity;
        private readonly WindowTracker _window;
        private readonly Func<DebugSnapshot> _getSnapshot;
        private readonly System.Windows.Forms.Timer _timer;

        private readonly Label _diag = NewMono();
        private readonly Label _issues = NewMono();
        private readonly Label _tracking = NewMono();

        public DebugWindowForm(ActivityTracker activity, WindowTracker window, Func<DebugSnapshot> getSnapshot)
        {
            _activity = activity;
            _window = window;
            _getSnapshot = getSnapshot;

            Text = "AZCKeeper - Debug Activity";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(760, 560);
            FormBorderStyle = FormBorderStyle.Sizable;
            MaximizeBox = true;

            var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 2, Padding = new Padding(6) };
            root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 55));
            root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 45));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 60));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 40));

            root.Controls.Add(Titled("Estado", _diag), 0, 0);
            root.Controls.Add(Titled("Tracking de tiempo", _tracking), 1, 0);
            var issuesWrap = Titled("Últimos errores / warnings", _issues);
            root.Controls.Add(issuesWrap, 0, 1);
            root.SetColumnSpan(issuesWrap, 2);

            Controls.Add(root);

            _timer = new System.Windows.Forms.Timer { Interval = 1000 };
            _timer.Tick += (s, e) => Refresh_();
            _timer.Start();
            Refresh_();
        }

        private static Label NewMono() => new Label
        {
            AutoSize = false,
            Dock = DockStyle.Fill,
            Font = new Font("Consolas", 8.5F),
            Padding = new Padding(4),
        };

        private static Control Titled(string title, Control body)
        {
            var box = new GroupBox { Text = title, Dock = DockStyle.Fill, Padding = new Padding(4) };
            box.Controls.Add(body);
            return box;
        }

        private void Refresh_()
        {
            try
            {
                var s = _getSnapshot != null ? _getSnapshot() : null;
                _diag.Text = BuildDiag(s);
                _issues.Text = s?.RecentIssues != null && s.RecentIssues.Count > 0
                    ? string.Join(Environment.NewLine, s.RecentIssues)
                    : "(sin errores recientes)";
                _issues.ForeColor = (s?.RecentIssues?.Count ?? 0) > 0 ? Color.Firebrick : Color.Gray;
                _tracking.Text = BuildTracking();
            }
            catch (Exception ex)
            {
                LocalLogger.Warn($"DebugWindowForm.Refresh_(): {ex.Message}");
            }
        }

        private static string BuildDiag(DebugSnapshot s)
        {
            if (s == null) return "(sin datos)";
            string ok(bool b) => b ? "sí" : "NO";
            return string.Join(Environment.NewLine, new[]
            {
                "— Versión / update —",
                $"  corriendo:  {s.RunningVersion}",
                $"  disponible: {s.AvailableVersion}   mínima: {s.MinimumVersion}",
                $"  update:     {s.UpdateStatus}",
                "",
                "— API / conexión —",
                $"  api:        {s.ApiBaseUrl}",
                $"  handshake:  {s.LastHandshake}",
                $"  estado:     {s.HandshakeStatus}",
                $"  backoff:    {s.BackoffStatus}",
                "",
                "— Cola —",
                $"  pendientes: {s.QueuePending}",
                "",
                "— Web-blocking / Auth —",
                $"  bloqueo:    {ok(s.WebBlockEnabled)}   dominios: {s.WebBlockDomains}   PAC activo: {ok(s.PacActive)}",
                $"  device:     {s.DeviceId}",
                $"  usuario:    {s.UserName}   token: {ok(s.HasToken)}",
            });
        }

        private string BuildTracking()
        {
            if (_activity == null) return "(tracker deshabilitado)";
            string win = _window != null
                ? $"[{_window.LastProcessName}] {_window.LastWindowTitle}"
                : "(WindowTracker off)";
            return string.Join(Environment.NewLine, new[]
            {
                $"inicio:   {(_activity.StartLocalTime == default ? "—" : _activity.StartLocalTime.ToString("HH:mm:ss"))}",
                $"ahora:    {DateTime.Now:HH:mm:ss}",
                $"sesión:   act {FormatSeconds(_activity.SessionActiveSeconds)} / inact {FormatSeconds(_activity.SessionInactiveSeconds)}",
                $"día:      act {FormatSeconds(_activity.CurrentDayActiveSeconds)} / inact {FormatSeconds(_activity.CurrentDayInactiveSeconds)}",
                $"ventana:  {win}",
            });
        }

        private static string FormatSeconds(double seconds)
        {
            var ts = TimeSpan.FromSeconds(seconds < 0 ? 0 : seconds);
            return $"{(int)ts.TotalHours:00}:{ts.Minutes:00}:{ts.Seconds:00}";
        }

        protected override void OnFormClosed(FormClosedEventArgs e)
        {
            try { _timer?.Stop(); _timer?.Dispose(); } catch { }
            base.OnFormClosed(e);
        }
    }
}
```

> Verificar los nombres reales de propiedades de `ActivityTracker`/`WindowTracker` usados aquí
> (`StartLocalTime`, `SessionActiveSeconds`, `SessionInactiveSeconds`, `CurrentDayActiveSeconds`,
> `CurrentDayInactiveSeconds`, `LastProcessName`, `LastWindowTitle`) contra el código actual (se leyeron
> de la clase inline vieja); ajustar si alguno difiere.

- [ ] **Step 3: Actualizar la creación en CoreService.InitializeModules**

En `CoreService.cs` (~línea 949), reemplazar:

```csharp
                    _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, () => _lastHandshakeTime);
```

por:

```csharp
                    _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, BuildDebugSnapshot);
```

- [ ] **Step 4: Verificar build**

Run: `dotnet build AZCKeeper_Client/AZCKeeper_Client.csproj -c Debug`
Expected: PASS.

- [ ] **Step 5: Prueba manual de la UI**

Correr el cliente con `EnableDebugWindow=true`. Verificar: la ventana muestra las secciones Estado / Tracking / Errores; versión, API, handshake, cola, web-blocking/auth pueblan; los errores recientes salen en rojo; refresca cada segundo.

- [ ] **Step 6: Commit**

```bash
git add AZCKeeper_Client/Core/DebugWindowForm.cs AZCKeeper_Client/Core/CoreService.cs
git commit -m "feat(debug): ventana Debug compacta con diagnosticos (version/api/cola/errores/blocking/auth)"
```

---

## Task 10: Suite completa + verificación final

- [ ] **Step 1: Suite de tests**

Run: `dotnet test AZCKeeper.Tests/AZCKeeper.Tests.csproj`
Expected: PASS (todos).

- [ ] **Step 2: Build release-like**

Run: `dotnet build AZCKeeper.sln -c Release`
Expected: PASS (0 errores; los warnings nullable preexistentes del updater son tolerados).

- [ ] **Step 3: Checklist manual final**

Repetir Task 5 (bloqueo preciso, gov DIRECT, reinicio, cierre limpio) + Task 9 Step 5 (ventana Debug) sobre el build.

- [ ] **Step 4: Commit final / merge de la rama** — seguir `superpowers:finishing-a-development-branch`.

---

## Notas de ejecución
- Los tests automatizados cubren la lógica pura: `PacContentBuilder` (tabla de precisión), `LocalPacServer` (sirve PAC + puerto persistido), `LocalLogger` (buffer). El registro/WinInet, la resolución real del sistema y la UI se verifican manual/scripted (Tasks 5, 9), porque mutan estado de máquina o son WinForms.
- El bloqueo real solo se puede validar en Windows con navegador; en Linux el cliente ni compila (falta WindowsDesktop SDK) — usar `build-release.sh` o Windows.
