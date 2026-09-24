<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$dayId = (int) ($_GET['day_id'] ?? $_POST['day_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT ed.*, e.id AS event_id, e.title AS event_title
     FROM event_days ed JOIN events e ON e.id = ed.event_id WHERE ed.id = ?"
);
$stmt->execute([$dayId]);
$day = $stmt->fetch();

if (!$day) {
    setFlash('danger', 'Hari/pertemuan tidak ditemukan.');
    redirect('admin/events.php');
}

// Add material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed, true)) {
            setFlash('danger', 'Format lampiran tidak diizinkan.');
            redirect('admin/materials.php?day_id=' . $dayId);
        }
        if ($_FILES['attachment']['size'] > MAX_UPLOAD_SIZE) {
            setFlash('danger', 'Ukuran lampiran melebihi batas maksimum.');
            redirect('admin/materials.php?day_id=' . $dayId);
        }
        $filename = 'material_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = UPLOAD_PATH . '/documents/' . $filename;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
            $attachmentPath = 'documents/' . $filename;
        }
    }

    if ($title === '') {
        setFlash('danger', 'Judul materi wajib diisi.');
    } else {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM materials WHERE event_day_id = ?');
        $maxOrder->execute([$dayId]);
        $nextOrder = (int) $maxOrder->fetchColumn() + 1;

        $ins = $pdo->prepare(
            'INSERT INTO materials (event_day_id, title, content, attachment, status, weight_percent, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$dayId, $title, $content, $attachmentPath, $status, $weightPercent, $nextOrder]);
        logActivity((int) $_SESSION['user_id'], 'add_material', "Menambah materi: $title");
        setFlash('success', 'Materi berhasil ditambahkan.');
    }
    redirect('admin/materials.php?day_id=' . $dayId);
}

// Edit material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '') {
        setFlash('danger', 'Judul materi wajib diisi.');
    } else {
        $upd = $pdo->prepare('UPDATE materials SET title=?, content=?, status=?, weight_percent=? WHERE id=? AND event_day_id=?');
        $upd->execute([$title, $content, $status, $weightPercent, $id, $dayId]);
        setFlash('success', 'Materi berhasil diperbarui.');
    }
    redirect('admin/materials.php?day_id=' . $dayId);
}

// Delete material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);

    $find = $pdo->prepare('SELECT attachment FROM materials WHERE id = ? AND event_day_id = ?');
    $find->execute([$id, $dayId]);
    $mat = $find->fetch();
    if ($mat && $mat['attachment'] && file_exists(UPLOAD_PATH . '/' . $mat['attachment'])) {
        @unlink(UPLOAD_PATH . '/' . $mat['attachment']);
    }

    $pdo->prepare('DELETE FROM materials WHERE id = ? AND event_day_id = ?')->execute([$id, $dayId]);
    setFlash('success', 'Materi berhasil dihapus.');
    redirect('admin/materials.php?day_id=' . $dayId);
}

$stmt = $pdo->prepare('SELECT * FROM materials WHERE event_day_id = ? ORDER BY sort_order ASC');
$stmt->execute([$dayId]);
$materials = $stmt->fetchAll();

// Total bobot gabungan materi + quiz + tugas pada hari ini (idealnya berjumlah 100%)
$weightStmt = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(weight_percent),0) FROM materials WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM videos WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM quizzes WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM assignments WHERE event_day_id = ?) AS total_weight"
);
$weightStmt->execute([$dayId, $dayId, $dayId, $dayId]);
$totalDayItemWeight = (float) $weightStmt->fetchColumn();

$statusLabel = ['draft' => 'Draft', 'published' => 'Terbit', 'inactive' => 'Nonaktif'];
$statusBadge = ['draft' => 'secondary', 'published' => 'success', 'inactive' => 'warning'];

$pageTitle = 'Materi — ' . $day['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $day['event_id'] ?>"><?= e($day['event_title']) ?></a></li>
        <li class="breadcrumb-item active">Hari <?= (int) $day['day_number'] ?> — Materi</li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="fw-bold mb-1">Materi: <?= e($day['title']) ?></h4>
        <span class="badge <?= abs($totalDayItemWeight - 100) < 0.01 ? 'bg-success' : ($totalDayItemWeight > 0 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
          Total bobot hari ini (materi+video+quiz+tugas): <?= $totalDayItemWeight ?>%
        </span>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMaterialModal"><i class="bi bi-plus-lg me-1"></i>Tambah Materi</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if (empty($materials)): ?>
        <p class="text-muted">Belum ada materi pada hari ini.</p>
      <?php endif; ?>
      <?php foreach ($materials as $m): ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0"><?= e($m['title']) ?></h6>
                <span class="badge bg-<?= $statusBadge[$m['status']] ?>"><?= $statusLabel[$m['status']] ?></span>
              </div>
              <p class="small text-muted mb-2"><?= e(mb_strimwidth(strip_tags($m['content'] ?? ''), 0, 140, '...')) ?></p>
              <?php if ($m['attachment']): ?>
                <a href="<?= UPLOAD_URL . '/' . e($m['attachment']) ?>" target="_blank" class="small"><i class="bi bi-paperclip"></i> Lihat Lampiran</a>
              <?php endif; ?>
              <p class="small text-muted mb-0 mt-1"><i class="bi bi-percent"></i> Bobot progress: <?= (float) $m['weight_percent'] ?>%</p>
            </div>
            <div class="card-footer bg-white d-flex gap-2">
              <button class="btn btn-sm btn-outline-primary edit-material-btn"
                data-id="<?= $m['id'] ?>" data-title="<?= e($m['title']) ?>"
                data-content="<?= e($m['content']) ?>" data-status="<?= $m['status'] ?>" data-weight="<?= (float) $m['weight_percent'] ?>"
                data-bs-toggle="modal" data-bs-target="#editMaterialModal">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus materi ini?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="day_id" value="<?= $dayId ?>">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addMaterialModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Materi</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Judul Materi <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label">Isi Materi</label>
            <textarea name="content" class="form-control" rows="6" placeholder="Tulis materi di sini. Mendukung heading, paragraf, list menggunakan format HTML sederhana."></textarea>
            <div class="form-text">Boleh menggunakan tag dasar: &lt;h3&gt;, &lt;p&gt;, &lt;b&gt;, &lt;i&gt;, &lt;ul&gt;/&lt;li&gt;, &lt;a&gt;.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Lampiran (opsional)</label>
            <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png">
            <div class="form-text">Maks. 5MB. Format: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Bobot Progress (% dari total hari ini)</label>
            <input type="number" name="weight_percent" min="0" max="100" step="0.5" class="form-control" value="0">
            <div class="form-text">Materi ini akan dihitung sebesar sekian persen saat peserta menandainya selesai. Total bobot materi+quiz+tugas pada hari yang sama sebaiknya berjumlah 100%.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="draft">Draft</option>
              <option value="published">Terbit</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editMaterialModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <input type="hidden" name="id" id="editMatId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Materi</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Judul Materi</label><input type="text" name="title" id="editMatTitle" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Isi Materi</label><textarea name="content" id="editMatContent" class="form-control" rows="6"></textarea></div>
          <div class="mb-3">
            <label class="form-label">Bobot Progress (% dari total hari ini)</label>
            <input type="number" name="weight_percent" id="editMatWeight" min="0" max="100" step="0.5" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="editMatStatus" class="form-select">
              <option value="draft">Draft</option>
              <option value="published">Terbit</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.edit-material-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('editMatId').value = this.getAttribute('data-id');
    document.getElementById('editMatTitle').value = this.getAttribute('data-title');
    document.getElementById('editMatContent').value = this.getAttribute('data-content');
    document.getElementById('editMatWeight').value = this.getAttribute('data-weight');
    document.getElementById('editMatStatus').value = this.getAttribute('data-status');
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
