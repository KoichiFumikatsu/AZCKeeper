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
