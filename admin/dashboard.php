<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();

$totalPeserta = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 2")->fetchColumn();
$totalKegiatan = (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$totalMateri = (int) $pdo->query("SELECT COUNT(*) FROM materials")->fetchColumn();
$totalQuiz = (int) $pdo->query("SELECT COUNT(*) FROM quizzes")->fetchColumn();
$totalTugas = (int) $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
$totalResponden = (int) $pdo->query("SELECT COUNT(DISTINCT user_id) FROM research_responses")->fetchColumn();

$recentActivities = $pdo->query(
    "SELECT al.action, al.description, al.created_at, u.full_name
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h4 class="fw-bold mb-0">Dashboard</h4>
        <p class="text-muted small mb-0">Selamat datang kembali, <?= e($_SESSION['full_name']) ?> 👋</p>
      </div>
      <a href="event_create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Kegiatan Baru</a>
    </div>

    <div class="row g-3 mb-4">
      <?php
      $stats = [
        ['label' => 'Total Peserta', 'value' => $totalPeserta, 'icon' => 'bi-people-fill', 'color' => 'primary'],
        ['label' => 'Total Kegiatan', 'value' => $totalKegiatan, 'icon' => 'bi-calendar-event-fill', 'color' => 'success'],
        ['label' => 'Total Materi', 'value' => $totalMateri, 'icon' => 'bi-journal-text', 'color' => 'info'],
        ['label' => 'Total Quiz', 'value' => $totalQuiz, 'icon' => 'bi-patch-question-fill', 'color' => 'warning'],
        ['label' => 'Total Tugas', 'value' => $totalTugas, 'icon' => 'bi-file-earmark-text-fill', 'color' => 'danger'],
        ['label' => 'Total Responden', 'value' => $totalResponden, 'icon' => 'bi-clipboard-data-fill', 'color' => 'secondary'],
      ];
      foreach ($stats as $s): ?>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
          <div class="card-body">
            <div class="stat-icon bg-<?= $s['color'] ?>-subtle text-<?= $s['color'] ?>"><i class="bi <?= $s['icon'] ?>"></i></div>
            <h3 class="fw-bold mt-2 mb-0"><?= $s['value'] ?></h3>
            <p class="text-muted small mb-0"><?= $s['label'] ?></p>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header bg-white fw-semibold">Peserta per Kategori Kegiatan</div>
          <div class="card-body">
            <canvas id="chartCategory" height="180"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header bg-white fw-semibold">Aktivitas Terbaru</div>
          <ul class="list-group list-group-flush">
            <?php if (empty($recentActivities)): ?>
              <li class="list-group-item text-muted small">Belum ada aktivitas.</li>
            <?php endif; ?>
            <?php foreach ($recentActivities as $act): ?>
              <li class="list-group-item small">
                <strong><?= e($act['full_name'] ?? 'Sistem') ?></strong> — <?= e($act['description'] ?: $act['action']) ?>
                <div class="text-muted" style="font-size:.75rem;"><?= e(date('d M Y H:i', strtotime($act['created_at']))) ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
<?php
$categoryData = $pdo->query(
    "SELECT c.name, COUNT(ep.id) AS total
     FROM categories c
     LEFT JOIN events e ON e.category_id = c.id
     LEFT JOIN event_participants ep ON ep.event_id = e.id AND ep.status = 'approved'
     GROUP BY c.id, c.name"
)->fetchAll();
$labels = array_column($categoryData, 'name');
$values = array_column($categoryData, 'total');
?>
new Chart(document.getElementById('chartCategory'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: 'Jumlah Peserta',
      data: <?= json_encode($values) ?>,
      backgroundColor: '#2563EB'
    }]
  },
  options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
