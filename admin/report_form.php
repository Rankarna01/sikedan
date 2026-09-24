<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$report = null;

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM activity_reports WHERE id = ?');
    $st->execute([$id]);
    $report = $st->fetch();
    if (!$report) {
        setFlash('danger', 'Laporan tidak ditemukan.');
        redirect('admin/reports.php');
    }
}

$errors = [];
$data = [
    'title' => $report['title'] ?? '',
    'event_id' => $report['event_id'] ?? (int) ($_GET['event_id'] ?? 0),
    'report_date' => $report['report_date'] ?? date('Y-m-d'),
    'location' => $report['location'] ?? '',
    'participant_count' => $report['participant_count'] ?? '',
    'summary' => $report['summary'] ?? '',
    'content' => $report['content'] ?? '',
    'status' => $report['status'] ?? 'draft',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data['title'] = trim($_POST['title'] ?? '');
    $data['event_id'] = (int) ($_POST['event_id'] ?? 0);
    $data['report_date'] = $_POST['report_date'] ?? '';
    $data['location'] = trim($_POST['location'] ?? '');
    $data['participant_count'] = trim($_POST['participant_count'] ?? '');
    $data['summary'] = trim($_POST['summary'] ?? '');
    $data['content'] = trim($_POST['content'] ?? '');
    $data['status'] = ($_POST['status'] ?? 'draft') === 'final' ? 'final' : 'draft';

    if ($data['title'] === '') $errors[] = 'Judul laporan wajib diisi.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['report_date'])) $errors[] = 'Tanggal laporan tidak valid.';
    if ($data['participant_count'] !== '' && (!ctype_digit($data['participant_count']))) $errors[] = 'Jumlah peserta harus berupa angka.';
    if ($data['event_id'] > 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM events WHERE id = ?');
        $chk->execute([$data['event_id']]);
        if (!(int) $chk->fetchColumn()) $errors[] = 'Kegiatan yang dipilih tidak ditemukan.';
    }

    $newAttachment = null;
    if (!$errors && !empty($_FILES['attachment']['name'])) {
        $saved = saveUploadedFile($_FILES['attachment'], 'reports', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'], 'report');
        if (is_array($saved)) $errors[] = $saved['error']; else $newAttachment = $saved;
    }

    if (!$errors) {
        $eventId = $data['event_id'] > 0 ? $data['event_id'] : null;
        $count = $data['participant_count'] === '' ? null : (int) $data['participant_count'];
        $adminId = (int) $_SESSION['user_id'];

        if ($report) {
            $attachment = $report['attachment'];
            if ($newAttachment) {
                deleteUploadedFile($attachment);
                $attachment = $newAttachment;
            } elseif (!empty($_POST['remove_attachment'])) {
                deleteUploadedFile($attachment);
                $attachment = null;
            }
            $pdo->prepare('UPDATE activity_reports SET event_id=?, title=?, report_date=?, location=?, participant_count=?, summary=?, content=?, status=?, attachment=? WHERE id=?')
                ->execute([$eventId, $data['title'], $data['report_date'], $data['location'] ?: null, $count, $data['summary'] ?: null, $data['content'] ?: null, $data['status'], $attachment, $id]);
            logActivity($adminId, 'edit_report', 'Mengubah laporan kegiatan: ' . $data['title']);
            setFlash('success', 'Laporan berhasil diperbarui.');
            redirect('admin/report_view.php?id=' . $id);
        }
        $pdo->prepare('INSERT INTO activity_reports (event_id, title, report_date, location, participant_count, summary, content, status, attachment, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$eventId, $data['title'], $data['report_date'], $data['location'] ?: null, $count, $data['summary'] ?: null, $data['content'] ?: null, $data['status'], $newAttachment, $adminId]);
        $newId = (int) $pdo->lastInsertId();
        logActivity($adminId, 'add_report', 'Menambah laporan kegiatan: ' . $data['title']);
        setFlash('success', 'Laporan berhasil ditambahkan.');
        redirect('admin/report_view.php?id=' . $newId);
    }
}

$eventOptions = $pdo->query('SELECT id, title FROM events ORDER BY start_date DESC')->fetchAll();
$pageTitle = $report ? 'Edit Laporan' : 'Tambah Laporan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="reports.php">Laporan Kegiatan</a></li><li class="breadcrumb-item active"><?= $report ? 'Edit' : 'Tambah' ?></li></ol></nav>
    <h4 class="fw-bold mb-3"><?= $report ? 'Edit Laporan' : 'Tambah Laporan' ?></h4>

    <?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="card">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Judul Laporan <span class="text-danger">*</span></label><input type="text" name="title" class="form-control" required maxlength="200" value="<?= e($data['title']) ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Kegiatan terkait</label>
            <select name="event_id" class="form-select">
              <option value="">— Tidak terkait kegiatan —</option>
              <?php foreach ($eventOptions as $ev): ?><option value="<?= $ev['id'] ?>" <?= (int) $data['event_id'] === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label">Tanggal <span class="text-danger">*</span></label><input type="date" name="report_date" class="form-control" required value="<?= e($data['report_date']) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Jumlah Peserta</label><input type="number" name="participant_count" min="0" class="form-control" value="<?= e((string) $data['participant_count']) ?>"></div>
          <div class="col-md-6"><label class="form-label">Lokasi</label><input type="text" name="location" class="form-control" maxlength="200" value="<?= e($data['location']) ?>"></div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="draft" <?= $data['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
              <option value="final" <?= $data['status'] === 'final' ? 'selected' : '' ?>>Final</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Ringkasan</label><textarea name="summary" class="form-control" rows="2" placeholder="Ringkasan singkat kegiatan"><?= e($data['summary']) ?></textarea></div>
          <div class="col-12"><label class="form-label">Uraian / Isi Laporan</label><textarea name="content" class="form-control" rows="8" placeholder="Pelaksanaan, hasil, kendala, tindak lanjut..."><?= e($data['content']) ?></textarea></div>
          <div class="col-12">
            <label class="form-label">Lampiran (opsional)</label>
            <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
            <div class="form-text">PDF, Word, Excel, PowerPoint, JPG, PNG. Maksimal <?= round(MAX_UPLOAD_SIZE / 1048576) ?> MB.</div>
            <?php if ($report && $report['attachment']): ?>
              <div class="mt-2 small">Lampiran saat ini: <a href="<?= e(UPLOAD_URL . '/' . $report['attachment']) ?>" target="_blank"><?= e(basename($report['attachment'])) ?></a>
                <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_attachment" value="1" id="rmAtt"><label class="form-check-label" for="rmAtt">Hapus lampiran ini</label></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card-footer bg-white d-flex gap-2 justify-content-end">
        <a href="<?= $report ? 'report_view.php?id=' . $id : 'reports.php' ?>" class="btn btn-secondary">Batal</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan</button>
      </div>
    </form>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
