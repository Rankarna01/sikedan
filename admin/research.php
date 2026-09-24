<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;
    $status = $_POST['status'] ?? 'draft';
    $relatedEventId = (int) ($_POST['event_id'] ?? 0) ?: null;

    if ($title === '') {
        setFlash('danger', 'Judul penelitian wajib diisi.');
        redirect('admin/research.php' . ($eventId ? '?event_id=' . $eventId : ''));
    }

    $ins = $pdo->prepare(
        'INSERT INTO research_forms (event_id, title, description, instructions, start_date, end_date, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$relatedEventId, $title, $description, $instructions, $startDate, $endDate, $status, $_SESSION['user_id']]);
    $newId = (int) $pdo->lastInsertId();
    logActivity((int) $_SESSION['user_id'], 'add_research_form', "Membuat form penelitian: $title");
    setFlash('success', 'Form penelitian berhasil dibuat. Silakan tambahkan pertanyaan.');
    redirect('admin/research_questions.php?form_id=' . $newId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM research_forms WHERE id = ?')->execute([$id]);
    setFlash('success', 'Form penelitian berhasil dihapus.');
    redirect('admin/research.php' . ($eventId ? '?event_id=' . $eventId : ''));
}

$where = $eventId ? 'WHERE rf.event_id = ?' : '';
$params = $eventId ? [$eventId] : [];

$stmt = $pdo->prepare(
    "SELECT rf.*, e.title AS event_title,
     (SELECT COUNT(*) FROM research_questions rq WHERE rq.form_id = rf.id) AS question_count,
     (SELECT COUNT(*) FROM research_responses rr WHERE rr.form_id = rf.id) AS response_count
     FROM research_forms rf
     LEFT JOIN events e ON e.id = rf.event_id
     $where
     ORDER BY rf.created_at DESC"
);
$stmt->execute($params);
$forms = $stmt->fetchAll();

$events = $pdo->query("SELECT id, title FROM events ORDER BY start_date DESC")->fetchAll();

$statusLabel = ['draft' => 'Draft', 'active' => 'Aktif', 'closed' => 'Ditutup'];
$statusBadge = ['draft' => 'secondary', 'active' => 'success', 'closed' => 'danger'];

$pageTitle = 'Penelitian';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="fw-bold mb-0">Form Penelitian</h4>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFormModal"><i class="bi bi-plus-lg me-1"></i>Buat Form Penelitian</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if (empty($forms)): ?>
        <p class="text-muted">Belum ada form penelitian. Buat form untuk mulai mengumpulkan data penelitian tanpa Google Form.</p>
      <?php endif; ?>
      <?php foreach ($forms as $f): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0"><?= e($f['title']) ?></h6>
                <span class="badge bg-<?= $statusBadge[$f['status']] ?>"><?= $statusLabel[$f['status']] ?></span>
              </div>
              <p class="small text-muted mb-2"><?= e(mb_strimwidth($f['description'] ?? '', 0, 100, '...')) ?></p>
              <?php if ($f['event_title']): ?><p class="small mb-1"><i class="bi bi-link-45deg"></i> Terkait: <?= e($f['event_title']) ?></p><?php endif; ?>
              <p class="small mb-0"><i class="bi bi-list-check"></i> <?= (int) $f['question_count'] ?> pertanyaan &middot; <i class="bi bi-people"></i> <?= (int) $f['response_count'] ?> responden</p>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2">
              <a href="research_questions.php?form_id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-check"></i> Kelola Pertanyaan</a>
              <a href="research_responses.php?form_id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-bar-chart"></i> Lihat Jawaban</a>
              <button class="btn btn-sm btn-outline-secondary" onclick="copyResearchLink(<?= $f['id'] ?>)"><i class="bi bi-link-45deg"></i> Salin Link</button>
              <form method="post" onsubmit="return confirm('Hapus form penelitian ini beserta seluruh respondennya?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addFormModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Buat Form Penelitian</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Judul Penelitian <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Deskripsi</label><textarea name="description" class="form-control" rows="2"></textarea></div>
          <div class="mb-3"><label class="form-label">Instruksi Pengisian</label><textarea name="instructions" class="form-control" rows="2"></textarea></div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Kegiatan Terkait (opsional)</label>
              <select name="event_id" class="form-select">
                <option value="">-- Tidak terkait kegiatan --</option>
                <?php foreach ($events as $ev): ?><option value="<?= $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="draft">Draft</option><option value="active">Aktif</option><option value="closed">Ditutup</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Tanggal Mulai</label><input type="date" name="start_date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Tanggal Selesai</label><input type="date" name="end_date" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan &amp; Kelola Pertanyaan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
function copyResearchLink(id) {
  var link = '<?= BASE_URL ?>/research_fill.php?id=' + id;
  navigator.clipboard.writeText(link).then(function () { alert('Link form penelitian disalin:\n' + link); });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
