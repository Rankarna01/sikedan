<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT e.*, ep.progress
     FROM event_participants ep
     JOIN events e ON e.id = ep.event_id
     WHERE ep.user_id = ? AND e.status = 'active' AND ep.status = 'approved'
     ORDER BY e.start_date DESC"
);
$stmt->execute([$userId]);
$activeEvents = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$userId]);
$totalEvents = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM certificates WHERE user_id = ?");
$stmt->execute([$userId]);
$totalCertificates = (int) $stmt->fetchColumn();

$pageTitle = 'Dashboard Peserta';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <h4 class="fw-bold">Selamat datang, <?= e($_SESSION['full_name']) ?> 👋</h4>
    <p class="text-muted mb-4">Berikut ringkasan kegiatan Anda.</p>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="card-body">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-calendar-event-fill"></i></div>
            <h3 class="fw-bold mt-2 mb-0"><?= $totalEvents ?></h3>
            <p class="text-muted small mb-0">Kegiatan Diikuti</p>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="card-body">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-calendar-check-fill"></i></div>
            <h3 class="fw-bold mt-2 mb-0"><?= count($activeEvents) ?></h3>
            <p class="text-muted small mb-0">Kegiatan Aktif</p>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="card stat-card h-100">
          <div class="card-body">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-award-fill"></i></div>
            <h3 class="fw-bold mt-2 mb-0"><?= $totalCertificates ?></h3>
            <p class="text-muted small mb-0">Sertifikat</p>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white fw-semibold">Kegiatan Aktif Saya</div>
      <div class="card-body">
        <?php if (empty($activeEvents)): ?>
          <p class="text-muted mb-0">Anda belum memiliki kegiatan aktif saat ini.</p>
        <?php else: ?>
          <?php foreach ($activeEvents as $ev): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between">
                <a href="event_detail.php?id=<?= (int) $ev['id'] ?>" class="fw-semibold text-decoration-none"><?= e($ev['title']) ?></a>
                <span class="small text-muted"><?= (int) $ev['progress'] ?>%</span>
              </div>
              <div class="progress" style="height:8px;">
                <div class="progress-bar" style="width:<?= (int) $ev['progress'] ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
