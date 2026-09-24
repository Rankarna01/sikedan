<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();

/** Bangun URL kembali (mempertahankan filter & halaman) setelah sebuah aksi POST */
function participantsBackUrl(): string
{
    $allowed = ['q', 'event_id', 'category', 'enroll_status', 'acct_status', 'date_from', 'date_to', 'per_page', 'page'];
    parse_str($_POST['back'] ?? '', $b);
    $keep = array_intersect_key($b, array_flip($allowed));
    $qs = http_build_query($keep);
    return 'admin/participants.php' . ($qs ? '?' . $qs : '');
}

/** Kirim notifikasi ke peserta bahwa pendaftarannya disetujui */
function notifyApproved(PDO $pdo, int $userId, string $eventTitle): void
{
    $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
        ->execute([$userId, 'Pendaftaran disetujui', "Pendaftaran Anda pada kegiatan \"$eventTitle\" telah disetujui. Silakan mulai belajar."]);
}

// ---------- Aksi POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $adminId = (int) $_SESSION['user_id'];
    $back = participantsBackUrl();

    if ($action === 'add') {
        $fullName = trim($_POST['full_name'] ?? '');
        $identity = trim($_POST['identity_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $institution = trim($_POST['institution'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $errors = [];
        if ($fullName === '') $errors[] = 'Nama lengkap wajib diisi.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
        if ($username === '') $errors[] = 'Username wajib diisi.';
        if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';

        if (empty($errors)) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? OR username = ?');
            $check->execute([$email, $username]);
            if ((int) $check->fetchColumn() > 0) {
                $errors[] = 'Email atau username sudah digunakan.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare(
                'INSERT INTO users (role_id, full_name, identity_number, email, phone, institution, position, username, password, status)
                 VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
            );
            $ins->execute([$fullName, $identity, $email, $phone, $institution, $position, $username, $hash]);
            logActivity($adminId, 'add_participant', "Menambah peserta: $fullName");
            setFlash('success', 'Peserta berhasil ditambahkan.');
        } else {
            setFlash('danger', implode(' ', $errors));
        }
        redirect($back);
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ? AND role_id = 2")->execute([$id]);
        setFlash('success', 'Status peserta berhasil diperbarui.');
        redirect($back);
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role_id = 2')->execute([$id]);
        setFlash('success', 'Peserta berhasil dihapus.');
        redirect($back);
    }

    if ($action === 'enroll') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        // Peserta yang ditambahkan langsung oleh admin otomatis berstatus disetujui
        $pdo->prepare("INSERT IGNORE INTO event_participants (event_id, user_id, status) VALUES (?, ?, 'approved')")->execute([$eventId, $userId]);
        setFlash('success', 'Peserta berhasil ditambahkan ke kegiatan.');
        redirect($back);
    }

    if ($action === 'unenroll') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $pdo->prepare('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?')->execute([$eventId, $userId]);
        setFlash('success', 'Peserta berhasil dikeluarkan dari kegiatan.');
        redirect($back);
    }

    // ----- Setujui / Tolak individu -----
    if ($action === 'approve' || $action === 'reject') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $upd = $pdo->prepare('UPDATE event_participants SET status = ? WHERE event_id = ? AND user_id = ?');
        $upd->execute([$newStatus, $eventId, $userId]);
        if ($upd->rowCount() > 0 && $action === 'approve') {
            $t = $pdo->prepare('SELECT title FROM events WHERE id = ?');
            $t->execute([$eventId]);
            notifyApproved($pdo, $userId, (string) $t->fetchColumn());
        }
        logActivity($adminId, $action . '_participant', "Peserta user_id $userId pada event_id $eventId: $newStatus");
        setFlash('success', $action === 'approve' ? 'Peserta berhasil disetujui.' : 'Pendaftaran peserta ditolak.');
        redirect($back);
    }

    // ----- Setujui Semua (seluruh pendaftar yang masih menunggu pada kegiatan) -----
    if ($action === 'approve_all') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $t = $pdo->prepare('SELECT title FROM events WHERE id = ?');
        $t->execute([$eventId]);
        $eventTitle = $t->fetchColumn();
        if ($eventTitle === false) {
            setFlash('danger', 'Kegiatan tidak ditemukan.');
            redirect($back);
        }
        $sel = $pdo->prepare("SELECT user_id FROM event_participants WHERE event_id = ? AND status = 'registered'");
        $sel->execute([$eventId]);
        $userIds = $sel->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE event_participants SET status = 'approved' WHERE event_id = ? AND status = 'registered'")->execute([$eventId]);
            foreach ($userIds as $uid) {
                notifyApproved($pdo, (int) $uid, (string) $eventTitle);
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal menyetujui peserta. Silakan coba lagi.');
            redirect($back);
        }
        logActivity($adminId, 'approve_all_participants', count($userIds) . " peserta disetujui pada event_id $eventId");
        setFlash('success', count($userIds) . ' peserta berhasil disetujui sekaligus.');
        redirect($back);
    }

    redirect($back);
}

// ---------- Filter (GET) ----------
$search = trim($_GET['q'] ?? '');
$eventFilterId = (int) ($_GET['event_id'] ?? 0);
$categoryFilter = (int) ($_GET['category'] ?? 0);
$enrollStatus = $_GET['enroll_status'] ?? '';
$acctStatus = $_GET['acct_status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$validDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$validDate($dateFrom)) $dateFrom = '';
if (!$validDate($dateTo)) $dateTo = '';
if (!in_array($enrollStatus, ['registered', 'approved', 'rejected', 'none'], true)) $enrollStatus = '';
if (!in_array($acctStatus, ['active', 'inactive'], true)) $acctStatus = '';

$currentEvent = null;
if ($eventFilterId > 0) {
    $evStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $evStmt->execute([$eventFilterId]);
    $currentEvent = $evStmt->fetch() ?: null;
    if (!$currentEvent) $eventFilterId = 0;
}

$where = ['u.role_id = 2'];
$params = [];
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.institution LIKE ? OR u.identity_number LIKE ?)';
    $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
}
if ($acctStatus !== '') {
    $where[] = 'u.status = ?';
    $params[] = $acctStatus;
}

$counts = ['registered' => 0, 'approved' => 0, 'rejected' => 0];
if ($currentEvent) {
    // Mode kegiatan: semua akun peserta + status pendaftarannya pada kegiatan ini
    $from = 'FROM users u LEFT JOIN event_participants ep ON ep.user_id = u.id AND ep.event_id = ' . (int) $eventFilterId;
    $select = 'u.*, ep.status AS enroll_status, ep.joined_at, ep.progress';
    if ($enrollStatus === 'none') {
        $where[] = 'ep.id IS NULL';
    } elseif ($enrollStatus !== '') {
        $where[] = 'ep.status = ?';
        $params[] = $enrollStatus;
    }
    if ($dateFrom !== '') { $where[] = 'DATE(ep.joined_at) >= ?'; $params[] = $dateFrom; }
    if ($dateTo !== '')   { $where[] = 'DATE(ep.joined_at) <= ?'; $params[] = $dateTo; }
    $orderSql = "ORDER BY (ep.status = 'registered') DESC, u.full_name ASC";

    $cStmt = $pdo->prepare('SELECT status, COUNT(*) FROM event_participants WHERE event_id = ? GROUP BY status');
    $cStmt->execute([$eventFilterId]);
    foreach ($cStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) $counts[$st] = (int) $n;
} else {
    // Mode global: satu baris per akun peserta + ringkasan pendaftarannya
    $from = 'FROM users u';
    $select = "u.*,
        (SELECT COUNT(*) FROM event_participants x WHERE x.user_id = u.id) AS total_events,
        (SELECT COUNT(*) FROM event_participants x WHERE x.user_id = u.id AND x.status = 'registered') AS pending_events";
    if ($categoryFilter > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM event_participants x JOIN events e ON e.id = x.event_id WHERE x.user_id = u.id AND e.category_id = ?)';
        $params[] = $categoryFilter;
    }
    if ($enrollStatus === 'none') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM event_participants x WHERE x.user_id = u.id)';
    } elseif ($enrollStatus !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM event_participants x WHERE x.user_id = u.id AND x.status = ?)';
        $params[] = $enrollStatus;
    }
    if ($dateFrom !== '') { $where[] = 'DATE(u.created_at) >= ?'; $params[] = $dateFrom; }
    if ($dateTo !== '')   { $where[] = 'DATE(u.created_at) <= ?'; $params[] = $dateTo; }
    $orderSql = 'ORDER BY u.full_name ASC';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = getPerPage();
$page = max(1, (int) ($_GET['page'] ?? 1));

$countStmt = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT $select $from $whereSql $orderSql LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$participants = $stmt->fetchAll();

$events = $pdo->query('SELECT id, title FROM events ORDER BY start_date DESC')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$totalPendingAll = (int) $pdo->query("SELECT COUNT(*) FROM event_participants WHERE status = 'registered'")->fetchColumn();

$enrollLabel = ['registered' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
$enrollBadge = ['registered' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'];
$hasFilter = ($search !== '' || $categoryFilter || $enrollStatus !== '' || $acctStatus !== '' || $dateFrom !== '' || $dateTo !== '');
$backField = '<input type="hidden" name="back" value="' . e(queryString()) . '">';

$pageTitle = 'Semua Peserta';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h4 class="fw-bold mb-0"><?= $currentEvent ? 'Peserta: ' . e($currentEvent['title']) : 'Semua Peserta' ?></h4>
        <?php if ($currentEvent): ?><a href="event_dashboard.php?id=<?= $currentEvent['id'] ?>" class="small">&larr; Kembali ke Kegiatan</a><?php endif; ?>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($currentEvent && $counts['registered'] > 0): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Setujui SEMUA <?= $counts['registered'] ?> peserta yang menunggu pada kegiatan ini?');">
            <?= csrfField() ?><?= $backField ?>
            <input type="hidden" name="action" value="approve_all">
            <input type="hidden" name="event_id" value="<?= $currentEvent['id'] ?>">
            <button type="submit" class="btn btn-success"><i class="bi bi-check2-all me-1"></i>Setujui Semua (<?= $counts['registered'] ?>)</button>
          </form>
        <?php endif; ?>
        <a href="import_participants.php<?= $currentEvent ? '?event_id=' . $currentEvent['id'] : '' ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Import Excel</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addParticipantModal"><i class="bi bi-plus-lg me-1"></i>Tambah Peserta</button>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$currentEvent && $totalPendingAll > 0): ?>
      <div class="alert alert-warning py-2 small">
        <i class="bi bi-hourglass-split me-1"></i>Ada <strong><?= $totalPendingAll ?></strong> pendaftaran yang menunggu persetujuan. Pilih <strong>Kegiatan</strong> pada filter di bawah untuk menyetujuinya.
      </div>
    <?php endif; ?>

    <?php if ($currentEvent): ?>
      <ul class="nav nav-pills mb-3 flex-nowrap overflow-auto small">
        <?php
        $tabs = ['' => 'Semua', 'registered' => 'Menunggu (' . $counts['registered'] . ')', 'approved' => 'Disetujui (' . $counts['approved'] . ')', 'rejected' => 'Ditolak (' . $counts['rejected'] . ')', 'none' => 'Belum terdaftar'];
        foreach ($tabs as $val => $label): ?>
          <li class="nav-item"><a class="nav-link py-1 text-nowrap <?= $enrollStatus === $val ? 'active' : '' ?>" href="?<?= e(queryString(['enroll_status' => $val, 'page' => null])) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Nama / email / username / institusi</label>
            <input type="text" class="form-control" name="q" placeholder="Cari..." value="<?= e($search) ?>">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small mb-1">Kegiatan</label>
            <select name="event_id" class="form-select">
              <option value="">Semua kegiatan</option>
              <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $eventFilterId === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (!$currentEvent): ?>
          <div class="col-6 col-md-4">
            <label class="form-label small mb-1">Kategori kegiatan</label>
            <select name="category" class="form-select">
              <option value="">Semua kategori</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $categoryFilter === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Status pendaftaran</label>
            <select name="enroll_status" class="form-select">
              <option value="">Semua</option>
              <option value="registered" <?= $enrollStatus === 'registered' ? 'selected' : '' ?>>Menunggu persetujuan</option>
              <option value="approved" <?= $enrollStatus === 'approved' ? 'selected' : '' ?>>Disetujui</option>
              <option value="rejected" <?= $enrollStatus === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
              <option value="none" <?= $enrollStatus === 'none' ? 'selected' : '' ?>>Belum ikut kegiatan</option>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" name="enroll_status" value="<?= e($enrollStatus) ?>">
          <?php endif; ?>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Status akun</label>
            <select name="acct_status" class="form-select">
              <option value="">Semua</option>
              <option value="active" <?= $acctStatus === 'active' ? 'selected' : '' ?>>Aktif</option>
              <option value="inactive" <?= $acctStatus === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tanggal daftar dari</label>
            <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Sampai</label>
            <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tampilkan</label>
            <select name="per_page" class="form-select" onchange="this.form.submit()">
              <?php foreach (perPageOptions() as $n): ?>
                <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per halaman</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3 d-flex gap-2">
            <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i>Terapkan</button>
            <?php if ($hasFilter || $eventFilterId): ?>
              <a href="participants.php" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
      <span>
        <?php if ($totalRows > 0): ?>
          Menampilkan <?= $offset + 1 ?>&ndash;<?= min($offset + $perPage, $totalRows) ?> dari <?= $totalRows ?> peserta
        <?php else: ?>Tidak ada data<?php endif; ?>
      </span>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Nama</th><th>Username</th><th>Email</th><th>Institusi</th>
              <?php if ($currentEvent): ?><th>Tgl Daftar</th><th>Pendaftaran</th><?php else: ?><th>Kegiatan</th><?php endif; ?>
              <th>Akun</th><th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($participants)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4"><?= $hasFilter || $currentEvent ? 'Tidak ada peserta yang sesuai filter.' : 'Belum ada peserta.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($participants as $p): ?>
              <tr>
                <td class="fw-semibold"><?= e($p['full_name']) ?></td>
                <td><?= e($p['username']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td class="small"><?= e($p['institution'] ?: '-') ?></td>
                <?php if ($currentEvent): ?>
                  <td class="small"><?= $p['joined_at'] ? formatTanggal($p['joined_at']) : '-' ?></td>
                  <td>
                    <?php if ($p['enroll_status']): ?>
                      <span class="badge bg-<?= $enrollBadge[$p['enroll_status']] ?>"><?= $enrollLabel[$p['enroll_status']] ?></span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary">Belum terdaftar</span>
                    <?php endif; ?>
                  </td>
                <?php else: ?>
                  <td class="small">
                    <?= (int) $p['total_events'] ?>
                    <?php if ((int) $p['pending_events'] > 0): ?><span class="badge bg-warning text-dark"><?= (int) $p['pending_events'] ?> menunggu</span><?php endif; ?>
                  </td>
                <?php endif; ?>
                <td>
                  <form method="post" class="d-inline">
                    <?= csrfField() ?><?= $backField ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm badge <?= $p['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> border-0">
                      <?= $p['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?>
                    </button>
                  </form>
                </td>
                <td class="text-end text-nowrap">
                  <?php if ($currentEvent): ?>
                    <?php $st = $p['enroll_status']; ?>
                    <?php if ($st === 'registered' || $st === 'rejected'): ?>
                      <form method="post" class="d-inline">
                        <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="approve">
                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>"><input type="hidden" name="event_id" value="<?= $currentEvent['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Setujui</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($st === 'registered'): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Tolak pendaftaran peserta ini?');">
                        <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="reject">
                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>"><input type="hidden" name="event_id" value="<?= $currentEvent['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Tolak</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($st === 'approved' || $st === 'rejected'): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Keluarkan peserta ini dari kegiatan?');">
                        <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="unenroll">
                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>"><input type="hidden" name="event_id" value="<?= $currentEvent['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Keluarkan</button>
                      </form>
                    <?php endif; ?>
                    <?php if (!$st): ?>
                      <form method="post" class="d-inline">
                        <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="enroll">
                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>"><input type="hidden" name="event_id" value="<?= $currentEvent['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success">Tambahkan ke Kegiatan</button>
                      </form>
                    <?php endif; ?>
                  <?php else: ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus peserta ini beserta seluruh riwayat kegiatannya?');">
                      <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php renderPagination($page, $totalPages); ?>
  </main>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addParticipantModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post">
      <?= csrfField() ?><?= $backField ?>
      <input type="hidden" name="action" value="add">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Tambah Peserta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nama Lengkap <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">NIK/NIM/NIP</label><input type="text" name="identity_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">No. HP</label><input type="text" name="phone" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Institusi</label><input type="text" name="institution" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Jabatan</label><input type="text" name="position" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Username <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" name="password" class="form-control" required minlength="6"></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
