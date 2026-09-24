<?php require_once __DIR__ . '/config/config.php'; http_response_code(404); ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>404 - Halaman Tidak Ditemukan</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
<div class="text-center">
    <h1 class="display-3 fw-bold text-primary">404</h1>
    <p class="fs-5">Halaman yang Anda cari tidak ditemukan.</p>
    <a href="<?= BASE_URL ?>" class="btn btn-primary">Kembali ke Beranda</a>
</div>
</body>
</html>
