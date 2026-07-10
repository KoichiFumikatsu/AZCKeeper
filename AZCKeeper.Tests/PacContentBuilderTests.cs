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
