<?php
require_once __DIR__ . '/config/config.php';

$pdo = getDBConnection();
$formId = (int) ($_GET['id'] ?? $_POST['form_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM research_forms WHERE id = ? AND status = 'active'");
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) {
    setFlash('danger', 'Form penelitian tidak ditemukan atau sudah tidak aktif.');
    redirect('index.php');
}

$qStmt = $pdo->prepare('SELECT * FROM research_questions WHERE form_id = ? ORDER BY sort_order ASC');
$qStmt->execute([$formId]);
$questions = $qStmt->fetchAll();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    foreach ($questions as $q) {
        if ($q['is_required']) {
            $field = 'q' . $q['id'];
            $val = $_POST[$field] ?? (isset($_POST[$field . '_arr']) ? $_POST[$field . '_arr'] : '');
            if (empty($val)) {
                $errors[] = 'Pertanyaan "' . $q['question_text'] . '" wajib diisi.';
            }
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $userId = isLoggedIn() ? (int) $_SESSION['user_id'] : null;
            $insResp = $pdo->prepare('INSERT INTO research_responses (form_id, user_id) VALUES (?, ?)');
            $insResp->execute([$formId, $userId]);
            $responseId = (int) $pdo->lastInsertId();

            $insAns = $pdo->prepare('INSERT INTO research_answers (response_id, question_id, answer_text) VALUES (?, ?, ?)');
            foreach ($questions as $q) {
                $field = 'q' . $q['id'];
                if ($q['question_type'] === 'checkbox' && isset($_POST[$field]) && is_array($_POST[$field])) {
                    $answerText = implode(', ', array_map('trim', $_POST[$field]));
                } else {
                    $answerText = trim($_POST[$field] ?? '');
                }
                $insAns->execute([$responseId, $q['id'], $answerText]);
            }

            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Terjadi kesalahan saat menyimpan jawaban. Silakan coba lagi.';
        }
    }
}

$pageTitle = $form['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width:700px;">
  <div class="card shadow-sm">
    <div class="card-body p-4 p-md-5">
      <h4 class="fw-bold"><?= e($form['title']) ?></h4>
      <?php if ($form['description']): ?><p class="text-muted"><?= nl2br(e($form['description'])) ?></p><?php endif; ?>
      <?php if ($form['instructions']): ?><div class="alert alert-secondary small"><?= nl2br(e($form['instructions'])) ?></div><?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success text-center py-4">
          <i class="bi bi-check-circle-fill fs-2 d-block mb-2"></i>
          <strong>Terima kasih!</strong> Jawaban Anda berhasil disimpan.
        </div>
      <?php else: ?>
        <?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="form_id" value="<?= $formId ?>">
          <?php foreach ($questions as $i => $q):
            $field = 'q' . $q['id'];
            $options = $q['options'] ? (json_decode($q['options'], true) ?: []) : [];
          ?>
            <div class="mb-4">
              <label class="form-label fw-semibold"><?= ($i + 1) ?>. <?= e($q['question_text']) ?> <?= $q['is_required'] ? '<span class="text-danger">*</span>' : '' ?></label>

              <?php if ($q['question_type'] === 'text'): ?>
                <input type="text" name="<?= $field ?>" class="form-control" <?= $q['is_required'] ? 'required' : '' ?>>

              <?php elseif ($q['question_type'] === 'long_text'): ?>
                <textarea name="<?= $field ?>" class="form-control" rows="3" <?= $q['is_required'] ? 'required' : '' ?>></textarea>

              <?php elseif ($q['question_type'] === 'radio' || $q['question_type'] === 'multiple_choice'): ?>
                <?php foreach ($options as $j => $opt): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="<?= $field ?>" id="<?= $field ?>_<?= $j ?>" value="<?= e($opt) ?>" <?= $q['is_required'] ? 'required' : '' ?>>
                    <label class="form-check-label" for="<?= $field ?>_<?= $j ?>"><?= e($opt) ?></label>
                  </div>
                <?php endforeach; ?>

              <?php elseif ($q['question_type'] === 'checkbox'): ?>
                <?php foreach ($options as $j => $opt): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="<?= $field ?>[]" id="<?= $field ?>_<?= $j ?>" value="<?= e($opt) ?>">
                    <label class="form-check-label" for="<?= $field ?>_<?= $j ?>"><?= e($opt) ?></label>
                  </div>
                <?php endforeach; ?>

              <?php elseif ($q['question_type'] === 'rating'): ?>
                <div class="d-flex gap-3">
                  <?php for ($r = 1; $r <= 5; $r++): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $field ?>" id="<?= $field ?>_r<?= $r ?>" value="<?= $r ?>" <?= $q['is_required'] ? 'required' : '' ?>>
                      <label class="form-check-label" for="<?= $field ?>_r<?= $r ?>"><?= $r ?></label>
                    </div>
                  <?php endfor; ?>
                </div>

              <?php elseif ($q['question_type'] === 'likert'): ?>
                <div class="d-flex justify-content-between flex-wrap gap-2">
                  <?php $likert = [1=>'Sangat Tidak Setuju',2=>'Tidak Setuju',3=>'Netral',4=>'Setuju',5=>'Sangat Setuju']; ?>
                  <?php foreach ($likert as $val => $label): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $field ?>" id="<?= $field ?>_l<?= $val ?>" value="<?= $val ?>" <?= $q['is_required'] ? 'required' : '' ?>>
                      <label class="form-check-label small" for="<?= $field ?>_l<?= $val ?>"><?= $val ?> - <?= $label ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-primary w-100">Kirim Jawaban</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
