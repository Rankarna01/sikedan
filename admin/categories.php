<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$errors = [];

// Add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        setFlash('danger', 'Nama kategori wajib diisi.');
    } else {
        $slug = slugify($name);
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$name, $slug, $description]);
            setFlash('success', 'Kategori berhasil ditambahkan.');
        } catch (PDOException $e) {
            setFlash('danger', 'Kategori dengan nama tersebut sudah ada.');
        }
    }
    redirect('admin/categories.php');
}

// Update category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        setFlash('danger', 'Nama kategori wajib diisi.');
    } else {
        $stmt = $pdo->prepare('UPDATE categories SET name=?, description=? WHERE id=?');
        $stmt->execute([$name, $description, $id]);
        setFlash('success', 'Kategori berhasil diperbarui.');
    }
    redirect('admin/categories.php');
}

// Delete category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $check = $pdo->prepare('SELECT COUNT(*) FROM events WHERE category_id = ?');
    $check->execute([$id]);
    if ((int) $check->fetchColumn() > 0) {
        setFlash('danger', 'Kategori tidak dapat dihapus karena masih digunakan oleh kegiatan.');
    } else {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        setFlash('success', 'Kategori berhasil dihapus.');
    }
    redirect('admin/categories.php');
}

$categories = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM events e WHERE e.category_id = c.id) AS event_count
     FROM categories c ORDER BY c.name"
)->fetchAll();

$pageTitle = 'Kategori Kegiatan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="fw-bold mb-0">Kategori Kegiatan</h4>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal"><i class="bi bi-plus-lg me-1"></i>Tambah Kategori</button>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Nama Kategori</th><th>Deskripsi</th><th>Jumlah Kegiatan</th><th class="text-end">Aksi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $cat): ?>
              <tr>
                <td class="fw-semibold"><?= e($cat['name']) ?></td>
                <td class="small text-muted"><?= e($cat['description'] ?: '-') ?></td>
                <td><?= (int) $cat['event_count'] ?></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                          data-id="<?= $cat['id'] ?>" data-name="<?= e($cat['name']) ?>" data-description="<?= e($cat['description']) ?>">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus kategori ini?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Kategori</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Nama Kategori</label><input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Deskripsi</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editCatId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Edit Kategori</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Nama Kategori</label><input type="text" name="name" id="editCatName" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Deskripsi</label><textarea name="description" id="editCatDescription" class="form-control" rows="3"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('editCategoryModal').addEventListener('show.bs.modal', function (event) {
  var btn = event.relatedTarget;
  document.getElementById('editCatId').value = btn.getAttribute('data-id');
  document.getElementById('editCatName').value = btn.getAttribute('data-name');
  document.getElementById('editCatDescription').value = btn.getAttribute('data-description');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
