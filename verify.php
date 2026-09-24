<?php
require_once __DIR__ . '/config/config.php';

$pdo = getDBConnection();
$code = trim($_GET['code'] ?? '');
$cert = null;

if ($code !== '') {
    $stmt = $pdo->prepare(
        "SELECT c.*, e.title AS event_title, e.start_date, e.end_date, u.full_name
         FROM certificates c
         JOIN events e ON e.id = c.event_id
         JOIN users u ON u.id = c.user_id
         WHERE c.verification_code = ?"
    );
    $stmt->execute([$code]);
    $cert = $stmt->fetch();
}

$pageTitle = 'Verifikasi Sertifikat';
require_once __DIR__ . '/includes/header.php';
?>
<nav class="navbar navbar-dark" style="background-color:var(--clr-dark);">
  <div class="container"><a class="navbar-brand fw-bold" href="<?= BASE_URL ?>"><i class="bi bi-mortarboard-fill"></i> <?= e(getSetting('app_name', 'SiKedan')) ?></a></div>
</nav>
<div class="container py-5" style="max-width:600px;">
  <div class="card shadow-sm">
    <div class="card-body p-4 p-md-5 text-center">
      <?php if ($code === ''): ?>
        <i class="bi bi-search text-primary" style="font-size:2.5rem;"></i>
        <h5 class="fw-bold mt-3">Verifikasi Sertifikat</h5>
        <form method="get" class="mt-3">
          <input type="text" name="code" class="form-control mb-2" placeholder="Masukkan kode verifikasi" required>
          <button class="btn btn-primary w-100">Cek Sertifikat</button>
        </form>
      <?php elseif ($cert): ?>
        <i class="bi bi-patch-check-fill text-success" style="font-size:3rem;"></i>
        <h5 class="fw-bold mt-3 text-success">Sertifikat Valid</h5>
        <hr>
        <p class="mb-1 text-muted small">Nama</p>
        <p class="fw-semibold fs-5"><?= e($cert['full_name']) ?></p>
        <p class="mb-1 text-muted small">Kegiatan</p>
        <p class="fw-semibold"><?= e($cert['event_title']) ?></p>
        <p class="mb-1 text-muted small">Tanggal Pelaksanaan</p>
        <p><?= formatTanggal($cert['start_date']) ?> &ndash; <?= formatTanggal($cert['end_date']) ?></p>
        <p class="mb-1 text-muted small">Nomor Sertifikat</p>
        <p class="fw-semibold"><?= e($cert['certificate_number']) ?></p>
        <p class="text-muted small mt-3">Diterbitkan <?= formatTanggal($cert['issued_at']) ?></p>
      <?php else: ?>
        <i class="bi bi-x-circle-fill text-danger" style="font-size:3rem;"></i>
        <h5 class="fw-bold mt-3 text-danger">Kode Tidak Ditemukan</h5>
        <p class="text-muted">Sertifikat dengan kode tersebut tidak ditemukan dalam sistem kami.</p>
        <a href="verify.php" class="btn btn-outline-secondary btn-sm">Coba Kode Lain</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
