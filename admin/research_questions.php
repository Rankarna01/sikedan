<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$formId = (int) ($_GET['form_id'] ?? $_POST['form_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM research_forms WHERE id = ?');
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) {
    setFlash('danger', 'Form penelitian tidak ditemukan.');
    redirect('admin/research.php');
}

$typeLabel = [
    'multiple_choice' => 'Pilihan Ganda', 'checkbox' => 'Checkbox', 'radio' => 'Radio',
    'text' => 'Teks Singkat', 'long_text' => 'Teks Panjang', 'rating' => 'Rating (1-5)', 'likert' => 'Skala Likert',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $questionText = trim($_POST['question_text'] ?? '');
    $type = $_POST['question_type'] ?? 'text';
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $optionsRaw = trim($_POST['options_text'] ?? '');
    $options = null;

    if (in_array($type, ['multiple_choice', 'checkbox', 'radio'], true) && $optionsRaw !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $optionsRaw)));
        $options = json_encode(array_values($lines));
    }

    if ($questionText === '' || !array_key_exists($type, $typeLabel)) {
        setFlash('danger', 'Pertanyaan dan tipe wajib diisi dengan benar.');
        redirect('admin/research_questions.php?form_id=' . $formId);
    }

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM research_questions WHERE form_id = ?');
    $maxOrder->execute([$formId]);
    $nextOrder = (int) $maxOrder->fetchColumn() + 1;

    $ins = $pdo->prepare('INSERT INTO research_questions (form_id, question_text, question_type, options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$formId, $questionText, $type, $options, $isRequired, $nextOrder]);
    setFlash('success', 'Pertanyaan berhasil ditambahkan.');
    redirect('admin/research_questions.php?form_id=' . $formId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $qid = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM research_questions WHERE id = ? AND form_id = ?')->execute([$qid, $formId]);
    setFlash('success', 'Pertanyaan berhasil dihapus.');
    redirect('admin/research_questions.php?form_id=' . $formId);
}

$stmt = $pdo->prepare('SELECT * FROM research_questions WHERE form_id = ? ORDER BY sort_order ASC');
$stmt->execute([$formId]);
$questions = $stmt->fetchAll();

$pageTitle = 'Pertanyaan — ' . $form['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="research.php">Penelitian</a></li><li class="breadcrumb-item active"><?= e($form['title']) ?></li></ol></nav>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="fw-bold mb-0">Pertanyaan: <?= e($form['title']) ?></h4>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQModal"><i class="bi bi-plus-lg me-1"></i>Tambah Pertanyaan</button>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if (empty($questions)): ?>
      <div class="card"><div class="card-body text-center text-muted py-5">Belum ada pertanyaan.</div></div>
    <?php endif; ?>

    <?php foreach ($questions as $i => $q): ?>
      <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-start">
          <div>
            <span class="badge bg-primary-subtle text-primary mb-2"><?= $typeLabel[$q['question_type']] ?></span>
            <?php if ($q['is_required']): ?><span class="badge bg-danger-subtle text-danger mb-2">Wajib</span><?php endif; ?>
            <p class="fw-semibold mb-1"><?= ($i + 1) ?>. <?= e($q['question_text']) ?></p>
            <?php if ($q['options']): ?>
              <ul class="small text-muted mb-0">
                <?php foreach (json_decode($q['options'], true) ?: [] as $opt): ?><li><?= e($opt) ?></li><?php endforeach; ?>
              </ul>
            <?php elseif ($q['question_type'] === 'likert'): ?>
              <p class="small text-muted mb-0">Skala 1 (Sangat Tidak Setuju) &ndash; 5 (Sangat Setuju)</p>
            <?php elseif ($q['question_type'] === 'rating'): ?>
              <p class="small text-muted mb-0">Rating bintang 1&ndash;5</p>
            <?php endif; ?>
          </div>
          <form method="post" onsubmit="return confirm('Hapus pertanyaan ini?');">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="form_id" value="<?= $formId ?>"><input type="hidden" name="id" value="<?= $q['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </main>
</div>

<!-- Add Question Modal -->
<div class="modal fade" id="addQModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="form_id" value="<?= $formId ?>">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Pertanyaan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Pertanyaan <span class="text-danger">*</span></label><textarea name="question_text" class="form-control" rows="2" required></textarea></div>
          <div class="row g-3 mb-3">
            <div class="col-md-8">
              <label class="form-label">Tipe Pertanyaan</label>
              <select name="question_type" id="rqType" class="form-select">
                <?php foreach ($typeLabel as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="is_required" id="rqRequired" checked><label class="form-check-label" for="rqRequired">Wajib diisi</label></div>
            </div>
          </div>
          <div id="rqOptionsWrap" class="mb-3">
            <label class="form-label">Daftar Pilihan (satu per baris)</label>
            <textarea name="options_text" class="form-control" rows="4" placeholder="Sangat Tidak Setuju&#10;Tidak Setuju&#10;Netral&#10;Setuju&#10;Sangat Setuju"></textarea>
            <div class="form-text">Hanya berlaku untuk tipe Pilihan Ganda, Checkbox, dan Radio.</div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('rqType').addEventListener('change', function () {
  var needsOptions = ['multiple_choice', 'checkbox', 'radio'].includes(this.value);
  document.getElementById('rqOptionsWrap').classList.toggle('d-none', !needsOptions);
});
document.getElementById('rqType').dispatchEvent(new Event('change'));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
