<?php
require_once __DIR__ . '/../config/config.php';
requireRole('peserta');

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$assignmentId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.*, ed.day_number, ed.event_id, e.title AS event_title
     FROM assignments a
     JOIN event_days ed ON ed.id = a.event_day_id
     JOIN events e ON e.id = ed.event_id
     WHERE a.id = ? AND a.status != 'draft'"
);
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    setFlash('danger', 'Tugas tidak ditemukan.');
    redirect('peserta/events.php');
}

if (!isApprovedParticipant($pdo, $userId, (int) $assignment['event_id'])) {
    setFlash('danger', 'Anda belum terdaftar atau pendaftaran Anda belum disetujui admin.');
    redirect('peserta/events.php');
}

$now = new DateTime();
$deadline = $assignment['deadline'] ? new DateTime($assignment['deadline']) : null;
$isPastDeadline = $deadline && $now > $deadline;
$isClosed = $assignment['status'] === 'closed';
$canSubmit = !$isClosed && (!$isPastDeadline || $assignment['allow_late']);

// Cek dukungan kolom submission_link (migrasi v5) tanpa fatal error jika belum dimigrasi
$supportsLink = true;
try {
    $pdo->query('SELECT submission_link FROM assignment_submissions LIMIT 1');
} catch (PDOException $e) {
    $supportsLink = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    verifyCsrf();

    if (!$canSubmit) {
        setFlash('danger', 'Pengumpulan tugas ini sudah ditutup.');
        redirect('peserta/assignment.php?id=' . $assignmentId);
    }

    $answerText = trim($_POST['answer_text'] ?? '');
    $submissionLink = trim($_POST['submission_link'] ?? '');
    $filePath = null;

    if ($submissionLink !== '' && !filter_var($submissionLink, FILTER_VALIDATE_URL)) {
        setFlash('danger', 'Link yang dimasukkan tidak valid. Pastikan diawali https:// atau http://');
        redirect('peserta/assignment.php?id=' . $assignmentId);
    }

    if (!empty($_FILES['file']['name'])) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_ASSIGNMENT_EXT, true)) {
            setFlash('danger', 'Format file tidak diizinkan. Gunakan: ' . implode(', ', ALLOWED_ASSIGNMENT_EXT));
            redirect('peserta/assignment.php?id=' . $assignmentId);
        }
        if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
            setFlash('danger', 'Ukuran file melebihi batas maksimum (5MB).');
            redirect('peserta/assignment.php?id=' . $assignmentId);
        }
        $filename = 'assignment_' . $assignmentId . '_' . $userId . '_' . time() . '.' . $ext;
        $dest = UPLOAD_PATH . '/assignments/' . $filename;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            $filePath = 'assignments/' . $filename;
        }
    }

    if ($answerText === '' && !$filePath && $submissionLink === '') {
        setFlash('danger', 'Isi jawaban, lampirkan file, atau masukkan link terlebih dahulu.');
        redirect('peserta/assignment.php?id=' . $assignmentId);
    }

    $isLate = $isPastDeadline ? 1 : 0;

    $find = $pdo->prepare('SELECT id, file_path FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?');
    $find->execute([$assignmentId, $userId]);
    $existing = $find->fetch();

    if (!$filePath && $existing) {
        $filePath = $existing['file_path']; // pertahankan file lama jika tidak upload ulang
    }

    try {
        if ($existing) {
            if ($supportsLink) {
                $upd = $pdo->prepare(
                    'UPDATE assignment_submissions SET answer_text=?, file_path=?, submission_link=?, is_late=?, submitted_at=NOW(), score=NULL, feedback=NULL, graded_at=NULL
                     WHERE id = ?'
                );
                $upd->execute([$answerText, $filePath, $submissionLink ?: null, $isLate, $existing['id']]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE assignment_submissions SET answer_text=?, file_path=?, is_late=?, submitted_at=NOW(), score=NULL, feedback=NULL, graded_at=NULL
                     WHERE id = ?'
                );
                $upd->execute([$answerText, $filePath, $isLate, $existing['id']]);
            }
        } else {
            if ($supportsLink) {
                $ins = $pdo->prepare(
                    'INSERT INTO assignment_submissions (assignment_id, user_id, answer_text, file_path, submission_link, is_late) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([$assignmentId, $userId, $answerText, $filePath, $submissionLink ?: null, $isLate]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO assignment_submissions (assignment_id, user_id, answer_text, file_path, is_late) VALUES (?, ?, ?, ?, ?)'
                );
                $ins->execute([$assignmentId, $userId, $answerText, $filePath, $isLate]);
            }
        }
    } catch (PDOException $e) {
        setFlash('danger', 'Terjadi kesalahan saat menyimpan pengumpulan. Silakan coba lagi.');
        redirect('peserta/assignment.php?id=' . $assignmentId);
    }

    updateParticipantProgress($pdo, $userId, (int) $assignment['event_id']);
    logActivity($userId, 'submit_assignment', "Mengumpulkan tugas: {$assignment['title']}");
    setFlash('success', 'Tugas berhasil dikumpulkan.');
    redirect('peserta/assignment.php?id=' . $assignmentId);
}

$subStmt = $pdo->prepare('SELECT * FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?');
$subStmt->execute([$assignmentId, $userId]);
$submission = $subStmt->fetch();

$pageTitle = $assignment['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_detail.php?id=<?= $assignment['event_id'] ?>"><?= e($assignment['event_title']) ?></a></li>
        <li class="breadcrumb-item active">Hari <?= (int) $assignment['day_number'] ?> — Tugas</li>
      </ol>
    </nav>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <h4 class="fw-bold"><?= e($assignment['title']) ?></h4>
        <p><?= nl2br(e($assignment['instructions'] ?: '-')) ?></p>
        <p class="small mb-0">
          <i class="bi bi-calendar-x"></i> Deadline: <?= $assignment['deadline'] ? date('d M Y H:i', strtotime($assignment['deadline'])) : '-' ?>
          <?php if ($isPastDeadline): ?>
            <span class="badge bg-danger ms-2">Deadline Terlewat</span>
            <?php if ($assignment['allow_late']): ?><span class="badge bg-warning">Pengumpulan Terlambat Diizinkan</span><?php endif; ?>
          <?php endif; ?>
        </p>
      </div>
    </div>

    <?php if ($submission): ?>
      <div class="card mb-3 border-success">
        <div class="card-header bg-success-subtle fw-semibold">Pengumpulan Anda</div>
        <div class="card-body">
          <p class="small text-muted mb-1">Dikumpulkan: <?= date('d M Y H:i', strtotime($submission['submitted_at'])) ?> <?= $submission['is_late'] ? '<span class="badge bg-warning">Terlambat</span>' : '' ?></p>
          <?php if ($submission['answer_text']): ?><p><?= nl2br(e($submission['answer_text'])) ?></p><?php endif; ?>
          <?php if ($submission['file_path']): ?><p class="mb-1"><a href="<?= UPLOAD_URL . '/' . e($submission['file_path']) ?>" target="_blank"><i class="bi bi-paperclip"></i> Lihat File Terkumpul</a></p><?php endif; ?>
          <?php if ($supportsLink && !empty($submission['submission_link'])): ?>
            <p class="mb-1"><a href="<?= e($submission['submission_link']) ?>" target="_blank"><i class="bi bi-link-45deg"></i> Buka Link yang Dikumpulkan</a></p>
          <?php endif; ?>
          <hr>
          <?php if ($submission['score'] !== null): ?>
            <p class="mb-1"><strong>Nilai:</strong> <?= (int) $submission['score'] ?></p>
            <?php if ($submission['feedback']): ?><p class="mb-0"><strong>Feedback:</strong> <?= nl2br(e($submission['feedback'])) ?></p><?php endif; ?>
          <?php else: ?>
            <p class="text-muted mb-0"><i class="bi bi-hourglass-split"></i> Belum dinilai oleh admin.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($canSubmit): ?>
      <div class="card">
        <div class="card-header bg-white fw-semibold"><?= $submission ? 'Kumpulkan Ulang / Perbarui Jawaban' : 'Kumpulkan Tugas' ?></div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="id" value="<?= $assignmentId ?>">
            <div class="mb-3">
              <label class="form-label">Jawaban (opsional jika melampirkan file/link)</label>
              <textarea name="answer_text" class="form-control" rows="4"><?= e($submission['answer_text'] ?? '') ?></textarea>
            </div>

            <?php if ($supportsLink): ?>
            <div class="mb-3">
              <label class="form-label"><i class="bi bi-link-45deg"></i> Link Tugas (opsional)</label>
              <input type="url" name="submission_link" class="form-control" placeholder="https://youtube.com/... atau https://drive.google.com/..." value="<?= e($submission['submission_link'] ?? '') ?>">
              <div class="form-text">
                Cocok untuk tugas berupa <strong>video pembelajaran</strong> atau file berukuran besar — cukup unggah ke YouTube/Google Drive/media lain, lalu tempel linknya di sini. Tidak wajib upload file PDF/dokumen jika Anda mengisi link.
              </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
              <label class="form-label">Lampirkan File (opsional)</label>
              <input type="file" name="file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.zip">
              <div class="form-text">Maks. 5MB. Format: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG, ZIP.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i><?= $submission ? 'Perbarui Pengumpulan' : 'Kumpulkan Tugas' ?></button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-secondary">Pengumpulan untuk tugas ini sudah ditutup.</div>
    <?php endif; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
