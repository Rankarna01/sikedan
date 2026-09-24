<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$assignmentId = (int) ($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.*, ed.day_number, ed.id AS day_id, e.id AS event_id, e.title AS event_title
     FROM assignments a
     JOIN event_days ed ON ed.id = a.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE a.id = ?"
);
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    setFlash('danger', 'Tugas tidak ditemukan.');
    redirect('admin/events.php');
}

$supportsLink = true;
try {
    $pdo->query('SELECT submission_link FROM assignment_submissions LIMIT 1');
} catch (PDOException $e) {
    $supportsLink = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade') {
    verifyCsrf();
    $submissionId = (int) ($_POST['submission_id'] ?? 0);
    $score = min(100, max(0, (float) ($_POST['score'] ?? 0)));
    $feedback = trim($_POST['feedback'] ?? '');

    $upd = $pdo->prepare(
        'UPDATE assignment_submissions SET score = ?, feedback = ?, graded_at = NOW()
         WHERE id = ? AND assignment_id = ?'
    );
    $upd->execute([$score, $feedback, $submissionId, $assignmentId]);

    $notifStmt = $pdo->prepare('SELECT user_id FROM assignment_submissions WHERE id = ?');
    $notifStmt->execute([$submissionId]);
    $submitterUserId = $notifStmt->fetchColumn();
    if ($submitterUserId) {
        $insNotif = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
        $insNotif->execute([$submitterUserId, 'Nilai tugas telah diberikan', "Tugas \"{$assignment['title']}\" telah dinilai: $score"]);
    }

    logActivity((int) $_SESSION['user_id'], 'grade_assignment', "Menilai pengumpulan tugas ID $submissionId");
    setFlash('success', 'Nilai berhasil disimpan.');
    redirect('admin/assignment_submissions.php?assignment_id=' . $assignmentId);
}

$selectCols = $supportsLink
    ? "s.*, u.full_name, u.institution"
    : "s.id, s.assignment_id, s.user_id, s.answer_text, s.file_path, s.submitted_at, s.is_late, s.score, s.feedback, s.graded_at, NULL AS submission_link, u.full_name, u.institution";

$stmt = $pdo->prepare(
    "SELECT $selectCols
     FROM assignment_submissions s
     JOIN users u ON u.id = s.user_id
     WHERE s.assignment_id = ?
     ORDER BY s.submitted_at DESC"
);
$stmt->execute([$assignmentId]);
$submissions = $stmt->fetchAll();

$pageTitle = 'Pengumpulan — ' . $assignment['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $assignment['event_id'] ?>"><?= e($assignment['event_title']) ?></a></li>
        <li class="breadcrumb-item"><a href="assignments.php?day_id=<?= $assignment['day_id'] ?>">Hari <?= (int) $assignment['day_number'] ?></a></li>
        <li class="breadcrumb-item active"><?= e($assignment['title']) ?></li>
      </ol>
    </nav>
    <h4 class="fw-bold mb-3">Pengumpulan: <?= e($assignment['title']) ?></h4>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Peserta</th><th>Waktu Kumpul</th><th>Status</th><th>File/Link/Jawaban</th><th>Nilai</th><th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($submissions)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada pengumpulan.</td></tr>
            <?php endif; ?>
            <?php foreach ($submissions as $s): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= e($s['full_name']) ?></div>
                  <div class="small text-muted"><?= e($s['institution'] ?: '-') ?></div>
                </td>
                <td class="small"><?= date('d M Y H:i', strtotime($s['submitted_at'])) ?></td>
                <td><?= $s['is_late'] ? '<span class="badge bg-warning">Terlambat</span>' : '<span class="badge bg-success">Tepat Waktu</span>' ?></td>
                <td class="small">
                  <?php if ($s['file_path']): ?>
                    <a href="<?= UPLOAD_URL . '/' . e($s['file_path']) ?>" target="_blank"><i class="bi bi-paperclip"></i> Unduh File</a><br>
                  <?php endif; ?>
                  <?php if (!empty($s['submission_link'])): ?>
                    <a href="<?= e($s['submission_link']) ?>" target="_blank"><i class="bi bi-link-45deg"></i> Buka Link</a><br>
                  <?php endif; ?>
                  <?php if ($s['answer_text']): ?>
                    <span class="text-muted"><?= e(mb_strimwidth($s['answer_text'], 0, 80, '...')) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= $s['score'] !== null ? (int) $s['score'] : '<span class="text-muted">Belum dinilai</span>' ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary grade-btn"
                    data-id="<?= $s['id'] ?>" data-name="<?= e($s['full_name']) ?>"
                    data-score="<?= e((string) $s['score']) ?>" data-feedback="<?= e($s['feedback']) ?>"
                    data-bs-toggle="modal" data-bs-target="#gradeModal">
                    <i class="bi bi-check2-square"></i> Nilai
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" id="gradeForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="grade">
      <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
      <input type="hidden" name="submission_id" id="gradeSubmissionId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Beri Nilai — <span id="gradeStudentName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nilai (0-100)</label>
            <input type="number" name="score" id="gradeScore" min="0" max="100" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Feedback</label>
            <textarea name="feedback" id="gradeFeedback" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan Nilai</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.grade-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('gradeSubmissionId').value = this.getAttribute('data-id');
    document.getElementById('gradeStudentName').textContent = this.getAttribute('data-name');
    var score = this.getAttribute('data-score');
    document.getElementById('gradeScore').value = (score === '' || score === null) ? '' : score;
    document.getElementById('gradeFeedback').value = this.getAttribute('data-feedback') || '';
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
