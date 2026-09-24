<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['event_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan.');
    redirect('admin/events.php');
}

$stmt = $pdo->prepare(
    "SELECT ev.*, u.full_name FROM evaluations ev LEFT JOIN users u ON u.id = ev.user_id
     WHERE ev.event_id = ? ORDER BY ev.submitted_at DESC"
);
$stmt->execute([$eventId]);
$evaluations = $stmt->fetchAll();

$total = count($evaluations);
$avgMaterial = $total ? round(array_sum(array_column($evaluations, 'material_rating')) / $total, 1) : 0;
$avgSpeaker = $total ? round(array_sum(array_column($evaluations, 'speaker_rating')) / $total, 1) : 0;
$avgOverall = $total ? round(array_sum(array_column($evaluations, 'overall_rating')) / $total, 1) : 0;

$pageTitle = 'Evaluasi — ' . $event['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $eventId ?>"><?= e($event['title']) ?></a></li><li class="breadcrumb-item active">Evaluasi</li></ol></nav>
    <h4 class="fw-bold mb-3">Evaluasi Kegiatan: <?= e($event['title']) ?></h4>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $total ?></h4><p class="text-muted small mb-0">Jumlah Responden</p></div></div></div>
      <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-journal-text"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgMaterial ?>/5</h4><p class="text-muted small mb-0">Rata-rata Materi</p></div></div></div>
      <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-person-video3"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgSpeaker ?>/5</h4><p class="text-muted small mb-0">Rata-rata Narasumber</p></div></div></div>
      <div class="col-6 col-md-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-star-fill"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgOverall ?>/5</h4><p class="text-muted small mb-0">Rata-rata Kepuasan</p></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header bg-white fw-semibold">Grafik Rata-rata Penilaian</div>
          <div class="card-body"><canvas id="evalChart" height="200"></canvas></div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header bg-white fw-semibold">Saran Peserta</div>
          <div class="list-group list-group-flush" style="max-height:320px; overflow-y:auto;">
            <?php if (empty($evaluations)): ?>
              <div class="list-group-item text-muted small">Belum ada evaluasi masuk.</div>
            <?php endif; ?>
            <?php foreach ($evaluations as $ev): ?>
              <?php if ($ev['suggestion']): ?>
                <div class="list-group-item">
                  <p class="small mb-1"><?= e($ev['suggestion']) ?></p>
                  <p class="text-muted mb-0" style="font-size:.75rem;">&mdash; <?= e($ev['full_name'] ?? 'Anonim') ?></p>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('evalChart'), {
  type: 'bar',
  data: {
    labels: ['Materi', 'Narasumber', 'Kepuasan Keseluruhan'],
    datasets: [{ label: 'Rata-rata (skala 1-5)', data: [<?= $avgMaterial ?>, <?= $avgSpeaker ?>, <?= $avgOverall ?>], backgroundColor: ['#2563EB', '#16A34A', '#F59E0B'] }]
  },
  options: { responsive: true, scales: { y: { beginAtZero: true, max: 5 } }, plugins: { legend: { display: false } } }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
