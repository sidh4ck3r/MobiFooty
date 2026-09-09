-- ============================================================
-- MOBIFOOTY — reference schema
-- The app self-installs these tables via GET/POST api/init.php,
-- so you do NOT need to run this file manually. It exists as a
-- reference / for manual setups.
-- ============================================================

CREATE DATABASE IF NOT EXISTS mobifooty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mobifooty;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    current_tier VARCHAR(20) DEFAULT NULL,
    tier_expires_at DATETIME DEFAULT NULL,
    self_excluded TINYINT(1) NOT NULL DEFAULT 0,
    self_exclude_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    reference VARCHAR(100) NOT NULL UNIQUE,
    provider VARCHAR(10) NOT NULL DEFAULT 'paystack',
    network VARCHAR(6) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    amount_ghs DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'GHS',
    tier VARCHAR(20) NOT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    tier_unlocked TINYINT(1) NOT NULL DEFAULT 0,
    gateway_response VARCHAR(255) DEFAULT NULL,
    meta JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analyses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tier VARCHAR(20) NOT NULL,
    filename VARCHAR(255) DEFAULT NULL,
    simulation TINYINT(1) NOT NULL DEFAULT 0,
    league VARCHAR(100) DEFAULT NULL,
    fixtures JSON NOT NULL,
    model JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    bet_type ENUM('single','multiple') NOT NULL DEFAULT 'single',
    stake DECIMAL(10,2) NOT NULL,
    odds DECIMAL(8,2) NOT NULL,
    potential_return DECIMAL(12,2) NOT NULL,
    result ENUM('pending','won','lost') NOT NULL DEFAULT 'pending',
    placed_at DATE NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_result (user_id, result),
    INDEX idx_user_placed (user_id, placed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;