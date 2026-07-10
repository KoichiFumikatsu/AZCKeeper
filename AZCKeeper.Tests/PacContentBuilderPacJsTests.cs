using AZCKeeper_Cliente.Blocking;
using Jint;
using Xunit;

namespace AZCKeeper.Tests
{
    public class PacContentBuilderPacJsTests
    {
        private static readonly string[] Domains = { "facebook.com", "x.com" };

        [Theory]
        [InlineData("facebook.com", "PROXY 127.0.0.1:9")]
        [InlineData("www.facebook.com", "PROXY 127.0.0.1:9")]
        [InlineData("m.facebook.com", "PROXY 127.0.0.1:9")]
        [InlineData("x.com", "PROXY 127.0.0.1:9")]
        [InlineData("notfacebook.com", "DIRECT")]
        [InlineData("facebook.com.evil.com", "DIRECT")]
        [InlineData("fbcdn.net", "DIRECT")]
        [InlineData("google.com", "DIRECT")]
        [InlineData("irs.gov", "DIRECT")]
        public void Emitted_pac_js_resolves_correctly(string host, string expected)
        {
            string pac = PacContentBuilder.Build(Domains);
            var engine = new Engine();
            engine.Execute(pac);
            var result = engine.Invoke("FindProxyForURL", "http://" + host + "/", host).AsString();
            Assert.Equal(expected, result);
        }
    }
}
