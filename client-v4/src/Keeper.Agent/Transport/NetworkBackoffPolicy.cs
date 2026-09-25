using System.Net;
using System.Net.Sockets;

namespace Keeper.Agent.Transport;

public enum NetworkFailureKind { Dns, Transient, Throttled, Authorization }
public static class NetworkBackoffPolicy
{
    public static NetworkFailureKind Classify(Exception exception)
    {
        if (exception is EnrollmentException) return NetworkFailureKind.Authorization;
        if (exception is TransportException transport)
        {
            if (transport.Status == HttpStatusCode.TooManyRequests) return NetworkFailureKind.Throttled;
            if (transport.Status is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden) return NetworkFailureKind.Authorization;
        }
        for (Exception? current = exception; current is not null; current = current.InnerException)
            if (current is SocketException { SocketErrorCode: SocketError.HostNotFound or SocketError.NoData or SocketError.TryAgain }) return NetworkFailureKind.Dns;
        return NetworkFailureKind.Transient;
    }
    public static double DelaySeconds(NetworkFailureKind kind, int failures)
    {
        var (initial, cap) = kind switch
        {
            NetworkFailureKind.Dns => (5, 60),
            NetworkFailureKind.Throttled => (30, 1800),
            NetworkFailureKind.Authorization => (120, 3600),
            _ => (5, 900)
        };
        return Math.Min(cap, initial * Math.Pow(2, Math.Clamp(failures - 1, 0, 12)));
    }
}
