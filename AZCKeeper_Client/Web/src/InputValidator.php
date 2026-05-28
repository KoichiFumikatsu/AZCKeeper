<?php
namespace Keeper;

/**
 * Validador de inputs para prevenir SQL Injection y XSS
 */
class InputValidator
{
    /**
     * Valida que una fecha tenga formato YYYY-MM-DD
     * @param string $date Fecha a validar
     * @param string $default Valor por defecto si es inválida
     * @return string Fecha validada o default
     */
    public static function validateDate(string $date, string $default = null): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $default ?? date('Y-m-d');
        }
        
        // Validar que sea fecha real (no 2026-99-99)
        $parts = explode('-', $date);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return $default ?? date('Y-m-d');
        }
        
        return $date;
    }
    
    /**
     * Valida un entero positivo
     * @param mixed $value Valor a validar
     * @param int $default Valor por defecto
     * @param int $min Valor mínimo permitido
     * @param int $max Valor máximo permitido
     * @return int Entero validado
     */
    public static function validateInt($value, int $default = 0, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < $min || $int > $max) {
            return $default;
        }
        return $int;
    }
    
    /**
     * Valida que un string esté en una lista de valores permitidos
     * @param string $value Valor a validar
     * @param array $allowed Valores permitidos
     * @param string $default Valor por defecto
     * @return string Valor validado
     */
    public static function validateEnum(string $value, array $allowed, string $default = ''): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
    
    /**
     * Escapa HTML de forma segura
     * @param mixed $value Valor a escapar
     * @return string Valor escapado
     */
    public static function escapeHtml($value): string
    {
        if ($value === null) return '';
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Valida array de IDs (enteros positivos)
     * @param array $ids Array de IDs
     * @return array Array de enteros validados
     */
    public static function validateIntArray(array $ids): array
    {
        return array_filter(array_map('intval', $ids), function($id) {
            return $id > 0;
        });
    }
}
