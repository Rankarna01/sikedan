<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$dayId = (int) ($_GET['day_id'] ?? $_POST['day_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT ed.*, e.id AS event_id, e.title AS event_title
     FROM event_days ed JOIN events e ON e.id = ed.event_id WHERE ed.id = ?"
);
$stmt->execute([$dayId]);
$day = $stmt->fetch();

if (!$day) {
    setFlash('danger', 'Hari/pertemuan tidak ditemukan.');
    redirect('admin/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $startDate = $_POST['start_date'] ?: null;
    $deadline = $_POST['deadline'] ?: null;
    $allowLate = isset($_POST['allow_late']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul tugas wajib diisi.');
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO assignments (event_day_id, title, instructions, start_date, deadline, allow_late, status, weight_percent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$dayId, $title, $instructions, $startDate, $deadline, $allowLate, $status, $weightPercent]);

        if ($status === 'active') {
            $dayInfo = $pdo->prepare('SELECT event_id FROM event_days WHERE id = ?');
            $dayInfo->execute([$dayId]);
            $evId = $dayInfo->fetchColumn();
            $participants = $pdo->prepare("SELECT user_id FROM event_participants WHERE event_id = ? AND status = 'approved'");
            $participants->execute([$evId]);
            $insNotif = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
            foreach ($participants->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $insNotif->execute([$uid, 'Tugas baru tersedia', "Tugas \"$title\" telah tersedia untuk dikerjakan."]);
            }
        }

        logActivity((int) $_SESSION['user_id'], 'add_assignment', "Menambah tugas: $title");
        setFlash('success', 'Tugas berhasil ditambahkan.');
    }
    redirect('admin/assignments.php?day_id=' . $dayId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $startDate = $_POST['start_date'] ?: null;
    $deadline = $_POST['deadline'] ?: null;
    $allowLate = isset($_POST['allow_late']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul tugas wajib diisi.');
    } else {
        $upd = $pdo->prepare(
            'UPDATE assignments SET title=?, instructions=?, start_date=?, deadline=?, allow_late=?, status=?, weight_percent=?
             WHERE id=? AND event_day_id=?'
        );
        $upd->execute([$title, $instructions, $startDate, $deadline, $allowLate, $status, $weightPercent, $id, $dayId]);
        setFlash('success', 'Tugas berhasil diperbarui.');
    }
    redirect('admin/assignments.php?day_id=' . $dayId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM assignments WHERE id = ? AND event_day_id = ?')->execute([$id, $dayId]);
    setFlash('success', 'Tugas berhasil dihapus.');
    redirect('admin/assignments.php?day_id=' . $dayId);
}

$stmt = $pdo->prepare(
    "SELECT a.*,
     (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id) AS submission_count,
     (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.score IS NOT NULL) AS graded_count
     FROM assignments a WHERE a.event_day_id = ? ORDER BY a.id ASC"
);
$stmt->execute([$dayId]);
$assignments = $stmt->fetchAll();

$weightStmt = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(weight_percent),0) FROM materials WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM videos WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM quizzes WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM assignments WHERE event_day_id = ?) AS total_weight"
);
$weightStmt->execute([$dayId, $dayId, $dayId, $dayId]);
$totalDayItemWeight = (float) $weightStmt->fetchColumn();

$statusLabel = ['draft' => 'Draft', 'active' => 'Aktif', 'closed' => 'Ditutup'];
$statusBadge = ['draft' => 'secondary', 'active' => 'success', 'closed' => 'danger'];

$pageTitle = 'Tugas — ' . $day['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $day['event_id'] ?>"><?= e($day['event_title']) ?></a></li>
        <li class="breadcrumb-item active">Hari <?= (int) $day['day_number'] ?> — Tugas</li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="fw-bold mb-1">Tugas: <?= e($day['title']) ?></h4>
        <span class="badge <?= abs($totalDayItemWeight - 100) < 0.01 ? 'bg-success' : ($totalDayItemWeight > 0 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
          Total bobot hari ini (materi+video+quiz+tugas): <?= $totalDayItemWeight ?>%
        </span>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssignmentModal"><i class="bi bi-plus-lg me-1"></i>Tambah Tugas</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if (empty($assignments)): ?>
        <p class="text-muted">Belum ada tugas pada hari ini.</p>
      <?php endif; ?>
      <?php foreach ($assignments as $a): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0"><?= e($a['title']) ?></h6>
                <span class="badge bg-<?= $statusBadge[$a['status']] ?>"><?= $statusLabel[$a['status']] ?></span>
              </div>
              <p class="small text-muted mb-2"><?= e(mb_strimwidth(strip_tags($a['instructions'] ?? ''), 0, 120, '...')) ?></p>
              <p class="small mb-1"><i class="bi bi-calendar-x"></i> Deadline: <?= $a['deadline'] ? date('d M Y H:i', strtotime($a['deadline'])) : '-' ?></p>
              <p class="small mb-0"><i class="bi bi-inbox"></i> <?= (int) $a['submission_count'] ?> pengumpulan, <?= (int) $a['graded_count'] ?> sudah dinilai</p>
              <p class="small mb-0"><i class="bi bi-percent"></i> Bobot progress: <?= (float) $a['weight_percent'] ?>%</p>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2">
              <a href="assignment_submissions.php?assignment_id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-inbox"></i> Lihat Pengumpulan</a>
              <button class="btn btn-sm btn-outline-secondary edit-assignment-btn"
                data-assignment='<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-bs-toggle="modal" data-bs-target="#editAssignmentModal">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <form method="post" onsubmit="return confirm('Hapus tugas ini beserta seluruh pengumpulan peserta?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="day_id" value="<?= $dayId ?>">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addAssignmentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Tugas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php include __DIR__ . '/partials/assignment_form_fields.php'; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editAssignmentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" id="editAssignmentForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <input type="hidden" name="id" id="editAsgId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Tugas</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php include __DIR__ . '/partials/assignment_form_fields.php'; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan Perubahan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.edit-assignment-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var a = JSON.parse(this.getAttribute('data-assignment'));
    var form = document.getElementById('editAssignmentForm');
    document.getElementById('editAsgId').value = a.id;
    form.querySelector('[name="title"]').value = a.title;
    form.querySelector('[name="instructions"]').value = a.instructions || '';
    form.querySelector('[name="start_date"]').value = a.start_date ? a.start_date.replace(' ', 'T').slice(0,16) : '';
    form.querySelector('[name="deadline"]').value = a.deadline ? a.deadline.replace(' ', 'T').slice(0,16) : '';
    form.querySelector('[name="allow_late"]').checked = a.allow_late == 1;
    form.querySelector('[name="status"]').value = a.status;
    form.querySelector('[name="weight_percent"]').value = a.weight_percent || 0;
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
