<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_User_multi_router_access extends CI_Migration
{
    public function up()
    {
        if (!$this->db->table_exists('users') || !$this->db->table_exists('routers')) {
            return;
        }

        if (!$this->db->table_exists('user_router_access')) {
            $this->db->query("
                CREATE TABLE `user_router_access` (
                    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT(20) UNSIGNED NOT NULL,
                    `router_id` BIGINT(20) UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_user_router_access_user_router` (`user_id`, `router_id`),
                    KEY `idx_user_router_access_router` (`router_id`),
                    CONSTRAINT `fk_user_router_access_user`
                        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT `fk_user_router_access_router`
                        FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        if ($this->db->field_exists('router_scope_id', 'users')) {
            $this->db->query("
                INSERT IGNORE INTO `user_router_access` (`user_id`, `router_id`, `created_at`)
                SELECT `id`, `router_scope_id`, NOW()
                FROM `users`
                WHERE `router_scope_id` IS NOT NULL AND `router_scope_id` > 0
            ");
        }
    }

    public function down()
    {
        // Non-destructive rollback intentionally omitted.
    }
}
