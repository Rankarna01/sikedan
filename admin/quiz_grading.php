<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$quizId = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT qz.*, ed.day_number, e.id AS event_id, e.title AS event_title
     FROM quizzes qz JOIN event_days ed ON ed.id = qz.event_day_id JOIN events e ON e.id = ed.event_id
     WHERE qz.id = ?"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    setFlash('danger', 'Quiz tidak ditemukan.');
    redirect('admin/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade_essay') {
    verifyCsrf();
    $answerId = (int) ($_POST['answer_id'] ?? 0);
    $score = max(0, (float) ($_POST['score'] ?? 0));
    $feedback = trim($_POST['feedback'] ?? '');

    $pdo->beginTransaction();
    try {
        // Get max score & attempt id for recalculation
        $find = $pdo->prepare(
            "SELECT qa.attempt_id, q.score AS max_score, q.id AS question_id
             FROM quiz_answers qa JOIN questions q ON q.id = qa.question_id
             WHERE qa.id = ?"
        );
        $find->execute([$answerId]);
        $ans = $find->fetch();
        $score = min($score, (float) $ans['max_score']);

        $upd = $pdo->prepare('UPDATE quiz_answers SET score = ?, is_correct = ?, feedback = ? WHERE id = ?');
        $upd->execute([$score, $score > 0 ? 1 : 0, $feedback, $answerId]);

        // Recalculate total attempt score
        $sumStmt = $pdo->prepare('SELECT SUM(score) AS total FROM quiz_answers WHERE attempt_id = ?');
        $sumStmt->execute([$ans['attempt_id']]);
        $newTotal = (float) $sumStmt->fetchColumn();

        $maxStmt = $pdo->prepare('SELECT SUM(score) FROM questions WHERE quiz_id = ?');
        $maxStmt->execute([$quizId]);
        $maxTotal = (float) $maxStmt->fetchColumn();

        $percent = $maxTotal > 0 ? round(($newTotal / $maxTotal) * 100, 2) : 0;
        $isPassed = $percent >= $quiz['passing_grade'] ? 1 : 0;

        $updAttempt = $pdo->prepare('UPDATE quiz_attempts SET score = ?, is_passed = ? WHERE id = ?');
        $updAttempt->execute([$percent, $isPassed, $ans['attempt_id']]);

        $pdo->commit();
        setFlash('success', 'Nilai essay berhasil disimpan.');
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Gagal menyimpan nilai.');
    }
    redirect('admin/quiz_grading.php?quiz_id=' . $quizId);
}

$stmt = $pdo->prepare(
    "SELECT qans.*, u.full_name, qat.attempt_number, q.question_text, q.score AS max_score
     FROM quiz_answers qans
     JOIN quiz_attempts qat ON qat.id = qans.attempt_id
     JOIN users u ON u.id = qat.user_id
     JOIN questions q ON q.id = qans.question_id
     WHERE qat.quiz_id = ? AND q.question_type = 'essay'
     ORDER BY qat.finished_at DESC"
);
$stmt->execute([$quizId]);
$essayAnswers = $stmt->fetchAll();

$pageTitle = 'Nilai Essay — ' . $quiz['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $quiz['event_id'] ?>"><?= e($quiz['event_title']) ?></a></li><li class="breadcrumb-item"><a href="quiz_questions.php?quiz_id=<?= $quizId ?>"><?= e($quiz['title']) ?></a></li><li class="breadcrumb-item active">Nilai Essay</li></ol></nav>
    <h4 class="fw-bold mb-3">Nilai Jawaban Essay: <?= e($quiz['title']) ?></h4>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if (empty($essayAnswers)): ?>
      <div class="card"><div class="card-body text-center text-muted py-5">Belum ada jawaban essay yang perlu dinilai.</div></div>
    <?php endif; ?>

    <?php foreach ($essayAnswers as $a): ?>
      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <span class="fw-semibold"><?= e($a['full_name']) ?></span>
              <span class="text-muted small">(percobaan ke-<?= (int) $a['attempt_number'] ?>)</span>
            </div>
            <span class="badge bg-<?= $a['score'] !== null && (float) $a['score'] > 0 ? 'success' : 'secondary' ?>">
              <?= $a['score'] !== null ? (float) $a['score'] . '/' . (int) $a['max_score'] : 'Belum dinilai' ?>
            </span>
          </div>
          <p class="small text-muted mb-1"><?= e($a['question_text']) ?></p>
          <p class="border rounded p-2 bg-light small"><?= nl2br(e($a['essay_answer'])) ?></p>
          <form method="post" class="row g-2 align-items-end mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="grade_essay">
            <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
            <input type="hidden" name="answer_id" value="<?= $a['id'] ?>">
            <div class="col-auto">
              <label class="form-label small mb-0">Skor (maks <?= (int) $a['max_score'] ?>)</label>
              <input type="number" name="score" min="0" max="<?= (int) $a['max_score'] ?>" class="form-control form-control-sm" value="<?= $a['score'] ?? '' ?>" style="width:100px;">
            </div>
            <div class="col">
              <label class="form-label small mb-0">Feedback</label>
              <input type="text" name="feedback" class="form-control form-control-sm" value="<?= e($a['feedback'] ?? '') ?>">
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
