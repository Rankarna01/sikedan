<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$errors = [];
$old = [
    'title' => '', 'category_id' => '', 'description' => '', 'location' => '',
    'partner' => '', 'start_date' => '', 'end_date' => '', 'quota' => '', 'status' => 'draft',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $old = [
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
    if (!in_array($old['status'], ['draft', 'active', 'inactive', 'completed'], true)) {
        $errors[] = 'Status tidak valid.';
    }

    // Build unique slug
    $baseSlug = slugify($old['title']);
    $slug = $baseSlug;

    if (empty($errors)) {
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE slug = ?');
        $i = 1;
        while (true) {
            $checkStmt->execute([$slug]);
            if ((int) $checkStmt->fetchColumn() === 0) break;
            $i++;
            $slug = $baseSlug . '-' . $i;
        }

        $coverImage = null;
        if (!empty($_FILES['cover_image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['cover_image']['size'] <= MAX_UPLOAD_SIZE) {
                $filename = 'event_cover_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = UPLOAD_PATH . '/images/' . $filename;
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
                    $coverImage = 'images/' . $filename;
                }
            } else {
                $errors[] = 'Foto sampul harus berformat JPG/PNG/WEBP dan maksimal 5MB.';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            'INSERT INTO events (category_id, title, slug, description, location, partner, start_date, end_date, quota, status, cover_image, cover_image_position, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $old['category_id'], $old['title'], $slug, $old['description'], $old['location'],
            $old['partner'], $old['start_date'], $old['end_date'], $old['quota'], $old['status'],
            $coverImage, $_POST['cover_image_position'] ?? 'center', $_SESSION['user_id'],
        ]);

        $newId = (int) $pdo->lastInsertId();
        logActivity((int) $_SESSION['user_id'], 'create_event', "Membuat kegiatan: {$old['title']}");
        setFlash('success', 'Kegiatan berhasil dibuat. Silakan tambahkan hari/pertemuan.');
        redirect('admin/event_dashboard.php?id=' . $newId);
    }
}

$pageTitle = 'Tambah Kegiatan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="fw-bold mb-0">Tambah Kegiatan</h4>
      <a href="events.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
    </div>

    <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= e($err) ?></div>
    <?php endforeach; ?>

    <div class="card">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
          <?= csrfField() ?>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Judul Kegiatan <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required value="<?= e($old['title']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Kategori <span class="text-danger">*</span></label>
              <select name="category_id" class="form-select" required>
                <option value="">-- Pilih Kategori --</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= (string) $old['category_id'] === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Foto Sampul (untuk hero banner landing page)</label>
              <input type="file" name="cover_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
              <div class="form-text">Opsional. JPG/PNG/WEBP, maks 5MB. Jika kosong, akan dipakai foto default.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Posisi Bagian Foto yang Ditampilkan</label>
              <select name="cover_image_position" class="form-select">
                <option value="top">Atas</option>
                <option value="center" selected>Tengah (default)</option>
                <option value="bottom">Bawah</option>
                <option value="left">Kiri</option>
                <option value="right">Kanan</option>
              </select>
              <div class="form-text">Gunakan ini jika foto terpotong tidak pas (misal bagian penting ada di tengah/bawah foto, bukan di atas).</div>
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
              <label class="form-label">Mitra (jika Pengabdian Masyarakat)</label>
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
                <option value="draft" <?= $old['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                <option value="completed" <?= $old['status'] === 'completed' ? 'selected' : '' ?>>Selesai</option>
              </select>
            </div>
          </div>
          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan Kegiatan</button>
            <a href="events.php" class="btn btn-outline-secondary">Batal</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
