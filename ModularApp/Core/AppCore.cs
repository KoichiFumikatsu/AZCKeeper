using System;
using System.Collections.Concurrent;

namespace ModularApp.Core
{
    public sealed class AppCore
    {
        public AppConfig Config { get; private set; }
        public ILogger Logger { get; private set; }

        public AppCore(AppConfig cfg, ILogger logger)
        {
            Config = cfg;
            Logger = logger;
        }

        private readonly ConcurrentDictionary<Type, object> _services = new ConcurrentDictionary<Type, object>();

        public void RegisterService<T>(T impl) where T : class
        {
            _services[typeof(T)] = impl;
        }

        public T Resolve<T>() where T : class
        {
            object obj;
            if (_services.TryGetValue(typeof(T), out obj)) return (T)obj;
            return null;
        }
    }
}
