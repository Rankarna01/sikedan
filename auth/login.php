<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) {
    redirect(currentRole() === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identity === '' || $password === '') {
        $errors[] = 'Username/email dan password wajib diisi.';
    } else {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE (u.username = ? OR u.email = ?) LIMIT 1'
        );
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Username/email atau password salah.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'Akun Anda tidak aktif. Hubungi administrator.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int) $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role_name'];
            $_SESSION['username']  = $user['username'];

            logActivity((int) $user['id'], 'login', 'User berhasil login');

            redirect($user['role_name'] === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php');
        }
    }
}

$pageTitle = 'Masuk';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-page d-flex align-items-center justify-content-center min-vh-100">
  <div class="card auth-card shadow-sm">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <i class="bi bi-mortarboard-fill text-primary" style="font-size:2.5rem;"></i>
        <h4 class="fw-bold mt-2 mb-0"><?= e(getSetting('app_name', 'SiKedan')) ?></h4>
        <p class="text-muted small">Masuk untuk melanjutkan</p>
      </div>

      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i> <?= e($err) ?></div>
      <?php endforeach; ?>

      <form method="post" novalidate>
        <?= csrfField() ?>
        <div class="mb-3">
          <label class="form-label">Username atau Email</label>
          <input type="text" name="identity" class="form-control" required autofocus value="<?= e($_POST['identity'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="remember" id="remember">
          <label class="form-check-label small" for="remember">Ingat saya</label>
        </div>
        <button type="submit" class="btn btn-primary w-100">Masuk</button>
      </form>

      <p class="text-center small text-muted mt-4 mb-0">
        Lupa password? Hubungi administrator sistem.
      </p>
      <p class="text-center small mt-2 mb-0">
        <a href="<?= BASE_URL ?>">&larr; Kembali ke Beranda</a>
      </p>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
