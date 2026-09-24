<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$quizId = (int) ($_GET['id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT q.*, ed.day_number, ed.event_id, e.title AS event_title
     FROM quizzes q
     JOIN event_days ed ON ed.id = q.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE q.id = ? AND q.status = 'active'"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    setFlash('danger', 'Quiz tidak ditemukan atau tidak aktif.');
    redirect('peserta/events.php');
}

// Verify enrollment
if (!isApprovedParticipant($pdo, $userId, (int) $quiz['event_id'])) {
    setFlash('danger', 'Anda belum terdaftar atau pendaftaran Anda belum disetujui admin.');
    redirect('peserta/events.php');
}

// Check schedule window
$now = new DateTime();
if ($quiz['start_time'] && $now < new DateTime($quiz['start_time'])) {
    setFlash('danger', 'Quiz ini belum dibuka. Silakan kembali lagi nanti.');
    redirect('peserta/event_detail.php?id=' . $quiz['event_id']);
}
if ($quiz['end_time'] && $now > new DateTime($quiz['end_time'])) {
    setFlash('danger', 'Waktu pengerjaan quiz ini sudah berakhir.');
    redirect('peserta/event_detail.php?id=' . $quiz['event_id']);
}

// Check attempt count
$attemptStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?');
$attemptStmt->execute([$quizId, $userId]);
$attemptsUsed = (int) $attemptStmt->fetchColumn();

if ($attemptsUsed >= $quiz['max_attempts']) {
    $bestStmt = $pdo->prepare('SELECT MAX(score) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?');
    $bestStmt->execute([$quizId, $userId]);
    $bestScore = (float) $bestStmt->fetchColumn();

    $pageTitle = $quiz['title'];
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/navbar.php';
    ?>
    <div class="app-layout">
      <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-content">
        <div class="card mx-auto" style="max-width:500px;">
          <div class="card-body text-center p-4">
            <i class="bi bi-hourglass-bottom text-warning fs-1"></i>
            <h5 class="fw-bold mt-3">Batas Percobaan Tercapai</h5>
            <p class="text-muted">Anda sudah menggunakan seluruh <?= (int) $quiz['max_attempts'] ?> kesempatan mengerjakan quiz ini.</p>
            <p class="mb-3">Skor terbaik Anda: <strong><?= $bestScore ?></strong> (passing grade: <?= (int) $quiz['passing_grade'] ?>)</p>
            <a href="event_detail.php?id=<?= $quiz['event_id'] ?>" class="btn btn-primary btn-sm">Kembali ke Kegiatan</a>
          </div>
        </div>
      </main>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
    <?php
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_quiz') {
    verifyCsrf();

    $qStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ?');
    $qStmt->execute([$quizId]);
    $questions = $qStmt->fetchAll();

    $optStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = ?');

    $pdo->beginTransaction();
    try {
        $attemptNumber = $attemptsUsed + 1;
        $insAttempt = $pdo->prepare('INSERT INTO quiz_attempts (quiz_id, user_id, attempt_number, started_at) VALUES (?, ?, ?, NOW())');
        $insAttempt->execute([$quizId, $userId, $attemptNumber]);
        $attemptId = (int) $pdo->lastInsertId();

        $totalScore = 0;
        $maxScore = 0;
        $insAnswer = $pdo->prepare(
            'INSERT INTO quiz_answers (attempt_id, question_id, selected_options, essay_answer, score, is_correct, feedback)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($questions as $q) {
            $maxScore += (int) $q['score'];
            $field = 'q' . $q['id'];

            if ($q['question_type'] === 'essay') {
                $essayAnswer = trim($_POST[$field] ?? '');
                $insAnswer->execute([$attemptId, $q['id'], null, $essayAnswer, 0, 0, null]);
                continue; // scored manually later
            }

            $optStmt->execute([$q['id']]);
            $options = $optStmt->fetchAll();
            $correctIds = array_column(array_filter($options, fn($o) => (int) $o['is_correct'] === 1), 'id');

            if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'true_false') {
                $selected = (int) ($_POST[$field] ?? 0);
                $isCorrect = in_array($selected, $correctIds, true);
                $score = $isCorrect ? (int) $q['score'] : 0;
                $insAnswer->execute([$attemptId, $q['id'], (string) $selected, null, $score, $isCorrect ? 1 : 0, null]);
                $totalScore += $score;
            } elseif ($q['question_type'] === 'multiple_answer') {
                $selectedArr = $_POST[$field] ?? [];
                if (!is_array($selectedArr)) $selectedArr = [$selectedArr];
                $selectedArr = array_map('intval', $selectedArr);
                sort($selectedArr);
                $correctSorted = $correctIds;
                sort($correctSorted);
                $isCorrect = ($selectedArr === $correctSorted) && !empty($selectedArr);
                $score = $isCorrect ? (int) $q['score'] : 0;
                $insAnswer->execute([$attemptId, $q['id'], implode(',', $selectedArr), null, $score, $isCorrect ? 1 : 0, null]);
                $totalScore += $score;
            }
        }

        $percentScore = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;
        $isPassed = $percentScore >= $quiz['passing_grade'] ? 1 : 0;

        $updAttempt = $pdo->prepare('UPDATE quiz_attempts SET score = ?, is_passed = ?, finished_at = NOW() WHERE id = ?');
        $updAttempt->execute([$percentScore, $isPassed, $attemptId]);

        $pdo->commit();
        logActivity($userId, 'submit_quiz', "Mengerjakan quiz: {$quiz['title']} (skor: $percentScore)");
        redirect('peserta/quiz_result.php?attempt_id=' . $attemptId);
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Terjadi kesalahan saat menyimpan jawaban. Silakan coba lagi.');
        redirect('peserta/quiz_take.php?id=' . $quizId);
    }
}

// Load questions for display
$qStmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY ' . ($quiz['random_question'] ? 'RAND()' : 'sort_order ASC'));
$qStmt->execute([$quizId]);
$questions = $qStmt->fetchAll();

$optStmt = $pdo->prepare('SELECT * FROM question_options WHERE question_id = ? ORDER BY ' . ($quiz['random_option'] ? 'RAND()' : 'sort_order ASC'));
foreach ($questions as &$q) {
    if (in_array($q['question_type'], ['multiple_choice', 'multiple_answer', 'true_false'], true)) {
        $optStmt->execute([$q['id']]);
        $q['options'] = $optStmt->fetchAll();
    } else {
        $q['options'] = [];
    }
}
unset($q);

$typeLabel = ['multiple_choice' => 'Pilihan Ganda', 'multiple_answer' => 'Multiple Answer', 'true_false' => 'Benar/Salah', 'essay' => 'Essay'];

$pageTitle = $quiz['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="card mb-3 border-primary">
      <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="fw-bold mb-1"><?= e($quiz['title']) ?></h5>
          <p class="text-muted small mb-0"><?= e($quiz['event_title']) ?> &middot; Percobaan ke-<?= $attemptsUsed + 1 ?> dari <?= (int) $quiz['max_attempts'] ?></p>
        </div>
        <div id="quizTimer" class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-clock me-1"></i><span id="timerDisplay">--:--</span></div>
      </div>
    </div>

    <form method="post" id="quizForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="submit_quiz">
      <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

      <?php foreach ($questions as $i => $q): $field = 'q' . $q['id']; ?>
        <div class="card mb-3">
          <div class="card-body">
            <span class="badge bg-secondary-subtle text-secondary mb-2"><?= $typeLabel[$q['question_type']] ?> &middot; <?= (int) $q['score'] ?> poin</span>
            <p class="fw-semibold"><?= ($i + 1) ?>. <?= e($q['question_text']) ?></p>

            <?php if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'true_false'): ?>
              <?php foreach ($q['options'] as $opt): ?>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="<?= $field ?>" id="<?= $field ?>_<?= $opt['id'] ?>" value="<?= $opt['id'] ?>" required>
                  <label class="form-check-label" for="<?= $field ?>_<?= $opt['id'] ?>"><?= e($opt['option_text']) ?></label>
                </div>
              <?php endforeach; ?>

            <?php elseif ($q['question_type'] === 'multiple_answer'): ?>
              <?php foreach ($q['options'] as $opt): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="<?= $field ?>[]" id="<?= $field ?>_<?= $opt['id'] ?>" value="<?= $opt['id'] ?>">
                  <label class="form-check-label" for="<?= $field ?>_<?= $opt['id'] ?>"><?= e($opt['option_text']) ?></label>
                </div>
              <?php endforeach; ?>

            <?php elseif ($q['question_type'] === 'essay'): ?>
              <textarea name="<?= $field ?>" class="form-control" rows="4" placeholder="Tulis jawaban Anda..." required></textarea>
              <p class="form-text mb-0"><i class="bi bi-info-circle"></i> Jawaban essay akan dinilai manual oleh admin.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary w-100 mb-4" id="submitQuizBtn"><i class="bi bi-send-check me-1"></i>Kumpulkan Jawaban</button>
    </form>
  </main>
</div>

<script>
(function () {
  var durationSeconds = <?= (int) $quiz['duration_minutes'] * 60 ?>;
  var timerDisplay = document.getElementById('timerDisplay');
  var quizForm = document.getElementById('quizForm');
  var submitted = false;

  function formatTime(sec) {
    var m = Math.floor(sec / 60), s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function tick() {
    if (submitted) return;
    timerDisplay.textContent = formatTime(durationSeconds);
    if (durationSeconds <= 0) {
      submitted = true;
      alert('Waktu habis! Jawaban Anda akan dikumpulkan otomatis.');
      quizForm.submit();
      return;
    }
    durationSeconds--;
    setTimeout(tick, 1000);
  }
  tick();

  quizForm.addEventListener('submit', function () {
    submitted = true;
    document.getElementById('submitQuizBtn').disabled = true;
    document.getElementById('submitQuizBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengirim...';
  });

  window.addEventListener('beforeunload', function (e) {
    if (!submitted) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
