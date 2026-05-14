-- Migration: Monitoring Interface Scope Per Router
-- Date: 2026-02-26
-- Safe to run multiple times (MariaDB/MySQL with IF NOT EXISTS support)

ALTER TABLE routers
    ADD COLUMN IF NOT EXISTS monitor_interfaces TEXT NULL AFTER timeout_seconds,
    ADD COLUMN IF NOT EXISTS monitor_down_watchlist TEXT NULL AFTER monitor_interfaces;

-- Optional seed (example)
-- UPDATE routers
-- SET monitor_interfaces = 'ether10,br-dist',
--     monitor_down_watchlist = 'ether10'
-- WHERE id = 1;
