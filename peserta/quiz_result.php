<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$attemptId = (int) ($_GET['attempt_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT qa.*, q.title AS quiz_title, q.passing_grade, q.event_day_id, ed.event_id, e.title AS event_title
     FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     JOIN event_days ed ON ed.id = q.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE qa.id = ? AND qa.user_id = ?"
);
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    setFlash('danger', 'Hasil quiz tidak ditemukan.');
    redirect('peserta/events.php');
}

updateParticipantProgress($pdo, $userId, (int) $attempt['event_id']);

$answerStmt = $pdo->prepare(
    "SELECT qa.*, q.question_text, q.question_type, q.score AS max_score
     FROM quiz_answers qa JOIN questions q ON q.id = qa.question_id
     WHERE qa.attempt_id = ? ORDER BY q.sort_order"
);
$answerStmt->execute([$attemptId]);
$answers = $answerStmt->fetchAll();

$hasEssay = false;
foreach ($answers as $a) {
    if ($a['question_type'] === 'essay') { $hasEssay = true; break; }
}

$pageTitle = 'Hasil Quiz — ' . $attempt['quiz_title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="card mx-auto mb-4" style="max-width:500px;">
      <div class="card-body text-center p-4">
        <?php if ($attempt['is_passed']): ?>
          <i class="bi bi-patch-check-fill text-success fs-1"></i>
          <h5 class="fw-bold mt-3 text-success">Selamat, Anda Lulus!</h5>
        <?php else: ?>
          <i class="bi bi-emoji-neutral text-warning fs-1"></i>
          <h5 class="fw-bold mt-3 text-warning">Belum Mencapai Passing Grade</h5>
        <?php endif; ?>
        <p class="text-muted mb-1"><?= e($attempt['quiz_title']) ?></p>
        <h2 class="fw-bold my-2"><?= (float) $attempt['score'] ?></h2>
        <p class="small text-muted">Passing grade: <?= (int) $attempt['passing_grade'] ?></p>
        <?php if ($hasEssay): ?>
          <div class="alert alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> Skor di atas belum termasuk soal essay yang masih menunggu penilaian manual dari admin.</div>
        <?php endif; ?>
        <a href="event_detail.php?id=<?= $attempt['event_id'] ?>" class="btn btn-primary btn-sm mt-3">Kembali ke Kegiatan</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-white fw-semibold">Rincian Jawaban</div>
      <div class="list-group list-group-flush">
        <?php foreach ($answers as $i => $a): ?>
          <div class="list-group-item">
            <p class="fw-semibold mb-1"><?= ($i + 1) ?>. <?= e($a['question_text']) ?></p>
            <?php if ($a['question_type'] === 'essay'): ?>
              <p class="small text-muted mb-1">Jawaban Anda: <?= e($a['essay_answer']) ?></p>
              <span class="badge bg-secondary">Menunggu penilaian manual</span>
            <?php else: ?>
              <span class="badge bg-<?= $a['is_correct'] ? 'success' : 'danger' ?>">
                <?= $a['is_correct'] ? 'Benar' : 'Salah' ?> (<?= (int) $a['score'] ?>/<?= (int) $a['max_score'] ?> poin)
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
