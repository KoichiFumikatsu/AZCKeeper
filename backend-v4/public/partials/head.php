<?php if (!defined('KEEPER_PRESENTATION')) { http_response_code(404); exit; } ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<?php require_once __DIR__ . '/brand.php'; ?>
<title><?= htmlspecialchars($title . ' · ' . $brand['name'], ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="/assets/brand/favicon.ico">
<link rel="stylesheet" href="/assets/k3.css?v=<?= filemtime(dirname(__DIR__) . '/assets/k3.css') ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= htmlspecialchars($imports['/assets/app.js'], ENT_QUOTES, 'UTF-8') ?>"></script>
