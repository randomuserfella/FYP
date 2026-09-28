-- ============================================================
-- ProcraTrack Push Notifications — Database Schema
-- Run this in phpMyAdmin > procratrack database > SQL tab
-- ============================================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `endpoint`     TEXT         NOT NULL,
    `p256dh`       TEXT         NOT NULL,  -- client public key
    `auth`         VARCHAR(255) NOT NULL,  -- auth secret
    `user_agent`   VARCHAR(255) DEFAULT NULL,
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_endpoint` (`user_id`, `endpoint`(200)),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- push_log — optional, keeps track of every notification sent
-- Useful for your FYP report / demo evidence
-- ============================================================

CREATE TABLE IF NOT EXISTS `push_log` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `type`        VARCHAR(50)  NOT NULL,   -- 'task_due', 'overdue', 'habit', 'test'
    `title`       VARCHAR(255) NOT NULL,
    `body`        VARCHAR(500) NOT NULL,
    `status`      ENUM('sent','failed') DEFAULT 'sent',
    `sent_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user` (`user_id`),
    KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
