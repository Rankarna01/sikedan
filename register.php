<?php
require_once __DIR__ . '/config/config.php';

$pdo = getDBConnection();
$eventSlug = trim($_GET['event'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM events WHERE slug = ? AND status = 'active'");
$stmt->execute([$eventSlug]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Link pendaftaran tidak valid atau kegiatan tidak aktif.');
    redirect('index.php');
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $institution = trim($_POST['institution'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '') $errors[] = 'Nama lengkap wajib diisi.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
    if ($username === '') $errors[] = 'Username wajib diisi.';
    if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';

    if (empty($errors)) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
        $check->execute([$email, $username]);
        if ((int) $check->fetchColumn() > 0) {
            $errors[] = 'Email atau username sudah terdaftar. Jika sudah memiliki akun, silakan login lalu hubungi admin untuk didaftarkan ke kegiatan ini.';
        }
    }

    if (empty($errors)) {
        if ($event['quota'] > 0) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status != 'rejected'");
            $countStmt->execute([$event['id']]);
            if ((int) $countStmt->fetchColumn() >= $event['quota']) {
                $errors[] = 'Mohon maaf, kuota peserta untuk kegiatan ini sudah penuh.';
            }
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (role_id, full_name, email, phone, institution, username, password, status)
                 VALUES (2, ?, ?, ?, ?, ?, ?, "active")'
            );
            $ins->execute([$fullName, $email, $phone, $institution, $username, $hash]);
            $newUserId = (int) $pdo->lastInsertId();

            // Pendaftaran mandiri berstatus 'registered' (menunggu persetujuan admin)
            $enroll = $pdo->prepare("INSERT INTO event_participants (event_id, user_id, status) VALUES (?, ?, 'registered')");
            $enroll->execute([$event['id'], $newUserId]);

            $pdo->commit();
            logActivity($newUserId, 'self_register', "Mendaftar mandiri ke kegiatan: {$event['title']}");
            $success = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Terjadi kesalahan saat memproses pendaftaran. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Pendaftaran: ' . $event['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-page d-flex align-items-center justify-content-center min-vh-100 py-4">
  <div class="card auth-card shadow-sm" style="max-width:520px;">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <i class="bi bi-calendar-check text-primary" style="font-size:2.5rem;"></i>
        <h5 class="fw-bold mt-2 mb-0"><?= e($event['title']) ?></h5>
        <p class="text-muted small mb-0"><?= formatTanggal($event['start_date']) ?> &ndash; <?= formatTanggal($event['end_date']) ?></p>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success text-center">
          <i class="bi bi-check-circle-fill fs-3 d-block mb-2"></i>
          <strong>Pendaftaran berhasil.</strong><br>
          Pendaftaran Anda sudah kami terima dan <strong>menunggu persetujuan admin</strong>. Anda dapat login dengan username &amp; password yang baru dibuat; kegiatan akan terbuka setelah disetujui.
        </div>
        <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary w-100">Masuk Sekarang</a>
      <?php else: ?>
        <?php foreach ($errors as $err): ?>
          <div class="alert alert-danger py-2"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post">
          <?= csrfField() ?>
          <div class="mb-3"><label class="form-label">Nama Lengkap <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" required value="<?= e($_POST['full_name'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Institusi</label><input type="text" name="institution" class="form-control" value="<?= e($_POST['institution'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">No. HP</label><input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Username <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" required value="<?= e($_POST['username'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" name="password" class="form-control" required minlength="6"></div>
          <button type="submit" class="btn btn-primary w-100">Daftar Sekarang</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
