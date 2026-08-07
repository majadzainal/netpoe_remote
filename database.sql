CREATE DATABASE IF NOT EXISTS netpoe_remote
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE netpoe_remote;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('superuser', 'user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  router_name VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  api_user VARCHAR(100) NOT NULL,
  api_pass VARCHAR(255) NOT NULL,
  api_port SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
  public_host VARCHAR(255) NULL,
  remote_port SMALLINT UNSIGNED NOT NULL DEFAULT 8080,
  remote_nat_comment VARCHAR(100) NOT NULL DEFAULT 'DYNAMIC_REMOTE_MODEM',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_routers_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX idx_routers_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  brand VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  olt_name VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  telnet_user VARCHAR(100) NOT NULL,
  telnet_pass VARCHAR(255) NOT NULL,
  telnet_port SMALLINT UNSIGNED NOT NULL DEFAULT 23,
  pon_port_count SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  optical_command VARCHAR(255) NOT NULL,
  onu_list_command VARCHAR(255) NOT NULL DEFAULT 'show onu all',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_olts_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  INDEX idx_olts_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_pppoe_mappings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  olt_id INT UNSIGNED NOT NULL,
  pppoe_name VARCHAR(100) NOT NULL,
  pon_onu VARCHAR(100) NOT NULL,
  mac_address VARCHAR(100) NULL,
  customer_name VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_olt_pppoe_mappings_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_olt_pppoe_mappings_olt
    FOREIGN KEY (olt_id) REFERENCES olts(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  UNIQUE KEY uniq_mapping_user_pppoe (user_id, pppoe_name),
  INDEX idx_mapping_olt_id (olt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pppoe_clients_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  router_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  service VARCHAR(50) NOT NULL,
  caller_id VARCHAR(100) NOT NULL,
  address VARCHAR(100) NOT NULL,
  uptime VARCHAR(100) NOT NULL,
  last_active VARCHAR(100) NOT NULL,
  status VARCHAR(20) NOT NULL,
  mapped VARCHAR(100) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pppoe_cache_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_pppoe_cache_router
    FOREIGN KEY (router_id) REFERENCES routers(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_pppoe_cache_name (router_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olt_signals_cache (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  olt_id INT UNSIGNED NOT NULL,
  pon_onu VARCHAR(100) NOT NULL,
  tx_power DECIMAL(8,4) NULL,
  rx_power DECIMAL(8,4) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_olt_signals_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_olt_signals_olt
    FOREIGN KEY (olt_id) REFERENCES olts(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_olt_signals_pon_onu (olt_id, pon_onu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  olt_id INT UNSIGNED NULL,
  source ENUM('cron', 'web') NOT NULL DEFAULT 'cron',
  status ENUM('success', 'error', 'warning', 'info') NOT NULL DEFAULT 'info',
  message VARCHAR(255) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sync_logs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  INDEX idx_sync_logs_user_date (user_id, created_at),
  INDEX idx_sync_logs_olt (olt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

