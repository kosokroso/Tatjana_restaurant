<?php header('Cache-Control: no-store'); $v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css'); ?><!doctype html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#1b5e4b">
<title>Tatjana Miza</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="app"><div class="boot">Nalagam…</div></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
<script src="assets/users.js?v=<?= $v ?>"></script>
</body>
</html>