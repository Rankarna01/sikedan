<?php
require_once __DIR__ . '/config/config.php';

$pdo = getDBConnection();

$totalEvents = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status='active'")->fetchColumn();
$totalParticipants = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role_id=2")->fetchColumn();
$totalMaterials = (int) $pdo->query("SELECT COUNT(*) FROM materials")->fetchColumn();

$recentEvents = $pdo->query(
    "SELECT e.*, c.name AS category_name, c.slug AS category_slug FROM events e
     JOIN categories c ON c.id = e.category_id
     WHERE e.status = 'active'
     ORDER BY e.start_date DESC LIMIT 6"
)->fetchAll();

$pageTitle = 'Beranda';
require_once __DIR__ . '/includes/header.php';
?>
<nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background-color:var(--clr-dark); box-shadow:0 2px 12px rgba(0,0,0,.15);">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>"><i class="bi bi-mortarboard-fill"></i> <?= e(getSetting('app_name', 'SiKedan')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <li class="nav-item"><a class="nav-link text-white" href="#kegiatan">Kegiatan</a></li>
        <li class="nav-item"><a class="nav-link text-white" href="#tentang">Tentang</a></li>
        <?php if (isLoggedIn()): ?>
          <li class="nav-item">
            <a class="btn btn-primary btn-sm" href="<?= currentRole() === 'admin' ? 'admin/dashboard.php' : 'peserta/dashboard.php' ?>">Dashboard</a>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-primary btn-sm" href="auth/login.php">Masuk</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php
// Hero banner: teks judul/subjudul SELALU memakai narasi umum SiKedan
// (nama aplikasi + tagline dari Pengaturan) agar konsisten dan tidak
// berubah-ubah mengikuti deskripsi kegiatan yang panjang. Yang berganti
// hanya foto latar belakang, diambil dari (berurutan prioritas):
// 1) Banner mandiri aktif (admin/hero_banners.php)
// 2) Foto sampul kegiatan aktif terbaru
// 3) Foto default bawaan sistem
$customBanners = [];
try {
    $customBanners = $pdo->query(
        "SELECT title, subtitle AS description, image AS cover_image, link_url, image_position
         FROM hero_banners WHERE status = 'active' ORDER BY sort_order ASC LIMIT 5"
    )->fetchAll();
} catch (PDOException $e) {
    $customBanners = [];
}

$defaultHeroImages = [
    'https://images.unsplash.com/photo-1524178232363-1fb2b075b655?auto=format&fit=crop&w=1600&q=60',
    'https://images.unsplash.com/photo-1517486808906-6ca8b3f04846?auto=format&fit=crop&w=1600&q=60',
    'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1600&q=60',
];

if (!empty($customBanners)) {
    $heroSlides = $customBanners;
} else {
    $eventSlides = $pdo->query(
        "SELECT title, description, cover_image, cover_image_position AS image_position, NULL AS link_url
         FROM events WHERE status = 'active' AND cover_image IS NOT NULL ORDER BY start_date DESC LIMIT 5"
    )->fetchAll();
    $heroSlides = $eventSlides;
}

if (empty($heroSlides)) {
    $heroSlides = [['title' => null, 'description' => null, 'cover_image' => null, 'link_url' => null]];
}

$heroTitle = getSetting('app_name', 'SiKedan');
$heroTagline = getSetting('app_tagline', 'Sistem Integrasi Kegiatan Edukasi, Pengabdian, dan Penelitian');
$heroDesc = 'Platform pembelajaran dan kegiatan akademik untuk mendukung pendidikan, penelitian, dan pengabdian masyarakat.';

// Warna badge kategori kegiatan, agar tiap kategori mudah dibedakan sekilas
$categoryColors = [
    'pembelajaran' => 'primary', 'penelitian' => 'info', 'pengabdian-masyarakat' => 'success',
    'pelatihan-workshop' => 'warning', 'seminar' => 'danger',
];
function categoryColor(array $map, ?string $slug): string
{
    return $map[$slug] ?? 'secondary';
}
?>
<section class="hero-slider" style="--hero-overlay-opacity: <?= e(getSetting('hero_overlay_opacity', '0.5')) ?>;">
  <div id="heroCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5000">
    <div class="carousel-indicators">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="<?= $i ?>" class="<?= $i === 0 ? 'active' : '' ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Slide <?= $i + 1 ?>"></button>
      <?php endforeach; ?>
    </div>
    <div class="carousel-inner">
      <?php foreach ($heroSlides as $i => $slide):
        $bgImage = $slide['cover_image'] ? (UPLOAD_URL . '/' . $slide['cover_image']) : $defaultHeroImages[$i % count($defaultHeroImages)];
        $bgPosition = $slide['image_position'] ?? 'center';
        // Banner mandiri boleh override judul/subjudul jika admin mengisinya secara eksplisit;
        // jika kosong (termasuk saat sumbernya dari kegiatan), selalu jatuh ke narasi umum SiKedan
        // agar teks hero tetap ringkas dan konsisten (tidak mengikuti deskripsi kegiatan yang panjang).
        $displayTitle = !empty($customBanners) && !empty($slide['title']) ? $slide['title'] : $heroTitle;
        $displaySubtitle = !empty($customBanners) && !empty($slide['description']) ? $slide['description'] : $heroTagline;
        $slideLink = $slide['link_url'] ?? null;
      ?>
        <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
          <div class="hero-slide" style="background-image:url('<?= e($bgImage) ?>'); background-position: <?= e($bgPosition) ?>;">
            <div class="hero-overlay"></div>
            <div class="hero-particles"></div>
            <div class="hero-slide-content text-center animate-fade-up">
              <h1 class="display-5"><?= e($displayTitle) ?></h1>
              <p class="lead mb-4 opacity-90"><?= e($displaySubtitle) ?></p>
              <div class="d-flex gap-2 justify-content-center flex-wrap">
                <?php if ($slideLink): ?>
                  <a href="<?= e($slideLink) ?>" class="btn btn-primary px-4">Lihat Selengkapnya</a>
                <?php else: ?>
                  <a href="auth/login.php" class="btn btn-primary px-4">Masuk</a>
                  <a href="#kegiatan" class="btn btn-outline-light px-4">Lihat Kegiatan</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($heroSlides) > 1): ?>
      <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Sebelumnya</span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Berikutnya</span>
      </button>
    <?php endif; ?>
  </div>
</section>

<section class="py-6 bg-white">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="stat-gradient-card stat-grad-blue reveal-on-scroll">
          <div class="stat-icon-wrap"><i class="bi bi-calendar-event"></i></div>
          <h2 class="fw-bold counter" data-target="<?= $totalEvents ?>">0</h2>
          <p class="mb-0">Kegiatan Aktif</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-gradient-card stat-grad-green reveal-on-scroll" style="animation-delay:.1s;">
          <div class="stat-icon-wrap"><i class="bi bi-people"></i></div>
          <h2 class="fw-bold counter" data-target="<?= $totalParticipants ?>">0</h2>
          <p class="mb-0">Peserta Terdaftar</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-gradient-card stat-grad-orange reveal-on-scroll" style="animation-delay:.2s;">
          <div class="stat-icon-wrap"><i class="bi bi-journal-text"></i></div>
          <h2 class="fw-bold counter" data-target="<?= $totalMaterials ?>">0</h2>
          <p class="mb-0">Materi Pembelajaran</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="py-6 section-tint-a">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-eyebrow">Semua yang Anda butuhkan</span>
      <h3 class="fw-bold mt-2">Fitur Unggulan SiKedan</h3>
      <p class="text-muted">Satu platform untuk pembelajaran, penelitian, pengabdian, dan pelatihan</p>
    </div>
    <div class="row g-4">
      <?php
      $features = [
        ['icon' => 'bi-journal-richtext', 'color' => 'primary', 'title' => 'Materi & Video', 'desc' => 'Sampaikan materi teks dan video pembelajaran dengan mudah, terstruktur per hari kegiatan.'],
        ['icon' => 'bi-patch-question', 'color' => 'warning', 'title' => 'Quiz Interaktif', 'desc' => '4 tipe soal, timer otomatis, acak soal & pilihan, hingga generate soal dengan bantuan AI.'],
        ['icon' => 'bi-file-earmark-text', 'color' => 'danger', 'title' => 'Tugas & Penilaian', 'desc' => 'Kumpulkan tugas peserta, beri nilai dan feedback, pantau progress secara real-time.'],
        ['icon' => 'bi-clipboard-data', 'color' => 'info', 'title' => 'Form Penelitian', 'desc' => 'Kumpulkan data penelitian dengan 6 tipe pertanyaan tanpa perlu Google Form.'],
        ['icon' => 'bi-people', 'color' => 'success', 'title' => 'Pengabdian Masyarakat', 'desc' => 'Kelola peserta pelatihan/workshop, import massal via Excel, atau pendaftaran mandiri.'],
        ['icon' => 'bi-award', 'color' => 'secondary', 'title' => 'Sertifikat Digital', 'desc' => 'Terbitkan sertifikat otomatis lengkap dengan QR code untuk verifikasi keaslian.'],
      ];
      foreach ($features as $idx => $f): ?>
        <div class="col-md-6 col-lg-4">
          <div class="feature-card reveal-on-scroll" style="animation-delay: <?= $idx * 0.08 ?>s;">
            <div class="feature-icon icon-solid-<?= $f['color'] ?>"><i class="bi <?= $f['icon'] ?>"></i></div>
            <h6 class="fw-bold mt-3 mb-2"><?= $f['title'] ?></h6>
            <p class="text-muted small mb-0"><?= $f['desc'] ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="kegiatan" class="py-6 bg-white">
  <div class="container">
    <div class="text-center mb-5">
      <span class="section-eyebrow">Ikuti Sekarang</span>
      <h3 class="fw-bold mt-2">Kegiatan Terbaru</h3>
    </div>
    <div class="row g-4">
      <?php if (empty($recentEvents)): ?>
        <div class="col-12 text-center py-4">
          <i class="bi bi-calendar-x text-muted" style="font-size:3rem;"></i>
          <p class="text-muted mt-2 mb-0">Belum ada kegiatan aktif saat ini.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($recentEvents as $idx => $ev):
        $catColor = categoryColor($categoryColors, $ev['category_slug'] ?? null);
        $thumbUrl = $ev['cover_image'] ? (UPLOAD_URL . '/' . $ev['cover_image']) : null;
        $thumbPos = $ev['cover_image_position'] ?: 'center';
      ?>
        <div class="col-md-4">
          <div class="card event-card h-100 reveal-on-scroll" style="animation-delay: <?= $idx * 0.1 ?>s; border-top: 4px solid var(--bs-<?= $catColor ?>, var(--clr-primary));">
            <?php if ($thumbUrl): ?>
              <div class="event-card-thumb" style="background-image:url('<?= e($thumbUrl) ?>'); background-position: <?= e($thumbPos) ?>;"></div>
            <?php else: ?>
              <div class="event-card-thumb event-card-thumb-placeholder"><i class="bi bi-image"></i></div>
            <?php endif; ?>
            <div class="card-body">
              <span class="badge bg-<?= $catColor ?>-subtle text-<?= $catColor ?> mb-2"><?= e($ev['category_name']) ?></span>
              <h5 class="fw-bold"><?= e($ev['title']) ?></h5>
              <p class="text-muted small mb-2"><i class="bi bi-calendar3"></i> <?= formatTanggal($ev['start_date']) ?> &ndash; <?= formatTanggal($ev['end_date']) ?></p>
              <p class="small text-truncate-3"><?= e(mb_strimwidth($ev['description'] ?? '', 0, 120, '...')) ?></p>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="tentang" class="py-6 section-tint-b">
  <div class="container">
    <div class="about-card mx-auto reveal-on-scroll">
      <div class="row align-items-center g-4">
        <div class="col-md-4 text-center">
          <div class="about-avatar mx-auto">
            <i class="bi bi-person-video3"></i>
          </div>
        </div>
        <div class="col-md-8">
          <span class="section-eyebrow">Tentang</span>
          <h3 class="fw-bold mt-1"><?= e(getSetting('lecturer_name', 'Nama Dosen')) ?></h3>
          <p class="text-primary fw-semibold mb-2"><i class="bi bi-mortarboard me-1"></i><?= e(getSetting('institution', '')) ?></p>
          <p class="text-muted mb-0">Platform ini dikembangkan sebagai portofolio dosen sekaligus media pembelajaran, penelitian, dan pengabdian kepada masyarakat &mdash; dibangun untuk mendukung Tridharma Perguruan Tinggi secara terintegrasi dalam satu tempat.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <div class="row g-4 pb-4">
      <div class="col-lg-5">
        <p class="mb-2 fw-bold text-white fs-4"><i class="bi bi-mortarboard-fill me-2"></i><?= e(getSetting('app_name', 'SiKedan')) ?></p>
        <p class="small text-secondary mb-0" style="max-width:360px;"><?= e(getSetting('footer_text', 'Platform pembelajaran, penelitian, dan pengabdian masyarakat.')) ?></p>
      </div>
      <div class="col-lg-3 col-6">
        <p class="text-white fw-semibold small text-uppercase mb-3" style="letter-spacing:.05em;">Tautan</p>
        <ul class="list-unstyled small footer-links">
          <li><a href="#kegiatan">Kegiatan</a></li>
          <li><a href="#tentang">Tentang</a></li>
          <li><a href="auth/login.php">Masuk</a></li>
        </ul>
      </div>
      <div class="col-lg-4 col-6">
        <p class="text-white fw-semibold small text-uppercase mb-3" style="letter-spacing:.05em;">Kontak</p>
        <ul class="list-unstyled small footer-links">
          <li><i class="bi bi-envelope-fill footer-icon-circle"></i><?= e(getSetting('email', '')) ?></li>
          <li><i class="bi bi-building-fill footer-icon-circle"></i><?= e(getSetting('institution', '')) ?></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom-bar text-center text-md-start d-md-flex justify-content-between align-items-center">
      <p class="small mb-0 text-secondary">&copy; <?= date('Y') ?> <?= e(getSetting('app_name', 'SiKedan')) ?>. Seluruh hak cipta dilindungi.</p>
      <p class="small mb-0 text-secondary mt-2 mt-md-0">Dibuat untuk mendukung Tridharma Perguruan Tinggi.</p>
    </div>
  </div>
</footer>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
