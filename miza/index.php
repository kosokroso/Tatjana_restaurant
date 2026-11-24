<?php header('Cache-Control: no-store'); $v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css'); ?><!doctype html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tatjana Miza</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="app"><div class="boot">Nalagam…</div></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
<script src="assets/users.js?v=<?= $v ?>"></script>
</body>
</html>