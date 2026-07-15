-- Run this once on existing FileAudit installations.
CREATE TABLE dns_queries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dns_server VARCHAR(128) NOT NULL,
    time_created DATETIME NOT NULL,
    client_ip VARCHAR(64) NOT NULL,
    query_name VARCHAR(1024) NOT NULL,
    query_type VARCHAR(32) NULL,
    response_code VARCHAR(64) NULL,
    entry_hash CHAR(64) NOT NULL,
    raw_line TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_dns_entry (entry_hash),
    INDEX idx_dns_time (time_created),
    INDEX idx_dns_client_time (client_ip, time_created),
    INDEX idx_dns_query_time (query_name(255), time_created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
