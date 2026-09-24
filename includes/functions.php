<?php
/**
 * Fungsi-fungsi bantuan (reusable) — SiKedan
 */

/** Escape output untuk mencegah XSS */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect ke URL tertentu lalu hentikan eksekusi */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

/** Cek apakah user sudah login */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/** Ambil role user yang sedang login */
function currentRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

/** Wajibkan login, redirect ke halaman login jika belum */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect('auth/login.php');
    }
}

/** Wajibkan role tertentu, tolak akses (403) jika tidak sesuai */
function requireRole(string $role): void
{
    requireLogin();
    if (currentRole() !== $role) {
        http_response_code(403);
        require_once ROOT_PATH . '/includes/error_403.php';
        exit;
    }
}

/** Generate & simpan CSRF token pada session */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Cetak hidden input CSRF token untuk form */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Validasi CSRF token dari request POST */
function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Permintaan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
    }
}

/** Ambil satu baris pengaturan (settings) berdasarkan key */
function getSetting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $pdo = getDBConnection();
        $cache = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/** Format tanggal Indonesia, contoh: 20 Agustus 2026 */
function formatTanggal(?string $date): string
{
    if (empty($date)) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $ts = strtotime($date);
    return date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Catat aktivitas ke activity_logs */
function logActivity(?int $userId, string $action, string $description = ''): void
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/** Slugify string sederhana */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/** Flash message sederhana menggunakan session */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Hitung ulang & simpan progress peserta pada sebuah kegiatan, menggunakan
 * sistem BOBOT PERSEN 2 tingkat (bukan sekadar hitung rata jumlah item):
 *
 * 1) Di dalam satu hari/pertemuan: setiap materi/quiz/tugas punya bobot
 *    (weight_percent) yang idealnya dijumlahkan 100% oleh admin. Progress
 *    hari itu = total bobot item yang sudah diselesaikan peserta.
 * 2) Di tingkat kegiatan: setiap hari punya bobot (weight_percent) yang
 *    idealnya dijumlahkan 100% oleh admin. Progress kegiatan = jumlah
 *    (bobot hari x progress hari itu).
 *
 * FALLBACK: jika admin belum mengisi bobot (semua weight_percent = 0 pada
 * suatu hari, atau semua bobot hari = 0 pada kegiatan), sistem otomatis
 * membagi rata (100 / jumlah item, atau 100 / jumlah hari) agar progress
 * tetap berjalan normal seperti sebelumnya tanpa perlu admin mengisi bobot
 * secara manual jika tidak diinginkan.
 */
function updateParticipantProgress(PDO $pdo, int $userId, int $eventId): void
{
    $dayStmt = $pdo->prepare('SELECT id, weight_percent FROM event_days WHERE event_id = ? ORDER BY day_number ASC');
    $dayStmt->execute([$eventId]);
    $days = $dayStmt->fetchAll();

    if (empty($days)) {
        $upd = $pdo->prepare('UPDATE event_participants SET progress = 0 WHERE user_id = ? AND event_id = ?');
        $upd->execute([$userId, $eventId]);
        return;
    }

    $totalDayWeight = array_sum(array_column($days, 'weight_percent'));
    $useEqualDaySplit = $totalDayWeight <= 0;
    $equalDayWeight = 100 / count($days);

    $overallProgress = 0;

    foreach ($days as $day) {
        $dayId = (int) $day['id'];
        $dayWeight = $useEqualDaySplit ? $equalDayWeight : (float) $day['weight_percent'];

        // Ambil semua item (materi/quiz/tugas) yang berlaku pada hari ini beserta bobot & status selesainya
        $items = [];

        $mStmt = $pdo->prepare("SELECT id, weight_percent FROM materials WHERE event_day_id = ? AND status = 'published'");
        $mStmt->execute([$dayId]);
        foreach ($mStmt->fetchAll() as $m) {
            $doneStmt = $pdo->prepare('SELECT is_completed FROM material_progress WHERE material_id = ? AND user_id = ?');
            $doneStmt->execute([$m['id'], $userId]);
            $items[] = ['weight' => (float) $m['weight_percent'], 'done' => (bool) $doneStmt->fetchColumn()];
        }

        try {
            $vStmt = $pdo->prepare('SELECT id, weight_percent FROM videos WHERE event_day_id = ?');
            $vStmt->execute([$dayId]);
            $videosInDay = $vStmt->fetchAll();
        } catch (PDOException $e) {
            // Kolom weight_percent pada tabel videos belum ada (migrasi v4 belum dijalankan).
            // Lewati video dari perhitungan bobot agar progress tetap berjalan normal untuk item lain.
            $videosInDay = [];
        }
        foreach ($videosInDay as $v) {
            try {
                $doneStmt = $pdo->prepare('SELECT is_watched FROM video_progress WHERE video_id = ? AND user_id = ?');
                $doneStmt->execute([$v['id'], $userId]);
                $isWatched = (bool) $doneStmt->fetchColumn();
            } catch (PDOException $e) {
                continue; // tabel video_progress belum ada
            }
            $items[] = ['weight' => (float) $v['weight_percent'], 'done' => $isWatched];
        }

        $qStmt = $pdo->prepare("SELECT id, weight_percent FROM quizzes WHERE event_day_id = ? AND status = 'active'");
        $qStmt->execute([$dayId]);
        foreach ($qStmt->fetchAll() as $q) {
            $doneStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?');
            $doneStmt->execute([$q['id'], $userId]);
            $items[] = ['weight' => (float) $q['weight_percent'], 'done' => (int) $doneStmt->fetchColumn() > 0];
        }

        $aStmt = $pdo->prepare("SELECT id, weight_percent FROM assignments WHERE event_day_id = ? AND status != 'draft'");
        $aStmt->execute([$dayId]);
        foreach ($aStmt->fetchAll() as $a) {
            $doneStmt = $pdo->prepare('SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = ? AND user_id = ?');
            $doneStmt->execute([$a['id'], $userId]);
            $items[] = ['weight' => (float) $a['weight_percent'], 'done' => (int) $doneStmt->fetchColumn() > 0];
        }

        if (empty($items)) {
            continue; // hari tanpa item apapun tidak menyumbang progress
        }

        $totalItemWeight = array_sum(array_column($items, 'weight'));
        $useEqualItemSplit = $totalItemWeight <= 0;
        $equalItemWeight = 100 / count($items);

        $dayProgress = 0;
        foreach ($items as $item) {
            if (!$item['done']) continue;
            $dayProgress += $useEqualItemSplit ? $equalItemWeight : $item['weight'];
        }
        // Jaga-jaga jika total bobot item diisi admin tidak persis 100 (misal 120% atau 80%),
        // dinormalisasi agar progress hari tetap dalam rentang 0-100.
        if (!$useEqualItemSplit && $totalItemWeight > 0) {
            $dayProgress = ($dayProgress / $totalItemWeight) * 100;
        }
        $dayProgress = min(100, max(0, $dayProgress));

        $overallProgress += ($dayWeight / 100) * $dayProgress;
    }

    // Normalisasi jika total bobot hari yang diisi admin tidak persis 100%
    if (!$useEqualDaySplit && $totalDayWeight > 0 && abs($totalDayWeight - 100) > 0.01) {
        $overallProgress = $overallProgress * (100 / $totalDayWeight);
    }

    $progress = round(min(100, max(0, $overallProgress)), 2);

    $upd = $pdo->prepare('UPDATE event_participants SET progress = ? WHERE user_id = ? AND event_id = ?');
    $upd->execute([$progress, $userId, $eventId]);
}


/* =========================================================
 * Helper tambahan: penomoran hari, persetujuan peserta,
 * pagination, dan upload file
 * ========================================================= */

/**
 * Susun ulang nomor hari sebuah kegiatan menjadi 1, 2, 3, ... berurutan.
 * Dipanggil setelah hari dihapus / sebelum hari baru ditambahkan, sehingga
 * nomor hari selalu dinamis. Contoh: Hari 1,2,3 -> Hari 1 & 2 dihapus ->
 * sisa hari menjadi Hari 1, dan hari baru otomatis menjadi Hari 2.
 * Hanya mengubah nomor urut; isi hari (materi/video/quiz/tugas) tidak berubah.
 */
function renumberEventDays(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare('SELECT id, day_number, sort_order FROM event_days WHERE event_id = ? ORDER BY day_number ASC, id ASC');
    $stmt->execute([$eventId]);
    $upd = $pdo->prepare('UPDATE event_days SET day_number = ?, sort_order = ? WHERE id = ?');
    $no = 0;
    foreach ($stmt->fetchAll() as $row) {
        $no++;
        if ((int) $row['day_number'] !== $no || (int) $row['sort_order'] !== $no) {
            $upd->execute([$no, $no, $row['id']]);
        }
    }
}

/** Apakah peserta sudah DISETUJUI pada kegiatan ini (berhak mengakses materi, dsb.) */
function isApprovedParticipant(PDO $pdo, int $userId, int $eventId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE user_id = ? AND event_id = ? AND status = 'approved'");
    $stmt->execute([$userId, $eventId]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Jumlah data per halaman yang diizinkan */
function perPageOptions(): array
{
    return [10, 25, 50, 100];
}

/** Ambil pilihan "jumlah data per halaman" dari query string (default 10) */
function getPerPage(): int
{
    $pp = (int) ($_GET['per_page'] ?? 10);
    return in_array($pp, perPageOptions(), true) ? $pp : 10;
}

/** Bangun query string dari $_GET saat ini, dengan penggantian/penghapusan parameter */
function queryString(array $override = []): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }
    return http_build_query($params);
}

/** Cetak navigasi halaman yang ringkas (mempertahankan semua filter aktif) */
function renderPagination(int $page, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }
    $pages = array_unique(array_filter(
        array_merge([1, $totalPages], range(max(1, $page - 2), min($totalPages, $page + 2))),
        fn($p) => $p >= 1 && $p <= $totalPages
    ));
    sort($pages);
    echo '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">';
    $prev = 0;
    foreach ($pages as $p) {
        if ($prev && $p - $prev > 1) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $active = $p === $page ? ' active' : '';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="?' . e(queryString(['page' => $p])) . '">' . $p . '</a></li>';
        $prev = $p;
    }
    echo '</ul></nav>';
}

/**
 * Upload file ke uploads/<subfolder>/ dengan validasi ekstensi & ukuran.
 * Mengembalikan path relatif (mis. 'certificates/abc.pdf') atau string berisi pesan error
 * dalam array ['error' => '...'].
 */
function saveUploadedFile(array $file, string $subfolder, array $allowedExt, string $prefix)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'File gagal diunggah. Silakan coba lagi.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['error' => 'Format file tidak diizinkan. Gunakan: ' . strtoupper(implode(', ', $allowedExt)) . '.'];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['error' => 'Ukuran file melebihi batas maksimum ' . round(MAX_UPLOAD_SIZE / 1048576) . ' MB.'];
    }
    $dir = UPLOAD_PATH . '/' . $subfolder;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return ['error' => 'File gagal disimpan di server.'];
    }
    return $subfolder . '/' . $filename;
}

/** Hapus file upload (path relatif dari folder uploads). Aman terhadap path traversal. */
function deleteUploadedFile(?string $relPath): void
{
    if (!$relPath || strpos($relPath, '..') !== false) {
        return;
    }
    $full = UPLOAD_PATH . '/' . ltrim($relPath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}
