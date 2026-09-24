<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT e.*, c.name AS category_name, ep.progress, ep.status AS enroll_status
     FROM event_participants ep
     JOIN events e ON e.id = ep.event_id
     JOIN categories c ON c.id = e.category_id
     WHERE ep.user_id = ?
     ORDER BY e.start_date DESC"
);
$stmt->execute([$userId]);
$events = $stmt->fetchAll();

$statusLabel = ['draft'=>'Draft','active'=>'Aktif','inactive'=>'Nonaktif','completed'=>'Selesai'];
$statusBadge = ['draft'=>'secondary','active'=>'success','inactive'=>'warning','completed'=>'primary'];

$pageTitle = 'Kegiatan Saya';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <h4 class="fw-bold mb-4">Kegiatan Saya</h4>
    <div class="row g-3">
      <?php if (empty($events)): ?>
        <p class="text-muted">Anda belum terdaftar pada kegiatan apapun. Hubungi admin untuk didaftarkan.</p>
      <?php endif; ?>
      <?php foreach ($events as $ev):
        $thumbUrl = $ev['cover_image'] ? (UPLOAD_URL . '/' . $ev['cover_image']) : null;
        $thumbPos = $ev['cover_image_position'] ?: 'center';
      ?>
        <div class="col-md-6 col-lg-4">
          <div class="card event-card h-100">
            <?php if ($thumbUrl): ?>
              <div class="event-card-thumb" style="background-image:url('<?= e($thumbUrl) ?>'); background-position: <?= e($thumbPos) ?>;"></div>
            <?php else: ?>
              <div class="event-card-thumb event-card-thumb-placeholder"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <div class="card-body">
              <span class="badge bg-primary-subtle text-primary mb-2"><?= e($ev['category_name']) ?></span>
              <span class="badge bg-<?= $statusBadge[$ev['status']] ?> mb-2"><?= $statusLabel[$ev['status']] ?></span>
              <h5 class="fw-bold"><?= e($ev['title']) ?></h5>
              <p class="text-muted small mb-2"><i class="bi bi-calendar3"></i> <?= formatTanggal($ev['start_date']) ?> &ndash; <?= formatTanggal($ev['end_date']) ?></p>
              <div class="progress mb-2" style="height:8px;">
                <div class="progress-bar" style="width:<?= (int) $ev['progress'] ?>%"></div>
              </div>
              <p class="small text-muted mb-3"><?= (int) $ev['progress'] ?>% selesai</p>
              <?php if ($ev['enroll_status'] === 'approved'): ?>
                <a href="event_detail.php?id=<?= (int) $ev['id'] ?>" class="btn btn-sm btn-primary w-100">Buka Kegiatan</a>
              <?php elseif ($ev['enroll_status'] === 'rejected'): ?>
                <button type="button" class="btn btn-sm btn-outline-danger w-100" disabled>Pendaftaran Ditolak</button>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-warning w-100" disabled><i class="bi bi-hourglass-split me-1"></i>Menunggu Persetujuan</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
