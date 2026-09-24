<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['event_id'] ?? 0);

// ---------- Tanpa event_id: pilih kegiatan dulu ----------
if ($eventId === 0) {
    $eventList = $pdo->query(
        "SELECT e.id, e.title, e.start_date, e.end_date,
         (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id AND ep.status = 'approved') AS participant_count,
         (SELECT COUNT(*) FROM certificates c WHERE c.event_id = e.id) AS cert_count
         FROM events e ORDER BY e.start_date DESC"
    )->fetchAll();
    $pageTitle = 'Sertifikat';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/navbar.php';
    ?>
    <div class="app-layout">
      <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-content">
        <h4 class="fw-bold mb-1">Sertifikat</h4>
        <p class="text-muted">Pilih kegiatan untuk menerbitkan sertifikat &mdash; otomatis oleh sistem atau upload manual.</p>
        <div class="card">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light"><tr><th>Kegiatan</th><th>Peserta</th><th>Sertifikat</th><th class="text-end">Aksi</th></tr></thead>
              <tbody>
                <?php if (empty($eventList)): ?><tr><td colspan="4" class="text-center text-muted py-4">Belum ada kegiatan.</td></tr><?php endif; ?>
                <?php foreach ($eventList as $ev): ?>
                  <tr>
                    <td><div class="fw-semibold"><?= e($ev['title']) ?></div><div class="small text-muted"><?= formatTanggal($ev['start_date']) ?> &ndash; <?= formatTanggal($ev['end_date']) ?></div></td>
                    <td><?= (int) $ev['participant_count'] ?></td>
                    <td><?= (int) $ev['cert_count'] ?></td>
                    <td class="text-end"><a href="certificates.php?event_id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-award"></i> Kelola</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    setFlash('danger', 'Kegiatan tidak ditemukan.');
    redirect('admin/certificates.php');
}

/** Nomor sertifikat unik: SIKEDAN/{tahun}/{event_id}/{urutan}. Urutan = nomor terbesar yang ada + 1 (aman setelah sertifikat dicabut). */
function generateCertificateNumber(PDO $pdo, int $eventId): string
{
    $year = date('Y');
    $stmt = $pdo->prepare('SELECT certificate_number FROM certificates WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $num) {
        if (preg_match('/(\d+)$/', $num, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE certificate_number = ?');
    do {
        $max++;
        $number = sprintf('SIKEDAN/%s/%03d/%04d', $year, $eventId, $max);
        $exists->execute([$number]);
    } while ((int) $exists->fetchColumn() > 0);
    return $number;
}

function uniqueVerifyCode(PDO $pdo): string
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE verification_code = ?');
    do {
        $code = strtoupper(bin2hex(random_bytes(5)));
        $exists->execute([$code]);
    } while ((int) $exists->fetchColumn() > 0);
    return $code;
}

function notifyCertificate(PDO $pdo, int $userId, string $eventTitle): void
{
    $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')
        ->execute([$userId, 'Sertifikat telah diterbitkan', "Sertifikat untuk kegiatan \"$eventTitle\" sudah dapat diunduh."]);
}

$self = 'admin/certificates.php?event_id=' . $eventId;
$adminId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ----- Opsi 1: generate otomatis untuk satu peserta -----
    if ($action === 'generate_one') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $check = $pdo->prepare('SELECT id FROM certificates WHERE event_id = ? AND user_id = ?');
        $check->execute([$eventId, $userId]);
        if ($check->fetch()) {
            setFlash('danger', 'Peserta ini sudah memiliki sertifikat untuk kegiatan ini.');
        } elseif (!isApprovedParticipant($pdo, $userId, $eventId)) {
            setFlash('danger', 'Peserta belum disetujui pada kegiatan ini.');
        } else {
            $pdo->prepare("INSERT INTO certificates (event_id, user_id, certificate_number, verification_code, issued_at, source) VALUES (?, ?, ?, ?, CURDATE(), 'auto')")
                ->execute([$eventId, $userId, generateCertificateNumber($pdo, $eventId), uniqueVerifyCode($pdo)]);
            notifyCertificate($pdo, $userId, $event['title']);
            logActivity($adminId, 'generate_certificate', "Menerbitkan sertifikat (otomatis) untuk user_id $userId, event_id $eventId");
            setFlash('success', 'Sertifikat berhasil dibuat otomatis.');
        }
        redirect($self);
    }

    // ----- Opsi 1: generate otomatis massal -----
    if ($action === 'generate_all') {
        $threshold = max(0, min(100, (int) ($_POST['threshold'] ?? 100)));
        $stmt = $pdo->prepare(
            "SELECT ep.user_id FROM event_participants ep
             WHERE ep.event_id = ? AND ep.status = 'approved' AND ep.progress >= ?
             AND ep.user_id NOT IN (SELECT user_id FROM certificates WHERE event_id = ?)"
        );
        $stmt->execute([$eventId, $threshold, $eventId]);
        $count = 0;
        $ins = $pdo->prepare("INSERT INTO certificates (event_id, user_id, certificate_number, verification_code, issued_at, source) VALUES (?, ?, ?, ?, CURDATE(), 'auto')");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $ins->execute([$eventId, $uid, generateCertificateNumber($pdo, $eventId), uniqueVerifyCode($pdo)]);
            notifyCertificate($pdo, (int) $uid, $event['title']);
            $count++;
        }
        logActivity($adminId, 'generate_certificates_bulk', "Menerbitkan $count sertifikat otomatis massal untuk event_id $eventId");
        setFlash('success', "$count sertifikat berhasil dibuat otomatis untuk peserta dengan progress >= $threshold%.");
        redirect($self);
    }

    // ----- Opsi 2: upload manual oleh admin (baru atau mengganti yang ada) -----
    if ($action === 'upload_manual') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $customNumber = trim($_POST['certificate_number'] ?? '');
        if (!isApprovedParticipant($pdo, $userId, $eventId)) {
            setFlash('danger', 'Peserta belum disetujui pada kegiatan ini.');
            redirect($self);
        }
        if (empty($_FILES['cert_file']['name'])) {
            setFlash('danger', 'Pilih file sertifikat terlebih dahulu (PDF, JPG, atau PNG).');
            redirect($self);
        }
        $saved = saveUploadedFile($_FILES['cert_file'], 'certificates', ['pdf', 'jpg', 'jpeg', 'png'], 'cert');
        if (is_array($saved)) {
            setFlash('danger', $saved['error']);
            redirect($self);
        }

        $existing = $pdo->prepare('SELECT id, file_path FROM certificates WHERE event_id = ? AND user_id = ?');
        $existing->execute([$eventId, $userId]);
        $row = $existing->fetch();

        if ($customNumber !== '') {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE certificate_number = ? AND id != ?');
            $dup->execute([$customNumber, $row['id'] ?? 0]);
            if ((int) $dup->fetchColumn() > 0) {
                deleteUploadedFile($saved);
                setFlash('danger', 'Nomor sertifikat tersebut sudah dipakai.');
                redirect($self);
            }
        }

        if ($row) {
            deleteUploadedFile($row['file_path']);
            if ($customNumber !== '') {
                $pdo->prepare("UPDATE certificates SET file_path = ?, source = 'manual', certificate_number = ?, issued_at = CURDATE() WHERE id = ?")->execute([$saved, $customNumber, $row['id']]);
            } else {
                $pdo->prepare("UPDATE certificates SET file_path = ?, source = 'manual', issued_at = CURDATE() WHERE id = ?")->execute([$saved, $row['id']]);
            }
            setFlash('success', 'Sertifikat manual berhasil diunggah (menggantikan sertifikat sebelumnya).');
        } else {
            $number = $customNumber !== '' ? $customNumber : generateCertificateNumber($pdo, $eventId);
            $pdo->prepare("INSERT INTO certificates (event_id, user_id, certificate_number, verification_code, issued_at, file_path, source) VALUES (?, ?, ?, ?, CURDATE(), ?, 'manual')")
                ->execute([$eventId, $userId, $number, uniqueVerifyCode($pdo), $saved]);
            notifyCertificate($pdo, $userId, $event['title']);
            setFlash('success', 'Sertifikat manual berhasil diunggah.');
        }
        logActivity($adminId, 'upload_certificate', "Upload sertifikat manual untuk user_id $userId, event_id $eventId");
        redirect($self);
    }

    // ----- Cabut / hapus sertifikat -----
    if ($action === 'revoke') {
        $certId = (int) ($_POST['cert_id'] ?? 0);
        $f = $pdo->prepare('SELECT file_path FROM certificates WHERE id = ? AND event_id = ?');
        $f->execute([$certId, $eventId]);
        $path = $f->fetchColumn();
        $pdo->prepare('DELETE FROM certificates WHERE id = ? AND event_id = ?')->execute([$certId, $eventId]);
        if ($path) deleteUploadedFile($path);
        setFlash('success', 'Sertifikat berhasil dicabut.');
        redirect($self);
    }
    redirect($self);
}

// ---------- Filter & daftar peserta ----------
$search = trim($_GET['q'] ?? '');
$certFilter = $_GET['cert'] ?? '';
$where = ["ep.event_id = ?", "ep.status = 'approved'"];
$params = [$eventId];
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.institution LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($certFilter === 'sudah') $where[] = 'c.id IS NOT NULL';
if ($certFilter === 'belum') $where[] = 'c.id IS NULL';
$whereSql = implode(' AND ', $where);
$fromSql = "FROM event_participants ep JOIN users u ON u.id = ep.user_id
            LEFT JOIN certificates c ON c.event_id = ep.event_id AND c.user_id = ep.user_id";

$perPage = getPerPage();
$page = max(1, (int) ($_GET['page'] ?? 1));
$cnt = $pdo->prepare("SELECT COUNT(*) $fromSql WHERE $whereSql");
$cnt->execute($params);
$totalRows = (int) $cnt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT ep.progress, u.id AS user_id, u.full_name, u.institution,
     c.id AS cert_id, c.certificate_number, c.verification_code, c.issued_at, c.source, c.file_path
     $fromSql WHERE $whereSql ORDER BY u.full_name ASC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$participants = $stmt->fetchAll();

$pageTitle = 'Sertifikat — ' . $event['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="certificates.php">Sertifikat</a></li><li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $eventId ?>"><?= e($event['title']) ?></a></li><li class="breadcrumb-item active">Kelola</li></ol></nav>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="fw-bold mb-0">Sertifikat: <?= e($event['title']) ?></h4>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkGenModal"><i class="bi bi-magic me-1"></i>Generate Otomatis Massal</button>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="row g-2 mb-3">
      <div class="col-md-6"><div class="border rounded p-3 bg-white h-100 small"><i class="bi bi-magic text-primary me-1"></i><strong>Generate otomatis</strong> &mdash; sistem membuat sertifikat bertemplate lengkap dengan nomor &amp; QR verifikasi.</div></div>
      <div class="col-md-6"><div class="border rounded p-3 bg-white h-100 small"><i class="bi bi-upload text-success me-1"></i><strong>Upload manual</strong> &mdash; unggah file sertifikat buatan Anda sendiri (PDF/JPG/PNG, maks. <?= round(MAX_UPLOAD_SIZE / 1048576) ?> MB).</div></div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <input type="hidden" name="event_id" value="<?= $eventId ?>">
          <div class="col-12 col-md-5"><input type="text" name="q" class="form-control" placeholder="Cari nama / institusi peserta..." value="<?= e($search) ?>"></div>
          <div class="col-6 col-md-3">
            <select name="cert" class="form-select">
              <option value="">Semua status</option>
              <option value="sudah" <?= $certFilter === 'sudah' ? 'selected' : '' ?>>Sudah bersertifikat</option>
              <option value="belum" <?= $certFilter === 'belum' ? 'selected' : '' ?>>Belum bersertifikat</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="per_page" class="form-select" onchange="this.form.submit()">
              <?php foreach (perPageOptions() as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> / hal</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Filter</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Peserta</th><th>Progress</th><th>No. Sertifikat</th><th>Jenis</th><th>Tanggal Terbit</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
            <?php if (empty($participants)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Belum ada peserta yang disetujui<?= $search !== '' || $certFilter !== '' ? ' / sesuai filter' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($participants as $p): ?>
              <tr>
                <td><div class="fw-semibold"><?= e($p['full_name']) ?></div><div class="small text-muted"><?= e($p['institution'] ?: '-') ?></div></td>
                <td><?= (int) $p['progress'] ?>%</td>
                <td class="small"><?= $p['certificate_number'] ? e($p['certificate_number']) : '<span class="text-muted">Belum diterbitkan</span>' ?></td>
                <td><?php if ($p['cert_id']): ?><span class="badge <?= $p['source'] === 'manual' ? 'bg-success' : 'bg-primary' ?>"><?= $p['source'] === 'manual' ? 'Upload manual' : 'Otomatis' ?></span><?php else: ?>-<?php endif; ?></td>
                <td class="small"><?= $p['issued_at'] ? formatTanggal($p['issued_at']) : '-' ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($p['cert_id']): ?>
                    <a href="<?= BASE_URL ?>/certificate_view.php?id=<?= $p['cert_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Lihat</a>
                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#uploadModal" data-user="<?= $p['user_id'] ?>" data-name="<?= e($p['full_name']) ?>" title="Ganti dengan file upload"><i class="bi bi-upload"></i></button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Cabut sertifikat ini?');">
                      <?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="cert_id" value="<?= $p['cert_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Cabut"><i class="bi bi-x-circle"></i></button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="d-inline">
                      <?= csrfField() ?><input type="hidden" name="action" value="generate_one"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> Otomatis</button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#uploadModal" data-user="<?= $p['user_id'] ?>" data-name="<?= e($p['full_name']) ?>"><i class="bi bi-upload"></i> Upload</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="small text-muted mt-2">Total <?= $totalRows ?> peserta disetujui.</div>
    <?php renderPagination($page, $totalPages); ?>
  </main>
</div>

<div class="modal fade" id="bulkGenModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="generate_all">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Generate Sertifikat Otomatis Massal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted">Sertifikat dibuat otomatis untuk seluruh peserta <strong>yang sudah disetujui</strong>, belum memiliki sertifikat, dan memenuhi progress minimal berikut:</p>
          <label class="form-label">Minimal Progress (%)</label>
          <input type="number" name="threshold" min="0" max="100" value="100" class="form-control">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary">Generate</button></div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_manual">
      <input type="hidden" name="user_id" id="uploadUserId">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Upload Sertifikat Manual</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="mb-3">Peserta: <strong id="uploadUserName"></strong></p>
          <div class="mb-3">
            <label class="form-label">File Sertifikat <span class="text-danger">*</span></label>
            <input type="file" name="cert_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
            <div class="form-text">PDF, JPG, atau PNG. Maksimal <?= round(MAX_UPLOAD_SIZE / 1048576) ?> MB.</div>
          </div>
          <div class="mb-0">
            <label class="form-label">Nomor Sertifikat (opsional)</label>
            <input type="text" name="certificate_number" class="form-control" maxlength="100" placeholder="Kosongkan untuk nomor otomatis">
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-success"><i class="bi bi-upload me-1"></i>Upload</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('uploadModal').addEventListener('show.bs.modal', function (ev) {
  var b = ev.relatedTarget;
  document.getElementById('uploadUserId').value = b.getAttribute('data-user');
  document.getElementById('uploadUserName').textContent = b.getAttribute('data-name');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
