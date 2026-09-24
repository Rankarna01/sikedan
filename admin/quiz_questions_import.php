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

$questionTypeLabel = ['multiple_choice' => 'Pilihan Ganda', 'multiple_answer' => 'Multiple Answer', 'true_false' => 'Benar/Salah', 'essay' => 'Essay'];

/**
 * Format CSV bank soal (kolom):
 * question_text, question_type, score, option_a, option_b, option_c, option_d, correct
 *
 * - question_type: multiple_choice | multiple_answer | true_false | essay
 * - correct (untuk multiple_choice/true_false): huruf tunggal, misal "a" atau "true"/"false"
 * - correct (untuk multiple_answer): huruf dipisah koma, misal "a,c"
 * - Untuk essay, kolom option_* dan correct boleh dikosongkan.
 */
function parseQuestionCsv(string $path): array
{
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) return [];
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) === 1 && trim($data[0]) === '') continue;
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim($data[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);
    }
    return $rows;
}

$step = $_POST['step'] ?? 'upload';
$previewRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview') {
    verifyCsrf();
    if (empty($_FILES['file']['name'])) {
        setFlash('danger', 'Silakan pilih file CSV terlebih dahulu.');
        redirect('admin/quiz_questions_import.php?quiz_id=' . $quizId);
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        setFlash('danger', 'Format file harus .csv. Jika file Anda .xlsx, silakan "Save As" ke CSV di Excel/Google Sheets terlebih dahulu.');
        redirect('admin/quiz_questions_import.php?quiz_id=' . $quizId);
    }
    if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
        setFlash('danger', 'Ukuran file melebihi batas maksimum (5MB).');
        redirect('admin/quiz_questions_import.php?quiz_id=' . $quizId);
    }

    $tmpPath = UPLOAD_PATH . '/documents/qimport_' . time() . '_' . bin2hex(random_bytes(4)) . '.csv';
    move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath);
    $rawRows = parseQuestionCsv($tmpPath);

    foreach ($rawRows as $row) {
        $errors = [];
        $text = $row['question_text'] ?? '';
        $type = strtolower($row['question_type'] ?? 'multiple_choice');
        $score = (int) ($row['score'] ?? 10);

        if ($text === '') $errors[] = 'Pertanyaan kosong';
        if (!array_key_exists($type, $questionTypeLabel)) $errors[] = 'Tipe soal tidak dikenali';

        $previewRows[] = [
            'question_text' => $text, 'question_type' => $type, 'score' => $score ?: 10,
            'option_a' => $row['option_a'] ?? '', 'option_b' => $row['option_b'] ?? '',
            'option_c' => $row['option_c'] ?? '', 'option_d' => $row['option_d'] ?? '',
            'correct' => strtolower($row['correct'] ?? ''), 'errors' => $errors,
        ];
    }

    $_SESSION['qimport_preview'] = $previewRows;
    $_SESSION['qimport_tmp_path'] = $tmpPath;
    $step = 'review';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    verifyCsrf();
    $rows = $_SESSION['qimport_preview'] ?? [];
    $imported = 0;

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE quiz_id = ?');
    $maxOrder->execute([$quizId]);
    $nextOrder = (int) $maxOrder->fetchColumn() + 1;

    $pdo->beginTransaction();
    try {
        $insQ = $pdo->prepare('INSERT INTO questions (quiz_id, question_text, question_type, score, sort_order) VALUES (?, ?, ?, ?, ?)');
        $insOpt = $pdo->prepare('INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)');
        $letterMap = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];

        foreach ($rows as $row) {
            if (!empty($row['errors'])) continue;

            $insQ->execute([$quizId, $row['question_text'], $row['question_type'], $row['score'], $nextOrder]);
            $questionId = (int) $pdo->lastInsertId();
            $nextOrder++;
            $imported++;

            if (in_array($row['question_type'], ['multiple_choice', 'multiple_answer'], true)) {
                $correctLetters = array_map('trim', explode(',', $row['correct']));
                $options = [$row['option_a'], $row['option_b'], $row['option_c'], $row['option_d']];
                foreach ($options as $idx => $optText) {
                    if (trim($optText) === '') continue;
                    $letter = array_search($idx, $letterMap, true);
                    $isCorrect = in_array($letter, $correctLetters, true) ? 1 : 0;
                    $insOpt->execute([$questionId, $optText, $isCorrect, $idx]);
                }
            } elseif ($row['question_type'] === 'true_false') {
                $correctVal = strtolower(trim($row['correct']));
                $insOpt->execute([$questionId, 'Benar', $correctVal === 'true' || $correctVal === 'benar' ? 1 : 0, 0]);
                $insOpt->execute([$questionId, 'Salah', $correctVal === 'false' || $correctVal === 'salah' ? 1 : 0, 1]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Gagal mengimpor soal: ' . $e->getMessage());
        redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
    }

    if (!empty($_SESSION['qimport_tmp_path']) && file_exists($_SESSION['qimport_tmp_path'])) {
        @unlink($_SESSION['qimport_tmp_path']);
    }
    unset($_SESSION['qimport_preview'], $_SESSION['qimport_tmp_path']);

    logActivity((int) $_SESSION['user_id'], 'import_questions', "Import $imported soal ke quiz: {$quiz['title']}");
    setFlash('success', "$imported soal berhasil diimpor.");
    redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
}

if ($step === 'review') {
    $previewRows = $_SESSION['qimport_preview'] ?? [];
}
$validCount = count(array_filter($previewRows, fn($r) => empty($r['errors'])));
$errorCount = count($previewRows) - $validCount;

$pageTitle = 'Import Soal — ' . $quiz['title'];
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
        <li class="breadcrumb-item"><a href="quiz_questions.php?quiz_id=<?= $quizId ?>"><?= e($quiz['title']) ?></a></li>
        <li class="breadcrumb-item active">Import Soal</li>
      </ol>
    </nav>
    <h4 class="fw-bold mb-3">Import Bank Soal dari Excel/CSV</h4>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($step === 'review' && !empty($previewRows)): ?>
      <div class="alert alert-info">
        Ditemukan <strong><?= count($previewRows) ?></strong> baris soal.
        <span class="text-success">✓ <?= $validCount ?> valid</span> &middot;
        <span class="text-danger">✗ <?= $errorCount ?> error</span>
      </div>
      <div class="card mb-3">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Pertanyaan</th><th>Tipe</th><th>Skor</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($previewRows as $r): ?>
                <tr class="<?= empty($r['errors']) ? '' : 'table-danger' ?>">
                  <td class="small"><?= e(mb_strimwidth($r['question_text'], 0, 60, '...')) ?></td>
                  <td class="small"><?= e($questionTypeLabel[$r['question_type']] ?? $r['question_type']) ?></td>
                  <td class="small"><?= (int) $r['score'] ?></td>
                  <td class="small">
                    <?php if (empty($r['errors'])): ?>
                      <span class="text-success"><i class="bi bi-check-circle"></i> Valid</span>
                    <?php else: ?>
                      <span class="text-danger"><i class="bi bi-x-circle"></i> <?= e(implode(', ', $r['errors'])) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="confirm">
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
        <button type="submit" class="btn btn-success" <?= $validCount === 0 ? 'disabled' : '' ?>>
          <i class="bi bi-check-lg me-1"></i>Konfirmasi Import (<?= $validCount ?> soal valid)
        </button>
        <a href="quiz_questions_import.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-secondary">Batal / Upload Ulang</a>
      </form>

    <?php else: ?>
      <div class="card">
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="preview">
            <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
            <div class="mb-3">
              <label class="form-label">Pilih File CSV Bank Soal</label>
              <input type="file" name="file" class="form-control" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload &amp; Pratinjau</button>
            <a href="<?= BASE_URL ?>/assets/template_bank_soal.csv" class="btn btn-outline-secondary" download><i class="bi bi-download me-1"></i>Download Template</a>
          </form>
        </div>
      </div>
      <div class="card mt-3">
        <div class="card-header bg-white fw-semibold small">Format Kolom CSV</div>
        <div class="card-body small">
          <table class="table table-sm mb-2">
            <thead><tr><th>Kolom</th><th>Keterangan</th></tr></thead>
            <tbody>
              <tr><td><code>question_text</code></td><td>Teks pertanyaan (wajib)</td></tr>
              <tr><td><code>question_type</code></td><td><code>multiple_choice</code>, <code>multiple_answer</code>, <code>true_false</code>, atau <code>essay</code></td></tr>
              <tr><td><code>score</code></td><td>Skor soal, angka (default 10 jika kosong)</td></tr>
              <tr><td><code>option_a</code> s/d <code>option_d</code></td><td>Pilihan jawaban (untuk multiple_choice/multiple_answer, boleh kosong untuk true_false/essay)</td></tr>
              <tr><td><code>correct</code></td><td>Untuk pilihan ganda: huruf jawaban benar, misal <code>a</code>. Untuk multiple answer: pisah koma, misal <code>a,c</code>. Untuk benar/salah: isi <code>true</code> atau <code>false</code>.</td></tr>
            </tbody>
          </table>
          <p class="text-muted mb-0">Jika file Anda berformat .xlsx, buka di Excel/Google Sheets lalu <strong>File → Save As → CSV</strong> terlebih dahulu.</p>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
