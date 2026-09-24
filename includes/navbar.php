<?php
$appName = getSetting('app_name', 'SiKedan');
$pdo = getDBConnection();
$notifCount = 0;
$notifItems = [];
if (isLoggedIn()) {
    $notifStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $notifStmt->execute([$_SESSION['user_id']]);
    $notifCount = (int) $notifStmt->fetchColumn();

    $notifListStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 6');
    $notifListStmt->execute([$_SESSION['user_id']]);
    $notifItems = $notifListStmt->fetchAll();
}
?>
<nav class="navbar navbar-expand-lg app-navbar sticky-top">
  <div class="container-fluid">
    <button class="btn btn-link text-white d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar">
      <i class="bi bi-list fs-3"></i>
    </button>
    <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">
      <i class="bi bi-mortarboard-fill"></i> <?= e($appName) ?>
    </a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <div class="dropdown">
        <button class="btn btn-link text-white position-relative" type="button" data-bs-toggle="dropdown" title="Notifikasi">
          <i class="bi bi-bell fs-5"></i>
          <?php if ($notifCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
              <?= $notifCount > 9 ? '9+' : $notifCount ?>
            </span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end p-0" style="width:320px; max-height:400px; overflow-y:auto;">
          <div class="px-3 py-2 border-bottom fw-semibold small">Notifikasi</div>
          <?php if (empty($notifItems)): ?>
            <div class="px-3 py-4 text-center text-muted small"><i class="bi bi-bell-slash d-block fs-4 mb-1"></i>Belum ada notifikasi.</div>
          <?php else: ?>
            <?php foreach ($notifItems as $n): ?>
              <div class="px-3 py-2 border-bottom small <?= $n['is_read'] ? '' : 'bg-primary-subtle' ?>">
                <div class="fw-semibold"><?= e($n['title']) ?></div>
                <?php if ($n['message']): ?><div class="text-muted"><?= e($n['message']) ?></div><?php endif; ?>
                <div class="text-muted" style="font-size:.7rem;"><?= e(date('d M Y H:i', strtotime($n['created_at']))) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="dropdown">
        <button class="btn btn-link text-white text-decoration-none dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle fs-5"></i>
          <span class="d-none d-sm-inline"><?= e($_SESSION['full_name'] ?? '') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= BASE_URL ?>/<?= currentRole() === 'admin' ? 'admin' : 'peserta' ?>/profile.php"><i class="bi bi-person me-2"></i>Profil</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>
