<?php
/**
 * Header — dipakai oleh layout admin & peserta.
 * Variabel opsional: $pageTitle
 */
$appName = getSetting('app_name', 'SiKedan');
$title   = isset($pageTitle) ? $pageTitle . ' - ' . $appName : $appName;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<link rel="icon" href="<?= BASE_URL ?>/assets/images/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
</head>
<body>
