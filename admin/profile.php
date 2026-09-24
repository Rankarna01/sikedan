<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    verifyCsrf();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $institution = trim($_POST['institution'] ?? '');
    $position = trim($_POST['position'] ?? '');

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('danger', 'Nama dan email wajib diisi dengan benar.');
    } else {
        $upd = $pdo->prepare('UPDATE users SET full_name=?, email=?, phone=?, institution=?, position=? WHERE id=?');
        $upd->execute([$fullName, $email, $phone, $institution, $position, $userId]);
        $_SESSION['full_name'] = $fullName;
        setFlash('success', 'Profil berhasil diperbarui.');
    }
    redirect('admin/profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verifyCsrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        setFlash('danger', 'Password saat ini salah.');
    } elseif (strlen($new) < 6) {
        setFlash('danger', 'Password baru minimal 6 karakter.');
    } elseif ($new !== $confirm) {
        setFlash('danger', 'Konfirmasi password baru tidak cocok.');
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $userId]);
        logActivity($userId, 'change_password', 'Mengubah password sendiri');
        setFlash('success', 'Password berhasil diubah.');
    }
    redirect('admin/profile.php');
}

$pageTitle = 'Profil Saya';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <h4 class="fw-bold mb-4">Profil Saya</h4>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header bg-white fw-semibold">Informasi Profil</div>
          <div class="card-body">
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_profile">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nama Lengkap</label><input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">No. HP</label><input type="text" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" value="<?= e($user['username']) ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Institusi</label><input type="text" name="institution" class="form-control" value="<?= e($user['institution'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Jabatan</label><input type="text" name="position" class="form-control" value="<?= e($user['position'] ?? '') ?>"></div>
              </div>
              <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1"></i>Simpan Profil</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header bg-white fw-semibold">Ubah Password</div>
          <div class="card-body">
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="change_password">
              <div class="mb-3"><label class="form-label">Password Saat Ini</label><input type="password" name="current_password" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">Password Baru</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
              <div class="mb-3"><label class="form-label">Konfirmasi Password Baru</label><input type="password" name="confirm_password" class="form-control" required minlength="6"></div>
              <button type="submit" class="btn btn-outline-primary"><i class="bi bi-shield-lock me-1"></i>Ubah Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
