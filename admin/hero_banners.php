<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();

// Cek apakah tabel hero_banners sudah ada (untuk database yang belum dimigrasi)
$tableExists = true;
try {
    $pdo->query('SELECT 1 FROM hero_banners LIMIT 1');
} catch (PDOException $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $pageTitle = 'Banner Hero — Migrasi Diperlukan';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/navbar.php';
    ?>
    <div class="app-layout">
      <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-content">
        <div class="card mx-auto" style="max-width:600px;">
          <div class="card-body p-4">
            <i class="bi bi-database-exclamation text-warning fs-1"></i>
            <h5 class="fw-bold mt-3">Migrasi Database Diperlukan</h5>
            <p class="text-muted">Fitur Banner Hero membutuhkan tabel <code>hero_banners</code> yang belum ada di database Anda.</p>
            <p>Jalankan <code>MIGRATION_hero_banners.sql</code> di phpMyAdmin terlebih dahulu.</p>
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-primary btn-sm">Kembali ke Dashboard</a>
          </div>
        </div>
      </main>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (empty($_FILES['image']['name'])) {
        setFlash('danger', 'Gambar banner wajib diunggah.');
        redirect('admin/hero_banners.php');
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        setFlash('danger', 'Format gambar harus JPG, PNG, atau WEBP.');
        redirect('admin/hero_banners.php');
    }
    if ($_FILES['image']['size'] > MAX_UPLOAD_SIZE) {
        setFlash('danger', 'Ukuran gambar maksimal 5MB.');
        redirect('admin/hero_banners.php');
    }

    $filename = 'hero_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = UPLOAD_PATH . '/images/' . $filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        setFlash('danger', 'Gagal mengunggah gambar. Silakan coba lagi.');
        redirect('admin/hero_banners.php');
    }

    $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM hero_banners')->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO hero_banners (image, title, subtitle, link_url, image_position, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(['images/' . $filename, $title, $subtitle, $linkUrl, $_POST['image_position'] ?? 'center', $status, $maxOrder + 1]);
    logActivity((int) $_SESSION['user_id'], 'add_hero_banner', 'Menambah banner hero landing page');
    setFlash('success', 'Banner berhasil ditambahkan.');
    redirect('admin/hero_banners.php');
}

// EDIT — fitur baru: ubah judul/subjudul/link/posisi/status, dan opsional ganti gambar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $imagePosition = $_POST['image_position'] ?? 'center';
    $status = $_POST['status'] ?? 'active';

    $find = $pdo->prepare('SELECT image FROM hero_banners WHERE id = ?');
    $find->execute([$id]);
    $existing = $find->fetch();

    if (!$existing) {
        setFlash('danger', 'Banner tidak ditemukan.');
        redirect('admin/hero_banners.php');
    }

    $imagePath = $existing['image']; // pertahankan gambar lama jika tidak diganti

    if (!empty($_FILES['image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            setFlash('danger', 'Format gambar harus JPG, PNG, atau WEBP.');
            redirect('admin/hero_banners.php');
        }
        if ($_FILES['image']['size'] > MAX_UPLOAD_SIZE) {
            setFlash('danger', 'Ukuran gambar maksimal 5MB.');
            redirect('admin/hero_banners.php');
        }
        $filename = 'hero_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = UPLOAD_PATH . '/images/' . $filename;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            if ($imagePath && file_exists(UPLOAD_PATH . '/' . $imagePath)) {
                @unlink(UPLOAD_PATH . '/' . $imagePath);
            }
            $imagePath = 'images/' . $filename;
        }
    }

    $upd = $pdo->prepare('UPDATE hero_banners SET image=?, title=?, subtitle=?, link_url=?, image_position=?, status=? WHERE id=?');
    $upd->execute([$imagePath, $title, $subtitle, $linkUrl, $imagePosition, $status, $id]);
    logActivity((int) $_SESSION['user_id'], 'edit_hero_banner', "Mengubah banner hero ID $id");
    setFlash('success', 'Banner berhasil diperbarui.');
    redirect('admin/hero_banners.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE hero_banners SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
    setFlash('success', 'Status banner berhasil diperbarui.');
    redirect('admin/hero_banners.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $find = $pdo->prepare('SELECT image FROM hero_banners WHERE id = ?');
    $find->execute([$id]);
    $banner = $find->fetch();
    if ($banner && file_exists(UPLOAD_PATH . '/' . $banner['image'])) {
        @unlink(UPLOAD_PATH . '/' . $banner['image']);
    }
    $pdo->prepare('DELETE FROM hero_banners WHERE id = ?')->execute([$id]);
    setFlash('success', 'Banner berhasil dihapus.');
    redirect('admin/hero_banners.php');
}

$banners = $pdo->query('SELECT * FROM hero_banners ORDER BY sort_order ASC')->fetchAll();

$pageTitle = 'Banner Hero Landing Page';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <div>
        <h4 class="fw-bold mb-0">Banner Hero Landing Page</h4>
        <p class="text-muted small mb-0">Kelola gambar slide yang tampil di banner utama halaman depan, terpisah dari foto sampul kegiatan.</p>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBannerModal"><i class="bi bi-plus-lg me-1"></i>Tambah Banner</button>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="alert alert-info small">
      <i class="bi bi-info-circle"></i> Prioritas tampilan hero di landing page: <strong>1) Banner mandiri aktif di halaman ini</strong> (jika ada) &rarr; 2) Foto sampul kegiatan aktif &rarr; 3) Foto default bawaan sistem.
      Atur kegelapan overlay banner di <a href="settings.php">Pengaturan</a>.
    </div>

    <div class="row g-3" id="bannerList">
      <?php if (empty($banners)): ?>
        <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-images fs-1 d-block mb-2"></i>Belum ada banner mandiri. Landing page akan memakai foto sampul kegiatan aktif atau foto default.</div></div></div>
      <?php endif; ?>
      <?php foreach ($banners as $b): ?>
        <div class="col-md-6 col-lg-4" data-id="<?= $b['id'] ?>">
          <div class="card h-100">
            <img src="<?= UPLOAD_URL . '/' . e($b['image']) ?>" class="card-img-top" style="height:160px;object-fit:cover;object-position:<?= e($b['image_position'] ?: 'center') ?>;" alt="<?= e($b['title']) ?>">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h6 class="fw-bold mb-0"><?= e($b['title'] ?: '(Tanpa judul)') ?></h6>
                <span class="badge bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $b['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span>
              </div>
              <p class="small text-muted mb-0"><?= e($b['subtitle'] ?: '-') ?></p>
            </div>
            <div class="card-footer bg-white d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-sm btn-outline-primary edit-banner-btn"
                data-banner='<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                data-bs-toggle="modal" data-bs-target="#editBannerModal">
                <i class="bi bi-pencil"></i> Edit
              </button>
              <form method="post" class="d-inline">
                <?= csrfField() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on"></i> <?= $b['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus banner ini?');">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
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
<div class="modal fade" id="addBannerModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Banner Hero</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Gambar Banner <span class="text-danger">*</span></label>
            <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
            <div class="form-text">JPG/PNG/WEBP, maks 5MB. Disarankan ukuran landscape lebar (misal 1600×600px).</div>
          </div>
          <div class="mb-3"><label class="form-label">Judul (opsional)</label><input type="text" name="title" class="form-control" placeholder="Contoh: Selamat Datang di SiKedan"></div>
          <div class="mb-3"><label class="form-label">Subjudul (opsional)</label><input type="text" name="subtitle" class="form-control" placeholder="Kalimat pendukung singkat"></div>
          <div class="mb-3"><label class="form-label">Link Tujuan (opsional)</label><input type="text" name="link_url" class="form-control" placeholder="#kegiatan atau URL lengkap"></div>
          <div class="mb-3">
            <label class="form-label">Posisi Bagian Foto yang Ditampilkan</label>
            <select name="image_position" class="form-select">
              <option value="top">Atas</option>
              <option value="center" selected>Tengah (default)</option>
              <option value="bottom">Bawah</option>
              <option value="left">Kiri</option>
              <option value="right">Kanan</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal (BARU) -->
<div class="modal fade" id="editBannerModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data" id="editBannerForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editBannerId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Banner Hero</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <img id="editBannerPreview" src="" style="max-height:120px;border-radius:8px;" class="mb-2 d-block" alt="Preview">
            <label class="form-label">Ganti Gambar (opsional)</label>
            <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">Biarkan kosong untuk mempertahankan gambar saat ini.</div>
          </div>
          <div class="mb-3"><label class="form-label">Judul</label><input type="text" name="title" id="editBannerTitle" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Subjudul</label><input type="text" name="subtitle" id="editBannerSubtitle" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Link Tujuan</label><input type="text" name="link_url" id="editBannerLink" class="form-control"></div>
          <div class="mb-3">
            <label class="form-label">Posisi Bagian Foto yang Ditampilkan</label>
            <select name="image_position" id="editBannerPosition" class="form-select">
              <option value="top">Atas</option>
              <option value="center">Tengah (default)</option>
              <option value="bottom">Bawah</option>
              <option value="left">Kiri</option>
              <option value="right">Kanan</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="editBannerStatus" class="form-select"><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan Perubahan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('.edit-banner-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var b = JSON.parse(this.getAttribute('data-banner'));
    document.getElementById('editBannerId').value = b.id;
    document.getElementById('editBannerTitle').value = b.title || '';
    document.getElementById('editBannerSubtitle').value = b.subtitle || '';
    document.getElementById('editBannerLink').value = b.link_url || '';
    document.getElementById('editBannerPosition').value = b.image_position || 'center';
    document.getElementById('editBannerStatus').value = b.status;
    document.getElementById('editBannerPreview').src = '<?= UPLOAD_URL ?>/' + b.image;
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
