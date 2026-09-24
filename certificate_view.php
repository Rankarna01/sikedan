<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pdo = getDBConnection();
$certId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT c.*, e.title AS event_title, e.start_date, e.end_date, u.full_name, u.id AS owner_id
     FROM certificates c
     JOIN events e ON e.id = c.event_id
     JOIN users u ON u.id = c.user_id
     WHERE c.id = ?"
);
$stmt->execute([$certId]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    die('Sertifikat tidak ditemukan.');
}

// Only admin or the certificate owner can view
if (currentRole() !== 'admin' && (int) $_SESSION['user_id'] !== (int) $cert['owner_id']) {
    http_response_code(403);
    require_once __DIR__ . '/includes/error_403.php';
    exit;
}

// Sertifikat hasil upload manual admin: tampilkan file yang diunggah
if (($cert['source'] ?? 'auto') === 'manual' && !empty($cert['file_path'])) {
    header('Location: ' . UPLOAD_URL . '/' . $cert['file_path']);
    exit;
}

$lecturerName = getSetting('lecturer_name', 'Nama Dosen');
$institution = getSetting('institution', '');
$verifyUrl = BASE_URL . '/verify.php?code=' . $cert['verification_code'];
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . urlencode($verifyUrl);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Sertifikat - <?= e($cert['full_name']) ?></title>
<style>
  @page { size: A4 landscape; margin: 0; }
  body { font-family: 'Georgia', serif; margin: 0; background: #f1f5f9; }
  .certificate {
    width: 1000px; height: 700px; margin: 30px auto; background: #fff;
    border: 12px solid #2563EB; padding: 50px 70px; box-sizing: border-box;
    position: relative; text-align: center;
  }
  .certificate::before {
    content: ''; position: absolute; inset: 16px; border: 2px solid #0F172A;
  }
  .cert-header { margin-top: 20px; }
  .cert-header h3 { color:#0F172A; letter-spacing: 3px; margin: 0; font-size: 16px; text-transform: uppercase; }
  .cert-title { font-size: 42px; color: #2563EB; font-weight: bold; margin: 20px 0 5px; }
  .cert-sub { font-size: 15px; color: #475569; margin-bottom: 30px; }
  .cert-name { font-size: 34px; color: #0F172A; font-weight: bold; margin: 20px 0; border-bottom: 2px solid #2563EB; display: inline-block; padding-bottom: 8px; }
  .cert-event { font-size: 20px; color: #1E293B; margin: 15px 0; font-style: italic; }
  .cert-date { font-size: 14px; color: #64748B; margin-bottom: 30px; }
  .cert-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; padding: 0 30px; }
  .cert-signature { text-align: center; }
  .cert-signature .line { border-top: 1px solid #333; width: 180px; margin: 40px auto 6px; }
  .cert-qr img { width: 90px; height: 90px; }
  .cert-number { font-size: 11px; color: #94A3B8; margin-top: 10px; }
  .no-print { text-align:center; margin: 20px; }
  @media print { .no-print { display: none; } body { background: #fff; } .certificate { margin: 0; box-shadow:none; } }
  @media (max-width: 1040px) {
    .certificate { width: 96vw; height: auto; min-height: 560px; padding: 30px 20px; transform: none; }
    .cert-title { font-size: 28px; }
    .cert-name { font-size: 22px; }
    .cert-event { font-size: 16px; }
    .cert-footer { flex-direction: column; gap: 20px; align-items: center; }
  }
</style>
</head>
<body>
  <div class="no-print">
    <button onclick="window.print()" style="padding:10px 20px;font-size:14px;cursor:pointer;">🖨️ Cetak / Simpan sebagai PDF</button>
  </div>
  <div class="certificate">
    <div class="cert-header"><h3><?= e(getSetting('app_name', 'SiKedan')) ?> &middot; <?= e($institution) ?></h3></div>
    <div class="cert-title">SERTIFIKAT</div>
    <div class="cert-sub">Diberikan kepada</div>
    <div class="cert-name"><?= e($cert['full_name']) ?></div>
    <div class="cert-sub">atas partisipasinya dalam kegiatan</div>
    <div class="cert-event">"<?= e($cert['event_title']) ?>"</div>
    <div class="cert-date"><?= formatTanggal($cert['start_date']) ?> &ndash; <?= formatTanggal($cert['end_date']) ?></div>

    <div class="cert-footer">
      <div class="cert-qr">
        <img src="<?= e($qrImageUrl) ?>" alt="QR Verifikasi">
        <div class="cert-number">No: <?= e($cert['certificate_number']) ?></div>
      </div>
      <div class="cert-signature">
        <div class="line"></div>
        <strong><?= e($lecturerName) ?></strong><br>
        <span style="font-size:13px;color:#64748B;">Penyelenggara</span>
      </div>
    </div>
  </div>
</body>
</html>
