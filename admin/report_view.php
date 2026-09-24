<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$id = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT r.*, e.title AS event_title, e.slug AS event_slug FROM activity_reports r LEFT JOIN events e ON e.id = r.event_id WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    setFlash('danger', 'Laporan tidak ditemukan.');
    redirect('admin/reports.php');
}

$pageTitle = $r['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
$isImage = $r['attachment'] && preg_match('/\.(jpe?g|png)$/i', $r['attachment']);
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="reports.php">Laporan Kegiatan</a></li><li class="breadcrumb-item active">Detail</li></ol></nav>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <h4 class="fw-bold mb-1"><?= e($r['title']) ?></h4>
        <span class="badge <?= $r['status'] === 'final' ? 'bg-success' : 'bg-secondary' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draft' ?></span>
      </div>
      <div class="d-flex gap-2 flex-wrap no-print">
        <a href="report_form.php?id=<?= $r['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak</button>
        <form method="post" action="reports.php" class="d-inline" onsubmit="return confirm('Hapus laporan ini?');">
          <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Hapus</button>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3 small">
          <div class="col-6 col-md-3"><div class="text-muted">Kegiatan</div><div class="fw-semibold"><?= $r['event_id'] ? '<a href="reports.php?event_id=' . (int) $r['event_id'] . '">' . e($r['event_title']) . '</a>' : '-' ?></div></div>
          <div class="col-6 col-md-3"><div class="text-muted">Tanggal</div><div class="fw-semibold"><?= formatTanggal($r['report_date']) ?></div></div>
          <div class="col-6 col-md-3"><div class="text-muted">Lokasi</div><div class="fw-semibold"><?= e($r['location'] ?: '-') ?></div></div>
          <div class="col-6 col-md-3"><div class="text-muted">Jumlah Peserta</div><div class="fw-semibold"><?= $r['participant_count'] !== null ? (int) $r['participant_count'] : '-' ?></div></div>
        </div>
      </div>
    </div>

    <?php if ($r['summary']): ?>
      <div class="card mb-3"><div class="card-header bg-white fw-semibold">Ringkasan</div><div class="card-body"><?= nl2br(e($r['summary'])) ?></div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header bg-white fw-semibold">Uraian Laporan</div><div class="card-body"><?= $r['content'] ? nl2br(e($r['content'])) : '<span class="text-muted">Belum ada uraian.</span>' ?></div></div>

    <?php if ($r['attachment']): ?>
      <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Lampiran</div>
        <div class="card-body">
          <?php if ($isImage): ?><img src="<?= e(UPLOAD_URL . '/' . $r['attachment']) ?>" alt="Lampiran" class="img-fluid rounded mb-2" style="max-height:400px;"><br><?php endif; ?>
          <a href="<?= e(UPLOAD_URL . '/' . $r['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip me-1"></i><?= e(basename($r['attachment'])) ?></a>
        </div>
      </div>
    <?php endif; ?>
    <p class="text-muted small">Dibuat <?= formatTanggal($r['created_at']) ?> &middot; Diperbarui <?= formatTanggal($r['updated_at']) ?></p>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
