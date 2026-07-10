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
