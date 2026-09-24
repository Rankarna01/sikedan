<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$videoId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT v.*, ed.id AS day_id, ed.day_number, ed.event_id, e.title AS event_title
     FROM videos v
     JOIN event_days ed ON ed.id = v.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE v.id = ?"
);
$stmt->execute([$videoId]);
$video = $stmt->fetch();

if (!$video) {
    setFlash('danger', 'Video tidak ditemukan.');
    redirect('peserta/events.php');
}

if (!isApprovedParticipant($pdo, $userId, (int) $video['event_id'])) {
    setFlash('danger', 'Anda belum terdaftar atau pendaftaran Anda belum disetujui admin.');
    redirect('peserta/events.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_watched') {
    verifyCsrf();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO video_progress (video_id, user_id, is_watched, watched_at)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE is_watched = 1, watched_at = NOW()'
        );
        $stmt->execute([$videoId, $userId]);
        updateParticipantProgress($pdo, $userId, (int) $video['event_id']);
        setFlash('success', 'Video ditandai sudah ditonton.');
    } catch (PDOException $e) {
        setFlash('danger', 'Fitur ini membutuhkan migrasi database (MIGRATION_v4_video_weight.sql) yang belum dijalankan. Hubungi admin sistem.');
    }
    redirect('peserta/video.php?id=' . $videoId);
}

$isWatched = false;
try {
    $watchedStmt = $pdo->prepare('SELECT is_watched FROM video_progress WHERE video_id = ? AND user_id = ?');
    $watchedStmt->execute([$videoId, $userId]);
    $isWatched = (bool) $watchedStmt->fetchColumn();
} catch (PDOException $e) {
    // Tabel video_progress belum ada; tombol akan tetap tampil sebagai "belum ditonton"
}

/** Ekstrak YouTube video ID dari berbagai format URL */
function extractYoutubeId(string $url): ?string
{
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return $m[1];
    }
    return null;
}
function extractVimeoId(string $url): ?string
{
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        return $m[1];
    }
    return null;
}

$ytId = extractYoutubeId($video['video_url']);
$vimeoId = extractVimeoId($video['video_url']);

$listStmt = $pdo->prepare('SELECT id, title FROM videos WHERE event_day_id = ? ORDER BY sort_order');
$listStmt->execute([$video['day_id']]);
$videoList = $listStmt->fetchAll();

$pageTitle = $video['title'];
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
          <div class="card-header bg-white fw-semibold small">Daftar Video</div>
          <div class="list-group list-group-flush">
            <?php foreach ($videoList as $v): ?>
              <a href="video.php?id=<?= $v['id'] ?>" class="list-group-item list-group-item-action small <?= (int) $v['id'] === $videoId ? 'active' : '' ?>"><?= e($v['title']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-9">
        <nav aria-label="breadcrumb" class="mb-2">
          <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="event_detail.php?id=<?= $video['event_id'] ?>"><?= e($video['event_title']) ?></a></li>
            <li class="breadcrumb-item active">Hari <?= (int) $video['day_number'] ?></li>
          </ol>
        </nav>
        <div class="card">
          <div class="ratio ratio-16x9">
            <?php if ($ytId): ?>
              <iframe src="https://www.youtube.com/embed/<?= e($ytId) ?>" title="<?= e($video['title']) ?>" allowfullscreen></iframe>
            <?php elseif ($vimeoId): ?>
              <iframe src="https://player.vimeo.com/video/<?= e($vimeoId) ?>" title="<?= e($video['title']) ?>" allowfullscreen></iframe>
            <?php else: ?>
              <div class="d-flex align-items-center justify-content-center bg-light">
                <a href="<?= e($video['video_url']) ?>" target="_blank" class="text-decoration-none text-center">
                  <i class="bi bi-play-circle fs-1 d-block mb-2"></i>Buka Video di Tab Baru
                </a>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <h5 class="fw-bold"><?= e($video['title']) ?></h5>
            <?php if ($video['description']): ?><p class="text-muted"><?= nl2br(e($video['description'])) ?></p><?php endif; ?>

            <?php if ($flash): ?>
              <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> py-2"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
              <a href="event_detail.php?id=<?= $video['event_id'] ?>" class="btn btn-sm btn-outline-secondary">&larr; Kembali ke Kegiatan</a>
              <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_watched">
                <button type="submit" class="btn btn-sm <?= $isWatched ? 'btn-success' : 'btn-primary' ?>" <?= $isWatched ? 'disabled' : '' ?>>
                  <i class="bi bi-check2-circle"></i> <?= $isWatched ? 'Sudah Ditonton' : 'Tandai Sudah Ditonton' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
