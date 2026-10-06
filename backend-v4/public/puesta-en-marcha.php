<?php
declare(strict_types=1);
$page = 'onboarding'; $title = 'Puesta en marcha'; $subtitle = 'Pasos para dejar funcionando el primer equipo de este cliente. Cada paso abre la pantalla donde se hace.';
// Estilos propios de esta pantalla (P1: lo guardado y decisiones por paso) en onboarding.css, enlazado en <head> sin tocar el layout compartido.
ob_start();
require __DIR__ . '/partials/layout-k3.php';
$css = '/assets/onboarding.css?v=' . filemtime(__DIR__ . '/assets/onboarding.css');
echo str_replace('</head>', '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . "\">\n</head>", (string) ob_get_clean());
