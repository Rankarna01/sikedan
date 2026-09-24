# SiKedan — Phase 1

LMS pribadi dosen berbasis **PHP Native + MySQL** (tanpa framework berat) untuk mendukung pembelajaran, penelitian, dan pengabdian kepada masyarakat.

Status: **PHASE 10 SELESAI — Security Audit + Responsive Polish + Deployment Checklist (PROJECT LENGKAP)**

## Persyaratan

- PHP 8.2+
- MySQL 8+ / MariaDB 10.4+
- Apache (via XAMPP)
- Ekstensi PHP: `pdo_mysql`, `mbstring`, `session`

## Instalasi (XAMPP)

1. Install XAMPP dan aktifkan **Apache** serta **MySQL** melalui XAMPP Control Panel.
2. Salin folder `sikedan` ke dalam `htdocs`, sehingga menjadi `C:\xampp\htdocs\sikedan` (atau path sejenis di macOS/Linux).
3. Buka `http://localhost/phpmyadmin`, buat database baru bernama `sikedan` (atau langsung import — file `database.sql` sudah memiliki `CREATE DATABASE`).
4. Import file `database.sql` melalui tab **Import** di phpMyAdmin.
5. Buka `config/database.php` dan sesuaikan kredensial jika berbeda dari default XAMPP:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'sikedan');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
6. Buka `config/config.php` dan sesuaikan `BASE_URL` dengan lokasi project Anda, contoh:
   ```php
   define('BASE_URL', 'http://localhost/sikedan');
   ```
7. Buka browser dan akses:
   ```
   http://localhost/sikedan
   ```

## Akun Demo

| Role    | Username  | Password       |
|---------|-----------|----------------|
| Admin   | `admin`   | `Admin@12345`  |
| Peserta | `peserta` | `Peserta@12345`|

**Segera ganti password akun demo setelah login pertama kali**, terutama sebelum digunakan pada environment publik.

## Struktur Folder (Phase 1)

```
/sikedan
├── admin/
│   ├── dashboard.php
│   ├── events.php            (list, cari, filter kegiatan)
│   ├── event_create.php      (tambah kegiatan)
│   ├── event_edit.php        (edit kegiatan)
│   ├── event_delete.php      (hapus kegiatan)
│   ├── event_dashboard.php   (kelola hari/pertemuan per kegiatan)
│   ├── categories.php        (CRUD kategori kegiatan)
│   ├── materials.php         (CRUD materi per hari: teks + lampiran file)
│   ├── videos.php            (CRUD video per hari: embed YouTube/Vimeo otomatis)
│   ├── quizzes.php           (CRUD quiz per hari: durasi, passing grade, percobaan, acak soal/pilihan)
│   ├── quiz_questions.php    (bank soal: pilihan ganda, multiple answer, benar/salah, essay)
│   ├── quiz_grading.php      (nilai jawaban essay quiz secara manual)
│   ├── partials/quiz_form_fields.php  (form quiz yang dipakai bersama modal tambah & edit)
│   ├── profile.php           (profil admin: edit data diri + ubah password)
│   ├── assignments.php        (CRUD tugas per hari: deadline, izin terlambat)
│   ├── assignment_submissions.php  (lihat & nilai pengumpulan peserta)
│   ├── partials/assignment_form_fields.php
│   ├── participants.php       (semua peserta: tambah manual, aktif/nonaktif, hapus, daftarkan/keluarkan dari kegiatan)
│   ├── import_participants.php (import peserta via CSV: preview, validasi, konfirmasi)
│   ├── research.php           (CRUD form penelitian)
│   ├── research_questions.php (bank pertanyaan: pilihan ganda, checkbox, radio, teks, rating, likert)
│   ├── research_responses.php (lihat jawaban responden + export CSV)
│   ├── evaluations.php        (dashboard evaluasi kegiatan: rata-rata + grafik + saran peserta)
│   ├── certificates.php       (terbitkan sertifikat per peserta atau massal berdasarkan progress)
│   ├── settings.php           (pengaturan aplikasi: nama, tagline, logo, dosen, institusi, warna, footer)
│   └── reports.php            (laporan kegiatan gabungan: statistik, nilai, progress, kepuasan + export CSV)
├── certificate_view.php        (tampilan sertifikat cetak/simpan PDF, dengan QR verifikasi)
├── verify.php                  (verifikasi sertifikat publik via kode)
├── register.php                (pendaftaran mandiri peserta via link kegiatan)
├── research_fill.php           (pengisian form penelitian publik/peserta)
├── evaluation.php              (form evaluasi kegiatan untuk peserta)
├── peserta/
│   ├── dashboard.php
│   ├── events.php             (daftar kegiatan yang diikuti)
│   ├── event_detail.php       (timeline hari: materi/video/quiz/tugas)
│   ├── material.php           (baca materi + tandai selesai)
│   ├── assignment.php         (lihat instruksi + kumpulkan/lihat nilai tugas)
│   ├── quiz_take.php          (kerjakan quiz dengan timer + auto-grading)
│   ├── quiz_result.php        (hasil quiz + rincian jawaban)
│   ├── profile.php            (profil peserta: edit data diri + ubah password)
│   └── certificates.php       (lihat & cetak sertifikat sendiri)
├── auth/
│   ├── login.php
│   └── logout.php
├── config/
│   ├── database.php
│   └── config.php
├── includes/
│   ├── header.php
│   ├── footer.php
│   ├── navbar.php
│   ├── sidebar.php
│   ├── functions.php
│   └── error_403.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── uploads/
│   ├── documents/
│   ├── assignments/
│   └── images/
├── index.php
├── 404.php
├── database.sql
└── README.md
```

Modul lain (CRUD kegiatan, materi, quiz, tugas, import Excel, penelitian, evaluasi, sertifikat, laporan, dsb.) akan ditambahkan bertahap pada Phase 2–10 sesuai rencana pengembangan.

## Keamanan yang Sudah Diimplementasikan di Phase 1

- Password di-hash dengan `password_hash()` (bcrypt) dan diverifikasi dengan `password_verify()`.
- Seluruh query menggunakan **PDO prepared statements** (anti SQL Injection).
- Output di-escape dengan `htmlspecialchars()` melalui helper `e()` (anti XSS).
- **CSRF token** wajib pada form login (dan seluruh form pada phase berikutnya) via `csrfField()` + `verifyCsrf()`.
- Role-based access control sederhana melalui `requireRole()` — halaman admin tidak bisa diakses langsung oleh peserta.
- Session di-regenerate setelah login untuk mencegah session fixation.
- Pengaturan `display_errors` otomatis nonaktif saat `APP_ENV = 'production'` (lihat `config/config.php`).
- **`.htaccess`**: file konfigurasi Apache disertakan di root (melindungi `.sql`/`.md` dari akses langsung via URL) dan di folder `uploads/` (menonaktifkan eksekusi PHP di dalamnya, agar file yang diunggah tidak bisa dijalankan sebagai script berbahaya meski lolos validasi ekstensi).

## Deployment ke Hosting / cPanel / VPS

1. Upload seluruh folder project ke `public_html` (atau subfolder domain Anda) via FTP/File Manager.
2. Buat database MySQL baru melalui cPanel, lalu import `database.sql` melalui phpMyAdmin.
3. Sesuaikan `config/database.php` dengan kredensial database hosting.
4. Ubah `BASE_URL` di `config/config.php` sesuai domain, contoh `https://namadomain.com`.
5. Ubah `APP_ENV` menjadi `'production'` di `config/config.php`.
6. Pastikan folder `uploads/` memiliki permission tulis (misalnya `755` atau `775` sesuai kebijakan hosting).
7. Aktifkan SSL/HTTPS pada domain (biasanya tersedia gratis via Let's Encrypt di cPanel).

## Backup

- **Database**: gunakan `mysqldump -u root -p sikedan > backup.sql` secara berkala, atau fitur Export di phpMyAdmin.
- **File**: backup folder `uploads/` secara berkala karena berisi dokumen, tugas, dan gambar yang diunggah pengguna.

## Phase 10 — Hasil Security Audit

> **Catatan tambahan:** dalam audit ini juga ditemukan bahwa `admin/settings.php` (dirujuk di sidebar sejak Phase 1) belum pernah dibangun filenya — sekarang telah dilengkapi dengan form pengaturan nama aplikasi, tagline, logo, nama dosen, institusi, warna utama, dan footer, seluruhnya tersimpan ke tabel `settings` dan diterapkan secara dinamis lewat `getSetting()`.

Audit menyeluruh dilakukan terhadap seluruh 47 file PHP sebelum rilis final. Ringkasan temuan dan status:

| Aspek | Status | Keterangan |
|---|---|---|
| CSRF protection | ✅ Lolos | Seluruh handler `POST` di 46 file diverifikasi memanggil `verifyCsrf()` sebelum memproses data. |
| Role-based access control | ✅ Lolos | Seluruh file di `admin/` memanggil `requireRole('admin')`, seluruh file di `peserta/` memanggil `requireRole('peserta')`. Halaman publik (`register.php`, `verify.php`, `research_fill.php`, `index.php`) sengaja tanpa guard sesuai fungsinya. |
| SQL Injection | ✅ Lolos | 100% query database menggunakan PDO prepared statements; tidak ada string SQL yang digabung langsung dari input pengguna. |
| XSS (output escaping) | ✅ Lolos | Seluruh output dinamis melalui helper `e()` (htmlspecialchars). Pengecualian yang **disengaja**: `materials.content` dirender sebagai HTML mentah pada `peserta/material.php` karena admin (dosen tepercaya) memang diberi kemampuan menulis format kaya (heading/list/bold) sesuai spesifikasi poin 10 — bukan celah, karena hanya admin yang bisa menulis ke kolom ini. Jika di masa depan kolom ini dibuka untuk input peserta, wajib disaring dengan HTML Purifier atau sejenisnya. |
| Upload security | ✅ Diperbaiki di Phase 10 | Validasi ekstensi + ukuran file kini konsisten di ketiga titik upload (`admin/materials.php`, `peserta/assignment.php`, `admin/import_participants.php` — sebelumnya baru validasi ekstensi, kini ukuran 5MB juga dicek). Nama file selalu diacak (`time() + random_bytes()`), dan folder `uploads/.htaccess` menonaktifkan eksekusi PHP di dalamnya sebagai lapisan pertahanan tambahan. |
| Password security | ✅ Lolos | `password_hash()`/`password_verify()` (bcrypt) di seluruh alur (login, tambah peserta manual, import Excel, pendaftaran mandiri). Tidak ada password disimpan/di-log dalam bentuk plaintext. |
| Session security | ✅ Lolos | `session_regenerate_id(true)` setelah login, `cookie_httponly`, `use_strict_mode`, serta `cookie_secure` otomatis aktif saat `APP_ENV=production`. |
| Authorization pada resource pribadi | ✅ Lolos | `certificate_view.php` memverifikasi pemilik sertifikat atau admin sebelum menampilkan; `peserta/*` memverifikasi kepesertaan (`event_participants`) sebelum menampilkan materi/tugas/kegiatan orang lain. |
| Error handling | ✅ Lolos | `display_errors` otomatis mati saat production; pesan error database tidak pernah ditampilkan mentah ke pengguna (`config/database.php` menangkap `PDOException` dan menampilkan pesan generik). |
| Rate limiting login | ⚠️ Belum ada | Sesuai prinsip "sederhana dan sesuai kebutuhan portofolio", brute-force throttling tidak diimplementasikan di v1. Jika akan dipakai untuk kegiatan publik berskala besar, disarankan menambahkan pembatasan percobaan login (misalnya via tabel counter per-IP atau reCAPTCHA) sebelum go-live.

## Phase 10 — Responsive Polish

- Ditambahkan breakpoint mobile tambahan di `assets/css/style.css`: tombol dalam grup (`card-footer`) menjadi full-width dan bertumpuk vertikal di layar < 576px, ukuran font statistik & hero disesuaikan, margin modal diperkecil.
- Halaman sertifikat (`certificate_view.php`) kini menyusut proporsional di layar < 1040px lebar (tetap terbaca di HP) tanpa mengubah tampilan versi cetak/PDF.
- Seluruh 9 tabel data di aplikasi sudah terbungkus `.table-responsive` sehingga bisa di-scroll horizontal di layar kecil tanpa merusak tata letak.
- Sidebar admin & peserta sudah menggunakan Bootstrap `offcanvas-lg` sejak Phase 1, sehingga otomatis menjadi menu geser (offcanvas) di mobile dan sidebar tetap di desktop — sesuai spesifikasi poin 51.

## Phase 10 — Deployment Checklist Final

Sebelum go-live ke hosting/VPS produksi, pastikan seluruh poin berikut:

- [ ] Import `database.sql` ke database production, **ganti password akun demo** (`admin`/`peserta`) atau hapus akun peserta demo.
- [ ] `config/database.php` — ganti `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` sesuai kredensial hosting.
- [ ] `config/config.php` — ubah `APP_ENV` menjadi `'production'` dan `BASE_URL` sesuai domain (`https://...`).
- [ ] Aktifkan **HTTPS/SSL** di domain (biasanya gratis via Let's Encrypt di cPanel) — penting karena `session.cookie_secure` otomatis aktif saat production dan membutuhkan HTTPS agar cookie sesi tetap terkirim.
- [ ] Set permission folder `uploads/` menjadi writable (755/775 sesuai kebijakan hosting), tapi pastikan `uploads/.htaccess` tetap ada agar PHP tidak bisa dieksekusi dari folder tersebut.
- [ ] Update pengaturan aplikasi lewat halaman **Admin → Pengaturan** (nama aplikasi, logo, nama dosen, institusi, warna) — saat ini menu Pengaturan masih placeholder dasar; nilai default bisa diubah langsung lewat tabel `settings` di database bila diperlukan sebelum halaman Pengaturan penuh dibangun.
- [ ] Jadwalkan backup rutin: `mysqldump` untuk database dan backup berkala folder `uploads/` (lihat bagian Backup di README ini).
- [ ] Uji ulang seluruh alur kritis di environment production: login, buat kegiatan, daftar peserta, kumpulkan tugas, terbitkan sertifikat, verifikasi sertifikat via QR — pastikan `BASE_URL` yang benar membuat semua tautan & QR code mengarah ke domain yang tepat (bukan `localhost`).
- [ ] (Opsional, pengembangan lanjutan) Pasang PhpSpreadsheet via Composer bila ingin mendukung upload `.xlsx` langsung tanpa konversi CSV manual — instruksi lengkap sudah ada di `admin/import_participants.php`.

---

## Pembaruan: Video Ikut Berbobot + Ikon Fitur Diperbesar

### Video Kini Punya Bobot Progress
Sebelumnya video hanya bisa ditonton tanpa pernah tercatat sebagai "selesai" dan tidak ikut dihitung dalam progress peserta sama sekali. Sekarang:
- **admin/videos.php** — setiap video punya field **Bobot Progress (%)**, sama seperti materi/quiz/tugas, dan ikut dijumlahkan dalam total bobot per-hari (materi+video+quiz+tugas = 100%).
- **peserta/video.php** — tombol **"Tandai Sudah Ditonton"** setelah menonton video, tercatat di tabel `video_progress`.
- **peserta/event_detail.php** — status "Sudah Ditonton"/"Belum" tampil di daftar video per hari, sama seperti status materi.
- **`updateParticipantProgress()`** di `includes/functions.php` sekarang ikut menghitung video dalam bobot progress kegiatan.

### Ikon Fitur Landing Page Diperbesar & Diperjelas
- Ukuran ikon di section "Fitur Unggulan" diperbesar dari 52px → 72px, dengan sudut lebih membulat (border-radius 18px).
- Background ikon diganti dari warna soft/transparan menjadi **gradasi warna solid** dengan bayangan (shadow) — jauh lebih menonjol dan profesional dibanding versi flat sebelumnya.

### Migrasi Database Diperlukan
Jalankan **`MIGRATION_v4_video_weight.sql`** di phpMyAdmin — menambahkan kolom `weight_percent` ke tabel `videos` dan membuat tabel baru `video_progress`. Migrasi ini **terpisah** dari `MIGRATION_v3` — jika belum pernah menjalankan v3, jalankan dulu v3 baru v4. Kode sudah dilengkapi penanganan otomatis (fallback aman, bukan fatal error) jika migrasi ini belum dijalankan — fitur bobot video hanya tidak akan aktif sampai migrasinya dijalankan.



### Gambar Kegiatan di Kartu
- Kartu kegiatan di landing page (Kegiatan Terbaru) dan halaman peserta (Kegiatan Saya) sekarang menampilkan **thumbnail foto kegiatan** di bagian atas kartu (170px tinggi, `object-fit: cover`), sebelum judul/badge kategori. Jika kegiatan belum punya foto sampul, ditampilkan placeholder ikon gambar dengan gradasi abu-abu.

### Posisi Crop Foto (Atas/Tengah/Bawah/Kiri/Kanan)
- Form tambah/edit kegiatan (`admin/event_create.php`, `event_edit.php`) dan form banner hero (`admin/hero_banners.php`) kini punya pilihan **"Posisi Bagian Foto yang Ditampilkan"** — berguna saat foto ter-crop otomatis (karena rasio kotak kartu/hero) dan bagian pentingnya justru terpotong. Tersimpan ke kolom `cover_image_position` (kegiatan) / `image_position` (banner hero), diterapkan sebagai CSS `background-position` secara dinamis di landing page.

### Sistem Bobot Progress 2 Tingkat (Bukan Rata Otomatis)
Sebelumnya, progress peserta dihitung otomatis rata: `(jumlah item selesai / total item) × 100`. Sekarang admin dapat **menentukan sendiri bobot setiap komponen**, sesuai permintaan — progress tidak langsung 100% begitu saja, tapi dibagi bertahap:

1. **Bobot per Hari** — setiap hari/pertemuan pada suatu kegiatan (`admin/event_dashboard.php` → Tambah Hari) punya field **Bobot Hari Ini (%)**. Idealnya total bobot seluruh hari pada satu kegiatan berjumlah 100% (misal Hari 1 = 30%, Hari 2 = 30%, Hari 3 = 40%). Indikator badge (hijau/kuning/abu) menunjukkan status total bobot secara real-time di halaman kelola kegiatan.
2. **Bobot per Item dalam Hari** — di dalam satu hari, setiap **materi**, **quiz**, dan **tugas** (`admin/materials.php`, `quizzes.php`, `assignments.php`) punya field **Bobot Progress (%)** sendiri. Idealnya total bobot materi+quiz+tugas dalam satu hari yang sama berjumlah 100%. Indikator badge yang sama ditampilkan di ketiga halaman ini.
3. **Perhitungan akhir**: Progress kegiatan = Σ (bobot hari × progress hari itu), dan progress hari = jumlah bobot item yang sudah diselesaikan peserta di hari tersebut.

**Fallback otomatis**: jika admin tidak mengisi bobot sama sekali (dibiarkan 0 di semua hari, atau 0 di semua item dalam suatu hari), sistem **otomatis membagi rata** seperti perilaku lama — jadi fitur ini bersifat opsional, tidak memaksa admin mengisi bobot manual jika tidak diperlukan. Sistem juga menormalisasi otomatis jika total bobot yang diisi admin tidak persis 100% (misalnya karena salah hitung), sehingga progress tetap berada di rentang 0–100%.

Logika lengkap ada di fungsi `updateParticipantProgress()` pada `includes/functions.php`, dipanggil otomatis setiap kali peserta menandai materi selesai, mengumpulkan tugas, atau mengerjakan quiz.

### Migrasi Database Diperlukan
Jalankan **`MIGRATION_v3_weight_and_position.sql`** di phpMyAdmin (tab SQL) pada database yang sudah ada — menambahkan kolom `weight_percent` ke `event_days`/`materials`/`quizzes`/`assignments`, dan `cover_image_position`/`image_position` ke `events`/`hero_banners`. Aman dijalankan, tidak menghapus data yang sudah ada.

---

## Pembaruan Pasca-Phase 10 — Integrasi Penuh & UI/UX Pendukung

Setelah audit ulang menyeluruh, ditemukan dan diperbaiki beberapa **celah integrasi** yang sebelumnya membuat alur belum tersambung end-to-end, plus penambahan elemen UI/UX pendukung:

### Celah Integrasi yang Diperbaiki
- **Peserta tidak bisa mengerjakan quiz** — sebelumnya quiz hanya bisa dibuat/dikelola admin, peserta hanya melihat status tanpa bisa mengerjakan. Sekarang tersedia penuh:
  - `peserta/quiz_take.php` — halaman pengerjaan quiz dengan **timer countdown otomatis submit saat waktu habis**, mendukung acak soal/pilihan sesuai pengaturan quiz, auto-grading untuk Pilihan Ganda/Multiple Answer/Benar-Salah, dan submission essay untuk dinilai manual. Melindungi dari percobaan submit ulang (`beforeunload` warning) dan menghormati batas percobaan, jadwal mulai/selesai, serta status keanggotaan kegiatan.
  - `peserta/quiz_result.php` — halaman hasil dengan rincian jawaban per soal, status lulus/tidak berdasarkan passing grade, dan progress otomatis ter-update.
  - `admin/quiz_grading.php` — halaman baru untuk admin menilai jawaban essay quiz secara manual (skor + feedback), dengan **rekalkulasi otomatis skor total & status lulus** setiap kali essay dinilai.
  - Progress peserta (`updateParticipantProgress()`) kini turut memperhitungkan quiz yang sudah dikerjakan, bukan hanya materi & tugas.
- **`admin/settings.php` hilang** meski dirujuk di sidebar sejak Phase 1 — sudah dilengkapi (lihat Phase 10).
- **`admin/profile.php` dan `peserta/profile.php` hilang** meski dirujuk di navbar sejak Phase 1 — sekarang tersedia untuk kedua role: edit data diri (nama, email, no HP, institusi, jabatan) dan ubah password dengan verifikasi password lama.

### UI/UX Pendukung Baru
- **Bell notifikasi di navbar** — memanfaatkan tabel `notifications` yang sebelumnya ada di database tapi tak pernah dipakai kode. Sekarang menampilkan badge jumlah belum dibaca dan dropdown daftar notifikasi terbaru. Notifikasi otomatis dikirim saat: nilai tugas diberikan, sertifikat diterbitkan (satu per satu maupun massal), dan tugas baru berstatus aktif dipublikasikan ke peserta terdaftar.
- **Loading state global pada tombol submit** — `assets/js/app.js` kini menonaktifkan tombol dan menampilkan spinner "Memproses..." otomatis di semua form saat disubmit, termasuk pengaman agar tombol tidak macet jika submission dibatalkan lewat dialog `confirm()` (form hapus).
- Tautan quiz pada `peserta/event_detail.php` kini benar-benar mengarah ke halaman pengerjaan (`quiz_take.php`), dengan badge warna hijau/kuning otomatis berdasarkan apakah skor terbaik sudah melewati passing grade.

### Hero Banner Slider (Landing Page)
- Landing page (`index.php`) menggunakan **Bootstrap Carousel** sebagai hero banner dengan **tiga tingkat prioritas sumber gambar**:
  1. **Banner mandiri aktif** — dikelola penuh oleh admin lewat menu **Admin → Banner Hero** (`admin/hero_banners.php`): upload gambar bebas (tidak terikat kegiatan), judul, subjudul, link tujuan opsional, status aktif/nonaktif, dan urutan tampil. Ini yang dipakai duluan jika ada minimal satu banner aktif.
  2. **Foto sampul kegiatan aktif** — jika tidak ada banner mandiri, otomatis memakai hingga 5 kegiatan aktif terbaru beserta foto sampulnya (field "Foto Sampul" di form tambah/edit kegiatan).
  3. **Foto default bawaan sistem** — jika keduanya kosong, dipakai sebagai fallback terakhir.
- **Overlay transparansi dapat diatur** tanpa mengubah kode: buka **Admin → Pengaturan → Kegelapan Overlay Hero Banner**, geser slider (rentang 0.1–0.9, direkomendasikan 0.4–0.6). Nilai ini langsung diterapkan sebagai CSS variable `--hero-overlay-opacity` sehingga foto tetap terlihat namun teks tetap terbaca.
- **Migrasi database**: jika database Anda sudah diimport sebelum fitur ini ada, jalankan `MIGRATION_hero_banners.sql` (cukup satu kali, aman dijalankan berkali-kali) di phpMyAdmin untuk menambahkan tabel `hero_banners` tanpa perlu import ulang seluruh database.

---



Seluruh **10 phase** pada rencana pengerjaan telah selesai. SiKedan kini merupakan LMS PHP Native + MySQL yang fungsional untuk:

1. ✅ Pembelajaran mahasiswa (materi, video, quiz, tugas, progress)
2. ✅ Penelitian (form kustom 6 tipe pertanyaan + export data)
3. ✅ Pengabdian kepada masyarakat (kategori kegiatan + seluruh fitur di atas)
4. ✅ Pelatihan/workshop (kegiatan multi-hari, sertifikat)
5. ✅ Pendataan peserta kegiatan (manual, import Excel/CSV, pendaftaran mandiri via link)
6. ✅ Pengumpulan tugas (upload file + penilaian)
7. ✅ Quiz/evaluasi pembelajaran (4 tipe soal)
8. ✅ Evaluasi kegiatan (rating + saran + grafik)
9. ✅ Sertifikat otomatis dengan verifikasi QR
10. ✅ Laporan kegiatan gabungan dengan export CSV

Terima kasih telah membangun SiKedan bersama Claude — selamat digunakan sebagai portofolio dan alat bantu Tridharma Perguruan Tinggi Anda!

---

## Pembaruan Terbaru — Optimasi Fitur Admin & Database Tunggal

### Database digabung menjadi SATU file
`database.sql` kini memuat **seluruh** struktur database dan menggantikan file lama:
`MIGRATION_hero_banners.sql`, `MIGRATION_v3_weight_and_position.sql`, `MIGRATION_v4_video_weight.sql`, `MIGRATION_v5_submission_link.sql`, `UPDATE_hero_overlay_setting.sql` (file-file tersebut sudah dihapus).

- **Instalasi baru**: import `database.sql` seperti biasa.
- **Database lama (sudah berisi data)**: cukup import `database.sql` yang baru melalui phpMyAdmin. File ini hanya menambahkan tabel/kolom yang belum ada — data yang sudah ada (peserta, kegiatan, materi, nilai, dan path gambar cover/banner/lampiran) **tidak diubah**. Aman diimport berulang kali; akun demo tidak dibuat ulang jika sudah ada pengguna.
- Folder `uploads/` (gambar cover kegiatan, banner hero, lampiran materi) tidak disentuh sama sekali.

### Manajemen Peserta (`admin/participants.php`)
- Tombol **Setujui** / **Tolak** per peserta, dan **Setujui Semua** (seluruh pendaftar berstatus menunggu pada kegiatan yang dipilih).
- Filter: nama/email/username/institusi, kegiatan, kategori, status pendaftaran, status akun, rentang tanggal daftar.
- Jumlah data per halaman: 10 / 25 / 50 / 100.
- Pendaftaran mandiri lewat link kini berstatus **menunggu persetujuan**; peserta baru dapat membuka materi setelah disetujui. Peserta yang ditambahkan admin / import Excel otomatis disetujui.

### Hari / Pertemuan
Nomor hari dinamis: setelah ada hari yang dihapus, nomor otomatis dirapikan (1, 2, 3, …). Contoh: Hari 1, 2, 3 → Hari 1 & 2 dihapus → hari sisa menjadi Hari 1 → hari baru menjadi Hari 2.

### Sertifikat (`admin/certificates.php`, menu **Sertifikat** di sidebar)
Dua opsi: **generate otomatis** oleh sistem (satuan/massal) atau **upload manual** file PDF/JPG/PNG oleh admin (juga bisa menggantikan sertifikat otomatis). Semua sertifikat tetap bisa diverifikasi lewat `verify.php`.

### Laporan Kegiatan (`admin/reports.php`)
CRUD lengkap (tambah, lihat, edit, hapus) dengan lampiran opsional, filter (kata kunci, kegiatan, kategori, status, rentang tanggal), hapus satuan / terpilih / **hapus semua** (butuh mengetik `HAPUS`). Statistik per kegiatan yang lama tetap tersedia pada tab **Statistik per Kegiatan**.

### Nama Aplikasi: SiKedan
Nama folder, database, `BASE_URL`, dan nama sesi kini juga `sikedan` (folder `sikedan`, database `sikedan`, `BASE_URL` = `http://localhost/sikedan`, sesi `sikedan_session`; semua pengguna logout satu kali).

**Pindah dari instalasi lama (`dosen_lms`)**: ekspor database `dosen_lms`, buat database `sikedan`, impor hasil ekspor tadi, lalu impor `database.sql` ini di atasnya (tanpa menghapus data). Salin folder `uploads/` lama ke folder `sikedan/uploads/`. Catatan: QR pada sertifikat lama mengarah ke `BASE_URL` lama.
