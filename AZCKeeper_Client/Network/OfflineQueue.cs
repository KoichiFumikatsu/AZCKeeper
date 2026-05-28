using System;
using System.Collections.Generic;
using System.Data.SQLite;
using System.IO;
using System.Text.Json;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Network
{
    /// <summary>
    /// Cola persistente offline usando SQLite para almacenar requests fallidos.
    /// Permite retry automático cuando la conexión se recupere.
    ///
    /// Comunicación:
    /// - ApiClient encola cuando falla el envío y luego reintenta con timer.
    /// - CoreService ajusta el intervalo de reintento vía ApiClient.UpdateRetryInterval().
    /// </summary>
    internal class OfflineQueue
    {
        private readonly string _dbPath;
        private readonly object _lock = new object();

        public OfflineQueue()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            string queueDir = Path.Combine(appData, "AZCKeeper", "Queue");

            if (!Directory.Exists(queueDir))
                Directory.CreateDirectory(queueDir);

            _dbPath = Path.Combine(queueDir, "offline_queue.db");
            InitializeDatabase();
        }

        private void InitializeDatabase()
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string createTable = @"
                        CREATE TABLE IF NOT EXISTS queue (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            endpoint TEXT NOT NULL,
                            payload_json TEXT NOT NULL,
                            created_at TEXT NOT NULL,
                            retry_count INTEGER DEFAULT 0,
                            last_retry_at TEXT NULL,
                            error_message TEXT NULL
                        );
                        CREATE INDEX IF NOT EXISTS idx_created ON queue(created_at);
                    ";

                    using var cmd = new SQLiteCommand(createTable, conn);
                    cmd.ExecuteNonQuery();
                }

                LocalLogger.Info("OfflineQueue: base de datos inicializada correctamente.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.InitializeDatabase(): error al crear DB SQLite.");
            }
        }

        /// <summary>
        /// Añade un payload a la cola offline.
        /// </summary>
        public void Enqueue(string endpoint, object payload)
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string json = JsonSerializer.Serialize(payload);
                    string now = DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss");

                    string insert = @"
                        INSERT INTO queue (endpoint, payload_json, created_at, retry_count)
                        VALUES (@endpoint, @payload, @created, 0)
                    ";

                    using var cmd = new SQLiteCommand(insert, conn);
                    cmd.Parameters.AddWithValue("@endpoint", endpoint);
                    cmd.Parameters.AddWithValue("@payload", json);
                    cmd.Parameters.AddWithValue("@created", now);
                    cmd.ExecuteNonQuery();

                    LocalLogger.Warn($"OfflineQueue: payload encolado. Endpoint={endpoint}");
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.Enqueue(): error al guardar en cola.");
            }
        }

        /// <summary>
        /// Obtiene items pendientes de la cola (máximo 50 por lote).
        /// </summary>
        public List<QueueItem> GetPendingItems(int maxItems = 50)
        {
            var items = new List<QueueItem>();

            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string select = @"
                        SELECT id, endpoint, payload_json, retry_count
                        FROM queue
                        WHERE retry_count < 5
                        ORDER BY created_at ASC
                        LIMIT @limit
                    ";

                    using var cmd = new SQLiteCommand(select, conn);
                    cmd.Parameters.AddWithValue("@limit", maxItems);

                    using var reader = cmd.ExecuteReader();
                    while (reader.Read())
                    {
                        items.Add(new QueueItem
                        {
                            Id = reader.GetInt64(0),
                            Endpoint = reader.GetString(1),
                            PayloadJson = reader.GetString(2),
                            RetryCount = reader.GetInt32(3)
                        });
                    }
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.GetPendingItems(): error al leer cola.");
            }

            return items;
        }

        /// <summary>
        /// Marca un item como enviado exitosamente y lo elimina.
        /// </summary>
        public void MarkAsSent(long itemId)
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string delete = "DELETE FROM queue WHERE id = @id";
                    using var cmd = new SQLiteCommand(delete, conn);
                    cmd.Parameters.AddWithValue("@id", itemId);
                    cmd.ExecuteNonQuery();
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.MarkAsSent(): error al eliminar item.");
            }
        }

        /// <summary>
        /// Incrementa contador de retry y guarda error.
        /// </summary>
        public void MarkAsRetried(long itemId, string errorMessage)
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string update = @"
                        UPDATE queue 
                        SET retry_count = retry_count + 1,
                            last_retry_at = @now,
                            error_message = @err
                        WHERE id = @id
                    ";

                    using var cmd = new SQLiteCommand(update, conn);
                    cmd.Parameters.AddWithValue("@id", itemId);
                    cmd.Parameters.AddWithValue("@now", DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss"));
                    cmd.Parameters.AddWithValue("@err", errorMessage);
                    cmd.ExecuteNonQuery();
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.MarkAsRetried(): error al actualizar retry.");
            }
        }

        /// <summary>
        /// Limpia items con más de 5 intentos fallidos (dead letter).
        /// </summary>
        public void CleanupDeadLetters()
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string delete = "DELETE FROM queue WHERE retry_count >= 5";
                    using var cmd = new SQLiteCommand(delete, conn);
                    int removed = cmd.ExecuteNonQuery();

                    if (removed > 0)
                        LocalLogger.Warn($"OfflineQueue: eliminados {removed} items con 5+ intentos fallidos.");
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "OfflineQueue.CleanupDeadLetters(): error.");
            }
        }

        /// <summary>
        /// Retorna cantidad de items pendientes en cola.
        /// </summary>
        public int GetPendingCount()
        {
            try
            {
                lock (_lock)
                {
                    using var conn = new SQLiteConnection($"Data Source={_dbPath};Version=3;");
                    conn.Open();

                    string count = "SELECT COUNT(*) FROM queue WHERE retry_count < 5";
                    using var cmd = new SQLiteCommand(count, conn);
                    return Convert.ToInt32(cmd.ExecuteScalar());
                }
            }
            catch
            {
                return 0;
            }
        }

        /// <summary>
        /// Item de cola con endpoint, payload serializado y contador de reintentos.
        /// </summary>
        internal class QueueItem
        {
            public long Id { get; set; }
            public string Endpoint { get; set; }
            public string PayloadJson { get; set; }
            public int RetryCount { get; set; }
        }
    }
}