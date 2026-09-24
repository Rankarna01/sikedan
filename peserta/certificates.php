<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT c.*, e.title AS event_title, e.start_date, e.end_date
     FROM certificates c JOIN events e ON e.id = c.event_id
     WHERE c.user_id = ? ORDER BY c.issued_at DESC"
);
$stmt->execute([$userId]);
$certificates = $stmt->fetchAll();

$pageTitle = 'Sertifikat Saya';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <h4 class="fw-bold mb-4">Sertifikat Saya</h4>
    <div class="row g-3">
      <?php if (empty($certificates)): ?>
        <p class="text-muted">Anda belum memiliki sertifikat. Sertifikat akan diterbitkan oleh admin setelah Anda menyelesaikan kegiatan.</p>
      <?php endif; ?>
      <?php foreach ($certificates as $c): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <i class="bi bi-award-fill text-warning fs-2"></i>
              <h6 class="fw-bold mt-2"><?= e($c['event_title']) ?></h6>
              <p class="small text-muted mb-1">No: <?= e($c['certificate_number']) ?></p>
              <p class="small text-muted mb-3">Diterbitkan: <?= formatTanggal($c['issued_at']) ?></p>
              <a href="<?= BASE_URL ?>/certificate_view.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Lihat / Cetak</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
