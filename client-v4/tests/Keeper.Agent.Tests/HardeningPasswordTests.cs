using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class HardeningPasswordTests
{
    private const string DataDirectory = @"C:\ProgramData\AZCKeeper\v4";
    private static readonly string PasswordPath = Path.Combine(DataDirectory, "hardening", "password.dpapi");
    private const string RequiredSddl = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)";

    [Fact]
    public void WritesUtf8WithoutBomOrAddedNewlineAndDoesNotRewriteSamePassword()
    {
        var files = new FakeFiles();
        var protector = new FakeProtector();
        var module = new HardeningPasswordModule(DataDirectory, files, protector);
        const string password = " á日本🔑\\\" ";
        var json = JsonSerializer.SerializeToUtf8Bytes(new { shared_password = password });

        module.ApplyResponse(json, default);
        var original = files.Blobs[PasswordPath];
        Assert.Equal(Encoding.UTF8.GetBytes(password), FakeProtector.Decode(original));
        Assert.Equal(RequiredSddl, files.Acls[PasswordPath]);
        Assert.Equal(["prepare", "write", "move"], files.Operations);
        Assert.Single(files.Blobs);
        Assert.All(protector.PlaintextBuffers, AssertZero);

        module.ApplyResponse(json, default);
        Assert.Same(original, files.Blobs[PasswordPath]);
        Assert.Equal(1, files.Writes);
        Assert.Equal(1, protector.ProtectCalls);
        Assert.All(protector.PlaintextBuffers, AssertZero);

        module.ApplyResponse("{\"shared_password\":\"rotated\"}"u8, default);
        Assert.Equal("rotated"u8.ToArray(), FakeProtector.Decode(files.Blobs[PasswordPath]));
        Assert.Equal(2, files.Writes);
        Assert.Single(files.Blobs);
        Assert.All(protector.PlaintextBuffers, AssertZero);
    }

    [Theory]
    [InlineData("write")]
    [InlineData("move")]
    [InlineData("protect")]
    [InlineData("unprotect")]
    public void FailuresPreservePreviousFileCleanTemporaryAndZeroPlaintext(string failure)
    {
        var files = new FakeFiles();
        var protector = new FakeProtector();
        var module = new HardeningPasswordModule(DataDirectory, files, protector);
        module.ApplyResponse("{\"shared_password\":\"old\"}"u8, default);
        var original = files.Blobs[PasswordPath];
        files.Failure = failure;
        protector.Failure = failure;

        Assert.ThrowsAny<Exception>(() => module.ApplyResponse("{\"shared_password\":\"new\"}"u8, default));

        Assert.Same(original, files.Blobs[PasswordPath]);
        Assert.Single(files.Blobs);
        Assert.All(protector.PlaintextBuffers, AssertZero);
    }

    [Fact]
    public void CancellationBeforeMovePreservesOldFileAndRemovesTemporary()
    {
        var files = new FakeFiles();
        var protector = new FakeProtector();
        var module = new HardeningPasswordModule(DataDirectory, files, protector);
        module.ApplyResponse("{\"shared_password\":\"old\"}"u8, default);
        var original = files.Blobs[PasswordPath];
        using var cancelled = new CancellationTokenSource();
        files.AfterWrite = cancelled.Cancel;

        Assert.Throws<OperationCanceledException>(() => module.ApplyResponse("{\"shared_password\":\"new\"}"u8, cancelled.Token));

        Assert.Same(original, files.Blobs[PasswordPath]);
        Assert.Single(files.Blobs);
        Assert.All(protector.PlaintextBuffers, AssertZero);
    }

    [Theory]
    [InlineData("{}")]
    [InlineData("{\"shared_password\":null}")]
    [InlineData("{\"shared_password\":42}")]
    [InlineData("{\"shared_password\":\"\"}")]
    [InlineData("{\"shared_password\":\"secret\\u0000\"}")]
    [InlineData("{\"shared_password\":\"secret\",\"shared_password\":\"duplicate\"}")]
    [InlineData("{\"shared_password\":\"secret\"")]
    [InlineData("{\"shared_password\":\"secret\"} {}")]
    [InlineData("{\"nested\":{\"shared_password\":\"secret\"}}")]
    [InlineData("[]")]
    public void InvalidResponseDoesNotTouchFilesystemOrExposePassword(string json)
    {
        var files = new FakeFiles();
        var module = new HardeningPasswordModule(DataDirectory, files, new FakeProtector());
        var error = Assert.Throws<InvalidDataException>(() => module.ApplyResponse(Encoding.UTF8.GetBytes(json), default));
        Assert.Equal("invalid_hardening_response", error.Message);
        Assert.Null(error.InnerException);
        Assert.Empty(files.Operations);
    }

    [Theory]
    [InlineData("prepare")]
    [InlineData("security")]
    public void UnsafeFilesystemIsRejectedBeforeDecryptingOrWriting(string failure)
    {
        var files = new FakeFiles { Failure = failure };
        files.Blobs[PasswordPath] = [1];
        var protector = new FakeProtector();
        var module = new HardeningPasswordModule(DataDirectory, files, protector);

        Assert.Throws<IOException>(() => module.ApplyResponse("{\"shared_password\":\"secret\"}"u8, default));

        Assert.Empty(protector.PlaintextBuffers);
        Assert.Equal(0, files.Writes);
    }

    [Fact]
    public async Task FetchUsesRotatedAuthenticatedTokenAndVerifiableRfc9421Signature()
    {
        await using var fixture = new SyncFixture();
        using var verifier = ECDsa.Create(fixture.Key.ExportParameters(false));
        var nonces = new HashSet<string>();
        fixture.HardeningResponse = request =>
        {
            Assert.Equal(HttpMethod.Get, request.Method);
            Assert.Null(request.Content);
            Assert.Equal("Bearer rotated-token", request.Headers.Authorization!.ToString());
            var input = request.Headers.GetValues("Signature-Input").Single()[5..];
            Assert.StartsWith("(\"@method\" \"@target-uri\" \"authorization\");", input);
            Assert.True(nonces.Add(input));
            var signatureBase = "\"@method\": GET\n\"@target-uri\": https://keeper.test/v1/client/hardening\n" +
                "\"authorization\": Bearer rotated-token\n\"@signature-params\": " + input;
            var signature = Convert.FromBase64String(request.Headers.GetValues("Signature").Single()[6..^1]);
            Assert.True(verifier.VerifyData(Encoding.UTF8.GetBytes(signatureBase), signature, HashAlgorithmName.SHA256,
                DSASignatureFormat.IeeeP1363FixedFieldConcatenation));
            return fixture.SecretResponse("{\"admin_name\":\"azcadmin\",\"hardening_mode\":\"panel\",\"deny_network_logon\":true,\"shared_password\":\"日本\\u00e1\"}");
        };

        Assert.Equal(120, await fixture.Client.ExecuteAsync(default));
        Assert.Equal(4, fixture.Client.RequestCount);
        Assert.Equal(120, await fixture.Client.ExecuteAsync(default));
        Assert.Equal(5, fixture.Client.RequestCount);   // el segundo sync ya no repite GET /client/hardening
        Assert.Equal(1, fixture.Files.Writes);
        Assert.Equal(Encoding.UTF8.GetBytes("日本á"), FakeProtector.Decode(fixture.Files.Blobs[PasswordPath]));
        Assert.All(fixture.ResponseStreams, stream => AssertZero(stream.AgentBuffer));
        Assert.All(fixture.Protector.PlaintextBuffers, AssertZero);
    }

    [Fact]
    public async Task UnconfiguredHardeningSkipsFilesystemAndDoesNotFailSync()
    {
        await using var fixture = new SyncFixture();
        fixture.HardeningResponse = _ => new HttpResponseMessage(HttpStatusCode.Conflict);
        Assert.Equal(120, await fixture.Client.ExecuteAsync(default));
        Assert.Empty(fixture.Files.Operations);
        Assert.Empty(fixture.Protector.PlaintextBuffers);
    }

    [Fact]
    public async Task HardeningSeRefrescaCadaSeisHorasTrasExito()
    {
        await using var fixture = new SyncFixture();
        var calls = 0;
        fixture.HardeningResponse = _ => { calls++; return fixture.SecretResponse("{\"shared_password\":\"secret\"}"); };
        await fixture.Client.ExecuteAsync(default);
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(1, calls);
        fixture.Clock.Advance(TimeSpan.FromHours(6) - TimeSpan.FromSeconds(1));
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(1, calls);
        fixture.Clock.Advance(TimeSpan.FromSeconds(1));
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(2, calls);
    }

    [Fact]
    public async Task SinClaveCargadaReintentaCadaQuinceMinutos()
    {
        // IT carga la clave y enseguida corre --harden: no puede esperar 6 horas.
        await using var fixture = new SyncFixture();
        var calls = 0;
        fixture.HardeningResponse = _ => { calls++; return new HttpResponseMessage(HttpStatusCode.Conflict); };
        await fixture.Client.ExecuteAsync(default);
        fixture.Clock.Advance(TimeSpan.FromMinutes(14));
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(1, calls);
        fixture.Clock.Advance(TimeSpan.FromMinutes(1));
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(2, calls);
    }

    [Fact]
    public async Task TrasUnErrorReintentaEnElSiguienteSync()
    {
        await using var fixture = new SyncFixture();
        var calls = 0;
        fixture.HardeningResponse = _ => { calls++; return new HttpResponseMessage(HttpStatusCode.ServiceUnavailable); };
        await Assert.ThrowsAsync<TransportException>(() => fixture.Client.ExecuteAsync(default));
        fixture.HardeningResponse = _ => { calls++; return new HttpResponseMessage(HttpStatusCode.Conflict); };
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(2, calls);
    }

    [Fact]
    public async Task UnauthorizedHardeningClearsTokenAndNextCycleLogsInAgain()
    {
        await using var fixture = new SyncFixture();
        fixture.HardeningResponse = _ => new HttpResponseMessage(HttpStatusCode.Unauthorized);
        await Assert.ThrowsAsync<TransportException>(() => fixture.Client.ExecuteAsync(default));
        Assert.Equal(1, fixture.TokenStore.Deletes);
        Assert.Null(fixture.TokenStore.Value);
        fixture.HardeningResponse = _ => new HttpResponseMessage(HttpStatusCode.Conflict);
        await fixture.Client.ExecuteAsync(default);
        Assert.Equal(2, fixture.Logins);
        Assert.Empty(fixture.Files.Operations);
    }

    [Fact]
    public async Task RateLimitPreservesRetryAfterAndDoesNotWrite()
    {
        await using var fixture = new SyncFixture();
        fixture.HardeningResponse = _ =>
        {
            var response = new HttpResponseMessage(HttpStatusCode.TooManyRequests);
            response.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromMinutes(7));
            return response;
        };
        var error = await Assert.ThrowsAsync<TransportException>(() => fixture.Client.ExecuteAsync(default));
        Assert.Equal(TimeSpan.FromMinutes(7), error.RetryAfter);
        Assert.Empty(fixture.Files.Operations);
    }

    [Theory]
    [InlineData("invalid")]
    [InlineData("oversized")]
    [InlineData("read")]
    [InlineData("write")]
    [InlineData("cancel")]
    public async Task ResponseBufferIsErasedEvenOnReadParseOrStorageFailure(string failure)
    {
        await using var fixture = new SyncFixture();
        using var cancelled = new CancellationTokenSource();
        fixture.HardeningResponse = _ =>
        {
            var json = failure == "oversized" ? new string(' ', 16385) :
                failure == "invalid" ? "{\"shared_password\":\"secret\",bad}" : "{\"shared_password\":\"secret\"}";
            var response = fixture.SecretResponse(json);
            var stream = fixture.ResponseStreams.Last();
            stream.FailRead = failure == "read";
            if (failure == "cancel") stream.AfterRead = cancelled.Cancel;
            return response;
        };
        fixture.Files.Failure = failure;

        await Assert.ThrowsAnyAsync<Exception>(() => fixture.Client.ExecuteAsync(cancelled.Token));

        Assert.All(fixture.ResponseStreams, stream => AssertZero(stream.AgentBuffer));
        Assert.All(fixture.Protector.PlaintextBuffers, AssertZero);
        Assert.Empty(fixture.Files.Blobs);
    }

    private static void AssertZero(byte[] bytes) => Assert.All(bytes, value => Assert.Equal(0, value));
    private static void AssertZero(Memory<byte> bytes) => AssertZero(bytes.ToArray());

    private sealed class FakeProtector : IHardeningPasswordProtector
    {
        public string? Failure { get; set; }
        public int ProtectCalls { get; private set; }
        public List<byte[]> PlaintextBuffers { get; } = [];
        public byte[] Protect(byte[] plaintext)
        {
            ProtectCalls++;
            PlaintextBuffers.Add(plaintext);
            if (Failure == "protect") throw new CryptographicException("test");
            return plaintext.Select(value => (byte)(value ^ 0xA5)).ToArray();
        }
        public byte[] Unprotect(byte[] encrypted)
        {
            if (Failure == "unprotect") throw new CryptographicException("test");
            var bytes = Decode(encrypted);
            PlaintextBuffers.Add(bytes);
            return bytes;
        }
        public static byte[] Decode(byte[] encrypted) => encrypted.Select(value => (byte)(value ^ 0xA5)).ToArray();
    }

    private sealed class FakeFiles : IHardeningPasswordFileSystem
    {
        public Dictionary<string, byte[]> Blobs { get; } = [];
        public Dictionary<string, string> Acls { get; } = [];
        public List<string> Operations { get; } = [];
        public string? Failure { get; set; }
        public Action? AfterWrite { get; set; }
        public int Writes { get; private set; }
        public void PrepareDirectory(string directory)
        {
            Assert.Equal(Path.GetDirectoryName(PasswordPath), directory);
            Operations.Add("prepare");
            if (Failure == "prepare") throw new IOException("hardening_reparse_point");
        }
        public bool Exists(string path) => Blobs.ContainsKey(path);
        public void EnsureFileSecurity(string path, string sddl)
        {
            Operations.Add("security");
            if (Failure == "security") throw new IOException("hardening_file_acl_not_private");
            Assert.Equal(RequiredSddl, sddl);
            Acls[path] = sddl;
        }
        public byte[] ReadAllBytes(string path) => Blobs[path].ToArray();
        public void WriteNew(string path, byte[] encrypted, string sddl)
        {
            Assert.Equal(RequiredSddl, sddl);
            Assert.Equal(Path.GetDirectoryName(PasswordPath), Path.GetDirectoryName(path));
            Assert.EndsWith(".tmp", path);
            Operations.Add("write");
            Writes++;
            Blobs.Add(path, encrypted.ToArray());
            Acls.Add(path, sddl);
            if (Failure == "write") throw new IOException("test_partial_write");
            AfterWrite?.Invoke();
        }
        public void Move(string source, string destination)
        {
            Assert.Equal(RequiredSddl, Acls[source]);
            Operations.Add("move");
            if (Failure == "move") throw new IOException("test_move");
            Blobs[destination] = Blobs[source];
            Acls[destination] = Acls[source];
            Blobs.Remove(source);
            Acls.Remove(source);
        }
        public void Delete(string path) { Blobs.Remove(path); Acls.Remove(path); }
    }

    private sealed class SyncFixture : IAsyncDisposable
    {
        private readonly TestDirectory directory = new();
        private readonly DurableOutbox outbox;
        private readonly HttpMessageSigner signer;
        private readonly ModuleHost host;
        private readonly HttpClient http;
        public ECDsa Key { get; } = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        public FakeFiles Files { get; } = new();
        public FakeProtector Protector { get; } = new();
        public MemoryTokenStore TokenStore { get; } = new();
        public List<ObservedStream> ResponseStreams { get; } = [];
        public SyncClient Client { get; }
        public int Logins { get; private set; }
        public Func<HttpRequestMessage, HttpResponseMessage> HardeningResponse { get; set; } = _ => throw new InvalidOperationException();
        public TestClock Clock { get; } = new();

        public SyncFixture()
        {
            outbox = new DurableOutbox(directory.File("outbox.json"));
            signer = new HttpMessageSigner(Key);
            var clock = Clock;
            host = new ModuleHost([], Samples.Context(clock));
            var token = new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "initial-token", TokenType = "Bearer", ExpiresIn = 3600 };
            http = new HttpClient(new Handler(request =>
            {
                switch (request.RequestUri!.AbsolutePath)
                {
                    case "/v1/client/auth/challenges":
                        return Json(new Challenge { Nonce = "challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) });
                    case "/v1/client/login":
                        Logins++;
                        return Json(token);
                    case "/v1/client/sync":
                        return Json(new SyncResponse
                        {
                            ProtocolVersion = 1, ServerTime = clock.GetUtcNow(), NextSyncAfterSeconds = 120, PolicyVersion = 1,
                            Policy = Samples.Policy(), Token = token with { AccessToken = "rotated-token" }, Release = null,
                            Commands = [], EpisodeAcks = [], LogAcks = [], CommandAcks = [], SecurityAck = null, ActivityAck = null
                        });
                    case "/v1/client/hardening": return HardeningResponse(request);
                    default: throw new InvalidOperationException("Unexpected request");
                }
            })) { BaseAddress = new Uri("https://keeper.test/v1/") };
            Client = new SyncClient(http, signer, Samples.Device, outbox,
                new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device), clock, tokenStore: TokenStore)
            {
                HardeningPassword = new HardeningPasswordModule(DataDirectory, Files, Protector)
            };
        }

        public HttpResponseMessage SecretResponse(string json)
        {
            var stream = new ObservedStream(Encoding.UTF8.GetBytes(json));
            ResponseStreams.Add(stream);
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StreamContent(stream) };
        }
        public async ValueTask DisposeAsync()
        {
            http.Dispose();
            signer.Dispose();
            await host.DisposeAsync();
            outbox.Dispose();
            directory.Dispose();
        }
        private static HttpResponseMessage Json<T>(T value) => new(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options))
        };
    }

    private sealed class MemoryTokenStore : IDeviceTokenStore
    {
        public StoredDeviceToken? Value { get; private set; }
        public int Deletes { get; private set; }
        public Task<StoredDeviceToken?> LoadAsync(CancellationToken ct) => Task.FromResult(Value);
        public Task SaveAsync(StoredDeviceToken token, CancellationToken ct) { Value = token; return Task.CompletedTask; }
        public Task DeleteAsync(CancellationToken ct) { Value = null; Deletes++; return Task.CompletedTask; }
    }

    private sealed class Handler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct) => Task.FromResult(respond(request));
    }

    private sealed class ObservedStream(byte[] bytes) : MemoryStream(bytes)
    {
        public override bool CanSeek => false;
        public Memory<byte> AgentBuffer { get; private set; }
        public bool FailRead { get; set; }
        public Action? AfterRead { get; set; }
        public override async ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken ct = default)
        {
            if (AgentBuffer.IsEmpty) AgentBuffer = buffer;
            var read = await base.ReadAsync(buffer, ct);
            if (FailRead) throw new IOException("test_read");
            AfterRead?.Invoke();
            return read;
        }
    }
}
