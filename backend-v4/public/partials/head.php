<?php if (!defined('KEEPER_PRESENTATION')) { http_response_code(404); exit; } ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · AZCKeeper</title>
<link rel="icon" href="/assets/brand/favicon.ico">
<link rel="stylesheet" href="/assets/styles.css?v=<?= filemtime(dirname(__DIR__) . '/assets/styles.css') ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= htmlspecialchars($imports['/assets/app.js'], ENT_QUOTES, 'UTF-8') ?>"></script>
