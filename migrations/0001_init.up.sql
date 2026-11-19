-- BarberLead core schema (Phase 1)
-- MariaDB 10.11 / MySQL 8 compatible. utf8mb4 everywhere for Bulgarian text.

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    totp_secret     VARCHAR(64)  NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME     NULL,
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45)  NOT NULL,
    username      VARCHAR(64)  NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_ip_time (ip, attempted_at),
    KEY ix_login_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geography: country > city > district (административен район) > neighborhood (квартал)
CREATE TABLE areas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id       INT UNSIGNED NULL,
    type            ENUM('country','city','district','neighborhood') NOT NULL,
    name_bg         VARCHAR(120) NOT NULL,
    name_en         VARCHAR(120) NULL,
    slug            VARCHAR(140) NOT NULL,
    sort_order      INT          NOT NULL DEFAULT 0,
    -- 'verified' = boundaries/hierarchy confirmed from an authoritative source (e.g. OSM import)
    -- 'approx'   = seeded from general knowledge, hierarchy may need correction
    geo_confidence  ENUM('verified','approx') NOT NULL DEFAULT 'approx',
    osm_id          BIGINT       NULL,
    lat             DECIMAL(9,6) NULL,
    lng             DECIMAL(9,6) NULL,
    bbox_south      DECIMAL(9,6) NULL,
    bbox_west       DECIMAL(9,6) NULL,
    bbox_north      DECIMAL(9,6) NULL,
    bbox_east       DECIMAL(9,6) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_areas_parent_slug (parent_id, slug),
    KEY ix_areas_parent (parent_id),
    CONSTRAINT fk_areas_parent FOREIGN KEY (parent_id) REFERENCES areas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE businesses (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(200) NOT NULL,
    name_normalized      VARCHAR(200) NOT NULL,
    brand                VARCHAR(200) NULL,
    category             VARCHAR(60)  NOT NULL DEFAULT 'barbershop',
    area_id              INT UNSIGNED NULL,
    address              VARCHAR(255) NULL,
    lat                  DECIMAL(9,6) NULL,
    lng                  DECIMAL(9,6) NULL,
    phone                VARCHAR(40)  NULL,
    phone_normalized     VARCHAR(20)  NULL,
    email                VARCHAR(190) NULL,
    website_url          VARCHAR(500) NULL,
    website_domain       VARCHAR(190) NULL,
    website_status       ENUM('unknown','none','weak','good') NOT NULL DEFAULT 'unknown',
    instagram_url        VARCHAR(300) NULL,
    facebook_url         VARCHAR(300) NULL,
    tiktok_url           VARCHAR(300) NULL,
    booking_method       VARCHAR(120) NULL,
    working_hours        VARCHAR(500) NULL,
    rating               DECIMAL(2,1) NULL,
    reviews_count        INT UNSIGNED NULL,
    status               VARCHAR(40)  NOT NULL DEFAULT 'new',
    lead_score           TINYINT UNSIGNED NULL,
    lead_score_override  TINYINT UNSIGNED NULL,
    score_explanation    TEXT         NULL,
    notes                TEXT         NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_biz_area (area_id),
    KEY ix_biz_status (status),
    KEY ix_biz_phone (phone_normalized),
    KEY ix_biz_domain (website_domain),
    KEY ix_biz_name (name_normalized),
    CONSTRAINT fk_biz_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline / history of a business (status changes, offers, notes, imports)
CREATE TABLE business_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id  INT UNSIGNED NOT NULL,
    type         VARCHAR(40)  NOT NULL,
    message      VARCHAR(500) NOT NULL,
    data         TEXT         NULL,
    actor        VARCHAR(64)  NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_events_biz (business_id, created_at),
    CONSTRAINT fk_events_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Пусната оферта": recording only. Nothing is ever sent from here.
CREATE TABLE offers (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id        INT UNSIGNED NOT NULL,
    sent_at            DATETIME     NOT NULL,
    channel            ENUM('instagram','facebook','email','phone','in_person','other') NOT NULL,
    amount_eur         DECIMAL(10,2) NULL,
    demo_url           VARCHAR(500) NULL,
    note               TEXT         NULL,
    follow_up_at       DATE         NULL,
    follow_up_done_at  DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_offers_biz (business_id),
    KEY ix_offers_followup (follow_up_at, follow_up_done_at),
    CONSTRAINT fk_offers_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demo websites. Phase 5 adds the generation/approval queue on top of this table.
CREATE TABLE demos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id  INT UNSIGNED NOT NULL,
    url          VARCHAR(500) NOT NULL,
    status       VARCHAR(40)  NOT NULL DEFAULT 'approved',
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    notes        TEXT         NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_demos_biz (business_id),
    KEY ix_demos_status (status),
    CONSTRAINT fk_demos_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name        VARCHAR(80) PRIMARY KEY,
    value       TEXT        NULL,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(64)  NULL,
    action      VARCHAR(60)  NOT NULL,
    entity      VARCHAR(40)  NULL,
    entity_id   INT UNSIGNED NULL,
    ip          VARCHAR(45)  NULL,
    data        TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
