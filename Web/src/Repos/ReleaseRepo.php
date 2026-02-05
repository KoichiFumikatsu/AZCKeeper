<?php
namespace Keeper\Repos;

use Keeper\Db;

class ReleaseRepo
{
    /**
     * Get the latest active release (excluding or including beta based on flag)
     */
    public static function getLatestRelease(bool $includeBeta = false): ?array
    {
        $pdo = Db::pdo();
        
        $sql = "SELECT * FROM keeper_client_releases 
                WHERE is_active = 1";
        
        if (!$includeBeta) {
            $sql .= " AND is_beta = 0";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 1";
        
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Get all releases (with optional filters)
     */
    public static function getAllReleases(bool $activeOnly = false, bool $excludeBeta = false): array
    {
        $pdo = Db::pdo();
        
        $conditions = [];
        if ($activeOnly) {
            $conditions[] = "is_active = 1";
        }
        if ($excludeBeta) {
            $conditions[] = "is_beta = 0";
        }
        
        $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        $sql = "SELECT * FROM keeper_client_releases $where ORDER BY created_at DESC";
        
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a specific release by ID
     */
    public static function getById(int $id): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM keeper_client_releases WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Get a specific release by version
     */
    public static function getByVersion(string $version): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM keeper_client_releases WHERE version = ?");
        $stmt->execute([$version]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Create a new release
     */
    public static function create(array $data): int
    {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("
            INSERT INTO keeper_client_releases 
            (version, download_url, file_size, release_notes, is_beta, force_update, minimum_version, is_active, release_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['version'],
            $data['download_url'],
            $data['file_size'] ?? 0,
            $data['release_notes'] ?? '',
            $data['is_beta'] ?? 0,
            $data['force_update'] ?? 0,
            $data['minimum_version'] ?? null,
            $data['is_active'] ?? 1,
            $data['release_date'] ?? date('Y-m-d')
        ]);
        
        return (int)$pdo->lastInsertId();
    }

    /**
     * Update an existing release
     */
    public static function update(int $id, array $data): bool
    {
        $pdo = Db::pdo();
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['version', 'download_url', 'file_size', 'release_notes', 
                         'is_beta', 'force_update', 'minimum_version', 'is_active', 'release_date'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE keeper_client_releases SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Delete a release (soft delete by setting is_active = 0)
     */
    public static function softDelete(int $id): bool
    {
        return self::update($id, ['is_active' => 0]);
    }

    /**
     * Hard delete a release (permanent)
     */
    public static function delete(int $id): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("DELETE FROM keeper_client_releases WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Check if a version exists
     */
    public static function versionExists(string $version, ?int $excludeId = null): bool
    {
        $pdo = Db::pdo();
        
        if ($excludeId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_releases WHERE version = ? AND id != ?");
            $stmt->execute([$version, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_releases WHERE version = ?");
            $stmt->execute([$version]);
        }
        
        return $stmt->fetchColumn() > 0;
    }
}
