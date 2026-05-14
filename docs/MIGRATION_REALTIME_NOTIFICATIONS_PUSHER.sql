-- ================================================================
-- MIGRATION: Realtime Notifications (Pusher WebSocket) - CI3
-- ================================================================
-- Safe order:
-- 1) Create notifications table
-- 2) Add indexes
-- 3) Verify table
-- ================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT(20) UNSIGNED NULL,
    `brand_id` BIGINT(20) UNSIGNED NULL,
    `router_id` BIGINT(20) UNSIGNED NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'info',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `reference_id` BIGINT(20) UNSIGNED NULL,
    `reference_type` VARCHAR(50) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_id` (`user_id`),
    KEY `idx_notifications_brand_id` (`brand_id`),
    KEY `idx_notifications_router_id` (`router_id`),
    KEY `idx_notifications_is_read` (`is_read`),
    KEY `idx_notifications_created_at` (`created_at`),
    KEY `idx_notifications_user_read` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Validation
SHOW CREATE TABLE `notifications`;

