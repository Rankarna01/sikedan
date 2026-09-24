<?php
$current = basename($_SERVER['SCRIPT_NAME']);
function navActive(string $file, string $current): string
{
    return $file === $current ? 'active' : '';
}
?>
<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar">
  <div class="offcanvas-body d-flex flex-column p-0">
    <?php if (currentRole() === 'admin'): ?>
      <a class="sidebar-link <?= navActive('dashboard.php', $current) ?>" href="<?= BASE_URL ?>/admin/dashboard.php">
        <i class="bi bi-speedometer2"></i> Dashboard
      </a>
      <div class="sidebar-heading">Kegiatan</div>
      <a class="sidebar-link <?= navActive('events.php', $current) ?>" href="<?= BASE_URL ?>/admin/events.php">
        <i class="bi bi-calendar-event"></i> Semua Kegiatan
      </a>
      <a class="sidebar-link <?= navActive('event_create.php', $current) ?>" href="<?= BASE_URL ?>/admin/event_create.php">
        <i class="bi bi-plus-circle"></i> Tambah Kegiatan
      </a>
      <a class="sidebar-link <?= navActive('categories.php', $current) ?>" href="<?= BASE_URL ?>/admin/categories.php">
        <i class="bi bi-tags"></i> Kategori
      </a>
      <a class="sidebar-link <?= navActive('hero_banners.php', $current) ?>" href="<?= BASE_URL ?>/admin/hero_banners.php">
        <i class="bi bi-images"></i> Banner Hero
      </a>
      <div class="sidebar-heading">Peserta</div>
      <a class="sidebar-link <?= navActive('participants.php', $current) ?>" href="<?= BASE_URL ?>/admin/participants.php">
        <i class="bi bi-people"></i> Semua Peserta
      </a>
      <div class="sidebar-heading">Pembelajaran</div>
      <a class="sidebar-link <?= navActive('materials.php', $current) ?>" href="<?= BASE_URL ?>/admin/materials.php">
        <i class="bi bi-journal-text"></i> Materi
      </a>
      <a class="sidebar-link <?= navActive('quizzes.php', $current) ?>" href="<?= BASE_URL ?>/admin/quizzes.php">
        <i class="bi bi-patch-question"></i> Quiz
      </a>
      <a class="sidebar-link <?= navActive('assignments.php', $current) ?>" href="<?= BASE_URL ?>/admin/assignments.php">
        <i class="bi bi-file-earmark-text"></i> Tugas
      </a>
      <div class="sidebar-heading">Lainnya</div>
      <a class="sidebar-link <?= navActive('certificates.php', $current) ?>" href="<?= BASE_URL ?>/admin/certificates.php">
        <i class="bi bi-award"></i> Sertifikat
      </a>
      <a class="sidebar-link <?= navActive('research.php', $current) ?>" href="<?= BASE_URL ?>/admin/research.php">
        <i class="bi bi-clipboard-data"></i> Penelitian
      </a>
      <a class="sidebar-link <?= navActive('reports.php', $current) ?>" href="<?= BASE_URL ?>/admin/reports.php">
        <i class="bi bi-graph-up"></i> Laporan
      </a>
      <a class="sidebar-link <?= navActive('settings.php', $current) ?>" href="<?= BASE_URL ?>/admin/settings.php">
        <i class="bi bi-gear"></i> Pengaturan
      </a>
    <?php else: ?>
      <a class="sidebar-link <?= navActive('dashboard.php', $current) ?>" href="<?= BASE_URL ?>/peserta/dashboard.php">
        <i class="bi bi-speedometer2"></i> Dashboard
      </a>
      <a class="sidebar-link <?= navActive('events.php', $current) ?>" href="<?= BASE_URL ?>/peserta/events.php">
        <i class="bi bi-calendar-event"></i> Kegiatan Saya
      </a>
      <a class="sidebar-link <?= navActive('certificates.php', $current) ?>" href="<?= BASE_URL ?>/peserta/certificates.php">
        <i class="bi bi-award"></i> Sertifikat
      </a>
      <a class="sidebar-link <?= navActive('profile.php', $current) ?>" href="<?= BASE_URL ?>/peserta/profile.php">
        <i class="bi bi-person"></i> Profil
      </a>
    <?php endif; ?>
    <a class="sidebar-link text-danger mt-auto" href="<?= BASE_URL ?>/auth/logout.php">
      <i class="bi bi-box-arrow-right"></i> Keluar
    </a>
  </div>
</div>
