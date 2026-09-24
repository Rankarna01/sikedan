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
    $description = trim($_POST['description'] ?? '');
    $duration = max(1, (int) ($_POST['duration_minutes'] ?? 20));
    $passingGrade = min(100, max(0, (int) ($_POST['passing_grade'] ?? 70)));
    $maxAttempts = max(1, (int) ($_POST['max_attempts'] ?? 1));
    $randomQ = isset($_POST['random_question']) ? 1 : 0;
    $randomO = isset($_POST['random_option']) ? 1 : 0;
    $startTime = $_POST['start_time'] ?: null;
    $endTime = $_POST['end_time'] ?: null;
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul quiz wajib diisi.');
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO quizzes (event_day_id, title, description, duration_minutes, passing_grade, max_attempts,
             random_question, random_option, start_time, end_time, status, weight_percent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$dayId, $title, $description, $duration, $passingGrade, $maxAttempts, $randomQ, $randomO, $startTime, $endTime, $status, $weightPercent]);
        $newId = (int) $pdo->lastInsertId();

        if ($status === 'active') {
            $dayInfo = $pdo->prepare('SELECT event_id FROM event_days WHERE id = ?');
            $dayInfo->execute([$dayId]);
            $evId = $dayInfo->fetchColumn();
            $participants = $pdo->prepare("SELECT user_id FROM event_participants WHERE event_id = ? AND status = 'approved'");
            $participants->execute([$evId]);
            $insNotif = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
            foreach ($participants->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $insNotif->execute([$uid, 'Quiz baru tersedia', "Quiz \"$title\" telah tersedia untuk dikerjakan."]);
            }
        }

        logActivity((int) $_SESSION['user_id'], 'add_quiz', "Menambah quiz: $title");
        setFlash('success', 'Quiz berhasil dibuat. Silakan tambahkan soal.');
        redirect('admin/quiz_questions.php?quiz_id=' . $newId);
    }
    redirect('admin/quizzes.php?day_id=' . $dayId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $duration = max(1, (int) ($_POST['duration_minutes'] ?? 20));
    $passingGrade = min(100, max(0, (int) ($_POST['passing_grade'] ?? 70)));
    $maxAttempts = max(1, (int) ($_POST['max_attempts'] ?? 1));
    $randomQ = isset($_POST['random_question']) ? 1 : 0;
    $randomO = isset($_POST['random_option']) ? 1 : 0;
    $startTime = $_POST['start_time'] ?: null;
    $endTime = $_POST['end_time'] ?: null;
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul quiz wajib diisi.');
    } else {
        $upd = $pdo->prepare(
            'UPDATE quizzes SET title=?, description=?, duration_minutes=?, passing_grade=?, max_attempts=?,
             random_question=?, random_option=?, start_time=?, end_time=?, status=?, weight_percent=? WHERE id=? AND event_day_id=?'
        );
        $upd->execute([$title, $description, $duration, $passingGrade, $maxAttempts, $randomQ, $randomO, $startTime, $endTime, $status, $weightPercent, $id, $dayId]);
        setFlash('success', 'Quiz berhasil diperbarui.');
    }
    redirect('admin/quizzes.php?day_id=' . $dayId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM quizzes WHERE id = ? AND event_day_id = ?')->execute([$id, $dayId]);
    setFlash('success', 'Quiz berhasil dihapus.');
    redirect('admin/quizzes.php?day_id=' . $dayId);
}

$stmt = $pdo->prepare(
    "SELECT q.*, (SELECT COUNT(*) FROM questions qs WHERE qs.quiz_id = q.id) AS question_count
     FROM quizzes q WHERE q.event_day_id = ? ORDER BY q.id ASC"
);
$stmt->execute([$dayId]);
$quizzes = $stmt->fetchAll();

$weightStmt = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(weight_percent),0) FROM materials WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM videos WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM quizzes WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM assignments WHERE event_day_id = ?) AS total_weight"
);
$weightStmt->execute([$dayId, $dayId, $dayId, $dayId]);
$totalDayItemWeight = (float) $weightStmt->fetchColumn();

$statusLabel = ['draft' => 'Draft', 'active' => 'Aktif', 'inactive' => 'Nonaktif'];
$statusBadge = ['draft' => 'secondary', 'active' => 'success', 'inactive' => 'warning'];

$pageTitle = 'Quiz — ' . $day['title'];
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
        <li class="breadcrumb-item active">Hari <?= (int) $day['day_number'] ?> — Quiz</li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="fw-bold mb-1">Quiz: <?= e($day['title']) ?></h4>
        <span class="badge <?= abs($totalDayItemWeight - 100) < 0.01 ? 'bg-success' : ($totalDayItemWeight > 0 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
          Total bobot hari ini (materi+video+quiz+tugas): <?= $totalDayItemWeight ?>%
        </span>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuizModal"><i class="bi bi-plus-lg me-1"></i>Tambah Quiz</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if (empty($quizzes)): ?>
        <p class="text-muted">Belum ada quiz pada hari ini.</p>
      <?php endif; ?>
      <?php foreach ($quizzes as $q): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0"><?= e($q['title']) ?></h6>
                <span class="badge bg-<?= $statusBadge[$q['status']] ?>"><?= $statusLabel[$q['status']] ?></span>
              </div>
              <p class="small text-muted mb-2"><?= e($q['description'] ?: '-') ?></p>
              <ul class="list-unstyled small mb-0">
                <li><i class="bi bi-question-circle me-1"></i><?= (int) $q['question_count'] ?> soal</li>
                <li><i class="bi bi-clock me-1"></i>Durasi: <?= (int) $q['duration_minutes'] ?> menit</li>
                <li><i class="bi bi-check2-circle me-1"></i>Passing grade: <?= (int) $q['passing_grade'] ?></li>
                <li><i class="bi bi-arrow-repeat me-1"></i>Percobaan: <?= (int) $q['max_attempts'] ?>x</li>
                <li><i class="bi bi-percent me-1"></i>Bobot progress: <?= (float) $q['weight_percent'] ?>%</li>
              </ul>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2">
              <a href="quiz_questions.php?quiz_id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-check"></i> Kelola Soal</a>
              <a href="quiz_grading.php?quiz_id=<?= $q['id'] ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-square"></i> Nilai Essay</a>
              <button class="btn btn-sm btn-outline-secondary edit-quiz-btn"
                data-quiz='<?= json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-bs-toggle="modal" data-bs-target="#editQuizModal">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <form method="post" onsubmit="return confirm('Hapus quiz ini beserta seluruh soal dan hasil pengerjaan peserta?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="day_id" value="<?= $dayId ?>">
                <input type="hidden" name="id" value="<?= $q['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addQuizModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Quiz</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php include __DIR__ . '/partials/quiz_form_fields.php'; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan &amp; Kelola Soal</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editQuizModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" id="editQuizForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <input type="hidden" name="id" id="editQuizId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Quiz</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php include __DIR__ . '/partials/quiz_form_fields.php'; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan Perubahan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.edit-quiz-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var q = JSON.parse(this.getAttribute('data-quiz'));
    var form = document.getElementById('editQuizForm');
    document.getElementById('editQuizId').value = q.id;
    form.querySelector('[name="title"]').value = q.title;
    form.querySelector('[name="description"]').value = q.description || '';
    form.querySelector('[name="duration_minutes"]').value = q.duration_minutes;
    form.querySelector('[name="passing_grade"]').value = q.passing_grade;
    form.querySelector('[name="max_attempts"]').value = q.max_attempts;
    form.querySelector('[name="random_question"]').checked = q.random_question == 1;
    form.querySelector('[name="random_option"]').checked = q.random_option == 1;
    form.querySelector('[name="start_time"]').value = q.start_time ? q.start_time.replace(' ', 'T').slice(0,16) : '';
    form.querySelector('[name="end_time"]').value = q.end_time ? q.end_time.replace(' ', 'T').slice(0,16) : '';
    form.querySelector('[name="status"]').value = q.status;
    form.querySelector('[name="weight_percent"]').value = q.weight_percent || 0;
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
