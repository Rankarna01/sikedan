<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$eventId = (int) ($_GET['id'] ?? 0);

// Ensure participant is enrolled
$stmt = $pdo->prepare(
    "SELECT e.*, c.name AS category_name, ep.progress, ep.status AS enroll_status
     FROM event_participants ep
     JOIN events e ON e.id = ep.event_id
     JOIN categories c ON c.id = e.category_id
     WHERE ep.user_id = ? AND ep.event_id = ?"
);
$stmt->execute([$userId, $eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan atau Anda belum terdaftar.');
    redirect('peserta/events.php');
}
if ($event['enroll_status'] !== 'approved') {
    setFlash('danger', $event['enroll_status'] === 'rejected'
        ? 'Pendaftaran Anda pada kegiatan ini tidak disetujui.'
        : 'Pendaftaran Anda masih menunggu persetujuan admin.');
    redirect('peserta/events.php');
}

$stmt = $pdo->prepare('SELECT * FROM event_days WHERE event_id = ? ORDER BY day_number ASC');
$stmt->execute([$eventId]);
$days = $stmt->fetchAll();

foreach ($days as &$day) {
    $matStmt = $pdo->prepare(
        "SELECT m.*, mp.is_completed FROM materials m
         LEFT JOIN material_progress mp ON mp.material_id = m.id AND mp.user_id = ?
         WHERE m.event_day_id = ? AND m.status = 'published' ORDER BY m.sort_order"
    );
    $matStmt->execute([$userId, $day['id']]);
    $day['materials'] = $matStmt->fetchAll();

    try {
        $vidStmt = $pdo->prepare(
            "SELECT v.*, vp.is_watched FROM videos v
             LEFT JOIN video_progress vp ON vp.video_id = v.id AND vp.user_id = ?
             WHERE v.event_day_id = ? ORDER BY v.sort_order"
        );
        $vidStmt->execute([$userId, $day['id']]);
        $day['videos'] = $vidStmt->fetchAll();
    } catch (PDOException $e) {
        // Tabel video_progress belum ada (migrasi v4 belum dijalankan); tampilkan video tanpa status ditonton.
        $vidStmt = $pdo->prepare('SELECT *, 0 AS is_watched FROM videos WHERE event_day_id = ? ORDER BY sort_order');
        $vidStmt->execute([$day['id']]);
        $day['videos'] = $vidStmt->fetchAll();
    }

    $quizStmt = $pdo->prepare(
        "SELECT q.*,
         (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?) AS attempt_count,
         (SELECT MAX(score) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?) AS best_score
         FROM quizzes q WHERE q.event_day_id = ? AND q.status = 'active' ORDER BY q.id"
    );
    $quizStmt->execute([$userId, $userId, $day['id']]);
    $day['quizzes'] = $quizStmt->fetchAll();

    $asgStmt = $pdo->prepare(
        "SELECT a.*,
         (SELECT id FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.user_id = ?) AS submission_id
         FROM assignments a WHERE a.event_day_id = ? AND a.status != 'draft' ORDER BY a.id"
    );
    $asgStmt->execute([$userId, $day['id']]);
    $day['assignments'] = $asgStmt->fetchAll();
}
unset($day);

$pageTitle = $event['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small"><li class="breadcrumb-item"><a href="events.php">Kegiatan Saya</a></li><li class="breadcrumb-item active"><?= e($event['title']) ?></li></ol>
    </nav>
    <div class="card mb-3">
      <div class="card-body">
        <h4 class="fw-bold"><?= e($event['title']) ?></h4>
        <p class="text-muted small mb-2">
          <i class="bi bi-tag"></i> <?= e($event['category_name']) ?> &middot;
          <i class="bi bi-calendar3"></i> <?= formatTanggal($event['start_date']) ?> &ndash; <?= formatTanggal($event['end_date']) ?>
        </p>
        <p><?= nl2br(e($event['description'] ?: '-')) ?></p>
        <div class="progress" style="height:10px;"><div class="progress-bar" style="width:<?= (int) $event['progress'] ?>%"></div></div>
        <p class="small text-muted mt-1 mb-0">Progress Anda: <?= (int) $event['progress'] ?>%</p>
        <a href="<?= BASE_URL ?>/evaluation.php?id=<?= $eventId ?>" class="btn btn-sm btn-outline-primary mt-3"><i class="bi bi-star me-1"></i>Isi Evaluasi Kegiatan</a>
      </div>
    </div>

    <div class="accordion" id="dayAccordion">
      <?php foreach ($days as $i => $day): ?>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#day<?= $day['id'] ?>">
              Hari <?= (int) $day['day_number'] ?> &mdash; <?= e($day['title']) ?>
            </button>
          </h2>
          <div id="day<?= $day['id'] ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#dayAccordion">
            <div class="accordion-body">

              <?php if (!empty($day['materials'])): ?>
                <h6 class="fw-semibold mt-2"><i class="bi bi-journal-text"></i> Materi</h6>
                <ul class="list-group mb-3">
                  <?php foreach ($day['materials'] as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <a href="material.php?id=<?= $m['id'] ?>"><?= e($m['title']) ?></a>
                      <?= $m['is_completed'] ? '<span class="badge bg-success">Selesai</span>' : '<span class="badge bg-secondary">Belum</span>' ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (!empty($day['videos'])): ?>
                <h6 class="fw-semibold"><i class="bi bi-camera-video"></i> Video</h6>
                <ul class="list-group mb-3">
                  <?php foreach ($day['videos'] as $v): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <a href="video.php?id=<?= $v['id'] ?>"><i class="bi bi-play-circle me-1"></i><?= e($v['title']) ?></a>
                      <?= $v['is_watched'] ? '<span class="badge bg-success">Sudah Ditonton</span>' : '<span class="badge bg-secondary">Belum</span>' ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (!empty($day['quizzes'])): ?>
                <h6 class="fw-semibold"><i class="bi bi-patch-question"></i> Quiz</h6>
                <ul class="list-group mb-3">
                  <?php foreach ($day['quizzes'] as $q): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <a href="quiz_take.php?id=<?= $q['id'] ?>"><?= e($q['title']) ?> <span class="text-muted small">(<?= (int) $q['duration_minutes'] ?> menit, passing <?= (int) $q['passing_grade'] ?>)</span></a>
                      <?php if ($q['attempt_count'] > 0): ?>
                        <span class="badge bg-<?= $q['best_score'] >= $q['passing_grade'] ? 'success' : 'warning' ?>">Skor terbaik: <?= (int) $q['best_score'] ?></span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Belum dikerjakan</span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (!empty($day['assignments'])): ?>
                <h6 class="fw-semibold"><i class="bi bi-file-earmark-text"></i> Tugas</h6>
                <ul class="list-group mb-2">
                  <?php foreach ($day['assignments'] as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <a href="assignment.php?id=<?= $a['id'] ?>"><?= e($a['title']) ?></a>
                      <?= $a['submission_id'] ? '<span class="badge bg-success">Sudah Dikumpulkan</span>' : '<span class="badge bg-warning">Belum Dikumpulkan</span>' ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (empty($day['materials']) && empty($day['videos']) && empty($day['quizzes']) && empty($day['assignments'])): ?>
                <p class="text-muted small mb-0">Belum ada konten yang tersedia pada hari ini.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
