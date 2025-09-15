namespace ModularApp.Core
{
    public interface IAppModule : System.IDisposable
    {
        string Name { get; }
        bool Enabled { get; }
        void Init(AppCore core);
        void Start();
        void Stop();
    }
}
