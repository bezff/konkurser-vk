CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(64) NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL,
    require_accept TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_contests_status (status, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participants (
    vk_user_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(80) NOT NULL DEFAULT '',
    last_name VARCHAR(80) NOT NULL DEFAULT '',
    profile_updated_at DATETIME NULL,
    PRIMARY KEY (vk_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acceptances (
    contest_id BIGINT UNSIGNED NOT NULL,
    vk_user_id BIGINT UNSIGNED NOT NULL,
    accepted_at DATETIME NOT NULL,
    PRIMARY KEY (contest_id, vk_user_id),
    CONSTRAINT fk_acceptances_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_clicks (
    contest_id BIGINT UNSIGNED NOT NULL,
    vk_user_id BIGINT UNSIGNED NOT NULL,
    referrer_vk_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (contest_id, vk_user_id),
    CONSTRAINT fk_referral_clicks_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
    contest_id BIGINT UNSIGNED NOT NULL,
    referred_vk_user_id BIGINT UNSIGNED NOT NULL,
    referrer_vk_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (contest_id, referred_vk_user_id),
    CONSTRAINT fk_referrals_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contest_id BIGINT UNSIGNED NOT NULL,
    vk_user_id BIGINT UNSIGNED NOT NULL,
    action_type VARCHAR(16) NOT NULL,
    action_key VARCHAR(80) NOT NULL,
    points INT NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contest_action (contest_id, action_key),
    KEY idx_actions_board (contest_id, status, vk_user_id),
    CONSTRAINT fk_actions_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS callback_events (
    event_id VARCHAR(80) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    received_at DATETIME NOT NULL,
    PRIMARY KEY (event_id),
    KEY idx_callback_events_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS callback_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(40) NOT NULL,
    result VARCHAR(32) NOT NULL,
    detail VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_callback_log_id (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip VARCHAR(45) NOT NULL,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
