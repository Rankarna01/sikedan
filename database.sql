-- =========================================================
-- SiKedan - Database (SATU FILE, LENGKAP)
-- PHP Native + MySQL / MariaDB
--
-- File ini MENGGANTIKAN seluruh file SQL sebelumnya:
--   database.sql (lama), MIGRATION_hero_banners.sql,
--   MIGRATION_v3_weight_and_position.sql, MIGRATION_v4_video_weight.sql,
--   MIGRATION_v5_submission_link.sql, UPDATE_hero_overlay_setting.sql
-- ditambah struktur baru (persetujuan peserta, sertifikat manual,
-- laporan kegiatan).
--
-- AMAN DIIMPORT DUA CARA:
--   1) Instalasi baru  -> semua tabel & data awal dibuat.
--   2) Database lama   -> hanya menambahkan tabel/kolom yang belum ada.
--      Data yang sudah ada (peserta, kegiatan, materi, nilai, path gambar
--      cover/banner/lampiran, dsb.) TIDAK diubah/dihapus. Boleh diimport
--      berulang kali. Akun demo tidak dibuat ulang jika sudah ada pengguna.
-- =========================================================

-- ============================
-- ROLES
-- ============================
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (id, name) VALUES
(1, 'admin'),
(2, 'peserta');

-- ============================
-- USERS
-- ============================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    identity_number VARCHAR(50) NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    institution VARCHAR(150) NULL,
    position VARCHAR(100) NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    photo VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

-- ============================
-- CATEGORIES
-- ============================
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Penanda instalasi baru (tabel users masih kosong). Dipakai agar data awal / akun demo
-- TIDAK dibuat ulang saat file ini diimport ke database yang sudah berisi data.
SET @fresh_install = (SELECT COUNT(*) = 0 FROM users);

INSERT INTO categories (name, slug, description)
SELECT * FROM (
SELECT 'Pembelajaran' AS name, 'pembelajaran' AS slug, 'Kegiatan perkuliahan dan pembelajaran mahasiswa' AS description
UNION ALL
SELECT 'Penelitian' AS name, 'penelitian' AS slug, 'Kegiatan pengumpulan data penelitian' AS description
UNION ALL
SELECT 'Pengabdian Masyarakat' AS name, 'pengabdian-masyarakat' AS slug, 'Kegiatan pengabdian kepada masyarakat' AS description
UNION ALL
SELECT 'Pelatihan/Workshop' AS name, 'pelatihan-workshop' AS slug, 'Pelatihan dan workshop' AS description
UNION ALL
SELECT 'Seminar' AS name, 'seminar' AS slug, 'Seminar dan kegiatan ilmiah' AS description
) AS seed WHERE @fresh_install = 1;

-- ============================
-- EVENTS (Kegiatan)
-- ============================
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(220) NOT NULL UNIQUE,
    description TEXT NULL,
    location VARCHAR(200) NULL,
    partner VARCHAR(200) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    quota INT DEFAULT 0,
    cover_image VARCHAR(255) NULL,
    cover_image_position VARCHAR(20) NOT NULL DEFAULT 'center',
    status ENUM('draft','active','inactive','completed') NOT NULL DEFAULT 'draft',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_events_status (status),
    INDEX idx_events_category (category_id)
) ENGINE=InnoDB;

-- ============================
-- EVENT DAYS (Hari/Pertemuan)
-- ============================
CREATE TABLE IF NOT EXISTS event_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    day_number INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    day_date DATE NULL,
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_event_days_event (event_id)
) ENGINE=InnoDB;

-- ============================
-- MATERIALS (Materi)
-- ============================
CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_day_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT NULL,
    attachment VARCHAR(255) NULL,
    status ENUM('draft','published','inactive') NOT NULL DEFAULT 'draft',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_day_id) REFERENCES event_days(id) ON DELETE CASCADE,
    INDEX idx_materials_day (event_day_id)
) ENGINE=InnoDB;

-- ============================
-- VIDEOS
-- ============================
CREATE TABLE IF NOT EXISTS videos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_day_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    video_url VARCHAR(255) NOT NULL,
    description TEXT NULL,
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_day_id) REFERENCES event_days(id) ON DELETE CASCADE,
    INDEX idx_videos_day (event_day_id)
) ENGINE=InnoDB;

-- ============================
-- VIDEO PROGRESS (peserta menandai video sudah ditonton)
-- ============================
CREATE TABLE IF NOT EXISTS video_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id INT NOT NULL,
    user_id INT NOT NULL,
    is_watched TINYINT(1) DEFAULT 0,
    watched_at TIMESTAMP NULL,
    FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_video_user (video_id, user_id)
) ENGINE=InnoDB;

-- ============================
-- QUIZZES
-- ============================
CREATE TABLE IF NOT EXISTS quizzes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_day_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    duration_minutes INT DEFAULT 20,
    passing_grade INT DEFAULT 70,
    max_attempts INT DEFAULT 1,
    random_question TINYINT(1) DEFAULT 0,
    random_option TINYINT(1) DEFAULT 0,
    start_time DATETIME NULL,
    end_time DATETIME NULL,
    status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_day_id) REFERENCES event_days(id) ON DELETE CASCADE,
    INDEX idx_quizzes_day (event_day_id)
) ENGINE=InnoDB;

-- ============================
-- QUESTIONS
-- ============================
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice','multiple_answer','true_false','essay') NOT NULL DEFAULT 'multiple_choice',
    score INT DEFAULT 10,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_questions_quiz (quiz_id)
) ENGINE=InnoDB;

-- ============================
-- QUESTION OPTIONS
-- ============================
CREATE TABLE IF NOT EXISTS question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    is_correct TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    INDEX idx_options_question (question_id)
) ENGINE=InnoDB;

-- ============================
-- QUIZ ATTEMPTS
-- ============================
CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    user_id INT NOT NULL,
    attempt_number INT DEFAULT 1,
    score DECIMAL(5,2) DEFAULT 0,
    is_passed TINYINT(1) DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_attempts_quiz_user (quiz_id, user_id)
) ENGINE=InnoDB;

-- ============================
-- QUIZ ANSWERS
-- ============================
CREATE TABLE IF NOT EXISTS quiz_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_options VARCHAR(255) NULL,
    essay_answer TEXT NULL,
    score DECIMAL(5,2) DEFAULT 0,
    is_correct TINYINT(1) DEFAULT 0,
    feedback TEXT NULL,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    INDEX idx_answers_attempt (attempt_id)
) ENGINE=InnoDB;

-- ============================
-- ASSIGNMENTS (Tugas)
-- ============================
CREATE TABLE IF NOT EXISTS assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_day_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    instructions TEXT NULL,
    start_date DATETIME NULL,
    deadline DATETIME NULL,
    allow_late TINYINT(1) DEFAULT 0,
    status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_day_id) REFERENCES event_days(id) ON DELETE CASCADE,
    INDEX idx_assignments_day (event_day_id)
) ENGINE=InnoDB;

-- ============================
-- ASSIGNMENT SUBMISSIONS
-- ============================
CREATE TABLE IF NOT EXISTS assignment_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    user_id INT NOT NULL,
    answer_text TEXT NULL,
    file_path VARCHAR(255) NULL,
    submission_link VARCHAR(500) NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_late TINYINT(1) DEFAULT 0,
    score DECIMAL(5,2) NULL,
    feedback TEXT NULL,
    graded_at TIMESTAMP NULL,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_submissions_assignment_user (assignment_id, user_id)
) ENGINE=InnoDB;

-- ============================
-- EVENT PARTICIPANTS
-- ============================
CREATE TABLE IF NOT EXISTS event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    progress DECIMAL(5,2) DEFAULT 0,
    status ENUM('registered','approved','rejected') DEFAULT 'approved',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_event_user (event_id, user_id)
) ENGINE=InnoDB;

-- ============================
-- MATERIAL PROGRESS
-- ============================
CREATE TABLE IF NOT EXISTS material_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    user_id INT NOT NULL,
    is_completed TINYINT(1) DEFAULT 0,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_material_user (material_id, user_id)
) ENGINE=InnoDB;

-- ============================
-- RESEARCH FORMS (Penelitian)
-- ============================
CREATE TABLE IF NOT EXISTS research_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('draft','active','closed') DEFAULT 'draft',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================
-- RESEARCH QUESTIONS
-- ============================
CREATE TABLE IF NOT EXISTS research_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice','checkbox','radio','text','long_text','rating','likert') NOT NULL,
    options TEXT NULL,
    is_required TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (form_id) REFERENCES research_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================
-- RESEARCH RESPONSES
-- ============================
CREATE TABLE IF NOT EXISTS research_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    user_id INT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES research_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================
-- RESEARCH ANSWERS
-- ============================
CREATE TABLE IF NOT EXISTS research_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    response_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_text TEXT NULL,
    FOREIGN KEY (response_id) REFERENCES research_responses(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES research_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================
-- EVALUATIONS (Evaluasi Kegiatan)
-- ============================
CREATE TABLE IF NOT EXISTS evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    material_rating INT NULL,
    speaker_rating INT NULL,
    overall_rating INT NULL,
    suggestion TEXT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_label VARCHAR(200) NOT NULL,
    answer_value VARCHAR(255) NOT NULL,
    FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================
-- CERTIFICATES
-- ============================
CREATE TABLE IF NOT EXISTS certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    certificate_number VARCHAR(100) NOT NULL UNIQUE,
    verification_code VARCHAR(50) NOT NULL UNIQUE,
    issued_at DATE NOT NULL,
    file_path VARCHAR(255) NULL,
    source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_cert_code (verification_code)
) ENGINE=InnoDB;

-- ============================
-- ACTIVITY LOGS
-- ============================
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(150) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================
-- SETTINGS
-- ============================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('app_name', 'SiKedan'),
('app_tagline', 'Sistem Integrasi Kegiatan Edukasi, Pengabdian, dan Penelitian'),
('lecturer_name', 'Nama Dosen'),
('institution', 'Politeknik Negeri Medan'),
('email', 'dosen@example.ac.id'),
('primary_color', '#2563EB'),
('dark_color', '#0F172A'),
('bg_color', '#F8FAFC'),
('hero_overlay_opacity', '0.5'),
('anthropic_api_key', ''),
('logo', ''),
('footer_text', '© 2026 SiKedan. Seluruh hak cipta dilindungi.');

-- Ganti nama aplikasi menjadi SiKedan pada database lama, HANYA jika nilainya masih bawaan
-- lama (tidak menimpa nama/tagline/footer yang sudah Anda ubah sendiri di menu Pengaturan).
UPDATE settings SET setting_value = 'SiKedan' WHERE setting_key = 'app_name' AND setting_value = 'DosenLMS';
UPDATE settings SET setting_value = 'Sistem Integrasi Kegiatan Edukasi, Pengabdian, dan Penelitian'
    WHERE setting_key = 'app_tagline' AND setting_value = 'Learning, Research & Community Engagement Platform';
UPDATE settings SET setting_value = CONCAT('© 2026 SiKedan. Seluruh hak cipta dilindungi.')
    WHERE setting_key = 'footer_text' AND setting_value = CONCAT('© 2026 DosenLMS. Seluruh hak cipta dilindungi.');

-- ============================
-- HERO BANNERS (mandiri, tidak terikat kegiatan)
-- ============================
CREATE TABLE IF NOT EXISTS hero_banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image VARCHAR(255) NOT NULL,
    title VARCHAR(200) NULL,
    subtitle VARCHAR(255) NULL,
    link_url VARCHAR(255) NULL,
    image_position VARCHAR(20) NOT NULL DEFAULT 'center',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================
-- ACTIVITY REPORTS (Laporan Kegiatan — CRUD oleh admin)
-- ============================
CREATE TABLE IF NOT EXISTS activity_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NULL,
    title VARCHAR(200) NOT NULL,
    report_date DATE NOT NULL,
    location VARCHAR(200) NULL,
    participant_count INT NULL,
    summary TEXT NULL,
    content LONGTEXT NULL,
    status ENUM('draft','final') NOT NULL DEFAULT 'draft',
    attachment VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_reports_event (event_id),
    INDEX idx_reports_date (report_date),
    INDEX idx_reports_status (status)
) ENGINE=InnoDB;

-- ============================
-- NOTIFICATIONS
-- ============================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_id)
) ENGINE=InnoDB;


-- =========================================================
-- UPGRADE OTOMATIS UNTUK DATABASE LAMA
-- Menambah kolom yang belum ada (pengganti MIGRATION v3, v4, v5 dan
-- pembaruan lainnya). Jika kolom sudah ada, langkah dilewati tanpa error.
-- Tidak ada data yang diubah atau dihapus.
-- =========================================================
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE events ADD COLUMN cover_image_position VARCHAR(20) NOT NULL DEFAULT ''center'' AFTER cover_image',
    'SELECT ''events.cover_image_position sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'cover_image_position');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE event_days ADD COLUMN weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER day_date',
    'SELECT ''event_days.weight_percent sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_days' AND COLUMN_NAME = 'weight_percent');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE materials ADD COLUMN weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER status',
    'SELECT ''materials.weight_percent sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'weight_percent');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE videos ADD COLUMN weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER description',
    'SELECT ''videos.weight_percent sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'videos' AND COLUMN_NAME = 'weight_percent');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE quizzes ADD COLUMN weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER status',
    'SELECT ''quizzes.weight_percent sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quizzes' AND COLUMN_NAME = 'weight_percent');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE assignments ADD COLUMN weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER status',
    'SELECT ''assignments.weight_percent sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignments' AND COLUMN_NAME = 'weight_percent');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE assignment_submissions ADD COLUMN submission_link VARCHAR(500) NULL AFTER file_path',
    'SELECT ''assignment_submissions.submission_link sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assignment_submissions' AND COLUMN_NAME = 'submission_link');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE hero_banners ADD COLUMN image_position VARCHAR(20) NOT NULL DEFAULT ''center'' AFTER link_url',
    'SELECT ''hero_banners.image_position sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hero_banners' AND COLUMN_NAME = 'image_position');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE certificates ADD COLUMN source ENUM(''auto'',''manual'') NOT NULL DEFAULT ''auto'' AFTER file_path',
    'SELECT ''certificates.source sudah ada'''
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificates' AND COLUMN_NAME = 'source');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- SEED DATA — DEMO ACCOUNTS
-- Password untuk kedua akun di bawah: Admin@12345 / Peserta@12345
-- Hash dibuat menggunakan password_hash() PHP (bcrypt)
-- =========================================================

-- admin / Admin@12345
INSERT INTO users (role_id, full_name, email, username, password, institution, position, status)
SELECT 1, 'Administrator', 'admin@dosenlms.test', 'admin',
'$2b$10$cfiWdxNH.WTLhFX.UVT09OlDrmVxt/ryHberYHDPggfzZShjrfhDO', 'Politeknik Negeri Medan', 'Dosen', 'active' FROM DUAL WHERE @fresh_install = 1;

-- peserta / Peserta@12345
INSERT INTO users (role_id, full_name, email, username, password, institution, position, status)
SELECT 2, 'Peserta Demo', 'peserta@dosenlms.test', 'peserta',
'$2b$10$qhluk.ES7FJF8F3n.iMZY.FtUTBPNasHEa9Ubr9rBsePFx95IZUbO', 'SMA Contoh', 'Guru', 'active' FROM DUAL WHERE @fresh_install = 1;

-- NOTE: Hash di atas adalah bcrypt asli (valid), teruji cocok dengan
-- password_verify() PHP. Segera ganti password setelah login pertama kali.
