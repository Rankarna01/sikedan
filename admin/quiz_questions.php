<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$quizId = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT qz.*, ed.day_number, ed.title AS day_title, ed.id AS day_id,
            e.id AS event_id, e.title AS event_title
     FROM quizzes qz
     JOIN event_days ed ON ed.id = qz.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE qz.id = ?"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    setFlash('danger', 'Quiz tidak ditemukan.');
    redirect('admin/events.php');
}

$questionTypeLabel = [
    'multiple_choice' => 'Pilihan Ganda',
    'multiple_answer' => 'Multiple Answer',
    'true_false' => 'Benar / Salah',
    'essay' => 'Essay',
];

// Add question(s) — mendukung banyak soal sekaligus dalam satu form (bulk)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_question') {
    verifyCsrf();
    $bulkQuestions = $_POST['questions'] ?? [];

    if (empty($bulkQuestions)) {
        setFlash('danger', 'Tidak ada soal yang diisi.');
        redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
    }

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE quiz_id = ?');
    $maxOrder->execute([$quizId]);
    $nextOrder = (int) $maxOrder->fetchColumn() + 1;

    $pdo->beginTransaction();
    try {
        $insQ = $pdo->prepare('INSERT INTO questions (quiz_id, question_text, question_type, score, sort_order) VALUES (?, ?, ?, ?, ?)');
        $insOpt = $pdo->prepare('INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)');
        $savedCount = 0;

        foreach ($bulkQuestions as $qData) {
            $questionText = trim($qData['text'] ?? '');
            $type = $qData['type'] ?? 'multiple_choice';
            $score = max(0, (int) ($qData['score'] ?? 10));

            if ($questionText === '' || !array_key_exists($type, $questionTypeLabel)) {
                continue; // lewati baris kosong/tidak valid, jangan gagalkan seluruh batch
            }

            $insQ->execute([$quizId, $questionText, $type, $score, $nextOrder]);
            $questionId = (int) $pdo->lastInsertId();
            $nextOrder++;
            $savedCount++;

            if ($type === 'multiple_choice' || $type === 'multiple_answer') {
                $options = $qData['options'] ?? [];
                $correctKeys = $qData['correct'] ?? [];
                if (!is_array($correctKeys)) $correctKeys = [$correctKeys];

                $order = 0;
                foreach ($options as $idx => $optText) {
                    $optText = trim($optText);
                    if ($optText === '') continue;
                    $isCorrect = in_array((string) $idx, array_map('strval', $correctKeys), true) ? 1 : 0;
                    $insOpt->execute([$questionId, $optText, $isCorrect, $order]);
                    $order++;
                }
            } elseif ($type === 'true_false') {
                $correctTF = $qData['correct_tf'] ?? 'true';
                $insOpt->execute([$questionId, 'Benar', $correctTF === 'true' ? 1 : 0, 0]);
                $insOpt->execute([$questionId, 'Salah', $correctTF === 'false' ? 1 : 0, 1]);
            }
            // essay: tidak perlu opsi, dinilai manual
        }

        $pdo->commit();
        setFlash('success', "$savedCount soal berhasil ditambahkan.");
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Gagal menambahkan soal: ' . $e->getMessage());
    }
    redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
}

// Delete question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_question') {
    verifyCsrf();
    $qid = (int) ($_POST['question_id'] ?? 0);
    $pdo->prepare('DELETE FROM questions WHERE id = ? AND quiz_id = ?')->execute([$qid, $quizId]);
    setFlash('success', 'Soal berhasil dihapus.');
    redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
}

$stmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY sort_order ASC');
$stmt->execute([$quizId]);
$questions = $stmt->fetchAll();

$optStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order ASC');
foreach ($questions as &$q) {
    if (in_array($q['question_type'], ['multiple_choice', 'multiple_answer', 'true_false'], true)) {
        $optStmt->execute([$q['id']]);
        $q['options'] = $optStmt->fetchAll();
    } else {
        $q['options'] = [];
    }
}
unset($q);

$totalScore = array_sum(array_column($questions, 'score'));

$pageTitle = 'Kelola Soal — ' . $quiz['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $quiz['event_id'] ?>"><?= e($quiz['event_title']) ?></a></li>
        <li class="breadcrumb-item"><a href="quizzes.php?day_id=<?= $quiz['day_id'] ?>">Hari <?= (int) $quiz['day_number'] ?></a></li>
        <li class="breadcrumb-item active"><?= e($quiz['title']) ?></li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h4 class="fw-bold mb-0">Kelola Soal: <?= e($quiz['title']) ?></h4>
        <p class="text-muted small mb-0"><?= count($questions) ?> soal &middot; Total skor: <?= $totalScore ?></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="quiz_questions_add.php?quiz_id=<?= $quizId ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Tambah Soal (Banyak Sekaligus)</a>
        <a href="quiz_questions_import.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-up me-1"></i>Import dari Excel/CSV</a>
        <a href="quiz_ai_generate.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-success"><i class="bi bi-stars me-1"></i>Generate dengan AI</a>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (empty($questions)): ?>
      <div class="card"><div class="card-body text-center text-muted py-5">Belum ada soal pada quiz ini.</div></div>
    <?php endif; ?>

    <?php foreach ($questions as $i => $q): ?>
      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <span class="badge bg-primary-subtle text-primary mb-2"><?= $questionTypeLabel[$q['question_type']] ?></span>
              <span class="badge bg-secondary-subtle text-secondary mb-2">Skor: <?= (int) $q['score'] ?></span>
              <p class="fw-semibold mb-2"><?= ($i + 1) ?>. <?= e($q['question_text']) ?></p>
              <?php if (!empty($q['options'])): ?>
                <ul class="list-unstyled small mb-0">
                  <?php foreach ($q['options'] as $opt): ?>
                    <li class="<?= $opt['is_correct'] ? 'text-success fw-semibold' : '' ?>">
                      <i class="bi <?= $opt['is_correct'] ? 'bi-check-circle-fill' : 'bi-circle' ?> me-1"></i><?= e($opt['option_text']) ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php elseif ($q['question_type'] === 'essay'): ?>
                <p class="small text-muted mb-0"><i class="bi bi-pencil-square"></i> Jawaban essay — dinilai manual oleh admin.</p>
              <?php endif; ?>
            </div>
            <form method="post" onsubmit="return confirm('Hapus soal ini?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_question">
              <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
              <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
