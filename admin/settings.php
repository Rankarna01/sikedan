<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();

$editableKeys = [
    'app_name' => 'Nama Aplikasi',
    'app_tagline' => 'Tagline Aplikasi',
    'lecturer_name' => 'Nama Dosen',
    'institution' => 'Institusi',
    'email' => 'Email Kontak',
    'primary_color' => 'Warna Utama (hex)',
    'hero_overlay_opacity' => 'Kegelapan Overlay Hero Banner (0.1 - 0.9)',
    'anthropic_api_key' => 'Anthropic API Key (untuk fitur Generate Soal AI)',
    'footer_text' => 'Teks Footer',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $upd = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    foreach (array_keys($editableKeys) as $key) {
        $value = trim($_POST[$key] ?? '');
        $upd->execute([$value, $key]);
    }

    // Optional logo upload
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg'], true) && $_FILES['logo']['size'] <= MAX_UPLOAD_SIZE) {
            $filename = 'logo_' . time() . '.' . $ext;
            $dest = UPLOAD_PATH . '/images/' . $filename;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $upd->execute(['images/' . $filename, 'logo']);
            }
        } else {
            setFlash('danger', 'Logo gagal diunggah: format atau ukuran tidak valid.');
            redirect('admin/settings.php');
        }
    }

    logActivity((int) $_SESSION['user_id'], 'update_settings', 'Memperbarui pengaturan aplikasi');
    setFlash('success', 'Pengaturan berhasil disimpan.');
    redirect('admin/settings.php');
}

$currentSettings = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

$pageTitle = 'Pengaturan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <h4 class="fw-bold mb-4">Pengaturan Aplikasi</h4>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="row g-3">
            <?php foreach ($editableKeys as $key => $label): ?>
              <div class="col-md-6">
                <label class="form-label"><?= e($label) ?></label>
                <?php if ($key === 'footer_text'): ?>
                  <textarea name="<?= $key ?>" class="form-control" rows="2"><?= e($currentSettings[$key] ?? '') ?></textarea>
                <?php elseif ($key === 'primary_color'): ?>
                  <input type="color" name="<?= $key ?>" class="form-control form-control-color" value="<?= e($currentSettings[$key] ?: '#2563EB') ?>">
                <?php elseif ($key === 'hero_overlay_opacity'): ?>
                  <input type="range" name="<?= $key ?>" min="0.1" max="0.9" step="0.05" class="form-range" value="<?= e($currentSettings[$key] ?: '0.5') ?>"
                    oninput="document.getElementById('opacityPreview').textContent = this.value">
                  <div class="form-text">Nilai saat ini: <span id="opacityPreview"><?= e($currentSettings[$key] ?: '0.5') ?></span> — semakin kecil semakin terang/transparan, semakin besar semakin gelap. Rekomendasi: 0.4–0.6.</div>
                <?php elseif ($key === 'anthropic_api_key'): ?>
                  <input type="password" name="<?= $key ?>" class="form-control" value="<?= e($currentSettings[$key] ?? '') ?>" placeholder="sk-ant-...">
                  <div class="form-text">Diperlukan untuk fitur <strong>Generate Soal dengan AI</strong> di modul Quiz. Dapatkan API key dari <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>. Kosongkan jika tidak ingin menggunakan fitur ini.</div>
                <?php else: ?>
                  <input type="text" name="<?= $key ?>" class="form-control" value="<?= e($currentSettings[$key] ?? '') ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <div class="col-md-6">
              <label class="form-label">Logo Aplikasi</label>
              <input type="file" name="logo" class="form-control" accept=".png,.jpg,.jpeg,.svg">
              <?php if (!empty($currentSettings['logo'])): ?>
                <div class="form-text">Logo saat ini: <img src="<?= UPLOAD_URL . '/' . e($currentSettings['logo']) ?>" style="height:24px;" alt="Logo"></div>
              <?php endif; ?>
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-4"><i class="bi bi-check-lg me-1"></i>Simpan Pengaturan</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header bg-white fw-semibold">Catatan</div>
      <div class="card-body small text-muted">
        <p class="mb-1">Perubahan pada nama aplikasi, warna, dan logo akan langsung berlaku di seluruh halaman (landing page, sertifikat, dsb) karena diambil dinamis dari tabel <code>settings</code> melalui fungsi <code>getSetting()</code>.</p>
        <p class="mb-0">Untuk mengubah warna sekunder (dark/background/success/warning/danger), edit langsung variabel CSS di <code>assets/css/style.css</code> sesuai kebutuhan.</p>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
