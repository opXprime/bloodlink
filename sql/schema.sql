-- bloodbank database schema
-- run this once on a fresh MySQL/MariaDB instance

CREATE DATABASE IF NOT EXISTS bloodbank;
USE bloodbank;

SET FOREIGN_KEY_CHECKS = 0;

-- countries, cities, areas — hierarchical location data

CREATE TABLE IF NOT EXISTS countries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    code        VARCHAR(10)  DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cities (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_city (country_id, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS areas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    city_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    centroid_lat    DECIMAL(10, 7) DEFAULT NULL,
    centroid_lon    DECIMAL(10, 7) DEFAULT NULL,
    is_active       TINYINT(1)     NOT NULL DEFAULT 1,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    UNIQUE KEY unique_area (city_id, name)
) ENGINE=InnoDB;

-- users — single table for all roles (donor, hospital, admin)

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('donor', 'hospital', 'admin') NOT NULL,
    country_id      INT UNSIGNED DEFAULT NULL,
    city_id         INT UNSIGNED DEFAULT NULL,
    area_id         INT UNSIGNED DEFAULT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    security_question VARCHAR(255) DEFAULT NULL,
    security_answer_hash VARCHAR(255) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- admin credentials — per-admin PIN for two-step login

CREATE TABLE IF NOT EXISTS admin_credentials (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL UNIQUE,
    pin_hash    VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- donor profiles — blood type, eligibility, availability

CREATE TABLE IF NOT EXISTS donor_profiles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL UNIQUE,
    blood_type          ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    date_of_birth       DATE DEFAULT NULL,
    gender              ENUM('male','female','other') DEFAULT NULL,
    phone               VARCHAR(20) DEFAULT NULL,
    area_id             INT UNSIGNED DEFAULT NULL,
    is_available        TINYINT(1) NOT NULL DEFAULT 1,
    is_eligible         TINYINT(1) NOT NULL DEFAULT 1,
    last_donation_date  DATE DEFAULT NULL,
    next_eligible_date  DATE DEFAULT NULL,
    weight_kg           DECIMAL(5,1) DEFAULT NULL,
    medical_conditions  TEXT DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- hospital profiles — name, address, verification status

CREATE TABLE IF NOT EXISTS hospital_profiles (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT UNSIGNED NOT NULL UNIQUE,
    hospital_name           VARCHAR(255) NOT NULL,
    license_number          VARCHAR(100) DEFAULT NULL,
    phone                   VARCHAR(20) DEFAULT NULL,
    email                   VARCHAR(255) DEFAULT NULL,
    address                 TEXT DEFAULT NULL,
    area_id                 INT UNSIGNED DEFAULT NULL,
    verification_status     ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verification_notes      TEXT DEFAULT NULL,
    verification_doc        VARCHAR(500) DEFAULT NULL,
    verified_at             TIMESTAMP NULL DEFAULT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- blood requests — hospitals post what they need

CREATE TABLE IF NOT EXISTS blood_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id     INT UNSIGNED NOT NULL,
    country_id      INT UNSIGNED NOT NULL,
    area_id         INT UNSIGNED DEFAULT NULL,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed    INT UNSIGNED NOT NULL DEFAULT 1,
    units_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,
    urgency         ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status          ENUM('open','fulfilled','closed') NOT NULL DEFAULT 'open',
    description     TEXT DEFAULT NULL,
    deadline        DATE DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospital_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id),
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- time slots — appointment windows per request

CREATE TABLE IF NOT EXISTS time_slots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED DEFAULT NULL,
    hospital_id     INT UNSIGNED DEFAULT NULL,
    slot_date       DATE NOT NULL,
    slot_time       TIME DEFAULT NULL,
    start_time      TIME DEFAULT NULL,
    end_time        TIME DEFAULT NULL,
    max_donors      INT UNSIGNED NOT NULL DEFAULT 1,
    booked_count    INT UNSIGNED NOT NULL DEFAULT 0,
    is_available    TINYINT(1) NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospital_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- bookings — five states: pending -> confirmed -> completed / cancelled / rejected

CREATE TABLE IF NOT EXISTS bookings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blood_request_id INT UNSIGNED NOT NULL,
    donor_id        INT UNSIGNED NOT NULL,
    hospital_id     INT UNSIGNED DEFAULT NULL,
    country_id      INT UNSIGNED DEFAULT NULL,
    time_slot_id    INT UNSIGNED DEFAULT NULL,
    units           INT UNSIGNED NOT NULL DEFAULT 1,
    status          ENUM('pending','confirmed','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
    scheduled_date  DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status_changed_at TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blood_request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospital_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE SET NULL,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- donation history — permanent record after booking completes

CREATE TABLE IF NOT EXISTS donation_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id        INT UNSIGNED NOT NULL,
    hospital_id     INT UNSIGNED NOT NULL,
    booking_id      INT UNSIGNED DEFAULT NULL,
    blood_type      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units           INT UNSIGNED NOT NULL DEFAULT 1,
    donation_date   DATE NOT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES donor_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospital_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- campaigns — blood drive events

CREATE TABLE IF NOT EXISTS campaigns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id     INT UNSIGNED NOT NULL,
    country_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT DEFAULT NULL,
    campaign_date   DATE DEFAULT NULL,
    location        VARCHAR(255) DEFAULT NULL,
    status          ENUM('upcoming','active','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospital_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id)
) ENGINE=InnoDB;

-- notifications — in-system alerts

CREATE TABLE IF NOT EXISTS notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    message     TEXT NOT NULL,
    type        ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    link        VARCHAR(500) DEFAULT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- system logs — admin audit trail

CREATE TABLE IF NOT EXISTS system_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(100) NOT NULL,
    details     TEXT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- contact messages — user_id links to logged-in sender, null for guests

CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED DEFAULT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    subject     VARCHAR(255) DEFAULT NULL,
    message     TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    admin_reply TEXT DEFAULT NULL,
    replied_at  TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- user reports — donors/hospitals can report each other

CREATE TABLE IF NOT EXISTS reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id     INT UNSIGNED NOT NULL,
    reported_id     INT UNSIGNED NOT NULL,
    reason          TEXT NOT NULL,
    status          ENUM('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
    admin_notes     TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- login attempts — brute force protection

CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    attempted_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
