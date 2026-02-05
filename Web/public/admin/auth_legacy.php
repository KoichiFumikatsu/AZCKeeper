<?php
// Integración con sistema legacy de autenticación
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
 
class KeeperAuth {
    private $auth;
    private $functions;
    
   // public function __construct() {
    //    $this->auth = new Auth();
    //    $this->functions = new Functions();
   // }
    
    /**
     * Verificar que el usuario esté logueado y tenga permisos
     */
    public function requireAuth($allowed_roles = ['administrador', 'it', 'supervisor']) {
        // Redirigir si no está logueado
        $this->auth->redirectIfNotLoggedIn();
        
        // Verificar rol
        if (!in_array($_SESSION['user_role'], $allowed_roles)) {
            header("Location: ../dashboard.php");
            exit();
        }
        
        return true;
    }
    
    /**
     * Obtener información del usuario actual
     */
    public function getCurrentUser() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['user_role'] ?? null,
            'sede_id' => $_SESSION['sede_id'] ?? null
        ];
    }
    
    /**
     * Verificar si el usuario tiene un rol específico
     */
    public function hasRole($role) {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
    }
    
    /**
     * Verificar si el usuario tiene alguno de los roles
     */
    public function hasAnyRole($roles) {
        return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], $roles);
    }
}