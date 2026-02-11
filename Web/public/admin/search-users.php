<?php
require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json');

$pdo = Keeper\Db::pdo();

function calculateUserStatus($secondsSinceLastSeen, $secondsSinceLastEvent) {
    if ($secondsSinceLastSeen === null || $secondsSinceLastSeen > 900000) {
        return 'offline';
    }
    
    if ($secondsSinceLastSeen >= 900) {
        return 'inactive';
    }
    
    if ($secondsSinceLastEvent === null || $secondsSinceLastEvent > 900000) {
        return ($secondsSinceLastSeen < 120) ? 'away' : 'inactive';
    }
    
    if ($secondsSinceLastEvent < 120) {
        return 'active';
    }
    
    return 'away';
}

// Obtener parámetros de búsqueda
$searchName = isset($_GET['name']) ? strtolower(trim($_GET['name'])) : '';
$searchStatus = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
$searchPolicy = isset($_GET['policy']) ? strtolower(trim($_GET['policy'])) : '';

// Consultar TODOS los usuarios
$users = $pdo->query("
    SELECT 
        u.id, 
        u.cc,
        u.display_name, 
        u.email,
        u.status,
        MAX(d.last_seen_at) as last_activity,
        MAX(d.last_seen_at) as last_seen,
        TIMESTAMPDIFF(SECOND, MAX(d.last_seen_at), NOW()) as seconds_since_seen,
        (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
         WHERE a.user_id = u.id AND a.day_date = CURDATE()) as last_event,
        TIMESTAMPDIFF(SECOND, 
            (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
             WHERE a.user_id = u.id AND a.day_date = CURDATE()), 
            NOW()) as seconds_since_event,
        (SELECT COUNT(*) FROM keeper_policy_assignments WHERE scope='user' AND user_id=u.id AND is_active=1) as has_policy
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
    GROUP BY u.id
    ORDER BY u.display_name
")->fetchAll(PDO::FETCH_ASSOC);

// Calcular estado y filtrar
$filteredUsers = [];
foreach ($users as $user) {
    $user['connection_status'] = calculateUserStatus($user['seconds_since_seen'], $user['seconds_since_event']);
    
    // Aplicar filtros
    $matchesName = !$searchName || 
                   stripos($user['display_name'], $searchName) !== false || 
                   stripos($user['cc'] ?? '', $searchName) !== false;
    
    $matchesStatus = !$searchStatus || $user['connection_status'] === $searchStatus;
    
    $policyType = $user['has_policy'] ? 'personalizada' : 'global';
    $matchesPolicy = !$searchPolicy || $policyType === $searchPolicy;
    
    if ($matchesName && $matchesStatus && $matchesPolicy) {
        $filteredUsers[] = [
            'id' => $user['id'],
            'cc' => $user['cc'] ?? 'N/A',
            'display_name' => $user['display_name'],
            'connection_status' => $user['connection_status'],
            'last_event' => $user['last_event'],
            'has_policy' => (bool)$user['has_policy']
        ];
    }
}

echo json_encode([
    'success' => true,
    'total' => count($filteredUsers),
    'users' => $filteredUsers
]);
