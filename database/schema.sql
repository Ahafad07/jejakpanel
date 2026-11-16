-- JejakPanel - skema database (MySQL / MariaDB)
-- Impor lewat phpMyAdmin/Adminer: buat database "jejakpanel", lalu impor file ini.

CREATE DATABASE IF NOT EXISTS jejakpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jejakpanel;

-- 1. Baris log akses mentah hasil parsing file log server
CREATE TABLE IF NOT EXISTS log_entries (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL,
    waktu       DATETIME     NOT NULL,
    method      VARCHAR(10)  NOT NULL,
    path        VARCHAR(500) NOT NULL,
    status      SMALLINT     NOT NULL,
    bytes       INT          NOT NULL DEFAULT 0,
    user_agent  VARCHAR(300) NULL,
    sumber      ENUM('impor','live') NOT NULL DEFAULT 'impor',   -- live = log pantau langsung / simulasi
    INDEX idx_ip_waktu (ip, waktu),
    INDEX idx_waktu (waktu)
) ENGINE=InnoDB;

-- 2. Insiden = satu rangkaian serangan dari satu IP (satu "cerita" kronologi)
CREATE TABLE IF NOT EXISTS incidents (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL,
    mulai       DATETIME     NOT NULL,
    selesai     DATETIME     NOT NULL,
    risiko      ENUM('rendah','sedang','tinggi','kritis') NOT NULL DEFAULT 'rendah',
    status      ENUM('baru','ditinjau','ditangani','serangan_ulang') NOT NULL DEFAULT 'baru',
    ringkasan   TEXT NULL,
    dibuat_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip)
) ENGINE=InnoDB;

-- 3. Tahap = satu panel di kronologi (misal: pengintaian, login paksa, login berhasil)
CREATE TABLE IF NOT EXISTS incident_stages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    incident_id   INT          NOT NULL,
    urutan        TINYINT      NOT NULL,
    jenis         ENUM('pengintaian','login_paksa','login_berhasil','lonjakan_error','serangan_ulang') NOT NULL,
    teknik_attack VARCHAR(20)  NOT NULL,   -- contoh: T1595, T1110
    mulai         DATETIME     NOT NULL,
    selesai       DATETIME     NOT NULL,
    jumlah_request INT         NOT NULL,
    penjelasan    TEXT         NOT NULL,   -- kalimat bahasa sederhana untuk operator
    rekomendasi   TEXT         NOT NULL,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Daftar IP yang ditandai untuk diblokir
CREATE TABLE IF NOT EXISTS blocklist (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45)  NOT NULL UNIQUE,
    incident_id INT          NULL,
    alasan      VARCHAR(255) NOT NULL,
    cara        ENUM('manual','otomatis') NOT NULL DEFAULT 'manual',
    dibuat_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    dikonfirmasi_pada TIMESTAMP NULL DEFAULT NULL,   -- waktu "Blokir ulang" terakhir
    permanen_pada     TIMESTAMP NULL DEFAULT NULL,   -- waktu operator menjadikan blokir permanen
    diterapkan_pada   TIMESTAMP NULL DEFAULT NULL,   -- waktu aturan ditulis ke .htaccess
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Posisi baca file log terakhir (agar impor hanya membaca baris baru)
CREATE TABLE IF NOT EXISTS import_state (
    file        VARCHAR(255) PRIMARY KEY,
    posisi      BIGINT       NOT NULL DEFAULT 0,
    diperbarui  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. IP yang ditandai aman oleh operator (tidak akan diblokir otomatis)
CREATE TABLE IF NOT EXISTS ip_aman (
    ip          VARCHAR(45)  PRIMARY KEY,
    alasan      VARCHAR(255) NOT NULL,
    dibuat_pada TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
