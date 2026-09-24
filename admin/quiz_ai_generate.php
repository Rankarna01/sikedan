<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$quizId = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT qz.*, ed.day_number, ed.id AS day_id, e.id AS event_id, e.title AS event_title
     FROM quizzes qz JOIN event_days ed ON ed.id = qz.event_day_id JOIN events e ON e.id = ed.event_id
     WHERE qz.id = ?"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    setFlash('danger', 'Quiz tidak ditemukan.');
    redirect('admin/events.php');
}

$apiKey = getSetting('anthropic_api_key', '');
$errors = [];
$generatedQuestions = [];

/**
 * Panggil Anthropic API untuk menghasilkan soal quiz dari teks materi.
 * Mengembalikan array soal terstruktur, atau melempar Exception jika gagal.
 */
function generateQuestionsFromMaterial(string $apiKey, string $materialText, int $questionCount, string $questionType): array
{
    $typeInstruction = match ($questionType) {
        'multiple_choice' => 'semuanya bertipe pilihan ganda (multiple_choice) dengan tepat 4 opsi dan hanya 1 jawaban benar',
        'multiple_answer' => 'semuanya bertipe multiple_answer dengan tepat 4 opsi dan 2 atau lebih jawaban benar',
        'true_false' => 'semuanya bertipe true_false (benar/salah)',
        'essay' => 'semuanya bertipe essay (soal uraian tanpa pilihan jawaban)',
        default => 'campuran antara multiple_choice, multiple_answer, dan true_false',
    };

    $systemPrompt = <<<PROMPT
Anda adalah asisten pembuat soal quiz untuk platform LMS. Berdasarkan materi yang diberikan pengguna,
buatkan {$questionCount} soal quiz dalam Bahasa Indonesia, {$typeInstruction}.

Balas HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan tambahan) dalam format persis berikut:
{
  "questions": [
    {
      "question_text": "teks pertanyaan",
      "question_type": "multiple_choice|multiple_answer|true_false|essay",
      "score": 10,
      "options": ["opsi A", "opsi B", "opsi C", "opsi D"],
      "correct_indexes": [0]
    }
  ]
}

Ketentuan:
- Untuk question_type "essay", field "options" dan "correct_indexes" cukup array kosong [].
- Untuk "true_false", "options" harus ["Benar", "Salah"] dan "correct_indexes" berisi index yang benar (0 untuk Benar, 1 untuk Salah).
- "correct_indexes" berisi index (mulai dari 0) dari opsi yang benar pada array "options".
- Soal harus relevan langsung dengan isi materi yang diberikan, bukan pengetahuan umum di luar materi.
- Jangan mengarang materi baru. Hanya berdasarkan teks yang diberikan.
PROMPT;

    $payload = json_encode([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 4000,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => "Materi:\n\n" . $materialText],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Gagal menghubungi Anthropic API: ' . $curlError);
    }
    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $msg = $errBody['error']['message'] ?? "HTTP $httpCode";
        throw new Exception('Anthropic API menolak permintaan: ' . $msg);
    }

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    // Bersihkan jika model membungkus JSON dengan markdown fences
    $text = trim(preg_replace('/^```json|```$/m', '', trim($text)));

    $parsed = json_decode($text, true);
    if (!is_array($parsed) || empty($parsed['questions'])) {
        throw new Exception('Respons AI tidak dalam format yang diharapkan. Silakan coba lagi.');
    }

    return $parsed['questions'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verifyCsrf();

    if (empty($apiKey)) {
        $errors[] = 'Anthropic API Key belum diatur. Silakan atur di Admin → Pengaturan terlebih dahulu.';
    } else {
        $materialText = trim($_POST['material_text'] ?? '');
        $questionCount = max(1, min(20, (int) ($_POST['question_count'] ?? 5)));
        $questionType = $_POST['question_type'] ?? 'mixed';

        if (mb_strlen($materialText) < 50) {
            $errors[] = 'Teks materi terlalu pendek. Tempelkan materi yang lebih lengkap (minimal beberapa paragraf) agar AI dapat membuat soal yang relevan.';
        } else {
            try {
                $generatedQuestions = generateQuestionsFromMaterial($apiKey, $materialText, $questionCount, $questionType);
                $_SESSION['ai_generated_questions'] = $generatedQuestions;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Simpan soal yang sudah di-review admin ke database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_generated') {
    verifyCsrf();
    $selectedQuestions = $_POST['save_questions'] ?? [];
    $allGenerated = $_SESSION['ai_generated_questions'] ?? [];

    if (empty($selectedQuestions) || empty($allGenerated)) {
        setFlash('danger', 'Tidak ada soal yang dipilih untuk disimpan.');
        redirect('admin/quiz_ai_generate.php?quiz_id=' . $quizId);
    }

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE quiz_id = ?');
    $maxOrder->execute([$quizId]);
    $nextOrder = (int) $maxOrder->fetchColumn() + 1;

    $pdo->beginTransaction();
    try {
        $insQ = $pdo->prepare('INSERT INTO questions (quiz_id, question_text, question_type, score, sort_order) VALUES (?, ?, ?, ?, ?)');
        $insOpt = $pdo->prepare('INSERT INTO question_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)');
        $saved = 0;

        foreach ($selectedQuestions as $idx) {
            $q = $allGenerated[(int) $idx] ?? null;
            if (!$q) continue;

            $insQ->execute([$quizId, $q['question_text'], $q['question_type'], (int) ($q['score'] ?? 10), $nextOrder]);
            $questionId = (int) $pdo->lastInsertId();
            $nextOrder++;
            $saved++;

            if (in_array($q['question_type'], ['multiple_choice', 'multiple_answer', 'true_false'], true)) {
                foreach ($q['options'] ?? [] as $optIdx => $optText) {
                    $isCorrect = in_array($optIdx, $q['correct_indexes'] ?? [], true) ? 1 : 0;
                    $insOpt->execute([$questionId, $optText, $isCorrect, $optIdx]);
                }
            }
        }
        $pdo->commit();
        unset($_SESSION['ai_generated_questions']);
        logActivity((int) $_SESSION['user_id'], 'ai_generate_questions', "Menyimpan $saved soal hasil generate AI ke quiz: {$quiz['title']}");
        setFlash('success', "$saved soal berhasil disimpan.");
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Gagal menyimpan soal: ' . $e->getMessage());
    }
    redirect('admin/quiz_questions.php?quiz_id=' . $quizId);
}

$questionTypeLabel = ['multiple_choice' => 'Pilihan Ganda', 'multiple_answer' => 'Multiple Answer', 'true_false' => 'Benar/Salah', 'essay' => 'Essay'];

$pageTitle = 'Generate Soal AI — ' . $quiz['title'];
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
        <li class="breadcrumb-item active">Generate Soal AI</li>
      </ol>
    </nav>
    <h4 class="fw-bold mb-3"><i class="bi bi-stars text-success"></i> Generate Soal dengan AI</h4>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

    <?php if (empty($apiKey)): ?>
      <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> Fitur ini membutuhkan Anthropic API Key.
        Silakan atur di <a href="settings.php">Admin → Pengaturan</a> terlebih dahulu.
      </div>
    <?php endif; ?>

    <?php if (empty($generatedQuestions)): ?>
      <div class="card mb-3">
        <div class="card-body">
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
            <div class="mb-3">
              <label class="form-label">Tempel Teks Materi <span class="text-danger">*</span></label>
              <textarea name="material_text" class="form-control" rows="10" placeholder="Salin-tempel isi materi (dari dokumen Word, PDF, PPT yang sudah dibuka, atau teks materi di sistem ini) di sini. AI akan membuat soal berdasarkan teks ini." required><?= e($_POST['material_text'] ?? '') ?></textarea>
              <div class="form-text">
                <i class="bi bi-info-circle"></i> Saat ini AI membaca dari <strong>teks yang ditempel</strong>, belum bisa membaca file PDF/PPT secara langsung.
                Untuk PDF/PPT: buka filenya, pilih semua teks (Ctrl+A), salin (Ctrl+C), lalu tempel di kotak ini.
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Jumlah Soal</label>
                <input type="number" name="question_count" min="1" max="20" value="5" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label">Tipe Soal</label>
                <select name="question_type" class="form-select">
                  <option value="mixed">Campuran</option>
                  <option value="multiple_choice">Pilihan Ganda</option>
                  <option value="multiple_answer">Multiple Answer</option>
                  <option value="true_false">Benar/Salah</option>
                  <option value="essay">Essay</option>
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-success mt-3" <?= empty($apiKey) ? 'disabled' : '' ?>><i class="bi bi-stars me-1"></i>Generate Soal</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-success"><i class="bi bi-check-circle"></i> AI berhasil menghasilkan <?= count($generatedQuestions) ?> soal. Review di bawah, centang yang ingin disimpan, lalu klik Simpan.</div>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_generated">
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

        <?php foreach ($generatedQuestions as $idx => $q): ?>
          <div class="card mb-3">
            <div class="card-body">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="save_questions[]" value="<?= $idx ?>" id="genQ<?= $idx ?>" checked>
                <label class="form-check-label fw-semibold" for="genQ<?= $idx ?>">
                  <span class="badge bg-secondary-subtle text-secondary"><?= $questionTypeLabel[$q['question_type']] ?? $q['question_type'] ?></span>
                  <?= e($q['question_text']) ?>
                </label>
              </div>
              <?php if (!empty($q['options'])): ?>
                <ul class="list-unstyled small ms-4 mb-0">
                  <?php foreach ($q['options'] as $oi => $opt): ?>
                    <li class="<?= in_array($oi, $q['correct_indexes'] ?? [], true) ? 'text-success fw-semibold' : '' ?>">
                      <i class="bi <?= in_array($oi, $q['correct_indexes'] ?? [], true) ? 'bi-check-circle-fill' : 'bi-circle' ?> me-1"></i><?= e($opt) ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php elseif ($q['question_type'] === 'essay'): ?>
                <p class="small text-muted ms-4 mb-0"><i class="bi bi-pencil-square"></i> Soal essay — dinilai manual.</p>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="d-flex gap-2 mb-4">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan Soal Terpilih</button>
          <a href="quiz_ai_generate.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-secondary">Generate Ulang</a>
        </div>
      </form>
    <?php endif; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
