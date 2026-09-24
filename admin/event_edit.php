<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan.');
    redirect('admin/events.php');
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$errors = [];
$old = $event;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
        'id' => $id,
        'title' => trim($_POST['title'] ?? ''),
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'description' => trim($_POST['description'] ?? ''),
        'location' => trim($_POST['location'] ?? ''),
        'partner' => trim($_POST['partner'] ?? ''),
        'start_date' => $_POST['start_date'] ?? '',
        'end_date' => $_POST['end_date'] ?? '',
        'quota' => (int) ($_POST['quota'] ?? 0),
        'status' => $_POST['status'] ?? 'draft',
    ];

    if ($old['title'] === '') $errors[] = 'Judul kegiatan wajib diisi.';
    if ($old['category_id'] <= 0) $errors[] = 'Kategori wajib dipilih.';
    if ($old['start_date'] === '' || $old['end_date'] === '') $errors[] = 'Tanggal mulai dan selesai wajib diisi.';
    if ($old['start_date'] && $old['end_date'] && $old['start_date'] > $old['end_date']) {
        $errors[] = 'Tanggal mulai tidak boleh lebih besar dari tanggal selesai.';
    }

    if (empty($errors)) {
        $coverImage = $event['cover_image']; // keep existing by default
        if (!empty($_FILES['cover_image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['cover_image']['size'] <= MAX_UPLOAD_SIZE) {
                $filename = 'event_cover_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = UPLOAD_PATH . '/images/' . $filename;
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
                    if ($coverImage && file_exists(UPLOAD_PATH . '/' . $coverImage)) {
                        @unlink(UPLOAD_PATH . '/' . $coverImage);
                    }
                    $coverImage = 'images/' . $filename;
                }
            } else {
                $errors[] = 'Foto sampul harus berformat JPG/PNG/WEBP dan maksimal 5MB.';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'UPDATE events SET category_id=?, title=?, description=?, location=?, partner=?,
             start_date=?, end_date=?, quota=?, status=?, cover_image=?, cover_image_position=? WHERE id=?'
        );
        $stmt->execute([
            $old['category_id'], $old['title'], $old['description'], $old['location'], $old['partner'],
            $old['start_date'], $old['end_date'], $old['quota'], $old['status'], $coverImage,
            $_POST['cover_image_position'] ?? 'center', $id,
        ]);
        logActivity((int) $_SESSION['user_id'], 'update_event', "Mengubah kegiatan: {$old['title']}");
        setFlash('success', 'Kegiatan berhasil diperbarui.');
        redirect('admin/events.php');
    }
}

$pageTitle = 'Edit Kegiatan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="fw-bold mb-0">Edit Kegiatan</h4>
      <a href="events.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Judul Kegiatan <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required value="<?= e($old['title']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Kategori <span class="text-danger">*</span></label>
              <select name="category_id" class="form-select" required>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= (string) $old['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Foto Sampul (untuk hero banner landing page)</label>
              <?php if (!empty($old['cover_image'])): ?>
                <div class="mb-2"><img src="<?= UPLOAD_URL . '/' . e($old['cover_image']) ?>" style="height:80px;border-radius:8px;" alt="Sampul saat ini"></div>
              <?php endif; ?>
              <input type="file" name="cover_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
              <div class="form-text">Opsional. Biarkan kosong untuk mempertahankan foto sampul saat ini.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Posisi Bagian Foto yang Ditampilkan</label>
              <select name="cover_image_position" class="form-select">
                <?php foreach (['top'=>'Atas','center'=>'Tengah (default)','bottom'=>'Bawah','left'=>'Kiri','right'=>'Kanan'] as $posKey=>$posLabel): ?>
                  <option value="<?= $posKey ?>" <?= ($old['cover_image_position'] ?? 'center') === $posKey ? 'selected' : '' ?>><?= $posLabel ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Gunakan ini jika foto terpotong tidak pas.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Deskripsi</label>
              <textarea name="description" class="form-control" rows="4"><?= e($old['description']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lokasi</label>
              <input type="text" name="location" class="form-control" value="<?= e($old['location']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Mitra</label>
              <input type="text" name="partner" class="form-control" value="<?= e($old['partner']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Tanggal Mulai <span class="text-danger">*</span></label>
              <input type="date" name="start_date" class="form-control" required value="<?= e($old['start_date']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Tanggal Selesai <span class="text-danger">*</span></label>
              <input type="date" name="end_date" class="form-control" required value="<?= e($old['end_date']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Kuota Peserta</label>
              <input type="number" name="quota" min="0" class="form-control" value="<?= e((string) $old['quota']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['draft'=>'Draft','active'=>'Aktif','inactive'=>'Nonaktif','completed'=>'Selesai'] as $key=>$label): ?>
                  <option value="<?= $key ?>" <?= $old['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan Perubahan</button>
            <a href="event_dashboard.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Kelola Hari/Materi &rarr;</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
