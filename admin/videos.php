<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$dayId = (int) ($_GET['day_id'] ?? $_POST['day_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT ed.*, e.id AS event_id, e.title AS event_title
     FROM event_days ed JOIN events e ON e.id = ed.event_id WHERE ed.id = ?"
);
$stmt->execute([$dayId]);
$day = $stmt->fetch();

if (!$day) {
    setFlash('danger', 'Hari/pertemuan tidak ditemukan.');
    redirect('admin/events.php');
}

/** Ekstrak YouTube video ID dari berbagai format URL */
function extractYoutubeId(string $url): ?string
{
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** Ekstrak Vimeo video ID */
function extractVimeoId(string $url): ?string
{
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        return $m[1];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $weightPercent = max(0, min(100, (float) ($_POST['weight_percent'] ?? 0)));

    if ($title === '' || $videoUrl === '') {
        setFlash('danger', 'Judul dan URL video wajib diisi.');
    } elseif (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        setFlash('danger', 'URL video tidak valid.');
    } else {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM videos WHERE event_day_id = ?');
        $maxOrder->execute([$dayId]);
        $nextOrder = (int) $maxOrder->fetchColumn() + 1;

        $ins = $pdo->prepare('INSERT INTO videos (event_day_id, title, video_url, description, weight_percent, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([$dayId, $title, $videoUrl, $description, $weightPercent, $nextOrder]);
        logActivity((int) $_SESSION['user_id'], 'add_video', "Menambah video: $title");
        setFlash('success', 'Video berhasil ditambahkan.');
    }
    redirect('admin/videos.php?day_id=' . $dayId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM videos WHERE id = ? AND event_day_id = ?')->execute([$id, $dayId]);
    setFlash('success', 'Video berhasil dihapus.');
    redirect('admin/videos.php?day_id=' . $dayId);
}

$stmt = $pdo->prepare('SELECT * FROM videos WHERE event_day_id = ? ORDER BY sort_order ASC');
$stmt->execute([$dayId]);
$videos = $stmt->fetchAll();

$weightStmt = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(weight_percent),0) FROM materials WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM videos WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM quizzes WHERE event_day_id = ?) +
        (SELECT COALESCE(SUM(weight_percent),0) FROM assignments WHERE event_day_id = ?) AS total_weight"
);
$weightStmt->execute([$dayId, $dayId, $dayId, $dayId]);
$totalDayItemWeight = (float) $weightStmt->fetchColumn();

$pageTitle = 'Video — ' . $day['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $day['event_id'] ?>"><?= e($day['event_title']) ?></a></li>
        <li class="breadcrumb-item active">Hari <?= (int) $day['day_number'] ?> — Video</li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="fw-bold mb-1">Video: <?= e($day['title']) ?></h4>
        <span class="badge <?= abs($totalDayItemWeight - 100) < 0.01 ? 'bg-success' : ($totalDayItemWeight > 0 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
          Total bobot hari ini (materi+video+quiz+tugas): <?= $totalDayItemWeight ?>%
        </span>
      </div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVideoModal"><i class="bi bi-plus-lg me-1"></i>Tambah Video</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if (empty($videos)): ?>
        <p class="text-muted">Belum ada video pada hari ini.</p>
      <?php endif; ?>
      <?php foreach ($videos as $v):
        $ytId = extractYoutubeId($v['video_url']);
        $vimeoId = extractVimeoId($v['video_url']);
      ?>
        <div class="col-md-6">
          <div class="card h-100">
            <div class="ratio ratio-16x9">
              <?php if ($ytId): ?>
                <iframe src="https://www.youtube.com/embed/<?= e($ytId) ?>" title="<?= e($v['title']) ?>" allowfullscreen></iframe>
              <?php elseif ($vimeoId): ?>
                <iframe src="https://player.vimeo.com/video/<?= e($vimeoId) ?>" title="<?= e($v['title']) ?>" allowfullscreen></iframe>
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center bg-light">
                  <a href="<?= e($v['video_url']) ?>" target="_blank" class="text-decoration-none"><i class="bi bi-play-circle fs-1"></i><br>Buka Video</a>
                </div>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <h6 class="fw-bold mb-1"><?= e($v['title']) ?></h6>
              <p class="small text-muted mb-0"><?= e($v['description'] ?: '-') ?></p>
              <p class="small text-muted mb-0 mt-1"><i class="bi bi-percent"></i> Bobot progress: <?= (float) $v['weight_percent'] ?>%</p>
            </div>
            <div class="card-footer bg-white">
              <form method="post" onsubmit="return confirm('Hapus video ini?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="day_id" value="<?= $dayId ?>">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Hapus</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addVideoModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="day_id" value="<?= $dayId ?>">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Video</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Judul Video <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label">URL Video (YouTube/Vimeo) <span class="text-danger">*</span></label>
            <input type="url" name="video_url" class="form-control" required placeholder="https://www.youtube.com/watch?v=...">
          </div>
          <div class="mb-3"><label class="form-label">Deskripsi</label><textarea name="description" class="form-control" rows="3"></textarea></div>
          <div class="mb-3">
            <label class="form-label">Bobot Progress (% dari total hari ini)</label>
            <input type="number" name="weight_percent" min="0" max="100" step="0.5" class="form-control" value="0">
            <div class="form-text">Video ini akan dihitung sebesar sekian persen saat peserta menandainya sudah ditonton.</div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
