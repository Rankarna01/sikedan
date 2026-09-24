<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['event_id'] ?? 0);

if ($eventId) {
    // ===================== DETAIL REPORT FOR ONE EVENT =====================
    $stmt = $pdo->prepare("SELECT e.*, c.name AS category_name FROM events e JOIN categories c ON c.id = e.category_id WHERE e.id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        setFlash('danger', 'Kegiatan tidak ditemukan.');
        redirect('admin/reports.php');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'approved'");
    $stmt->execute([$eventId]);
    $totalParticipants = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT AVG(progress) FROM event_participants WHERE event_id = ? AND status = 'approved'");
    $stmt->execute([$eventId]);
    $avgProgress = round((float) $stmt->fetchColumn(), 1);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'approved' AND progress >= 100");
    $stmt->execute([$eventId]);
    $completedCount = (int) $stmt->fetchColumn();
    $completionRate = $totalParticipants > 0 ? round(($completedCount / $totalParticipants) * 100, 1) : 0;

    // Average assignment score across the event
    $stmt = $pdo->prepare(
        "SELECT AVG(s.score) FROM assignment_submissions s
         JOIN assignments a ON a.id = s.assignment_id
         JOIN event_days ed ON ed.id = a.event_day_id
         WHERE ed.event_id = ? AND s.score IS NOT NULL"
    );
    $stmt->execute([$eventId]);
    $avgAssignmentScore = round((float) ($stmt->fetchColumn() ?: 0), 1);

    // Average quiz score
    $stmt = $pdo->prepare(
        "SELECT AVG(qa.score) FROM quiz_attempts qa
         JOIN quizzes q ON q.id = qa.quiz_id
         JOIN event_days ed ON ed.id = q.event_day_id
         WHERE ed.event_id = ?"
    );
    $stmt->execute([$eventId]);
    $avgQuizScore = round((float) ($stmt->fetchColumn() ?: 0), 1);

    // Evaluation averages
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, AVG(material_rating) AS m, AVG(speaker_rating) AS s, AVG(overall_rating) AS o FROM evaluations WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $evalData = $stmt->fetch();
    $evalCount = (int) $evalData['n'];
    $satisfactionAvg = $evalCount > 0 ? round((float) $evalData['o'], 1) : 0;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $certCount = (int) $stmt->fetchColumn();

    // Participant-level table
    $stmt = $pdo->prepare(
        "SELECT u.full_name, u.institution, ep.progress,
         (SELECT AVG(score) FROM assignment_submissions s2 JOIN assignments a2 ON a2.id = s2.assignment_id
          JOIN event_days ed2 ON ed2.id = a2.event_day_id WHERE ed2.event_id = ep.event_id AND s2.user_id = u.id AND s2.score IS NOT NULL) AS avg_assignment,
         (SELECT AVG(score) FROM quiz_attempts qa2 JOIN quizzes q2 ON q2.id = qa2.quiz_id
          JOIN event_days ed3 ON ed3.id = q2.event_day_id WHERE ed3.event_id = ep.event_id AND qa2.user_id = u.id) AS avg_quiz,
         (SELECT COUNT(*) FROM certificates c2 WHERE c2.event_id = ep.event_id AND c2.user_id = u.id) AS has_cert
         FROM event_participants ep JOIN users u ON u.id = ep.user_id
         WHERE ep.event_id = ? AND ep.status = 'approved' ORDER BY u.full_name ASC"
    );
    $stmt->execute([$eventId]);
    $participantRows = $stmt->fetchAll();

    // CSV export of the participant-level table
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="laporan_' . slugify($event['title']) . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Nama', 'Institusi', 'Progress (%)', 'Rata-rata Nilai Tugas', 'Rata-rata Skor Quiz', 'Sertifikat']);
        foreach ($participantRows as $r) {
            fputcsv($out, [
                $r['full_name'], $r['institution'], (int) $r['progress'],
                $r['avg_assignment'] !== null ? round((float) $r['avg_assignment'], 1) : '-',
                $r['avg_quiz'] !== null ? round((float) $r['avg_quiz'], 1) : '-',
                $r['has_cert'] > 0 ? 'Sudah' : 'Belum',
            ]);
        }
        fclose($out);
        exit;
    }

    $pageTitle = 'Laporan — ' . $event['title'];
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/navbar.php';
    ?>
    <div class="app-layout">
      <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-content">
        <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="reports.php">Laporan</a></li><li class="breadcrumb-item"><a href="reports.php?view=stats">Statistik</a></li><li class="breadcrumb-item active"><?= e($event['title']) ?></li></ol></nav>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h4 class="fw-bold mb-0">Laporan Kegiatan</h4>
            <p class="text-muted small mb-0"><?= e($event['title']) ?> &middot; <?= e($event['category_name']) ?> &middot; <?= formatTanggal($event['start_date']) ?> &ndash; <?= formatTanggal($event['end_date']) ?></p>
          </div>
          <div class="d-flex gap-2">
            <a href="?event_id=<?= $eventId ?>&export=csv" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
            <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak</button>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $totalParticipants ?></h4><p class="text-muted small mb-0">Jumlah Peserta</p></div></div></div>
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-graph-up-arrow"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgProgress ?>%</h4><p class="text-muted small mb-0">Progress Rata-rata</p></div></div></div>
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-check2-circle"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $completionRate ?>%</h4><p class="text-muted small mb-0">Tingkat Penyelesaian</p></div></div></div>
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-patch-question"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgQuizScore ?></h4><p class="text-muted small mb-0">Rata-rata Skor Quiz</p></div></div></div>
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-file-earmark-text"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $avgAssignmentScore ?></h4><p class="text-muted small mb-0">Rata-rata Nilai Tugas</p></div></div></div>
          <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card h-100"><div class="card-body"><div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-star-fill"></i></div><h4 class="fw-bold mt-2 mb-0"><?= $satisfactionAvg ?>/5</h4><p class="text-muted small mb-0">Kepuasan (<?= $evalCount ?> resp.)</p></div></div></div>
        </div>

        <div class="card mb-4">
          <div class="card-header bg-white fw-semibold">Ringkasan Naratif</div>
          <div class="card-body">
            <p class="mb-1"><strong>Nama Kegiatan:</strong> <?= e($event['title']) ?></p>
            <p class="mb-1"><strong>Tanggal:</strong> <?= formatTanggal($event['start_date']) ?> &ndash; <?= formatTanggal($event['end_date']) ?></p>
            <p class="mb-1"><strong>Jumlah Peserta:</strong> <?= $totalParticipants ?></p>
            <p class="mb-1"><strong>Rata-rata Nilai:</strong> <?= $avgAssignmentScore > 0 ? $avgAssignmentScore : $avgQuizScore ?></p>
            <p class="mb-1"><strong>Tingkat Penyelesaian:</strong> <?= $completionRate ?>%</p>
            <p class="mb-1"><strong>Kepuasan:</strong> <?= $satisfactionAvg ?>/5</p>
            <p class="mb-0"><strong>Sertifikat Diterbitkan:</strong> <?= $certCount ?> dari <?= $totalParticipants ?> peserta</p>
          </div>
        </div>

        <div class="card">
          <div class="card-header bg-white fw-semibold">Data Peserta</div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light"><tr><th>Nama</th><th>Institusi</th><th>Progress</th><th>Nilai Tugas</th><th>Skor Quiz</th><th>Sertifikat</th></tr></thead>
              <tbody>
                <?php foreach ($participantRows as $r): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($r['full_name']) ?></td>
                    <td class="small"><?= e($r['institution'] ?: '-') ?></td>
                    <td><?= (int) $r['progress'] ?>%</td>
                    <td><?= $r['avg_assignment'] !== null ? round((float) $r['avg_assignment'], 1) : '-' ?></td>
                    <td><?= $r['avg_quiz'] !== null ? round((float) $r['avg_quiz'], 1) : '-' ?></td>
                    <td><?= $r['has_cert'] > 0 ? '<span class="badge bg-success">Sudah</span>' : '<span class="badge bg-secondary">Belum</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    <?php
    exit;
}

// ===================== LAPORAN KEGIATAN (CRUD) =====================
$adminId = (int) $_SESSION['user_id'];

/** Hapus laporan beserta lampirannya */
function deleteReports(PDO $pdo, ?array $ids = null): int
{
    if ($ids === null) {
        $rows = $pdo->query('SELECT id, attachment FROM activity_reports')->fetchAll();
    } else {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, attachment FROM activity_reports WHERE id IN ($in)");
        $st->execute($ids);
        $rows = $st->fetchAll();
    }
    foreach ($rows as $r) {
        deleteUploadedFile($r['attachment']);
        $pdo->prepare('DELETE FROM activity_reports WHERE id = ?')->execute([$r['id']]);
    }
    return count($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    parse_str($_POST['back'] ?? '', $b);
    $keep = array_intersect_key($b, array_flip(['q', 'ev', 'category', 'status', 'date_from', 'date_to', 'per_page', 'page']));
    $back = 'admin/reports.php' . ($keep ? '?' . http_build_query($keep) : '');

    if ($action === 'delete') {
        $n = deleteReports($pdo, [(int) ($_POST['id'] ?? 0)]);
        if ($n) logActivity($adminId, 'delete_report', 'Menghapus 1 laporan kegiatan');
        setFlash('success', $n ? 'Laporan berhasil dihapus.' : 'Laporan tidak ditemukan.');
    } elseif ($action === 'delete_selected') {
        $ids = $_POST['ids'] ?? [];
        $n = is_array($ids) ? deleteReports($pdo, $ids) : 0;
        if ($n) logActivity($adminId, 'delete_reports_selected', "Menghapus $n laporan kegiatan terpilih");
        setFlash($n ? 'success' : 'danger', $n ? "$n laporan terpilih berhasil dihapus." : 'Pilih minimal satu laporan terlebih dahulu.');
    } elseif ($action === 'delete_all') {
        if (trim($_POST['confirm_text'] ?? '') !== 'HAPUS') {
            setFlash('danger', 'Konfirmasi salah. Ketik HAPUS (huruf besar) untuk menghapus seluruh laporan.');
        } else {
            $n = deleteReports($pdo, null);
            logActivity($adminId, 'delete_reports_all', "Menghapus SELURUH laporan kegiatan ($n data)");
            setFlash('success', "Seluruh laporan ($n data) berhasil dihapus.");
            $back = 'admin/reports.php';
        }
    }
    redirect($back);
}

$view = $_GET['view'] ?? '';

// ---------- Tab "Statistik" = daftar kegiatan untuk laporan statistik (fitur lama) ----------
if ($view === 'stats') {
    $events = $pdo->query(
        "SELECT e.*, c.name AS category_name,
         (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id AND ep.status = 'approved') AS participant_count
         FROM events e JOIN categories c ON c.id = e.category_id ORDER BY e.start_date DESC"
    )->fetchAll();

    $pageTitle = 'Statistik Kegiatan';
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/navbar.php';
    ?>
    <div class="app-layout">
      <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-content">
        <h4 class="fw-bold mb-3">Laporan Kegiatan</h4>
        <ul class="nav nav-tabs mb-3">
          <li class="nav-item"><a class="nav-link" href="reports.php">Daftar Laporan</a></li>
          <li class="nav-item"><a class="nav-link active" href="reports.php?view=stats">Statistik per Kegiatan</a></li>
        </ul>
        <p class="text-muted">Pilih kegiatan untuk melihat statistik lengkap (rata-rata nilai, tingkat penyelesaian, kepuasan).</p>
        <div class="card">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light"><tr><th>Kegiatan</th><th>Kategori</th><th>Peserta</th><th class="text-end">Aksi</th></tr></thead>
              <tbody>
                <?php if (empty($events)): ?><tr><td colspan="4" class="text-center text-muted py-4">Belum ada kegiatan.</td></tr><?php endif; ?>
                <?php foreach ($events as $ev): ?>
                  <tr>
                    <td class="fw-semibold"><?= e($ev['title']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= (int) $ev['participant_count'] ?></td>
                    <td class="text-end"><a href="reports.php?event_id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-graph-up"></i> Lihat Statistik</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    <?php
    exit;
}

// ---------- Daftar laporan + filter ----------
$search = trim($_GET['q'] ?? '');
$evFilter = (int) ($_GET['ev'] ?? 0);
$catFilter = (int) ($_GET['category'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : '';
if (!in_array($statusFilter, ['draft', 'final'], true)) $statusFilter = '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(r.title LIKE ? OR r.summary LIKE ? OR r.location LIKE ? OR r.content LIKE ?)';
    $params = array_merge($params, array_fill(0, 4, "%$search%"));
}
if ($evFilter > 0) { $where[] = 'r.event_id = ?'; $params[] = $evFilter; }
if ($catFilter > 0) { $where[] = 'e.category_id = ?'; $params[] = $catFilter; }
if ($statusFilter !== '') { $where[] = 'r.status = ?'; $params[] = $statusFilter; }
if ($dateFrom !== '') { $where[] = 'r.report_date >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'r.report_date <= ?'; $params[] = $dateTo; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$fromSql = 'FROM activity_reports r LEFT JOIN events e ON e.id = r.event_id';

$perPage = getPerPage();
$page = max(1, (int) ($_GET['page'] ?? 1));
$cnt = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
$cnt->execute($params);
$totalRows = (int) $cnt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT r.*, e.title AS event_title $fromSql $whereSql ORDER BY r.report_date DESC, r.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$reports = $stmt->fetchAll();
$totalAll = (int) $pdo->query('SELECT COUNT(*) FROM activity_reports')->fetchColumn();

$eventOptions = $pdo->query('SELECT id, title FROM events ORDER BY start_date DESC')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$hasFilter = ($search !== '' || $evFilter || $catFilter || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '');
$backField = '<input type="hidden" name="back" value="' . e(queryString()) . '">';

$pageTitle = 'Laporan Kegiatan';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="fw-bold mb-0">Laporan Kegiatan</h4>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($totalAll > 0): ?>
          <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal"><i class="bi bi-trash3 me-1"></i>Hapus Semua</button>
        <?php endif; ?>
        <a href="report_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Tambah Laporan</a>
      </div>
    </div>

    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link active" href="reports.php">Daftar Laporan</a></li>
      <li class="nav-item"><a class="nav-link" href="reports.php?view=stats">Statistik per Kegiatan</a></li>
    </ul>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Cari judul / ringkasan / lokasi</label>
            <input type="text" name="q" class="form-control" placeholder="Cari..." value="<?= e($search) ?>">
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small mb-1">Kegiatan</label>
            <select name="ev" class="form-select">
              <option value="">Semua kegiatan</option>
              <?php foreach ($eventOptions as $ev): ?><option value="<?= $ev['id'] ?>" <?= $evFilter === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small mb-1">Kategori</label>
            <select name="category" class="form-select">
              <option value="">Semua kategori</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $catFilter === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select">
              <option value="">Semua</option>
              <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
              <option value="final" <?= $statusFilter === 'final' ? 'selected' : '' ?>>Final</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Tanggal dari</label><input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Sampai</label><input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>"></div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tampilkan</label>
            <select name="per_page" class="form-select" onchange="this.form.submit()">
              <?php foreach (perPageOptions() as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per halaman</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan Filter</button>
            <?php if ($hasFilter): ?><a href="reports.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <form method="post" id="bulkForm" onsubmit="return confirm('Hapus laporan yang dipilih? Tindakan ini tidak dapat dibatalkan.');">
      <?= csrfField() ?><?= $backField ?>
      <input type="hidden" name="action" value="delete_selected">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <span class="small text-muted"><?= $totalRows > 0 ? 'Menampilkan ' . ($offset + 1) . '–' . min($offset + $perPage, $totalRows) . " dari $totalRows laporan" : 'Tidak ada data' ?></span>
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Hapus Terpilih</button>
      </div>
    </form>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:36px;"><input type="checkbox" class="form-check-input" id="checkAll" title="Pilih semua di halaman ini"></th>
              <th>Judul</th><th>Kegiatan</th><th>Tanggal</th><th>Peserta</th><th>Status</th><th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reports)): ?>
              <tr><td colspan="7" class="text-center text-muted py-4"><?= $hasFilter ? 'Tidak ada laporan yang sesuai filter.' : 'Belum ada laporan. Klik "Tambah Laporan" untuk membuat.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($reports as $r): ?>
              <tr>
                <td><input type="checkbox" class="form-check-input row-check" name="ids[]" value="<?= $r['id'] ?>" form="bulkForm"></td>
                <td>
                  <a href="report_view.php?id=<?= $r['id'] ?>" class="fw-semibold text-decoration-none"><?= e($r['title']) ?></a>
                  <?php if ($r['attachment']): ?><i class="bi bi-paperclip text-muted ms-1" title="Ada lampiran"></i><?php endif; ?>
                </td>
                <td class="small"><?= e($r['event_title'] ?: '-') ?></td>
                <td class="small"><?= formatTanggal($r['report_date']) ?></td>
                <td><?= $r['participant_count'] !== null ? (int) $r['participant_count'] : '-' ?></td>
                <td><span class="badge <?= $r['status'] === 'final' ? 'bg-success' : 'bg-secondary' ?>"><?= $r['status'] === 'final' ? 'Final' : 'Draft' ?></span></td>
                <td class="text-end text-nowrap">
                  <a href="report_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Lihat"><i class="bi bi-eye"></i></a>
                  <a href="report_form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus laporan ini?');">
                    <?= csrfField() ?><?= $backField ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button>
                  </form>
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

<div class="modal fade" id="deleteAllModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete_all">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title text-danger">Hapus Semua Laporan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger small">Seluruh <strong><?= $totalAll ?></strong> laporan kegiatan beserta lampirannya akan dihapus permanen (bukan hanya yang tampil pada filter ini).</div>
          <label class="form-label">Ketik <strong>HAPUS</strong> untuk melanjutkan</label>
          <input type="text" name="confirm_text" class="form-control" autocomplete="off" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-danger">Hapus Semua</button></div>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('checkAll')?.addEventListener('change', function () {
  document.querySelectorAll('.row-check').forEach(function (c) { c.checked = this.checked; }, this);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
