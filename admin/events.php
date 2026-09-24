<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'e.title LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($statusFilter !== '') {
    $where[] = 'e.status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter !== '') {
    $where[] = 'e.category_id = ?';
    $params[] = $categoryFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination
$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM events e $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$sql = "SELECT e.*, c.name AS category_name,
        (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id AND ep.status = 'approved') AS participant_count
        FROM events e
        JOIN categories c ON c.id = e.category_id
        $whereSql
        ORDER BY e.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$statusBadge = [
    'draft' => 'secondary',
    'active' => 'success',
    'inactive' => 'warning',
    'completed' => 'primary',
];
$statusLabel = [
    'draft' => 'Draft',
    'active' => 'Aktif',
    'inactive' => 'Nonaktif',
    'completed' => 'Selesai',
];

$pageTitle = 'Semua Kegiatan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
      <h4 class="fw-bold mb-0">Semua Kegiatan</h4>
      <a href="event_create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Tambah Kegiatan</a>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2">
          <div class="col-md-4">
            <input type="text" class="form-control" name="q" placeholder="Cari judul kegiatan..." value="<?= e($search) ?>">
          </div>
          <div class="col-md-3">
            <select name="status" class="form-select">
              <option value="">Semua Status</option>
              <?php foreach ($statusLabel as $key => $label): ?>
                <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <select name="category" class="form-select">
              <option value="">Semua Kategori</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (string) $categoryFilter === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-search"></i> Cari</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Judul Kegiatan</th>
              <th>Kategori</th>
              <th>Tanggal</th>
              <th>Peserta</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($events)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada kegiatan.</td></tr>
            <?php endif; ?>
            <?php foreach ($events as $ev): ?>
              <tr>
                <td class="fw-semibold"><?= e($ev['title']) ?></td>
                <td><?= e($ev['category_name']) ?></td>
                <td class="small"><?= formatTanggal($ev['start_date']) ?> &ndash; <?= formatTanggal($ev['end_date']) ?></td>
                <td><?= (int) $ev['participant_count'] ?></td>
                <td><span class="badge bg-<?= $statusBadge[$ev['status']] ?>"><?= $statusLabel[$ev['status']] ?></span></td>
                <td class="text-end">
                  <a href="event_dashboard.php?id=<?= (int) $ev['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Kelola"><i class="bi bi-kanban"></i></a>
                  <a href="event_edit.php?id=<?= (int) $ev['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                  <button type="button" class="btn btn-sm btn-outline-danger"
                          data-bs-toggle="modal" data-bs-target="#deleteModal"
                          data-id="<?= (int) $ev['id'] ?>" data-title="<?= e($ev['title']) ?>" title="Hapus">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination justify-content-center">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&category=<?= urlencode($categoryFilter) ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
    <?php endif; ?>
  </main>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" action="event_delete.php">
      <?= csrfField() ?>
      <input type="hidden" name="id" id="deleteEventId">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Hapus Kegiatan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          Apakah Anda yakin ingin menghapus kegiatan "<strong id="deleteEventTitle"></strong>"?
          Seluruh hari, materi, video, quiz, dan tugas terkait akan ikut terhapus.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger">Ya, Hapus</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('deleteModal').addEventListener('show.bs.modal', function (event) {
  var btn = event.relatedTarget;
  document.getElementById('deleteEventId').value = btn.getAttribute('data-id');
  document.getElementById('deleteEventTitle').textContent = btn.getAttribute('data-title');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
