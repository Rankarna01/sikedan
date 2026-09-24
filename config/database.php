<?php
/**
 * Konfigurasi Database — SiKedan
 * Otomatis memisahkan kredensial untuk Localhost dan Hosting (Deployment).
 */

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostName = strtolower(explode(':', $httpHost)[0]);
$isLocal  = in_array($hostName, ['localhost', '127.0.0.1', '::1'])
    || str_ends_with($hostName, '.test')
    || str_ends_with($hostName, '.local');

if ($isLocal) {
    // ==========================================
    // 1. PENGATURAN LOCALHOST (XAMPP / Laragon)
    // ==========================================
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('DB_NAME') ?: 'sikedan');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    define('DB_CHARSET', 'utf8mb4');
} else {
    // ==========================================
    // 2. PENGATURAN HOSTING (Hostinger / cPanel)
    // ==========================================
    // Silakan sesuaikan 4 baris di bawah dengan info Database MySQL di Hostinger Anda:
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('DB_NAME') ?: 'u117434194_sikedan'); // Ganti dengan Nama Database Hostinger Anda
    define('DB_USER', getenv('DB_USER') ?: 'u117434194_sikedan'); // Ganti dengan Username Database Hostinger Anda
    define('DB_PASS', getenv('DB_PASS') ?: 'PASSWORD_DATABASE_HOSTINGER'); // Ganti dengan Password Database Hostinger Anda
    define('DB_CHARSET', 'utf8mb4');
}

function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            http_response_code(500);

            if (defined('APP_ENV') && APP_ENV === 'development') {
                die('<h3>Koneksi Database Gagal (Localhost)</h3><p>' . htmlspecialchars($e->getMessage()) . '</p>');
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Database Error - SiKedan</title>';
                echo '<style>body{font-family:sans-serif;padding:30px;line-height:1.6;background:#f8f9fa;color:#333}.box{background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:700px;margin:auto;border-left:5px solid #ffc107}code{background:#e9ecef;padding:2px 6px;border-radius:4px;color:#d63384}</style></head><body>';
                echo '<div class="box">';
                echo '<h2 style="color:#d39e00;margin-top:0">⚠️ Koneksi Database Hosting Belum Sesuai</h2>';
                echo '<p>Aplikasi belum bisa terhubung ke database MySQL di hosting.</p>';
                echo '<p><strong>Detail Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '<h3>Langkah Penyelesaian di Hostinger:</h3>';
                echo '<ol>';
                echo '<li>Buka file <code>config/database.php</code> di File Manager Hostinger.</li>';
                echo '<li>Pada bagian <strong>PENGATURAN HOSTING</strong>, isi <code>DB_NAME</code>, <code>DB_USER</code>, dan <code>DB_PASS</code> sesuai database yang Anda buat di kontrol panel Hostinger (menu <em>Databases &rarr; Management</em>).</li>';
                echo '<li>Pastikan Anda sudah mengimpor file <code>database.sql</code> ke database hosting lewat <strong>phpMyAdmin</strong>.</li>';
                echo '</ol>';
                echo '</div></body></html>';
                exit;
            }
        }
    }

    return $pdo;
}
