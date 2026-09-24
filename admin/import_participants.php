<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

/**
 * Parser CSV sederhana dan stabil tanpa dependency Composer.
 * Mendukung file .csv (termasuk hasil export dari Excel/Google Sheets).
 * Kolom wajib: nama, email, username, password
 * Kolom opsional: institusi, jabatan, no_hp
 */
function parseCsvFile(string $path): array
{
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        // Deteksi delimiter otomatis (koma atau titik koma)
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) return [];
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) === 1 && trim($data[0]) === '') continue; // skip empty lines
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim($data[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);
    }
    return $rows;
}

$step = $_POST['step'] ?? 'upload';
$previewRows = [];
$validCount = 0;
$errorCount = 0;

// STEP 1: Upload & preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview') {
    verifyCsrf();

    if (empty($_FILES['file']['name'])) {
        setFlash('danger', 'Silakan pilih file CSV terlebih dahulu.');
        redirect('admin/import_participants.php' . ($eventId ? '?event_id=' . $eventId : ''));
    }

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        setFlash('danger', 'Format file harus .csv. Jika file Anda .xlsx, silakan "Save As" ke CSV terlebih dahulu di Excel/Google Sheets (lihat catatan di bawah).');
        redirect('admin/import_participants.php' . ($eventId ? '?event_id=' . $eventId : ''));
    }
    if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
        setFlash('danger', 'Ukuran file melebihi batas maksimum (5MB).');
        redirect('admin/import_participants.php' . ($eventId ? '?event_id=' . $eventId : ''));
    }

    $tmpPath = UPLOAD_PATH . '/documents/import_' . time() . '_' . bin2hex(random_bytes(4)) . '.csv';
    move_uploaded_file($_FILES['file']['tmp_name'], $tmpPath);

    $rawRows = parseCsvFile($tmpPath);
    $existingEmails = $pdo->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $existingUsernames = $pdo->query('SELECT username FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $seenEmails = [];
    $seenUsernames = [];

    foreach ($rawRows as $row) {
        $errors = [];
        $nama = $row['nama'] ?? '';
        $email = $row['email'] ?? '';
        $username = $row['username'] ?? '';
        $password = $row['password'] ?? '';

        if ($nama === '') $errors[] = 'Nama kosong';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid';
        if ($username === '') $errors[] = 'Username kosong';
        if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter';

        if ($email && (in_array($email, $existingEmails, true) || isset($seenEmails[$email]))) {
            $errors[] = 'Email duplikat';
        }
        if ($username && (in_array($username, $existingUsernames, true) || isset($seenUsernames[$username]))) {
            $errors[] = 'Username duplikat';
        }

        if (empty($errors)) {
            $seenEmails[$email] = true;
            $seenUsernames[$username] = true;
            $validCount++;
        } else {
            $errorCount++;
        }

        $previewRows[] = [
            'nama' => $nama, 'email' => $email, 'username' => $username, 'password' => $password,
            'institusi' => $row['institusi'] ?? '', 'jabatan' => $row['jabatan'] ?? '', 'no_hp' => $row['no_hp'] ?? '',
            'errors' => $errors,
        ];
    }

    $_SESSION['import_preview'] = $previewRows;
    $_SESSION['import_tmp_path'] = $tmpPath;
    $step = 'review';
}

// STEP 2: Confirm import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    verifyCsrf();
    $rows = $_SESSION['import_preview'] ?? [];
    $imported = 0;

    $ins = $pdo->prepare(
        'INSERT INTO users (role_id, full_name, email, username, password, institution, position, phone, status)
         VALUES (2, ?, ?, ?, ?, ?, ?, ?, "active")'
    );
    $enroll = $pdo->prepare('INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?, ?)');

    foreach ($rows as $row) {
        if (!empty($row['errors'])) continue;
        $hash = password_hash($row['password'], PASSWORD_BCRYPT);
        try {
            $ins->execute([$row['nama'], $row['email'], $row['username'], $hash, $row['institusi'], $row['jabatan'], $row['no_hp']]);
            $newUserId = (int) $pdo->lastInsertId();
            if ($eventId) {
                $enroll->execute([$eventId, $newUserId]);
            }
            $imported++;
        } catch (PDOException $e) {
            // skip on race-condition duplicate
        }
    }

    if (!empty($_SESSION['import_tmp_path']) && file_exists($_SESSION['import_tmp_path'])) {
        @unlink($_SESSION['import_tmp_path']);
    }
    unset($_SESSION['import_preview'], $_SESSION['import_tmp_path']);

    logActivity((int) $_SESSION['user_id'], 'import_participants', "Import $imported peserta via Excel/CSV");
    setFlash('success', "$imported peserta berhasil diimpor.");
    redirect('admin/participants.php' . ($eventId ? '?event_id=' . $eventId : ''));
}

if ($step === 'review') {
    $previewRows = $_SESSION['import_preview'] ?? [];
    $validCount = count(array_filter($previewRows, fn($r) => empty($r['errors'])));
    $errorCount = count($previewRows) - $validCount;
}

$pageTitle = 'Import Peserta Excel';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$flash = getFlash();
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h4 class="fw-bold mb-0">Import Peserta dari Excel/CSV</h4>
      <a href="participants.php<?= $eventId ? '?event_id=' . $eventId : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($step === 'review' && !empty($previewRows)): ?>
      <div class="alert alert-info">
        Ditemukan <strong><?= count($previewRows) ?></strong> baris data.
        <span class="text-success">✓ <?= $validCount ?> valid</span> &middot;
        <span class="text-danger">✗ <?= $errorCount ?> error</span>
      </div>
      <div class="card mb-3">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Nama</th><th>Email</th><th>Username</th><th>Institusi</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($previewRows as $r): ?>
                <tr class="<?= empty($r['errors']) ? '' : 'table-danger' ?>">
                  <td><?= e($r['nama']) ?></td>
                  <td><?= e($r['email']) ?></td>
                  <td><?= e($r['username']) ?></td>
                  <td><?= e($r['institusi']) ?></td>
                  <td class="small">
                    <?php if (empty($r['errors'])): ?>
                      <span class="text-success"><i class="bi bi-check-circle"></i> Valid</span>
                    <?php else: ?>
                      <span class="text-danger"><i class="bi bi-x-circle"></i> <?= e(implode(', ', $r['errors'])) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="step" value="confirm">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <button type="submit" class="btn btn-success" <?= $validCount === 0 ? 'disabled' : '' ?>>
          <i class="bi bi-check-lg me-1"></i>Konfirmasi Import (<?= $validCount ?> data valid<?= $eventId ? ', otomatis didaftarkan ke kegiatan ini' : '' ?>)
        </button>
        <a href="import_participants.php<?= $eventId ? '?event_id=' . $eventId : '' ?>" class="btn btn-outline-secondary">Batal / Upload Ulang</a>
      </form>

    <?php else: ?>
      <div class="card">
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="preview">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <div class="mb-3">
              <label class="form-label">Pilih File CSV</label>
              <input type="file" name="file" class="form-control" accept=".csv" required>
              <div class="form-text">
                Kolom wajib: <code>nama, email, username, password</code>. Kolom opsional: <code>institusi, jabatan, no_hp</code>.<br>
                Jika file Anda berformat <code>.xlsx</code>, buka di Excel/Google Sheets lalu <strong>File → Download/Save As → CSV (Comma delimited)</strong> terlebih dahulu.
              </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload &amp; Pratinjau</button>
            <a href="<?= BASE_URL ?>/assets/template_import_peserta.csv" class="btn btn-outline-secondary" download><i class="bi bi-download me-1"></i>Download Template CSV</a>
          </form>
        </div>
      </div>

      <div class="alert alert-secondary mt-3 small">
        <strong>Catatan implementasi:</strong> untuk mendukung upload file <code>.xlsx</code> secara langsung tanpa konversi manual,
        Anda dapat memasang library <a href="https://github.com/PHPOffice/PhpSpreadsheet" target="_blank">PhpSpreadsheet</a> via Composer:
        <pre class="mt-2 mb-0 bg-white p-2 rounded border">composer require phpoffice/phpspreadsheet</pre>
        Setelah folder <code>vendor/</code> tersedia, ganti pemanggilan <code>parseCsvFile()</code> pada <code>import_participants.php</code>
        dengan pembacaan menggunakan <code>PhpOffice\PhpSpreadsheet\IOFactory::load()</code>. Versi CSV saat ini dipilih sebagai
        <em>fallback</em> yang stabil dan tidak memerlukan dependency tambahan, sesuai prinsip kesederhanaan aplikasi ini.
      </div>
    <?php endif; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
