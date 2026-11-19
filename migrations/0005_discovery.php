<?php
// Phase 2: discovery jobs, source provenance, duplicate review, OSM boundaries, scan schedules.
// Data-only migration: SQL strings or ['task', name] steps run by BarberLead\Migrator.
return [
    'up' => [
        // How relevant a business is as a lead: barbershop / men's haircuts (high) ... women-only salon (low).
        "ALTER TABLE businesses
            ADD COLUMN relevance ENUM('high','medium','low') NOT NULL DEFAULT 'medium' AFTER category,
            ADD COLUMN relevance_reason VARCHAR(255) NULL AFTER relevance,
            ADD COLUMN geo_confidence ENUM('verified','approx','unknown') NOT NULL DEFAULT 'unknown' AFTER lng,
            ADD COLUMN merged_into_id INT UNSIGNED NULL,
            ADD KEY ix_biz_relevance (relevance)",

        // Area geometry from OpenStreetMap (polygon rings as JSON [[lat,lng],...]).
        "ALTER TABLE areas
            ADD COLUMN geometry MEDIUMTEXT NULL,
            ADD COLUMN geometry_source VARCHAR(60) NULL,
            ADD COLUMN geometry_updated_at DATETIME NULL",

        // One row per (source, id) we ever saw. data = normalised fields as returned by that source.
        "CREATE TABLE business_sources (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id    INT UNSIGNED NOT NULL,
            source         VARCHAR(20)  NOT NULL,
            source_id      VARCHAR(190) NOT NULL,
            url            VARCHAR(500) NULL,
            data           TEXT         NULL,
            match_reason   VARCHAR(255) NULL,
            first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_source (source, source_id),
            KEY ix_sources_biz (business_id),
            CONSTRAINT fk_sources_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Background jobs (scans, boundary imports). Claimed atomically via locked_until/lock_token.
        "CREATE TABLE jobs (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type           VARCHAR(30)  NOT NULL,
            area_id        INT UNSIGNED NULL,
            status         ENUM('queued','running','done','partial','failed','cancelled') NOT NULL DEFAULT 'queued',
            params         TEXT         NULL,
            state          MEDIUMTEXT   NULL,
            progress       VARCHAR(255) NULL,
            found_count    INT UNSIGNED NOT NULL DEFAULT 0,
            new_count      INT UNSIGNED NOT NULL DEFAULT 0,
            updated_count  INT UNSIGNED NOT NULL DEFAULT 0,
            review_count   INT UNSIGNED NOT NULL DEFAULT 0,
            attempts       INT UNSIGNED NOT NULL DEFAULT 0,
            error          TEXT         NULL,
            log            MEDIUMTEXT   NULL,
            requested_by   VARCHAR(64)  NULL,
            lock_token     CHAR(32)     NULL,
            locked_until   DATETIME     NULL,
            run_after      DATETIME     NULL,
            heartbeat_at   DATETIME     NULL,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at     DATETIME     NULL,
            finished_at    DATETIME     NULL,
            -- only one active job per (type, area): enforced by this generated key
            active_key     VARCHAR(60) AS (IF(status IN ('queued','running'), CONCAT(type, ':', IFNULL(area_id, 0)), NULL)) PERSISTENT,
            UNIQUE KEY uq_jobs_active (active_key),
            KEY ix_jobs_status (status, run_after),
            KEY ix_jobs_area (area_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE scan_schedules (
            area_id        INT UNSIGNED PRIMARY KEY,
            interval_days  INT UNSIGNED NOT NULL DEFAULT 30,
            next_run_at    DATETIME     NOT NULL,
            enabled        TINYINT(1)   NOT NULL DEFAULT 1,
            CONSTRAINT fk_sched_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Possible duplicates the system did not dare to merge automatically.
        "CREATE TABLE duplicate_candidates (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id    INT UNSIGNED NOT NULL,
            other_id       INT UNSIGNED NOT NULL,
            score          DECIMAL(4,3) NOT NULL,
            reasons        VARCHAR(500) NOT NULL,
            status         ENUM('open','merged','dismissed') NOT NULL DEFAULT 'open',
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at    DATETIME     NULL,
            UNIQUE KEY uq_pair (business_id, other_id),
            KEY ix_dup_status (status),
            CONSTRAINT fk_dup_a FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
            CONSTRAINT fk_dup_b FOREIGN KEY (other_id) REFERENCES businesses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "INSERT IGNORE INTO settings (name, value) VALUES ('google_monthly_cap', '900')",
    ],
    'down' => [
        'DROP TABLE IF EXISTS duplicate_candidates',
        'DROP TABLE IF EXISTS scan_schedules',
        'DROP TABLE IF EXISTS jobs',
        'DROP TABLE IF EXISTS business_sources',
        'ALTER TABLE areas DROP COLUMN geometry, DROP COLUMN geometry_source, DROP COLUMN geometry_updated_at',
        'ALTER TABLE businesses DROP KEY ix_biz_relevance, DROP COLUMN relevance, DROP COLUMN relevance_reason, DROP COLUMN geo_confidence, DROP COLUMN merged_into_id',
        "DELETE FROM settings WHERE name = 'google_monthly_cap'",
    ],
];
