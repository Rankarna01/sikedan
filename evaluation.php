<?php
require_once __DIR__ . '/config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$eventId = (int) ($_GET['id'] ?? $_POST['event_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.* FROM event_participants ep JOIN events e ON e.id = ep.event_id
     WHERE ep.user_id = ? AND ep.event_id = ? AND ep.status = 'approved'"
);
$stmt->execute([$userId, $eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan atau Anda belum terdaftar.');
    redirect('peserta/events.php');
}

$check = $pdo->prepare('SELECT id FROM evaluations WHERE event_id = ? AND user_id = ?');
$check->execute([$eventId, $userId]);
$alreadyFilled = (bool) $check->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyFilled) {
    verifyCsrf();
    $materialRating = (int) ($_POST['material_rating'] ?? 0);
    $speakerRating = (int) ($_POST['speaker_rating'] ?? 0);
    $overallRating = (int) ($_POST['overall_rating'] ?? 0);
    $suggestion = trim($_POST['suggestion'] ?? '');

    $ins = $pdo->prepare(
        'INSERT INTO evaluations (event_id, user_id, material_rating, speaker_rating, overall_rating, suggestion)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$eventId, $userId, $materialRating, $speakerRating, $overallRating, $suggestion]);
    logActivity($userId, 'submit_evaluation', "Mengisi evaluasi kegiatan: {$event['title']}");
    setFlash('success', 'Terima kasih, evaluasi Anda telah tersimpan.');
    redirect('evaluation.php?id=' . $eventId);
}

$pageTitle = 'Evaluasi — ' . $event['title'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="card mx-auto" style="max-width:600px;">
      <div class="card-body p-4">
        <h5 class="fw-bold">Evaluasi Kegiatan</h5>
        <p class="text-muted small"><?= e($event['title']) ?></p>

        <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <?php if ($alreadyFilled): ?>
          <div class="alert alert-info">Anda sudah mengisi evaluasi untuk kegiatan ini. Terima kasih atas partisipasinya!</div>
        <?php else: ?>
          <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <?php
            $ratingFields = [
                'material_rating' => 'Bagaimana kualitas materi?',
                'speaker_rating' => 'Bagaimana kualitas narasumber?',
                'overall_rating' => 'Apakah kegiatan ini bermanfaat?',
            ];
            foreach ($ratingFields as $field => $label): ?>
              <div class="mb-3">
                <label class="form-label fw-semibold"><?= $label ?></label>
                <div class="d-flex gap-3">
                  <?php for ($r = 1; $r <= 5; $r++): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="<?= $field ?>" id="<?= $field ?>_<?= $r ?>" value="<?= $r ?>" required>
                      <label class="form-check-label" for="<?= $field ?>_<?= $r ?>"><?= $r ?></label>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="mb-3">
              <label class="form-label fw-semibold">Saran</label>
              <textarea name="suggestion" class="form-control" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary w-100">Kirim Evaluasi</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
