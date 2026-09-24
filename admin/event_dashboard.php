<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.*, c.name AS category_name FROM events e
     JOIN categories c ON c.id = e.category_id WHERE e.id = ?"
);
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan.');
    redirect('admin/events.php');
}

// Handle add day
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_day') {
    verifyCsrf();
    $title = trim($_POST['day_title'] ?? '');
    $dayDate = $_POST['day_date'] ?? null;
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul hari/pertemuan wajib diisi.');
    } else {
        // Penomoran dinamis: rapikan dulu nomor yang ada, lalu hari baru = jumlah hari + 1
        renumberEventDays($pdo, $eventId);
        $stmtMax = $pdo->prepare('SELECT COUNT(*) FROM event_days WHERE event_id = ?');
        $stmtMax->execute([$eventId]);
        $nextDay = (int) $stmtMax->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO event_days (event_id, day_number, title, day_date, weight_percent, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$eventId, $nextDay, $title, $dayDate ?: null, $weightPercent, $nextDay]);
        logActivity((int) $_SESSION['user_id'], 'add_event_day', "Menambah hari ke kegiatan: {$event['title']}");
        setFlash('success', 'Hari/pertemuan berhasil ditambahkan.');
    }
    redirect('admin/event_dashboard.php?id=' . $eventId);
}

// Handle delete day
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_day') {
    verifyCsrf();
    $dayId = (int) ($_POST['day_id'] ?? 0);
    $del = $pdo->prepare('DELETE FROM event_days WHERE id = ? AND event_id = ?');
    $del->execute([$dayId, $eventId]);
    // Nomor hari berikutnya otomatis menyesuaikan (tidak ada lubang nomor)
    renumberEventDays($pdo, $eventId);
    setFlash('success', 'Hari/pertemuan berhasil dihapus. Nomor hari sudah disesuaikan otomatis.');
    redirect('admin/event_dashboard.php?id=' . $eventId);
}

renumberEventDays($pdo, $eventId);

$stmt = $pdo->prepare(
    "SELECT ed.*,
     (SELECT COUNT(*) FROM materials m WHERE m.event_day_id = ed.id) AS material_count,
     (SELECT COUNT(*) FROM videos v WHERE v.event_day_id = ed.id) AS video_count,
     (SELECT COUNT(*) FROM quizzes q WHERE q.event_day_id = ed.id) AS quiz_count,
     (SELECT COUNT(*) FROM assignments a WHERE a.event_day_id = ed.id) AS assignment_count
     FROM event_days ed WHERE ed.event_id = ? ORDER BY ed.day_number ASC"
);
$stmt->execute([$eventId]);
$days = $stmt->fetchAll();
$totalDayWeight = array_sum(array_column($days, 'weight_percent'));

$stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'approved'");
$stmt->execute([$eventId]);
$participantCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT AVG(progress) FROM event_participants WHERE event_id = ? AND status = 'approved'");
$stmt->execute([$eventId]);
$avgProgress = round((float) $stmt->fetchColumn(), 1);

$statusBadge = ['draft'=>'secondary','active'=>'success','inactive'=>'warning','completed'=>'primary'];
$statusLabel = ['draft'=>'Draft','active'=>'Aktif','inactive'=>'Nonaktif','completed'=>'Selesai'];

$pageTitle = $event['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <div class="d-flex align-items-center gap-2">
          <h4 class="fw-bold mb-0"><?= e($event['title']) ?></h4>
          <span class="badge bg-<?= $statusBadge[$event['status']] ?>"><?= $statusLabel[$event['status']] ?></span>
        </div>
        <p class="text-muted small mb-0">
          <i class="bi bi-tag"></i> <?= e($event['category_name']) ?> &middot;
          <i class="bi bi-calendar3"></i> <?= formatTanggal($event['start_date']) ?> &ndash; <?= formatTanggal($event['end_date']) ?>
        </p>
      </div>
      <div class="d-flex gap-2">
        <a href="event_edit.php?id=<?= $eventId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit Kegiatan</a>
        <a href="events.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100"><div class="card-body">
          <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></div>
          <h4 class="fw-bold mt-2 mb-0"><?= $participantCount ?></h4>
          <p class="text-muted small mb-0">Total Peserta</p>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100"><div class="card-body">
          <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-graph-up-arrow"></i></div>
          <h4 class="fw-bold mt-2 mb-0"><?= $avgProgress ?>%</h4>
          <p class="text-muted small mb-0">Progress Rata-rata</p>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100"><div class="card-body">
          <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-calendar-week"></i></div>
          <h4 class="fw-bold mt-2 mb-0"><?= count($days) ?></h4>
          <p class="text-muted small mb-0">Hari/Pertemuan</p>
        </div></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card stat-card h-100"><div class="card-body">
          <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-people"></i></div>
          <h4 class="fw-bold mt-2 mb-0"><?= (int) $event['quota'] ?></h4>
          <p class="text-muted small mb-0">Kuota Peserta</p>
        </div></div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span>Hari / Pertemuan
              <span class="badge <?= abs($totalDayWeight - 100) < 0.01 ? 'bg-success' : ($totalDayWeight > 0 ? 'bg-warning text-dark' : 'bg-secondary') ?> ms-2">
                Total bobot: <?= $totalDayWeight ?>%
              </span>
            </span>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDayModal">
              <i class="bi bi-plus-lg"></i> Tambah Hari
            </button>
          </div>
          <div class="card-body p-0">
            <?php if (empty($days)): ?>
              <p class="text-muted text-center py-4 mb-0">Belum ada hari/pertemuan. Tambahkan hari pertama untuk mulai mengisi materi, video, quiz, dan tugas.</p>
            <?php else: ?>
              <div class="accordion accordion-flush" id="dayAccordion">
                <?php foreach ($days as $i => $day): ?>
                  <div class="accordion-item">
                    <h2 class="accordion-header">
                      <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#day<?= $day['id'] ?>">
                        Hari <?= (int) $day['day_number'] ?> &mdash; <?= e($day['title']) ?>
                        <span class="badge bg-primary-subtle text-primary ms-2"><?= (float) $day['weight_percent'] ?>%</span>
                        <?php if ($day['day_date']): ?><span class="text-muted small ms-2"><?= formatTanggal($day['day_date']) ?></span><?php endif; ?>
                      </button>
                    </h2>
                    <div id="day<?= $day['id'] ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#dayAccordion">
                      <div class="accordion-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                          <a href="materials.php?day_id=<?= $day['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-text"></i> Materi (<?= (int) $day['material_count'] ?>)</a>
                          <a href="videos.php?day_id=<?= $day['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-camera-video"></i> Video (<?= (int) $day['video_count'] ?>)</a>
                          <a href="quizzes.php?day_id=<?= $day['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-patch-question"></i> Quiz (<?= (int) $day['quiz_count'] ?>)</a>
                          <a href="assignments.php?day_id=<?= $day['id'] ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-text"></i> Tugas (<?= (int) $day['assignment_count'] ?>)</a>
                        </div>
                        <form method="post" onsubmit="return confirm('Hapus hari ini beserta seluruh materi/video/quiz/tugas di dalamnya?');">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="delete_day">
                          <input type="hidden" name="day_id" value="<?= $day['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i> Hapus Hari Ini</button>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card">
          <div class="card-header bg-white fw-semibold">Deskripsi Kegiatan</div>
          <div class="card-body">
            <p class="small mb-2"><?= nl2br(e($event['description'] ?: '-')) ?></p>
            <p class="small mb-1"><strong>Lokasi:</strong> <?= e($event['location'] ?: '-') ?></p>
            <p class="small mb-0"><strong>Mitra:</strong> <?= e($event['partner'] ?: '-') ?></p>
          </div>
        </div>
        <div class="card mt-3">
          <div class="card-header bg-white fw-semibold">Kelola Lainnya</div>
          <div class="list-group list-group-flush">
            <a href="participants.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-people me-2"></i>Peserta Kegiatan</a>
            <a href="import_participants.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-file-earmark-excel me-2"></i>Import Peserta Excel</a>
            <button type="button" class="list-group-item list-group-item-action text-start" onclick="copyRegLink()"><i class="bi bi-link-45deg me-2"></i>Salin Link Pendaftaran</button>
            <a href="research.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-clipboard-data me-2"></i>Form Penelitian</a>
            <a href="evaluations.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-star me-2"></i>Evaluasi Kegiatan</a>
            <a href="certificates.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-award me-2"></i>Sertifikat</a>
            <a href="reports.php?event_id=<?= $eventId ?>" class="list-group-item list-group-item-action"><i class="bi bi-graph-up me-2"></i>Laporan Kegiatan</a>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Add Day Modal -->
<div class="modal fade" id="addDayModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_day">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Tambah Hari / Pertemuan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Judul Hari/Pertemuan <span class="text-danger">*</span></label>
            <input type="text" name="day_title" class="form-control" required placeholder="Contoh: Pengenalan Artificial Intelligence">
            <div class="form-text">Hari ini akan otomatis diberi nomor <strong>Hari <?= count($days) + 1 ?></strong>. Nomor menyesuaikan otomatis jika ada hari yang dihapus.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal (opsional)</label>
            <input type="date" name="day_date" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Bobot Hari Ini (% dari total progress kegiatan)</label>
            <input type="number" name="weight_percent" min="0" max="100" step="0.5" class="form-control" value="0">
            <div class="form-text">
              Total bobot seluruh hari pada kegiatan ini sebaiknya berjumlah 100%. Saat ini total bobot hari yang sudah diisi: <strong><?= $totalDayWeight ?>%</strong>.
              Jika dibiarkan 0 di semua hari, sistem otomatis membagi rata.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
function copyRegLink() {
  var link = '<?= BASE_URL ?>/register.php?event=<?= e($event['slug']) ?>';
  navigator.clipboard.writeText(link).then(function () {
    alert('Link pendaftaran disalin:\n' + link);
  });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
