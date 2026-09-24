<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>403 - Akses Ditolak</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
<div class="text-center">
    <h1 class="display-3 fw-bold text-danger">403</h1>
    <p class="fs-5">Anda tidak memiliki akses ke halaman ini.</p>
    <a href="<?= defined('BASE_URL') ? BASE_URL : '/' ?>" class="btn btn-primary">Kembali ke Beranda</a>
</div>
</body>
</html>
