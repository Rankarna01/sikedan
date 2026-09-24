<?php
/**
 * Konfigurasi Aplikasi — SiKedan
 */

// Path absolut ke root aplikasi
define('ROOT_PATH', dirname(__DIR__));

// Domain & URL Hosting Anda
define('HOSTING_URL', 'http://sikedan.site');

// Deteksi Host & Environment
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostName = strtolower(explode(':', $httpHost)[0]);
$isLocal  = in_array($hostName, ['localhost', '127.0.0.1', '::1'])
    || str_ends_with($hostName, '.test')
    || str_ends_with($hostName, '.local');

// Environment: 'development' (local) atau 'production' (hosting)
define('APP_ENV', $isLocal ? 'development' : 'production');

if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
}

// Deteksi Protokol (HTTP / HTTPS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$protocol = $isHttps ? 'https://' : 'http://';

// Deteksi subdirektori jika dijalankan di localhost
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
$appRoot = str_replace('\\', '/', realpath(ROOT_PATH) ?: ROOT_PATH);
$subDir  = '';
if (!empty($docRoot) && str_starts_with($appRoot, $docRoot)) {
    $subDir = substr($appRoot, strlen($docRoot));
}

// Penentuan BASE_URL:
// - Jika di Localhost: otomatis deteksi URL lokal (contoh: http://localhost/sikedan)
// - Jika di Hosting: otomatis gunakan protokol aktif + host yang diakses, atau default ke HOSTING_URL
if ($isLocal) {
    $localUrl = rtrim($protocol . $httpHost . $subDir, '/');
    define('BASE_URL', getenv('APP_URL') ?: ($localUrl ?: 'http://localhost/sikedan'));
} else {
    $hostingBase = !empty($httpHost) ? rtrim($protocol . $httpHost . $subDir, '/') : HOSTING_URL;
    define('BASE_URL', getenv('APP_URL') ?: $hostingBase);
}

// Path upload
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

// Batas ukuran upload (byte) — default 5MB
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);

// Ekstensi file yang diizinkan untuk tugas
define('ALLOWED_ASSIGNMENT_EXT', ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip']);

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
if ($isHttps) {
    ini_set('session.cookie_secure', 1);
}

session_name('sikedan_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

// Muat konfigurasi database
require_once __DIR__ . '/database.php';

// Validasi keberadaan file functions.php
$functionsPath = ROOT_PATH . '/includes/functions.php';
if (!file_exists($functionsPath)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>File Missing - SiKedan</title>';
    echo '<style>body{font-family:sans-serif;padding:30px;line-height:1.6;background:#f8f9fa;color:#333}.box{background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:700px;margin:auto;border-left:5px solid #dc3545}code{background:#e9ecef;padding:2px 6px;border-radius:4px;color:#d63384}</style></head><body>';
    echo '<div class="box">';
    echo '<h2 style="color:#dc3545;margin-top:0">⚠️ File <code>includes/functions.php</code> Tidak Ditemukan!</h2>';
    echo '<p>Sistem mencari file di path berikut:</p>';
    echo '<p><code>' . htmlspecialchars($functionsPath) . '</code></p>';
    echo '<h3>Cara Mengatasi di Hosting (Hostinger / cPanel):</h3>';
    echo '<ol>';
    echo '<li>Buka <strong>File Manager</strong> di Hostinger Anda.</li>';
    echo '<li>Masuk ke folder <code>public_html</code>.</li>';
    echo '<li>Pastikan folder <strong><code>includes</code></strong> sudah ter-upload dan berada sejajar dengan file <code>index.php</code>.</li>';
    echo '<li>Pastikan di dalam folder <code>includes</code> terdapat file <strong><code>functions.php</code></strong>.</li>';
    echo '<li><strong>Perhatikan Huruf Besar/Kecil:</strong> Hosting Linux bersifat <em>case-sensitive</em>. Pastikan nama foldernya huruf kecil semua (<code>includes</code>, BUKAN <code>Includes</code>).</li>';
    echo '<li>Pastikan permission folder adalah <strong>755</strong> dan file <strong>644</strong>.</li>';
    echo '</ol>';
    echo '</div></body></html>';
    exit;
}

require_once $functionsPath;
