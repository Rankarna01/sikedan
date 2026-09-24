<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$materialId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT m.*, ed.id AS day_id, ed.day_number, ed.event_id, e.title AS event_title
     FROM materials m
     JOIN event_days ed ON ed.id = m.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE m.id = ? AND m.status = 'published'"
);
$stmt->execute([$materialId]);
$material = $stmt->fetch();

if (!$material) {
    setFlash('danger', 'Materi tidak ditemukan.');
    redirect('peserta/events.php');
}

// Verify enrollment
if (!isApprovedParticipant($pdo, $userId, (int) $material['event_id'])) {
    setFlash('danger', 'Anda belum terdaftar atau pendaftaran Anda belum disetujui admin.');
    redirect('peserta/events.php');
}

/* updateParticipantProgress() tersedia global dari includes/functions.php */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_complete') {
    verifyCsrf();
    $stmt = $pdo->prepare(
        'INSERT INTO material_progress (material_id, user_id, is_completed, completed_at)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()'
    );
    $stmt->execute([$materialId, $userId]);
    updateParticipantProgress($pdo, $userId, (int) $material['event_id']);
    setFlash('success', 'Materi ditandai selesai.');
    redirect('peserta/material.php?id=' . $materialId);
}

$progStmt = $pdo->prepare('SELECT is_completed FROM material_progress WHERE material_id = ? AND user_id = ?');
$progStmt->execute([$materialId, $userId]);
$isCompleted = (bool) $progStmt->fetchColumn();

// Sidebar list of materials in the same day for prev/next navigation
$listStmt = $pdo->prepare("SELECT id, title FROM materials WHERE event_day_id = ? AND status='published' ORDER BY sort_order");
$listStmt->execute([$material['day_id']]);
$materialList = $listStmt->fetchAll();

$currentIndex = null;
foreach ($materialList as $idx => $m) {
    if ((int) $m['id'] === $materialId) { $currentIndex = $idx; break; }
}
$prevMaterial = $currentIndex !== null && $currentIndex > 0 ? $materialList[$currentIndex - 1] : null;
$nextMaterial = $currentIndex !== null && $currentIndex < count($materialList) - 1 ? $materialList[$currentIndex + 1] : null;

$pageTitle = $material['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="row g-3">
      <div class="col-lg-3">
        <div class="card">
          <div class="card-header bg-white fw-semibold small">Daftar Materi</div>
          <div class="list-group list-group-flush">
            <?php foreach ($materialList as $m): ?>
              <a href="material.php?id=<?= $m['id'] ?>" class="list-group-item list-group-item-action small <?= (int) $m['id'] === $materialId ? 'active' : '' ?>"><?= e($m['title']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-9">
        <nav aria-label="breadcrumb" class="mb-2">
          <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="event_detail.php?id=<?= $material['event_id'] ?>"><?= e($material['event_title']) ?></a></li>
            <li class="breadcrumb-item active">Hari <?= (int) $material['day_number'] ?></li>
          </ol>
        </nav>

        <?php if ($flash): ?>
          <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="card">
          <div class="card-body">
            <h4 class="fw-bold"><?= e($material['title']) ?></h4>
            <div class="my-3"><?= $material['content'] ?></div>
            <?php if ($material['attachment']): ?>
              <a href="<?= UPLOAD_URL . '/' . e($material['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-paperclip"></i> Lihat Lampiran</a>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top flex-wrap gap-2">
              <div>
                <?php if ($prevMaterial): ?>
                  <a href="material.php?id=<?= $prevMaterial['id'] ?>" class="btn btn-outline-secondary btn-sm">&larr; Sebelumnya</a>
                <?php endif; ?>
              </div>
              <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_complete">
                <input type="hidden" name="id" value="<?= $materialId ?>">
                <button type="submit" class="btn btn-sm <?= $isCompleted ? 'btn-success' : 'btn-primary' ?>" <?= $isCompleted ? 'disabled' : '' ?>>
                  <i class="bi bi-check2-circle"></i> <?= $isCompleted ? 'Materi Selesai' : 'Tandai Selesai' ?>
                </button>
              </form>
              <div>
                <?php if ($nextMaterial): ?>
                  <a href="material.php?id=<?= $nextMaterial['id'] ?>" class="btn btn-outline-secondary btn-sm">Berikutnya &rarr;</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
