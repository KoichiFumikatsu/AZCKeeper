<?php
/**
 * Endpoint AJAX para obtener ventanas recientes de un usuario
 * Sin recargar la página completa
 */
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\InputValidator;

header('Content-Type: application/json');

try {
    $userId = InputValidator::validateInt($_GET['user_id'] ?? 0, 0, 1);
    $dateFrom = InputValidator::validateDate($_GET['date_from'] ?? '', date('Y-m-01'));
    $dateTo = InputValidator::validateDate($_GET['date_to'] ?? '', date('Y-m-d'));
    $page = InputValidator::validateInt($_GET['page'] ?? 1, 1, 1, 10000);
    $perPage = InputValidator::validateInt($_GET['per_page'] ?? 10, 10, 5, 100);
    
    // Filtros opcionales
    $searchProcess = trim($_GET['search_process'] ?? '');
    $searchTitle = trim($_GET['search_title'] ?? '');
    
    if (!$userId) {
        throw new Exception('User ID requerido');
    }
    
    $pdo = Keeper\Db::pdo();
    
    // Obtener dispositivos del usuario
    $stmt = $pdo->prepare("SELECT id FROM keeper_devices WHERE user_id = ?");
    $stmt->execute([$userId]);
    $deviceIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    
    if (empty($deviceIds)) {
        echo json_encode([
            'success' => true,
            'total' => 0,
            'totalPages' => 0,
            'currentPage' => 1,
            'windows' => []
        ]);
        exit;
    }
    
    $deviceIds = InputValidator::validateIntArray($deviceIds);
    $deviceIdsPlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $deviceIdsParams = $deviceIds;
    
    // Construir WHERE con filtros
    $whereConditions = [
        "user_id = ?",
        "device_id IN ({$deviceIdsPlaceholders})",
        "day_date BETWEEN ? AND ?"
    ];
    $params = array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]);
    
    if (!empty($searchProcess)) {
        $whereConditions[] = "process_name LIKE ?";
        $params[] = '%' . $searchProcess . '%';
    }
    
    if (!empty($searchTitle)) {
        $whereConditions[] = "window_title LIKE ?";
        $params[] = '%' . $searchTitle . '%';
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Contar total
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM keeper_window_episode
        WHERE {$whereClause}
    ");
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
    
    $totalPages = ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;
    
    // Obtener ventanas
    $stmt = $pdo->prepare("
        SELECT 
            process_name,
            window_title,
            start_at,
            end_at,
            duration_seconds,
            is_in_call,
            day_date
        FROM keeper_window_episode
        WHERE {$whereClause}
        ORDER BY start_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $windows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total' => (int)$total,
        'totalPages' => (int)$totalPages,
        'currentPage' => (int)$page,
        'perPage' => (int)$perPage,
        'windows' => $windows
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
